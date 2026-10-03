<?php

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');
$profile = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$postbit = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/postbit.php');
$theme = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');

function active_application_gate_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$workflow, $profile, $postbit, $theme] as $source) {
    active_application_gate_assert($source !== false, 'A required source file could not be read');
}

active_application_gate_assert(
    strpos($workflow, 'function af_characterworkflow_resolve_active_application(int $uid): ?array') !== false,
    'The canonical active-application resolver is missing'
);
active_application_gate_assert(
    strpos($workflow, 'LEFT JOIN {$prefix}threads t ON t.tid=r.tid') !== false
        && strpos($workflow, "(int)(\$row['thread_uid'] ?? 0) !== \$uid") !== false
        && strpos($workflow, "in_array((int)(\$row['fid'] ?? 0), \$allowedForums, true)") !== false,
    'The resolver does not validate the live thread, owner and application forum'
);
active_application_gate_assert(
    strpos($workflow, "\$db->delete_query(\$relationTable, 'tid=' . \$tid") !== false
        && strpos($workflow, "\$db->delete_query(AF_CWF_TABLE, 'tid=' . \$tid)") !== false,
    'Orphan application relations are not invalidated'
);
active_application_gate_assert(
    substr_count($profile, 'af_characterworkflow_resolve_active_application') >= 4,
    'Profile composition and lazy Character Sheet are not both application-gated'
);
active_application_gate_assert(
    strpos($profile, "return ['tid' => 0, 'about_html' => '', 'fields' => []];") !== false
        && strpos($profile, "return '';") !== false,
    'A profile without an application does not fail closed'
);
active_application_gate_assert(
    substr_count($postbit, 'af_characterworkflow_resolve_active_application') >= 2
        && strpos($postbit, 'af_charactersheets_get_sheet_slug_by_uid($uid)') !== false,
    'CharacterSheet/application postbit payload can bypass the canonical gate'
);
active_application_gate_assert(
    strpos($theme, 'af_apui_get_profile_character_payload($uid)') !== false
        && strpos($theme, "return \$cache[\$uid] = function_exists('af_atf_resolve_element_theme_key')") !== false,
    'Postbit element is not derived from the gated profile payload'
);
active_application_gate_assert(
    strpos($workflow, 'af_wanted') === false || strpos($workflow, 'function af_characterworkflow_resolve_active_application') < strpos($workflow, 'af_wanted_application_accepted'),
    'Resolver unexpectedly depends on Wanted data'
);

echo "active application profile gate regression: OK\n";
