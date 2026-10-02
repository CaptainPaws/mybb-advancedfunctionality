<?php
define('IN_MYBB', 1);
$root=__DIR__.'/../';
$manifest=require $root.'inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php';
$source=file_get_contents($root.'inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$js=file_get_contents($root.'inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.js');
if(($manifest['id']??'')!=='advancedbuddylist')throw new RuntimeException('Buddy manifest id is not canonical.');
if(($manifest['frontend']['mode']??'')!=='contextual'||($manifest['frontend']['routes']??[])!==[['script'=>'buddy.php']]||($manifest['frontend']['directory_fallback']??null)!==false)throw new RuntimeException('Buddy frontend manifest must target only buddy.php.');
foreach(['verify_post_check','requester_uid','addressee_uid','pair_low','pair_high','af_abdl_is_ignored_either_way','af_abdl_sync_legacy_user','af_abdl_migrate_legacy'] as $needle)if(!str_contains($source,$needle))throw new RuntimeException('Missing friendship safety contract: '.$needle);
foreach(['fetch(','FormData','credentials:\'same-origin\'','300'] as $needle)if(!str_contains($js,$needle))throw new RuntimeException('Missing progressive AJAX behavior: '.$needle);
if(!str_contains(file_get_contents($root.'inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/buddy.php'),"define('THIS_SCRIPT', 'buddy.php')"))throw new RuntimeException('Dedicated buddy page missing.');
echo "Advanced Buddy List frontend and persistence checks passed.\n";
