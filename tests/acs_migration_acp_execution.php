<?php
/** Isolated native MySQL fixture only. Never point at a real forum. */
if(PHP_SAPI!=='cli')die('CLI only');
define('IN_MYBB',true);define('IN_ADMINCP',true);
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Administration/Migration/AcpExecution.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function axcheck($ok,$m){if(!$ok)throw new RuntimeException($m);}
function axfail($f,$code){try{$f();throw new LogicException('Expected '.$code);}catch(RuntimeException $e){axcheck($e->getMessage()===$code,$e->getMessage());}}
function verify_post_check($v,$silent=false){return $v==='fixture';}
$config=['database'=>['hostname'=>'localhost','username'=>'root','password'=>'','database'=>'acs414b_test']];
$mybb=new class {public $user=['uid'=>7];public $usergroup=['cancp'=>1];public $admin=['permissions'=>['advancedfunctionality'=>['index'=>1],'tools'=>['optimizedb'=>1]]];public $request_method='post';public $settings=['boardclosed'=>1];};
$db=new class {
 public $read_link;public $write_link;public $table_prefix='fixture_';
 function __construct(){$this->read_link=$this->write_link=new mysqli('localhost','root','','acs414b_test',0,'/tmp/acs414b/socket/mysql.sock');$this->write_link->set_charset('utf8mb4');}
 function query($q,$hide=0){return $this->write_link->query($q);}
 function fetch_array($q){return $q->fetch_assoc()?:false;}
};
axcheck(ini_get('mysqli.default_socket')==='/tmp/acs414b/socket/mysql.sock','isolated_socket_required');
$c=$db->write_link;$c->query("SET SESSION sql_mode=CONCAT(@@session.sql_mode,',NO_AUTO_VALUE_ON_ZERO')");$c->query('SET GLOBAL read_only=OFF');$c->query('SET GLOBAL offline_mode=OFF');
// Prove the physical barrier survives engine conversion, for both kinds of other table.
foreach(['MyISAM','InnoDB'] as $otherEngine){
 $c->query('CREATE TABLE lock_probe_a (id INT PRIMARY KEY) ENGINE=MyISAM');$c->query('CREATE TABLE lock_probe_b (id INT PRIMARY KEY) ENGINE='.$otherEngine);
 $other=new mysqli('localhost','root','','acs414b_test',0,'/tmp/acs414b/socket/mysql.sock');
 $c->query('LOCK TABLES lock_probe_a WRITE,lock_probe_b WRITE');$c->query('ALTER TABLE lock_probe_a ENGINE=InnoDB,ALGORITHM=COPY,LOCK=EXCLUSIVE');
 $other->query('INSERT INTO lock_probe_b VALUES (1)',MYSQLI_ASYNC);$r=[$other];$e=[];$z=[];axcheck(mysqli_poll($r,$e,$z,0,100000)===0,'Write barrier vanished after ALTER');
 $c->query('UNLOCK TABLES');$r=[$other];$e=[];$z=[];mysqli_poll($r,$e,$z,1);$other->reap_async_query();$other->close();$c->query('DROP TABLE lock_probe_a,lock_probe_b');
}
foreach(AcsEngineMigration::ORDER as $t){$c->query('DROP TABLE IF EXISTS fixture_'.$t);$pk=$t==='moderatorlog'?'':'PRIMARY KEY(id),';$auto=$pk?'AUTO_INCREMENT':'';
 $c->query("CREATE TABLE fixture_$t (id INT NOT NULL $auto, message TEXT, value VARCHAR(120), bytes BLOB, $pk KEY lookup(value(20))) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4".($pk?' AUTO_INCREMENT=1000':''));
 $c->query("INSERT INTO fixture_$t(id,message,value,bytes) VALUES (0,'Жизнь','a',X'00FF'),(2,'two','b',NULL)");
 if(!$pk)$c->query("INSERT INTO fixture_$t(id,message,value,bytes) VALUES (0,'Жизнь','a',X'00FF')");
 if(in_array($t,['threads','posts'],true))$c->query("ALTER TABLE fixture_$t ADD FULLTEXT search_text(message)");
}
$base='/tmp/acs414b/execution-'.bin2hex(random_bytes(6));mkdir($base,0700);$j=new AF_ACS_MigrationJournal($base.'/ui');$engineDir=$base.'/engine';
class SlowAcpFixtureMysql extends mysqli {
 public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool {return parent::query(str_starts_with($query,'ALTER TABLE')?'SELECT SLEEP(30)':$query,$result_mode);}
}
$slow=static function(){$m=new SlowAcpFixtureMysql('localhost','root','','acs414b_test',0,'/tmp/acs414b/socket/mysql.sock');$m->set_charset('utf8mb4');return $m;};
$normal=static function(){$m=new mysqli('localhost','root','','acs414b_test',0,'/tmp/acs414b/socket/mysql.sock');$m->set_charset('utf8mb4');return $m;};
$cancelDir=$base.'/cancel';mkdir($cancelDir,0700);$uuid=$c->query('SELECT @@server_uuid AS uuid')->fetch_assoc()['uuid'];
$timed=AcsEngineMigration::forAcp($slow,'fixture_',$cancelDir,$uuid);
try{axfail(fn()=>$timed->acpStep(),'ddl_outcome_unknown');}finally{$timed->closeAcp();}
axcheck(AcsEngineMigration::readAcpState($cancelDir)['intent']!==null,'Timed-out SQL lost intent');
$recovered=AcsEngineMigration::forAcp($normal,'fixture_',$cancelDir,$uuid);try{$recovered->acpStep(true);}finally{$recovered->closeAcp();}
axcheck(AcsEngineMigration::readAcpState($cancelDir)['intent']===null,'Cancellation recovery failed');
$x=new AF_ACS_MigrationAcpExecution($db,$j,$engineDir);
$req=['op'=>'begin','csrf'=>'fixture','backup'=>'1','key'=>str_repeat('1',32),'revision'=>0];
axfail(fn()=>$x->command(array_replace($req,['backup'=>''])),'backup_statement_required');
axfail(fn()=>$x->command(array_replace($req,['csrf'=>'bad'])),'csrf_required');
$mybb->usergroup['cancp']=0;axfail(fn()=>$x->command($req),'ACS_ADMIN_PERMISSION_REQUIRED');$mybb->usergroup['cancp']=1;
$mybb->settings['boardclosed']=0;axfail(fn()=>$x->command($req),'forum_not_closed');$mybb->settings['boardclosed']=1;
$s=$x->command($req);axcheck(($s['job']['error']??null)===null,'First step error '.($s['job']['error']??''));axcheck($x->status()['done']===1,'First request did not convert exactly one');
axcheck($x->command($req)===$s&&$x->status()['done']===1,'Replay repeated ALTER');
// Unexpected schema change between requests is detected; previous success remains intact.
$c->query('ALTER TABLE fixture_mailqueue ADD KEY unexpected(value)');
$s=$x->command(['op'=>'next','csrf'=>'fixture','key'=>str_repeat('2',32),'revision'=>$s['revision']]);
axcheck($s['job']['status']==='recovery_required'&&$x->status()['done']===1,'Failed sequence did not stop');
$c->query('ALTER TABLE fixture_mailqueue DROP KEY unexpected');
$s=$x->command(['op'=>'recover','csrf'=>'fixture','key'=>str_repeat('3',32),'revision'=>$s['revision']]);axcheck($s['job']['status']==='ready','Recovery failed');
// Simulate lost completion receipt: actual ALTER committed, durable intent was not cleared.
$raw=AcsEngineMigration::readAcpState($engineDir);$raw['intent']=['table'=>'threadsubscriptions','time'=>time()];unset($raw['completed']['threadsubscriptions']);
$raw['journal_hash']=hash('sha256',json_encode($raw,JSON_UNESCAPED_SLASHES));
file_put_contents($engineDir.'/engine-state.php',"<?php http_response_code(404); exit; ?>\n".json_encode($raw));
$s=$x->command(['op'=>'recover','csrf'=>'fixture','key'=>str_repeat('4',32),'revision'=>$s['revision']]);axcheck($x->status()['done']===1&&!$x->status()['unknown'],'Unknown outcome not recovered');
$c->query("UPDATE fixture_mailqueue SET value='legitimate update' WHERE id=2");
for($i=2;$i<=10;$i++){$s=$x->command(['op'=>'next','csrf'=>'fixture','key'=>str_pad(dechex($i+10),32,'0',STR_PAD_LEFT),'revision'=>$s['revision']]);axcheck(($s['job']['error']??null)===null,'Step '.$i.': '.($s['job']['error']??''));axcheck($x->status()['done']===$i,'Multiple/missing ALTER');}
axcheck($c->query('SELECT value FROM fixture_mailqueue WHERE id=2')->fetch_assoc()['value']==='legitimate update','New data lost across step boundary');
axcheck($x->status()['complete']&&$s['job']['status']==='completed','Completion');
axfail(fn()=>$x->command(['op'=>'begin','csrf'=>'fixture','backup'=>'1','key'=>str_repeat('a',32),'revision'=>$s['revision']]),'migration_already_complete');
// A new journal observing already-InnoDB tables must not perform conversion.
$y=new AF_ACS_MigrationAcpExecution($db,new AF_ACS_MigrationJournal($base.'/already'),$base.'/new-engine');$s=$y->command($req);axcheck(($s['job']['no_change']??false)&&!file_exists($base.'/new-engine/engine-state.php'),'Already InnoDB repeated work');
echo "ACP engine: rights/CSRF/backup/maintenance, actual single-table ALTER, replay, stopped partial failure, unknown-outcome recovery, ten verified tables with FULLTEXT/AUTO_INCREMENT/data, completed/already-InnoDB refusal passed\n";
