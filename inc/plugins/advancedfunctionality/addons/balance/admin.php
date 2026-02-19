<?php
if (!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

class AF_Admin_Balance
{
    public static function dispatch(): void
    {
        global $db, $mybb;

        require_once MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/balance/balance.php';

        $baseUrl = 'index.php?module=advancedfunctionality&af_view=balance';
        $view = (string)$mybb->get_input('balance_view');

        if ($view === 'tx') {
            $uid = (int)$mybb->get_input('uid');
            $kind = (string)$mybb->get_input('kind');
            $reason = trim((string)$mybb->get_input('reason'));
            $where = '1=1';
            if ($uid > 0) $where .= ' AND uid=' . $uid;
            if (in_array($kind, ['exp','credits'], true)) $where .= " AND kind='" . $db->escape_string($kind) . "'";
            if ($reason !== '') $where .= " AND reason='" . $db->escape_string($reason) . "'";
            echo '<h2>Balance transactions</h2><p><a href="' . htmlspecialchars_uni($baseUrl) . '">Settings</a></p>';
            $q = $db->simple_select(AF_BALANCE_TX_TABLE, '*', $where, ['order_by'=>'id', 'order_dir'=>'DESC', 'limit'=>50]);
            echo '<table class="general"><tr><th>ID</th><th>UID</th><th>Kind</th><th>Amount</th><th>After</th><th>Reason</th><th>Source</th><th>Date</th></tr>';
            while ($r = $db->fetch_array($q)) {
                echo '<tr><td>'.(int)$r['id'].'</td><td>'.(int)$r['uid'].'</td><td>'.htmlspecialchars_uni($r['kind']).'</td><td>'.(int)$r['amount'].'</td><td>'.(int)$r['balance_after'].'</td><td>'.htmlspecialchars_uni($r['reason']).'</td><td>'.htmlspecialchars_uni($r['source']).'</td><td>'.my_date('d.m.Y H:i', (int)$r['created_at']).'</td></tr>';
            }
            echo '</table>';
            return;
        }

        echo '<h2>Balance</h2>';
        echo '<p>Use MyBB settings group <strong>AF: Balance</strong> for EXP/Credits configuration.</p>';
        echo '<p><a href="' . htmlspecialchars_uni($baseUrl . '&balance_view=tx') . '">Transactions</a></p>';
    }
}
