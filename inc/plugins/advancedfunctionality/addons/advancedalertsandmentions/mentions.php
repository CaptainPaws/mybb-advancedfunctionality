<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/**
 * Подсказки для @упоминаний (минимально, стабильно).
 * Возвращает массив items: [{uid, username}]
 */
function af_aam_suggest_users(string $q, int $limit = 8): array
{
    global $db;

    $q = trim($q);
    if ($q === '') {
        return [];
    }

    if (mb_strlen($q) > 50) {
        $q = mb_substr($q, 0, 50);
    }

    if (mb_strlen($q) < 2) {
        return [];
    }

    $limit = (int)$limit;
    if ($limit <= 0) $limit = 10;
    if ($limit > 10) $limit = 10;

    // LIKE-safe (экранируем % и _)
    $qLike = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $qLike = $db->escape_string($qLike);

    $sql = $db->write_query("
        SELECT uid, username
        FROM " . TABLE_PREFIX . "users
        WHERE username LIKE '%{$qLike}%'
        ORDER BY (username LIKE '{$qLike}%') DESC, username ASC
        LIMIT {$limit}
    ");

    $out = [];
    while ($u = $db->fetch_array($sql)) {
        $out[] = [
            'uid' => (int)$u['uid'],
            'username' => (string)$u['username'],
        ];
    }

    return $out;
}

function af_aam_handle_all_mention(string $message, int $fromUid, int $objectId, array $extra = []): void
{
    global $db, $mybb;

    // Есть ли @all
    if (!preg_match('/(^|[\s>])@all\b/i', $message)) {
        return;
    }

    // Кто отправил — нужен для проверки прав
    $fromUser = $mybb->user;
    if ((int)($fromUser['uid'] ?? 0) !== $fromUid) {
        $fromUser = $db->fetch_array($db->simple_select('users', 'uid,usergroup,additionalgroups,username', 'uid='.(int)$fromUid));
        if (!$fromUser) {
            return;
        }
    }

    $allowed = af_aam_parse_gid_csv((string)($mybb->settings['af_aam_all_allowed_groups'] ?? ''));
    if (!af_aam_user_in_any_group($fromUser, $allowed)) {
        // нет прав на @all
        return;
    }

    // Кому отправляем (пусто = всем зарегистрированным)
    $targetGids = af_aam_parse_gid_csv((string)($mybb->settings['af_aam_all_target_groups'] ?? ''));

    // type_id для "mention"
    $typeRow = $db->fetch_array($db->simple_select(AF_AAM_TABLE_TYPES, 'id', "code='".$db->escape_string('mention')."'"));
    $typeId = (int)($typeRow['id'] ?? 0);
    if ($typeId <= 0 || !$db->table_exists(AF_AAM_TABLE_ALERTS)) {
        return;
    }

    // Выбираем всех пользователей (uid>0)
    $q = $db->simple_select('users', 'uid,usergroup,additionalgroups', 'uid>0');
    $rows = [];
    while ($u = $db->fetch_array($q)) {
        $uid = (int)$u['uid'];
        if ($uid <= 0 || $uid === $fromUid) {
            continue;
        }

        // если target-группы заданы — фильтруем
        if (!empty($targetGids)) {
            if (!af_aam_user_in_any_group($u, $targetGids)) {
                continue;
            }
        }

        $rows[] = [
            'uid'       => $uid,
            'is_read'   => 0,
            'dateline'  => TIME_NOW,
            'type_id'   => $typeId,
            'object_id' => (int)$objectId,
            'from_uid'  => (int)$fromUid,
            'forced'    => 0,
            'extra'     => $db->escape_string(json_encode(array_merge($extra, ['all' => 1]), JSON_UNESCAPED_UNICODE)),
        ];

        // пачками, чтобы не убить запросом форум
        if (count($rows) >= 500) {
            if (method_exists($db, 'insert_query_multiple')) {
                $db->insert_query_multiple(AF_AAM_TABLE_ALERTS, $rows);
            } else {
                foreach ($rows as $r) {
                    $db->insert_query(AF_AAM_TABLE_ALERTS, $r);
                }
            }
            $rows = [];
        }
    }

    if (!empty($rows)) {
        if (method_exists($db, 'insert_query_multiple')) {
            $db->insert_query_multiple(AF_AAM_TABLE_ALERTS, $rows);
        } else {
            foreach ($rows as $r) {
                $db->insert_query(AF_AAM_TABLE_ALERTS, $r);
            }
        }
    }
}
