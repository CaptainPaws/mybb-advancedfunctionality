<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_postbit_button(array &$post): void
{
    global $templates, $lang;

    $post['af_cs_plaque'] = '';
    $post['af_balance_inline_stats'] = '';
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
    $af_balance_inline_stats = '';
    if (function_exists('af_balance_get_postbit_data')) {
        $balance_data = af_balance_get_postbit_data($uid);

        $credits_display = htmlspecialchars_uni((string)($balance_data['credits_display'] ?? '0.00'));
        $currency_symbol = htmlspecialchars_uni((string)($balance_data['currency_symbol'] ?? '¢'));
        $level = (int)($balance_data['level'] ?? 1);
        $ability_tokens_scaled = (int)($balance_data['ability_tokens_scaled'] ?? 0);
        $ability_tokens_display = htmlspecialchars_uni((string)($balance_data['ability_tokens_display'] ?? '0.00'));
        $ability_tokens_symbol = htmlspecialchars_uni((string)($balance_data['ability_tokens_symbol'] ?? '♦'));
        $progress_percent = max(0, min(100, (int)($balance_data['progress_percent'] ?? 0)));
        $exp_display = htmlspecialchars_uni((string)($balance_data['exp_display'] ?? '0'));
        $exp_need_display = htmlspecialchars_uni((string)($balance_data['exp_need_display'] ?? '0'));

        $labels = function_exists('af_balance_default_labels') ? af_balance_default_labels() : [
            'credits' => 'Кредиты',
            'ability_tokens' => 'Токены',
            'level' => 'Уровень',
            'exp' => 'Опыт',
        ];

        $label_credits = (string)($labels['credits'] ?? 'Кредиты');
        $label_ability_tokens = (string)($labels['ability_tokens'] ?? 'Токены');
        $label_level = (string)($labels['level'] ?? 'Уровень');
        $label_exp = (string)($labels['exp'] ?? 'Опыт');

        $postbit_label_credits_html = function_exists('af_balance_resolve_postbit_label_html')
            ? af_balance_resolve_postbit_label_html($label_credits, (string)($GLOBALS['mybb']->settings['af_balance_postbit_icon_credits_html'] ?? ''), 300)
            : htmlspecialchars_uni($label_credits);
        $postbit_label_level_html = function_exists('af_balance_resolve_postbit_label_html')
            ? af_balance_resolve_postbit_label_html($label_level, (string)($GLOBALS['mybb']->settings['af_balance_postbit_icon_level_html'] ?? ''), 300)
            : htmlspecialchars_uni($label_level);
        $postbit_label_exp_html = function_exists('af_balance_resolve_postbit_label_html')
            ? af_balance_resolve_postbit_label_html($label_exp, (string)($GLOBALS['mybb']->settings['af_balance_postbit_icon_exp_html'] ?? ''), 300)
            : htmlspecialchars_uni($label_exp);
        $postbit_label_ability_tokens_html = function_exists('af_balance_resolve_postbit_label_html')
            ? af_balance_resolve_postbit_label_html($label_ability_tokens, (string)($GLOBALS['mybb']->settings['af_balance_postbit_icon_ability_tokens_html'] ?? ''), 300)
            : htmlspecialchars_uni($label_ability_tokens);

        $ability_tokens_postbit_html = '';

        $isQuickReplyContext = (defined('THIS_SCRIPT') && strtolower((string)THIS_SCRIPT) === 'newreply.php') || defined('IN_XMLHTTP');
        $af_apc_postbit_html = $isQuickReplyContext ? '' : '<af_apc_uid_' . $uid . '>';

        $tpl_balance = $templates->get('af_balance_postbit');
        if ($tpl_balance === '') {
            $tpl_balance = $templates->get('af_balance_postbit_plaque');
        }
        eval("\$af_balance_stats = \"" . $tpl_balance . "\";");

        $tpl_balance_inline = <<<'HTML'
<span class="af-apui-stat-chip af-apui-stat-chip--credits" data-af-balance-credits="1" data-pid="{$post['pid']}" data-uid="{$post['uid']}"><span class="af-apui-stat-chip__icon">{$postbit_label_credits_html}</span><span class="af-apui-stat-chip__value" data-af-balance-credits-value="1">{$credits_display} {$currency_symbol}</span></span>{$ability_tokens_postbit_html}<span class="af-apui-stat-chip af-apui-stat-chip--level" data-af-balance-level="1" data-pid="{$post['pid']}" data-uid="{$post['uid']}"><span class="af-apui-stat-chip__icon">{$postbit_label_level_html}</span><span class="af-apui-stat-chip__value" data-af-balance-level-value="1">{$level}</span></span><span class="af-apui-stat-chip af-apui-stat-chip--posts" data-af-balance-posts="1" data-pid="{$post['pid']}" data-uid="{$post['uid']}"><span class="af-apui-stat-chip__icon"><i class="fa-solid fa-pen" aria-hidden="true"></i></span><span class="af-apui-stat-chip__value"><span class="af-apc-slot" data-af-apc-slot="1" data-uid="{$post['uid']}">{$af_apc_postbit_html}</span></span></span>
HTML;

        if (!empty($balance_data['ability_tokens_show_postbit'])) {
            $ability_tokens_postbit_html = '<span class="af-apui-stat-chip af-apui-stat-chip--tokens" data-af-balance-ability="1" data-af-balance-ability-scaled="' . $ability_tokens_scaled . '" data-pid="' . (int)$post['pid'] . '" data-uid="' . $uid . '"><span class="af-apui-stat-chip__icon">' . $postbit_label_ability_tokens_html . '</span><span class="af-apui-stat-chip__value" data-af-balance-ability-value="1">' . $ability_tokens_display . ' ' . $ability_tokens_symbol . '</span></span>';
            $tpl_balance_inline = str_replace('{$ability_tokens_postbit_html}', $ability_tokens_postbit_html, $tpl_balance_inline);
        } else {
            $tpl_balance_inline = str_replace('{$ability_tokens_postbit_html}', '', $tpl_balance_inline);
        }

        eval("\$af_balance_inline_stats = \"" . $tpl_balance_inline . "\";");
    }

    $post['af_balance_inline_stats'] = $af_balance_inline_stats;

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
