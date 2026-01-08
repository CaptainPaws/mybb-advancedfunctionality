<?php
/**
 * AF Addon: Advanced Poster Avatar
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Показывает аватар последнего постера:
 * - index.php (forumbits) — рядом с lastpost
 * - forumdisplay.php (threadlist) — рядом с lastposterlink
 *
 * Без автоправок шаблонов: вставляем маркеры <apa_uid_[X]> в хуках,
 * а в pre_output_page заменяем их на HTML аватара одним батч-запросом.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_APA_ID', 'advancedposteravatar');
define('AF_APA_MARK', '<!--af_apa_assets-->');

/* -------------------- INSTALL / UNINSTALL -------------------- */
function af_advancedposteravatar_install()
{
    global $db;

    $groupName = 'af_' . AF_APA_ID;

    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($groupName) . "'", ['limit' => 1]),
        'gid'
    );

    if ($gid <= 0) {
        $disporder = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS mx'), 'mx');
        $insert = [
            'name'        => $groupName,
            'title'       => 'AF: Advanced Poster Avatar',
            'description' => 'Настройки отображения аватара последнего постера.',
            'disporder'   => $disporder + 1,
            'isdefault'   => 0,
        ];
        $gid = (int)$db->insert_query('settinggroups', $insert);
    }

    af_apa_ensure_setting($gid, 'af_advancedposteravatar_index', 'Показывать на главной (index.php)', 'Добавляет аватар последнего постера в списке форумов.', 'yesno', '1', 10);
    af_apa_ensure_setting($gid, 'af_advancedposteravatar_forumdisplay', 'Показывать в списке тем (forumdisplay.php)', 'Добавляет аватар последнего постера в списке тем.', 'yesno', '1', 20);

    // НОВОЕ: позиция
    af_apa_ensure_setting(
        $gid,
        'af_advancedposteravatar_position',
        'Позиция аватара',
        'Слева или справа от текста (последний пост/последний постер).',
        "select\nleft=Слева\nright=Справа",
        'left',
        25
    );

    af_apa_ensure_setting($gid, 'af_advancedposteravatar_size', 'Размер аватара (px)', 'Например: 44. Используется для вывода и генерации буквенного аватара.', 'text', '44', 30);
    af_apa_ensure_setting($gid, 'af_advancedposteravatar_letter', 'Буквенный аватар при отсутствии картинки', 'Если у пользователя нет аватара — рисовать кружок с первой буквой (JS).', 'yesno', '1', 40);
    af_apa_ensure_setting($gid, 'af_advancedposteravatar_onerror', 'Подменять битый аватар на дефолтный', 'Если картинка не грузится — заменить на дефолтный аватар.', 'yesno', '1', 50);

    rebuild_settings();

    // Вставляем маркеры (СТАРТ + КОНЕЦ) в шаблоны
    af_apa_templates_apply(true);
}

function af_advancedposteravatar_uninstall()
{
    global $db;

    af_apa_templates_apply(false);

    $db->delete_query('settings', "name IN (
        'af_advancedposteravatar_index',
        'af_advancedposteravatar_forumdisplay',
        'af_advancedposteravatar_position',
        'af_advancedposteravatar_size',
        'af_advancedposteravatar_letter',
        'af_advancedposteravatar_onerror'
    )");

    $db->delete_query('settinggroups', "name='af_" . $db->escape_string(AF_APA_ID) . "'");
    rebuild_settings();
}

function af_apa_ensure_setting($gid, $name, $title, $desc, $optionscode, $value, $disporder)
{
    global $db;

    $exists = (int)$db->fetch_field(
        $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]),
        'sid'
    );

    if ($exists > 0) {
        $db->update_query('settings', [
            'gid'       => (int)$gid,
            'disporder' => (int)$disporder,
        ], "sid='{$exists}'");
        return;
    }

    $db->insert_query('settings', [
        'name'        => $name,
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $optionscode,
        'value'       => $db->escape_string($value),
        'disporder'   => (int)$disporder,
        'gid'         => (int)$gid,
    ]);
}


/* -------------------- INIT / HOOKS -------------------- */
function af_advancedposteravatar_init()
{
    // Аддон грузится ядром AF в global_start
    if (!af_apa_is_frontend()) {
        return;
    }

    // ВАЖНО:
    // НИЧЕГО не вставляем в $forum['lastpost'] и другие timestamp поля.
    // Маркеры добавляются через шаблоны при install/uninstall (как в оригинале LPA).
}

function af_advancedposteravatar_pre_output(&$page)
{
    if (!af_apa_is_frontend()) {
        return;
    }

    if (strpos($page, '<apa_uid_[') !== false) {
        $ctx = af_apa_context();
        $page = af_apa_replace_markers($page, $ctx['wrap'], $ctx['img'], $ctx['pos']);
    }

    if (!af_apa_should_run_on_this_script()) {
        return;
    }

    if (strpos($page, AF_APA_MARK) !== false) {
        return;
    }

    $assets = af_apa_assets_html();
    if ($assets === '') {
        return;
    }

    if (stripos($page, '</head>') !== false) {
        $page = str_ireplace('</head>', AF_APA_MARK . "\n" . $assets . "\n</head>", $page);
    } else {
        $page .= "\n" . AF_APA_MARK . "\n" . $assets;
    }
}


/**
 * На главной: добавляем маркер перед lastpost HTML у форума.
 */
function af_apa_hook_forumbits_forum(&$forum)
{
    // Маркеры вставляются через шаблоны.
    return;
}


/**
 * В forumdisplay: добавляем маркер перед lastposterlink.
 * Тут проще править уже собранный $lastposterlink (он реально попадает в шаблон).
 */
function af_apa_hook_forumdisplay_thread(&$thread)
{
    // используем: маркеры вставляются через шаблоны.
    return;
}


/* -------------------- RENDER / REPLACE -------------------- */
function af_apa_replace_markers($html, $wrapClass, $imgClass, $pos)
{
    global $db;

    if (!preg_match_all('#<apa_uid_\[([0-9]+)\]>#', $html, $m)) {
        return $html;
    }

    $wrapMode = (strpos($html, '<apa_end>') !== false);

    $uids = [];
    foreach ($m[1] as $raw) {
        $uids[] = (int)$raw;
    }
    $uids = array_values(array_unique($uids));

    $userMap = [];
    $need = array_filter($uids, static function($u) { return $u > 0; });

    if (!empty($need)) {
        $in = implode(',', array_map('intval', $need));
        $q = $db->simple_select('users', 'uid, username, avatar, avatartype', "uid IN ({$in})");
        while ($u = $db->fetch_array($q)) {
            $userMap[(int)$u['uid']] = $u;
        }
    }

    $find = [];
    $replace = [];

    foreach ($uids as $uid) {
        $find[] = '<apa_uid_[' . $uid . ']>';
        $replace[] = af_apa_render_avatar_block($uid, $userMap, $wrapClass, $imgClass, $pos, $wrapMode);
    }

    $out = str_replace($find, $replace, $html);

    if ($wrapMode) {
        // закрываем: <span class="apa_row ..."><span class="apa_avatar">..</span><span class="apa_meta"> ... </span></span>
        $out = str_replace('<apa_end>', '</span></span>', $out);
    }

    return $out;
}

function af_apa_render_avatar_block($uid, array $userMap, $wrapClass, $imgClass, $pos, $wrapMode)
{
    global $mybb, $lang, $theme;

    $lang->load('global');

    $size = af_apa_size();
    $defaultAvatar = str_replace('{theme}', $theme['imgdir'], (string)$mybb->settings['useravatar']);
    $onerror = ((int)$mybb->settings['af_advancedposteravatar_onerror'] === 1)
        ? ' onerror="this.src=\'' . htmlspecialchars_uni($defaultAvatar) . '\'"'
        : '';

    $pos = ($pos === 'right') ? 'right' : 'left';
    $posClass = ($pos === 'right') ? 'apa_pos_right' : 'apa_pos_left';

    // строим “внутренность” аватара: либо img, либо ссылка+img
    if ($uid <= 0 || empty($userMap[$uid])) {
        $name = $lang->guest;
        $img = af_apa_build_img_tag('', $name, $size, $imgClass, $defaultAvatar, $onerror);
        $avatarInner = $img;
    } else {
        $u = $userMap[$uid];
        $name = (string)$u['username'];

        $avatarUrl = '';
        if (!empty($u['avatar'])) {
            $fa = format_avatar($u['avatar'], $size . 'x' . $size, 2048);
            $avatarUrl = isset($fa['image']) ? (string)$fa['image'] : (string)$u['avatar'];
        }

        $img = af_apa_build_img_tag($avatarUrl, $name, $size, $imgClass, $defaultAvatar, $onerror);

        $profile = get_profile_link((int)$uid);
        $href = $mybb->settings['bburl'] . '/' . $profile;

        $avatarInner = '<a href="' . htmlspecialchars_uni($href) . '" class="apa_link" title="' . htmlspecialchars_uni($name) . '">' . $img . '</a>';
    }

    $wrapClassSafe = htmlspecialchars_uni($wrapClass);

    // CSS-переменная под размер (чтобы padding считался автоматически)
    $style = ' style="--apa-size:' . (int)$size . 'px"';

    if ($wrapMode) {
        return '<span class="apa_row ' . $posClass . ' ' . $wrapClassSafe . '"' . $style . '>'
             . '<span class="apa_avatar">' . $avatarInner . '</span>'
             . '<span class="apa_meta">';
    }

    // fallback
    return '<span class="apa_inline ' . $posClass . ' ' . $wrapClassSafe . '"' . $style . '>'
         . '<span class="apa_avatar">' . $avatarInner . '</span>'
         . '</span>';
}



function af_apa_build_img_tag($avatarUrl, $username, $size, $imgClass, $defaultAvatar, $onerror)
{
    global $mybb;

    $usernameSafe = htmlspecialchars_uni($username);
    $sizeInt = (int)$size;

    $useLetter = ((int)$mybb->settings['af_advancedposteravatar_letter'] === 1);

    if ($avatarUrl === '') {
        if ($useLetter) {
            // JS превратит это в svg data-uri
            return '<img src="javascript:void(0);" class="apa_bg ' . htmlspecialchars_uni($imgClass) . '" data-name="' . $usernameSafe . '" alt="' . $usernameSafe . '" width="' . $sizeInt . '" height="' . $sizeInt . '" />';
        }

        $src = htmlspecialchars_uni($defaultAvatar);
        return '<img src="' . $src . '" class="apa_img ' . htmlspecialchars_uni($imgClass) . '" alt="' . $usernameSafe . '" width="' . $sizeInt . '" height="' . $sizeInt . '"' . $onerror . ' />';
    }

    $src = htmlspecialchars_uni($avatarUrl);
    return '<img src="' . $src . '" class="apa_img ' . htmlspecialchars_uni($imgClass) . '" alt="' . $usernameSafe . '" width="' . $sizeInt . '" height="' . $sizeInt . '"' . $onerror . ' />';
}

/* -------------------- HELPERS -------------------- */

function af_apa_is_frontend()
{
    if (defined('IN_ADMINCP')) {
        return false;
    }
    if (!defined('THIS_SCRIPT')) {
        return false;
    }
    // на modcp можно не лезть
    if (THIS_SCRIPT === 'modcp.php') {
        return false;
    }
    return true;
}

function af_apa_should_run_on_this_script()
{
    global $mybb;

    if (THIS_SCRIPT === 'index.php' && (int)$mybb->settings['af_advancedposteravatar_index'] === 1) {
        return true;
    }
    if (THIS_SCRIPT === 'forumdisplay.php' && (int)$mybb->settings['af_advancedposteravatar_forumdisplay'] === 1) {
        return true;
    }
    return false;
}

function af_apa_context()
{
    if (THIS_SCRIPT === 'index.php') {
        return ['wrap' => 'apa_forumindex', 'img' => 'apa_img_index', 'pos' => af_apa_position()];
    }
    if (THIS_SCRIPT === 'forumdisplay.php') {
        return ['wrap' => 'apa_forumdisplay', 'img' => 'apa_img_forumdisplay', 'pos' => af_apa_position()];
    }
    return ['wrap' => 'apa_default', 'img' => 'apa_img_default', 'pos' => af_apa_position()];
}

function af_apa_position()
{
    global $mybb;
    $v = isset($mybb->settings['af_advancedposteravatar_position']) ? (string)$mybb->settings['af_advancedposteravatar_position'] : 'left';
    return ($v === 'right') ? 'right' : 'left';
}


function af_apa_size()
{
    global $mybb;
    $v = isset($mybb->settings['af_advancedposteravatar_size']) ? (int)$mybb->settings['af_advancedposteravatar_size'] : 44;
    if ($v < 16) $v = 16;
    if ($v > 128) $v = 128;
    return $v;
}

function af_apa_templates_apply($install = true)
{
    $path = MYBB_ROOT . 'inc/adminfunctions_templates.php';
    if (!file_exists($path)) {
        return;
    }
    require_once $path;

    $markerIndexStart = '<apa_uid_[{$lastpost_data[\'lastposteruid\']}]>';
    $markerFDStart    = '<apa_uid_[{$thread[\'lastposteruid\']}]>';
    $markerEnd        = '<apa_end>';

    if ($install) {
        /* ---------- INDEX (forumbits lastpost) ---------- */

        // start
        find_replace_templatesets(
            'forumbit_depth1_forum_lastpost',
            '#^(?!.*' . preg_quote($markerIndexStart, '#') . ')(.*)$#s',
            $markerIndexStart . '$1'
        );
        find_replace_templatesets(
            'forumbit_depth2_forum_lastpost',
            '#^(?!.*' . preg_quote($markerIndexStart, '#') . ')(.*)$#s',
            $markerIndexStart . '$1'
        );

        // end
        find_replace_templatesets(
            'forumbit_depth1_forum_lastpost',
            '#^(?!.*' . preg_quote($markerEnd, '#') . ')(.*)$#s',
            '$1' . $markerEnd
        );
        find_replace_templatesets(
            'forumbit_depth2_forum_lastpost',
            '#^(?!.*' . preg_quote($markerEnd, '#') . ')(.*)$#s',
            '$1' . $markerEnd
        );

        /* ---------- FORUMDISPLAY (threadlist lastpost column) ---------- */
        /**
         * КЛЮЧЕВОЕ:
         * На forumdisplay.php дата/время (обычно {$lastpostdate}) стоит ВЫШЕ, чем {$lastposterlink}.
         * Если START ставить только перед {$lastposterlink} — аватар “проваливается вниз”.
         *
         * Поэтому:
         * 1) СНАЧАЛА чистим старые маркеры (любые позиции) — чтобы апгрейд был без ручных плясок.
         * 2) START вставляем ПЕРЕД {$lastpostdate} (но только в том td, где дальше есть {$lastposterlink}).
         * 3) END вставляем ПЕРЕД </td> ЭТОГО ЖЕ td (после START).
         */

        // 1) чистим следы старых установок в forumdisplay_thread
        find_replace_templatesets('forumdisplay_thread', '#' . preg_quote($markerFDStart, '#') . '#', '', 0);
        find_replace_templatesets('forumdisplay_thread', '#' . preg_quote($markerEnd, '#') . '#', '', 0);

        // 2) START: прямо перед {$lastpostdate}, но только если ДО </td> встречается {$lastposterlink}
        // (то есть это именно “последнее сообщение” колонка)
        find_replace_templatesets(
            'forumdisplay_thread',
            '#(\{\$lastpostdate\})(?=(?:(?!</td>).)*\{\$lastposterlink\})#s',
            $markerFDStart . '$1',
            1
        );

        // Fallback, если в теме нет {$lastpostdate} (редко, но бывает кастом):
        // тогда START ставим перед {$lastposterlink}
        find_replace_templatesets(
            'forumdisplay_thread',
            '#^(?!.*' . preg_quote($markerFDStart, '#') . ')(\s*[\s\S]*?)(\{\$lastposterlink\})#s',
            '$1' . $markerFDStart . '$2',
            1
        );

        // 3) END: перед </td> того td, где стоит START (не перелезая в другие td)
        find_replace_templatesets(
            'forumdisplay_thread',
            '#(' . preg_quote($markerFDStart, '#') . '(?:(?!</td>).)*)(</td>)#s',
            '$1' . $markerEnd . '$2',
            1
        );
    } else {
        // удаляем маркеры
        find_replace_templatesets('forumbit_depth1_forum_lastpost', '#' . preg_quote($markerIndexStart, '#') . '#', '', 0);
        find_replace_templatesets('forumbit_depth2_forum_lastpost', '#' . preg_quote($markerIndexStart, '#') . '#', '', 0);

        find_replace_templatesets('forumdisplay_thread', '#' . preg_quote($markerFDStart, '#') . '#', '', 0);

        find_replace_templatesets('forumbit_depth1_forum_lastpost', '#' . preg_quote($markerEnd, '#') . '#', '', 0);
        find_replace_templatesets('forumbit_depth2_forum_lastpost', '#' . preg_quote($markerEnd, '#') . '#', '', 0);
        find_replace_templatesets('forumdisplay_thread', '#' . preg_quote($markerEnd, '#') . '#', '', 0);
    }
}


function af_apa_assets_html()
{
    global $mybb;

    // теперь ассеты лежат в /addons/advancedposteravatar/assets/
    $base = $mybb->settings['bburl']
          . '/inc/plugins/advancedfunctionality/addons/' . AF_APA_ID
          . '/assets';

    $css = $base . '/advancedposteravatar.css?v=100';
    $js  = $base . '/advancedposteravatar.js?v=100';

    return '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '" />' . "\n"
         . '<script src="' . htmlspecialchars_uni($js) . '" defer></script>';
}

