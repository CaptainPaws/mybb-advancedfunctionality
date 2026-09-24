<?php
$root=dirname(__DIR__);
$core=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$admin=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php');
$manifest=require $root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/manifest.php';
$assetPage=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/wanted.php');
$afCore=file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$afRouter=file_get_contents($root.'/inc/plugins/advancedfunctionality/admin/router.php');
$workflow=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');
$page=file_get_contents($root.'/wanted.php');
$checks=[
 'public entry point has MyBB bootstrap'=>strpos($page,"define('IN_MYBB', 1)")!==false&&strpos($page,"define('THIS_SCRIPT', 'wanted.php')")!==false&&strpos($page,"require_once __DIR__.'/global.php'")!==false,
 'public page delegates to addon'=>strpos($page,'af_wanted_render_page();')!==false,
 'installable public alias is canonical root page'=>$page===$assetPage&&strpos($assetPage,'AF_WANTED_PAGE_ALIAS')!==false,
 'addon lifecycle installs public alias'=>strpos($core,'af_wanted_ensure_page_alias();')!==false&&strpos($core,"MYBB_ROOT.'wanted.php'")!==false,
 'runtime init performs no schema migration'=>preg_match('~function af_advancedwanted_init\(\): void \{(?<body>.*?)\n\}~s',$core,$initMatch)===1&&strpos($initMatch['body'],'af_wanted_ensure_schema')===false,
 'manifest exposes AF ACP controller'=>($manifest['admin']['slug']??'')==='advancedwanted'&&($manifest['admin']['controller']??'')==='admin.php'&&($manifest['admin']['title']??'')==='AdvancedWanted',
 'ACP router loads addon bootstrap before controller'=>strpos($afCore,"require_once \$ctrl['bootstrap']")!==false&&strpos($afRouter,"require_once \$ctrl['bootstrap']")!==false,
 'ACP router class and dispatch exist'=>strpos($admin,'class AF_Admin_Advancedwanted')!==false&&strpos($admin,'function dispatch')!==false,
 'ACP landing navigation is visible'=>count(array_filter(['AdvancedWanted','Записи','Поля формы','Настройки'],fn($label)=>strpos($admin,$label)!==false))===4,
 'permission keys contain no escaped underscores'=>strpos($core,"'af_wanted_'.\$name.'_groups'")!==false&&strpos($core,'af_wanted\\_')===false,
 'all permission actions are mapped'=>count(array_filter(['view','create','edit','delete','moderate','reserve','apply'],fn($action)=>strpos($core,"'{$action}'")!==false))===7,
 'three independent tables'=>strpos($core,"'af_wanted_entries'")!==false&&strpos($core,"'af_wanted_fields'")!==false&&strpos($core,"'af_wanted_values'")!==false,
 'immutable auto increment id'=>strpos($core,'id INT UNSIGNED NOT NULL AUTO_INCREMENT')!==false,
 'all lifecycle states'=>count(array_filter(['open','reserved','application','archived'],fn($s)=>strpos($core,"'$s'")!==false))===4,
 'reservation is atomic'=>strpos($core,"AND status='open'")!==false&&strpos($core,'affected_rows()!==1')!==false,
 'dynamic filters use SQL EXISTS'=>strpos($core,'EXISTS (SELECT 1 FROM')!==false,
 'GET progressive filters'=>strpos($core,'class="af-wanted-filters" method="get"')!==false,
 'KB option resolver reused'=>strpos($core,'af_kb_get_arpg_mechanics_options')!==false&&strpos($core,'af_kb_get_origin_variants')!==false,
 'signed server transport'=>strpos($core,"hash_hmac('sha256'")!==false&&strpos($core,'datahandler_post_insert_thread')!==false,
 'workflow metadata migration'=>strpos($workflow,"field_exists('wanted_id'")!==false,
 'acceptance archives wanted'=>strpos($workflow,'af_wanted_application_accepted')!==false,
 'ordinary workflow remains nullable'=>strpos($workflow,'wanted_id INT UNSIGNED DEFAULT NULL')!==false,
 'ACP field builder'=>strpos($admin, '$do === \'save_field\'')!==false,
 'safe CSRF actions'=>strpos($admin,'verify_post_check')!==false,
 'create and edit use active schema in configured order'=>strpos($core,"af_wanted_validate(af_wanted_fields()")!==false&&strpos($core,"'order_by'=>'sortorder ASC, id ASC'")!==false,
 'edit only updates mutable timestamp'=>strpos($core,"update_query(AF_WANTED_ENTRIES,['updated_at'=>TIME_NOW],'id='.\$id)")!==false,
 'detail renders author and configured detail fields'=>strpos($core,"Автор: '.build_profile_link")!==false&&strpos($core,"['show_detail']")!==false,
 'delete permission has a POST CSRF UI'=>strpos($core,'action="wanted.php?action=delete&id=')!==false&&strpos($core,"af_wanted_can('delete',\$e)")!==false,
 'catalog excludes textarea from card values'=>strpos($core,"!in_array(\$f['type'],['select','radio','kb_dynamic'],true)")!==false,
];
$failed=[];foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failed[]=$label;}exit($failed?1:0);
