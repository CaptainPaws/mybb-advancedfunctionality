<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_postbit_button(array &$post): void
{
    global $mybb, $templates, $lang;

    $post['af_cs_plaque'] = '';
    $post['postbit_balance_html'] = '';

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

    $sheet_url = 'misc.php?action=af_charactersheet&slug=' . rawurlencode($slug);
    $button_label = $lang->af_charactersheets_sheet_button ?? 'Лист персонажа';
    $sheet_url = htmlspecialchars_uni($sheet_url);
    $button_label = htmlspecialchars_uni($button_label);
    $sheet_slug = htmlspecialchars_uni($slug);

    af_charactersheets_postbit_preload_balances();
    $bal = af_charactersheets_postbit_get_balance($uid);
    $exp_total = ((float)($bal['exp'] ?? 0)) / (defined('AF_BALANCE_EXP_SCALE') ? AF_BALANCE_EXP_SCALE : 100);
    $level_data = af_charactersheets_compute_level($exp_total);
    $level = (int)($level_data['level'] ?? 1);
    $exp_current = htmlspecialchars_uni(number_format((float)($level_data['exp_in_level'] ?? 0), 2, '.', ' '));
    $exp_need = htmlspecialchars_uni(number_format((float)($level_data['exp_need'] ?? 0), 2, '.', ' '));
    $progress_percent = max(0, min(100, (int)($level_data['percent'] ?? 0)));
    $currency_symbol = htmlspecialchars_uni((string)($mybb->settings['af_balance_currency_symbol'] ?? '¢'));
    $credits_raw = (int)($bal['credits'] ?? 0);
    $credits = htmlspecialchars_uni(function_exists('af_balance_format_credits') ? af_balance_format_credits($credits_raw) : number_format($credits_raw / 100, 2, '.', ' '));

    $tpl = $templates->get('postbit_plaque');
    eval("\$plaque_html = \"" . $tpl . "\";");

    $post['af_cs_plaque'] = $plaque_html;

    if (!af_cs_assets_disabled_for_current_page()) {
        $GLOBALS['af_charactersheets_needs_assets'] = true;
        $GLOBALS['af_charactersheets_needs_modal'] = true;
    }
}

function af_charactersheets_postbit_preload_balances(): void
{
    static $done = false;
    if ($done || !function_exists('af_balance_get')) {
        return;
    }

    global $db;
    $uids = [];
    if (!empty($GLOBALS['posts']) && is_array($GLOBALS['posts'])) {
        foreach ($GLOBALS['posts'] as $p) {
            $u = (int)($p['uid'] ?? 0);
            if ($u > 0) $uids[$u] = $u;
        }
    }
    if (!$uids) {
        $done = true;
        return;
    }

    $list = implode(',', array_map('intval', array_values($uids)));
    $cache = [];
    if ($db->table_exists('af_balance')) {
        $q = $db->simple_select('af_balance', 'uid,exp,credits', 'uid IN ('.$list.')');
        while ($row = $db->fetch_array($q)) {
            $cache[(int)$row['uid']] = ['uid'=>(int)$row['uid'],'exp'=>(int)$row['exp'],'credits'=>(int)$row['credits']];
        }
    }
    foreach ($uids as $uid) {
        if (!isset($cache[$uid])) {
            $cache[$uid] = af_balance_get($uid);
        }
    }

    $GLOBALS['af_cs_postbit_balance_cache'] = $cache;
    $done = true;
}

function af_charactersheets_postbit_get_balance(int $uid): array
{
    $cache = (array)($GLOBALS['af_cs_postbit_balance_cache'] ?? []);
    if (isset($cache[$uid])) return $cache[$uid];
    return function_exists('af_balance_get') ? af_balance_get($uid) : ['uid'=>$uid,'exp'=>0,'credits'=>0];
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
