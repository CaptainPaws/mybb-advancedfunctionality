<?php
$root = dirname(__DIR__);
$wanted = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$workflow = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');

$checks = [
    'acceptance uses the application owner uid' => strpos($workflow, "af_wanted_application_accepted((int)\$workflow['wanted_id'], \$tid, (int)(\$thread['uid'] ?? 0))") !== false,
    'acceptance archives only the exact linked application' => strpos($wanted, "status='application' AND application_tid=\$tid") !== false,
    'archive retains application tid while recording player and time' => strpos($wanted, "['status'=>'archived','accepted_uid'=>\$acceptedUid,'archived_at'=>TIME_NOW") !== false,
    'active and archive tabs split lifecycle states' => strpos($wanted, "e.status='archived'") !== false
        && strpos($wanted, "e.status IN ('open','reserved','application')") !== false,
    'archive card and detail expose player profile' => substr_count($wanted, "Игрок: '.build_profile_link") >= 2,
    'archive exposes accepted application topic' => strpos($wanted, "Принятая анкета") !== false,
    'workflow registers hard delete release' => strpos($workflow, "class_moderation_delete_thread_start', 'af_cwf_application_thread_released") !== false,
    'workflow registers reject and cancellation releases' => strpos($workflow, "class_moderation_soft_delete_threads', 'af_cwf_application_threads_released") !== false
        && strpos($workflow, "class_moderation_unapprove_threads', 'af_cwf_application_threads_released") !== false,
    'direct handler deletion is covered' => strpos($workflow, "datahandler_post_delete_thread', 'af_cwf_application_thread_deleted") !== false,
    'ordinary applications are ignored' => strpos($workflow, "if (\$wantedId <= 0 || !function_exists('af_wanted_application_released'))") !== false,
    'release verifies exact topic and clears application relation' => strpos($wanted, "AND status='application' AND application_tid=") !== false
        && strpos($wanted, "'application_tid'=>null") !== false,
    'release restores reservation only for the same applicant' => strpos($wanted, "\$reserved=(int)\$e['reserved_by_uid']===\$applicantUid&&\$applicantUid>0") !== false
        && strpos($wanted, "'status'=>\$reserved?'reserved':'open'") !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) $failed[] = $label;
}
exit($failed ? 1 : 0);
