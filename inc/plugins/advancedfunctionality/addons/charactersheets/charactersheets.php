<?php
/**
 * AF Addon: CharacterSheets
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Автопринятие анкеты с ответом, закрытием, переносом и триггером листа персонажа.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

const AF_CS_ID = 'charactersheets';
const AF_CS_TABLE = 'af_charactersheets_accept';
const AF_CS_CONFIG_TABLE = 'af_charactersheets_config';
const AF_CS_TPL_MARK = '<!--AF_CS_ACCEPT-->';
const AF_CS_ASSET_MARK = '<!--AF_CS_ASSETS-->';
const AF_CS_MODAL_MARK = '<!--AF_CS_MODAL-->';

define('AF_CS_BASE', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/charactersheets/');
define('AF_CS_TPL_DIR', AF_CS_BASE . 'templates/');

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_charactersheets_is_installed(): bool
{
    global $db;
    return $db->table_exists(AF_CS_TABLE);
}

function af_charactersheets_install(): void
{
    global $db;

    af_charactersheets_ensure_schema();
    af_charactersheets_ensure_settings();
    af_charactersheets_templates_install_or_update();
    af_charactersheets_ensure_postbit_placeholder();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_charactersheets_activate(): bool
{
    af_charactersheets_templates_install_or_update();
    af_charactersheets_ensure_schema();
    af_charactersheets_ensure_postbit_placeholder();
    return true;
}

function af_charactersheets_deactivate(): bool
{
    return true;
}

function af_charactersheets_uninstall(): void
{
    global $db;

    if ($db->table_exists(AF_CS_TABLE)) {
        $db->drop_table(AF_CS_TABLE);
    }
    if ($db->table_exists(AF_CS_CONFIG_TABLE)) {
        $db->drop_table(AF_CS_CONFIG_TABLE);
    }

    $db->delete_query('settings', "name IN (
        'af_charactersheets_enabled',
        'af_charactersheets_accept_groups',
        'af_charactersheets_pending_forums',
        'af_charactersheets_accepted_forum',
        'af_charactersheets_accept_wrap_htmlbb',
        'af_charactersheets_accept_close_thread',
        'af_charactersheets_accept_move_thread',
        'af_charactersheets_sheet_autocreate'
    )");
    $db->delete_query('settinggroups', "name='af_charactersheets'");
    $db->delete_query('templates', "title LIKE 'charactersheets_%'");
    $db->delete_query('templates', "title IN ('charactersheet_fullpage','charactersheet_inner','charactersheet_modal','postbit_plaque','charactersheet_rct_cards','charactersheet_stats_bars','charactersheet_attributes','charactersheet_skills','charactersheet_feats')");

    if (file_exists(MYBB_ROOT . 'inc/adminfunctions_templates.php')) {
        require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
    }
    if (function_exists('find_replace_templatesets')) {
        find_replace_templatesets('postbit_classic', "#\\{\\$post\\['af_cs_plaque'\\]\\}#i", '');
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

/* -------------------- SETTINGS -------------------- */

function af_charactersheets_ensure_settings(): void
{
    global $db, $lang;

    af_charactersheets_lang();

    $gid = af_charactersheets_ensure_group(
        'af_charactersheets',
        $lang->af_charactersheets_group ?? 'AF: CharacterSheets',
        $lang->af_charactersheets_group_desc ?? 'CharacterSheets settings.'
    );

    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_enabled',
        $lang->af_charactersheets_enabled ?? 'Enable CharacterSheets',
        $lang->af_charactersheets_enabled_desc ?? 'Yes/No',
        'yesno',
        '1',
        1
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_groups',
        $lang->af_charactersheets_accept_groups ?? 'Groups allowed to accept',
        $lang->af_charactersheets_accept_groups_desc ?? 'CSV group ids',
        'text',
        '4,3,6',
        2
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_pending_forums',
        $lang->af_charactersheets_pending_forums ?? 'Pending forums',
        $lang->af_charactersheets_pending_forums_desc ?? 'CSV forum ids',
        'text',
        '',
        3
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accepted_forum',
        $lang->af_charactersheets_accepted_forum ?? 'Accepted forum',
        $lang->af_charactersheets_accepted_forum_desc ?? 'Forum id',
        'text',
        '',
        4
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_wrap_htmlbb',
        $lang->af_charactersheets_accept_wrap_htmlbb ?? 'Wrap in [html]',
        $lang->af_charactersheets_accept_wrap_htmlbb_desc ?? 'Wrap acceptance post',
        'yesno',
        '1',
        5
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_close_thread',
        $lang->af_charactersheets_accept_close_thread ?? 'Close thread',
        $lang->af_charactersheets_accept_close_thread_desc ?? 'Close thread after acceptance',
        'yesno',
        '1',
        6
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_move_thread',
        $lang->af_charactersheets_accept_move_thread ?? 'Move thread',
        $lang->af_charactersheets_accept_move_thread_desc ?? 'Move thread after acceptance',
        'yesno',
        '1',
        7
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_sheet_autocreate',
        $lang->af_charactersheets_sheet_autocreate ?? 'Auto-create sheet',
        $lang->af_charactersheets_sheet_autocreate_desc ?? 'Trigger sheet generator stub',
        'yesno',
        '1',
        8
    );

    $db->delete_query('settings', "name='af_charactersheets_accept_post_template'");
}

function af_charactersheets_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_charactersheets_enabled']);
}

/* -------------------- INIT HOOKS -------------------- */

function af_charactersheets_init(): void
{
    global $plugins;

    $plugins->add_hook('showthread_start', 'af_charactersheets_showthread_start');
    $plugins->add_hook('pre_output_page', 'af_charactersheets_pre_output');
    $plugins->add_hook('misc_start', 'af_charactersheets_misc_start');
    $plugins->add_hook('postbit', 'af_charactersheets_postbit_button');
    $plugins->add_hook('postbit_prev', 'af_charactersheets_postbit_button');
    $plugins->add_hook('postbit_pm', 'af_charactersheets_postbit_button');
}

/* -------------------- SHOWTHREAD BUTTON -------------------- */
function af_charactersheets_showthread_start(): void
{
    global $mybb, $thread, $lang;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    if (!is_array($thread)) {
        return;
    }

    af_charactersheets_lang();

    $tid = (int)($thread['tid'] ?? 0);
    $fid = (int)($thread['fid'] ?? 0);
    if ($tid <= 0 || $fid <= 0) {
        return;
    }

    // Кнопка и логика — только в pending-форумах
    if (!af_charactersheets_is_pending_forum($fid)) {
        return;
    }

    // Права (группы)
    if (!af_charactersheets_user_can_accept($mybb->user, $fid)) {
        return;
    }

    // Если тему вернули обратно в pending после принятия —
    // открываем (чтобы можно было редактировать). Делать это безопасно только для тех,
    // кто имеет право принимать.
    $accept_row = af_charactersheets_get_accept_row($tid);
    $was_accepted = !empty($accept_row['accepted']);

    if ($was_accepted) {
        // В MyBB поле closed: '' или '0' = открыта, '1' = закрыта
        $closed = (string)($thread['closed'] ?? '');
        if ($closed === '1') {
            require_once MYBB_ROOT . 'inc/class_moderation.php';
            $moderation = new Moderation;
            // Открываем тему. Если по какой-то причине нет прав — MyBB сам отработает.
            $moderation->open_threads([$tid]);

            // Обновим локально, чтоб не было странностей дальше
            $thread['closed'] = '0';
        }
    }

    // В accepted-форуме кнопка не нужна (на всякий)
    if (af_charactersheets_is_in_accepted_forum($fid)) {
        return;
    }

    $text = $was_accepted
        ? ($lang->af_charactersheets_accept_button_reaccept ?? 'Принять заново')
        : ($lang->af_charactersheets_accept_button ?? 'Принять анкету');

    $url = 'misc.php?action=af_charactersheets_accept&tid=' . $tid . '&my_post_key=' . $mybb->post_code;

    // ВАЖНО: не оборачиваем в div — чтобы кнопка вставлялась в один ряд с input-кнопками
    $GLOBALS['af_charactersheets_accept_button'] =
        '<a class="button af-cs-accept-button" href="' . htmlspecialchars_uni($url) . '">'
        . '<span>' . htmlspecialchars_uni($text) . '</span>'
        . '</a>';
}

function af_charactersheets_pre_output(&$page): void
{
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'showthread.php') {
        return;
    }

    if (!empty($GLOBALS['af_charactersheets_needs_assets'])) {
        $page = af_charactersheets_inject_assets($page);
    }

    if (!empty($GLOBALS['af_charactersheets_needs_modal'])) {
        $page = af_charactersheets_inject_modal($page);
    }

    if (empty($GLOBALS['af_charactersheets_accept_button'])) {
        return;
    }

    if (strpos($page, AF_CS_TPL_MARK) !== false) {
        return;
    }

    $insert = "\n" . AF_CS_TPL_MARK . "\n" . $GLOBALS['af_charactersheets_accept_button'] . "\n";

    // в блоке quick reply — рядом с кнопками Preview/Submit
    // Сначала пробуем вставить ПОСЛЕ preview (если есть), иначе — перед submit.
    $count = 0;
    $page2 = @preg_replace(
        '~(<input\b[^>]*\bid=("|\')quick_reply_preview\2[^>]*>)~i',
        '$1' . "\n" . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    $count = 0;
    $page2 = @preg_replace(
        '~(<input\b[^>]*\bid=("|\')quick_reply_submit\2[^>]*>)~i',
        $insert . '$1',
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }


    // 2) Альтернатива: рядом с формой quick reply (если id на submit вдруг кастомный)
    $count = 0;
    $page2 = @preg_replace(
        '~(<form\b[^>]*\bname=("|\')quick_reply\2[^>]*>)~i',
        '$1' . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 3) Запасной якорь: перед контентом
    $count = 0;
    $page2 = @preg_replace(
        '~(<div\s+id=("|\')content\2\b[^>]*>)~i',
        $insert . '$1',
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 4) Старые темы: td.thead strong
    $count = 0;
    $page2 = @preg_replace(
        '~(<td\b[^>]*\bclass=("\')thead\2[^>]*>.*?<strong\b[^>]*>.*?</strong>)~is',
        '$1' . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 5) Фолбэк: перед </body>
    $count = 0;
    $page2 = @preg_replace('~</body>~i', $insert . "\n</body>", $page, 1, $count);
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 6) Совсем крайний случай
    $page .= $insert;
}

/* -------------------- MISC ENDPOINT -------------------- */

function af_charactersheets_misc_start(): void
{
    global $mybb, $db, $lang, $session;

    $action = (string)$mybb->get_input('action');
    if ($action === 'af_charactersheet') {
        af_charactersheets_lang();
        $slug = (string)$mybb->get_input('slug');
        af_charactersheets_render_sheet_page($slug);
        exit;
    }

    if ($mybb->get_input('action') !== 'af_charactersheets_accept') {
        return;
    }

    af_charactersheets_lang();

    if (!af_charactersheets_is_enabled()) {
        af_charactersheets_deny('Addon disabled');
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $tid = (int)$mybb->get_input('tid');
    if ($tid <= 0) {
        af_charactersheets_deny('Invalid tid', ['tid' => $tid]);
    }

    $thread = $db->fetch_array($db->simple_select('threads', '*', 'tid=' . $tid, ['limit' => 1]));
    if (empty($thread)) {
        af_charactersheets_deny('Thread not found', ['tid' => $tid]);
    }

    $fid = (int)$thread['fid'];
    if (!af_charactersheets_is_pending_forum($fid)) {
        af_charactersheets_deny('Thread not in pending forum', ['tid' => $tid, 'fid' => $fid]);
    }

    if (af_charactersheets_is_in_accepted_forum($fid)) {
        af_charactersheets_deny('Thread already in accepted forum', ['tid' => $tid, 'fid' => $fid]);
    }

    if (!af_charactersheets_user_can_accept($mybb->user, $fid)) {
        af_charactersheets_deny('User cannot accept', ['tid' => $tid, 'uid' => $mybb->user['uid'] ?? 0]);
    }

    $accept_row = af_charactersheets_get_accept_row($tid);
    $was_accepted = !empty($accept_row['accepted']);

    // 1) Убеждаемся, что лист существует (но НЕ пересоздаём, если уже есть)
    $sheet = ['ok' => true, 'slug' => ''];
    if (!empty($mybb->settings['af_charactersheets_sheet_autocreate'])) {
        $sheet = af_charactersheets_autocreate_sheet($tid, $thread);
    }

    // 2) Всегда публикуем сообщение принятия (и при первичном принятии, и при повторном)
    $message = af_charactersheets_build_accept_message($thread);

    require_once MYBB_ROOT . 'inc/datahandlers/post.php';
    $posthandler = new PostDataHandler('insert');
    $posthandler->action = 'reply';

    $subject = 'Re: ' . (string)$thread['subject'];

    $post_data = [
        'tid' => $tid,
        'fid' => $fid,
        'subject' => $subject,
        'uid' => (int)$mybb->user['uid'],
        'username' => (string)$mybb->user['username'],
        'message' => $message,
        'ipaddress' => $session->ipaddress ?? '',
        'longipaddress' => $session->packedip ?? '',

        // ВАЖНО (PHP8+): options обязателен массивом
        'options' => [
            'signature' => 0,
            'disablesmilies' => 0,
            'subscriptionmethod' => 0,
        ],
    ];

    $posthandler->set_data($post_data);

    if (!$posthandler->validate_post()) {
        af_charactersheets_log('Post validation failed', [
            'tid' => $tid,
            'errors' => $posthandler->get_friendly_errors(),
        ]);
        $msg = $lang->af_charactersheets_accept_error ?? 'Не удалось принять анкету.';
        redirect('showthread.php?tid=' . $tid, $msg);
    }

    $postinfo = $posthandler->insert_post();
    $accepted_pid = (int)($postinfo['pid'] ?? 0);
    if ($accepted_pid <= 0) {
        af_charactersheets_log('Post insert failed', ['tid' => $tid]);
        $msg = $lang->af_charactersheets_accept_error ?? 'Не удалось принять анкету.';
        redirect('showthread.php?tid=' . $tid, $msg);
    }

    // Обновляем строку: accepted_pid теперь “последнее сообщение принятия”
    af_charactersheets_upsert_accept_row($tid, [
        'uid' => (int)$thread['uid'],
        'accepted_pid' => $accepted_pid,
        // sheet_slug/sheet_created сохранятся из autocreate_sheet, если он отработал
    ]);


    if (!empty($mybb->settings['af_charactersheets_accept_move_thread'])) {
        $target_fid = (int)($mybb->settings['af_charactersheets_accepted_forum'] ?? 0);
        if ($target_fid > 0 && $target_fid !== $fid) {
            require_once MYBB_ROOT . 'inc/class_moderation.php';
            $moderation = new Moderation;
            $moderation->move_thread($tid, $target_fid, 0);
            $fid = $target_fid;
        }
    }

    if (!empty($mybb->settings['af_charactersheets_accept_close_thread'])) {
        require_once MYBB_ROOT . 'inc/class_moderation.php';
        $moderation = new Moderation;
        $moderation->close_threads([$tid]);
    }

    af_charactersheets_upsert_accept_row($tid, [
        'uid' => (int)$thread['uid'],
        'accepted' => 1,
        'accepted_by_uid' => (int)$mybb->user['uid'],
        'accepted_pid' => $accepted_pid,
        'accepted_at' => TIME_NOW,
    ]);

    if (!empty($mybb->settings['af_charactersheets_sheet_autocreate'])) {
        af_charactersheets_autocreate_sheet($tid, $thread);
    }

    $msg = $lang->af_charactersheets_accept_done ?? 'Анкета принята: тема закрыта и перенесена.';
    redirect('showthread.php?tid=' . $tid, $msg);
}

/* -------------------- ACCEPT LOGIC -------------------- */
function af_charactersheets_build_accept_message(array $thread): string
{
    global $mybb;

    $template = af_charactersheets_get_accept_template();
    if ($template === '') {
        $template = af_charactersheets_default_accept_template();
    }

    $tid = (int)($thread['tid'] ?? 0);
    $uid = (int)($thread['uid'] ?? 0);
    $username = (string)($thread['username'] ?? '');

    $thread_url = af_charactersheets_make_absolute_url(
        function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid)
    );
    $profile_url = af_charactersheets_make_absolute_url(
        function_exists('get_profile_link') ? get_profile_link($uid) : ('member.php?action=profile&uid=' . $uid)
    );

    // Ссылка на лист персонажа (мы добавим роут ниже)
    $sheet_slug = '';
    $row = af_charactersheets_get_accept_row($tid);
    if (!empty($row['sheet_slug'])) {
        $sheet_slug = (string)$row['sheet_slug'];
    } else {
        // если ещё нет — ожидаемо, но мы всё равно подставим будущий
        $sheet_slug = af_charactersheets_slugify((string)($thread['subject'] ?? ''), $tid);
    }
    $sheet_url = af_charactersheets_make_absolute_url('misc.php?action=af_charactersheet&slug=' . rawurlencode($sheet_slug));

    // Упоминание в BBCode (надёжно, если НЕ оборачивать в [html])
    $mention_bb = '[mention=' . $uid . ']' . $username . '[/mention]';

    $replacements = [
        '{mention}'     => $mention_bb,
        '{username}'    => $username,
        '{uid}'         => (string)$uid,
        '{thread_url}'  => $thread_url,
        '{profile_url}' => $profile_url,
        '{accepted_by}' => (string)($mybb->user['username'] ?? ''),
        '{sheet_url}'   => $sheet_url,
        '{sheet_slug}'  => $sheet_slug,
    ];

    $rendered = strtr($template, $replacements);

    // ВАЖНО: если ты хочешь, чтобы {mention} точно стал кликабельным — НЕ заворачиваем в [html]
    if (!empty($mybb->settings['af_charactersheets_accept_wrap_htmlbb'])) {
        // Оставляю как опцию, но предупреждаю: внутри [html] упоминания чаще всего не парсятся.
        // Лучше выключить настройку wrap_htmlbb для принятия.
        $rendered = "[html]\n" . $rendered . "\n[/html]";
    }

    return $rendered;
}

function af_charactersheets_autocreate_sheet(int $tid, array $thread): array
{
    $row = af_charactersheets_get_accept_row($tid);

    // Если лист уже был создан ранее — не трогаем, просто возвращаем
    if (!empty($row['sheet_created']) && !empty($row['sheet_slug'])) {
        return ['ok' => true, 'slug' => (string)$row['sheet_slug']];
    }

    // Если slug уже есть, даже без sheet_created — считаем, что это наш стабильный slug
    if (!empty($row['sheet_slug'])) {
        $slug = (string)$row['sheet_slug'];
        af_charactersheets_upsert_accept_row($tid, [
            'uid' => (int)($thread['uid'] ?? 0),
            'sheet_slug' => $slug,
            'sheet_created' => 1,
        ]);
        return ['ok' => true, 'slug' => $slug];
    }

    $slug = af_charactersheets_slugify((string)($thread['subject'] ?? ''), $tid);

    af_charactersheets_upsert_accept_row($tid, [
        'uid' => (int)($thread['uid'] ?? 0),
        'sheet_slug' => $slug,
        'sheet_created' => 1,
    ]);

    return ['ok' => true, 'slug' => $slug];
}


function af_charactersheets_get_by_slug(string $slug): array
{
    global $db;
    $slug = trim($slug);
    if ($slug === '') return [];

    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', "sheet_slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_render_sheet_page(string $slug): void
{
    global $db, $lang, $templates, $header, $headerinclude, $footer, $mybb;

    $row = af_charactersheets_get_by_slug($slug);
    if (empty($row)) {
        error_no_permission(); // или error("Not found")
        exit;
    }

    $tid = (int)($row['tid'] ?? 0);
    $uid = (int)($row['uid'] ?? 0);

    $thread = [];
    if ($tid > 0) {
        $thread = $db->fetch_array($db->simple_select('threads', 'tid,fid,subject', 'tid=' . $tid, ['limit' => 1]));
        if (!is_array($thread)) $thread = [];
    }

    $user = [];
    if ($uid > 0) {
        $user = $db->fetch_array($db->simple_select('users', 'uid,username', 'uid=' . $uid, ['limit' => 1]));
        if (!is_array($user)) $user = [];
    }

    $profile_url = function_exists('get_profile_link') ? get_profile_link($uid) : ('member.php?action=profile&uid=' . $uid);
    $thread_url = function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid);

    $atf_fields = af_charactersheets_get_atf_fields($tid);
    $atf_index = af_charactersheets_index_fields($atf_fields);

    $character_name = af_charactersheets_pick_field_value($atf_index, ['character_name', 'char_name', 'name']);
    if ($character_name === '') {
        $character_name = (string)($thread['subject'] ?? '');
    }
    if ($character_name === '') {
        $character_name = $user['username'] ?? 'Лист персонажа';
    }

    $sheet_title = htmlspecialchars_uni($character_name);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $sheet_base_html = af_charactersheets_build_base_html($character_name, $profile_url, $thread_url);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($atf_fields);
    $sheet_kb_html = af_charactersheets_build_kb_cards_html($atf_fields);
    $sheet_stats_html = af_charactersheets_build_stats_html($atf_index);
    $sheet_skills_html = af_charactersheets_build_skills_html($atf_index);
    $sheet_feats_html = af_charactersheets_build_feats_html($atf_index);
    $sheet_portrait_url = af_charactersheets_get_portrait_url($atf_index);

    $page_title = 'Лист персонажа';
    if (!empty($user['username'])) {
        $page_title .= ' — ' . $user['username'];
    } elseif (!empty($character_name)) {
        $page_title .= ' — ' . $character_name;
    }

    $assets = af_charactersheets_get_asset_urls();
    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=1.0.0" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=1.0.0"></script>' . "\n";

    $tplInner = $templates->get('charactersheet_inner');
    eval("\$sheet_inner = \"" . $tplInner . "\";");

    $ajax = (string)$mybb->get_input('ajax');
    if ($ajax === '1') {
        $tplModal = $templates->get('charactersheet_modal');
        eval("\$page = \"" . $tplModal . "\";");
    } else {
        $tplFull = $templates->get('charactersheet_fullpage');
        eval("\$page = \"" . $tplFull . "\";");
    }

    output_page($page);
    exit;
}

/* -------------------- HELPERS -------------------- */

function af_charactersheets_get_accept_row(int $tid): array
{
    global $db;

    if ($tid <= 0) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_upsert_accept_row(int $tid, array $data): void
{
    global $db;

    if ($tid <= 0) {
        return;
    }

    $row = af_charactersheets_get_accept_row($tid);
    $defaults = [
        'uid' => 0,
        'accepted' => 0,
        'accepted_by_uid' => null,
        'accepted_pid' => null,
        'accepted_at' => 0,
        'sheet_slug' => null,
        'sheet_created' => 0,
    ];
    $payload = array_merge($defaults, $row ?: [], $data, ['tid' => $tid]);

    if ($row) {
        $db->update_query(AF_CS_TABLE, af_charactersheets_db_escape_array($payload), 'tid=' . $tid);
    } else {
        $db->insert_query(AF_CS_TABLE, af_charactersheets_db_escape_array($payload));
    }
}

function af_charactersheets_db_escape_array(array $data): array
{
    global $db;

    $escaped = [];
    foreach ($data as $key => $value) {
        if (is_null($value)) {
            $escaped[$key] = null;
            continue;
        }
        if (is_int($value) || is_float($value)) {
            $escaped[$key] = $value;
            continue;
        }
        $escaped[$key] = $db->escape_string((string)$value);
    }

    return $escaped;
}

function af_charactersheets_is_accepted(int $tid): bool
{
    $row = af_charactersheets_get_accept_row($tid);
    return !empty($row['accepted']);
}

function af_charactersheets_postbit_button(array &$post): void
{
    global $mybb, $templates, $lang;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $post['af_cs_plaque'] = '';

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

    $tpl = $templates->get('postbit_plaque');
    eval("\$plaque_html = \"" . $tpl . "\";");

    $post['af_cs_plaque'] = $plaque_html;

    $GLOBALS['af_charactersheets_needs_assets'] = true;
    $GLOBALS['af_charactersheets_needs_modal'] = true;
}

function af_charactersheets_is_pending_forum(int $fid): bool
{
    global $mybb;

    $pending = af_charactersheets_csv_to_ids($mybb->settings['af_charactersheets_pending_forums'] ?? '');
    if (!$pending) {
        return false;
    }
    return in_array($fid, $pending, true);
}

function af_charactersheets_is_in_accepted_forum(int $fid): bool
{
    global $mybb;
    $accepted_fid = (int)($mybb->settings['af_charactersheets_accepted_forum'] ?? 0);
    return $accepted_fid > 0 && $fid === $accepted_fid;
}

function af_charactersheets_user_can_accept(array $user, int $fid): bool
{
    global $mybb;

    if (empty($user['uid'])) {
        return false;
    }

    $groups = af_charactersheets_csv_to_ids($mybb->settings['af_charactersheets_accept_groups'] ?? '');
    if (!$groups) {
        return false;
    }

    $usergroups = [(int)($user['usergroup'] ?? 0)];
    $additional = af_charactersheets_csv_to_ids($user['additionalgroups'] ?? '');
    $usergroups = array_unique(array_filter(array_merge($usergroups, $additional)));

    $allowed = array_intersect($groups, $usergroups);
    if (empty($allowed)) {
        return false;
    }

    //if (function_exists('is_moderator')) {
    //    if (!is_moderator($fid, 'canmanagethreads')) {
    //        return false;
    //    }
    //}

    return true;
}

function af_charactersheets_csv_to_ids(string $csv): array
{
    $parts = array_filter(array_map('trim', explode(',', $csv)), static function ($val) {
        return $val !== '';
    });

    $ids = [];
    foreach ($parts as $part) {
        $ids[] = (int)$part;
    }

    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}

function af_charactersheets_make_absolute_url(string $url): string
{
    global $mybb;

    if ($url === '') {
        return $url;
    }

    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    $base = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($base === '') {
        return $url;
    }

    return $base . '/' . ltrim($url, '/');
}

function af_charactersheets_ensure_schema(): void
{
    global $db;

    if (!$db->table_exists(AF_CS_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_TABLE." (
              tid INT UNSIGNED NOT NULL,
              uid INT UNSIGNED NOT NULL,
              accepted TINYINT(1) NOT NULL DEFAULT 0,
              accepted_by_uid INT UNSIGNED DEFAULT NULL,
              accepted_pid INT UNSIGNED DEFAULT NULL,
              accepted_at INT UNSIGNED NOT NULL DEFAULT 0,
              sheet_slug VARCHAR(120) DEFAULT NULL,
              sheet_created TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (tid),
              KEY uid (uid),
              KEY accepted (accepted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    if (!$db->table_exists(AF_CS_CONFIG_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_CONFIG_TABLE." (
              id TINYINT(1) NOT NULL,
              accept_post_template MEDIUMTEXT NOT NULL,
              updated_at INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $exists = (int)$db->fetch_field($db->simple_select(AF_CS_CONFIG_TABLE, 'id', 'id=1', ['limit' => 1]), 'id');
    if (!$exists) {
        $db->insert_query(AF_CS_CONFIG_TABLE, [
            'id' => 1,
            'accept_post_template' => $db->escape_string(af_charactersheets_default_accept_template()),
            'updated_at' => TIME_NOW,
        ]);
    }
}

function af_charactersheets_default_accept_template(): string
{
    return "Добро пожаловать, {mention}!\n\nРады видеть тебя в Warp Rift. Вот полезные ссылки:\n- Правила: /rules.php\n- Лор: /misc.php?action=af_kb&type=...\n- Вопросы: /forumdisplay.php?fid=...\n\nЛист персонажа: {sheet_url}";
}

function af_charactersheets_get_accept_template(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    global $db;
    if (!$db->table_exists(AF_CS_CONFIG_TABLE)) {
        $cache = '';
        return $cache;
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_CONFIG_TABLE, 'accept_post_template', 'id=1', ['limit' => 1]));
    $cache = is_array($row) ? (string)($row['accept_post_template'] ?? '') : '';
    return $cache;
}

function af_charactersheets_set_accept_template(string $template): void
{
    global $db;
    if (!$db->table_exists(AF_CS_CONFIG_TABLE)) {
        return;
    }

    $db->update_query(AF_CS_CONFIG_TABLE, [
        'accept_post_template' => $db->escape_string($template),
        'updated_at' => TIME_NOW,
    ], 'id=1');
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
    if (!$db->table_exists(AF_CS_TABLE)) {
        $cache[$uid] = '';
        return '';
    }

    $row = $db->fetch_array($db->simple_select(
        AF_CS_TABLE,
        'sheet_slug',
        "uid=" . (int)$uid . " AND sheet_created=1 AND sheet_slug<>''",
        ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]
    ));
    $slug = is_array($row) ? (string)($row['sheet_slug'] ?? '') : '';
    $cache[$uid] = $slug;
    return $slug;
}

function af_charactersheets_get_asset_urls(): array
{
    global $mybb;
    $baseUrl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    return [
        'css' => $baseUrl . '/inc/plugins/advancedfunctionality/addons/' . AF_CS_ID . '/assets/charactersheets.css',
        'js' => $baseUrl . '/inc/plugins/advancedfunctionality/addons/' . AF_CS_ID . '/assets/charactersheets.js',
    ];
}

function af_charactersheets_inject_assets(string $page): string
{
    if (strpos($page, AF_CS_ASSET_MARK) !== false) {
        return $page;
    }

    $assets = af_charactersheets_get_asset_urls();
    $inject = "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=1.0.0" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=1.0.0"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $inject . '</head>', $page, 1);
        return $page;
    }

    return $inject . $page;
}

function af_charactersheets_inject_modal(string $page): string
{
    global $lang;

    if (strpos($page, AF_CS_MODAL_MARK) !== false) {
        return $page;
    }

    if (!isset($lang->af_charactersheets_name)) {
        af_charactersheets_lang();
    }

    $modal_title = htmlspecialchars_uni($lang->af_charactersheets_sheet_modal_title ?? 'Лист персонажа');
    $modal_close_label = htmlspecialchars_uni($lang->af_charactersheets_sheet_modal_close ?? 'Закрыть');

    $modal_html = '<div class="af-cs-modal" data-afcs-modal>'
        . '<div class="af-cs-modal__backdrop" data-afcs-close></div>'
        . '<div class="af-cs-modal__dialog">'
        . '<div class="af-cs-modal__header">'
        . '<div class="af-cs-modal__title">' . $modal_title . '</div>'
        . '<button class="af-cs-modal__close" type="button" data-afcs-close aria-label="' . $modal_close_label . '">×</button>'
        . '</div>'
        . '<div class="af-cs-modal__body">'
        . '<iframe class="af-cs-modal__frame" data-afcs-frame title="' . $modal_title . '"></iframe>'
        . '</div>'
        . '</div>'
        . '</div>';

    $inject = "\n" . AF_CS_MODAL_MARK . "\n" . $modal_html . "\n";

    if (stripos($page, '</body>') !== false) {
        $page = preg_replace('~</body>~i', $inject . '</body>', $page, 1);
        return $page;
    }

    return $page . $inject;
}

function af_charactersheets_ensure_postbit_placeholder(): void
{
    global $db;

    $needle = '{$post[\'af_cs_plaque\']}';
    $q = $db->simple_select('templates', 'tid,template', "title='postbit_classic'");

    while ($row = $db->fetch_array($q)) {
        $tid = (int)$row['tid'];
        $tpl = (string)$row['template'];

        if ($tid <= 0 || $tpl === '') {
            continue;
        }

        if (strpos($tpl, $needle) !== false) {
            continue;
        }

        $count = 0;
        $pattern = '#(<div[^>]*class="post_author[^"]*scaleimages[^"]*"[^>]*>)(.*?)(</div>\s*</td>)#s';
        $new = preg_replace($pattern, '$1$2' . "\n" . $needle . "\n" . '$3', $tpl, 1, $count);

        if ($count && is_string($new) && $new !== '' && $new !== $tpl) {
            $db->update_query('templates', ['template' => $db->escape_string($new)], 'tid=' . $tid);
        }
    }

    if (function_exists('cache_templatesets')) {
        cache_templatesets();
    }
}

function af_charactersheets_templates_install_or_update(): void
{
    global $db;

    if (!is_dir(AF_CS_TPL_DIR)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(AF_CS_TPL_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) !== 'html') {
            continue;
        }

        $path = $file->getPathname();
        $relative = ltrim(str_replace(AF_CS_TPL_DIR, '', $path), DIRECTORY_SEPARATOR);
        $basename = basename($path, '.html');
        if ($basename === '') {
            continue;
        }

        if (strpos($relative, 'blocks' . DIRECTORY_SEPARATOR) === 0) {
            $name = 'charactersheet_' . $basename;
        } else {
            $name = $basename;
        }

        $tpl = @file_get_contents($path);
        if ($tpl === false) {
            continue;
        }

        $title = $db->escape_string($name);
        $existing = $db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'", ['limit' => 1]);
        $tid = (int)$db->fetch_field($existing, 'tid');

        $row = [
            'title'    => $title,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => '1839',
            'dateline' => TIME_NOW,
        ];

        if ($tid) {
            $db->update_query('templates', $row, "tid='{$tid}'");
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

function af_charactersheets_get_atf_fields(int $tid): array
{
    global $db;

    if ($tid <= 0) {
        return [];
    }

    if (!$db->table_exists('af_atf_fields') || !$db->table_exists('af_atf_values')) {
        return [];
    }

    $fields = [];
    $q = $db->write_query("
        SELECT f.fieldid, f.name, f.title, f.type, f.options, v.value
        FROM " . TABLE_PREFIX . "af_atf_values v
        INNER JOIN " . TABLE_PREFIX . "af_atf_fields f ON f.fieldid = v.fieldid
        WHERE v.tid = " . (int)$tid . " AND f.active = 1
        ORDER BY f.sortorder ASC, f.fieldid ASC
    ");

    while ($row = $db->fetch_array($q)) {
        $value = (string)($row['value'] ?? '');
        if ($value === '') {
            continue;
        }

        $fields[] = [
            'name' => (string)($row['name'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'options' => (string)($row['options'] ?? ''),
            'value' => $value,
        ];
    }

    foreach ($fields as &$field) {
        $field['value_label'] = af_charactersheets_resolve_field_label($field);
    }
    unset($field);

    return $fields;
}

function af_charactersheets_resolve_field_label(array $field): string
{
    $type = strtolower((string)($field['type'] ?? ''));
    $value = (string)($field['value'] ?? '');

    if ($value === '') {
        return '';
    }

    if (in_array($type, ['select', 'radio'], true)) {
        if (function_exists('af_atf_kb_resolve_label')) {
            return af_atf_kb_resolve_label((string)($field['options'] ?? ''), $value);
        }
        $opts = af_charactersheets_parse_options((string)($field['options'] ?? ''));
        return $opts[$value] ?? $value;
    }

    if ($type === 'checkbox') {
        return $value === '1' ? 'Да' : 'Нет';
    }

    return $value;
}

function af_charactersheets_parse_options(string $raw): array
{
    if (function_exists('af_atf_parse_options')) {
        return af_atf_parse_options($raw);
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_filter(array_map('trim', explode("\n", $raw)));

    $out = [];
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === '') {
                $k = $v;
            }
            $out[$k] = ($v === '') ? $k : $v;
        } else {
            $out[$line] = $line;
        }
    }
    return $out;
}

function af_charactersheets_index_fields(array $fields): array
{
    $index = [];
    foreach ($fields as $field) {
        $name = strtolower((string)($field['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $index[$name] = $field;
    }
    return $index;
}

function af_charactersheets_pick_field_value(array $index, array $names, bool $label = true): string
{
    foreach ($names as $name) {
        $key = strtolower((string)$name);
        if (!isset($index[$key])) {
            continue;
        }
        $field = $index[$key];
        $value = $label ? (string)($field['value_label'] ?? '') : (string)($field['value'] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function af_charactersheets_to_number(string $value): ?float
{
    $value = str_replace(',', '.', $value);
    $value = preg_replace('~[^0-9.\-]+~', '', $value);
    if ($value === '' || $value === '-' || $value === '.') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function af_charactersheets_get_portrait_url(array $index): string
{
    $url = af_charactersheets_pick_field_value($index, [
        'character_image',
        'character_avatar',
        'character_portrait',
        'character_face',
        'portrait',
        'avatar',
        'image',
    ]);

    if ($url === '') {
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="480" viewBox="0 0 360 480">'
            . '<rect width="360" height="480" fill="#2b2b2b"/>'
            . '<circle cx="180" cy="170" r="70" fill="#3d3d3d"/>'
            . '<rect x="70" y="270" width="220" height="140" rx="24" fill="#3d3d3d"/>'
            . '</svg>'
        );
    }

    return $url;
}

function af_charactersheets_build_stats_html(array $index): string
{
    $level_value = af_charactersheets_pick_field_value($index, ['character_level', 'level'], false);
    $level = af_charactersheets_to_number($level_value);

    $hp_current_value = af_charactersheets_pick_field_value($index, ['character_hp', 'hp', 'hp_current'], false);
    $hp_max_value = af_charactersheets_pick_field_value($index, ['character_hp_max', 'hp_max', 'hp_total'], false);
    $hp_current = af_charactersheets_to_number($hp_current_value);
    $hp_max = af_charactersheets_to_number($hp_max_value);

    $energy_current_value = af_charactersheets_pick_field_value($index, ['character_energy', 'energy', 'energy_current'], false);
    $energy_max_value = af_charactersheets_pick_field_value($index, ['character_energy_max', 'energy_max', 'energy_total'], false);
    $energy_current = af_charactersheets_to_number($energy_current_value);
    $energy_max = af_charactersheets_to_number($energy_max_value);

    $ac_value = af_charactersheets_pick_field_value($index, ['character_ac', 'ac', 'armor_class'], false);
    $balance_value = af_charactersheets_pick_field_value($index, ['character_balance', 'balance', 'currency', 'credits', 'gold'], false);

    $ac = af_charactersheets_to_number($ac_value);
    $balance = af_charactersheets_to_number($balance_value);

    $stats = [];
    $stats[] = af_charactersheets_render_stat_bar(
        'Уровень',
        $level !== null ? (string)(int)$level : 'нет данных',
        $level !== null ? min(100, max(0, (int)$level * 5)) : 0
    );
    $stats[] = af_charactersheets_render_stat_bar(
        'HP',
        ($hp_current !== null && $hp_max !== null) ? (int)$hp_current . ' / ' . (int)$hp_max : 'нет данных',
        ($hp_current !== null && $hp_max) ? min(100, max(0, (int)round(($hp_current / $hp_max) * 100))) : 0
    );
    $stats[] = af_charactersheets_render_stat_bar(
        'Energy',
        ($energy_current !== null && $energy_max !== null) ? (int)$energy_current . ' / ' . (int)$energy_max : 'нет данных',
        ($energy_current !== null && $energy_max) ? min(100, max(0, (int)round(($energy_current / $energy_max) * 100))) : 0
    );
    $stats[] = af_charactersheets_render_stat_value('AC', $ac !== null ? (string)(int)$ac : 'нет данных');
    $stats[] = af_charactersheets_render_stat_value('Баланс', $balance !== null ? (string)(int)$balance : 'нет данных');

    $stats_html = implode('', $stats);
    global $templates;
    $tpl = $templates->get('charactersheet_stats_bars');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_render_stat_bar(string $label, string $value, int $percent): string
{
    $percent = max(0, min(100, $percent));
    return '<div class="af-cs-stat">'
        . '<div class="af-cs-stat__label">' . htmlspecialchars_uni($label) . '</div>'
        . '<div class="af-cs-stat__value">' . htmlspecialchars_uni($value) . '</div>'
        . '<div class="af-cs-progress" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">'
        . '<span style="width:' . $percent . '%"></span>'
        . '</div>'
        . '</div>';
}

function af_charactersheets_render_stat_value(string $label, string $value): string
{
    return '<div class="af-cs-stat">'
        . '<div class="af-cs-stat__label">' . htmlspecialchars_uni($label) . '</div>'
        . '<div class="af-cs-stat__value">' . htmlspecialchars_uni($value) . '</div>'
        . '</div>';
}

function af_charactersheets_build_skills_html(array $index): string
{
    $skills_html = '<div class="af-cs-skill-item">'
        . '<span>Нет данных по навыкам</span>'
        . '<a href="#" class="af-cs-skill-link">Улучшить</a>'
        . '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_skills');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_feats_html(array $index): string
{
    $feats_html = '<div class="af-cs-feat-item">'
        . '<span>Нет данных по скиллам</span>'
        . '<a href="#" class="af-cs-feat-link">Открыть магазин</a>'
        . '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_feats');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_parse_bbcode(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    require_once MYBB_ROOT . 'inc/class_parser.php';
    $parser = new postParser;
    $options = [
        'allow_html' => 0,
        'allow_mycode' => 1,
        'allow_smilies' => 0,
        'allow_imgcode' => 1,
        'filter_badwords' => 1,
        'nl2br' => 1,
    ];

    return $parser->parse_message($text, $options);
}

function af_charactersheets_kb_mapping(): array
{
    return [
        'character_race' => [
            'type' => 'race',
            'label' => 'Раса',
        ],
        'character_class' => [
            'type' => 'class',
            'label' => 'Класс',
        ],
        'character_theme' => [
            'type' => 'theme',
            'label' => 'Тема',
        ],
    ];
}

function af_charactersheets_kb_get_entry(string $type, string $key): array
{
    if (function_exists('af_atf_kb_get_entry')) {
        return af_atf_kb_get_entry($type, $key);
    }

    global $db;
    if (!$db->table_exists('af_kb_entries')) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(
        'af_kb_entries',
        '*',
        "type='" . $db->escape_string($type) . "' AND `key`='" . $db->escape_string($key) . "' AND active=1",
        ['limit' => 1]
    ));

    return is_array($row) ? $row : [];
}

function af_charactersheets_kb_pick_text(array $row, string $field): string
{
    if (function_exists('af_kb_pick_text')) {
        return af_kb_pick_text($row, $field);
    }

    $suffix = af_charactersheets_is_ru() ? '_ru' : '_en';
    $key = $field . $suffix;
    $value = (string)($row[$key] ?? '');
    if ($value === '') {
        $fallback = (string)($row[$field . '_ru'] ?? '');
        if ($fallback === '') {
            $fallback = (string)($row[$field . '_en'] ?? '');
        }
        return $fallback;
    }

    return $value;
}

function af_charactersheets_is_ru(): bool
{
    if (function_exists('af_kb_is_ru')) {
        return af_kb_is_ru();
    }

    global $lang;
    return isset($lang->language) && $lang->language === 'russian';
}

function af_charactersheets_build_base_html(string $character_name, string $profile_url, string $thread_url): string
{
    $items = [];

    if ($character_name !== '') {
        $items[] = '<li><span class="af-cs-meta-key">Имя</span><span class="af-cs-meta-value">' . htmlspecialchars_uni($character_name) . '</span></li>';
    }

    if ($profile_url !== '') {
        $items[] = '<li><span class="af-cs-meta-key">Профиль</span><span class="af-cs-meta-value"><a href="' . htmlspecialchars_uni($profile_url) . '">Открыть</a></span></li>';
    }

    if ($thread_url !== '') {
        $items[] = '<li><span class="af-cs-meta-key">Анкета</span><span class="af-cs-meta-value"><a href="' . htmlspecialchars_uni($thread_url) . '">Открыть</a></span></li>';
    }

    if (empty($items)) {
        return '<div class="af-cs-muted">Нет данных</div>';
    }

    return '<ul class="af-cs-meta-list">' . implode('', $items) . '</ul>';
}

function af_charactersheets_build_attributes_html(array $fields): string
{
    $items = [];

    foreach ($fields as $field) {
        $type = strtolower((string)($field['type'] ?? ''));
        $name = (string)($field['name'] ?? '');
        $label = (string)($field['title'] ?? $name);
        $value = (string)($field['value_label'] ?? '');

        if ($value === '') {
            continue;
        }

        if ($type !== 'number' && !preg_match('/^(attr_|attribute_|stat_)/i', $name)) {
            continue;
        }

        $items[] = '<li><span class="af-cs-key">' . htmlspecialchars_uni($label) . '</span><span class="af-cs-value">' . htmlspecialchars_uni($value) . '</span></li>';
    }

    if (empty($items)) {
        return '<div class="af-cs-muted">Нет данных</div>';
    }

    $attributes_html = '<ul>' . implode('', $items) . '</ul>';

    global $templates;
    $tpl = $templates->get('charactersheet_attributes');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_kb_cards_html(array $fields): string
{
    $mapped = af_charactersheets_kb_mapping();
    $cards = [];
    $index = af_charactersheets_index_fields($fields);

    foreach ($mapped as $fieldName => $data) {
        $kbType = (string)$data['type'];
        $label = (string)$data['label'];
        $field = $index[$fieldName] ?? [];

        $key = (string)($field['value'] ?? '');
        $entry = $key !== '' ? af_charactersheets_kb_get_entry($kbType, $key) : [];
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        if ($title === '') {
            $title = (string)($field['value_label'] ?? '');
        }
        if ($title === '') {
            $title = $label;
        }

        $short = af_charactersheets_kb_pick_text($entry, 'short');
        $body = af_charactersheets_kb_pick_text($entry, 'body');

        $short_html = $short !== '' ? af_charactersheets_parse_bbcode($short) : '';
        $body_html = $body !== '' ? af_charactersheets_parse_bbcode($body) : '';

        if ($short_html === '' && $body_html === '' && $key === '') {
            $body_html = '<div class="af-cs-muted">Нет данных</div>';
        }

        $cards[] = '<div class="af-cs-kb-card">'
            . '<div class="af-cs-kb-title">' . htmlspecialchars_uni($title) . '</div>'
            . ($short_html !== '' ? '<div class="af-cs-kb-short">' . $short_html . '</div>' : '')
            . ($body_html !== '' ? '<div class="af-cs-kb-body">' . $body_html . '</div>' : '')
            . '</div>';
    }

    $rct_cards_html = implode('', $cards);

    global $templates;
    $tpl = $templates->get('charactersheet_rct_cards');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_slugify(string $text, int $tid): string
{
    $text = trim($text);
    if ($text !== '' && function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted)) {
            $text = $converted;
        }
    }

    $text = strtolower($text);
    $text = preg_replace('~[^a-z0-9]+~', '-', $text);
    $text = trim($text, '-');

    if ($text === '') {
        $text = 'thread';
    }

    if ($tid > 0 && strpos($text, (string)$tid) === false) {
        $text .= '-' . $tid;
    }

    return $text;
}

function af_charactersheets_log(string $message, array $context = []): void
{
    $payload = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    error_log('[AF CharacterSheets] ' . $message . ($payload ? ' | ' . $payload : ''));
}

function af_charactersheets_deny(string $message, array $context = []): void
{
    af_charactersheets_log($message, $context);
    error_no_permission();
}

function af_charactersheets_lang(): void
{
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang(AF_CS_ID);
    }
}

function af_charactersheets_ensure_group(string $name, string $title, string $desc): int
{
    global $db;
    $q = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) {
        return $gid;
    }

    $max = $db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $db->insert_query('settinggroups', [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder' => $disp,
        'isdefault' => 0,
    ]);

    return (int)$db->insert_id();
}

function af_charactersheets_ensure_setting(
    int $gid,
    string $name,
    string $title,
    string $desc,
    string $type,
    string $value,
    int $order
): void {
    global $db;
    $q = $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'");
    $sid = (int)$db->fetch_field($q, 'sid');

    $row = [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value' => $db->escape_string($value),
        'disporder' => $order,
        'gid' => $gid,
    ];

    if ($sid) {
        $db->update_query('settings', $row, 'sid=' . $sid);
    } else {
        $db->insert_query('settings', $row);
    }
}
