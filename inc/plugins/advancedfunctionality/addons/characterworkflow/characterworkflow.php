<?php
/**
 * AF Addon: CharacterWorkflow
 * Оркестратор модерации анкеты персонажа (ATF + KB + CharacterSheets + moderation).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* optional: AF core expected */ }

define('AF_CWF_ID', 'characterworkflow');
define('AF_CWF_TABLE', 'af_character_workflow');

function af_characterworkflow_install(): bool
{
    af_cwf_ensure_schema();
    return true;
}

function af_characterworkflow_activate(): bool
{
    af_cwf_ensure_schema();
    return true;
}

function af_characterworkflow_init(): void
{
    af_cwf_ensure_schema();
}

function af_cwf_ensure_schema(): void
{
    global $db;

    if (!is_object($db) || $db->table_exists(AF_CWF_TABLE)) {
        return;
    }

    $db->write_query("\n        CREATE TABLE " . TABLE_PREFIX . AF_CWF_TABLE . " (\n          tid INT UNSIGNED NOT NULL,\n          state VARCHAR(32) NOT NULL DEFAULT 'draft',\n          kb_entry_id INT UNSIGNED DEFAULT NULL,\n          sheet_id INT UNSIGNED DEFAULT NULL,\n          sheet_slug VARCHAR(190) DEFAULT NULL,\n          greeting_post_id INT UNSIGNED DEFAULT NULL,\n          reviewed_by INT UNSIGNED DEFAULT NULL,\n          accepted_by_uid INT UNSIGNED DEFAULT NULL,\n          transferred_by_uid INT UNSIGNED DEFAULT NULL,\n          accepted_at INT UNSIGNED NOT NULL DEFAULT 0,\n          transferred_at INT UNSIGNED NOT NULL DEFAULT 0,\n          revision_requested_at INT UNSIGNED NOT NULL DEFAULT 0,\n          updated_at INT UNSIGNED NOT NULL DEFAULT 0,\n          PRIMARY KEY (tid),\n          KEY state (state),\n          KEY kb_entry_id (kb_entry_id),\n          KEY sheet_id (sheet_id),\n          KEY greeting_post_id (greeting_post_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");
}

function af_cwf_get_row(int $tid): array
{
    global $db;

    if ($tid <= 0 || !is_object($db) || !$db->table_exists(AF_CWF_TABLE)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(AF_CWF_TABLE, '*', 'tid=' . $tid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_cwf_upsert_row(int $tid, array $data): void
{
    global $db;

    if ($tid <= 0 || !is_object($db) || !$db->table_exists(AF_CWF_TABLE)) {
        return;
    }

    $defaults = [
        'state' => 'draft',
        'kb_entry_id' => null,
        'sheet_id' => null,
        'sheet_slug' => null,
        'greeting_post_id' => null,
        'reviewed_by' => null,
        'accepted_by_uid' => null,
        'transferred_by_uid' => null,
        'accepted_at' => 0,
        'transferred_at' => 0,
        'revision_requested_at' => 0,
        'updated_at' => TIME_NOW,
    ];

    $row = af_cwf_get_row($tid);
    $payload = array_merge($defaults, $row ?: [], $data, ['tid' => $tid, 'updated_at' => TIME_NOW]);

    if ($row) {
        $db->update_query(AF_CWF_TABLE, af_charactersheets_db_escape_array($payload), 'tid=' . $tid);
        return;
    }

    $db->insert_query(AF_CWF_TABLE, af_charactersheets_db_escape_array($payload));
}

function af_cwf_accept_character_application(int $tid, int $actorUid, array $context = []): array
{
    $thread = is_array($context['thread'] ?? null) ? (array)$context['thread'] : [];
    $acceptedPid = (int)($context['accepted_pid'] ?? 0);

    $update = [
        'state' => 'approved',
        'accepted_by_uid' => $actorUid > 0 ? $actorUid : null,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
        'accepted_at' => TIME_NOW,
    ];

    if ($acceptedPid > 0) {
        $update['greeting_post_id'] = $acceptedPid;
    }

    if (!empty($thread)) {
        $tid = (int)($thread['tid'] ?? $tid);
    }

    af_cwf_upsert_row($tid, $update);

    return ['ok' => true, 'state' => 'approved', 'tid' => $tid];
}

function af_cwf_transfer_character_application(int $tid, int $actorUid, array $context = []): array
{
    $update = [
        'state' => 'transferred',
        'transferred_by_uid' => $actorUid > 0 ? $actorUid : null,
        'transferred_at' => TIME_NOW,
    ];

    $targetFid = (int)($context['target_fid'] ?? 0);
    if ($targetFid > 0) {
        $update['state'] = 'accepted';
    }

    af_cwf_upsert_row($tid, $update);

    return ['ok' => true, 'state' => $update['state'], 'tid' => $tid];
}

function af_cwf_mark_needs_revision(int $tid, int $actorUid): void
{
    af_cwf_upsert_row($tid, [
        'state' => 'needs_revision',
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
        'revision_requested_at' => TIME_NOW,
    ]);
}

function af_cwf_bind_kb_entry(int $tid, int $entryId, int $actorUid = 0): void
{
    af_cwf_upsert_row($tid, [
        'state' => 'under_review',
        'kb_entry_id' => $entryId > 0 ? $entryId : null,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
    ]);
}

function af_cwf_bind_sheet(int $tid, int $sheetId, string $sheetSlug, int $actorUid = 0): void
{
    af_cwf_upsert_row($tid, [
        'state' => 'under_review',
        'sheet_id' => $sheetId > 0 ? $sheetId : null,
        'sheet_slug' => trim($sheetSlug) !== '' ? trim($sheetSlug) : null,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
    ]);
}
