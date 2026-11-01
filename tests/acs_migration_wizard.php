<?php
/** Focused ACP preparation fixture: no real MyBB, SQL writes, worker or DDL. */
define('IN_MYBB',true);define('IN_ADMINCP',true);
$root=sys_get_temp_dir().'/acs-wizard-'.bin2hex(random_bytes(8)).'/';mkdir($root,0700);define('MYBB_ROOT',$root);
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function verify_post_check($key,$silent=false){return $key==='fixture-csrf';}
$mybb=new class {
 public $user=['uid'=>7];public $usergroup=['cancp'=>1];public $admin=['permissions'=>['advancedfunctionality'=>['index'=>1],'tools'=>['optimizedb'=>1]]];
 public $request_method='post';public $post_code='fixture-csrf';public $input=[];
 function get_input($k){return (string)($this->input[$k]??'');}
};
$db=new class {
 public $table_prefix='SECRET_PREFIX_';public $calls=[];
 function query($sql,$hide){$this->calls[]=$sql;if(!preg_match('/\A(?:SELECT|SHOW CREATE TABLE|SHOW VARIABLES)\b/',$sql))throw new LogicException('SQL mutation');
  if(str_contains($sql,'VERSION()'))$rows=[['version'=>'8.0.46']];
  elseif(str_starts_with($sql,'SHOW VARIABLES'))$rows=[['Variable_name'=>'datadir','Value'=>'/private/SECRET_PATH'],['Variable_name'=>'event_scheduler','Value'=>'OFF']];
  elseif(str_contains($sql,'performance_schema.threads'))$rows=[['n'=>'2']];
  elseif(str_contains($sql,'tasks`'))$rows=[['enabled'=>'1']];
  elseif(str_contains($sql,'information_schema.TABLES')){$rows=[];foreach(AcsEngineMigration::ORDER as $t)$rows[]=['TABLE_NAME'=>$this->table_prefix.$t,'ENGINE'=>$t==='mailqueue'?'InnoDB':'MyISAM','DATA_LENGTH'=>'1000','INDEX_LENGTH'=>'200'];}
  else $rows=[['Table'=>'SECRET_PREFIX','Create Table'=>"CREATE TABLE x (id INT PRIMARY KEY) ENGINE=MyISAM COMMENT='SECRET_PASSWORD /private/SECRET_PATH'"]];
  return (object)['rows'=>$rows];}
 function fetch_array($q){return array_shift($q->rows);}
};
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Administration/Migration/Wizard.php';
function wcheck($v,$m){if(!$v)throw new RuntimeException($m);}
function wfails($fn,$code){try{$fn();throw new LogicException('Expected '.$code);}catch(RuntimeException $e){wcheck($e->getMessage()===$code,$e->getMessage());}}
$dir=$root.'private-preparation';$j=new AF_ACS_MigrationJournal($dir);$service=new AF_ACS_MigrationService($db,$j,new AF_ACS_MigrationBackgroundWorker);
$req=['op'=>'check','csrf'=>'fixture-csrf','key'=>str_repeat('a',32),'revision'=>0,'tables'=>['forums','users','threads','posts']];
try {
 $before=count($db->calls);$mybb->usergroup['cancp']=0;wfails(fn()=>$service->state(),'ACS_ADMIN_PERMISSION_REQUIRED');wfails(fn()=>$service->command($req),'ACS_ADMIN_PERMISSION_REQUIRED');wcheck(count($db->calls)===$before,'SQL before permission');$mybb->usergroup['cancp']=1;
 wfails(fn()=>$service->command(array_replace($req,['csrf'=>'bad'])),'csrf_required');
 $mybb->request_method='get';wfails(fn()=>$service->command($req),'csrf_required');$mybb->request_method='post';
 $s=$service->command($req);wcheck($s['revision']===1&&$s['report']['status']==='BLOCKED','Saved report');
 foreach(['backup_missing','restore_unverified','staging_missing','http_not_proven','space_unverified'] as $c)wcheck(in_array($c,$s['report']['issues'],true),'Missing refusal '.$c);
 $before=count($db->calls);wcheck($service->command($req)===$s&&count($db->calls)===$before,'Duplicate operation repeated work');
 wfails(fn()=>$service->command(array_replace($req,['op'=>'prepare'])),'request_conflict');
 wfails(fn()=>$service->command(array_replace($req,['key'=>str_repeat('b',32)])),'version_conflict');
 $mybb->admin['permissions']['tools']['optimizedb']=0;
 wfails(fn()=>$service->command(array_replace($req,['key'=>str_repeat('c',32),'revision'=>1,'op'=>'prepare'])),'ACS_ADMIN_PERMISSION_REQUIRED');wfails(fn()=>$service->command(array_replace($req,['key'=>str_repeat('c',32),'revision'=>1,'op'=>'register_backup'])),'ACS_ADMIN_PERMISSION_REQUIRED');$mybb->admin['permissions']['tools']['optimizedb']=1;
 $start=array_replace($req,['op'=>'start','revision'=>1,'key'=>str_repeat('d',32),'phrase'=>'ПОДТВЕРЖДАЮ МИГРАЦИЮ']);
 $blocked=$service->command($start);wcheck($blocked['job']===null&&$blocked['report']['status']==='BLOCKED'&&in_array('active_connections',$blocked['report']['issues'],true),'Created unverified job');wcheck($service->command($start)===$blocked,'Duplicate start created work');
 $reload=new AF_ACS_MigrationService($db,new AF_ACS_MigrationJournal($dir),new AF_ACS_MigrationBackgroundWorker);wcheck($reload->state()===$blocked,'Reload lost journal');
 wfails(fn()=>(new AF_ACS_MigrationBackgroundWorker)->submit(),'worker_unavailable');
 $polluted=$blocked;$polluted['report']['credentials']='SECRET_PASSWORD';$polluted['report']['database']['tables']['posts']['path']='/private/SECRET_PATH';
 $json=json_encode($service->export($polluted));foreach(['SECRET_','fixture-csrf','receipts','/private/'] as $secret)wcheck(!str_contains($json,$secret),'Export secret '.$secret);
 // Usable evidence registration: records an administrator statement, never an execution grant.
 $evService=new AF_ACS_MigrationService($db,new AF_ACS_MigrationJournal($root.'evidence'),new AF_ACS_MigrationBackgroundWorker);
 $proof=['date'=>gmdate('Y-m-d\TH:i',time()-7200),'verified'=>gmdate('Y-m-d\TH:i',time()-3600),'reference'=>'Backup 123','checksum'=>str_repeat('b',64),'result'=>'passed','method'=>'Restored the complete backup in a separate private database and checked files.'];
 $backupReq=array_replace($req,['op'=>'register_backup','key'=>str_repeat('2',32),'evidence'=>$proof]);
 wfails(fn()=>$evService->command(array_replace($backupReq,['evidence'=>array_replace($proof,['checksum'=>''])])),'evidence_invalid');
 wfails(fn()=>$evService->command(array_replace($backupReq,['evidence'=>array_replace($proof,['verified'=>'2099-01-01T00:00'])])),'evidence_invalid');
 $recorded=$evService->command($backupReq);wcheck($recorded['evidence']['backup']['source']==='administrator_statement'&&$recorded['job']===null,'Statement became execution grant');
 wcheck($evService->command($backupReq)===$recorded,'Evidence replay');
 $staged=$evService->command(array_replace($req,['op'=>'register_staging','revision'=>1,'key'=>str_repeat('3',32),'evidence'=>$proof]));
 wcheck(!in_array('staging_missing',$staged['report']['issues'],true)&&in_array('http_not_proven',$staged['report']['issues'],true),'Evidence ignored or falsely unlocked HTTP');
 wcheck($staged['report']['runtime']['visible_other_connections']===2,'Runtime writers missing');
 $changed=$evService->command(array_replace($req,['op'=>'prepare','revision'=>2,'key'=>str_repeat('4',32),'tables'=>['mailqueue','forums','users','threads','posts'],'conditional_confirmed'=>'1']));
 wcheck(in_array('staging_missing',$changed['report']['issues'],true),'Staging scope drift ignored');
 $encoded=json_encode($evService->export($changed));wcheck(!str_contains($encoded,'Restored the complete')&&!str_contains($encoded,'Backup 123'),'Free-text evidence leaked in export');
 // A protected pre-existing job is observed; changing the page never launches it again.
 $j->command(str_repeat('e',32),'fixture',2,function($x){$x['job']=['id'=>'opaque','status'=>'running'];return $x;});
 wfails(fn()=>$service->command(array_replace($req,['op'=>'prepare','revision'=>3,'key'=>str_repeat('f',32)])),'unfinished_operation');
 $observed=$service->command(array_replace($req,['revision'=>3,'key'=>str_repeat('f',32)]));wcheck($observed['job']['id']==='opaque'&&in_array('unfinished_operation',$observed['report']['issues'],true),'Read-only observation restarted job');
 // Render uses its normal journal, still without SQL writes, executor or command on GET.
 $mybb->request_method='post';$mybb->input=['migration_op'=>'check','migration_revision'=>'0','migration_key'=>str_repeat('1',32),'my_post_key'=>'fixture-csrf','migration_tables'=>['forums','users','threads','posts']];
 ob_start();AF_ACS_MigrationWizard::render();$savedHtml=ob_get_clean();wcheck(str_contains($savedHtml,'8.0.46'),'Controller did not persist check');
 $before=count($db->calls);ob_start();AF_ACS_MigrationWizard::render();ob_end_clean();wcheck(count($db->calls)===$before,'Controller repeated POST work');
 $mybb->input=['migration_op'=>'register_backup','migration_revision'=>'1','migration_key'=>str_repeat('7',32),'my_post_key'=>'fixture-csrf','migration_tables'=>['forums','users','threads','posts']];foreach($proof as $k=>$v)$mybb->input['evidence_'.$k]=$v;
 ob_start();AF_ACS_MigrationWizard::render();$evidenceHtml=ob_get_clean();wcheck(str_contains($evidenceHtml,'Заявление администратора сохранено')&&str_contains($evidenceHtml,str_repeat('b',64)),'Controller evidence registration failed');
 $mybb->request_method='get';$mybb->input=[];ob_start();AF_ACS_MigrationWizard::render();$html=ob_get_clean();wcheck(str_contains($html,'8.0.46'),'GET lost saved report');
 wcheck(str_contains($html,'Инструменты → Миграция БД')&&str_contains($html,'Запустить миграцию</button>')&&str_contains($html,'<button disabled>Запустить'),'UI gates');
 wcheck(!str_contains($html,'SECRET_PASSWORD')&&!str_contains($html,'SECRET_PREFIX_'),'HTML secret');
 preg_match('~data:application/json;base64,([A-Za-z0-9+/=]+)~',$html,$m);wcheck(isset($m[1])&&json_decode(base64_decode($m[1]),true)['publication']==='BLOCKED','Safe export');
 echo "Migration wizard: permissions/CSRF, read-only runtime/writers, backup+restore/staging statement registration and validation, scope drift, replay/reload, no HTTP DDL grant and safe UI/export passed\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
