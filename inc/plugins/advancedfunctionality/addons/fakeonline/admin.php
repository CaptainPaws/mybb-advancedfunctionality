<?php
/**
 * AF FakeOnline — ACP controller
 * Path: /inc/plugins/advancedfunctionality/addons/fakeonline/admin.php
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

// ACP-контроллер может грузиться без bootstrap аддона — поэтому страхуемся:
if (!defined('AF_FAKEONLINE_TASK_DB_FILE')) {
    define('AF_FAKEONLINE_TASK_DB_FILE', 'af_fakeonline'); // tasks.file в БД
}

class AF_Admin_Fakeonline
{
    public static function dispatch(): void
    {
        global $mybb, $db, $lang, $page;

        // ВАЖНО: AF router в ACP работает через af_view=...
        $baseUrl = 'index.php?module=advancedfunctionality&af_view=fakeonline';

        // Подгружаем файл задачи, чтобы иметь доступ к helper-функциям
        self::ensureTaskIncluded();

        // Обработка действий (в AF принято использовать do=...)
        $do = (string)($mybb->input['do'] ?? '');

        if ($mybb->request_method === 'post' && $do !== '') {
            if (function_exists('check_post_check')) {
                check_post_check($mybb->input['my_post_key'] ?? '');
            }

            if ($do === 'run_task') {
                self::runTaskNow(false);
                self::redirect($baseUrl, 'Задача FakeOnline выполнена вручную.');
            }

            if ($do === 'force_online') {
                $n = self::forceBringOnline();
                self::redirect($baseUrl, "Принудительно выведено онлайн профилей: {$n}.");
            }

            if ($do === 'clear_fake_sessions') {
                self::clearFakeSessions();
                self::redirect($baseUrl, 'Фейковые сессии очищены.');
            }

            if ($do === 'reset_state') {
                self::resetState();
                self::redirect($baseUrl, 'State-таблица сброшена (очищена).');
            }

            self::redirect($baseUrl, 'Неизвестное действие.');
        }

        // UI
        if (isset($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('Fake Online', $baseUrl);
        }

        echo '<div class="form_container">';
        echo '<p style="margin: 8px 0 14px 0;">';
        echo 'Здесь можно вручную выполнить задачу или принудительно вывести выбранные профили онлайн (без рандома).';
        echo '</p>';

        self::renderButtons($baseUrl);
        self::renderStatusBox();

        echo '</div>';
    }

    private static function renderButtons(string $baseUrl): void
    {
        global $mybb;

        $postKey = htmlspecialchars((string)($mybb->post_code ?? ''), ENT_QUOTES);

        // ВАЖНО: action формы тоже должен быть с af_view
        echo '<form action="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '" method="post" style="margin-bottom: 16px;">';
        echo '<input type="hidden" name="my_post_key" value="' . $postKey . '">';

        echo '<div style="display:flex; gap:10px; flex-wrap:wrap;">';

        echo '<button class="button" type="submit" name="do" value="run_task">Запустить таск сейчас</button>';

        echo '<button class="button" type="submit" name="do" value="force_online">';
        echo 'Принудительно вывести профили онлайн';
        echo '</button>';

        echo '<button class="button" type="submit" name="do" value="clear_fake_sessions">';
        echo 'Очистить фейковые сессии';
        echo '</button>';

        echo '<button class="button" type="submit" name="do" value="reset_state" onclick="return confirm(\'Точно очистить af_fakeonline_state?\')">';
        echo 'Сбросить state';
        echo '</button>';

        echo '</div>';
        echo '</form>';
    }

    private static function renderStatusBox(): void
    {
        global $db, $mybb;

        if (!$db->table_exists('af_fakeonline_state')) {
            echo '<div class="error" style="margin-top:10px;">Таблица af_fakeonline_state не найдена. Аддон не установлен корректно.</div>';
            return;
        }

        $rawProfiles = (string)($mybb->settings['af_fakeonline_profiles'] ?? '');
        $uids = [];

        if (function_exists('af_fakeonline_task_resolve_uids')) {
            $uids = af_fakeonline_task_resolve_uids($rawProfiles);
        }

        $uids = array_values(array_unique(array_map('intval', $uids)));

        echo '<h3 style="margin-top:0;">Статус</h3>';

        echo '<div style="margin-bottom:10px;">';
        echo '<b>profiles (как в настройке):</b> <code>' . htmlspecialchars($rawProfiles, ENT_QUOTES) . '</code><br>';
        echo '<b>разобранные uid:</b> <code>' . htmlspecialchars(implode(', ', $uids), ENT_QUOTES) . '</code><br>';
        echo '<b>фейковых сессий сейчас:</b> <code>' . (int)self::countFakeSessions() . '</code>';
        echo '</div>';

        if (empty($uids)) {
            echo '<div class="notice">Список uid пустой — нечего выводить онлайн. Проверь настройку profiles (например: <code>2,3</code>).</div>';
            return;
        }

        $uidSql = implode(',', $uids);
        $q = $db->simple_select('af_fakeonline_state', '*', "uid IN ({$uidSql})", ['order_by' => 'uid', 'order_dir' => 'ASC']);

        echo '<table class="general" cellspacing="0" cellpadding="5" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th>UID</th><th>next_action</th><th>online_until</th><th>last_location</th><th>fake_session</th><th>real_session</th>';
        echo '</tr></thead><tbody>';

        $now = TIME_NOW;

        while ($row = $db->fetch_array($q)) {
            $uid = (int)$row['uid'];
            $next = (int)$row['next_action'];
            $until = (int)$row['online_until'];
            $loc = (string)$row['last_location'];

            $hasFake = self::hasFakeSession($uid);
            $hasReal = (function_exists('af_fakeonline_task_has_real_session') ? (af_fakeonline_task_has_real_session($uid) ? 1 : 0) : 0);

            echo '<tr>';
            echo '<td>' . $uid . '</td>';
            echo '<td><code>' . $next . '</code> ' . ($next <= $now ? '✅' : '') . '</td>';
            echo '<td><code>' . $until . '</code> ' . ($until > $now ? '🟢' : '⚪') . '</td>';
            echo '<td><code>' . htmlspecialchars($loc, ENT_QUOTES) . '</code></td>';
            echo '<td>' . ($hasFake ? '✅' : '—') . '</td>';
            echo '<td>' . ($hasReal ? '✅' : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function ensureTaskIncluded(): void
    {
        // 1) Подключаем bootstrap аддона, чтобы были константы/общие функции
        $bootstrap = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/fakeonline/fakeonline.php';
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }

        // На всякий случай ещё раз страхуемся (если bootstrap не подключился/переименован)
        if (!defined('AF_FAKEONLINE_TASK_DB_FILE')) {
            define('AF_FAKEONLINE_TASK_DB_FILE', 'af_fakeonline');
        }

        // 2) Подключаем task-файл (реальный путь, как запускает MyBB)
        $taskFile = MYBB_ROOT . 'inc/tasks/' . (defined('AF_FAKEONLINE_TASK_FILE') ? AF_FAKEONLINE_TASK_FILE : 'af_fakeonline.php');
        if (is_file($taskFile)) {
            require_once $taskFile;
        }

    }


    private static function getTaskRow(): array
    {
        global $db;

        $file = $db->escape_string(AF_FAKEONLINE_TASK_DB_FILE);

        $q = $db->simple_select('tasks', '*', "file='{$file}'", ['limit' => 1]);
        $row = $db->fetch_array($q);

        if (is_array($row) && !empty($row)) {
            return $row;
        }

        return [
            'tid'   => 0,
            'title' => 'AF Fake Online (manual)',
            'file'  => AF_FAKEONLINE_TASK_DB_FILE,
        ];
    }

    private static function runTaskNow(bool $forceSpawn): void
    {
        if (!function_exists('task_af_fakeonline')) {
            return;
        }
        task_af_fakeonline(self::getTaskRow());
    }

    private static function forceBringOnline(): int
    {
        global $mybb, $db;

        if (!function_exists('af_fakeonline_task_resolve_uids')) {
            return 0;
        }
        if (!function_exists('af_fakeonline_task_ensure_fake_session')) {
            return 0;
        }
        if (!function_exists('af_fakeonline_task_pick_location')) {
            return 0;
        }

        $rawProfiles = (string)($mybb->settings['af_fakeonline_profiles'] ?? '');
        $uids = af_fakeonline_task_resolve_uids($rawProfiles);
        $uids = array_values(array_unique(array_map('intval', $uids)));

        if (empty($uids)) {
            return 0;
        }

        $now = TIME_NOW;

        $sessionMin = (int)($mybb->settings['af_fakeonline_session_minutes'] ?? 12);
        if ($sessionMin < 1) $sessionMin = 1;

        // ВАЖНО: для “моментальной ходьбы” после форса — next_action ставим почти сейчас
        $nextAction = $now + 1;

        // Перед форсом — синхронизируем state, чтобы строки точно были
        if (function_exists('af_fakeonline_task_sync_state')) {
            af_fakeonline_task_sync_state($uids, $now);
        }

        $task = self::getTaskRow();
        $n = 0;

        foreach ($uids as $uid) {
            $uid = (int)$uid;

            // Стартовая локация тоже пусть будет “живая”
            $loc = af_fakeonline_task_pick_location($uid);

            // Ставим “онлайн”
            af_fakeonline_task_ensure_fake_session($uid, $now, $loc, $task, false);

            // Обновляем state: online_until + next_action
            $onlineUntil = $now + ($sessionMin * 60);

            $db->update_query('af_fakeonline_state', [
                'online_until'  => (int)$onlineUntil,
                'next_action'   => (int)$nextAction,
                'last_location' => $db->escape_string($loc),
            ], "uid='{$uid}'");

            $n++;
        }

        if (function_exists('add_task_log')) {
            add_task_log($task, "Force online: {$n} профилей.");
        }

        return $n;
    }

    private static function clearFakeSessions(): void
    {
        global $db;

        if ($db->table_exists('sessions')) {
            $db->delete_query('sessions', "useragent LIKE '%AF FakeOnline/%'");
        }

        if ($db->table_exists('af_fakeonline_state')) {
            $db->update_query('af_fakeonline_state', [
                'online_until'  => 0,
                'next_action'   => 0,
                'last_location' => '',
                'fake_ip'       => '',
                'fake_sid'      => '',
            ], "1=1");
        }
    }

    private static function resetState(): void
    {
        global $db;

        if ($db->table_exists('af_fakeonline_state')) {
            $db->delete_query('af_fakeonline_state', "1=1");
        }
    }

    private static function hasFakeSession(int $uid): bool
    {
        global $db;

        if (!$db->table_exists('sessions')) {
            return false;
        }

        $uid = (int)$uid;
        $q = $db->simple_select(
            'sessions',
            'sid',
            "uid='{$uid}' AND useragent LIKE '%AF FakeOnline/%'",
            ['limit' => 1]
        );

        return (bool)$db->fetch_field($q, 'sid');
    }

    private static function countFakeSessions(): int
    {
        global $db;

        if (!$db->table_exists('sessions')) {
            return 0;
        }

        $q = $db->simple_select('sessions', 'COUNT(*) AS c', "useragent LIKE '%AF FakeOnline/%'");
        return (int)$db->fetch_field($q, 'c');
    }

    private static function redirect(string $url, string $msg): void
    {
        if (function_exists('flash_message')) {
            flash_message($msg, 'success');
        }
        if (function_exists('admin_redirect')) {
            admin_redirect($url);
        }
        header('Location: ' . $url);
        exit;
    }
}
