<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$buddy = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$css = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.css');
$js = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.js');
$aam = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php');
$manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php');

foreach ([$buddy,$css,$js,$aam,$manifest] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to read Buddy List search sources.');
}

foreach ([
    "\$mybb->input['tab']??'friends'",
    "['friends','ignore','search']",
    "function af_abdl_search_action",
    "fa-user-plus",
    "\$where=\"uid<>{\$uid}\"",
    "'order_by'=>'username','order_dir'=>'ASC','limit'=>50",
    "af_abdl_search_action(\$user,\$state)",
    "'action'=>'do_editlists'",
    "'manage'=>'buddy'",
    "'add_username'=>(string)\$user['username']",
    "'action'=>'acceptrequest'",
    "'action'=>'declinerequest'",
    "'action'=>'cancelrequest'",
    "\$assetVersion='2.3.1'",
] as $needle) {
    if (!str_contains($buddy,$needle)) throw new RuntimeException('Buddy search/native request contract missing: '.$needle);
}

if (!str_contains($css,'.af-abdl-icon-btn')) {
    throw new RuntimeException('Icon-only add-friend button styling is missing.');
}
if (!str_contains($js,"data.set('ajax','1')") || !str_contains($js,"fetch(form.action")) {
    throw new RuntimeException('Native User CP AJAX bridge is missing.');
}
if (!str_contains($aam,"\$mybb->get_input('af_abdl_return') !== '' ? 'buddy.php?tab=friends' : 'usercp.php?action=editlists'")) {
    throw new RuntimeException('Native buddy request alert does not return to Buddy List.');
}
if (!str_contains($manifest,"'version'     => '2.3.1'")) {
    throw new RuntimeException('Advanced Buddy List version mismatch.');
}

echo "Advanced Buddy List search and native request UX regression: OK\n";
