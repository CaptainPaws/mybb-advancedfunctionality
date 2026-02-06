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
const AF_CS_TPL_MARK = '<!--AF_CS_ACCEPT-->';

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_charactersheets_is_installed(): bool
{
    global $db;
    return $db->table_exists(AF_CS_TABLE);
}

function af_charactersheets_install(): void
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

    af_charactersheets_ensure_settings();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_charactersheets_uninstall(): void
{
    global $db;

    if ($db->table_exists(AF_CS_TABLE)) {
        $db->drop_table(AF_CS_TABLE);
    }

    $db->delete_query('settings', "name IN (
        'af_charactersheets_enabled',
        'af_charactersheets_accept_groups',
        'af_charactersheets_pending_forums',
        'af_charactersheets_accepted_forum',
        'af_charactersheets_accept_post_template',
        'af_charactersheets_accept_wrap_htmlbb',
        'af_charactersheets_accept_close_thread',
        'af_charactersheets_accept_move_thread',
        'af_charactersheets_sheet_autocreate'
    )");
    $db->delete_query('settinggroups', "name='af_charactersheets'");

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

    $default_template = "Добро пожаловать, @{username}!\n\nРады видеть тебя в Warp Rift. Вот полезные ссылки:\n- Правила: /rules.php\n- Лор: /misc.php?action=af_kb&type=...\n- Вопросы: /forumdisplay.php?fid=...";

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
        'af_charactersheets_accept_post_template',
        $lang->af_charactersheets_accept_post_template ?? 'Acceptance post template',
        $lang->af_charactersheets_accept_post_template_desc ?? 'Supports placeholders',
        'textarea',
        $default_template,
        5
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_wrap_htmlbb',
        $lang->af_charactersheets_accept_wrap_htmlbb ?? 'Wrap in [html]',
        $lang->af_charactersheets_accept_wrap_htmlbb_desc ?? 'Wrap acceptance post',
        'yesno',
        '1',
        6
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_close_thread',
        $lang->af_charactersheets_accept_close_thread ?? 'Close thread',
        $lang->af_charactersheets_accept_close_thread_desc ?? 'Close thread after acceptance',
        'yesno',
        '1',
        7
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_accept_move_thread',
        $lang->af_charactersheets_accept_move_thread ?? 'Move thread',
        $lang->af_charactersheets_accept_move_thread_desc ?? 'Move thread after acceptance',
        'yesno',
        '1',
        8
    );
    af_charactersheets_ensure_setting(
        $gid,
        'af_charactersheets_sheet_autocreate',
        $lang->af_charactersheets_sheet_autocreate ?? 'Auto-create sheet',
        $lang->af_charactersheets_sheet_autocreate_desc ?? 'Trigger sheet generator stub',
        'yesno',
        '1',
        9
    );
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

    if (!af_charactersheets_is_pending_forum($fid)) {
        return;
    }

    if (af_charactersheets_is_accepted($tid)) {
        return;
    }

    if (af_charactersheets_is_in_accepted_forum($fid)) {
        return;
    }

    if (!af_charactersheets_user_can_accept($mybb->user, $fid)) {
        return;
    }

    $text = $lang->af_charactersheets_accept_button ?? 'Принять анкету';
    $url = 'misc.php?action=af_charactersheets_accept&tid=' . $tid . '&my_post_key=' . $mybb->post_code;

    $GLOBALS['af_charactersheets_accept_button'] =
        '<div class="af-cs-accept-button">'
        . '<a class="button new_thread_button" href="' . htmlspecialchars_uni($url) . '">'
        . '<span>' . htmlspecialchars_uni($text) . '</span>'
        . '</a>'
        . '</div>';
}

function af_charactersheets_pre_output(string $page): string
{
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'showthread.php') {
        return $page;
    }

    if (empty($GLOBALS['af_charactersheets_accept_button'])) {
        return $page;
    }

    if (strpos($page, AF_CS_TPL_MARK) !== false) {
        return $page;
    }

    $insert = "\n" . AF_CS_TPL_MARK . "\n" . $GLOBALS['af_charactersheets_accept_button'] . "\n";

    $count = 0;
    $page2 = @preg_replace(
        '~(<td\b[^>]*\bclass=("\')thead\2[^>]*>.*?<strong\b[^>]*>.*?</strong>)~is',
        '$1' . $insert,
        $page,
        1,
        $count
    );

    if ($count > 0 && is_string($page2)) {
        return $page2;
    }

    $count = 0;
    $page2 = @preg_replace('~(</strong>)~i', '$1' . $insert, $page, 1, $count);
    if ($count > 0 && is_string($page2)) {
        return $page2;
    }

    return $page . $insert;
}

/* -------------------- MISC ENDPOINT -------------------- */

function af_charactersheets_misc_start(): void
{
    global $mybb, $db, $lang, $session;

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
    if (!empty($accept_row['accepted'])) {
        $msg = $lang->af_charactersheets_accept_already ?? 'Анкета уже была принята.';
        redirect('showthread.php?tid=' . $tid, $msg);
    }

    $accepted_pid = (int)($accept_row['accepted_pid'] ?? 0);

    if ($accepted_pid <= 0) {
        $message = af_charactersheets_build_accept_message($thread);

        require_once MYBB_ROOT . 'inc/datahandlers/post.php';
        $posthandler = new PostDataHandler('insert');
        $posthandler->action = 'reply';

        $subject = 'Re: ' . $thread['subject'];
        $post_data = [
            'tid' => $tid,
            'fid' => $fid,
            'subject' => $subject,
            'uid' => (int)$mybb->user['uid'],
            'username' => (string)$mybb->user['username'],
            'message' => $message,
            'ipaddress' => $session->ipaddress ?? '',
            'longipaddress' => $session->packedip ?? '',
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

        af_charactersheets_upsert_accept_row($tid, [
            'uid' => (int)$thread['uid'],
            'accepted_pid' => $accepted_pid,
        ]);
    }

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

    $template = (string)($mybb->settings['af_charactersheets_accept_post_template'] ?? '');
    if ($template === '') {
        $template = 'Добро пожаловать, @{username}!';
    }

    $tid = (int)$thread['tid'];
    $uid = (int)$thread['uid'];

    $thread_url = af_charactersheets_make_absolute_url(
        function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid)
    );
    $profile_url = af_charactersheets_make_absolute_url(
        function_exists('get_profile_link') ? get_profile_link($uid) : ('member.php?action=profile&uid=' . $uid)
    );

    $replacements = [
        '{username}' => (string)($thread['username'] ?? ''),
        '{uid}' => (string)$uid,
        '{thread_url}' => $thread_url,
        '{profile_url}' => $profile_url,
        '{accepted_by}' => (string)($mybb->user['username'] ?? ''),
    ];

    $rendered = strtr($template, $replacements);

    if (!empty($mybb->settings['af_charactersheets_accept_wrap_htmlbb'])) {
        $rendered = "[html]\n" . $rendered . "\n[/html]";
    }

    return $rendered;
}

function af_charactersheets_autocreate_sheet(int $tid, array $thread): array
{
    global $db;

    $row = af_charactersheets_get_accept_row($tid);
    if (!empty($row['sheet_created'])) {
        return ['ok' => true, 'slug' => (string)($row['sheet_slug'] ?? '')];
    }

    $slug = af_charactersheets_slugify((string)($thread['subject'] ?? ''), $tid);

    af_charactersheets_upsert_accept_row($tid, [
        'uid' => (int)($thread['uid'] ?? 0),
        'sheet_slug' => $slug,
        'sheet_created' => 1,
    ]);

    return ['ok' => true, 'slug' => $slug];
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

    if (function_exists('is_moderator')) {
        if (!is_moderator($fid, 'canmanagethreads')) {
            return false;
        }
    }

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
