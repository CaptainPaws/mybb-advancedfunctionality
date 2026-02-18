<?php
/**
 * AF Addon: CharacterSheets
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Автопринятие анкеты с ответом, закрытием, переносом и триггером листа персонажа.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_charactersheets_is_installed_impl(): bool
{
    global $db;
    return $db->table_exists(AF_CS_TABLE);
}

function af_charactersheets_install_impl(): void
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

function af_charactersheets_activate_impl(): bool
{
    af_charactersheets_templates_install_or_update();
    af_charactersheets_ensure_schema();
    af_charactersheets_ensure_postbit_placeholder();
    return true;
}

function af_charactersheets_deactivate_impl(): bool
{
    return true;
}

function af_charactersheets_uninstall_impl(): void
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
    if ($db->table_exists(AF_CS_SKILLS_TABLE)) {
        $db->drop_table(AF_CS_SKILLS_TABLE);
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
        'af_charactersheets_knowledge_per_int',
        'af_charactersheets_humanity_base',
        'af_charactersheets_aug_slots_json'
    )");
    $db->delete_query('settinggroups', "name='af_charactersheets'");
    $db->delete_query('templates', "title LIKE 'charactersheets_%'");
    $db->delete_query('templates', "title IN ('charactersheet_fullpage','charactersheet_inner','charactersheet_modal','postbit_plaque','charactersheet_rct_cards','charactersheet_stats_bars','charactersheet_attributes','charactersheet_progress','charactersheet_skills','charactersheet_feats','charactersheet_abilities','charactersheet_inventory','charactersheet_augmentations','charactersheet_equipment','charactersheet_knowledge','charactersheets_catalog','charactersheets_catalog_card')");

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

    $db->delete_query(
        'settings',
        "name IN ('af_charactersheets_hp_base','af_charactersheets_hp_per_con','af_charactersheets_hp_per_level','af_charactersheets_humanity_loss_per_aug')"
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
        '1',
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
        'af_charactersheets_humanity_base',
        $lang->af_charactersheets_humanity_base ?? 'Humanity base',
        $lang->af_charactersheets_humanity_base_desc ?? 'Base Humanity for all characters.',
        'text',
        '100',
        49
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_aug_slots_json',
        $lang->af_charactersheets_aug_slots_json ?? 'Augmentation slots',
        $lang->af_charactersheets_aug_slots_json_desc ?? 'JSON list of augmentation slots (slot_key, titles, icon, sortorder, max_equipped).',
        'textarea',
        '[{"slot_key":"head","title_ru":"Голова","title_en":"Head","icon":"fa-solid fa-helmet-safety","sortorder":10,"max_equipped":1},{"slot_key":"eyes","title_ru":"Глаза","title_en":"Eyes","icon":"fa-solid fa-eye","sortorder":20,"max_equipped":1},{"slot_key":"arms","title_ru":"Руки","title_en":"Arms","icon":"fa-solid fa-hand","sortorder":30,"max_equipped":1},{"slot_key":"body","title_ru":"Торс","title_en":"Body","icon":"fa-solid fa-heart","sortorder":40,"max_equipped":1},{"slot_key":"legs","title_ru":"Ноги","title_en":"Legs","icon":"fa-solid fa-person-walking","sortorder":50,"max_equipped":1},{"slot_key":"nervous","title_ru":"Нервная система","title_en":"Nervous system","icon":"fa-solid fa-brain","sortorder":60,"max_equipped":1},{"slot_key":"skin","title_ru":"Кожа","title_en":"Skin","icon":"fa-solid fa-hand-sparkles","sortorder":70,"max_equipped":1},{"slot_key":"implant","title_ru":"Имплант","title_en":"Implant","icon":"fa-solid fa-microchip","sortorder":80,"max_equipped":1}]',
        50
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_exp_manual_groups',
        $lang->af_charactersheets_exp_manual_groups ?? 'EXP manual award groups',
        $lang->af_charactersheets_exp_manual_groups_desc ?? 'CSV group ids allowed to grant experience manually.',
        'text',
        '4,3,6',
        54
    );

    $db->delete_query('settings', "name='af_charactersheets_accept_post_template'");
}

function af_charactersheets_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_charactersheets_enabled']);
}

/* -------------------- SHOWTHREAD BUTTON -------------------- */
function af_charactersheets_showthread_start_impl(): void
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

function af_charactersheets_pre_output_impl(&$page): void
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

function af_charactersheets_misc_start_impl(): void
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

    af_charactersheets_handle_accept_exp($tid, (int)$mybb->user['uid']);

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

function af_charactersheets_apply_purchase(int $sheet_id, string $kb_type, string $kb_key, int $qty, array $meta = []): bool
{
    return false;
}

function af_charactersheets_is_accepted(int $tid): bool
{
    $row = af_charactersheets_get_accept_row($tid);
    return !empty($row['accepted']);
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

    if (!$db->table_exists(AF_CS_SKILLS_TABLE)) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_CS_SKILLS_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT UNSIGNED NOT NULL,
            sheet_id INT UNSIGNED NOT NULL,
            skill_key VARCHAR(64) NOT NULL,
            skill_rank TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY sheet_skill (sheet_id, skill_key),
            KEY uid (uid),
            KEY skill_key (skill_key)
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

function af_charactersheets_format_signed($value): string
{
    $num = (float)$value;
    if (abs($num - round($num)) < 0.0001) {
        $num = (int)round($num);
    }
    $prefix = $num > 0 ? '+' : '';
    return $prefix . (string)$num;
}

function af_charactersheets_format_decimal($value): string
{
    // В БД amount = DECIMAL(12,4), прилетает строкой типа "100.0000"
    $s = trim((string)$value);
    if ($s === '') return '0';

    // Нормализуем запятую на всякий
    $s = str_replace(',', '.', $s);

    // Если есть дробная часть — срежем хвостовые нули
    if (strpos($s, '.') !== false) {
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        if ($s === '' || $s === '-' ) return '0';
    }

    return $s;
}

/**
 * Возвращает username по uid с простым статическим кэшем.
 */
function af_charactersheets_username_by_uid(int $uid): string
{
    static $cache = [];

    if ($uid <= 0) return '';
    if (isset($cache[$uid])) return (string)$cache[$uid];

    global $db;
    $name = '';
    $row = $db->fetch_array($db->simple_select('users', 'username', 'uid=' . (int)$uid, ['limit' => 1]));
    if (is_array($row) && !empty($row['username'])) {
        $name = (string)$row['username'];
    }

    $cache[$uid] = $name;
    return $name;
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

function cs_kb_get_entry($type, $key): ?array
{
    $entry = af_charactersheets_kb_get_entry((string)$type, (string)$key);
    return !empty($entry) ? $entry : null;
}

function cs_kb_get_data_rules($entry): array
{
    if (!is_array($entry)) {
        return [];
    }

    $raw = (string)($entry['data_json'] ?? $entry['rules_json'] ?? '');
    $decoded = af_charactersheets_json_decode($raw);
    if ((string)($decoded['schema'] ?? '') !== 'af_kb.rules.v1') {
        return [];
    }

    return cs_kb_rules_normalize($decoded);
}

function cs_kb_get_meta($entry): array
{
    if (!is_array($entry)) {
        return [];
    }

    $decoded = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    if ((string)($decoded['schema'] ?? '') !== 'af_kb.meta.v1') {
        return [];
    }

    return $decoded;
}

function cs_kb_get_block_text($meta, $block_key, $lang = ''): string
{
    if (!is_array($meta) || !is_array($meta['blocks'] ?? null)) {
        return '';
    }

    $lang = $lang !== '' ? $lang : (af_charactersheets_is_ru() ? 'ru' : 'en');
    foreach ((array)$meta['blocks'] as $block) {
        if (!is_array($block) || (string)($block['key'] ?? '') !== (string)$block_key) {
            continue;
        }

        $value = (string)($block['content_' . $lang] ?? '');
        if ($value === '') {
            $value = (string)($block['content_ru'] ?? $block['content_en'] ?? $block['content'] ?? '');
        }
        return trim($value);
    }

    return '';
}

function af_cs_kb_get_meta_blocks($type, $key): array
{
    $type = trim((string)$type);
    $key = trim((string)$key);
    if ($type === '' || $key === '') {
        return [];
    }

    $candidate_types = [$type];
    if ($type === 'theme') {
        $candidate_types[] = 'themes';
    } elseif ($type === 'themes') {
        $candidate_types[] = 'theme';
    }

    $entry = [];
    foreach ($candidate_types as $candidate) {
        $entry = af_charactersheets_kb_get_entry($candidate, $key);
        if (!empty($entry)) {
            break;
        }
    }

    if (empty($entry)) {
        return [];
    }

    $meta = cs_kb_get_meta($entry);
    $blocks = $meta['blocks'] ?? [];
    return is_array($blocks) ? $blocks : [];
}

function af_cs_kb_extract_block_content(array $block, bool $isRu): string
{
    $lang = $isRu ? 'ru' : 'en';

    $extract_value = static function ($value) use ($lang): string {
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }

        if (!is_array($value)) {
            return '';
        }

        $localized = (string)($value[$lang] ?? '');
        if ($localized !== '') {
            return trim($localized);
        }

        foreach (['ru', 'en'] as $fallback_lang) {
            $candidate = (string)($value[$fallback_lang] ?? '');
            if ($candidate !== '') {
                return trim($candidate);
            }
        }

        foreach (['text', 'value', 'html', 'content', 'body'] as $key) {
            $candidate = $value[$key] ?? null;
            if (is_string($candidate) || is_numeric($candidate)) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            } elseif (is_array($candidate)) {
                $localized = (string)($candidate[$lang] ?? $candidate['ru'] ?? $candidate['en'] ?? '');
                if ($localized !== '') {
                    return trim($localized);
                }
            }
        }

        return '';
    };

    $content_keys = [
        'data_' . $lang,
        'content_' . $lang,
        'body_' . $lang,
        'text_' . $lang,
        'html_' . $lang,
        'data',
        'content',
        'text',
        'body',
        'html',
    ];

    foreach ($content_keys as $field) {
        if (!array_key_exists($field, $block)) {
            continue;
        }

        $raw = $block[$field];
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                $piece = $extract_value($item);
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
            if (!empty($parts)) {
                return trim(implode("\n", $parts));
            }
            continue;
        }

        $text = $extract_value($raw);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

function af_cs_render_kb_bonuses_text($type, $key, $isRu): string
{
    global $db;

    $type = trim((string)$type);
    $key = trim((string)$key);
    if ($type === '' || $key === '') {
        return '';
    }

    $entry = af_charactersheets_kb_get_entry($type, $key);
    if (empty($entry['id'])) {
        return '';
    }

    if (!is_object($db) || !$db->table_exists('af_kb_blocks')) {
        return '';
    }

    $where = "entry_id=" . (int)$entry['id']
        . " AND block_key='bonuses'";
    if (!function_exists('af_kb_can_edit') || !af_kb_can_edit()) {
        $where .= ' AND active=1';
    }

    $block = $db->fetch_array($db->simple_select(
        'af_kb_blocks',
        'content_ru, content_en',
        $where,
        ['limit' => 1]
    ));
    if (!is_array($block)) {
        return '';
    }

    $localized_field = (bool)$isRu ? 'content_ru' : 'content_en';
    $text = trim((string)($block[$localized_field] ?? ''));
    if ($text === '') {
        $text = af_charactersheets_kb_pick_text($block, 'content');
    }
    if ($text === '') {
        return '';
    }

    if (function_exists('af_kb_parse_message')) {
        return trim((string)af_kb_parse_message($text));
    }

    return af_charactersheets_parse_bbcode($text);
}


function cs_kb_rules_normalize($dataJson): array
{
    $rules = is_array($dataJson) ? $dataJson : [];

    $statsZero = [
        'str' => 0,
        'dex' => 0,
        'con' => 0,
        'int' => 0,
        'wis' => 0,
        'cha' => 0,
    ];

    $fixed = is_array($rules['fixed'] ?? null) ? $rules['fixed'] : [];
    $fixedBonuses = is_array($rules['fixed_bonuses'] ?? null) ? $rules['fixed_bonuses'] : [];

    $fixedStats = $statsZero;
    foreach (['stats', 'attributes'] as $k) {
        if (!empty($fixed[$k]) && is_array($fixed[$k])) {
            foreach ($statsZero as $stat => $_) {
                if (isset($fixed[$k][$stat])) {
                    $fixedStats[$stat] = (int)$fixed[$k][$stat];
                }
            }
        }
    }

    $fixedBonusStats = $statsZero;
    foreach (['stats', 'attributes'] as $k) {
        if (!empty($fixedBonuses[$k]) && is_array($fixedBonuses[$k])) {
            foreach ($statsZero as $stat => $_) {
                if (isset($fixedBonuses[$k][$stat])) {
                    $fixedBonusStats[$stat] = (int)$fixedBonuses[$k][$stat];
                }
            }
        }
    }

    return [
        'schema' => (string)($rules['schema'] ?? ''),
        'speed' => (int)($rules['speed'] ?? $fixed['speed'] ?? 0),
        'fixed' => [
            'stats' => $fixedStats,
            'hp' => (int)($fixed['hp'] ?? 0),
            'armor' => (int)($fixed['armor'] ?? 0),
            'initiative' => (int)($fixed['initiative'] ?? 0),
            'speed' => (int)($fixed['speed'] ?? 0),
            'carry' => (int)($fixed['carry'] ?? 0),
            'ep' => (int)($fixed['ep'] ?? 0),
            'damage' => (int)($fixed['damage'] ?? 0),
            'skill_points' => (int)($fixed['skill_points'] ?? 0),
            'language_slots' => (int)($fixed['language_slots'] ?? 0),
        ],
        'fixed_bonuses' => [
            'stats' => $fixedBonusStats,
            'hp' => (int)($fixedBonuses['hp'] ?? 0),
            'armor' => (int)($fixedBonuses['armor'] ?? 0),
            'initiative' => (int)($fixedBonuses['initiative'] ?? 0),
            'speed' => (int)($fixedBonuses['speed'] ?? 0),
            'carry' => (int)($fixedBonuses['carry'] ?? 0),
            'ep' => (int)($fixedBonuses['ep'] ?? 0),
            'damage' => (int)($fixedBonuses['damage'] ?? 0),
            'skill_points' => (int)($fixedBonuses['skill_points'] ?? 0),
            'language_slots' => (int)($fixedBonuses['language_slots'] ?? 0),
            'attribute_points' => (int)($fixedBonuses['attribute_points'] ?? 0),
        ],
        'hp_base' => (int)($rules['hp_base'] ?? 0),
        'languages' => is_array($rules['languages'] ?? null) ? array_values($rules['languages']) : [],
        'choices' => is_array($rules['choices'] ?? null) ? array_values($rules['choices']) : [],
        'grants' => is_array($rules['grants'] ?? null) ? array_values($rules['grants']) : [],
        'traits' => is_array($rules['traits'] ?? null) ? array_values($rules['traits']) : [],
    ];
}

function af_charactersheets_kb_normalize_entry(array $entry): array
{
    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $entry_type = (string)($entry['type_key'] ?? $entry['type'] ?? '');
    $entry_key = (string)($entry['key'] ?? '');
    $data = af_cs_kb_get_data_rules($entry_type, $entry_key);

    if ((string)($meta['schema'] ?? '') !== 'af_kb.meta.v1') {
        $meta = [];
    }

    return [
        'entry' => $entry,
        'type_key' => $entry_type,
        'key' => $entry_key,
        'title' => af_charactersheets_kb_pick_text($entry, 'title'),
        'short' => af_charactersheets_kb_pick_text($entry, 'short'),
        'body' => af_charactersheets_kb_pick_text($entry, 'body'),
        'meta' => $meta,
        'data' => cs_kb_rules_normalize(is_array($data) ? $data : []),
    ];
}

function af_cs_kb_get_data_rules_result(string $kbType, string $kbKey): array
{
    static $cache = [];

    $kbType = trim($kbType);
    $kbKey = trim($kbKey);

    if ($kbType === '' || $kbKey === '') {
        return [
            'ok' => false,
            'reason' => 'NO_ROW',
            'schema' => '',
            'rules' => [],
            'meta' => [],
            'entry' => [],
        ];
    }

    $cache_key = $kbType . ':' . $kbKey;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    global $db;
    if (!is_object($db) || !$db->table_exists('af_kb_entries')) {
        return $cache[$cache_key] = [
            'ok' => false,
            'reason' => 'NO_ROW',
            'schema' => '',
            'rules' => [],
            'meta' => [],
            'entry' => [],
        ];
    }

    // 1) Собираем список полей ТОЛЬКО из тех, что реально существуют в БД
    $fields = ['id', 'type', '`key`'];

    if ($db->field_exists('meta_json', 'af_kb_entries')) {
        $fields[] = 'meta_json';
    }
    if ($db->field_exists('data_json', 'af_kb_entries')) {
        $fields[] = 'data_json';
    }
    if ($db->field_exists('rules_json_raw', 'af_kb_entries')) {
        $fields[] = 'rules_json_raw';
    }
    if ($db->field_exists('rules_json', 'af_kb_entries')) {
        $fields[] = 'rules_json';
    }
    if ($db->field_exists('active', 'af_kb_entries')) {
        $fields[] = 'active';
    }

    $select_fields = implode(',', $fields);

    $where = "type='" . $db->escape_string($kbType) . "' AND `key`='" . $db->escape_string($kbKey) . "'";
    if (in_array('active', $fields, true)) {
        $where .= " AND active=1";
    }

    $entry = $db->fetch_array($db->simple_select(
        'af_kb_entries',
        $select_fields,
        $where,
        ['limit' => 1]
    ));

    if (!is_array($entry) || empty($entry['id'])) {
        return $cache[$cache_key] = [
            'ok' => false,
            'reason' => 'NO_ROW',
            'schema' => '',
            'rules' => [],
            'meta' => [],
            'entry' => [],
        ];
    }

    // 2) Канон источника rules: data_json -> rules_json_raw -> rules_json
    $raw_data_json = '';

    if (isset($entry['data_json'])) {
        $raw_data_json = trim((string)$entry['data_json']);
    }
    if ($raw_data_json === '' && isset($entry['rules_json_raw'])) {
        $raw_data_json = trim((string)$entry['rules_json_raw']);
    }
    if ($raw_data_json === '' && isset($entry['rules_json'])) {
        $raw_data_json = trim((string)$entry['rules_json']);
    }

    // 3) Опциональный fallback в blocks (НО тоже безопасно по полям)
    if ($raw_data_json === '' && $db->table_exists('af_kb_blocks')) {
        $blockFields = [];
        if ($db->field_exists('data_json', 'af_kb_blocks')) {
            $blockFields[] = 'data_json';
        }
        if ($db->field_exists('content', 'af_kb_blocks')) {
            // вдруг у тебя rules хранятся в content (на всякий)
            $blockFields[] = 'content';
        }

        if (!empty($blockFields)) {
            $block = $db->fetch_array($db->simple_select(
                'af_kb_blocks',
                implode(',', $blockFields),
                "entry_id=" . (int)$entry['id'] . " AND block_key='data' " . ($db->field_exists('active', 'af_kb_blocks') ? "AND active=1" : ""),
                ['order_by' => 'sortorder,id', 'order_dir' => 'ASC', 'limit' => 1]
            ));

            if (is_array($block)) {
                if (isset($block['data_json']) && trim((string)$block['data_json']) !== '') {
                    $raw_data_json = trim((string)$block['data_json']);
                } elseif (isset($block['content']) && trim((string)$block['content']) !== '') {
                    $raw_data_json = trim((string)$block['content']);
                }
            }
        }
    }

    if ($raw_data_json === '') {
        return $cache[$cache_key] = [
            'ok' => false,
            'reason' => 'EMPTY_DATA',
            'schema' => '',
            'rules' => [],
            'meta' => cs_kb_get_meta($entry),
            'entry' => $entry,
        ];
    }

    $decoded = af_charactersheets_json_decode($raw_data_json);
    if (!is_array($decoded)) {
        return $cache[$cache_key] = [
            'ok' => false,
            'reason' => 'BAD_JSON',
            'schema' => '',
            'rules' => [],
            'meta' => cs_kb_get_meta($entry),
            'entry' => $entry,
        ];
    }

    $schema = (string)($decoded['schema'] ?? '');
    if ($schema !== 'af_kb.rules.v1') {
        return $cache[$cache_key] = [
            'ok' => false,
            'reason' => 'BAD_SCHEMA',
            'schema' => $schema,
            'rules' => [],
            'meta' => cs_kb_get_meta($entry),
            'entry' => $entry,
        ];
    }

    return $cache[$cache_key] = [
        'ok' => true,
        'reason' => 'OK',
        'schema' => $schema,
        'rules' => cs_kb_rules_normalize($decoded),
        'meta' => cs_kb_get_meta($entry),
        'entry' => $entry,
    ];
}

function af_cs_kb_get_data_rules(string $kbType, string $kbKey): array
{
    $result = af_cs_kb_get_data_rules_result($kbType, $kbKey);
    return (array)($result['rules'] ?? []);
}

function af_charactersheets_kb_resolve_entry(string $type_key, string $key): array
{
    static $cache = [];

    $type_key = trim($type_key);
    $key = trim($key);
    if ($type_key === '' || $key === '') {
        return [];
    }

    $cache_key = $type_key . ':' . $key;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $candidate_types = [$type_key];
    if ($type_key === 'theme') {
        $candidate_types[] = 'themes';
    } elseif ($type_key === 'themes') {
        $candidate_types[] = 'theme';
    }

    foreach ($candidate_types as $candidate_type) {
        $entry = af_charactersheets_kb_get_entry($candidate_type, $key);
        if (!empty($entry)) {
            return $cache[$cache_key] = af_charactersheets_kb_normalize_entry($entry);
        }
    }

    return $cache[$cache_key] = [];
}

function af_charactersheets_kb_get_resolved_by_type(string $type_key): array
{
    $rows = af_charactersheets_get_kb_entries_by_type($type_key);
    if (!$rows && $type_key === 'skill') {
        $rows = af_charactersheets_get_kb_entries_by_type('skills');
    }

    $result = [];
    foreach ($rows as $entry) {
        $normalized = af_charactersheets_kb_normalize_entry($entry);
        if (!empty($normalized['key'])) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function af_charactersheets_extract_skill_grants(array $resolved, string $source): array
{
    $data = (array)($resolved['data'] ?? []);
    $grants = [];

    $fixed = (array)($data['skills_fixed'] ?? []);
    foreach ($fixed as $skill_key) {
        $skill_key = trim((string)$skill_key);
        if ($skill_key === '') {
            continue;
        }
        $grants[$skill_key] = ['skill_key' => $skill_key, 'skill_rank' => 1, 'source' => $source];
    }

    foreach ((array)($data['skills_grants'] ?? []) as $grant) {
        if (!is_array($grant)) {
            continue;
        }
        $skill_key = trim((string)($grant['key'] ?? ''));
        if ($skill_key === '') {
            continue;
        }
        $skill_rank = max(1, (int)($grant['skill_rank'] ?? 1));
        $grants[$skill_key] = ['skill_key' => $skill_key, 'skill_rank' => $skill_rank, 'source' => $source];
    }

    foreach ((array)($data['grants'] ?? []) as $grant) {
        if (!is_array($grant)) {
            continue;
        }
        $grant_type = (string)($grant['type'] ?? $grant['kb_type'] ?? '');
        if (!in_array($grant_type, ['skill', 'skills'], true)) {
            continue;
        }
        $skill_key = trim((string)($grant['kb_key'] ?? $grant['key'] ?? ''));
        if ($skill_key === '') {
            continue;
        }
        $skill_rank = max(1, (int)($grant['skill_rank'] ?? $grant['value'] ?? 1));
        $grants[$skill_key] = ['skill_key' => $skill_key, 'skill_rank' => $skill_rank, 'source' => $source];
    }

    foreach ((array)($data['traits'] ?? []) as $trait) {
        if (!is_array($trait)) {
            continue;
        }
        foreach ((array)($trait['grants'] ?? []) as $grant) {
            if (!is_array($grant)) {
                continue;
            }
            $grant_type = (string)($grant['type'] ?? $grant['kb_type'] ?? '');
            if (!in_array($grant_type, ['skill', 'skills'], true)) {
                continue;
            }
            $skill_key = trim((string)($grant['kb_key'] ?? $grant['key'] ?? ''));
            if ($skill_key === '') {
                continue;
            }
            $skill_rank = max(1, (int)($grant['skill_rank'] ?? $grant['value'] ?? 1));
            $grants[$skill_key] = ['skill_key' => $skill_key, 'skill_rank' => $skill_rank, 'source' => $source];
        }
    }

    return array_values($grants);
}

function cs_rules_get_first_int(array $rules, array $paths): int
{
    foreach ($paths as $path) {
        $node = $rules;
        $ok = true;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $ok = false;
                break;
            }
            $node = $node[$segment];
        }
        if ($ok && (is_numeric($node) || is_string($node))) {
            return max(0, (int)$node);
        }
    }

    return 0;
}

function cs_get_skill_points_from_rules($rules): int
{
    if (!is_array($rules)) {
        return 0;
    }

    $points = max(0, (int)($rules['fixed']['skill_points'] ?? 0))
        + max(0, (int)($rules['fixed_bonuses']['skill_points'] ?? 0));

    foreach ((array)($rules['choices'] ?? []) as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $choice_type = (string)($choice['type'] ?? '');
        if ($choice_type === 'skill_pick_choice' && (string)($choice['grant_mode'] ?? '') === 'skill_points') {
            $points += max(0, (int)($choice['pick'] ?? 0)) * max(0, (int)($choice['points_value'] ?? 0));
            continue;
        }
        if (!in_array($choice_type, ['skill_pick', 'skill_points', 'skills_pick', 'kb_pick'], true)) {
            continue;
        }
        $kb_type = (string)($choice['kb_type'] ?? '');
        if ($choice_type === 'kb_pick' && !in_array($kb_type, ['skill', 'skills'], true)) {
            continue;
        }
        $points += max(0, (int)($choice['pick'] ?? $choice['value'] ?? 0));
    }

    return $points;
}

function af_cs_build_rules_sources(array $sheet): array
{
    $kb_sources = cs_get_sheet_kb_sources($sheet);
    $result = [];

    foreach (['race', 'class', 'theme'] as $type) {
        $key = trim((string)($kb_sources[$type] ?? ''));
        if ($key === '') {
            $result[$type] = [
                'type' => $type,
                'key' => '',
                'schema' => '',
                'rules' => cs_kb_rules_normalize([]),
                'valid' => false,
            ];
            continue;
        }

        $candidate_types = [$type];
        if ($type === 'theme') {
            $candidate_types[] = 'themes';
        } elseif ($type === 'themes') {
            $candidate_types[] = 'theme';
        }

        $source_result = [];
        $resolved_type = $type;
        foreach ($candidate_types as $candidate_type) {
            $source_result = af_cs_kb_get_data_rules_result($candidate_type, $key);
            $resolved_type = $candidate_type;
            if (!empty($source_result['entry']) || (string)($source_result['reason'] ?? '') !== 'NO_ROW') {
                break;
            }
        }

        $rules = cs_kb_rules_normalize((array)($source_result['rules'] ?? []));
        $schema = (string)($source_result['schema'] ?? '');
        $is_valid = (bool)($source_result['ok'] ?? false);
        $reason = (string)($source_result['reason'] ?? ($is_valid ? 'OK' : 'BAD_JSON'));

        $result[$type] = [
            'type' => $type,
            'key' => $key,
            'schema' => $schema,
            'rules' => $is_valid ? $rules : cs_kb_rules_normalize([]),
            'valid' => $is_valid,
            'reason' => $reason,
            'resolved_type' => $resolved_type,
            'entry' => (array)($source_result['entry'] ?? []),
        ];
    }

    return $result;
}

function af_cs_aggregate_rules(array $sources): array
{
    $stats = af_charactersheets_default_attributes();
    $fixed = [
        'stats' => $stats,
        'hp' => 0,
        'armor' => 0,
        'initiative' => 0,
        'speed' => 0,
        'carry' => 0,
        'ep' => 0,
        'damage' => 0,
        'skill_points' => 0,
        'language_slots' => 0,
    ];
    $fixed_bonuses = $fixed + ['attribute_points' => 0, 'feat_points' => 0, 'perk_points' => 0];
    $fixed_bonuses['stats'] = $stats;

    $totals = [
        'hp_base_total' => 0,
        'fixed_hp_total' => 0,
        'speed_base_total' => 0,
        'speed_total' => 0,
        'bonus_attribute_points' => 0,
        'bonus_skill_points' => 0,
        'points_pools' => [
            'attribute_points' => 0,
            'skill_points' => 0,
            'feat_points' => 0,
            'perk_points' => 0,
            'language_slots' => 0,
        ],
        'choices' => [],
        'grants' => [],
    ];

    foreach ($sources as $source) {
        $rules = cs_kb_rules_normalize((array)($source['rules'] ?? []));
        foreach (array_keys($stats) as $stat) {
            $fixed['stats'][$stat] += (int)($rules['fixed']['stats'][$stat] ?? 0);
            $fixed_bonuses['stats'][$stat] += (int)($rules['fixed_bonuses']['stats'][$stat] ?? 0);
        }
        foreach (['hp', 'armor', 'initiative', 'speed', 'carry', 'ep', 'damage', 'skill_points', 'language_slots'] as $k) {
            $fixed[$k] += (int)($rules['fixed'][$k] ?? 0);
            $fixed_bonuses[$k] += (int)($rules['fixed_bonuses'][$k] ?? 0);
        }
        foreach (['attribute_points', 'feat_points', 'perk_points'] as $k) {
            $fixed_bonuses[$k] += (int)($rules['fixed_bonuses'][$k] ?? 0);
        }

        $totals['hp_base_total'] += (int)($rules['hp_base'] ?? 0);
        $totals['fixed_hp_total'] += (int)($rules['fixed_bonuses']['hp'] ?? 0);
        $totals['speed_base_total'] += (int)($rules['speed'] ?? 0);
        $totals['speed_total'] += (int)($rules['speed'] ?? 0) + (int)($rules['fixed_bonuses']['speed'] ?? 0);
        $totals['points_pools']['attribute_points'] += (int)($rules['fixed']['attribute_points'] ?? 0) + (int)($rules['fixed_bonuses']['attribute_points'] ?? 0);
        $totals['points_pools']['skill_points'] += (int)($rules['fixed']['skill_points'] ?? 0) + (int)($rules['fixed_bonuses']['skill_points'] ?? 0);
        $totals['points_pools']['feat_points'] += (int)($rules['fixed_bonuses']['feat_points'] ?? 0);
        $totals['points_pools']['perk_points'] += (int)($rules['fixed_bonuses']['perk_points'] ?? 0);
        $totals['points_pools']['language_slots'] += (int)($rules['fixed']['language_slots'] ?? 0) + (int)($rules['fixed_bonuses']['language_slots'] ?? 0);

        foreach ((array)($rules['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $choice['source'] = (string)($source['type'] ?? '');
            $totals['choices'][] = $choice;

            if ((string)($choice['type'] ?? '') === 'skill_pick_choice' && (string)($choice['grant_mode'] ?? '') === 'skill_points') {
                $totals['bonus_skill_points'] += max(0, (int)($choice['pick'] ?? 0)) * max(0, (int)($choice['points_value'] ?? 0));
            }
        }

        $totals['grants'] = array_merge($totals['grants'], (array)($rules['grants'] ?? []));
    }

    $totals['points_pools']['skill_points'] += $totals['bonus_skill_points'];

    return [
        'fixed' => $fixed,
        'fixed_bonuses' => $fixed_bonuses,
        'hp_base_total' => $totals['hp_base_total'],
        'fixed_hp_total' => $totals['fixed_hp_total'],
        'speed_base_total' => $totals['speed_base_total'],
        'speed_total' => $totals['speed_total'],
        'bonus_attribute_points' => $totals['bonus_attribute_points'],
        'bonus_skill_points' => $totals['bonus_skill_points'],
        'points_pools' => $totals['points_pools'],
        'choices' => $totals['choices'],
        'grants' => $totals['grants'],
    ];
}

function cs_get_attribute_points_from_rules($rules): int
{
    if (!is_array($rules)) {
        return 0;
    }

    $points = max(0, (int)($rules['fixed']['attribute_points'] ?? 0))
        + max(0, (int)($rules['fixed_bonuses']['attribute_points'] ?? 0));

    foreach ((array)($rules['choices'] ?? []) as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $choice_type = (string)($choice['type'] ?? '');
        if (!in_array($choice_type, ['attribute_points'], true)) {
            continue;
        }
        $points += max(0, (int)($choice['pick'] ?? $choice['value'] ?? 0));
    }

    return $points;
}

function af_charactersheets_extract_skill_points_from_sources(array $context): int
{
    $total = 0;
    foreach (['race', 'class', 'theme'] as $source) {
        $resolved = (array)($context[$source] ?? []);
        $data = (array)($resolved['data'] ?? []);
        $total += cs_get_skill_points_from_rules($data);
    }
    return $total;
}

function af_charactersheets_extract_attribute_points_from_sources(array $context): int
{
    $total = 0;
    foreach (['race', 'class', 'theme'] as $source) {
        $resolved = (array)($context[$source] ?? []);
        $data = (array)($resolved['data'] ?? []);
        $total += cs_get_attribute_points_from_rules($data);
    }
    return $total;
}

function cs_get_sheet_kb_sources(array $sheet): array
{
    $tid = (int)($sheet['tid'] ?? 0);
    $index = [];
    if ($tid > 0) {
        $index = af_charactersheets_index_fields(af_charactersheets_get_atf_fields($tid));
    }

    return [
        'race' => af_charactersheets_pick_field_value($index, ['character_race', 'race'], false),
        'class' => af_charactersheets_pick_field_value($index, ['character_class', 'class'], false),
        'theme' => af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false),
    ];
}

function cs_build_resolved_rules($raceRules, $classRules, $themeRules, $build, $settings): array
{
    $stats = af_charactersheets_default_attributes();
    $fixed = [
        'stats' => $stats,
        'hp' => 0,
        'armor' => 0,
        'initiative' => 0,
        'speed' => 0,
        'carry' => 0,
        'ep' => 0,
        'damage' => 0,
        'skill_points' => 0,
        'language_slots' => 0,
    ];
    $fixed_bonuses = $fixed + ['attribute_points' => 0];
    $fixed_bonuses['stats'] = $stats;
    $hp_base = ['race' => 0, 'class' => 0, 'theme' => 0];
    $grants = [];
    $choices = [];

    foreach (['race' => $raceRules, 'class' => $classRules, 'theme' => $themeRules] as $source => $rules) {
        $rules = cs_kb_rules_normalize(is_array($rules) ? $rules : []);
        $hp_base[$source] = (int)($rules['hp_base'] ?? 0);

        foreach (array_keys($stats) as $stat) {
            $fixed['stats'][$stat] += (int)($rules['fixed']['stats'][$stat] ?? 0);
            $fixed_bonuses['stats'][$stat] += (int)($rules['fixed_bonuses']['stats'][$stat] ?? 0);
        }
        foreach (['hp','armor','initiative','speed','carry','ep','damage','skill_points','language_slots'] as $k) {
            $fixed[$k] += (int)($rules['fixed'][$k] ?? 0);
            $fixed_bonuses[$k] += (int)($rules['fixed_bonuses'][$k] ?? 0);
        }
        $fixed_bonuses['attribute_points'] += (int)($rules['fixed_bonuses']['attribute_points'] ?? 0);
        $grants = array_merge($grants, (array)($rules['grants'] ?? []));
        foreach ((array)($rules['choices'] ?? []) as $choice) {
            if (is_array($choice)) {
                $choice['source'] = $source;
                $choices[] = $choice;
            }
        }
    }

    return [
        'hp_base' => $hp_base,
        'fixed' => $fixed,
        'fixed_bonuses' => $fixed_bonuses,
        'grants' => $grants,
        'choices' => $choices,
    ];
}

function cs_resolve_character_kb_context(int $sheet_id): array
{
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return [];
    }

    $kb_sources = cs_get_sheet_kb_sources($sheet);
    $rule_sources = af_cs_build_rules_sources($sheet);
    $aggregate = af_cs_aggregate_rules(array_values($rule_sources));
    $build = af_charactersheets_normalize_build(
        af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''))
    );

    $race = af_charactersheets_kb_resolve_entry('race', (string)($kb_sources['race'] ?? ''));
    $class = af_charactersheets_kb_resolve_entry('class', (string)($kb_sources['class'] ?? ''));
    $theme = af_charactersheets_kb_resolve_entry('theme', (string)($kb_sources['theme'] ?? ''));

    $skills_all = af_charactersheets_kb_get_resolved_by_type('skill');
    $skills_all_map = [];
    foreach ($skills_all as $item) {
        $k = (string)($item['key'] ?? '');
        if ($k !== '') {
            $skills_all_map[$k] = $item;
        }
    }

    $skills_active = [];
    foreach (af_charactersheets_get_sheet_skills($sheet_id) as $row) {
        $skill_key = (string)($row['skill_key'] ?? '');
        if ($skill_key === '') {
            continue;
        }
        $resolved = (array)($skills_all_map[$skill_key] ?? af_charactersheets_kb_resolve_entry('skill', $skill_key));
        $skills_active[] = [
            'row' => $row,
            'resolved' => $resolved,
        ];
    }

    $languages = [];
    foreach ((array)($build['knowledge']['languages'] ?? []) as $language_key) {
        $item = af_charactersheets_kb_resolve_entry('language', (string)$language_key);
        if (!empty($item)) {
            $languages[] = $item;
        }
    }

    return [
        'race' => $race,
        'class' => $class,
        'theme' => $theme,
        'sources' => $rule_sources,
        'aggregate' => $aggregate,
        'skills_all' => $skills_all,
        'skills_active' => $skills_active,
        'languages' => $languages,
        'kb_sources' => $kb_sources,
    ];
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

function af_charactersheets_get_augmentation_slots(): array
{
    global $mybb;

    $raw = (string)($mybb->settings['af_charactersheets_aug_slots_json'] ?? '');
    $slots = af_charactersheets_json_decode($raw);
    if (!is_array($slots)) {
        $slots = [];
    }

    $normalized = [];
    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $key = trim((string)($slot['slot_key'] ?? $slot['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $title_ru = (string)($slot['title_ru'] ?? $slot['title'] ?? '');
        $title_en = (string)($slot['title_en'] ?? $slot['title'] ?? '');
        $title = af_charactersheets_is_ru() ? $title_ru : $title_en;
        if ($title === '') {
            $title = $title_ru !== '' ? $title_ru : ($title_en !== '' ? $title_en : $key);
        }
        $normalized[$key] = [
            'slot_key' => $key,
            'title' => $title,
            'title_ru' => $title_ru,
            'title_en' => $title_en,
            'icon' => (string)($slot['icon'] ?? ''),
            'sortorder' => (int)($slot['sortorder'] ?? $slot['sort_order'] ?? 0),
            'max_equipped' => max(1, (int)($slot['max_equipped'] ?? 1)),
        ];
    }

    uasort($normalized, function (array $a, array $b): int {
        $sort = ($a['sortorder'] ?? 0) <=> ($b['sortorder'] ?? 0);
        if ($sort !== 0) {
            return $sort;
        }
        return strcmp((string)$a['slot_key'], (string)$b['slot_key']);
    });

    return $normalized;
}

function af_charactersheets_get_equipment_slots(): array
{
    return [
        'armor' => 'Броня',
        'weapon' => 'Оружие',
        'shield' => 'Щит',
    ];
}

function af_charactersheets_get_inventory_item_type(array $item): string
{
    return (string)($item['kb_type'] ?? $item['type'] ?? '');
}

function af_charactersheets_get_inventory_item_key(array $item): string
{
    return (string)($item['kb_key'] ?? $item['key'] ?? '');
}

function af_charactersheets_normalize_slot_items($slot_value): array
{
    if (empty($slot_value)) {
        return [];
    }
    if (is_array($slot_value) && (isset($slot_value['type']) || isset($slot_value['key']) || isset($slot_value['kb_type']) || isset($slot_value['kb_key']))) {
        return [$slot_value];
    }
    if (is_array($slot_value) && array_values($slot_value) === $slot_value) {
        return array_values(array_filter($slot_value, 'is_array'));
    }
    if (is_array($slot_value)) {
        return [$slot_value];
    }
    return [];
}

function af_charactersheets_get_augmentation_slot_config(string $slot_key): array
{
    $slots = af_charactersheets_get_augmentation_slots();
    return $slots[$slot_key] ?? [];
}

function af_charactersheets_default_build(): array
{
    $augmentation_slots = [];
    foreach (af_charactersheets_get_augmentation_slots() as $slot => $config) {
        $max_equipped = (int)($config['max_equipped'] ?? 1);
        $augmentation_slots[$slot] = $max_equipped > 1 ? [] : null;
    }
    $equipment_slots = [];
    foreach (af_charactersheets_get_equipment_slots() as $slot => $label) {
        $equipment_slots[$slot] = null;
    }

    return [
        'allocated_stats' => af_charactersheets_zero_attributes(),
        'attributes_allocated' => af_charactersheets_zero_attributes(),
        'picks' => [],
        'choices' => [],
        'active_skills' => [],
        'skills' => [],
        'knowledge' => [
            'languages' => [],
            'knowledges' => [],
        ],
        'inventory' => [
            'items' => [],
        ],
        'abilities' => [
            'slots_total' => 0,
            'owned' => [],
        ],
        'augmentations' => [
            'slots' => $augmentation_slots,
            'owned' => [],
        ],
        'equipment' => [
            'slots' => $equipment_slots,
            'owned' => [],
        ],
    ];
}

function af_charactersheets_normalize_build(array $build): array
{
    $defaults = af_charactersheets_default_build();

    $build = array_merge($defaults, $build);
    if (isset($build['allocated_stats']) && is_array($build['allocated_stats'])) {
        $build['attributes_allocated'] = array_merge((array)$build['attributes_allocated'], (array)$build['allocated_stats']);
    }
    $build['allocated_stats'] = array_merge(af_charactersheets_zero_attributes(), (array)($build['attributes_allocated'] ?? []));

    if (isset($build['picks']) && is_array($build['picks'])) {
        $build['choices'] = array_merge((array)$build['choices'], (array)$build['picks']);
    }
    $build['picks'] = (array)($build['choices'] ?? []);

    if (isset($build['active_skills']) && is_array($build['active_skills']) && empty($build['skills'])) {
        $build['skills'] = (array)$build['active_skills'];
    }
    $build['active_skills'] = (array)($build['skills'] ?? []);

    $build['knowledge'] = array_merge($defaults['knowledge'], (array)($build['knowledge'] ?? []));
    $build['inventory'] = array_merge($defaults['inventory'], (array)($build['inventory'] ?? []));
    $build['abilities'] = array_merge($defaults['abilities'], (array)($build['abilities'] ?? []));
    $build['augmentations'] = array_merge($defaults['augmentations'], (array)($build['augmentations'] ?? []));
    $build['equipment'] = array_merge($defaults['equipment'], (array)($build['equipment'] ?? []));

    $inventory_raw = (array)($build['inventory'] ?? []);
    $inventory_items = [];
    if (isset($inventory_raw['items']) && is_array($inventory_raw['items'])) {
        $inventory_items = $inventory_raw['items'];
    } elseif (array_values($inventory_raw) === $inventory_raw) {
        $inventory_items = $inventory_raw;
    }
    $inventory_items = array_values(array_filter($inventory_items, 'is_array'));
    $stacked_inventory = [];
    foreach ($inventory_items as $item) {
        $type = af_charactersheets_get_inventory_item_type($item);
        $key = af_charactersheets_get_inventory_item_key($item);
        if ($type === '' || $key === '') {
            continue;
        }
        $qty = (int)($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $stack_key = $type . ':' . $key;
        if (!isset($stacked_inventory[$stack_key])) {
            $stacked_inventory[$stack_key] = [
                'kb_type' => $type,
                'kb_key' => $key,
                'qty' => $qty,
                'equipped' => !empty($item['equipped']),
                'slot' => (string)($item['slot'] ?? ''),
            ];
        } else {
            $stacked_inventory[$stack_key]['qty'] += $qty;
            if (!empty($item['equipped'])) {
                $stacked_inventory[$stack_key]['equipped'] = true;
            }
        }
    }
    $build['inventory']['items'] = array_values($stacked_inventory);

    $abilities_owned = [];
    $abilities_raw = (array)($build['abilities'] ?? []);
    if (isset($abilities_raw['owned']) && is_array($abilities_raw['owned'])) {
        $abilities_owned = $abilities_raw['owned'];
    } elseif (array_values($abilities_raw) === $abilities_raw) {
        $abilities_owned = $abilities_raw;
    }
    $build['abilities']['owned'] = array_values(array_filter($abilities_owned, 'is_array'));
    $build['abilities']['slots_total'] = (int)($build['abilities']['slots_total'] ?? 0);

    $augmentation_defaults = $defaults['augmentations']['slots'];
    $augmentation_slots = $augmentation_defaults;
    foreach ((array)($build['augmentations']['slots'] ?? []) as $slot_key => $slot_value) {
        if (!array_key_exists($slot_key, $augmentation_defaults)) {
            continue;
        }
        $augmentation_slots[$slot_key] = $slot_value;
    }
    foreach ($augmentation_slots as $slot_key => $slot_value) {
        $config = af_charactersheets_get_augmentation_slot_config((string)$slot_key);
        $max_equipped = (int)($config['max_equipped'] ?? 1);
        $normalized_items = af_charactersheets_normalize_slot_items($slot_value);
        if ($max_equipped <= 1) {
            $augmentation_slots[$slot_key] = $normalized_items ? $normalized_items[0] : null;
        } else {
            $augmentation_slots[$slot_key] = array_slice($normalized_items, 0, $max_equipped);
        }
    }
    $build['augmentations']['slots'] = $augmentation_slots;
    $build['augmentations']['owned'] = array_values(array_filter((array)($build['augmentations']['owned'] ?? []), 'is_array'));

    $equipment_defaults = $defaults['equipment']['slots'];
    $equipment_slots = array_merge($equipment_defaults, (array)($build['equipment']['slots'] ?? []));
    $build['equipment']['slots'] = $equipment_slots;
    $build['equipment']['owned'] = array_values(array_filter((array)($build['equipment']['owned'] ?? []), 'is_array'));

    return $build;
}

function af_charactersheets_get_skills_catalog(bool $activeOnly = true): array
{
    global $db;

    if (is_object($db) && $db->table_exists('af_kb_entries')) {
        $where = "type='skill'";
        if ($activeOnly) {
            $where .= ' AND active=1';
        }
        $rows = [];
        $q = $db->simple_select('af_kb_entries', '*', $where, ['order_by' => 'sortorder, title_ru, title_en', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($q)) {
            if (!is_array($row)) {
                continue;
            }
            $rules = function_exists('af_kb_decode_json') ? af_kb_decode_json((string)($row['data_json'] ?? '{}')) : json_decode((string)($row['data_json'] ?? '{}'), true);
            if (!is_array($rules)) {
                $rules = [];
            }
            $skill = is_array($rules['skill'] ?? null) ? $rules['skill'] : [];
            $rows[] = [
                'slug' => (string)($row['key'] ?? ''),
                'title_ru' => (string)($row['title_ru'] ?? ''),
                'title_en' => (string)($row['title_en'] ?? ''),
                'attribute' => (string)($skill['attribute'] ?? $rules['attribute'] ?? 'int'),
                'description_ru' => (string)($row['short_ru'] ?? ''),
                'description_en' => (string)($row['short_en'] ?? ''),
                'active' => (int)($row['active'] ?? 1),
                'sort_order' => (int)($row['sortorder'] ?? 0),
                'kb_type' => 'skill',
                'kb_key' => (string)($row['key'] ?? ''),
            ];
        }
        if (!empty($rows)) {
            return $rows;
        }
    }

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
