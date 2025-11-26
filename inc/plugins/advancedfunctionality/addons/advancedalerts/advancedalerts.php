<?php
/**
 * Advanced Alerts — внутренний аддон AF
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * - Типы уведомлений (вкл/выкл глобально + пользовательские чекбоксы в UCP).
 * - Триггеры: ответы/цитаты/упоминания, ЛС, друзья.
 * - /misc.php?action=af_alerts, AJAX API, поп-ап, звук, тосты.
 * - Вставки в UCP выполняются правкой шаблонов при activate()/deactivate().
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AA_ID', 'advancedalerts');
define('AF_AA_BASE', AF_ADDONS . AF_AA_ID . '/');
// Публичные файлы (af_alerts.js/css/mp3) теперь лежат прямо в корне аддона
define('AF_AA_PUBLIC_DIR', AF_AA_BASE);
define('AF_AA_RETENTION_DAYS', 7);


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
        // Добавим временную INT-колонку, сконвертируем значения и переименуем
        $tbl = TABLE_PREFIX.'alerts';
        // 1) добавляем временную колонку
        $db->write_query("ALTER TABLE `{$tbl}` ADD COLUMN `dateline_int` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `object_id`", true);
        // 2) конвертируем существующие значения
        $db->write_query("UPDATE `{$tbl}` SET `dateline_int` = UNIX_TIMESTAMP(`dateline`)", true);
        // 3) удаляем старую колонку и переименовываем временную
        $db->write_query("ALTER TABLE `{$tbl}` DROP COLUMN `dateline`", true);
        $db->write_query("ALTER TABLE `{$tbl}` CHANGE COLUMN `dateline_int` `dateline` INT UNSIGNED NOT NULL", true);
        // 4) убедимся в ключах
        $db->write_query("ALTER TABLE `{$tbl}` ADD KEY `uid_read` (`uid`,`is_read`,`dateline`)", true);
        $db->write_query("ALTER TABLE `{$tbl}` ADD KEY `type_id` (`type_id`)", true);
    }

    // На всякий случай добавим отсутствующую колонку is_read
    $have_is_read = false;
    $colq = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX."alerts` LIKE 'is_read'", true);
    if ($colq && $db->num_rows($colq) > 0) $have_is_read = true;
    if (!$have_is_read) {
        $db->write_query("ALTER TABLE `".TABLE_PREFIX."alerts` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `dateline`", true);
        $db->write_query("ALTER TABLE `".TABLE_PREFIX."alerts` ADD KEY `uid_read` (`uid`,`is_read`,`dateline`)", true);
    }
}



/* ===== Активация/деактивация: правки шаблонов под UCP ===== */

function af_advancedalerts_activate()
{
    // вставка в usercp_nav_home и usercp_nav_profile
    afaa_tpl_insert_links();
}

function af_advancedalerts_deactivate()
{
    // убрать вставки
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
    if (!$sid) $db->insert_query('settings', $seed);
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

    // Если последний URL — наш AJAX эндпоинт, подменяем дружелюбное описание
    if (strpos($loc, 'misc.php') !== false && strpos($loc, 'action=af_alerts_api') !== false) {
        // Стандартная схема плагина Who's Online:
        //  - location_name — текст
        //  - location_url  — ссылка
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
        // format_avatar возвращает массив [image, width, height]
        $max = '64x64';
        $av = format_avatar($user['avatar'] ?? '', $user['avatardimensions'] ?? '', $max);
        return (string)($av['image'] ?? (is_array($av) ? reset($av) : ''));
    }
}

function afaa_user_in_allowed_groups(int $uid): bool
{
    // Гости (uid <= 0) никогда не получают уведомления.
    // Все зарегистрированные пользователи (uid > 0) — всегда получают.
    return $uid > 0;
}


/* === Регистрация типов: поддержка массива и строковых аргументов === */
function afaa_register_default_types(): void
{
    // Канонический набор типов (только реально используемые)
    $defaults = [
        'rep'              => 'Изменение репутации',
        'pm'               => 'Новое личное сообщение',
        'buddylist'        => 'Заявка в друзья',
        'buddy_accept'     => 'Принятие заявки в друзья',
        'quoted'           => 'Вас процитировали',
        'subscribed_thread'=> 'Ответ в подписанной теме',
        'subscribed_forum' => 'Новая тема в подписанном форуме',
        'mention'          => 'Упоминание пользователя',
        'group_mention'    => 'Упоминание группы или @all',
    ];

    foreach ($defaults as $code => $title) {
        afaa_register_type($code, $title, true, true);
    }
}




function afaa_register_type($code, $title=null, $enabled=true, $user_can_disable=true): int
{
    // Вариант из admin.php: массив с ключами
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

// Совместимость с аддонами: обёртка, которую ждёт AdvancedMentions
if (!function_exists('af_advancedalerts_register_type')) {
    /**
     * Совместимая регистрация типа уведомления для внутренних аддонов AF.
     *
     * @param string $code
     * @param array  $opts ['title' => ..., 'enabled' => bool, 'can_be_user_disabled' => bool]
     */
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
// Универсальный хелпер для добавления уведомлений из внутренних аддонов (AdvancedMentions и т.п.).
// Используется, например, af_advancedmentions_notify_users().
if (!function_exists('af_advancedalerts_add')) {
    /**
     * Добавить уведомление заданного типа для пользователя.
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

        // Отправитель (если есть)
        $from_uid = (int)($ctx['from_uid'] ?? $ctx['from_user_id'] ?? 0);

        $extra     = [];
        $object_id = null;

        switch ($type_code) {

            // === Упоминание пользователя в теме ===
            case 'mention':
                $pid = (int)($ctx['pid'] ?? 0);
                $tid = (int)($ctx['tid'] ?? 0);

                // Если пришёл только pid — добираем tid из поста
                if ($tid <= 0 && $pid > 0) {
                    $post = $db->fetch_array(
                        $db->simple_select('posts', 'tid', "pid={$pid}", ['limit' => 1])
                    ) ?: [];
                    $tid = (int)($post['tid'] ?? 0);
                }

                // Если пришёл только tid — попробуем найти первый пост для ссылки
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
                    // Вообще не к чему привязаться
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

                // Имя отправителя
                $from_username = '';
                if ($from_uid > 0) {
                    require_once MYBB_ROOT.'inc/functions_user.php';
                    $from_user = get_user($from_uid);
                    if (!empty($from_user['username'])) {
                        $from_username = (string)$from_user['username'];
                    }
                }

                // Если вдруг не достали юзернейм из get_user, попробуем взять текущего
                if ($from_username === '' && !empty($mybb->user['uid']) && (int)$mybb->user['uid'] === $from_uid) {
                    $from_username = (string)($mybb->user['username'] ?? '');
                }

                $extra = [
                    'thread_subject' => $subject,
                    'from_username'  => $from_username,
                    'link'           => $link,
                ];

                // Привязываем object_id к pid (если есть), иначе к tid
                $object_id = $pid > 0 ? $pid : $tid;
                break;

            // === Любые будущие кастомные типы (awards, gifts и т.п.) ===
            default:
                // Тут ничего "умного" не делаем: просто пробрасываем то, что нам дали.
                // Минимальный контракт:
                //  - thread_subject / subject — заголовок/описание
                //  - from_username           — имя отправителя (если есть)
                //  - link                    — ссылка на объект
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

// Хелпер для удаления типа уведомления (используется при uninstall аддонов)
if (!function_exists('af_advancedalerts_unregister_type')) {
    /**
     * Удалить тип уведомления и связанные с ним пользовательские настройки.
     */
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

        // Удаляем сами настройки и тип, историю alert'ов не трогаем
        $db->delete_query('alert_settings', "type_id={$tid}");
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

/**
 * Массовое сохранение пользовательских настроек типов уведомлений.
 */
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

    // 0) UID должен быть живой
    if ($uid <= 0) {
        return false;
    }

    // 0.1) Проверка групп: гости и запрещённые группы не получают алерты
    if (!afaa_user_in_allowed_groups($uid)) {
        return false;
    }

    $type_code = trim($type_code);
    if ($type_code === '') {
        return false;
    }

    // 1) Находим или создаём тип уведомления
    $row = $db->fetch_array(
        $db->simple_select(
            'alert_types',
            'id, code, title, enabled, can_be_user_disabled',
            "code='".$db->escape_string($type_code)."'", 
            ['limit' => 1]
        )
    );

    if (!$row || (int)$row['id'] <= 0) {
        // Если тип ещё не зарегистрирован — сначала регистрируем дефолтные
        afaa_register_default_types();

        $row = $db->fetch_array(
            $db->simple_select(
                'alert_types',
                'id, code, title, enabled, can_be_user_disabled',
                "code='".$db->escape_string($type_code)."'", 
                ['limit' => 1]
            )
        );

        // Всё ещё нет — создаём минимальный тип с кодом = заголовок
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

    // 2) Глобально выключенный тип не шлём никому
    if (!$type_enabled_global) {
        return false;
    }

    // 3) Если тип можно отключать на уровне пользователя — проверяем его настройки
    if ($type_user_can_off) {
        if (!afaa_user_type_enabled($uid, $type_id)) {
            return false;
        }
    }

    // 4) Собираем строку для вставки
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

    // 5) Анти-дубликат: не спамим одинаковыми алертами каждые 2 минуты
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
    // $r: из JOIN alerts + alert_types + decoded extra
    $type = (string)($r['type_code'] ?? '');
    $ex   = is_array($r['extra']) ? $r['extra'] : [];

    // Алиасы старых кодов к новому канону
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
            // Старый тип "ответ в вашей теме" считаем эквивалентом подписки на тему
            $type = 'subscribed_thread';
            break;
        case 'rated_threadauthor':
        case 'voted_threadauthor':
            // Эти типы больше не используются — пускаем через универсальный формат
            break;
        case 'gift':
            // Подарков нет — тоже уйдёт в универсальный формат
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

    // === rep: изменение репутации ===
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

    // === pm ===
    if ($type === 'pm') {
        $txt = 'Новое личное сообщение от "'.$from_link.'"'
             . ($title_html ? ' с заголовком "'.$title_html.'"' : '');
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    // === mention (прямое @) ===
    if ($type === 'mention') {
        $txt = '"'.$from_link.'" упомянул вас в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    // === group_mention (упоминание по @group{ID} или @all) ===
    if ($type === 'group_mention') {
        $txt = '"'.$from_link.'" упомянул группу, в которую вы входите, в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    // === subscribed_thread ===
    if ($type === 'subscribed_thread') {
        $txt = '"'.$from_link.'" написал в теме "'.$thread_title_html.'", на которую вы подписаны';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    // === subscribed_forum ===
    if ($type === 'subscribed_forum') {
        $txt = '"'.$from_link.'" создал новую тему "'.$thread_title_html.'" в форуме, на который вы подписаны';
        return ['text_html' => $txt, 'link' => $msg_link, 'avatar' => $from_a];
    }

    // === quoted ===
    if ($type === 'quoted') {
        $txt = '"'.$from_link.'" процитировал вас в теме "'.$thread_title_html.'"';
        return ['text_html'=>$txt, 'link'=>$msg_link, 'avatar'=>$from_a];
    }

    // === buddylist === (заявка в друзья)
    if ($type === 'buddylist') {
        $txt = '"'.$from_link.'" отправил вам запрос в друзья';
        return [
            'text_html'=>$txt,
            'link'     =>$msg_link ?: 'usercp.php?action=editlists',
            'avatar'   =>$from_a
        ];
    }

    // === buddy_accept === (приняли вашу заявку)
    if ($type === 'buddy_accept') {
        $txt = '"'.$from_link.'" принял вашу заявку в друзья';
        return [
            'text_html'=>$txt,
            'link'     =>$msg_link ?: 'usercp.php?action=editlists',
            'avatar'   =>$from_a
        ];
    }

    // === УНИВЕРСАЛЬНЫЙ ФОРМАТ ДЛЯ КАСТОМНЫХ ТИПОВ ===
    //
    // Логика:
    //  - если в extra_data передали title/subject — это основной текст;
    //  - если ещё есть from_username — показываем `"отправитель" — текст`;
    //  - если нет title — используем title типа или код.
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

/* ===== Helpers: правка шаблонов с маркерами (как в headerinclude) ===== */

function afaa_tpl_insert_links(): void
{
    global $db;
    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    // helper: массовое обновление по title
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

    // usercp_nav_home — ссылка на список уведомлений
    $update_all_by_title('usercp_nav_home', function($t){
        $beg='<!--AF_ADVANCEDALERTS_NAV_HOME_START-->'; $end='<!--AF_ADVANCEDALERTS_NAV_HOME_END-->';
        if (strpos($t, $beg)!==false) return $t;
        $block = $beg."\n".'<td class="trow1 smalltext"><a href="misc.php?action=af_alerts" class="usercp_nav_item">Мои уведомления</a></td>'."\n".$end;
        return $t."\n".$block;
    });

    // usercp_nav_profile — ВЕДЁМ СРАЗУ НА /usercp.php?action=af_alert_prefs
    $update_all_by_title('usercp_nav_profile', function($t){
        $beg='<!--AF_ADVANCEDALERTS_NAV_PROFILE_START-->'; $end='<!--AF_ADVANCEDALERTS_NAV_PROFILE_END-->';
        if (strpos($t, $beg)!==false) return $t;
        $block = $beg."\n".'<td class="trow1 smalltext"><a href="usercp.php?action=af_alert_prefs" class="usercp_nav_item">Настройки уведомлений</a></td>'."\n".$end;
        return $t."\n".$block;
    });

    // usercp — маленький блок ссылок под профилем
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
        // Основная группа
        $condParts[] = "usergroup={$gid}";
        // Дополнительные группы
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

/**
 * Унифицированный сбор адресатов упоминаний (обычные, группы, @all).
 * Возвращает массив uid по типам: ['mention' => [...], 'group_mention' => [...]].
 */
function afaa_collect_mentions(string $message, int $author_uid): array
{
    global $db, $mybb;

    // Нормализуем текст, чтобы ловить NBSP и двойные пробелы
    $message = str_replace("\xc2\xa0", ' ', $message);
    $message = preg_replace('~[ \t]+~u', ' ', $message);

    $mentionUsernames = [];
    $mentionAll       = false;
    $mentionGroups    = [];

    if (preg_match_all('~@([^\r\n@]{2,80})~u', $message, $mAll)) {
        foreach ($mAll[1] as $raw) {
            $tok = (string)$raw;
            $tok = str_replace("\xc2\xa0", ' ', $tok);
            $tok = preg_replace('~\s+~u', ' ', $tok);

            $tokParts = preg_split('~(\[|/quote)~ui', $tok, 2);
            $tok = $tokParts[0] ?? $tok;
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }

            if (preg_match('~^["«](.+?)["»]$~u', $tok, $mmTok)) {
                $tok = trim($mmTok[1]);
            }

            if ($tok === '') {
                continue;
            }

            $low = my_strtolower($tok);

            if ($low === 'all') {
                $mentionAll = true;
                continue;
            }

            if (preg_match('~^group\{(\d+)\}$~', $low, $gm)) {
                $gid = (int)$gm[1];
                if ($gid > 0) {
                    $mentionGroups[$gid] = true;
                }
                continue;
            }

            $mentionUsernames[$low] = true;
        }
    }

    // Разрешения на @all: только группы 3,4,6 (модеры/админы)
    $canUseAll = false;
    if ($mentionAll) {
        require_once MYBB_ROOT.'inc/functions_user.php';
        if (function_exists('is_member')) {
            $canUseAll = is_member([3,4,6], $author_uid);
        } else {
            $ug  = (int)($mybb->user['usergroup'] ?? 0);
            $ags = (string)($mybb->user['additionalgroups'] ?? '');
            $allowed = [3,4,6];
            if (in_array($ug, $allowed, true)) {
                $canUseAll = true;
            } else {
                $extra = array_filter(array_map('intval', explode(',', $ags)));
                if (array_intersect($extra, $allowed)) {
                    $canUseAll = true;
                }
            }
        }

        if (!$canUseAll) {
            $mentionAll = false;
        }
    }

    $mentionUidsDirect = [];
    $mentionUidsGroup  = [];

    if ($mentionUsernames) {
        $in = [];
        foreach (array_keys($mentionUsernames) as $n) {
            $in[] = "'".$db->escape_string($n)."'";
        }
        if ($in) {
            $rq = $db->write_query(
                "SELECT uid, username
                 FROM ".TABLE_PREFIX."users
                 WHERE LOWER(username) IN (".implode(',', $in).")"
            );
            while ($u = $db->fetch_array($rq)) {
                $uid = (int)$u['uid'];
                if ($uid > 0 && $uid !== $author_uid) {
                    $mentionUidsDirect[$uid] = true;
                }
            }
        }
    }

    if ($mentionAll && $canUseAll) {
        $aq = $db->simple_select(
            'users',
            'uid',
            "uid<>".$author_uid." AND uid>0"
        );
        while ($u = $db->fetch_array($aq)) {
            $uid = (int)$u['uid'];
            if ($uid > 0 && $uid !== $author_uid) {
                $mentionUidsDirect[$uid] = true;
            }
        }
    }

    if ($mentionGroups) {
        $gids      = array_keys($mentionGroups);
        $groupUids = afaa_users_in_groups($gids);
        foreach ($groupUids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0 || $uid === $author_uid) {
                continue;
            }
            if (!empty($mentionUidsDirect[$uid])) {
                continue;
            }
            $mentionUidsGroup[$uid] = true;
        }
    }

    return [
        'mention'       => array_keys($mentionUidsDirect),
        'group_mention' => array_keys($mentionUidsGroup),
    ];
}

/**
 * Универсальный отправитель уведомлений об упоминаниях.
 * Используется и Advanced Alerts, и Advanced Mentions, чтобы не было расхождений/дубликатов.
 */
function afaa_notify_mentions_from_message(
    string $message,
    int $author_uid,
    int $pid = 0,
    int $tid = 0,
    ?string $thread_subject = null,
    ?string $from_username = null,
    ?string $link = null
): void {
    global $db, $mybb;

    // Определяем ключ, чтобы повторно не отправлять для одного и того же поста/темы
    $mentionKey = $pid > 0 ? 'pid:'.$pid : ($tid > 0 ? 'tid:'.$tid : null);
    if ($mentionKey && !empty($GLOBALS['afaa_mentions_sent'][$mentionKey])) {
        return;
    }

    // Восстанавливаем tid/pid/subject/link, если не передали
    if ($tid <= 0 && $pid > 0) {
        $post = $db->fetch_array(
            $db->simple_select('posts', 'tid', "pid={$pid}", ['limit' => 1])
        ) ?: [];
        $tid = (int)($post['tid'] ?? 0);
    }

    if ($thread_subject === null || $thread_subject === '') {
        if ($tid > 0) {
            $thr = $db->fetch_array(
                $db->simple_select('threads', 'subject', "tid={$tid}", ['limit' => 1])
            ) ?: [];
            $thread_subject = trim((string)($thr['subject'] ?? 'Тема'));
        } else {
            $thread_subject = 'Тема';
        }
    }

    if ($pid <= 0 && $tid > 0) {
        $post = $db->fetch_array(
            $db->simple_select('posts', 'pid', "tid={$tid}", ['order_by' => 'dateline', 'order_dir' => 'ASC', 'limit' => 1])
        ) ?: [];
        $pid = (int)($post['pid'] ?? 0);
    }

    if ($link === null || $link === '') {
        $link = $pid > 0
            ? "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}"
            : ($tid > 0 ? "showthread.php?tid={$tid}" : '');
    }

    if ($from_username === null) {
        $from_username = '';
        if ($author_uid > 0) {
            require_once MYBB_ROOT.'inc/functions_user.php';
            $u = get_user($author_uid);
            if (!empty($u['username'])) {
                $from_username = (string)$u['username'];
            }
        }
        if ($from_username === '' && !empty($mybb->user['uid']) && (int)$mybb->user['uid'] === $author_uid) {
            $from_username = (string)($mybb->user['username'] ?? '');
        }
    }

    $buckets = afaa_collect_mentions($message, $author_uid);

    foreach ($buckets['mention'] as $uid) {
        afaa_send(
            (int)$uid,
            'mention',
            [
                'thread_subject' => $thread_subject,
                'from_username'  => $from_username,
                'link'           => $link,
            ],
            $author_uid,
            $pid ?: $tid ?: null
        );
    }

    foreach ($buckets['group_mention'] as $uid) {
        afaa_send(
            (int)$uid,
            'group_mention',
            [
                'thread_subject' => $thread_subject,
                'from_username'  => $from_username,
                'link'           => $link,
            ],
            $author_uid,
            $pid ?: $tid ?: null
        );
    }

    if ($mentionKey !== null) {
        $GLOBALS['afaa_mentions_sent'][$mentionKey] = true;
    }
}



/* ====================== HOOKS REG ======================= */

function af_advancedalerts_init(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    global $mybb, $plugins;

    // Если настроек ещё нет — создаём таблицы/настройки и пересобираем.
    if (!isset($mybb->settings['af_advancedalerts_enabled'])) {
        af_advancedalerts_install();
        rebuild_settings();
    }

    // Глобальный выключатель аддона
    if ((int)($mybb->settings['af_advancedalerts_enabled'] ?? 0) !== 1) {
        return;
    }

    // Сервисные вещи: чистка старых, схема, дефолтные типы
    afaa_prune_old(AF_AA_RETENTION_DAYS);
    afaa_register_default_types();
    afaa_maybe_migrate_schema();

    // === Фронт: инъекции и роутер страниц/JSON ===
    $plugins->add_hook('global_start',    'afaa_global_start');
    $plugins->add_hook('pre_output_page', 'afaa_pre_output');
    $plugins->add_hook('misc_start',      'afaa_misc_router');
    $plugins->add_hook('usercp_start',    'afaa_usercp_router');
    // Who's Online: не светим сырую ссылку на af_alerts_api
    $plugins->add_hook('build_friendly_wol_location_end', 'afaa_wol_location');


    // === UCP: рендер блока и сохранение опций ===
    $plugins->add_hook('usercp_end',            'afaa_usercp_end');
    $plugins->add_hook('usercp_do_options_end', 'afaa_usercp_do_options_end');

    // === Триггеры данных ===

    // Посты: подписки, цитаты, упоминания, подписка на форум
    // Правильный хук: вызывается при вставке поста (и для новых тем, и для ответов)
    $plugins->add_hook('datahandler_post_insert_post', 'afaa_post_insert_end');
    $plugins->add_hook('datahandler_post_insert_thread', 'afaa_post_insert_end');

    // Личные сообщения: новое ЛС
    // Правильный хук: вызывается после успешного коммита ЛС
    $plugins->add_hook('datahandler_pm_insert_commit', 'afaa_pm_insert_end');

    // Друзья (этот был правильный, его оставляем)
    $plugins->add_hook('usercp_do_editlists_end', 'afaa_editlists_end');

    $plugins->add_hook('reputation_do_add_end', 'afaa_rep_do_add_end');


}


function afaa_pm_after_send(): void
{
// больше не используется
}

function afaa_newreply_after(): void
{
// больше не используется
}



/* ====================== FRONT INJECTION ======================= */

function afaa_global_start(): void
{
    global $mybb, $db;

    // НЕ трогать API-эндпоинт — он должен отдать чистый JSON
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'misc.php' && $mybb->get_input('action') === 'af_alerts_api') {
        return;
    }

    // 1) Страховка: если по какой-то причине init не успели вызвать — дернём здесь.
    if (!function_exists('af_advancedalerts_init_called')) {
        // метка, чтобы не зациклиться: init сам по себе идемпотентный
        af_advancedalerts_init();
        function af_advancedalerts_init_called() { return true; }
    }

    // 2) Страховка CSRF для AJAX: прокинем post_key в окно как можно раньше.
    if (!isset($GLOBALS['afaa_post_key_js'])) {
        $pk = addslashes($mybb->post_code ?? '');
        // Впишем маленький инлайновый скрипт в headerinclude (если он уже собран)
        if (isset($GLOBALS['headerinclude'])) {
            $inject = "<script>window.my_post_key=window.my_post_key||'{$pk}';</script>";
            if (strpos($GLOBALS['headerinclude'], 'window.my_post_key') === false) {
                $GLOBALS['headerinclude'] .= "\n{$inject}\n";
            }
        } else {
            // Если headerinclude позже — pre_output всё равно повторно вольёт ключ.
        }
        $GLOBALS['afaa_post_key_js'] = true;
    }

    // 3) Лёгкая проверка таблиц/типов (без шума в лог):
    // Если таблиц нет (свежая установка или сброс) — создадим и зарегистрируем дефолтные типы.
    static $schema_ok = null;
    if ($schema_ok === null) {
        $schema_ok = true;
        if (!$db->table_exists('alert_types') || !$db->table_exists('alerts')) {
            af_advancedalerts_install();
            rebuild_settings();
            $schema_ok = false; // только что создали — на этом заходе не трогаем остальное
        }
        if ($schema_ok) {
            // Убедимся, что дефолтные типы реально есть (уже по новому канону)
            $need = [
                'rep',
                'pm',
                'buddylist',
                'buddy_accept',
                'quoted',
                'subscribed_thread',
                'subscribed_forum',
                'mention',
                'group_mention',
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

    // DEBUG smoke insert — отключено в проде
    /*
    if ((int)$mybb->user['uid'] > 0 && empty($GLOBALS['afaa_smoke_once']) && is_member('4')) { // group 4 = Admins
        $GLOBALS['afaa_smoke_once'] = 1;
        afaa_send((int)$mybb->user['uid'], 'pm', ['title'=>'Проверка Advanced Alerts', 'link'=>'index.php'], 1, null);
    }
    */


}

function afaa_pre_output(string &$page): void
{
    global $mybb;

    // API не трогаем
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'misc.php' && $mybb->get_input('action') === 'af_alerts_api') {
        return;
    }

    $baseurl = rtrim($mybb->settings['bburl'], '/');
    $limit   = (int)($mybb->settings['af_aa_dropdown_limit'] ?? 10);
    $poll    = max(5, (int)($mybb->settings['af_aa_poll_seconds'] ?? 20));
    $toast   = max(1, (int)($mybb->settings['af_aa_toast_limit'] ?? 5));
    $me      = (int)($mybb->user['uid'] ?? 0);
    $up      = $me > 0 ? afaa_get_user_prefs($me) : ['sound'=>1,'toasts'=>1];

    // Гостям ничего не вставляем
    if ($me <= 0) {
        return;
    }

    // Дефолтный аватар
    require_once MYBB_ROOT.'inc/functions_user.php';
    $def = format_avatar('', '', '64x64');
    $defAvatar = is_array($def) ? (string)$def['image'] : (string)$def;

    $post_key = addslashes($mybb->post_code ?? '');

    // JS-файл теперь лежит прямо в /addons/advancedalerts/
    $jsPath = AF_AA_PUBLIC_DIR.'af_alerts.js';
    $jsVer  = @file_exists($jsPath) ? @filemtime($jsPath) : TIME_NOW;

    // Конфиг для фронта (добавил userId)
    $cfgScript = "<script>window.AFAlertsCfg={"
        ."pollSec:{$poll},"
        ."dropdownLimit:{$limit},"
        ."toastLimit:{$toast},"
        ."defAvatar:'".htmlspecialchars($defAvatar, ENT_QUOTES)."',"
        ."userSound:".((int)$up['sound'] ? 'true' : 'false').","
        ."userToasts:".((int)$up['toasts'] ? 'true' : 'false').","
        ."userId:{$me}"
        ."};</script>";

    // Подключаем CSS/JS/звук — без папки assets
    $head  = "\n";
    $head .= "<script>window.my_post_key=window.my_post_key||'{$post_key}';</script>\n";
    $head .= "<link rel=\"stylesheet\" href=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/af_alerts.css?v={$jsVer}\" />\n";
    $head .= $cfgScript . "\n";
    $head .= "<script src=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/af_alerts.js?v={$jsVer}\"></script>\n";
    $head .= "<audio id=\"afaa-audio\" preload=\"auto\" style=\"display:none\"><source src=\"{$baseurl}/inc/plugins/advancedfunctionality/addons/advancedalerts/ping.mp3\" type=\"audio/mpeg\"></audio>\n";

    $page = str_replace('</head>', $head.'</head>', $page);

    $badge = afaa_unread_count($me);
    $title = 'Уведомления';
    $all   = 'Все уведомления';
    $gear  = 'Настройки';
    $back  = 'Вернуться к уведомлениям';

    $soundChecked = $up['sound'] ? ' checked' : '';

    // Колокольчик + попап + тумблер "Звук"
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



/* ====================== ROUTER (PAGE + AJAX) ======================= */
function afaa_misc_router(): void
{
    global $mybb, $db, $header, $footer, $headerinclude, $templates, $theme, $lang;

    $action = $mybb->get_input('action');
    if ($action !== 'af_alerts' && $action !== 'af_alerts_api') return;

    if ((int)$mybb->user['uid'] <= 0) error_no_permission();

    // ==== СТРАНИЦА /misc.php?action=af_alerts ====
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
                        . '<form method="post" action="misc.php?action=af_alerts_api" class="inline">'
                        . '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">'
                        . '<input type="hidden" name="op" value="mark_read">'
                        . '<input type="hidden" name="ids[]" value="'.(int)$r['id'].'">'
                        . '<input type="submit" class="button small" value="✓" title="Прочитано" style="opacity:.4">'
                        . '</form> '
                        . '<form method="post" action="misc.php?action=af_alerts_api" class="inline">'
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

    // ==== AJAX API (чистый JSON) ====
    if ($action === 'af_alerts_api') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors', '0');
        @header_remove('Content-Type');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (my_strtolower($mybb->request_method) !== 'post') {
            header('Content-Type: application/json; charset=utf-8', true, 405);
            echo json_encode(['ok'=>0, 'error'=>'method_not_allowed']);
            exit;
        }

        $post_key = (string)$mybb->get_input('my_post_key');
        $real_key = (string)($mybb->post_code ?? '');
        if ($post_key === '' || !hash_equals($real_key, $post_key)) {
            header('Content-Type: application/json; charset=utf-8', true, 403);
            echo json_encode(['ok'=>0, 'error'=>'bad_csrf']);
            exit;
        }

        $uid = (int)$mybb->user['uid'];
        if ($uid <= 0) {
            header('Content-Type: application/json; charset=utf-8', true, 401);
            echo json_encode(['ok'=>0, 'error'=>'unauthorized']);
            exit;
        }

        $op = $mybb->get_input('op');

        if ($op === 'ping') {
            echo json_encode(['ok'=>1, 'pong'=>TIME_NOW]);
            exit;
        }

        if ($op === 'list') {
            $limit = max(1, (int)($mybb->get_input('limit') ?: ($mybb->settings['af_aa_dropdown_limit'] ?? 10)));
            $rows  = afaa_list_for_user($uid, $limit, 0);

            $data = [];
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

            echo json_encode(['ok'=>1, 'items'=>$data, 'badge'=>afaa_unread_count($uid)]);
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
            echo json_encode(['ok'=>1, 'badge'=>afaa_unread_count($uid)]);
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
            echo json_encode(['ok'=>1, 'badge'=>0]);
            exit;
        }

        if ($op === 'mark_read') {
            $ids = $mybb->get_input('ids', MyBB::INPUT_ARRAY) ?? [];
            afaa_mark_read_ids($uid, array_map('intval',$ids));
            echo json_encode(['ok'=>1, 'badge'=>afaa_unread_count($uid)]);
            exit;
        }

        if ($op === 'delete') {
            $ids = $mybb->get_input('ids', MyBB::INPUT_ARRAY) ?? [];
            afaa_delete_ids($uid, array_map('intval',$ids));
            echo json_encode(['ok'=>1, 'badge'=>afaa_unread_count($uid)]);
            exit;
        }

        // ==== DIAG: расширенная диагностика ====
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
            $diag_uid  = (int)$mybb->get_input('uid'); // можно передать uid явно
            if ($diag_uid <= 0) {
                $diag_uid = $uid;
            }

            if ($have_alerts) {
                // всего
                $r = $db->simple_select('alerts','COUNT(*) AS c');
                $cnt_total = (int)$db->fetch_field($r, 'c');

                // для текущего/указанного
                $r2 = $db->simple_select('alerts','COUNT(*) AS c', "uid=".(int)$diag_uid);
                $cnt_user = (int)$db->fetch_field($r2, 'c');

                // TOP 20 по uid
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

                // последние 20 для diag_uid
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
                'ok'       => $ok ? 1 : 0,
                'enabled'  => $enabled,
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
                'per_uid'        => $per_uid,
                'diag_uid'       => $diag_uid,
                'last_for_uid'   => $last_rows,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // быстрый предикат: есть ли колонка is_read?
        $have_is_read = false;
        $colq = $db->write_query("SHOW COLUMNS FROM `".TABLE_PREFIX."alerts` LIKE 'is_read'");
        if ($colq && $db->num_rows($colq) > 0) {
            $have_is_read = true;
        }
        if (!$have_is_read) {
            echo json_encode(['ok'=>0,'error'=>'schema_mismatch_missing_is_read']);
            exit;
        }

        if ($op === 'probe_insert') {
            $uid = (int)$mybb->user['uid'];
            if ($uid <= 0) {
                echo json_encode(['ok'=>0,'error'=>'unauthorized']); exit;
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
                    echo json_encode(['ok'=>0, 'error'=>'schema_mismatch_missing_col', 'col'=>$c]); exit;
                }
            }

            $pmid = afaa_type_id('pm');
            if ($pmid <= 0) { afaa_register_default_types(); $pmid = afaa_type_id('pm'); }
            if ($pmid <= 0) {
                echo json_encode(['ok'=>0, 'error'=>'no_type_pm']); exit;
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

            $ok = 0; $id = 0; $errNo = 0; $errStr = '';
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

        echo json_encode(['ok'=>0, 'error'=>'unknown_op']);
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

    // Сохраняем только если включено разрешение на отключение типов
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

/* ====================== TRIGGERS ======================= */
function afaa_post_insert_end(&$posthandler): void
{
    global $db, $mybb;

    // Автор поста
    $author = (int)($mybb->user['uid'] ?? 0);
    if ($author <= 0) {
        return;
    }

    // Унифицируем данные: сперва берем то, что дал datahandler
    $data = [];
    if (!empty($posthandler->post_insert_data) && is_array($posthandler->post_insert_data)) {
        $data = $posthandler->post_insert_data;
    } elseif (!empty($posthandler->data) && is_array($posthandler->data)) {
        $data = $posthandler->data;
    }

    // Пытаемся вытащить pid / tid / fid / subject / message из handler'а
    $pid     = 0;
    if (!empty($posthandler->pid)) {
        $pid = (int)$posthandler->pid;
    } elseif (!empty($data['pid'])) {
        $pid = (int)$data['pid'];
    }

    $tid     = (int)($data['tid']     ?? 0);
    $fid     = (int)($data['fid']     ?? 0);
    $subject = trim((string)($data['subject'] ?? ''));
    $message = (string)($data['message'] ?? '');

    // Нормализуем текст: убираем неразрывные пробелы и приводим пробелы к одному виду
    // (важно для @"Name Surname" с NBSP вместо обычного пробела)
    $message = str_replace("\xc2\xa0", ' ', $message);        // NBSP -> обычный пробел
    $message = preg_replace('~[ \t]+~u', ' ', $message);     // сливаем пачки пробелов


    // Если чего-то не хватает — подстрахуемся базой
    if ($pid > 0 && ($tid <= 0 || $fid <= 0 || $message === '')) {
        $post = $db->fetch_array(
            $db->simple_select('posts', 'tid,fid,message', "pid={$pid}", ['limit' => 1])
        ) ?: [];
        if ($tid <= 0 && !empty($post['tid'])) {
            $tid = (int)$post['tid'];
        }
        if ($fid <= 0 && !empty($post['fid'])) {
            $fid = (int)$post['fid'];
        }
        if ($message === '' && isset($post['message'])) {
            $message = (string)$post['message'];
        }
    }

    if ($tid <= 0) {
        // Без tid — совсем беда, ничего не сделаем
        return;
    }

    // Тема (subject / firstpost / fid)
    $thread = $db->fetch_array(
        $db->simple_select('threads', 'subject,firstpost,fid', "tid={$tid}", ['limit' => 1])
    ) ?: [];

    if ($subject === '') {
        $subject = trim((string)($thread['subject'] ?? 'Тема'));
    }
    if ($fid <= 0 && !empty($thread['fid'])) {
        $fid = (int)$thread['fid'];
    }

    // Если pid так и не получили — привяжем ссылку хотя бы к последнему посту темы
    if ($pid <= 0) {
        $last = $db->fetch_array(
            $db->simple_select('posts', 'pid', "tid={$tid}", ['order_by' => 'dateline', 'order_dir'=>'DESC', 'limit'=>1])
        ) ?: [];
        $pid = (int)($last['pid'] ?? 0);
    }

    $link = $pid > 0
        ? "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}"
        : "showthread.php?tid={$tid}";

    $author_name = (string)($mybb->user['username'] ?? '');

    $callKey = $pid > 0 ? 'pid:'.$pid : ($tid > 0 ? 'tid:'.$tid : null);
    if ($callKey !== null) {
        if (!empty($GLOBALS['afaa_post_processed'][$callKey])) {
            return;
        }
        $GLOBALS['afaa_post_processed'][$callKey] = true;
    }

    /* ---------- 1) Подписчики темы (subscribed_thread) ---------- */

    $subs = [];
    $q = $db->simple_select(
        'threadsubscriptions',
        'uid',
        "tid={$tid} AND uid<>".$author
    );
    while ($r = $db->fetch_array($q)) {
        $subs[(int)$r['uid']] = true;
    }

    foreach (array_keys($subs) as $suid) {
        afaa_send(
            (int)$suid,
            'subscribed_thread',
            [
                'thread_subject' => $subject,
                'from_username'  => $author_name,
                'link'           => $link,
            ],
            $author,
            $pid ?: null
        );
    }

    /* ---------- 2) Подписчики форума (subscribed_forum, только НОВАЯ тема) ---------- */

    // Не полагаемся только на threads.firstpost — он иногда 0 на момент хука.
    $is_new_thread = false;

    // Попытка №1: сравнить firstpost и pid (если оба есть)
    if (!empty($thread['firstpost']) && $pid > 0) {
        if ((int)$thread['firstpost'] === $pid) {
            $is_new_thread = true;
        }
    }

    // Попытка №2: fallback по количеству постов в теме
    if (!$is_new_thread && $tid > 0) {
        $cnt = (int)$db->fetch_field(
            $db->simple_select('posts', 'COUNT(*) AS c', "tid={$tid}"),
            'c'
        );
        if ($cnt <= 1) {
            $is_new_thread = true;
        }
    }

    if ($fid > 0 && $is_new_thread) {
        $fsubs = [];
        $fq = $db->simple_select(
            'forumsubscriptions',
            'uid',
            "fid={$fid} AND uid<>".$author
        );
        while ($r = $db->fetch_array($fq)) {
            $fsubs[(int)$r['uid']] = true;
        }

        foreach (array_keys($fsubs) as $fuid) {
            afaa_send(
                (int)$fuid,
                'subscribed_forum',
                [
                    'thread_subject' => $subject,
                    'from_username'  => $author_name,
                    'link'           => $link,
                ],
                $author,
                $pid ?: null
            );
        }
    }

    /* ---------- 3) ЦИТИРОВАНИЕ: через pid в [quote ... pid="123"...] или по имени ---------- */

    $quotePids        = [];
    $quoteUsersByName = [];

    // Формат [quote="A.R.K.A.D.I." pid='28' dateline='...']
    if (preg_match_all('~\[quote[^\]]*?\bpid=(?:"|\')?(\d+)(?:"|\')?[^\]]*\]~i', $message, $m)) {
        foreach ($m[1] as $pidRaw) {
            $qp = (int)$pidRaw;
            if ($qp > 0) {
                $quotePids[$qp] = true;
            }
        }
    }

    // Дополнительно: [quote="Имя Фамилия"] — даже если pid есть, лишним не будет, дубликаты мы отфильтруем
    if (preg_match_all('~\[quote=(?:"|\')([^"\']{2,60})(?:"|\')[^\]]*\]~ui', $message, $mm)) {
        foreach ($mm[1] as $nm) {
            $nm = my_strtolower(trim($nm));
            if ($nm !== '') {
                $quoteUsersByName[$nm] = true;
            }
        }
    }

    $quotedUids = [];

    // Через pid
    if ($quotePids) {
        $pidList = implode(',', array_map('intval', array_keys($quotePids)));
        $rq = $db->write_query(
            "SELECT DISTINCT uid FROM ".TABLE_PREFIX."posts WHERE pid IN ({$pidList})"
        );
        while ($u = $db->fetch_array($rq)) {
            $uid = (int)$u['uid'];
            if ($uid > 0 && $uid !== $author) {
                $quotedUids[$uid] = true;
            }
        }
    }

    // Через username
    if ($quoteUsersByName) {
        $in = [];
        foreach (array_keys($quoteUsersByName) as $n) {
            $in[] = "'".$db->escape_string($n)."'";
        }
        if ($in) {
            $rq = $db->write_query(
                "SELECT uid, username FROM ".TABLE_PREFIX."users WHERE LOWER(username) IN (".implode(',', $in).")"
            );
            while ($u = $db->fetch_array($rq)) {
                $uid = (int)$u['uid'];
                if ($uid > 0 && $uid !== $author) {
                    $quotedUids[$uid] = true;
                }
            }
        }
    }

    foreach (array_keys($quotedUids) as $uid) {
        afaa_send(
            (int)$uid,
            'quoted',
            [
                'thread_subject' => $subject,
                'from_username'  => $author_name,
                'link'           => $link,
            ],
            $author,
            $pid ?: null
        );
    }


    /* ---------- 4) УПОМИНАНИЯ: @username / @"username", @all, @group{} ---------- */

    afaa_notify_mentions_from_message(
        $message,
        $author,
        $pid,
        $tid,
        $subject,
        $author_name,
        $link
    );


function afaa_rep_do_add_end($reputationhandler): void
{
    global $db, $mybb;

    $from = (int)($mybb->user['uid'] ?? 0);
    if ($from <= 0) {
        return;
    }

    // Пытаемся взять данные из datahandler'а (каноничный способ)
    $data = [];
    if (is_object($reputationhandler) && isset($reputationhandler->data) && is_array($reputationhandler->data)) {
        $data = $reputationhandler->data;
    }

    $to     = (int)($data['uid'] ?? 0);          // кому ставят репу
    $points = (int)($data['reputation'] ?? 0);   // величина
    $reason = (string)($data['comments'] ?? ''); // причина
    $pid    = (int)($data['pid'] ?? 0);          // пост (если есть)

    if ($to <= 0 || $to === $from) {
        return;
    }

    // Ссылка: если есть pid — в пост, иначе в страницу репутации
    $link = '';
    if ($pid > 0) {
        $post = $db->fetch_array(
            $db->simple_select('posts', 'tid', "pid={$pid}", ['limit' => 1])
        ) ?: [];
        if (!empty($post['tid'])) {
            $tid = (int)$post['tid'];
            $link = "showthread.php?tid={$tid}&pid={$pid}#pid{$pid}";
        }
    }
    if ($link === '') {
        $link = "reputation.php?uid={$to}";
    }

    $from_name = (string)($mybb->user['username'] ?? '');

    afaa_send(
        $to,
        'rep',
        [
            'from_username' => $from_name,
            'points'        => $points,
            'reason'        => $reason,
            'link'          => $link,
        ],
        $from,
        null
    );
}


function afaa_pm_insert_end(&$pmhandler): void
{
    global $db, $mybb;

    // Отправитель
    $from = (int)($mybb->user['uid'] ?? 0);
    if ($from <= 0) {
        return;
    }

    // Данные из datahandler
    $data = $pmhandler->pm_insert_data ?? $pmhandler->data ?? [];
    $targets = [];

    // --- 1) Получатели из структуры recipients (to + bcc) ---
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

    // --- 2) Подстраховка — читаем toid из privatemessages по pmid ---
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

    // --- 3) Заголовок и ссылка на ЛС ---
    $subject = '';
    if (!empty($data['subject'])) {
        $subject = (string)$data['subject'];
    } elseif ($pmids) {
        $idList = implode(',', array_keys($pmids));
        $q2 = $db->simple_select('privatemessages', 'subject', "pmid IN ({$idList})", ['limit' => 1]);
        $subject = (string)$db->fetch_field($q2, 'subject');
    }

    $from_name = (string)($mybb->user['username'] ?? '');

    // Берём любой pmid (как правило, он один)
    $pmidForLink = $pmids ? max(array_keys($pmids)) : 0;
    $link        = $pmidForLink > 0
        ? "private.php?action=read&pmid={$pmidForLink}"
        : "private.php";

    // --- 4) Рассылаем уведомления ---
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
    global $db, $mybb;
    $me = (int)$mybb->user['uid']; if ($me<=0) return;

    if (!empty($_POST['add_username'])) {
        $name = trim((string)$_POST['add_username']);
        if ($name !== '') {
            $u = $db->fetch_array($db->simple_select('users','uid', "LOWER(username)='". $db->escape_string(my_strtolower($name))."'", ['limit'=>1]));
            if (!empty($u['uid']) && (int)$u['uid'] !== $me) {
                afaa_send((int)$u['uid'], 'buddylist', [
                    'title'=>'Заявка в друзья',
                    'link'=>'usercp.php?action=editlists'
                ], $me, null);

            }
        }
    }

    if (!empty($_POST['accept']) && is_array($_POST['accept'])) {
        foreach (array_keys($_POST['accept']) as $from) {
            $from = (int)$from;
            if ($from>0 && $from !== $me) {
                afaa_send($from, 'buddy_accept', [
                    'title'=>'Заявка в друзья принята',
                    'link'=>'usercp.php?action=editlists'
                ], $me, null);
            }
        }
    }
}

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

    // Сохранение
    if (my_strtolower($mybb->request_method) === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        // Чекбоксы типов
        $incoming = $mybb->get_input('afaa_types', MyBB::INPUT_ARRAY) ?? [];
        afaa_save_user_types($uid, $incoming);

        // Персональные флаги UI (звук/тосты)
        $sound  = (int)$mybb->get_input('afaa_sound') === 1 ? 1 : 0;
        $toasts = (int)$mybb->get_input('afaa_toasts') === 1 ? 1 : 0;
        afaa_set_user_prefs($uid, $sound, $toasts);

        // Здесь можно убрать запись в usernotes, если не нужна
        // redirect и выход
        redirect('usercp.php?action=af_alert_prefs', 'Настройки сохранены.');
    }

    // Рендер формы
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



// Гарантируем регистрацию хуков даже если AF-ядро не вызвало init.
if (defined('IN_MYBB')) {
    af_advancedalerts_init();
}
