<?php
/** Group metadata only. All content and delivery remain owned by MyBB PM. */
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) die('No direct access');

function af_advancedmessenger_install(): void { af_am_groups_schema(); }
function af_advancedmessenger_upgrade(): void { af_am_groups_schema(); }
function af_advancedmessenger_activate(): void { af_am_groups_schema(); }
function af_advancedmessenger_deactivate(): void { /* Retain PM and metadata. */ }
function af_advancedmessenger_uninstall(): void { /* Retain metadata; no implicit destructive removal. */ }

function af_am_groups_schema(): void
{
    global $db;
    static $ready = false;
    if ($ready) return;
    $pk = match ($db->type) {
        'pgsql' => 'SERIAL PRIMARY KEY',
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        default => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    };
    $suffix = in_array($db->type, ['mysql','mysqli'], true) ? ' ENGINE=InnoDB '.$db->build_create_table_collation() : '';
    $tables = [
        'af_am_groups' => "gid {$pk}, title VARCHAR(180) NOT NULL, creator INT NOT NULL, created INT NOT NULL, active INT NOT NULL DEFAULT 1",
        'af_am_members' => 'gid INT NOT NULL, uid INT NOT NULL, joined INT NOT NULL, active INT NOT NULL DEFAULT 1, PRIMARY KEY (gid,uid)',
        'af_am_links' => 'pmid INT NOT NULL UNIQUE, gid INT NOT NULL, PRIMARY KEY (gid,pmid)',
    ];
    foreach ($tables as $table => $fields) {
        if (!$db->table_exists($table)) {
            $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX.$table.' ('.$fields.')'.$suffix);
        }
    }
    $ready = true;
}

/** Every public group operation goes through current membership. */
function af_am_group(int $gid): ?array
{
    global $db, $mybb;
    if ($gid < 1) return null;
    $q = $db->query('SELECT g.* FROM '.TABLE_PREFIX.'af_am_groups g JOIN '.TABLE_PREFIX.'af_am_members m ON m.gid=g.gid'
        .' WHERE g.gid='.$gid.' AND g.active=1 AND m.active=1 AND m.uid='.(int)$mybb->user['uid']);
    return $db->fetch_array($q) ?: null;
}

function af_am_group_members(int $gid): array
{
    global $db;
    if (!af_am_group($gid)) error_no_permission();
    $q = $db->query('SELECT u.uid,u.username,u.avatar,u.avatartype FROM '.TABLE_PREFIX.'af_am_members m'
        .' JOIN '.TABLE_PREFIX.'users u ON u.uid=m.uid WHERE m.gid='.$gid.' AND m.active=1 ORDER BY u.username');
    $users = [];
    while ($row = $db->fetch_array($q)) $users[(int)$row['uid']] = $row;
    return $users;
}

/** One bounded metadata projection per request, never a lookup per bubble. */
function af_am_group_links(?int $after = null, int $anchor = 0): array
{
    global $db, $mybb;
    static $links;
    if ($links !== null) return $links;
    $links = [];
    $q = $db->query('SELECT l.pmid,g.gid,g.title FROM '.TABLE_PREFIX.'af_am_links l'
        .' JOIN '.TABLE_PREFIX.'af_am_groups g ON g.gid=l.gid AND g.active=1'
        .' JOIN '.TABLE_PREFIX.'af_am_members m ON m.gid=g.gid AND m.active=1 AND m.uid='.(int)$mybb->user['uid']
        .' JOIN '.TABLE_PREFIX.'privatemessages p ON p.pmid=l.pmid AND p.uid=m.uid'
        .($after !== null ? ' WHERE (l.pmid>'.max(0,$after).' OR l.pmid='.max(0,$anchor).')' : ''));
    while ($row = $db->fetch_array($q)) $links[(int)$row['pmid']] = $row;
    return $links;
}

function af_am_group_prepare(): void
{
    global $mybb, $db;
    $gid = $mybb->get_input('am_gid', MyBB::INPUT_INT);
    $GLOBALS['am_group_id'] = $gid;
    if (!$gid) return;
    $group = af_am_group($gid);
    if (!$group) error_no_permission();
    // Do not silently drop a deleted participant and perform partial delivery.
    $q = $db->simple_select('af_am_members', 'COUNT(*) AS n', 'gid='.$gid.' AND active=1');
    $count = (int)$db->fetch_field($q, 'n');
    $users = af_am_group_members($gid);
    if (count($users) !== $count) error(af_advancedmessenger_t('group_unavailable'));
    unset($users[(int)$mybb->user['uid']]);
    if (!$users) error(af_advancedmessenger_t('group_unavailable'));
    $limit = (int)$mybb->usergroup['maxpmrecipients'];
    if ($limit > 0 && count($users) > $limit) error(af_advancedmessenger_t('group_limit'));
    // Run before private.php resolves recipients, for submit AND preview. UID
    // membership is authoritative; editable form values cannot split a group.
    $mybb->input['to'] = implode(', ', array_column($users, 'username'));
    $mybb->input['bcc'] = '';
    $mybb->input['uid'] = 0;
    $mybb->input['options'] = $mybb->get_input('options', MyBB::INPUT_ARRAY);
    $mybb->input['options']['savecopy'] = 1;
    $GLOBALS['am_group_recipients'] = array_keys($users);
    $GLOBALS['am_group_title'] = $group['title'];
}

/** After native validation, force one outgoing copy and validate full membership. */
function af_am_group_runtime(): bool
{
    global $mybb;
    return defined('THIS_SCRIPT') && THIS_SCRIPT === 'private.php'
        && !empty($mybb->settings['af_advancedmessenger_enabled']) && !empty($mybb->user['uid'])
        && (!function_exists('af_frontend_asset_allowed') || af_frontend_asset_allowed('advancedmessenger', 'runtime'));
}

function af_am_group_validate(&$handler): void
{
    global $mybb;
    if (!af_am_group_runtime() || !$mybb->get_input('am_origin') || !empty($handler->data['saveasdraft'])) return;
    if (empty($handler->data['recipients'])) return;
    $ids = [];
    $bcc = false;
    foreach ($handler->data['recipients'] as $recipient) {
        if (!empty($recipient['bcc'])) $bcc = true;
        else $ids[] = (int)$recipient['uid'];
    }
    $ids = array_values(array_unique($ids));
    $gid = $mybb->get_input('am_gid', MyBB::INPUT_INT);
    if ($gid) {
        $expected = $GLOBALS['am_group_recipients'] ?? [];
        sort($expected); sort($ids);
        if (!af_am_group($gid) || $bcc || $ids !== $expected) error(af_advancedmessenger_t('group_unavailable'));
    }
    $others = array_values(array_diff($ids, [(int)$mybb->user['uid']]));
    if ($gid || (!$bcc && count($others) >= 2)) {
        // A group sender gets only their saved copy, never a duplicate inbox copy.
        unset($handler->data['recipients'][(int)$mybb->user['uid']]);
        $handler->data['options']['savecopy'] = 1;
    }
}

/** Collect exact insert IDs at the native commit hooks, including the saved copy. */
function af_am_group_capture(&$handler): void
{
    global $db, $mybb;
    if (!af_am_group_runtime() || !$mybb->get_input('am_origin')) return;
    $store = $GLOBALS['am_group_inserts'] ??= new SplObjectStorage();
    $ids = isset($store[$handler]) ? $store[$handler] : [];
    $ids[] = (int)$db->insert_id();
    $store[$handler] = $ids;
}

function af_am_group_sent(&$handler): void
{
    global $mybb, $db;
    if (!af_am_group_runtime() || !$mybb->get_input('am_origin') || empty($handler->return_values['messagesent'])) return;
    $store = $GLOBALS['am_group_inserts'] ?? null;
    if (!$store || !isset($store[$handler])) return;
    $ids = array_values(array_unique(array_filter($store[$handler])));
    unset($store[$handler]);
    $pm = $handler->data;
    $sender = (int)$pm['fromid'];
    if ($sender !== (int)$mybb->user['uid']) return;
    $members = [$sender];
    foreach ($pm['recipients'] as $recipient) {
        if (!empty($recipient['bcc'])) return; // BCC mail stays ordinary mass mail.
        $members[] = (int)$recipient['uid'];
    }
    $members = array_values(array_unique($members));
    $gid = $mybb->get_input('am_gid', MyBB::INPUT_INT);
    if (!$gid && count($members) < 3) return;
    if ($gid && !af_am_group($gid)) error_no_permission();
    if (!$gid) {
        $title = trim((string)$pm['subject']);
        if ($title === '' || $title === (string)($GLOBALS['lang']->pm_no_subject ?? '')) {
            $names = [];
            foreach ($pm['recipients'] as $r) $names[] = $r['username'];
            array_unshift($names, $mybb->user['username']);
            $title = implode(', ', $names);
        }
        $gid = (int)$db->insert_query('af_am_groups', ['title'=>$db->escape_string(my_substr($title,0,180)),
            'creator'=>$sender, 'created'=>TIME_NOW, 'active'=>1]);
        foreach ($members as $uid) $db->insert_query('af_am_members', ['gid'=>$gid,'uid'=>$uid,'joined'=>TIME_NOW,'active'=>1]);
    }
    foreach ($ids as $pmid) $db->insert_query('af_am_links', ['pmid'=>$pmid,'gid'=>$gid]);
    $GLOBALS['am_sent_pmid'] = 0;
    // Locate the sender's exact copy among captured IDs, never by text/time.
    $q = $db->simple_select('privatemessages', 'pmid', 'uid='.$sender.' AND folder=2 AND pmid IN ('.implode(',', $ids).')');
    $GLOBALS['am_sent_pmid'] = (int)$db->fetch_field($q, 'pmid');
}

/** Drafts retain group context, but never enter chronological group history. */
function af_am_group_draft(&$handler): void
{
    global $mybb, $db;
    $gid = $mybb->get_input('am_gid', MyBB::INPUT_INT);
    if (!af_am_group_runtime() || !$mybb->get_input('am_origin') || !$gid || !af_am_group($gid)) return;
    $pmid = (int)$handler->pmid;
    if ($pmid > 0) $db->insert_query('af_am_links', ['gid'=>$gid, 'pmid'=>$pmid]);
}

function af_am_group_header(int $gid, int $pmid, int $folder): string
{
    global $mybb;
    $group = af_am_group($gid);
    if (!$group) error_no_permission();
    $users = af_am_group_members($gid);
    $html = '<div class="am-peer"><span class="am-group-avatar"><i class="fa-solid fa-users" aria-hidden="true"></i></span>'
        .'<div class="am-peer__text"><h2 id="am-title">'.af_advancedmessenger_e($group['title']).'</h2>'
        .'<details class="am-group-members"><summary>'.count($users).' '.af_advancedmessenger_e(af_advancedmessenger_t('participants')).'</summary><div class="am-group-members__list">';
    foreach ($users as $user) $html .= '<a href="'.get_profile_link((int)$user['uid']).'">'.af_advancedmessenger_avatar($user).'<span>'.af_advancedmessenger_e($user['username']).'</span></a>';
    $html .= '</div></details></div></div><nav class="am-conversation-actions"><a id="am-latest" data-am-dialog="1" href="private.php?action=read&amp;pmid='.$pmid.'&amp;am_latest=1&amp;am_folder='.$folder.'">'.af_advancedmessenger_icon('latest').'</a>';
    if ((int)$group['creator'] === (int)$mybb->user['uid']) {
        $html .= '<details class="am-group-rename"><summary>'.af_advancedmessenger_icon('actions').'<span class="am-visually-hidden">'.af_advancedmessenger_e(af_advancedmessenger_t('group_rename')).'</span></summary><form method="post" action="private.php">'
            .'<input type="hidden" name="action" value="am_group_rename"><input type="hidden" name="my_post_key" value="'.af_advancedmessenger_e($mybb->post_code).'">'
            .'<input type="hidden" name="am_gid" value="'.$gid.'"><input type="hidden" name="pmid" value="'.$pmid.'">'
            .'<label>'.af_advancedmessenger_e(af_advancedmessenger_t('group_rename')).'<input name="am_group_title" maxlength="180" required value="'.af_advancedmessenger_e($group['title']).'"></label>'
            .'<button type="submit">'.af_advancedmessenger_e(af_advancedmessenger_t('group_save')).'</button></form></details>';
    }
    return $html.'</nav>';
}
