<?php
$root=dirname(__DIR__);
$core=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$admin=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php');
$workflow=file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');
$page=file_get_contents($root.'/wanted.php');
$checks=[
 'public page delegates to addon'=>strpos($page,'af_wanted_render_page')!==false,
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
 'ACP field builder'=>strpos($admin,"do==='save_field'")!==false,
 'safe CSRF actions'=>strpos($admin,'verify_post_check')!==false,
];
$failed=[];foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failed[]=$label;}exit($failed?1:0);
