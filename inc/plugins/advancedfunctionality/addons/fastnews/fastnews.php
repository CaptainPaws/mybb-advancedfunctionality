<?php
/**
 * Аддон "Быстрые новости"
 * Языки аддона генерируются ядром по manifest.php['lang'].
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

/* ================= УСТАНОВКА ================= */

function af_fastnews_install()
{
    global $lang;

    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('fastnews');
    }

    $gid = af_fastnews_ensure_group(
        $lang->af_fastnews_group ?? 'AF: Быстрые новости',
        $lang->af_fastnews_group_desc ?? 'Настройки внутреннего аддона «Быстрые новости».'
    );

    af_fastnews_ensure_setting(
        'af_fastnews_enabled',
        $lang->af_fastnews_enabled ?? 'Включить блок',
        $lang->af_fastnews_enabled_desc ?? 'Да/Нет',
        'yesno', '1', 1, $gid
    );
    af_fastnews_ensure_setting(
        'af_fastnews_html',
        $lang->af_fastnews_html ?? 'Содержимое блока',
        $lang->af_fastnews_html_desc ?? 'Можно HTML/BBCode (если разрешено на форуме).',
        'textarea', '<strong>Новости:</strong> Добро пожаловать!', 2, $gid
    );
    af_fastnews_ensure_setting(
        'af_fastnews_visible_for',
        $lang->af_fastnews_visible_for ?? 'ID групп, через запятую',
        $lang->af_fastnews_visible_for_desc ?? 'Пусто — показывать всем.',
        'text', '', 3, $gid
    );

    rebuild_settings();
}

function af_fastnews_ensure_group($title, $desc)
{
    global $db;
    $q = $db->simple_select('settinggroups','gid',"name='af_fastnews'", ['limit'=>1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) return $gid;

    $max = $db->fetch_field($db->simple_select('settinggroups','MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $db->insert_query('settinggroups', [
        'name'        => 'af_fastnews',
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder'   => $disp,
        'isdefault'   => 0
    ]);
    return (int)$db->insert_id();
}

function af_fastnews_ensure_setting($name, $title, $desc, $type, $value, $order, $gid)
{
    global $db;
    $q = $db->simple_select('settings','sid',"name='".$db->escape_string($name)."'");
    $sid = (int)$db->fetch_field($q, 'sid');

    $row = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => (int)$order,
        'gid'         => (int)$gid
    ];

    if ($sid) {
        $db->update_query('settings', $row, "sid=".$sid);
    } else {
        $db->insert_query('settings', $row);
    }
}

/* ================= ВСПОМОГАТЕЛЬНОЕ ================= */

function af_fastnews_is_frontend()
{
    // Не вмешиваемся в ACP/ModCP
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false) return false;
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'modcp.php') !== false) return false;
    return true;
}

function af_fastnews_parse_message($raw)
{
    if (!class_exists('postParser')) {
        require_once MYBB_ROOT.'inc/class_parser.php';
    }
    $parser = new postParser();
    $opts = [
        'allow_html'         => 0,
        'allow_mycode'       => 1,
        'allow_basicmycode'  => 1,
        'allow_smilies'      => 1,
        'allow_imgcode'      => 1,
        'allow_videocode'    => 1,
        'allow_list'         => 1,
        'allow_alignmycode'  => 1,
        'allow_font'         => 1,
        'allow_color'        => 1,
        'allow_size'         => 1,
        'filter_badwords'    => 1,
        'nl2br'              => 1,
    ];
    // простая эвристика на BBCode
    if (strpos($raw, '[') !== false && strpos($raw, ']') !== false) {
        return $parser->parse_message($raw, $opts);
    }
    return $raw;
}

/* ============== pre_output_page (совместимо с MyBB и твоим хабом) ============== */
/**
 * Сигнатура: аргумент ПО ССЫЛКЕ, без type-hint’ов, с безопасным default.
 * Возвращаем $page на случай, если внешний обёртчик ждёт значение.
 */
function af_fastnews_pre_output(&$page = '')
{
    global $mybb;

    if ($page === null) { $page = ''; }
    if (!is_string($page)) { $page = (string)$page; }
    if ($page === '')   { return $page; }

    if (!af_fastnews_is_frontend()) { return $page; }

    if (strpos($page, '<!--af_fastnews-->') !== false) {
        return $page;
    }

    if (empty($mybb->settings['af_fastnews_enabled'])) {
        return $page;
    }

    // Проверка групп
    $list = trim((string)($mybb->settings['af_fastnews_visible_for'] ?? ''));
    if ($list !== '') {
        $allowed = array_filter(array_map('intval', explode(',', $list)));
        $ug      = (int)($mybb->user['usergroup'] ?? 1);
        $extra   = array_filter(array_map('intval', explode(',', (string)($mybb->user['additionalgroups'] ?? ''))));
        $user_groups = array_unique(array_merge([$ug], $extra));
        if (!array_intersect($user_groups, $allowed)) {
            return $page;
        }
    }

    // Контент
    $raw  = (string)($mybb->settings['af_fastnews_html'] ?? '');
    if ($raw === '') {
        return $page;
    }
    $html = af_fastnews_parse_message($raw);

    $block = "<!--af_fastnews-->\n<div class=\"af_fastnews\">{$html}</div>\n";

    // 1) перед #content
    $needle = '<div id="content">';
    $pos = stripos($page, $needle);
    if ($pos !== false) {
        $page = substr($page, 0, $pos) . $block . substr($page, $pos);
        return $page;
    }

    // 2) внутри #content перед .wrapper
    $patched = preg_replace('~(<div[^>]*id="content"[^>]*>)(\s*<div[^>]*class="[^"]*\bwrapper\b[^"]*"[^>]*>)~i', "$1\n{$block}$2", $page, 1);
    if ($patched !== null && $patched !== $page) {
        $page = $patched;
        return $page;
    }

    // 3) фолбэк — сразу после <body>
    $page = preg_replace('~<body([^>]*)>~i', '<body$1>'."\n".$block, $page, 1);

    return $page;
}

function af_fastnews_init() { /* no-op */ }
