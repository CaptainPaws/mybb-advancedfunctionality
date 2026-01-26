<?php
/**
 * Task: AF Fake Online
 * File must be deployed to /inc/tasks/af_fakeonline.php
 *
 * AF_FAKEONLINE_TASK_SIGNATURE
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

function task_af_fakeonline($task): void
{
    global $db, $mybb;

    // Фича выключена — ничего не делаем
    if (empty($mybb->settings['af_fakeonline_enabled'])) {
        return;
    }

    $now = TIME_NOW;

    // Настройки
    $rawProfiles = (string)($mybb->settings['af_fakeonline_profiles'] ?? '');
    $minInterval = (int)($mybb->settings['af_fakeonline_min_interval'] ?? 60);
    $maxInterval = (int)($mybb->settings['af_fakeonline_max_interval'] ?? 240);
    $sessionMin  = (int)($mybb->settings['af_fakeonline_session_minutes'] ?? 12);
    $spawnChance = (int)($mybb->settings['af_fakeonline_spawn_chance'] ?? 45);
    $maxOnline   = (int)($mybb->settings['af_fakeonline_max_online'] ?? 5);
    $skipReal    = (int)($mybb->settings['af_fakeonline_skip_real'] ?? 1) === 1;
    $debug       = (int)($mybb->settings['af_fakeonline_debug'] ?? 0) === 1;

    if ($minInterval < 10) $minInterval = 10;
    if ($maxInterval < $minInterval) $maxInterval = $minInterval;
    if ($sessionMin < 1) $sessionMin = 1;
    if ($spawnChance < 0) $spawnChance = 0;
    if ($spawnChance > 100) $spawnChance = 100;
    if ($maxOnline < 1) $maxOnline = 1;

    if (!$db->table_exists('af_fakeonline_state')) {
        // Аддон не установлен корректно
        return;
    }

    // Разбираем список профилей -> uid[]
    $uids = af_fakeonline_task_resolve_uids($rawProfiles);
    if (empty($uids)) {
        // Нечего делать
        af_fakeonline_task_cleanup_all_fake_sessions($debug);
        return;
    }

    // Синхронизируем state-таблицу (добавить недостающих, удалить лишних)
    af_fakeonline_task_sync_state($uids, $now);
    af_fakeonline_task_clamp_next_actions($uids, $now, $minInterval, $maxInterval);

    // Считаем сколько фейковых уже онлайн
    $onlineNow = af_fakeonline_task_count_fake_online();

    // Берем кандидатов на обработку (next_action <= now) пачкой
    $uidListSql = implode(',', array_map('intval', $uids));
    $q = $db->simple_select(
        'af_fakeonline_state',
        '*',
        "uid IN ({$uidListSql}) AND next_action<=" . (int)$now,
        ['order_by' => 'next_action', 'order_dir' => 'ASC', 'limit' => 50]
    );

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        $onlineUntil = (int)$row['online_until'];

        // Если пользователь реально онлайн (не наша сессия) — не вмешиваемся
        if ($skipReal && af_fakeonline_task_has_real_session($uid)) {
            af_fakeonline_task_set_next_action($uid, $now + af_fakeonline_task_rand($minInterval, $maxInterval), $onlineUntil);
            if ($debug) {
                af_fakeonline_task_log($task, "Skip uid={$uid} (real session detected)");
            }
            continue;
        }

        // Если оффлайн — можем “зайти” онлайн, но с шансом и с лимитом max_online
        if ($onlineUntil <= $now) {
            // Чистим нашу фейковую сессию (если висит)
            af_fakeonline_task_delete_fake_session($uid);

            if ($onlineNow >= $maxOnline) {
                af_fakeonline_task_set_next_action($uid, $now + af_fakeonline_task_rand($minInterval, $maxInterval), 0);
                if ($debug) {
                    af_fakeonline_task_log($task, "Limit reached, keep offline uid={$uid}");
                }
                continue;
            }

            $roll = af_fakeonline_task_rand(1, 100);
            if ($roll > $spawnChance) {
                af_fakeonline_task_set_next_action($uid, $now + af_fakeonline_task_rand($minInterval, $maxInterval), 0);
                if ($debug) {
                    af_fakeonline_task_log($task, "Stay offline uid={$uid} (roll={$roll} > chance={$spawnChance})");
                }
                continue;
            }

            // Появляемся онлайн
            $onlineUntil = $now + ($sessionMin * 60);
            af_fakeonline_task_ensure_fake_session($uid, $now, af_fakeonline_task_pick_location_safe($uid), $task, $debug);
            af_fakeonline_task_set_next_action($uid, $now + af_fakeonline_task_rand($minInterval, $maxInterval), $onlineUntil);
            $onlineNow++;

            if ($debug) {
                af_fakeonline_task_log($task, "Go online uid={$uid} until={$onlineUntil}");
            }
            continue;
        }

        // Если онлайн — делаем “действие”: меняем location, обновляем time
        $loc = af_fakeonline_task_pick_location_safe($uid);
        af_fakeonline_task_ensure_fake_session($uid, $now, $loc, $task, $debug);
        af_fakeonline_task_set_next_action($uid, $now + af_fakeonline_task_rand($minInterval, $maxInterval), $onlineUntil);

        if ($debug) {
            af_fakeonline_task_log($task, "Move uid={$uid} -> {$loc}");
        }
    }

    // Доп. уборка: удаляем устаревшие фейковые сессии, если вдруг online_until уже прошёл
    af_fakeonline_task_cleanup_expired_states($now);
}

/* ────────────────────────────────────────────────────────────── */
/* Helpers                                                         */
/* ────────────────────────────────────────────────────────────── */

function af_fakeonline_task_resolve_uids(string $raw): array
{
    global $db;

    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    // Токенизация: запятые, пробелы, новые строки
    $tokens = preg_split('/[\s,]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return [];
    }

    $uids = [];
    $names = [];

    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t === '') continue;

        if (preg_match('/^\d+$/', $t)) {
            $uids[] = (int)$t;
        } else {
            $names[] = $t;
        }
    }

    $uids = array_values(array_unique(array_filter($uids)));

    if (!empty($names)) {
        $escaped = array_map([$db, 'escape_string'], $names);
        $in = "'" . implode("','", $escaped) . "'";
        $q = $db->simple_select('users', 'uid', "username IN ({$in})");
        while ($r = $db->fetch_array($q)) {
            $uids[] = (int)$r['uid'];
        }
    }

    $uids = array_values(array_unique(array_filter($uids)));

    // Отсекаем гостей/нулевых и несуществующих uid
    if (empty($uids)) return [];

    $in = implode(',', array_map('intval', $uids));
    $ok = [];
    $q2 = $db->simple_select('users', 'uid', "uid IN ({$in})");
    while ($r2 = $db->fetch_array($q2)) {
        $ok[] = (int)$r2['uid'];
    }

    return array_values(array_unique($ok));
}

function af_fakeonline_task_sync_state(array $uids, int $now): void
{
    global $db;

    $uidSql = implode(',', array_map('intval', $uids));

    // Удаляем лишних
    $db->delete_query('af_fakeonline_state', "uid NOT IN ({$uidSql})");

    // Добавляем недостающих
    $existing = [];
    $q = $db->simple_select('af_fakeonline_state', 'uid', "uid IN ({$uidSql})");
    while ($r = $db->fetch_array($q)) {
        $existing[(int)$r['uid']] = true;
    }

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        if (isset($existing[$uid])) continue;

        $db->insert_query('af_fakeonline_state', [
            'uid'          => $uid,
            'next_action'  => $now + af_fakeonline_task_rand(10, 120),
            'online_until' => 0,
            'last_location'=> '',
            'fake_ip'      => '',
            'fake_sid'     => '',
        ]);
    }
}

function af_fakeonline_task_count_fake_online(): int
{
    global $db;

    // Считаем наши сессии по useragent-маркеру
    $q = $db->simple_select('sessions', 'COUNT(*) AS c', "useragent LIKE '%AF FakeOnline/%'");
    return (int)$db->fetch_field($q, 'c');
}

function af_fakeonline_task_has_real_session(int $uid): bool
{
    global $db;

    // Есть активная сессия этого uid, которая НЕ наша (не AF FakeOnline)
    $cutoff = TIME_NOW - 15 * 60;

    $uid = (int)$uid;
    $q = $db->simple_select(
        'sessions',
        'sid',
        "uid='{$uid}' AND time>='{$cutoff}' AND useragent NOT LIKE '%AF FakeOnline/%'",
        ['limit' => 1]
    );

    return (bool)$db->fetch_field($q, 'sid');
}

function af_fakeonline_task_set_next_action(int $uid, int $nextAction, int $onlineUntil): void
{
    global $db;

    $db->update_query('af_fakeonline_state', [
        'next_action'  => (int)$nextAction,
        'online_until' => (int)$onlineUntil,
    ], "uid='" . (int)$uid . "'");
}

function af_fakeonline_task_cleanup_expired_states(int $now): void
{
    global $db;

    $q = $db->simple_select('af_fakeonline_state', 'uid', "online_until>0 AND online_until<=" . (int)$now);
    while ($r = $db->fetch_array($q)) {
        $uid = (int)$r['uid'];
        af_fakeonline_task_delete_fake_session($uid);
        $db->update_query('af_fakeonline_state', [
            'online_until' => 0,
        ], "uid='{$uid}'");
    }
}

function af_fakeonline_task_cleanup_all_fake_sessions(bool $debug): void
{
    global $db;

    // Удаляем все наши сессии
    $db->delete_query('sessions', "useragent LIKE '%AF FakeOnline/%'");

    if ($debug && $db->table_exists('af_fakeonline_state')) {
        $db->update_query('af_fakeonline_state', [
            'online_until' => 0,
        ], "1=1");
    }
}

function af_fakeonline_task_delete_fake_session(int $uid): void
{
    global $db;
    $uid = (int)$uid;
    $db->delete_query('sessions', "uid='{$uid}' AND useragent LIKE '%AF FakeOnline/%'");
}

function af_fakeonline_task_ensure_fake_session(int $uid, int $now, string $location, $task, bool $debug): void
{
    global $db;

    $uid = (int)$uid;

    // useragent-маркер — по нему отличаем “наши” сессии
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AF FakeOnline/1.0';

    // Генерим стабильный “док-IP” + sid
    $ip = af_fakeonline_task_fake_ip_for_uid($uid);
    $sid = md5($uid . '|' . $now . '|' . mt_rand());

    // Узнаём usergroup (если поле usergroup есть в sessions)
    $usergroup = 0;
    $qg = $db->simple_select('users', 'usergroup', "uid='{$uid}'", ['limit' => 1]);
    $usergroup = (int)$db->fetch_field($qg, 'usergroup');

    // Подготовим колонки с учётом реальной схемы таблицы sessions (на разных сборках/форках отличия)
    $fields = [
        'sid'       => $sid,
        'uid'       => $uid,
        'time'      => (int)$now,
        'location'  => $db->escape_string($location),
        'useragent' => $db->escape_string($ua),
        'anonymous' => 0,
        'nopermission' => 0,
    ];

    // IP: если есть my_inet_pton — кладём бинарно; если нет — строкой
    if ($db->field_exists('ip', 'sessions')) {
        if (function_exists('my_inet_pton')) {
            $fields['ip'] = $db->escape_binary(my_inet_pton($ip));
        } else {
            $fields['ip'] = $db->escape_string($ip);
        }
    }

    if ($db->field_exists('usergroup', 'sessions')) {
        $fields['usergroup'] = $usergroup;
    }

    // Обновить или вставить “нашу” сессию
    $q = $db->simple_select('sessions', 'sid', "uid='{$uid}' AND useragent LIKE '%AF FakeOnline/%'", ['limit' => 1]);
    $existingSid = (string)$db->fetch_field($q, 'sid');

    // Часть полей может отсутствовать — подрежем
    foreach (array_keys($fields) as $k) {
        if (!$db->field_exists($k, 'sessions')) {
            unset($fields[$k]);
        }
    }

    if ($existingSid !== '') {
        $db->update_query('sessions', $fields, "uid='{$uid}' AND useragent LIKE '%AF FakeOnline/%'");
    } else {
        // Вставка требует обязательных колонок — на всякий случай проверим минимум
        if (!isset($fields['sid'], $fields['uid'], $fields['time'], $fields['location'], $fields['useragent'])) {
            return;
        }
        $db->insert_query('sessions', $fields);
    }

    // Сохраним “последнюю локацию” в state
    $db->update_query('af_fakeonline_state', [
        'last_location' => $db->escape_string($location),
        'fake_ip'       => $db->escape_string($ip),
        'fake_sid'      => $db->escape_string($sid),
    ], "uid='{$uid}'");

    if ($debug) {
        af_fakeonline_task_log($task, "ensure_session uid={$uid} ip={$ip} sid={$sid} loc={$location}");
    }
}

function af_fakeonline_task_fake_ip_for_uid(int $uid): string
{
    // 203.0.113.0/24 — TEST-NET-3 (док-подсеть), безопасно использовать как “фейк”
    $last = ($uid % 250) + 2;
    return '203.0.113.' . $last;
}

function af_fakeonline_task_pick_location(int $uid): string
{
    // Набор “скриптов”, имитирующих активность
    $r = af_fakeonline_task_rand(1, 100);

    if ($r <= 25) {
        return 'index.php';
    }
    if ($r <= 40) {
        return 'portal.php';
    }
    if ($r <= 58) {
        // “ходит по темам”
        $tid = af_fakeonline_task_pick_random_thread_id();
        if ($tid > 0) {
            return 'showthread.php?tid=' . $tid;
        }
        return 'forumdisplay.php';
    }
    if ($r <= 72) {
        // “смотрит профиль”
        return 'member.php?action=profile&uid=' . $uid;
    }
    if ($r <= 86) {
        // “ЛС”
        return 'private.php';
    }
    if ($r <= 94) {
        // “настройки”
        return 'usercp.php';
    }

    // “поиск/новые сообщения”
    return 'search.php?action=getnew';
}

function af_fakeonline_task_pick_random_thread_id(): int
{
    global $db;

    // Быстрый рандом без ORDER BY RAND():
    $qMax = $db->simple_select('threads', 'MAX(tid) AS m', "visible='1'");
    $max = (int)$db->fetch_field($qMax, 'm');
    if ($max <= 0) return 0;

    $start = af_fakeonline_task_rand(1, $max);

    $q1 = $db->simple_select('threads', 'tid', "visible='1' AND tid>=" . (int)$start, ['order_by' => 'tid', 'order_dir' => 'ASC', 'limit' => 1]);
    $tid = (int)$db->fetch_field($q1, 'tid');
    if ($tid > 0) return $tid;

    $q2 = $db->simple_select('threads', 'tid', "visible='1' AND tid<=" . (int)$start, ['order_by' => 'tid', 'order_dir' => 'DESC', 'limit' => 1]);
    return (int)$db->fetch_field($q2, 'tid');
}

function af_fakeonline_task_rand(int $min, int $max): int
{
    if ($max < $min) $max = $min;
    try {
        return random_int($min, $max);
    } catch (Throwable $e) {
        return mt_rand($min, $max);
    }
}

function af_fakeonline_task_log($task, string $msg): void
{
    // MyBB task runner пишет в лог через add_task_log($task, $message)
    if (function_exists('add_task_log')) {
        add_task_log($task, $msg);
    }
}

function af_fakeonline_task_location_file_exists(string $location): bool
{
    $script = $location;
    $qpos = strpos($script, '?');
    if ($qpos !== false) {
        $script = substr($script, 0, $qpos);
    }

    $script = trim($script);
    if ($script === '') return false;

    // безопасность: запрещаем выход за пределы
    if (strpos($script, '..') !== false || strpos($script, '\\') !== false) {
        return false;
    }

    // разрешаем только *.php
    if (!preg_match('~^[a-z0-9_\-]+\.php$~i', $script)) {
        return false;
    }

    return is_file(MYBB_ROOT . $script);
}

function af_fakeonline_task_is_location_allowed(int $uid, string $location): bool
{
    // 1) файл должен существовать
    if (!af_fakeonline_task_location_file_exists($location)) {
        return false;
    }

    // 2) можно отсечь “палевные” роуты, если хочешь.
    //    Я по умолчанию ВЫКЛЮЧУ portal.php и calendar.php автоматически (их нет на диске — и так отсеется),
    //    но также предлагаю по умолчанию не ходить в ЛС и юзеркп, если боишься палевности.
    $script = $location;
    $qpos = strpos($script, '?');
    if ($qpos !== false) {
        $script = substr($script, 0, $qpos);
    }
    $script = strtolower($script);

    // Если хочешь вообще запретить эти страницы — оставь как есть.
    // Если хочешь разрешить — просто убери их из списка.
    $hardDeny = [
        'portal.php',    // у тебя отключён
        'calendar.php',  // у тебя отключён
        // 'private.php', // раскомментируй, если тоже хочешь запретить
        // 'usercp.php',  // раскомментируй, если тоже хочешь запретить
    ];

    if (in_array($script, $hardDeny, true)) {
        return false;
    }

    return true;
}

function af_fakeonline_task_pick_location_safe(int $uid): string
{
    // Пытаемся несколько раз выбрать валидную локацию
    for ($i = 0; $i < 12; $i++) {
        $loc = af_fakeonline_task_pick_location($uid);
        if (af_fakeonline_task_is_location_allowed($uid, $loc)) {
            return $loc;
        }
    }

    // Фолбэк — главная (и она точно существует)
    return 'index.php';
}

function af_fakeonline_task_clamp_next_actions(array $uids, int $now, int $minInterval, int $maxInterval): void
{
    global $db;

    if (empty($uids)) return;

    $uidSql = implode(',', array_map('intval', $uids));
    $limitFuture = $now + max(30, $maxInterval); // если next_action “улетел” дальше этого — тянем ближе

    // Забираем текущие значения
    $q = $db->simple_select('af_fakeonline_state', 'uid,next_action,online_until', "uid IN ({$uidSql})");
    while ($r = $db->fetch_array($q)) {
        $uid = (int)$r['uid'];
        $next = (int)$r['next_action'];
        $until = (int)$r['online_until'];

        $needFix = false;

        // 1) next_action слишком далеко в будущем (после изменения настроек такое часто)
        if ($next > $limitFuture) {
            $needFix = true;
        }

        // 2) user “онлайн”, но next_action почему-то позже конца сессии — тогда он “зависает” на одной локации до оффлайна
        if ($until > $now && $next > $until) {
            $needFix = true;
        }

        if ($needFix) {
            $newNext = $now + af_fakeonline_task_rand($minInterval, $maxInterval);
            $db->update_query('af_fakeonline_state', [
                'next_action' => (int)$newNext,
            ], "uid='{$uid}'");
        }
    }
}
