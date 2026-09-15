<?php

$root = dirname(__DIR__);
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$kb = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$workflow = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');

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
canon_lifecycle_assert(strpos($atf, 'af_atf_bridge_sync_existing_canon') !== false, 'Canon update path is missing');
canon_lifecycle_assert(strpos($atf, "'canon_baseline'") !== false, 'Immutable canon baseline is missing');
canon_lifecycle_assert(substr_count($atf, "if (!isset(\$characterMeta['canon_baseline']))") === 1, 'Baseline is not write-once');
canon_lifecycle_assert(strpos($kb, "\$releaseMode === 'restore'") !== false, 'Baseline restore path is missing');
canon_lifecycle_assert(strpos($atf, "'role_history'") !== false, 'Role history is missing');
canon_lifecycle_assert(strpos($kb, "!af_cwf_is_allowed_forum") !== false, 'Archived forum detection is missing');
canon_lifecycle_assert(strpos($atf, "\$entryKey = 'oc-' . \$tid") !== false, 'Original-character CREATE path regressed');

echo "Canon character lifecycle regression checks passed\n";

