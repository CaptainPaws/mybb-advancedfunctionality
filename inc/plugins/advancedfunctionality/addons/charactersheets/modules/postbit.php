<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_postbit_button(array &$post): void
{
    global $templates, $lang;

    $post['af_cs_plaque'] = '';
    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $slug = af_charactersheets_get_sheet_slug_by_uid($uid);
    if ($slug === '') {
        return;
    }

    if (!isset($lang->af_charactersheets_name)) {
        af_charactersheets_lang();
    }

    $sheet_url = af_charactersheets_url(['slug' => $slug]);
    $button_label = $lang->af_charactersheets_sheet_button ?? 'Лист персонажа';
    $sheet_url = htmlspecialchars_uni($sheet_url);
    $button_label = htmlspecialchars_uni($button_label);
    $sheet_slug = htmlspecialchars_uni($slug);

    $af_balance_stats = '';
    if (function_exists('af_balance_get_postbit_data')) {
        $balance_data = af_balance_get_postbit_data($uid);

        $credits_display = htmlspecialchars_uni((string)($balance_data['credits_display'] ?? '0.00'));
        $currency_symbol = htmlspecialchars_uni((string)($balance_data['currency_symbol'] ?? '¢'));
        $level = (int)($balance_data['level'] ?? 1);
        $progress_percent = max(0, min(100, (int)($balance_data['progress_percent'] ?? 0)));
        $exp_display = htmlspecialchars_uni((string)($balance_data['exp_display'] ?? '0'));
        $exp_need_display = htmlspecialchars_uni((string)($balance_data['exp_need_display'] ?? '0'));

        $tpl_balance = $templates->get('af_balance_postbit');
        if ($tpl_balance === '') {
            $tpl_balance = $templates->get('af_balance_postbit_plaque');
        }
        eval("\$af_balance_stats = \"" . $tpl_balance . "\";");
    }

    $tpl = $templates->get('af_charactersheets_postbit_plaque');
    eval("\$plaque_html = \"" . $tpl . "\";");

    $post['af_cs_plaque'] = $plaque_html;

    if (!af_cs_assets_disabled_for_current_page()) {
        $GLOBALS['af_charactersheets_needs_assets'] = true;
        $GLOBALS['af_charactersheets_needs_modal'] = true;
    }
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
