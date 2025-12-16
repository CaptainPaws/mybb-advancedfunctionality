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

    $limit = (int)$limit;
    if ($limit <= 0) $limit = 8;
    if ($limit > 20) $limit = 20;

    // LIKE-safe (экранируем % и _)
    $qLike = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $qLike = $db->escape_string($qLike);

    $sql = $db->write_query("
        SELECT uid, username
        FROM " . TABLE_PREFIX . "users
        WHERE username LIKE '%{$qLike}%'
        ORDER BY username ASC
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
