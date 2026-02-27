<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVINV_ID', 'advancedinventory');
define('AF_ADVINV_BASE', AF_ADDONS . AF_ADVINV_ID . '/');
define('AF_ADVINV_TPL_DIR', AF_ADVINV_BASE . 'templates/');
define('AF_ADVINV_ASSET_DIR', AF_ADVINV_BASE . 'assets/');
define('AF_ADVINV_ALIAS_MARKER', "define('AF_ADVANCEDINVENTORY_PAGE_ALIAS', 1);");

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
    af_advancedinventory_ensure_setting('af_advancedinventory_default_tab', 'Default tab', 'Default tab for inventory page', "select\nequipment=equipment\nresources=resources\npets=pets\ncustomization=customization", 'equipment', 4, $gid);

    af_advancedinventory_ensure_inventory_storage();

    if (!$db->table_exists('af_inventory_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_inventory_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
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
            KEY uid_slot (uid, slot),
            KEY uid_slot_subtype (uid, slot, subtype),
            KEY uid_kb (uid, kb_type, kb_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    af_advancedinventory_templates_install_or_update();
    af_advancedinventory_migrate_from_shop();
    if (!af_advancedinventory_alias_sync()) {
        af_advancedinventory_alias_sync_notice_on_failure();
    }

    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_activate(): void
{
    af_advancedinventory_templates_install_or_update();
    af_advancedinventory_ensure_inventory_storage();
    af_advancedinventory_migrate_from_shop();
    if (!af_advancedinventory_alias_sync()) {
        af_advancedinventory_alias_sync_notice_on_failure();
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_deactivate(): void
{
    $target = af_advancedinventory_alias_target_path();
    if (af_advancedinventory_alias_is_ours($target)) {
        @unlink($target);
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

    $target = af_advancedinventory_alias_target_path();
    if (af_advancedinventory_alias_is_ours($target)) {
        @unlink($target);
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedinventory_is_installed(): bool
{
    global $db;
    return $db->table_exists('af_inventory_items');
}

function af_advancedinventory_alias_target_path(): string
{
    return MYBB_ROOT . 'inventory.php';
}

function af_advancedinventory_alias_asset_path(): string
{
    return AF_ADVINV_ASSET_DIR . 'inventory.php';
}

function af_advancedinventory_alias_is_ours(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) { return false; }
    return strpos((string)file_get_contents($path), AF_ADVINV_ALIAS_MARKER) !== false;
}

function af_advancedinventory_alias_sync(): bool
{
    $target = af_advancedinventory_alias_target_path();
    $asset = af_advancedinventory_alias_asset_path();
    if (!is_file($asset) || !is_readable($asset)) { return false; }
    if (is_file($target) && !af_advancedinventory_alias_is_ours($target)) { return false; }
    return (bool)@copy($asset, $target);
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
    return af_advancedinventory_alias_is_ours(af_advancedinventory_alias_target_path());
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
    if (!in_array($action, ['inventory', 'tab', 'api_list', 'api_move', 'api_equip', 'api_unequip'], true)) {
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

function af_advancedinventory_dispatch(string $action): void
{
    switch ($action) {
        case 'inventory': af_advancedinventory_render_inventory(); return;
        case 'tab': af_advancedinventory_render_tab(); return;
        case 'api_list': af_advancedinventory_api_list(); return;
        case 'api_move':
        case 'api_equip':
        case 'api_unequip': af_advancedinventory_json(['ok' => false, 'error' => 'not_implemented'], 501); return;
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

    $defaultTab = (string)($mybb->settings['af_advancedinventory_default_tab'] ?? 'equipment');
    if (!in_array($defaultTab, ['equipment', 'resources', 'pets', 'customization'], true)) {
        $defaultTab = 'equipment';
    }

    $assetBase = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/';
    $cssFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.css';
    $jsFile = AF_ADVINV_ASSET_DIR . 'advancedinventory.js';
    $vCss = @is_file($cssFile) ? (string)@filemtime($cssFile) : '1';
    $vJs = @is_file($jsFile) ? (string)@filemtime($jsFile) : '1';
    $headerinclude .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($assetBase . 'advancedinventory.css?v=' . rawurlencode($vCss)) . '">';
    $headerinclude .= '<script src="' . htmlspecialchars_uni($assetBase . 'advancedinventory.js?v=' . rawurlencode($vJs)) . '" defer></script>';

    $tabs = af_advancedinventory_tabs();
    $tabLinks = '';
    foreach ($tabs as $code => $title) {
        $active = $code === $defaultTab ? 'is-active' : '';
        $tabLinks .= '<button type="button" class="af-inv-tab ' . $active . '" data-tab="' . htmlspecialchars_uni($code) . '">' . htmlspecialchars_uni($title) . '</button>';
    }

    $firstUrl = af_advancedinventory_url('tab', ['uid' => $ownerUid, 'tab' => $defaultTab, 'sub' => 'all', 'ajax' => 1], true);
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

function af_advancedinventory_render_tab(): void
{
    global $mybb, $templates;
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $ownerUid = (int)$mybb->get_input('uid');
    if ($ownerUid <= 0) { $ownerUid = $viewerUid; }
    if (!af_inv_user_can_view($viewerUid, $ownerUid)) {
        error_no_permission();
    }

    $tab = (string)$mybb->get_input('tab');
    if (!array_key_exists($tab, af_advancedinventory_tabs())) { $tab = 'equipment'; }
    $sub = trim((string)$mybb->get_input('sub'));

    $slot = $tab;
    if ($tab === 'equipment') { $slot = 'equipment'; }
    if ($tab === 'resources') { $slot = 'resources'; }
    if ($tab === 'pets') { $slot = 'pets'; }
    if ($tab === 'customization') { $slot = 'customization'; }

    $filters = ['slot' => $slot, 'subtype' => $sub, 'page' => max(1, (int)$mybb->get_input('page'))];
    $data = af_inv_get_items($ownerUid, $filters);

    $rows = '';
    foreach ($data['items'] as $item) {
        $qty = (int)$item['qty'];
        $title = (string)$item['title'];
        $subtype = (string)$item['subtype'];
        $icon = trim((string)$item['icon']);
        $iconHtml = $icon !== '' ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="" loading="lazy">' : '';
        $rows .= '<div class="af-inv-card">' . $iconHtml . '<div class="af-inv-card-title">' . htmlspecialchars_uni($title) . '</div><div class="af-inv-card-meta">' . htmlspecialchars_uni($subtype) . '</div><div class="af-inv-card-qty">x' . $qty . '</div></div>';
    }
    if ($rows === '') {
        $rows = '<div class="af-inv-empty">Inventory is empty.</div>';
    }

    $filterButtons = '';
    foreach (af_advancedinventory_subfilters($tab) as $code => $title) {
        $isActive = ($code === ($sub === '' ? 'all' : $sub)) ? 'is-active' : '';
        $url = af_advancedinventory_url('tab', ['uid' => $ownerUid, 'tab' => $tab, 'sub' => $code, 'ajax' => 1], true);
        $filterButtons .= '<a class="af-inv-subfilter ' . $isActive . '" href="' . $url . '">' . htmlspecialchars_uni($title) . '</a>';
    }

    $html = '<div class="af-inv-subfilters">' . $filterButtons . '</div><div class="af-inv-grid">' . $rows . '</div>';
    output_page($html);
    exit;
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
    af_advancedinventory_json(['ok' => true, 'data' => af_inv_get_items($ownerUid, ['slot' => (string)$mybb->get_input('slot'), 'subtype' => (string)$mybb->get_input('subtype'), 'search' => (string)$mybb->get_input('search'), 'page' => (int)$mybb->get_input('page')])]);
}

function af_inv_add_item(int $uid, array $item): int
{
    global $db;
    $uid = max(0, $uid);
    if ($uid <= 0) { return 0; }
    $now = TIME_NOW;
    $slot = substr(trim((string)($item['slot'] ?? 'stash')), 0, 32);
    $subtype = substr(trim((string)($item['subtype'] ?? '')), 0, 32);
    $kbType = substr(trim((string)($item['kb_type'] ?? '')), 0, 32);
    $kbKey = substr(trim((string)($item['kb_key'] ?? '')), 0, 64);
    $title = substr(trim((string)($item['title'] ?? '')), 0, 255);
    $icon = substr(trim((string)($item['icon'] ?? '')), 0, 255);
    $qty = max(1, (int)($item['qty'] ?? 1));
    $metaJson = is_string($item['meta_json'] ?? null) ? (string)$item['meta_json'] : json_encode((array)($item['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $metaHash = md5((string)$metaJson);

    $where = "uid={$uid} AND slot='" . $db->escape_string($slot) . "' AND subtype='" . $db->escape_string($subtype) . "' AND kb_type='" . $db->escape_string($kbType) . "' AND kb_key='" . $db->escape_string($kbKey) . "' AND MD5(COALESCE(meta_json,''))='" . $db->escape_string($metaHash) . "'";
    $row = $db->fetch_array($db->simple_select('af_inventory_items', 'id,qty', $where, ['limit' => 1]));
    if ($row) {
        $id = (int)$row['id'];
        $db->update_query('af_inventory_items', ['qty' => (int)$row['qty'] + $qty, 'title' => $db->escape_string($title), 'icon' => $db->escape_string($icon), 'updated_at' => $now], 'id=' . $id);
        return $id;
    }

    return (int)$db->insert_query('af_inventory_items', [
        'uid' => $uid,
        'slot' => $db->escape_string($slot),
        'subtype' => $db->escape_string($subtype),
        'kb_type' => $db->escape_string($kbType),
        'kb_key' => $db->escape_string($kbKey),
        'title' => $db->escape_string($title),
        'icon' => $db->escape_string($icon),
        'qty' => $qty,
        'meta_json' => $db->escape_string((string)$metaJson),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function af_inv_remove_item(int $uid, int $itemId, int $qty = 1): bool
{
    global $db;
    $row = $db->fetch_array($db->simple_select('af_inventory_items', 'id,qty', 'id=' . (int)$itemId . ' AND uid=' . (int)$uid, ['limit' => 1]));
    if (!$row) { return false; }
    $left = (int)$row['qty'] - max(1, $qty);
    if ($left <= 0) {
        $db->delete_query('af_inventory_items', 'id=' . (int)$row['id'] . ' AND uid=' . (int)$uid);
        return true;
    }
    $db->update_query('af_inventory_items', ['qty' => $left, 'updated_at' => TIME_NOW], 'id=' . (int)$row['id'] . ' AND uid=' . (int)$uid);
    return true;
}

function af_inv_get_items(int $uid, array $filters = []): array
{
    global $db, $mybb;
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['perPage'] ?? ($mybb->settings['af_advancedinventory_perpage'] ?? 24)));
    $where = ['uid=' . (int)$uid];
    if (($filters['slot'] ?? '') !== '') {
        $where[] = "slot='" . $db->escape_string((string)$filters['slot']) . "'";
    }
    if (($filters['subtype'] ?? '') !== '' && (string)$filters['subtype'] !== 'all') {
        $where[] = "subtype='" . $db->escape_string((string)$filters['subtype']) . "'";
    }
    if (($filters['search'] ?? '') !== '') {
        $like = $db->escape_string_like((string)$filters['search']);
        $where[] = "(title LIKE '%{$like}%' OR kb_key LIKE '%{$like}%')";
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)$db->fetch_field($db->simple_select('af_inventory_items', 'COUNT(*) AS c', $whereSql), 'c');
    $offset = ($page - 1) * $perPage;
    $items = [];
    $q = $db->simple_select('af_inventory_items', '*', $whereSql, ['order_by' => 'updated_at', 'order_dir' => 'DESC', 'limit' => $perPage, 'start' => $offset]);
    while ($row = $db->fetch_array($q)) { $items[] = $row; }
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
    return (bool)array_intersect([3, 4, 6], af_advancedinventory_user_group_ids());
}

function af_advancedinventory_user_group_ids(): array
{
    global $mybb;
    $ids = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $gid) {
        $gid = (int)trim($gid);
        if ($gid > 0) { $ids[] = $gid; }
    }
    return array_values(array_unique(array_filter($ids)));
}

function af_advancedinventory_parse_groups_csv(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $g) {
        $gid = (int)trim($g);
        if ($gid > 0) { $out[] = $gid; }
    }
    return array_values(array_unique($out));
}

function af_advancedinventory_tabs(): array
{
    return [
        'equipment' => 'Экипировка',
        'resources' => 'Ресурсы',
        'pets' => 'Питомцы',
        'customization' => 'Кастомизация профиля',
    ];
}

function af_advancedinventory_subfilters(string $tab): array
{
    if ($tab === 'resources') { return ['all' => 'Все', 'loot' => 'Добыча', 'chests' => 'Сундуки', 'stones' => 'Камни']; }
    if ($tab === 'pets') { return ['eggs' => 'Яйца', 'pets' => 'Питомцы']; }
    if ($tab === 'customization') { return ['profile' => 'Профиль', 'postbit' => 'Постбит', 'sheet' => 'Лист персонажа']; }
    return ['all' => 'Все', 'weapon' => 'Оружие', 'armor' => 'Броня', 'ammo' => 'Боеприпасы', 'consumable' => 'Расходники'];
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
    if (!$db->table_exists('af_inventory_items')) { return; }
    $done = (string)$db->fetch_field($db->simple_select('datacache', 'cache', "title='af_advancedinventory_migrated'", ['limit' => 1]), 'cache');
    if ($done === '1') { return; }

    if ($db->table_exists('af_shop_inventory_legacy')) {
        $q = $db->query("SELECT uid, item_kind, slot_code, kb_id, qty, created_at, updated_at FROM " . TABLE_PREFIX . "af_shop_inventory_legacy");
        while ($row = $db->fetch_array($q)) {
            $kbId = (int)($row['kb_id'] ?? 0);
            af_inv_add_item((int)$row['uid'], [
                'slot' => (string)($row['slot_code'] ?? 'stash'),
                'subtype' => (string)($row['item_kind'] ?? ''),
                'kb_type' => 'item',
                'kb_key' => $kbId > 0 ? ('legacy_kb_' . $kbId) : '',
                'title' => '',
                'icon' => '',
                'qty' => (int)($row['qty'] ?? 1),
                'meta_json' => '',
            ]);
        }
    }

    if ($db->table_exists('af_shop_orders')) {
        $qOrders = $db->simple_select('af_shop_orders', 'uid, items_json', "status IN ('paid','completed','issued')", ['order_by' => 'order_id ASC']);
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
                af_inv_add_item($uid, [
                    'slot' => (string)($item['slot'] ?? 'stash'),
                    'subtype' => (string)($item['subtype'] ?? ''),
                    'kb_type' => (string)($item['kb_type'] ?? 'item'),
                    'kb_key' => (string)($item['kb_key'] ?? ''),
                    'title' => (string)($item['title'] ?? ''),
                    'icon' => (string)($item['icon'] ?? ''),
                    'qty' => max(1, (int)($item['qty'] ?? 1)),
                    'meta_json' => is_string($item['meta_json'] ?? null) ? (string)$item['meta_json'] : '',
                ]);
            }
        }
    }

    $exists = (int)$db->fetch_field($db->simple_select('datacache', 'COUNT(*) AS c', "title='af_advancedinventory_migrated'"), 'c');
    if ($exists > 0) {
        $db->update_query('datacache', ['cache' => '1'], "title='af_advancedinventory_migrated'");
    } else {
        $db->insert_query('datacache', ['title' => 'af_advancedinventory_migrated', 'cache' => '1']);
    }
}
