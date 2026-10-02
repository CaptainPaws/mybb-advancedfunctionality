<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$buddy = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$css = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.css');
$js = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.js');
$formatter = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentionsformatters.php');
$manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php');

foreach ([$buddy,$css,$js,$formatter,$manifest] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to read Buddy List search sources.');
}

foreach ([
    "\$mybb->input['tab']??'friends'",
    "['friends','ignore','search']",
    "function af_abdl_search_action",
    "fa-user-plus",
    "\$where=\"uid<>{\$uid}\"",
    "'order_by'=>'username','order_dir'=>'ASC','limit'=>50",
    "af_abdl_card(\$u,af_abdl_search_action(\$uid,(int)\$u['uid']))",
    "['url'=>'buddy.php?tab=friends']",
] as $needle) {
    if (!str_contains($buddy,$needle)) throw new RuntimeException('Buddy search/friends contract missing: '.$needle);
}

if (!str_contains($css,'.af-abdl-icon-btn')) {
    throw new RuntimeException('Icon-only add-friend button styling is missing.');
}

if (!str_contains($js,"value.length===1") || !str_contains($js,"buddy.php?tab=search")) {
    throw new RuntimeException('Search list refresh contract is missing.');
}

if (!str_contains($formatter,"!empty(\$extra['url']) ? (string)\$extra['url'] : 'usercp.php?action=editlists'")) {
    throw new RuntimeException('Buddy request alert does not prefer the addon URL with MyBB fallback.');
}

if (!str_contains($manifest,"'version'     => '2.2.0'")) {
    throw new RuntimeException('Advanced Buddy List version mismatch.');
}

echo "Advanced Buddy List search and request UX regression: OK\n";
