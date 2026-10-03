<?php
$source=file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$manifest=file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php');

foreach ([
    "'buddyrequests'",
    "'buddylist'",
    "'ignorelist'",
    "'action'=>'do_editlists'",
    "'manage'=>'buddy'",
    "'manage'=>'ignored'",
    "'action'=>'acceptrequest'",
    "'action'=>'declinerequest'",
    "'action'=>'cancelrequest'",
    "action=\"usercp.php\"",
] as $needle) {
    if (!str_contains($source,$needle)) throw new RuntimeException('Missing native MyBB friendship contract: '.$needle);
}
foreach (['friends','ignore','search'] as $tab) {
    if(!str_contains($source,"'{$tab}'")) throw new RuntimeException('Missing tab: '.$tab);
}
foreach (['AF_ABDL_FRIENDSHIPS','AF_ABDL_IGNORES','af_abdl_apply_action','af_abdl_sync_legacy_user'] as $legacy) {
    if (str_contains($source,$legacy)) throw new RuntimeException('Legacy parallel friendship runtime is still active: '.$legacy);
}
if(!str_contains($manifest,"['script' => 'buddy.php']")) throw new RuntimeException('Manifest route missing.');
echo "Advanced Buddy List native MyBB friendship model checks passed.\n";
