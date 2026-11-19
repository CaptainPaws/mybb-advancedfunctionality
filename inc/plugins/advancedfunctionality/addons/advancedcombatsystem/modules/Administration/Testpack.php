<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) { die('No direct access'); }

final class AF_ACS_Testpack
{
    private const LEDGER_TYPE = 'acs_testpack';
    private const COLUMNS = [
        'af_kb_entries'=>'id type key title_ru title_en short_ru short_en body_ru body_en tech_ru tech_en data_json meta_json item_kind active updated_at',
        'af_kb_blocks'=>'id entry_id block_key content_ru content_en data_json sortorder',
        'af_kb_log'=>'id uid action type key diff_json dateline',
        'af_shop_shops'=>'shop_id code title_ru enabled settings_json',
        'af_shop_categories'=>'cat_id shop_id title description enabled',
        'af_shop_slots'=>'slot_id shop_id cat_id source_type source_ref_id kb_type kb_id kb_key price currency stock enabled sortorder',
    ];

    private static function esc(string $value): string { return htmlspecialchars_uni($value); }

    /** MyBB's normal SQL error path exits, so use its quiet query mode here. */
    private static function query(string $sql, string $code = 'ACS_TESTPACK_DATABASE_ERROR')
    {
        global $db;
        try {
            $result = $db->write_query($sql, 1);
        } catch (mysqli_sql_exception $error) {
            throw new RuntimeException($code, 0, $error);
        }
        if ($result === false) throw new RuntimeException($code);
        return $result;
    }

    private static function row(string $table, string $where): ?array
    {
        global $db;
        return $db->fetch_array(self::query('SELECT * FROM `'.TABLE_PREFIX.$table.'` WHERE '.$where.' LIMIT 1')) ?: null;
    }

    private static function insert(string $table, array $row): int
    {
        global $db;
        // Fields and table are installer constants or from the checked Shop source projection.
        $columns = explode(' ', self::COLUMNS[$table]);
        if (array_diff(array_keys($row), $columns)) throw new RuntimeException('ACS_TESTPACK_SCHEMA_INVALID:'.$table);
        $values = array_map(static fn($value): string => is_int($value)
            ? (string)$value : "'".$db->escape_string($value)."'", $row);
        self::query('INSERT INTO `'.TABLE_PREFIX.$table.'` (`'.implode('`,`', array_keys($row))
            .'`) VALUES ('.implode(',', $values).')','ACS_TESTPACK_WRITE_FAILED:'.$table);
        $id = (int)$db->insert_id();
        if ($id < 1) throw new RuntimeException('ACS_TESTPACK_INSERT_ID_MISSING:'.$table);
        return $id;
    }

    private static function ledger(): ?array
    {
        $row = self::row('af_kb_log', "type='".self::LEDGER_TYPE."' AND `key`='".AF_ACS_TestpackDefinitions::SHOP."' AND action='install'");
        if (!$row) return null;
        $ledger = json_decode($row['diff_json'], true);
        if (($ledger['version'] ?? null) !== 1 || !isset($ledger['kb'],$ledger['shop'],$ledger['category'],$ledger['slots'])) {
            throw new RuntimeException('ACS_TESTPACK_PROVENANCE_INVALID');
        }
        return $ledger;
    }

    /** Load owner APIs only on this ACP route. Failed checks carry safe reason codes. */
    private static function prerequisites(array &$checks): void
    {
        global $db, $mybb;
        foreach (['knowledgebase','advancedshop','advancedinventory','charactersheets','advancedcombatsystem','balance'] as $addon) {
            $checks[$addon] = af_is_addon_enabled($addon) ? '' : 'ADDON_DISABLED';
        }
        $writer = isset($db->read_link, $db->write_link) && $db->write_link instanceof mysqli;
        $checks['MySQL writer'] = $writer ? '' : 'MYSQL_WRITER_UNAVAILABLE';
        if ($writer) {
            foreach (self::COLUMNS + ['af_kb_types'=>'id type type_key mechanic_key is_active active sortorder title_ru title_en'] as $table => $columns) {
                if (!$db->table_exists($table)) {
                    $checks[$table] = 'TABLE_MISSING';
                    continue;
                }
                $name = TABLE_PREFIX.$table;
                $result = self::query('SHOW COLUMNS FROM `'.$name.'`');
                $actual = [];
                while ($column = $db->fetch_array($result)) $actual[] = $column['Field'];
                $missing = array_diff(explode(' ', $columns), $actual);
                $checks[$table] = $missing ? 'COLUMNS_MISSING: '.implode(', ', $missing) : '';
                if (isset(self::COLUMNS[$table])) {
                    $safe = $db->escape_string($name);
                    $result = self::query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$safe}'");
                    if (strcasecmp((string)$db->fetch_field($result,'ENGINE'),'InnoDB') !== 0) {
                        $checks[$table] = ltrim($checks[$table].' INNODB_REQUIRED');
                    }
                }
            }
            foreach (['af_kb_entries'=>['type','key'], 'af_shop_shops'=>['code']] as $table => $identity) {
                if ($checks[$table] !== '') continue;
                $indexes = [];
                $result = self::query('SHOW INDEX FROM `'.TABLE_PREFIX.$table.'`');
                while ($index = $db->fetch_array($result)) {
                    if ((int)$index['Non_unique'] === 0) {
                        $indexes[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Sub_part'] === null ? $index['Column_name'] : null;
                    }
                }
                $unique = false;
                foreach ($indexes as $columns) {
                    ksort($columns);
                    if (array_values($columns) === $identity) $unique = true;
                }
                if (!$unique) $checks[$table] = 'UNIQUE_IDENTITY_MISSING';
            }
            foreach (['af_advinv_items','af_advinv_equipped','af_cs_sheets','af_balance','af_balance_tx'] as $table) {
                $checks[$table] = $db->table_exists($table) ? '' : 'TABLE_MISSING';
            }
        }
        $checks['Настройка KB'] = !empty($mybb->settings['af_knowledgebase_enabled']) ? '' : 'KB_SETTING_DISABLED';
        $checks['Настройка Shop'] = (int)($mybb->settings['af_advancedshop_enabled'] ?? 1) === 1 ? '' : 'SHOP_SETTING_DISABLED';
        try {
            require_once __DIR__.'/../../advancedcombatsystem.php';
            af_advancedcombatsystem_migrations()->assertInstalled();
            $checks['Схема ACS'] = '';
        } catch (Throwable $error) {
            $checks['Схема ACS'] = 'ACS_SCHEMA_UNAVAILABLE: '.self::safeCode($error);
        }
        if ($checks['knowledgebase'] === '') {
            @require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
            $checks['Контракт KB'] = self::missingFunctions([
                'af_kb_arpg_primary_elements','af_kb_arpg_character_stat_registry','af_kb_find_type_row',
                'af_kb_arpg_read_element','af_kb_arpg_envelope_defaults','af_kb_default_type_profile_payload_arpg',
                'af_kb_validate_arpg_entry_by_type','af_kb_arpg_public_mechanics_errors',
                'af_kb_arpg_service_mechanics_errors','af_kb_arpg_prepare_bestiary_ability_ref',
                'af_kb_ability_catalog_metadata_valid','af_kb_arpg_acs_lookup','af_kb_url',
            ]);
            if ($checks['Контракт KB'] === '' && ($checks['af_kb_types'] ?? null) === ''
                && ($checks['af_kb_entries'] ?? null) === '') {
                foreach (['arpg_origin','arpg_archetype','arpg_ability','arpg_bestiary','arpg_item','arpg_mechanics','arpg_element'] as $type) {
                    $checks[$type] = !empty(af_kb_find_type_row($type)['active']) ? '' : 'KB_TYPE_MISSING_OR_INACTIVE';
                }
                $fire = af_kb_arpg_read_element('fire');
                $checks['arpg_element:fire'] = $fire['supported'] && ($fire['root_element'] ?? null) === 'fire'
                    ? '' : 'KB_FIRE_UNAVAILABLE';
            }
        }
        if ($checks['advancedshop'] === '') {
            @require_once AF_ADDONS.'advancedshop/advancedshop.php';
            $checks['Контракт Shop'] = self::missingFunctions([
                'af_advancedshop_slot_payload_from_source','af_advancedshop_assert_source_matches_shop',
                'af_advancedshop_money_to_minor','af_advancedshop_url','mb_strtolower',
            ]);
        }
        if ($checks['advancedinventory'] === '') {
            @require_once AF_ADDONS.'advancedinventory/advancedinventory.php';
            $checks['Контракт Inventory'] = self::missingFunctions(['af_inv_candidate_slots_for_item','af_inv_add_item']);
        }
        if ($checks['charactersheets'] === '') {
            @require_once AF_ADDONS.'charactersheets/modules/combat_snapshot.php';
            $checks['Источник CharacterSheets'] = self::missingFunctions(['af_charactersheets_prepare_combat_snapshot']);
        }
    }

    private static function missingFunctions(array $names): string
    {
        $missing = array_filter($names, static fn(string $name): bool => !function_exists($name));
        return $missing ? 'API_MISSING: '.implode(', ', $missing) : '';
    }

    private static function validate(string $type, array $rules): array
    {
        $payload = af_kb_arpg_envelope_defaults($type);
        $payload['rules'] = array_replace($payload['rules'], af_kb_default_type_profile_payload_arpg($type), $rules);
        $errors = [];
        $payload = af_kb_validate_arpg_entry_by_type($type,$payload,$errors);
        $errors = array_merge($errors, af_kb_arpg_public_mechanics_errors($payload['rules']));
        if ($type === 'arpg_mechanics') {
            foreach ($payload['rules']['entries'] as $entry) {
                $errors = array_merge($errors,af_kb_arpg_service_mechanics_errors($payload['rules']['service_kind'],$entry));
            }
        }
        return [$payload,$errors];
    }

    private static function writeEntry(string $key, array $definition, int $uid): int
    {
        $type = $definition['type'];
        $rules = $definition['rules'];
        if ($type === 'arpg_bestiary') {
            $errors = [];
            foreach ($rules['ability_keys'] as &$ability) $ability = af_kb_arpg_prepare_bestiary_ability_ref($ability,$errors);
            unset($ability);
            if ($errors) throw new RuntimeException('ACS_TESTPACK_PIN_UNAVAILABLE:'.$key);
        }
        [$payload,$errors] = self::validate($type,$rules);
        if ($errors) throw new RuntimeException('ACS_TESTPACK_KB_INVALID:'.$key);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $meta = ['kb_type'=>$type,'rules'=>$payload['rules'],'ui'=>$payload['ui']];
        if ($definition['catalog']) {
            if (!af_kb_ability_catalog_metadata_valid($definition['catalog'])) throw new RuntimeException('ACS_TESTPACK_KB_INVALID:'.$key);
            $meta['ability_catalog'] = $definition['catalog'];
        }
        $id = self::insert('af_kb_entries',[
            'type'=>$type,'key'=>$key,'title_ru'=>$definition['title'],'short_ru'=>$definition['description'],
            'short_en'=>'','body_ru'=>$definition['description'],'body_en'=>'','tech_ru'=>'','tech_en'=>'',
            'data_json'=>$json,'meta_json'=>json_encode($meta,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'item_kind'=>$type === 'arpg_item' ? $rules['item_kind'] : '', 'active'=>1,'updated_at'=>TIME_NOW,
        ]);
        self::insert('af_kb_blocks',[
            'entry_id'=>$id,'block_key'=>'data','content_ru'=>'','content_en'=>'','data_json'=>$json,'sortorder'=>9999,
        ]);
        self::insert('af_kb_log',['uid'=>$uid,'action'=>'create','type'=>$type,'key'=>$key,
            'diff_json'=>json_encode(['provenance'=>self::LEDGER_TYPE,'id'=>$id],JSON_THROW_ON_ERROR),'dateline'=>TIME_NOW]);
        return $id;
    }

    private static function install(array $entries,int $uid): bool
    {
        global $db;
        $prefix = $db->escape_string(TABLE_PREFIX);
        $lock = "CONCAT('acs_testpack_', LEFT(SHA2(CONCAT(DATABASE(), ':', '{$prefix}'), 256), 32))";
        $result = self::query("SELECT GET_LOCK({$lock}, 5) AS acquired");
        if ((int)$db->fetch_field($result,'acquired') !== 1) throw new RuntimeException('ACS_TESTPACK_BUSY');
        $readLink = $db->read_link;
        $db->read_link = $db->write_link; // Owner API reads must see uncommitted rows on this connection.
        try {
            if (self::ledger() !== null) return false;
            self::query('START TRANSACTION');
            try {
                foreach ($entries as $key => $entry) {
                    $type = $entry['type'];
                    if (self::row('af_kb_entries',"type='{$type}' AND `key`='{$key}'")) {
                        throw new RuntimeException('ACS_TESTPACK_FOREIGN_KEY:'.$type.':'.$key);
                    }
                }
                $code = AF_ACS_TestpackDefinitions::SHOP;
                if (self::row('af_shop_shops',"code='{$code}'")) throw new RuntimeException('ACS_TESTPACK_FOREIGN_KEY:'.$code);
                $ids = [];
                foreach ($entries as $key => $entry) $ids[$key] = self::writeEntry($key,$entry,$uid);
                $shop = ['code'=>$code,'title_ru'=>'ACS — Испытательный арсенал','enabled'=>1,
                    'settings_json'=>json_encode(['mechanic_key'=>'arpg'],JSON_THROW_ON_ERROR)];
                $shopId = self::insert('af_shop_shops',$shop);
                $categoryId = self::insert('af_shop_categories',['shop_id'=>$shopId,'title'=>'Учебное снаряжение',
                    'description'=>'Предметы для ручной проверки ACS.']);
                $slots = [];
                foreach ($entries as $key => $entry) {
                    if ($entry['type'] !== 'arpg_item') continue;
                    $source = af_advancedshop_slot_payload_from_source('kb',['kb_id'=>$ids[$key]]);
                    af_advancedshop_assert_source_matches_shop($shop,$source);
                    unset($source['appearance_preset']);
                    $slots[$key] = self::insert('af_shop_slots',$source + ['shop_id'=>$shopId,'cat_id'=>$categoryId,
                        'price'=>af_advancedshop_money_to_minor((string)$entry['price']),'currency'=>'credits','enabled'=>1,'stock'=>-1,
                        'sortorder'=>count($slots)]);
                }
                self::insert('af_kb_log',[
                    'uid'=>$uid,'action'=>'install','type'=>self::LEDGER_TYPE,'key'=>$code,'dateline'=>TIME_NOW,
                    'diff_json'=>json_encode(['version'=>1,'kb'=>$ids,'shop'=>$shopId,
                        'category'=>[AF_ACS_TestpackDefinitions::CATEGORY=>$categoryId],'slots'=>$slots],JSON_THROW_ON_ERROR),
                ]);
                self::query('COMMIT');
            } catch (Throwable $error) {
                $db->write_query('ROLLBACK', 1);
                throw $error;
            }
            return true;
        } finally {
            $db->read_link = $readLink;
            $db->write_query("SELECT RELEASE_LOCK({$lock})", 1);
        }
    }

    private static function codes(array $diagnostics): array
    {
        return array_values(array_unique(array_map(static fn(array $row): string => (string)($row['code'] ?? 'source_invalid'),
            array_filter($diagnostics, static fn(array $row): bool => ($row['severity'] ?? 'error') === 'error'))));
    }

    private static function safeCode(Throwable $error): string
    {
        $code = $error->getMessage();
        return preg_match('/\A(?:ACS_TESTPACK_[A-Z_]+(?::[a-z0-9_]+){0,2}|[a-z][a-z0-9_]{3,100})\z/D',$code) ? $code : 'ACS_TESTPACK_CHECK_FAILED';
    }

    private static function abilityCodes(string $key, int $uid): array
    {
        $adapter = new AF_ACS_KnowledgeBaseAdapter;
        $dto = $adapter->readDefinition('arpg_ability',$key,$uid);
        if (empty($dto['ready'])) return self::codes($dto['diagnostics'] ?? []) ?: ['ability_definition_unavailable'];
        try {
            if (in_array($key,['acs_test_defender_stance','acs_test_stone_skin','acs_test_bandage'],true)) {
                $adapter->reactionDefinition($key.'_reaction',$uid);
            } else {
                AF_ACS_QueryService::compileAbility($dto);
            }
        } catch (Throwable $error) {
            return [self::safeCode($error)];
        }
        return [];
    }

    private static function readiness(array $entries, array $ledger, int $uid): void
    {
        require_once __DIR__.'/../Infrastructure/Adapters/RegistrationSource.php';
        require_once __DIR__.'/../Application/QueryService.php';
        $shopId = (int)$ledger['shop'];
        $categoryId = (int)($ledger['category'][AF_ACS_TestpackDefinitions::CATEGORY] ?? 0);
        $shop = self::row('af_shop_shops','shop_id='.$shopId);
        $shopValid = $shop && $shop['code'] === AF_ACS_TestpackDefinitions::SHOP && !empty($shop['enabled'])
            && self::row('af_shop_categories','cat_id='.$categoryId.' AND shop_id='.$shopId.' AND enabled=1');
        if ($shopValid) {
            $url = rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/'.af_advancedshop_url('shop',['shop'=>AF_ACS_TestpackDefinitions::SHOP]);
            echo '<p><a href="'.self::esc($url).'">ACS — Испытательный арсенал</a></p>';
        }
        echo '<h3>Готовность определений</h3><ul>';
        if (!$shopValid) echo '<li>Shop: shop_category_unavailable</li>';
        foreach ($entries as $key => $entry) {
            $type = $entry['type'];
            $row = af_kb_arpg_acs_lookup($type,$key);
            $codes = [];
            $slotProblem = '';
            if (!$row || (int)$row['id'] !== (int)($ledger['kb'][$key] ?? 0) || empty($row['active'])) {
                $codes[] = 'provenance_object_missing_replaced_or_disabled';
            } else {
                try {
                    $diagnostics = [];
                    $source = af_kb_arpg_acs_source($row,$diagnostics);
                    $codes = self::codes($diagnostics);
                    [, $errors] = self::validate($type,$source['rules']);
                    foreach ($errors as $error) $codes[] = 'kb_validation: '.$error;
                    if (!$codes && $type === 'arpg_ability') {
                        $codes = self::abilityCodes($key,$uid);
                    } elseif (!$codes && $type === 'arpg_bestiary') {
                        $registration = new AF_ACS_RegistrationSource;
                        $dto = $registration->read('monster',$key,$uid);
                        if (empty($dto['ready'])) {
                            $codes = self::codes($dto['diagnostics'] ?? []) ?: ['registration_source_unavailable'];
                        } else {
                            foreach (array_keys($registration->definitions($dto,$uid)) as $ability) {
                                array_push($codes,...self::abilityCodes($ability,$uid));
                            }
                        }
                    } elseif (!$codes && $type === 'arpg_item') {
                        $meta = af_kb_decode_json($row['meta_json']);
                        $meta['kb_type'] = 'arpg_item';
                        $target = af_advancedshop_detect_inventory_target_from_kb_meta($meta);
                        if ($target['slot'] !== 'equipment') $codes[] = 'inventory_destination_unavailable';
                        $expected = $source['rules']['item']['equip']['slot'] ?? null;
                        if ($expected !== null) {
                            $slots = af_inv_candidate_slots_for_item(['entity'=>'equipment','subtype'=>$target['subtype'],'meta_json'=>$row['meta_json']]);
                            $candidates = $expected === 'accessory' ? ['accessory_1','accessory_2'] : [$expected];
                            if (!array_intersect($candidates,$slots)) $codes[] = 'inventory_equip_slot_unavailable';
                        }
                        if ($key === 'acs_test_energy_elixir') $slotProblem .= ' · Боевое применение: ACS_ITEM_EFFECT_UNSUPPORTED';
                    }
                } catch (Throwable $error) {
                    $codes[] = self::safeCode($error);
                }
            }
            if ($type === 'arpg_item') {
                $slotId = (int)($ledger['slots'][$key] ?? 0);
                $kbId = (int)($ledger['kb'][$key] ?? 0);
                $linked = $shopValid && $row && (int)$row['id'] === $kbId && self::row('af_shop_slots',
                    "slot_id={$slotId} AND shop_id={$shopId} AND cat_id={$categoryId} AND source_type='kb' AND source_ref_id={$kbId} AND kb_id={$kbId} AND kb_type='arpg_item' AND kb_key='{$key}' AND enabled=1");
                $slotProblem .= $linked ? ' · Shop: позиция связана' : ' · Shop: shop_slot_unlinked';
            }
            $title = self::esc($row['title_ru'] ?? $entry['title']);
            if ($row && (int)$row['id'] === (int)($ledger['kb'][$key] ?? 0) && $type !== 'arpg_mechanics') {
                $url = rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/'.af_kb_url(['type'=>$type,'key'=>$key]);
                $title = '<a href="'.self::esc($url).'">'.$title.'</a>';
            }
            $ok = match ($type) {
                'arpg_bestiary' => 'источник регистрации готов',
                'arpg_ability' => 'DTO нормализован и принят компилятором',
                default => 'KB valid',
            };
            echo '<li>'.$title.' <code>'.self::esc($key).'</code> — '.self::esc($codes ? implode(', ', $codes) : $ok)
                .self::esc($slotProblem).'</li>';
        }
        echo '</ul><p>Боевой запуск, покупка и экипировка проверяются вручную на выбранном аккаунте.</p>';
    }

    public static function render(AF_ACS_AuthorizationService $auth): void
    {
        global $mybb;
        $auth->requirePermission('admin.schema.manage');
        echo '<h2>Тестовый комплект ACS</h2><p>Установить тестовый комплект ACS</p>'
            .'<p>После подтверждения будут добавлены определения KB и отдельный магазин.</p>';

        $checks = [];
        $entries = [];
        $failure = '';
        $stage = 'ACS_TESTPACK_BOOTSTRAP_ERROR';
        try {
            self::prerequisites($checks);
            $stage = 'ACS_TESTPACK_DEFINITIONS_ERROR';
            @require_once __DIR__.'/TestpackDefinitions.php';
            if (($checks['Контракт KB'] ?? null) === '') {
                $elements = af_kb_arpg_primary_elements();
                if (count($elements) !== 17) throw new RuntimeException('ACS_TESTPACK_ELEMENT_REGISTRY_INVALID');
                $entries = AF_ACS_TestpackDefinitions::entries(af_kb_arpg_character_stat_registry(), $elements);
                if (!$entries) throw new RuntimeException('ACS_TESTPACK_DEFINITIONS_EMPTY');
            }
        } catch (Throwable $error) {
            $failure = $error instanceof Error ? $stage : self::safeCode($error);
        }
        $ready = $failure === '' && $entries && !array_filter($checks);
        echo '<h3>Зависимости</h3><table class="general"><thead><tr><th>Компонент</th><th>Состояние</th></tr></thead><tbody>';
        foreach ($checks as $name => $reason) {
            echo '<tr><td>'.self::esc($name).'</td><td>'.($reason === '' ? 'Доступно' : self::esc($reason)).'</td></tr>';
        }
        echo '</tbody></table>';
        if ($entries) {
            echo '<details open><summary>Состав комплекта</summary><ul>';
            foreach ($entries as $key => $entry) {
                echo '<li>'.self::esc($entry['title'].' · '.$entry['type'].' / '.$key)
                    .($entry['price'] > 0 ? ' · '.$entry['price'].' credits' : '').'</li>';
            }
            echo '</ul><p>Магазин «ACS — Испытательный арсенал», одна категория, девять товаров.</p></details>';
        } else {
            echo '<p>Список записей недоступен. Проверьте контракт KB и диагностику ниже.</p>';
        }

        $ledger = null;
        $created = false;
        $uid = (int)$mybb->user['uid'];
        try {
            if (($checks['af_kb_log'] ?? null) === '') $ledger = self::ledger();
            if ($mybb->request_method === 'post') {
                if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
                    throw new RuntimeException('ACS_TESTPACK_CSRF_INVALID');
                }
                if ($mybb->get_input('acs_command') !== 'install_testpack' || $mybb->get_input('acs_confirm') !== '1') {
                    throw new RuntimeException('ACS_TESTPACK_CONFIRMATION_REQUIRED');
                }
                if ($ledger === null) {
                    if (!$ready) throw new RuntimeException('ACS_TESTPACK_DEPENDENCY_UNAVAILABLE');
                    $created = self::install($entries,$uid);
                    $ledger = self::ledger();
                    if ($ledger === null) throw new RuntimeException('ACS_TESTPACK_PROVENANCE_INVALID');
                    if ($created && function_exists('log_admin_action')) {
                        log_admin_action('acs_install_testpack',AF_ACS_TestpackDefinitions::SHOP);
                    }
                }
            }
        } catch (Throwable $error) {
            if ($failure === '') $failure = self::safeCode($error);
        }

        $state = $failure !== '' || (!$ready && !$ledger) ? 'ошибка' : ($ledger ? 'установлен' : 'не установлен');
        echo '<p><strong>Статус: '.$state.'</strong></p>';
        if ($failure !== '' || !$ready) {
            echo '<p class="error">'.self::esc($failure ?: 'ACS_TESTPACK_DEPENDENCY_UNAVAILABLE').'</p>';
        }
        if ($ledger) {
            echo $created ? '<p>Тестовый комплект установлен.</p>' : '<p>Тестовый комплект уже установлен.</p>';
            if ($ready) {
                try {
                    self::readiness($entries,$ledger,$uid);
                } catch (Throwable $error) {
                    echo '<p class="error">Ошибка проверки: '.self::esc(self::safeCode($error)).'</p>';
                }
            }
        } elseif ($ready) {
            echo '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=testpack">'
                .'<input type="hidden" name="my_post_key" value="'.self::esc($mybb->post_code).'">'
                .'<input type="hidden" name="acs_command" value="install_testpack">'
                .'<label><input type="checkbox" name="acs_confirm" value="1" required> Подтверждаю создание перечисленных записей.</label> '
                .'<button type="submit" class="submit_button">Установить тестовый комплект ACS</button></form>';
        }
        echo '<p>Порядок подготовки персонажа и боя приведён в <a href="https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/main/docs/design/acs/ACS_TESTPACK_01_MANUAL_SETUP.md">инструкции ручной приёмки</a>.</p>';
    }
}
