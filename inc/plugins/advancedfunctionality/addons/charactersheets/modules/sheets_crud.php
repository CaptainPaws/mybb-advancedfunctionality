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
        'kb_entry_id' => null,
        'kb_synced_at' => 0,
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

    $tid = (int)$tid;
    $uid = (int)$uid;
    $slug = trim($slug);
    $hasUid = ($uid > 0);

    $existing = [];
    if ($tid > 0) {
        $existing = af_charactersheets_get_sheet_by_tid($tid);
    }
    if (empty($existing) && $slug !== '') {
        $existing = af_charactersheets_get_sheet_by_slug($slug);
    }

    // ВАЖНО:
    // В текущей схеме таблицы uid уникален, поэтому uid обязан участвовать
    // в duplicate guard всегда, иначе INSERT падает на Duplicate entry по uid.
    if (empty($existing) && $hasUid) {
        $existing = af_charactersheets_get_sheet_by_uid($uid);
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
            $db->update_query(
                AF_CS_SHEETS_TABLE,
                af_charactersheets_db_escape_array($updates),
                'id=' . (int)$existing['id']
            );
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

    $progress = [
        'level' => 1,
        'exp' => 0,
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

    try {
        $id = (int)$db->insert_query(AF_CS_SHEETS_TABLE, $row);
    } catch (Throwable $e) {
        // Защита от гонки / повторного создания в рамках текущей UNIQUE-схемы по uid.
        $fallback = [];

        if ($tid > 0) {
            $fallback = af_charactersheets_get_sheet_by_tid($tid);
        }
        if (empty($fallback) && $slug !== '') {
            $fallback = af_charactersheets_get_sheet_by_slug($slug);
        }
        if (empty($fallback) && $hasUid) {
            $fallback = af_charactersheets_get_sheet_by_uid($uid);
        }

        if (!empty($fallback)) {
            $updates = [];

            if ($slug !== '' && (string)($fallback['slug'] ?? '') !== $slug) {
                $updates['slug'] = $slug;
            }
            if ($tid > 0 && (int)($fallback['tid'] ?? 0) !== $tid) {
                $updates['tid'] = $tid;
            }
            if ($hasUid && (int)($fallback['uid'] ?? 0) !== $uid) {
                $updates['uid'] = $uid;
            }

            if ($updates) {
                $updates['updated_at'] = TIME_NOW;
                $db->update_query(
                    AF_CS_SHEETS_TABLE,
                    af_charactersheets_db_escape_array($updates),
                    'id=' . (int)$fallback['id']
                );
                $fallback = af_charactersheets_get_sheet_by_id((int)$fallback['id']);
            }

            return $fallback;
        }

        throw $e;
    }

    if ($id <= 0) {
        return [];
    }

    return af_charactersheets_get_sheet_by_id($id);
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
    $build = af_charactersheets_normalize_build(af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')));
    $context = cs_resolve_character_kb_context($sheet_id);
    $grants = [];
    $source_priority = [
        'race_choice' => 0,
        'race_variant_choice' => 1,
        'class_choice' => 2,
        'theme_choice' => 3,
        'race' => 4,
        'race_variant' => 5,
        'class' => 6,
        'theme' => 7,
    ];

    $push_grant = static function (string $skill_key, int $skill_rank, string $source) use (&$grants, $source_priority): void {
        if ($skill_key === '' || $skill_rank <= 0 || $source === '') {
            return;
        }
        $existing = (array)($grants[$skill_key] ?? []);
        if (!$existing) {
            $grants[$skill_key] = ['skill_rank' => $skill_rank, 'source' => $source];
            return;
        }

        $existing_rank = (int)($existing['skill_rank'] ?? 0);
        $existing_source = (string)($existing['source'] ?? '');
        if ($skill_rank > $existing_rank) {
            $grants[$skill_key] = ['skill_rank' => $skill_rank, 'source' => $source];
            return;
        }

        if ($skill_rank === $existing_rank
            && ($source_priority[$source] ?? 100) < ($source_priority[$existing_source] ?? 100)
        ) {
            $grants[$skill_key]['source'] = $source;
        }
    };

    foreach (['race', 'race_variant', 'class', 'theme'] as $source) {
        $resolved = (array)($context[$source] ?? []);
        foreach (af_charactersheets_extract_skill_grants($resolved, $source) as $grant) {
            $push_grant(
                (string)($grant['skill_key'] ?? ''),
                max(1, (int)($grant['skill_rank'] ?? 1)),
                $source
            );
        }
    }

    foreach (af_charactersheets_collect_skill_pick_choices($context, $build) as $choice) {
        if ((string)($choice['grant_mode'] ?? '') !== 'rank') {
            continue;
        }
        $choice_source = (string)($choice['source'] ?? 'race') . '_choice';
        $rank_value = max(1, (int)($choice['rank_value'] ?? 1));
        foreach ((array)($choice['selected'] ?? []) as $skill_key) {
            $push_grant((string)$skill_key, $rank_value, $choice_source);
        }
    }

    $fixed_sources = ['race', 'race_variant', 'class', 'theme', 'race_choice', 'race_variant_choice', 'class_choice', 'theme_choice'];

    $rows = af_charactersheets_get_sheet_skills($sheet_id);
    $rows_by_key = [];
    foreach ($rows as $row) {
        $skill_key = (string)($row['skill_key'] ?? '');
        $source = (string)($row['source'] ?? 'manual');
        if ($skill_key !== '') {
            $rows_by_key[$skill_key] = $row;
        }
        if (!in_array($source, $fixed_sources, true)) {
            continue;
        }
        if (!isset($grants[$skill_key])) {
            af_charactersheets_delete_sheet_skill($sheet_id, $skill_key);
        }
    }

    foreach ($grants as $skill_key => $grant) {
        $existing = (array)($rows_by_key[$skill_key] ?? []);
        $existing_rank = max(0, (int)($existing['skill_rank'] ?? 0));
        $existing_source = (string)($existing['source'] ?? '');
        $grant_rank = max(1, (int)($grant['skill_rank'] ?? 1));
        $grant_source = (string)($grant['source'] ?? 'race');

        $apply_rank = max($existing_rank, $grant_rank);
        $apply_source = $grant_source;
        if ($existing_rank > $grant_rank && $existing_source !== '') {
            $apply_source = $existing_source;
        }

        af_charactersheets_upsert_sheet_skill($sheet_id, $uid, $skill_key, $apply_rank, 1, $apply_source);
    }
}
