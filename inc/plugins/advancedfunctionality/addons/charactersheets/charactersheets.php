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
const AF_CS_SHEETS_TABLE = 'af_cs_sheets';
const AF_CS_EXP_LEDGER_TABLE = 'af_cs_exp_ledger';
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
    if ($db->table_exists(AF_CS_SHEETS_TABLE)) {
        $db->drop_table(AF_CS_SHEETS_TABLE);
    }
    if ($db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        $db->drop_table(AF_CS_EXP_LEDGER_TABLE);
    }

    $db->delete_query('settings', "name IN (
        'af_charactersheets_enabled',
        'af_charactersheets_accept_groups',
        'af_charactersheets_pending_forums',
        'af_charactersheets_accepted_forum',
        'af_charactersheets_accept_wrap_htmlbb',
        'af_charactersheets_accept_close_thread',
        'af_charactersheets_accept_move_thread',
        'af_charactersheets_sheet_autocreate',
        'af_charactersheets_attr_pool_max',
        'af_charactersheets_attr_cap',
        'af_charactersheets_exp_per_char',
        'af_charactersheets_exp_on_register',
        'af_charactersheets_exp_on_accept',
        'af_charactersheets_level_cap',
        'af_charactersheets_level_req_base',
        'af_charactersheets_level_req_step',
        'af_charactersheets_attr_points_per_level',
        'af_charactersheets_skill_points_per_level',
        'af_charactersheets_exp_manual_groups'
    )");
    $db->delete_query('settinggroups', "name='af_charactersheets'");
    $db->delete_query('templates', "title LIKE 'charactersheets_%'");
    $db->delete_query('templates', "title IN ('charactersheet_fullpage','charactersheet_inner','charactersheet_modal','postbit_plaque','charactersheet_rct_cards','charactersheet_stats_bars','charactersheet_attributes','charactersheet_progress','charactersheet_skills','charactersheet_feats','charactersheets_catalog','charactersheets_catalog_card')");

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
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_attr_pool_max',
        $lang->af_charactersheets_attr_pool_max ?? 'Attribute pool max',
        $lang->af_charactersheets_attr_pool_max_desc ?? 'Maximum attribute points available for allocation.',
        'text',
        '10',
        20
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_attr_cap',
        $lang->af_charactersheets_attr_cap ?? 'Attribute cap',
        $lang->af_charactersheets_attr_cap_desc ?? 'Maximum final attribute value after bonuses (0 disables cap).',
        'text',
        '0',
        21
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_per_char',
        $lang->af_charactersheets_exp_per_char ?? 'EXP per character',
        $lang->af_charactersheets_exp_per_char_desc ?? 'Experience granted per post character.',
        'text',
        '0.02',
        30
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_on_register',
        $lang->af_charactersheets_exp_on_register ?? 'EXP on register',
        $lang->af_charactersheets_exp_on_register_desc ?? 'Experience granted after registration.',
        'text',
        '0',
        31
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_on_accept',
        $lang->af_charactersheets_exp_on_accept ?? 'EXP on accept',
        $lang->af_charactersheets_exp_on_accept_desc ?? 'Experience granted after sheet acceptance.',
        'text',
        '0',
        32
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_level_cap',
        $lang->af_charactersheets_level_cap ?? 'Level cap',
        $lang->af_charactersheets_level_cap_desc ?? 'Maximum level.',
        'text',
        '20',
        40
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_level_req_base',
        $lang->af_charactersheets_level_req_base ?? 'Level requirement base',
        $lang->af_charactersheets_level_req_base_desc ?? 'EXP required to reach level 2.',
        'text',
        '2000',
        41
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_level_req_step',
        $lang->af_charactersheets_level_req_step ?? 'Level requirement step',
        $lang->af_charactersheets_level_req_step_desc ?? 'Additional EXP required per level.',
        'text',
        '1000',
        42
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_attr_points_per_level',
        $lang->af_charactersheets_attr_points_per_level ?? 'Attribute points per level',
        $lang->af_charactersheets_attr_points_per_level_desc ?? 'Points granted on each level up.',
        'text',
        '0',
        43
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_skill_points_per_level',
        $lang->af_charactersheets_skill_points_per_level ?? 'Skill points per level',
        $lang->af_charactersheets_skill_points_per_level_desc ?? 'Skill points granted on each level up.',
        'text',
        '0',
        44
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_manual_groups',
        $lang->af_charactersheets_exp_manual_groups ?? 'EXP manual award groups',
        $lang->af_charactersheets_exp_manual_groups_desc ?? 'CSV group ids allowed to grant experience manually.',
        'text',
        '4,3,6',
        45
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
    $plugins->add_hook('member_do_register_end', 'af_charactersheets_member_do_register_end');
    $plugins->add_hook('post_do_newpost_end', 'af_charactersheets_post_do_newpost_end');
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
    if ($action === 'af_charactersheets') {
        af_charactersheets_lang();
        af_charactersheets_render_catalog_page();
        exit;
    }
    if ($action === 'af_charactersheet_api') {
        af_charactersheets_lang();
        af_charactersheets_handle_api();
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

    $exp_on_accept = (float)($mybb->settings['af_charactersheets_exp_on_accept'] ?? 0);
    if ($exp_on_accept > 0) {
        $sheet = af_charactersheets_get_sheet_by_tid($tid);
        if (!empty($sheet)) {
            af_charactersheets_grant_exp(
                (int)$sheet['id'],
                $exp_on_accept,
                'accept:' . $tid,
                'accept',
                ['tid' => $tid, 'accepted_by' => (int)$mybb->user['uid']]
            );
        }
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
        $existing = af_charactersheets_get_sheet_by_slug((string)$row['sheet_slug']);
        if (!empty($existing)) {
            return $existing;
        }
    }

    // Если slug уже есть, даже без sheet_created — считаем, что это наш стабильный slug
    if (!empty($row['sheet_slug'])) {
        $slug = (string)$row['sheet_slug'];
        $sheet = af_charactersheets_ensure_sheet($tid, (int)($thread['uid'] ?? 0), $slug);
        af_charactersheets_upsert_accept_row($tid, [
            'uid' => (int)($thread['uid'] ?? 0),
            'sheet_slug' => $slug,
            'sheet_created' => 1,
        ]);
        return !empty($sheet) ? $sheet : ['slug' => $slug];
    }

    $slug = af_charactersheets_slugify((string)($thread['subject'] ?? ''), $tid);

    $sheet = af_charactersheets_ensure_sheet($tid, (int)($thread['uid'] ?? 0), $slug);
    af_charactersheets_upsert_accept_row($tid, [
        'uid' => (int)($thread['uid'] ?? 0),
        'sheet_slug' => $slug,
        'sheet_created' => 1,
    ]);

    return !empty($sheet) ? $sheet : ['slug' => $slug];
}


function af_charactersheets_get_by_slug(string $slug): array
{
    global $db;
    $slug = trim($slug);
    if ($slug === '') return [];

    $slug_esc = $db->escape_string($slug);
    if ($db->table_exists(AF_CS_SHEETS_TABLE)) {
        $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', "slug='{$slug_esc}'", ['limit' => 1]));
        if (is_array($row) && !empty($row)) {
            return $row;
        }
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', "sheet_slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_render_sheet_page(string $slug): void
{
    global $db, $lang, $templates, $header, $headerinclude, $footer, $mybb;

    $accept_row = af_charactersheets_get_accept_row_by_slug($slug);
    if (empty($accept_row)) {
        error_no_permission(); // или error("Not found")
        exit;
    }

    $tid = (int)($accept_row['tid'] ?? 0);
    $uid = (int)($accept_row['uid'] ?? 0);

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
    $profile_url = html_entity_decode($profile_url, ENT_QUOTES, 'UTF-8');
    $thread_url = function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid);

    $atf_fields = af_charactersheets_get_atf_fields($tid);
    $atf_index = af_charactersheets_index_fields($atf_fields);

    $character_name_en = af_charactersheets_pick_field_value($atf_index, ['character_name_en', 'character_name', 'char_name', 'name']);
    if ($character_name_en === '') {
        $character_name_en = (string)($thread['subject'] ?? '');
    }
    if ($character_name_en === '') {
        $character_name_en = $user['username'] ?? 'Лист персонажа';
    }

    $character_name_ru = af_charactersheets_pick_field_value($atf_index, ['character_name_ru']);
    $character_nicknames = af_charactersheets_pick_field_value($atf_index, ['character_nicknames', 'character_nickname', 'nickname']);

    $sheet = af_charactersheets_get_sheet_by_slug($slug);
    if (empty($sheet)) {
        $sheet = af_charactersheets_autocreate_sheet($tid, $thread);
    }

    $sheet_view = af_charactersheets_compute_sheet_view($sheet);
    $can_edit_sheet = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_award_exp = af_charactersheets_user_can_award_exp($mybb->user ?? []);

    $sheet_title = htmlspecialchars_uni($character_name_en);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $sheet_base_html = af_charactersheets_build_base_html($profile_url, $thread_url);
    $sheet_info_table_html = af_charactersheets_build_info_table_html($atf_index);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($sheet_view, $can_edit_sheet);
    $sheet_bonus_html = af_charactersheets_build_bonus_html($atf_index);
    $sheet_skills_html = af_charactersheets_build_skills_html($atf_index);
    $sheet_feats_html = af_charactersheets_build_feats_html($atf_index);
    $sheet_inventory_html = af_charactersheets_build_inventory_html();
    $sheet_augments_html = af_charactersheets_build_augments_html();
    $sheet_mechanics_html = af_charactersheets_build_mechanics_html();
    $sheet_progress_html = af_charactersheets_build_progress_html($sheet_view, $sheet, $can_award_exp);
    $sheet_portrait_url = af_charactersheets_get_portrait_url($atf_index);
    $sheet_level_value = (int)($sheet_view['level'] ?? 1);
    $sheet_level_percent = (int)($sheet_view['level_percent'] ?? 0);
    $sheet_level_exp_label = htmlspecialchars_uni((string)($sheet_view['level_exp_label'] ?? ''));
    $sheet_name_ru = htmlspecialchars_uni($character_name_ru !== '' ? $character_name_ru : '—');
    $sheet_nicknames = htmlspecialchars_uni($character_nicknames !== '' ? $character_nicknames : '—');
    $sheet_id = (int)($sheet['id'] ?? 0);
    $sheet_post_key = htmlspecialchars_uni($mybb->post_code);

    $page_title = 'Лист персонажа';
    if (!empty($user['username'])) {
        $page_title .= ' — ' . $user['username'];
    } elseif (!empty($character_name)) {
        $page_title .= ' — ' . $character_name;
    }

    $assets = af_charactersheets_get_asset_urls();
    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=1.1.0" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=1.1.0"></script>' . "\n";

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

function af_charactersheets_get_accept_row_by_slug(string $slug): array
{
    global $db;

    $slug = trim($slug);
    if ($slug === '') {
        return [];
    }

    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', "sheet_slug='{$slug_esc}'", ['limit' => 1]));
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

function af_charactersheets_json_decode(string $raw): array
{
    if (function_exists('af_kb_decode_json')) {
        $decoded = af_kb_decode_json($raw);
        return is_array($decoded) ? $decoded : [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function af_charactersheets_json_encode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function af_charactersheets_default_attributes(): array
{
    return [
        'str' => 0,
        'dex' => 0,
        'con' => 0,
        'int' => 0,
        'wis' => 0,
        'cha' => 0,
    ];
}

function af_charactersheets_get_sheet_by_id(int $sheet_id): array
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'id=' . $sheet_id, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_tid(int $tid): array
{
    global $db;

    if ($tid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_uid(int $uid): array
{
    global $db;

    if ($uid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'uid=' . $uid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_ensure_sheet(int $tid, int $uid, string $slug): array
{
    global $db, $mybb;

    if (!$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $existing = [];
    if ($uid > 0) {
        $existing = af_charactersheets_get_sheet_by_uid($uid);
    }
    if (empty($existing) && $tid > 0) {
        $existing = af_charactersheets_get_sheet_by_tid($tid);
    }

    if (!empty($existing)) {
        $updates = [];
        if ($slug !== '' && (string)($existing['slug'] ?? '') !== $slug) {
            $updates['slug'] = $slug;
        }
        if ($tid > 0 && (int)($existing['tid'] ?? 0) !== $tid) {
            $updates['tid'] = $tid;
        }
        if ($uid > 0 && (int)($existing['uid'] ?? 0) !== $uid) {
            $updates['uid'] = $uid;
        }
        if ($updates) {
            $updates['updated_at'] = TIME_NOW;
            $db->update_query(AF_CS_SHEETS_TABLE, af_charactersheets_db_escape_array($updates), 'id=' . (int)$existing['id']);
            $existing = af_charactersheets_get_sheet_by_id((int)$existing['id']);
        }
        return $existing;
    }

    $base = [
        'race_key' => '',
        'class_key' => '',
        'theme_key' => '',
        'attributes_base' => af_charactersheets_default_attributes(),
    ];

    if ($tid > 0) {
        $fields = af_charactersheets_get_atf_fields($tid);
        $index = af_charactersheets_index_fields($fields);
        $base['race_key'] = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
        $base['class_key'] = af_charactersheets_pick_field_value($index, ['character_class', 'class'], false);
        $base['theme_key'] = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
    }

    $build = [
        'attributes_allocated' => af_charactersheets_default_attributes(),
        'choices' => [],
        'skills' => [],
        'inventory' => [],
    ];

    $progress = [
        'level' => 1,
        'exp' => 0,
        'attr_points_free' => 0,
        'skill_points_free' => 0,
    ];

    $row = [
        'uid' => $uid,
        'tid' => $tid,
        'slug' => $slug,
        'base_json' => $db->escape_string(af_charactersheets_json_encode($base)),
        'build_json' => $db->escape_string(af_charactersheets_json_encode($build)),
        'progress_json' => $db->escape_string(af_charactersheets_json_encode($progress)),
        'updated_at' => TIME_NOW,
    ];

    $id = (int)$db->insert_query(AF_CS_SHEETS_TABLE, $row);
    if ($id <= 0) {
        return [];
    }
    $sheet = af_charactersheets_get_sheet_by_id($id);

    $exp_on_register = (float)($mybb->settings['af_charactersheets_exp_on_register'] ?? 0);
    if ($exp_on_register > 0 && $uid > 0) {
        af_charactersheets_grant_exp(
            $id,
            $exp_on_register,
            'register:' . $uid,
            'register',
            ['uid' => $uid]
        );
        $sheet = af_charactersheets_get_sheet_by_id($id);
    }

    return $sheet;
}

function af_charactersheets_update_sheet_json(int $sheet_id, array $base, array $build, array $progress): void
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return;
    }

    $db->update_query(
        AF_CS_SHEETS_TABLE,
        af_charactersheets_db_escape_array([
            'base_json' => af_charactersheets_json_encode($base),
            'build_json' => af_charactersheets_json_encode($build),
            'progress_json' => af_charactersheets_json_encode($progress),
            'updated_at' => TIME_NOW,
        ]),
        'id=' . $sheet_id
    );
}

function af_charactersheets_user_can_edit_sheet(array $sheet, array $user): bool
{
    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if (!empty($user['uid']) && (int)($sheet['uid'] ?? 0) === $uid) {
        return true;
    }

    if (function_exists('is_moderator')) {
        return is_moderator((int)($sheet['tid'] ?? 0), 'canmoderate');
    }

    return false;
}

function af_charactersheets_user_can_award_exp(array $user): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if (!empty($user['issupermod']) || !empty($user['cancp'])) {
        return true;
    }

    $groups = af_charactersheets_csv_to_ids($mybb->settings['af_charactersheets_exp_manual_groups'] ?? '');
    if (!$groups) {
        return false;
    }

    return is_member($groups, $user);
}

function af_charactersheets_get_attribute_labels(): array
{
    return [
        'str' => 'Сила',
        'dex' => 'Ловкость',
        'con' => 'Конституция',
        'int' => 'Интеллект',
        'wis' => 'Мудрость',
        'cha' => 'Харизма',
    ];
}

function af_charactersheets_kb_get_blocks(array $entry): array
{
    $blocks = [];
    if (empty($entry['id'])) {
        return $blocks;
    }

    global $db;
    if ($db->table_exists('af_kb_blocks')) {
        $q = $db->simple_select('af_kb_blocks', '*', 'entry_id=' . (int)$entry['id']);
        while ($row = $db->fetch_array($q)) {
            if (!is_array($row)) {
                continue;
            }
            $blocks[] = $row;
        }
    }

    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    if (!empty($meta['blocks']) && is_array($meta['blocks'])) {
        foreach ($meta['blocks'] as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }
    }

    return $blocks;
}

function af_charactersheets_normalize_modifiers(string $type, string $key): array
{
    $entry = af_charactersheets_kb_get_entry($type, $key);
    if (empty($entry)) {
        return [];
    }

    $modifiers = [];
    $attributes = af_charactersheets_default_attributes();

    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $metaSets = [];
    if (!empty($meta['modifiers']) && is_array($meta['modifiers'])) {
        $metaSets[] = $meta['modifiers'];
    }
    if (!empty($meta['bonuses']) && is_array($meta['bonuses'])) {
        $metaSets[] = $meta['bonuses'];
    }
    foreach (['stats', 'attributes'] as $metaKey) {
        if (!empty($meta[$metaKey]) && is_array($meta[$metaKey])) {
            foreach ($meta[$metaKey] as $stat => $value) {
                if (array_key_exists($stat, $attributes)) {
                    $modifiers[] = [
                        'id' => $type . ':' . $key . ':' . $stat,
                        'source' => $type,
                        'type' => 'attribute_bonus',
                        'target' => $stat,
                        'value' => (float)$value,
                        'requires_choice' => false,
                    ];
                }
            }
        }
    }

    foreach ($metaSets as $set) {
        foreach ($set as $item) {
            if (!is_array($item)) {
                continue;
            }
            $stat = (string)($item['stat'] ?? $item['attribute'] ?? $item['target'] ?? '');
            $value = $item['value'] ?? $item['amount'] ?? 0;
            $requires_choice = !empty($item['requires_choice']) || !empty($item['choice']) || !empty($item['requiresChoice']);
            if ($stat !== '' && !array_key_exists($stat, $attributes)) {
                continue;
            }
            $modifiers[] = [
                'id' => $type . ':' . $key . ':' . ($stat !== '' ? $stat : 'choice'),
                'source' => $type,
                'type' => 'attribute_bonus',
                'target' => $stat !== '' ? $stat : null,
                'value' => (float)$value,
                'requires_choice' => $requires_choice,
            ];
        }
    }

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        if (empty($data)) {
            continue;
        }
        $blockSets = [];
        if (!empty($data['modifiers']) && is_array($data['modifiers'])) {
            $blockSets[] = $data['modifiers'];
        }
        if (!empty($data['bonuses']) && is_array($data['bonuses'])) {
            $blockSets[] = $data['bonuses'];
        }
        foreach (['stats', 'attributes'] as $blockKey) {
            if (!empty($data[$blockKey]) && is_array($data[$blockKey])) {
                foreach ($data[$blockKey] as $stat => $value) {
                    if (array_key_exists($stat, $attributes)) {
                        $modifiers[] = [
                            'id' => $type . ':' . $key . ':' . $stat . ':block',
                            'source' => $type,
                            'type' => 'attribute_bonus',
                            'target' => $stat,
                            'value' => (float)$value,
                            'requires_choice' => false,
                        ];
                    }
                }
            }
        }
        foreach ($blockSets as $set) {
            foreach ($set as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $stat = (string)($item['stat'] ?? $item['attribute'] ?? $item['target'] ?? '');
                $value = $item['value'] ?? $item['amount'] ?? 0;
                $requires_choice = !empty($item['requires_choice']) || !empty($item['choice']) || !empty($item['requiresChoice']);
                if ($stat !== '' && !array_key_exists($stat, $attributes)) {
                    continue;
                }
                $modifiers[] = [
                    'id' => $type . ':' . $key . ':' . ($stat !== '' ? $stat : 'choice') . ':block',
                    'source' => $type,
                    'type' => 'attribute_bonus',
                    'target' => $stat !== '' ? $stat : null,
                    'value' => (float)$value,
                    'requires_choice' => $requires_choice,
                ];
            }
        }
    }

    return $modifiers;
}

function af_charactersheets_level_req(int $level): float
{
    global $mybb;

    $base = (float)($mybb->settings['af_charactersheets_level_req_base'] ?? 0);
    $step = (float)($mybb->settings['af_charactersheets_level_req_step'] ?? 0);
    if ($level <= 1) {
        return $base;
    }

    return $base + $step * ($level - 1);
}

function af_charactersheets_compute_level(float $exp): array
{
    global $mybb;

    $level_cap = (int)($mybb->settings['af_charactersheets_level_cap'] ?? 0);
    if ($level_cap <= 0) {
        $level_cap = 999;
    }

    $level = 1;
    $next_req = af_charactersheets_level_req($level);

    while ($level < $level_cap && $exp >= $next_req) {
        $level++;
        $next_req = af_charactersheets_level_req($level);
    }

    $prev_req = $level > 1 ? af_charactersheets_level_req($level - 1) : 0;
    $progress = $next_req > $prev_req ? ($exp - $prev_req) / ($next_req - $prev_req) : 0;
    $progress = max(0, min(1, $progress));

    return [
        'level' => $level,
        'next_req' => $next_req,
        'prev_req' => $prev_req,
        'percent' => (int)round($progress * 100),
    ];
}

function af_charactersheets_compute_sheet_view(array $sheet): array
{
    global $mybb;

    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));

    $attributes_base = array_merge(af_charactersheets_default_attributes(), (array)($base['attributes_base'] ?? []));
    $attributes_allocated = array_merge(af_charactersheets_default_attributes(), (array)($build['attributes_allocated'] ?? []));

    $choices = (array)($build['choices'] ?? []);
    $errors = [];
    $bonus = af_charactersheets_default_attributes();
    $choice_requirements = [];

    $sources = [
        'race' => (string)($base['race_key'] ?? ''),
        'class' => (string)($base['class_key'] ?? ''),
        'themes' => (string)($base['theme_key'] ?? ''),
    ];

    $choice_map = [
        'race' => 'race_attr_bonus_choice',
        'class' => 'class_attr_bonus_choice',
        'themes' => 'theme_attr_bonus_choice',
    ];

    foreach ($sources as $source => $key) {
        if ($key === '') {
            continue;
        }
        $mods = af_charactersheets_normalize_modifiers($source, $key);
        foreach ($mods as $mod) {
            if (($mod['type'] ?? '') !== 'attribute_bonus') {
                continue;
            }
            $target = $mod['target'] ?? null;
            if (!empty($mod['requires_choice'])) {
                $choice_key = $choice_map[$source] ?? '';
                $chosen = $choice_key !== '' ? (string)($choices[$choice_key] ?? '') : '';
                $choice_requirements[$source] = [
                    'choice_key' => $choice_key,
                    'chosen' => $chosen,
                    'value' => (float)($mod['value'] ?? 0),
                ];
                if ($chosen === '') {
                    if (!in_array('Не выбран бонус для ' . $source, $errors, true)) {
                        $errors[] = 'Не выбран бонус для ' . $source;
                    }
                    continue;
                }
                $target = $chosen;
            }

            if ($target !== null && array_key_exists($target, $bonus)) {
                $bonus[$target] += (float)($mod['value'] ?? 0);
            }
        }
    }

    $final = [];
    foreach ($attributes_base as $key => $value) {
        $final[$key] = (float)$value + (float)($attributes_allocated[$key] ?? 0) + (float)($bonus[$key] ?? 0);
    }

    $pool_max = (int)($mybb->settings['af_charactersheets_attr_pool_max'] ?? 0);
    $spent = 0;
    foreach ($attributes_allocated as $value) {
        $spent += (int)$value;
    }

    $remaining = $pool_max - $spent;
    if ($spent > $pool_max) {
        $errors[] = 'Превышен лимит очков пула.';
    }

    $attr_cap = (int)($mybb->settings['af_charactersheets_attr_cap'] ?? 0);
    if ($attr_cap > 0) {
        foreach ($final as $key => $value) {
            if ($value > $attr_cap) {
                $errors[] = 'Превышен лимит атрибутов (' . $key . ').';
            }
        }
    }

    $exp = (float)($progress['exp'] ?? 0);
    $level_data = af_charactersheets_compute_level($exp);
    $progress['level'] = (int)($progress['level'] ?? $level_data['level']);

    $attributes_labels = af_charactersheets_get_attribute_labels();
    $choice_details = [];
    foreach ($choice_requirements as $source => $data) {
        $entry = af_charactersheets_kb_get_entry($source, (string)$sources[$source]);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        if ($label === '') {
            $label = $source;
        }
        $choice_details[] = [
            'source' => $source,
            'label' => $label,
            'choice_key' => $data['choice_key'],
            'chosen' => $data['chosen'],
        ];
    }

    return [
        'base' => $attributes_base,
        'allocated' => $attributes_allocated,
        'bonus' => $bonus,
        'final' => $final,
        'pool_max' => $pool_max,
        'spent' => $spent,
        'remaining' => $remaining,
        'errors' => $errors,
        'choices' => $choices,
        'choice_details' => $choice_details,
        'labels' => $attributes_labels,
        'level' => $level_data['level'],
        'level_percent' => $level_data['percent'],
        'level_exp_label' => number_format($exp, 2, '.', ' ') . ' / ' . number_format($level_data['next_req'], 2, '.', ' '),
        'exp' => $exp,
        'next_req' => $level_data['next_req'],
    ];
}

function af_charactersheets_grant_exp(int $sheet_id, float $amount, string $event_key, string $event_type, array $meta): bool
{
    global $db, $mybb;

    if ($sheet_id <= 0 || $amount == 0.0 || !$db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        return false;
    }

    $event_key = trim($event_key);
    if ($event_key === '') {
        return false;
    }

    $exists = (int)$db->fetch_field(
        $db->simple_select(AF_CS_EXP_LEDGER_TABLE, 'id', "event_key='" . $db->escape_string($event_key) . "'", ['limit' => 1]),
        'id'
    );
    if ($exists) {
        return false;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $current_exp = (float)($progress['exp'] ?? 0);
    $new_exp = $current_exp + $amount;
    $progress['exp'] = $new_exp;

    $level_before = (int)($progress['level'] ?? 1);
    $level_data = af_charactersheets_compute_level($new_exp);
    $level_after = (int)$level_data['level'];
    $progress['level'] = $level_after;

    $attr_points_per_level = (int)($mybb->settings['af_charactersheets_attr_points_per_level'] ?? 0);
    $skill_points_per_level = (int)($mybb->settings['af_charactersheets_skill_points_per_level'] ?? 0);
    if ($level_after > $level_before) {
        $delta = $level_after - $level_before;
        $progress['attr_points_free'] = (int)($progress['attr_points_free'] ?? 0) + $delta * $attr_points_per_level;
        $progress['skill_points_free'] = (int)($progress['skill_points_free'] ?? 0) + $delta * $skill_points_per_level;
    }

    $db->insert_query(AF_CS_EXP_LEDGER_TABLE, af_charactersheets_db_escape_array([
        'sheet_id' => $sheet_id,
        'uid' => (int)($sheet['uid'] ?? 0),
        'event_key' => $event_key,
        'event_type' => $event_type,
        'amount' => $amount,
        'meta_json' => af_charactersheets_json_encode($meta),
        'created_at' => TIME_NOW,
    ]));

    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);

    return true;
}

function af_charactersheets_get_ledger(int $sheet_id, int $limit = 10): array
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        return [];
    }

    $rows = [];
    $q = $db->simple_select(
        AF_CS_EXP_LEDGER_TABLE,
        '*',
        'sheet_id=' . $sheet_id,
        ['order_by' => 'id', 'order_dir' => 'DESC', 'limit' => $limit]
    );
    while ($row = $db->fetch_array($q)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function af_charactersheets_build_attributes_html(array $view, bool $can_edit): string
{
    $rows = [];
    $labels = (array)($view['labels'] ?? []);
    foreach ($labels as $key => $label) {
        $base = (float)($view['base'][$key] ?? 0);
        $allocated = (float)($view['allocated'][$key] ?? 0);
        $bonus = (float)($view['bonus'][$key] ?? 0);
        $final = (float)($view['final'][$key] ?? 0);

        $input = $can_edit
            ? '<input type="number" min="0" step="1" value="' . htmlspecialchars_uni((string)$allocated)
                . '" data-afcs-attr-input="' . htmlspecialchars_uni($key) . '" />'
            : '<span class="af-cs-attr-readonly">' . htmlspecialchars_uni((string)$allocated) . '</span>';

        $rows[] = '<div class="af-cs-attr-row">'
            . '<div class="af-cs-attr-label">' . htmlspecialchars_uni($label) . '</div>'
            . '<div>' . htmlspecialchars_uni((string)$base) . '</div>'
            . '<div>' . $input . '</div>'
            . '<div>' . htmlspecialchars_uni((string)$bonus) . '</div>'
            . '<div class="af-cs-attr-final">' . htmlspecialchars_uni((string)$final) . '</div>'
            . '</div>';
    }

    $attributes_rows_html = implode('', $rows);
    $attributes_pool_max = (int)($view['pool_max'] ?? 0);
    $attributes_pool_remaining = (int)($view['remaining'] ?? 0);

    $error_items = [];
    foreach ((array)($view['errors'] ?? []) as $error) {
        $error_items[] = '<div class="af-cs-warning">' . htmlspecialchars_uni((string)$error) . '</div>';
    }
    $attributes_errors_html = implode('', $error_items);

    $choice_items = [];
    foreach ((array)($view['choice_details'] ?? []) as $choice) {
        $choice_key = (string)($choice['choice_key'] ?? '');
        $label = (string)($choice['label'] ?? '');
        if ($choice_key === '') {
            continue;
        }
        $select_options = '';
        foreach ($labels as $key => $attrLabel) {
            $selected = ((string)($view['choices'][$choice_key] ?? '') === $key) ? ' selected' : '';
            $select_options .= '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>'
                . htmlspecialchars_uni($attrLabel) . '</option>';
        }
        $choice_items[] = '<div class="af-cs-choice-row">'
            . '<label>' . htmlspecialchars_uni($label) . '</label>'
            . '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '">' . $select_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить</button>'
            . '</div>';
    }
    $attributes_choice_html = ($can_edit && $choice_items)
        ? '<div class="af-cs-choices">' . implode('', $choice_items) . '</div>'
        : '';
    $attributes_actions_html = $can_edit
        ? '<button type="button" class="af-cs-btn" data-afcs-save-attributes="1">Сохранить распределение</button>'
        : '<div class="af-cs-muted">Редактирование недоступно.</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_attributes');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_progress_html(array $view, array $sheet, bool $can_award): string
{
    $sheet_id = (int)($sheet['id'] ?? 0);
    $level = (int)($view['level'] ?? 1);
    $exp = (float)($view['exp'] ?? 0);
    $next = (float)($view['next_req'] ?? 0);
    $percent = (int)($view['level_percent'] ?? 0);
    $exp_label = htmlspecialchars_uni((string)($view['level_exp_label'] ?? ''));

    $ledger_items = [];
    foreach (af_charactersheets_get_ledger($sheet_id, 10) as $row) {
        $meta = af_charactersheets_json_decode((string)($row['meta_json'] ?? ''));
        $desc = (string)($meta['reason'] ?? $meta['source'] ?? '');
        if ($desc === '' && !empty($meta['tid'])) {
            $desc = 'Тема #' . (int)$meta['tid'];
        }
        if ($desc === '') {
            $desc = strtoupper((string)($row['event_type'] ?? 'exp'));
        }
        $ledger_items[] = '<div class="af-cs-ledger-row">'
            . '<div>' . htmlspecialchars_uni($desc) . '</div>'
            . '<div class="af-cs-ledger-amount">' . htmlspecialchars_uni((string)$row['amount']) . '</div>'
            . '<div class="af-cs-ledger-date">' . htmlspecialchars_uni(date('d.m.Y H:i', (int)$row['created_at'])) . '</div>'
            . '</div>';
    }
    if (!$ledger_items) {
        $ledger_items[] = '<div class="af-cs-muted">Нет начислений.</div>';
    }
    $ledger_html = implode('', $ledger_items);

    $manual_award_html = '';
    if ($can_award) {
        $manual_award_html = '<form class="af-cs-award" data-afcs-award-form>'
            . '<input type="number" step="0.01" name="amount" placeholder="EXP" required />'
            . '<input type="text" name="reason" placeholder="Причина" />'
            . '<button type="submit" class="af-cs-btn">Начислить</button>'
            . '</form>';
    }

    global $templates;
    $tpl = $templates->get('charactersheet_progress');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_handle_api(): void
{
    global $mybb;

    if (!af_charactersheets_is_enabled()) {
        af_charactersheets_json_response(['success' => false, 'error' => 'Addon disabled']);
    }

    $do = (string)$mybb->get_input('do');
    $sheet_id = (int)$mybb->get_input('sheet_id');
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        af_charactersheets_json_response(['success' => false, 'error' => 'Sheet not found']);
    }

    $can_edit = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_award = af_charactersheets_user_can_award_exp($mybb->user ?? []);

    if (in_array($do, ['save_attributes', 'save_choice', 'grant_exp'], true)) {
        verify_post_check($mybb->get_input('my_post_key'));
    }

    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));

    if ($do === 'save_attributes') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        $allocations = $mybb->get_input('allocations', MyBB::INPUT_ARRAY);
        $allowed = array_keys(af_charactersheets_default_attributes());
        $sanitized = [];
        foreach ($allowed as $key) {
            $value = (int)($allocations[$key] ?? 0);
            if ($value < 0) {
                $value = 0;
            }
            $sanitized[$key] = $value;
        }

        $build['attributes_allocated'] = $sanitized;
        $temp_sheet = $sheet;
        $temp_sheet['build_json'] = af_charactersheets_json_encode($build);
        $view = af_charactersheets_compute_sheet_view($temp_sheet);
        if (!empty($view['errors'])) {
            af_charactersheets_json_response(['success' => false, 'errors' => $view['errors']]);
        }
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'save_choice') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        $choice_key = (string)$mybb->get_input('choice_key');
        $choice_value = (string)$mybb->get_input('choice_value');
        $allowed_choices = ['race_attr_bonus_choice', 'class_attr_bonus_choice', 'theme_attr_bonus_choice'];
        if (!in_array($choice_key, $allowed_choices, true)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid choice']);
        }
        if (!array_key_exists($choice_value, af_charactersheets_default_attributes())) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attribute']);
        }
        $build['choices'][$choice_key] = $choice_value;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'grant_exp') {
        if (!$can_award) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $amount = (float)$mybb->get_input('amount');
        $reason = (string)$mybb->get_input('reason');
        if ($amount == 0.0) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Amount is zero']);
        }
        $event_key = 'manual:' . $sheet_id . ':' . TIME_NOW . ':' . mt_rand(1000, 9999);
        af_charactersheets_grant_exp($sheet_id, $amount, $event_key, 'manual', ['reason' => $reason]);
    } else {
        af_charactersheets_json_response(['success' => false, 'error' => 'Unknown action']);
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    $view = af_charactersheets_compute_sheet_view($sheet);
    $attributes_html = af_charactersheets_build_attributes_html($view, $can_edit);
    $progress_html = af_charactersheets_build_progress_html($view, $sheet, $can_award);

    af_charactersheets_json_response([
        'success' => true,
        'view' => $view,
        'attributes_html' => $attributes_html,
        'progress_html' => $progress_html,
    ]);
}

function af_charactersheets_json_response(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_charactersheets_member_do_register_end(): void
{
    global $mybb;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $exp_on_register = (float)($mybb->settings['af_charactersheets_exp_on_register'] ?? 0);
    if ($exp_on_register <= 0) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    af_charactersheets_grant_exp(
        (int)$sheet['id'],
        $exp_on_register,
        'register:' . $uid,
        'register',
        ['uid' => $uid]
    );
}

function af_charactersheets_post_do_newpost_end(): void
{
    global $mybb, $pid, $post;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $exp_per_char = (float)($mybb->settings['af_charactersheets_exp_per_char'] ?? 0);
    if ($exp_per_char <= 0) {
        return;
    }

    $pid = (int)$pid;
    if ($pid <= 0) {
        return;
    }

    $uid = (int)($post['uid'] ?? $mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    $message = (string)($post['message'] ?? $mybb->get_input('message'));
    $chars = function_exists('my_strlen') ? my_strlen($message) : strlen($message);
    if ($chars <= 0) {
        return;
    }

    $amount = $chars * $exp_per_char;
    af_charactersheets_grant_exp(
        (int)$sheet['id'],
        $amount,
        'post:' . $pid,
        'post',
        ['pid' => $pid, 'chars' => $chars]
    );
}

function af_charactersheets_apply_purchase(int $sheet_id, string $kb_type, string $kb_key, int $qty, array $meta = []): bool
{
    return false;
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
    $sheet_slug = htmlspecialchars_uni($slug);

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

    if (!$db->table_exists(AF_CS_SHEETS_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_SHEETS_TABLE." (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              uid INT UNSIGNED NOT NULL,
              tid INT UNSIGNED NOT NULL,
              slug VARCHAR(190) NOT NULL,
              base_json MEDIUMTEXT NOT NULL,
              build_json MEDIUMTEXT NOT NULL,
              progress_json MEDIUMTEXT NOT NULL,
              updated_at INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              UNIQUE KEY uid (uid),
              UNIQUE KEY slug (slug),
              KEY tid (tid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    if (!$db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_EXP_LEDGER_TABLE." (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              sheet_id INT UNSIGNED NOT NULL,
              uid INT UNSIGNED NOT NULL,
              event_key VARCHAR(190) NOT NULL,
              event_type VARCHAR(32) NOT NULL,
              amount DECIMAL(12,4) NOT NULL DEFAULT 0,
              meta_json TEXT NOT NULL,
              created_at INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              UNIQUE KEY event_key (event_key),
              KEY sheet_id (sheet_id),
              KEY uid (uid)
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
    $anchor = '{$post[\'userstars\']}';
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
        if (strpos($tpl, $anchor) === false) {
            continue;
        }

        $new = str_replace($anchor, $anchor . "\n" . $needle, $tpl);

        if (is_string($new) && $new !== '' && $new !== $tpl) {
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
        'character_pic',
        'character_image',
        'character_avatar',
        'character_portrait',
        'character_face',
        'portrait',
        'avatar',
        'image',
    ], false);

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
    return '';
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
    $skills = [
        ['name' => 'Атлетика', 'attr' => 'Ловкости'],
        ['name' => 'Скрытность', 'attr' => 'Ловкости'],
        ['name' => 'Анализ', 'attr' => 'Интеллекта'],
        ['name' => 'Восприятие', 'attr' => 'Мудрости'],
        ['name' => 'Убеждение', 'attr' => 'Харизмы'],
        ['name' => 'Медицина', 'attr' => 'Мудрости'],
        ['name' => 'Выживание', 'attr' => 'Конституции'],
        ['name' => 'Рукопашный бой', 'attr' => 'Силы'],
        ['name' => 'Акробатика', 'attr' => 'Ловкости'],
        ['name' => 'История', 'attr' => 'Интеллекта'],
        ['name' => 'Запугивание', 'attr' => 'Харизмы'],
        ['name' => 'Техника', 'attr' => 'Интеллекта'],
        ['name' => 'Ловкость рук', 'attr' => 'Ловкости'],
        ['name' => 'Знания', 'attr' => 'Интеллекта'],
    ];

    $items = [];
    foreach ($skills as $skill) {
        $items[] = '<div class="af-cs-skill-item">'
            . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($skill['name']) . ' <span>(от ' . htmlspecialchars_uni($skill['attr']) . ')</span></div>'
            . '<div class="af-cs-skill-value">+0</div>'
            . '</div>';
    }

    $skills_html = implode('', $items);

    global $templates;
    $tpl = $templates->get('charactersheet_skills');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_feats_html(array $index): string
{
    $feats_html = '<div class="af-cs-feat-item">'
        . '<div class="af-cs-feat-title">Способностей пока нет</div>'
        . '<div class="af-cs-feat-meta">Заглушка магазина</div>'
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
        'character_themes' => [
            'type' => 'themes',
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

function af_charactersheets_kb_get_block_html(array $entry, string $blockKey): string
{
    $blockKey = trim($blockKey);
    if ($blockKey === '') {
        return '<div class="af-cs-muted">Нет данных</div>';
    }

    $block = [];

    if (!empty($entry['id'])) {
        global $db;
        if (is_object($db) && $db->table_exists('af_kb_blocks')) {
            $where = "entry_id=" . (int)$entry['id']
                . " AND block_key='" . $db->escape_string($blockKey) . "'";
            if (!function_exists('af_kb_can_edit') || !af_kb_can_edit()) {
                $where .= " AND active=1";
            }
            $row = $db->fetch_array($db->simple_select('af_kb_blocks', '*', $where, ['limit' => 1]));
            if (is_array($row)) {
                $block = $row;
            }
        }
    }

    if (empty($block)) {
        $metaRaw = (string)($entry['meta_json'] ?? '');
        $meta = function_exists('af_kb_decode_json') ? af_kb_decode_json($metaRaw) : json_decode($metaRaw, true);
        if (is_array($meta) && !empty($meta['blocks']) && is_array($meta['blocks'])) {
            $blocks = $meta['blocks'];
            $isList = array_keys($blocks) === range(0, count($blocks) - 1);
            if ($isList) {
                foreach ($blocks as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if ((string)($item['key'] ?? '') === $blockKey) {
                        $block = $item;
                        break;
                    }
                }
            } else {
                $block = is_array($blocks[$blockKey] ?? null) ? $blocks[$blockKey] : [];
            }
        }
    }

    $content = af_charactersheets_kb_pick_text($block, 'content');
    if ($content === '') {
        return '<div class="af-cs-muted">Нет данных</div>';
    }

    $html = af_charactersheets_parse_bbcode($content);
    return $html !== '' ? $html : '<div class="af-cs-muted">Нет данных</div>';
}

function af_charactersheets_is_ru(): bool
{
    if (function_exists('af_kb_is_ru')) {
        return af_kb_is_ru();
    }

    global $lang;
    return isset($lang->language) && $lang->language === 'russian';
}

function af_charactersheets_render_kb_chip(string $type, string $key, string $fallbackLabel = ''): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $entry = af_charactersheets_kb_get_entry($type, $key);
    $label = af_charactersheets_kb_pick_text($entry, 'title');
    if ($label === '') {
        $label = $fallbackLabel;
    }
    if ($label === '') {
        $label = $key;
    }

    $url = 'misc.php?action=kb&type=' . rawurlencode($type) . '&key=' . rawurlencode($key);

    return '<a class="af-cs-chip af-cs-chip--link" href="' . htmlspecialchars_uni($url)
        . '" target="_blank" rel="noopener">' . htmlspecialchars_uni($label) . '</a>';
}

function af_charactersheets_build_base_html(string $profile_url, string $thread_url): string
{
    $items = [];

    if ($profile_url !== '') {
        $items[] = '<a class="af-cs-btn" href="' . htmlspecialchars_uni($profile_url) . '">Профиль</a>';
    }

    if ($thread_url !== '') {
        $items[] = '<a class="af-cs-btn" href="' . htmlspecialchars_uni($thread_url) . '">Анкета</a>';
    }

    if (empty($items)) {
        return '<div class="af-cs-muted">Нет данных</div>';
    }

    return '<div class="af-cs-button-row">' . implode('', $items) . '</div>';
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

        $blockKey = $kbType === 'themes' ? 'knowledges' : 'characteristics';
        $body_html = af_charactersheets_kb_get_block_html($entry, $blockKey);

        $cards[] = '<div class="af-cs-kb-card">'
            . '<div class="af-cs-kb-title">' . htmlspecialchars_uni($title) . '</div>'
            . ($body_html !== '' ? '<div class="af-cs-kb-body">' . $body_html . '</div>' : '')
            . '</div>';
    }

    $rct_cards_html = implode('', $cards);

    global $templates;
    $tpl = $templates->get('charactersheet_rct_cards');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_info_table_html(array $index): string
{
    $age = af_charactersheets_pick_field_value($index, ['character_age', 'age']);
    $gender = af_charactersheets_pick_field_value($index, ['character_gen', 'character_gender', 'gender']);
    $race_key = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
    $class_key = af_charactersheets_pick_field_value($index, ['character_class', 'class'], false);
    $theme_key = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
    $race_label = af_charactersheets_pick_field_value($index, ['character_race', 'race'], true);
    $class_label = af_charactersheets_pick_field_value($index, ['character_class', 'class'], true);
    $theme_label = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], true);

    $items = [];
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Возраст</div><div class="af-cs-info-value">' . htmlspecialchars_uni($age !== '' ? $age : '—') . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Пол</div><div class="af-cs-info-value">' . htmlspecialchars_uni($gender !== '' ? $gender : '—') . '</div></div>';

    $chip_html = '';
    $chip_html .= af_charactersheets_render_kb_chip('race', $race_key, $race_label);
    $chip_html .= af_charactersheets_render_kb_chip('class', $class_key, $class_label);
    $chip_html .= af_charactersheets_render_kb_chip('themes', $theme_key, $theme_label);
    if ($chip_html === '') {
        $chip_html = '<span class="af-cs-muted">—</span>';
    }
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Чипы</div><div class="af-cs-info-value">' . $chip_html . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Кошелёк</div><div class="af-cs-info-value">0 ₵</div></div>';

    return '<div class="af-cs-info-table">' . implode('', $items) . '</div>';
}

function af_charactersheets_build_bonus_html(array $index): string
{
    $mapping = af_charactersheets_kb_mapping();
    $columns = [];
    foreach ($mapping as $fieldName => $data) {
        $field = $index[$fieldName] ?? [];
        $key = (string)($field['value'] ?? '');
        $entry = $key !== '' ? af_charactersheets_kb_get_entry((string)$data['type'], $key) : [];
        $title = (string)$data['label'];
        $text_html = af_charactersheets_kb_get_block_html($entry, 'bonuses');
        $columns[] = '<div class="af-cs-bonus-card">'
            . '<div class="af-cs-bonus-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-cs-bonus-body">' . $text_html . '</div>'
            . '</div>';
    }

    return '<div class="af-cs-bonus-grid">' . implode('', $columns) . '</div>';
}

function af_charactersheets_build_mechanics_html(): string
{
    $col1 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Класс брони</div>'
        . '<div class="af-cs-mech-row"><span>Броня</span><span>0</span></div>'
        . '<div class="af-cs-mech-row"><span>Щит</span><span>0</span></div>'
        . '<div class="af-cs-mech-row af-cs-mech-total"><span>Итоговый AC</span><span>0</span></div>'
        . '</div>';

    $col2 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Спасброски</div>'
        . '<div class="af-cs-mech-row"><span>Рефлекс</span><span>0</span></div>'
        . '<div class="af-cs-mech-row"><span>Воля</span><span>0</span></div>'
        . '<div class="af-cs-mech-row"><span>Стойкость</span><span>0</span></div>'
        . '<div class="af-cs-mech-row"><span>Восприятие</span><span>0</span></div>'
        . '<div class="af-cs-mech-divider"></div>'
        . '<div class="af-cs-mech-row"><span>HP</span><span>—</span></div>'
        . '<div class="af-cs-mech-row"><span>Человечность</span><span>—</span></div>'
        . '</div>';

    $col3 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Урон</div>'
        . '<div class="af-cs-mech-row"><span>Базовый</span><span>0</span></div>'
        . '<div class="af-cs-mech-row"><span>Бонус оружия</span><span>0</span></div>'
        . '<div class="af-cs-mech-row af-cs-mech-total"><span>Итог</span><span>0</span></div>'
        . '</div>';

    return '<div class="af-cs-mechanics-grid">' . $col1 . $col2 . $col3 . '</div>';
}

function af_charactersheets_build_inventory_html(): string
{
    return '<div class="af-cs-inventory-grid" data-afcs-inventory>'
        . '<div class="af-cs-inventory-card">'
        . '<div class="af-cs-inventory-title">Экипировка</div>'
        . '<div class="af-cs-inventory-row"><span>Оружие</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Броня</span><span>—</span></div>'
        . '</div>'
        . '<div class="af-cs-inventory-card">'
        . '<div class="af-cs-inventory-title">Инвентарь</div>'
        . '<div class="af-cs-inventory-row"><span>Броня</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Оружие</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Боеприпасы</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Инструменты</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Модификации</span><span>—</span></div>'
        . '<div class="af-cs-inventory-row"><span>Ресурсы</span><span>—</span></div>'
        . '</div>'
        . '<div class="af-cs-inventory-card">'
        . '<div class="af-cs-inventory-title">Навыки и предметы</div>'
        . '<div class="af-cs-muted">Скоро: интеграция с магазином по ключам KB.</div>'
        . '</div>'
        . '</div>';
}

function af_charactersheets_build_augments_html(): string
{
    return '<div class="af-cs-augmentations">'
        . '<div class="af-cs-augmentations-list">'
        . '<div class="af-cs-augmentation-item">'
        . '<div class="af-cs-augmentation-name">Усиленный слух</div>'
        . '<div class="af-cs-augmentation-meta">Человечность: -3</div>'
        . '</div>'
        . '</div>'
        . '<div class="af-cs-augmentation-controls">'
        . '<button type="button" class="af-cs-btn af-cs-btn--ghost">Добавить мод</button>'
        . '<div class="af-cs-augmentation-slot">'
        . '<label>Слот</label>'
        . '<select>'
        . '<option>Левый глаз</option>'
        . '<option>Правый глаз</option>'
        . '<option>Левая рука</option>'
        . '<option>Правая рука</option>'
        . '<option>Ноги</option>'
        . '<option>Нервная система</option>'
        . '</select>'
        . '</div>'
        . '<div class="af-cs-augmentation-humanity">'
        . '<span>Потеря человечности</span>'
        . '<strong>3</strong>'
        . '</div>'
        . '</div>'
        . '</div>';
}

function af_charactersheets_render_catalog_page(): void
{
    global $db, $templates, $header, $headerinclude, $footer;

    $rows = [];
    if ($db->table_exists(AF_CS_TABLE)) {
        $q = $db->simple_select(AF_CS_TABLE, 'tid,uid,sheet_slug', 'sheet_created=1', ['order_by' => 'tid', 'order_dir' => 'DESC']);
        while ($row = $db->fetch_array($q)) {
            if (!empty($row['sheet_slug'])) {
                $rows[] = $row;
            }
        }
    }

    $tids = array_map(static function ($row) {
        return (int)($row['tid'] ?? 0);
    }, $rows);
    $atf_map = af_charactersheets_get_atf_fields_map($tids);

    $cards = [];
    foreach ($rows as $row) {
        $tid = (int)($row['tid'] ?? 0);
        $slug = (string)($row['sheet_slug'] ?? '');
        $fields = $atf_map[$tid] ?? [];
        $index = af_charactersheets_index_fields($fields);

        $name_en = af_charactersheets_pick_field_value($index, ['character_name_en', 'character_name', 'char_name', 'name']);
        $name_ru = af_charactersheets_pick_field_value($index, ['character_name_ru']);
        $race = af_charactersheets_pick_field_value($index, ['character_race', 'race']);
        $class = af_charactersheets_pick_field_value($index, ['character_class', 'class']);
        $theme = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme']);
        $portrait = af_charactersheets_get_portrait_url($index);

        $sheet_url = 'misc.php?action=af_charactersheet&slug=' . rawurlencode($slug);
        $sheet_url = htmlspecialchars_uni($sheet_url);
        $card_name_en = htmlspecialchars_uni($name_en !== '' ? $name_en : $slug);
        $card_name_ru = htmlspecialchars_uni($name_ru);
        $card_race_label = htmlspecialchars_uni($race);
        $card_class_label = htmlspecialchars_uni($class);
        $card_theme_label = htmlspecialchars_uni($theme);
        $card_race_chip = $race !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($race) . '</span>' : '';
        $card_class_chip = $class !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($class) . '</span>' : '';
        $card_theme_chip = $theme !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($theme) . '</span>' : '';
        $card_portrait = htmlspecialchars_uni($portrait);

        $tpl = $templates->get('charactersheets_catalog_card');
        eval("\$card_html = \"" . $tpl . "\";");
        $cards[] = $card_html;
    }

    $cards_html = implode('', $cards);
    if ($cards_html === '') {
        $cards_html = '<div class="af-cs-muted">Листы персонажей пока не созданы.</div>';
    }

    $assets = af_charactersheets_get_asset_urls();
    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=1.0.0" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=1.0.0"></script>' . "\n";

    $page_title = 'Каталог листов персонажей';
    $tpl = $templates->get('charactersheets_catalog');
    eval("\$page = \"" . $tpl . "\";");
    output_page($page);
    exit;
}

function af_charactersheets_get_atf_fields_map(array $tids): array
{
    global $db;

    $tids = array_values(array_filter(array_map('intval', $tids)));
    if (empty($tids)) {
        return [];
    }

    if (!$db->table_exists('af_atf_fields') || !$db->table_exists('af_atf_values')) {
        return [];
    }

    $id_list = implode(',', $tids);
    $map = [];
    $q = $db->write_query("
        SELECT v.tid, f.name, f.title, f.type, f.options, v.value
        FROM " . TABLE_PREFIX . "af_atf_values v
        INNER JOIN " . TABLE_PREFIX . "af_atf_fields f ON f.fieldid = v.fieldid
        WHERE v.tid IN (" . $id_list . ") AND f.active = 1
        ORDER BY f.sortorder ASC, f.fieldid ASC
    ");

    while ($row = $db->fetch_array($q)) {
        $tid = (int)($row['tid'] ?? 0);
        $value = (string)($row['value'] ?? '');
        if ($value === '' || $tid <= 0) {
            continue;
        }

        $field = [
            'name' => (string)($row['name'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'options' => (string)($row['options'] ?? ''),
            'value' => $value,
        ];
        $field['value_label'] = af_charactersheets_resolve_field_label($field);

        $map[$tid][] = $field;
    }

    return $map;
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
