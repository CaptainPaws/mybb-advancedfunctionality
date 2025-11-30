<?php
/**
 * Advanced Alerts — внутренний аддон AF
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * - Типы уведомлений (вкл/выкл глобально + пользовательские чекбоксы в UCP).
 * - Триггеры: ответы/цитаты/упоминания, ЛС, друзья, подписки на темы/форумы, репутация.
 * - /misc.php?action=af_alerts — страница списка уведомлений.
 * - /xmlhttp.php?action=af_alerts_api — AJAX API, поп-ап, звук, тосты.
 * - Вставки в UCP выполняются правкой шаблонов при activate()/deactivate().
 * - ЛОГИКА УПОМИНАНИЙ (@username / @"Имя Фамилия") ВСТРОЕНА СЮДА (раньше была в advancedmentions.php).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AA_ID', 'advancedalerts');
define('AF_AA_BASE', AF_ADDONS . AF_AA_ID . '/');
// Публичные файлы (af_alerts.js/css/mp3/advancedmentions.js/css) лежат прямо в корне аддона
define('AF_AA_PUBLIC_DIR', AF_AA_BASE);
define('AF_AA_RETENTION_DAYS', 7);

// === ADMIN CONTROLLER (страница типов уведомлений в ACP) ===
// /inc/plugins/advancedfunctionality/addons/advancedalerts/admin.php
if (defined('IN_MYBB') && defined('IN_ADMINCP')) {
    require_once AF_AA_BASE . 'admin.php';
}


/* ====================== INSTALL/SETTINGS ======================= */

function af_advancedalerts_install()
{
    global $db;

    if (!$db->table_exists('alert_types')) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX."alert_types`(
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(100) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `can_be_user_disabled` TINYINT(1) NOT NULL DEFAULT 1,
                `title` VARCHAR(150) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `code`(`code`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ");
    }

    if (!$db->table_exists('alerts')) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX."alerts`(
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uid` INT UNSIGNED NOT NULL,
                `from_user_id` INT UNSIGNED DEFAULT NULL,
                `type_id` INT UNSIGNED NOT NULL,
                `object_id` BIGINT UNSIGNED DEFAULT NULL,
                `extra_data` TEXT DEFAULT NULL,
                `dateline` INT UNSIGNED NOT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `visible` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `uid_read` (`uid`,`is_read`,`dateline`),
                KEY `type_id` (`type_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ");
    }

    if (!$db->table_exists('alert_settings')) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX."alert_settings`(
                `uid` INT UNSIGNED NOT NULL,
                `type_id` INT UNSIGNED NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`uid`,`type_id`),
                KEY `type_id`(`type_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ");
    }

    if (!$db->table_exists('alert_prefs')) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX."alert_prefs`(
                `uid` INT UNSIGNED NOT NULL,
                `sound` TINYINT(1) NOT NULL DEFAULT 1,
                `toasts` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY(`uid`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ");
    }

    $gid = afaa_settings_group_id();
    if (!$gid) {
        $group = [
            'name'        => 'af_advancedalerts',
            'title'       => 'Advanced Alerts',
            'description' => 'Настройки внутренней системы уведомлений AF.',
            'disporder'   => 20,
            'isdefault'   => 0
        ];
        $gid = (int)$db->insert_query('settinggroups', $group);
    }

    afaa_ensure_setting($gid, [
        'name' => 'af_advancedalerts_enabled',
        'title' => 'Включить Advanced Alerts',
        'description' => 'Глобальное включение внутренней системы уведомлений.',
        'optionscode' => 'yesno',
        'value' => '1',
        'disporder' => 1
    ]);
    afaa_ensure_setting($gid, [
        'name' => 'af_aa_allow_user_disable',
        'title' => 'Разрешить отключать типы в UCP',
        'description' => 'Показывать чекбоксы в личном разделе пользователя.',
        'optionscode' => 'yesno',
        'value' => '1',
        'disporder' => 2
    ]);
    afaa_ensure_setting($gid, [
        'name' => 'af_aa_dropdown_limit',
        'title' => 'Сколько показывать в поп-апе',
        'description' => 'Количество записей в выпадающем списке колокольчика.',
        'optionscode' => 'text',
        'value' => '10',
        'disporder' => 3
    ]);
    afaa_ensure_setting($gid, [
        'name' => 'af_aa_toast_limit',
        'title' => 'Максимум тост-плашек',
        'description' => 'Одновременно видимых всплывающих плашек.',
        'optionscode' => 'text',
        'value' => '5',
        'disporder' => 4
    ]);
    afaa_ensure_setting($gid, [
        'name' => 'af_aa_poll_seconds',
        'title' => 'Интервал опроса (сек)',
        'description' => 'Как часто проверять новые уведомления.',
        'optionscode' => 'text',
        'value' => '20',
        'disporder' => 5
    ]);
    afaa_ensure_setting($gid, [
        'name' => 'af_aa_page_perpage',
        'title' => 'Уведомления на странице (misc)',
        'description' => 'Сколько строк на странице /misc.php?action=af_alerts',
        'optionscode' => 'text',
        'value' => '20',
        'disporder' => 7
    ]);
    afaa_ensure_setting($gid, [
        'name'        => 'af_aa_mention_all_groups',
        'title'       => 'Группы, которым разрешён тег @all',
        'description' => 'ID групп через запятую, которые могут использовать @all (по умолчанию 3,4,6).',
        'optionscode' => 'text',
        'value'       => '3,4,6',
        'disporder'   => 8,
    ]);
    afaa_ensure_setting($gid, [
        'name'        => 'af_aa_group_mention_groups',
        'title'       => 'Группы, которым разрешён тег @group{ID}',
        'description' => 'ID групп через запятую, которые могут использовать @group{ID} (по умолчанию 3,4,6).',
        'optionscode' => 'text',
        'value'       => '3,4,6',
        'disporder'   => 9,
    ]);

    // ====== НАСТРОЙКИ ДЛЯ УПОМИНАНИЙ (бывший Advanced Mentions) ======
    afaa_ensure_setting($gid, [
        'name'        => 'af_advancedmentions_enabled',
        'title'       => 'Включить Advanced Mentions',
        'description' => 'Если включено, пользователи смогут упоминать друг друга по @username.',
        'optionscode' => 'onoff',
        'value'       => '1',
        'disporder'   => 20,
    ]);
    afaa_ensure_setting($gid, [
        'name'        => 'af_advancedmentions_click_insert',
        'title'       => 'Клик по нику вставляет упоминание',
        'description' => 'Если включено, клик по нику в постбите вставляет @"username" в форму ответа вместо перехода в профиль.',
        'optionscode' => 'onoff',
        'value'       => '1',
        'disporder'   => 21,
    ]);
    afaa_ensure_setting($gid, [
        'name'        => 'af_advancedmentions_suggest_min',
        'title'       => 'Минимум символов для подсказок',
        'description' => 'Сколько символов после @ нужно ввести, чтобы показать список пользователей (по умолчанию 2).',
        'optionscode' => 'text',
        'value'       => '2',
        'disporder'   => 22,
    ]);

    // ЛЕГАСИ: убираем старую настройку групп, если осталась
    $db->delete_query('settings', "name='af_aa_groups'");

    rebuild_settings();
    afaa_register_default_types();
}

function af_advancedalerts_is_installed()
{
    global $db;
    return $db->table_exists('alert_types') && $db->table_exists('alerts');
}

function af_advancedalerts_uninstall()
{
    // историю не трогаем
}


/* ====================== SCHEMA MIGRATION ======================= */

function afaa_column_type(string $table, string $col): string
{
    global $db;
    $res = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX.$db->escape_string($table)."` LIKE '".$db->escape_string($col)."'", true);
    if ($res && $db->num_rows($res)) {
        $row = $db->fetch_array($res);
        return my_strtolower((string)$row['Type']);
    }
    return '';
}

function afaa_maybe_migrate_schema(): void
{
    global $db;

    // alerts.dateline -> INT UNSIGNED NOT NULL (если сейчас datetime/timestamp)
    $t = afaa_column_type('alerts', 'dateline');
    if ($t && (strpos($t,'datetime') !== false || strpos($t,'timestamp') !== false)) {
        $tbl = TABLE_PREFIX.'alerts';

        $db->write_query("ALTER TABLE `{$tbl}` ADD COLUMN `dateline_int` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `object_id`", true);
        $db->write_query("UPDATE `{$tbl}` SET `dateline_int` = UNIX_TIMESTAMP(`dateline`)", true);
        $db->write_query("ALTER TABLE `{$tbl}` DROP COLUMN `dateline`", true);
        $db->write_query("ALTER TABLE `{$tbl}` CHANGE COLUMN `dateline_int` `dateline` INT UNSIGNED NOT NULL", true);
        $db->write_query("ALTER TABLE `{$tbl}` ADD KEY `uid_read` (`uid`,`is_read`,`dateline`)", true);
        $db->write_query("ALTER TABLE `{$tbl}` ADD KEY `type_id` (`type_id`)", true);
    }

    // На всякий случай добавим отсутствующую колонку is_read
    $have_is_read = false;
    $colq = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX."alerts` LIKE 'is_read'", true);
    if ($colq && $db->num_rows($colq) > 0) {
        $have_is_read = true;
    }
    if (!$have_is_read) {
        $db->write_query("ALTER TABLE `".TABLE_PREFIX."alerts` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `dateline`", true);
        $db->write_query("ALTER TABLE `".TABLE_PREFIX."alerts` ADD KEY `uid_read` (`uid`,`is_read`,`dateline`)", true);
    }
}


/* ===== Активация/деактивация: правки шаблонов под UCP ===== */

function af_advancedalerts_activate()
{
    afaa_tpl_insert_links();
}

function af_advancedalerts_deactivate()
{
    afaa_tpl_remove_links();
}


/* ====================== HELPERS ======================= */

function afaa_settings_group_id(): int
{
    global $db;
    $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedalerts'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    return $gid ?: 0;
}

function afaa_ensure_setting(int $gid, array $seed): void
{
    global $db;
    $q = $db->simple_select('settings','sid', "name='".$db->escape_string($seed['name'])."'", ['limit'=>1]);
    $sid = (int)$db->fetch_field($q, 'sid');
    $seed['gid'] = $gid;
    if (!$sid) {
        $db->insert_query('settings', $seed);
    } else {
        $db->update_query('settings', $seed, "sid={$sid}");
    }
}

function afaa_get_user_prefs(int $uid): array
{
    global $db;
    $row = $db->fetch_array($db->simple_select('alert_prefs','sound,toasts', "uid={$uid}", ['limit'=>1])) ?: [];
    return [
        'sound'  => isset($row['sound'])  ? (int)$row['sound']  : 1,
        'toasts' => isset($row['toasts']) ? (int)$row['toasts'] : 1,
    ];
}

function afaa_set_user_prefs(int $uid, int $sound, int $toasts): void
{
    global $db;
    $exists = $db->simple_select('alert_prefs','uid', "uid={$uid}", ['limit'=>1]);
    $payload = ['sound'=>$sound?1:0, 'toasts'=>$toasts?1:0];
    if ($db->num_rows($exists)) {
        $db->update_query('alert_prefs', $payload, "uid={$uid}");
    } else {
        $payload['uid'] = $uid;
        $db->insert_query('alert_prefs', $payload);
    }
}

function afaa_wol_location(&$plugin_array): void
{
    if (empty($plugin_array['user_activity']['location'])) {
        return;
    }

    $loc = $plugin_array['user_activity']['location'];
    $path = parse_url($loc, PHP_URL_PATH) ?? '';
    parse_str(parse_url($loc, PHP_URL_QUERY) ?? '', $params);

    // /misc.php?action=af_alerts — список уведомлений
    if (stripos((string)$path, 'misc.php') !== false && ($params['action'] ?? '') === 'af_alerts') {
        $plugin_array['location_name'] = 'Просматривает уведомления';
        $plugin_array['location_url']  = 'misc.php?action=af_alerts';
        return;
    }

    // Ajax-проверка (xmlhttp.php?action=af_alerts_api) засчитываем как обычное пребывание на форуме
    if (stripos((string)$path, 'xmlhttp.php') !== false && ($params['action'] ?? '') === 'af_alerts_api') {
        $plugin_array['location_name'] = 'Просматривает форум';
        $plugin_array['location_url']  = 'index.php';
    }
}


// === AVATAR HELPERS ===
if (!function_exists('afaa_user_avatar_url')) {
    function afaa_user_avatar_url(int $uid): string
    {
        if ($uid <= 0) return '';
        require_once MYBB_ROOT.'inc/functions_user.php';
        $user = get_user($uid);
        if (!$user) return '';
        $max = '64x64';
        $av = format_avatar($user['avatar'] ?? '', $user['avatardimensions'] ?? '', $max);
        return (string)($av['image'] ?? (is_array($av) ? reset($av) : ''));
    }
}

function afaa_user_in_allowed_groups(int $uid): bool
{
    // Гости не получают алерты, все зареганные — да
    return $uid > 0;
}

function afaa_can_user_use_tag(int $uid, string $settingKey, string $defaultList = '3,4,6'): bool
{
    global $mybb;

    if ($uid <= 0) {
        return false;
    }

    $raw = (string)($mybb->settings[$settingKey] ?? $defaultList);

    $parts = array_filter(array_map('intval', preg_split('~[,\s]+~', $raw)));
    if (!$parts) {
        $parts = array_filter(array_map('intval', explode(',', $defaultList)));
    }
    if (!$parts) {
        return false;
    }

    require_once MYBB_ROOT.'inc/functions_user.php';

    if (function_exists('is_member')) {
        $res = is_member($parts, $uid);
        return (bool)$res;
    }

    if ((int)($mybb->user['uid'] ?? 0) !== $uid) {
        return false;
    }

    $ug  = (int)($mybb->user['usergroup'] ?? 0);
    $ags = (string)($mybb->user['additionalgroups'] ?? '');

    if (in_array($ug, $parts, true)) {
        return true;
    }

    $extra = array_filter(array_map('intval', explode(',', $ags)));
    return (bool)array_intersect($extra, $parts);
}


/* === Регистрация типов: поддержка массива и строковых аргументов === */

function afaa_register_default_types(): void
{
    $defaults = [
        'rep'               => 'Изменение репутации',
        'pm'                => 'Новое личное сообщение',
        'buddylist'         => 'Заявка в друзья',
        'buddy_accept'      => 'Принятие заявки в друзья',
        'quoted'            => 'Вас процитировали',
        'subscribed_thread' => 'Ответ в подписанной теме',
        'subscribed_forum'  => 'Новая тема в подписанном форуме',
        'mention'           => 'Упоминание пользователя',
        'mention_group'     => 'Упоминание группы @group{ID}',
        'mention_all'       => 'Глобальное упоминание @all',
    ];

    foreach ($defaults as $code => $title) {
        afaa_register_type($code, $title, true, true);
    }
}

function afaa_register_type($code, $title=null, $enabled=true, $user_can_disable=true): int
{
    if (is_array($code)) {
        $arr = $code;
        $code = (string)($arr['code'] ?? '');
        $title = (string)($arr['title'] ?? $code);
        $user_can_disable = (int)($arr['can_be_user_disabled'] ?? 1) === 1;
        $enabled          = (int)($arr['enabled'] ?? 1) === 1;
    }

    global $db;
    $code = trim((string)$code);
    if ($code === '') return 0;

    $row = $db->fetch_array($db->simple_select('alert_types','id', "code='".$db->escape_string($code)."'", ['limit'=>1]));
    if ($row && (int)$row['id'] > 0) {
        $db->update_query('alert_types', [
            'title' => $db->escape_string((string)$title),
            'enabled' => $enabled?1:0,
            'can_be_user_disabled' => $user_can_disable?1:0
        ], "id=".(int)$row['id']);
        return (int)$row['id'];
    }
    return (int)$db->insert_query('alert_types', [
        'code' => $db->escape_string($code),
        'title'=> $db->escape_string((string)$title),
        'enabled' => $enabled?1:0,
        'can_be_user_disabled' => $user_can_disable?1:0
    ]);
}


// Совместимость для внутренних аддонов
if (!function_exists('af_advancedalerts_register_type')) {
    function af_advancedalerts_register_type(string $code, array $opts = []): int
    {
        $payload = [
            'code'                 => $code,
            'title'                => $opts['title'] ?? $code,
            'enabled'              => isset($opts['enabled']) ? ((int)$opts['enabled'] ? 1 : 0) : 1,
            'can_be_user_disabled' => isset($opts['can_be_user_disabled'])
                ? ((int)$opts['can_be_user_disabled'] ? 1 : 0)
                : 1,
        ];

        return afaa_register_type($payload);
    }
}

if (!function_exists('af_advancedalerts_add')) {
    /**
     * Универсальный хелпер для добавления уведомлений из внутренних аддонов.
     *
     * $type_code — код типа ('mention', 'rep', 'pm', кастом и т.д.).
     * $uid       — кому шлём.
     * $ctx       — контекст (from_uid, pid, tid, subject, link и т.п.).
     */
    function af_advancedalerts_add(string $type_code, int $uid, array $ctx = []): bool
    {
        global $db, $mybb;

        $uid       = (int)$uid;
        $type_code = trim($type_code);

        if ($uid <= 0 || $type_code === '') {
            return false;
        }

        $from_uid = (int)($ctx['from_uid'] ?? $ctx['from_user_id'] ?? 0);

        $extra     = [];
        $object_id = null;

        switch ($type_code) {
            case 'mention':
                $pid = (int)($ctx['pid'] ?? 0);
                $tid = (int)($ctx['tid'] ?? 0);

                if ($tid <= 0 && $pid > 0) {
                    $post = $db->fetch_array(
                        $db->simple_select('posts', 'tid', "pid={$pid}", ['limit' => 1])
                    ) ?: [];
                    $tid = (int)($post['tid'] ?? 0);
                }

                if ($pid <= 0 && $tid > 0) {
                    $post = $db->fetch_array(
                        $db->simple_select(
                            'posts',
                            'pid',
                            "tid={$tid}",
                            ['order_by' => 'dateline', 'order_dir' => 'ASC', 'limit' => 1]
                        )
                    ) ?: [];
                    $pid = (int)($post['pid'] ?? 0);
                }

                if ($tid <= 0 && $pid <= 0) {
                    return false;
                }

                $thread = $tid > 0
                    ? $db->fetch_array(
                        $db->simple_select('threads', 'subject', "tid={$tid}", ['limit' => 1])
                    ) ?: []
                    : [];

                $subject = trim((string)($thread['subject'] ?? 'Тема'));

                $link = $pid > 0
                    ? "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}"
                    : "showthread.php?tid={$tid}";

                $from_username = '';
                if ($from_uid > 0) {
                    require_once MYBB_ROOT.'inc/functions_user.php';
                    $from_user = get_user($from_uid);
                    if (!empty($from_user['username'])) {
                        $from_username = (string)$from_user['username'];
                    }
                }

                if ($from_username === '' && !empty($mybb->user['uid']) && (int)$mybb->user['uid'] === $from_uid) {
                    $from_username = (string)($mybb->user['username'] ?? '');
                }

                $extra = [
                    'thread_subject' => $subject,
                    'from_username'  => $from_username,
                    'link'           => $link,
                ];

                $object_id = $pid > 0 ? $pid : $tid;
                break;

            default:
                $title = (string)($ctx['thread_subject'] ?? $ctx['subject'] ?? '');
                $from_username = (string)($ctx['from_username'] ?? '');

                $extra = [
                    'thread_subject' => $title,
                    'from_username'  => $from_username,
                    'link'           => (string)($ctx['link'] ?? ''),
                ];

                $obj = (int)($ctx['object_id'] ?? 0);
                if ($obj > 0) {
                    $object_id = $obj;
                }
                break;
        }

        return afaa_send(
            $uid,
            $type_code,
            $extra,
            $from_uid > 0 ? $from_uid : null,
            $object_id
        );
    }
}

if (!function_exists('af_advancedalerts_unregister_type')) {
    function af_advancedalerts_unregister_type(string $code): void
    {
        global $db;

        $code = trim($code);
        if ($code === '') {
            return;
        }

        $q   = $db->simple_select('alert_types', 'id', "code='".$db->escape_string($code)."'", ['limit' => 1]);
        $tid = (int)$db->fetch_field($q, 'id');
        if ($tid <= 0) {
            return;
        }

        if ($db->table_exists('alert_settings')) {
            $db->delete_query('alert_settings', "type_id={$tid}");
        }

        if ($db->table_exists('alerts')) {
            $db->delete_query('alerts', "type_id={$tid}");
        }

        $db->delete_query('alert_types', "id={$tid}");
    }
}

function afaa_type_id(string $code): int
{
    global $db;
    $q = $db->simple_select('alert_types', 'id', "code='".$db->escape_string($code)."'", ['limit'=>1]);
    return (int)$db->fetch_field($q, 'id');
}

function afaa_user_type_enabled(int $uid, int $type_id): bool
{
    global $db;
    $q = $db->simple_select('alert_settings','enabled', "uid={$uid} AND type_id={$type_id}", ['limit'=>1]);
    if ($db->num_rows($q) === 0) return true;
    return (int)$db->fetch_field($q,'enabled') === 1;
}

function afaa_set_user_type_enabled(int $uid, int $type_id, bool $on): void
{
    global $db;
    $exists = $db->simple_select('alert_settings','uid', "uid={$uid} AND type_id={$type_id}", ['limit'=>1]);
    if ($db->num_rows($exists)) {
        $db->update_query('alert_settings', ['enabled' => $on?1:0], "uid={$uid} AND type_id={$type_id}");
    } else {
        $db->insert_query('alert_settings', ['uid' => $uid, 'type_id'=>$type_id, 'enabled'=>$on?1:0]);
    }
}

/**
 * Вернуть все типы уведомлений с учётом пользовательских предпочтений.
 *
 * @return array{code:string,title:string,enabled:bool,can_disable:bool,user_enabled:bool,id:int}[]
 */
function afaa_types_for_user(int $uid, bool $only_enabled = true): array
{
    global $db;

    $types = [];
    $q = $db->simple_select('alert_types', 'id,code,title,enabled,can_be_user_disabled');
    while ($t = $db->fetch_array($q)) {
        $enabled = (int)$t['enabled'] === 1;
        if ($only_enabled && !$enabled) {
            continue;
        }

        $canDisable = (int)$t['can_be_user_disabled'] === 1;
        $types[] = [
            'id'           => (int)$t['id'],
            'code'         => (string)$t['code'],
            'title'        => (string)($t['title'] ?: $t['code']),
            'enabled'      => $enabled,
            'can_disable'  => $canDisable,
            'user_enabled' => $canDisable ? afaa_user_type_enabled($uid, (int)$t['id']) : true,
        ];
    }

    return $types;
}

function afaa_save_user_types(int $uid, array $incoming): void
{
    $types = afaa_types_for_user($uid, false);
    foreach ($types as $type) {
        if (!$type['can_disable']) {
            continue;
        }

        $on = isset($incoming[$type['code']]);
        afaa_set_user_type_enabled($uid, (int)$type['id'], $on);
    }
}

function afaa_send(int $uid, string $type_code, array $extra = [], ?int $from_user_id = null, $object_id = null): bool
{
    global $db, $mybb;

    if ($uid <= 0) {
        return false;
    }

    if (!afaa_user_in_allowed_groups($uid)) {
        return false;
    }

    $type_code = trim($type_code);
    if ($type_code === '') {
        return false;
    }

    $row = $db->fetch_array(
        $db->simple_select(
            'alert_types',
            'id, code, title, enabled, can_be_user_disabled',
            "code='".$db->escape_string($type_code)."'", 
            ['limit' => 1]
        )
    );

    if (!$row || (int)$row['id'] <= 0) {
        afaa_register_default_types();

        $row = $db->fetch_array(
            $db->simple_select(
                'alert_types',
                'id, code, title, enabled, can_be_user_disabled',
                "code='".$db->escape_string($type_code)."'", 
                ['limit' => 1]
            )
        );

        if (!$row || (int)$row['id'] <= 0) {
            $insert_id = (int)$db->insert_query('alert_types', [
                'code'                 => $db->escape_string($type_code),
                'title'                => $db->escape_string($type_code),
                'enabled'              => 1,
                'can_be_user_disabled' => 1,
            ]);

            if ($insert_id <= 0) {
                return false;
            }

            $row = [
                'id'                   => $insert_id,
                'code'                 => $type_code,
                'title'                => $type_code,
                'enabled'              => 1,
                'can_be_user_disabled' => 1,
            ];
        }
    }

    $type_id             = (int)$row['id'];
    $type_enabled_global = (int)$row['enabled'] === 1;
    $type_user_can_off   = (int)$row['can_be_user_disabled'] === 1;

    if (!$type_enabled_global) {
        return false;
    }

    if ($type_user_can_off) {
        if (!afaa_user_type_enabled($uid, $type_id)) {
            return false;
        }
    }

    $row_insert = [
        'uid'        => $uid,
        'type_id'    => $type_id,
        'dateline'   => TIME_NOW,
        'is_read'    => 0,
        'visible'    => 1,
        'extra_data' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    if (!empty($from_user_id)) {
        $row_insert['from_user_id'] = (int)$from_user_id;
    }
    if (!empty($object_id)) {
        $row_insert['object_id'] = (int)$object_id;
    }

    $cond = "uid=".(int)$row_insert['uid']." AND type_id=".(int)$row_insert['type_id']." AND is_read=0 AND dateline>=".(TIME_NOW - 120);

    if (!empty($from_user_id)) {
        $cond .= " AND from_user_id=".(int)$from_user_id;
    }
    if (!empty($object_id)) {
        $cond .= " AND object_id=".(int)$object_id;
    }

    $dupe = $db->simple_select('alerts', 'id', $cond, ['limit' => 1]);
    if ($db->num_rows($dupe) > 0) {
        return false;
    }

    $db->insert_query('alerts', $row_insert);
    return (int)$db->insert_id() > 0;
}

function afaa_profile_link(int $uid, string $username): string
{
    require_once MYBB_ROOT.'inc/functions.php';
    return build_profile_link(htmlspecialchars_uni($username), $uid);
}

function afaa_compose_row_text(array $r): array
{
    $type = (string)($r['type_code'] ?? '');
    $ex   = is_array($r['extra']) ? $r['extra'] : [];

    switch ($type) {
        case 'reputation':
            $type = 'rep';
            break;
        case 'quote':
        case 'quoted_you':
            $type = 'quoted';
            break;
        case 'buddy_request':
            $type = 'buddylist';
            break;
        case 'mentioned_you':
            $type = 'mention';
            break;
        case 'post_threadauthor':
            $type = 'subscribed_thread';
            break;
        case 'group_mention':
            $type = 'mention_group';
            break;
    }

    $from_uid  = (int)($r['from_user_id'] ?? 0);
    $from_name = (string)($ex['from_username'] ?? ($ex['from_name'] ?? 'Пользователь'));
    $from_a    = afaa_user_avatar_url($from_uid);
    $from_link = $from_uid > 0 ? afaa_profile_link($from_uid, $from_name) : htmlspecialchars_uni($from_name);

    $title      = trim((string)($ex['subject'] ?? $ex['title'] ?? ''));
    $title_html = $title !== '' ? htmlspecialchars_uni($title) : '';

    $thread_title_html = htmlspecialchars_uni((string)($ex['thread_subject'] ?? $title));
    $msg_link = (string)($ex['link'] ?? 'javascript:void(0)');

    if ($type === 'rep') {
        $points = (int)($ex['points'] ?? 0);
        $reason = trim((string)($ex['reason'] ?? ''));
        $delta  = $points > 0 ? '+'.$points : (string)$points;

        $txt = '"'.$from_link.'" изменил вашу репутацию';
        if ($points !== 0) {
            $txt .= ' ('.$delta.')';
        }
        if ($reason !== '') {
            $txt .= ' — '.htmlspecialchars_uni($reason);
        }

        return ['text_html' => $txt, 'link' => $msg_link ?: 'reputation.php', 'avatar' => $from_a];
    }

    if ($type === 'pm') {
        $txt = 'Новое личное сообщение от "'.$from_link.'"'
             . ($title_html ? ' с заголовком "'.$title_html.'"' : '');
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'mention') {
        $txt = '"'.$from_link.'" упомянул вас в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'mention_group') {
        $txt = '"'.$from_link.'" упомянул группу, в которую вы входите, в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'mention_all') {
        $txt = '"'.$from_link.'" сделал глобальное упоминание @all в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'subscribed_thread') {
        $txt = '"'.$from_link.'" написал в теме "'.$thread_title_html.'", на которую вы подписаны';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'subscribed_forum') {
        $txt = '"'.$from_link.'" создал новую тему "'.$thread_title_html.'" в форуме, на который вы подписаны';
        return ['text_html' => $txt, 'link' => $msg_link, 'avatar' => $from_a];
    }

    if ($type === 'quoted') {
        $txt = '"'.$from_link.'" процитировал вас в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    if ($type === 'buddylist') {
        $txt = '"'.$from_link.'" отправил вам запрос в друзья';
        return [
            'text_html'=>$txt,
            'link'     =>$msg_link ?: 'usercp.php?action=editlists',
            'avatar'   =>$from_a
        ];
    }

    if ($type === 'buddy_accept') {
        $txt = '"'.$from_link.'" принял вашу заявку в друзья';
        return [
            'text_html'=>$txt,
            'link'     =>$msg_link ?: 'usercp.php?action=editlists',
            'avatar'   =>$from_a
        ];
    }

    $baseTitle = $title_html !== ''
        ? $title_html
        : htmlspecialchars_uni($r['type_title'] ?? $type);

    if ($from_uid > 0) {
        $txt = '"'.$from_link.'" — '.$baseTitle;
    } else {
        $txt = $baseTitle;
    }

    return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
}

function afaa_list_for_user(int $uid, int $limit=20, int $offset=0): array
{
    global $db;
    $res = [];
    $q = $db->write_query("
        SELECT a.*, t.code AS type_code, t.title AS type_title
        FROM ".TABLE_PREFIX."alerts a
        LEFT JOIN ".TABLE_PREFIX."alert_types t ON (t.id=a.type_id)
        WHERE a.visible=1 AND a.uid=".(int)$uid."
        ORDER BY a.dateline DESC
        LIMIT ".(int)$offset.", ".(int)$limit
    );
    while ($r = $db->fetch_array($q)) {
        $r['extra'] = $r['extra_data'] ? @json_decode($r['extra_data'], true) : [];
        $res[] = $r;
    }
    return $res;
}

function afaa_unread_count(int $uid): int
{
    global $db;
    $q = $db->simple_select('alerts','COUNT(*) AS c', "uid={$uid} AND `is_read`=0 AND visible=1");
    return (int)$db->fetch_field($q, 'c');
}

function afaa_mark_read_all(int $uid): void
{
    global $db;
    $db->update_query('alerts', ['is_read'=>1], "uid={$uid} AND `is_read`=0");
}

function afaa_mark_read_ids(int $uid, array $ids): void
{
    global $db;
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) return;
    $db->update_query('alerts', ['is_read'=>1], "uid={$uid} AND id IN (".implode(',',$ids).")");
}

function afaa_delete_ids(int $uid, array $ids): void
{
    global $db;
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) return;
    $db->delete_query('alerts', "uid={$uid} AND id IN (".implode(',',$ids).")");
}

function afaa_prune_old(int $days): void
{
    global $db;
    $ttl = max(1, (int)$days) * 86400;
    $db->delete_query('alerts', "dateline < ".(TIME_NOW - $ttl));
}


/* ===== Helpers: правка шаблонов ===== */

function afaa_tpl_insert_links(): void
{
    global $db;
    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    $update_all_by_title = function(string $title, callable $mutate) use ($db)
    {
        $q = $db->simple_select('templates', 'tid,template', "title='".$db->escape_string($title)."'", ['limit' => 1000]);
        while ($tpl = $db->fetch_array($q)) {
            $t = $tpl['template'];
            $new = $mutate($t);
            if ($new !== $t) {
                $db->update_query('templates', ['template'=>$db->escape_string($new)], "tid=".(int)$tpl['tid']);
            }
        }
    };

    $update_all_by_title('usercp_nav_home', function($t){
        $beg='<!--AF_ADVANCEDALERTS_NAV_HOME_START-->'; $end='<!--AF_ADVANCEDALERTS_NAV_HOME_END-->';
        if (strpos($t, $beg)!==false) return $t;
        $block = $beg."\n".'<td class="trow1 smalltext"><a href="misc.php?action=af_alerts" class="usercp_nav_item">Мои уведомления</a></td>'."\n".$end;
        return $t."\n".$block;
    });

    $update_all_by_title('usercp_nav_profile', function($t){
        $beg='<!--AF_ADVANCEDALERTS_NAV_PROFILE_START-->'; $end='<!--AF_ADVANCEDALERTS_NAV_PROFILE_END-->';
        if (strpos($t, $beg)!==false) return $t;
        $block = $beg."\n".'<td class="trow1 smalltext"><a href="usercp.php?action=af_alert_prefs" class="usercp_nav_item">Настройки уведомлений</a></td>'."\n".$end;
        return $t."\n".$block;
    });

    $update_all_by_title('usercp', function($t){
        $beg='<!--AF_ADVANCEDALERTS_UCP_INLINE_START-->'; $end='<!--AF_ADVANCEDALERTS_UCP_INLINE_END-->';
        if (strpos($t, $beg)!==false) return $t;
        $needle = '{$usercpprofile_e}';
        $block  = $beg."\n"
                . '<div class="trow1 smalltext afaa-ucp-links">'
                . '<a href="misc.php?action=af_alerts">Мои уведомления</a> &middot; '
                . '<a href="usercp.php?action=af_alert_prefs">Настройки уведомлений</a>'
                . '</div>'."\n"
                . $end;
        return (strpos($t,$needle)!==false) ? str_replace($needle, $needle."\n".$block, $t) : $t."\n".$block;
    });
}

function afaa_tpl_remove_links(): void
{
    global $db;
    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    $kill = [
        'usercp_nav_home'    => '#<!--AF_ADVANCEDALERTS_NAV_HOME_START-->.*?<!--AF_ADVANCEDALERTS_NAV_HOME_END-->#s',
        'usercp_nav_profile' => '#<!--AF_ADVANCEDALERTS_NAV_PROFILE_START-->.*?<!--AF_ADVANCEDALERTS_NAV_PROFILE_END-->#s',
        'usercp'             => '#<!--AF_ADVANCEDALERTS_UCP_INLINE_START-->.*?<!--AF_ADVANCEDALERTS_UCP_INLINE_END-->#s',
    ];
    foreach ($kill as $title => $rx) {
        $q = $db->simple_select('templates','tid,template', "title='".$db->escape_string($title)."'");
        while ($tpl = $db->fetch_array($q)) {
            $t = preg_replace($rx, '', $tpl['template']);
            if ($t !== $tpl['template']) {
                $db->update_query('templates', ['template'=>$db->escape_string($t)], "tid=".(int)$tpl['tid']);
            }
        }
    }
}

function afaa_users_in_groups(array $gids): array
{
    global $db;

    $uids = [];
    $gids = array_filter(array_map('intval', $gids));
    if (!$gids) {
        return [];
    }

    $condParts = [];
    foreach ($gids as $gid) {
        if ($gid <= 0) continue;
        $condParts[] = "usergroup={$gid}";
        $gidEsc = (int)$gid;
        $condParts[] = "FIND_IN_SET({$gidEsc}, additionalgroups)";
    }

    if (!$condParts) {
        return [];
    }

    $where = '('.implode(' OR ', $condParts).')';

    $q = $db->simple_select('users', 'uid', $where);
    while ($u = $db->fetch_array($q)) {
        $uid = (int)$u['uid'];
        if ($uid > 0) {
            $uids[$uid] = true;
        }
    }

    return array_keys($uids);
}


/* ====================== HOOKS REG ======================= */

function af_advancedalerts_init(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    global $mybb, $plugins, $db;

    if (!isset($mybb->settings['af_advancedalerts_enabled'])) {
        af_advancedalerts_install();
        rebuild_settings();
    }

    if ((int)($mybb->settings['af_advancedalerts_enabled'] ?? 0) !== 1) {
        return;
    }

    afaa_prune_old(AF_AA_RETENTION_DAYS);
    afaa_register_default_types();
    afaa_maybe_migrate_schema();

    $plugins->add_hook('global_start',    'afaa_global_start');
    $plugins->add_hook('xmlhttp', 'afaa_xmlhttp_router');
    $plugins->add_hook('misc_start', 'afaa_xmlhttp_router');
    $plugins->add_hook('pre_output_page', 'afaa_pre_output');
    $plugins->add_hook('misc_start',      'afaa_misc_router');
    $plugins->add_hook('usercp_start',    'afaa_usercp_router');
 


    $plugins->add_hook('build_friendly_wol_location_end', 'afaa_wol_location');

    $plugins->add_hook('usercp_end',            'afaa_usercp_end');
    $plugins->add_hook('usercp_do_options_end', 'afaa_usercp_do_options_end');

    $plugins->add_hook('datahandler_post_insert_post',   'afaa_post_insert_end');
    $plugins->add_hook('datahandler_post_insert_thread', 'afaa_post_insert_end');

    $plugins->add_hook('datahandler_pm_insert_commit', 'afaa_pm_insert_end');

    $plugins->add_hook('usercp_do_editlists_end', 'afaa_editlists_end');

    $plugins->add_hook('reputation_do_add_end', 'afaa_rep_do_add_end');

    $plugins->add_hook('postbit',             'afaa_postbit_mentions');
    $plugins->add_hook('postbit_prev',        'afaa_postbit_mentions');
    $plugins->add_hook('postbit_announcement','afaa_postbit_mentions');
}

function afaa_pm_after_send(): void {}
function afaa_newreply_after(): void {}


/* ====================== FRONT INJECTION ======================= */

function afaa_global_start(): void
{
    global $mybb, $db;

    static $schema_ok = null;
    if ($schema_ok === null) {
        $schema_ok = true;
        if (!$db->table_exists('alert_types') || !$db->table_exists('alerts')) {
            af_advancedalerts_install();
            rebuild_settings();
            $schema_ok = false;
        }
        if ($schema_ok) {
            $need = [
                'rep',
                'pm',
                'buddylist',
                'buddy_accept',
                'quoted',
                'subscribed_thread',
                'subscribed_forum',
                'mention',
                'mention_group',
                'mention_all',
            ];

            $have = [];
            $q = $db->simple_select('alert_types','code');
            while ($r = $db->fetch_array($q)) {
                $have[] = $r['code'];
            }
            foreach (array_diff($need, $have) as $miss) {
                afaa_register_type($miss, ucfirst(str_replace('_',' ',$miss)), true, true);
            }
        }
    }

    if (!isset($GLOBALS['afaa_post_key_js'])) {
        $pk = addslashes($mybb->post_code ?? '');
        if (isset($GLOBALS['headerinclude'])) {
            $inject = "<script>window.my_post_key=window.my_post_key||'{$pk}';</script>";
            if (strpos($GLOBALS['headerinclude'], 'window.my_post_key') === false) {
                $GLOBALS['headerinclude'] .= "\n{$inject}\n";
            }
        }
        $GLOBALS['afaa_post_key_js'] = true;
    }
}

function afaa_pre_output(string &$page): void
{
    global $mybb;

    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php' && $mybb->get_input('action') === 'af_alerts_api') {
        return;
    }

    $baseurl = rtrim($mybb->settings['bburl'], '/');
    $limit   = (int)($mybb->settings['af_aa_dropdown_limit'] ?? 10);
    $poll    = max(5, (int)($mybb->settings['af_aa_poll_seconds'] ?? 20));
    $toast   = max(1, (int)($mybb->settings['af_aa_toast_limit'] ?? 5));
    $me      = (int)($mybb->user['uid'] ?? 0);
    $up      = $me > 0 ? afaa_get_user_prefs($me) : ['sound'=>1,'toasts'=>1];

    if ($me <= 0) {
        return;
    }

    require_once MYBB_ROOT.'inc/functions_user.php';
    $def = format_avatar('', '', '64x64');
    $defAvatar = is_array($def) ? (string)$def['image'] : (string)$def;

    $post_key = addslashes($mybb->post_code ?? '');

    $jsPath = AF_AA_PUBLIC_DIR.'af_alerts.js';
    $jsVer  = @file_exists($jsPath) ? @filemtime($jsPath) : TIME_NOW;

    $cfgScript = "<script>window.AFAlertsCfg={"
        ."pollSec:{$poll},"
        ."dropdownLimit:{$limit},"
        ."toastLimit:{$toast},"
        ."defAvatar:'".htmlspecialchars($defAvatar, ENT_QUOTES)."',"
        ."userSound:".((int)$up['sound'] ? 'true' : 'false').","
        ."userToasts:".((int)$up['toasts'] ? 'true' : 'false').","
        ."userId:{$me}"
        ."};</script>";

    $head  = "\n";
    $head .= "<script>window.my_post_key=window.my_post_key||'{$post_key}';</script>\n";
    $head .= "<link rel=\"stylesheet\" href=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/af_alerts.css?v={$jsVer}\" />\n";
    $head .= $cfgScript . "\n";
    $head .= "<script src=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/af_alerts.js?v={$jsVer}\"></script>\n";
    $head .= "<audio id=\"afaa-audio\" preload=\"auto\" style=\"display:none\"><source src=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/ping.mp3\" type=\"audio/mpeg\"></audio>\n";

    $page = str_replace('</head>', $head.'</head>', $page);

    afaa_pre_output_mentions($page);

    $badge = afaa_unread_count($me);
    $title = 'Уведомления';
    $all   = 'Все уведомления';
    $gear  = 'Настройки';
    $back  = 'Вернуться к уведомлениям';

    $soundChecked = $up['sound'] ? ' checked' : '';

    $bell = <<<HTML
    <li class="afaa-li">
    <a href="#" class="afaa-bell-link" onclick="return AFAlerts.togglePopup(this)" aria-label="{$title}">
        <span class="afaa-bell">&#128276;</span>
        <span class="afaa-label">{$title}</span>
        <span class="afaa-badge" data-afaa-badge>{$badge}</span>
    </a>
    <div class="afaa-popup" role="dialog" aria-modal="true" style="display:none">
        <div class="afaa-popup-head">
        <span class="afaa-head-title" data-afaa-head-title>{$title}</span>
        <div class="afaa-head-actions">
            <button class="afaa-back" type="button" data-afaa-prefs-back title="{$back}" style="display:none">←</button>
            <span class="afaa-sound">
            Звук:
            <label class="afaa-switch">
                <input type="checkbox" data-afaa-sound-toggle{$soundChecked}>
                <span class="afaa-slider"></span>
            </label>
            </span>
            <button class="afaa-gear" type="button" data-afaa-open-prefs title="{$gear}">⚙</button>
            <button class="afaa-close" type="button" aria-label="Закрыть" onclick="AFAlerts.closePopup(this)">×</button>
        </div>
        </div>
        <div class="afaa-popup-body" data-afaa-view="list">
        <ul class="afaa-list" data-afaa-list></ul>
        <div class="afaa-empty" data-afaa-empty style="display:none">Нет уведомлений</div>
        </div>
        <div class="afaa-popup-body afaa-popup-body-prefs" data-afaa-view="prefs" style="display:none">
            <div class="afaa-prefs" data-afaa-prefs></div>
        </div>
        <div class="afaa-popup-foot">
        <a class="button small" href="misc.php?action=af_alerts">{$all}</a>
        <button class="button small" type="button" onclick="AFAlerts.markAllRead()">Пометить прочитанным</button>
        </div>
    </div>
    </li>

    <div class="afaa-toasts" data-afaa-toasts></div>
    HTML;

    if (preg_match('~(<ul[^>]*class=["\']menu\s+user_links["\'][^>]*>)~i', $page)) {
        $page = preg_replace('~(<ul[^>]*class=["\']menu\s+user_links["\'][^>]*>)~i', '$1'.$bell, $page, 1);
    } else {
        $page = str_replace('{$header}', '{$header}<ul class="menu user_links">'.$bell.'</ul>', $page);
    }

    if (stripos($page, '</body>') === false) {
        $page .= '<div class="afaa-toasts" data-afaa-toasts></div>';
    }
}


/* ====================== ROUTER (PAGE + AJAX + MENTIONS) ======================= */

function afaa_misc_router(): void
{
    global $mybb, $db, $header, $footer, $headerinclude, $templates, $theme, $lang;

    $action = $mybb->get_input('action');
    if ($action !== 'af_alerts' && $action !== 'af_mention_suggest') {
        return;
    }

    if ($action === 'af_mention_suggest') {
        afaa_misc_mention_suggest();
        exit;
    }

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }

    if ($action === 'af_alerts') {
        add_breadcrumb('Уведомления', 'misc.php?action=af_alerts');

        $perpage = max(1, (int)($mybb->settings['af_aa_page_perpage'] ?? 20));
        $pageN   = max(1, (int)$mybb->get_input('page'));
        $start   = ($pageN - 1) * $perpage;

        $since = TIME_NOW - (AF_AA_RETENTION_DAYS * 86400);
        $uid   = (int)$mybb->user['uid'];

        $cntq  = $db->simple_select('alerts','COUNT(*) AS c', "uid={$uid} AND visible=1 AND dateline>={$since}");
        $total = (int)$db->fetch_field($cntq, 'c');

        $rows = [];
        $q = $db->write_query("
            SELECT a.*, t.code AS type_code, t.title AS type_title
            FROM ".TABLE_PREFIX."alerts a
            LEFT JOIN ".TABLE_PREFIX."alert_types t ON (t.id=a.type_id)
            WHERE a.visible=1 AND a.uid={$uid} AND a.dateline>={$since}
            ORDER BY a.dateline DESC
            LIMIT {$start}, {$perpage}
        ");
        while ($r = $db->fetch_array($q)) {
            $r['extra'] = $r['extra_data'] ? @json_decode($r['extra_data'], true) : [];
            $rows[] = $r;
        }

        $tbody = '';
        foreach ($rows as $r) {
            $fmt  = afaa_compose_row_text($r);
            $ava  = htmlspecialchars($fmt['avatar']);
            $time = my_date('relative', (int)$r['dateline']);
            $cls  = ((int)$r['is_read'] === 1) ? 'is-read' : 'is-unread';

            $tbody .= '<tr class="'.$cls.'">'
                    . '<td class="afaa-col-from">'.($ava?'<img class="afaa-ava" src="'.$ava.'" alt="">':'').'</td>'
                    . '<td class="afaa-col-text"><a class="afaa-row-link" href="'.htmlspecialchars($fmt['link']).'" data-id="'.(int)$r['id'].'">'.$fmt['text_html'].'</a></td>'
                    . '<td class="afaa-col-time">'.$time.'</td>'
                    . '<td class="afaa-col-actions">'
                        . '<form method="post" action="misc.php?action=af_alerts" class="inline">'
                        . '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">'
                        . '<input type="hidden" name="op" value="mark_read">'
                        . '<input type="hidden" name="ids[]" value="'.(int)$r['id'].'">'
                        . '<input type="submit" class="button small" value="✓" title="Прочитано" style="opacity:.4">'
                        . '</form> '
                        . '<form method="post" action="misc.php?action=af_alerts" class="inline">'
                        . '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">'
                        . '<input type="hidden" name="op" value="delete">'
                        . '<input type="hidden" name="ids[]" value="'.(int)$r['id'].'">'
                        . '<input type="submit" class="button small" value="×" title="Удалить">'
                        . '</form>'
                    . '</td>'
                    . '</tr>';
        }

        $nav = '';
        if ($total > $perpage) {
            $pages = (int)ceil($total / $perpage);
            $nav .= '<div class="afaa-pager">';
            for ($i=1; $i<=$pages; $i++) {
                $cls = $i === $pageN ? 'class="current"' : '';
                $nav .= '<a '.$cls.' href="misc.php?action=af_alerts&page='.$i.'">'.$i.'</a> ';
            }
            $nav .= '</div>';
        }

        $content = '<div class="afaa-page">'
            . '<div class="smalltext" style="opacity:.7;margin-bottom:.5rem">AF Alerts page OK</div>'
            . '<h2>Мои уведомления (за последние '.(int)AF_AA_RETENTION_DAYS.' дней)</h2>'
            . $nav
            . '<table class="tborder afaa-table">'
            . '<thead><tr>'
            . '<th class="tcat">Кто</th>'
            . '<th class="tcat">Что</th>'
            . '<th class="tcat">Когда</th>'
            . '<th class="tcat">Действия</th>'
            . '</tr></thead>'
            . '<tbody>'.($tbody ?: '<tr><td colspan="4"><em>Нет уведомлений</em></td></tr>').'</tbody>'
            . '</table>'
            . $nav
            . '</div>';

        $misc = $content;
        eval("\$page = \"".$templates->get('misc')."\";");
        output_page($page);
        exit;
    }
}

function afaa_xmlhttp_router(): void
{
    global $mybb, $db;

    // Работаем только на нашем эндпоинте
    if ($mybb->get_input('action') !== 'af_alerts_api') {
        return;
    }

    // Только POST. Если прилетает GET (например, пользователь кликает по ссылке в WOL),
    // не отвечаем ошибкой, чтобы не ломать UX, а просто перенаправляем на главную.
    if ($mybb->request_method !== 'post') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors', '0');
        header('Location: index.php');
        exit;
    }

    // Чистим буферы и настраиваем заголовки под JSON
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('display_errors', '0');
    @header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    // На всякий случай ещё раз метод проверим (через my_strtolower)
    if (my_strtolower($mybb->request_method) !== 'post') {
        echo json_encode(['ok' => 0, 'error' => 'method_not_allowed']);
        exit;
    }

    // Авторизация
    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'unauthorized']);
        exit;
    }

    // Операция
    $op = (string)$mybb->get_input('op');

    // CSRF
    $post_key = (string)$mybb->get_input('my_post_key');
    $real_key = (string)($mybb->post_code ?? '');

    $write_ops = [
        'prefs',
        'mark_all_read',
        'mark_read',
        'delete',
        'probe_insert',
    ];

    $requires_csrf = in_array($op, $write_ops, true);

    if ($requires_csrf) {
        if ($post_key === '' || $real_key === '' || !hash_equals($real_key, $post_key)) {
            echo json_encode(['ok' => 0, 'error' => 'bad_csrf', 'op' => $op]);
            exit;
        }
    }

    // --- ВАЖНО: всё, что ниже, заворачиваем в try/catch,
    // чтобы при любой ошибке отдать JSON, а не пустую страницу ---
    try {

        if ($op === 'ping') {
            echo json_encode(['ok' => 1, 'pong' => TIME_NOW]);
            exit;
        }

        if ($op === 'list') {
            // На всякий случай проверим, существует ли функция
            if (!function_exists('afaa_list_for_user')) {
                echo json_encode([
                    'ok'    => 0,
                    'error' => 'no_afaa_list_for_user',
                ]);
                exit;
            }

            $limit = max(
                1,
                (int)($mybb->get_input('limit') ?: ($mybb->settings['af_aa_dropdown_limit'] ?? 10))
            );

            // Могут тут падать запросы/язык/формат extra_data — всё попадёт в catch
            $rows = afaa_list_for_user($uid, $limit, 0);

            $data = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $from = (int)($r['from_user_id'] ?? 0);
                    $fmt  = afaa_compose_row_text($r);

                    $data[] = [
                        'id'      => (int)$r['id'],
                        'read'    => (int)$r['is_read'],
                        'is_read' => (int)$r['is_read'],
                        'html'    => (string)$fmt['text_html'],
                        'title'   => strip_tags((string)$fmt['text_html']),
                        'link'    => (string)$fmt['link'],
                        'time'    => my_date('relative', (int)$r['dateline']),
                        'from'    => $from,
                        'avatar'  => (string)$fmt['avatar'],
                    ];
                }
            }

            echo json_encode([
                'ok'    => 1,
                'items' => $data,
                'badge' => afaa_unread_count($uid),
            ]);
            exit;
        }

        if ($op === 'types') {
            echo json_encode([
                'ok'    => 1,
                'types' => afaa_types_for_user($uid),
                'prefs' => afaa_get_user_prefs($uid),
            ]);
            exit;
        }

        if ($op === 'badge') {
            echo json_encode(['ok' => 1, 'badge' => afaa_unread_count($uid)]);
            exit;
        }

        if ($op === 'prefs') {
            $sound  = (int)$mybb->get_input('sound') ? 1 : 0;
            $toasts = (int)$mybb->get_input('toasts') ? 1 : 0;
            $types  = $mybb->get_input('types', MyBB::INPUT_ARRAY) ?? [];

            afaa_set_user_prefs($uid, $sound, $toasts);
            if (is_array($types)) {
                afaa_save_user_types($uid, $types);
            }

            echo json_encode([
                'ok'     => 1,
                'sound'  => $sound,
                'toasts' => $toasts,
                'badge'  => afaa_unread_count($uid),
            ]);
            exit;
        }

        if ($op === 'mark_all_read') {
            afaa_mark_read_all($uid);
            echo json_encode(['ok' => 1, 'badge' => 0]);
            exit;
        }

        if ($op === 'mark_read') {
            $ids = $mybb->get_input('ids', MyBB::INPUT_ARRAY) ?? [];
            afaa_mark_read_ids($uid, array_map('intval', $ids));
            echo json_encode([
                'ok'    => 1,
                'badge' => afaa_unread_count($uid),
            ]);
            exit;
        }

        if ($op === 'delete') {
            $ids = $mybb->get_input('ids', MyBB::INPUT_ARRAY) ?? [];
            afaa_delete_ids($uid, array_map('intval', $ids));
            echo json_encode([
                'ok'    => 1,
                'badge' => afaa_unread_count($uid),
            ]);
            exit;
        }

        if ($op === 'diag') {
            $ok        = true;
            $problems  = [];

            $have_types  = $db->table_exists('alert_types');
            $have_alerts = $db->table_exists('alerts');
            $have_sets   = $db->table_exists('alert_settings');

            if (!$have_types)  { $ok = false; $problems[] = 'no_table:alert_types'; }
            if (!$have_alerts) { $ok = false; $problems[] = 'no_table:alerts'; }
            if (!$have_sets)   { $problems[] = 'no_table:alert_settings'; }

            $enabled = (int)($mybb->settings['af_advancedalerts_enabled'] ?? 0);

            $cnt_total = 0;
            $cnt_user  = 0;
            $per_uid   = [];
            $last_rows = [];
            $diag_uid  = (int)$mybb->get_input('uid');
            if ($diag_uid <= 0) {
                $diag_uid = $uid;
            }

            if ($have_alerts) {
                $r = $db->simple_select('alerts', 'COUNT(*) AS c');
                $cnt_total = (int)$db->fetch_field($r, 'c');

                $r2 = $db->simple_select('alerts', 'COUNT(*) AS c', "uid=".(int)$diag_uid);
                $cnt_user = (int)$db->fetch_field($r2, 'c');

                $uq = $db->write_query("
                    SELECT uid, COUNT(*) AS c
                    FROM ".TABLE_PREFIX."alerts
                    GROUP BY uid
                    ORDER BY c DESC
                    LIMIT 20
                ");
                while ($urow = $db->fetch_array($uq)) {
                    $per_uid[] = [
                        'uid'   => (int)$urow['uid'],
                        'count' => (int)$urow['c'],
                    ];
                }

                $lq = $db->write_query("
                    SELECT id, uid, type_id, is_read, visible, dateline,
                           LEFT(extra_data, 200) AS extra_snip
                    FROM ".TABLE_PREFIX."alerts
                    WHERE uid=".(int)$diag_uid."
                    ORDER BY id DESC
                    LIMIT 20
                ");
                while ($lr = $db->fetch_array($lq)) {
                    $last_rows[] = [
                        'id'        => (int)$lr['id'],
                        'uid'       => (int)$lr['uid'],
                        'type_id'   => (int)$lr['type_id'],
                        'is_read'   => (int)$lr['is_read'],
                        'visible'   => (int)$lr['visible'],
                        'dateline'  => (int)$lr['dateline'],
                        'extra'     => (string)$lr['extra_snip'],
                    ];
                }
            }

            echo json_encode([
                'ok'      => $ok ? 1 : 0,
                'enabled' => $enabled,
                'problems' => $problems,
                'tables'   => [
                    'alert_types'    => $have_types ? 1 : 0,
                    'alerts'         => $have_alerts ? 1 : 0,
                    'alert_settings' => $have_sets ? 1 : 0,
                ],
                'counts' => [
                    'all'   => $cnt_total,
                    'user'  => $cnt_user,
                    'badge' => afaa_unread_count($uid),
                ],
                'per_uid'      => $per_uid,
                'diag_uid'     => $diag_uid,
                'last_for_uid' => $last_rows,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($op === 'probe_insert') {
            $have_is_read = false;
            $colq = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX."alerts` LIKE 'is_read'");
            if ($colq && $db->num_rows($colq) > 0) {
                $have_is_read = true;
            }
            if (!$have_is_read) {
                echo json_encode(['ok' => 0, 'error' => 'schema_mismatch_missing_is_read']);
                exit;
            }

            $needCols = ['id','uid','type_id','dateline','is_read','visible','extra_data'];
            $haveCols = [];
            $colq = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX."alerts`", true);
            if ($colq) {
                while ($c = $db->fetch_array($colq)) {
                    $haveCols[my_strtolower($c['Field'])] = true;
                }
            }
            foreach ($needCols as $c) {
                if (empty($haveCols[$c])) {
                    echo json_encode(['ok'=>0, 'error'=>'schema_mismatch_missing_col', 'col'=>$c]);
                    exit;
                }
            }

            $pmid = afaa_type_id('pm');
            if ($pmid <= 0) {
                afaa_register_default_types();
                $pmid = afaa_type_id('pm');
            }
            if ($pmid <= 0) {
                echo json_encode(['ok'=>0, 'error'=>'no_type_pm']);
                exit;
            }

            $row = [
                'uid'        => $uid,
                'type_id'    => (int)$pmid,
                'dateline'   => TIME_NOW,
                'is_read'    => 0,
                'visible'    => 1,
                'extra_data' => json_encode(['title'=>'probe','link'=>'index.php'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ];

            $tbl = TABLE_PREFIX.'alerts';
            $sql = "INSERT INTO `{$tbl}` (`uid`,`type_id`,`dateline`,`is_read`,`visible`,`extra_data`) VALUES (".
                (int)$row['uid'].",".
                (int)$row['type_id'].",".
                (int)$row['dateline'].",".
                (int)$row['is_read'].",".
                (int)$row['visible'].",".
                "'".$db->escape_string($row['extra_data'])."')";

            $ok  = 0;
            $id  = 0;
            $errNo  = 0;
            $errStr = '';
            $res = $db->write_query($sql, true);
            if ($res) {
                $ok = 1;
                $id = (int)$db->insert_id();
            } else {
                if (method_exists($db,'error_number')) $errNo = (int)$db->error_number();
                if (method_exists($db,'error'))        $errStr = (string)$db->error();
            }

            $back = [];
            if ($id > 0) {
                $q = $db->simple_select('alerts','id,uid,type_id,`is_read`,visible,dateline',"id={$id}",['limit'=>1]);
                $back = $db->fetch_array($q) ?: [];
            }

            echo json_encode([
                'ok'       => $ok,
                'insert_id'=> $id,
                'err_no'   => $errNo,
                'err'      => $errStr,
                'table'    => $tbl,
                'readback' => $back,
                'badge'    => afaa_unread_count($uid),
            ]);
            exit;
        }

        // Неизвестная операция
        echo json_encode(['ok' => 0, 'error' => 'unknown_op', 'op' => $op]);
        exit;

    } catch (\Throwable $e) {
        // Ловим ЛЮБУЮ ошибку в логике выше и даём осмысленный JSON,
        // чтобы на фронте не было "empty response".
        $resp = [
            'ok'    => 0,
            'error' => 'exception',
            'msg'   => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ];
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }
}




/* ====================== UCP: OPTIONS BOX ======================= */

function afaa_usercp_end(): void
{
    global $mybb, $db;
    if ($mybb->get_input('action') !== 'options') return;
    if ((int)($mybb->settings['af_aa_allow_user_disable'] ?? 0) !== 1) return;
    $uid = (int)$mybb->user['uid']; if ($uid<=0) return;

    $rows='';
    $q=$db->simple_select('alert_types','id,code,title,enabled');
    while ($t=$db->fetch_array($q)) {
        if ((int)$t['enabled'] !== 1) continue;
        $on = afaa_user_type_enabled($uid, (int)$t['id']);
        $title = htmlspecialchars($t['title'] ?: $t['code']);
        $code  = htmlspecialchars($t['code']);
        $chk   = $on ? ' checked' : '';
        $rows .= '<label class="afaa-chk"><input type="checkbox" name="afaa_types['.$code.']" value="1"'.$chk.'> '.$title.'</label>';
    }
    if ($rows === '') $rows = '<em>Нет доступных типов уведомлений.</em>';

    echo '<div id="af_advancedalerts" class="afaa-ucp-box"><h3>Предпочтения уведомлений</h3><div class="afaa-chk-grid">'.$rows.'</div></div>';
}

function afaa_usercp_do_options_end()
{
    global $mybb, $db;
    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) return;

    if ((int)($mybb->settings['af_aa_allow_user_disable'] ?? 0) !== 1) return;

    verify_post_check($mybb->get_input('my_post_key'));
    $types = [];
    $q = $db->simple_select('alert_types', 'id,code,enabled');
    while ($t = $db->fetch_array($q)) $types[] = $t;

    $incoming = $mybb->get_input('afaa_types', MyBB::INPUT_ARRAY) ?? [];
    foreach ($types as $t) {
        if ((int)$t['enabled'] !== 1) continue;
        $on = isset($incoming[$t['code']]);
        afaa_set_user_type_enabled($uid, (int)$t['id'], $on);
    }
}


/* ====================== UCP: ПОЛНАЯ СТРАНИЦА ПРЕДПОЧТЕНИЙ ======================= */

function afaa_usercp_router(): void
{
    global $mybb;
    if ($mybb->get_input('action') === 'af_alert_prefs') {
        afaa_usercp_prefs_page();
        exit;
    }
}

function afaa_usercp_prefs_page(): void
{
    global $mybb, $db, $templates;

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }

    add_breadcrumb('Пользовательский раздел', 'usercp.php');
    add_breadcrumb('Предпочтения уведомлений', 'usercp.php?action=af_alert_prefs');

    $uid = (int)$mybb->user['uid'];

    if (my_strtolower($mybb->request_method) === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $uid = (int)$mybb->user['uid'];
        if ($uid <= 0) {
            error_no_permission();
        }

        $prefsSound  = !empty($mybb->input['afaa_sound']) ? 1 : 0;
        $prefsToasts = !empty($mybb->input['afaa_toasts']) ? 1 : 0;
        afaa_set_user_prefs($uid, $prefsSound, $prefsToasts);

        $typesIncoming = $mybb->get_input('afaa_types', MyBB::INPUT_ARRAY) ?? [];
        afaa_save_user_types($uid, $typesIncoming);

        redirect('usercp.php?action=af_alert_prefs', 'Настройки уведомлений сохранены.');
    }

    $prefs = afaa_get_user_prefs($uid);
    $chkSound  = $prefs['sound']  ? ' checked' : '';
    $chkToasts = $prefs['toasts'] ? ' checked' : '';

    $rows = '';
    foreach (afaa_types_for_user($uid) as $type) {
        if (!$type['can_disable']) {
            continue;
        }
        $title = htmlspecialchars($type['title']);
        $code  = htmlspecialchars($type['code']);
        $chk   = $type['user_enabled'] ? ' checked' : '';
        $rows .= '<label class="afaa-chk"><input type="checkbox" name="afaa_types['.$code.']" value="1"'.$chk.'> '.$title.'</label>';
    }
    if ($rows === '') {
        $rows = '<em>Нет доступных типов уведомлений.</em>';
    }

    $csrf = $mybb->post_code;
    $html = '<div class="afaa-page">'
          . '<h2>Предпочтения уведомлений</h2>'
          . '<form method="post" action="usercp.php?action=af_alert_prefs">'
          . '<input type="hidden" name="my_post_key" value="'.$csrf.'">'
          . '<fieldset class="afaa-ucp-box"><legend>Типы уведомлений</legend>'
          . '<div class="afaa-chk-grid">'.$rows.'</div>'
          . '</fieldset>'
          . '<fieldset class="afaa-ucp-box"><legend>Интерфейс</legend>'
          . '<label><input type="checkbox" name="afaa_sound" value="1"'.$chkSound.'> Звук при новых уведомлениях</label><br>'
          . '<label><input type="checkbox" name="afaa_toasts" value="1"'.$chkToasts.'> Показывать тост-плашки</label>'
          . '</fieldset>'
          . '<div><input type="submit" class="button" value="Применить настройки"></div>'
          . '</form>'
          . '</div>';

    global $header, $footer, $headerinclude, $theme;
    $usercp = $html;
    eval("\$page = \"".$templates->get('usercp')."\";");
    output_page($page);
}


/* ====================== MENTIONS: HELPERS + FRONT ======================= */

function afaa_mentions_enabled(): bool
{
    global $mybb;

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return false;
    }

    return !empty($mybb->settings['af_advancedmentions_enabled'])
        && (int)$mybb->settings['af_advancedmentions_enabled'] === 1;
}

/**
 * Подключение JS/CSS для Advanced Mentions
 */
function afaa_pre_output_mentions(string &$page): void
{
    global $mybb;

    if ($page === '' || !afaa_mentions_enabled()) {
        return;
    }

    $bburl = rtrim($mybb->settings['bburl'], '/');

    $js_fs  = AF_AA_PUBLIC_DIR.'advancedmentions.js';
    $css_fs = AF_AA_PUBLIC_DIR.'advancedmentions.css';

    $ver = TIME_NOW;
    if (file_exists($js_fs)) {
        $ver = max($ver, (int)@filemtime($js_fs));
    }
    if (file_exists($css_fs)) {
        $ver = max($ver, (int)@filemtime($css_fs));
    }

    $js_url  = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedalerts/advancedmentions.js?v='.$ver;
    $css_url = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedalerts/advancedmentions.css?v='.$ver;

    $suggest_url = $bburl.'/misc.php?action=af_mention_suggest';

    $click_insert = (
        !empty($mybb->settings['af_advancedmentions_click_insert']) &&
        (int)$mybb->settings['af_advancedmentions_click_insert'] === 1
    );

    $min_chars = (int)($mybb->settings['af_advancedmentions_suggest_min'] ?? 2);
    if ($min_chars < 1) {
        $min_chars = 2;
    }

    if (stripos($page, 'advancedmentions.js') !== false) {
        return;
    }

    $config = [
        'suggestUrl' => $suggest_url,
        'clickInsert' => $click_insert,
        'minChars' => $min_chars,
    ];

    $config_json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $inject  = "\n<!-- AdvancedAlerts: AdvancedMentions assets -->\n";
    $inject .= '<link rel="stylesheet" type="text/css" href="'.
        htmlspecialchars_uni($css_url).'" />'."\n";
    $inject .= "<script type=\"text/javascript\">
window.afAdvancedMentionsConfig = {$config_json};
window.afAdvancedMentionsLoaded = true;
console.log('AdvancedMentions: config loaded', window.afAdvancedMentionsConfig);
</script>\n";
    $inject .= '<script type="text/javascript" src="'.
        htmlspecialchars_uni($js_url).'"></script>'."\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $inject.'</head>', $page, 1);
    } else {
        $page = $inject.$page;
    }
}


/**
 * AJAX-подсказки @username
 * URL: misc.php?action=af_mention_suggest&query=...
 */
function afaa_misc_mention_suggest(): void
{
    global $mybb, $db;

    header('Content-Type: application/json; charset=utf-8');

    if (empty($mybb->user['uid'])) {
        echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
        exit;
    }

    if (!afaa_mentions_enabled()) {
        echo json_encode(['ok' => false, 'error' => 'mentions_disabled']);
        exit;
    }

    $query_raw = trim($mybb->get_input('query'));

    $min_chars = (int)($mybb->settings['af_advancedmentions_suggest_min'] ?? 2);
    if ($min_chars <= 0) {
        $min_chars = 2;
    }

    if ($query_raw === '' || mb_strlen($query_raw, 'UTF-8') < $min_chars) {
        echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once MYBB_ROOT.'inc/functions_user.php';
    require_once MYBB_ROOT.'inc/functions.php';

    $term_l = my_strtolower($query_raw);
    $like   = $db->escape_string_like($term_l);
    $pattern = "%{$like}%";

    $limit = 10;

    $sql = "
        SELECT uid, username, usergroup, displaygroup
        FROM ".TABLE_PREFIX."users
        WHERE LOWER(username) LIKE '".$db->escape_string($pattern)."'
        ORDER BY username
        LIMIT {$limit}
    ";

    $res = $db->query($sql);

    $results = [];
    while ($row = $db->fetch_array($res)) {
        $formatted = format_name($row['username'], (int)$row['usergroup'], (int)$row['displaygroup']);
        $results[] = [
            'uid'       => (int)$row['uid'],
            'username'  => $row['username'],
            'display'   => $row['username'],
            'formatted' => $formatted,
        ];
    }

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * postbit-хук — парсим текст и добавляем кнопку "Упомянуть"
 */
function afaa_postbit_mentions(array &$post): void
{
    global $mybb;

    if (!afaa_mentions_enabled()) {
        return;
    }

    if (!empty($post['message'])) {
        $post['message'] = afaa_parse_mentions_message((string)$post['message']);
    }

    if (!empty($mybb->user['uid']) && !empty($post['username']) && !empty($mybb->settings['af_advancedmentions_click_insert'])) {
        $username_attr = htmlspecialchars_uni($post['username']);
        $title_attr    = htmlspecialchars_uni($post['username']);

        $button_html = '<a href="javascript:void(0);"'
            .' class="af-mention-button"'
            .' data-username="'.$username_attr.'"'
            .' title="Упомянуть '.$title_attr.'">@Упомянуть</a>';

        if (isset($post['button_rep']) && $post['button_rep'] !== '') {
            $post['button_rep'] .= ' '.$button_html;
        } elseif (isset($post['button_pm']) && $post['button_pm'] !== '') {
            $post['button_pm'] .= ' '.$button_html;
        } elseif (isset($post['author_buttons'])) {
            $post['author_buttons'] .= ' '.$button_html;
        } else {
            if (empty($post['af_mention_button'])) {
                $post['af_mention_button'] = '';
            }
            $post['af_mention_button'] .= $button_html;
        }
    }
}

/**
 * Разбор текста сообщения на @-упоминания.
 *
 * Возвращает:
 * [
 *   'users'  => [uid => 'Username', ...],
 *   'groups' => [gid => true, ...],
 *   'all'    => bool
 * ]
 */
function afaa_extract_mentions(string $message): array
{
    $res = [
        'users'  => [],
        'groups' => [],
        'all'    => false,
    ];

    if ($message === '') {
        return $res;
    }

    if (preg_match('~@all\b~i', $message)) {
        $res['all'] = true;
    }

    if (preg_match_all('~@group\{(\d+)\}~i', $message, $m)) {
        foreach ($m[1] as $gid) {
            $gid = (int)$gid;
            if ($gid > 0) {
                $res['groups'][$gid] = true;
            }
        }
    }

    if (preg_match_all('~@\s*([^\r\n <>"\.,!?:;\[\]\(\)\{\}]{1,60})~u', $message, $m)) {
        global $db;

        $names = [];
        foreach ($m[1] as $name) {
            $name = trim($name);
            $name = rtrim($name, ',.!?:;)]}>»');
            if ($name !== '') {
                $names[my_strtolower($name)] = $name;
            }
        }

        if ($names) {
            $whereParts = [];
            foreach (array_keys($names) as $low) {
                $whereParts[] = "LOWER(username)='".$db->escape_string($low)."'";
            }

            if ($whereParts) {
                $q = $db->simple_select('users', 'uid,username', implode(' OR ', $whereParts));
                while ($row = $db->fetch_array($q)) {
                    $uid  = (int)$row['uid'];
                    $uname= (string)$row['username'];
                    if ($uid > 0) {
                        $res['users'][$uid] = $uname;
                    }
                }
            }
        }
    }

    if (preg_match_all('~@\"([^\"\r\n]{1,60})\"~u', $message, $m)) {
        $names = [];
        foreach ($m[1] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        if ($names) {
            global $db;
            $whereParts = [];
            foreach (array_keys($names) as $name) {
                $whereParts[] = "username='".$db->escape_string($name)."'";
            }

            if ($whereParts) {
                $q = $db->simple_select('users', 'uid,username', implode(' OR ', $whereParts));
                while ($row = $db->fetch_array($q)) {
                    $uid  = (int)$row['uid'];
                    $uname= (string)$row['username'];
                    if ($uid > 0) {
                        $res['users'][$uid] = $uname;
                    }
                }
            }
        }
    }

    return $res;
}

function afaa_handle_mentions_for_post(int $from_uid, int $tid, int $pid, int $fid, string $message): void
{
    if ($from_uid <= 0 || $tid <= 0 || $pid <= 0) {
        return;
    }
    if (!afaa_mentions_enabled()) {
        return;
    }

    global $db;

    $m = afaa_extract_mentions($message);
    if (!$m['users'] && !$m['groups'] && !$m['all']) {
        return;
    }

    $thread = $db->fetch_array(
        $db->simple_select('threads', 'subject', "tid={$tid}", ['limit'=>1])
    ) ?: [];
    $subject = trim((string)($thread['subject'] ?? 'Тема'));

    $ctxBase = [
        'from_uid'       => $from_uid,
        'pid'            => $pid,
        'tid'            => $tid,
        'thread_subject' => $subject,
        'link'           => "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}",
    ];

    foreach ($m['users'] as $uid => $uname) {
        if ($uid === $from_uid) {
            continue;
        }
        af_advancedalerts_add('mention', $uid, $ctxBase);
    }

    if ($m['groups']) {
        if (afaa_can_user_use_tag($from_uid, 'af_aa_group_mention_groups')) {
            $gids    = array_keys($m['groups']);
            $members = afaa_users_in_groups($gids);
            foreach ($members as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0 || $uid === $from_uid) {
                    continue;
                }
                af_advancedalerts_add('mention_group', $uid, $ctxBase);
            }
        }
    }

    if ($m['all'] && afaa_can_user_use_tag($from_uid, 'af_aa_mention_all_groups')) {
        $q = $db->simple_select('users', 'uid', "uid <> {$from_uid}");
        while ($row = $db->fetch_array($q)) {
            $uid = (int)$row['uid'];
            if ($uid <= 0) continue;
            af_advancedalerts_add('mention_all', $uid, $ctxBase);
        }
    }
}

function afaa_handle_quotes_for_post(int $from_uid, int $tid, int $pid, string $message): void
{
    if ($from_uid <= 0 || $tid <= 0 || $pid <= 0) {
        return;
    }

    global $db;

    if (!preg_match_all(
        "#\[quote=(?:\\\"|'|&quot;)?(?P<username>.+?)(?:\\\"|'|&quot;)? pid='(?P<pid>\d+)' dateline='(?P<dt>\d+)'[^\]]*\]#i",
        $message,
        $m
    )) {
        return;
    }

    $pids = [];
    foreach ($m['pid'] as $pidStr) {
        $p = (int)$pidStr;
        if ($p > 0) {
            $pids[$p] = true;
        }
    }
    if (!$pids) {
        return;
    }

    $q = $db->simple_select('posts', 'pid, uid', "pid IN (".implode(',', array_keys($pids)).")");
    $targets = [];
    while ($row = $db->fetch_array($q)) {
        $puid = (int)$row['uid'];
        if ($puid > 0 && $puid !== $from_uid) {
            $targets[$puid] = true;
        }
    }
    if (!$targets) {
        return;
    }

    $thread = $db->fetch_array(
        $db->simple_select('threads', 'subject', "tid={$tid}", ['limit'=>1])
    ) ?: [];
    $subject = trim((string)($thread['subject'] ?? 'Тема'));

    $ctx = [
        'from_uid'       => $from_uid,
        'pid'            => $pid,
        'tid'            => $tid,
        'thread_subject' => $subject,
        'link'           => "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}",
    ];

    foreach (array_keys($targets) as $uid) {
        af_advancedalerts_add('quoted', (int)$uid, $ctx);
    }
}

function afaa_notify_thread_subscribers(int $from_uid, int $tid, int $pid): void
{
    global $db;

    if ($from_uid <= 0 || $tid <= 0 || $pid <= 0) {
        return;
    }

    $q = $db->simple_select(
        'threadsubscriptions',
        'uid',
        "tid={$tid} AND notification > 0"
    );
    if (!$q || $db->num_rows($q) === 0) {
        return;
    }

    $thread = $db->fetch_array(
        $db->simple_select('threads', 'subject', "tid={$tid}", ['limit'=>1])
    ) ?: [];
    $subject = trim((string)($thread['subject'] ?? 'Тема'));

    $ctx = [
        'from_uid'       => $from_uid,
        'pid'            => $pid,
        'tid'            => $tid,
        'thread_subject' => $subject,
        'link'           => "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}",
    ];

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        if ($uid <= 0 || $uid === $from_uid) {
            continue;
        }
        af_advancedalerts_add('subscribed_thread', $uid, $ctx);
    }
}

function afaa_notify_forum_subscribers(int $from_uid, int $fid, int $tid, int $pid): void
{
    global $db;

    if ($from_uid <= 0 || $fid <= 0 || $tid <= 0) {
        return;
    }

    $q = $db->simple_select(
        'forumsubscriptions',
        'uid',
        "fid={$fid}"
    );
    if (!$q || $db->num_rows($q) === 0) {
        return;
    }

    $thread = $db->fetch_array(
        $db->simple_select('threads', 'subject', "tid={$tid}", ['limit'=>1])
    ) ?: [];
    $subject = trim((string)($thread['subject'] ?? 'Тема'));

    $link = $pid > 0
        ? "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}"
        : "showthread.php?tid={$tid}";

    $ctx = [
        'from_uid'       => $from_uid,
        'pid'            => $pid,
        'tid'            => $tid,
        'thread_subject' => $subject,
        'link'           => $link,
    ];

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        if ($uid <= 0 || $uid === $from_uid) {
            continue;
        }
        af_advancedalerts_add('subscribed_forum', $uid, $ctx);
    }
}

/**
 * Парсинг текста поста — превращаем @"Имя" и @username в ссылки на профиль.
 * На выходе формат: <a ...>@Имя Фамилия</a> (без кавычек).
 */
function afaa_parse_mentions_message(string $message): string
{
    global $db;

    if ($message === '' || strpos($message, '@') === false) {
        return $message;
    }

    $emailRegex = "#\\b[^@[\"|'|`][A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,4}\\b#i";
    preg_match_all($emailRegex, $message, $emails, PREG_SET_ORDER);
    $message = preg_replace($emailRegex, "<af-email>\n", $message);

    $names = [];

    if (preg_match_all('~@"([^"\r\n]{1,60})"~u', $message, $m1)) {
        foreach ($m1[1] as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }
    }

    if (preg_match_all('~@([^\s\[\]<>{}]{1,60})~u', $message, $m2)) {
        foreach ($m2[1] as $raw) {
            $name = trim($raw, " \t,:;!?()<>\"'");
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }
    }

    $groupIds = [];
    if (preg_match_all('~@group\{(\d+)\}~i', $message, $gm)) {
        foreach ($gm[1] as $gidRaw) {
            $gid = (int)$gidRaw;
            if ($gid > 0) {
                $groupIds[$gid] = true;
            }
        }
    }

    if (empty($names) && empty($groupIds)) {
        foreach ($emails as $email) {
            $message = preg_replace("#\<af-email>\n?#", $email[0], $message, 1);
        }
        return $message;
    }

    $lowers = [];
    foreach ($names as $n) {
        $low = my_strtolower($n);
        if ($low !== '') {
            $lowers[$low] = true;
        }
    }

    require_once MYBB_ROOT.'inc/functions.php';

    $mapUsers = [];
    if (!empty($lowers)) {
        $in = [];
        foreach (array_keys($lowers) as $low) {
            $in[] = "'".$db->escape_string($low)."'";
        }

        if ($in) {
            $sql = "
                SELECT uid, username
                FROM ".TABLE_PREFIX."users
                WHERE LOWER(username) IN (".implode(',', $in).")
            ";
            $res = $db->query($sql);
            while ($row = $db->fetch_array($res)) {
                $key = my_strtolower($row['username']);
                $mapUsers[$key] = [
                    'uid'      => (int)$row['uid'],
                    'username' => $row['username'],
                ];
            }
        }
    }

    if (!empty($mapUsers)) {
        foreach ($mapUsers as $low => $u) {
            $uname = $u['username'];
            $uid   = (int)$u['uid'];

            $pattern1 = '~@"\s*'.preg_quote($uname, '~').'\s*"~iu';
            $message = preg_replace_callback($pattern1, function($m) use ($uid, $uname) {
                $text = '@'.htmlspecialchars_uni($uname);
                return build_profile_link($text, $uid);
            }, $message);

            $pattern2 = '~@\s*'.preg_quote($uname, '~').'\b~iu';
            $message = preg_replace_callback($pattern2, function($m) use ($uid, $uname) {
                $text = '@'.htmlspecialchars_uni($uname);
                return build_profile_link($text, $uid);
            }, $message);
        }
    }

    if (!empty($groupIds)) {
        $gidList = implode(',', array_map('intval', array_keys($groupIds)));

        $groupTitles = [];
        $gq = $db->simple_select('usergroups', 'gid, title', "gid IN ({$gidList})");
        while ($g = $db->fetch_array($gq)) {
            $groupTitles[(int)$g['gid']] = (string)$g['title'];
        }

        foreach ($groupTitles as $gid => $title) {
            $label    = '@group: '.htmlspecialchars_uni($title);
            $patternG = '~@group\{'.preg_quote((string)$gid, '~').'\}~i';
            $message  = preg_replace($patternG, '<span class="afaa-group-mention">'.$label.'</span>', $message);
        }
    }

    foreach ($emails as $email) {
        $message = preg_replace("#\<af-email>\n?#", $email[0], $message, 1);
    }

    return $message;
}


/* ====================== TRIGGERS ======================= */

function afaa_post_insert_end(&$posthandler): void
{
    global $mybb;

    if (!is_object($posthandler)) {
        return;
    }

    $data   = $posthandler->data ?? [];
    $method = property_exists($posthandler, 'method') ? $posthandler->method : '';

    $from_uid = (int)($data['uid'] ?? ($mybb->user['uid'] ?? 0));
    $tid      = (int)($posthandler->tid ?? $data['tid'] ?? 0);
    $pid      = (int)($posthandler->pid ?? 0);
    $fid      = (int)($data['fid'] ?? 0);
    $message  = (string)($data['message'] ?? ($posthandler->post_insert_data['message'] ?? ''));

    if ($from_uid <= 0 || $tid <= 0 || $pid <= 0) {
        return;
    }

    afaa_handle_mentions_for_post($from_uid, $tid, $pid, $fid, $message);
    afaa_handle_quotes_for_post($from_uid, $tid, $pid, $message);

    if (in_array($method, ['insert_post', 'insertPost'], true)) {
        afaa_notify_thread_subscribers($from_uid, $tid, $pid);
    } elseif (in_array($method, ['insert_thread', 'insertThread'], true)) {
        afaa_notify_forum_subscribers($from_uid, $fid, $tid, $pid);
    }
}

function afaa_rep_do_add_end(): void
{
    global $mybb, $db;

    $from_uid = (int)($mybb->user['uid'] ?? 0);
    if ($from_uid <= 0) {
        return;
    }

    $target_uid = (int)$mybb->get_input('uid', MyBB::INPUT_INT);
    if ($target_uid <= 0 || $target_uid === $from_uid) {
        return;
    }

    $cut = TIME_NOW - 10;
    $q = $db->simple_select(
        'reputation',
        'rid, uid, adduid, reputation, comments, dateline',
        "uid={$target_uid} AND adduid={$from_uid} AND dateline>={$cut}",
        ['order_by' => 'dateline', 'order_dir' => 'DESC', 'limit' => 1]
    );
    $rep = $db->fetch_array($q);
    if (!$rep) {
        return;
    }

    $ctx = [
        'from_uid'      => (int)$rep['adduid'],
        'points'        => (int)$rep['reputation'],
        'reason'        => (string)$rep['comments'],
        'object_id'     => (int)$rep['rid'],
        'thread_subject'=> '',
        'link'          => "reputation.php?uid={$target_uid}",
    ];

    af_advancedalerts_add('rep', $target_uid, $ctx);
}

function afaa_pm_insert_end(&$pmhandler): void
{
    global $db, $mybb;

    $from = (int)($mybb->user['uid'] ?? 0);
    if ($from <= 0) {
        return;
    }

    $data = $pmhandler->pm_insert_data ?? $pmhandler->data ?? [];
    $targets = [];

    if (!empty($data['recipients']) && is_array($data['recipients'])) {
        foreach (['to', 'bcc'] as $key) {
            if (!empty($data['recipients'][$key]) && is_array($data['recipients'][$key])) {
                foreach ($data['recipients'][$key] as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0 && $uid !== $from) {
                        $targets[$uid] = true;
                    }
                }
            }
        }
    }

    $pmids = [];

    if (!empty($pmhandler->pmid)) {
        if (is_array($pmhandler->pmid)) {
            foreach ($pmhandler->pmid as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $pmids[$id] = true;
                }
            }
        } else {
            $id = (int)$pmhandler->pmid;
            if ($id > 0) {
                $pmids[$id] = true;
            }
        }
    }

    if ($pmids) {
        $idList = implode(',', array_keys($pmids));
        $q = $db->simple_select('privatemessages', 'toid', "pmid IN ({$idList})");
        while ($row = $db->fetch_array($q)) {
            $uid = (int)$row['toid'];
            if ($uid > 0 && $uid !== $from) {
                $targets[$uid] = true;
            }
        }
    }

    $targets = array_keys($targets);
    if (!$targets) {
        return;
    }

    $subject = '';
    if (!empty($data['subject'])) {
        $subject = (string)$data['subject'];
    } elseif ($pmids) {
        $idList = implode(',', array_keys($pmids));
        $q2 = $db->simple_select('privatemessages', 'subject', "pmid IN ({$idList})", ['limit' => 1]);
        $subject = (string)$db->fetch_field($q2, 'subject');
    }

    $from_name = (string)($mybb->user['username'] ?? '');

    $pmidForLink = $pmids ? max(array_keys($pmids)) : 0;
    $link        = $pmidForLink > 0
        ? "private.php?action=read&pmid={$pmidForLink}"
        : "private.php";

    foreach ($targets as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0 || $uid === $from) {
            continue;
        }

        afaa_send(
            $uid,
            'pm',
            [
                'subject'       => $subject,
                'from_username' => $from_name,
                'thread_subject'=> '',
                'link'          => $link,
            ],
            $from,
            $pmidForLink ?: null
        );
    }
}

function afaa_editlists_end(): void
{
    global $mybb, $db;

    $from_uid = (int)$mybb->user['uid'];
    if ($from_uid <= 0) {
        return;
    }

    require_once MYBB_ROOT.'inc/functions_user.php';

    $username = trim($mybb->get_input('add_username'));
    if ($username !== '') {
        $user = get_user_by_username($username, ['fields' => 'uid,username']);
        if ($user && (int)$user['uid'] > 0 && (int)$user['uid'] !== $from_uid) {
            $target_uid = (int)$user['uid'];

            $cut = TIME_NOW - 10;
            $q = $db->simple_select(
                'buddyrequests',
                'id',
                "uid={$target_uid} AND fromuid={$from_uid} AND dateline>={$cut}",
                ['limit' => 1]
            );
            if ($db->num_rows($q)) {
                $ctx = [
                    'from_uid' => $from_uid,
                    'link'     => 'usercp.php?action=editlists',
                ];
                af_advancedalerts_add('buddylist', $target_uid, $ctx);
            }
        }
    }

    $acceptIds = $mybb->get_input('accept', MyBB::INPUT_ARRAY);
    if (is_array($acceptIds) && $acceptIds) {
        $ids = array_filter(array_map('intval', array_keys($acceptIds)));
        if ($ids) {
            $in = implode(',', $ids);
            $q = $db->simple_select(
                'buddyrequests',
                'id, uid, fromuid',
                "id IN ({$in})"
            );
            while ($row = $db->fetch_array($q)) {
                $sender = (int)$row['fromuid'];
                $target = (int)$row['uid'];

                if ($sender > 0 && $target === $from_uid) {
                    $ctx = [
                        'from_uid' => $from_uid,
                        'link'     => 'usercp.php?action=editlists',
                    ];
                    af_advancedalerts_add('buddy_accept', $sender, $ctx);
                }
            }
        }
    }
}


// Гарантируем регистрацию хуков
if (defined('IN_MYBB')) {
    af_advancedalerts_init();
}
