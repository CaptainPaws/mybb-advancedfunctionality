<?php
declare(strict_types=1);
/** Destructive synthetic schema test: only acs414b_test / fixture_ via isolated socket. */
require dirname(__DIR__).'/tools/acs/EngineMigration.php';
$socket=$argv[1]??'';if($socket!=='/tmp/acs414b/socket/mysql.sock')throw new RuntimeException('isolated_socket_required');
umask(0077);mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function test414b(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$dir='/tmp/acs414b/state-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$c=['database'=>'acs414b_test','prefix'=>'fixture_','socket'=>$socket,'user'=>'root','password'=>'','tables'=>AcsEngineMigration::ORDER];
foreach(['backup','restore_evidence','maintenance_evidence','staging_evidence'] as $k){$p=$dir.'/'.$k;file_put_contents($p,'SYNTHETIC TEST EVIDENCE ONLY: '.$k);$c[$k]=['path'=>$p,'sha256'=>hash_file('sha256',$p)];}
$evidence=[
 'restore_evidence'=>['backup_sha256'=>$c['backup']['sha256'],'result'=>'pass','backup_captured_at'=>time(),'verified_at'=>time()],
 'maintenance_evidence'=>['freeze_started_at'=>time()-1,'writers_stopped'=>true],
 'staging_evidence'=>['mysql_version'=>'8.0.46','tables'=>$c['tables'],'result'=>'pass'],
];
foreach($evidence as $k=>$v){file_put_contents($c[$k]['path'],json_encode($v));$c[$k]['sha256']=hash_file('sha256',$c[$k]['path']);}
$c['space']=array_map(fn($p)=>['path'=>$p,'minimum_free_bytes'=>1048576],['/tmp/acs414b/data','/tmp/acs414b/tmp',$dir]);
$config=$dir.'/config.json';file_put_contents($config,json_encode($c));
$connect=fn()=>new mysqli('localhost','root','','acs414b_test',0,$socket);
$db=$connect();$db->set_charset('utf8mb4');$db->query('SET GLOBAL read_only=OFF');$db->query('SET GLOBAL offline_mode=OFF');
foreach($c['tables'] as $t){$db->query('DROP TABLE IF EXISTS fixture_'.$t);$pk=$t==='moderatorlog'?'':'PRIMARY KEY(id),';$auto=$t==='moderatorlog'?'':'AUTO_INCREMENT';
 $db->query("CREATE TABLE fixture_$t (id INT UNSIGNED NOT NULL $auto, subject VARCHAR(120), message TEXT, bytes BLOB, $pk KEY subject_prefix(subject(20))) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='literal ENGINE=MyISAM AUTO_INCREMENT=17'".($auto?' AUTO_INCREMENT=1000':''));
 if(in_array($t,['posts','threads'],true))$db->query("ALTER TABLE fixture_$t ADD FULLTEXT KEY search_text(message)");
 $db->query("SET SESSION sql_mode=CONCAT(@@session.sql_mode,',NO_AUTO_VALUE_ON_ZERO')");
 $db->query("INSERT INTO fixture_$t(id,subject,message,bytes) VALUES (0,'Жизнь','test data',X'00FF'),(2,'Ядро','test data two',NULL)");
 if(!$auto)$db->query("INSERT INTO fixture_$t(id,subject,message,bytes) VALUES (0,'Жизнь','test data',X'00FF')");
}
$db->query('SET GLOBAL event_scheduler=OFF');$db->close();
$run=function(string $mode,?string $approval=null)use($config,$dir){$tool=new AcsEngineMigration($config,$dir);try{return $tool->run($mode,$approval);}finally{unset($tool);gc_collect_cycles();}};
$fail=function(string $mode,string $code,?string $approval=null)use($run){try{$run($mode,$approval);throw new LogicException('Expected refusal '.$code);}catch(RuntimeException $e){test414b($e->getMessage()===$code,'Refusal mismatch: '.$e->getMessage());}};
try {
 $fail('capture','maintenance_server_gates');
 $db=$connect();$db->query('SET GLOBAL offline_mode=ON');$db->query('SET GLOBAL read_only=ON');$db->close();
 $large=$c;$large['space'][0]['minimum_free_bytes']=PHP_INT_MAX;file_put_contents($config,json_encode($large));$fail('capture','insufficient_free_space');file_put_contents($config,json_encode($c));
 $result=$run('capture');$a=['baseline_hash'=>$result['baseline_hash'],'tables'=>$c['tables'],'backup_sha256'=>$c['backup']['sha256'],'approved_by'=>'isolated-fixture','expires_at'=>time()+3600,'all_writers_stopped'=>true,'same_host_storage_verified'=>true,'backup_restore_tested'=>true,'staging_passed'=>true];
 $approval=$dir.'/approval.json';file_put_contents($approval,json_encode($a));
 $first=$run('verify');test414b($first['verified']&&!$first['all_innodb'],'Initial verify');
 $observer=$connect();$fail('apply-next','parallel_database_connections',$approval);$observer->close();
 file_put_contents($c['backup']['path'],'corrupt');$fail('apply-next','backup_artifact_hash',$approval);file_put_contents($c['backup']['path'],'SYNTHETIC TEST EVIDENCE ONLY: backup');
 $bad=$a;$bad['expires_at']=time()-1;file_put_contents($approval,json_encode($bad));$fail('apply-next','approval_expired_or_missing',$approval);file_put_contents($approval,json_encode($a));
 test414b($run('apply-next',$approval)['converted']==='threadsubscriptions','First table/order');
 $stateFile=$dir.'/state.json';$s=json_decode(file_get_contents($stateFile),true);$t='mailqueue';
 // Persisted intent before DDL: a killed process did not execute the statement.
 $s['intent']=['table'=>$t,'time'=>time()];file_put_contents($stateFile,json_encode($s));
 $fail('apply-next','recovery_required',$approval);$fail('verify','recovery_required');
 test414b($run('recover',$approval)['engine']==='MyISAM','Pre-DDL interruption recovery');
 // Persisted intent, completed DDL, lost process before recording receipt.
 $s=json_decode(file_get_contents($stateFile),true);$s['intent']=['table'=>$t,'time'=>time()];file_put_contents($stateFile,json_encode($s));
 $db=$connect();$db->query('ALTER TABLE fixture_mailqueue ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE, AUTO_INCREMENT=1000');$db->close();
 test414b($run('recover',$approval)['engine']==='InnoDB','Post-DDL unknown outcome recovery');
 foreach(array_slice($c['tables'],2) as $t)test414b($run('apply-next',$approval)['converted']===$t,'Sequential conversion '.$t);
 $verified=$run('verify');test414b($verified['all_innodb'],'Final verification');test414b($run('verify')===$verified,'Repeated verification');test414b($run('apply-next',$approval)['complete'],'No repeated ALTER after complete');
 // A mutation after capture is refused, never healed or deleted.
 $db=$connect();$db->query("UPDATE fixture_posts SET message='drift' WHERE id=2");$db->close();$fail('verify','schema_or_data_drift_data');
 $db=$connect();$db->query("UPDATE fixture_posts SET message='test data two' WHERE id=2");$db->query('ALTER TABLE fixture_posts ADD KEY unexpected(id,subject(5))');$db->close();$fail('verify','schema_or_data_drift_ddl');
 echo "Engine tool MySQL 8.0.46: maintenance/parallel/backup/approval gates; ten scoped conversions, FULLTEXT/index/charset/count/digest/AUTO_INCREMENT preservation; pre/post-DDL interruption recovery, repeat verification/no repeat ALTER and drift refusal passed\n";
} finally {
 $db=$connect();$db->query('SET GLOBAL read_only=OFF');$db->query('SET GLOBAL offline_mode=OFF');$db->close();
 // Keep private synthetic state only under /tmp for inspection; no user data in repository.
}
