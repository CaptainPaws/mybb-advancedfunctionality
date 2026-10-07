<?php
define('IN_MYBB',true);define('THIS_SCRIPT','showthread.php');define('TABLE_PREFIX','mybb_');
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
define('AF_APF_VALUES_TABLE','af_apf_values');define('AF_APF_SECONDARY_AVATAR_KEY','secondary_avatar');define('AF_ATF_TABLE_VALUES','af_atf_values');
function batch_check(bool $ok,string $why): void{if(!$ok)throw new RuntimeException($why);}
function htmlspecialchars_uni($v): string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function extract_batch_function(string $file,string $name): void {
    $source=file_get_contents($file);
    if(!preg_match('~function '.preg_quote($name,'~').'\(.*?\n\}\r?\n~s',$source,$match))throw new RuntimeException('Missing '.$name);
    eval($match[0]);
}
$mybb=(object)['settings'=>['bburl'=>'https://forum.test']];
$db=new class {
 public string $table_prefix='mybb_';public array $calls=[];
 function table_exists($table){return true;}function escape_string($s){return $s;}
 function simple_select($table,$fields,$where){
  $this->calls[]=$table.':'.$where;
  $rows=match($table){
   'posts'=>[['uid'=>42],['uid'=>43],['uid'=>44]],
   'af_apf_values'=>[['uid'=>42,'field_value'=>'uploads/avatars/secondary_42_0123456789abcdef0123456789abcdef.png']],
   'af_atf_values'=>[['tid'=>142,'value'=>'fire'],['tid'=>143,'value'=>'invalid-element']],default=>throw new RuntimeException($table)};
  return (object)['rows'=>$rows,'i'=>0];
 }
 function query($sql){$this->calls[]='reputation:'.$sql;return (object)['rows'=>[],'i'=>0];}
 function fetch_array($query){return $query->rows[$query->i++]??false;}
 function fetch_field($q,$field){throw new RuntimeException('Per-author fallback SQL');}
};
$workflowBatches=0;
function af_characterworkflow_preload_active_applications(array $uids):void{global $workflowBatches;++$workflowBatches;}
function af_characterworkflow_resolve_active_application(int $uid):?array{return $uid===44?null:['tid'=>100+$uid];}
function af_atf_get_fields_cached():array{return [['name'=>'character_element','fieldid'=>8]];}
function af_elementtheme_resolve_key(string $key):string{return $key==='fire'?'fire':'';}
extract_batch_function(AF_ADDONS.'advancedprofilefields/advancedprofilefields.php','af_apf_preload_system_values');
extract_batch_function(AF_ADDONS.'advancedprofilefields/advancedprofilefields.php','af_apf_get_system_value');
extract_batch_function(AF_ADDONS.'advancedprofilefields/advancedprofilefields.php','af_apf_get_secondary_avatar');
extract_batch_function(AF_ADDONS.'advancedthreadfields/advancedthreadfields.php','af_atf_preload_author_elements');
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
$pids='1,2,3';$post=['pid'=>1,'tid'=>77,'uid'=>42];
af_adaptivethemeframework_preload_postbit_data($post);
batch_check(count($db->calls)===4 && $workflowBatches===1,'Expected one authors, reputation, APF values and character elements batch');
batch_check(str_contains($db->calls[0],'r.pid IN (1,2,3)'),'Reputation scanned outside current page');
batch_check(str_contains($db->calls[2],'uid IN (42,43,44)'),'APF batch missed authors');
batch_check(str_contains($db->calls[3],'tid IN (142,143) AND fieldid=8'),'Element batch did not restrict live applications/field');
for($i=0;$i<20;$i++)foreach([42,43,44] as $uid){$row=$post;$row['uid']=$uid;af_adaptivethemeframework_preload_postbit_data($row);af_adaptivethemeframework_compose_postbit($row);batch_check($row['af_atf_element']===($uid===42?'fire':''),'Element allow-list/neutral fallback changed');batch_check(($row['af_atf_secondary_avatar']!=='')===($uid===42),'Secondary negative cache changed');}
batch_check(count($db->calls)===4 && $workflowBatches===1,'Repeat authors/missing fields executed more SQL');
echo "Stage 6 real batch helpers passed: 60 compositions, 4 initial batches, zero additional SQL.\n";
