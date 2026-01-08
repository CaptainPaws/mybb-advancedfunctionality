<?php
/**
 * Аддон "Быстрые новости"
 * Вставка в шаблоны: {$fastnews}
 * Контент хранится в datacache (НЕ в settings.php).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

/**
 * Ключ кэша (datacache)
 * Храним массив: ['content' => '...', 'updated' => TIME_NOW]
 */
define('AF_FASTNEWS_CACHE_KEY', 'af_fastnews');

/* ================= УСТАНОВКА / УДАЛЕНИЕ ================= */
function af_fastnews_install()
{
    global $lang;

    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang('fastnews');
    }

    // Настройки (только служебные, НЕ контент)
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
        'af_fastnews_visible_for',
        $lang->af_fastnews_visible_for ?? 'ID групп, через запятую',
        $lang->af_fastnews_visible_for_desc ?? 'Пусто — показывать всем.',
        'text', '', 2, $gid
    );

    // Контент — в datacache (НЕ settings.php)
    // Важно: по умолчанию используем MyCode, а не HTML, чтобы работало даже при выключенном allowhtml
    af_fastnews_cache_write('[b]Новости:[/b] Добро пожаловать!');

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}


function af_fastnews_uninstall()
{
    global $db;

    // Удаляем settings группы/настроек (только служебные, без контента)
    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_fastnews'", ['limit' => 1]),
        'gid'
    );

    if ($gid) {
        $db->delete_query('settings', "gid={$gid}");
        $db->delete_query('settinggroups', "gid={$gid}");
    }

    // Удаляем datacache
    af_fastnews_cache_delete();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_fastnews_is_installed()
{
    global $db;
    $q = $db->simple_select('settinggroups', 'gid', "name='af_fastnews'", ['limit' => 1]);
    return (int)$db->fetch_field($q, 'gid') > 0;
}

function af_fastnews_activate() { /* no-op */ }
function af_fastnews_deactivate() { /* no-op */ }

function af_fastnews_ensure_group($title, $desc)
{
    global $db;
    $q = $db->simple_select('settinggroups','gid',"name='af_fastnews'", ['limit'=>1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) return $gid;

    $max  = $db->fetch_field($db->simple_select('settinggroups','MAX(disporder) AS m'), 'm');
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
    $q = $db->simple_select('settings','sid',"name='".$db->escape_string($name)."'", ['limit'=>1]);
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

/**
 * Читаем raw-контент из datacache
 */
function af_fastnews_get_raw()
{
    $data = af_fastnews_cache_read();
    $raw  = '';

    if (is_array($data) && isset($data['content'])) {
        $raw = (string)$data['content'];
    } elseif (is_string($data)) {
        // на случай старого формата
        $raw = $data;
    }

    return $raw;
}

/**
 * Сохраняем raw-контент в datacache
 */
function af_fastnews_set_raw($raw)
{
    $raw = (string)$raw;
    af_fastnews_cache_write($raw);
}

/**
 * datacache helpers
 */
function af_fastnews_cache_read()
{
    global $cache;

    if (!is_object($cache)) {
        require_once MYBB_ROOT.'inc/class_cache.php';
        $cache = new datacache;
        $cache->cache();
    }

    return $cache->read(AF_FASTNEWS_CACHE_KEY);
}

function af_fastnews_cache_write($content)
{
    global $cache;

    if (!is_object($cache)) {
        require_once MYBB_ROOT.'inc/class_cache.php';
        $cache = new datacache;
        $cache->cache();
    }

    $cache->update(AF_FASTNEWS_CACHE_KEY, [
        'content' => (string)$content,
        'updated' => TIME_NOW,
    ]);
}

function af_fastnews_cache_delete()
{
    global $cache;

    if (!is_object($cache)) {
        require_once MYBB_ROOT.'inc/class_cache.php';
        $cache = new datacache;
        $cache->cache();
    }

    if (method_exists($cache, 'delete')) {
        $cache->delete(AF_FASTNEWS_CACHE_KEY);
    } else {
        // фоллбек для экзотики
        $cache->update(AF_FASTNEWS_CACHE_KEY, null);
    }
}

/**
 * Парсинг: BBCode/MyCode + (HTML только если разрешён на форуме)
 */
function af_fastnews_parse_message($raw)
{
    if ($raw === null) { return ''; }
    $raw = (string)$raw;
    if ($raw === '') { return ''; }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT.'inc/class_parser.php';
    }

    $parser = new postParser();

    /**
     * ВАЖНО:
     * FastNews — контент админа (ACP). Поэтому HTML разрешаем принудительно,
     * иначе баннеры/ссылки/картинки будут превращаться в текст, если allowhtml отсутствует/выключен.
     *
     * При этом MyCode тоже парсим — можно смешивать [b] и <a>/<img>.
     */
    $opts = [
        'allow_html'         => 1,
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

    return $parser->parse_message($raw, $opts);
}


/**
 * Собираем HTML блока (готовый для {$fastnews})
 */
function af_fastnews_build_block()
{
    global $mybb;

    if (!af_fastnews_is_frontend()) return '';

    if (empty($mybb->settings['af_fastnews_enabled'])) return '';

    // Проверка групп
    $list = trim((string)($mybb->settings['af_fastnews_visible_for'] ?? ''));
    if ($list !== '') {
        $allowed = array_filter(array_map('intval', explode(',', $list)));
        $ug      = (int)($mybb->user['usergroup'] ?? 1);
        $extra   = array_filter(array_map('intval', explode(',', (string)($mybb->user['additionalgroups'] ?? ''))));
        $user_groups = array_unique(array_merge([$ug], $extra));
        if (!array_intersect($user_groups, $allowed)) {
            return '';
        }
    }

    $raw = af_fastnews_get_raw();
    if ($raw === '') return '';

    $html  = af_fastnews_parse_message($raw);
    $block = "<!--af_fastnews-->\n<div class=\"af_fastnews\">{$html}</div>\n";

    return $block;
}

/* ================= ХУКИ AF ================= */

/**
 * AF вызывает init на глобальном старте.
 * Здесь мы готовим переменную {$fastnews} для шаблонов.
 */
function af_fastnews_init()
{
    global $fastnews;

    // Главное: переменная существует всегда, чтобы {$fastnews} не оставался “сырой” строкой
    $fastnews = af_fastnews_build_block();
}

/**
 * Больше НЕ врезаемся в HTML страниц (никаких pre_output инъекций).
 * Это автоматически решает проблему “переадрессации” и даёт контроль админу через {$fastnews}.
 */
function af_fastnews_pre_output(&$page = '')
{
    return $page;
}
