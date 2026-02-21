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

function af_advancedshop_kb_schema_meta(): array
{
    global $db;

    static $meta = null;
    if (is_array($meta)) {
        return $meta;
    }

    $kbTableSql = af_advancedshop_kb_table();
    $columns = [];
    if ($db->table_exists('af_kb_entries')) {
        $resCols = $db->query("SHOW COLUMNS FROM {$kbTableSql}");
        while ($row = $db->fetch_array($resCols)) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
    }

    $meta = [
        'kb_table_sql' => $kbTableSql,
        'columns' => $columns,
        'exists' => $db->table_exists('af_kb_entries'),
    ];
    return $meta;
}

function af_advancedshop_kb_cols(): array
{
    return [
        'id' => 'id',
        'type' => '`type`',
        'key' => '`key`',
        'title_ru' => 'title_ru',
        'title_en' => 'title_en',
        'title' => '',
        'short_ru' => 'short_ru',
        'short_en' => 'short_en',
        'short' => '',
        'tech_ru' => 'tech_ru',
        'tech_en' => 'tech_en',
        'tech' => '',
        'body_ru' => 'body_ru',
        'body_en' => 'body_en',
        'body' => '',
        'meta_json' => 'meta_json',
        'data_json' => 'data_json',
        'active' => 'active',
        'sortorder' => 'sortorder',
    ];
}

function af_advancedshop_init(): void
{
    global $plugins;
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_equipped_schema();
    $plugins->add_hook('global_start', 'af_advancedshop_register_routes', 10);
    $plugins->add_hook('misc_start', 'af_advancedshop_misc_router', 10);
    $plugins->add_hook('pre_output_page', 'af_advancedshop_pre_output', 10);
}

function af_advancedshop_ensure_slots_schema(): void
{
    global $db;

    if (!$db->table_exists('af_shop_slots')) {
        return;
    }

    if (!$db->field_exists('kb_type', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN kb_type VARCHAR(32) NOT NULL DEFAULT 'item' AFTER cat_id");
    }
    if (!$db->field_exists('kb_key', 'af_shop_slots')) {
        $db->write_query("ALTER TABLE " . TABLE_PREFIX . "af_shop_slots ADD COLUMN kb_key VARCHAR(128) NOT NULL DEFAULT '' AFTER kb_id");
    }

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? '';
    $kbTypeCol = $kbCols['type'] ?? '';
    $kbKeyCol = $kbCols['key'] ?? '';
    if ($kbIdCol === '' || $kbTypeCol === '' || $kbKeyCol === '') {
        return;
    }

    $safeTypeCol = $kbTypeCol === 'type' ? '`type`' : $kbTypeCol;
    $safeKeyCol = $kbKeyCol === 'key' ? '`key`' : $kbKeyCol;
    $db->write_query(
        "UPDATE " . TABLE_PREFIX . "af_shop_slots s
        INNER JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        SET s.kb_type=COALESCE(NULLIF(e." . $safeTypeCol . ", ''), s.kb_type),
            s.kb_key=COALESCE(NULLIF(e." . $safeKeyCol . ", ''), s.kb_key)
        WHERE s.kb_key='' OR s.kb_type=''
        "
    );
}

function af_advancedshop_ensure_equipped_schema(): void
{
    global $db;

    if ($db->table_exists('af_inventory_equipped')) {
        return;
    }

    $db->write_query("CREATE TABLE " . TABLE_PREFIX . "af_inventory_equipped (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uid INT UNSIGNED NOT NULL,
        slot_code VARCHAR(32) NOT NULL,
        inv_id INT UNSIGNED NOT NULL,
        kb_id INT UNSIGNED NOT NULL,
        equipped_at INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_uid_slot (uid, slot_code),
        KEY uid_idx (uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
            kb_key VARCHAR(128) NOT NULL DEFAULT '',
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
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_equipped_schema();
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
}

function af_advancedshop_activate(): void
{
    global $lang;
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
    af_advancedshop_ensure_slots_schema();
    af_advancedshop_ensure_equipped_schema();
    af_advancedshop_templates_install_or_update();
    if (function_exists('rebuild_settings')) { rebuild_settings(); }
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
    $routes = ['shop','shop_category','shop_cart','shop_checkout','shop_add_to_cart','shop_update_cart','shop_manage','shop_manage_categories','shop_manage_category_create','shop_manage_category_update','shop_manage_category_delete','shop_manage_sortorder_rebuild','shop_manage_slots','shop_manage_slot_create','shop_manage_slot_update','shop_manage_slot_delete','shop_kb_search','shop_kb_schema','shop_health','inventory','inventory_item_info','inventory_equipped_get','inventory_equip','inventory_unequip'];
    if (!in_array($action, $routes, true)) { return; }

    $apiActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slots', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete', 'shop_kb_search', 'shop_kb_schema', 'shop_health', 'inventory_item_info', 'inventory_equipped_get', 'inventory_equip', 'inventory_unequip'];
    $buyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart'];
    $manageActions = ['shop_manage', 'shop_manage_categories', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slots', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete', 'shop_kb_search', 'shop_kb_schema', 'shop_health'];

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

    $postKeyActions = ['shop_checkout', 'shop_add_to_cart', 'shop_update_cart', 'shop_manage_category_create', 'shop_manage_category_update', 'shop_manage_category_delete', 'shop_manage_sortorder_rebuild', 'shop_manage_slot_create', 'shop_manage_slot_update', 'shop_manage_slot_delete', 'inventory_equip', 'inventory_unequip'];
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
            case 'shop_manage_category_update': af_advancedshop_manage_category_update(); return;
            case 'shop_manage_category_delete': af_advancedshop_manage_category_delete(); return;
            case 'shop_manage_sortorder_rebuild': af_advancedshop_manage_sortorder_rebuild(); return;
            case 'shop_manage_slots': af_advancedshop_manage_slots(); return;
            case 'shop_manage_slot_create': af_advancedshop_manage_slot_create(); return;
            case 'shop_manage_slot_update': af_advancedshop_manage_slot_update(); return;
            case 'shop_manage_slot_delete': af_advancedshop_manage_slot_delete(); return;
            case 'shop_kb_search': af_advancedshop_kb_search(); return;
            case 'shop_kb_schema': af_advancedshop_kb_schema(); return;
            case 'shop_health': af_advancedshop_health_ping(); return;
            case 'inventory': af_advancedshop_render_inventory(); return;
            case 'inventory_item_info': af_advancedshop_inventory_item_info(); return;
            case 'inventory_equipped_get': af_advancedshop_inventory_equipped_get(); return;
            case 'inventory_equip': af_advancedshop_inventory_equip(); return;
            case 'inventory_unequip': af_advancedshop_inventory_unequip(); return;
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

function af_advancedshop_inventory_moderator_groups(): array
{
    return [3, 4, 6];
}

function af_advancedshop_can_moderate_inventory(): bool
{
    global $mybb;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) {
        return false;
    }
    return (bool)array_intersect(af_advancedshop_inventory_moderator_groups(), af_advancedshop_user_group_ids());
}

function af_advancedshop_inventory_target_uid(): int
{
    global $mybb;

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if ($viewerUid <= 0) {
        af_advancedshop_json_err('auth', 403);
    }

    $targetUid = (int)$mybb->get_input('uid');
    if ($targetUid <= 0) {
        $targetUid = $viewerUid;
    }

    if ($targetUid !== $viewerUid && !af_advancedshop_can_moderate_inventory()) {
        af_advancedshop_json_err('Not allowed', 403);
    }

    return $targetUid;
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
    add_breadcrumb($lang->af_advancedshop_shop_title ?? 'Shop', 'misc.php?action=shop&shop=' . urlencode((string)$shop['code']));
    $shopId = (int)$shop['shop_id'];
    $catId = (int)$mybb->get_input('cat');

    $flatCats = [];
    $qCats = $db->simple_select('af_shop_categories', '*', 'shop_id=' . $shopId . ' AND enabled=1', ['order_by' => 'sortorder ASC, title ASC, cat_id ASC']);
    while ($cat = $db->fetch_array($qCats)) {
        $flatCats[] = $cat;
    }
    $cats = af_advancedshop_render_shop_categories_tree($flatCats, (string)$shop['code'], $catId);

    $slotsHtml = '';
    $where = 's.shop_id=' . $shopId . ' AND s.enabled=1';
    if ($catId > 0) { $where .= ' AND s.cat_id=' . $catId; }
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $slotHasKbType = $db->field_exists('kb_type', 'af_shop_slots');
    $slotHasKbKey = $db->field_exists('kb_key', 'af_shop_slots');
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
    if (!empty($kbCols['type'])) { $kbSelect[] = 'e.' . ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type'; }
    if (!empty($kbCols['key'])) { $kbSelect[] = 'e.' . ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key'; }
    if ($slotHasKbType) { $kbSelect[] = 's.kb_type AS slot_kb_type'; }
    if ($slotHasKbKey) { $kbSelect[] = 's.kb_key AS slot_kb_key'; }
    $qSlots = $db->query("SELECT s.*, " . implode(', ', $kbSelect) . "
        FROM " . TABLE_PREFIX . "af_shop_slots s
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        WHERE {$where}
        ORDER BY s.sortorder ASC, s.slot_id DESC");

    while ($slot = $db->fetch_array($qSlots)) {
        $slot_id = (int)$slot['slot_id'];
        $slot_price = af_advancedshop_money_format((int)$slot['price']);
        $slot_currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol((string)$slot['currency']));
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
        $profile = af_advancedshop_kb_item_profile($slot);
        $slot_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ($slot['icon_url'] ?? '')));
        $slot['rarity'] = $profile['rarity'];
        $slot_rarity_value = (string)$slot['rarity'];
        $slot_rarity = htmlspecialchars_uni($slot_rarity_value);
        $slot['rarity_label'] = af_advancedshop_rarity_label($slot_rarity_value);
        $slot_rarity_label = htmlspecialchars_uni((string)$slot['rarity_label']);
        $slot['rarity_class'] = 'af-rarity-' . $slot_rarity_value;
        $slot_rarity_class = htmlspecialchars_uni((string)$slot['rarity_class']);
        $slot_kb_id = (int)$slot['kb_id'];
        $slot_kb_type = (string)($slot['slot_kb_type'] ?? ($slot['kb_type'] ?? 'item'));
        if ($slot_kb_type === '') { $slot_kb_type = 'item'; }
        $slot_kb_key = (string)($slot['slot_kb_key'] ?? ($slot['kb_key'] ?? ''));
        $slot_kb_url = htmlspecialchars_uni(af_advancedshop_kb_entry_url($slot_kb_id, $slot_kb_type, $slot_kb_key));
        eval('$slotsHtml .= "' . af_advancedshop_tpl('advancedshop_product_card') . '";');
    }

    $balance = (int)af_shop_get_balance((int)($mybb->user['uid'] ?? 0), (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $currencySlug = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol($currencySlug));
    $balance = af_advancedshop_money_format($balance);
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $shop_title = htmlspecialchars_uni($lang->af_advancedshop_shop_title ?? 'Shop');
    $cart_url = 'misc.php?action=shop_cart&amp;shop=' . urlencode($shop['code']);
    $inventory_link = '';
    if ((int)($mybb->user['uid'] ?? 0) > 0) {
        $inventory_link = '<a class="af-shop-btn" href="misc.php?action=inventory">Инвентарь</a>';
    }
    $balance_badge = '<span class="af-shop-balance">' . htmlspecialchars_uni($lang->af_advancedshop_balance ?? 'Balance') . ': <strong>' . $balance . '</strong> ' . $currency_symbol . '</span>';
    $assets = af_advancedshop_assets_html();
    eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_shop') . '";');
    eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
    output_page($page);
    exit;
}

function af_advancedshop_assets_html(): string
{
    [$cssUrl, $jsUrl] = af_advancedshop_asset_urls();
    return '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '"><script defer src="' . htmlspecialchars_uni($jsUrl) . '"></script>';
}

function af_advancedshop_asset_urls(): array
{
    global $mybb;
    $base = rtrim((string)$mybb->settings['bburl'], '/') . '/inc/plugins/advancedfunctionality/addons/advancedshop/assets';
    $cssPath = AF_ADVSHOP_BASE . 'assets/advancedshop.css';
    $jsPath = AF_ADVSHOP_BASE . 'assets/advancedshop.js';
    $vCss = @file_exists($cssPath) ? (string)@filemtime($cssPath) : '1';
    $vJs = @file_exists($jsPath) ? (string)@filemtime($jsPath) : '1';
    return [$base . '/advancedshop.css?v=' . rawurlencode($vCss), $base . '/advancedshop.js?v=' . rawurlencode($vJs)];
}

function af_advancedshop_pre_output(string &$page = ''): void
{
    global $mybb;
    $action = (string)($mybb->input['action'] ?? '');
    if (!in_array($action, ['shop', 'shop_category', 'shop_cart', 'shop_manage', 'shop_manage_slots', 'inventory'], true)) {
        return;
    }

    [$cssUrl, $jsUrl] = af_advancedshop_asset_urls();
    if (strpos($page, '<!-- af_advancedshop_assets -->') !== false) {
        return;
    }
    $bits = "\n<!-- af_advancedshop_assets -->\n"
        . '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n"
        . '<script defer src="' . htmlspecialchars_uni($jsUrl) . '"></script>' . "\n";
    if (strpos($page, '</head>') !== false) {
        $page = str_replace('</head>', $bits . '</head>', $page);
    } else {
        $page = $bits . $page;
    }
}

function af_advancedshop_render_cart(): void
{
    global $db, $mybb, $lang, $headerinclude, $header, $footer;
    if ((int)($mybb->user['uid'] ?? 0) <= 0) { error_no_permission(); }
    $shop = af_advancedshop_current_shop();
    add_breadcrumb($lang->af_advancedshop_shop_title ?? 'Shop', 'misc.php?action=shop&shop=' . urlencode((string)$shop['code']));
    add_breadcrumb($lang->af_advancedshop_cart_title ?? 'Cart', 'misc.php?action=shop_cart&shop=' . urlencode((string)$shop['code']));
    $cart = af_advancedshop_get_or_create_cart((int)$shop['shop_id'], (int)$mybb->user['uid']);
    [$itemsHtml, $total] = af_advancedshop_build_cart_items($cart);
    $balance = (int)af_shop_get_balance((int)$mybb->user['uid'], (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits'));
    $can_checkout = $balance >= $total ? '' : 'disabled="disabled"';
    $msg = $balance >= $total ? '' : '<div class="af-shop-error">' . htmlspecialchars_uni($lang->af_advancedshop_error_not_enough_money ?? 'Not enough money') . '</div>';
    $assets = af_advancedshop_assets_html();
    $shop_code = htmlspecialchars_uni((string)$shop['code']);
    $shop_url = 'misc.php?action=shop&amp;shop=' . urlencode((string)$shop['code']);
    $currencySlug = (string)($mybb->settings['af_advancedshop_currency_slug'] ?? 'credits');
    $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol($currencySlug));
    $balance = af_advancedshop_money_format($balance);
    $total = af_advancedshop_money_format($total);
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
    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $metaCol = $kbCols['meta_json'] ?? null;

    $select = [
        'ci.*',
        's.price',
        's.currency',
        's.kb_id',
        ($titleRuCol ? 'e.' . $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? 'e.' . $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? 'e.' . $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($metaCol ? 'e.' . $metaCol . ' AS kb_meta' : "'' AS kb_meta"),
    ];

    $q = $db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_shop_cart_items ci
        INNER JOIN " . TABLE_PREFIX . "af_shop_slots s ON(s.slot_id=ci.slot_id)
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
        WHERE ci.cart_id=" . (int)$cart['cart_id'] . " ORDER BY ci.id ASC");
    while ($row = $db->fetch_array($q)) {
        $item_id = (int)$row['id'];
        $slot_id = (int)$row['slot_id'];
        $qty = max(1, (int)$row['qty']);
        $price = (int)$row['price'];
        $sum = $qty * $price;
        $total += $sum;
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
        $item_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ''));
        $item_title_raw = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($item_title_raw === '') { $item_title_raw = (string)($row['kb_title'] ?? ''); }
        $item_title = htmlspecialchars_uni($item_title_raw);
        $price = af_advancedshop_money_format($price);
        $sum = af_advancedshop_money_format($sum);
        $currency_symbol = htmlspecialchars_uni(af_advancedshop_currency_symbol((string)($row['currency'] ?? 'credits')));
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

    $balanceAfter = max(0, (int)$balance - (int)$total);
    af_advancedshop_json_ok([
        'checkout' => [
            'total_minor' => (int)$total,
            'total_major' => af_advancedshop_money_format((int)$total),
            'currency' => $currency,
            'currency_symbol' => af_advancedshop_currency_symbol($currency),
            'order_id' => (int)$orderId,
            'balance_minor' => $balanceAfter,
            'balance_major' => af_advancedshop_money_format($balanceAfter),
        ],
        'links' => [
            'shop' => 'misc.php?action=shop&shop=' . urlencode((string)$shop['code']),
            'inventory' => 'misc.php?action=inventory',
        ],
    ]);
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
    if (!empty($kbCols['data_json'])) { $select[] = $kbCols['data_json'] . ' AS kb_data'; }
    $kb = $db->fetch_array($db->query("SELECT " . implode(',', $select) . " FROM " . af_advancedshop_kb_table() . " WHERE " . $kbIdCol . "=" . $kbId . " LIMIT 1"));
    if (!$kb) { return; }
    $profile = af_advancedshop_kb_item_profile($kb);
    $stackMax = max(1, (int)$profile['stack_max']);
    $rarity = (string)$profile['rarity'];
    $slotCode = (string)$profile['slot'];
    $itemKind = (string)$profile['item_kind'];

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
    add_breadcrumb($lang->af_advancedshop_manage_title ?? 'Manage Shop', 'misc.php?action=shop_manage&shop=' . urlencode((string)$shop['code']));

    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'sortorder ASC, title ASC, cat_id ASC']);
    while ($cat = $db->fetch_array($q)) {
        $flat[] = $cat;
    }
    $ordered = af_advancedshop_category_tree_rows($flat);

    $parentMap = [0 => '—'];
    foreach ($flat as $cat) {
        $parentMap[(int)$cat['cat_id']] = (string)$cat['title'];
    }

    $rows = '';
    foreach ($ordered as $catWrap) {
        $cat = $catWrap['row'];
        $depth = (int)$catWrap['depth'];
        $cat_id = (int)$cat['cat_id'];
        $cat_title = htmlspecialchars_uni((string)$cat['title']);
        $cat_description = htmlspecialchars_uni((string)$cat['description']);
        $cat_parent = (int)$cat['parent_id'];
        $cat_parent_title = htmlspecialchars_uni((string)($parentMap[$cat_parent] ?? '—'));
        $cat_enabled = (int)$cat['enabled'];
        $cat_enabled_checked = $cat_enabled ? 'checked="checked"' : '';
        $cat_sortorder = (int)$cat['sortorder'];
        $slots_url = 'misc.php?action=shop_manage_slots&amp;shop=' . urlencode((string)$shop['code']) . '&amp;cat=' . $cat_id;
        $cat_depth = $depth;
        eval('$rows .= "' . af_advancedshop_tpl('advancedshop_manage_category_row') . '";');
    }

    $assets = af_advancedshop_assets_html();
    $health_block = '<div class="af-shop-health" id="af-shop-health">'
        . '<strong>AF Shop health</strong> '
        . '<span data-health-js>JS loaded: no</span> '
        . '<span data-health-postkey>postKey present: no</span> '
        . '<span data-health-api>API ping: ...</span>'
        . '</div>';
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
    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'sortorder ASC, title ASC, cat_id ASC']);
    while ($r = $db->fetch_array($q)) {
        $flat[] = $r;
    }
    $rows = [];
    foreach (af_advancedshop_category_tree_rows($flat) as $item) {
        $r = $item['row'];
        $rows[] = [
            'cat_id' => (int)$r['cat_id'],
            'title' => (string)$r['title'],
            'description' => (string)$r['description'],
            'parent_id' => (int)$r['parent_id'],
            'enabled' => (int)$r['enabled'],
            'sortorder' => (int)$r['sortorder'],
            'depth' => (int)$item['depth'],
        ];
    }
    af_advancedshop_json_ok(['categories' => $rows]);
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
    if ($parentId <= 0) { $parentId = (int)$mybb->get_input('parent'); }
    if ($parentId <= 0) { $parentId = (int)$mybb->get_input('parentid'); }
    $parentId = max(0, $parentId);
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
            'description' => (string)$mybb->get_input('description'),
            'parent_id' => $parentId,
            'enabled' => 1,
            'sortorder' => $sortorder,
        ],
    ]);
}

function af_advancedshop_manage_category_update(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
    $existing = $db->fetch_array($db->simple_select('af_shop_categories', '*', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$existing) { af_advancedshop_json_err('Category not found', 404); }

    $title = trim((string)$mybb->get_input('title'));
    if ($title === '') { af_advancedshop_json_err('Title required', 422); }
    if (my_strlen($title) > 255) { af_advancedshop_json_err('Title too long', 422); }
    $parentId = max(0, (int)$mybb->get_input('parent_id'));
    if ($parentId <= 0) { $parentId = max(0, (int)$mybb->get_input('parent')); }
    if ($parentId <= 0) { $parentId = max(0, (int)$mybb->get_input('parentid')); }
    if ($parentId === $catId) { af_advancedshop_json_err('Invalid parent category', 422); }

    $db->update_query('af_shop_categories', [
        'parent_id' => $parentId,
        'title' => $db->escape_string($title),
        'description' => $db->escape_string((string)$mybb->get_input('description')),
        'sortorder' => (int)$mybb->get_input('sortorder'),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
    ], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);

    af_advancedshop_json_ok([
        'cat' => [
            'cat_id' => $catId,
            'title' => $title,
            'description' => (string)$mybb->get_input('description'),
            'parent_id' => $parentId,
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        ],
    ]);
}

function af_advancedshop_manage_category_delete(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
    $existing = $db->fetch_array($db->simple_select('af_shop_categories', 'cat_id', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$existing) { af_advancedshop_json_err('Category not found', 404); }

    $hasSlots = (int)$db->fetch_field($db->simple_select('af_shop_slots', 'COUNT(*) AS c', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']), 'c');
    if ($hasSlots > 0) {
        af_advancedshop_json_err('Category not empty', 409);
    }

    $db->delete_query('af_shop_categories', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
    af_advancedshop_json_ok(['deleted' => $catId]);
}

function af_advancedshop_manage_slots(): void
{
    global $mybb, $db, $headerinclude, $header, $footer, $lang;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();
    $catId = (int)$mybb->get_input('cat');
    $do = (string)$mybb->get_input('do');

    if (strtolower($mybb->request_method) === 'get' && $do === '') {
        $category = $db->fetch_array($db->simple_select('af_shop_categories', '*', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
        if (!$category) {
            error_no_permission();
        }
        $shop_code = htmlspecialchars_uni((string)$shop['code']);
        $cat_id = $catId;
        $category_id = $catId;
        $category_title_raw = (string)$category['title'];
        $category_title = htmlspecialchars_uni($category_title_raw);
        add_breadcrumb($lang->af_advancedshop_manage_title ?? 'Manage Shop', 'misc.php?action=shop_manage&shop=' . urlencode((string)$shop['code']));
        add_breadcrumb($lang->af_advancedshop_manage_categories ?? 'Categories', 'misc.php?action=shop_manage&shop=' . urlencode((string)$shop['code']));
        add_breadcrumb($lang->af_advancedshop_manage_slots ?? 'Slots', 'misc.php?action=shop_manage_slots&shop=' . urlencode((string)$shop['code']) . '&cat=' . $catId);
        add_breadcrumb($category_title_raw, 'misc.php?action=shop_manage_slots&shop=' . urlencode((string)$shop['code']) . '&cat=' . $catId);
        $assets = af_advancedshop_assets_html();
        eval('$af_advancedshop_content = "' . af_advancedshop_tpl('advancedshop_manage_slots') . '";');
        eval('$page = "' . af_advancedshop_tpl('advancedshop_fullpage') . '";');
        output_page($page);
        exit;
    }

    if (strtolower($mybb->request_method) === 'get' && ($do === 'list' || $do === '')) {
        if ($catId <= 0) { af_advancedshop_json_err('Category not found', 404); }
        $rows = [];
        $kbCols = af_advancedshop_kb_cols();
        $kbIdCol = $kbCols['id'] ?? 'id';
        $titleSelect = [];
        if (!empty($kbCols['title_ru'])) { $titleSelect[] = 'e.' . $kbCols['title_ru'] . ' AS kb_title_ru'; }
        if (!empty($kbCols['title_en'])) { $titleSelect[] = 'e.' . $kbCols['title_en'] . ' AS kb_title_en'; }
        if (empty($titleSelect) && !empty($kbCols['title'])) { $titleSelect[] = 'e.' . $kbCols['title'] . ' AS kb_title'; }
        if (!empty($kbCols['meta_json'])) { $titleSelect[] = 'e.' . $kbCols['meta_json'] . ' AS kb_meta'; }
        if (!empty($kbCols['type'])) { $titleSelect[] = 'e.' . ($kbCols['type'] === 'type' ? '`type`' : $kbCols['type']) . ' AS kb_type'; }
        if (!empty($kbCols['key'])) { $titleSelect[] = 'e.' . ($kbCols['key'] === 'key' ? '`key`' : $kbCols['key']) . ' AS kb_key'; }
        if ($db->field_exists('kb_type', 'af_shop_slots')) { $titleSelect[] = 's.kb_type AS slot_kb_type'; }
        if ($db->field_exists('kb_key', 'af_shop_slots')) { $titleSelect[] = 's.kb_key AS slot_kb_key'; }
        if (!empty($kbCols['data_json'])) { $titleSelect[] = 'e.' . $kbCols['data_json'] . ' AS kb_data'; }
        if (!$titleSelect) { $titleSelect[] = "'' AS kb_title"; }
        $q = $db->query("SELECT s.*, " . implode(', ', $titleSelect) . " FROM " . TABLE_PREFIX . "af_shop_slots s
            LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=s.kb_id)
            WHERE s.shop_id=" . (int)$shop['shop_id'] . " AND s.cat_id={$catId} ORDER BY s.sortorder ASC, s.slot_id DESC");
        while ($r = $db->fetch_array($q)) {
            $title = af_advancedshop_pick_lang((string)($r['kb_title_ru'] ?? ''), (string)($r['kb_title_en'] ?? ''));
            if ($title === '') { $title = (string)($r['kb_title'] ?? ''); }
            $meta = @json_decode((string)($r['kb_meta'] ?? '{}'), true);
            $profile = af_advancedshop_kb_item_profile($r);
            $rows[] = [
                'slot_id' => (int)$r['slot_id'],
                'kb_id' => (int)$r['kb_id'],
                'kb_type' => (string)($r['slot_kb_type'] ?? ($r['kb_type'] ?? 'item')),
                'kb_key' => (string)($r['slot_kb_key'] ?? ($r['kb_key'] ?? '')),
                'title' => $title,
                'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
                'rarity' => (string)$profile['rarity'],
                'rarity_label' => af_advancedshop_rarity_label((string)$profile['rarity']),
                'rarity_class' => 'af-rarity-' . (string)$profile['rarity'],
                'debug_rarity_raw' => (string)$profile['rarity_raw'],
                'debug_rarity_final' => (string)$profile['rarity'],
                'debug_data_json_present' => (string)$profile['data_json_present'],
                'price' => (int)$r['price'],
                'price_major' => af_advancedshop_money_format((int)$r['price']),
                'cat_id' => (int)$r['cat_id'],
                'currency' => (string)$r['currency'],
                'stock' => (int)$r['stock'],
                'limit_per_user' => (int)$r['limit_per_user'],
                'sortorder' => (int)$r['sortorder'],
                'enabled' => (int)$r['enabled'],
            ];
        }
        af_advancedshop_json_ok(['rows' => $rows]);
    }

    af_advancedshop_json_err('unsupported', 400);
}
function af_advancedshop_manage_slot_create(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $catId = (int)$mybb->get_input('cat_id');
    $kbId = (int)$mybb->get_input('kb_id');
    $kbType = trim((string)$mybb->get_input('kb_type'));
    $kbKey = trim((string)$mybb->get_input('kb_key'));
    if ($kbType === '') { $kbType = 'item'; }
    if ($catId <= 0 || $kbId <= 0) { af_advancedshop_json_err('cat_id and kb_id required', 422); }

    $cat = $db->fetch_array($db->simple_select('af_shop_categories', 'cat_id', 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$cat) { af_advancedshop_json_err('Category not found', 404); }

    $duplicate = $db->fetch_array($db->simple_select('af_shop_slots', 'slot_id', 'shop_id=' . (int)$shop['shop_id'] . ' AND cat_id=' . $catId . ' AND kb_id=' . $kbId, ['limit' => 1]));
    if ($duplicate) { af_advancedshop_json_err('Slot with this KB item already exists in category', 409); }

    // Slots store prices in minor units (e.g. 150 = 1.50).
    $priceMinor = af_advancedshop_money_to_minor((string)$mybb->get_input('price'));
    $slotId = (int)$db->insert_query('af_shop_slots', [
        'shop_id' => (int)$shop['shop_id'],
        'cat_id' => $catId,
        'kb_type' => $db->escape_string($kbType),
        'kb_id' => $kbId,
        'kb_key' => $db->escape_string($kbKey),
        'price' => $priceMinor,
        'currency' => $db->escape_string((string)($mybb->get_input('currency') ?: $mybb->settings['af_advancedshop_currency_slug'])),
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
        'meta_json' => $db->escape_string((string)$mybb->get_input('meta_json')),
    ]);

    af_advancedshop_json_ok(['slot' => [
        'slot_id' => $slotId,
        'cat_id' => $catId,
        'kb_id' => $kbId,
        'kb_type' => $kbType,
        'kb_key' => $kbKey,
        'price' => $priceMinor,
        'price_major' => af_advancedshop_money_format($priceMinor),
        'currency' => (string)($mybb->get_input('currency') ?: $mybb->settings['af_advancedshop_currency_slug']),
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
    ]]);
}

function af_advancedshop_manage_slot_update(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $slotId = (int)$mybb->get_input('slot_id');
    if ($slotId <= 0) { af_advancedshop_json_err('Slot not found', 404); }
    $slot = $db->fetch_array($db->simple_select('af_shop_slots', '*', 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id'], ['limit' => 1]));
    if (!$slot) { af_advancedshop_json_err('Slot not found', 404); }

    $priceMinor = af_advancedshop_money_to_minor((string)$mybb->get_input('price'));
    $update = [
        'price' => $priceMinor,
        'currency' => $db->escape_string((string)($mybb->get_input('currency') ?: $slot['currency'])),
        'stock' => (int)$mybb->get_input('stock', MyBB::INPUT_INT),
        'limit_per_user' => max(0, (int)$mybb->get_input('limit_per_user', MyBB::INPUT_INT)),
        'enabled' => (int)$mybb->get_input('enabled') ? 1 : 0,
        'sortorder' => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
    ];
    $db->update_query('af_shop_slots', $update, 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);

    af_advancedshop_json_ok(['slot' => [
        'slot_id' => $slotId,
        'cat_id' => (int)$slot['cat_id'],
        'kb_id' => (int)$slot['kb_id'],
        'kb_type' => (string)($slot['kb_type'] ?? 'item'),
        'kb_key' => (string)($slot['kb_key'] ?? ''),
        'price' => (int)$update['price'],
        'price_major' => af_advancedshop_money_format((int)$update['price']),
        'currency' => (string)($mybb->get_input('currency') ?: $slot['currency']),
        'stock' => (int)$update['stock'],
        'limit_per_user' => (int)$update['limit_per_user'],
        'enabled' => (int)$update['enabled'],
        'sortorder' => (int)$update['sortorder'],
    ]]);
}

function af_advancedshop_manage_slot_delete(): void
{
    global $mybb, $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $slotId = (int)$mybb->get_input('slot_id');
    if ($slotId <= 0) { af_advancedshop_json_err('Slot not found', 404); }
    $db->delete_query('af_shop_slots', 'slot_id=' . $slotId . ' AND shop_id=' . (int)$shop['shop_id']);
    af_advancedshop_json_ok(['deleted' => $slotId]);
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
    $typeCol = $kbCols['type'] ?? null;
    $keyCol = $kbCols['key'] ?? null;
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
        ($typeCol ? (($typeCol === 'type' ? '`type`' : $typeCol) . ' AS kb_type') : "'item' AS kb_type"),
        ($keyCol ? (($keyCol === 'key' ? '`key`' : $keyCol) . ' AS kb_key') : "'' AS kb_key"),
    ];
    if (!empty($kbCols['data_json'])) { $select[] = $kbCols['data_json'] . ' AS kb_data'; }
    $orderSort = !empty($kbCols['sortorder']) ? $kbCols['sortorder'] . ' ASC, ' : '';
    $sql = "SELECT " . implode(',', $select) . " FROM " . af_advancedshop_kb_table() . " WHERE {$where} ORDER BY {$orderSort}{$kbIdCol} DESC LIMIT 50";
    $res = $db->query($sql);
    $items = [];
    while ($row = $db->fetch_array($res)) {
        $profile = af_advancedshop_kb_item_profile($row);
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
        $title = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($title === '') { $title = (string)($row['kb_title'] ?? ''); }
        $short = af_advancedshop_pick_lang((string)($row['kb_short_ru'] ?? ''), (string)($row['kb_short_en'] ?? ''));
        if ($short === '') { $short = (string)($row['kb_short'] ?? ''); }
        $items[] = [
            'kb_id' => (int)$row['kb_id'],
            'kb_type' => (string)($row['kb_type'] ?? 'item'),
            'kb_key' => (string)($row['kb_key'] ?? ''),
            'title' => $title,
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)$profile['rarity'],
            'stack_max' => (int)$profile['stack_max'],
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
    $canViewAny = af_advancedshop_can_moderate_inventory();
    if ($targetUid !== $viewerUid && !$canViewAny) { error_no_permission(); }

    $rarityFilter = trim((string)$mybb->get_input('rarity'));
    $search = trim((string)$mybb->get_input('q'));

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $bodyRuCol = $kbCols['body_ru'] ?? null;
    $bodyEnCol = $kbCols['body_en'] ?? null;
    $bodyCol = $kbCols['body'] ?? null;
    $shortRuCol = $kbCols['short_ru'] ?? null;
    $shortEnCol = $kbCols['short_en'] ?? null;
    $shortCol = $kbCols['short'] ?? null;
    $techRuCol = $kbCols['tech_ru'] ?? null;
    $techEnCol = $kbCols['tech_en'] ?? null;
    $techCol = $kbCols['tech'] ?? null;
    $dataCol = $kbCols['data_json'] ?? null;
    $metaCol = $kbCols['meta_json'] ?? null;

    $where = 'i.uid=' . $targetUid;
    if ($rarityFilter !== '') { $where .= " AND i.rarity='" . $db->escape_string($rarityFilter) . "'"; }
    if ($search !== '') {
        $s = $db->escape_string($search);
        $searchParts = [];
        foreach (array_filter([$titleRuCol, $titleEnCol, $titleCol]) as $column) {
            $searchParts[] = 'e.' . $column . " LIKE '%{$s}%'";
        }
        if ($searchParts) {
            $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
        }
    }

    $select = [
        'i.*',
        ($titleRuCol ? 'e.' . $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? 'e.' . $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? 'e.' . $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($bodyRuCol ? 'e.' . $bodyRuCol . ' AS kb_body_ru' : "'' AS kb_body_ru"),
        ($bodyEnCol ? 'e.' . $bodyEnCol . ' AS kb_body_en' : "'' AS kb_body_en"),
        ($bodyCol ? 'e.' . $bodyCol . ' AS kb_body' : "'' AS kb_body"),
        ($shortRuCol ? 'e.' . $shortRuCol . ' AS kb_short_ru' : "'' AS kb_short_ru"),
        ($shortEnCol ? 'e.' . $shortEnCol . ' AS kb_short_en' : "'' AS kb_short_en"),
        ($shortCol ? 'e.' . $shortCol . ' AS kb_short' : "'' AS kb_short"),
        ($techRuCol ? 'e.' . $techRuCol . ' AS kb_tech_ru' : "'' AS kb_tech_ru"),
        ($techEnCol ? 'e.' . $techEnCol . ' AS kb_tech_en' : "'' AS kb_tech_en"),
        ($techCol ? 'e.' . $techCol . ' AS kb_tech' : "'' AS kb_tech"),
        ($dataCol ? 'e.' . $dataCol . ' AS kb_data' : "'' AS kb_data"),
        ($metaCol ? 'e.' . $metaCol . ' AS kb_meta' : "'' AS kb_meta"),
    ];

    $tabsOrder = ['weapon', 'armor', 'consumable', 'gear', 'misc'];
    $tabLabels = [
        'weapon' => 'Weapon',
        'armor' => 'Armor',
        'consumable' => 'Consumable',
        'gear' => 'Gear',
        'misc' => 'Misc',
    ];
    $tabsGrid = [];
    $q = $db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_inventory_items i
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=i.kb_id)
        WHERE {$where}
        ORDER BY i.updated_at DESC, i.inv_id DESC");
    while ($row = $db->fetch_array($q)) {
        $inv_id = (int)$row['inv_id'];
        $inv_kb_id = (string)(int)($row['kb_id'] ?? 0);
        $inv_qty = (int)$row['qty'];
        $invTitleRaw = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($invTitleRaw === '') { $invTitleRaw = (string)($row['kb_title'] ?? ''); }
        $inv_title = htmlspecialchars_uni($invTitleRaw);
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
        $inv_icon = htmlspecialchars_uni((string)($meta['ui']['icon_url'] ?? ''));
        $inv_rarity = htmlspecialchars_uni((string)($row['rarity'] ?? 'common'));
        $tooltipSource = af_advancedshop_pick_lang((string)($row['kb_tech_ru'] ?? ''), (string)($row['kb_tech_en'] ?? ''));
        if ($tooltipSource === '') { $tooltipSource = (string)($row['kb_tech'] ?? ''); }
        if ($tooltipSource === '') {
            $tooltipSource = af_advancedshop_pick_lang((string)($row['kb_short_ru'] ?? ''), (string)($row['kb_short_en'] ?? ''));
            if ($tooltipSource === '') { $tooltipSource = (string)($row['kb_short'] ?? ''); }
        }
        $tooltip_body = af_advancedshop_parse_bbcode($tooltipSource);

        $kbData = @json_decode((string)($row['kb_data'] ?? '{}'), true);
        $resolvedKind = '';
        if (is_array($kbData)) {
            $resolvedKind = (string)($kbData['item']['item_kind'] ?? ($kbData['item_kind'] ?? ''));
        }
        if ($resolvedKind === '') { $resolvedKind = (string)($row['item_kind'] ?? ''); }
        $resolvedKind = strtolower(trim($resolvedKind));
        if ($resolvedKind === '') { $resolvedKind = 'misc'; }

        $slotHtml = '';
        eval('$slotHtml = "' . af_advancedshop_tpl('advancedshop_inventory_slot') . '";');
        if (!isset($tabsGrid[$resolvedKind])) {
            $tabsGrid[$resolvedKind] = '';
            if (!isset($tabLabels[$resolvedKind])) {
                $tabLabels[$resolvedKind] = ucfirst($resolvedKind);
            }
            if (!in_array($resolvedKind, $tabsOrder, true)) {
                $tabsOrder[] = $resolvedKind;
            }
        }
        $tabsGrid[$resolvedKind] .= $slotHtml;
    }

    $inventory_tabs = '';
    $inventory_panels = '';
    foreach ($tabsOrder as $kind) {
        $grid = $tabsGrid[$kind] ?? '';
        if ($grid === '') {
            continue;
        }
        $kindEsc = htmlspecialchars_uni($kind);
        $kindLabel = htmlspecialchars_uni((string)($tabLabels[$kind] ?? ucfirst($kind)));
        $inventory_tabs .= '<button type="button" class="af-inventory-tab" data-kind="' . $kindEsc . '">' . $kindLabel . '</button>';
        $inventory_panels .= '<section class="af-inventory-panel" data-kind="' . $kindEsc . '"><div class="af-inventory-grid">' . $grid . '</div></section>';
    }
    if ($inventory_tabs === '') {
        $inventory_tabs = '<button type="button" class="af-inventory-tab is-active" data-kind="misc">Misc</button>';
        $inventory_panels = '<section class="af-inventory-panel is-active" data-kind="misc"><div class="af-inventory-empty">No items</div></section>';
    }

    $equipmentSlotsMeta = [
        'weapon_main' => ['label' => 'Main Hand', 'icon' => '⚔️'],
        'weapon_off' => ['label' => 'Off Hand', 'icon' => '🛡️'],
        'head' => ['label' => 'Head', 'icon' => '⛑️'],
        'body' => ['label' => 'Body', 'icon' => '🦺'],
        'hands' => ['label' => 'Hands', 'icon' => '🧤'],
        'legs' => ['label' => 'Legs', 'icon' => '👖'],
        'feet' => ['label' => 'Feet', 'icon' => '🥾'],
        'back' => ['label' => 'Back', 'icon' => '🎒'],
        'belt' => ['label' => 'Belt', 'icon' => '🧷'],
    ];
    $equipped = af_advancedshop_inventory_equipped_fetch($targetUid);
    $equipment_slots_html = '';
    foreach (af_advancedshop_inventory_slots_canonical() as $slotCode) {
        $slotMeta = $equipmentSlotsMeta[$slotCode] ?? ['label' => ucfirst(str_replace('_', ' ', $slotCode)), 'icon' => '⬜'];
        $entry = $equipped[$slotCode] ?? null;
        $equip_slot_code = htmlspecialchars_uni($slotCode);
        $equip_slot_label = htmlspecialchars_uni((string)($slotMeta['label'] ?? $slotCode));
        $equip_slot_icon = htmlspecialchars_uni((string)($slotMeta['icon'] ?? '⬜'));
        $equip_inv_id = (string)(int)($entry['inv_id'] ?? 0);
        $equip_kb_id = (string)(int)($entry['kb_id'] ?? 0);
        $equip_rarity_raw = (string)($entry['rarity'] ?? 'common');
        $equip_rarity = htmlspecialchars_uni($equip_rarity_raw !== '' ? $equip_rarity_raw : 'common');
        $equip_item_title_raw = trim((string)($entry['title'] ?? ''));
        if ($equip_item_title_raw === '') {
            $equip_item_title_raw = 'Пусто';
        }
        $equip_item_title = htmlspecialchars_uni($equip_item_title_raw);
        $equip_item_title_html = $equip_item_title;
        $equip_item_icon = htmlspecialchars_uni((string)($entry['icon_url'] ?? ''));
        if ($equip_item_icon !== '') {
            $equip_item_icon_html = '<img src="' . $equip_item_icon . '" alt="' . $equip_item_title . '">';
        } else {
            $equip_item_icon_html = '<span class="af-equip-slot__placeholder">Пусто</span>';
        }
        $slotHtml = '';
        eval('$slotHtml = "' . af_advancedshop_tpl('advancedshop_inventory_equipment_slot') . '";');
        $equipment_slots_html .= $slotHtml;
    }

    $assets = af_advancedshop_assets_html();
    $inventory_uid = $targetUid;
    $rarity_common_selected = $rarityFilter === 'common' ? 'selected="selected"' : '';
    $rarity_uncommon_selected = $rarityFilter === 'uncommon' ? 'selected="selected"' : '';
    $rarity_rare_selected = $rarityFilter === 'rare' ? 'selected="selected"' : '';
    $rarity_epic_selected = $rarityFilter === 'epic' ? 'selected="selected"' : '';
    $rarity_legendary_selected = $rarityFilter === 'legendary' ? 'selected="selected"' : '';
    $equipment_panel = '';
    eval('$equipment_panel = "' . af_advancedshop_tpl('advancedshop_inventory_equipment') . '";');
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

function af_advancedshop_inventory_slots_canonical(): array
{
    return ['weapon_main', 'weapon_off', 'head', 'body', 'hands', 'legs', 'feet', 'back', 'belt'];
}

function af_advancedshop_inventory_normalize_slot_code(string $slot): string
{
    return mb_strtolower(trim($slot));
}

function af_advancedshop_inventory_tags_normalized($tags): array
{
    if (!is_array($tags)) {
        return [];
    }

    $result = [];
    foreach ($tags as $key => $value) {
        if (is_int($key)) {
            if (!is_scalar($value)) {
                continue;
            }
            $tag = mb_strtolower(trim((string)$value));
            if ($tag !== '') {
                $result[$tag] = true;
            }
            continue;
        }

        $keyNorm = mb_strtolower(trim((string)$key));
        if ($keyNorm === '') {
            continue;
        }

        if (is_bool($value)) {
            if ($value) {
                $result[$keyNorm] = true;
            }
            continue;
        }

        $valueNorm = mb_strtolower(trim((string)$value));
        if ($valueNorm === '' || $valueNorm === '0' || $valueNorm === 'false' || $valueNorm === 'no') {
            continue;
        }
        $result[$keyNorm] = true;
    }

    return array_keys($result);
}

function af_advancedshop_inventory_resolve_slot(array $profile, string $requestedSlot = ''): array
{
    $requestedSlot = af_advancedshop_inventory_normalize_slot_code($requestedSlot);
    $equippable = af_advancedshop_inventory_equippable_info($profile);
    if (empty($equippable['is_equippable'])) {
        af_advancedshop_json_err('Item is not equippable', 422);
    }

    $allowed = $equippable['allowed_slots'];
    $default = (string)$equippable['default_slot'];
    $slots = af_advancedshop_inventory_slots_canonical();

    if ($requestedSlot !== '') {
        if (!in_array($requestedSlot, $slots, true)) {
            af_advancedshop_json_err('Invalid slot_code', 422);
        }
        if (!in_array($requestedSlot, $allowed, true)) {
            af_advancedshop_json_err('slot_code is not compatible with item', 422);
        }
        $default = $requestedSlot;
    }

    return ['slot_code' => $default, 'allowed_slots' => $allowed];
}

function af_advancedshop_inventory_equippable_info(array $profile): array
{
    $slots = af_advancedshop_inventory_slots_canonical();
    $slot = af_advancedshop_inventory_normalize_slot_code((string)($profile['slot'] ?? ''));
    $tags = af_advancedshop_inventory_tags_normalized($profile['tags'] ?? []);
    $tagsMap = array_fill_keys($tags, true);

    if (in_array($slot, $slots, true)) {
        return ['is_equippable' => true, 'allowed_slots' => [$slot], 'default_slot' => $slot];
    }

    if ($slot === 'armor') {
        $default = 'body';
        if (isset($tagsMap['helmet']) || isset($tagsMap['head'])) {
            $default = 'head';
        } elseif (isset($tagsMap['boots']) || isset($tagsMap['feet'])) {
            $default = 'feet';
        } elseif (isset($tagsMap['gloves']) || isset($tagsMap['hands'])) {
            $default = 'hands';
        } elseif (isset($tagsMap['pants']) || isset($tagsMap['legs'])) {
            $default = 'legs';
        } elseif (isset($tagsMap['back']) || isset($tagsMap['cloak'])) {
            $default = 'back';
        } elseif (isset($tagsMap['belt']) || isset($tagsMap['waist'])) {
            $default = 'belt';
        }
        return ['is_equippable' => true, 'allowed_slots' => [$default], 'default_slot' => $default];
    }

    if ($slot === 'weapon') {
        return ['is_equippable' => true, 'allowed_slots' => ['weapon_main', 'weapon_off'], 'default_slot' => 'weapon_main'];
    }

    return ['is_equippable' => false, 'allowed_slots' => [], 'default_slot' => ''];
}

function af_advancedshop_inventory_equipped_fetch(int $uid): array
{
    global $db;

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $metaCol = $kbCols['meta_json'] ?? null;

    $select = [
        'eq.slot_code',
        'eq.inv_id',
        'eq.kb_id',
        ($titleRuCol ? 'e.' . $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? 'e.' . $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? 'e.' . $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($metaCol ? 'e.' . $metaCol . ' AS kb_meta' : "'' AS kb_meta"),
        'i.rarity AS inv_rarity',
    ];

    $out = [];
    $q = $db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_inventory_equipped eq
        LEFT JOIN " . TABLE_PREFIX . "af_inventory_items i ON(i.inv_id=eq.inv_id AND i.uid=eq.uid)
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=eq.kb_id)
        WHERE eq.uid=" . $uid . "
        ORDER BY eq.id ASC");
    while ($row = $db->fetch_array($q)) {
        $title = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
        if ($title === '') {
            $title = (string)($row['kb_title'] ?? '');
        }
        $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);

        $slotCode = (string)($row['slot_code'] ?? '');
        $out[$slotCode] = [
            'inv_id' => (int)($row['inv_id'] ?? 0),
            'kb_id' => (int)($row['kb_id'] ?? 0),
            'title' => $title,
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)($row['inv_rarity'] ?? 'common'),
            'slot_code' => $slotCode,
        ];
    }

    return $out;
}

function af_advancedshop_inventory_equipped_get(): void
{
    $targetUid = af_advancedshop_inventory_target_uid();
    af_advancedshop_json_ok(['equipped' => af_advancedshop_inventory_equipped_fetch($targetUid)]);
}

function af_advancedshop_inventory_item_info(): void
{
    global $mybb, $db;

    $targetUid = af_advancedshop_inventory_target_uid();
    $invId = (int)$mybb->get_input('inv_id');
    if ($invId <= 0) {
        af_advancedshop_json_err('inv_id required', 422);
    }

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $titleRuCol = $kbCols['title_ru'] ?? null;
    $titleEnCol = $kbCols['title_en'] ?? null;
    $titleCol = $kbCols['title'] ?? null;
    $techRuCol = $kbCols['tech_ru'] ?? null;
    $techEnCol = $kbCols['tech_en'] ?? null;
    $techCol = $kbCols['tech'] ?? null;
    $shortRuCol = $kbCols['short_ru'] ?? null;
    $shortEnCol = $kbCols['short_en'] ?? null;
    $shortCol = $kbCols['short'] ?? null;
    $metaCol = $kbCols['meta_json'] ?? null;
    $dataCol = $kbCols['data_json'] ?? null;

    $select = [
        'i.inv_id',
        'i.kb_id',
        'i.rarity',
        ($titleRuCol ? 'e.' . $titleRuCol . ' AS kb_title_ru' : "'' AS kb_title_ru"),
        ($titleEnCol ? 'e.' . $titleEnCol . ' AS kb_title_en' : "'' AS kb_title_en"),
        ($titleCol ? 'e.' . $titleCol . ' AS kb_title' : "'' AS kb_title"),
        ($techRuCol ? 'e.' . $techRuCol . ' AS kb_tech_ru' : "'' AS kb_tech_ru"),
        ($techEnCol ? 'e.' . $techEnCol . ' AS kb_tech_en' : "'' AS kb_tech_en"),
        ($techCol ? 'e.' . $techCol . ' AS kb_tech' : "'' AS kb_tech"),
        ($shortRuCol ? 'e.' . $shortRuCol . ' AS kb_short_ru' : "'' AS kb_short_ru"),
        ($shortEnCol ? 'e.' . $shortEnCol . ' AS kb_short_en' : "'' AS kb_short_en"),
        ($shortCol ? 'e.' . $shortCol . ' AS kb_short' : "'' AS kb_short"),
        ($metaCol ? 'e.' . $metaCol . ' AS kb_meta' : "'' AS kb_meta"),
        ($dataCol ? 'e.' . $dataCol . ' AS kb_data' : "'' AS kb_data"),
    ];

    $row = $db->fetch_array($db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_inventory_items i
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=i.kb_id)
        WHERE i.inv_id=" . $invId . " AND i.uid=" . $targetUid . " LIMIT 1"));
    if (!$row) {
        af_advancedshop_json_err('Item not found', 404);
    }

    $title = af_advancedshop_pick_lang((string)($row['kb_title_ru'] ?? ''), (string)($row['kb_title_en'] ?? ''));
    if ($title === '') {
        $title = (string)($row['kb_title'] ?? '');
    }
    $meta = @json_decode((string)($row['kb_meta'] ?? '{}'), true);
    $tooltipSource = af_advancedshop_pick_lang((string)($row['kb_tech_ru'] ?? ''), (string)($row['kb_tech_en'] ?? ''));
    if ($tooltipSource === '') { $tooltipSource = (string)($row['kb_tech'] ?? ''); }
    if ($tooltipSource === '') {
        $tooltipSource = af_advancedshop_pick_lang((string)($row['kb_short_ru'] ?? ''), (string)($row['kb_short_en'] ?? ''));
        if ($tooltipSource === '') { $tooltipSource = (string)($row['kb_short'] ?? ''); }
    }

    $profile = af_advancedshop_kb_item_profile($row);
    $equipInfo = af_advancedshop_inventory_equippable_info($profile);

    af_advancedshop_json_ok([
        'item' => [
            'inv_id' => (int)($row['inv_id'] ?? 0),
            'kb_id' => (int)($row['kb_id'] ?? 0),
            'title' => $title,
            'icon_url' => (string)($meta['ui']['icon_url'] ?? ''),
            'rarity' => (string)($row['rarity'] ?? 'common'),
            'description_html' => af_advancedshop_parse_bbcode($tooltipSource),
            'is_equippable' => (bool)($equipInfo['is_equippable'] ?? false),
            'default_slot_code' => (string)($equipInfo['default_slot'] ?? ''),
            'allowed_slots' => array_values($equipInfo['allowed_slots'] ?? []),
        ],
    ]);
}

function af_advancedshop_inventory_equip(): void
{
    global $mybb, $db;

    $targetUid = af_advancedshop_inventory_target_uid();
    $invId = (int)$mybb->get_input('inv_id');
    if ($invId <= 0) {
        af_advancedshop_json_err('inv_id required', 422);
    }

    $kbCols = af_advancedshop_kb_cols();
    $kbIdCol = $kbCols['id'] ?? 'id';
    $dataCol = $kbCols['data_json'] ?? null;
    $select = ['i.*', ($dataCol ? 'e.' . $dataCol . ' AS kb_data' : "'' AS kb_data")];

    $row = $db->fetch_array($db->query("SELECT " . implode(', ', $select) . "
        FROM " . TABLE_PREFIX . "af_inventory_items i
        LEFT JOIN " . af_advancedshop_kb_table() . " e ON(e." . $kbIdCol . "=i.kb_id)
        WHERE i.inv_id=" . $invId . " AND i.uid=" . $targetUid . " LIMIT 1"));
    if (!$row) {
        af_advancedshop_json_err('Item not found', 404);
    }

    $requestedSlot = (string)$mybb->get_input('slot_code');
    $profile = af_advancedshop_kb_item_profile($row);
    $resolved = af_advancedshop_inventory_resolve_slot($profile, $requestedSlot);
    $slotCode = (string)$resolved['slot_code'];

    $db->write_query("INSERT INTO " . TABLE_PREFIX . "af_inventory_equipped (uid, slot_code, inv_id, kb_id, equipped_at)
        VALUES (" . $targetUid . ", '" . $db->escape_string($slotCode) . "', " . (int)$row['inv_id'] . ", " . (int)$row['kb_id'] . ", " . TIME_NOW . ")
        ON DUPLICATE KEY UPDATE inv_id=VALUES(inv_id), kb_id=VALUES(kb_id), equipped_at=VALUES(equipped_at)");

    $equipped = af_advancedshop_inventory_equipped_fetch($targetUid);
    af_advancedshop_json_ok(['slot' => $equipped[$slotCode] ?? null, 'equipped' => $equipped]);
}

function af_advancedshop_inventory_unequip(): void
{
    global $mybb, $db;

    $targetUid = af_advancedshop_inventory_target_uid();
    $slotCode = af_advancedshop_inventory_normalize_slot_code((string)$mybb->get_input('slot_code'));
    if (!in_array($slotCode, af_advancedshop_inventory_slots_canonical(), true)) {
        af_advancedshop_json_err('Invalid slot_code', 422);
    }

    $db->delete_query('af_inventory_equipped', 'uid=' . $targetUid . " AND slot_code='" . $db->escape_string($slotCode) . "'");
    af_advancedshop_json_ok(['slot_code' => $slotCode]);
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

function af_advancedshop_money_scale(): int
{
    global $mybb;
    $scale = (int)($mybb->settings['af_advancedshop_money_scale'] ?? 100);
    return $scale > 0 ? $scale : 100;
}

function af_advancedshop_money_format(int $amountMinor): string
{
    $value = $amountMinor / af_advancedshop_money_scale();
    return number_format($value, 2, '.', '');
}

function af_advancedshop_money_to_minor(string $amount): int
{
    $value = (float)str_replace(',', '.', trim($amount));
    return max(0, (int)round($value * af_advancedshop_money_scale()));
}

function af_advancedshop_currency_symbol(string $slug): string
{
    $slug = trim($slug);
    if ($slug === 'credits') {
        return '¢';
    }
    return $slug;
}


function af_advancedshop_render_shop_categories_tree(array $rows, string $shopCode, int $activeCatId): string
{
    $childrenByParent = [];
    foreach ($rows as $row) {
        $parentId = max(0, (int)($row['parent_id'] ?? 0));
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = $row;
    }

    $rendered = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, $childrenByParent, $shopCode, $activeCatId, &$rendered): string {
        $html = '';
        foreach ($childrenByParent[$parentId] ?? [] as $cat) {
            $rowCatId = (int)$cat['cat_id'];
            $rendered[$rowCatId] = true;
            $hasChildren = !empty($childrenByParent[$rowCatId]);
            $activeClass = $activeCatId === $rowCatId ? 'is-active' : '';
            $cat_url = 'misc.php?action=shop_category&amp;shop=' . urlencode($shopCode) . '&amp;cat=' . $rowCatId;
            $cat_title = htmlspecialchars_uni((string)$cat['title']);
            $cat_depth = $depth;
            $cat_toggle = '<button type="button" class="af-cat-toggle' . ($hasChildren ? '' : ' is-empty') . '" data-cat="' . $rowCatId . '" aria-expanded="true"' . ($hasChildren ? '' : ' aria-hidden="true" tabindex="-1"') . '><span class="af-cat-toggle__icon">' . ($hasChildren ? '▾' : '') . '</span></button>';
            $cat_children = '';
            if ($hasChildren) {
                $childHtml = $walk($rowCatId, $depth + 1);
                $cat_children = '<div class="af-cat-children" data-parent="' . $rowCatId . '">' . $childHtml . '</div>';
            }
            eval('$html .= "' . af_advancedshop_tpl('advancedshop_shop_category') . '";');
        }
        return $html;
    };

    $html = $walk(0, 0);
    foreach ($rows as $row) {
        if (!isset($rendered[(int)$row['cat_id']])) {
            $parentId = max(0, (int)($row['parent_id'] ?? 0));
            $html .= $walk($parentId, 0);
        }
    }
    return $html;
}

function af_advancedshop_category_tree_rows(array $rows): array
{
    $byParent = [];
    foreach ($rows as $row) {
        $parentId = max(0, (int)($row['parent_id'] ?? 0));
        if (!isset($byParent[$parentId])) {
            $byParent[$parentId] = [];
        }
        $byParent[$parentId][] = $row;
    }

    $walk = static function (int $parentId, int $depth) use (&$walk, &$byParent): array {
        $out = [];
        foreach (($byParent[$parentId] ?? []) as $row) {
            $out[] = ['row' => $row, 'depth' => $depth];
            $out = array_merge($out, $walk((int)$row['cat_id'], $depth + 1));
        }
        return $out;
    };

    $out = $walk(0, 0);
    $listed = [];
    foreach ($out as $item) {
        $listed[(int)$item['row']['cat_id']] = true;
    }
    foreach ($rows as $row) {
        $catId = (int)$row['cat_id'];
        if (!isset($listed[$catId])) {
            $out[] = ['row' => $row, 'depth' => 0];
        }
    }

    return $out;
}

function af_advancedshop_manage_sortorder_rebuild(): void
{
    global $db;
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $shop = af_advancedshop_current_shop();

    $flat = [];
    $q = $db->simple_select('af_shop_categories', '*', 'shop_id=' . (int)$shop['shop_id'], ['order_by' => 'parent_id ASC, sortorder ASC, title ASC, cat_id ASC']);
    while ($row = $db->fetch_array($q)) {
        $flat[] = $row;
    }

    $grouped = [];
    foreach ($flat as $row) {
        $grouped[max(0, (int)$row['parent_id'])][] = (int)$row['cat_id'];
    }

    foreach ($grouped as $parentId => $catIds) {
        $pos = 10;
        foreach ($catIds as $catId) {
            $db->update_query('af_shop_categories', ['sortorder' => $pos], 'cat_id=' . $catId . ' AND shop_id=' . (int)$shop['shop_id']);
            $pos += 10;
        }
    }

    af_advancedshop_json_ok(['rebuilt' => true]);
}

function af_advancedshop_kb_schema(): void
{
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    $schema = af_advancedshop_kb_schema_meta();
    $cols = af_advancedshop_kb_cols();

    af_advancedshop_json_ok([
        'kb_table' => (string)($schema['kb_table_sql'] ?? ''),
        'columns' => array_values($schema['columns'] ?? []),
        'picked' => [
            'id' => (string)($cols['id'] ?? ''),
            'type' => (string)($cols['type'] ?? ''),
            'key' => (string)($cols['key'] ?? ''),
            'meta_json' => (string)($cols['meta_json'] ?? ''),
            'data_json' => (string)($cols['data_json'] ?? ''),
        ],
    ]);
}

function af_advancedshop_health_ping(): void
{
    if (!af_advancedshop_can_manage()) { af_advancedshop_json_err('Not allowed', 403); }
    af_advancedshop_json_ok(['ping' => 'ok']);
}

function af_advancedshop_kb_item_profile(array $kbRow): array
{
    $default = [
        'rarity' => 'common',
        'item_kind' => '',
        'slot' => '',
        'stack_max' => 1,
        'currency' => 'credits',
        'price' => 0,
        'tags' => [],
        'rarity_raw' => '',
        'data_json_present' => 'no',
    ];
    $jsonRaw = (string)($kbRow['kb_data'] ?? $kbRow['data_json'] ?? '');
    if (trim($jsonRaw) === '') {
        return $default;
    }

    $data = @json_decode($jsonRaw, true);
    if (!is_array($data)) {
        return $default;
    }

    $default['data_json_present'] = 'yes';
    $item = is_array($data['item'] ?? null) ? $data['item'] : [];
    $rawRarity = '';
    if ($item && !empty($item['rarity'])) {
        $rawRarity = (string)$item['rarity'];
    } elseif (!empty($data['rarity'])) {
        $rawRarity = (string)$data['rarity'];
    }

    $tags = $data['tags'] ?? ($item['tags'] ?? []);
    if (!is_array($tags)) { $tags = []; }

    return [
        'rarity' => af_advancedshop_normalize_rarity($rawRarity),
        'item_kind' => (string)($item['item_kind'] ?? ($data['item_kind'] ?? '')),
        'slot' => (string)($item['slot'] ?? ($data['slot'] ?? '')),
        'stack_max' => max(1, (int)($item['stack_max'] ?? ($data['stack_max'] ?? 1))),
        'currency' => (string)($item['currency'] ?? ($data['currency'] ?? 'credits')),
        'price' => max(0, (int)($item['price'] ?? ($data['price'] ?? 0))),
        'tags' => $tags,
        'rarity_raw' => $rawRarity,
        'data_json_present' => 'yes',
    ];
}

function af_advancedshop_extract_rarity(array $data): string
{
    return af_advancedshop_kb_item_profile(['kb_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])['rarity'];
}

function af_advancedshop_normalize_rarity(string $rarity): string
{
    $value = mb_strtolower(trim($rarity));
    if ($value === '') {
        return 'common';
    }

    $map = [
        'обычная' => 'common',
        'обыкновенная' => 'common',
        'необычная' => 'uncommon',
        'редкая' => 'rare',
        'эпическая' => 'epic',
        'легендарная' => 'legendary',
    ];
    if (isset($map[$value])) {
        return $map[$value];
    }

    if (in_array($value, ['common', 'uncommon', 'rare', 'epic', 'legendary'], true)) {
        return $value;
    }

    return 'common';
}

function af_advancedshop_rarity_label(string $rarity): string
{
    global $lang;
    $normalized = af_advancedshop_normalize_rarity($rarity);
    $key = 'af_advancedshop_rarity_' . $normalized;
    return $lang->{$key} ?? ucfirst($normalized);
}

function af_advancedshop_kb_entry_url(int $kbId, string $type = '', string $entryKey = ''): string
{
    if ($type !== '' && $entryKey !== '') {
        return 'misc.php?action=kb&type=' . urlencode($type) . '&key=' . urlencode($entryKey);
    }
    return 'misc.php?action=kb';
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
