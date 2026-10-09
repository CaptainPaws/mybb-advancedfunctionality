<?php
define('IN_MYBB', true);
define('THIS_SCRIPT', 'showthread.php');
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
function check(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
function htmlspecialchars_uni($s): string { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
$mybb=(object)['settings'=>[],'user'=>['uid'=>42],'usergroup'=>[]];
$plugins=new class {public array $hooks=[];function add_hook($hook,$function,$priority=10){$this->hooks[$hook][]=$function;}};
$db=new class {
 public string $table_prefix='mybb_'; public int $reads=0;public int $tableChecks=0;public bool $missing=false;
 function table_exists($table){++$this->tableChecks;return !$this->missing;}
 function query($q){++$this->reads;return ['rows'=>[['pid'=>1,'reputation'=>1,'comments'=>'good','username'=>'Voter']],'i'=>0];}
 function simple_select($table,...$args){++$this->reads;return ['rows'=>[['uid'=>42],['uid'=>43]],'i'=>0];}
 function fetch_array(&$q){return $q['rows'][$q['i']++]??false;}
 function write_query($q){throw new RuntimeException('DDL on frontend');}
};
af_adaptivethemeframework_init();
check($db->tableChecks===0 && $db->reads===0,'Frontend init accessed schema/data');
check(in_array('af_adaptivethemeframework_preload_postbit_data',$plugins->hooks['postbit']), 'Missing preload hook');
$pids='1,2';$post=['pid'=>1,'uid'=>42,'tid'=>77,'username'=>'Author','message'=>'text'];
$authorCalls=[];
function af_apf_get_secondary_avatar(int $uid): string {global $authorCalls;$authorCalls[$uid]=($authorCalls[$uid]??0)+1;return '/secondary-'.$uid.'.png';}
function af_apui_get_profile_character_payload(int $uid): array {return ['fields'=>['character_element'=>['value'=>'fire']]];}
function af_elementtheme_resolve_key(string $key): string {return $key==='fire'?'fire':'';}
// Exercise the real presentation gate with the test's approved character provider.
function af_apui_is_approved_character_application(int $uid, int $tid, array $relation): bool { return $uid === 42; }

function af_characterworkflow_resolve_active_application(int $uid): ?array { return ['tid' => 100 + $uid, 'relation' => ['uid' => $uid]]; }
$source = file_get_contents(AF_ADDONS.'advancedelementtheme/advancedelementtheme.php');
preg_match('/function af_elementtheme_resolve_surface_key\(.*?\n\}/s', $source, $gate); eval($gate[0]);
af_adaptivethemeframework_preload_postbit_data($post);
$reads=$db->reads;
for($i=0;$i<20;$i++){ $row=$post;af_adaptivethemeframework_compose_postbit($row);check($row['af_atf_element']==='fire','Element lost');check(str_contains($row['af_atf_secondary_avatar'],'secondary-42'),'Secondary lost');check(str_contains($row['af_atf_post_reputation_html'],'+1'),'Reputation lost');}
check($db->reads===$reads,'Layout composition queried SQL');
check($authorCalls===[42=>1,43=>1],'Authors were not preloaded once each');
$requestVisibility=$postVisibility=$renders=0;
af_adaptivethemeframework_register_component(['owner'=>'mybb','key'=>'request_visible','slot'=>'post.before_body',
 'visibility_scope'=>'request','visibility'=>static function($ctx)use(&$requestVisibility){++$requestVisibility;return true;},
 'memo_key'=>static fn($ctx)=>$ctx['uid'], 'renderer'=>static function($ctx)use(&$renders){++$renders;return 'author:'.$ctx['uid'];}]);
af_adaptivethemeframework_register_component(['owner'=>'mybb','key'=>'post_visible','slot'=>'post.after_body',
 'visibility'=>static function($ctx)use(&$postVisibility){++$postVisibility;return $ctx['pid']%2===0;},'html'=>'even']);
for($i=1;$i<=20;$i++){
 check(af_adaptivethemeframework_render_slot('post.before_body',['uid'=>42,'pid'=>$i])==='author:42','Memo output mismatch');
 check(af_adaptivethemeframework_render_slot('post.after_body',['uid'=>42,'pid'=>$i])===($i%2===0?'even':''),'Post visibility cached incorrectly');
}
check($requestVisibility===1 && $postVisibility===20 && $renders===1,'Request/post memo boundaries failed');
// Late registration invalidates the prepared list and memo, without changing order.
af_adaptivethemeframework_register_component(['owner'=>'mybb','key'=>'late','slot'=>'post.before_body','sortorder'=>1,'html'=>'late;']);
check(af_adaptivethemeframework_render_slot('post.before_body',['uid'=>43,'pid'=>21])==='late;author:43','Late provider missing');
$page='<html><body class="atf-active" data-atf-owned="1"><article class="atf-post"></article><atf-footer-placement></atf-footer-placement></body></html>';
af_adaptivethemeframework_mark_page($page);$once=$page;af_adaptivethemeframework_mark_page($page);
check($page===$once && substr_count($page,'id="atf-footer-composition"')===1,'Owned footer not canonical/idempotent');
check(str_contains($page,'postbit-sticky.js') && !str_contains($page,'modals.js'),'Modal runtime leaked to plain thread');
$page='<html><body class="atf-active" data-atf-owned="1"><a data-afcs-open="1">Lazy</a><atf-footer-placement></atf-footer-placement></body></html>';
af_adaptivethemeframework_mark_page($page);
check(str_contains($page,'modals.js')&&str_contains($page,'surfaces/modals.css'),'Lazy trigger omitted modal assets');
$db->missing=true;
check(af_adaptivethemeframework_forum_layout()==='full','Missing preferences did not fall back');
check(!empty($GLOBALS['af_adaptivethemeframework_diagnostics']['preferences_schema_missing']),'Missing schema not diagnosed');
$checks=$db->tableChecks;af_adaptivethemeframework_forum_layout();check($db->tableChecks===$checks,'Readiness was not memoized');
echo "Stage 6 PHP hot path, batch, memo, footer and schema contracts passed.\n";
