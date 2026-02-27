<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedinventory
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $db, $mybb;

        $page = max(1, (int)$mybb->get_input('page'));
        $perPage = 20;
        $search = trim((string)$mybb->get_input('username'));
        $hasItems = trim((string)$mybb->get_input('has_items'));

        $where = ['u.uid > 0'];
        if ($search !== '') {
            $like = $db->escape_string_like($search);
            $where[] = "u.username LIKE '%{$like}%'";
        }
        if ($hasItems === 'yes') {
            $where[] = 'COALESCE(inv.total_rows,0) > 0';
        } elseif ($hasItems === 'no') {
            $where[] = 'COALESCE(inv.total_rows,0) = 0';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int)$db->fetch_field($db->query("SELECT COUNT(*) AS c
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN (
                SELECT uid, COUNT(*) AS total_rows, COALESCE(SUM(qty),0) AS total_qty
                FROM " . TABLE_PREFIX . "af_inventory_items
                GROUP BY uid
            ) inv ON(inv.uid=u.uid)
            WHERE {$whereSql}"), 'c');
        $offset = ($page - 1) * $perPage;

        $q = $db->query("SELECT u.uid, u.username, COALESCE(inv.total_rows,0) AS total_rows, COALESCE(inv.total_qty,0) AS total_qty
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN (
                SELECT uid, COUNT(*) AS total_rows, COALESCE(SUM(qty),0) AS total_qty
                FROM " . TABLE_PREFIX . "af_inventory_items
                GROUP BY uid
            ) inv ON(inv.uid=u.uid)
            WHERE {$whereSql}
            ORDER BY u.username ASC
            LIMIT {$offset}, {$perPage}");

        $rows = '';
        while ($row = $db->fetch_array($q)) {
            $url = '../inventory.php?uid=' . (int)$row['uid'];
            $rows .= '<tr><td><a href="../member.php?action=profile&amp;uid=' . (int)$row['uid'] . '">' . htmlspecialchars_uni((string)$row['username']) . '</a></td><td>' . (int)$row['total_rows'] . '</td><td>' . (int)$row['total_qty'] . '</td><td><a class="button" href="' . htmlspecialchars_uni($url) . '">Открыть инвентарь</a></td></tr>';
        }

        $html = '';
        $html .= '<div class="af-box"><h2>Инвентари пользователей</h2>';
        $html .= '<form method="get"><input type="hidden" name="module" value="config-plugins"><input type="hidden" name="action" value="advancedinventory">';
        $html .= '<input type="text" name="username" placeholder="Username" value="' . htmlspecialchars_uni($search) . '"> ';
        $html .= '<select name="has_items"><option value="">Все</option><option value="yes"' . ($hasItems === 'yes' ? ' selected' : '') . '>Непустые</option><option value="no"' . ($hasItems === 'no' ? ' selected' : '') . '>Пустые</option></select> ';
        $html .= '<button type="submit" class="button">Фильтр</button></form>';
        $html .= '<table class="table"><thead><tr><th>Пользователь</th><th>Всего предметов</th><th>Всего qty</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '<p>Всего: ' . $total . '</p>';
        $html .= '</div>';
        return $html;
    }
}
