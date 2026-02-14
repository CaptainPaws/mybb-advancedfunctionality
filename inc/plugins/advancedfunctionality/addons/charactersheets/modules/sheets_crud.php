<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_get_accept_row(int $tid): array
{
    global $db;

    if ($tid <= 0) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_accept_row_by_slug(string $slug): array
{
    global $db;

    $slug = trim($slug);
    if ($slug === '') {
        return [];
    }

    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_TABLE, '*', "sheet_slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_upsert_accept_row(int $tid, array $data): void
{
    global $db;

    if ($tid <= 0) {
        return;
    }

    $row = af_charactersheets_get_accept_row($tid);
    $defaults = [
        'uid' => 0,
        'accepted' => 0,
        'accepted_by_uid' => null,
        'accepted_pid' => null,
        'accepted_at' => 0,
        'sheet_slug' => null,
        'sheet_created' => 0,
    ];
    $payload = array_merge($defaults, $row ?: [], $data, ['tid' => $tid]);

    if ($row) {
        $db->update_query(AF_CS_TABLE, af_charactersheets_db_escape_array($payload), 'tid=' . $tid);
    } else {
        $db->insert_query(AF_CS_TABLE, af_charactersheets_db_escape_array($payload));
    }
}

function af_charactersheets_db_escape_array(array $data): array
{
    global $db;

    $escaped = [];
    foreach ($data as $key => $value) {
        if (is_null($value)) {
            $escaped[$key] = null;
            continue;
        }
        if (is_int($value) || is_float($value)) {
            $escaped[$key] = $value;
            continue;
        }
        $escaped[$key] = $db->escape_string((string)$value);
    }

    return $escaped;
}

function af_charactersheets_json_decode(string $raw): array
{
    if (function_exists('af_kb_decode_json')) {
        $decoded = af_kb_decode_json($raw);
        return is_array($decoded) ? $decoded : [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function af_charactersheets_json_encode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function af_charactersheets_get_sheet_by_id(int $sheet_id): array
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'id=' . $sheet_id, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_tid(int $tid): array
{
    global $db;

    if ($tid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_get_sheet_by_uid(int $uid): array
{
    global $db;

    if ($uid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

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

    $slug_esc = $db->escape_string($slug);
    $row = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', "slug='{$slug_esc}'", ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_charactersheets_ensure_sheet(int $tid, int $uid, string $slug): array
{
    global $db, $mybb;

    if (!$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }

    $uid = (int)$uid;
    $hasUid = ($uid > 0);

    $existing = [];
    if ($hasUid) {
        $existing = af_charactersheets_get_sheet_by_uid($uid);
    }
    if (empty($existing) && $tid > 0) {
        $existing = af_charactersheets_get_sheet_by_tid($tid);
    }

    if (!empty($existing)) {
        $updates = [];
        if ($slug !== '' && (string)($existing['slug'] ?? '') !== $slug) {
            $updates['slug'] = $slug;
        }
        if ($tid > 0 && (int)($existing['tid'] ?? 0) !== $tid) {
            $updates['tid'] = $tid;
        }

        if ($hasUid && (int)($existing['uid'] ?? 0) !== $uid) {
            $updates['uid'] = $uid;
        }

        if ($updates) {
            $updates['updated_at'] = TIME_NOW;
            $db->update_query(AF_CS_SHEETS_TABLE, af_charactersheets_db_escape_array($updates), 'id=' . (int)$existing['id']);
            $existing = af_charactersheets_get_sheet_by_id((int)$existing['id']);
        }
        return $existing;
    }

    $base = [
        'race_key' => '',
        'class_key' => '',
        'theme_key' => '',
        'attributes_base' => af_charactersheets_default_attributes(),
    ];

    if ($tid > 0) {
        $fields = af_charactersheets_get_atf_fields($tid);
        $index = af_charactersheets_index_fields($fields);
        $base['race_key'] = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
        $base['class_key'] = af_charactersheets_pick_field_value($index, ['character_class', 'class'], false);
        $base['theme_key'] = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
    }

    $build = af_charactersheets_default_build();

    $starting_attr_pool = (int)($mybb->settings['af_charactersheets_attr_pool_max'] ?? 0);
    $progress = [
        'level' => 1,
        'exp' => 0,
        'attr_points_free' => $starting_attr_pool,
        'skill_points_free' => 0,
        'bonus_attr_points' => 0,
        'bonus_skill_points' => 0,
    ];

    $row = [
        'uid' => $hasUid ? $uid : null,
        'tid' => $tid,
        'slug' => $slug,
        'base_json' => $db->escape_string(af_charactersheets_json_encode($base)),
        'build_json' => $db->escape_string(af_charactersheets_json_encode($build)),
        'progress_json' => $db->escape_string(af_charactersheets_json_encode($progress)),
        'updated_at' => TIME_NOW,
    ];

    $id = (int)$db->insert_query(AF_CS_SHEETS_TABLE, $row);
    if ($id <= 0) {
        return [];
    }

    $sheet = af_charactersheets_get_sheet_by_id($id);

    $exp_on_register = (float)($mybb->settings['af_charactersheets_exp_on_register'] ?? 0);
    if ($exp_on_register > 0 && $hasUid) {
        af_charactersheets_grant_exp(
            $id,
            $exp_on_register,
            'register:' . $uid,
            'register',
            ['uid' => $uid]
        );
        $sheet = af_charactersheets_get_sheet_by_id($id);
    }

    return $sheet;
}

function af_charactersheets_update_sheet_json(int $sheet_id, array $base, array $build, array $progress): void
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return;
    }

    $db->update_query(
        AF_CS_SHEETS_TABLE,
        af_charactersheets_db_escape_array([
            'base_json' => af_charactersheets_json_encode($base),
            'build_json' => af_charactersheets_json_encode($build),
            'progress_json' => af_charactersheets_json_encode($progress),
            'updated_at' => TIME_NOW,
        ]),
        'id=' . $sheet_id
    );
}

function af_charactersheets_delete_sheet(int $sheet_id, array $actor, string $reason = ''): bool
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return false;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }

    if (!af_charactersheets_user_can_delete_sheet($sheet, $actor)) {
        return false;
    }

    $tid = (int)($sheet['tid'] ?? 0);
    $uid = (int)($sheet['uid'] ?? 0);
    $slug = (string)($sheet['slug'] ?? '');

    if ($db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        $db->delete_query(AF_CS_EXP_LEDGER_TABLE, 'sheet_id=' . $sheet_id);
    }
    if ($db->table_exists(AF_CS_POINTS_LEDGER_TABLE)) {
        $db->delete_query(AF_CS_POINTS_LEDGER_TABLE, 'sheet_id=' . $sheet_id);
    }

    $db->delete_query(AF_CS_SHEETS_TABLE, 'id=' . $sheet_id);

    if ($db->table_exists(AF_CS_TABLE)) {
        if ($tid > 0) {
            $db->update_query(AF_CS_TABLE, [
                'sheet_slug' => null,
                'sheet_created' => 0,
            ], 'tid=' . $tid);
        } elseif ($slug !== '') {
            $db->update_query(AF_CS_TABLE, [
                'sheet_slug' => null,
                'sheet_created' => 0,
            ], "sheet_slug='" . $db->escape_string($slug) . "'");
        }
    }

    af_charactersheets_log('Sheet deleted', [
        'sheet_id' => $sheet_id,
        'sheet_uid' => $uid,
        'sheet_tid' => $tid,
        'sheet_slug' => $slug,
        'deleted_by' => (int)($actor['uid'] ?? 0),
        'deleted_by_username' => (string)($actor['username'] ?? ''),
        'reason' => $reason,
    ]);

    return true;
}

function af_charactersheets_get_sheet_skills(int $sheet_id): array
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SKILLS_TABLE)) {
        return [];
    }

    $rows = [];
    $q = $db->simple_select(AF_CS_SKILLS_TABLE, '*', 'sheet_id=' . $sheet_id, ['order_by' => 'skill_key', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function af_charactersheets_upsert_sheet_skill(int $sheet_id, int $uid, string $skill_key, int $skill_rank, int $is_active, string $source): void
{
    global $db;

    if ($sheet_id <= 0 || $skill_key === '' || !$db->table_exists(AF_CS_SKILLS_TABLE)) {
        return;
    }

    $skill_key = trim($skill_key);
    $existing = $db->fetch_array($db->simple_select(
        AF_CS_SKILLS_TABLE,
        '*',
        "sheet_id=" . $sheet_id . " AND skill_key='" . $db->escape_string($skill_key) . "'",
        ['limit' => 1]
    ));

    $payload = af_charactersheets_db_escape_array([
        'uid' => $uid,
        'sheet_id' => $sheet_id,
        'skill_key' => $skill_key,
        'skill_rank' => max(0, $skill_rank),
        'is_active' => $is_active ? 1 : 0,
        'source' => $source !== '' ? $source : 'manual',
        'updated_at' => TIME_NOW,
    ]);

    if (is_array($existing) && !empty($existing)) {
        $db->update_query(AF_CS_SKILLS_TABLE, $payload, 'id=' . (int)$existing['id']);
        return;
    }

    $payload['created_at'] = TIME_NOW;
    $db->insert_query(AF_CS_SKILLS_TABLE, $payload);
}

function af_charactersheets_delete_sheet_skill(int $sheet_id, string $skill_key): void
{
    global $db;

    if ($sheet_id <= 0 || $skill_key === '' || !$db->table_exists(AF_CS_SKILLS_TABLE)) {
        return;
    }

    $db->delete_query(
        AF_CS_SKILLS_TABLE,
        "sheet_id=" . $sheet_id . " AND skill_key='" . $db->escape_string($skill_key) . "'"
    );
}

function af_charactersheets_sync_fixed_skills(int $sheet_id): void
{
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return;
    }

    $uid = (int)($sheet['uid'] ?? 0);
    $context = cs_resolve_character_kb_context($sheet_id);
    $grants = [];
    foreach (['race', 'class', 'theme'] as $source) {
        $resolved = (array)($context[$source] ?? []);
        foreach (af_charactersheets_extract_skill_grants($resolved, $source) as $grant) {
            $skill_key = (string)($grant['skill_key'] ?? '');
            if ($skill_key === '') {
                continue;
            }
            $grants[$skill_key] = [
                'skill_rank' => max(1, (int)($grant['skill_rank'] ?? 1)),
                'source' => $source,
            ];
        }
    }

    $rows = af_charactersheets_get_sheet_skills($sheet_id);
    foreach ($rows as $row) {
        $skill_key = (string)($row['skill_key'] ?? '');
        $source = (string)($row['source'] ?? 'manual');
        if (!in_array($source, ['race', 'class', 'theme'], true)) {
            continue;
        }
        if (!isset($grants[$skill_key])) {
            af_charactersheets_delete_sheet_skill($sheet_id, $skill_key);
        }
    }

    foreach ($grants as $skill_key => $grant) {
        af_charactersheets_upsert_sheet_skill($sheet_id, $uid, $skill_key, (int)$grant['skill_rank'], 1, (string)$grant['source']);
    }
}
