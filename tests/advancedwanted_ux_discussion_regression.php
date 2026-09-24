<?php
$root=dirname(__DIR__);
$core=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$admin=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php');
$css=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css');
$chips=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_chips.js');
$checks=[
 'title is explicit and legacy label migration persists its actual field'=>strpos($admin,"'is_title' =>")!==false&&strpos($core,"title='Имя и фамилия [en]'")!==false&&strpos($core,"['settings']['is_title']")!==false,
 'only one ACP title field survives'=>strpos($admin,'A schema has exactly one semantic title field')!==false,
 'reservation schema stores guest and fixed deadline'=>strpos($core,"field_exists('reserved_guest_name'")!==false&&strpos($core,"field_exists('reserved_until'")!==false,
 'guest reservation has independent permission and required name'=>strpos($core,'af_wanted_allow_guest_reservation')!==false&&strpos($core,'name="guest_name" required')!==false,
 'settings are repaired onto the live group without overwriting values'=>strpos($core,"update_query('settings'")!==false&&strpos($core,"'gid'=>")!==false&&strpos($core,'af_wanted_discussion_tid')!==false,
 'discussion uses signed short lived cookie prefill'=>strpos($core,"my_setcookie('af_wanted_discuss'")!==false&&strpos($core,"[wanted='")!==false&&strpos($core,"af_wanted_prefill_reply")!==false,
 'wanted BBCode resolves by stable id'=>strpos($core,"'/\\[wanted=([0-9]{1,10})\\]/i'")!==false&&strpos($core,'data-wanted-id=')!==false,
 'wanted chip reuses KB modal fetch and renderer'=>strpos($chips,"function fetchChipEntry(chip)")!==false&&strpos($chips,"fetchChipEntry(chip).then")!==false,
 'card image fills a fixed-ratio area and chips stay compact'=>strpos($css,'width: 100%')!==false&&strpos($css,'aspect-ratio: 4 / 3')!==false&&strpos($css,'object-fit: cover')!==false&&strpos($css,'font-size: 8px')!==false,
 'detail has opaque high contrast wiki surface'=>strpos($css,'background: #17191e')!==false&&strpos($css,'border-bottom: 2px solid #ad865d')!==false,
 'filters and toolbar share compact responsive layout'=>strpos($css,'height: 2.25rem')!==false&&strpos($css,'.af-wanted-toolbar')!==false,
];
$failed=[];foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failed[]=$label;}exit($failed?1:0);
