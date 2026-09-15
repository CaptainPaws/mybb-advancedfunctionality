<?php

$root = dirname(__DIR__);
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$kb = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$workflow = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');
$sheetsCrud = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/sheets_crud.php');
$sheetRender = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');

function canon_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

canon_lifecycle_assert(strpos($kb, "source_kb_id=' . (int)(\$entry['id']") !== false, 'Apply URL does not carry immutable KB id');
canon_lifecycle_assert(strpos($atf, "name=\"af_atf_prefill_token\"") !== false, 'Server-owned prefill token is not retained through submit');
canon_lifecycle_assert(strpos($workflow, "if (!empty(\$ctx['kb_linked']))") !== false, 'CREATE is not blocked for linked applications');
canon_lifecycle_assert(strpos($workflow, 'function af_cwf_validate_source_kb') !== false, 'Source KB validation is missing');
canon_lifecycle_assert(strpos($workflow, 'function af_cwf_has_linked_kb_character') !== false, 'Shared linked Character condition is missing');
canon_lifecycle_assert(strpos($workflow, "AND type='character' AND active=1") !== false, 'Linked Character condition does not validate type and active status');
canon_lifecycle_assert(strpos($sheetRender, 'af_cwf_has_linked_kb_character($tid, $accept_row)') !== false, 'Sheet resolver does not use the shared linked Character condition');
canon_lifecycle_assert(strpos($sheetRender, "'character_meta.source_uid'") === false, 'Sheet still guesses a Character link by user id');
canon_lifecycle_assert(substr_count($sheetRender, 'if ($has_kb_character)') >= 3, 'KB presence does not control profile and ability fallbacks');
canon_lifecycle_assert(strpos($sheetsCrud, 'function af_charactersheets_refresh_sheet_from_kb') !== false, 'Sheet KB refresh pipeline is missing');
canon_lifecycle_assert(strpos($atf, 'af_charactersheets_refresh_sheet_from_kb($tid') !== false, 'KB sync does not automatically refresh the Sheet');
canon_lifecycle_assert(strpos($atf, '$existing = $entryLink;') !== false, 'Linked original/legacy Character is not updated by immutable id');
canon_lifecycle_assert(strpos($atf, 'af_atf_bridge_sync_existing_canon') !== false, 'Canon update path is missing');
canon_lifecycle_assert(strpos($atf, "'canon_baseline'") !== false, 'Immutable canon baseline is missing');
canon_lifecycle_assert(substr_count($atf, "if (!isset(\$characterMeta['canon_baseline']))") === 1, 'Baseline is not write-once');
canon_lifecycle_assert(strpos($kb, "\$releaseMode === 'restore'") !== false, 'Baseline restore path is missing');
canon_lifecycle_assert(strpos($atf, "'role_history'") !== false, 'Role history is missing');
canon_lifecycle_assert(strpos($kb, "!af_cwf_is_allowed_forum") !== false, 'Archived forum detection is missing');
canon_lifecycle_assert(strpos($atf, "\$entryKey = 'oc-' . \$tid") !== false, 'Original-character CREATE path regressed');

echo "Canon character lifecycle regression checks passed\n";
