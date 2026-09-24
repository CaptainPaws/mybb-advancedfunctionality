<?php
$root = dirname(__DIR__);
$wanted = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$workflow = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');

$checks = [
    'apply enters the configured ATF new-thread flow' => strpos($wanted, "redirect('newthread.php?fid='." . '$fid') !== false,
    'intent binds wanted user forum expiry and HMAC' => strpos($wanted, "\$payload=\$id.'.'.\$uid.'.'.\$fid.'.'.(TIME_NOW+1800)") !== false
        && strpos($wanted, "hash_hmac('sha256',\$payload,af_wanted_intent_secret())") !== false,
    'decoder rejects expired and overlong intents' => strpos($wanted, '$expires<TIME_NOW||$expires>TIME_NOW+1800') !== false,
    'thread hook verifies persisted forum and author' => strpos($wanted, "simple_select('threads','tid,fid,uid'") !== false
        && strpos($wanted, "(int)(\$thread['fid']??0)!==(int)\$intent['fid']") !== false
        && strpos($wanted, "(int)(\$thread['uid']??0)!==(int)\$intent['uid']") !== false,
    'intent is consumed only after a successful exact link' => strpos($wanted, "if(af_wanted_link_application") !== false
        && strpos($wanted, "))my_unsetcookie('af_wanted_apply')") !== false,
    'link transition atomically preserves reservation ownership' => strpos($wanted, "(status='open' OR (status='reserved' AND reserved_by_uid=\$uid))") !== false,
    'successful link writes CharacterWorkflow wanted metadata' => strpos($wanted, "af_cwf_upsert_row(\$tid,['wanted_id'=>\$wantedId])") !== false,
    'workflow migration adds nullable wanted column' => strpos($workflow, "add_column(AF_CWF_TABLE, 'wanted_id', 'INT UNSIGNED DEFAULT NULL") !== false,
    'workflow migration repairs a missing wanted index' => strpos($workflow, "index_exists(AF_CWF_TABLE, 'wanted_id')") !== false
        && strpos($workflow, 'ADD KEY wanted_id (wanted_id)') !== false,
    'new ordinary workflows default to no Wanted' => strpos($workflow, "'wanted_id' => null") !== false,
    'application UI has submitted label and topic link' => strpos($wanted, "'application'=>'Анкета подана'") !== false
        && strpos($wanted, '>Открыть анкету</a>') !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) $failed[] = $label;
}
exit($failed ? 1 : 0);
