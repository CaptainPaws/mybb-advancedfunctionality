<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

require_once __DIR__ . '/metadata.php';
require_once __DIR__ . '/attributes.php';

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

/** Project only stable KB selectors into sheet base_json; runtime build/progress stay untouched. */
function af_charactersheets_refresh_sheet_from_kb(int $tid, int $uid = 0, bool $createIfMissing = false, int $actorUid = 0): array
{
    global $db;

    $acceptRow = af_charactersheets_get_accept_row($tid);
    $source = function_exists('af_charactersheets_resolve_character_kb_entry')
        ? af_charactersheets_resolve_character_kb_entry($tid, $uid, $acceptRow)
        : [];
    if (empty(($source['entry'] ?? [])['id'])) {
        return [];
    }

    $sheet = af_charactersheets_get_sheet_by_tid($tid);
    if ($sheet || $createIfMissing) {
        if ($uid <= 0) {
            $thread = $db->fetch_array($db->simple_select('threads', 'uid', 'tid=' . $tid, ['limit' => 1]));
            $uid = (int)($thread['uid'] ?? 0);
        }
        try {
            if ($createIfMissing) {
                $sheet = af_charactersheets_ensure_sheet($tid, $uid, af_charactersheets_expected_sheet_slug($tid, $acceptRow), $actorUid);
            } elseif ($sheet) {
                // A read/refresh of an existing row must never recreate one
                // deleted while waiting for the topic lock.
                $sheet = af_charactersheets_with_sheet_lock($tid, static function () use ($tid, $uid, $actorUid): array {
                    $existing = af_charactersheets_find_sheet_for_creation($tid, $uid, '');
                    if ($existing) af_charactersheets_bind_topic_sheet($existing, $actorUid);
                    return $existing;
                });
            }
        } catch (RuntimeException $error) {
            af_charactersheets_log('Sheet binding during KB sync blocked', ['tid' => $tid, 'code' => $error->getMessage()]);
            return [];
        }
    }
    if (empty($sheet['id'])) {
        return [];
    }

    $profile = (array)(($source['payload'] ?? [])['profile'] ?? []);
    $meta = (array)(($source['payload'] ?? [])['meta'] ?? []);
    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $base['race_key'] = (string)($profile['character_origin'] ?? $profile['character_race'] ?? '');
    $base['class_key'] = (string)($profile['character_archetype'] ?? $profile['character_class'] ?? '');
    $base['theme_key'] = (string)($profile['character_faction'] ?? '');
    $mechanic = trim((string)($meta['mechanic'] ?? ''));
    if ($mechanic === 'arpg' || $mechanic === 'dnd') {
        $base['mechanic'] = $mechanic;
        $base['sheet_mode'] = $mechanic;
    }
    $db->update_query(AF_CS_SHEETS_TABLE, [
        'base_json' => $db->escape_string(af_charactersheets_json_encode($base)),
        'updated_at' => TIME_NOW,
    ], 'id=' . (int)$sheet['id']);

    return af_charactersheets_get_sheet_by_id((int)$sheet['id']);
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







/** Serialize sheet lifecycle writes for one topic on the authoritative MyBB connection. */
function af_charactersheets_with_sheet_lock(int $tid, callable $operation): mixed
{
    global $db;

    $scope = $db->escape_string(TABLE_PREFIX . 'cs-sheet:' . $tid);
    $lock = "SHA2(CONCAT(DATABASE(), ':', '{$scope}'), 256)";
    $query = $db->write_query("SELECT GET_LOCK({$lock}, 5) AS acquired", true);
    if (!$query || (int)$db->fetch_field($query, 'acquired') !== 1) {
        throw new RuntimeException('sheet_busy');
    }
    try {
        return $operation();
    } finally {
        $db->write_query("SELECT RELEASE_LOCK({$lock})", true);
    }
}

/** TID is the identity. Slug can recover only a row for that exact topic. */
function af_charactersheets_find_sheet_for_creation(int $tid, int $uid, string $slug): array
{
    global $db;

    $table = TABLE_PREFIX . AF_CS_SHEETS_TABLE;
    $query = $db->write_query("SELECT * FROM `{$table}` WHERE tid={$tid} ORDER BY id LIMIT 1");
    $sheet = (array)$db->fetch_array($query);
    if (!$sheet && $slug !== '') {
        $escaped = $db->escape_string($slug);
        $sheet = (array)$db->fetch_array($db->write_query("SELECT * FROM `{$table}` WHERE slug='{$escaped}' LIMIT 1"));
        if ($sheet && (int)$sheet['tid'] !== $tid) {
            throw new RuntimeException('sheet_slug_conflict');
        }
    }
    if ($sheet && (int)$sheet['uid'] !== $uid) {
        throw new RuntimeException('sheet_owner_conflict');
    }
    return $sheet;
}

function af_charactersheets_expected_sheet_slug(int $tid, array $acceptRow): string
{
    return trim((string)($acceptRow['sheet_slug'] ?? '')) ?: 'thread-' . $tid;
}

function af_charactersheets_ensure_sheet(int $tid, int $uid, string $slug, int $actorUid = 0): array
{
    global $db;

    if ($tid <= 0 || $uid <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE)) {
        return [];
    }
    $slug = trim($slug);

    return af_charactersheets_with_sheet_lock($tid, static function () use ($db, $tid, $uid, $slug, $actorUid): array {
        $thread = $db->fetch_array($db->write_query('SELECT uid FROM `' . TABLE_PREFIX . 'threads` WHERE tid=' . $tid . ' LIMIT 1'));
        if ((int)($thread['uid'] ?? 0) !== $uid) {
            throw new RuntimeException('sheet_thread_owner_invalid');
        }
        $accept = af_charactersheets_get_accept_row($tid);
        if ((int)($accept['uid'] ?? 0) > 0 && (int)$accept['uid'] !== $uid) {
            throw new RuntimeException('sheet_owner_conflict');
        }
        $sheet = af_charactersheets_find_sheet_for_creation($tid, $uid, $slug);
        if (!$sheet) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/iD', $slug)) {
                throw new RuntimeException('sheet_slug_invalid');
            }
            $sheet = af_charactersheets_insert_topic_sheet($tid, $uid, $slug);
        }
        if ($sheet) af_charactersheets_bind_topic_sheet($sheet, $actorUid);
        return $sheet;
    });
}

/** Update only the binding for a verified topic sheet while holding its lock. */
function af_charactersheets_bind_topic_sheet(array $sheet, int $actorUid): void
{
    global $db;

    $tid = (int)$sheet['tid'];
    $uid = (int)$sheet['uid'];
    $accept = af_charactersheets_get_accept_row($tid);
    if ((int)($accept['uid'] ?? 0) > 0 && (int)$accept['uid'] !== $uid) {
        throw new RuntimeException('sheet_owner_conflict');
    }
    $update = ['uid' => $uid, 'sheet_slug' => (string)$sheet['slug'], 'sheet_created' => 1];
    if (empty($accept['kb_entry_id']) && function_exists('af_charactersheets_resolve_character_kb_entry')) {
        $source = af_charactersheets_resolve_character_kb_entry($tid, $uid, $accept);
        if (!empty($source['entry']['id'])) {
            $update['kb_entry_id'] = (int)$source['entry']['id'];
        }
    }
    if ($accept) {
        // Preserve any KB link that appeared since this request read the row.
        $entryId = (int)($update['kb_entry_id'] ?? 0);
        unset($update['kb_entry_id']);
        $db->update_query(AF_CS_TABLE, af_charactersheets_db_escape_array($update), 'tid=' . $tid);
        if ($entryId > 0) {
            $db->update_query(AF_CS_TABLE, ['kb_entry_id' => $entryId], 'tid=' . $tid . ' AND (kb_entry_id IS NULL OR kb_entry_id=0)');
        }
    } else {
        af_charactersheets_upsert_accept_row($tid, $update);
    }
    if (function_exists('af_cwf_bind_sheet')) {
        af_cwf_bind_sheet($tid, (int)$sheet['id'], (string)$sheet['slug'], $actorUid);
    }
}

/** Called under the topic lock. A different topic may still race for the same slug. */
function af_charactersheets_insert_topic_sheet(int $tid, int $uid, string $slug): array
{
    global $db;

    $base = [
        'race_key' => '',
        'class_key' => '',
        'theme_key' => '',
        'attributes_base' => af_charactersheets_default_attributes(),
    ];

    $characterSource = $tid > 0 && function_exists('af_charactersheets_resolve_character_kb_entry')
        ? af_charactersheets_resolve_character_kb_entry($tid, $uid, af_charactersheets_get_accept_row($tid))
        : [];
    $characterProfile = (array)(($characterSource['payload'] ?? [])['profile'] ?? []);
    $characterMeta = (array)(($characterSource['payload'] ?? [])['meta'] ?? []);
    if (!empty(($characterSource['entry'] ?? [])['id'])) {
        $base['race_key'] = (string)($characterProfile['character_origin'] ?? $characterProfile['character_race'] ?? '');
        $base['class_key'] = (string)($characterProfile['character_archetype'] ?? $characterProfile['character_class'] ?? '');
        $base['theme_key'] = (string)($characterProfile['character_faction'] ?? '');
        $mechanic = trim((string)($characterMeta['mechanic'] ?? ''));
        if ($mechanic === 'arpg' || $mechanic === 'dnd') {
            $base['mechanic'] = $mechanic;
            $base['sheet_mode'] = $mechanic;
        }
    } elseif ($tid > 0) {
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
        'uid' => $uid,
        'tid' => $tid,
        'slug' => $slug,
        'base_json' => $db->escape_string(af_charactersheets_json_encode($base)),
        'build_json' => $db->escape_string(af_charactersheets_json_encode($build)),
        'progress_json' => $db->escape_string(af_charactersheets_json_encode($progress)),
        'updated_at' => TIME_NOW,
    ];

    $fields = '`' . implode('`,`', array_keys($row)) . '`';
    $values = implode(',', array_map(static fn($value): string => "'" . $value . "'", $row));
    $table = TABLE_PREFIX . AF_CS_SHEETS_TABLE;
    try {
        $inserted = $db->write_query("INSERT INTO `{$table}` ({$fields}) VALUES ({$values})", true);
        $errorCode = $inserted ? 0 : (int)$db->error_number();
    } catch (mysqli_sql_exception $error) {
        $errorCode = (int)$error->getCode();
    }

    if ($errorCode === 1062) {
        // Retry the identity checks on the writer. A unique slug may now
        // belong to another topic, while an old unique UID grants no identity.
        $sheet = af_charactersheets_find_sheet_for_creation($tid, $uid, $slug);
        if ($sheet) return $sheet;
    }
    if (empty($inserted)) {
        throw new RuntimeException('sheet_insert_failed');
    }
    return af_charactersheets_find_sheet_for_creation($tid, $uid, $slug);
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
    try {
        return af_charactersheets_with_sheet_lock($tid, static function () use ($db, $sheet_id, $sheet, $actor, $reason): bool {
            $fresh = $db->fetch_array($db->write_query('SELECT * FROM `' . TABLE_PREFIX . AF_CS_SHEETS_TABLE . '` WHERE id=' . $sheet_id));
            if (!$fresh || (int)$fresh['tid'] !== (int)$sheet['tid'] || (int)$fresh['uid'] !== (int)$sheet['uid']
                || !af_charactersheets_user_can_delete_sheet($fresh, $actor)) {
                return false;
            }
            return af_charactersheets_delete_sheet_locked($fresh, $actor, $reason);
        });
    } catch (RuntimeException $error) {
        af_charactersheets_log('Sheet deletion blocked', ['sheet_id' => $sheet_id, 'code' => $error->getMessage()]);
        return false;
    }
}

function af_charactersheets_delete_sheet_locked(array $sheet, array $actor, string $reason): bool
{
    global $db;
    $sheet_id = (int)$sheet['id'];
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
            ], 'tid=' . $tid . " AND sheet_slug='" . $db->escape_string($slug) . "'");
        } elseif ($slug !== '') {
            $db->update_query(AF_CS_TABLE, [
                'sheet_slug' => null,
                'sheet_created' => 0,
            ], "sheet_slug='" . $db->escape_string($slug) . "'");
        }
    }

    if (defined('AF_CWF_TABLE') && $db->table_exists(AF_CWF_TABLE) && $tid > 0) {
        $db->update_query(AF_CWF_TABLE, [
            'sheet_id' => null,
            'sheet_slug' => null,
            'updated_at' => TIME_NOW,
        ], 'tid=' . $tid . ' AND sheet_id=' . $sheet_id);
        unset($GLOBALS['af_cwf_rows'][$tid]);
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
