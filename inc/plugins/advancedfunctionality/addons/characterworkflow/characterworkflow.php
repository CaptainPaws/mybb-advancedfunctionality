<?php
/**
 * AF Addon: CharacterWorkflow
 * Оркестратор модерации анкеты персонажа (ATF + KB + CharacterSheets + moderation).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* optional: AF core expected */ }

define('AF_CWF_ID', 'characterworkflow');
define('AF_CWF_TABLE', 'af_character_workflow');
define('AF_CWF_STATE_DRAFT', 'draft');
define('AF_CWF_STATE_UNDER_REVIEW', 'under_review');
define('AF_CWF_STATE_NEEDS_REVISION', 'needs_revision');
define('AF_CWF_STATE_APPROVED', 'approved');
define('AF_CWF_STATE_ACCEPTED', 'accepted');
define('AF_CWF_STATE_TRANSFERRED', 'transferred');

function af_characterworkflow_install(): bool
{
    af_cwf_ensure_schema();
    af_cwf_ensure_settings();
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    return true;
}

function af_characterworkflow_activate(): bool
{
    af_cwf_ensure_schema();
    af_cwf_ensure_settings();
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    return true;
}

function af_characterworkflow_init(): void
{
    af_cwf_ensure_schema();
}

function af_characterworkflow_uninstall(): void
{
    global $db;
    if (!is_object($db)) {
        return;
    }

    $db->delete_query('settings', "name IN (
        'af_characterworkflow_enabled',
        'af_characterworkflow_target_forums',
        'af_characterworkflow_transfer_group_ids',
        'af_characterworkflow_greeting_mode',
        'af_characterworkflow_canon_source_policy',
        'af_characterworkflow_original_source_policy'
    )");
    $db->delete_query('settinggroups', "name='af_characterworkflow'");
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
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
        $db->update_query(AF_CWF_TABLE, af_cwf_db_escape_array($payload), 'tid=' . $tid);
        return;
    }

    $db->insert_query(AF_CWF_TABLE, af_cwf_db_escape_array($payload));
}

function af_cwf_load_lang(): void
{
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang(AF_CWF_ID);
    }
}

function af_cwf_ensure_settings(): void
{
    global $lang;
    af_cwf_load_lang();

    $gid = af_cwf_ensure_group(
        'af_characterworkflow',
        $lang->af_characterworkflow_group ?? 'AF: CharacterWorkflow',
        $lang->af_characterworkflow_group_desc ?? 'Character application workflow orchestrator settings.'
    );

    af_cwf_ensure_setting($gid, 'af_characterworkflow_enabled', $lang->af_characterworkflow_enabled ?? 'Enable CharacterWorkflow', $lang->af_characterworkflow_enabled_desc ?? 'Enables workflow state table and moderation orchestration API.', 'yesno', '1', 1);
    af_cwf_ensure_setting($gid, 'af_characterworkflow_target_forums', $lang->af_characterworkflow_target_forums ?? 'Target accepted forum ids', $lang->af_characterworkflow_target_forums_desc ?? 'CSV forum IDs used as transfer target(s).', 'text', '', 2);
    af_cwf_ensure_setting($gid, 'af_characterworkflow_transfer_group_ids', $lang->af_characterworkflow_transfer_group_ids ?? 'Groups to assign on transfer', $lang->af_characterworkflow_transfer_group_ids_desc ?? 'CSV group IDs assigned to thread author after transfer.', 'text', '', 3);
    af_cwf_ensure_setting($gid, 'af_characterworkflow_greeting_mode', $lang->af_characterworkflow_greeting_mode ?? 'Greeting behavior', $lang->af_characterworkflow_greeting_mode_desc ?? 'inherit = CharacterSheets logic, always = always attempt greeting post, never = do not post greeting.', "select\ninherit=Inherit CharacterSheets\nalways=Always post\nnever=Never post", 'inherit', 4);
    af_cwf_ensure_setting($gid, 'af_characterworkflow_canon_source_policy', $lang->af_characterworkflow_canon_source_policy ?? 'Canon source-of-truth policy', $lang->af_characterworkflow_canon_source_policy_desc ?? 'Defines policy for canon characters.', "select\nkb=KB only", 'kb', 5);
    af_cwf_ensure_setting($gid, 'af_characterworkflow_original_source_policy', $lang->af_characterworkflow_original_source_policy ?? 'Original source-of-truth policy', $lang->af_characterworkflow_original_source_policy_desc ?? 'Defines policy for original characters before/after KB creation.', "select\nthread_then_kb=Thread/ATF before KB, KB after create", 'thread_then_kb', 6);
}

function af_cwf_is_enabled(): bool
{
    global $mybb;
    return !isset($mybb->settings['af_characterworkflow_enabled']) || !empty($mybb->settings['af_characterworkflow_enabled']);
}

function af_cwf_csv_to_ids(string $csv): array
{
    $items = array_filter(array_map('trim', explode(',', $csv)), static function ($item) {
        return $item !== '';
    });
    $ids = array_map('intval', $items);
    return array_values(array_unique(array_filter($ids, static function ($id) {
        return $id > 0;
    })));
}

function af_cwf_get_target_forum_ids(): array
{
    global $mybb;
    $ids = af_cwf_csv_to_ids((string)($mybb->settings['af_characterworkflow_target_forums'] ?? ''));
    if (!empty($ids)) {
        return $ids;
    }
    $fallback = (int)($mybb->settings['af_charactersheets_accepted_forum'] ?? 0);
    return $fallback > 0 ? [$fallback] : [];
}

function af_cwf_get_transfer_group_ids(): array
{
    global $mybb;
    return af_cwf_csv_to_ids((string)($mybb->settings['af_characterworkflow_transfer_group_ids'] ?? ''));
}

function af_cwf_detect_character_kind(int $tid, int $uid, array $acceptRow = []): string
{
    if (!function_exists('af_charactersheets_resolve_character_kb_entry')) {
        return 'original';
    }
    $source = af_charactersheets_resolve_character_kb_entry($tid, $uid, $acceptRow);
    $profile = (array)(($source['payload'] ?? [])['profile'] ?? []);
    $category = trim((string)($profile['category'] ?? ''));
    if ($category === 'canons') {
        return 'canon';
    }
    return 'original';
}

function af_cwf_get_context(int $tid, array $thread = [], array $acceptRow = []): array
{
    global $db;
    if ($tid <= 0) {
        return [];
    }
    if (empty($thread) && is_object($db)) {
        $thread = (array)$db->fetch_array($db->simple_select('threads', '*', 'tid=' . $tid, ['limit' => 1]));
    }
    if (empty($acceptRow) && function_exists('af_charactersheets_get_accept_row')) {
        $acceptRow = af_charactersheets_get_accept_row($tid);
    }
    $uid = (int)($thread['uid'] ?? ($acceptRow['uid'] ?? 0));
    $workflow = af_cwf_get_row($tid);
    $kbLinked = (int)($workflow['kb_entry_id'] ?? ($acceptRow['kb_entry_id'] ?? 0)) > 0;
    $sheetExists = function_exists('af_charactersheets_resolve_existing_sheet_for_thread')
        ? !empty(af_charactersheets_resolve_existing_sheet_for_thread($tid, $uid, $acceptRow))
        : ((int)($workflow['sheet_id'] ?? 0) > 0);
    $kind = af_cwf_detect_character_kind($tid, $uid, $acceptRow);

    return [
        'tid' => $tid,
        'thread' => $thread,
        'accept' => $acceptRow,
        'workflow' => $workflow,
        'uid' => $uid,
        'fid' => (int)($thread['fid'] ?? 0),
        'state' => (string)($workflow['state'] ?? ''),
        'was_accepted' => function_exists('af_charactersheets_is_accepted') ? af_charactersheets_is_accepted($tid) : false,
        'is_pending_forum' => function_exists('af_charactersheets_is_pending_forum') ? af_charactersheets_is_pending_forum((int)($thread['fid'] ?? 0)) : false,
        'is_target_forum' => in_array((int)($thread['fid'] ?? 0), af_cwf_get_target_forum_ids(), true),
        'kb_linked' => $kbLinked,
        'sheet_exists' => $sheetExists,
        'character_kind' => $kind,
    ];
}

function af_cwf_can_accept(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    return !empty($ctx['is_pending_forum']);
}

function af_cwf_can_transfer(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    return !empty($ctx['was_accepted']) && empty($ctx['is_target_forum']);
}

function af_cwf_can_create_sheet(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    return empty($ctx['sheet_exists']);
}

function af_cwf_can_create_kb(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    if (($ctx['character_kind'] ?? 'original') === 'canon') {
        return false;
    }
    return empty($ctx['kb_linked']);
}

function af_cwf_can_sync_kb(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    if (($ctx['character_kind'] ?? 'original') === 'canon') {
        return false;
    }
    return !empty($ctx['kb_linked']);
}

function af_cwf_can_request_revision(int $tid, array $thread = [], array $acceptRow = []): bool
{
    $ctx = af_cwf_get_context($tid, $thread, $acceptRow);
    return !empty($ctx['was_accepted']) || !empty($ctx['is_pending_forum']);
}

function af_cwf_accept_character_application(int $tid, int $actorUid, array $context = []): array
{
    $thread = is_array($context['thread'] ?? null) ? (array)$context['thread'] : [];
    $acceptedPid = (int)($context['accepted_pid'] ?? 0);

    $update = [
        'state' => AF_CWF_STATE_APPROVED,
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

    return ['ok' => true, 'state' => AF_CWF_STATE_APPROVED, 'tid' => $tid];
}

function af_cwf_transfer_character_application(int $tid, int $actorUid, array $context = []): array
{
    global $db;

    $thread = is_array($context['thread'] ?? null) ? (array)$context['thread'] : [];
    if (empty($thread) && is_object($db)) {
        $thread = (array)$db->fetch_array($db->simple_select('threads', '*', 'tid=' . $tid, ['limit' => 1]));
    }

    $update = [
        'state' => AF_CWF_STATE_TRANSFERRED,
        'transferred_by_uid' => $actorUid > 0 ? $actorUid : null,
        'transferred_at' => TIME_NOW,
    ];

    $targetFid = (int)($context['target_fid'] ?? 0);
    if ($targetFid > 0) {
        $update['state'] = AF_CWF_STATE_ACCEPTED;
    }

    af_cwf_upsert_row($tid, $update);
    af_cwf_assign_transfer_groups((int)($thread['uid'] ?? 0));

    return ['ok' => true, 'state' => $update['state'], 'tid' => $tid];
}

function af_cwf_mark_needs_revision(int $tid, int $actorUid): void
{
    af_cwf_upsert_row($tid, [
        'state' => AF_CWF_STATE_NEEDS_REVISION,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
        'revision_requested_at' => TIME_NOW,
    ]);
}

function af_cwf_bind_kb_entry(int $tid, int $entryId, int $actorUid = 0): void
{
    $current = af_cwf_get_row($tid);
    if ((int)($current['kb_entry_id'] ?? 0) > 0 && (int)($current['kb_entry_id'] ?? 0) !== $entryId) {
        return;
    }
    af_cwf_upsert_row($tid, [
        'state' => AF_CWF_STATE_UNDER_REVIEW,
        'kb_entry_id' => $entryId > 0 ? $entryId : null,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
    ]);
}

function af_cwf_bind_sheet(int $tid, int $sheetId, string $sheetSlug, int $actorUid = 0): void
{
    $current = af_cwf_get_row($tid);
    if ((int)($current['sheet_id'] ?? 0) > 0 && (int)($current['sheet_id'] ?? 0) !== $sheetId) {
        return;
    }
    af_cwf_upsert_row($tid, [
        'state' => AF_CWF_STATE_UNDER_REVIEW,
        'sheet_id' => $sheetId > 0 ? $sheetId : null,
        'sheet_slug' => trim($sheetSlug) !== '' ? trim($sheetSlug) : null,
        'reviewed_by' => $actorUid > 0 ? $actorUid : null,
    ]);
}

function af_cwf_assign_transfer_groups(int $uid): void
{
    global $db;
    if ($uid <= 0 || !is_object($db)) {
        return;
    }
    $groups = af_cwf_get_transfer_group_ids();
    if (empty($groups)) {
        return;
    }
    $primary = (int)array_shift($groups);
    $additional = implode(',', $groups);
    $payload = ['usergroup' => $primary, 'additionalgroups' => $additional];
    $db->update_query('users', af_cwf_db_escape_array($payload), 'uid=' . $uid);
}

function af_cwf_ensure_group(string $name, string $title, string $desc): int
{
    global $db;
    $q = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid > 0) {
        return $gid;
    }
    $max = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm');
    $db->insert_query('settinggroups', [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder' => $max + 1,
        'isdefault' => 0,
    ]);
    return (int)$db->insert_id();
}

function af_cwf_ensure_setting(int $gid, string $name, string $title, string $desc, string $type, string $value, int $order): void
{
    global $db;
    $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'sid');
    $row = [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value' => $db->escape_string($value),
        'disporder' => $order,
        'gid' => $gid,
    ];
    if ($sid > 0) {
        $db->update_query('settings', $row, 'sid=' . $sid);
        return;
    }
    $db->insert_query('settings', $row);
}

function af_cwf_db_escape_array(array $data): array
{
    global $db;
    $out = [];
    foreach ($data as $k => $v) {
        if ($v === null) {
            $out[$k] = '';
            continue;
        }
        if (is_bool($v)) {
            $out[$k] = $v ? 1 : 0;
            continue;
        }
        if (is_int($v) || is_float($v)) {
            $out[$k] = $v;
            continue;
        }
        $out[$k] = $db->escape_string((string)$v);
    }
    return $out;
}
