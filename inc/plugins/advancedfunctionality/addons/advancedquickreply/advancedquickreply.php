<?php
/**
 * AF Addon: AdvancedQuickReply (MHEditor-like)
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Функции:
 * - ACP: управление кастомными кнопками (таблица)
 * - Фронт: добавляет команды SCEditor и встраивает кнопки в toolbar
 * - UserCP: опциональный чекбокс "выключить редактор"
 * - FULL EDITOR IN QUICK REPLY (showthread.php): патч шаблона + showthread_start как в старой версии
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AQR_ID', 'advancedquickreply');
define('AF_AQR_TABLE', 'af_aqr_buttons');
define('AF_AQR_USER_COL', 'useaf_aqr');

define('AF_AQR_MARK_TEMPLATE', '<!-- advancedquickreply -->');
define('AF_AQR_MARK_ASSETS', '<!--af_aqr_assets-->');

function af_advancedquickreply_info(): array
{
    return [
        'name'          => 'Advanced Quick Reply (MHEditor-like)',
        'description'   => 'Кастомные кнопки SCEditor с управлением из ACP (внутренний аддон AF).',
        'website'       => '',
        'author'        => 'CaptainPaws',
        'authorsite'    => '',
        'version'       => '1.0.1',
        'compatibility' => '18*',
    ];
}

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_advancedquickreply_install(): bool
{
    global $db;

    af_aqr_ensure_settings();

    if (!$db->table_exists(AF_AQR_TABLE)) {
        $collation = $db->build_create_table_collation();

        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX.AF_AQR_TABLE." (
                bid INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                icon VARCHAR(255) NOT NULL DEFAULT '',
                opentag VARCHAR(255) NOT NULL,
                closetag VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                disporder INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (bid),
                UNIQUE KEY name (name),
                KEY active (active),
                KEY disporder (disporder)
            ) ENGINE=MyISAM {$collation};
        ");
    }

    if (!$db->field_exists(AF_AQR_USER_COL, 'users')) {
        $db->add_column('users', AF_AQR_USER_COL, "TINYINT(1) NOT NULL DEFAULT 1");
    }

    af_aqr_seed_defaults();

    // КАНОНИЧНО: патчим showthread_quickreply => {$codebuttons}
    af_aqr_patch_showthread_quickreply_template();

    rebuild_settings();
    return true;
}

function af_advancedquickreply_uninstall(): bool
{
    global $db;

    // Снимаем патч шаблона
    af_aqr_unpatch_showthread_quickreply_template();

    if ($db->table_exists(AF_AQR_TABLE)) {
        $db->drop_table(AF_AQR_TABLE);
    }

    if ($db->field_exists(AF_AQR_USER_COL, 'users')) {
        $db->drop_column('users', AF_AQR_USER_COL);
    }

    af_aqr_remove_settings();
    rebuild_settings();

    return true;
}

function af_advancedquickreply_activate(): bool
{
    // ВАЖНО: чтобы не делать uninstall/install (который сносит таблицу),
    // на активации мы гарантируем нужные настройки и патч шаблона.
    af_aqr_ensure_settings();
    af_aqr_patch_showthread_quickreply_template();
    rebuild_settings();
    return true;
}

function af_advancedquickreply_deactivate(): bool
{
    // Не снимаем патч шаблона на deactivate — как и в старой версии (управление настройкой enabled).
    return true;
}

/* -------------------- INIT / HOOKS -------------------- */
function af_advancedquickreply_init(): void
{
    global $plugins, $mybb;

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    // ВАЖНО: подхватываем весь PHP из assets/bbcodes/**,
    // чтобы парсеры/хуки паков работали независимо от layout тулбара.
    af_aqr_include_all_bbcode_php();

    
    // UserCP toggle
    $plugins->add_hook('usercp_options_start', 'af_aqr_usercp_options');
    $plugins->add_hook('usercp_do_options_end', 'af_aqr_usercp_options');

    // КАНОНИЧНО: готовим $codebuttons / $smilieinserter в showthread_start
    $plugins->add_hook('showthread_start', 'af_aqr_showthread_start');

    // NEW: свой endpoint предпросмотра, чтобы не зависеть от xmlhttp.php
    $plugins->add_hook('misc_start', 'af_aqr_misc_start');

    // BBCode packs parse (render posts)
    $plugins->add_hook('parse_message_end', 'af_aqr_parse_message_end');
}


function af_aqr_normalize_icon_url(string $icon, string $bburl): string
{
    $icon = trim($icon);

    if ($icon === '' || $bburl === '') {
        return $icon;
    }

    $bburl = rtrim($bburl, '/');

    // Уже абсолютные (http/https///) или data:
    if (preg_match('~^(https?:)?//~i', $icon) || strpos($icon, 'data:') === 0) {
        return $icon;
    }

    // Root-relative (/inc/... или /images/...)
    if (isset($icon[0]) && $icon[0] === '/') {
        return $bburl . $icon;
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/';

    // Поддержка пакетов: "bbcodes/table/icon.svg" -> внутри assets/
    if (strpos($icon, 'bbcodes/') === 0) {
        return $assetsBase . $icon;
    }

    $imgBase = $assetsBase . 'img/';

    // Старый формат: полный URL на /assets/<file> — перекинуть в /assets/img/<file>
    if (strpos($icon, $assetsBase) === 0 && strpos($icon, '/assets/img/') === false) {
        return $imgBase . substr($icon, strlen($assetsBase));
    }

    // Если в БД лежит что-то вроде "img/foo.svg" — сделаем абсолютным внутри assets
    if (strpos($icon, 'img/') === 0) {
        return $assetsBase . $icon;
    }

    // Если лежит просто имя файла ("aqr-icon.svg") — считаем, что это assets/img/
    return $imgBase . $icon;
}

function af_aqr_parse_message_end(&$message): void
{
    global $mybb;

    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    if (!is_string($message) || $message === '') {
        return;
    }

    // Подключаем весь PHP из bbcodes всегда (на случай, если пак сам добавляет хуки/хелперы)
    af_aqr_include_all_bbcode_php();

    $packs = af_aqr_discover_bbcode_packs();
    if (empty($packs)) {
        return;
    }

    // Источник истины для enabled — layout.
    // Но если layout пустой/не настроен — парсим ВСЕ паки, чтобы теги типа [hide] не оставались текстом.
    $layout = af_aqr_get_toolbar_layout();
    $enabledPackIds = af_aqr_enabled_pack_ids_from_layout($layout);

    if (empty($enabledPackIds)) {
        // fallback: все pack ids
        $enabledPackIds = [];
        foreach ($packs as $p) {
            $pid = (string)($p['id'] ?? '');
            if ($pid !== '') {
                $enabledPackIds[] = $pid;
            }
        }
    }

    if (empty($enabledPackIds)) {
        return;
    }

    $enabledSet = array_fill_keys($enabledPackIds, true);

    // Быстрый skip по tags (если пак их объявил)
    $hay = $message;
    $maybe = false;

    foreach ($packs as $p) {
        $pid = (string)($p['id'] ?? '');
        if ($pid === '' || empty($enabledSet[$pid])) {
            continue;
        }

        $tags = (array)($p['tags'] ?? []);
        if (empty($tags)) {
            // Если tags не задан — считаем пак потенциально релевантным
            $maybe = true;
            break;
        }

        foreach ($tags as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;

            if (stripos($hay, '[' . $t) !== false) {
                $maybe = true;
                break 2;
            }
        }
    }

    if (!$maybe) {
        return;
    }

    // Прогоняем parsers активных паков
    foreach ($packs as $p) {
        $pid = (string)($p['id'] ?? '');
        if ($pid === '' || empty($enabledSet[$pid])) {
            continue;
        }

        // 1) Если в manifest задан parser — подключим (на случай, если он не был подтянут общим include)
        $parserFile = (string)($p['parser'] ?? '');
        if ($parserFile !== '' && is_file($parserFile)) {
            @include_once $parserFile;
        }

        // 2) Каноничная функция парсера пакета
        $fn = 'af_aqr_bbcode_' . $pid . '_parse';
        if (function_exists($fn)) {
            try {
                $fn($message);
            } catch (Throwable $e) {
                // молчим, чтобы не ронять рендер темы
            }
        }
    }
}

/**
 * showthread_start: превращаем quick reply в полный редактор (как в старой версии).
 */
function af_aqr_showthread_start(): void
{
    global $mybb, $forum, $codebuttons, $smilieinserter;

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    // если разрешено выключать и юзер выключил — не генерим codebuttons/smilies
    if (!empty($mybb->settings['af_advancedquickreply_user_toggle'])) {
        if (!empty($mybb->user['uid']) && isset($mybb->user[AF_AQR_USER_COL]) && (int)$mybb->user[AF_AQR_USER_COL] !== 1) {
            return;
        }
    }

    // Логика как newreply.php (и как в твоей старой версии)
    if (!empty($mybb->settings['bbcodeinserter'])
        && (!empty($forum['allowmycode']) || !empty($forum['allowsmilies']))
        && ($mybb->user['uid'] == 0 || !empty($mybb->user['showcodebuttons']))
    ) {
        if (!function_exists('build_mycode_inserter')) {
            require_once MYBB_ROOT.'inc/functions.php';
        }

        // Quick reply использует textarea id="message"
        $codebuttons = build_mycode_inserter('message', true);
    }

    if (!empty($mybb->settings['smilieinserter']) && !empty($forum['allowsmilies'])) {
        if (!function_exists('build_clickable_smilies')) {
            require_once MYBB_ROOT.'inc/functions.php';
        }

        $smilieinserter = build_clickable_smilies();
    }
}

/**
 * AF core вызывает это из pre_output_page (мы используем только для кастомных кнопок/JS),
 * но НЕ как основу "полного редактора" в quick reply — это уже сделано через $codebuttons.
 */
function af_advancedquickreply_pre_output(&$page = ''): void
{
    global $mybb;

    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    // если разрешено выключать и юзер выключил — ничего не делаем
    if (!empty($mybb->settings['af_advancedquickreply_user_toggle'])) {
        if (!empty($mybb->user['uid']) && isset($mybb->user[AF_AQR_USER_COL]) && (int)$mybb->user[AF_AQR_USER_COL] !== 1) {
            return;
        }
    }

    // quick reply?
    $isQuickReplyPage =
        (stripos($page, 'id="quick_reply_form"') !== false) ||
        (stripos($page, "id='quick_reply_form'") !== false) ||
        (stripos($page, AF_AQR_MARK_TEMPLATE) !== false);

    $script = defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '';
    $action = (string)($mybb->input['action'] ?? '');
    $isTargetScript = af_aqr_is_target_script($script, $action);

    if (!$isTargetScript) {
        return;
    }

    $applyWhere = (string)($mybb->settings['af_advancedquickreply_apply_where'] ?? 'both');
    $forceFullEditor = !empty($mybb->settings['af_advancedquickreply_quickreply_full_editor']);

    if (!$forceFullEditor) {
        if ($applyWhere !== 'both') {
            if ($applyWhere === 'quickreply' && !$isQuickReplyPage && $script === 'showthread.php') {
                return;
            }
            if ($applyWhere === 'full' && $isQuickReplyPage) {
                return;
            }
        }
    }

    if (stripos($page, AF_AQR_MARK_ASSETS) !== false) {
        return;
    }

    $buttons = af_aqr_get_active_buttons();

    $needSceditorJs  = !af_aqr_page_has_sceditor_js($page);
    $needSceditorCss = !af_aqr_page_has_sceditor_css($page);

    // Разделяем CSS и JS, чтобы JS ставить строго ПОСЛЕ jQuery
    $parts = af_aqr_assets_parts($buttons, $needSceditorCss, $needSceditorJs, $isQuickReplyPage, $page);

    $css = (string)($parts['css'] ?? '');
    $js  = (string)($parts['js'] ?? '');

    // 1) CSS — безопасно в <head> (в конец head)
    if ($css !== '') {
        if (stripos($page, '</head>') !== false) {
            $page = str_ireplace('</head>', AF_AQR_MARK_ASSETS.$css."\n</head>", $page);
        } elseif (preg_match('~<head[^>]*>~i', $page)) {
            // fallback: если вдруг нет </head>, но есть <head>
            $page = preg_replace('~(<head[^>]*>)~i', '$1'.AF_AQR_MARK_ASSETS.$css, $page, 1);
        } else {
            // самый крайний fallback
            $page = AF_AQR_MARK_ASSETS.$css.$page;
        }
    } else {
        // даже если css пустой — всё равно ставим маркер, чтобы не дублироваться
        if (stripos($page, AF_AQR_MARK_ASSETS) === false) {
            if (stripos($page, '</head>') !== false) {
                $page = str_ireplace('</head>', AF_AQR_MARK_ASSETS."\n</head>", $page);
            } else {
                $page = AF_AQR_MARK_ASSETS.$page;
            }
        }
    }

    // 2) JS — критично: ПОСЛЕ jQuery (иначе "jQuery is not defined")
    if ($js !== '') {
        $updated = af_aqr_insert_after_last_jquery($page, $js);
        if ($updated !== null) {
            $page = $updated;
            return;
        }

        // если jQuery не нашли (или он не скриптом, или убран) — ставим JS ближе к концу body
        if (stripos($page, '</body>') !== false) {
            $page = str_ireplace('</body>', $js."\n</body>", $page);
            return;
        }

        if (stripos($page, '</head>') !== false) {
            $page = str_ireplace('</head>', $js."\n</head>", $page);
            return;
        }

        $page .= $js;
    }
}

function af_aqr_page_has_sceditor_js(string $page): bool
{
    // Ищем только реальные JS-инклюды SCEditor
    return (bool)preg_match('~jquery\.sceditor(\.min)?\.js~i', $page);
}

function af_aqr_misc_start(): void
{
    global $mybb;

    if ((string)($mybb->input['action'] ?? '') !== 'af_aqr_postpreview') {
        return;
    }

    // отвечаем HTML
    @header('Content-Type: text/html; charset=UTF-8');
    @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @header('Pragma: no-cache');

    // Мы не меняем состояние форума, но post_key используем как "вежливую" защиту.
    // Важно: verify_post_check() есть в inc/functions.php
    if (!function_exists('verify_post_check')) {
        require_once MYBB_ROOT . 'inc/functions.php';
    }

    $postKey = (string)($mybb->input['my_post_key'] ?? '');

    if (!empty($mybb->user['uid'])) {
        // silent=true, чтобы не редиректило/не роняло страницу
        $ok = true;
        try {
            $ok = (bool)verify_post_check($postKey, true);
        } catch (Throwable $e) {
            $ok = false;
        }

        if (!$ok) {
            echo '<div class="af-aqr-previewempty">Ошибка: неверный my_post_key.</div>';
            exit;
        }
    } else {
        // гостям quick reply обычно не нужен; но на всякий случай:
        if ($postKey === '') {
            echo '<div class="af-aqr-previewempty">Ошибка: предпросмотр доступен только авторизованным.</div>';
            exit;
        }
    }

    $raw = (string)($mybb->input['message'] ?? '');
    $raw = trim($raw);

    if ($raw === '') {
        echo '<div class="af-aqr-previewempty">Пусто. Напиши что-нибудь 🙂</div>';
        exit;
    }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }

    // Опционально: подхватим ограничения форума по tid (если прислали)
    $allowMyCode = 1;
    $allowSmilies = 1;
    $allowImg = 1;
    $allowVideo = 1;

    $tid = isset($mybb->input['tid']) ? (int)$mybb->input['tid'] : 0;

    if ($tid > 0 && function_exists('get_thread') && function_exists('get_forum')) {
        $thread = get_thread($tid);
        if (!empty($thread['fid'])) {
            $forum = get_forum((int)$thread['fid']);
            if (is_array($forum) && !empty($forum)) {
                $allowMyCode  = !empty($forum['allowmycode']) ? 1 : 0;
                $allowSmilies = !empty($forum['allowsmilies']) ? 1 : 0;
                $allowImg     = !empty($forum['allowimgcode']) ? 1 : 0;
                $allowVideo   = !empty($forum['allowvideocode']) ? 1 : 0;
            }
        }
    }

    $parser = new postParser();

    // ВАЖНО: allow_html = 0 (безопасно). Превью должно быть как постинг без “внезапного HTML”.
    $options = [
        'allow_html'      => 0,
        'allow_mycode'    => $allowMyCode,
        'allow_smilies'   => $allowSmilies,
        'allow_imgcode'   => $allowImg,
        'allow_videocode' => $allowVideo,
        'filter_badwords' => 1,
    ];

    $html = $parser->parse_message($raw, $options);

    // Оборачиваем, чтобы стилизовать при желании
    echo '<div class="af-aqr-previewparsed">' . $html . '</div>';
    exit;
}

/* -------------------- USERCP (toggle) -------------------- */

function af_aqr_usercp_options(): void
{
    global $mybb, $db, $templates;

    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    if (empty($mybb->settings['af_advancedquickreply_user_toggle'])) {
        return;
    }

    if (empty($mybb->user['uid'])) {
        return;
    }

    // save
    if ($mybb->request_method === 'post' && (string)$mybb->input['action'] === 'do_options') {
        $val = !empty($mybb->input['useaf_aqr']) ? 1 : 0;
        $db->update_query('users', [AF_AQR_USER_COL => (int)$val], "uid='".(int)$mybb->user['uid']."'");
        $mybb->user[AF_AQR_USER_COL] = (int)$val;
    }

    $checked = (!isset($mybb->user[AF_AQR_USER_COL]) || (int)$mybb->user[AF_AQR_USER_COL] === 1)
        ? 'checked="checked"'
        : '';

    global $lang;
    $label = (is_object($lang) && isset($lang->af_advancedquickreply_useeditor))
        ? (string)$lang->af_advancedquickreply_useeditor
        : 'Enable Advanced Quick Reply';

    $rowHtml =
        '<tr>'
        .'<td valign="top" width="1"><input type="checkbox" class="checkbox" name="useaf_aqr" id="useaf_aqr" value="1" '.$checked.' /></td>'
        .'<td><span class="smalltext"><label for="useaf_aqr">'.htmlspecialchars_uni($label).'</label></span></td>'
        .'</tr>';

    if (!empty($templates->cache['usercp_options'])) {
        $find = '{$board_style}';
        if (strpos($templates->cache['usercp_options'], 'name="useaf_aqr"') === false) {
            $templates->cache['usercp_options'] = str_replace($find, $find.$rowHtml, $templates->cache['usercp_options']);
        }
    }
}

/* -------------------- TEMPLATE PATCH (showthread_quickreply) -------------------- */
function af_aqr_patch_showthread_quickreply_template(): void
{
    global $db;

    $q = $db->simple_select('templates', 'tid,template', "title='showthread_quickreply'");
    while ($tpl = $db->fetch_array($q)) {

        $src = (string)$tpl['template'];

        // уже патчено
        if (strpos($src, AF_AQR_MARK_TEMPLATE) !== false) {
            continue;
        }

        $updated = $src;

        // 1) Маркер вставляем после textarea (нужен нам как “якорь/детект”)
        $updated = preg_replace(
            '#</textarea>#i',
            '</textarea>'.AF_AQR_MARK_TEMPLATE,
            $updated,
            1
        );

        // 2) ВАЖНО: {$codebuttons} добавляем ТОЛЬКО если его в шаблоне нет вообще
        if (strpos($updated, '{$codebuttons}') === false) {
            $updated = preg_replace(
                '#</textarea>\s*'.preg_quote(AF_AQR_MARK_TEMPLATE, '#').'#i',
                '</textarea>'.AF_AQR_MARK_TEMPLATE.'{$codebuttons}',
                $updated,
                1
            );
        }

        // 3) Убираем строку “Быстрый ответ” (и аналоги), если она есть
        //    (поддерживаем: {$lang->quick_reply}, “Быстрый ответ”, “Quick Reply”)
        $updated = preg_replace(
            '~<tr>\s*<td[^>]*class=("|\')trow_sep\1[^>]*>\s*<strong>\s*(\{\$lang->quick_reply\}|Быстрый\s+ответ|Quick\s+Reply)\s*</strong>.*?</td>\s*</tr>~isu',
            '',
            $updated,
            1
        );

        if ($updated !== null && $updated !== $src) {
            $db->update_query('templates', [
                'template' => $db->escape_string($updated),
            ], 'tid='.(int)$tpl['tid']);
        }
    }
}

function af_aqr_unpatch_showthread_quickreply_template(): void
{
    global $db;

    $q = $db->simple_select('templates', 'tid,template', "title='showthread_quickreply'");
    while ($tpl = $db->fetch_array($q)) {

        $src = (string)$tpl['template'];

        if (strpos($src, AF_AQR_MARK_TEMPLATE) === false) {
            continue;
        }

        // 1) если у нас было “маркер + codebuttons” — снимем это
        $updated = str_replace(AF_AQR_MARK_TEMPLATE.'{$codebuttons}', '', $src);

        // 2) если был только маркер — снимем маркер
        $updated = str_replace(AF_AQR_MARK_TEMPLATE, '', $updated);

        if ($updated !== $src) {
            $db->update_query('templates', [
                'template' => $db->escape_string($updated),
            ], 'tid='.(int)$tpl['tid']);
        }
    }
}

/* -------------------- HELPERS -------------------- */
/**
 * Рекурсивно собирает все .php файлы внутри assets/bbcodes (кроме manifest.php)
 * и include_once'ит их.
 *
 * Нужно, чтобы любые pack-парсеры/хуки/хелперы из bbcodes работали всегда,
 * даже если кнопка не включена в toolbar layout.
 */
function af_aqr_include_all_bbcode_php(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $base = af_aqr_bbcodes_dir();
    if (!is_dir($base)) {
        return;
    }

    $files = [];
    af_aqr_collect_php_files_recursive($base, $files);

    if (empty($files)) {
        return;
    }

    // стабильный порядок (на случай зависимостей между файлами)
    sort($files, SORT_STRING);

    foreach ($files as $f) {
        // На всякий случай
        if (!is_string($f) || $f === '' || !is_file($f)) {
            continue;
        }
        @include_once $f;
    }
}

/**
 * Рекурсивный обход директории и сбор .php (кроме manifest.php).
 */
function af_aqr_collect_php_files_recursive(string $dir, array &$out): void
{
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }

        $path = rtrim($dir, "/\\") . '/' . $it;

        if (is_dir($path)) {
            af_aqr_collect_php_files_recursive($path, $out);
            continue;
        }

        if (!is_file($path)) {
            continue;
        }

        // только php
        if (strtolower(substr($it, -4)) !== '.php') {
            continue;
        }

        // manifest.php не подключаем (он return array и не должен “исполняться” как логика)
        if (strcasecmp($it, 'manifest.php') === 0) {
            continue;
        }

        $out[] = $path;
    }
}


function af_aqr_get_toolbar_layout(): ?array
{
    global $mybb;

    $raw = (string)($mybb->settings['af_advancedquickreply_toolbar_layout'] ?? '');
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    // минимальная нормализация
    if (!isset($data['v'])) {
        $data['v'] = 1;
    }
    if (empty($data['sections']) || !is_array($data['sections'])) {
        return null;
    }

    return $data;
}

/**
 * BASE dir для bbcode-паков.
 */
function af_aqr_bbcodes_dir(): string
{
    return MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/bbcodes/';
}

/**
 * Публичная база для ассетов (относительные пути паков будут добавляться к ней).
 */
function af_aqr_assets_base_url(): string
{
    global $mybb;
    $bburl = (string)($mybb->settings['bburl'] ?? '');
    return rtrim($bburl, '/') . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/';
}

function af_aqr_get_fontfamily_families(): array
{
    global $mybb;

    $raw = (string)($mybb->settings['af_advancedquickreply_fontfamily_json'] ?? '');
    $raw = trim($raw);

    if ($raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    $families = $data['families'] ?? null;
    if (!is_array($families)) return [];

    $out = [];

    foreach ($families as $f) {
        if (!is_array($f)) continue;

        $id = trim((string)($f['id'] ?? ''));
        $name = trim((string)($f['name'] ?? ''));
        if ($name === '') continue;

        $sys = !empty($f['system']) ? 1 : 0;

        $files = [];
        if (!empty($f['files']) && is_array($f['files'])) {
            foreach (['woff2','woff','ttf','otf'] as $ext) {
                $val = trim((string)($f['files'][$ext] ?? ''));
                if ($val !== '') $files[$ext] = $val;
            }
        }

        $out[] = [
            'id'     => ($id !== '' ? $id : preg_replace('~[^a-z0-9_]+~i', '_', strtolower($name))),
            'name'   => $name,
            'system' => $sys,
            'files'  => $files,
        ];
    }

    return $out;
}

function af_aqr_safe_css_string(string $value): string
{
    $value = preg_replace('/[\x00-\x1f\x7f]/u', '', $value ?? '');
    $value = str_replace(['"', "'", '\\'], '', $value);
    return trim($value);
}

function af_aqr_build_fontface_css(array $families): string
{
    if (empty($families)) {
        return '';
    }

    $base = af_aqr_assets_base_url();
    if ($base === '') {
        return '';
    }

    $base = rtrim($base, '/') . '/';

    $css = '';
    foreach ($families as $family) {
        if (!is_array($family)) {
            continue;
        }

        $name = af_aqr_safe_css_string((string)($family['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $files = $family['files'] ?? null;
        if (!is_array($files) || empty($files)) {
            continue;
        }

        $src = [];
        $map = [
            'woff2' => 'woff2',
            'woff'  => 'woff',
            'ttf'   => 'truetype',
            'otf'   => 'opentype',
        ];

        foreach ($map as $ext => $format) {
            $file = trim((string)($files[$ext] ?? ''));
            if ($file === '') {
                continue;
            }

            $encoded = rawurlencode($file);
            $encoded = str_replace('%2F', '/', $encoded);

            $src[] = 'url("' . $base . 'fonts/' . $encoded . '") format("' . $format . '")';
        }

        if (empty($src)) {
            continue;
        }

        $css .= "\n@font-face{"
            . 'font-family:"' . $name . '";'
            . 'src:' . implode(',', $src) . ';'
            . 'font-style:normal;'
            . 'font-weight:400;'
            . 'font-display:swap;'
            . "}\n";
    }

    return trim($css);
}

/**
 * Дискавери паков: assets/bbcodes/<pack>/manifest.php
 * manifest.php должен return array.
 */
function af_aqr_discover_bbcode_packs(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $base = af_aqr_bbcodes_dir();
    $out  = [];

    if (!is_dir($base)) {
        $cache = [];
        return $cache;
    }

    $dirs = @scandir($base);
    if (!is_array($dirs)) {
        $cache = [];
        return $cache;
    }

    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;

        $packDir = $base . $d . '/';
        if (!is_dir($packDir)) continue;

        $manifestFile = $packDir . 'manifest.php';
        if (!is_file($manifestFile)) continue;

        $m = @include $manifestFile;
        if (!is_array($m)) continue;

        $id = trim((string)($m['id'] ?? $d));
        if ($id === '' || !preg_match('~^[a-z0-9_]+$~i', $id)) continue;

        $buttons = (array)($m['buttons'] ?? []);
        $assets  = (array)($m['assets'] ?? []);
        $tags    = (array)($m['tags'] ?? []);
        $parser  = trim((string)($m['parser'] ?? ''));

        // база для относительных ассетов ЭТОГО пакета
        $packRelBase = 'bbcodes/' . $d . '/';

        // нормализуем assets: разрешаем
        // 1) "lockcontent.js"        -> bbcodes/<pack>/lockcontent.js
        // 2) "bbcodes/<pack>/x.js"   -> как есть
        // и запрещаем абсолютные и root-relative.
        $css = [];
        foreach ((array)($assets['css'] ?? []) as $x) {
            $x = trim((string)$x);
            if ($x === '') continue;

            if (preg_match('~^(https?:)?//~i', $x) || (isset($x[0]) && $x[0] === '/')) {
                continue;
            }
            if (strpos($x, '..') !== false) {
                continue;
            }

            if (strpos($x, 'bbcodes/') === 0) {
                $css[] = $x;
            } else {
                $css[] = $packRelBase . ltrim($x, "/\\");
            }
        }

        $js = [];
        foreach ((array)($assets['js'] ?? []) as $x) {
            $x = trim((string)$x);
            if ($x === '') continue;

            if (preg_match('~^(https?:)?//~i', $x) || (isset($x[0]) && $x[0] === '/')) {
                continue;
            }
            if (strpos($x, '..') !== false) {
                continue;
            }

            if (strpos($x, 'bbcodes/') === 0) {
                $js[] = $x;
            } else {
                $js[] = $packRelBase . ltrim($x, "/\\");
            }
        }

        // parser: разрешаем "server.php" (внутри pack dir) или пусто
        $parserAbs = '';
        if ($parser !== '') {
            if (strpos($parser, '..') === false) {
                $candidate = $packDir . $parser;
                if (is_file($candidate)) {
                    $parserAbs = $candidate;
                }
            }
        }

        $out[$id] = [
            'id'      => $id,
            'title'   => (string)($m['title'] ?? $id),
            'dir'     => $packDir,
            'rel'     => $packRelBase, // оставляем для справки
            'buttons' => $buttons,
            'assets'  => [
                'css' => $css,
                'js'  => $js,
            ],
            'tags'    => $tags,
            'parser'  => $parserAbs,
        ];
    }

    uasort($out, function ($a, $b) {
        return strcasecmp((string)$a['id'], (string)$b['id']);
    });

    $cache = array_values($out);
    return $cache;
}

/**
 * Карта cmd => packId, чтобы понимать, какой пак включён тулбаром.
 */
function af_aqr_pack_cmd_map(): array
{
    static $map = null;
    if (is_array($map)) return $map;

    $map = [];
    foreach (af_aqr_discover_bbcode_packs() as $p) {
        $pid = (string)($p['id'] ?? '');
        $btns = (array)($p['buttons'] ?? []);
        foreach ($btns as $b) {
            if (!is_array($b)) continue;
            $cmd = trim((string)($b['cmd'] ?? ''));
            if ($cmd === '') continue;
            $map[$cmd] = $pid;
        }
    }
    return $map;
}

/**
 * Какие packId реально включены (используются) в сохранённом layout тулбара.
 */
function af_aqr_enabled_pack_ids_from_layout(?array $layout): array
{
    $enabled = [];

    if (!$layout || empty($layout['sections']) || !is_array($layout['sections'])) {
        return $enabled;
    }

    $cmdToPack = af_aqr_pack_cmd_map();

    foreach ($layout['sections'] as $sec) {
        if (!is_array($sec)) continue;
        $items = (array)($sec['items'] ?? []);
        foreach ($items as $it) {
            $cmd = trim((string)$it);
            if ($cmd === '') continue;
            if (!isset($cmdToPack[$cmd])) continue;
            $enabled[$cmdToPack[$cmd]] = true;
        }
    }

    return array_keys($enabled);
}

/**
 * Возвращает built-in кнопки ТОЛЬКО из включённых паков (по тулбару).
 */
function af_aqr_get_builtin_buttons_enabled(array $enabledPackIds): array
{
    static $cache = null;

    // кешируем по ключу, чтобы не перечитывать диски
    $key = implode(',', $enabledPackIds);
    if (is_array($cache) && isset($cache[$key])) {
        return $cache[$key];
    }

    global $mybb;

    $bburl = (string)($mybb->settings['bburl'] ?? '');
    $bburl = rtrim($bburl, '/');

    $enabledSet = array_fill_keys($enabledPackIds, true);
    $out = [];

    foreach (af_aqr_discover_bbcode_packs() as $p) {
        $pid = (string)($p['id'] ?? '');
        if ($pid === '' || empty($enabledSet[$pid])) continue;

        foreach ((array)($p['buttons'] ?? []) as $b) {
            if (!is_array($b)) continue;

            $cmd   = trim((string)($b['cmd'] ?? ''));
            $name  = trim((string)($b['name'] ?? ''));
            $title = trim((string)($b['title'] ?? ''));
            if ($cmd === '' || $title === '') continue;

            $icon = trim((string)($b['icon'] ?? ''));
            if ($icon !== '') {
                $icon = af_aqr_normalize_icon_url($icon, $bburl);
            }

            $out[] = [
                'cmd'      => $cmd,
                'name'     => $name,
                'title'    => $title,
                'icon'     => $icon,
                'handler'  => trim((string)($b['handler'] ?? '')),
                'opentag'  => (string)($b['opentag'] ?? ''),
                'closetag' => (string)($b['closetag'] ?? ''),
                'pack'     => $pid,
            ];
        }
    }

    $cache = is_array($cache) ? $cache : [];
    $cache[$key] = $out;
    return $out;
}

function af_aqr_cache_buster(): string
{
    // “Всегда свежак”: меняется на каждом запросе (и не зависит от mtime/версий)
    // TIME_NOW есть в MyBB, но добавим rand, чтобы даже в одну секунду не совпадало.
    $t = defined('TIME_NOW') ? (string)TIME_NOW : (string)time();
    return rawurlencode($t . '-' . (string)mt_rand(1000, 9999));
}

function af_aqr_editor_selectors_from_settings(): array
{
    global $mybb;

    $raw = trim((string)($mybb->settings['af_advancedquickreply_editor_selectors'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('~\s*,\s*~', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') continue;
        $out[] = $part;
    }

    return $out;
}

function af_aqr_page_has_sceditor_css(string $page): bool
{
    // Ищем реальные инклюды темы SCEditor (обычно default.min.css)
    return (bool)preg_match('~sceditor/.*\.css~i', $page)
        || (stripos($page, 'default.min.css') !== false);
}

function af_aqr_is_target_script(string $script, string $action = ''): bool
{
    if ($script === 'showthread.php' || $script === 'newthread.php' || $script === 'editpost.php') {
        return true;
    }

    if ($script === 'private.php') {
        return in_array($action, ['send', 'read'], true);
    }

    return false;
}

function af_aqr_assets_html(array $buttons, bool $includeSceditorCss = false, bool $includeSceditorJs = false, bool $isQuickReplyPage = false): string
{
    global $mybb;

    $bburl = (string)$mybb->settings['bburl'];
    $base  = $bburl.'/inc/plugins/advancedfunctionality/addons/'.AF_AQR_ID.'/assets';

    $ver = af_aqr_cache_buster();

    $toolbarLayout = af_aqr_get_toolbar_layout();

    $payload = [
        'buttons' => $buttons,
        'cfg' => [
            'enabled'               => true,
            'quickreplyFullEditor'  => !empty($mybb->settings['af_advancedquickreply_quickreply_full_editor']),
            'bburl'                 => $bburl,
            'isQuickReplyPage'      => $isQuickReplyPage,
            'previewUrl'            => $bburl . '/misc.php?action=af_aqr_postpreview',
            'postKey'               => isset($mybb->post_code) ? (string)$mybb->post_code : '',
            'countBbcode'           => !empty($mybb->settings['af_advancedquickreply_counter_count_bbcode']),
            'editorSelectors'       => af_aqr_editor_selectors_from_settings(),
            'toolbarLayout'         => $toolbarLayout,
            'payloadVersion'        => (string)af_aqr_cache_buster(),
            'layoutHash'            => $toolbarLayout ? md5(json_encode($toolbarLayout)) : '',
            'debug'                 => !empty($mybb->settings['af_advancedquickreply_debug']),

            'assetsBaseUrl'         => rtrim($bburl, '/') . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets/',
            'sceditorStylesBaseUrl' => rtrim($bburl, '/') . '/jscripts/sceditor/styles/',
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }

    $out = '';
    $defer = ' defer="defer"';

    if ($includeSceditorCss) {
        $out .= "\n".'<link rel="stylesheet" href="'.$bburl.'/jscripts/sceditor/themes/default.min.css?v='.$ver.'" />';
    }

    if ($includeSceditorJs) {
        $out .= "\n".'<script'.$defer.' src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.min.js?v='.$ver.'"></script>';
        $out .= "\n".'<script'.$defer.' src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.bbcode.min.js?v='.$ver.'"></script>';
        $out .= "\n".'<script'.$defer.' src="'.$bburl.'/jscripts/bbcodes_sceditor.js?v='.$ver.'"></script>';
    }

    // ЕДИНЫЙ CSS (QR + full editor / quick edit)
    $out .= "\n".'<link rel="stylesheet" href="'.$base.'/advancededitor.css?v='.$ver.'" />';

    $out .= "\n".'<script>window.afEditorPayload = '.$json.';window.afAqrPayload = window.afEditorPayload;</script>';
    $out .= "\n".'<script'.$defer.' src="'.$base.'/advancededitor.js?v='.$ver.'"></script>'."\n";

    return $out;
}

function af_aqr_assets_parts(array $buttons, bool $includeSceditorCss, bool $includeSceditorJs, bool $isQuickReplyPage, string $pageHtml): array
{
    global $mybb;

    $bburl = (string)($mybb->settings['bburl'] ?? '');
    $bburl = rtrim($bburl, '/');

    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AQR_ID . '/assets';
    $ver  = af_aqr_cache_buster();

    $toolbarLayout = af_aqr_get_toolbar_layout();
    $enabledPackIds = af_aqr_enabled_pack_ids_from_layout($toolbarLayout);

    $packs = af_aqr_discover_bbcode_packs();
    if (empty($enabledPackIds)) {
        $enabledPackIds = [];
        foreach ($packs as $p) {
            $pid = (string)($p['id'] ?? '');
            if ($pid !== '') $enabledPackIds[] = $pid;
        }
    }

    $builtinsEnabled = af_aqr_get_builtin_buttons_enabled($enabledPackIds);

    $payload = [
        'buttons'   => $buttons,
        'builtins'  => $builtinsEnabled,
        'cfg' => [
            'enabled'               => true,
            'quickreplyFullEditor'  => !empty($mybb->settings['af_advancedquickreply_quickreply_full_editor']),
            'bburl'                 => $bburl,
            'isQuickReplyPage'      => $isQuickReplyPage,
            'previewUrl'            => $bburl . '/misc.php?action=af_aqr_postpreview',
            'postKey'               => isset($mybb->post_code) ? (string)$mybb->post_code : '',
            'countBbcode'           => !empty($mybb->settings['af_advancedquickreply_counter_count_bbcode']),
            'toolbarLayout'         => $toolbarLayout,
            'enabledPacks'          => $enabledPackIds,
            'fontFamilies'          => af_aqr_get_fontfamily_families(),
            'editorSelectors'       => af_aqr_editor_selectors_from_settings(),
            'payloadVersion'        => (string)af_aqr_cache_buster(),
            'layoutHash'            => $toolbarLayout ? md5(json_encode($toolbarLayout)) : '',
            'debug'                 => !empty($mybb->settings['af_advancedquickreply_debug']),

            'assetsBaseUrl'         => af_aqr_assets_base_url(),
            'sceditorStylesBaseUrl' => $bburl . '/jscripts/sceditor/styles/',
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }

    $css = '';
    $js  = '';

    if ($includeSceditorCss) {
        $css .= "\n" . '<link rel="stylesheet" href="' . $bburl . '/jscripts/sceditor/themes/default.min.css?v=' . $ver . '" />';
    }

    if ($includeSceditorJs) {
        $defer = ' defer="defer"';
        $js .= "\n" . '<script' . $defer . ' src="' . $bburl . '/jscripts/sceditor/jquery.sceditor.min.js?v=' . $ver . '"></script>';
        $js .= "\n" . '<script' . $defer . ' src="' . $bburl . '/jscripts/sceditor/jquery.sceditor.bbcode.min.js?v=' . $ver . '"></script>';
        $js .= "\n" . '<script' . $defer . ' src="' . $bburl . '/jscripts/bbcodes_sceditor.js?v=' . $ver . '"></script>';
    }

    // ЕДИНЫЙ CSS ядра (QR + full editor / quick edit)
    $css .= "\n" . '<link rel="stylesheet" href="' . $base . '/advancededitor.css?v=' . $ver . '" />';

    $fontCss = af_aqr_build_fontface_css(af_aqr_get_fontfamily_families());
    if ($fontCss !== '') {
        $css .= "\n" . '<style id="af-aqr-fontfaces">' . $fontCss . "\n</style>";
    }

    // CSS паков
    $assetsBaseUrl = af_aqr_assets_base_url();
    $enabledSet = array_fill_keys($enabledPackIds, true);

    foreach ($packs as $p) {
        $pid = (string)($p['id'] ?? '');
        if ($pid === '' || empty($enabledSet[$pid])) continue;

        foreach ((array)($p['assets']['css'] ?? []) as $relCss) {
            $relCss = trim((string)$relCss);
            if ($relCss === '') continue;
            $css .= "\n" . '<link rel="stylesheet" href="' . $assetsBaseUrl . $relCss . '?v=' . $ver . '" />';
        }
    }

    // JS паков
    $defer = ' defer="defer"';
    foreach ($packs as $p) {
        $pid = (string)($p['id'] ?? '');
        if ($pid === '' || empty($enabledSet[$pid])) continue;

        foreach ((array)($p['assets']['js'] ?? []) as $relJs) {
            $relJs = trim((string)$relJs);
            if ($relJs === '') continue;
            $js .= "\n" . '<script' . $defer . ' src="' . $assetsBaseUrl . $relJs . '?v=' . $ver . '"></script>';
        }
    }

    // Ядро JS
    $js  .= "\n" . '<script>window.afEditorPayload = ' . $json . ';window.afAqrPayload = window.afEditorPayload;</script>';
    $js  .= "\n" . '<script defer="defer" src="' . $base . '/advancededitor.js?v=' . $ver . '"></script>' . "\n";

    return [
        'css' => $css,
        'js'  => $js,
    ];
}

function af_aqr_insert_after_last_jquery(string $html, string $insert): ?string
{
    // ВАЖНО:
    // Раньше мы вставляли только "после последнего jquery*.js".
    // Но SCEditor / bbcodes_sceditor.js может быть подключён НИЖЕ jQuery,
    // и тогда наш defer-скрипт выполняется раньше SCEditor => команд нет => кнопки "мертвые".
    //
    // Поэтому выбираем "последний скрипт", чей src содержит jquery ИЛИ sceditor,
    // и вставляем после него.

    $re = '~<script\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>\s*</script>~i';

    if (!preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $bestMatch = null;
    $bestPos   = -1;

    $count = count($m[0]);
    for ($i = 0; $i < $count; $i++) {
        $full = (string)$m[0][$i][0];
        $pos  = (int)$m[0][$i][1];
        $src  = strtolower((string)$m[2][$i][0]);

        // интересуют зависимости: jquery / sceditor / bbcodes_sceditor
        if (strpos($src, 'jquery') === false && strpos($src, 'sceditor') === false) {
            continue;
        }

        // берём самый поздний по позиции в документе
        if ($pos > $bestPos) {
            $bestPos   = $pos;
            $bestMatch = $full;
        }
    }

    if ($bestMatch === null) {
        return null;
    }

    $after = $bestPos + strlen($bestMatch);
    if ($after < 0 || $after > strlen($html)) {
        return null;
    }

    return substr($html, 0, $after) . "\n" . $insert . substr($html, $after);
}

function af_aqr_get_active_buttons(): array
{
    global $db, $mybb;

    $out = [];

    if (!$db->table_exists(AF_AQR_TABLE)) {
        return $out;
    }

    $bburl = (isset($mybb) && isset($mybb->settings['bburl'])) ? (string)$mybb->settings['bburl'] : '';

    $q = $db->simple_select(AF_AQR_TABLE, '*', "active=1", ['order_by' => 'disporder ASC, name ASC']);
    while ($row = $db->fetch_array($q)) {
        $icon = (string)$row['icon'];
        $icon = af_aqr_normalize_icon_url($icon, $bburl);

        $out[] = [
            'name'     => (string)$row['name'],
            'title'    => (string)$row['title'],
            'icon'     => $icon,
            'opentag'  => (string)$row['opentag'],
            'closetag' => (string)$row['closetag'],
        ];
    }

    return $out;
}

function af_aqr_seed_defaults(): void
{
    return;
}

/* -------------------- SETTINGS -------------------- */
function af_aqr_ensure_settings(): int
{
    global $db;

    $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedquickreply'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');

    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_advancedquickreply',
            'title'       => 'Advanced Quick Reply',
            'description' => 'Настройки Advanced Quick Reply.',
            'disporder'   => 100,
            'isdefault'   => 0,
        ]);
    } else {
        $db->update_query('settinggroups', [
            'title'       => $db->escape_string('Advanced Quick Reply'),
            'description' => $db->escape_string('Настройки Advanced Quick Reply.'),
        ], "gid='{$gid}'");
    }

    // дефолтные системные шрифты (покажутся сразу)
    $defaultFonts = [
        'v' => 1,
        'families' => [
            ['id' => 'arial',           'name' => 'Arial',           'system' => 1, 'files' => (object)[]],
            ['id' => 'helvetica',       'name' => 'Helvetica',       'system' => 1, 'files' => (object)[]],
            ['id' => 'verdana',         'name' => 'Verdana',         'system' => 1, 'files' => (object)[]],
            ['id' => 'tahoma',          'name' => 'Tahoma',          'system' => 1, 'files' => (object)[]],
            ['id' => 'trebuchet_ms',    'name' => 'Trebuchet MS',    'system' => 1, 'files' => (object)[]],
            ['id' => 'georgia',         'name' => 'Georgia',         'system' => 1, 'files' => (object)[]],
            ['id' => 'times_new_roman', 'name' => 'Times New Roman', 'system' => 1, 'files' => (object)[]],
            ['id' => 'garamond',        'name' => 'Garamond',        'system' => 1, 'files' => (object)[]],
            ['id' => 'courier_new',     'name' => 'Courier New',     'system' => 1, 'files' => (object)[]],
        ],
    ];
    $defaultFontsJson = json_encode($defaultFonts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($defaultFontsJson) || $defaultFontsJson === '') {
        $defaultFontsJson = '{"v":1,"families":[]}';
    }

    $meta = [
        'af_advancedquickreply_enabled' => [
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
            'title'       => 'Включить Advanced Quick Reply',
            'description' => 'Включает кастомные кнопки SCEditor и расширения для редактора.',
        ],
        'af_advancedquickreply_user_toggle' => [
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 2,
            'title'       => 'Разрешить пользователю выключать редактор',
            'description' => 'Добавляет чекбокс в UserCP: пользователь может отключить расширенный редактор.',
        ],
        'af_advancedquickreply_apply_where' => [
            'optionscode' => 'text',
            'value'       => 'both',
            'disporder'   => 3,
            'title'       => 'Где применять',
            'description' => 'both / quickreply / full — где подключать расширения (не влияет на full editor в quick reply).',
        ],
        'af_advancedquickreply_quickreply_full_editor' => [
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 4,
            'title'       => 'Полный редактор в Quick Reply',
            'description' => 'В showthread quick reply гарантирует SCEditor и автоинициализацию прямо в форме.',
        ],
        'af_advancedquickreply_counter_count_bbcode' => [
            'optionscode' => 'yesno',
            'value'       => '0',
            'disporder'   => 5,
            'title'       => 'Счётчик символов: считать BBCode',
            'description' => 'Если Да — считаем вместе с тегами [b], [url=...] и т.п. Если Нет — теги не учитываются.',
        ],
        'af_advancedquickreply_debug' => [
            'optionscode' => 'yesno',
            'value'       => '0',
            'disporder'   => 6,
            'title'       => 'Debug-режим редактора',
            'description' => 'Включает диагностические логи в консоли браузера для Advanced Editor.',
        ],
        'af_advancedquickreply_toolbar_layout' => [
            'optionscode' => 'textarea',
            'value'       => '',
            'disporder'   => 7,
            'title'       => 'Toolbar layout (JSON)',
            'description' => 'Не редактируй руками. Заполняется конструктором тулбара в Advanced Quick Reply.',
        ],

        // НОВОЕ: список font-family (JSON)
        'af_advancedquickreply_fontfamily_json' => [
            'optionscode' => 'textarea',
            'value'       => $defaultFontsJson,
            'disporder'   => 8,
            'title'       => 'Font families (JSON)',
            'description' => 'Заполняется вкладкой "Загрузить шрифты". Не редактируй руками.',
        ],
    ];

    foreach ($meta as $name => $m) {
        af_aqr_ensure_setting(
            $gid,
            $name,
            (string)$m['optionscode'],
            (string)$m['value'],
            (int)$m['disporder'],
            (string)$m['title'],
            (string)$m['description']
        );
    }

    return $gid;
}


function af_aqr_ensure_setting(
    int $gid,
    string $name,
    string $type,
    string $value,
    int $disporder,
    string $title,
    string $description
): void {
    global $db;

    $nameEsc = $db->escape_string($name);

    $q = $db->simple_select('settings', 'sid', "name='{$nameEsc}'", ['limit' => 1]);
    $sid = (int)$db->fetch_field($q, 'sid');

    if ($sid > 0) {
        $db->update_query('settings', [
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string($description),
            'optionscode' => $db->escape_string($type),
            'disporder'   => (int)$disporder,
            'gid'         => (int)$gid,
        ], "sid='{$sid}'");
        return;
    }

    $db->insert_query('settings', [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($description),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => (int)$disporder,
        'gid'         => (int)$gid,
    ]);
}

function af_aqr_remove_settings(): void
{
    global $db;

    $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedquickreply'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid <= 0) {
        return;
    }

    $db->delete_query('settings', "gid='{$gid}'");
    $db->delete_query('settinggroups', "gid='{$gid}'");
}

function af_aqr_get_builtin_buttons(): array
{
    $layout = af_aqr_get_toolbar_layout();
    $enabledPackIds = af_aqr_enabled_pack_ids_from_layout($layout);
    return af_aqr_get_builtin_buttons_enabled($enabledPackIds);
}
