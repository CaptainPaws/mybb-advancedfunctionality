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
const AF_CS_POINTS_LEDGER_TABLE = 'af_cs_points_ledger';
const AF_CS_SKILLS_CATALOG_TABLE = 'af_cs_skills_catalog';
const AF_CS_TPL_MARK = '<!--AF_CS_ACCEPT-->';
const AF_CS_ASSET_MARK = '<!--AF_CS_ASSETS-->';
const AF_CS_MODAL_MARK = '<!--AF_CS_MODAL-->';
const AF_CS_ASSET_FALLBACK_VERSION = '1.1.0';

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
    if ($db->table_exists(AF_CS_POINTS_LEDGER_TABLE)) {
        $db->drop_table(AF_CS_POINTS_LEDGER_TABLE);
    }
    if ($db->table_exists(AF_CS_SKILLS_CATALOG_TABLE)) {
        $db->drop_table(AF_CS_SKILLS_CATALOG_TABLE);
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
        'af_charactersheets_exp_manual_groups',
        'af_charactersheets_exp_forum_categories',
        'af_charactersheets_exp_forum_forums',
        'af_charactersheets_exp_forum_exclude',
        'af_charactersheets_exp_forum_mode',
        'af_charactersheets_exp_allow_negative',
        'af_charactersheets_exp_allow_overdraw',
        'af_charactersheets_knowledge_base_choices',
        'af_charactersheets_knowledge_per_int'
    )");
    $db->delete_query('settinggroups', "name='af_charactersheets'");
    $db->delete_query('templates', "title LIKE 'charactersheets_%'");
    $db->delete_query('templates', "title IN ('charactersheet_fullpage','charactersheet_inner','charactersheet_modal','postbit_plaque','charactersheet_rct_cards','charactersheet_stats_bars','charactersheet_attributes','charactersheet_progress','charactersheet_skills','charactersheet_feats','charactersheet_knowledge','charactersheets_catalog','charactersheets_catalog_card')");

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
        'af_charactersheets_exp_forum_categories',
        $lang->af_charactersheets_exp_forum_categories ?? 'EXP forums: categories',
        $lang->af_charactersheets_exp_forum_categories_desc ?? 'CSV category fids. All child forums are included.',
        'text',
        '',
        31
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_forum_forums',
        $lang->af_charactersheets_exp_forum_forums ?? 'EXP forums: forums',
        $lang->af_charactersheets_exp_forum_forums_desc ?? 'CSV forum fids included.',
        'text',
        '',
        32
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_forum_exclude',
        $lang->af_charactersheets_exp_forum_exclude ?? 'EXP forums: exclude',
        $lang->af_charactersheets_exp_forum_exclude_desc ?? 'CSV forum fids excluded.',
        'text',
        '',
        33
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_forum_mode',
        $lang->af_charactersheets_exp_forum_mode ?? 'EXP forums: mode',
        $lang->af_charactersheets_exp_forum_mode_desc ?? 'include = only selected, exclude = all except selected.',
        "select\ninclude=include\nexclude=exclude",
        'include',
        34
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
        35
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
        'af_charactersheets_exp_allow_negative',
        $lang->af_charactersheets_exp_allow_negative ?? 'Allow negative EXP awards',
        $lang->af_charactersheets_exp_allow_negative_desc ?? 'Allow manual EXP subtraction.',
        'yesno',
        '0',
        45
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_allow_overdraw',
        $lang->af_charactersheets_exp_allow_overdraw ?? 'Allow EXP to go below zero',
        $lang->af_charactersheets_exp_allow_overdraw_desc ?? 'If disabled, EXP cannot drop below zero.',
        'yesno',
        '0',
        46
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_knowledge_base_choices',
        $lang->af_charactersheets_knowledge_base_choices ?? 'Knowledge choices base',
        $lang->af_charactersheets_knowledge_base_choices_desc ?? 'Base number of knowledge choices.',
        'text',
        '0',
        47
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_knowledge_per_int',
        $lang->af_charactersheets_knowledge_per_int ?? 'Knowledge per INT',
        $lang->af_charactersheets_knowledge_per_int_desc ?? 'Choices added per INT point (floor).',
        'text',
        '0.4',
        48
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_manual_groups',
        $lang->af_charactersheets_exp_manual_groups ?? 'EXP manual award groups',
        $lang->af_charactersheets_exp_manual_groups_desc ?? 'CSV group ids allowed to grant experience manually.',
        'text',
        '4,3,6',
        49
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

    // Дедуп ассетов на showthread тоже
    if (!empty($GLOBALS['af_charactersheets_needs_assets'])) {
        $page = af_charactersheets_canonicalize_assets_html($page);
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
        error_no_permission();
        exit;
    }

    $tid = (int)($accept_row['tid'] ?? 0);
    $uid = (int)($accept_row['uid'] ?? 0);

    // ВАЖНО: берем uid из threads тоже, но accept_row приоритетнее как “источник истины”
    $thread = [];
    if ($tid > 0) {
        $thread = $db->fetch_array($db->simple_select(
            'threads',
            'tid,fid,uid,username,subject',
            'tid=' . $tid,
            ['limit' => 1]
        ));
        if (!is_array($thread)) $thread = [];
    }

    // Если в threads uid есть — ок, если нет/0 — используем accept_row uid
    if ($uid > 0 && (int)($thread['uid'] ?? 0) !== $uid) {
        $thread['uid'] = $uid;
    } elseif ((int)($thread['uid'] ?? 0) > 0 && $uid <= 0) {
        $uid = (int)$thread['uid'];
    } else {
        $thread['uid'] = (int)($thread['uid'] ?? 0);
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
        // ВАЖНО: автосоздаём с корректным uid
        $sheet = af_charactersheets_autocreate_sheet($tid, $thread);
    }

    $sheet_view = af_charactersheets_compute_sheet_view($sheet);
    $can_edit_sheet = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_award_exp = af_charactersheets_user_can_award_exp($mybb->user ?? []);
    // fid нужен для is_moderator(), возьмём из threads если можем
    $fid_for_mod = (int)($thread['fid'] ?? 0);
    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $sheet_title = htmlspecialchars_uni($character_name_en);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $sheet_base_html = af_charactersheets_build_base_html($profile_url, $thread_url);
    $sheet_info_table_html = af_charactersheets_build_info_table_html($atf_index);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_bonus_html = af_charactersheets_build_bonus_html($atf_index);
    $sheet_skills_html = af_charactersheets_build_skills_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_knowledge_html = af_charactersheets_build_knowledge_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_feats_html = af_charactersheets_build_feats_html($atf_index);
    $sheet_inventory_html = af_charactersheets_build_inventory_html();
    $sheet_augments_html = af_charactersheets_build_augments_html();
    $sheet_mechanics_html = af_charactersheets_build_mechanics_html();

    $sheet_progress_html = af_charactersheets_build_progress_html($sheet_view, $sheet, $can_award_exp, $can_view_ledger);

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
    } elseif (!empty($character_name_en)) {
        $page_title .= ' — ' . $character_name_en;
    }

    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n";
    af_charactersheets_ensure_assets_in_headerinclude();


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

    // Вырезаем дубли ассетов (в т.ч. если кто-то другой добавил ?v=...)
    $page = af_charactersheets_canonicalize_assets_html($page);

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

function af_charactersheets_get_sheet_by_slug(string $slug): array
{
    global $db;

    $slug = trim($slug);
    if ($slug === '' || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    if (!preg_match('~^[a-z0-9][a-z0-9\\-]*$~i', $slug)) {
        return [];
    }

    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', "slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_ensure_sheet(int $tid, int $uid, string $slug): array
{
    global $db, $mybb;

    if (!$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    // uid=0 (или <0) считаем “нет пользователя”
    $uid = (int)$uid;
    $hasUid = ($uid > 0);

    $existing = [];
    if ($hasUid) {
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

        // uid обновляем только если валидный
        if ($hasUid && (int)($existing['uid'] ?? 0) !== $uid) {
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
        'knowledge' => [
            'languages' => [],
            'knowledges' => [],
        ],
        'inventory' => [],
    ];

    $starting_attr_pool = (int)($mybb->settings['af_charactersheets_attr_pool_max'] ?? 0);
    $progress = [
        'level' => 1,
        'exp' => 0,
        'attr_points_free' => $starting_attr_pool,
        'skill_points_free' => 0,
        'bonus_attr_points' => 0,
        'bonus_skill_points' => 0,
    ];

    // ВАЖНО: если нет uid — пишем NULL, а не 0 (после миграции схемы)
    $row = [
        'uid' => $hasUid ? $uid : null,
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
    if ($exp_on_register > 0 && $hasUid) {
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

function af_charactersheets_user_can_view_ledger(array $sheet, array $user, int $fid = 0): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    // 1) владелец листа
    if ((int)($sheet['uid'] ?? 0) === $uid) {
        return true;
    }

    // 2) админ/мод/супермод
    if (!empty($mybb->usergroup['cancp']) || !empty($user['cancp'])) {
        return true;
    }
    if (!empty($mybb->usergroup['issupermod']) || !empty($user['issupermod'])) {
        return true;
    }
    if (!empty($mybb->usergroup['canmodcp']) || !empty($user['canmodcp'])) {
        return true;
    }

    // 3) модератор форума (если можем определить fid)
    if ($fid > 0 && function_exists('is_moderator')) {
        // можно усилить правом, но ты просила минимум is_moderator()
        if (is_moderator($fid)) {
            return true;
        }
    }

    return false;
}

function af_charactersheets_user_can_view_pools(array $sheet, array $user, int $fid = 0): bool
{
    return af_charactersheets_user_can_view_ledger($sheet, $user, $fid);
}

function af_charactersheets_user_can_edit_sheet(array $sheet, array $user): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    // 1) владелец листа → 1
    if ((int)($sheet['uid'] ?? 0) === $uid) {
        return true;
    }

    // 2) админы и модеры → 1 на всех листах
    // (берём и из $mybb->usergroup, и из $user на всякий случай)
    $is_admin = !empty($mybb->usergroup['cancp']) || !empty($user['cancp']);
    $is_modcp = !empty($mybb->usergroup['canmodcp']) || !empty($user['canmodcp']);
    $is_supermod = !empty($user['issupermod']);

    if ($is_admin || $is_modcp || $is_supermod) {
        return true;
    }

    // 3) остальные → 0
    return false;
}

function af_charactersheets_user_can_award_exp(array $user): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    // Админы/супермоды — всегда да
    if (!empty($user['issupermod']) || !empty($user['cancp'])) {
        return true;
    }

    // Разрешённые группы (CSV из настроек)
    $allowed = af_charactersheets_csv_to_ids($mybb->settings['af_charactersheets_exp_manual_groups'] ?? '');
    if (!$allowed) {
        return false;
    }

    // Группы пользователя: основная + дополнительные
    $usergroups = [(int)($user['usergroup'] ?? 0)];
    $additional = af_charactersheets_csv_to_ids((string)($user['additionalgroups'] ?? ''));
    $usergroups = array_values(array_unique(array_filter(array_merge($usergroups, $additional))));

    // Есть пересечение — можно
    return !empty(array_intersect($allowed, $usergroups));
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

/**
 * Contract for bonuses in KB JSON (meta_json or blocks[].data_json):
 * {
 *   "bonuses": [
 *     {"type":"attribute_bonus","target":"str","value":1},
 *     {"type":"attribute_bonus","requires_choice":true,"value":2},
 *     {"type":"attribute_points","value":2},
 *     {"type":"skill_points","value":1},
 *     {"type":"skill_bonus","target":"analysis","value":1},
 *     {"type":"skill_bonus","requires_choice":true,"value":1},
 *     {"type":"knowledge_choice","value":1},
 *     {"type":"language_choice","value":1},
 *     {"type":"knowledge","target":"lore_key"},
 *     {"type":"language","target":"common"}
 *   ]
 * }
 */
function af_charactersheets_normalize_bonus_items(string $source, string $key): array
{
    $entry = af_charactersheets_kb_get_entry($source, $key);
    if (empty($entry)) {
        return [];
    }

    $items = [];
    $attributes = af_charactersheets_default_attributes();

    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $sets = [];
    if (!empty($meta['bonuses']) && is_array($meta['bonuses'])) {
        $sets[] = $meta['bonuses'];
    }
    if (!empty($meta['modifiers']) && is_array($meta['modifiers'])) {
        $sets[] = $meta['modifiers'];
    }
    foreach (['stats', 'attributes'] as $metaKey) {
        if (!empty($meta[$metaKey]) && is_array($meta[$metaKey])) {
            foreach ($meta[$metaKey] as $stat => $value) {
                if (array_key_exists($stat, $attributes)) {
                    $items[] = [
                        'source' => $source,
                        'type' => 'attribute_bonus',
                        'target' => $stat,
                        'value' => (float)$value,
                        'requires_choice' => false,
                    ];
                }
            }
        }
    }

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        if (empty($data)) {
            continue;
        }
        if (!empty($data['bonuses']) && is_array($data['bonuses'])) {
            $sets[] = $data['bonuses'];
        }
        if (!empty($data['modifiers']) && is_array($data['modifiers'])) {
            $sets[] = $data['modifiers'];
        }
        foreach (['stats', 'attributes'] as $blockKey) {
            if (!empty($data[$blockKey]) && is_array($data[$blockKey])) {
                foreach ($data[$blockKey] as $stat => $value) {
                    if (array_key_exists($stat, $attributes)) {
                        $items[] = [
                            'source' => $source,
                            'type' => 'attribute_bonus',
                            'target' => $stat,
                            'value' => (float)$value,
                            'requires_choice' => false,
                        ];
                    }
                }
            }
        }
    }

    foreach ($sets as $set) {
        foreach ($set as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            $target = (string)($item['target'] ?? $item['stat'] ?? $item['attribute'] ?? $item['skill'] ?? $item['key'] ?? '');
            $value = $item['value'] ?? $item['amount'] ?? 0;
            $requires_choice = !empty($item['requires_choice']) || !empty($item['choice']) || !empty($item['requiresChoice']);

            if ($type === '') {
                if ($target !== '' && array_key_exists($target, $attributes)) {
                    $type = 'attribute_bonus';
                } elseif ($target !== '') {
                    $type = 'skill_bonus';
                }
            }

            if ($type === '') {
                continue;
            }

            $items[] = [
                'source' => $source,
                'type' => $type,
                'target' => $target !== '' ? $target : null,
                'value' => (float)$value,
                'requires_choice' => $requires_choice,
            ];
        }
    }

    $normalized = [];
    $i = 0;
    foreach ($items as $item) {
        $item['id'] = $source . ':' . $key . ':' . $i++;
        $normalized[] = $item;
    }

    return $normalized;
}

function af_charactersheets_collect_bonus_items(array $base): array
{
    $items = [];
    $sources = [
        'race' => (string)($base['race_key'] ?? ''),
        'class' => (string)($base['class_key'] ?? ''),
        'themes' => (string)($base['theme_key'] ?? ''),
    ];
    foreach ($sources as $source => $key) {
        if ($key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($source, $key));
    }
    return $items;
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
    $skill_choice_requirements = [];
    $bonus_attr_points = 0;
    $bonus_skill_points = 0;
    $bonus_knowledge_choices = 0;
    $bonus_language_choices = 0;
    $bonus_skill_map = [];
    $bonus_languages = [];
    $bonus_knowledges = [];
    $bonus_sources = [];

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

    $bonus_items = af_charactersheets_collect_bonus_items($base);
    foreach ($bonus_items as $item) {
        $type = (string)($item['type'] ?? '');
        $target = $item['target'] ?? null;
        $value = $item['value'] ?? 0;
        $requires_choice = !empty($item['requires_choice']);
        $source = (string)($item['source'] ?? '');
        if ($source !== '' && !isset($bonus_sources[$source])) {
            $bonus_sources[$source] = true;
        }

        if ($type === 'attribute_bonus') {
            if ($requires_choice) {
                $choice_key = $choice_map[$source] ?? '';
                $chosen = $choice_key !== '' ? (string)($choices[$choice_key] ?? '') : '';
                $choice_requirements[$source] = [
                    'choice_key' => $choice_key,
                    'chosen' => $chosen,
                    'value' => (float)$value,
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
                $bonus[$target] += (float)$value;
            }
            continue;
        }

        if ($type === 'attribute_points') {
            $bonus_attr_points += (int)$value;
            continue;
        }

        if ($type === 'skill_points') {
            $bonus_skill_points += (int)$value;
            continue;
        }

        if ($type === 'skill_bonus') {
            if ($requires_choice && (string)$target === '') {
                $choice_key = 'skill_bonus_choice_' . md5((string)($item['id'] ?? $source . ':' . $value));
                $chosen = (string)($choices[$choice_key] ?? '');
                $skill_choice_requirements[] = [
                    'choice_key' => $choice_key,
                    'chosen' => $chosen,
                    'value' => (float)$value,
                ];
                if ($chosen === '') {
                    continue;
                }
                $target = $chosen;
            }
            if (is_string($target) && $target !== '') {
                $bonus_skill_map[$target] = ($bonus_skill_map[$target] ?? 0) + (float)$value;
            }
            continue;
        }

        if ($type === 'knowledge_choice') {
            $bonus_knowledge_choices += (int)$value;
            continue;
        }

        if ($type === 'language_choice') {
            $bonus_language_choices += (int)$value;
            continue;
        }

        if ($type === 'knowledge') {
            if (is_string($target) && $target !== '') {
                $bonus_knowledges[] = $target;
            }
            continue;
        }

        if ($type === 'language') {
            if (is_string($target) && $target !== '') {
                $bonus_languages[] = $target;
            }
            continue;
        }
    }

    $final = [];
    foreach ($attributes_base as $key => $value) {
        $final[$key] = (float)$value + (float)($attributes_allocated[$key] ?? 0) + (float)($bonus[$key] ?? 0);
    }

    $spent = 0;
    foreach ($attributes_allocated as $value) {
        $spent += (int)$value;
    }
    $pool_remaining = (int)($progress['attr_points_free'] ?? 0) + $bonus_attr_points;
    $pool_max = $pool_remaining + $spent;
    $remaining = $pool_remaining;
    if ($pool_remaining < 0) {
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

    $bonus_source_labels = [];
    foreach (array_keys($bonus_sources) as $source) {
        $entry = af_charactersheets_kb_get_entry($source, (string)($sources[$source] ?? ''));
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        if ($label === '') {
            $label = $source;
        }
        $bonus_source_labels[] = $label;
    }

    $skills_catalog = af_charactersheets_get_skills_catalog(true);
    $skills_invested = (array)($build['skills'] ?? []);
    $skills_view = [];
    $skills_spent = 0;
    foreach ($skills_catalog as $skill) {
        $slug = (string)$skill['slug'];
        $attr_key = (string)$skill['attr_key'];
        $base_val = (float)($final[$attr_key] ?? 0);
        $base_mod = (int)floor($base_val);
        $invested = (int)($skills_invested[$slug] ?? 0);
        if ($invested < 0) {
            $invested = 0;
        }
        $skills_spent += $invested;
        $bonus_val = (float)($bonus_skill_map[$slug] ?? 0);
        $total = $base_mod + $invested + $bonus_val;

        $skills_view[] = [
            'slug' => $slug,
            'title' => (string)$skill['title'],
            'attr_key' => $attr_key,
            'attr_label' => $attributes_labels[$attr_key] ?? $attr_key,
            'base' => $base_mod,
            'invested' => $invested,
            'bonus' => $bonus_val,
            'total' => $total,
        ];
    }

    $skill_pool_remaining = (int)($progress['skill_points_free'] ?? 0) + $bonus_skill_points;
    $skill_pool_total = $skill_pool_remaining + $skills_spent;
    if ($skill_pool_remaining < 0) {
        $errors[] = 'Превышен лимит очков навыков.';
    }

    $knowledge_build = (array)($build['knowledge'] ?? []);
    $knowledge_selected = array_values(array_unique(array_filter((array)($knowledge_build['knowledges'] ?? []))));
    $language_selected = array_values(array_unique(array_filter((array)($knowledge_build['languages'] ?? []))));
    $bonus_languages = array_values(array_unique($bonus_languages));
    $bonus_knowledges = array_values(array_unique($bonus_knowledges));

    $knowledge_base_choices = (int)($mybb->settings['af_charactersheets_knowledge_base_choices'] ?? 0);
    $knowledge_per_int = (float)($mybb->settings['af_charactersheets_knowledge_per_int'] ?? 0);
    $int_value = (float)($final['int'] ?? 0);
    $knowledge_from_int = (int)floor($int_value * $knowledge_per_int);
    $knowledge_total_choices = $knowledge_base_choices + $knowledge_from_int + $bonus_knowledge_choices;
    $language_total_choices = $bonus_language_choices;

    $knowledge_remaining = $knowledge_total_choices - count($knowledge_selected);
    $language_remaining = $language_total_choices - count($language_selected);
    if ($knowledge_remaining < 0) {
        $errors[] = 'Превышен лимит знаний.';
    }
    if ($language_remaining < 0) {
        $errors[] = 'Превышен лимит языков.';
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
        'skill_choice_details' => $skill_choice_requirements,
        'labels' => $attributes_labels,
        'level' => $level_data['level'],
        'level_percent' => $level_data['percent'],
        'level_exp_label' => number_format($exp, 2, '.', ' ') . ' / ' . number_format($level_data['next_req'], 2, '.', ' '),
        'exp' => $exp,
        'next_req' => $level_data['next_req'],
        'skills' => $skills_view,
        'skill_pool_total' => $skill_pool_total,
        'skill_pool_spent' => $skills_spent,
        'skill_pool_remaining' => $skill_pool_remaining,
        'bonus_attr_points' => $bonus_attr_points,
        'bonus_skill_points' => $bonus_skill_points,
        'bonus_sources' => $bonus_source_labels,
        'knowledge' => [
            'selected' => $knowledge_selected,
            'bonus' => $bonus_knowledges,
            'total_choices' => $knowledge_total_choices,
            'remaining' => $knowledge_remaining,
        ],
        'languages' => [
            'selected' => $language_selected,
            'bonus' => $bonus_languages,
            'total_choices' => $language_total_choices,
            'remaining' => $language_remaining,
        ],
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

function af_charactersheets_get_pools(int $sheet_id): array
{
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return [];
    }
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    return [
        'attribute' => (int)($progress['attr_points_free'] ?? 0),
        'skill' => (int)($progress['skill_points_free'] ?? 0),
    ];
}

function af_charactersheets_log_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): void
{
    global $db;
    if (!$db->table_exists(AF_CS_POINTS_LEDGER_TABLE)) {
        return;
    }
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return;
    }
    $db->insert_query(AF_CS_POINTS_LEDGER_TABLE, af_charactersheets_db_escape_array([
        'sheet_id' => $sheet_id,
        'uid' => (int)($sheet['uid'] ?? 0),
        'point_type' => $type,
        'amount' => $amount,
        'reason' => $reason,
        'meta_json' => af_charactersheets_json_encode($meta),
        'created_at' => TIME_NOW,
    ]));
}

function af_charactersheets_add_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): bool
{
    if ($amount <= 0) {
        return false;
    }
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }
    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $key = $type === 'skill' ? 'skill_points_free' : 'attr_points_free';
    $progress[$key] = (int)($progress[$key] ?? 0) + $amount;
    af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    af_charactersheets_log_points($sheet_id, $type, $amount, $reason, $meta);
    return true;
}

function af_charactersheets_spend_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): bool
{
    if ($amount <= 0) {
        return false;
    }
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }
    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $key = $type === 'skill' ? 'skill_points_free' : 'attr_points_free';
    $progress[$key] = (int)($progress[$key] ?? 0) - $amount;
    af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    af_charactersheets_log_points($sheet_id, $type, -$amount, $reason, $meta);
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

function af_charactersheets_build_attributes_html(array $view, bool $can_edit, bool $can_view_pool): string
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
                . '" data-afcs-attr-input="' . htmlspecialchars_uni($key) . '" class="af-cs-attr-input" />'
            : '<span class="af-cs-attr-readonly">' . htmlspecialchars_uni((string)$allocated) . '</span>';

        $rows[] = '<div class="af-cs-attr-card">'
            . '<div class="af-cs-attr-card__head">'
            . '<div class="af-cs-attr-label">' . htmlspecialchars_uni($label) . '</div>'
            . '<div class="af-cs-attr-final">' . htmlspecialchars_uni((string)$final) . '</div>'
            . '</div>'
            . '<div class="af-cs-attr-card__meta">'
            . '<div class="af-cs-attr-card__stat"><span>База</span><strong>' . htmlspecialchars_uni((string)$base) . '</strong></div>'
            . '<div class="af-cs-attr-card__stat"><span>Бонус</span><strong>' . htmlspecialchars_uni((string)$bonus) . '</strong></div>'
            . '<div class="af-cs-attr-card__stat"><span>Распределено</span>' . $input . '</div>'
            . '</div>'
            . '</div>';
    }

    $attributes_rows_html = implode('', $rows);
    $attributes_pool_max = (int)($view['pool_max'] ?? 0);
    $attributes_pool_remaining = (int)($view['remaining'] ?? 0);
    $attributes_pool_spent = (int)($view['spent'] ?? 0);
    $attributes_pool_html = '';
    if ($can_view_pool) {
        $attributes_pool_html = '<div class="af-cs-attr-pool">'
            . '<div>Пул: <strong>' . htmlspecialchars_uni((string)$attributes_pool_max) . '</strong></div>'
            . '<div>Распределено: <strong data-afcs-pool-spent>' . htmlspecialchars_uni((string)$attributes_pool_spent) . '</strong></div>'
            . '<div>Осталось: <strong data-afcs-pool-remaining>' . htmlspecialchars_uni((string)$attributes_pool_remaining) . '</strong></div>'
            . '</div>';
    }

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

    $attributes_can_edit = $can_edit ? 1 : 0;

    global $templates;
    $tpl = $templates->get('charactersheet_attributes');
    eval("\$out = \"" . $tpl . "\";");
    return $out;

}

function af_charactersheets_build_progress_html(array $view, array $sheet, bool $can_award, bool $can_view_ledger): string
{
    $sheet_id = (int)($sheet['id'] ?? 0);
    $level = (int)($view['level'] ?? 1);
    $exp = (float)($view['exp'] ?? 0);
    $next = (float)($view['next_req'] ?? 0);
    $percent = (int)($view['level_percent'] ?? 0);
    $exp_label = htmlspecialchars_uni((string)($view['level_exp_label'] ?? ''));

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $attr_points_free = (int)($progress['attr_points_free'] ?? 0);
    $skill_points_free = (int)($progress['skill_points_free'] ?? 0);
    $bonus_attr_points = (int)($view['bonus_attr_points'] ?? 0);
    $bonus_skill_points = (int)($view['bonus_skill_points'] ?? 0);

    $points_html = '';
    if ($can_view_ledger) {
        $points_html = '<div>Свободные очки атрибутов: <strong>' . htmlspecialchars_uni((string)($attr_points_free + $bonus_attr_points)) . '</strong></div>'
            . '<div>Свободные очки навыков: <strong>' . htmlspecialchars_uni((string)($skill_points_free + $bonus_skill_points)) . '</strong></div>';
        $bonus_sources = (array)($view['bonus_sources'] ?? []);
        if ($bonus_sources) {
            $points_html .= '<div class="af-cs-muted">Источник бонусов: '
                . htmlspecialchars_uni(implode(', ', $bonus_sources))
                . '</div>';
        }
    }
    // ---- LEDGER (только если есть права) ----
    $ledger_html = '';
    $ledger_toggle_html = '';
    $ledger_block_html = '';

    if ($can_view_ledger) {
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

        // toggle + block (как ты просила)
        $ledger_toggle_html = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-ledger-toggle>История начислений</button>';
        $ledger_block_html = '<div class="af-cs-ledger" data-afcs-ledger hidden>' . $ledger_html . '</div>';
    }

    // ---- MANUAL AWARD (только если can_award) ----
    $manual_award_html = '';
    $manual_award_toggle_html = '';
    if ($can_award) {
        $manual_award_toggle_html = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-award-toggle>Ручное начисление</button>';
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

    if (in_array($do, ['save_attributes', 'save_choice', 'grant_exp', 'update_skill', 'add_knowledge', 'remove_knowledge'], true)) {
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

        $prev_build = $build;
        $build['attributes_allocated'] = $sanitized;
        $prev_spent = 0;
        foreach ((array)($prev_build['attributes_allocated'] ?? []) as $value) {
            $prev_spent += (int)$value;
        }
        $new_spent = 0;
        foreach ($sanitized as $value) {
            $new_spent += (int)$value;
        }
        $delta = $new_spent - $prev_spent;
        $view = af_charactersheets_compute_sheet_view($sheet);
        $available = (int)($progress['attr_points_free'] ?? 0) + (int)($view['bonus_attr_points'] ?? 0);
        if ($delta > $available) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Not enough attribute points']);
        }
        $progress['attr_points_free'] = (int)($progress['attr_points_free'] ?? 0) - $delta;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
        if ($delta !== 0) {
            af_charactersheets_log_points(
                $sheet_id,
                'attribute',
                -$delta,
                'attributes_allocation',
                ['delta' => $delta]
            );
        }
    } elseif ($do === 'save_choice') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        $choice_key = (string)$mybb->get_input('choice_key');
        $choice_value = (string)$mybb->get_input('choice_value');
        $allowed_attr_choices = ['race_attr_bonus_choice', 'class_attr_bonus_choice', 'theme_attr_bonus_choice'];
        if (in_array($choice_key, $allowed_attr_choices, true)) {
            if (!array_key_exists($choice_value, af_charactersheets_default_attributes())) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attribute']);
            }
        } elseif (strpos($choice_key, 'skill_bonus_choice_') === 0) {
            $skills = af_charactersheets_get_skills_catalog(true);
            $allowed = array_map(static function ($row) {
                return (string)($row['slug'] ?? '');
            }, $skills);
            if ($choice_value === '' || !in_array($choice_value, $allowed, true)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid skill']);
            }
        } else {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid choice']);
        }
        $build['choices'][$choice_key] = $choice_value;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'update_skill') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $slug = (string)$mybb->get_input('slug');
        $delta = (int)$mybb->get_input('delta');
        if ($slug === '' || ($delta !== 1 && $delta !== -1)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid skill update']);
        }
        $skills = af_charactersheets_get_skills_catalog(true);
        $allowed = array_map(static function ($row) {
            return (string)($row['slug'] ?? '');
        }, $skills);
        if (!in_array($slug, $allowed, true)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Unknown skill']);
        }

        $current = (int)($build['skills'][$slug] ?? 0);
        $next = max(0, $current + $delta);

        $view = af_charactersheets_compute_sheet_view($sheet);
        $available = (int)($progress['skill_points_free'] ?? 0) + (int)($view['bonus_skill_points'] ?? 0);
        if ($delta > $available) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Not enough skill points']);
        }
        $progress['skill_points_free'] = (int)($progress['skill_points_free'] ?? 0) - $delta;
        $build['skills'][$slug] = $next;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
        if ($delta !== 0) {
            af_charactersheets_log_points(
                $sheet_id,
                'skill',
                -$delta,
                'skill_allocation',
                ['slug' => $slug, 'delta' => $delta]
            );
        }
    } elseif ($do === 'add_knowledge' || $do === 'remove_knowledge') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $type = (string)$mybb->get_input('type');
        $key = (string)$mybb->get_input('key');
        if (!in_array($type, ['knowledge', 'language'], true) || $key === '') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid knowledge request']);
        }
        $knowledge = (array)($build['knowledge'] ?? []);
        $list_key = $type === 'knowledge' ? 'knowledges' : 'languages';
        $selected = array_values(array_unique(array_filter((array)($knowledge[$list_key] ?? []))));

        if ($do === 'add_knowledge') {
            $view = af_charactersheets_compute_sheet_view($sheet);
            $remaining = $type === 'knowledge'
                ? (int)($view['knowledge']['remaining'] ?? 0)
                : (int)($view['languages']['remaining'] ?? 0);
            if ($remaining <= 0) {
                af_charactersheets_json_response(['success' => false, 'error' => 'No slots available']);
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            if (empty($entry)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Unknown knowledge']);
            }
            if (!in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        } else {
            $selected = array_values(array_filter($selected, static function ($item) use ($key) {
                return $item !== $key;
            }));
        }

        $knowledge[$list_key] = $selected;
        $build['knowledge'] = $knowledge;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'grant_exp') {
        if (!$can_award) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $amount_raw = (string)$mybb->get_input('amount');
        $amount = af_charactersheets_to_number($amount_raw);
        $reason = trim((string)$mybb->get_input('reason'));
        if ($amount === null || $amount == 0.0) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Amount is invalid']);
        }
        if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_negative'])) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Negative EXP not allowed']);
        }
        if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_overdraw'])) {
            $current_exp = (float)($progress['exp'] ?? 0);
            if ($current_exp + $amount < 0) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Not enough EXP to subtract']);
            }
        }
        $event_key = 'manual:' . $sheet_id . ':' . TIME_NOW . ':' . mt_rand(1000, 9999);
        $meta = [
            'reason' => $reason,
            'awarded_by' => (int)($mybb->user['uid'] ?? 0),
            'awarded_by_username' => (string)($mybb->user['username'] ?? ''),
        ];
        if (!af_charactersheets_grant_exp($sheet_id, (float)$amount, $event_key, 'manual', $meta)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'EXP update failed']);
        }
    } else {
        af_charactersheets_json_response(['success' => false, 'error' => 'Unknown action']);
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    $view = af_charactersheets_compute_sheet_view($sheet);
    // попытаемся получить fid по tid (для is_moderator)
    $fid_for_mod = 0;
    if (!empty($sheet['tid'])) {
        global $db;
        $tid = (int)$sheet['tid'];
        if ($tid > 0) {
            $fid_for_mod = (int)$db->fetch_field(
                $db->simple_select('threads', 'fid', 'tid=' . $tid, ['limit' => 1]),
                'fid'
            );
        }
    }

    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $attributes_html = af_charactersheets_build_attributes_html($view, $can_edit, $can_view_ledger);
    $progress_html = af_charactersheets_build_progress_html($view, $sheet, $can_award, $can_view_ledger);
    $skills_html = af_charactersheets_build_skills_html($view, $can_edit, $can_view_ledger);
    $knowledge_html = af_charactersheets_build_knowledge_html($view, $can_edit, $can_view_ledger);


    af_charactersheets_json_response([
        'success' => true,
        'view' => $view,
        'attributes_html' => $attributes_html,
        'progress_html' => $progress_html,
        'skills_html' => $skills_html,
        'knowledge_html' => $knowledge_html,
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

    global $db;
    $post_row = $db->fetch_array($db->simple_select('posts', 'pid,fid,uid,visible,message', 'pid=' . $pid, ['limit' => 1]));
    if (!is_array($post_row) || empty($post_row)) {
        return;
    }

    $uid = (int)($post_row['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if ((int)($post_row['visible'] ?? 1) !== 1) {
        return;
    }

    $fid = (int)($post_row['fid'] ?? 0);
    if (!af_charactersheets_is_exp_forum_allowed($fid)) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    $message = (string)($post_row['message'] ?? $mybb->get_input('message'));
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
        ['pid' => $pid, 'chars' => $chars, 'fid' => $fid]
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

function af_charactersheets_expand_forum_ids_with_children(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($n) {
        return $n > 0;
    }));
    if (!$ids) {
        return [];
    }

    $want = array_fill_keys($ids, true);

    global $cache;
    $forums = null;
    if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
        $forums = $cache->read('forums');
    }
    if (!is_array($forums) || empty($forums)) {
        if (function_exists('cache_forums')) {
            @cache_forums();
            if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
                $forums = $cache->read('forums');
            }
        }
    }
    if (!is_array($forums) || empty($forums)) {
        sort($ids);
        return $ids;
    }

    $out = $want;
    foreach ($forums as $fid => $forum) {
        $fid = (int)$fid;
        if ($fid <= 0) {
            continue;
        }
        $parentlist = '';
        if (is_array($forum) && isset($forum['parentlist'])) {
            $parentlist = (string)$forum['parentlist'];
        }
        if ($parentlist === '' && is_array($forum) && isset($forum['pid'])) {
            $pid = (int)$forum['pid'];
            $chain = [$fid];
            $guard = 0;
            while ($pid > 0 && $guard++ < 50) {
                $chain[] = $pid;
                if (!isset($forums[$pid]) || !is_array($forums[$pid])) {
                    break;
                }
                $pid = (int)($forums[$pid]['pid'] ?? 0);
            }
            $parentlist = implode(',', array_reverse($chain));
        }
        if ($parentlist === '') {
            continue;
        }
        $pl = ',' . $parentlist . ',';
        foreach ($ids as $x) {
            if (strpos($pl, ',' . $x . ',') !== false) {
                $out[$fid] = true;
                break;
            }
        }
    }

    $result = array_keys($out);
    sort($result);
    return $result;
}

function af_charactersheets_is_exp_forum_allowed(int $fid): bool
{
    global $mybb;

    if ($fid <= 0) {
        return false;
    }

    $mode = (string)($mybb->settings['af_charactersheets_exp_forum_mode'] ?? 'include');
    $category_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_categories'] ?? ''));
    $forum_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_forums'] ?? ''));
    $exclude_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_exclude'] ?? ''));

    if ($exclude_ids && in_array($fid, $exclude_ids, true)) {
        return false;
    }

    $selected = array_values(array_unique(array_merge(
        $forum_ids,
        af_charactersheets_expand_forum_ids_with_children($category_ids)
    )));

    if ($mode === 'exclude') {
        if (!$selected) {
            return true;
        }
        return !in_array($fid, $selected, true);
    }

    if (!$selected) {
        return true;
    }
    return in_array($fid, $selected, true);
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

    if (!$db->table_exists(AF_CS_POINTS_LEDGER_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_POINTS_LEDGER_TABLE." (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              sheet_id INT UNSIGNED NOT NULL,
              uid INT UNSIGNED NOT NULL,
              point_type VARCHAR(32) NOT NULL,
              amount INT NOT NULL DEFAULT 0,
              reason VARCHAR(190) NOT NULL,
              meta_json TEXT NOT NULL,
              created_at INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY sheet_id (sheet_id),
              KEY uid (uid),
              KEY point_type (point_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    if (!$db->table_exists(AF_CS_SKILLS_CATALOG_TABLE)) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."".AF_CS_SKILLS_CATALOG_TABLE." (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              slug VARCHAR(120) NOT NULL,
              title VARCHAR(190) NOT NULL,
              attr_key VARCHAR(16) NOT NULL,
              description TEXT NOT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              active TINYINT(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (id),
              UNIQUE KEY slug (slug),
              KEY active (active),
              KEY sort_order (sort_order)
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

function af_charactersheets_get_asset_version(): string
{
    $css = AF_CS_BASE . 'assets/charactersheets.css';
    $js = AF_CS_BASE . 'assets/charactersheets.js';
    $timestamps = [];
    if (is_file($css)) {
        $timestamps[] = (int)filemtime($css);
    }
    if (is_file($js)) {
        $timestamps[] = (int)filemtime($js);
    }
    if (!$timestamps) {
        return AF_CS_ASSET_FALLBACK_VERSION;
    }
    return (string)max($timestamps);
}

function af_charactersheets_ensure_assets_in_headerinclude(): void
{
    global $headerinclude;

    // уже вставляли этим хелпером
    if (!empty($GLOBALS['af_charactersheets_assets_included'])) {
        return;
    }

    $assets = af_charactersheets_get_asset_urls();

    // если в headerinclude уже есть эти файлы (с ?v= или без) — не дублируем
    $hasCss = (stripos($headerinclude, 'charactersheets.css') !== false);
    $hasJs  = (stripos($headerinclude, 'charactersheets.js') !== false);

    if (!$hasCss) {
        $headerinclude .= "\n" . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '" />' . "\n";
    }
    if (!$hasJs) {
        $headerinclude .= "\n" . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '"></script>' . "\n";
    }

    // маркер — чтобы не пытаться добавить второй раз
    $GLOBALS['af_charactersheets_assets_included'] = true;
}

function af_charactersheets_canonicalize_assets_html(string $html): string
{
    $assets = af_charactersheets_get_asset_urls();

    // 1) вырезаем ВСЕ варианты charactersheets.css (с ?v=, без, с другими параметрами)
    $html = preg_replace(
        '~<link\b[^>]*href=("|\')[^"\']*charactersheets\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '',
        $html
    );

    // 2) вырезаем ВСЕ варианты charactersheets.js
    $html = preg_replace(
        '~<script\b[^>]*src=("|\')[^"\']*charactersheets\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i',
        '',
        $html
    );

    // 3) вставляем каноничный набор один раз
    $inject = "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '"></script>' . "\n";

    if (stripos($html, '</head>') !== false) {
        $html2 = preg_replace('~</head>~i', $inject . '</head>', $html, 1);
        return is_string($html2) ? $html2 : ($inject . $html);
    }

    return $inject . $html;
}

function af_charactersheets_inject_assets(string $page): string
{
    // если уже вставляли маркером — выходим
    if (strpos($page, AF_CS_ASSET_MARK) !== false) {
        return $page;
    }

    // если уже есть ссылки/скрипты на эти ассеты (с ?v= или без) — тоже выходим
    if (stripos($page, 'charactersheets.css') !== false || stripos($page, 'charactersheets.js') !== false) {
        return $page;
    }

    $assets = af_charactersheets_get_asset_urls();

    $inject = "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page2 = preg_replace('~</head>~i', $inject . '</head>', $page, 1);
        return is_string($page2) ? $page2 : ($inject . $page);
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
    $anchors = [
        '{$post[\'user_details\']}',
        '{$post[\'usercontact\']}',
        '{$post[\'userstars\']}',
    ];
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
        $new = '';
        foreach ($anchors as $anchor) {
            if (strpos($tpl, $anchor) !== false) {
                $new = str_replace($anchor, $anchor . "\n" . $needle, $tpl);
                break;
            }
        }
        if ($new === '') {
            if (stripos($tpl, '</td>') !== false) {
                $new = preg_replace('~</td>~i', $needle . "\n</td>", $tpl, 1);
            } elseif (stripos($tpl, '</div>') !== false) {
                $new = preg_replace('~</div>~i', $needle . "\n</div>", $tpl, 1);
            } else {
                $new = $tpl . "\n" . $needle;
            }
        }

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

function af_charactersheets_build_skills_html(array $view, bool $can_edit, bool $can_view_pool): string
{
    $skills = (array)($view['skills'] ?? []);
    $items = [];
    foreach ($skills as $skill) {
        $slug = (string)($skill['slug'] ?? '');
        $title = (string)($skill['title'] ?? '');
        $attr_label = (string)($skill['attr_label'] ?? '');
        $base = (int)($skill['base'] ?? 0);
        $invested = (int)($skill['invested'] ?? 0);
        $bonus = (float)($skill['bonus'] ?? 0);
        $total = (float)($skill['total'] ?? 0);

        $controls = '';
        if ($can_edit && $slug !== '') {
            $controls = '<div class="af-cs-skill-controls">'
                . '<button type="button" class="af-cs-skill-btn" data-afcs-skill-change="1" data-slug="' . htmlspecialchars_uni($slug) . '" data-delta="-1">−</button>'
                . '<span class="af-cs-skill-invested">' . htmlspecialchars_uni((string)$invested) . '</span>'
                . '<button type="button" class="af-cs-skill-btn" data-afcs-skill-change="1" data-slug="' . htmlspecialchars_uni($slug) . '" data-delta="1">+</button>'
                . '</div>';
        } else {
            $controls = '<div class="af-cs-skill-controls"><span class="af-cs-skill-invested">' . htmlspecialchars_uni((string)$invested) . '</span></div>';
        }

        $items[] = '<div class="af-cs-skill-item">'
            . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title)
            . '<span>(от ' . htmlspecialchars_uni($attr_label) . ')</span>'
            . '</div>'
            . '<div class="af-cs-skill-meta">'
            . '<div class="af-cs-skill-base">База: <strong>' . htmlspecialchars_uni((string)$base) . '</strong></div>'
            . '<div class="af-cs-skill-bonus">Бонус: <strong>' . htmlspecialchars_uni((string)$bonus) . '</strong></div>'
            . '</div>'
            . '<div class="af-cs-skill-right">'
            . $controls
            . '<div class="af-cs-skill-total">Итог: <strong>' . htmlspecialchars_uni((string)$total) . '</strong></div>'
            . '</div>'
            . '</div>';
    }

    if (!$items) {
        $items[] = '<div class="af-cs-muted">Навыков пока нет.</div>';
    }

    $skills_html = implode('', $items);

    $skill_pool_html = '';
    if ($can_view_pool) {
        $skill_pool_html = '<div class="af-cs-skill-pool">'
            . '<div>Пул навыков: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_total'] ?? 0)) . '</strong></div>'
            . '<div>Распределено: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_spent'] ?? 0)) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_remaining'] ?? 0)) . '</strong></div>'
            . '</div>';
    }

    $choice_html = '';
    if ($can_edit && !empty($view['skill_choice_details'])) {
        $options = '';
        foreach ($skills as $skill) {
            $slug = (string)($skill['slug'] ?? '');
            $title = (string)($skill['title'] ?? '');
            if ($slug === '') {
                continue;
            }
            $options .= '<option value="' . htmlspecialchars_uni($slug) . '">' . htmlspecialchars_uni($title) . '</option>';
        }

        $rows = [];
        foreach ($view['skill_choice_details'] as $choice) {
            $choice_key = (string)($choice['choice_key'] ?? '');
            $chosen = (string)($choice['chosen'] ?? '');
            if ($choice_key === '') {
                continue;
            }
            $select = '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '">';
            $select .= '<option value="">— выбрать навык —</option>';
            foreach ($skills as $skill) {
                $slug = (string)($skill['slug'] ?? '');
                $title = (string)($skill['title'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $selected = $chosen === $slug ? ' selected' : '';
                $select .= '<option value="' . htmlspecialchars_uni($slug) . '"' . $selected . '>' . htmlspecialchars_uni($title) . '</option>';
            }
            $select .= '</select>';

            $rows[] = '<div class="af-cs-choice-row">'
                . '<label>Бонус к любому навыку</label>'
                . $select
                . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить</button>'
                . '</div>';
        }
        if ($rows) {
            $choice_html = '<div class="af-cs-choices">' . implode('', $rows) . '</div>';
        }
    }

    global $templates;
    $tpl = $templates->get('charactersheet_skills');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_knowledge_html(array $view, bool $can_edit, bool $can_view_pool): string
{
    $knowledge_entries = af_charactersheets_get_kb_entries_by_type('knowledge');
    $language_entries = af_charactersheets_get_kb_entries_by_type('language');

    $knowledge_selected = (array)($view['knowledge']['selected'] ?? []);
    $knowledge_bonus = (array)($view['knowledge']['bonus'] ?? []);
    $knowledge_remaining = (int)($view['knowledge']['remaining'] ?? 0);
    $knowledge_total = (int)($view['knowledge']['total_choices'] ?? 0);

    $language_selected = (array)($view['languages']['selected'] ?? []);
    $language_bonus = (array)($view['languages']['bonus'] ?? []);
    $language_remaining = (int)($view['languages']['remaining'] ?? 0);
    $language_total = (int)($view['languages']['total_choices'] ?? 0);

    $knowledge_options = '<option value="">— выбрать знание —</option>';
    foreach ($knowledge_entries as $entry) {
        $key = (string)($entry['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        $knowledge_options .= '<option value="' . htmlspecialchars_uni($key) . '">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</option>';
    }

    $language_options = '<option value="">— выбрать язык —</option>';
    foreach ($language_entries as $entry) {
        $key = (string)($entry['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        $language_options .= '<option value="' . htmlspecialchars_uni($key) . '">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</option>';
    }

    $knowledge_items = [];
    foreach ($knowledge_bonus as $key) {
        $entry = af_charactersheets_kb_get_entry('knowledge', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $knowledge_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span>' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . '<em>Бонус</em>'
            . '</div>';
    }
    foreach ($knowledge_selected as $key) {
        $entry = af_charactersheets_kb_get_entry('knowledge', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $remove = $can_edit
            ? '<button type="button" data-afcs-knowledge-remove="1" data-afcs-knowledge-type="knowledge" data-afcs-knowledge-key="' . htmlspecialchars_uni($key) . '">×</button>'
            : '';
        $knowledge_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span>' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . $remove
            . '</div>';
    }
    if (!$knowledge_items) {
        $knowledge_items[] = '<div class="af-cs-muted">Пока нет знаний.</div>';
    }

    $language_items = [];
    foreach ($language_bonus as $key) {
        $entry = af_charactersheets_kb_get_entry('language', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $language_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span>' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . '<em>Бонус</em>'
            . '</div>';
    }
    foreach ($language_selected as $key) {
        $entry = af_charactersheets_kb_get_entry('language', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $remove = $can_edit
            ? '<button type="button" data-afcs-knowledge-remove="1" data-afcs-knowledge-type="language" data-afcs-knowledge-key="' . htmlspecialchars_uni($key) . '">×</button>'
            : '';
        $language_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span>' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . $remove
            . '</div>';
    }
    if (!$language_items) {
        $language_items[] = '<div class="af-cs-muted">Пока нет языков.</div>';
    }

    $knowledge_items_html = implode('', $knowledge_items);
    $language_items_html = implode('', $language_items);
    $knowledge_form = '';
    $language_form = '';
    if ($can_edit) {
        $knowledge_form = '<div class="af-cs-knowledge-form">'
            . '<select data-afcs-knowledge-select="knowledge">' . $knowledge_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-knowledge-add="1" data-afcs-knowledge-type="knowledge">Добавить</button>'
            . '</div>';
        $language_form = '<div class="af-cs-knowledge-form">'
            . '<select data-afcs-knowledge-select="language">' . $language_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-knowledge-add="1" data-afcs-knowledge-type="language">Добавить</button>'
            . '</div>';
    }

    $knowledge_pool_html = '';
    if ($can_view_pool) {
        $knowledge_pool_html = '<div class="af-cs-knowledge-pool">'
            . '<div>Доступно знаний: <strong>' . htmlspecialchars_uni((string)$knowledge_total) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)$knowledge_remaining) . '</strong></div>'
            . '</div>';
    }

    $language_pool_html = '';
    if ($can_view_pool) {
        $language_pool_html = '<div class="af-cs-knowledge-pool">'
            . '<div>Доступно языков: <strong>' . htmlspecialchars_uni((string)$language_total) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)$language_remaining) . '</strong></div>'
            . '</div>';
    }

    global $templates;
    $tpl = $templates->get('charactersheet_knowledge');
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

function af_charactersheets_get_kb_entries_by_type(string $type): array
{
    global $db;
    if (!$db->table_exists('af_kb_entries')) {
        return [];
    }

    $rows = [];
    $q = $db->simple_select(
        'af_kb_entries',
        '*',
        "type='" . $db->escape_string($type) . "' AND active=1",
        ['order_by' => 'id', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($q)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function af_charactersheets_get_skills_catalog(bool $activeOnly = true): array
{
    global $db;
    if (!$db->table_exists(AF_CS_SKILLS_CATALOG_TABLE)) {
        return [];
    }

    $where = $activeOnly ? 'active=1' : '1=1';
    $rows = [];
    $q = $db->simple_select(
        AF_CS_SKILLS_CATALOG_TABLE,
        '*',
        $where,
        ['order_by' => 'sort_order', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($q)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
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
    $slot = '<div class="af-cs-slot"><span>—</span></div>';
    $equip = '<div class="af-cs-inventory-equip">'
        . '<div class="af-cs-inventory-title">Экипировка</div>'
        . '<div class="af-cs-slot-grid">'
        . '<div class="af-cs-slot"><span>Голова</span></div>'
        . '<div class="af-cs-slot"><span>Торс</span></div>'
        . '<div class="af-cs-slot"><span>Руки</span></div>'
        . '<div class="af-cs-slot"><span>Ноги</span></div>'
        . '<div class="af-cs-slot"><span>Оружие</span></div>'
        . '<div class="af-cs-slot"><span>Оружие 2</span></div>'
        . '<div class="af-cs-slot"><span>Броня</span></div>'
        . '<div class="af-cs-slot"><span>Аксессуар</span></div>'
        . '</div>'
        . '</div>';

    $section = static function (string $title, int $count) use ($slot): string {
        return '<div class="af-cs-inventory-section">'
            . '<div class="af-cs-inventory-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-cs-slot-grid">' . str_repeat($slot, $count) . '</div>'
            . '</div>';
    };

    $craft = '<div class="af-cs-inventory-section af-cs-inventory-section--wide">'
        . '<div class="af-cs-inventory-title">Ремесленная сумка</div>'
        . '<div class="af-cs-slot-grid">' . str_repeat($slot, 10) . '</div>'
        . '</div>';

    return '<div class="af-cs-inventory" data-afcs-inventory>'
        . $equip
        . '<div class="af-cs-inventory-sections">'
        . $section('Броня', 6)
        . $section('Оружие', 6)
        . $section('Боеприпасы', 6)
        . $section('Инструменты', 6)
        . $craft
        . '</div>'
        . '</div>';
}

function af_charactersheets_build_augments_html(): string
{
    $slot = '<div class="af-cs-slot"><span>—</span></div>';
    $slots = '<div class="af-cs-slot-grid">'
        . '<div class="af-cs-slot"><span>Голова</span></div>'
        . '<div class="af-cs-slot"><span>Глаза</span></div>'
        . '<div class="af-cs-slot"><span>Руки</span></div>'
        . '<div class="af-cs-slot"><span>Торс</span></div>'
        . '<div class="af-cs-slot"><span>Ноги</span></div>'
        . '<div class="af-cs-slot"><span>Нервная система</span></div>'
        . '<div class="af-cs-slot"><span>Кожа</span></div>'
        . '<div class="af-cs-slot"><span>Имплант</span></div>'
        . '</div>';

    $available = '<div class="af-cs-slot-grid">'
        . str_repeat($slot, 8)
        . '</div>';

    return '<div class="af-cs-augmentations">'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-inventory-title">Слоты экипировки аугментаций</div>'
        . $slots
        . '</div>'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-inventory-title">Доступные аугментации</div>'
        . '<div class="af-cs-muted">Заглушка инвентаря аугментаций.</div>'
        . $available
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
    $asset_version = af_charactersheets_get_asset_version();
    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=' . $asset_version . '" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=' . $asset_version . '"></script>' . "\n";

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
