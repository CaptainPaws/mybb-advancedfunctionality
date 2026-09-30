<?php
$source=file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbyddylist/advancedbyddylist.php');
$manifest=file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbyddylist/manifest.php');
foreach(["UNIQUE KEY `pair` (`pair_low`,`pair_high`)","enum('pending','accepted')","UNIQUE KEY `owner_target` (`uid`,`ignored_uid`)","if (\$relation) \$db->delete_query",'Relations intentionally survive deactivation','User relations are retained deliberately'] as $needle)if(!str_contains($source,$needle))throw new RuntimeException('Missing relation invariant: '.$needle);
foreach(['friends','ignore','search'] as $tab)if(!str_contains($source,"'{$tab}'"))throw new RuntimeException('Missing tab: '.$tab);
if(!str_contains($manifest,"['script' => 'buddy.php']"))throw new RuntimeException('Manifest route missing.');
echo "Advanced Buddy List friendship model checks passed.\n";
