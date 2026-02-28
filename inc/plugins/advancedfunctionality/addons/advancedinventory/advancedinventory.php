<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVINV_ID', 'advancedinventory');
define('AF_ADVINV_BASE', AF_ADDONS . AF_ADVINV_ID . '/');
define('AF_ADVINV_TPL_DIR', AF_ADVINV_BASE . 'templates/');
define('AF_ADVINV_ASSET_DIR', AF_ADVINV_BASE . 'assets/');
define('AF_ADVINV_DEBUG_LOG', AF_CACHE . 'advancedinventory_debug.log');
define('AF_ADVINV_ALIAS_MARKER', "define('AF_ADVANCEDINVENTORY_PAGE_ALIAS', 1);");
define('AF_ADVINV_INVENTORIES_ALIAS_MARKER', "define('AF_ADVANCEDINVENTORIES_PAGE_ALIAS', 1);");
define('AF_ADVINV_TABLE_ITEMS', 'af_advinv_items');
define('AF_ADVINV_TABLE_SHOP_MAP', 'af_advinv_shop_map');
define('AF_ADVINV_TABLE_ENTITIES', 'af_advinv_entities');
define('AF_ADVINV_TABLE_ENTITY_FILTERS', 'af_advinv_entity_filters');

af_advancedinventory_init();

function af_advancedinventory_init(): void
{
    global $plugins;
    $plugins->add_hook('global_start', 'af_advancedinventory_register_routes', 10);
    $plugins->add_hook('misc_start', 'af_advancedinventory_misc_router', 10);
}

function af_advancedinventory_register_routes(): void
{
}

function af_advancedinventory_install(): void
{
    global $db, $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('advancedinventory');
    }

    $gid = af_advancedinventory_ensure_setting_group(
        $lang->af_advancedinventory_group ?? 'AF: Inventory',
        $lang->af_advancedinventory_group_desc ?? 'Inventory addon settings.'
    );
    af_advancedinventory_ensure_setting('af_advancedinventory_enabled', 'Enable inventory', 'Yes/No', 'yesno', '1', 1, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_view_groups', 'View groups', 'CSV group IDs that may view other inventories', 'text', '2', 2, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_perpage', 'Items per page', 'Per-page limit', 'numeric', '24', 3, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_default_tab', 'Default tab', 'Default tab for inventory page', 'text', 'equipment', 4, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_manage_groups', 'Manage groups', 'CSV group IDs that may open inventories.php and manage inventories', 'text', '3,4,6', 5, $gid);

    af_advancedinventory_ensure_inventory_storage();
    af_advancedinventory_upgrade_schema();

    if (!$db->table_exists('af_advinv_equipped')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_advinv_equipped (
            uid INT UNSIGNED NOT NULL,
            equip_slot VARCHAR(64) NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (uid, equip_slot),
            KEY uid_item (uid, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    af_advancedinventory_templates_install_or_update();
    af_advancedinventory_migrate_from_shop();
    af_advancedinventory_write_schema_markdown();
    if (!af_advancedinventory_alias_sync()) {
        af_advancedinventory_alias_sync_notice_on_failure();
    }

    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_activate(): void
{
    af_advancedinventory_upgrade_schema();
    af_advancedinventory_templates_install_or_update();
    af_advancedinventory_ensure_inventory_storage();
    af_advancedinventory_migrate_from_shop();
    af_advancedinventory_write_schema_markdown();
    if (!af_advancedinventory_alias_sync()) {
        af_advancedinventory_alias_sync_notice_on_failure();
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_write_schema_markdown(): void
{
    global $db;

    if (!defined('AF_CACHE')) {
        return;
    }

    $prefix = (string)TABLE_PREFIX;
    $schemaPath = AF_CACHE . 'advancedinventory_schema.md';
    $allTables = af_advancedinventory_schema_all_tables();

    $requiredTables = [
        $prefix . 'af_advinv_items',
        $prefix . 'af_advinv_equipped',
        $prefix . 'af_advinv_entities',
        $prefix . 'af_advinv_entity_filters',
        $prefix . 'af_advinv_shop_map',
        $prefix . 'af_shop_orders',
    ];

    foreach ($allTables as $tableName) {
        if (strpos($tableName, $prefix . 'af_shop_') === 0) {
            $requiredTables[] = $tableName;
        }
        if (strpos($tableName, $prefix . 'af_kb_') === 0) {
            $requiredTables[] = $tableName;
        }
        if (
            strpos($tableName, $prefix . 'af_') === 0
            && (
                strpos($tableName, 'inventory') !== false
                || strpos($tableName, 'legacy') !== false
            )
        ) {
            $requiredTables[] = $tableName;
        }
    }

    $requiredTables = array_values(array_unique($requiredTables));
    sort($requiredTables);

    $lines = [];
    $lines[] = '# Advanced Inventory / Shop DB schema';
    $lines[] = '';
    $lines[] = '- generated_at: ' . date('c');
    $lines[] = '- table_prefix: `' . $prefix . '`';
    $lines[] = '';

    foreach ($requiredTables as $tableName) {
        $plainName = substr($tableName, strlen($prefix));
        $exists = $db->table_exists($plainName);

        $lines[] = '## Таблица: `' . $tableName . '`';
        $lines[] = '';

        if (!$exists) {
            $lines[] = '_Не найдена в текущей БД._';
            $lines[] = '';
            continue;
        }

        $lines[] = '| name | type | null | default | key |';
        $lines[] = '|---|---|---|---|---|';

        $safeTable = str_replace('`', '``', $tableName);
        $res = $db->query("SHOW COLUMNS FROM `{$safeTable}`");
        while ($col = $db->fetch_array($res)) {
            $field = (string)($col['Field'] ?? '');
            $type = (string)($col['Type'] ?? '');
            $nullable = (string)($col['Null'] ?? '');
            $default = array_key_exists('Default', $col) && $col['Default'] !== null ? (string)$col['Default'] : 'NULL';
            $key = (string)($col['Key'] ?? '');
            $lines[] = '| `' . $field . '` | `' . $type . '` | `' . $nullable . '` | `' . str_replace('|', '\\|', $default) . '` | `' . $key . '` |';
        }

        $lines[] = '';
    }

    @file_put_contents($schemaPath, implode("\n", $lines) . "\n");
    af_advinv_debug_log('schema_markdown_written', [
        'path' => $schemaPath,
        'tables' => $requiredTables,
    ]);
}

function af_advancedinventory_schema_all_tables(): array
{
    global $db;

    $tables = [];
    $res = $db->query("SHOW TABLES");
    while ($row = $db->fetch_array($res)) {
        $name = (string)reset($row);
        if ($name !== '') {
            $tables[] = $name;
        }
    }
    return $tables;
}

function af_advancedinventory_deactivate(): void
{
    foreach (af_advancedinventory_alias_definitions() as $alias) {
        if (af_advancedinventory_alias_is_ours($alias['target'], $alias['marker'])) {
            @unlink($alias['target']);
        }
    }
}

function af_advancedinventory_uninstall(): void
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedinventory'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        $db->delete_query('settings', 'gid=' . $gid);
        $db->delete_query('settinggroups', 'gid=' . $gid);
    }
    $db->delete_query('templates', "title LIKE 'advancedinventory_%'");
    if ($db->table_exists(AF_ADVINV_TABLE_SHOP_MAP)) {
        $db->drop_table(AF_ADVINV_TABLE_SHOP_MAP);
    }
    if ($db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
        $db->drop_table(AF_ADVINV_TABLE_ENTITY_FILTERS);
    }
    if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
        $db->drop_table(AF_ADVINV_TABLE_ENTITIES);
    }

    foreach (af_advancedinventory_alias_definitions() as $alias) {
        if (af_advancedinventory_alias_is_ours($alias['target'], $alias['marker'])) {
            @unlink($alias['target']);
        }
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_is_installed(): bool
{
    global $db;
    return $db->table_exists(AF_ADVINV_TABLE_ITEMS);
}

function af_advancedinventory_alias_target_path(): string
{
    return MYBB_ROOT . 'inventory.php';
}

function af_advancedinventory_alias_definitions(): array
{
    return [
        [
            'target' => af_advancedinventory_alias_target_path(),
            'asset' => AF_ADVINV_ASSET_DIR . 'inventory.php',
            'marker' => AF_ADVINV_ALIAS_MARKER,
        ],
        [
            'target' => MYBB_ROOT . 'inventories.php',
            'asset' => AF_ADVINV_ASSET_DIR . 'inventories.php',
            'marker' => AF_ADVINV_INVENTORIES_ALIAS_MARKER,
        ],
    ];
}

function af_advancedinventory_alias_is_ours(string $path, string $marker = AF_ADVINV_ALIAS_MARKER): bool
{
    if (!is_file($path) || !is_readable($path)) { return false; }
    return strpos((string)file_get_contents($path), $marker) !== false;
}

function af_advancedinventory_alias_sync(): bool
{
    $ok = true;
    foreach (af_advancedinventory_alias_definitions() as $alias) {
        if (!is_file($alias['asset']) || !is_readable($alias['asset'])) { $ok = false; continue; }
        if (is_file($alias['target']) && !af_advancedinventory_alias_is_ours($alias['target'], $alias['marker'])) { $ok = false; continue; }
        $ok = (bool)@copy($alias['asset'], $alias['target']) && $ok;
    }
    return $ok;
}

function af_advancedinventory_alias_sync_notice_on_failure(): void
{
    $target = af_advancedinventory_alias_target_path();
    if (!is_file($target) || af_advancedinventory_alias_is_ours($target)) { return; }
    if (defined('IN_ADMINCP') && function_exists('flash_message')) {
        flash_message('Advanced Inventory: inventory.php already exists and is not managed by AF, alias was not installed.', 'error');
    }
}

function af_advancedinventory_alias_available(): bool
{
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'inventory.php') {
        return true;
    }
    return af_advancedinventory_alias_is_ours(af_advancedinventory_alias_target_path(), AF_ADVINV_ALIAS_MARKER);
}

function af_advancedinventory_url(string $action = 'inventory', array $params = [], bool $html = false): string
{
    $useAlias = af_advancedinventory_alias_available();
    $script = $useAlias ? 'inventory.php' : 'misc.php';
    if (!$useAlias || ($action !== '' && $action !== 'inventory' && $action !== 'view')) {
        $params = array_merge(['action' => $action], $params);
    }
    $url = $script;
    if ($params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url .= '?' . ($html ? str_replace('&', '&amp;', $query) : $query);
    }
    return $url;
}

function af_advancedinventory_misc_router(): void
{
    global $mybb;
    $action = (string)$mybb->get_input('action');
    if (!in_array($action, ['inventory', 'inventories', 'tab', 'entity', 'api_list', 'api_move', 'api_equip', 'api_unequip', 'api_update', 'api_delete'], true)) {
        return;
    }
    if (!af_advancedinventory_alias_available()) {
        af_advancedinventory_dispatch($action === '' ? 'inventory' : $action);
        return;
    }
    $params = $_GET;
    unset($params['action']);
    header('Location: ' . af_advancedinventory_url($action, $params));
    exit;
}

function af_advancedinventory_render_inventory_page(): void
{
    global $mybb;
    $action = (string)$mybb->get_input('action');
    if ($action === '' || $action === 'view' || $action === 'inventory') {
        $action = 'inventory';
    }
    af_advancedinventory_dispatch($action);
}

function af_advancedinventory_render_inventories_page(): void
{
    af_advancedinventory_dispatch('inventories');
}

function af_advancedinventory_dispatch(string $action): void
{
    switch ($action) {
        case 'inventory': af_advancedinventory_render_inventory(); return;
        case 'inventories': af_advancedinventory_render_inventories(); return;
        case 'tab':
        case 'entity': af_advancedinventory_render_tab(); return;
        case 'api_list': af_advancedinventory_api_list(); return;
        case 'api_equip': af_advancedinventory_api_equip(); return;
        case 'api_unequip': af_advancedinventory_api_unequip(); return;
        case 'api_update': af_advancedinventory_api_update(); return;
        case 'api_delete': af_advancedinventory_api_delete(); return;
        case 'api_move': af_advancedinventory_json(['ok' => false, 'error' => 'not_implemented'], 501); return;
    }
    error_no_permission();
}

function af_advancedinventory_render_inventory(): void
{
    global $mybb, $headerinclude, $header, $footer, $templates, $lang, $db;
    if ((int)($mybb->settings['af_advancedinventory_enabled'] ?? 1) !== 1) {
        error((string)($lang->af_advancedinventory_error_disabled ?? 'Inventory is disabled'));
    }

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) { $ownerUid = $viewerUid; }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) {
        error_no_permission();
    }

    $user = $db->fetch_array($db->simple_select('users', 'uid,username,avatar', 'uid=' . $ownerUid, ['limit' => 1]));
    if (!$user) { error_no_permission(); }

    $tabs = af_advancedinventory_tabs();
    if (!$tabs) {
        error('Inventory entities are not configured.');
    }
    $defaultTab = (string)($mybb->settings['af_advancedinventory_default_tab'] ?? '');
    if (!isset($tabs[$defaultTab])) {
        $defaultTab = (string)array_key_first($tabs);
    }

    $assetBase = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/';
    $cssFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.css';
    $jsFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.js';
    $vCss = @is_file($cssFile) ? (string)@filemtime($cssFile) : '1';
    $vJs = @is_file($jsFile) ? (string)@filemtime($jsFile) : '1';
    $headerinclude .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($assetBase . 'advancedinventory.css?v=' . rawurlencode($vCss)) . '">';
    $headerinclude .= '<script src="' . htmlspecialchars_uni($assetBase . 'advancedinventory.js?v=' . rawurlencode($vJs)) . '" defer></script>';

    $tabLinks = '';
    foreach ($tabs as $code => $title) {
        $active = $code === $defaultTab ? 'is-active' : '';
        $tabLinks .= '<button type="button" class="af-inv-tab ' . $active . '" data-entity="' . htmlspecialchars_uni($code) . '">' . htmlspecialchars_uni($title) . '</button>';
    }

    $firstUrl = af_advancedinventory_url('entity', ['uid' => $ownerUid, 'entity' => $defaultTab, 'sub' => 'all', 'ajax' => 1], true);
    eval('$af_inv_content = "' . $templates->get('advancedinventory_inventory_inner') . '";');
    eval('$page = "' . $templates->get('advancedinventory_inventory_page') . '";');
    output_page($page);
    exit;
}

function af_advancedinventory_ensure_inventory_storage(): void
{
    global $db;

    if (!$db->table_exists('af_inventory_items')) {
        return;
    }

    if (!$db->field_exists('inv_id', 'af_inventory_items')) {
        return;
    }

    if (!$db->table_exists('af_shop_inventory_legacy')) {
        $db->write_query("RENAME TABLE " . TABLE_PREFIX . "af_inventory_items TO " . TABLE_PREFIX . "af_shop_inventory_legacy");
    }
}

function af_advancedinventory_upgrade_schema(): void
{
    global $db;

    af_advinv_debug_log('schema_check_start', ['table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS]);

    if (!$db->table_exists(AF_ADVINV_TABLE_ITEMS)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
            entity VARCHAR(32) NOT NULL DEFAULT 'equipment',
            slot VARCHAR(32) NOT NULL DEFAULT 'stash',
            subtype VARCHAR(32) NOT NULL DEFAULT '',
            kb_type VARCHAR(32) NOT NULL DEFAULT '',
            kb_key VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            icon VARCHAR(255) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 1,
            meta_json MEDIUMTEXT NULL,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            KEY uid_entity (uid, entity),
            KEY uid_entity_subtype (uid, entity, subtype),
            KEY uid_slot (uid, slot),
            KEY uid_slot_subtype (uid, slot, subtype),
            KEY uid_kb (uid, kb_type, kb_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        af_advinv_debug_log('schema_upgrade_done', ['added_cols' => ['__table_created__'], 'added_keys' => ['uid_entity', 'uid_entity_subtype', 'uid_slot', 'uid_slot_subtype', 'uid_kb']]);
        af_advancedinventory_log_schema_columns();
    } else {
        $columns = af_advancedinventory_fetch_table_columns();
        af_advinv_debug_log('schema_columns', ['table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS, 'cols' => array_values($columns)]);

        $columnSql = [
            'uid' => "ADD COLUMN uid INT UNSIGNED NOT NULL",
            'entity' => "ADD COLUMN entity VARCHAR(32) NOT NULL DEFAULT 'equipment'",
            'slot' => "ADD COLUMN slot VARCHAR(32) NOT NULL DEFAULT 'stash'",
            'subtype' => "ADD COLUMN subtype VARCHAR(32) NOT NULL DEFAULT ''",
            'kb_type' => "ADD COLUMN kb_type VARCHAR(32) NOT NULL DEFAULT ''",
            'kb_key' => "ADD COLUMN kb_key VARCHAR(64) NOT NULL DEFAULT ''",
            'title' => "ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''",
            'icon' => "ADD COLUMN icon VARCHAR(255) NOT NULL DEFAULT ''",
            'qty' => "ADD COLUMN qty INT NOT NULL DEFAULT 1",
            'meta_json' => "ADD COLUMN meta_json MEDIUMTEXT NULL",
            'created_at' => "ADD COLUMN created_at INT UNSIGNED NOT NULL DEFAULT 0",
            'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
        ];

        $addedCols = [];
        foreach ($columnSql as $name => $alterSql) {
            if (in_array($name, $columns, true)) {
                continue;
            }
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " " . $alterSql);
            $addedCols[] = $name;
        }

        if (in_array('entity', $columns, true)) {
            $validSql = af_advinv_entities_sql_list();
            $db->write_query("UPDATE " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " SET entity = CASE
                WHEN slot IN ({$validSql}) THEN slot
                ELSE 'equipment'
            END
            WHERE entity = '' OR entity IS NULL OR entity NOT IN ({$validSql})");
        }

        $indexes = [];
        $indexQuery = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS);
        while ($row = $db->fetch_array($indexQuery)) {
            $indexes[(string)($row['Key_name'] ?? '')] = true;
        }

        $addedKeys = [];
        $indexSql = [
            'uid_entity' => 'ADD KEY uid_entity (uid, entity)',
            'uid_entity_subtype' => 'ADD KEY uid_entity_subtype (uid, entity, subtype)',
            'uid_slot' => 'ADD KEY uid_slot (uid, slot)',
            'uid_slot_subtype' => 'ADD KEY uid_slot_subtype (uid, slot, subtype)',
            'uid_kb' => 'ADD KEY uid_kb (uid, kb_type, kb_key)',
        ];
        foreach ($indexSql as $name => $alterSql) {
            if (isset($indexes[$name])) {
                continue;
            }
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " " . $alterSql);
            $addedKeys[] = $name;
        }

        af_advinv_debug_log('schema_upgrade_done', ['added_cols' => $addedCols, 'added_keys' => $addedKeys]);
        af_advancedinventory_log_schema_columns();
    }

    af_advinv_entities_upgrade_schema();
    af_advinv_entity_filters_upgrade_schema();
    af_advinv_shop_map_upgrade_schema();
}

function af_advinv_default_entities(): array
{
    return [
        ['entity' => 'equipment', 'title_ru' => 'Экипировка', 'title_en' => 'Equipment', 'enabled' => 1, 'sortorder' => 10, 'renderer' => 'generic', 'settings_json' => '{}'],
        ['entity' => 'resources', 'title_ru' => 'Ресурсы', 'title_en' => 'Resources', 'enabled' => 1, 'sortorder' => 20, 'renderer' => 'generic', 'settings_json' => '{}'],
        ['entity' => 'pets', 'title_ru' => 'Питомцы', 'title_en' => 'Pets', 'enabled' => 1, 'sortorder' => 30, 'renderer' => 'generic', 'settings_json' => '{}'],
        ['entity' => 'customization', 'title_ru' => 'Кастомизация профиля', 'title_en' => 'Customization', 'enabled' => 1, 'sortorder' => 40, 'renderer' => 'generic', 'settings_json' => '{}'],
    ];
}

function af_advinv_default_entity_filters(): array
{
    return [
        ['entity' => 'equipment', 'code' => 'weapon', 'title_ru' => 'Оружие', 'title_en' => 'Weapon', 'sortorder' => 10, 'match_json' => '{"kind":["weapon"],"type":["weapon"],"tags":["weapon"]}'],
        ['entity' => 'equipment', 'code' => 'armor', 'title_ru' => 'Броня', 'title_en' => 'Armor', 'sortorder' => 20, 'match_json' => '{"kind":["armor"],"type":["armor"],"tags":["armor"]}'],
        ['entity' => 'equipment', 'code' => 'ammo', 'title_ru' => 'Боеприпасы', 'title_en' => 'Ammo', 'sortorder' => 30, 'match_json' => '{"kind":["ammo"],"type":["ammo"],"tags":["ammo"]}'],
        ['entity' => 'equipment', 'code' => 'augmentations', 'title_ru' => 'Аугментации', 'title_en' => 'Augmentations', 'sortorder' => 40, 'match_json' => '{"kind":["augmentations","augmentation","cyberware"],"type":["augmentations"],"tags":["augmentations","augmentation","cyberware"]}'],
        ['entity' => 'equipment', 'code' => 'consumable', 'title_ru' => 'Расходники', 'title_en' => 'Consumable', 'sortorder' => 50, 'match_json' => '{"kind":["consumable"],"type":["consumable"],"tags":["consumable"]}'],
        ['entity' => 'resources', 'code' => 'loot', 'title_ru' => 'Добыча', 'title_en' => 'Loot', 'sortorder' => 10, 'match_json' => '{"tags":["loot"],"kind":["loot"],"type":["loot"]}'],
        ['entity' => 'resources', 'code' => 'chests', 'title_ru' => 'Сундуки', 'title_en' => 'Chests', 'sortorder' => 20, 'match_json' => '{"tags":["chest","chests"],"kind":["chest"],"type":["chest"]}'],
        ['entity' => 'resources', 'code' => 'stones', 'title_ru' => 'Камни', 'title_en' => 'Stones', 'sortorder' => 30, 'match_json' => '{"tags":["stone","stones"],"kind":["stone"],"type":["stone"]}'],
        ['entity' => 'pets', 'code' => 'eggs', 'title_ru' => 'Яйца', 'title_en' => 'Eggs', 'sortorder' => 10, 'match_json' => '{"tags":["egg","eggs"],"kind":["egg"],"type":["egg"]}'],
        ['entity' => 'pets', 'code' => 'pets', 'title_ru' => 'Питомцы', 'title_en' => 'Pets', 'sortorder' => 20, 'match_json' => '{"tags":["pet","pets"],"kind":["pet"],"type":["pet"]}'],
        ['entity' => 'customization', 'code' => 'profile', 'title_ru' => 'Профиль', 'title_en' => 'Profile', 'sortorder' => 10, 'match_json' => '{"tags":["profile"],"kind":["profile"],"type":["profile"]}'],
        ['entity' => 'customization', 'code' => 'postbit', 'title_ru' => 'Постбит', 'title_en' => 'Postbit', 'sortorder' => 20, 'match_json' => '{"tags":["postbit"],"kind":["postbit"],"type":["postbit"]}'],
        ['entity' => 'customization', 'code' => 'sheet', 'title_ru' => 'Лист персонажа', 'title_en' => 'Character sheet', 'sortorder' => 30, 'match_json' => '{"tags":["sheet"],"kind":["sheet"],"type":["sheet"]}'],
    ];
}

function af_advinv_entities_upgrade_schema(): void
{
    global $db;

    if (!$db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " (
            entity VARCHAR(32) NOT NULL,
            title_ru VARCHAR(255) NOT NULL DEFAULT '',
            title_en VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            renderer VARCHAR(32) NOT NULL DEFAULT 'generic',
            settings_json MEDIUMTEXT NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (entity),
            KEY enabled_sort (enabled, sortorder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $columns = [];
        $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES);
        while ($row = $db->fetch_array($q)) {
            $name = trim((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $columnSql = [
            'entity' => "ADD COLUMN entity VARCHAR(32) NOT NULL",
            'title_ru' => "ADD COLUMN title_ru VARCHAR(255) NOT NULL DEFAULT ''",
            'title_en' => "ADD COLUMN title_en VARCHAR(255) NOT NULL DEFAULT ''",
            'enabled' => "ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
            'sortorder' => "ADD COLUMN sortorder INT NOT NULL DEFAULT 0",
            'renderer' => "ADD COLUMN renderer VARCHAR(32) NOT NULL DEFAULT 'generic'",
            'settings_json' => "ADD COLUMN settings_json MEDIUMTEXT NULL",
            'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ($columnSql as $name => $sql) {
            if (!isset($columns[$name])) {
                $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " " . $sql);
            }
        }

        $indexes = [];
        $idxQ = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES);
        while ($idx = $db->fetch_array($idxQ)) {
            $indexes[(string)($idx['Key_name'] ?? '')] = true;
        }
        if (!isset($indexes['PRIMARY']) && isset($columns['entity'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " ADD PRIMARY KEY (entity)");
        }
        if (!isset($indexes['enabled_sort'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITIES . " ADD KEY enabled_sort (enabled, sortorder)");
        }
    }

    foreach (af_advinv_default_entities() as $entityRow) {
        $entity = (string)$entityRow['entity'];
        $exists = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'COUNT(*) AS c', "entity='" . $db->escape_string($entity) . "'", ['limit' => 1]), 'c');
        if ($exists > 0) {
            continue;
        }
        $db->insert_query(AF_ADVINV_TABLE_ENTITIES, [
            'entity' => $db->escape_string($entity),
            'title_ru' => $db->escape_string((string)$entityRow['title_ru']),
            'title_en' => $db->escape_string((string)$entityRow['title_en']),
            'enabled' => (int)$entityRow['enabled'],
            'sortorder' => (int)$entityRow['sortorder'],
            'renderer' => $db->escape_string((string)$entityRow['renderer']),
            'settings_json' => $db->escape_string((string)$entityRow['settings_json']),
            'updated_at' => TIME_NOW,
        ]);
    }
}

function af_advinv_entity_filters_upgrade_schema(): void
{
    global $db;

    if (!$db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . " (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity VARCHAR(32) NOT NULL,
            code VARCHAR(32) NOT NULL,
            title_ru VARCHAR(255) NOT NULL DEFAULT '',
            title_en VARCHAR(255) NOT NULL DEFAULT '',
            sortorder INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            match_json MEDIUMTEXT NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY entity_sort (entity, sortorder),
            KEY enabled_sort (entity, enabled, sortorder),
            UNIQUE KEY entity_code (entity, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $columns = [];
        $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS);
        while ($row = $db->fetch_array($q)) {
            $name = trim((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $columnSql = [
            'id' => "ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT",
            'entity' => "ADD COLUMN entity VARCHAR(32) NOT NULL",
            'code' => "ADD COLUMN code VARCHAR(32) NOT NULL",
            'title_ru' => "ADD COLUMN title_ru VARCHAR(255) NOT NULL DEFAULT ''",
            'title_en' => "ADD COLUMN title_en VARCHAR(255) NOT NULL DEFAULT ''",
            'sortorder' => "ADD COLUMN sortorder INT NOT NULL DEFAULT 0",
            'enabled' => "ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
            'match_json' => "ADD COLUMN match_json MEDIUMTEXT NULL",
            'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ($columnSql as $name => $sql) {
            if (!isset($columns[$name])) {
                $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . " " . $sql);
            }
        }

        $indexes = [];
        $idxQ = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS);
        while ($idx = $db->fetch_array($idxQ)) {
            $indexes[(string)($idx['Key_name'] ?? '')] = true;
        }
        if (!isset($indexes['entity_sort'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . " ADD KEY entity_sort (entity, sortorder)");
        }
        if (!isset($indexes['enabled_sort'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . " ADD KEY enabled_sort (entity, enabled, sortorder)");
        }
        if (!isset($indexes['entity_code'])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_ENTITY_FILTERS . " ADD UNIQUE KEY entity_code (entity, code)");
        }
    }

    foreach (af_advinv_default_entity_filters() as $row) {
        $entity = $db->escape_string((string)$row['entity']);
        $code = $db->escape_string((string)$row['code']);
        $exists = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, 'COUNT(*) AS c', "entity='{$entity}' AND code='{$code}'", ['limit' => 1]), 'c');
        if ($exists > 0) {
            continue;
        }
        $db->insert_query(AF_ADVINV_TABLE_ENTITY_FILTERS, [
            'entity' => $entity,
            'code' => $code,
            'title_ru' => $db->escape_string((string)$row['title_ru']),
            'title_en' => $db->escape_string((string)$row['title_en']),
            'sortorder' => (int)$row['sortorder'],
            'enabled' => 1,
            'match_json' => $db->escape_string((string)$row['match_json']),
            'updated_at' => TIME_NOW,
        ]);
    }
}

function af_advinv_shop_map_upgrade_schema(): void
{
    global $db;

    if (!$db->table_exists(AF_ADVINV_TABLE_SHOP_MAP)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_code VARCHAR(32) NOT NULL,
            shop_cat_id INT UNSIGNED NOT NULL DEFAULT 0,
            inventory_entity VARCHAR(32) NOT NULL,
            default_subtype VARCHAR(32) NULL,
            mode VARCHAR(16) NOT NULL DEFAULT 'mixed',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY shop_cat_enabled (shop_code, shop_cat_id, enabled, sortorder),
            KEY enabled_sort (enabled, sortorder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    $columns = [];
    $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP);
    while ($row = $db->fetch_array($q)) {
        $col = trim((string)($row['Field'] ?? ''));
        if ($col !== '') {
            $columns[$col] = true;
        }
    }

    $columnSql = [
        'shop_code' => "ADD COLUMN shop_code VARCHAR(32) NOT NULL DEFAULT ''",
        'shop_cat_id' => "ADD COLUMN shop_cat_id INT UNSIGNED NOT NULL DEFAULT 0",
        'inventory_entity' => "ADD COLUMN inventory_entity VARCHAR(32) NOT NULL DEFAULT 'resources'",
        'default_subtype' => "ADD COLUMN default_subtype VARCHAR(32) NULL",
        'mode' => "ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'mixed'",
        'enabled' => "ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
        'sortorder' => "ADD COLUMN sortorder INT UNSIGNED NOT NULL DEFAULT 0",
        'updated_at' => "ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0",
    ];

    foreach ($columnSql as $col => $sql) {
        if (!isset($columns[$col])) {
            $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " " . $sql);
        }
    }

    $indexes = [];
    $idxQ = $db->write_query("SHOW INDEX FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP);
    while ($idx = $db->fetch_array($idxQ)) {
        $indexes[(string)($idx['Key_name'] ?? '')] = true;
    }

    if (!isset($indexes['shop_cat_enabled'])) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " ADD KEY shop_cat_enabled (shop_code, shop_cat_id, enabled, sortorder)");
    }
    if (!isset($indexes['enabled_sort'])) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " ADD KEY enabled_sort (enabled, sortorder)");
    }

    // Legacy migration: map old shop_id/cat_id/entity to new shop_code/shop_cat_id/inventory_entity.
    if (isset($columns['shop_id']) && isset($columns['shop_code']) && $db->table_exists('af_shop')) {
        $db->write_query("UPDATE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " m
            INNER JOIN " . TABLE_PREFIX . "af_shop s ON(s.shop_id=m.shop_id)
            SET m.shop_code=s.code
            WHERE m.shop_code=''");
    }
    if (isset($columns['cat_id']) && isset($columns['shop_cat_id'])) {
        $db->write_query("UPDATE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " SET shop_cat_id=cat_id WHERE shop_cat_id=0");
    }
    if (isset($columns['entity']) && isset($columns['inventory_entity'])) {
        $db->write_query("UPDATE " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . " SET inventory_entity=entity WHERE inventory_entity='' OR inventory_entity='resources'");
    }
}

function af_advinv_shop_map_resolve(string $shopCode, int $shopCatId): array
{
    global $db;

    $shopCode = trim((string)$shopCode);
    if ($shopCode === '' || !$db->table_exists(AF_ADVINV_TABLE_SHOP_MAP)) {
        return [];
    }

    $shopCodeSql = $db->escape_string($shopCode);
    $shopCatId = max(0, (int)$shopCatId);

    $query = $db->query("SELECT id, inventory_entity, default_subtype, shop_cat_id, mode
        FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_SHOP_MAP . "
        WHERE shop_code='{$shopCodeSql}'
          AND enabled=1
          AND (shop_cat_id={$shopCatId} OR shop_cat_id=0)
        ORDER BY sortorder ASC, id ASC");

    while ($row = $db->fetch_array($query)) {
        $entity = af_advancedinventory_normalize_entity((string)($row['inventory_entity'] ?? ''));
        if (!af_advinv_entity_exists($entity)) {
            continue;
        }

        return [
            'id' => (int)$row['id'],
            'entity' => $entity,
            'default_subtype' => trim((string)($row['default_subtype'] ?? '')),
            'cat_id' => (int)($row['shop_cat_id'] ?? 0),
            'mode' => trim((string)($row['mode'] ?? 'mixed')),
        ];
    }

    return [];
}

function af_advancedinventory_fetch_table_columns(): array
{
    global $db;
    $cols = [];
    $q = $db->write_query("SHOW COLUMNS FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS);
    while ($row = $db->fetch_array($q)) {
        $col = trim((string)($row['Field'] ?? ''));
        if ($col !== '') {
            $cols[] = $col;
        }
    }
    return $cols;
}

function af_advancedinventory_log_schema_columns(): void
{
    af_advinv_debug_log('schema_columns', [
        'table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS,
        'cols' => af_advancedinventory_fetch_table_columns(),
    ]);
}

function af_advancedinventory_render_tab(): void
{
    global $mybb;
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) { $ownerUid = $viewerUid; }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) {
        error_no_permission();
    }

    $tabs = af_advancedinventory_tabs();
    $tab = (string)$mybb->get_input('entity');
    if ($tab === '') {
        $tab = (string)$mybb->get_input('tab');
    }
    if (!isset($tabs[$tab])) {
        $tab = (string)array_key_first($tabs);
    }
    if ($tab === '') {
        error_no_permission();
    }

    $sub = trim((string)$mybb->get_input('sub'));
    $page = max(1, (int)$mybb->get_input('page'));
    $ajax = (int)$mybb->get_input('ajax') === 1;

    $html = af_advinv_render_entity_tab($tab, $ownerUid, $sub, $page, $ajax);
    if ($ajax || defined('XMLHTTP_REQUEST')) {
        echo $html;
        exit;
    }
    output_page($html);
    exit;
}

function af_advancedinventory_render_inventories(): void
{
    global $db, $mybb, $headerinclude, $header, $footer;
    if (!af_advancedinventory_user_can_manage()) {
        error_no_permission();
    }
    $page = max(1, (int)$mybb->get_input('page'));
    $perPage = 20;
    $username = trim((string)$mybb->get_input('username'));
    $entity = trim((string)$mybb->get_input('entity'));
    if ($entity === '') {
        $entity = trim((string)$mybb->get_input('slot'));
    }
    $state = trim((string)$mybb->get_input('state'));
    $where = ['u.uid>0'];
    if ($username !== '') {
        $like = $db->escape_string_like($username);
        $where[] = "u.username LIKE '%{$like}%'";
    }
    if ($entity !== '') {
        $where[] = "EXISTS(SELECT 1 FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " i2 WHERE i2.uid=u.uid AND i2.entity='" . $db->escape_string(af_advancedinventory_normalize_entity($entity)) . "')";
    }
    if ($state === 'empty') {
        $where[] = 'COALESCE(inv.total_rows,0)=0';
    } elseif ($state === 'nonempty') {
        $where[] = 'COALESCE(inv.total_rows,0)>0';
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)$db->fetch_field($db->query("SELECT COUNT(*) AS c FROM " . TABLE_PREFIX . "users u LEFT JOIN (SELECT uid, COUNT(*) total_rows, COALESCE(SUM(qty),0) total_qty, MAX(updated_at) updated_at FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " GROUP BY uid) inv ON(inv.uid=u.uid) WHERE {$whereSql}"), 'c');
    $offset = ($page - 1) * $perPage;
    $q = $db->query("SELECT u.uid,u.username,COALESCE(inv.total_rows,0) total_rows,COALESCE(inv.total_qty,0) total_qty,COALESCE(inv.updated_at,0) updated_at FROM " . TABLE_PREFIX . "users u LEFT JOIN (SELECT uid, COUNT(*) total_rows, COALESCE(SUM(qty),0) total_qty, MAX(updated_at) updated_at FROM " . TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS . " GROUP BY uid) inv ON(inv.uid=u.uid) WHERE {$whereSql} ORDER BY inv.updated_at DESC, u.username ASC LIMIT {$offset},{$perPage}");
    $rows = '';
    while ($row = $db->fetch_array($q)) {
        $invUrl = af_advancedinventory_url('inventory', ['uid' => (int)$row['uid']], true);
        $rows .= '<tr><td>' . htmlspecialchars_uni((string)$row['username']) . '</td><td><a href="' . $invUrl . '">Открыть инвентарь</a></td><td>' . (int)$row['total_rows'] . '</td><td>' . (int)$row['total_qty'] . '</td><td>' . ((int)$row['updated_at'] > 0 ? my_date('relative', (int)$row['updated_at']) : '-') . '</td></tr>';
    }
    $pages = max(1, (int)ceil($total / $perPage));
    $pager = '';
    for ($i = 1; $i <= $pages; $i++) {
        $url = 'inventories.php?page=' . $i . '&username=' . rawurlencode($username) . '&entity=' . rawurlencode($entity) . '&state=' . rawurlencode($state);
        $pager .= '<a class="af-page' . ($i === $page ? ' is-active' : '') . '" href="' . htmlspecialchars_uni($url) . '">' . $i . '</a> ';
    }
    $headerinclude .= '<link rel="stylesheet" href="' . htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'], '/') . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/advancedinventory.css') . '">';
    $entityOptions = '<option value="">Все entity</option>';
    foreach (af_advancedinventory_tabs(false) as $entitySlug => $entityTitle) {
        $selected = $entity === $entitySlug ? ' selected' : '';
        $entityOptions .= '<option value="' . htmlspecialchars_uni($entitySlug) . '"' . $selected . '>' . htmlspecialchars_uni($entitySlug) . '</option>';
    }
    $html = '<!DOCTYPE html><html><head><title>Инвентари пользователей</title>' . $headerinclude . '</head><body>' . $header;
    $html .= '<div class="af-box"><h1>Все инвентари пользователей</h1><form method="get" action="inventories.php"><input type="text" name="username" placeholder="username" value="' . htmlspecialchars_uni($username) . '"> <select name="entity">' . $entityOptions . '</select> <select name="state"><option value="">Все</option><option value="empty"' . ($state === 'empty' ? ' selected' : '') . '>Пустые</option><option value="nonempty"' . ($state === 'nonempty' ? ' selected' : '') . '>Непустые</option></select> <button type="submit">Фильтр</button></form>';
    $html .= '<table class="tborder"><thead><tr><th>User</th><th>Инвентарь</th><th>Rows</th><th>Sum qty</th><th>Updated</th></tr></thead><tbody>' . $rows . '</tbody></table><div>' . $pager . '</div></div>';
    $html .= $footer . '</body></html>';
    output_page($html);
    exit;
}

function af_advancedinventory_require_post(): void
{
    global $mybb;
    if (strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
        af_advancedinventory_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    verify_post_check($mybb->get_input('post_key'), true);
}

function af_advancedinventory_api_equip(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    $ownerUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_can_manage_owner($viewerUid, $ownerUid)) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $item = af_inv_get_item_for_owner($ownerUid, (int)$mybb->get_input('item_id'));
    if (!$item) { af_advancedinventory_json(['ok' => false, 'error' => 'item_not_found'], 404); }
    $slot = trim((string)$mybb->get_input('equip_slot'));
    $allowedSlots = af_inv_candidate_slots_for_item($item);
    if ($slot === '' && $allowedSlots) { $slot = (string)$allowedSlots[0]; }
    if ($slot === '' || !in_array($slot, $allowedSlots, true)) { af_advancedinventory_json(['ok' => false, 'error' => 'slot_invalid'], 422); }
    $db->delete_query('af_advinv_equipped', 'uid=' . $ownerUid . ' AND item_id=' . (int)$item['id']);
    $exists = (int)$db->fetch_field($db->simple_select('af_advinv_equipped', 'COUNT(*) c', "uid={$ownerUid} AND equip_slot='" . $db->escape_string($slot) . "'"), 'c');
    if ($exists > 0) {
        $db->update_query('af_advinv_equipped', ['item_id' => (int)$item['id'], 'updated_at' => TIME_NOW], "uid={$ownerUid} AND equip_slot='" . $db->escape_string($slot) . "'");
    } else {
        $db->insert_query('af_advinv_equipped', ['uid' => $ownerUid, 'equip_slot' => $db->escape_string($slot), 'item_id' => (int)$item['id'], 'updated_at' => TIME_NOW]);
    }
    af_advancedinventory_json(['ok' => true]);
}

function af_advancedinventory_api_unequip(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    $ownerUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_can_manage_owner($viewerUid, $ownerUid)) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $slot = trim((string)$mybb->get_input('equip_slot'));
    if ($slot !== '') {
        $db->delete_query('af_advinv_equipped', 'uid=' . $ownerUid . " AND equip_slot='" . $db->escape_string($slot) . "'");
    } else {
        $db->delete_query('af_advinv_equipped', 'uid=' . $ownerUid . ' AND item_id=' . (int)$mybb->get_input('item_id'));
    }
    af_advancedinventory_json(['ok' => true]);
}

function af_advancedinventory_api_update(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    if (!af_advancedinventory_user_can_manage()) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $uid = (int)$mybb->get_input('uid');
    $itemId = (int)$mybb->get_input('item_id');
    $qty = max(1, (int)$mybb->get_input('qty'));
    $rawEntity = trim((string)$mybb->get_input('entity'));
    $entity = af_advancedinventory_normalize_entity($rawEntity);
    $slot = trim((string)$mybb->get_input('slot'));
    $row = ['qty' => $qty, 'updated_at' => TIME_NOW];
    if ($rawEntity !== '') {
        $row['entity'] = $db->escape_string($entity);
    }
    if ($slot !== '') { $row['slot'] = $db->escape_string(substr($slot, 0, 32)); }
    $db->update_query(AF_ADVINV_TABLE_ITEMS, $row, 'id=' . $itemId . ' AND uid=' . $uid);
    af_advancedinventory_json(['ok' => true]);
}

function af_advancedinventory_api_delete(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    if (!af_advancedinventory_user_can_manage()) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $uid = (int)$mybb->get_input('uid');
    $itemId = (int)$mybb->get_input('item_id');
    $db->delete_query(AF_ADVINV_TABLE_ITEMS, 'id=' . $itemId . ' AND uid=' . $uid);
    $db->delete_query('af_advinv_equipped', 'uid=' . $uid . ' AND item_id=' . $itemId);
    af_advancedinventory_json(['ok' => true]);
}

function af_advancedinventory_api_list(): void
{
    global $mybb;
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) { $ownerUid = $viewerUid; }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) {
        af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    $entity = (string)$mybb->get_input('entity');
    if ($entity === '') {
        $entity = (string)$mybb->get_input('slot');
    }
    af_advancedinventory_json(['ok' => true, 'data' => af_inv_get_items($ownerUid, ['entity' => $entity, 'subtype' => (string)$mybb->get_input('subtype'), 'search' => (string)$mybb->get_input('search'), 'page' => (int)$mybb->get_input('page')])]);
}

function af_advinv_debug_log(string $event, array $context = []): void
{
    $line = '[AF-ADVINV][' . date('c') . '][' . $event . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @error_log($line);
    @file_put_contents(AF_ADVINV_DEBUG_LOG, $line . "\n", FILE_APPEND);
}

function af_advinv_kb_table_sql(): string
{
    if (function_exists('af_advancedshop_kb_table')) {
        return af_advancedshop_kb_table();
    }
    return TABLE_PREFIX . 'af_kb_entries';
}

function af_advinv_kb_cols(): array
{
    if (function_exists('af_advancedshop_kb_cols')) {
        return af_advancedshop_kb_cols();
    }
    return [
        'id' => 'id',
        'type' => '`type`',
        'key' => '`key`',
        'title_ru' => 'title_ru',
        'title_en' => 'title_en',
        'title' => '',
        'meta_json' => 'meta_json',
    ];
}

function af_advinv_kb_extract_icon(?string $metaJson): string
{
    if (!is_string($metaJson) || trim($metaJson) === '') {
        return '';
    }
    $meta = @json_decode($metaJson, true);
    if (!is_array($meta)) {
        return '';
    }
    return trim((string)($meta['ui']['icon_url'] ?? $meta['icon_url'] ?? ''));
}

function af_advinv_enrich_items_from_kb(array $items): array
{
    global $db;
    if (!$items || !$db->table_exists('af_kb_entries')) {
        return $items;
    }

    $keys = [];
    foreach ($items as $item) {
        $title = trim((string)($item['title'] ?? ''));
        $icon = trim((string)($item['icon'] ?? ''));
        $kbKey = trim((string)($item['kb_key'] ?? ''));
        if ($kbKey === '' || ($title !== '' && $icon !== '')) {
            continue;
        }
        $kbType = trim((string)($item['kb_type'] ?? ''));
        $keys[$kbType . '||' . $kbKey] = ['kb_type' => $kbType, 'kb_key' => $kbKey];
    }
    if (!$keys) {
        return $items;
    }

    $kbCols = af_advinv_kb_cols();
    $keyExpr = $kbCols['key'] ?? '`key`';
    $typeExpr = $kbCols['type'] ?? '`type`';
    $titleRuExpr = $kbCols['title_ru'] ?: "''";
    $titleEnExpr = $kbCols['title_en'] ?: "''";
    $titleExpr = $kbCols['title'] ?: "''";
    $metaExpr = $kbCols['meta_json'] ?: "''";
    $whereOr = [];
    foreach ($keys as $pair) {
        $whereOr[] = '(' . $typeExpr . "='" . $db->escape_string($pair['kb_type']) . "' AND " . $keyExpr . "='" . $db->escape_string($pair['kb_key']) . "')";
    }
    if (!$whereOr) {
        return $items;
    }

    $q = $db->query("SELECT " . $typeExpr . " AS kb_type, " . $keyExpr . " AS kb_key, " . $titleRuExpr . " AS title_ru, " . $titleEnExpr . " AS title_en, " . $titleExpr . " AS title_plain, " . $metaExpr . " AS meta_json FROM " . af_advinv_kb_table_sql() . " WHERE " . implode(' OR ', $whereOr));
    $kbMap = [];
    while ($row = $db->fetch_array($q)) {
        $t = trim((string)($row['title_ru'] ?? ''));
        if ($t === '') { $t = trim((string)($row['title_en'] ?? '')); }
        if ($t === '') { $t = trim((string)($row['title_plain'] ?? '')); }
        $kbType = trim((string)($row['kb_type'] ?? ''));
        $kbKey = trim((string)($row['kb_key'] ?? ''));
        $kbMap[$kbType . '||' . $kbKey] = [
            'title' => $t,
            'icon' => af_advinv_kb_extract_icon((string)($row['meta_json'] ?? '')),
        ];
    }

    foreach ($items as &$item) {
        $kbType = trim((string)($item['kb_type'] ?? ''));
        $kbKey = trim((string)($item['kb_key'] ?? ''));
        if ($kbKey === '') {
            continue;
        }
        $mapKey = $kbType . '||' . $kbKey;
        $fromKb = $kbMap[$mapKey] ?? null;
        if (!$fromKb) {
            if (trim((string)($item['title'] ?? '')) === '') {
                $item['title'] = $kbKey;
            }
            continue;
        }
        if (trim((string)($item['title'] ?? '')) === '') {
            $item['title'] = $fromKb['title'] !== '' ? $fromKb['title'] : $kbKey;
        }
        if (trim((string)($item['icon'] ?? '')) === '' && $fromKb['icon'] !== '') {
            $item['icon'] = $fromKb['icon'];
        }
    }
    unset($item);

    return $items;
}

function af_advinv_classify_equipment_from_kb_meta(array $kbMeta): string
{
    $normalizeKind = static function (string $raw): string {
        $kind = mb_strtolower(trim($raw));
        if ($kind === 'augmentation' || $kind === 'cyberware') {
            return 'augmentations';
        }
        if (in_array($kind, ['weapon', 'armor', 'ammo', 'consumable', 'augmentations'], true)) {
            return $kind;
        }
        return '';
    };

    $rulesItem = is_array($kbMeta['rules']['item'] ?? null) ? (array)$kbMeta['rules']['item'] : [];

    $kindFromRules = $normalizeKind((string)($rulesItem['item_kind'] ?? ''));
    if ($kindFromRules !== '') {
        return $kindFromRules;
    }

    $typeFromRules = $normalizeKind((string)($rulesItem['item_type'] ?? ''));
    if ($typeFromRules !== '') {
        return $typeFromRules;
    }

    foreach (is_array($rulesItem['tags'] ?? null) ? $rulesItem['tags'] : [] as $tag) {
        $kindFromTag = $normalizeKind((string)$tag);
        if ($kindFromTag !== '') {
            return $kindFromTag;
        }
    }

    $kind = $normalizeKind((string)($kbMeta['item_kind'] ?? ''));
    if ($kind !== '') {
        return $kind;
    }

    $type = $normalizeKind((string)($kbMeta['item_type'] ?? ''));
    if ($type !== '') {
        return $type;
    }

    foreach (is_array($kbMeta['tags'] ?? null) ? $kbMeta['tags'] : [] as $tag) {
        $kindFromTag = $normalizeKind((string)$tag);
        if ($kindFromTag !== '') {
            return $kindFromTag;
        }
    }

    return '';
}

function af_advinv_match_subtype_by_entity_filters(string $entity, array $kbMeta): string
{
    $filters = af_advinv_get_entity_filters($entity);
    if (!$filters) {
        return '';
    }

    foreach ($filters as $filter) {
        $match = (array)($filter['match'] ?? []);
        if (!$match) {
            continue;
        }
        if (af_advinv_match_filter_rule($match, $kbMeta)) {
            return (string)($filter['code'] ?? '');
        }
    }

    return '';
}

function af_advinv_match_filter_rule(array $rule, array $kbMeta): bool
{
    $fields = [
        'kind' => af_advinv_collect_meta_values($kbMeta, ['rules.item.item_kind', 'rules.item.kind', 'item_kind', 'kind']),
        'type' => af_advinv_collect_meta_values($kbMeta, ['rules.item.item_type', 'rules.item.type', 'item_type', 'type']),
        'tags' => af_advinv_collect_meta_values($kbMeta, ['rules.item.tags', 'tags']),
    ];

    foreach (['kind', 'type', 'tags'] as $field) {
        if (!array_key_exists($field, $rule)) {
            continue;
        }

        $needles = is_array($rule[$field]) ? $rule[$field] : [$rule[$field]];
        $needlesNorm = [];
        foreach ($needles as $needle) {
            $needleNorm = mb_strtolower(trim((string)$needle));
            if ($needleNorm !== '') {
                $needlesNorm[$needleNorm] = true;
            }
        }
        if (!$needlesNorm) {
            continue;
        }

        $values = $fields[$field] ?? [];
        $matched = false;
        foreach ($values as $val) {
            if (isset($needlesNorm[$val])) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return false;
        }
    }

    return true;
}

function af_advinv_collect_meta_values(array $meta, array $paths): array
{
    $out = [];
    foreach ($paths as $path) {
        $cursor = $meta;
        $ok = true;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                $ok = false;
                break;
            }
            $cursor = $cursor[$segment];
        }
        if (!$ok) {
            continue;
        }

        $vals = is_array($cursor) ? $cursor : [$cursor];
        foreach ($vals as $value) {
            $norm = mb_strtolower(trim((string)$value));
            if ($norm !== '') {
                $out[$norm] = true;
            }
        }
    }
    return array_keys($out);
}

function af_inv_add_item(int $uid, array $item): int
{
    global $db;
    $uid = max(0, $uid);
    if ($uid <= 0) { return 0; }
    $now = TIME_NOW;
    $entity = af_advancedinventory_normalize_entity((string)($item['entity'] ?? ($item['slot'] ?? 'equipment')));
    $slot = substr(trim((string)($item['slot'] ?? $entity)), 0, 32);
    $subtype = substr(trim((string)($item['subtype'] ?? '')), 0, 32);
    $kbType = substr(trim((string)($item['kb_type'] ?? '')), 0, 32);
    $kbKey = substr(trim((string)($item['kb_key'] ?? '')), 0, 64);
    $title = substr(trim((string)($item['title'] ?? '')), 0, 255);
    $icon = substr(trim((string)($item['icon'] ?? '')), 0, 255);
    $qty = max(1, (int)($item['qty'] ?? 1));
    $metaJson = is_string($item['meta_json'] ?? null) ? (string)$item['meta_json'] : json_encode((array)($item['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $meta = @json_decode((string)$metaJson, true);
    $meta = is_array($meta) ? $meta : [];

    $equipmentKinds = ['weapon', 'armor', 'ammo', 'augmentations', 'consumable'];
    if ($slot === '') {
        $slot = $entity;
    }
    if ($entity === 'resources' && in_array($subtype, $equipmentKinds, true)) {
        $entity = 'equipment';
        $slot = 'equipment';
    }

    if ($entity === 'equipment') {
        if ($subtype === '' || $subtype === 'misc') {
            $fromMeta = af_advinv_classify_equipment_from_kb_meta($meta);
            if ($fromMeta !== '' && in_array($fromMeta, $equipmentKinds, true)) {
                $subtype = $fromMeta;
            }
        }

        if ($subtype === '') {
            $kbKeyNormalized = mb_strtolower($kbKey);
            if (strpos($kbKeyNormalized, 'gun') === 0) {
                $subtype = 'weapon';
            }
        }
    }

    if ($subtype === '' || $subtype === 'misc') {
        $mappedSubtype = af_advinv_match_subtype_by_entity_filters($entity, $meta);
        if ($mappedSubtype !== '') {
            $subtype = $mappedSubtype;
        }
    }

    $metaHash = md5((string)$metaJson);

    $columns = af_advancedinventory_fetch_table_columns();
    $hasTitleColumn = in_array('title', $columns, true);
    $hasIconColumn = in_array('icon', $columns, true);

    af_advinv_debug_log('af_inv_add_item_start', [
        'uid' => $uid,
        'entity' => $entity,
        'slot' => $slot,
        'subtype' => $subtype,
        'kb_type' => $kbType,
        'kb_key' => $kbKey,
        'qty' => $qty,
        'table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS,
    ]);

    af_advinv_debug_log('af_inv_add_item_input', [
        'uid' => $uid,
        'entity' => $entity,
        'slot' => $slot,
        'subtype' => $subtype,
        'kb_type' => $kbType,
        'kb_key' => $kbKey,
        'title' => $title,
        'qty' => $qty,
    ]);

    $where = "uid={$uid} AND entity='" . $db->escape_string($entity) . "' AND subtype='" . $db->escape_string($subtype) . "' AND kb_type='" . $db->escape_string($kbType) . "' AND kb_key='" . $db->escape_string($kbKey) . "' AND MD5(COALESCE(meta_json,''))='" . $db->escape_string($metaHash) . "'";
    $row = $db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ITEMS, 'id,qty', $where, ['limit' => 1]));
    if ($row) {
        $id = (int)$row['id'];
        $newQty = (int)$row['qty'] + $qty;
        $updatePayload = ['qty' => $newQty, 'updated_at' => $now];
        if ($hasTitleColumn) {
            $updatePayload['title'] = $db->escape_string($title);
        }
        if ($hasIconColumn) {
            $updatePayload['icon'] = $db->escape_string($icon);
        }

        af_advinv_debug_log('checkout_db_op', [
            'stage' => 'af_inv_add_item_update',
            'op' => 'update_query',
            'table' => AF_ADVINV_TABLE_ITEMS,
            'fields' => array_keys($updatePayload),
            'uid' => $uid,
            'item_payload' => $item,
        ]);

        $db->update_query(AF_ADVINV_TABLE_ITEMS, $updatePayload, 'id=' . $id);
        af_advinv_debug_log('af_inv_add_item_merged', ['uid' => $uid, 'id' => $id, 'old_qty' => (int)$row['qty'], 'new_qty' => $newQty]);
        return $id;
    }

    $insertPayload = [
        'uid' => $uid,
        'entity' => $db->escape_string($entity),
        'slot' => $db->escape_string($slot),
        'subtype' => $db->escape_string($subtype),
        'kb_type' => $db->escape_string($kbType),
        'kb_key' => $db->escape_string($kbKey),
        'qty' => $qty,
        'meta_json' => $db->escape_string((string)$metaJson),
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if ($hasTitleColumn) {
        $insertPayload['title'] = $db->escape_string($title);
    }
    if ($hasIconColumn) {
        $insertPayload['icon'] = $db->escape_string($icon);
    }

    af_advinv_debug_log('checkout_db_op', [
        'stage' => 'af_inv_add_item_insert',
        'op' => 'insert_query',
        'table' => AF_ADVINV_TABLE_ITEMS,
        'fields' => array_keys($insertPayload),
        'uid' => $uid,
        'item_payload' => $item,
    ]);

    $newId = (int)$db->insert_query(AF_ADVINV_TABLE_ITEMS, $insertPayload);
    af_advinv_debug_log('af_inv_add_item_inserted', ['uid' => $uid, 'id' => $newId]);
    return $newId;
}

function af_inv_remove_item(int $uid, int $itemId, int $qty = 1): bool
{
    global $db;
    $row = $db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ITEMS, 'id,qty', 'id=' . (int)$itemId . ' AND uid=' . (int)$uid, ['limit' => 1]));
    if (!$row) { return false; }
    $left = (int)$row['qty'] - max(1, $qty);
    if ($left <= 0) {
        $db->delete_query(AF_ADVINV_TABLE_ITEMS, 'id=' . (int)$row['id'] . ' AND uid=' . (int)$uid);
        return true;
    }
    $db->update_query(AF_ADVINV_TABLE_ITEMS, ['qty' => $left, 'updated_at' => TIME_NOW], 'id=' . (int)$row['id'] . ' AND uid=' . (int)$uid);
    return true;
}

function af_inv_get_items(int $uid, array $filters = []): array
{
    global $db, $mybb;
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['perPage'] ?? ($mybb->settings['af_advancedinventory_perpage'] ?? 24)));
    $where = ['uid=' . (int)$uid];
    $entityFilter = (string)($filters['entity'] ?? '');
    if ($entityFilter !== '') {
        $where[] = "entity='" . $db->escape_string(af_advancedinventory_normalize_entity($entityFilter)) . "'";
    }
    if (($filters['subtype'] ?? '') !== '' && (string)$filters['subtype'] !== 'all') {
        $where[] = "subtype='" . $db->escape_string((string)$filters['subtype']) . "'";
    }
    if (($filters['search'] ?? '') !== '') {
        $like = $db->escape_string_like((string)$filters['search']);
        $where[] = "(title LIKE '%{$like}%' OR kb_key LIKE '%{$like}%')";
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)$db->fetch_field($db->simple_select(AF_ADVINV_TABLE_ITEMS, 'COUNT(*) AS c', $whereSql), 'c');
    $offset = ($page - 1) * $perPage;
    $items = [];
    $q = $db->simple_select(AF_ADVINV_TABLE_ITEMS, '*', $whereSql, ['order_by' => 'updated_at', 'order_dir' => 'DESC', 'limit' => $perPage, 'start' => $offset]);
    while ($row = $db->fetch_array($q)) { $items[] = $row; }

    $enrich = (bool)($filters['enrich'] ?? false);
    if ($enrich) {
        $items = af_advinv_enrich_items_from_kb($items);
    }

    af_advinv_debug_log('af_inv_get_items', [
        'uid' => (int)$uid,
        'filters' => $filters,
        'where' => $whereSql,
        'table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS,
        'total' => $total,
        'rows' => count($items),
        'enrich' => $enrich ? 1 : 0,
    ]);

    return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
}

function af_inv_user_can_view(int $viewerUid, int $ownerUid): bool
{
    global $mybb;
    if ($ownerUid <= 0) { return false; }
    if ($viewerUid === $ownerUid && $ownerUid > 0) { return true; }
    if (af_advancedinventory_user_is_staff()) { return true; }
    $allowed = af_advancedinventory_parse_groups_csv((string)($mybb->settings['af_advancedinventory_view_groups'] ?? ''));
    return $viewerUid > 0 && (bool)array_intersect($allowed, af_advancedinventory_user_group_ids());
}

function af_advancedinventory_user_is_staff(): bool
{
    return af_advancedinventory_user_can_manage();
}

function af_advancedinventory_parse_groups_csv(string $csv): array
{
    $groups = [];
    foreach (explode(',', $csv) as $part) {
        $id = (int)trim((string)$part);
        if ($id > 0) {
            $groups[$id] = $id;
        }
    }

    return array_values($groups);
}

function af_advancedinventory_user_group_ids(): array
{
    global $mybb;

    $groups = [];
    $usergroup = (int)($mybb->user['usergroup'] ?? 0);
    if ($usergroup > 0) {
        $groups[$usergroup] = $usergroup;
    }

    $additionalGroups = af_advancedinventory_parse_groups_csv((string)($mybb->user['additionalgroups'] ?? ''));
    foreach ($additionalGroups as $gid) {
        $groups[(int)$gid] = (int)$gid;
    }

    return array_values($groups);
}

function af_advancedinventory_user_can_manage(): bool
{
    global $mybb;
    $allowed = af_advancedinventory_parse_groups_csv((string)($mybb->settings['af_advancedinventory_manage_groups'] ?? '3,4,6'));
    return (bool)array_intersect($allowed, af_advancedinventory_user_group_ids());
}

function af_inv_can_manage_owner(int $viewerUid, int $ownerUid): bool
{
    if ($viewerUid > 0 && $viewerUid === $ownerUid) { return true; }
    return af_advancedinventory_user_can_manage();
}

function af_inv_equipment_slots(): array
{
    return ['head' => 'Head', 'body' => 'Body', 'hands' => 'Hands', 'legs' => 'Legs', 'feet' => 'Feet', 'weapon_mainhand' => 'Main hand', 'weapon_offhand' => 'Off hand', 'consumable_1' => 'Consumable 1', 'consumable_2' => 'Consumable 2', 'ammo' => 'Ammo', 'artifact' => 'Artifact'];
}

function af_inv_get_equipped(int $uid): array
{
    global $db;
    $out = [];
    if (!$db->table_exists('af_advinv_equipped')) { return $out; }
    $q = $db->simple_select('af_advinv_equipped', '*', 'uid=' . $uid);
    while ($row = $db->fetch_array($q)) {
        $out[(string)$row['equip_slot']] = $row;
    }
    return $out;
}

function af_advancedinventory_normalize_entity(string $entity): string
{
    $entity = trim($entity);
    if ($entity !== '' && af_advinv_entity_exists($entity)) {
        return $entity;
    }

    $tabs = af_advancedinventory_tabs(false);
    if ($tabs) {
        return (string)array_key_first($tabs);
    }

    return $entity;
}

function af_advinv_entities_sql_list(): string
{
    global $db;
    $values = [];
    foreach (array_keys(af_advancedinventory_tabs(false)) as $entity) {
        $values[] = "'" . $db->escape_string((string)$entity) . "'";
    }
    if (!$values) {
        return "''";
    }
    return implode(', ', $values);
}

function af_advinv_entity_exists(string $entity): bool
{
    global $db;
    static $all = null;

    if ($all === null) {
        $all = [];
        if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
            $q = $db->simple_select(AF_ADVINV_TABLE_ENTITIES, 'entity');
            while ($row = $db->fetch_array($q)) {
                $slug = trim((string)($row['entity'] ?? ''));
                if ($slug !== '') {
                    $all[$slug] = true;
                }
            }
        }
    }

    return isset($all[$entity]);
}

function af_advinv_get_entities(bool $enabledOnly = true): array
{
    global $db, $mybb;
    static $cache = [];

    $cacheKey = $enabledOnly ? 'enabled' : 'all';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $entities = [];
    if ($db->table_exists(AF_ADVINV_TABLE_ENTITIES)) {
        $where = $enabledOnly ? 'enabled=1' : '';
        $q = $db->simple_select(AF_ADVINV_TABLE_ENTITIES, '*', $where, ['order_by' => 'sortorder,entity', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($q)) {
            $slug = trim((string)($row['entity'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $titleRu = trim((string)($row['title_ru'] ?? ''));
            $titleEn = trim((string)($row['title_en'] ?? ''));
            $entities[$slug] = [
                'entity' => $slug,
                'title' => $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $slug),
                'title_ru' => $titleRu,
                'title_en' => $titleEn,
                'renderer' => trim((string)($row['renderer'] ?? 'generic')),
                'settings_json' => (string)($row['settings_json'] ?? ''),
                'enabled' => (int)($row['enabled'] ?? 0),
            ];
        }
    }

    $cache[$cacheKey] = $entities;
    return $entities;
}

function af_advinv_get_entity_by_slug(string $entity): array
{
    $all = af_advinv_get_entities(false);
    return (array)($all[$entity] ?? []);
}

function af_advinv_get_entity_filters(string $entity): array
{
    global $db;
    static $cache = [];

    if (isset($cache[$entity])) {
        return $cache[$entity];
    }

    $filters = [];
    if ($db->table_exists(AF_ADVINV_TABLE_ENTITY_FILTERS)) {
        $q = $db->simple_select(
            AF_ADVINV_TABLE_ENTITY_FILTERS,
            'id,code,title_ru,title_en,sortorder,enabled,match_json',
            "entity='" . $db->escape_string($entity) . "' AND enabled=1",
            ['order_by' => 'sortorder,id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($q)) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '' || $code === 'all') {
                continue;
            }
            $titleRu = trim((string)($row['title_ru'] ?? ''));
            $titleEn = trim((string)($row['title_en'] ?? ''));
            $filters[] = [
                'code' => $code,
                'title' => $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $code),
                'title_ru' => $titleRu,
                'title_en' => $titleEn,
                'sortorder' => (int)($row['sortorder'] ?? 0),
                'match' => af_advancedinventory_decode_assoc((string)($row['match_json'] ?? '')),
            ];
        }
    }

    $cache[$entity] = $filters;
    return $filters;
}

function af_advinv_render_entity_tab(string $entity, int $ownerUid, string $sub, int $page, bool $ajax): string
{
    return af_advinv_render_entity_generic($entity, $ownerUid, $sub, $page, $ajax);
}

function af_advinv_render_entity_generic(string $entity, int $ownerUid, string $sub, int $page, bool $ajax): string
{
    $filters = ['entity' => $entity, 'subtype' => $sub, 'page' => max(1, $page)];
    $data = af_inv_get_items($ownerUid, array_merge($filters, ['enrich' => true]));
    $canManage = af_advancedinventory_user_can_manage();

    $rows = af_advinv_render_tab_cards($data['items'], $canManage, false, []);
    $filterButtons = af_advinv_render_subfilter_links($entity, $ownerUid, $sub, af_advancedinventory_subfilters($entity));
    $html = '<div class="af-inv-subfilters">' . $filterButtons . '</div>';
    $html .= '<div class="af-inv-grid-wrap"><div class="af-inv-grid">' . $rows . '</div></div>';

    $apiBase = af_advancedinventory_url('', [], false);
    $html .= '<div class="af-inv-api" data-api-base="' . htmlspecialchars_uni($apiBase) . '" data-owner="' . $ownerUid . '"></div>';
    return $html;
}

function af_advancedinventory_decode_assoc(string $json): array
{
    $decoded = @json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function af_advancedinventory_tabs(bool $enabledOnly = true): array
{
    $tabs = [];
    foreach (af_advinv_get_entities($enabledOnly) as $slug => $row) {
        $tabs[$slug] = (string)($row['title'] ?? $slug);
    }
    return $tabs;
}

function af_advancedinventory_subfilters(string $tab): array
{
    $tab = af_advancedinventory_normalize_entity($tab);
    $filters = ['all' => 'Все'];
    foreach (af_advinv_get_entity_filters($tab) as $row) {
        $filters[(string)$row['code']] = (string)$row['title'];
    }
    return $filters;
}

function af_advinv_render_tab_cards(array $items, bool $canManage, bool $allowEquipActions, array $equipped = []): string
{
    $rows = '';
    foreach ($items as $item) {
        $qty = (int)$item['qty'];
        $title = (string)$item['title'];
        $subtype = (string)$item['subtype'];
        $icon = trim((string)$item['icon']);
        $iconHtml = $icon !== '' ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="" loading="lazy">' : '';
        $actions = '';
        if ($allowEquipActions) {
            $slotCandidates = af_inv_candidate_slots_for_item($item);
            $isEquipped = af_inv_find_equipped_slot_by_item($equipped, (int)$item['id']);
            if ($isEquipped !== '') {
                $actions .= '<button class="af-inv-action" data-action="unequip" data-item-id="' . (int)$item['id'] . '">Снять</button>';
            } elseif ($slotCandidates) {
                $actions .= '<button class="af-inv-action" data-action="equip" data-item-id="' . (int)$item['id'] . '" data-equip-slot="' . htmlspecialchars_uni((string)$slotCandidates[0]) . '">Надеть</button>';
            }
        }
        if ($canManage) {
            $actions .= '<button class="af-inv-action" data-action="delete" data-item-id="' . (int)$item['id'] . '">Удалить</button>';
            $actions .= '<label>Qty <input type="number" min="1" class="af-inv-qty" value="' . $qty . '"></label><button class="af-inv-action" data-action="update" data-item-id="' . (int)$item['id'] . '">Сохранить</button>';
        }
        $rows .= '<div class="af-inv-card">' . $iconHtml . '<div class="af-inv-card-title">' . htmlspecialchars_uni($title) . '</div><div class="af-inv-card-meta">' . htmlspecialchars_uni($subtype) . '</div><div class="af-inv-card-qty">x' . $qty . '</div><div class="af-inv-card-actions">' . $actions . '</div></div>';
    }
    if ($rows === '') {
        $rows = '<div class="af-inv-empty">Inventory is empty.</div>';
    }
    return $rows;
}

function af_advinv_render_subfilter_links(string $tab, int $ownerUid, string $sub, array $subfilters): string
{
    $current = $sub === '' ? 'all' : $sub;
    $filterButtons = '';
    foreach ($subfilters as $code => $title) {
        $isActive = ($code === $current) ? 'is-active' : '';
        $url = af_advancedinventory_url('entity', ['uid' => $ownerUid, 'entity' => $tab, 'sub' => $code, 'ajax' => 1], true);
        $filterButtons .= '<a class="af-inv-subfilter ' . $isActive . '" href="' . $url . '">' . htmlspecialchars_uni($title) . '</a>';
    }
    return $filterButtons;
}

function af_advancedinventory_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_advancedinventory_templates_install_or_update(): void
{
    global $db;
    foreach (glob(AF_ADVINV_TPL_DIR . '*.html') ?: [] as $file) {
        $name = basename($file, '.html');
        $template = (string)file_get_contents($file);
        $row = ['title' => $db->escape_string($name), 'template' => $db->escape_string($template), 'sid' => -2, 'version' => '', 'dateline' => TIME_NOW];
        $tid = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='" . $db->escape_string($name) . "'", ['limit' => 1]), 'tid');
        if ($tid > 0) { $db->update_query('templates', $row, 'tid=' . $tid); } else { $db->insert_query('templates', $row); }
    }
}

function af_advancedinventory_ensure_setting_group(string $title, string $desc): int
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedinventory'", ['limit' => 1]), 'gid');
    if ($gid > 0) { return $gid; }
    $disp = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm') + 1;
    $db->insert_query('settinggroups', ['name' => 'af_advancedinventory', 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'disporder' => $disp, 'isdefault' => 0]);
    return (int)$db->insert_id();
}

function af_advancedinventory_ensure_setting(string $name, string $title, string $desc, string $code, string $value, int $order, int $gid): void
{
    global $db;
    $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'sid');
    $row = ['name' => $db->escape_string($name), 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'optionscode' => $db->escape_string($code), 'value' => $db->escape_string($value), 'disporder' => $order, 'gid' => $gid, 'isdefault' => 0];
    if ($sid > 0) { $db->update_query('settings', $row, 'sid=' . $sid); } else { $db->insert_query('settings', $row); }
}

function af_advancedinventory_migrate_from_shop(): void
{
    global $db;
    if (!$db->table_exists(AF_ADVINV_TABLE_ITEMS)) { return; }
    $done = (string)$db->fetch_field($db->simple_select('datacache', 'cache', "title='af_advancedinventory_migrated'", ['limit' => 1]), 'cache');
    if ($done === '1') { return; }

    $migrated = 0;
    $sourceDetected = false;
    $kbCols = af_advinv_kb_cols();
    $kbIdExpr = $kbCols['id'] ?? 'id';
    $kbTypeExpr = $kbCols['type'] ?? "''";
    $kbKeyExpr = $kbCols['key'] ?? "''";
    $kbTitleRuExpr = $kbCols['title_ru'] ?: "''";
    $kbTitleEnExpr = $kbCols['title_en'] ?: "''";
    $kbTitleExpr = $kbCols['title'] ?: "''";
    $kbMetaExpr = $kbCols['meta_json'] ?: "''";

    af_advinv_debug_log('migration_start', [
        'new_table' => TABLE_PREFIX . AF_ADVINV_TABLE_ITEMS,
        'has_legacy_table' => $db->table_exists('af_shop_inventory_legacy') ? 1 : 0,
        'has_orders_table' => $db->table_exists('af_shop_orders') ? 1 : 0,
    ]);

    if ($db->table_exists('af_shop_inventory_legacy')) {
        $sourceDetected = true;
        $q = $db->query("SELECT l.uid, l.item_kind, l.slot_code, l.kb_id, l.qty, l.created_at, l.updated_at, "
            . $kbTypeExpr . " AS kb_type, "
            . $kbKeyExpr . " AS kb_key, "
            . $kbTitleRuExpr . " AS title_ru, "
            . $kbTitleEnExpr . " AS title_en, "
            . $kbTitleExpr . " AS title_plain, "
            . $kbMetaExpr . " AS meta_json "
            . "FROM " . TABLE_PREFIX . "af_shop_inventory_legacy l "
            . "LEFT JOIN " . af_advinv_kb_table_sql() . " e ON(e." . $kbIdExpr . "=l.kb_id)");
        while ($row = $db->fetch_array($q)) {
            $title = trim((string)($row['title_ru'] ?? ''));
            if ($title === '') { $title = trim((string)($row['title_en'] ?? '')); }
            if ($title === '') { $title = trim((string)($row['title_plain'] ?? '')); }
            $payload = [
                'entity' => (string)($row['slot_code'] ?? 'equipment'),
                'slot' => (string)($row['slot_code'] ?? 'equipment'),
                'subtype' => (string)($row['item_kind'] ?? ''),
                'kb_type' => (string)($row['kb_type'] ?? 'item'),
                'kb_key' => (string)($row['kb_key'] ?? ''),
                'title' => $title,
                'icon' => af_advinv_kb_extract_icon((string)($row['meta_json'] ?? '')),
                'qty' => (int)($row['qty'] ?? 1),
                'meta_json' => '',
            ];
            $newId = af_inv_add_item((int)$row['uid'], $payload);
            if ($newId > 0) {
                $migrated++;
            }
        }
        af_advinv_debug_log('migration_source_legacy', ['migrated_rows' => $migrated]);
    }

    if ($db->table_exists('af_shop_orders')) {
        $sourceDetected = true;
        $before = $migrated;
        $qOrders = $db->simple_select('af_shop_orders', 'order_id,uid, items_json', "status IN ('paid','completed','issued')", ['order_by' => 'order_id ASC']);
        while ($order = $db->fetch_array($qOrders)) {
            $uid = (int)($order['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $items = json_decode((string)($order['items_json'] ?? ''), true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $newId = af_inv_add_item($uid, [
                    'entity' => (string)($item['entity'] ?? ($item['slot'] ?? 'equipment')),
                    'slot' => (string)($item['slot'] ?? ($item['entity'] ?? 'equipment')),
                    'subtype' => (string)($item['subtype'] ?? ''),
                    'kb_type' => (string)($item['kb_type'] ?? 'item'),
                    'kb_key' => (string)($item['kb_key'] ?? ''),
                    'title' => (string)($item['title'] ?? ''),
                    'icon' => (string)($item['icon'] ?? ''),
                    'qty' => max(1, (int)($item['qty'] ?? 1)),
                    'meta_json' => is_string($item['meta_json'] ?? null) ? (string)$item['meta_json'] : '',
                ]);
                if ($newId > 0) {
                    $migrated++;
                }
            }
        }
        af_advinv_debug_log('migration_source_orders', ['migrated_rows' => $migrated - $before]);
    }

    if (!$sourceDetected) {
        af_advinv_debug_log('migration_skipped_no_source', []);
        return;
    }

    if ($migrated <= 0) {
        af_advinv_debug_log('migration_skipped_no_rows', []);
        return;
    }

    $exists = (int)$db->fetch_field($db->simple_select('datacache', 'COUNT(*) AS c', "title='af_advancedinventory_migrated'"), 'c');
    if ($exists > 0) {
        $db->update_query('datacache', ['cache' => '1'], "title='af_advancedinventory_migrated'");
    } else {
        $db->insert_query('datacache', ['title' => 'af_advancedinventory_migrated', 'cache' => '1']);
    }
    af_advinv_debug_log('migration_done', ['migrated_total' => $migrated]);
}
