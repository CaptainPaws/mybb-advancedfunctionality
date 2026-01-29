<?php
/**
 * AdvancedFunctionality Addon: Fake Online
 * MyBB 1.8.x, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

const AF_FAKEONLINE_ID = 'fakeonline';
// Файл на диске в /inc/tasks/
const AF_FAKEONLINE_TASK_FILE = 'af_fakeonline.php';
// Значение для tasks.file (БЕЗ task_ и БЕЗ .php)
const AF_FAKEONLINE_TASK_DB_FILE = 'af_fakeonline';


/**
 * init hook (AF core may call it).
 */
function af_fakeonline_init(): void
{
    global $mybb, $plugins;

    if (empty($mybb->settings['af_fakeonline_enabled'])) {
        return;
    }

    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    // В AF activate() может не вызываться, поэтому ставим таск по месту.
    af_fakeonline_runtime_ensure_task();

    // ── Fix WOL ("Кто онлайн") только для фейковых сессий ───────────────
    if (isset($plugins) && is_object($plugins) && method_exists($plugins, 'add_hook')) {
        // Правим activity/location если MyBB определил как "login/unknown"
        $plugins->add_hook('fetch_wol_activity_end', 'af_fakeonline_wol_fix_activity');
        // Если всё равно получилось "Вошедшие" — меняем текст на "На главной"
        $plugins->add_hook('build_friendly_wol_location_end', 'af_fakeonline_wol_fix_location');
    }
}

/**
 * No-op pre_output hook (AF core may call it).
 */
function af_fakeonline_pre_output(string &$page = ''): void
{
    // Ничего не вставляем в HTML.
}

/* ────────────────────────────────────────────────────────────── */
/* Install / Activate / Deactivate / Uninstall                    */
/* ────────────────────────────────────────────────────────────── */

function af_fakeonline_install(): void
{
    global $db;

    // Таблица состояния (когда и как обновлять фейковую сессию для каждого uid)
    $collation = $db->build_create_table_collation();
    $table = $db->table_prefix . 'af_fakeonline_state';

    if (!$db->table_exists('af_fakeonline_state')) {
        $db->write_query("
            CREATE TABLE {$table} (
                uid INT UNSIGNED NOT NULL,
                next_action INT UNSIGNED NOT NULL DEFAULT 0,
                online_until INT UNSIGNED NOT NULL DEFAULT 0,
                last_location VARCHAR(255) NOT NULL DEFAULT '',
                fake_ip VARCHAR(45) NOT NULL DEFAULT '',
                fake_sid VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (uid)
            ) ENGINE=InnoDB {$collation};
        ");
    }

    af_fakeonline_ensure_settings();
    af_fakeonline_rebuild_settings_safe();
}

function af_fakeonline_is_installed(): bool
{
    global $db;
    return $db->table_exists('af_fakeonline_state');
}

function af_fakeonline_uninstall(): void
{
    global $db;

    // Удаляем задачу из tasks и файл (если наш)
    af_fakeonline_unregister_task();
    af_fakeonline_remove_task_file_if_ours();

    // Удаляем настройки
    af_fakeonline_remove_settings();

    // Дропаем таблицу состояния
    if ($db->table_exists('af_fakeonline_state')) {
        $db->drop_table('af_fakeonline_state');
    }

    af_fakeonline_rebuild_settings_safe();
}

function af_fakeonline_activate(): void
{
    af_fakeonline_ensure_settings();
    af_fakeonline_rebuild_settings_safe();

    // Кладём task-файл из assets в /inc/tasks
    af_fakeonline_deploy_task_file();

    // Регистрируем/включаем задачу
    af_fakeonline_register_or_enable_task();
}

function af_fakeonline_deactivate(): void
{
    // Отключаем задачу, и по желанию можно удалить файл
    af_fakeonline_disable_task();
    // Если хочешь оставлять файл — закомментируй строку ниже
    af_fakeonline_remove_task_file_if_ours();
}

/* ────────────────────────────────────────────────────────────── */
/* Settings                                                        */
/* ────────────────────────────────────────────────────────────── */

function af_fakeonline_ensure_settings(): void
{
    global $db, $lang;

    // Группа настроек
    $group_name = 'af_fakeonline';
    $gid = 0;

    $q = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($group_name) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');

    if ($gid <= 0) {
        $disporder = 1;
        $q2 = $db->simple_select('settinggroups', 'MAX(disporder) AS m');
        $m = (int)$db->fetch_field($q2, 'm');
        if ($m > 0) $disporder = $m + 1;

        $group = [
            'name'        => $group_name,
            'title'       => $lang->af_fakeonline_group ?? 'Фейковый онлайн',
            'description' => $lang->af_fakeonline_group_desc ?? 'Настройки имитации онлайна.',
            'disporder'   => $disporder,
            'isdefault'   => 0,
        ];
        $db->insert_query('settinggroups', $group);
        $gid = (int)$db->insert_id();
    }

    // Настройки
    af_fakeonline_upsert_setting($gid, 'af_fakeonline_enabled', 'yesno', 1,
        $lang->af_fakeonline_enabled ?? 'Включить аддон',
        $lang->af_fakeonline_enabled_desc ?? 'Включает работу задачи.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_profiles', 'textarea', '',
        $lang->af_fakeonline_profiles ?? 'Профили для фейкового онлайна',
        $lang->af_fakeonline_profiles_desc ?? 'UID или usernames, разделители: запятая/пробел/новая строка.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_min_interval', 'text', 60,
        $lang->af_fakeonline_min_interval ?? 'Минимальный интервал действий (сек)',
        $lang->af_fakeonline_min_interval_desc ?? 'Минимальная пауза между “переходами”.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_max_interval', 'text', 240,
        $lang->af_fakeonline_max_interval ?? 'Максимальный интервал действий (сек)',
        $lang->af_fakeonline_max_interval_desc ?? 'Максимальная пауза между “переходами”.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_session_minutes', 'text', 12,
        $lang->af_fakeonline_session_minutes ?? 'Длительность “онлайн-сессии” (мин)',
        $lang->af_fakeonline_session_minutes_desc ?? 'Сколько минут профиль остаётся онлайн.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_spawn_chance', 'text', 45,
        $lang->af_fakeonline_spawn_chance ?? 'Шанс появления онлайн (%)',
        $lang->af_fakeonline_spawn_chance_desc ?? 'Шанс “зайти” онлайн, когда пришло время.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_max_online', 'text', 5,
        $lang->af_fakeonline_max_online ?? 'Макс. фейковых онлайн одновременно',
        $lang->af_fakeonline_max_online_desc ?? 'Ограничение одновременных фейковых сессий.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_skip_real', 'yesno', 1,
        $lang->af_fakeonline_skip_real ?? 'Не трогать, если профиль реально онлайн',
        $lang->af_fakeonline_skip_real_desc ?? 'Если есть настоящая сессия — фейк не создаём/не обновляем.'
    );

    af_fakeonline_upsert_setting($gid, 'af_fakeonline_debug', 'yesno', 0,
        $lang->af_fakeonline_debug ?? 'Debug-логирование задачи',
        $lang->af_fakeonline_debug_desc ?? 'Расширенный лог при выполнении task.'
    );
}

function af_fakeonline_upsert_setting(
    int $gid,
    string $name,
    string $type,
    $defaultValue,
    string $title,
    string $description
): void {
    global $db;

    $q = $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]);
    $sid = (int)$db->fetch_field($q, 'sid');

    $disporder = 1;
    $q2 = $db->simple_select('settings', 'MAX(disporder) AS m', "gid='" . (int)$gid . "'");
    $m = (int)$db->fetch_field($q2, 'm');
    if ($m > 0) $disporder = $m + 1;

    $row = [
        'name'        => $name,
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($description),
        'optionscode' => $type,
        'value'       => (string)$defaultValue,
        'disporder'   => $disporder,
        'gid'         => $gid,
    ];

    if ($sid > 0) {
        // Не перетираем value если уже есть (чтобы настройки не сбрасывались)
        unset($row['value'], $row['disporder'], $row['gid']);
        $db->update_query('settings', $row, "sid='{$sid}'");
    } else {
        $db->insert_query('settings', $row);
    }
}

function af_fakeonline_remove_settings(): void
{
    global $db;

    $group_name = 'af_fakeonline';
    $q = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($group_name) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');

    if ($gid > 0) {
        $db->delete_query('settings', "gid='{$gid}'");
        $db->delete_query('settinggroups', "gid='{$gid}'");
    }
}

function af_fakeonline_rebuild_settings_safe(): void
{
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

/* ────────────────────────────────────────────────────────────── */
/* Task deploy + register                                           */
/* ────────────────────────────────────────────────────────────── */
function af_fakeonline_deploy_task_file(): bool
{
    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_FAKEONLINE_ID . '/assets/' . AF_FAKEONLINE_TASK_FILE;

    $dstDir = MYBB_ROOT . 'inc/tasks';
    $dst    = $dstDir . '/' . AF_FAKEONLINE_TASK_FILE;

    if (!is_dir($dstDir)) {
        @mkdir($dstDir, 0755, true);
    }

    if (!file_exists($src)) {
        return false;
    }

    $srcCode = file_get_contents($src);
    if ($srcCode === false || trim($srcCode) === '') {
        return false;
    }

    $needWrite = true;
    if (file_exists($dst)) {
        $dstCode = file_get_contents($dst);
        if ($dstCode !== false && hash('sha256', $dstCode) === hash('sha256', $srcCode)) {
            $needWrite = false;
        }
    }

    if ($needWrite) {
        $ok = @file_put_contents($dst, $srcCode);
        return $ok !== false;
    }

    return true;
}


function af_fakeonline_remove_task_file_if_ours(): void
{
    $paths = [
        MYBB_ROOT . 'inc/tasks/' . AF_FAKEONLINE_TASK_FILE,      // новый: af_fakeonline.php
        MYBB_ROOT . 'inc/tasks/task_af_fakeonline.php',          // старый хвост, на всякий случай
    ];

    foreach ($paths as $dst) {
        if (!is_file($dst)) {
            continue;
        }

        $data = @file_get_contents($dst);
        if ($data === false) {
            continue;
        }

        if (strpos($data, 'AF_FAKEONLINE_TASK_SIGNATURE') !== false) {
            @unlink($dst);
        }
    }
}

function af_fakeonline_register_or_enable_task(): void
{
    global $db;

    $good = defined('AF_FAKEONLINE_TASK_DB_FILE') ? AF_FAKEONLINE_TASK_DB_FILE : 'af_fakeonline';
    $goodEsc = $db->escape_string($good);

    // Частые “ошибочные” варианты, которые оставляют дубли в tasks
    $bad = [
        'task_af_fakeonline',
        'task_af_fakeonline.php',
        'af_fakeonline.php',
        'fakeonline',
    ];

    // 1) Удаляем очевидно неверные записи
    $badEsc = array_map([$db, 'escape_string'], $bad);
    $badIn = "'" . implode("','", $badEsc) . "'";
    $db->delete_query('tasks', "file IN ({$badIn}) AND file<>'{$goodEsc}'");

    // 2) Если по правильному file всё равно есть дубли — оставляем одну, остальные удаляем
    $tids = [];
    $q = $db->simple_select('tasks', 'tid', "file='{$goodEsc}'", ['order_by' => 'tid', 'order_dir' => 'ASC']);
    while ($r = $db->fetch_array($q)) {
        $tids[] = (int)$r['tid'];
    }
    if (count($tids) > 1) {
        $keep = array_shift($tids);
        $del = implode(',', array_map('intval', $tids));
        if ($del !== '') {
            $db->delete_query('tasks', "tid IN ({$del}) AND tid<>'{$keep}'");
        }
    }

    // каждую минуту (минимум для MyBB tasks; чаще штатно нельзя)
    $minute = '*';

    // хотим, чтобы после включения таск гарантированно стартовал в ближайшую минуту
    $nextRun = TIME_NOW + 60;

    // 3) Теперь гарантируем, что “правильная” задача существует и включена
    $tid = (int)$db->fetch_field(
        $db->simple_select('tasks', 'tid', "file='{$goodEsc}'", ['limit' => 1]),
        'tid'
    );

    if ($tid > 0) {
        $update = [
            'enabled'  => 1,
            'minute'   => $minute,
            'hour'     => '*',
            'day'      => '*',
            'month'    => '*',
            'weekday'  => '*',
            'logging'  => 1,
            'locked'   => 0,
        ];

        // Эти поля есть в стандартном MyBB 1.8 tasks
        if ($db->field_exists('nextrun', 'tasks')) {
            $update['nextrun'] = (int)$nextRun;
        }
        if ($db->field_exists('lastrun', 'tasks')) {
            $update['lastrun'] = 0;
        }

        $db->update_query('tasks', $update, "tid='{$tid}'");
        return;
    }

    $insert = [
        'title'       => 'AF Fake Online',
        'description' => 'Имитирует онлайн выбранных профилей (AdvancedFunctionality addon fakeonline).',
        'file'        => $good,
        'minute'      => $minute,
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'weekday'     => '*',
        'enabled'     => 1,
        'logging'     => 1,
        'locked'      => 0,
    ];

    if ($db->field_exists('nextrun', 'tasks')) {
        $insert['nextrun'] = (int)$nextRun;
    }
    if ($db->field_exists('lastrun', 'tasks')) {
        $insert['lastrun'] = 0;
    }

    $db->insert_query('tasks', $insert);
}

function af_fakeonline_disable_task(): void
{
    global $db;

    $file = $db->escape_string(AF_FAKEONLINE_TASK_DB_FILE);

    $tid = (int)$db->fetch_field(
        $db->simple_select('tasks', 'tid', "file='{$file}'", ['limit' => 1]),
        'tid'
    );

    if ($tid > 0) {
        $db->update_query('tasks', ['enabled' => 0], "tid='{$tid}'");
    }
}

function af_fakeonline_unregister_task(): void
{
    global $db;
    $db->delete_query('tasks', "file='" . $db->escape_string(AF_FAKEONLINE_TASK_DB_FILE) . "'");
}

function af_fakeonline_runtime_ensure_task(): void
{
    // 1) Положить файл (если отсутствует/отличается — обновит)
    af_fakeonline_deploy_task_file();

    // 2) Записать/включить задачу в БД
    af_fakeonline_register_or_enable_task();
}

function af_fakeonline_wol_is_fake_session(array $user_activity): bool
{
    $ua = (string)($user_activity['useragent'] ?? '');
    return ($ua !== '' && strpos($ua, 'AF FakeOnline/') !== false);
}

/**
 * Если MyBB определил активность криво (login/unknown) или не распарсил location —
 * принудительно выставляем activity по реальному location.
 * ТОЛЬКО для AF FakeOnline.
 *
 * ВАЖНО: MyBB hook fetch_wol_activity_end передаёт сюда САМ массив $user_activity (по ссылке),
 * а не массив-обёртку. Поэтому сигнатура должна быть (&$user_activity).
 */
function af_fakeonline_wol_fix_activity(&$user_activity): void
{
    if (empty($user_activity) || !is_array($user_activity)) {
        return;
    }

    if (!af_fakeonline_wol_is_fake_session($user_activity)) {
        return;
    }

    $activity = (string)($user_activity['activity'] ?? '');
    $location = (string)($user_activity['location'] ?? '');

    // Нормализуем на случай HTML entities (иногда location приходит уже экранированным)
    if ($location !== '') {
        $location = html_entity_decode($location, ENT_QUOTES);
    }

    // Если location пустой — хотя бы не "Вошедшие"
    if (trim($location) === '') {
        $user_activity['activity'] = 'index';
        $user_activity['location'] = 'index.php';
        $user_activity['nopermission'] = 0;
        return;
    }

    // Распарсим script + query (поддержка /member.php и member.php)
    $path  = (string)parse_url($location, PHP_URL_PATH);
    $query = (string)parse_url($location, PHP_URL_QUERY);

    $script = trim($path);
    if ($script === '') {
        $script = $location;
        $qpos = strpos($script, '?');
        if ($qpos !== false) {
            $script = substr($script, 0, $qpos);
        }
    }

    $script = ltrim(strtolower($script), '/');
    $qs = [];
    if ($query !== '') {
        parse_str($query, $qs);
    }

    /**
     * КРИТИЧНО:
     * Для profile MyBB САМ формирует friendly-location и ссылку, опираясь на $user_activity['uid'].
     * Поэтому location должен быть БЕЗ uid, иначе получится member.php?action=profile&&amp;uid=...
     */
    if (
        $script === 'member.php'
        && isset($qs['action']) && (string)$qs['action'] === 'profile'
        && isset($qs['uid'])
    ) {
        $targetUid = (int)$qs['uid'];
        if ($targetUid > 0) {
            $user_activity['activity'] = 'profile';

            // ВАЖНО: uid здесь = ЦЕЛЕВОЙ профиль (не автор сессии)
            $user_activity['uid'] = $targetUid;

            // ВАЖНО: location БЕЗ &uid=..., иначе MyBB допишет uid второй раз
            $user_activity['location'] = 'member.php?action=profile';

            $user_activity['nopermission'] = 0;
            return;
        }
    }

    // Если MyBB уже определил НЕ криво — не лезем дальше
    $badActivity = in_array($activity, ['login', 'unknown', ''], true);
    if (!$badActivity) {
        return;
    }

    // Мини-маппинг “куда смотрит”
    $map = [
        'index.php'        => 'index',
        'portal.php'       => 'portal',
        'showthread.php'   => 'showthread',
        'forumdisplay.php' => 'forumdisplay',
        'private.php'      => 'private',
        'usercp.php'       => 'usercp',
        'search.php'       => 'search',
        'member.php'       => 'profile',
    ];

    $act = $map[$script] ?? 'index';

    $user_activity['activity'] = $act;

    if (trim((string)($user_activity['location'] ?? '')) === '') {
        $user_activity['location'] = 'index.php';
    }

    $user_activity['nopermission'] = 0;
}

/**
 * Финальный слой: подстраховка текста локации в "Кто онлайн" для AF FakeOnline.
 *
 * ПРИМЕЧАНИЕ:
 * В MyBB этот hook иногда даёт только строку $location, а иногда (в некоторых сборках/патчах) — массив.
 * Поэтому делаем максимально терпимую сигнатуру.
 */
function af_fakeonline_wol_fix_location(&$arg1, $arg2 = null): void
{
    global $lang, $db;

    // Вариант 1: MyBB/сборка передала массив вида ['location'=>...,'user_activity'=>...]
    if (is_array($arg1) && isset($arg1['location'])) {
        $hook_args = &$arg1;

        if (empty($hook_args['user_activity']) || !is_array($hook_args['user_activity'])) {
            return;
        }
        if (!af_fakeonline_wol_is_fake_session($hook_args['user_activity'])) {
            return;
        }

        $ua = $hook_args['user_activity'];
        $targetUid = 0;

        if ((string)($ua['activity'] ?? '') === 'profile' && !empty($ua['uid'])) {
            $targetUid = (int)$ua['uid'];
        }

        if ($targetUid > 0) {
            static $nameCache = [];

            if (!isset($nameCache[$targetUid])) {
                $q = $db->simple_select('users', 'username', "uid='" . (int)$targetUid . "'", ['limit' => 1]);
                $nameCache[$targetUid] = (string)$db->fetch_field($q, 'username');
            }

            $uname = $nameCache[$targetUid] ?: ('UID ' . $targetUid);
            $unameEsc = function_exists('htmlspecialchars_uni')
                ? htmlspecialchars_uni($uname)
                : htmlspecialchars($uname, ENT_QUOTES);

            // ВАЖНО: даём MyBB собрать корректную ссылку (SEO, экранирование и т.д.)
            if (function_exists('get_profile_link')) {
                $url = get_profile_link((int)$targetUid);
            } else {
                // fallback, если вдруг get_profile_link недоступен
                $url = 'member.php?action=profile&amp;uid=' . (int)$targetUid;
            }

            $hook_args['location'] = 'Смотрит профиль <a href="' . $url . '">' . $unameEsc . '</a>';
            return;
        }

        // если вдруг всё равно "Вошедшие"
        $locHtml = (string)$hook_args['location'];
        $plain = trim(htmlspecialchars_decode(strip_tags($locHtml)));
        $loggedInText = (string)($lang->online_loggedin ?? 'Вошедшие');

        if ($plain === $loggedInText || $plain === 'Вошедшие') {
            $hook_args['location'] = 'На главной';
        }

        return;
    }

    // Вариант 2: MyBB передал только строку $location — без user_activity не трогаем.
    return;
}



