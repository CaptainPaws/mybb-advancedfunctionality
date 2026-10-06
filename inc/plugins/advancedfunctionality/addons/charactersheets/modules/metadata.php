<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Shared read API; writes and recovery live in sheets_crud.php. */
function af_charactersheets_get_accept_row(int $tid): array
{
    global $db;

    if ($tid <= 0) {
        return [];
    }

    if (!is_object($db) || !$db->table_exists(AF_CS_TABLE)) return [];
    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_tid(int $tid): array
{
    global $db;

    if ($tid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return [];
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_uid(int $uid): array
{
    global $db;

    if ($uid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return [];
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'uid=' . $uid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_slug(string $slug): array
{
    global $db;

    $slug = trim($slug);
    if ($slug === '' || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    if (!preg_match('~^[a-z0-9][a-z0-9\-]*$~i', $slug)) {
        return [];
    }

    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return [];
    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', "slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}
