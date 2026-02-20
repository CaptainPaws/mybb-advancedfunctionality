<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVSHOP_ID', 'advancedshop');
define('AF_ADVSHOP_BASE', AF_ADDONS . AF_ADVSHOP_ID . '/');
define('AF_ADVSHOP_TPL_DIR', AF_ADVSHOP_BASE . 'templates/');

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
    $routes = ['shop','shop_category','shop_cart','shop_checkout','shop_add_to_cart','shop_update_cart','shop_manage','shop_manage_categories','shop_manage_slots','shop_kb_search','inventory'];
    if (!in_array($action, $routes, true)) { return; }

    if ((int)($mybb->settings['af_advancedshop_enabled'] ?? 1) !== 1 && $action !== 'inventory') {
        error_no_permission();
    }

    if ($action === 'shop_checkout' || $action === 'shop_add_to_cart' || $action === 'shop_update_cart' || $action === 'shop_manage_categories' || $action === 'shop_manage_slots') {
        af_advancedshop_assert_post_key();
    }

    switch ($action) {
        case 'shop':
        case 'shop_category': af_advancedshop_render_shop(); return;
        case 'shop_cart': af_advancedshop_render_cart(); return;
        case 'shop_checkout': af_advancedshop_checkout(); return;
        case 'shop_add_to_cart': af_advancedshop_add_to_cart(); return;
        case 'shop_update_cart': af_advancedshop_update_cart(); return;
        case 'shop_manage': af_advancedshop_render_manage(); return;
        case 'shop_manage_categories': af_advancedshop_manage_categories(); return;
        case 'shop_manage_slots': af_advancedshop_manage_slots(); return;
        case 'shop_kb_search': af_advancedshop_kb_search(); return;
        case 'inventory': af_advancedshop_render_inventory(); return;
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
        af_advancedshop_json(['ok' => false, 'error' => 'POST required']);
    }
    verify_post_check($mybb->get_input('my_post_key'), true);
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
    if ((int)($mybb->user['uid'] ?? 0) <= 0 && (int)($mybb->settings['af_advancedshop_allow_guest_view'] ?? 1) !== 1) {
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
    $qSlots = $db->query("SELECT s.*, e.title_ru, e.title_en, e.short_ru, e.short_en, e.body_ru, e.body_en, e.meta_json, e.data_json
        FROM " . TABLE_PREFIX . "af_shop_slots s
        LEFT JOIN " . TABLE_PREFIX . "af_kb_entries e ON(e.id=s.kb_id)
        WHERE {$where}
        ORDER BY s.sortorder ASC, s.slot_id DESC");

    while ($slot = $db->fetch_array($qSlots)) {
        $slot_id = (int)$slot['slot_id'];
        $slot_price = (int)$slot['price'];
        $kbTitle = af_advancedshop_pick_lang($slot['title_ru'] ?? '', $slot['title_en'] ?? '');
        $slot_title = htmlspecialchars_uni($kbTitle ?: ('#' . (int)$slot['kb_id']));
        $shortText = af_advancedshop_pick_lang($slot['short_ru'] ?? '', $slot['short_en'] ?? '');
        if ($shortText === '') {
            $shortText = mb_substr(strip_tags(af_advancedshop_pick_lang($slot['body_ru'] ?? '', $slot['body_en'] ?? '')), 0, 140);
        }
        $slot_short = htmlspecialchars_uni($shortText);
        $meta = @json_decode((string)($slot['meta_json'] ?? '{}'), true);
        $data = @json_decode((string)($slot['data_json'] ?? '{}'), true);
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
    if ($uid <= 0) { af_advancedshop_json(['ok' => false, 'error' => 'auth']); }
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
    af_advancedshop_json(['ok' => true]);
}

function af_advancedshop_update_cart(): void
{
    global $mybb, $db;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json(['ok' => false, 'error' => 'auth']); }
    $itemId = (int)$mybb->get_input('item_id');
    $qty = (int)$mybb->get_input('qty');
    $item = $db->fetch_array($db->query("SELECT ci.* FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_carts c ON(c.cart_id=ci.cart_id)
        WHERE ci.id={$itemId} AND c.uid={$uid} LIMIT 1"));
    if (!$item) { af_advancedshop_json(['ok' => false, 'error' => 'not_found']); }
    if ($qty <= 0) {
        $db->delete_query('af_shop_cart_items', 'id=' . $itemId);
    } else {
        $db->update_query('af_shop_cart_items', ['qty' => $qty], 'id=' . $itemId);
    }
    af_advancedshop_json(['ok' => true]);
}

function af_advancedshop_checkout(): void
{
    global $mybb, $db, $lang;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) { af_advancedshop_json(['ok' => false, 'error' => 'auth']); }
    $shop = af_advancedshop_current_shop();
    $currency = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], $uid);
    [$items, $total] = af_advancedshop_checkout_collect_items((int)$cart['cart_id']);
    if (!$items || $total <= 0) { af_advancedshop_json(['ok' => false, 'error' => 'empty']); }

    $balance = af_shop_get_balance($uid, $currency);
    if ($balance < $total) {
        af_advancedshop_json(['ok' => false, 'error' => $lang->af_advancedshop_error_not_enough_money ?? 'Not enough money']);
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
        af_advancedshop_json(['ok' => false, 'error' => 'checkout_failed']);
    }

    af_advancedshop_json(['ok' => true, 'message' => $lang->af_advancedshop_success_checkout ?? 'Checkout complete', 'redirect' => 'misc.php?action=shop_cart&shop=' . urlencode((string)$shop['code'])]);
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
    $kb = $db->fetch_array($db->simple_select('af_kb_entries', 'id,data_json,item_kind', 'id=' . $kbId, ['limit' => 1]));
    if (!$kb) { return; }
    $data = @json_decode((string)($kb['data_json'] ?? '{}'), true);
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
    global $lang, $headerinclude, $header, $footer;
    if (!af_advancedshop_can_manage()) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $assets = af_advancedshop_assets_html();
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_manage_categories(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json(['ok' => false, 'error' => 'forbidden']); }
    $shop = af_advancedshop_current_shop();
    $do = (string)$mybb->get_input('do');
    if ($do === 'create') {
        $db->insert_query('af_shop_categories', [
            'shop_id' => (int)$shop['shop_id'],
            'parent_id' => (int)$mybb->get_input('parent_id'),
            'title' => $db->escape_string((string)$mybb->get_input('title')),
            'description' => $db->escape_string((string)$mybb->get_input('description')),
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        ]);
        af_advancedshop_json(['ok' => true]);
    }
    if ($do === 'update') {
        $catId = (int)$mybb->get_input('cat_id');
        $db->update_query('af_shop_categories', [
            'parent_id' => (int)$mybb->get_input('parent_id'),
            'title' => $db->escape_string((string)$mybb->get_input('title')),
            'description' => $db->escape_string((string)$mybb->get_input('description')),
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        ], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
        af_advancedshop_json(['ok' => true]);
    }
    af_advancedshop_json(['ok' => false, 'error' => 'unsupported']);
}

function af_advancedshop_manage_slots(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json(['ok' => false, 'error' => 'forbidden']); }
    $shop = af_advancedshop_current_shop();
    $do = (string)$mybb->get_input('do');

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
        af_advancedshop_json(['ok' => true]);
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
        af_advancedshop_json(['ok' => true]);
    }

    if (strtolower($mybb->request_method) === 'get') {
        $catId = (int)$mybb->get_input('cat');
        $rows = [];
        $q = $db->query("SELECT s.*, e.title_ru, e.title_en FROM " . TABLE_PREFIX . "af_shop_slots s
            LEFT JOIN " . TABLE_PREFIX . "af_kb_entries e ON(e.id=s.kb_id)
            WHERE s.shop_id=" . (int)$shop['shop_id'] . ($catId > 0 ? " AND s.cat_id={$catId}" : '') . " ORDER BY s.sortorder ASC, s.slot_id DESC");
        while ($r = $db->fetch_array($q)) {
            $rows[] = ['slot_id' => (int)$r['slot_id'], 'kb_id' => (int)$r['kb_id'], 'title' => af_advancedshop_pick_lang((string)$r['title_ru'], (string)$r['title_en']), 'price' => (int)$r['price']];
        }
        af_advancedshop_json(['ok' => true, 'rows' => $rows]);
    }

    af_advancedshop_json(['ok' => false, 'error' => 'unsupported']);
}

function af_advancedshop_kb_search(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json(['ok' => false, 'error' => 'forbidden']); }
    $q = trim((string)$mybb->get_input('q'));
    $escaped = $db->escape_string($q);
    $where = "active=1";
    if ($escaped !== '') {
        $where .= " AND (title_ru LIKE '%{$escaped}%' OR title_en LIKE '%{$escaped}%' OR short_ru LIKE '%{$escaped}%')";
    }
    $sql = "SELECT id,title_ru,title_en,short_ru,short_en,meta_json,data_json FROM " . TABLE_PREFIX . "af_kb_entries WHERE {$where} ORDER BY sortorder ASC, id DESC LIMIT 50";
    $res = $db->query($sql);
    $items = [];
    while ($row = $db->fetch_array($res)) {
        $data = @json_decode((string)($row['data_json'] ?? '{}'), true);
        if ((string)($data['type_profile'] ?? '') !== 'item') { continue; }
        $meta = @json_decode((string)($row['meta_json'] ?? '{}'), true);
        $items[] = [
            'kb_id' => (int)$row['id'],
            'title' => af_advancedshop_pick_lang((string)$row['title_ru'], (string)$row['title_en']),
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)($data['item']['rarity'] ?? ($data['rarity'] ?? 'common')),
            'stack_max' => (int)($data['item']['stack_max'] ?? 1),
            'short' => af_advancedshop_pick_lang((string)$row['short_ru'], (string)$row['short_en']),
        ];
    }
    af_advancedshop_json(['ok' => true, 'items' => $items]);
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
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
