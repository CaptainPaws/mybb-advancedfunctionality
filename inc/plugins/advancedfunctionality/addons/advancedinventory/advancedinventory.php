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
    af_advancedinventory_ensure_setting('af_advancedinventory_debug_enabled', 'Enable debug logging', 'Write Advanced Inventory debug events to file', 'yesno', '0', 6, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_debug_max_kb', 'Debug log max size (KB)', 'Rotate debug log when size exceeds this limit. Set 0 to disable rotation.', 'numeric', '512', 7, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_support_slots_json', 'Support slots JSON', 'JSON list of support slot configs: slot_code, title_ru/title_en, sortorder.', 'textarea', '[{"slot_code":"support_1","title_ru":"Быстрый слот 1","title_en":"Support slot 1","sortorder":10},{"slot_code":"support_2","title_ru":"Быстрый слот 2","title_en":"Support slot 2","sortorder":20},{"slot_code":"support_3","title_ru":"Быстрый слот 3","title_en":"Support slot 3","sortorder":30}]', 8, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_assets_blacklist', 'Assets blacklist', 'Disable Advanced Inventory JS/CSS on these scripts. One page per line, for example: index.php\nforumdisplay.php\nusercp.php', 'textarea', 'index.php', 9, $gid);

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
    global $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('advancedinventory');
    }
    $gid = af_advancedinventory_ensure_setting_group(
        $lang->af_advancedinventory_group ?? 'AF: Inventory',
        $lang->af_advancedinventory_group_desc ?? 'Inventory addon settings.'
    );
    af_advancedinventory_ensure_setting('af_advancedinventory_debug_enabled', 'Enable debug logging', 'Write Advanced Inventory debug events to file', 'yesno', '0', 6, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_debug_max_kb', 'Debug log max size (KB)', 'Rotate debug log when size exceeds this limit. Set 0 to disable rotation.', 'numeric', '512', 7, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_support_slots_json', 'Support slots JSON', 'JSON list of support slot configs: slot_code, title_ru/title_en, sortorder.', 'textarea', '[{"slot_code":"support_1","title_ru":"Быстрый слот 1","title_en":"Support slot 1","sortorder":10},{"slot_code":"support_2","title_ru":"Быстрый слот 2","title_en":"Support slot 2","sortorder":20},{"slot_code":"support_3","title_ru":"Быстрый слот 3","title_en":"Support slot 3","sortorder":30}]', 8, $gid);
    af_advancedinventory_ensure_setting('af_advancedinventory_assets_blacklist', 'Assets blacklist', 'Disable Advanced Inventory JS/CSS on these scripts. One page per line, for example: index.php\nforumdisplay.php\nusercp.php', 'textarea', 'index.php', 9, $gid);

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
        $prefix . 'af_advinv_support_slots',
        $prefix . 'af_advinv_entities',
        $prefix . 'af_advinv_entity_filters',
        $prefix . 'af_advinv_shop_map',
        $prefix . 'af_shop_orders',
    ];

    foreach ($allTables as $tableName) {
        if ($tableName === $prefix . 'af_shop' || $tableName === $prefix . 'af_shop_shops') {
            $requiredTables[] = $tableName;
        }
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
    if ($db->table_exists('af_advinv_support_slots')) { $db->drop_table('af_advinv_support_slots'); }
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

function af_advancedinventory_normalize_script_name(string $raw): string
{
    $raw = trim(str_replace('\\', '/', $raw));
    if ($raw === '') {
        return '';
    }

    $path = parse_url($raw, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $raw;
    }

    $script = strtolower((string)basename($path));
    if ($script === '' || $script === '.' || $script === '/') {
        return '';
    }

    if (strpos($script, '.php') === false) {
        $script .= '.php';
    }

    return $script;
}

function af_advancedinventory_current_script_name(): string
{
    if (defined('THIS_SCRIPT')) {
        $script = af_advancedinventory_normalize_script_name((string)THIS_SCRIPT);
        if ($script !== '') {
            return $script;
        }
    }

    foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
        $script = af_advancedinventory_normalize_script_name((string)($_SERVER[$key] ?? ''));
        if ($script !== '') {
            return $script;
        }
    }

    return '';
}

function af_advancedinventory_is_inventory_ui_context(string $action = ''): bool
{
    global $mybb;

    $script = af_advancedinventory_current_script_name();
    if ($script === 'inventory.php' || $script === 'inventories.php') {
        return true;
    }

    if ($script !== 'misc.php') {
        return false;
    }

    $currentAction = $action !== '' ? $action : (string)$mybb->get_input('action');
    return in_array($currentAction, ['inventory', 'inventories', 'tab', 'entity'], true);
}

function af_advancedinventory_assets_blacklist(): array
{
    global $mybb;

    static $blacklist = null;
    if (is_array($blacklist)) {
        return $blacklist;
    }

    $blacklist = [];
    $raw = str_replace(["\r\n", "\r"], "\n", (string)($mybb->settings['af_advancedinventory_assets_blacklist'] ?? ''));
    foreach (explode("\n", $raw) as $line) {
        $script = af_advancedinventory_normalize_script_name($line);
        if ($script !== '') {
            $blacklist[$script] = $script;
        }
    }

    return $blacklist;
}

function af_advancedinventory_assets_are_blacklisted(?string $script = null): bool
{
    $script = $script === null ? af_advancedinventory_current_script_name() : af_advancedinventory_normalize_script_name($script);
    if ($script === '') {
        return false;
    }

    $blacklist = af_advancedinventory_assets_blacklist();
    return isset($blacklist[$script]);
}

function af_advancedinventory_should_inject_assets(bool $embedded = false, string $action = ''): bool
{
    if (af_advancedinventory_assets_are_blacklisted()) {
        return false;
    }

    if ($embedded) {
        return true;
    }

    return af_advancedinventory_is_inventory_ui_context($action);
}

function af_advancedinventory_append_runtime_assets(string &$headerinclude, bool $withScript = true): void
{
    global $mybb;

    if (!af_advancedinventory_should_inject_assets(false)) {
        return;
    }

    $assetBase = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/';
    $cssFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.css';
    $vCss = @is_file($cssFile) ? (string)@filemtime($cssFile) : '1';
    $headerinclude .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($assetBase . 'advancedinventory.css?v=' . rawurlencode($vCss)) . '">';

    if (!$withScript) {
        return;
    }

    $jsFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.js';
    $vJs = @is_file($jsFile) ? (string)@filemtime($jsFile) : '1';
    $headerinclude .= '<script src="' . htmlspecialchars_uni($assetBase . 'advancedinventory.js?v=' . rawurlencode($vJs)) . '" defer></script>';
}

function af_advancedinventory_append_embedded_assets(string &$headerinclude, bool $withScript = true): void
{
    global $mybb;

    if (!af_advancedinventory_should_inject_assets(true)) {
        return;
    }

    $assetBase = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/';
    $cssFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.css';
    $vCss = @is_file($cssFile) ? (string)@filemtime($cssFile) : '1';
    $headerinclude .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($assetBase . 'advancedinventory.css?v=' . rawurlencode($vCss)) . '">';

    if (!$withScript) {
        return;
    }

    $jsFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.js';
    $vJs = @is_file($jsFile) ? (string)@filemtime($jsFile) : '1';
    $headerinclude .= '<script src="' . htmlspecialchars_uni($assetBase . 'advancedinventory.js?v=' . rawurlencode($vJs)) . '" defer></script>';
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
    if (!in_array($action, ['inventory', 'inventories', 'tab', 'entity', 'api_list', 'api_move', 'api_equip', 'api_unequip', 'api_update', 'api_delete', 'api_sell', 'api_bind_support_slot', 'api_unbind_support_slot', 'api_support_slots_state'], true)) {
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
        case 'api_sell': af_advancedinventory_api_sell(); return;
        case 'api_bind_support_slot': af_advancedinventory_api_bind_support_slot(); return;
        case 'api_unbind_support_slot': af_advancedinventory_api_unbind_support_slot(); return;
        case 'api_support_slots_state': af_advancedinventory_api_support_slots_state(); return;
        case 'api_move': af_advancedinventory_json(['ok' => false, 'error' => 'not_implemented'], 501); return;
    }
    error_no_permission();
}

function af_advancedinventory_render_inventory(): void
{
    global $mybb, $headerinclude, $templates, $lang, $db;
    if ((int)($mybb->settings['af_advancedinventory_enabled'] ?? 1) !== 1) {
        error((string)($lang->af_advancedinventory_error_disabled ?? 'Inventory is disabled'));
    }

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) {
        $ownerUid = $viewerUid;
    }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) {
        error_no_permission();
    }

    $user = $db->fetch_array($db->simple_select('users', 'uid,username,avatar', 'uid=' . $ownerUid, ['limit' => 1]));
    if (!$user) {
        error_no_permission();
    }

    $tabs = af_advancedinventory_tabs();
    if (!$tabs) {
        error('Inventory entities are not configured.');
    }

    $defaultTab = (string)($mybb->settings['af_advancedinventory_default_tab'] ?? '');
    if (!isset($tabs[$defaultTab])) {
        $defaultTab = (string)array_key_first($tabs);
    }

    af_advancedinventory_append_runtime_assets($headerinclude, true);

    $af_inv_content = af_advancedinventory_build_inventory_fragment($ownerUid);
    eval('$page = "' . $templates->get('advancedinventory_inventory_page') . '";');
    output_page($page);
    exit;
}

function af_advancedinventory_build_inventory_fragment(int $ownerUid): string
{
    global $mybb, $headerinclude, $templates, $lang, $db;

    if ((int)($mybb->settings['af_advancedinventory_enabled'] ?? 1) !== 1) {
        return '';
    }

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if ($ownerUid <= 0) {
        $ownerUid = $viewerUid;
    }
    if ($ownerUid <= 0 || !af_inv_user_can_view($viewerUid, $ownerUid)) {
        return '';
    }

    $user = $db->fetch_array($db->simple_select('users', 'uid,username,avatar', 'uid=' . $ownerUid, ['limit' => 1]));
    if (!$user) {
        return '';
    }

    $tabs = af_advancedinventory_tabs();
    if (!$tabs) {
        return '';
    }

    $defaultTab = (string)($mybb->settings['af_advancedinventory_default_tab'] ?? '');
    if (!isset($tabs[$defaultTab])) {
        $defaultTab = (string)array_key_first($tabs);
    }

    af_advancedinventory_append_embedded_assets($headerinclude, true);

    $tabLinks = '';
    foreach ($tabs as $code => $title) {
        $active = $code === $defaultTab ? 'is-active' : '';
        $tabLinks .= '<button type="button" class="af-inv-tab ' . $active . '" data-entity="' . htmlspecialchars_uni($code) . '">' . htmlspecialchars_uni($title) . '</button>';
    }

    $firstPanelUrl = af_advancedinventory_url('entity', ['uid' => $ownerUid, 'entity' => $defaultTab, 'sub' => 'all', 'ajax' => 1], false);
    $entityUrlBase = af_advancedinventory_url('entity', ['uid' => $ownerUid], false);
    $firstPanelHtml = af_advinv_render_entity_tab($defaultTab, $ownerUid, 'all', 1, true);
    $wallet = af_advinv_wallet_payload($ownerUid);
    $walletBalance = htmlspecialchars_uni((string)($wallet['balance_major'] ?? '0'));
    $walletCurrencySymbol = htmlspecialchars_uni((string)($wallet['currency_symbol'] ?? '₡'));
    $walletCurrencyCode = htmlspecialchars_uni((string)($wallet['currency'] ?? 'credits'));

    eval('$inventory_inner = "' . $templates->get('advancedinventory_inventory_inner') . '";');

    return $inventory_inner;
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

    if (!$db->table_exists('af_advinv_support_slots')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_advinv_support_slots (
            uid INT UNSIGNED NOT NULL,
            slot_code VARCHAR(64) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            sortorder INT NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (uid, slot_code),
            KEY uid_item (uid, item_id),
            KEY uid_sortorder (uid, sortorder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    global $mybb, $headerinclude, $templates;

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) {
        $ownerUid = $viewerUid;
    }

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
    $pageNum = max(1, (int)$mybb->get_input('page'));
    $fragment = (int)$mybb->get_input('ajax') === 1 || (int)$mybb->get_input('fragment') === 1;

    $af_inv_frame_content = af_advinv_render_entity_tab($tab, $ownerUid, $sub, $pageNum, true);
    if ($fragment) {
        echo $af_inv_frame_content;
        exit;
    }

    af_advancedinventory_append_runtime_assets($headerinclude, false);

    $af_inv_frame_title = htmlspecialchars_uni((string)($tabs[$tab] ?? $tab));
    $tpl = $templates->get('advancedinventory_entity_frame', 1, 0);
    if (trim($tpl) === '') {
        $page = '<!DOCTYPE html><html><head><title>' . $af_inv_frame_title . '</title>' . $headerinclude . '</head><body class="af-inv-iframe-body">' . $af_inv_frame_content . '</body></html>';
    } else {
        eval('$page = "' . $tpl . '";');
    }

    output_page($page);
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
    af_advancedinventory_append_runtime_assets($headerinclude, false);
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

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        af_advancedinventory_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    $postKey = trim((string)$mybb->get_input('my_post_key'));
    if ($postKey === '') {
        $postKey = trim((string)$mybb->get_input('post_key'));
    }

    if ($postKey === '' || !verify_post_check($postKey, true)) {
        af_advancedinventory_json(['ok' => false, 'error' => 'invalid_post_key'], 403);
    }
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

function af_advancedinventory_api_support_slots_state(): void
{
    global $mybb;
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) { $ownerUid = $viewerUid; }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }

    $slotConfigs = af_inv_support_slots();
    $bindings = af_inv_support_bindings($ownerUid);
    $itemsById = [];
    foreach (af_inv_get_items($ownerUid, ['entity' => 'equipment', 'page' => 1, 'per_page' => 500])['items'] ?? [] as $item) {
        $itemsById[(int)($item['id'] ?? 0)] = $item;
    }

    $slots = [];
    foreach ($slotConfigs as $slotCode => $config) {
        $binding = (array)($bindings[$slotCode] ?? []);
        $item = [];
        if ($binding) {
            $item = (array)($itemsById[(int)($binding['item_id'] ?? 0)] ?? af_inv_get_item_for_owner($ownerUid, (int)($binding['item_id'] ?? 0)));
        }
        if ($item && !af_inv_is_consumable_item($item)) {
            $item = [];
        }
        $slots[] = [
            'slot_code' => $slotCode,
            'title' => (string)($config['title'] ?? $slotCode),
            'legacy_slot' => (string)($config['legacy_slot'] ?? ''),
            'sortorder' => (int)($config['sortorder'] ?? 0),
            'item_id' => (int)($binding['item_id'] ?? 0),
            'item_title' => (string)($item['title'] ?? $item['appearance_title'] ?? ''),
            'qty_bound' => (int)($item['qty'] ?? 0),
            'is_empty' => empty($binding),
        ];
    }

    af_advancedinventory_json(['ok' => true, 'slots' => $slots]);
}

function af_advancedinventory_api_bind_support_slot(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    $ownerUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_can_manage_owner($viewerUid, $ownerUid)) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $item = af_inv_get_item_for_owner($ownerUid, (int)$mybb->get_input('item_id'));
    if (!$item) { af_advancedinventory_json(['ok' => false, 'error' => 'item_not_found'], 404); }
    if (!af_inv_is_consumable_item($item)) { af_advancedinventory_json(['ok' => false, 'error' => 'item_not_consumable'], 422); }
    $slotCode = af_inv_map_legacy_support_slot((string)$mybb->get_input('slot_code'));
    $slotConfigs = af_inv_support_slots();
    if ($slotCode === '' || !isset($slotConfigs[$slotCode])) { af_advancedinventory_json(['ok' => false, 'error' => 'slot_invalid'], 422); }
    $db->delete_query('af_advinv_support_slots', 'uid=' . $ownerUid . ' AND item_id=' . (int)$item['id']);
    $exists = (int)$db->fetch_field($db->simple_select('af_advinv_support_slots', 'COUNT(*) c', "uid={$ownerUid} AND slot_code='" . $db->escape_string($slotCode) . "'"), 'c');
    $row = ['item_id' => (int)$item['id'], 'sortorder' => (int)($slotConfigs[$slotCode]['sortorder'] ?? 0), 'updated_at' => TIME_NOW];
    if ($exists > 0) {
        $db->update_query('af_advinv_support_slots', $row, "uid={$ownerUid} AND slot_code='" . $db->escape_string($slotCode) . "'");
    } else {
        $row['uid'] = $ownerUid; $row['slot_code'] = $db->escape_string($slotCode); $row['created_at'] = TIME_NOW;
        $db->insert_query('af_advinv_support_slots', $row);
    }
    af_advancedinventory_json(['ok' => true, 'slot_code' => $slotCode]);
}

function af_advancedinventory_api_unbind_support_slot(): void
{
    global $mybb, $db;
    af_advancedinventory_require_post();
    $ownerUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_can_manage_owner($viewerUid, $ownerUid)) { af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403); }
    $slotCode = af_inv_map_legacy_support_slot((string)$mybb->get_input('slot_code'));
    if ($slotCode !== '') {
        $db->delete_query('af_advinv_support_slots', 'uid=' . $ownerUid . " AND slot_code='" . $db->escape_string($slotCode) . "'");
    } else {
        $db->delete_query('af_advinv_support_slots', 'uid=' . $ownerUid . ' AND item_id=' . (int)$mybb->get_input('item_id'));
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


function af_advinv_require_shop_runtime(): void
{
    if (function_exists('af_shop_get_balance') && function_exists('af_shop_add_balance') && function_exists('af_advancedshop_currency_symbol')) {
        return;
    }

    $shopBootstrap = AF_ADDONS . 'advancedshop/advancedshop.php';
    if (is_file($shopBootstrap)) {
        require_once $shopBootstrap;
    }
}

function af_advinv_wallet_payload(int $uid): array
{
    global $mybb;

    af_advinv_require_shop_runtime();

    $currency = trim((string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    if ($currency === '') {
        $currency = 'credits';
    }

    $balanceMinor = function_exists('af_shop_get_balance') ? (int)af_shop_get_balance($uid, $currency) : 0;
    $balanceMajor = function_exists('af_advancedshop_money_format')
        ? af_advancedshop_money_format($balanceMinor)
        : number_format($balanceMinor / 100, 2, '.', '');
    $currencySymbol = function_exists('af_advancedshop_currency_symbol')
        ? af_advancedshop_currency_symbol($currency)
        : $currency;

    return [
        'uid' => $uid,
        'currency' => $currency,
        'currency_symbol' => $currencySymbol,
        'balance_minor' => $balanceMinor,
        'balance_major' => $balanceMajor,
    ];
}

function af_advinv_item_shop_price_minor(array $item): int
{
    global $db;

    $meta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
    $priceMinor = max(0, (int)($meta['shop']['price_each'] ?? 0));
    if ($priceMinor > 0) {
        return $priceMinor;
    }

    af_advinv_require_shop_runtime();

    if (!$db->table_exists('af_shop_slots')) {
        return 0;
    }

    $kbType = trim((string)($item['kb_type'] ?? ''));
    $kbKey = trim((string)($item['kb_key'] ?? ''));
    $itemId = (int)($item['id'] ?? 0);

    $appearanceInfo = af_advinv_resolve_appearance_item($item);
    $sourceType = trim((string)($appearanceInfo['source_type'] ?? ($item['source_type'] ?? 'kb')));
    if ($sourceType === 'appearance' || !empty($appearanceInfo['is_visual_item'])) {
        $presetId = (int)($appearanceInfo['preset_id'] ?? 0);
        if ($presetId <= 0 && strpos($kbKey, 'appearance:') === 0) {
            $presetId = (int)substr($kbKey, strlen('appearance:'));
        }
        if ($presetId > 0) {
            $query = $db->query("SELECT price FROM " . TABLE_PREFIX . "af_shop_slots WHERE source_type='appearance' AND source_ref_id=" . $presetId . " AND enabled=1 ORDER BY price ASC, slot_id ASC LIMIT 1");
            $row = $db->fetch_array($query);
            return max(0, (int)($row['price'] ?? 0));
        }
        return 0;
    }

    $where = [];
    $kbId = (int)($item['kb_id'] ?? 0);
    if ($kbId > 0) {
        $where[] = 'kb_id=' . $kbId;
    }
    if ($kbType !== '' && $kbKey !== '') {
        $where[] = "(kb_type='" . $db->escape_string($kbType) . "' AND kb_key='" . $db->escape_string($kbKey) . "')";
    }
    if (!$where) {
        return 0;
    }

    $query = $db->query("SELECT price FROM " . TABLE_PREFIX . "af_shop_slots WHERE enabled=1 AND (" . implode(' OR ', $where) . ") ORDER BY price ASC, slot_id ASC LIMIT 1");
    $row = $db->fetch_array($query);
    return max(0, (int)($row['price'] ?? 0));
}

function af_advinv_item_sale_profile(array $item, ?int $qtyOverride = null): array
{
    global $mybb;

    af_advinv_require_shop_runtime();

    $meta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
    $currency = trim((string)($meta['shop']['currency'] ?? ($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits')));
    if ($currency === '') {
        $currency = 'credits';
    }

    $unitPriceMinor = af_advinv_item_shop_price_minor($item);
    $itemQty = max(1, (int)($item['qty'] ?? 1));
    $saleQty = $qtyOverride === null ? $itemQty : max(1, min($itemQty, (int)$qtyOverride));
    $sellUnitMinor = (int)floor($unitPriceMinor * 0.8);
    $sellTotalMinor = $sellUnitMinor * $saleQty;

    $format = function ($minor) {
        return function_exists('af_advancedshop_money_format')
            ? af_advancedshop_money_format((int)$minor)
            : number_format(((int)$minor) / 100, 2, '.', '');
    };

    return [
        'currency' => $currency,
        'currency_symbol' => function_exists('af_advancedshop_currency_symbol') ? af_advancedshop_currency_symbol($currency) : $currency,
        'item_qty' => $itemQty,
        'qty' => $saleQty,
        'price_each_minor' => $unitPriceMinor,
        'price_each_major' => $format($unitPriceMinor),
        'price_total_minor' => $unitPriceMinor * $saleQty,
        'price_total_major' => $format($unitPriceMinor * $saleQty),
        'sell_each_minor' => $sellUnitMinor,
        'sell_each_major' => $format($sellUnitMinor),
        'sell_total_minor' => $sellTotalMinor,
        'sell_total_major' => $format($sellTotalMinor),
        'can_sell' => $sellUnitMinor > 0,
    ];
}

function af_advancedinventory_api_sell(): void
{
    global $mybb, $db;

    af_advancedinventory_require_post();

    $ownerUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_can_manage_owner($viewerUid, $ownerUid)) {
        af_advancedinventory_json(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $itemId = (int)$mybb->get_input('item_id');
    $item = af_inv_get_item_for_owner($ownerUid, $itemId);
    if (!$item) {
        af_advancedinventory_json(['ok' => false, 'error' => 'item_not_found'], 404);
    }

    $itemQty = max(1, (int)($item['qty'] ?? 1));
    $sellQtyRaw = isset($mybb->input['qty']) ? trim((string)$mybb->input['qty']) : '';
    $sellQty = $sellQtyRaw === '' ? 1 : (int)$mybb->get_input('qty');
    if ($sellQty < 1) {
        af_advancedinventory_json(['ok' => false, 'error' => 'invalid_qty'], 422);
    }
    if ($sellQty > $itemQty) {
        af_advancedinventory_json(['ok' => false, 'error' => 'qty_exceeds_item'], 422);
    }

    $sale = af_advinv_item_sale_profile($item, $sellQty);
    if (empty($sale['can_sell'])) {
        af_advancedinventory_json(['ok' => false, 'error' => 'sell_price_unavailable'], 422);
    }

    $sellMinor = max(0, (int)($sale['sell_total_minor'] ?? 0));
    if ($sellMinor <= 0) {
        af_advancedinventory_json(['ok' => false, 'error' => 'sell_price_invalid'], 422);
    }

    $equipped = af_inv_get_equipped($ownerUid);
    $equippedSlot = af_inv_find_equipped_slot_by_item($equipped, $itemId);
    if ($equippedSlot !== '' && $sellQty < $itemQty) {
        $slotCandidates = af_inv_candidate_slots_for_item($item);
        $isConsumableSlot = strpos($equippedSlot, 'support_') === 0 || strpos($equippedSlot, 'consumable_') === 0;
        $isConsumableItem = af_inv_is_consumable_item($item);
        if (!$isConsumableSlot || !$isConsumableItem) {
            af_advancedinventory_json(['ok' => false, 'error' => 'equipped_partial_sale_forbidden'], 422);
        }
    }

    $appearanceInfo = af_advinv_resolve_appearance_item($item);
    $itemQtyLeft = max(0, $itemQty - $sellQty);

    $db->write_query('START TRANSACTION');
    try {
        if (!empty($appearanceInfo['is_visual_item']) && $db->table_exists('af_aa_active')) {
            $db->delete_query('af_aa_active', "entity_type='user' AND entity_id=" . $ownerUid . " AND item_id=" . $itemId);
        }

        if ($itemQtyLeft > 0) {
            $db->update_query(AF_ADVINV_TABLE_ITEMS, ['qty' => $itemQtyLeft, 'updated_at' => TIME_NOW], 'id=' . $itemId . ' AND uid=' . $ownerUid);
        } else {
            $db->delete_query('af_advinv_equipped', 'uid=' . $ownerUid . ' AND item_id=' . $itemId);
            $db->delete_query(AF_ADVINV_TABLE_ITEMS, 'id=' . $itemId . ' AND uid=' . $ownerUid);
        }

        if (function_exists('af_shop_add_balance')) {
            af_shop_add_balance($ownerUid, (string)$sale['currency'], $sellMinor, 'inventory_sell', [
                'item_id' => $itemId,
                'kb_key' => (string)($item['kb_key'] ?? ''),
                'title' => (string)($item['appearance_title'] ?? ($item['title'] ?? '')),
                'qty' => $sellQty,
                'source' => 'advancedinventory',
            ]);
        }

        $db->write_query('COMMIT');
    } catch (Throwable $e) {
        $db->write_query('ROLLBACK');
        af_advancedinventory_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $wallet = af_advinv_wallet_payload($ownerUid);
    af_advancedinventory_json([
        'ok' => true,
        'sold_qty' => $sellQty,
        'sold_minor' => $sellMinor,
        'sold_major' => (string)($sale['sell_total_major'] ?? '0'),
        'item_qty_left' => $itemQtyLeft,
        'currency' => (string)$sale['currency'],
        'currency_symbol' => (string)$sale['currency_symbol'],
        'wallet' => $wallet,
        'message' => 'Предмет продан, баланс пополнен.',
    ]);
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

function af_advinv_debug_enabled(): bool
{
    global $mybb;

    return (int)($mybb->settings['af_advancedinventory_debug_enabled'] ?? 0) === 1;
}

function af_advinv_debug_max_kb(): int
{
    global $mybb;

    $maxKb = (int)($mybb->settings['af_advancedinventory_debug_max_kb'] ?? 512);
    return max(0, $maxKb);
}

function af_advinv_debug_write(string $content, int $flags = 0): bool
{
    return @file_put_contents(AF_ADVINV_DEBUG_LOG, $content, $flags) !== false;
}

function af_advinv_debug_clear(): array
{
    $bytesBefore = (is_file(AF_ADVINV_DEBUG_LOG) && is_readable(AF_ADVINV_DEBUG_LOG)) ? (int)@filesize(AF_ADVINV_DEBUG_LOG) : 0;
    $ok = af_advinv_debug_write('', LOCK_EX);
    $bytesAfter = (is_file(AF_ADVINV_DEBUG_LOG) && is_readable(AF_ADVINV_DEBUG_LOG)) ? (int)@filesize(AF_ADVINV_DEBUG_LOG) : 0;

    if ($ok && $bytesAfter !== 0) {
        $ok = false;
    }

    return [
        'ok' => $ok,
        'path' => AF_ADVINV_DEBUG_LOG,
        'bytes_before' => $bytesBefore,
        'bytes_after' => $bytesAfter,
        'error' => $ok ? '' : 'failed_to_truncate',
    ];
}

function af_advinv_debug_rotate_if_needed(): void
{
    $maxBytes = af_advinv_debug_max_kb() * 1024;
    if ($maxBytes <= 0 || !is_file(AF_ADVINV_DEBUG_LOG)) {
        return;
    }

    $size = @filesize(AF_ADVINV_DEBUG_LOG);
    if ($size === false || (int)$size <= $maxBytes) {
        return;
    }

    $rotated = AF_ADVINV_DEBUG_LOG . '.1';
    @unlink($rotated);
    if (!@rename(AF_ADVINV_DEBUG_LOG, $rotated)) {
        af_advinv_debug_write('');
    }
}

function af_advinv_debug_log(string $tag, array $data = [], string $channel = 'ADVINV'): void
{
    if (!af_advinv_debug_enabled()) {
        return;
    }

    af_advinv_debug_rotate_if_needed();

    $normalizedChannel = strtoupper(trim($channel));
    if ($normalizedChannel === '') {
        $normalizedChannel = 'ADVINV';
    }

    $line = '[AF-' . $normalizedChannel . '][' . date('c') . '][' . $tag . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @error_log($line);
    af_advinv_debug_write($line . "\n", FILE_APPEND);
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

function af_advinv_decode_meta_json($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = @json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function af_advinv_resolve_appearance_item(array $item): array
{
    if (function_exists('af_advancedshop_inventory_resolve_appearance_item')) {
        $kbMetaRaw = (string)($item['kb_meta'] ?? '');
        $kbMeta = af_advinv_decode_meta_json($kbMetaRaw);
        if (!$kbMeta) {
            $kbMeta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
        }

        return (array)af_advancedshop_inventory_resolve_appearance_item($item, [], $kbMeta);
    }

    $meta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
    $appearance = is_array($meta['appearance'] ?? null) ? (array)$meta['appearance'] : [];
    $kbKey = trim((string)($item['kb_key'] ?? ''));
    $isAppearance = trim((string)($item['source_type'] ?? '')) === 'appearance'
        || strpos($kbKey, 'appearance:') === 0
        || !empty($appearance)
        || !empty($appearance['preset_id'])
        || !empty($appearance['target_key']);

    $presetId = 0;
    if (strpos($kbKey, 'appearance:') === 0) {
        $presetId = (int)substr($kbKey, strlen('appearance:'));
    }
    if ($presetId <= 0) {
        $presetId = (int)($appearance['preset_id'] ?? 0);
    }

    return [
        'source_type' => $isAppearance ? 'appearance' : trim((string)($item['source_type'] ?? 'kb')),
        'is_visual_item' => $isAppearance,
        'kb_key' => $kbKey,
        'preset_id' => $presetId,
        'target_key' => trim((string)($appearance['target_key'] ?? '')),
        'appearance_meta' => $appearance,
    ];
}

function af_advinv_active_appearance_map(int $uid): array
{
    global $db;

    $map = [];
    if ($uid <= 0 || !$db->table_exists('af_aa_active')) {
        return $map;
    }

    $q = $db->simple_select('af_aa_active', '*', "entity_type='user' AND entity_id='" . (int)$uid . "' AND is_enabled='1'");
    while ($row = $db->fetch_array($q)) {
        $targetKey = trim((string)($row['target_key'] ?? ''));
        if ($targetKey === '') {
            continue;
        }
        $map[$targetKey] = $row;
    }

    return $map;
}

function af_advinv_enrich_items_from_kb(array $items): array
{
    global $db;

    if (!$items) {
        return $items;
    }

    $kbMap = [];

    $kbTableSql = trim((string)af_advinv_kb_table_sql());
    $kbTablePlain = $kbTableSql;
    if ($kbTablePlain !== '' && defined('TABLE_PREFIX') && TABLE_PREFIX !== '' && strpos($kbTablePlain, TABLE_PREFIX) === 0) {
        $kbTablePlain = substr($kbTablePlain, strlen(TABLE_PREFIX));
    }
    $kbTableExists = ($kbTablePlain !== '' && $db->table_exists($kbTablePlain));

    if ($kbTableExists) {
        $keys = [];
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            $icon = trim((string)($item['icon'] ?? ''));
            $kbKey = trim((string)($item['kb_key'] ?? ''));
            if ($kbKey === '' || ($title !== '' && $icon !== '')) {
                continue;
            }

            $kbType = trim((string)($item['kb_type'] ?? ''));
            $keys[$kbType . '||' . $kbKey] = [
                'kb_type' => $kbType,
                'kb_key'  => $kbKey,
            ];
        }

        if ($keys) {
            $kbCols = af_advinv_kb_cols();
            $keyExpr = $kbCols['key'] ?? '`key`';
            $typeExpr = $kbCols['type'] ?? '`type`';
            $titleRuExpr = $kbCols['title_ru'] ?: "''";
            $titleEnExpr = $kbCols['title_en'] ?: "''";
            $titleExpr = $kbCols['title'] ?: "''";
            $metaExpr = $kbCols['meta_json'] ?: "''";

            $whereOr = [];
            foreach ($keys as $pair) {
                $whereOr[] = '('
                    . $typeExpr . "='" . $db->escape_string($pair['kb_type']) . "' AND "
                    . $keyExpr . "='" . $db->escape_string($pair['kb_key']) . "'"
                    . ')';
            }

            if ($whereOr) {
                $q = $db->query(
                    "SELECT "
                    . $typeExpr . " AS kb_type, "
                    . $keyExpr . " AS kb_key, "
                    . $titleRuExpr . " AS title_ru, "
                    . $titleEnExpr . " AS title_en, "
                    . $titleExpr . " AS title_plain, "
                    . $metaExpr . " AS meta_json
                    FROM " . $kbTableSql . "
                    WHERE " . implode(' OR ', $whereOr)
                );

                while ($row = $db->fetch_array($q)) {
                    $t = trim((string)($row['title_ru'] ?? ''));
                    if ($t === '') {
                        $t = trim((string)($row['title_en'] ?? ''));
                    }
                    if ($t === '') {
                        $t = trim((string)($row['title_plain'] ?? ''));
                    }

                    $kbType = trim((string)($row['kb_type'] ?? ''));
                    $kbKey = trim((string)($row['kb_key'] ?? ''));

                    $kbMap[$kbType . '||' . $kbKey] = [
                        'title' => $t,
                        'icon'  => af_advinv_kb_extract_icon((string)($row['meta_json'] ?? '')),
                    ];
                }
            }
        }
    }

    $appearanceActiveMap = [];

    foreach ($items as &$item) {
        $kbType = trim((string)($item['kb_type'] ?? ''));
        $kbKey = trim((string)($item['kb_key'] ?? ''));
        $mapKey = $kbType . '||' . $kbKey;
        $fromKb = $kbMap[$mapKey] ?? null;

        if ($fromKb) {
            if (trim((string)($item['title'] ?? '')) === '') {
                $item['title'] = $fromKb['title'] !== '' ? $fromKb['title'] : $kbKey;
            }
            if (trim((string)($item['icon'] ?? '')) === '' && $fromKb['icon'] !== '') {
                $item['icon'] = $fromKb['icon'];
            }
        } elseif ($kbKey !== '' && trim((string)($item['title'] ?? '')) === '') {
            $item['title'] = $kbKey;
        }

        $meta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
        $item['meta'] = $meta;

        $appearanceInfo = af_advinv_resolve_appearance_item($item);
        $appearanceMeta = is_array($appearanceInfo['appearance_meta'] ?? null)
            ? (array)$appearanceInfo['appearance_meta']
            : [];

        $resolvedKbKey = trim((string)($appearanceInfo['kb_key'] ?? $kbKey));
        $sourceType = trim((string)($appearanceInfo['source_type'] ?? ($item['source_type'] ?? '')));
        $targetKey = trim((string)($item['appearance_target'] ?? ($appearanceInfo['target_key'] ?? ($appearanceMeta['target_key'] ?? ''))));
        $presetId = (int)($item['appearance_preset_id'] ?? ($appearanceInfo['preset_id'] ?? ($appearanceMeta['preset_id'] ?? 0)));

        $isVisual = !empty($item['is_visual_item'])
            || !empty($appearanceInfo['is_visual_item'])
            || $sourceType === 'appearance'
            || strpos($resolvedKbKey, 'appearance:') === 0
            || !empty($appearanceMeta);

        $previewImage = trim((string)($appearanceMeta['preview_image'] ?? ($item['appearance_preview_image'] ?? ($item['icon'] ?? ''))));
        $appearanceTitle = trim((string)($appearanceMeta['title'] ?? ($item['appearance_title'] ?? ($item['title'] ?? ''))));
        $appearanceSlug = trim((string)($appearanceMeta['slug'] ?? ($item['appearance_slug'] ?? '')));

        $item['is_visual_item'] = $isVisual ? 1 : 0;
        $item['source_type'] = $isVisual ? 'appearance' : ($sourceType !== '' ? $sourceType : 'kb');
        $item['appearance_target'] = $targetKey;
        $item['appearance_preset_id'] = $presetId;
        $item['appearance_preview_image'] = $previewImage;
        $item['preview_image'] = $previewImage;
        $item['appearance_title'] = $appearanceTitle;
        $item['appearance_slug'] = $appearanceSlug;
        $item['appearance_meta'] = $appearanceMeta;
        $item['appearance_is_active'] = 0;
        $item['kb_key'] = $resolvedKbKey !== '' ? $resolvedKbKey : $kbKey;

        if ($previewImage !== '') {
            $item['icon'] = $previewImage;
        }
        if ($appearanceTitle !== '') {
            $item['title'] = $appearanceTitle;
        }

        if ($isVisual) {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid > 0) {
                if (!isset($appearanceActiveMap[$uid])) {
                    $appearanceActiveMap[$uid] = af_advinv_active_appearance_map($uid);
                }
                $activeTarget = (array)($appearanceActiveMap[$uid][$targetKey] ?? []);
                $item['appearance_is_active'] = ((int)($activeTarget['item_id'] ?? 0) === (int)($item['id'] ?? 0)) ? 1 : 0;
            }
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

function af_inv_legacy_support_slot_map(): array
{
    return [
        'consumable_1' => 'support_1',
        'consumable_2' => 'support_2',
    ];
}

function af_inv_support_slots(): array
{
    global $mybb;

    $raw = (string)($mybb->settings['af_advancedinventory_support_slots_json'] ?? '');
    $decoded = af_advancedinventory_decode_assoc($raw);
    if (!is_array($decoded) || array_values($decoded) !== $decoded) {
        $decoded = [];
    }

    $slots = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slotCode = trim((string)($row['slot_code'] ?? $row['code'] ?? ''));
        if ($slotCode === '') {
            continue;
        }
        $titleRu = trim((string)($row['title_ru'] ?? $row['title'] ?? ''));
        $titleEn = trim((string)($row['title_en'] ?? $row['title'] ?? ''));
        $title = $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $slotCode);
        $slots[$slotCode] = [
            'slot_code' => $slotCode,
            'title' => $title,
            'title_ru' => $titleRu,
            'title_en' => $titleEn,
            'sortorder' => (int)($row['sortorder'] ?? $row['sort_order'] ?? 0),
        ];
    }

    foreach (af_inv_legacy_support_slot_map() as $legacyCode => $slotCode) {
        if (!isset($slots[$slotCode])) {
            $slots[$slotCode] = [
                'slot_code' => $slotCode,
                'title' => 'Быстрый слот ' . preg_replace('~^support_~', '', $slotCode),
                'title_ru' => 'Быстрый слот ' . preg_replace('~^support_~', '', $slotCode),
                'title_en' => 'Support slot ' . preg_replace('~^support_~', '', $slotCode),
                'sortorder' => (int)preg_replace('~\D+~', '', $slotCode) * 10,
            ];
        }
        $slots[$slotCode]['legacy_slot'] = $legacyCode;
    }

    uasort($slots, static function (array $a, array $b): int {
        $sort = ((int)($a['sortorder'] ?? 0)) <=> ((int)($b['sortorder'] ?? 0));
        return $sort !== 0 ? $sort : strcmp((string)$a['slot_code'], (string)$b['slot_code']);
    });

    return $slots;
}

function af_inv_support_slot_labels(): array
{
    $labels = [];
    foreach (af_inv_support_slots() as $slotCode => $slot) {
        $labels[$slotCode] = (string)($slot['title'] ?? $slotCode);
    }
    return $labels;
}

function af_inv_support_bindings(int $uid): array
{
    global $db;
    $out = [];
    if ($uid <= 0 || !$db->table_exists('af_advinv_support_slots')) {
        return $out;
    }
    $q = $db->simple_select('af_advinv_support_slots', '*', 'uid=' . (int)$uid, ['order_by' => 'sortorder ASC, slot_code ASC']);
    while ($row = $db->fetch_array($q)) {
        $out[(string)$row['slot_code']] = $row;
    }
    return $out;
}

function af_inv_find_support_slot_by_item(array $bindings, int $itemId): string
{
    foreach ($bindings as $slotCode => $row) {
        if ((int)($row['item_id'] ?? 0) === $itemId) {
            return (string)$slotCode;
        }
    }
    return '';
}

function af_inv_is_consumable_item(array $item): bool
{
    return trim((string)($item['subtype'] ?? '')) === 'consumable';
}

function af_inv_map_legacy_support_slot(string $slot): string
{
    $slot = trim($slot);
    $map = af_inv_legacy_support_slot_map();
    return $map[$slot] ?? $slot;
}

function af_inv_equipment_slots(): array
{
    return ['head' => 'Head', 'body' => 'Body', 'hands' => 'Hands', 'legs' => 'Legs', 'feet' => 'Feet', 'weapon_mainhand' => 'Main hand', 'weapon_offhand' => 'Off hand', 'ammo' => 'Ammo', 'artifact' => 'Artifact'] + af_inv_support_slot_labels();
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

function af_inv_get_item_for_owner(int $ownerUid, int $itemId): array
{
    global $db;

    $ownerUid = (int)$ownerUid;
    $itemId = (int)$itemId;
    if ($ownerUid <= 0 || $itemId <= 0) {
        return [];
    }

    $row = (array)$db->fetch_array(
        $db->simple_select(
            AF_ADVINV_TABLE_ITEMS,
            '*',
            'id=' . $itemId . ' AND uid=' . $ownerUid,
            ['limit' => 1]
        )
    );

    if (!$row) {
        return [];
    }

    $items = af_advinv_enrich_items_from_kb([$row]);
    return (array)($items[0] ?? $row);
}

function af_inv_find_equipped_slot_by_item(array $equipped, int $itemId): string
{
    $itemId = (int)$itemId;
    if ($itemId <= 0) {
        return '';
    }

    foreach ($equipped as $slotCode => $row) {
        if ((int)($row['item_id'] ?? 0) === $itemId) {
            return (string)$slotCode;
        }
    }

    return '';
}

function af_inv_candidate_slots_for_item(array $item): array
{
    $entity = af_advancedinventory_normalize_entity((string)($item['entity'] ?? ''));
    if ($entity !== 'equipment') {
        return [];
    }

    $knownSlots = af_inv_equipment_slots();
    $meta = af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''));
    $result = [];

    $pushSlot = static function (string $rawSlot) use (&$result, $knownSlots): void {
        $slot = mb_strtolower(trim($rawSlot));
        if ($slot === '') {
            return;
        }

        $aliases = [
            'mainhand'      => 'weapon_mainhand',
            'main_hand'     => 'weapon_mainhand',
            'weapon'        => 'weapon_mainhand',
            'offhand'       => 'weapon_offhand',
            'off_hand'      => 'weapon_offhand',
            'ammo'          => 'ammo',
            'body'          => 'body',
            'head'          => 'head',
            'hands'         => 'hands',
            'legs'          => 'legs',
            'feet'          => 'feet',
            'artifact'      => 'artifact',
            'consumable'    => 'support_1',
            'consumable_1'  => 'support_1',
            'consumable_2'  => 'support_2',
        ];

        $slot = $aliases[$slot] ?? $slot;
        if (!isset($knownSlots[$slot])) {
            return;
        }

        $result[$slot] = $slot;
    };

    $paths = [
        'rules.item.equip.slot',
        'rules.item.equip.slots',
        'rules.item.slot',
        'equip.slot',
        'equip.slots',
        'slot',
    ];

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

        if (is_array($cursor)) {
            foreach ($cursor as $slotValue) {
                $pushSlot((string)$slotValue);
            }
        } else {
            $pushSlot((string)$cursor);
        }
    }

    if ($result) {
        return array_values($result);
    }

    $subtype = mb_strtolower(trim((string)($item['subtype'] ?? '')));
    if ($subtype === '') {
        $subtype = af_advinv_classify_equipment_from_kb_meta($meta);
    }

    switch ($subtype) {
        case 'weapon':
            $pushSlot('weapon_mainhand');
            $pushSlot('weapon_offhand');
            break;

        case 'armor':
            $pushSlot('body');
            break;

        case 'ammo':
            $pushSlot('ammo');
            break;

        case 'consumable':
            foreach (array_keys(af_inv_support_slots()) as $supportSlotCode) {
                $pushSlot($supportSlotCode);
            }
            break;

        case 'augmentations':
            $pushSlot('artifact');
            break;
    }

    return array_values($result);
}

function af_advinv_export_charactersheet_equipment_state(int $uid): array
{
    $items = [];
    $equipped = [];
    $groups = [
        'armor' => ['title' => 'Броня', 'slots' => []],
        'weapon' => ['title' => 'Оружие', 'slots' => []],
        'ammo' => ['title' => 'Боеприпасы', 'slots' => []],
        'support' => ['title' => 'Поддержка', 'slots' => []],
    ];

    if ($uid <= 0) {
        return ['items' => [], 'equipped' => [], 'groups' => $groups];
    }

    $allowedSubtypes = ['weapon', 'armor', 'ammo', 'consumable'];
    $allItems = (array)(af_inv_get_items($uid, ['entity' => 'equipment', 'page' => 1, 'per_page' => 500, 'enrich' => true])['items'] ?? []);
    $equippedRows = af_inv_get_equipped($uid);
    $supportBindings = af_inv_support_bindings($uid);
    $slotLabels = af_inv_equipment_slots();

    foreach ($allItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $subtype = trim((string)($item['subtype'] ?? ''));
        if ($subtype === '') {
            $subtype = af_advinv_classify_equipment_from_kb_meta(af_advinv_decode_meta_json((string)($item['meta_json'] ?? '')));
        }
        if (!in_array($subtype, $allowedSubtypes, true)) {
            continue;
        }

        $itemId = (int)($item['id'] ?? 0);
        $equippedSlot = af_inv_find_equipped_slot_by_item($equippedRows, $itemId);
        if ($equippedSlot === '' && $subtype === 'consumable') {
            $equippedSlot = af_inv_find_support_slot_by_item($supportBindings, $itemId);
        }

        $candidateSlots = af_inv_candidate_slots_for_item($item);
        $items[] = [
            'id' => $itemId,
            'subtype' => $subtype,
            'title' => (string)($item['appearance_title'] ?? $item['title'] ?? $item['kb_key'] ?? 'Предмет'),
            'description' => (string)($item['description'] ?? ''),
            'short_description' => (string)($item['short_description'] ?? ''),
            'qty' => max(1, (int)($item['qty'] ?? 1)),
            'icon' => (string)($item['appearance_preview_image'] ?? $item['icon'] ?? ''),
            'kb_type' => (string)($item['kb_type'] ?? 'item'),
            'kb_key' => (string)($item['kb_key'] ?? ''),
            'candidate_slots' => array_values($candidateSlots),
            'equipped_slot' => $equippedSlot,
            'equipped_slot_label' => (string)($slotLabels[$equippedSlot] ?? $equippedSlot),
            'meta_json' => (string)($item['meta_json'] ?? ''),
        ];
    }

    foreach ($equippedRows as $slotCode => $row) {
        $item = af_inv_get_item_for_owner($uid, (int)($row['item_id'] ?? 0));
        if (!$item) {
            continue;
        }
        $subtype = trim((string)($item['subtype'] ?? ''));
        if ($subtype === '') {
            $subtype = af_advinv_classify_equipment_from_kb_meta(af_advinv_decode_meta_json((string)($item['meta_json'] ?? '')));
        }
        if (!in_array($subtype, ['weapon', 'armor', 'ammo'], true)) {
            continue;
        }

        $groupKey = $subtype;
        $equipped[$slotCode] = [
            'slot' => (string)$slotCode,
            'slot_label' => (string)($slotLabels[$slotCode] ?? $slotCode),
            'group' => $groupKey,
            'title' => (string)($item['appearance_title'] ?? $item['title'] ?? $item['kb_key'] ?? 'Предмет'),
            'icon' => (string)($item['appearance_preview_image'] ?? $item['icon'] ?? ''),
            'item_id' => (int)($item['id'] ?? 0),
            'kb_type' => (string)($item['kb_type'] ?? 'item'),
            'kb_key' => (string)($item['kb_key'] ?? ''),
            'subtype' => $subtype,
        ];
    }

    foreach ($supportBindings as $slotCode => $binding) {
        $item = af_inv_get_item_for_owner($uid, (int)($binding['item_id'] ?? 0));
        if (!$item || !af_inv_is_consumable_item($item)) {
            continue;
        }
        $equipped[$slotCode] = [
            'slot' => (string)$slotCode,
            'slot_label' => (string)($slotLabels[$slotCode] ?? $slotCode),
            'group' => 'support',
            'title' => (string)($item['appearance_title'] ?? $item['title'] ?? $item['kb_key'] ?? 'Предмет'),
            'icon' => (string)($item['appearance_preview_image'] ?? $item['icon'] ?? ''),
            'item_id' => (int)($item['id'] ?? 0),
            'kb_type' => (string)($item['kb_type'] ?? 'item'),
            'kb_key' => (string)($item['kb_key'] ?? ''),
            'subtype' => 'consumable',
        ];
    }

    foreach ($equipped as $slotCode => $slotItem) {
        $groupKey = (string)($slotItem['group'] ?? 'support');
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = ['title' => ucfirst($groupKey), 'slots' => []];
        }
        $groups[$groupKey]['slots'][$slotCode] = $slotItem;
    }

    return ['items' => $items, 'equipped' => $equipped, 'groups' => $groups];
}

function af_advinv_export_charactersheet_augmentations_inventory(int $uid): array
{
    if ($uid <= 0) {
        return [];
    }

    $out = [];
    $allItems = (array)(af_inv_get_items($uid, ['entity' => 'equipment', 'page' => 1, 'per_page' => 500, 'enrich' => true])['items'] ?? []);
    foreach ($allItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $subtype = trim((string)($item['subtype'] ?? ''));
        if ($subtype === '') {
            $subtype = af_advinv_classify_equipment_from_kb_meta(af_advinv_decode_meta_json((string)($item['meta_json'] ?? '')));
        }
        if ($subtype !== 'augmentations') {
            continue;
        }
        $out[] = $item;
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
    global $mybb;

    $filters = [
        'entity' => $entity,
        'subtype' => $sub,
        'page' => max(1, $page),
    ];

    $data = af_inv_get_items($ownerUid, array_merge($filters, ['enrich' => true]));
    $items = (array)($data['items'] ?? []);
    $canManage = af_advancedinventory_user_can_manage();
    $canEditOwner = af_inv_can_manage_owner((int)($mybb->user['uid'] ?? 0), $ownerUid);
    $equipped = $entity === 'equipment' ? af_inv_get_equipped($ownerUid) : [];
    $wallet = af_advinv_wallet_payload($ownerUid);

    $filterButtons = af_advinv_render_subfilter_links(
        $entity,
        $ownerUid,
        $sub,
        af_advancedinventory_subfilters($entity)
    );

    $selectedItemId = !empty($items) ? (int)($items[0]['id'] ?? 0) : 0;
    $hiddenPostKey = htmlspecialchars_uni((string)$mybb->post_code);
    $apiBase = af_advancedinventory_url('', [], false);

    $html = '';
    $html .= '<div class="af-inv-entity-page" data-entity="' . htmlspecialchars_uni($entity) . '">';
    $html .= '<input type="hidden" name="my_post_key" value="' . $hiddenPostKey . '">';
    $html .= '<div class="af-inv-subfilters">' . $filterButtons . '</div>';
    $html .= '<div class="af-inv-api"'
        . ' data-api-base="' . htmlspecialchars_uni($apiBase) . '"'
        . ' data-owner="' . (int)$ownerUid . '"'
        . ' data-can-edit="' . ($canEditOwner ? '1' : '0') . '"'
        . ' data-wallet-balance="' . htmlspecialchars_uni((string)($wallet['balance_major'] ?? '0')) . '"'
        . ' data-wallet-symbol="' . htmlspecialchars_uni((string)($wallet['currency_symbol'] ?? '₡')) . '"></div>';
    $html .= af_advinv_render_workspace($entity, $items, $selectedItemId, $canManage, $canEditOwner, $equipped);
    $html .= '</div>';

    return $html;
}

function af_advinv_render_workspace(string $entity, array $items, int $selectedItemId, bool $canManage, bool $canEditOwner, array $equipped): string
{
    $html = '<div class="af-inv-workspace" data-entity="' . htmlspecialchars_uni($entity) . '">';
    $html .= '<div class="af-inv-slots-pane">';
    $html .= af_advinv_render_slot_grid($items, $selectedItemId, $equipped);
    $html .= '</div>';
    $html .= '<div class="af-inv-preview-pane">';
    $html .= af_advinv_render_preview_stack($items, $selectedItemId, $canManage, $canEditOwner, $equipped);
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function af_advinv_render_slot_grid(array $items, int $selectedItemId, array $equipped): string
{
    if (!$items) {
        return '<div class="af-inv-empty">В этом разделе пока пусто.</div>';
    }

    $equippedByItem = [];
    foreach ($equipped as $slotCode => $row) {
        $itemId = (int)($row['item_id'] ?? 0);
        if ($itemId > 0) {
            $equippedByItem[$itemId] = (string)$slotCode;
        }
    }

    $activeMapByUid = [];
    $html = '<div class="af-inv-slot-grid">';

    foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $kbKey = trim((string)($item['kb_key'] ?? ''));

        $appearanceInfo = af_advinv_resolve_appearance_item($item);
        $appearanceMeta = is_array($appearanceInfo['appearance_meta'] ?? null)
            ? (array)$appearanceInfo['appearance_meta']
            : [];

        $isVisual = !empty($item['is_visual_item'])
            || !empty($appearanceInfo['is_visual_item'])
            || trim((string)($appearanceInfo['source_type'] ?? '')) === 'appearance'
            || strpos($kbKey, 'appearance:') === 0
            || !empty($appearanceMeta);

        $appearanceTarget = trim((string)($item['appearance_target'] ?? ($appearanceInfo['target_key'] ?? ($appearanceMeta['target_key'] ?? ''))));

        $isActive = !empty($item['appearance_is_active']);
        if ($isVisual && !$isActive && $appearanceTarget !== '') {
            $uid = (int)($item['uid'] ?? 0);
            if ($uid > 0) {
                if (!isset($activeMapByUid[$uid])) {
                    $activeMapByUid[$uid] = af_advinv_active_appearance_map($uid);
                }
                $activeTarget = (array)($activeMapByUid[$uid][$appearanceTarget] ?? []);
                $isActive = ((int)($activeTarget['item_id'] ?? 0) === $itemId);
            }
        }

        $title = trim((string)($item['appearance_title'] ?? ($appearanceMeta['title'] ?? ($item['title'] ?? 'Предмет'))));
        if ($title === '') {
            $title = $kbKey !== '' ? $kbKey : 'Предмет';
        }

        $icon = trim((string)($item['appearance_preview_image'] ?? ($appearanceMeta['preview_image'] ?? ($item['icon'] ?? ''))));
        $qty = max(1, (int)($item['qty'] ?? 1));
        $equippedSlot = (string)($equippedByItem[$itemId] ?? '');
        $isSelected = $selectedItemId > 0 && $selectedItemId === $itemId;

        $classes = ['af-inv-slot'];
        if ($isSelected) {
            $classes[] = 'is-selected';
        }
        if ($isVisual) {
            $classes[] = 'is-appearance';
        }
        if ($isActive) {
            $classes[] = 'is-active';
        }
        if ($equippedSlot !== '') {
            $classes[] = 'is-equipped';
        }

        $iconHtml = $icon !== ''
            ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="' . htmlspecialchars_uni($title) . '" loading="lazy">'
            : '<span class="af-inv-slot__placeholder">?</span>';

        $badgeHtml = $qty > 1 ? '<span class="af-inv-slot__badge">' . $qty . '</span>' : '';
        $stateMark = '';
        if ($equippedSlot !== '') {
            $stateMark = '<span class="af-inv-slot__state">E</span>';
        } elseif ($isActive) {
            $stateMark = '<span class="af-inv-slot__state">A</span>';
        }

        $html .= '<button type="button" class="' . htmlspecialchars_uni(implode(' ', $classes)) . '"'
            . ' data-item-select="' . $itemId . '"'
            . ' data-item-id="' . $itemId . '"'
            . ' aria-label="' . htmlspecialchars_uni($title) . '">'
            . '<span class="af-inv-slot__icon">' . $iconHtml . '</span>'
            . $badgeHtml
            . $stateMark
            . '</button>';
    }

    $html .= '</div>';
    return $html;
}

function af_advinv_render_preview_stack(array $items, int $selectedItemId, bool $canManage, bool $canEditOwner, array $equipped): string
{
    if (!$items) {
        return '<div class="af-inv-preview-card is-active"><div class="af-inv-empty">Выбери раздел с предметами или купи что-нибудь в магазине.</div></div>';
    }

    $html = '';
    foreach ($items as $index => $item) {
        $itemId = (int)($item['id'] ?? 0);
        $active = ($selectedItemId > 0 ? $selectedItemId === $itemId : $index === 0);
        $html .= af_advinv_render_preview_card($item, $active, $canManage, $canEditOwner, $equipped);
    }

    return $html;
}

function af_advinv_render_preview_card(array $item, bool $active, bool $canManage, bool $canEditOwner, array $equipped): string
{
    $itemId = (int)($item['id'] ?? 0);
    $kbKey = trim((string)($item['kb_key'] ?? ''));

    $appearanceInfo = af_advinv_resolve_appearance_item($item);
    $appearanceMeta = is_array($appearanceInfo['appearance_meta'] ?? null)
        ? (array)$appearanceInfo['appearance_meta']
        : [];

    $isVisual = !empty($item['is_visual_item'])
        || !empty($appearanceInfo['is_visual_item'])
        || trim((string)($appearanceInfo['source_type'] ?? '')) === 'appearance'
        || strpos($kbKey, 'appearance:') === 0
        || !empty($appearanceMeta);

    $appearanceTarget = trim((string)($item['appearance_target'] ?? ($appearanceInfo['target_key'] ?? ($appearanceMeta['target_key'] ?? ''))));
    $appearanceSlug = trim((string)($item['appearance_slug'] ?? ($appearanceMeta['slug'] ?? '')));
    $appearancePresetId = (int)($item['appearance_preset_id'] ?? ($appearanceInfo['preset_id'] ?? ($appearanceMeta['preset_id'] ?? 0)));

    $title = trim((string)($item['appearance_title'] ?? ($appearanceMeta['title'] ?? ($item['title'] ?? 'Предмет'))));
    if ($title === '') {
        $title = $kbKey !== '' ? $kbKey : 'Предмет';
    }

    $icon = trim((string)($item['appearance_preview_image'] ?? ($appearanceMeta['preview_image'] ?? ($item['icon'] ?? ''))));
    $qty = max(1, (int)($item['qty'] ?? 1));
    $subtype = trim((string)($item['subtype'] ?? ''));

    $isActiveAppearance = !empty($item['appearance_is_active']);
    if ($isVisual && !$isActiveAppearance && $appearanceTarget !== '') {
        $uid = (int)($item['uid'] ?? 0);
        if ($uid > 0) {
            $activeMap = af_advinv_active_appearance_map($uid);
            $activeTarget = (array)($activeMap[$appearanceTarget] ?? []);
            $isActiveAppearance = ((int)($activeTarget['item_id'] ?? 0) === $itemId);
        }
    }

    $sale = af_advinv_item_sale_profile($item);

    $slotCandidates = af_inv_candidate_slots_for_item($item);
    $equippedSlot = af_inv_find_equipped_slot_by_item($equipped, $itemId);
    $slotLabels = af_inv_equipment_slots();

    $classes = ['af-inv-preview-card'];
    if ($active) {
        $classes[] = 'is-active';
    }

    $metaRows = [];
    if ($subtype !== '') {
        $metaRows[] = ['Тип', $subtype];
    }
    if ($kbKey !== '') {
        $metaRows[] = ['Ключ', $kbKey];
    }
    if ($isVisual && $appearancePresetId > 0) {
        $metaRows[] = ['Preset', '#' . $appearancePresetId];
    }
    if ($isVisual && $appearanceSlug !== '') {
        $metaRows[] = ['Slug', $appearanceSlug];
    }
    if ($isVisual && $appearanceTarget !== '') {
        $metaRows[] = ['Target', $appearanceTarget];
    }
    if ($equippedSlot !== '') {
        $metaRows[] = ['Надето', (string)($slotLabels[$equippedSlot] ?? $equippedSlot)];
    } elseif ($slotCandidates) {
        $defaultSlot = (string)$slotCandidates[0];
        $metaRows[] = ['Слот', (string)($slotLabels[$defaultSlot] ?? $defaultSlot)];
    }
    if (!empty($sale['price_each_minor'])) {
        $metaRows[] = ['Цена магазина / шт.', (string)$sale['price_each_major'] . ' ' . (string)$sale['currency_symbol']];
        $metaRows[] = ['Продажа / шт. (80%)', (string)$sale['sell_each_major'] . ' ' . (string)$sale['currency_symbol']];
        if ($qty > 1) {
            $metaRows[] = ['Продажа за весь стак', (string)$sale['sell_total_major'] . ' ' . (string)$sale['currency_symbol']];
        }
    }

    $statusHtml = '';
    if ($isVisual) {
        $statusHtml = '<div class="af-inv-preview-status ' . ($isActiveAppearance ? 'is-active' : 'is-inactive') . '">'
            . ($isActiveAppearance ? 'Пресет активен' : 'Пресет не активен')
            . '</div>';
    } elseif ($equippedSlot !== '') {
        $statusHtml = '<div class="af-inv-preview-status is-active">' . (af_inv_is_consumable_item($item) ? 'Назначен в быстрый слот' : 'Предмет надет') . '</div>';
    } elseif ($slotCandidates) {
        $statusHtml = '<div class="af-inv-preview-status is-inactive">' . (af_inv_is_consumable_item($item) ? 'Предмет готов к быстрому слоту' : 'Предмет готов к экипировке') . '</div>';
    }

    $actions = '';
    if ($canEditOwner) {
        if ($isVisual) {
            $actions .= '<button type="button" class="af-inv-action" data-af-appearance-apply-btn data-item-id="' . $itemId . '"' . ($isActiveAppearance ? ' disabled="disabled"' : '') . '>Активировать</button>';
            $actions .= '<button type="button" class="af-inv-action" data-af-appearance-unapply-btn data-target-key="' . htmlspecialchars_uni($appearanceTarget) . '"' . (!$isActiveAppearance ? ' disabled="disabled"' : '') . '>Снять</button>';
        } else {
            if ($equippedSlot !== '') {
                if (af_inv_is_consumable_item($item)) {
                    $actions .= '<button type="button" class="af-inv-action" data-action="unbind_support_slot" data-item-id="' . $itemId . '" data-slot-code="' . htmlspecialchars_uni($equippedSlot) . '">Убрать из быстрого слота</button>';
                } else {
                    $actions .= '<button type="button" class="af-inv-action" data-action="unequip" data-item-id="' . $itemId . '" data-equip-slot="' . htmlspecialchars_uni($equippedSlot) . '">Снять</button>';
                }
            } elseif ($slotCandidates) {
                $defaultSlot = (string)$slotCandidates[0];
                if (af_inv_is_consumable_item($item)) {
                    $actions .= '<button type="button" class="af-inv-action" data-action="bind_support_slot" data-item-id="' . $itemId . '" data-slot-code="' . htmlspecialchars_uni($defaultSlot) . '">В быстрый слот</button>';
                } else {
                    $actions .= '<button type="button" class="af-inv-action" data-action="equip" data-item-id="' . $itemId . '" data-equip-slot="' . htmlspecialchars_uni($defaultSlot) . '">Надеть</button>';
                }
            }
        }

        $actions .= '<button type="button" class="af-inv-action" data-action="sell" data-item-id="' . $itemId . '"' . (empty($sale['can_sell']) ? ' disabled="disabled"' : '') . '>Продать</button>';
    }

    if ($canManage || $canEditOwner) {
        $qtyInputValue = $canManage ? $qty : 1;
        $actions .= '<label class="af-inv-qty-wrap">Qty <input type="number" min="1" max="' . $qty . '" class="af-inv-qty" value="' . $qtyInputValue . '" data-max-qty="' . $qty . '"></label>';
    }

    if ($canManage) {
        $actions .= '<button type="button" class="af-inv-action" data-action="update" data-item-id="' . $itemId . '">Сохранить</button>';
        $actions .= '<button type="button" class="af-inv-action" data-action="delete" data-item-id="' . $itemId . '">Удалить</button>';
    }

    $iconHtml = $icon !== ''
        ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="' . htmlspecialchars_uni($title) . '" loading="lazy">'
        : '<div class="af-inv-card__placeholder">?</div>';

    $metaHtml = '';
    if ($metaRows) {
        $metaHtml .= '<dl class="af-inv-preview-meta">';
        foreach ($metaRows as $row) {
            $metaHtml .= '<dt>' . htmlspecialchars_uni((string)$row[0]) . '</dt><dd>' . htmlspecialchars_uni((string)$row[1]) . '</dd>';
        }
        $metaHtml .= '</dl>';
    }

    return '<section class="' . htmlspecialchars_uni(implode(' ', $classes)) . '" data-preview-item="' . $itemId . '"' . ($active ? '' : ' hidden="hidden"') . '>'
        . '<div class="af-inv-preview-media">' . $iconHtml . '</div>'
        . '<div class="af-inv-preview-body">'
        . '<h3 class="af-inv-preview-title">' . htmlspecialchars_uni($title) . '</h3>'
        . '<div class="af-inv-preview-qty">Количество: x' . $qty . '</div>'
        . $statusHtml
        . $metaHtml
        . '<div class="af-inv-card-actions af-inv-preview-actions">' . $actions . '</div>'
        . '</div>'
        . '</section>';
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
    $slotLabels = af_inv_equipment_slots();

    foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $qty = max(1, (int)($item['qty'] ?? 1));
        $title = trim((string)($item['appearance_title'] ?? ($item['title'] ?? '')));
        if ($title === '') {
            $title = trim((string)($item['kb_key'] ?? 'Предмет'));
        }

        $subtype = trim((string)($item['subtype'] ?? ''));
        $kbKey = trim((string)($item['kb_key'] ?? ''));
        $icon = trim((string)($item['appearance_preview_image'] ?? ($item['icon'] ?? '')));
        $iconHtml = $icon !== ''
            ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="' . htmlspecialchars_uni($title) . '" loading="lazy">'
            : '<div class="af-inv-card__placeholder">?</div>';

        $isVisualItem = !empty($item['is_visual_item']);
        $cardClasses = ['af-inv-card'];
        $actions = '';
        $statusHtml = '';

        if ($isVisualItem) {
            $cardClasses[] = 'af-inv-card--appearance';

            $appearanceTarget = trim((string)($item['appearance_target'] ?? ''));
            $presetId = (int)($item['appearance_preset_id'] ?? 0);
            $presetSlug = trim((string)($item['appearance_slug'] ?? ''));
            $isActive = !empty($item['appearance_is_active']);

            $metaParts = [];
            if ($presetId > 0) {
                $metaParts[] = 'preset #' . $presetId;
            }
            if ($presetSlug !== '') {
                $metaParts[] = 'slug: ' . $presetSlug;
            }
            if ($appearanceTarget !== '') {
                $metaParts[] = 'target: ' . $appearanceTarget;
            }

            $statusHtml = '<div class="af-inv-card-status ' . ($isActive ? 'is-active' : 'is-inactive') . '">'
                . ($isActive ? 'Активен' : 'Не активен')
                . '</div>';

            $actions .= '<button type="button" class="af-inv-action" data-af-appearance-apply-btn data-item-id="' . $itemId . '"' . ($isActive ? ' disabled="disabled"' : '') . '>Активировать</button>';
            $actions .= '<button type="button" class="af-inv-action" data-af-appearance-unapply-btn data-target-key="' . htmlspecialchars_uni($appearanceTarget) . '"' . (!$isActive ? ' disabled="disabled"' : '') . '>Снять</button>';
            $sale = af_advinv_item_sale_profile($item, 1);
            $actions .= '<button type="button" class="af-inv-action" data-action="sell" data-item-id="' . $itemId . '"' . (empty($sale['can_sell']) ? ' disabled="disabled"' : '') . '>Продать</button>';

            if ($canManage) {
                $actions .= '<button type="button" class="af-inv-action" data-action="delete" data-item-id="' . $itemId . '">Удалить</button>';
            }

            $rows .= '<article class="' . htmlspecialchars_uni(implode(' ', $cardClasses)) . '" data-item-id="' . $itemId . '" data-is-visual-item="1" data-appearance-target="' . htmlspecialchars_uni($appearanceTarget) . '" data-appearance-active="' . ($isActive ? '1' : '0') . '">'
                . '<div class="af-inv-card__media">' . $iconHtml . '</div>'
                . '<div class="af-inv-card-title">' . htmlspecialchars_uni($title) . '</div>'
                . '<div class="af-inv-card-meta">' . htmlspecialchars_uni(implode(' / ', $metaParts)) . '</div>'
                . '<div class="af-inv-card-qty">x' . $qty . '</div>'
                . $statusHtml
                . '<div class="af-inv-card-actions">' . $actions . '</div>'
                . '</article>';

            continue;
        }

        if ($allowEquipActions) {
            $slotCandidates = af_inv_candidate_slots_for_item($item);
            $equippedSlot = af_inv_find_equipped_slot_by_item($equipped, $itemId);

            if ($equippedSlot !== '') {
                $cardClasses[] = 'is-equipped';
                $slotLabel = (string)($slotLabels[$equippedSlot] ?? $equippedSlot);
                $statusHtml = '<div class="af-inv-card-status is-active">' . (af_inv_is_consumable_item($item) ? 'Быстрый слот: ' : 'Надето: ') . htmlspecialchars_uni($slotLabel) . '</div>';
                $actions .= af_inv_is_consumable_item($item)
                    ? '<button type="button" class="af-inv-action" data-action="unbind_support_slot" data-item-id="' . $itemId . '" data-slot-code="' . htmlspecialchars_uni($equippedSlot) . '">Убрать из быстрого слота</button>'
                    : '<button type="button" class="af-inv-action" data-action="unequip" data-item-id="' . $itemId . '" data-equip-slot="' . htmlspecialchars_uni($equippedSlot) . '">Снять</button>';
            } elseif ($slotCandidates) {
                $defaultSlot = (string)$slotCandidates[0];
                $slotLabel = (string)($slotLabels[$defaultSlot] ?? $defaultSlot);
                $statusHtml = '<div class="af-inv-card-status is-inactive">' . (af_inv_is_consumable_item($item) ? 'Быстрый слот: ' : 'Слот: ') . htmlspecialchars_uni($slotLabel) . '</div>';
                $actions .= af_inv_is_consumable_item($item)
                    ? '<button type="button" class="af-inv-action" data-action="bind_support_slot" data-item-id="' . $itemId . '" data-slot-code="' . htmlspecialchars_uni($defaultSlot) . '">В быстрый слот</button>'
                    : '<button type="button" class="af-inv-action" data-action="equip" data-item-id="' . $itemId . '" data-equip-slot="' . htmlspecialchars_uni($defaultSlot) . '">Надеть</button>';
            }
        }

        if ($canManage) {
            $actions .= '<button type="button" class="af-inv-action" data-action="delete" data-item-id="' . $itemId . '">Удалить</button>';
            $actions .= '<label class="af-inv-qty-wrap">Qty <input type="number" min="1" class="af-inv-qty" value="' . $qty . '"></label>';
            $actions .= '<button type="button" class="af-inv-action" data-action="update" data-item-id="' . $itemId . '">Сохранить</button>';
        }

        $metaParts = [];
        if ($subtype !== '') {
            $metaParts[] = $subtype;
        }
        if ($kbKey !== '') {
            $metaParts[] = $kbKey;
        }

        $rows .= '<article class="' . htmlspecialchars_uni(implode(' ', $cardClasses)) . '" data-item-id="' . $itemId . '">'
            . '<div class="af-inv-card__media">' . $iconHtml . '</div>'
            . '<div class="af-inv-card-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-inv-card-meta">' . htmlspecialchars_uni(implode(' / ', $metaParts)) . '</div>'
            . '<div class="af-inv-card-qty">x' . $qty . '</div>'
            . $statusHtml
            . '<div class="af-inv-card-actions">' . $actions . '</div>'
            . '</article>';
    }

    if ($rows === '') {
        $rows = '<div class="af-inv-empty">В этом разделе пока пусто.</div>';
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
