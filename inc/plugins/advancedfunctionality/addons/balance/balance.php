<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

const AF_BALANCE_TABLE = 'af_balance';
const AF_BALANCE_TX_TABLE = 'af_balance_tx';
const AF_BALANCE_EXP_SCALE = 100;
const AF_BALANCE_CREDITS_SCALE = 100;

function af_balance_is_installed(): bool { global $db; return $db->table_exists(AF_BALANCE_TABLE); }
function af_balance_install(): void { af_balance_ensure_schema(); af_balance_ensure_settings(); if (function_exists('rebuild_settings')) rebuild_settings(); }
function af_balance_activate(): bool { af_balance_ensure_schema(); af_balance_ensure_settings(); af_balance_migrate_from_charactersheets(); af_balance_migrate_credits_scale(); return true; }
function af_balance_deactivate(): bool { return true; }
function af_balance_uninstall(): void {}

function af_balance_init(): void
{
    global $plugins;
    $plugins->add_hook('datahandler_user_insert', 'af_balance_datahandler_user_insert');
    $plugins->add_hook('datahandler_post_insert_post', 'af_balance_datahandler_post_insert');
    $plugins->add_hook('datahandler_post_insert_thread', 'af_balance_datahandler_post_insert');
    $plugins->add_hook('member_do_register_end', 'af_balance_member_do_register_end');
    $plugins->add_hook('post_do_newpost_end', 'af_balance_post_do_newpost_end');
    $plugins->add_hook('misc_start', 'af_balance_misc_start');
}

function af_balance_ensure_schema(): void
{
    global $db;
    if (!$db->table_exists(AF_BALANCE_TABLE)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_BALANCE_TABLE . " (
            uid INT UNSIGNED NOT NULL,
            exp BIGINT NOT NULL DEFAULT 0,
            credits BIGINT NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists(AF_BALANCE_TX_TABLE)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT UNSIGNED NOT NULL,
            kind ENUM('exp','credits') NOT NULL,
            amount BIGINT NOT NULL,
            balance_after BIGINT NOT NULL,
            reason VARCHAR(64) NOT NULL,
            source VARCHAR(64) NOT NULL,
            ref_type VARCHAR(32) NOT NULL DEFAULT '',
            ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meta_json MEDIUMTEXT NULL,
            actor_uid INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY uid_kind (uid, kind),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

function af_balance_ensure_settings(): void
{
    global $db;
    $needsRebuild = false;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_balance'", ['limit' => 1]), 'gid');
    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name' => 'af_balance', 'title' => 'AF: Balance', 'description' => 'EXP and Credits settings', 'disporder' => 60, 'isdefault' => 0,
        ]);
    }
    $defs = [
        ['af_balance_exp_enabled','EXP enabled','yesno','1',1],['af_balance_exp_per_char','EXP per char','text','0.02',2],['af_balance_exp_on_register','EXP on register','text','0',3],['af_balance_exp_on_accept','EXP on accept','text','0',4],
        ['af_balance_exp_categories_csv','EXP categories CSV','text','',5],['af_balance_exp_forums_csv','EXP forums CSV','text','',6],['af_balance_exp_mode','EXP mode',"select\ninclude=include\nexclude=exclude",'include',7],
        ['af_balance_exp_allow_negative_award','EXP allow negative award','yesno','0',8],['af_balance_exp_allow_balance_negative','EXP allow negative balance','yesno','0',9],['af_balance_exp_manual_groups','EXP manual groups','text','4',10],
        ['af_balance_credits_enabled','Credits enabled','yesno','1',20],['af_balance_credits_per_char','Credits per char','text','0',21],['af_balance_credits_on_register','Credits on register','text','0',22],['af_balance_credits_on_accept','Credits on accept','text','0',23],
        ['af_balance_credits_categories_csv','Credits categories CSV','text','',24],['af_balance_credits_forums_csv','Credits forums CSV','text','',25],['af_balance_credits_mode','Credits mode',"select\ninclude=include\nexclude=exclude",'include',26],
        ['af_balance_credits_allow_negative_award','Credits allow negative award','yesno','0',27],['af_balance_credits_allow_balance_negative','Credits allow negative balance','yesno','0',28],['af_balance_credits_manual_groups','Credits manual groups','text','4',29],
        ['af_balance_tx_keep_limit','TX keep limit','text','5000',30],['af_balance_tx_enable','Enable tx log','yesno','1',31],
        ['af_balance_credits_scale_migrated','Credits scale migrated','yesno','0',32],
        ['af_balance_currency_symbol','Currency symbol','text','¢',33],
        ['af_balance_manage_groups','Balance manage groups','text','3,4',34],
    ];
    $legacyMigratedSid = af_balance_pick_setting_sid('af_balance_migrated_credits_scale', $needsRebuild);
    if ($legacyMigratedSid > 0) {
        $legacyMigratedValue = $db->fetch_field($db->simple_select('settings', 'value', 'sid=' . $legacyMigratedSid, ['limit' => 1]), 'value');
        $targetSid = af_balance_pick_setting_sid('af_balance_credits_scale_migrated', $needsRebuild);
        if ($targetSid > 0) {
            if ($legacyMigratedValue !== null && $legacyMigratedValue !== '') {
                $db->update_query('settings', ['value' => $legacyMigratedValue], 'sid=' . $targetSid);
            }
            $db->delete_query('settings', 'sid=' . $legacyMigratedSid);
            $needsRebuild = true;
        } else {
            $db->update_query('settings', ['name' => 'af_balance_credits_scale_migrated'], 'sid=' . $legacyMigratedSid);
            $needsRebuild = true;
        }
    }

    foreach ($defs as [$name,$title,$opt,$val,$order]) {
        $sid = af_balance_pick_setting_sid($name, $needsRebuild);
        $row = ['name'=>$name,'title'=>$title,'description'=>$title,'optionscode'=>$opt,'disporder'=>$order,'gid'=>$gid,'isdefault'=>0];
        if ($sid > 0) {
            $db->update_query('settings',$row,"sid=".$sid);
        } else {
            $row['value'] = $val;
            $db->insert_query('settings',$row);
            $needsRebuild = true;
        }
    }

    if ($needsRebuild && function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_balance_pick_setting_sid(string $name, bool &$deduped = false): int
{
    global $db;

    $escaped = $db->escape_string($name);
    $q = $db->simple_select('settings', 'sid', "name='" . $escaped . "'", ['order_by' => 'sid', 'order_dir' => 'ASC']);

    $keepSid = 0;
    while ($row = $db->fetch_array($q)) {
        $sid = (int)($row['sid'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if ($keepSid === 0) {
            $keepSid = $sid;
            continue;
        }
        $db->delete_query('settings', 'sid=' . $sid);
        $deduped = true;
    }

    return $keepSid;
}

function af_balance_get(int $uid): array
{
    global $db;
    if ($uid <= 0) return ['uid'=>0,'exp'=>0,'credits'=>0];
    $row = $db->fetch_array($db->simple_select(AF_BALANCE_TABLE, '*', 'uid='.(int)$uid, ['limit'=>1]));
    if (!$row) {
        $db->insert_query(AF_BALANCE_TABLE, ['uid'=>$uid, 'exp'=>0, 'credits'=>0, 'updated_at'=>TIME_NOW]);
        return ['uid'=>$uid,'exp'=>0,'credits'=>0];
    }
    return ['uid'=>$uid,'exp'=>(int)$row['exp'],'credits'=>(int)$row['credits']];
}

function af_balance_add($uid, string $kind, $amount, array $meta = []): array
{
    global $db, $mybb;
    $uid = (int)$uid;
    if ($uid <= 0 || !in_array($kind, ['exp','credits'], true)) return af_balance_get($uid);
    $is_exp = $kind === 'exp';
    $scale = $is_exp ? AF_BALANCE_EXP_SCALE : AF_BALANCE_CREDITS_SCALE;
    $scaled = (int)floor(((float)$amount) * $scale);
    if ($scaled < 0 && empty($mybb->settings['af_balance_'.$kind.'_allow_negative_award'])) return af_balance_get($uid);

    $bal = af_balance_get($uid);
    $old = (int)$bal[$kind];
    $new = $old + $scaled;
    if ($new < 0 && empty($mybb->settings['af_balance_'.$kind.'_allow_balance_negative'])) $new = 0;

    $db->update_query(AF_BALANCE_TABLE, [$kind => $new, 'updated_at'=>TIME_NOW], 'uid=' . $uid);

    if (!empty($mybb->settings['af_balance_tx_enable']) && $db->table_exists(AF_BALANCE_TX_TABLE)) {
        $reason = substr((string)($meta['reason'] ?? ''), 0, 64);
        $source = substr((string)($meta['source'] ?? 'balance'), 0, 64);
        $refType = substr((string)($meta['ref_type'] ?? ''), 0, 32);
        $db->insert_query(AF_BALANCE_TX_TABLE, [
            'uid' => $uid, 'kind' => $db->escape_string($kind), 'amount' => ($new - $old), 'balance_after' => $new,
            'reason' => $db->escape_string($reason), 'source' => $db->escape_string($source),
            'ref_type' => $db->escape_string($refType), 'ref_id' => (int)($meta['ref_id'] ?? 0),
            'meta_json' => $db->escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'actor_uid' => (int)($meta['actor_uid'] ?? 0), 'created_at' => TIME_NOW,
        ]);
        af_balance_trim_tx();
    }

    if ($is_exp && function_exists('af_charactersheets_on_exp_changed')) {
        af_charactersheets_on_exp_changed($uid, $old, $new, $meta);
    }

    return af_balance_get($uid);
}

function af_balance_add_exp($uid, $amount, array $meta = []): array { return af_balance_add((int)$uid, 'exp', $amount, $meta); }
function af_balance_add_credits($uid, $amount, array $meta = []): array { return af_balance_add((int)$uid, 'credits', $amount, $meta); }

function af_balance_can_manual_adjust($uid, string $kind): bool
{
    global $mybb;
    $kind = $kind === 'credits' ? 'credits' : 'exp';
    $csv = (string)($mybb->settings['af_balance_'.$kind.'_manual_groups'] ?? '');
    $allowed = af_balance_csv_to_ids($csv);
    if (!$allowed) return false;
    $groups = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) { $groups[] = (int)$g; }
    return (bool)array_intersect(array_filter($groups), $allowed);
}

function af_balance_member_do_register_end(): void
{
    global $mybb, $user_info;
    $uid = (int)($user_info['uid'] ?? 0);
    if ($uid <= 0) {
        $uid = (int)($mybb->input['regid'] ?? 0);
    }
    if ($uid <= 0) return;
    af_balance_apply_register_awards($uid);
}

function af_balance_datahandler_user_insert($userhandler): void
{
    if (!is_object($userhandler)) {
        return;
    }

    $uid = (int)($userhandler->uid ?? 0);
    if ($uid <= 0) {
        $uid = (int)($userhandler->data['uid'] ?? 0);
    }
    if ($uid <= 0) {
        return;
    }

    af_balance_apply_register_awards($uid);
}

function af_balance_apply_register_awards(int $uid): void
{
    global $mybb;

    if (!empty($mybb->settings['af_balance_exp_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_exp_on_register'] ?? 0);
        if ($amt != 0.0) af_balance_add_exp($uid, $amt, ['reason'=>'register','source'=>'balance','ref_type'=>'uid','ref_id'=>$uid]);
    }
    if (!empty($mybb->settings['af_balance_credits_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_credits_on_register'] ?? 0);
        if ($amt != 0.0) af_balance_add_credits($uid, $amt, ['reason'=>'register','source'=>'balance','ref_type'=>'uid','ref_id'=>$uid]);
    }
}

function af_balance_datahandler_post_insert($posthandler): void
{
    if (!is_object($posthandler)) {
        return;
    }

    $insert = is_array($posthandler->post_insert_data ?? null) ? $posthandler->post_insert_data : [];
    $data = is_array($posthandler->data ?? null) ? $posthandler->data : [];

    $uid = (int)($insert['uid'] ?? $data['uid'] ?? 0);
    $fid = (int)($insert['fid'] ?? $data['fid'] ?? 0);
    $visible = (int)($insert['visible'] ?? $data['visible'] ?? 0);
    $pid = (int)($insert['pid'] ?? 0);
    if ($pid <= 0) {
        $pid = (int)($posthandler->pid ?? 0);
    }
    $message = (string)($insert['message'] ?? $data['message'] ?? '');

    af_balance_award_for_post($uid, $fid, $message, $visible, $pid);
}

function af_balance_post_do_newpost_end(): void
{
    global $mybb, $db, $pid, $newpid;
    $resolved_pid = (int)($pid ?? $newpid ?? $mybb->input['pid'] ?? 0);
    if ($resolved_pid <= 0) return;
    $post = $db->fetch_array($db->simple_select('posts', 'pid,uid,fid,message,visible', 'pid=' . $resolved_pid, ['limit' => 1]));
    if (!$post) return;
    af_balance_award_for_post((int)$post['uid'], (int)$post['fid'], (string)($post['message'] ?? ''), (int)$post['visible'], $resolved_pid);
}

function af_balance_award_for_post(int $uid, int $fid, string $message, int $visible, int $pid = 0): void
{
    global $mybb;
    if ($visible !== 1 || $uid <= 0) {
        return;
    }

    $chars = function_exists('my_strlen') ? my_strlen($message) : strlen($message);
    if ($chars <= 0) {
        return;
    }

    if (!empty($mybb->settings['af_balance_exp_enabled']) && af_balance_is_forum_allowed($fid, 'exp')) {
        $rate = (float)($mybb->settings['af_balance_exp_per_char'] ?? 0);
        if ($rate != 0.0) af_balance_add_exp($uid, $chars * $rate, ['reason'=>'post_chars','source'=>'balance','ref_type'=>'pid','ref_id'=>$pid,'fid'=>$fid,'chars'=>$chars]);
    }
    if (!empty($mybb->settings['af_balance_credits_enabled']) && af_balance_is_forum_allowed($fid, 'credits')) {
        $rate = (float)($mybb->settings['af_balance_credits_per_char'] ?? 0);
        if ($rate != 0.0) af_balance_add_credits($uid, $chars * $rate, ['reason'=>'post_chars','source'=>'balance','ref_type'=>'pid','ref_id'=>$pid,'fid'=>$fid,'chars'=>$chars]);
    }
}

function af_balance_handle_accept(int $uid, int $tid, int $actor_uid = 0): void
{
    global $mybb;
    if ($uid <= 0) return;
    if (!empty($mybb->settings['af_balance_exp_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_exp_on_accept'] ?? 0);
        if ($amt != 0.0) af_balance_add_exp($uid, $amt, ['reason'=>'accept','source'=>'balance','actor_uid'=>$actor_uid,'ref_type'=>'tid','ref_id'=>$tid]);
    }
    if (!empty($mybb->settings['af_balance_credits_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_credits_on_accept'] ?? 0);
        if ($amt != 0.0) af_balance_add_credits($uid, $amt, ['reason'=>'accept','source'=>'balance','actor_uid'=>$actor_uid,'ref_type'=>'tid','ref_id'=>$tid]);
    }
}

function af_balance_csv_to_ids(string $csv): array { $out=[]; foreach (explode(',', $csv) as $p){$id=(int)trim($p); if($id>0)$out[$id]=$id;} return array_values($out);} 
function af_balance_expand_forum_ids_with_children(array $ids): array
{ global $db; if(!$ids)return[]; $all=$ids; $pending=$ids; while($pending){ $pid=array_pop($pending); $q=$db->simple_select('forums','fid','pid='.(int)$pid); while($r=$db->fetch_array($q)){ $fid=(int)$r['fid']; if($fid>0 && !in_array($fid,$all,true)){ $all[]=$fid; $pending[]=$fid; } } } return $all; }
function af_balance_is_forum_allowed(int $fid, string $kind): bool
{
    global $mybb; if ($fid<=0) return false;
    $mode = (string)($mybb->settings['af_balance_'.$kind.'_mode'] ?? 'include');
    $cats = af_balance_csv_to_ids((string)($mybb->settings['af_balance_'.$kind.'_categories_csv'] ?? ''));
    $forums = af_balance_csv_to_ids((string)($mybb->settings['af_balance_'.$kind.'_forums_csv'] ?? ''));
    $selected = array_values(array_unique(array_merge($forums, af_balance_expand_forum_ids_with_children($cats))));
    if ($mode === 'exclude') return !$selected || !in_array($fid, $selected, true);
    return !$selected || in_array($fid, $selected, true);
}

function af_balance_trim_tx(): void
{ global $db, $mybb; $limit=(int)($mybb->settings['af_balance_tx_keep_limit'] ?? 0); if($limit<=0)return; $cnt=(int)$db->fetch_field($db->simple_select(AF_BALANCE_TX_TABLE,'COUNT(*) AS c'),'c'); if($cnt <= $limit)return; $over=$cnt-$limit; $db->write_query("DELETE FROM ".TABLE_PREFIX.AF_BALANCE_TX_TABLE." ORDER BY id ASC LIMIT ".(int)$over); }

function af_balance_format_exp(int $scaledExp): string
{
    return number_format($scaledExp / AF_BALANCE_EXP_SCALE, 2, '.', ' ');
}

function af_balance_format_credits(int $scaledCredits): string
{
    return number_format($scaledCredits / AF_BALANCE_CREDITS_SCALE, 2, '.', ' ');
}

function af_balance_migrate_credits_scale(): void
{
    global $db, $mybb;
    if ((int)($mybb->settings['af_balance_credits_scale_migrated'] ?? 0) === 1) {
        return;
    }

    if ($db->table_exists(AF_BALANCE_TABLE)) {
        $db->write_query('UPDATE ' . TABLE_PREFIX . AF_BALANCE_TABLE . ' SET credits=credits*' . AF_BALANCE_CREDITS_SCALE);
    }
    if ($db->table_exists(AF_BALANCE_TX_TABLE)) {
        $db->write_query("UPDATE " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " SET amount=amount*" . AF_BALANCE_CREDITS_SCALE . ", balance_after=balance_after*" . AF_BALANCE_CREDITS_SCALE . " WHERE kind='credits'");
    }
    $db->update_query('settings', ['value' => '1'], "name='af_balance_credits_scale_migrated'");
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_balance_can_manage(): bool
{
    global $mybb;
    $allowed = af_balance_csv_to_ids((string)($mybb->settings['af_balance_manage_groups'] ?? '3,4'));
    $groups = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) {
        $groups[] = (int)$g;
    }
    return (bool)array_intersect(array_filter($groups), $allowed);
}

function af_balance_misc_start(): void
{
    global $mybb;
    if ((string)$mybb->get_input('action') !== 'balance_manage') {
        return;
    }
    if (!af_balance_can_manage()) {
        error_no_permission();
    }
    if ((string)$mybb->get_input('do') === 'adjust') {
        af_balance_handle_manage_adjust();
    }
    af_balance_render_manage_page();
    exit;
}

function af_balance_handle_manage_adjust(): void
{
    global $mybb;
    verify_post_check($mybb->get_input('my_post_key'));
    $uid = (int)$mybb->get_input('uid');
    $kind = (string)$mybb->get_input('kind');
    $op = (string)$mybb->get_input('op');
    $amount = (float)$mybb->get_input('amount');
    $reason = trim((string)$mybb->get_input('reason'));
    if (!in_array($kind, ['exp', 'credits'], true) || $uid <= 0 || $amount <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Bad request']);
        exit;
    }
    $signed = $op === 'sub' ? -$amount : $amount;
    $meta = ['reason' => $reason !== '' ? $reason : 'manual_adjust', 'source' => 'balance_manage', 'actor_uid' => (int)($mybb->user['uid'] ?? 0), 'ref_type' => 'uid', 'ref_id' => $uid];
    $bal = $kind === 'exp' ? af_balance_add_exp($uid, $signed, $meta) : af_balance_add_credits($uid, $signed, $meta);
    $expFloat = ((int)$bal['exp']) / AF_BALANCE_EXP_SCALE;
    $levelData = function_exists('af_charactersheets_compute_level') ? af_charactersheets_compute_level($expFloat) : ['level' => 1, 'percent' => 0];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'uid' => $uid,
        'kind' => $kind,
        'exp_display' => af_balance_format_exp((int)$bal['exp']),
        'credits_display' => af_balance_format_credits((int)$bal['credits']),
        'level' => (int)($levelData['level'] ?? 1),
        'progress_percent' => (int)($levelData['percent'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_balance_migrate_from_charactersheets(): void
{
    global $db;
    if (!$db->table_exists('af_cs_sheets') || !$db->table_exists(AF_BALANCE_TABLE)) return;
    $q = $db->simple_select('af_cs_sheets', 'uid, progress_json', 'uid IS NOT NULL AND uid>0');
    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        if ($uid <= 0) continue;
        $existing = af_balance_get($uid);
        if ((int)$existing['exp'] !== 0) continue;
        $progress = json_decode((string)($row['progress_json'] ?? ''), true);
        $expLegacy = (float)($progress['exp'] ?? 0);
        if ($expLegacy > 0) {
            $scaled = (int)floor($expLegacy * AF_BALANCE_EXP_SCALE);
            $db->update_query(AF_BALANCE_TABLE, ['exp' => $scaled, 'updated_at' => TIME_NOW], 'uid=' . $uid);
        }
    }
}


function af_balance_render_manage_page(): void
{
    global $db, $mybb, $templates, $headerinclude, $header, $footer, $theme;

    $kind = (string)$mybb->get_input('kind');
    if (!in_array($kind, ['exp', 'credits'], true)) $kind = 'exp';
    $q = trim((string)$mybb->get_input('q'));
    $race = trim((string)$mybb->get_input('race'));

    $where = 'u.uid>0';
    if ($q !== '') {
        $where .= " AND u.username LIKE '%" . $db->escape_string_like($q) . "%'";
    }

    $rows = [];
    $sql = "SELECT u.uid,u.username,u.avatar,b.exp,b.credits,s.progress_json FROM " . TABLE_PREFIX . "users u "
        . "LEFT JOIN " . TABLE_PREFIX . AF_BALANCE_TABLE . " b ON b.uid=u.uid "
        . "LEFT JOIN " . TABLE_PREFIX . "af_cs_sheets s ON s.uid=u.uid "
        . "WHERE " . $where . " ORDER BY u.username ASC LIMIT 200";
    $res = $db->query($sql);
    while ($row = $db->fetch_array($res)) {
        $progress = json_decode((string)($row['progress_json'] ?? ''), true);
        $rowRace = trim((string)($progress['race'] ?? ''));
        if ($race !== '' && mb_stripos($rowRace, $race) === false) {
            continue;
        }
        $uid = (int)$row['uid'];
        $exp = (int)($row['exp'] ?? 0);
        $credits = (int)($row['credits'] ?? 0);
        $levelData = function_exists('af_charactersheets_compute_level') ? af_charactersheets_compute_level($exp / AF_BALANCE_EXP_SCALE) : ['level' => 1];
        $avatar = trim((string)($row['avatar'] ?? ''));
        if ($avatar === '') $avatar = 'images/default_avatar.png';
        $rows[] = '<tr data-af-balance-row="' . $uid . '"><td><img src="' . htmlspecialchars_uni($avatar) . '" width="34" height="34" style="border-radius:50%"></td>'
            . '<td><a href="member.php?action=profile&uid=' . $uid . '">' . htmlspecialchars_uni((string)$row['username']) . '</a><div class="smalltext">' . htmlspecialchars_uni($rowRace) . '</div></td>'
            . '<td data-af-balance-exp>' . af_balance_format_exp($exp) . '</td>'
            . '<td data-af-balance-credits>' . af_balance_format_credits($credits) . '</td>'
            . '<td data-af-balance-level>' . (int)($levelData['level'] ?? 1) . '</td>'
            . '<td><button type="button" class="button" data-af-balance-adjust="1" data-uid="' . $uid . '">Начислить</button></td></tr>';
    }

    $rows_html = $rows ? implode('', $rows) : '<tr><td colspan="6">Нет результатов</td></tr>';
    $kind_exp_active = $kind === 'exp' ? 'is-active' : '';
    $kind_credits_active = $kind === 'credits' ? 'is-active' : '';
    $currency_symbol = htmlspecialchars_uni((string)($mybb->settings['af_balance_currency_symbol'] ?? '¢'));
    $my_post_key = htmlspecialchars_uni($mybb->post_code);
    eval('$page = "' . $templates->get('balance') . '";');
    output_page($page);
}
