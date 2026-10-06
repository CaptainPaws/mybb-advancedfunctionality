<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

/** Read-only CharacterSheets metadata for trigger/profile contexts. */
function af_charactersheets_get_accept_row(int $tid): array
{
    static $cache = [];
    global $db;
    if ($tid <= 0) return [];
    if (array_key_exists($tid, $cache)) return (array)$cache[$tid];
    if (!is_object($db) || !$db->table_exists(AF_CS_TABLE)) return $cache[$tid] = [];
    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return $cache[$tid] = (is_array($row) ? $row : []);
}

function af_charactersheets_get_sheet_by_tid(int $tid): array
{
    static $cache = [];
    global $db;
    if ($tid <= 0) return [];
    if (array_key_exists($tid, $cache)) return (array)$cache[$tid];
    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return $cache[$tid] = [];
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return $cache[$tid] = (is_array($row) ? $row : []);
}

function af_charactersheets_get_sheet_by_uid(int $uid): array
{
    static $cache = [];
    global $db;
    if ($uid <= 0) return [];
    if (array_key_exists($uid, $cache)) return (array)$cache[$uid];
    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return $cache[$uid] = [];
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'uid=' . $uid, ['limit' => 1]));
    return $cache[$uid] = (is_array($row) ? $row : []);
}

function af_charactersheets_get_sheet_by_slug(string $slug): array
{
    static $cache = [];
    global $db;
    $slug = trim($slug);
    if ($slug === '' || !preg_match('~^[a-z0-9][a-z0-9\-]*$~i', $slug)) return [];
    if (array_key_exists($slug, $cache)) return (array)$cache[$slug];
    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return $cache[$slug] = [];
    $slugEscaped = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', "slug='{$slugEscaped}'", ['limit' => 1]));
    return $cache[$slug] = (is_array($row) ? $row : []);
}
