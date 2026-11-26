<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Activation checks installed storage and pending legacy work without changing either. */
function af_advinv_activation_blocker(): ?string
{
    global $db;

    if (af_advinv_reservation_writers_enabled()) {
        require_once __DIR__.'/OwnerSchema.php';
        $plan = AF_ADVINV_OwnerSchema::plan(new AF_ADVINV_OwnerProtocol($db));
        if ($plan['create'] || $plan['blocked']) return 'INV_SCHEMA_NOT_READY';
    }

    $required = [
        AF_ADVINV_TABLE_ITEMS => ['id','uid','entity','slot','subtype','kb_type','kb_key','title','icon','qty','meta_json','created_at','updated_at'],
        'af_advinv_equipped' => ['uid','equip_slot','item_id','updated_at'],
        'af_advinv_support_slots' => ['uid','slot_code','item_id','sortorder','created_at','updated_at'],
        AF_ADVINV_TABLE_ENTITIES => ['entity','title_ru','title_en','enabled','sortorder','renderer','settings_json','updated_at'],
        AF_ADVINV_TABLE_ENTITY_FILTERS => ['id','entity','code','title_ru','title_en','sortorder','enabled','match_json','updated_at'],
        AF_ADVINV_TABLE_SHOP_MAP => ['id','shop_code','shop_cat_id','inventory_entity','default_subtype','mode','enabled','sortorder','updated_at'],
    ];
    foreach ($required as $table => $fields) {
        if (!$db->table_exists($table)) return 'INV_STORAGE_UPGRADE_REQUIRED';
        foreach ($fields as $field) {
            if (!$db->field_exists($field, $table)) return 'INV_STORAGE_UPGRADE_REQUIRED';
        }
    }

    $entities = af_advinv_entities_sql_list();
    if ($db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ITEMS, 'id',
        "entity IS NULL OR entity='' OR entity NOT IN ({$entities})", ['limit'=>1]))) {
        return 'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL';
    }
    $items = $db->simple_select(AF_ADVINV_TABLE_ITEMS, 'subtype,meta_json', "entity='customization'");
    while ($item = $db->fetch_array($items)) {
        if (af_advinv_customization_migrated_subtype($item) !== (string)$item['subtype']) {
            return 'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL';
        }
    }
    $legacyCodes = array_keys(af_advinv_customization_legacy_subtype_map());
    $legacyCodesSql = "'".implode("','", array_map([$db,'escape_string'],$legacyCodes))."'";
    if ($db->fetch_array($db->simple_select(AF_ADVINV_TABLE_ENTITY_FILTERS, 'id',
        "entity='customization' AND code IN ({$legacyCodesSql})", ['limit'=>1]))) {
        return 'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL';
    }

    $legacyMappings = [
        'shop_id'=> "shop_code='' AND shop_id>0",
        'cat_id'=> 'shop_cat_id=0 AND cat_id>0',
        'entity'=> "(inventory_entity='' OR inventory_entity='resources') AND entity<>'' AND inventory_entity<>entity",
    ];
    foreach ($legacyMappings as $field => $pending) {
        if ($db->field_exists($field, AF_ADVINV_TABLE_SHOP_MAP)
            && $db->fetch_array($db->simple_select(AF_ADVINV_TABLE_SHOP_MAP, 'id', $pending, ['limit'=>1]))) {
            return 'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL';
        }
    }

    $imported = (string)$db->fetch_field($db->simple_select('datacache', 'cache',
        "title='af_advancedinventory_migrated'", ['limit'=>1]), 'cache');
    if ($imported === '1') return null;

    foreach (['af_inventory_items','af_shop_inventory_legacy'] as $table) {
        if ($db->table_exists($table) && ($table !== 'af_inventory_items' || $db->field_exists('inv_id', $table))
            && $db->fetch_array($db->simple_select($table, 'uid', '', ['limit'=>1]))) {
            return 'INV_LEGACY_IMPORT_REVIEW_REQUIRED';
        }
    }
    if ($db->table_exists('af_shop_orders')) {
        // Checkout-key orders grant their items in the same transaction as the order.
        $legacyOnly = $db->field_exists('checkout_key', 'af_shop_orders') ? " AND (checkout_key IS NULL OR checkout_key='')" : '';
        $orders = $db->simple_select('af_shop_orders', 'items_json', "uid>0 AND status IN ('paid','completed','issued')".$legacyOnly);
        while ($order = $db->fetch_array($orders)) {
            $items = json_decode((string)$order['items_json'], true);
            if (is_array($items) && array_filter($items, 'is_array')) return 'INV_LEGACY_IMPORT_REVIEW_REQUIRED';
        }
    }
    return null;
}
