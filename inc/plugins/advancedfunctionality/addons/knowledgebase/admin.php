<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_KnowledgeBase
{
    /**
     * AF ACP Router Canon:
     * /admin/index.php?module=advancedfunctionality&af_view=knowledgebase&do=...
     *
     * Мы НЕ используем tab/action/act, потому что они могут быть служебными.
     * Используем do=types|kinds|type_new|type_edit|type_delete|kind_new|kind_edit|kind_delete
     */
    private static function baseUrl(string $do, array $params = []): string
    {
        $q = array_merge([
            'module'  => 'advancedfunctionality',
            'af_view' => 'knowledgebase',
            'do'      => $do,
        ], $params);

        return 'index.php?' . http_build_query($q, '', '&');
    }

    private static function getDo(): string
    {
        $do = isset($_GET['do']) ? (string)$_GET['do'] : 'types';
        $allowed = [
            'types','kinds','registries',
            'type_new','type_edit','type_delete',
            'kind_new','kind_edit','kind_delete',
            'registry_new','registry_edit','registry_delete',
        ];
        if (!in_array($do, $allowed, true)) {
            $do = 'types';
        }
        return $do;
    }

    public static function dispatch(): void
    {
        global $db;

        $do = self::getDo();

        // POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_post_check((string)($_POST['my_post_key'] ?? ''));

            if ($do === 'type_new' || $do === 'type_edit') {
                self::saveType(); // redirect+exit
            } elseif ($do === 'kind_new' || $do === 'kind_edit') {
                self::saveKind(); // redirect+exit
            } elseif ($do === 'registry_new' || $do === 'registry_edit') {
                self::saveRegistry(); // redirect+exit
            }
        }

        // DELETE (GET + my_post_key)
        if ($do === 'type_delete') {
            self::deleteType(); // redirect+exit
        }
        if ($do === 'kind_delete') {
            self::deleteKind(); // redirect+exit
        }
        if ($do === 'registry_delete') {
            self::deleteRegistry(); // redirect+exit
        }

        // UI shell
        echo '<div class="group"><h3>Knowledge Base</h3>';
        echo '<div class="border_wrapper" style="padding:12px;">';

        echo '<p>';
        echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('types')).'">KB → Типы</a> ';
        echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('kinds')).'">KB → Подтипы предметов</a> ';
        echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('registries')).'">KB → ARPG registries</a>';
        echo '</p>';

        // render by do
        if ($do === 'kinds' || $do === 'kind_new' || $do === 'kind_edit') {
            self::renderKinds($do);
        } elseif ($do === 'registries' || $do === 'registry_new' || $do === 'registry_edit') {
            self::renderRegistries($do);
        } else {
            self::renderTypes($do);
        }

        echo '</div></div>';
    }

    private static function renderTypes(string $do): void
    {
        global $db, $mybb;

        // FORM
        if ($do === 'type_new' || $do === 'type_edit') {
            $id = (int)($_GET['id'] ?? 0);

            $row = [
                'id' => 0,
                'type_key' => '',
                'type' => '',
                'title_ru' => '',
                'title_en' => '',
                'desc_ru' => '',
                'desc_en' => '',
                'rules_schema' => 'af_kb.rules.v1',
                'ui_schema_json' => '{}',
                'is_active' => 1,
                'sortorder' => 0
            ];

            if ($do === 'type_edit' && $id > 0) {
                $found = $db->fetch_array($db->simple_select('af_kb_types', '*', 'id='.$id, ['limit'=>1]));
                if ($found) {
                    $row = array_merge($row, $found);
                    if (empty($row['type_key'])) {
                        $row['type_key'] = (string)($row['type'] ?? '');
                    }
                }
            }

            echo '<h4>'.(($do === 'type_edit' && $id) ? 'Редактировать тип' : 'Добавить тип').'</h4>';

            $actionUrl = self::baseUrl($do, ($do === 'type_edit' && $id) ? ['id' => $id] : []);

            echo '<form method="post" action="'.htmlspecialchars_uni($actionUrl).'">';
            echo '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';

            echo '<p>type_key: <input type="text" name="type_key" value="'.htmlspecialchars_uni((string)$row['type_key']).'" required /></p>';
            echo '<p>title_ru: <input type="text" name="title_ru" value="'.htmlspecialchars_uni((string)$row['title_ru']).'" required /></p>';
            echo '<p>title_en: <input type="text" name="title_en" value="'.htmlspecialchars_uni((string)$row['title_en']).'" required /></p>';
            echo '<p>rules_schema: <input type="text" name="rules_schema" value="'.htmlspecialchars_uni((string)$row['rules_schema']).'" /></p>';
            echo '<p>sortorder: <input type="number" name="sortorder" value="'.(int)$row['sortorder'].'" /></p>';
            echo '<p>active: <input type="checkbox" name="is_active" value="1" '.(!empty($row['is_active']) ? 'checked' : '').' /></p>';

            echo '<p>desc_ru:<br><textarea name="desc_ru" rows="3" style="width:100%;">'.htmlspecialchars_uni((string)$row['desc_ru']).'</textarea></p>';
            echo '<p>desc_en:<br><textarea name="desc_en" rows="3" style="width:100%;">'.htmlspecialchars_uni((string)$row['desc_en']).'</textarea></p>';
            echo '<p>ui_schema_json:<br><textarea name="ui_schema_json" rows="18" style="width:100%;">'.htmlspecialchars_uni((string)$row['ui_schema_json']).'</textarea></p>';

            echo '<p><button class="button button_yes" type="submit">Сохранить</button> ';
            echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('types')).'">Отмена</a></p>';

            echo '</form>';
            return;
        }

        // LIST
        echo '<p><a class="button" href="'.htmlspecialchars_uni(self::baseUrl('type_new')).'">Добавить тип</a></p>';

        echo '<table class="table">';
        echo '<tr><th>type_key</th><th>RU</th><th>EN</th><th>schema</th><th>active</th><th>sort</th><th></th></tr>';

        $q = $db->simple_select('af_kb_types', '*', '', ['order_by' => 'sortorder, type_key, type']);
        while ($row = $db->fetch_array($q)) {
            $key = (string)($row['type_key'] ?: $row['type']);

            $editUrl = self::baseUrl('type_edit', ['id' => (int)$row['id']]);
            $delUrl  = self::baseUrl('type_delete', [
                'id' => (int)$row['id'],
                'my_post_key' => $mybb->post_code
            ]);

            echo '<tr>';
            echo '<td>'.htmlspecialchars_uni($key).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_ru']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_en']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)($row['rules_schema'] ?: 'af_kb.rules.v1')).'</td>';
            echo '<td>'.(!empty($row['is_active']) ? '1' : '0').'</td>';
            echo '<td>'.(int)$row['sortorder'].'</td>';
            echo '<td><a href="'.htmlspecialchars_uni($editUrl).'">edit</a> | <a href="'.htmlspecialchars_uni($delUrl).'">delete</a></td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private static function renderKinds(string $do): void
    {
        global $db, $mybb;

        // FORM
        if ($do === 'kind_new' || $do === 'kind_edit') {
            $id = (int)($_GET['id'] ?? 0);

            $row = [
                'id' => 0,
                'kind_key' => '',
                'title_ru' => '',
                'title_en' => '',
                'desc_ru' => '',
                'desc_en' => '',
                'ui_schema_json' => '{}',
                'is_active' => 1,
                'sortorder' => 0
            ];

            if ($do === 'kind_edit' && $id > 0) {
                $found = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', 'id='.$id, ['limit'=>1]));
                if ($found) {
                    $row = array_merge($row, $found);
                }
            }

            echo '<h4>'.(($do === 'kind_edit' && $id) ? 'Редактировать подтип' : 'Добавить подтип').'</h4>';

            $actionUrl = self::baseUrl($do, ($do === 'kind_edit' && $id) ? ['id' => $id] : []);

            echo '<form method="post" action="'.htmlspecialchars_uni($actionUrl).'">';
            echo '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';

            echo '<p>kind_key: <input type="text" name="kind_key" value="'.htmlspecialchars_uni((string)$row['kind_key']).'" required /></p>';
            echo '<p>title_ru: <input type="text" name="title_ru" value="'.htmlspecialchars_uni((string)$row['title_ru']).'" required /></p>';
            echo '<p>title_en: <input type="text" name="title_en" value="'.htmlspecialchars_uni((string)$row['title_en']).'" required /></p>';
            echo '<p>sortorder: <input type="number" name="sortorder" value="'.(int)$row['sortorder'].'" /></p>';
            echo '<p>active: <input type="checkbox" name="is_active" value="1" '.(!empty($row['is_active']) ? 'checked' : '').' /></p>';

            echo '<p>ui_schema_json:<br><textarea name="ui_schema_json" rows="14" style="width:100%;">'.htmlspecialchars_uni((string)$row['ui_schema_json']).'</textarea></p>';

            echo '<p><button class="button button_yes" type="submit">Сохранить</button> ';
            echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('kinds')).'">Отмена</a></p>';

            echo '</form>';
            return;
        }

        // LIST
        echo '<p><a class="button" href="'.htmlspecialchars_uni(self::baseUrl('kind_new')).'">Добавить подтип</a></p>';

        echo '<table class="table">';
        echo '<tr><th>kind_key</th><th>RU</th><th>EN</th><th>active</th><th>sort</th><th></th></tr>';

        $q = $db->simple_select('af_kb_item_kinds', '*', '', ['order_by' => 'sortorder, kind_key']);
        while ($row = $db->fetch_array($q)) {

            $editUrl = self::baseUrl('kind_edit', ['id' => (int)$row['id']]);
            $delUrl  = self::baseUrl('kind_delete', [
                'id' => (int)$row['id'],
                'my_post_key' => $mybb->post_code
            ]);

            echo '<tr>';
            echo '<td>'.htmlspecialchars_uni((string)$row['kind_key']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_ru']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_en']).'</td>';
            echo '<td>'.(!empty($row['is_active']) ? '1' : '0').'</td>';
            echo '<td>'.(int)$row['sortorder'].'</td>';
            echo '<td><a href="'.htmlspecialchars_uni($editUrl).'">edit</a> | <a href="'.htmlspecialchars_uni($delUrl).'">delete</a></td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private static function saveType(): void
    {
        global $db;

        $id = (int)($_POST['id'] ?? 0);
        $key = preg_replace('~[^a-z0-9_]+~', '', strtolower((string)($_POST['type_key'] ?? '')));
        $schema = (string)($_POST['ui_schema_json'] ?? '{}');

        json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || $key === '') {
            flash_message('Ошибка JSON или type_key.', 'error');
            admin_redirect(self::baseUrl($id > 0 ? 'type_edit' : 'type_new', $id > 0 ? ['id' => $id] : []));
            exit;
        }

        $data = [
            'type' => $db->escape_string($key),
            'type_key' => $db->escape_string($key),
            'mechanic_key' => $db->escape_string(af_kb_get_default_mechanic_mode()),
            'title_ru' => $db->escape_string((string)($_POST['title_ru'] ?? '')),
            'title_en' => $db->escape_string((string)($_POST['title_en'] ?? '')),
            'desc_ru' => $db->escape_string((string)($_POST['desc_ru'] ?? '')),
            'desc_en' => $db->escape_string((string)($_POST['desc_en'] ?? '')),
            'rules_schema' => $db->escape_string((string)($_POST['rules_schema'] ?? 'af_kb.rules.v1')),
            'ui_schema_json' => $db->escape_string($schema),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'active' => !empty($_POST['is_active']) ? 1 : 0,
            'sortorder' => (int)($_POST['sortorder'] ?? 0),
            'updated_at' => TIME_NOW,
        ];

        if ($id > 0) {
            $db->update_query('af_kb_types', $data, 'id='.$id);
        } else {
            $db->insert_query('af_kb_types', $data);
        }

        flash_message('Тип сохранён.', 'success');
        admin_redirect(self::baseUrl('types'));
        exit;
    }

    private static function saveKind(): void
    {
        global $db;

        $id = (int)($_POST['id'] ?? 0);
        $key = preg_replace('~[^a-z0-9_]+~', '', strtolower((string)($_POST['kind_key'] ?? '')));
        $schema = (string)($_POST['ui_schema_json'] ?? '{}');

        json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || $key === '') {
            flash_message('Ошибка JSON или kind_key.', 'error');
            admin_redirect(self::baseUrl($id > 0 ? 'kind_edit' : 'kind_new', $id > 0 ? ['id' => $id] : []));
            exit;
        }

        $data = [
            'kind_key' => $db->escape_string($key),
            'title_ru' => $db->escape_string((string)($_POST['title_ru'] ?? '')),
            'title_en' => $db->escape_string((string)($_POST['title_en'] ?? '')),
            'ui_schema_json' => $db->escape_string($schema),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sortorder' => (int)($_POST['sortorder'] ?? 0),
            'updated_at' => TIME_NOW,
        ];

        if ($id > 0) {
            $db->update_query('af_kb_item_kinds', $data, 'id='.$id);
        } else {
            $db->insert_query('af_kb_item_kinds', $data);
        }

        flash_message('Подтип сохранён.', 'success');
        admin_redirect(self::baseUrl('kinds'));
        exit;
    }

    private static function deleteType(): void
    {
        global $db;

        verify_post_check((string)($_GET['my_post_key'] ?? ''));
        $id = (int)($_GET['id'] ?? 0);

        $row = $db->fetch_array($db->simple_select('af_kb_types', '*', 'id='.$id, ['limit' => 1]));
        if (!$row) {
            admin_redirect(self::baseUrl('types'));
            exit;
        }

        $type = (string)($row['type_key'] ?: $row['type']);
        $cnt = (int)$db->fetch_field(
            $db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', "type='".$db->escape_string($type)."'"),
            'cnt'
        );

        if ($cnt > 0) {
            flash_message('Нельзя удалить: есть записи этого типа.', 'error');
            admin_redirect(self::baseUrl('types'));
            exit;
        }

        $db->delete_query('af_kb_types', 'id='.$id);
        flash_message('Тип удалён.', 'success');
        admin_redirect(self::baseUrl('types'));
        exit;
    }

    private static function deleteKind(): void
    {
        global $db;

        verify_post_check((string)($_GET['my_post_key'] ?? ''));
        $id = (int)($_GET['id'] ?? 0);

        $row = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', 'id='.$id, ['limit' => 1]));
        if (!$row) {
            admin_redirect(self::baseUrl('kinds'));
            exit;
        }

        $cnt = (int)$db->fetch_field(
            $db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', "item_kind='".$db->escape_string((string)$row['kind_key'])."'"),
            'cnt'
        );

        if ($cnt > 0) {
            flash_message('Нельзя удалить: есть item-записи с этим kind.', 'error');
            admin_redirect(self::baseUrl('kinds'));
            exit;
        }

        $db->delete_query('af_kb_item_kinds', 'id='.$id);
        flash_message('Подтип удалён.', 'success');
        admin_redirect(self::baseUrl('kinds'));
        exit;
    }

    private static function renderRegistries(string $do): void
    {
        global $db, $mybb;

        if ($do === 'registry_new' || $do === 'registry_edit') {
            $id = (int)($_GET['id'] ?? 0);
            $row = [
                'id' => 0,
                'registry_key' => 'ability_type',
                'value_key' => '',
                'label_ru' => '',
                'label_en' => '',
                'sortorder' => 0,
                'is_active' => 1,
            ];
            if ($do === 'registry_edit' && $id > 0) {
                $found = $db->fetch_array($db->simple_select('af_kb_arpg_registries', '*', 'id='.$id, ['limit'=>1]));
                if ($found) {
                    $row = array_merge($row, $found);
                }
            }

            $registryOptions = af_kb_arpg_registry_keys_for_admin();
            echo '<h4>'.(($do === 'registry_edit' && $id) ? 'Редактировать значение registry' : 'Добавить значение registry').'</h4>';
            $actionUrl = self::baseUrl($do, ($do === 'registry_edit' && $id) ? ['id' => $id] : []);
            echo '<form method="post" action="'.htmlspecialchars_uni($actionUrl).'">';
            echo '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';

            echo '<p>registry_key: <select name="registry_key">';
            foreach ($registryOptions as $opt) {
                $selected = ((string)$row['registry_key'] === $opt) ? ' selected="selected"' : '';
                echo '<option value="'.htmlspecialchars_uni($opt).'"'.$selected.'>'.htmlspecialchars_uni($opt).'</option>';
            }
            echo '</select></p>';
            echo '<p>value_key: <input type="text" name="value_key" value="'.htmlspecialchars_uni((string)$row['value_key']).'" required /></p>';
            echo '<p>label_ru: <input type="text" name="label_ru" value="'.htmlspecialchars_uni((string)$row['label_ru']).'" required /></p>';
            echo '<p>label_en: <input type="text" name="label_en" value="'.htmlspecialchars_uni((string)$row['label_en']).'" required /></p>';
            echo '<p>sortorder: <input type="number" name="sortorder" value="'.(int)$row['sortorder'].'" /></p>';
            echo '<p>active: <input type="checkbox" name="is_active" value="1" '.(!empty($row['is_active']) ? 'checked' : '').' /></p>';
            echo '<p><button class="button button_yes" type="submit">Сохранить</button> ';
            echo '<a class="button" href="'.htmlspecialchars_uni(self::baseUrl('registries')).'">Отмена</a></p>';
            echo '</form>';
            return;
        }

        echo '<p><a class="button" href="'.htmlspecialchars_uni(self::baseUrl('registry_new')).'">Добавить значение</a></p>';
        echo '<table class="table">';
        echo '<tr><th>registry</th><th>value_key</th><th>RU</th><th>EN</th><th>active</th><th>sort</th><th></th></tr>';
        $q = $db->simple_select('af_kb_arpg_registries', '*', '', ['order_by' => 'registry_key, sortorder, value_key']);
        while ($row = $db->fetch_array($q)) {
            $editUrl = self::baseUrl('registry_edit', ['id' => (int)$row['id']]);
            $delUrl  = self::baseUrl('registry_delete', ['id' => (int)$row['id'], 'my_post_key' => $mybb->post_code]);
            echo '<tr>';
            echo '<td>'.htmlspecialchars_uni((string)$row['registry_key']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['value_key']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['label_ru']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['label_en']).'</td>';
            echo '<td>'.(!empty($row['is_active']) ? '1' : '0').'</td>';
            echo '<td>'.(int)$row['sortorder'].'</td>';
            echo '<td><a href="'.htmlspecialchars_uni($editUrl).'">edit</a> | <a href="'.htmlspecialchars_uni($delUrl).'">delete</a></td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private static function saveRegistry(): void
    {
        global $db;

        $id = (int)($_POST['id'] ?? 0);
        $registryKey = trim((string)($_POST['registry_key'] ?? ''));
        $valueKey = preg_replace('~[^a-z0-9_]+~', '', strtolower((string)($_POST['value_key'] ?? '')));
        if (!in_array($registryKey, af_kb_arpg_registry_keys_for_admin(), true) || $valueKey === '') {
            flash_message('Ошибка: некорректный registry_key или value_key.', 'error');
            admin_redirect(self::baseUrl($id > 0 ? 'registry_edit' : 'registry_new', $id > 0 ? ['id' => $id] : []));
            exit;
        }

        $data = [
            'registry_key' => $db->escape_string($registryKey),
            'value_key' => $db->escape_string($valueKey),
            'label_ru' => $db->escape_string((string)($_POST['label_ru'] ?? '')),
            'label_en' => $db->escape_string((string)($_POST['label_en'] ?? '')),
            'sortorder' => (int)($_POST['sortorder'] ?? 0),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'updated_at' => TIME_NOW,
        ];
        if ($id > 0) {
            $db->update_query('af_kb_arpg_registries', $data, 'id='.$id);
        } else {
            $existsWhere = "registry_key='" . $db->escape_string($registryKey) . "' AND value_key='" . $db->escape_string($valueKey) . "'";
            $exists = $db->fetch_array($db->simple_select('af_kb_arpg_registries', 'id', $existsWhere, ['limit' => 1]));
            if ($exists) {
                flash_message('Такое значение уже существует.', 'error');
                admin_redirect(self::baseUrl('registry_new'));
                exit;
            }
            $db->insert_query('af_kb_arpg_registries', $data);
        }
        flash_message('Registry сохранён.', 'success');
        admin_redirect(self::baseUrl('registries'));
        exit;
    }

    private static function deleteRegistry(): void
    {
        global $db;
        verify_post_check((string)($_GET['my_post_key'] ?? ''));
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $db->delete_query('af_kb_arpg_registries', 'id='.$id);
        }
        flash_message('Значение registry удалено.', 'success');
        admin_redirect(self::baseUrl('registries'));
        exit;
    }
}
