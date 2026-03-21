<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_cs_get_postbit_sheet_payload(int $uid): array
{
    global $lang;

    $payload = [
        'enabled' => false,
        'sheet_url' => '',
        'sheet_slug' => '',
        'button_label' => '',
    ];

    if (!af_charactersheets_is_enabled() || $uid <= 0) {
        return $payload;
    }

    $slug = af_charactersheets_get_sheet_slug_by_uid($uid);
    if ($slug === '') {
        return $payload;
    }

    if (!isset($lang->af_charactersheets_name)) {
        af_charactersheets_lang();
    }

    $payload['enabled'] = true;
    $payload['sheet_url'] = af_charactersheets_url(['slug' => $slug]);
    $payload['sheet_slug'] = $slug;
    $payload['button_label'] = (string)($lang->af_charactersheets_sheet_button ?? 'Лист персонажа');

    if (!af_cs_assets_disabled_for_current_page()) {
        $GLOBALS['af_charactersheets_needs_assets'] = true;
        $GLOBALS['af_charactersheets_needs_modal'] = true;
    }

    return $payload;
}

function af_charactersheets_get_sheet_slug_by_uid(int $uid): string
{
    static $cache = [];
    if ($uid <= 0) {
        return '';
    }
    if (array_key_exists($uid, $cache)) {
        return (string)$cache[$uid];
    }

    global $db;
    $slug = '';
    if ($db->table_exists(AF_CS_SHEETS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_SHEETS_TABLE,
            'slug',
            "uid=" . (int)$uid . " AND slug<>''",
            ['order_by' => 'id', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['slug'] ?? '') : '';
    }

    if ($slug === '' && $db->table_exists(AF_CS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_TABLE,
            'sheet_slug',
            "uid=" . (int)$uid . " AND sheet_created=1 AND sheet_slug<>''",
            ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['sheet_slug'] ?? '') : '';
    }

    if ($slug === '' && $db->table_exists(AF_CS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(
            AF_CS_TABLE,
            'sheet_slug',
            "uid=" . (int)$uid . " AND sheet_slug<>''",
            ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
        ));
        $slug = is_array($row) ? (string)($row['sheet_slug'] ?? '') : '';
    }

    $cache[$uid] = $slug;
    return $slug;
}
