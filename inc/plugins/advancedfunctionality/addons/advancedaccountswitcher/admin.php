<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_AdvancedAccountSwitcher
{
    public static function dispatch()
    {
        global $mybb, $lang;

        // гарантированно грузим язык аддона в ACP
        if (is_object($lang)) {
            $lang->load('advancedfunctionality_advancedaccountswitcher', true, true);
        }

        // AF роутер использует af_view=advancedaccountswitcher как идентификатор аддона,
        // поэтому внутренние вкладки гоняем через af_sub
        $sub = (string)($mybb->input['af_sub'] ?? 'overview');

        if ($sub === 'logs') {
            self::render_logs();
            return;
        }

        if ($sub === 'switches') {
            self::render_switches();
            return;
        }

        self::render_overview();
    }

    private static function t(string $key, string $fallback): string
    {
        global $lang;
        if (is_object($lang) && isset($lang->$key) && trim((string)$lang->$key) !== '') {
            return (string)$lang->$key;
        }
        return $fallback;
    }

    private static function render_tabs(string $active = 'overview'): void
    {
        $base = 'index.php?module=advancedfunctionality&af_view=advancedaccountswitcher';

        $tabLinks    = self::t('af_aas_admin_tab_links', 'Links');
        $tabAudit    = self::t('af_aas_admin_tab_audit', 'Action logs');
        $tabSwitches = self::t('af_aas_admin_tab_switches', 'Switch logs');

        echo '<div style="margin:10px 0 14px;">';
        echo '<a class="button'.($active==='overview'?' active':'').'" href="'.$base.'">'.htmlspecialchars_uni($tabLinks).'</a> ';
        echo '<a class="button'.($active==='logs'?' active':'').'" href="'.$base.'&af_sub=logs">'.htmlspecialchars_uni($tabAudit).'</a> ';
        echo '<a class="button'.($active==='switches'?' active':'').'" href="'.$base.'&af_sub=switches">'.htmlspecialchars_uni($tabSwitches).'</a>';
        echo '</div>';
    }

    private static function render_overview()
    {
        global $mybb, $db;

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

        echo '<h2>'.htmlspecialchars_uni(self::t('af_aas_admin_title', 'Advanced Account Switcher')).'</h2>';
        self::render_tabs('overview');

        $ph  = self::t('af_aas_admin_search_placeholder', 'Search by username...');
        $btn = self::t('af_aas_admin_btn_search', 'Search');

        echo '<form method="get" action="index.php" style="margin-bottom:10px;">
            <input type="hidden" name="module" value="advancedfunctionality">
            <input type="hidden" name="af_view" value="advancedaccountswitcher">
            <input type="hidden" name="af_sub" value="overview">
            <input type="text" name="q" value="' . htmlspecialchars_uni($q) . '" class="textbox" placeholder="'.htmlspecialchars_uni($ph).'">
            <button class="button">'.htmlspecialchars_uni($btn).'</button>
        </form>';

        echo '<table class="general" cellspacing="0" cellpadding="4" width="100%">';
        echo '<tr>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_id', 'ID')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_master', 'Master')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_attached', 'Attached')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_date', 'Date')).'</th>
        </tr>';

        foreach ($rows as $r) {
            $d = !empty($r['created_at']) ? my_date('relative', (int)$r['created_at']) : '-';
            echo '<tr>
                <td>' . (int)$r['id'] . '</td>
                <td><a href="../member.php?action=profile&uid=' . (int)$r['master_uid'] . '" target="_blank">' . htmlspecialchars_uni($r['master_name'] ?? ('#'.$r['master_uid'])) . '</a></td>
                <td><a href="../member.php?action=profile&uid=' . (int)$r['attached_uid'] . '" target="_blank">' . htmlspecialchars_uni($r['attached_name'] ?? ('#'.$r['attached_uid'])) . '</a></td>
                <td>' . $d . '</td>
            </tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="4"><em>'.htmlspecialchars_uni(self::t('af_aas_admin_empty_links', 'No links yet.')).'</em></td></tr>';
        }

        echo '</table>';
        echo '<p class="smalltext">'.htmlspecialchars_uni(self::t('af_aas_admin_note_links', 'Showing last 200 links (performance limit).')).'</p>';
    }

    private static function render_logs()
    {
        global $mybb, $db;

        $q      = trim((string)($mybb->input['q'] ?? ''));
        $action = trim((string)($mybb->input['act'] ?? ''));

        $where = "1=1";
        if ($action !== '') {
            $where .= " AND l.action='" . $db->escape_string($action) . "'";
        }
        if ($q !== '') {
            $like = $db->escape_string_like($q);
            $where .= " AND (
                ua.username LIKE '{$like}%'
                OR um.username LIKE '{$like}%'
                OR ut.username LIKE '{$like}%'
            )";
        }

        $rows = [];
        $res = $db->query("
            SELECT
                l.id, l.action, l.actor_uid, l.master_uid, l.attached_uid, l.ip, l.created_at,
                ua.username AS actor_name,
                um.username AS master_name,
                ut.username AS attached_name
            FROM " . TABLE_PREFIX . "af_aas_audit_log l
            LEFT JOIN " . TABLE_PREFIX . "users ua ON (ua.uid = l.actor_uid)
            LEFT JOIN " . TABLE_PREFIX . "users um ON (um.uid = l.master_uid)
            LEFT JOIN " . TABLE_PREFIX . "users ut ON (ut.uid = l.attached_uid)
            WHERE {$where}
            ORDER BY l.id DESC
            LIMIT 200
        ");

        while ($r = $db->fetch_array($res)) {
            $rows[] = $r;
        }

        echo '<h2>'.htmlspecialchars_uni(self::t('af_aas_admin_audit_title', 'Advanced Account Switcher — Action logs')).'</h2>';
        self::render_tabs('logs');

        $ph       = self::t('af_aas_admin_search_placeholder', 'Search by username...');
        $btn      = self::t('af_aas_admin_btn_filter', 'Filter');
        $optAny   = self::t('af_aas_admin_filter_action_any', '— action —');

        $optCreate = self::t('af_aas_admin_filter_action_create', 'Create');
        $optLink   = self::t('af_aas_admin_filter_action_link', 'Link');
        $optUnlink = self::t('af_aas_admin_filter_action_unlink', 'Unlink');

        echo '<form method="get" action="index.php" style="margin-bottom:10px;">
            <input type="hidden" name="module" value="advancedfunctionality">
            <input type="hidden" name="af_view" value="advancedaccountswitcher">
            <input type="hidden" name="af_sub" value="logs">

            <input type="text" name="q" value="' . htmlspecialchars_uni($q) . '" class="textbox" placeholder="'.htmlspecialchars_uni($ph).'">

            <select name="act" class="textbox">
                <option value="">'.htmlspecialchars_uni($optAny).'</option>
                <option value="create"'.($action==='create'?' selected':'').'>'.htmlspecialchars_uni($optCreate).'</option>
                <option value="link"'.($action==='link'?' selected':'').'>'.htmlspecialchars_uni($optLink).'</option>
                <option value="unlink"'.($action==='unlink'?' selected':'').'>'.htmlspecialchars_uni($optUnlink).'</option>
            </select>

            <button class="button">'.htmlspecialchars_uni($btn).'</button>
        </form>';

        echo '<table class="general" cellspacing="0" cellpadding="4" width="100%">';
        echo '<tr>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_id', 'ID')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_action', 'Action')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_actor', 'Actor')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_master', 'Master')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_attached', 'Attached')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_ip', 'IP')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_when', 'When')).'</th>
        </tr>';

        foreach ($rows as $r) {
            $d = !empty($r['created_at']) ? my_date('relative', (int)$r['created_at']) : '-';

            $actorUid    = (int)($r['actor_uid'] ?? 0);
            $masterUid   = (int)($r['master_uid'] ?? 0);
            $attachedUid = (int)($r['attached_uid'] ?? 0);

            $actorLabel    = htmlspecialchars_uni($r['actor_name'] ?? ('#'.$actorUid));
            $masterLabel   = htmlspecialchars_uni($r['master_name'] ?? ('#'.$masterUid));
            $attachedLabel = htmlspecialchars_uni($r['attached_name'] ?? ('#'.$attachedUid));

            $actorHtml    = $actorUid > 0 ? '<a href="../member.php?action=profile&uid='.$actorUid.'" target="_blank">'.$actorLabel.'</a>' : $actorLabel;
            $masterHtml   = $masterUid > 0 ? '<a href="../member.php?action=profile&uid='.$masterUid.'" target="_blank">'.$masterLabel.'</a>' : $masterLabel;
            $attachedHtml = $attachedUid > 0 ? '<a href="../member.php?action=profile&uid='.$attachedUid.'" target="_blank">'.$attachedLabel.'</a>' : $attachedLabel;

            echo '<tr>
                <td>' . (int)$r['id'] . '</td>
                <td>' . htmlspecialchars_uni((string)($r['action'] ?? '')) . '</td>
                <td>' . $actorHtml . '</td>
                <td>' . $masterHtml . '</td>
                <td>' . $attachedHtml . '</td>
                <td>' . htmlspecialchars_uni((string)($r['ip'] ?? '')) . '</td>
                <td>' . $d . '</td>
            </tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="7"><em>'.htmlspecialchars_uni(self::t('af_aas_admin_empty_audit', 'No action logs yet.')).'</em></td></tr>';
        }

        echo '</table>';
        echo '<p class="smalltext">'.htmlspecialchars_uni(self::t('af_aas_admin_note_audit', 'Showing last 200 records. Max 5000 kept, old ones are pruned automatically.')).'</p>';
    }

    private static function render_switches()
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

        echo '<h2>'.htmlspecialchars_uni(self::t('af_aas_admin_switches_title', 'Advanced Account Switcher — Switch logs')).'</h2>';
        self::render_tabs('switches');

        $thFrom = self::t('af_aas_admin_th_from', 'From');
        $thTo   = self::t('af_aas_admin_th_to', 'To');

        echo '<table class="general" cellspacing="0" cellpadding="4" width="100%">';
        echo '<tr>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_id', 'ID')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_actor', 'Actor')).'</th>
            <th>'.htmlspecialchars_uni($thFrom).'</th>
            <th>'.htmlspecialchars_uni($thTo).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_ip', 'IP')).'</th>
            <th>'.htmlspecialchars_uni(self::t('af_aas_admin_th_when', 'When')).'</th>
        </tr>';

        foreach ($rows as $r) {
            $d = !empty($r['created_at']) ? my_date('relative', (int)$r['created_at']) : '-';
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
            echo '<tr><td colspan="6"><em>'.htmlspecialchars_uni(self::t('af_aas_admin_empty_switches', 'No switch logs yet.')).'</em></td></tr>';
        }

        echo '</table>';
        echo '<p class="smalltext">'.htmlspecialchars_uni(self::t('af_aas_admin_note_switches', 'Showing last 200 switches.')).'</p>';
    }
}
