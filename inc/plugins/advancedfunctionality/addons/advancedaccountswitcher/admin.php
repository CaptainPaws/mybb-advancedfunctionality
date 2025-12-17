<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_AdvancedAccountSwitcher
{
    public static function dispatch()
    {
        global $mybb, $db, $lang;

        if (!isset($lang->af_advancedaccountswitcher_group)) {
            // на случай, если язык не подхватился автоматически
            $lang->af_advancedaccountswitcher_group = 'AF: Advanced Account Switcher';
        }

        $view = (string)($mybb->input['af_view'] ?? 'overview');

        if ($view === 'logs') {
            self::render_logs();
            return;
        }

        self::render_overview();
    }

    private static function render_overview()
    {
        global $mybb, $db, $page;

        $q = trim((string)($mybb->input['q'] ?? ''));

        $where = "1=1";
        if ($q !== '') {
            $like = $db->escape_string_like($q);
            $where = "(um.username LIKE '{$like}%' OR ua.username LIKE '{$like}%')";
        }

        $rows = [];
        $res = $db->query("
            SELECT l.id, l.master_uid, l.attached_uid, l.created_at,
                   um.username AS master_name,
                   ua.username AS attached_name
            FROM " . TABLE_PREFIX . "af_aas_links l
            LEFT JOIN " . TABLE_PREFIX . "users um ON (um.uid = l.master_uid)
            LEFT JOIN " . TABLE_PREFIX . "users ua ON (ua.uid = l.attached_uid)
            WHERE {$where}
            ORDER BY l.id DESC
            LIMIT 200
        ");

        while ($r = $db->fetch_array($res)) {
            $rows[] = $r;
        }

        echo '<h2>Advanced Account Switcher</h2>';
        echo '<form method="get" action="index.php">
            <input type="hidden" name="module" value="advancedfunctionality">
            <input type="hidden" name="af_view" value="advancedaccountswitcher">
            <input type="text" name="q" value="' . htmlspecialchars_uni($q) . '" class="textbox" placeholder="Поиск по нику...">
            <button class="button">Искать</button>
            <a class="button" href="index.php?module=advancedfunctionality&af_view=advancedaccountswitcher&af_sub=logs">Логи</a>
        </form><br>';

        echo '<table class="general" cellspacing="0" cellpadding="4" width="100%">';
        echo '<tr>
            <th>ID</th>
            <th>Master</th>
            <th>Attached</th>
            <th>Дата</th>
        </tr>';

        foreach ($rows as $r) {
            $d = $r['created_at'] ? my_date('relative', (int)$r['created_at']) : '-';
            echo '<tr>
                <td>' . (int)$r['id'] . '</td>
                <td><a href="../member.php?action=profile&uid=' . (int)$r['master_uid'] . '" target="_blank">' . htmlspecialchars_uni($r['master_name'] ?? ('#'.$r['master_uid'])) . '</a></td>
                <td><a href="../member.php?action=profile&uid=' . (int)$r['attached_uid'] . '" target="_blank">' . htmlspecialchars_uni($r['attached_name'] ?? ('#'.$r['attached_uid'])) . '</a></td>
                <td>' . $d . '</td>
            </tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="4"><em>Пока нет связей.</em></td></tr>';
        }

        echo '</table>';
        echo '<p class="smalltext">Показаны последние 200 связей (для безопасности/производительности).</p>';
    }

    private static function render_logs()
    {
        global $db;

        $rows = [];
        $res = $db->query("
            SELECT l.id, l.actor_uid, l.from_uid, l.to_uid, l.ip, l.created_at,
                   ua.username AS actor_name,
                   uf.username AS from_name,
                   ut.username AS to_name
            FROM " . TABLE_PREFIX . "af_aas_switch_log l
            LEFT JOIN " . TABLE_PREFIX . "users ua ON (ua.uid = l.actor_uid)
            LEFT JOIN " . TABLE_PREFIX . "users uf ON (uf.uid = l.from_uid)
            LEFT JOIN " . TABLE_PREFIX . "users ut ON (ut.uid = l.to_uid)
            ORDER BY l.id DESC
            LIMIT 200
        ");

        while ($r = $db->fetch_array($res)) {
            $rows[] = $r;
        }

        echo '<h2>Advanced Account Switcher — Логи переключений</h2>';
        echo '<a class="button" href="index.php?module=advancedfunctionality&af_view=advancedaccountswitcher">← Назад</a><br><br>';

        echo '<table class="general" cellspacing="0" cellpadding="4" width="100%">';
        echo '<tr>
            <th>ID</th>
            <th>Кто</th>
            <th>Из</th>
            <th>В</th>
            <th>IP</th>
            <th>Когда</th>
        </tr>';

        foreach ($rows as $r) {
            $d = $r['created_at'] ? my_date('relative', (int)$r['created_at']) : '-';
            echo '<tr>
                <td>' . (int)$r['id'] . '</td>
                <td>' . htmlspecialchars_uni($r['actor_name'] ?? ('#'.$r['actor_uid'])) . '</td>
                <td>' . htmlspecialchars_uni($r['from_name'] ?? ('#'.$r['from_uid'])) . '</td>
                <td>' . htmlspecialchars_uni($r['to_name'] ?? ('#'.$r['to_uid'])) . '</td>
                <td>' . htmlspecialchars_uni($r['ip'] ?? '') . '</td>
                <td>' . $d . '</td>
            </tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="6"><em>Пока нет логов.</em></td></tr>';
        }

        echo '</table>';
        echo '<p class="smalltext">Показаны последние 200 переключений.</p>';
    }
}
