<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVSHOP_ID', 'advancedshop');
define('AF_ADVSHOP_BASE', AF_ADDONS . AF_ADVSHOP_ID . '/');
define('AF_ADVSHOP_TPL_DIR', AF_ADVSHOP_BASE . 'templates/');

function af_advancedshop_kb_table(): string
{
    return TABLE_PREFIX . 'af_kb_entries';
}

function af_advancedshop_kb_cols(): array
{
    global $db;

    static $cols = null;
    if (is_array($cols)) {
        return $cols;
    }

    $table = af_advancedshop_kb_table();
    $checkTable = strpos($table, TABLE_PREFIX) === 0 ? substr($table, strlen(TABLE_PREFIX)) : $table;
    $pick = static function (array $candidates) use ($db, $checkTable): ?string {
        foreach ($candidates as $candidate) {
            if ($db->field_exists($candidate, $checkTable)) {
                return $candidate;
            }
        }
        return null;
    };

    $cols = [
        'id' => $pick(['id', 'entry_id', 'kb_id']),
        'title' => $pick(['title_ru', 'title_en', 'title']),
        'title_ru' => $pick(['title_ru']),
        'title_en' => $pick(['title_en']),
        'body' => $pick(['body_ru', 'body_en', 'body']),
        'body_ru' => $pick(['body_ru']),
        'body_en' => $pick(['body_en']),
        'short' => $pick(['short_ru', 'short_en', 'short']),
        'short_ru' => $pick(['short_ru']),
        'short_en' => $pick(['short_en']),
        'meta_json' => $pick(['meta_json', 'meta']),
        'data_json' => $pick(['data_json', 'rules_json', 'data', 'rules', 'content_json']),
        'active' => $pick(['active', 'enabled']),
        'sortorder' => $pick(['sortorder', 'displayorder']),
    ];

    return $cols;
}

function af_advancedshop_init(): void
{
    global $plugins;
    $plugins->add_hook('global_start', 'af_advancedshop_register_routes', 10);
    $plugins->add_hook('misc_start', 'af_advancedshop_misc_router', 10);
}

function af_advancedshop_register_routes(): void
{
    // registration placeholder (canonical requirement)
}

function af_advancedshop_install(): void
{
    global $db, $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('advancedshop');
    }

    $gid = af_advancedshop_ensure_setting_group(
        $lang->af_advancedshop_group ?? 'AF: Shop',
        $lang->af_advancedshop_group_desc ?? 'Shop addon settings.'
    );
    af_advancedshop_ensure_setting('af_advancedshop_enabled', $lang->af_advancedshop_enabled ?? 'Enable shop', $lang->af_advancedshop_enabled_desc ?? 'Yes/No', 'yesno', '1', 1, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_manage_groups', $lang->af_advancedshop_manage_groups ?? 'Manage groups', $lang->af_advancedshop_manage_groups_desc ?? 'CSV IDs', 'text', '3,4', 2, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_currency_slug', $lang->af_advancedshop_currency_slug ?? 'Currency', $lang->af_advancedshop_currency_slug_desc ?? 'credits', 'text', 'credits', 3, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_items_per_page', 'Items per page', 'Shop page size', 'numeric', '24', 4, $gid);
    af_advancedshop_ensure_setting('af_advancedshop_allow_guest_view', 'Allow guest view', 'Guests may browse the shop', 'yesno', '1', 5, $gid);

    if (!$db->table_exists('af_shop')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop (
            shop_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_categories')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_categories (
            cat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            sortorder INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            KEY shop_sort (shop_id, parent_id, sortorder)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_slots')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_slots (
            slot_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            cat_id INT UNSIGNED NOT NULL,
            kb_type VARCHAR(32) NOT NULL DEFAULT 'item',
            kb_id INT UNSIGNED NOT NULL,
            price INT NOT NULL DEFAULT 0,
            currency VARCHAR(32) NOT NULL DEFAULT 'credits',
            stock INT NOT NULL DEFAULT -1,
            limit_per_user INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            meta_json MEDIUMTEXT NULL,
            KEY shop_cat_sort (shop_id, cat_id, enabled, sortorder),
            KEY kb_idx (kb_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_carts')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_carts (
            cart_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            uid INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY shop_uid (shop_id, uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_cart_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_cart_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cart_id INT UNSIGNED NOT NULL,
            slot_id INT UNSIGNED NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            KEY cart_slot (cart_id, slot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_shop_orders')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_shop_orders (
            order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            uid INT UNSIGNED NOT NULL,
            total INT NOT NULL DEFAULT 0,
            currency VARCHAR(32) NOT NULL DEFAULT 'credits',
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'paid',
            items_json MEDIUMTEXT NOT NULL,
            KEY uid_created (uid, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_inventory_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_inventory_items (
            inv_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
            kb_id INT UNSIGNED NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            stack_max INT NOT NULL DEFAULT 1,
            rarity VARCHAR(32) NOT NULL DEFAULT 'common',
            item_kind VARCHAR(32) NOT NULL DEFAULT '',
            slot_code VARCHAR(32) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY uid_kb (uid, kb_id),
            KEY uid_filters (uid, rarity, slot_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_wardrobe_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_wardrobe_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
            kb_id INT UNSIGNED NOT NULL,
            meta_json MEDIUMTEXT NULL,
            created_at INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists('af_treasury_items')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_treasury_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
            kb_id INT UNSIGNED NOT NULL,
            meta_json MEDIUMTEXT NULL,
            created_at INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $shops = [
        'game' => 'Game Shop',
        'customization' => 'Customization Shop',
        'other' => 'Other Shop',
    ];
    foreach ($shops as $code => $title) {
        $row = $db->fetch_array($db->simple_select('af_shop', 'shop_id', "code='" . $db->escape_string($code) . "'", ['limit' => 1]));
        if (!$row) {
            $db->insert_query('af_shop', [
                'code' => $db->escape_string($code),
                'title' => $db->escape_string($title),
                'enabled' => 1,
                'created_at' => TIME_NOW,
                'updated_at' => TIME_NOW,
            ]);
        }
    }

    af_advancedshop_templates_install_or_update();
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_activate(): void
{
    af_advancedshop_templates_install_or_update();
}

function af_advancedshop_deactivate(): void {}

function af_advancedshop_uninstall(): void
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        $db->delete_query('settings', 'gid=' . $gid);
        $db->delete_query('settinggroups', 'gid=' . $gid);
    }
    foreach (['advancedshop_%'] as $like) {
        $db->delete_query('templates', "title LIKE '" . $db->escape_string($like) . "'");
    }
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_is_installed(): bool
{
    global $db;
    return $db->table_exists('af_shop');
}

function af_advancedshop_misc_router(): void
{
    global $mybb;
    if (($mybb->input['action'] ?? '') === '') { return; }

    $action = (string)$mybb->get_input('action');
    $routes = ['shop','shop_category','shop_cart','shop_checkout','shop_add_to_cart','shop_update_cart','shop_manage','shop_manage_categories','shop_manage_category_create','shop_manage_slots','shop_kb_search','inventory'];
    if (!in_array($action, $routes, true)) { return; }

    $apiActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_slots', 'shop_kb_search'];
    $buyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart'];
    $manageActions = ['shop_manage', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_slots', 'shop_kb_search'];

    if ((int)($mybb->settings['af_advancedshop_enabled'] ?? 1) !== 1 && $action !== 'inventory') {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }

    if (in_array($action, ['shop', 'shop_category', 'shop_cart'], true) && !af_advancedshop_can_view_shop()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }
    if (in_array($action, $buyActions, true) && !af_advancedshop_can_buy()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }
    if (in_array($action, $manageActions, true) && !af_advancedshop_can_manage()) {
        if (in_array($action, $apiActions, true)) { af_advancedshop_json_err('Not allowed', 403); }
        error_no_permission();
    }

    $postKeyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_category_create'];
    if (in_array($action, $postKeyActions, true)) {
        af_advancedshop_assert_post_key();
    }
    if (in_array($action, ['shop_manage_slots', 'shop_manage_categories'], true) && strtolower($mybb->request_method) === 'post') {
        af_advancedshop_assert_post_key();
    }

    try {
        switch ($action) {
            case 'shop':
            case 'shop_category': af_advancedshop_render_shop(); return;
            case 'shop_cart': af_advancedshop_render_cart(); return;
            case 'shop_checkout': af_advancedshop_checkout(); return;
            case 'shop_add_to_cart': af_advancedshop_add_to_cart(); return;
            case 'shop_update_cart': af_advancedshop_update_cart(); return;
            case 'shop_manage': af_advancedshop_render_manage(); return;
            case 'shop_manage_categories': af_advancedshop_manage_categories(); return;
            case 'shop_manage_category_create': af_advancedshop_manage_category_create(); return;
            case 'shop_manage_slots': af_advancedshop_manage_slots(); return;
            case 'shop_kb_search': af_advancedshop_kb_search(); return;
            case 'inventory': af_advancedshop_render_inventory(); return;
        }
    } catch (mysqli_sql_exception $e) {
        if (in_array($action, $apiActions, true)) {
            $details = af_advancedshop_can_manage() ? ['details' => $e->getMessage()] : [];
            af_advancedshop_json_err('DB error', 500, $details);
        }
        throw $e;
    } catch (Throwable $e) {
        if (in_array($action, $apiActions, true)) {
            $details = af_advancedshop_can_manage() ? ['details' => $e->getMessage()] : [];
            af_advancedshop_json_err('Server error', 500, $details);
        }
        throw $e;
    }
}

function af_advancedshop_templates_install_or_update(): void
{
    global $db;
    foreach (glob(AF_ADVSHOP_TPL_DIR . '*.html') ?: [] as $file) {
        $name = basename($file, '.html');
        $template = (string)file_get_contents($file);
        $row = [
            'title' => $db->escape_string($name),
            'template' => $db->escape_string($template),
            'sid' => -2,
            'version' => '',
            'dateline' => TIME_NOW,
        ];
        $existing = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='".$db->escape_string($name)."'", ['limit' => 1]), 'tid');
        if ($existing > 0) {
            $db->update_query('templates', $row, 'tid=' . $existing);
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

function af_advancedshop_tpl(string $name): string
{
    global $templates;
    return $templates->get($name, 1, 0);
}

function af_advancedshop_ensure_setting_group(string $title, string $desc): int
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
    if ($gid > 0) { return $gid; }
    $disp = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm') + 1;
    $db->insert_query('settinggroups', ['name' => 'af_advancedshop', 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'disporder' => $disp, 'isdefault' => 0]);
    return (int)$db->insert_id();
}

function af_advancedshop_ensure_setting(string $name, string $title, string $desc, string $code, string $value, int $order, int $gid): void
{
    global $db;
    $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]), 'sid');
    $row = ['name' => $db->escape_string($name), 'title' => $db->escape_string($title), 'description' => $db->escape_string($desc), 'optionscode' => $db->escape_string($code), 'value' => $db->escape_string($value), 'disporder' => $order, 'gid' => $gid, 'isdefault' => 0];
    if ($sid > 0) { $db->update_query('settings', $row, 'sid=' . $sid); }
    else { $db->insert_query('settings', $row); }
}

function af_advancedshop_assert_post_key(): void
{
    global $mybb;
    if (strtolower($mybb->request_method) !== 'post') {
        af_advancedshop_json_err('POST required', 405);
    }
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        af_advancedshop_json_err('Invalid post key', 403);
    }
}

function af_advancedshop_parse_groups_csv(string $csv): array
{
    $ids = [];
    foreach (explode(',', $csv) as $part) {
        $id = (int)trim($part);
        if ($id > 0) { $ids[$id] = $id; }
    }
    return array_values($ids);
}

function af_advancedshop_user_group_ids(): array
{
    global $mybb;
    $ids = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) {
        $gid = (int)trim($g);
        if ($gid > 0) { $ids[] = $gid; }
    }
    return array_values(array_unique(array_filter($ids)));
}

function af_advancedshop_can_manage(): bool
{
    global $mybb;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) { return false; }
    $allowed = af_advancedshop_parse_groups_csv((string)($mybb->settings['af_advancedshop_manage_groups'] ?? '3,4'));
    return (bool)array_intersect($allowed, af_advancedshop_user_group_ids());
}

function af_advancedshop_can_view_shop(): bool
{
    global $mybb;
    if ((int)($mybb->user['uid'] ?? 0) > 0) {
        return true;
    }
    return (int)($mybb->settings['af_advancedshop_allow_guest_view'] ?? 1) === 1;
}

function af_advancedshop_can_buy(): bool
{
    global $mybb;
    return (int)($mybb->user['uid'] ?? 0) > 0;
}

function af_advancedshop_current_shop(): array
{
    global $mybb, $db;
    $code = $db->escape_string((string)$mybb->get_input('shop'));
    if ($code === '') { $code = 'game'; }
    $shop = $db->fetch_array($db->simple_select('af_shop', '*', "code='".$code."'", ['limit' => 1]));
    if (!$shop) {
        error('Shop not found');
    }
    return $shop;
}

function af_advancedshop_render_shop(): void
{
    global $db, $mybb, $lang, $headerinclude, $header, $footer;
    if (!af_advancedshop_can_view_shop()) {
        error_no_permission();
    }
    $shop = af_advancedshop_current_shop();
    $shopId = (int)$shop['shop_id'];
    $catId = (int)$mybb->get_input('cat');

    $cats = '';
    $qCats = $db->simple_select('af_shop_categories', '*', 'shop_id=' . $shopId . ' AND enabled=1', ['order_by' => 'sortorder,title', 'order_dir' => 'ASC']);
    while ($cat = $db->fetch_array($qCats)) {
        $rowCatId = (int)$cat['cat_id'];
        $activeClass = $catId === $rowCatId ? 'is-active' : '';
        $cat_url = 'misc.php?action=shop_category&amp;shop=' . urlencode($shop['code']) . '&amp;cat=' . $rowCatId;
        $cat_title = htmlspecialchars_uni($cat['title']);
        eval('$cats .= "' . af_advancedshop_tpl('advancedshop_manage_category_row') . '";');
    }

    $slotsHtml = '';
    $where = 's.shop_id=' . $shopId . ' AND s.enabled=1';
    if ($catId > 0) { $where .= ' AND s.cat_id=' . $catId; }
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $kbSelect = ['e.' . $kbIdCol . ' AS kb_id'];
    if (!empty($kbCols['title_ru'])) { $kbSelect[] = 'e.' . $kbCols['title_ru'] . ' AS kb_title_ru'; }
    if (!empty($kbCols['title_en'])) { $kbSelect[] = 'e.' . $kbCols['title_en'] . ' AS kb_title_en'; }
    if (empty($kbCols['title_ru']) && empty($kbCols['title_en']) && !empty($kbCols['title'])) { $kbSelect[] = 'e.' . $kbCols['title'] . ' AS kb_title'; }
    if (!empty($kbCols['short_ru'])) { $kbSelect[] = 'e.' . $kbCols['short_ru'] . ' AS kb_short_ru'; }
    if (!empty($kbCols['short_en'])) { $kbSelect[] = 'e.' . $kbCols['short_en'] . ' AS kb_short_en'; }
    if (empty($kbCols['short_ru']) && empty($kbCols['short_en']) && !empty($kbCols['short'])) { $kbSelect[] = 'e.' . $kbCols['short'] . ' AS kb_short'; }
    if (!empty($kbCols['body_ru'])) { $kbSelect[] = 'e.' . $kbCols['body_ru'] . ' AS kb_body_ru'; }
    if (!empty($kbCols['body_en'])) { $kbSelect[] = 'e.' . $kbCols['body_en'] . ' AS kb_body_en'; }
    if (empty($kbCols['body_ru']) && empty($kbCols['body_en']) && !empty($kbCols['body'])) { $kbSelect[] = 'e.' . $kbCols['body'] . ' AS kb_body'; }
    if (!empty($kbCols['meta_json'])) { $kbSelect[] = 'e.' . $kbCols['meta_json'] . ' AS kb_meta'; }
    if (!empty($kbCols['data_json'])) { $kbSelect[] = 'e.' . $kbCols['data_json'] . ' AS kb_data'; }
    $qSlots = $db->query("SELECT s.*, " . implode(', ', $kbSelect) . "
        FROM " . TABLE_PREFIX . "af_shop_slots s
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        WHERE {$where}
        ORDER BY s.sortorder ASC, s.slot_id DESC");

    while ($slot = $db->fetch_array($qSlots)) {
        $slot_id = (int)$slot['slot_id'];
        $slot_price = (int)$slot['price'];
        $kbTitle = af_advancedshop_pick_lang((string)($slot['kb_title_ru'] ?? ''), (string)($slot['kb_title_en'] ?? ''));
        if ($kbTitle === '') { $kbTitle = (string)($slot['kb_title'] ?? ''); }
        $slot_title = htmlspecialchars_uni($kbTitle ?: ('#' . (int)$slot['kb_id']));
        $shortText = af_advancedshop_pick_lang((string)($slot['kb_short_ru'] ?? ''), (string)($slot['kb_short_en'] ?? ''));
        if ($shortText === '') { $shortText = (string)($slot['kb_short'] ?? ''); }
        if ($shortText === '') {
            $bodyText = af_advancedshop_pick_lang((string)($slot['kb_body_ru'] ?? ''), (string)($slot['kb_body_en'] ?? ''));
            if ($bodyText === '') { $bodyText = (string)($slot['kb_body'] ?? ''); }
            $shortText = mb_substr(strip_tags($bodyText), 0, 140);
        }
        $slot_short = htmlspecialchars_uni($shortText);
        $meta = @json_decode((string)($slot['kb_meta'] ?? '{}'), true);
        $data = @json_decode((string)($slot['kb_data'] ?? '{}'), true);
        $slot_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ($slot['icon_url'] ?? '')));
        $slot_rarity = htmlspecialchars_uni((string)($data['item']['rarity'] ?? ($data['rarity'] ?? 'common')));
        eval('$slotsHtml .= "' . af_advancedshop_tpl('advancedshop_product_card') . '";');
    }

    $balance = (int)af_shop_get_balance((int)($mybb->user['uid'] ?? 0), (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $shop_title = htmlspecialchars_uni($lang->af_advancedshop_shop_title ?? 'Shop');
    $cart_url = 'misc.php?action=shop_cart&amp;shop=' . urlencode($shop['code']);
    $assets = af_advancedshop_assets_html();
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_shop') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_assets_html(): string
{
    global $mybb;
    $base = rtrim((string)$mybb->settings['bburl'], '/') . '/inc/plugins/advancedfunctionality/addons/advancedshop/assets';
    return '<link rel="stylesheet" href="' . htmlspecialchars_uni($base . '/advancedshop.css') . '"><script src="' . htmlspecialchars_uni($base . '/advancedshop.js') . '"></script>';
}

function af_advancedshop_render_cart(): void
{
    global $db, $mybb, $lang, $headerinclude, $header, $footer;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], (int)$mybb->user['uid']);
    [$itemsHtml, $total] = af_advancedshop_build_cart_items($cart);
    $balance = (int)af_shop_get_balance((int)$mybb->user['uid'], (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $can_checkout = $balance >= $total ? '' : 'disabled="disabled"';
    $msg = $balance >= $total ? '' : '<div class="af-shop-error">' . htmlspecialchars_uni($lang->af_advancedshop_error_not_enough_money ?? 'Not enough money') . '</div>';
    $assets = af_advancedshop_assets_html();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_cart') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_get_or_create_cart(int $shopId, int $uid): array
{
    global $db;
    $row = $db->fetch_array($db->simple_select('af_shop_carts', '*', 'shop_id=' . $shopId . ' AND uid=' . $uid, ['limit' => 1]));
    if ($row) { return $row; }
    $db->insert_query('af_shop_carts', ['shop_id' => $shopId, 'uid' => $uid, 'updated_at' => TIME_NOW]);
    return $db->fetch_array($db->simple_select('af_shop_carts', '*', 'cart_id=' . (int)$db->insert_id(), ['limit' => 1]));
}

function af_advancedshop_build_cart_items(array $cart): array
{
    global $db;
    $itemsHtml = '';
    $total = 0;
    $q = $db->query("SELECT ci.*, s.price, s.kb_id, e.title_ru, e.title_en, e.meta_json
        FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_slots s ON(s.slot_id=ci.slot_id)
        LEFT JOIN " . TABLE_PREFIX . "af_kb_entries e ON(e.id=s.kb_id)
        WHERE ci.cart_id=" . (int)$cart['cart_id'] . " ORDER BY ci.id ASC");
    while ($row = $db->fetch_array($q)) {
        $item_id = (int)$row['id'];
        $slot_id = (int)$row['slot_id'];
        $qty = max(1, (int)$row['qty']);
        $price = (int)$row['price'];
        $sum = $qty * $price;
        $total += $sum;
        $meta = @json_decode((string)($row['meta_json'] ?? '{}'), true);
        $item_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ''));
        $item_title = htmlspecialchars_uni(af_advancedshop_pick_lang((string)($row['title_ru'] ?? ''), (string)($row['title_en'] ?? '')));
        eval('$itemsHtml .= "' . af_advancedshop_tpl('advancedshop_cart_item') . '";');
    }
    return [$itemsHtml, $total];
}

function af_advancedshop_add_to_cart(): void
{
    global $mybb, $db;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    $shop = af_advancedshop_current_shop();
    $slotId = (int)$mybb->get_input('slot');
    $qty = max(1, (int)$mybb->get_input('qty'));
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], $uid);
    $existing = $db->fetch_array($db->simple_select('af_shop_cart_items', '*', 'cart_id=' . (int)$cart['cart_id'] . ' AND slot_id=' . $slotId, ['limit' => 1]));
    if ($existing) {
        $db->update_query('af_shop_cart_items', ['qty' => (int)$existing['qty'] + $qty], 'id=' . (int)$existing['id']);
    } else {
        $db->insert_query('af_shop_cart_items', ['cart_id' => (int)$cart['cart_id'], 'slot_id' => $slotId, 'qty' => $qty]);
    }
    $db->update_query('af_shop_carts', ['updated_at' => TIME_NOW], 'cart_id=' . (int)$cart['cart_id']);
    af_advancedshop_json_ok();
}

function af_advancedshop_update_cart(): void
{
    global $mybb, $db;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    $itemId = (int)$mybb->get_input('item_id');
    $qty = (int)$mybb->get_input('qty');
    $item = $db->fetch_array($db->query("SELECT ci.* FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_carts c ON(c.cart_id=ci.cart_id)
        WHERE ci.id={$itemId} AND c.uid={$uid} LIMIT 1"));
    if (!$item) { af_advancedshop_json_err('not_found', 404); }
    if ($qty <= 0) {
        $db->delete_query('af_shop_cart_items', 'id=' . $itemId);
    } else {
        $db->update_query('af_shop_cart_items', ['qty' => $qty], 'id=' . $itemId);
    }
    af_advancedshop_json_ok();
}

function af_advancedshop_checkout(): void
{
    global $mybb, $db, $lang;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json_err('auth', 403); }
    $shop = af_advancedshop_current_shop();
    $currency = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], $uid);
    [$items, $total] = af_advancedshop_checkout_collect_items((int)$cart['cart_id']);
    if (!$items || $total <= 0) { af_advancedshop_json_err('empty', 400); }

    $balance = af_shop_get_balance($uid, $currency);
    if ($balance < $total) {
        af_advancedshop_json_err($lang->af_advancedshop_error_not_enough_money ?? 'Not enough money', 400);
    }

    $db->write_query('START TRANSACTION');
    try {
        $orderId = (int)$db->insert_query('af_shop_orders', [
            'shop_id' => (int)$shop['shop_id'],
            'uid' => $uid,
            'total' => $total,
            'currency' => $db->escape_string($currency),
            'created_at' => TIME_NOW,
            'status' => 'paid',
            'items_json' => $db->escape_string(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        af_shop_sub_balance($uid, $currency, $total, 'shop_purchase', ['order_id' => $orderId, 'shop' => $shop['code']]);

        foreach ($items as $item) {
            af_advancedshop_grant_inventory_item($uid, (int)$item['kb_id'], (int)$item['qty']);
        }

        $db->delete_query('af_shop_cart_items', 'cart_id=' . (int)$cart['cart_id']);
        $db->write_query('COMMIT');
    } catch (Throwable $e) {
        $db->write_query('ROLLBACK');
        af_advancedshop_json_err('checkout_failed', 500);
    }

    af_advancedshop_json_ok(['message' => $lang->af_advancedshop_success_checkout ?? 'Checkout complete', 'redirect' => 'misc.php?action=shop_cart&shop=' . urlencode((string)$shop['code'])]);
}

function af_advancedshop_checkout_collect_items(int $cartId): array
{
    global $db;
    $items = [];
    $total = 0;
    $q = $db->query("SELECT ci.qty, s.slot_id, s.kb_id, s.price, s.currency
        FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_slots s ON(s.slot_id=ci.slot_id)
        WHERE ci.cart_id={$cartId} AND s.enabled=1");
    while ($row = $db->fetch_array($q)) {
        $qty = max(1, (int)$row['qty']);
        $price = max(0, (int)$row['price']);
        $items[] = [
            'slot_id' => (int)$row['slot_id'],
            'kb_id' => (int)$row['kb_id'],
            'qty' => $qty,
            'price_each' => $price,
            'currency' => (string)$row['currency'],
        ];
        $total += $qty * $price;
    }
    return [$items, $total];
}

function af_advancedshop_grant_inventory_item(int $uid, int $kbId, int $qty): void
{
    global $db;
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $select = [$kbIdCol . ' AS kb_id'];
    if (!empty($kbCols['data_json'])) {
        $select[] = $kbCols['data_json'] . ' AS kb_data';
    }
    if ($db->field_exists('item_kind', 'af_kb_entries')) {
        $select[] = 'item_kind';
    }
    $kb = $db->fetch_array($db->query("SELECT " . implode(',', $select) . " FROM " . af_advancedshop_kb_table() . " WHERE " . $kbIdCol . "=" . $kbId . " LIMIT 1"));
    if (!$kb) { return; }
    $data = @json_decode((string)($kb['kb_data'] ?? '{}'), true);
    $stackMax = max(1, (int)($data['item']['stack_max'] ?? 1));
    $rarity = (string)($data['item']['rarity'] ?? ($data['rarity'] ?? 'common'));
    $slotCode = (string)($data['item']['slot'] ?? '');
    $itemKind = (string)($kb['item_kind'] ?? ($data['item_kind'] ?? ''));

    $left = $qty;
    while ($left > 0) {
        $row = $db->fetch_array($db->simple_select('af_inventory_items', '*', 'uid=' . $uid . ' AND kb_id=' . $kbId . ' AND qty < stack_max', ['order_by' => 'inv_id', 'order_dir' => 'ASC', 'limit' => 1]));
        if ($row && $stackMax > 1) {
            $can = max(0, (int)$row['stack_max'] - (int)$row['qty']);
            $add = min($can, $left);
            if ($add > 0) {
                $db->update_query('af_inventory_items', ['qty' => (int)$row['qty'] + $add, 'updated_at' => TIME_NOW], 'inv_id=' . (int)$row['inv_id']);
                $left -= $add;
                continue;
            }
        }

        $pack = $stackMax > 1 ? min($stackMax, $left) : 1;
        $db->insert_query('af_inventory_items', [
            'uid' => $uid,
            'kb_id' => $kbId,
            'qty' => $pack,
            'stack_max' => $stackMax,
            'rarity' => $db->escape_string($rarity),
            'item_kind' => $db->escape_string($itemKind),
            'slot_code' => $db->escape_string($slotCode),
            'created_at' => TIME_NOW,
            'updated_at' => TIME_NOW,
        ]);
        $left -= $pack;
    }
}

function af_advancedshop_render_manage(): void
{
    global $lang, $headerinclude, $header, $footer, $db, $mybb;
    if (!af_advancedshop_can_manage()) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);

    $rows = '';
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'sortorder ASC, cat_id ASC']);
    while ($cat = $db->fetch_array($q)) {
        $cat_id = (int)$cat['cat_id'];
        $cat_title = htmlspecialchars_uni((string)$cat['title']);
        $cat_parent = (int)$cat['parent_id'];
        $cat_sortorder = (int)$cat['sortorder'];
        $slots_url = 'misc.php?action=shop_manage_slots&amp;shop=' . urlencode((string)$shop['code']) . '&amp;cat=' . $cat_id;
        eval('$rows .= "' . af_advancedshop_tpl('advancedshop_manage_category_row') . '";');
    }

    $assets = af_advancedshop_assets_html();
    eval('$categories_table = "' . af_advancedshop_tpl('advancedshop_manage_categories') . '";');
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}
function af_advancedshop_manage_categories(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();
    $do = (string)$mybb->get_input('do');

    if (strtolower($mybb->request_method) === 'get' || $do === 'list') {
        $rows = [];
        $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'sortorder ASC, cat_id ASC']);
        while ($r = $db->fetch_array($q)) {
            $rows[] = [
                'cat_id' => (int)$r['cat_id'],
                'title' => (string)$r['title'],
                'parent_id' => (int)$r['parent_id'],
                'enabled' => (int)$r['enabled'],
                'sortorder' => (int)$r['sortorder'],
            ];
        }
        af_advancedshop_json_ok(['categories' => $rows]);
    }

    if ($do === 'create') {
        af_advancedshop_manage_category_create();
    }

    if ($do === 'update') {
        $catId = (int)$mybb->get_input('cat_id');
        if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
        $title = trim((string)$mybb->get_input('title'));
        if ($title === '') { af_advancedshop_json_err('Title required', 422); }
        if (my_strlen($title) > 255) { af_advancedshop_json_err('Title too long', 422); }
        $db->update_query('af_shop_categories', [
            'parent_id' => (int)$mybb->get_input('parent_id'),
            'title' => $db->escape_string($title),
            'description' => $db->escape_string((string)$mybb->get_input('description')),
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        ], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
        af_advancedshop_json_ok();
    }

    if ($do === 'delete') {
        $catId = (int)$mybb->get_input('cat_id');
        if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
        $db->delete_query('af_shop_categories', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
        af_advancedshop_json_ok();
    }

    af_advancedshop_json_err('unsupported', 400);
}
function af_advancedshop_manage_category_create(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        af_advancedshop_json_err('Invalid post key', 403);
    }

    $shop = af_advancedshop_current_shop();
    $title = trim((string)$mybb->get_input('title'));
    if ($title === '') {
        $title = trim((string)$mybb->get_input('name'));
    }
    if ($title === '') { af_advancedshop_json_err('Title required', 422); }
    if (my_strlen($title) > 255) { af_advancedshop_json_err('Title too long', 422); }

    $parentId = (int)$mybb->get_input('parent_id');
    $sortorder = (int)$mybb->get_input('sortorder');
    $catId = (int)$db->insert_query('af_shop_categories', [
        'shop_id' => (int)$shop['shop_id'],
        'parent_id' => $parentId,
        'title' => $db->escape_string($title),
        'description' => $db->escape_string((string)$mybb->get_input('description')),
        'sortorder' => $sortorder,
        'enabled' => 1,
    ]);

    af_advancedshop_json_ok([
        'cat' => [
            'cat_id' => $catId,
            'title' => $title,
            'parent_id' => $parentId,
            'enabled' => 1,
            'sortorder' => $sortorder,
        ],
    ]);
}
function af_advancedshop_manage_slots(): void
{
    global $mybb, $db, $headerinclude, $header, $footer;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();
    $catId = (int)$mybb->get_input('cat');
    $do = (string)$mybb->get_input('do');

    if (strtolower($mybb->request_method) === 'get' && $do === '') {
        $shop_code = htmlspecialchars_uni((string)$shop['code']);
        $cat_id = $catId;
        $assets = af_advancedshop_assets_html();
        eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage_slots') . '";');
        eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
        output_page($page);
        exit;
    }

    if (strtolower($mybb->request_method) === 'get' && ($do === 'list' || $do === '')) {
        $rows = [];
        $kbCols = af_advancedshop_kb_cols();
        $kbIdCol = $kbCols['id'] ?? 'id';
        $titleSelect = [];
        if (!empty($kbCols['title_ru'])) { $titleSelect[] = 'e.' . $kbCols['title_ru'] . ' AS kb_title_ru'; }
        if (!empty($kbCols['title_en'])) { $titleSelect[] = 'e.' . $kbCols['title_en'] . ' AS kb_title_en'; }
        if (empty($titleSelect) && !empty($kbCols['title'])) { $titleSelect[] = 'e.' . $kbCols['title'] . ' AS kb_title'; }
        if (!$titleSelect) { $titleSelect[] = "'' AS kb_title"; }
        $q = $db->query("SELECT s.*, " . implode(', ', $titleSelect) . " FROM " . TABLE_PREFIX . "af_shop_slots s
            LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
            WHERE s.shop_id=" . (int)$shop['shop_id'] . ($catId > 0 ? " AND s.cat_id={$catId}" : '') . " ORDER BY s.sortorder ASC, s.slot_id DESC");
        while ($r = $db->fetch_array($q)) {
            $title = af_advancedshop_pick_lang((string)($r['kb_title_ru'] ?? ''), (string)($r['kb_title_en'] ?? ''));
            if ($title === '') { $title = (string)($r['kb_title'] ?? ''); }
            $rows[] = [
                'slot_id' => (int)$r['slot_id'],
                'kb_id' => (int)$r['kb_id'],
                'title' => $title,
                'price' => (int)$r['price'],
                'cat_id' => (int)$r['cat_id'],
                'sortorder' => (int)$r['sortorder'],
                'enabled' => (int)$r['enabled'],
            ];
        }
        af_advancedshop_json_ok(['rows' => $rows]);
    }

    if (strtolower($mybb->request_method) !== 'post') { af_advancedshop_json_err('POST required', 405); }

    if ($do === 'create') {
        $db->insert_query('af_shop_slots', [
            'shop_id' => (int)$shop['shop_id'],
            'cat_id' => (int)$mybb->get_input('cat_id'),
            'kb_type' => 'item',
            'kb_id' => (int)$mybb->get_input('kb_id'),
            'price' => (int)$mybb->get_input('price'),
            'currency' => $db->escape_string((string)($mybb->get_input('currency') ?: $mybb->settings['af_advancedshop_currency_slug'])),
            'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
            'limit_per_user' => (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
            'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
            'meta_json' => $db->escape_string((string)$mybb->get_input('meta_json')),
        ]);
        af_advancedshop_json_ok();
    }

    if ($do === 'update') {
        $slotId = (int)$mybb->get_input('slot_id');
        $db->update_query('af_shop_slots', [
            'cat_id' => (int)$mybb->get_input('cat_id'),
            'price' => (int)$mybb->get_input('price'),
            'currency' => $db->escape_string((string)$mybb->get_input('currency')),
            'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
            'limit_per_user' => (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
            'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
            'meta_json' => $db->escape_string((string)$mybb->get_input('meta_json')),
        ], 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);
        af_advancedshop_json_ok();
    }

    if ($do === 'delete') {
        $slotId = (int)$mybb->get_input('slot_id');
        $db->delete_query('af_shop_slots', 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);
        af_advancedshop_json_ok();
    }

    af_advancedshop_json_err('unsupported', 400);
}
function af_advancedshop_kb_search(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('forbidden', 403); }
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $shortRuCol = $kbCols['short_ru'] ?? null;
    $shortEnCol = $kbCols['short_en'] ?? null;
    $shortCol = $kbCols['short'] ?? null;
    $q = trim((string)$mybb->get_input('q'));
    $escaped = $db->escape_string($q);
    $where = '1=1';
    if (!empty($kbCols['active'])) {
        $where .= ' AND ' . $kbCols['active'] . '=1';
    }
    if ($escaped !== '') {
        $searchParts = [];
        foreach (array_filter([$titleRuCol, $titleEnCol, $titleCol, $shortRuCol, $shortEnCol, $shortCol]) as $column) {
            $searchParts[] = $column . " LIKE '%{$escaped}%'";
        }
        if ($searchParts) {
            $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
        }
    }
    if (!empty($kbCols['data_json'])) {
        $needle = $db->escape_string('"type_profile":"item"');
        $where .= " AND " . $kbCols['data_json'] . " LIKE '%{$needle}%'";
    }
    $select = [
        $kbIdCol . ' AS kb_id',
        ($titleRuCol ? $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($shortRuCol ? $shortRuCol . ' AS kb_short_ru' : "'' AS kb_short_ru"),
        ($shortEnCol ? $shortEnCol . ' AS kb_short_en' : "'' AS kb_short_en"),
        ($shortCol ? $shortCol . ' AS kb_short' : "'' AS kb_short"),
        (!empty($kbCols['meta_json']) ? $kbCols['meta_json'] . ' AS kb_meta' : "'' AS kb_meta"),
    ];
    if (!empty($kbCols['data_json'])) {
        $select[] = $kbCols['data_json'] . ' AS kb_data';
    }
    $orderSort = !empty($kbCols['sortorder']) ? $kbCols['sortorder'] . ' ASC, ' : '';
    $sql = "SELECT " . implode(',', $select) . " FROM " . af_advancedshop_kb_table() . " WHERE {$where} ORDER BY {$orderSort}{$kbIdCol} DESC LIMIT 50";
    $res = $db->query($sql);
    $items = [];
    while ($row = $db->fetch_array($res)) {
        $data = @json_decode((string)($row['kb_data'] ?? '{}'), true);
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
        $title = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($title === '') { $title = (string)($row['kb_title'] ?? ''); }
        $short = af_advancedshop_pick_lang((string)($row['kb_short_ru'] ?? ''), (string)($row['kb_short_en'] ?? ''));
        if ($short === '') { $short = (string)($row['kb_short'] ?? ''); }
        $items[] = [
            'kb_id' => (int)$row['kb_id'],
            'title' => $title,
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)($data['item']['rarity'] ?? ($data['rarity'] ?? 'common')),
            'stack_max' => (int)($data['item']['stack_max'] ?? 1),
            'short' => $short,
        ];
    }
    af_advancedshop_json_ok(['items' => $items]);
}

function af_advancedshop_render_inventory(): void
{
    global $mybb, $db, $headerinclude, $header, $footer;
    $requestUid = (int)$mybb->get_input('uid');
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    $targetUid = $requestUid > 0 ? $requestUid : $viewerUid;
    if ($targetUid <= 0) { error_no_permission(); }
    $canViewAny = af_advancedshop_can_manage();
    if ($targetUid !== $viewerUid && !$canViewAny) { error_no_permission(); }

    $rarityFilter = trim((string)$mybb->get_input('rarity'));
    $slotFilter = trim((string)$mybb->get_input('slot_code'));
    $search = trim((string)$mybb->get_input('q'));

    $where = 'i.uid=' . $targetUid;
    if ($rarityFilter !== '') { $where .= " AND i.rarity='" . $db->escape_string($rarityFilter) . "'"; }
    if ($slotFilter !== '') { $where .= " AND i.slot_code='" . $db->escape_string($slotFilter) . "'"; }
    if ($search !== '') {
        $s = $db->escape_string($search);
        $where .= " AND (e.title_ru LIKE '%{$s}%' OR e.title_en LIKE '%{$s}%')";
    }

    $grid = '';
    $q = $db->query("SELECT i.*, e.title_ru, e.title_en, e.body_ru, e.body_en, e.meta_json
        FROM " . TABLE_PREFIX . "af_inventory_items i
        LEFT JOIN " . TABLE_PREFIX . "af_kb_entries e ON(e.id=i.kb_id)
        WHERE {$where}
        ORDER BY i.updated_at DESC, i.inv_id DESC");
    while ($row = $db->fetch_array($q)) {
        $inv_id = (int)$row['inv_id'];
        $inv_qty = (int)$row['qty'];
        $inv_title = htmlspecialchars_uni(af_advancedshop_pick_lang((string)$row['title_ru'], (string)$row['title_en']));
        $meta = @json_decode((string)($row['meta_json'] ?? '{}'), true);
        $inv_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ''));
        $inv_rarity = htmlspecialchars_uni((string)($row['rarity'] ?? 'common'));
        $tooltip_body = af_advancedshop_parse_bbcode((string)af_advancedshop_pick_lang((string)$row['body_ru'], (string)$row['body_en']));
        eval('$grid .= "' . af_advancedshop_tpl('advancedshop_inventory_slot') . '";');
    }

    $assets = af_advancedshop_assets_html();
    $inventory_uid = $targetUid;
    eval('$inventory_grid = "' . af_advancedshop_tpl('advancedshop_inventory_grid') . '";');
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_inventory') . '";');

    if ((int)$mybb->get_input('ajax') === 1) {
        echo $af_advancedshop_content;
        exit;
    }

    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_parse_bbcode(string $text): string
{
    if ($text === '') { return ''; }
    if (!class_exists('postParser')) { require_once MYBB_ROOT . 'inc/class_parser.php'; }
    $parser = new postParser();
    return $parser->parse_message($text, [
        'allow_html' => 0,
        'allow_mycode' => 1,
        'allow_basicmycode' => 1,
        'allow_smilies' => 1,
        'allow_imgcode' => 1,
        'allow_videocode' => 1,
        'filter_badwords' => 1,
        'nl2br' => 1,
    ]);
}

function af_advancedshop_pick_lang(string $ru, string $en): string
{
    global $mybb;
    $lang = (string)($mybb->settings['bblanguage'] ?? 'russian');
    return $lang === 'english' ? ($en ?: $ru) : ($ru ?: $en);
}

function af_shop_get_balance(int $uid, string $currency_slug): int
{
    $currency_slug = $currency_slug === '' ? 'credits' : $currency_slug;
    if ($currency_slug === 'credits' && function_exists('af_balance_get')) {
        $bal = af_balance_get($uid);
        return (int)($bal['credits'] ?? 0);
    }
    return 0;
}

function af_shop_add_balance(int $uid, string $currency_slug, int $amount, string $reason, array $meta = []): void
{
    if ($currency_slug === 'credits' && function_exists('af_balance_add_credits')) {
        $meta['reason'] = $reason;
        $meta['source'] = 'advancedshop';
        af_balance_add_credits($uid, $amount / 100, $meta);
    }
}

function af_shop_sub_balance(int $uid, string $currency_slug, int $amount, string $reason, array $meta = []): void
{
    af_shop_add_balance($uid, $currency_slug, -abs($amount), $reason, $meta);
}

function af_advancedshop_json(array $data): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_advancedshop_json_ok(array $payload = []): void
{
    af_advancedshop_json(array_merge(['ok' => true], $payload));
}

function af_advancedshop_json_err(string $message, int $code = 400, array $extra = []): void
{
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    af_advancedshop_json(array_merge(['ok' => false, 'error' => $message, 'code' => $code], $extra));
}
