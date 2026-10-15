<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** A conservative projection: recipient lists are never returned to the browser. */
function af_advancedmessenger_identity(array $row, int $owner): string
{
    $id = (int)$row['pmid'];
    $link = af_am_group_links()[$id] ?? null;
    if ($link && (int)$row['folder'] !== 3) return 'group:'.(int)$link['gid'];
    if ((int)$row['folder'] === 3 || (int)$row['fromid'] < 1) return 'message:'.$id;
    $recipients = is_array($row['recipients'] ?? null)
        ? $row['recipients'] : my_unserialize((string)($row['recipients'] ?? ''));
    if (!is_array($recipients) || !empty($recipients['bcc'])
        || !isset($recipients['to']) || !is_array($recipients['to'])) return 'message:'.$id;
    $to = array_values(array_unique(array_map('intval', $recipients['to'])));
    if (count($to) !== 1 || $to[0] < 1 || $to[0] !== (int)$row['toid']) return 'message:'.$id;
    if ((int)$row['fromid'] === $owner) return 'user:'.$to[0];
    if ($to[0] === $owner) return 'user:'.(int)$row['fromid'];
    return 'message:'.$id;
}

/** Visible reply-all participants only; never read or merge the BCC list. */
function af_advancedmessenger_reply_all_ids(array $row, int $owner): array
{
    $recipients = is_array($row['recipients'] ?? null)
        ? $row['recipients'] : my_unserialize((string)($row['recipients'] ?? ''));
    $visible = is_array($recipients['to'] ?? null) ? $recipients['to'] : [];
    $visible[] = (int)($row['fromid'] ?? 0);
    return array_values(array_unique(array_filter(array_map('intval', $visible),
        static fn(int $uid): bool => $uid > 0 && $uid !== $owner)));
}

function af_advancedmessenger_owned(int $pmid): ?array
{
    global $db, $mybb;
    $query = $db->simple_select('privatemessages', '*', "pmid=".$pmid." AND uid=".(int)$mybb->user['uid']);
    $row = $db->fetch_array($query);
    return $row ?: null;
}

/**
 * Metadata only, one owner's mailbox. No persistent index/cache or body scan.
 * O(N) classification is required by MyBB's serialized recipients. Bodies and
 * user records are fetched only for this page (30 dialogs), never for N rows.
 */
function af_advancedmessenger_dialogs(int $folder, int $page, string $name = ''): array
{
    global $db, $mybb;
    $owner = (int)$mybb->user['uid'];
    $where = "uid={$owner}";
    $where .= $folder > 0 ? " AND folder={$folder}" : ' AND folder NOT IN (3,4)';
    $query = $db->simple_select('privatemessages', 'pmid,fromid,toid,recipients,folder,dateline,status', $where,
        ['order_by' => 'dateline DESC, pmid', 'order_dir' => 'DESC']);
    $dialogs = [];
    while ($row = $db->fetch_array($query)) {
        $key = af_advancedmessenger_identity($row, $owner);
        if (!isset($dialogs[$key])) $dialogs[$key] = ['key' => $key, 'row' => $row, 'unread' => 0];
        if ((int)$row['status'] === 0 && (int)$row['folder'] !== 2 && (int)$row['folder'] !== 3 && (int)$row['folder'] !== 4) {
            ++$dialogs[$key]['unread'];
        }
    }
    // Match names by UID, so renaming a participant cannot split a dialog.
    $users = [];
    $ids = [];
    $lookup = $name === '' ? array_slice(array_values($dialogs), (max(1,min($page,max(1,(int)ceil(count($dialogs)/30))))-1)*30,30) : $dialogs;
    foreach ($lookup as $dialog) {
        $row = $dialog['row'];
        $peer = str_starts_with($dialog['key'], 'user:') ? (int)substr($dialog['key'], 5)
            : ((int)$row['fromid'] === $owner ? (int)$row['toid'] : (int)$row['fromid']);
        if ($peer > 0) $ids[$peer] = $peer;
    }
    // Chunk to avoid an unbounded SQL IN expression for imported mailboxes.
    foreach (array_chunk(array_values($ids), 300) as $chunk) {
        $query = $db->simple_select('users', 'uid,username,avatar,avatartype', 'uid IN ('.implode(',', $chunk).')');
        while ($user = $db->fetch_array($query)) $users[(int)$user['uid']] = $user;
    }
    foreach ($dialogs as $key => &$dialog) {
        $row = $dialog['row'];
        $peer = str_starts_with($key, 'user:') ? (int)substr($key, 5)
            : ((int)$row['fromid'] === $owner ? (int)$row['toid'] : (int)$row['fromid']);
        $dialog['user'] = $users[$peer] ?? ['uid' => 0, 'username' => af_advancedmessenger_t('unknown'), 'avatar' => ''];
        if (str_starts_with($key, 'group:')) $dialog['user'] = ['uid'=>0, 'username'=>af_am_group_links()[(int)$row['pmid']]['title'], 'avatar'=>''];
        if ($name !== '' && my_strpos(my_strtolower($dialog['user']['username']), my_strtolower($name)) === false) {
            unset($dialogs[$key]);
        }
    }
    unset($dialog);
    $count = count($dialogs);
    $page = max(1, min($page, max(1, (int)ceil($count / 30))));
    $dialogs = array_slice(array_values($dialogs), ($page - 1) * 30, 30);
    $ids = array_map(static fn(array $d): int => (int)$d['row']['pmid'], $dialogs);
    $bodies = [];
    if ($ids) {
        $query = $db->simple_select('privatemessages', 'pmid,subject,message',
            "uid={$owner} AND pmid IN (".implode(',', $ids).')');
        while ($row = $db->fetch_array($query)) $bodies[(int)$row['pmid']] = $row;
    }
    foreach ($dialogs as &$dialog) $dialog['row'] += $bodies[(int)$dialog['row']['pmid']] ?? [];
    unset($dialog);
    return ['items' => $dialogs, 'page' => $page, 'pages' => max(1, (int)ceil($count / 30))];
}

/** Cursor scans metadata in bounded batches; rejected group mail never loads a body. */
function af_advancedmessenger_history(array $anchor, int $beforeTime = 0, int $beforeId = 0): array
{
    global $db, $mybb;
    $owner = (int)$mybb->user['uid'];
    $key = af_advancedmessenger_identity($anchor, $owner);
    $where = "uid={$owner}";
    if (str_starts_with($key, 'group:')) {
        $gid = (int)substr($key, 6);
        if (!af_am_group($gid)) error_no_permission();
        $where .= ' AND pmid IN (SELECT pmid FROM '.TABLE_PREFIX.'af_am_links WHERE gid='.$gid.')';
        $where .= (int)$anchor['folder'] === 4 ? ' AND folder=4' : ' AND folder NOT IN (3,4)';
    } elseif (str_starts_with($key, 'user:')) {
        $peer = (int)substr($key, 5);
        $where .= " AND ((fromid={$owner} AND toid={$peer}) OR (fromid={$peer} AND toid={$owner}))";
        $where .= (int)$anchor['folder'] === 4 ? ' AND folder=4' : ' AND folder NOT IN (3,4)';
    } else {
        $where .= ' AND pmid='.(int)$anchor['pmid'];
    }
    if ($beforeTime >= 0 && $beforeId > 0) {
        $where .= " AND (dateline<{$beforeTime} OR (dateline={$beforeTime} AND pmid<{$beforeId}))";
    }
    if ($key === 'user:'.$owner && (int)$anchor['folder'] !== 4) {
        // Exclude an exact self-PM sent copy before applying the cursor/limit,
        // so the two native copies cannot straddle adjacent history pages.
        $table = TABLE_PREFIX.'privatemessages';
        $where .= " AND (folder<>2 OR NOT EXISTS (SELECT 1 FROM {$table} twin WHERE twin.uid={$owner}"
            ." AND twin.fromid={$owner} AND twin.toid={$owner} AND twin.folder NOT IN (2,3,4)"
            ." AND twin.dateline={$table}.dateline AND twin.subject={$table}.subject"
            ." AND twin.message={$table}.message AND twin.recipients={$table}.recipients))";
    }
    $query = $db->simple_select('privatemessages', 'pmid,fromid,toid,recipients,folder,dateline,status', $where,
        ['order_by' => 'dateline DESC, pmid', 'order_dir' => 'DESC', 'limit' => 100]);
    $ids = [];
    $cursor = null;
    $scanned = 0;
    while ($row = $db->fetch_array($query)) {
        ++$scanned;
        $cursor = [(int)$row['dateline'], (int)$row['pmid']];
        if (af_advancedmessenger_identity($row, $owner) === $key) $ids[] = (int)$row['pmid'];
        if (count($ids) === 30) break;
    }
    $more = $scanned === 100 || count($ids) === 30;
    $rows = [];
    if ($ids) {
        $query = $db->query('SELECT u.*, f.*, pm.*, u.username AS author_username FROM '.TABLE_PREFIX.'privatemessages pm'
            .' LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=pm.fromid'
            .' LEFT JOIN '.TABLE_PREFIX.'userfields f ON f.ufid=pm.fromid'
            ." WHERE pm.uid={$owner} AND pm.pmid IN (".implode(',', $ids).') ORDER BY pm.dateline ASC, pm.pmid ASC');
        while ($row = $db->fetch_array($query)) { $row['author_username'] ??= af_advancedmessenger_t('unknown'); $row['am_group'] = str_starts_with($key, 'group:'); $rows[] = $row; }
    }
    // Self-PMs have an inbox copy and a saved sent copy. Pair exact copies only;
    // never collapse different pmids merely because their subject/time matches.
    $seen = [];
    $result = [];
    foreach ($rows as $row) {
        if ((int)$row['fromid'] === $owner && (int)$row['toid'] === $owner) {
            $hash = hash('sha256', $row['dateline']."\0".$row['subject']."\0".$row['message']."\0".$row['recipients']);
            $side = (int)$row['folder'] === 2 ? 'sent' : 'received';
            $other = $side === 'sent' ? 'received' : 'sent';
            if (!empty($seen[$hash][$other])) { --$seen[$hash][$other]; continue; }
            $seen[$hash][$side] = ($seen[$hash][$side] ?? 0) + 1;
        }
        $result[] = $row;
    }
    return ['rows' => $result, 'cursor' => $more ? $cursor : null, 'key' => $key];
}

/** Incremental own-mailbox scan; never marks PM read or rebuilds dialog groups. */
function af_advancedmessenger_new_messages(array $anchor, int $after): array
{
    global $db, $mybb;
    $owner = (int)$mybb->user['uid'];
    $key = af_advancedmessenger_identity($anchor, $owner);
    $where = 'uid='.$owner.' AND pmid>'.$after;
    $where .= (int)$anchor['folder'] === 4 ? ' AND folder=4' : ' AND folder NOT IN (3,4)';
    if (str_starts_with($key, 'group:')) {
        $gid = (int)substr($key,6);
        if (!af_am_group($gid)) error_no_permission();
        $where .= ' AND pmid IN (SELECT pmid FROM '.TABLE_PREFIX.'af_am_links WHERE gid='.$gid.')';
    } elseif (str_starts_with($key, 'user:')) {
        $peer = (int)substr($key,5);
        $where .= ' AND ((fromid='.$owner.' AND toid='.$peer.') OR (fromid='.$peer.' AND toid='.$owner.'))';
    } else return ['rows'=>[], 'after'=>$after];
    $q = $db->simple_select('privatemessages', 'pmid,fromid,toid,folder,recipients', $where, ['order_by'=>'pmid','order_dir'=>'ASC','limit'=>30]);
    $ids = [];
    while ($row = $db->fetch_array($q)) {
        $after = max($after,(int)$row['pmid']);
        if (af_advancedmessenger_identity($row,$owner) === $key) $ids[] = (int)$row['pmid'];
    }
    $rows = [];
    if ($ids) {
        $q = $db->query('SELECT u.avatar,u.avatartype,u.username AS author_username,pm.* FROM '.TABLE_PREFIX.'privatemessages pm LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=pm.fromid WHERE pm.uid='.$owner.' AND pm.pmid IN ('.implode(',',$ids).') ORDER BY pm.dateline,pm.pmid');
        while ($row = $db->fetch_array($q)) {
            $row['author_username'] ??= af_advancedmessenger_t('unknown');
            $row['am_group'] = str_starts_with($key,'group:');
            // Self-PM recipient copy is canonical; skip its duplicate saved copy.
            if ($key === 'user:'.$owner && (int)$row['folder'] === 2) continue;
            $rows[] = $row;
        }
    }
    return ['rows'=>$rows, 'after'=>$after];
}
