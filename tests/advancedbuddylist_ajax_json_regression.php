<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$buddy = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$js = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.js');
$manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php');

foreach ([$buddy,$js,$manifest] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to read Buddy List native action sources.');
}

foreach ([
    "action=\"usercp.php\"",
    "'my_post_key'",
    "'af_abdl_return'=>'1'",
    "'action'=>'do_editlists'",
    "'action'=>'acceptrequest'",
    "'action'=>'declinerequest'",
    "'action'=>'cancelrequest'",
] as $needle) {
    if (!str_contains($buddy,$needle)) throw new RuntimeException('Missing native User CP form contract: '.$needle);
}

foreach ([
    "data.set('ajax','1')",
    "fetch(form.action",
    "return r.text()",
    "fetch(location.href",
] as $needle) {
    if (!str_contains($js,$needle)) throw new RuntimeException('Missing native User CP AJAX bridge: '.$needle);
}

foreach (['application/json','JSON.parse(text)','buddy.php?ajax=1'] as $obsolete) {
    if (str_contains($js,$obsolete)) throw new RuntimeException('Obsolete private Buddy JSON API is still used: '.$obsolete);
}

if (!str_contains($manifest, "'version'     => '2.3.0'")) throw new RuntimeException('Advanced Buddy List version mismatch.');
echo "Advanced Buddy List native User CP action bridge regression: OK\n";
