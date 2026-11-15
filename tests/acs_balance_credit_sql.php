<?php
/** Narrow disposable MySQL fixture; never connects over TCP or to an existing DB.
 * php tests/acs_balance_credit_sql.php /tmp/acs1031-socket/mysql.sock */
if (PHP_SAPI !== 'cli') exit(1);
$socket=$argv[1] ?? '';
if (!preg_match('~\A/tmp/acs1031-socket/[A-Za-z0-9_.-]+\z~D',$socket)) throw new RuntimeException('Disposable socket required');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
define('IN_MYBB',1); define('TABLE_PREFIX','t_'); define('TIME_NOW',time());
require __DIR__.'/fixtures/InventoryFixtureDatabase.php';
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/balance/balance.php';
final class BalanceFixtureLink extends mysqli
{
    public bool $loseAck=false;
    public function commit(int $flags=0, ?string $name=null): bool
    {
        $r=parent::commit($flags,$name);
        if ($this->loseAck) { $this->loseAck=false; throw new RuntimeException('fixture lost ACK after durable COMMIT'); }
        return $r;
    }
}
function connection(string $name=''): BalanceFixtureLink
{
    global $socket;
    $c=new BalanceFixtureLink('localhost','root','',$name,0,$socket); $c->set_charset('utf8mb4'); return $c;
}
function check(bool $v,string $label): void { if (!$v) throw new RuntimeException('FAIL: '.$label); echo "PASS: $label\n"; }
function denied(callable $f,string $code): void
{
    try { $f(); } catch (Throwable $e) { check(str_contains($e->getMessage(),$code),$code); return; }
    throw new RuntimeException('Expected '.$code);
}
function scalar(mysqli $c,string $sql): mixed { return $c->query($sql)->fetch_row()[0]; }
/** Test-only source provider: production must use confirmed reward persistence.
 * Reads authoritative DB, never caller asserted permissions/amount/recipient. */
final class BalanceFixtureAuthority implements AF_BALANCE_RewardAuthority
{
    public function __construct(private mysqli $c) {}
    public function authorize(array $intent): array
    {
        $s=$this->c->prepare('SELECT * FROM fixture_authority WHERE source_ref=?');$s->bind_param('s',$intent['source_ref']);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if (!$r || (int)$r['confirmed'] !== 1 || (int)$r['issuer_allowed'] !== 1 || (int)$r['uid'] !== $intent['uid'] || $r['kind'] !== $intent['kind'] || (int)$r['amount_minor'] !== $intent['amount_minor']) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        return ['scope'=>'acs.reward','source_ref'=>$r['source_ref'],'uid'=>(int)$r['uid'],'kind'=>$r['kind'],'amount_minor'=>(int)$r['amount_minor'],'authority_ref'=>'fixture:'.$r['source_ref'],'confirmation_ref'=>'outcome:confirmed:1','issuer_ref'=>'issuer:server:7'];
    }
}
function bootstrap(mysqli $c): void
{
    global $db,$mybb,$callbacks;
    $db=new InventoryFixtureDatabase($c);$callbacks=[];
    $mybb=(object)['settings'=>['af_balance_tx_enable'=>1,'af_balance_tx_keep_limit'=>0,'af_balance_exp_enabled'=>1,'af_balance_credits_enabled'=>1,'af_balance_exp_per_char'=>0.03,'af_balance_credits_per_char'=>0.02]];
}
function af_charactersheets_on_exp_changed($uid,$old,$new,$meta): void { global $callbacks; $callbacks[]=[$uid,$old,$new,$meta]; }
function seed(mysqli $c,string $source,int $uid,string $kind,int $amount,int $confirmed=1,int $allowed=1): void
{
    $s=$c->prepare('INSERT INTO fixture_authority VALUES(?,?,?,?,?,?)');$s->bind_param('sisiii',$source,$uid,$kind,$amount,$confirmed,$allowed);$s->execute();$s->close();
}
function version(mysqli $c,int $uid): int { return (int)(scalar($c,'SELECT owner_version FROM t_af_balance WHERE uid='.$uid) ?? 1); }
function credit(AF_BALANCE_RewardCredit $g,string $key,int $uid,string $kind,int $amount,string $source,int $version): array { return $g->credit($key,$uid,$kind,$amount,$source,$version); }
if (($argv[2] ?? '') === 'worker') {
    $c=connection($argv[3]);bootstrap($c);$g=new AF_BALANCE_RewardCredit($c,new BalanceFixtureAuthority($c));
    $r=json_decode(base64_decode($argv[4]),true,32,JSON_THROW_ON_ERROR);
    echo "ready\n";flush();
    try { $result=$r['type']==='native' ? af_balance_add_credits($r['uid'],1) : credit($g,...$r['args']); echo json_encode($result,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR); }
    exit;
}
function parallel(mysqli $lock,string $name,int $uid,array $requests): array
{
    global $socket;
    $lock->begin_transaction();$lock->query('SELECT uid FROM t_af_balance WHERE uid='.$uid.' FOR UPDATE');$workers=[];
    foreach ($requests as $r) {
        $p=proc_open([PHP_BINARY,__FILE__,$socket,'worker',$name,base64_encode(json_encode($r,JSON_THROW_ON_ERROR))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);if (trim(fgets($pipes[1]))!=='ready') throw new RuntimeException('worker failed');$workers[]=[$p,$pipes];
    }
    $until=microtime(true)+5;$n=0;
    do { $n=(int)scalar($lock,'SELECT COUNT(*) FROM performance_schema.data_lock_waits');if ($n>0) break; usleep(10000); } while (microtime(true)<$until);
    $lock->commit();check($n>0,'actual MySQL lock contention');$out=[];
    foreach ($workers as [$p,$pipes]) {
        $raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        if ($code!==0 || $err!=='') throw new RuntimeException('Worker failed: '.$err.$raw);
        $out[]=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }
    return $out;
}
$name='acs1031_fixture_'.bin2hex(random_bytes(8));$admin=connection();$admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$c=connection($name);
try {
    bootstrap($c);af_balance_ensure_schema();
    $c->query('CREATE TABLE t_settings(name VARCHAR(100) PRIMARY KEY,value TEXT) ENGINE=MyISAM');
    $c->query("INSERT INTO t_settings VALUES('af_balance_credits_scale_migrated','0')");
    $c->query('CREATE TABLE fixture_authority(source_ref VARCHAR(191) PRIMARY KEY,uid INT UNSIGNED,kind VARCHAR(32),amount_minor BIGINT,confirmed INT,issuer_allowed INT) ENGINE=InnoDB');
    $g=new AF_BALANCE_RewardCredit($c,new BalanceFixtureAuthority($c));
    denied(fn()=>$g->assertStorage(),'BALANCE_SCHEMA_NOT_READY');
    check(!$c->query("SHOW TABLES LIKE 't_af_balance_external_operations'")->num_rows,'readiness creates no schema');
    af_balance_add_exp(50,1.239);check((int)af_balance_get(50)['exp']===123,'legacy floor scaling unchanged');
    denied(fn()=>$g->upgrade(),'BALANCE_LEGACY_SCALE_NOT_MIGRATED');
    $c->query("UPDATE t_settings SET value='1'");
    $c->query('ALTER TABLE t_af_balance ADD owner_version BIGINT UNSIGNED NOT NULL DEFAULT 1');
    denied(fn()=>af_balance_add_credits(50,1),'BALANCE_SCHEMA_NOT_READY');
    check(af_balance_get(50)['credits']===0,'partial upgrade blocks native delta');
    $g->upgrade();$g->upgrade();check(version($c,50)===1,'resumable owner upgrade');
    $c->query('ALTER TABLE t_af_balance_tx ENGINE=MyISAM');denied(fn()=>$g->assertStorage(),'BALANCE_SCHEMA_NOT_READY');denied(fn()=>$g->upgrade(),'BALANCE_SCHEMA_NOT_READY');$c->query('ALTER TABLE t_af_balance_tx ENGINE=InnoDB');
    $c->query('ALTER TABLE t_af_balance_external_operations DROP INDEX source_once');denied(fn()=>$g->assertStorage(),'BALANCE_SCHEMA_NOT_READY');$c->query('ALTER TABLE t_af_balance_external_operations ADD UNIQUE KEY source_once(source_hash)');
    $mode=scalar($c,'SELECT @@SESSION.sql_mode');$c->query("SET SESSION sql_mode=''");denied(fn()=>$g->assertStorage(),'BALANCE_UNSAFE_CONNECTION');
    $s=$c->prepare('SET SESSION sql_mode=?');$s->bind_param('s',$mode);$s->execute();
    seed($c,'reward:exp:10',10,'exp',123);$r=$g->credit('exp:one',10,'exp',123,'reward:exp:10',1);
    check($r['status']==='committed' && $r['balance_after']===123 && $r['owner_version']===2,'one exact minor EXP credit');
    check(count($callbacks)===1,'reward does not call CharacterSheets EXP hook');
    check($g->credit('exp:one',10,'exp',123,'reward:exp:10',1)===$r && $g->lookup('exp:one')===$r,'duplicate and lookup same immutable receipt');
    $c->query("UPDATE fixture_authority SET issuer_allowed=0 WHERE source_ref='reward:exp:10'");
    denied(fn()=>$g->lookup('exp:one'),'BALANCE_REWARD_UNAUTHORIZED');
    $c->query("UPDATE fixture_authority SET issuer_allowed=1 WHERE source_ref='reward:exp:10'");
    denied(fn()=>$g->credit('exp:one',10,'exp',123,'reward:exp:10',2),'BALANCE_INTENT_CONFLICT');
    denied(fn()=>$g->credit('exp:newkey',10,'exp',123,'reward:exp:10',2),'BALANCE_INTENT_CONFLICT');
    denied(fn()=>$g->credit('wrong:uid',11,'exp',123,'reward:exp:10',1),'BALANCE_REWARD_UNAUTHORIZED');
    denied(fn()=>$g->credit('wrong:amount',10,'exp',124,'reward:exp:10',2),'BALANCE_REWARD_UNAUTHORIZED');
    denied(fn()=>$g->credit('wrong:float',10,'exp',1.2,'reward:exp:10',2),'BALANCE_INVALID_INTENT');
    denied(fn()=>$g->credit('wrong:kind',10,'ability_tokens',123,'reward:exp:10',2),'BALANCE_INVALID_INTENT');
    denied(fn()=>(new AF_BALANCE_RewardCredit($c))->credit('no:provider',10,'exp',123,'reward:exp:10',2),'BALANCE_REWARD_UNAUTHORIZED');
    denied(fn()=>(new AF_BALANCE_RewardCredit($c))->lookup('exp:one'),'BALANCE_REWARD_UNAUTHORIZED');
    seed($c,'reward:unconfirmed',10,'credits',100,0);seed($c,'reward:forbidden',10,'credits',100,1,0);
    foreach (['reward:unconfirmed','reward:forbidden','missing'] as $s) denied(fn()=>$g->credit('deny:'.str_replace(':','_',$s),10,'credits',100,$s,2),'BALANCE_REWARD_UNAUTHORIZED');
    seed($c,'reward:credits:10',10,'credits',456);$r=$g->credit('credits:one',10,'credits',456,'reward:credits:10',2);
    check($r['balance_after']===456 && $r['owner_version']===3,'one exact minor credits credit');
    seed($c,'reward:version',10,'credits',10);denied(fn()=>$g->credit('version:conflict',10,'credits',10,'reward:version',2),'BALANCE_VERSION_CONFLICT');
    check($g->lookup('version:conflict')['status']==='not_found' && version($c,10)===3,'version rejection leaves no delta/receipt');
    seed($c,'reward:rollback',10,'credits',20);
    $c->query("CREATE TRIGGER fixture_fail BEFORE INSERT ON t_af_balance_tx FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback after balance update'");
    denied(fn()=>$g->credit('rollback',10,'credits',20,'reward:rollback',3),'fixture rollback');$c->query('DROP TRIGGER fixture_fail');
    check((int)af_balance_get(10)['credits']===456 && version($c,10)===3 && $g->lookup('rollback')['status']==='not_found','rollback after delta rolls back identity, balance, version and ledger');
    seed($c,'reward:ack',10,'credits',7);$c->loseAck=true;$unknown=$g->credit('lost:ack',10,'credits',7,'reward:ack',3);
    check($unknown['status']==='recovery_required','lost ACK returns recovery barrier');$proof=$g->lookup('lost:ack');
    check($proof['status']==='committed' && $proof['balance_after']===463 && $g->credit('lost:ack',10,'credits',7,'reward:ack',3)===$proof,'lookup proves unknown COMMIT without duplicate delta');
    $c->begin_transaction();$c->query('UPDATE t_af_balance SET credits=credits+1 WHERE uid=10');
    seed($c,'reward:ambient',10,'credits',1);denied(fn()=>$g->credit('ambient',10,'credits',1,'reward:ambient',4),'BALANCE_AMBIENT_TRANSACTION');$c->rollback();
    check((int)af_balance_get(10)['credits']===463,'gateway cannot commit ambient transaction');
    // Native post path still uses legacy floor, tracked totals, edit deltas and hook.
    af_balance_recalc_post_awards(101,50,1,1,'abcdef','fixture');$post=af_balance_get(50);
    check($post['exp']===141 && $post['credits']===12,'ordinary post scaled awards unchanged after upgrade');
    $v=version($c,50);af_balance_recalc_post_awards(101,50,1,1,'abcdef','fixture');check(version($c,50)===$v,'ordinary duplicate post hash behavior unchanged');
    af_balance_recalc_post_awards(101,50,1,1,'abc','fixture');check(af_balance_get(50)['exp']===132 && af_balance_get(50)['credits']===6,'post edit delta unchanged');
    $v=version($c,50);$c->begin_transaction();af_balance_apply_scaled_delta(50,'credits',-2,[],true);$c->rollback();check(version($c,50)===$v && af_balance_get(50)['credits']===6,'native writer joins existing Shop-style transaction');
    af_balance_owner_delta(50,'credits',-100,true);check(af_balance_get(50)['credits']===0,'native manual clamp unchanged');
    // Two real requests sharing key return one receipt, one tx and one delta.
    af_balance_get(20);seed($c,'reward:race',20,'credits',100);
    $req=['type'=>'credit','args'=>['race:same',20,'credits',100,'reward:race',1]];
    $out=parallel($c,$name,20,[$req,$req]);check($out[0]===$out[1] && $out[0]['status']==='committed' && af_balance_get(20)['credits']===100,'concurrent identical credits exactly once');
    af_balance_get(21);seed($c,'reward:race:a',21,'credits',100);seed($c,'reward:race:b',21,'credits',200);
    $out=parallel($c,$name,21,[['type'=>'credit','args'=>['race:a',21,'credits',100,'reward:race:a',1]],['type'=>'credit','args'=>['race:b',21,'credits',200,'reward:race:b',1]]]);
    $ok=array_values(array_filter($out,fn($x)=>($x['status']??'')==='committed'));$conflict=array_values(array_filter($out,fn($x)=>($x['error']??'')==='BALANCE_VERSION_CONFLICT'));
    check(count($ok)===1 && count($conflict)===1 && af_balance_get(21)['credits']===$ok[0]['amount_minor'],'two different concurrent credits use owner CAS');
    af_balance_get(22);seed($c,'reward:native:race',22,'credits',25);
    $out=parallel($c,$name,22,[['type'=>'credit','args'=>['race:native',22,'credits',25,'reward:native:race',1]],['type'=>'native','uid'=>22]]);
    $gateway=$out[0];check(af_balance_get(22)['credits']===100+(($gateway['status']??'')==='committed'?25:0),'native writer cannot overwrite concurrent reward');
    check((int)scalar($c,"SELECT COUNT(*) FROM t_af_balance_tx WHERE source='acs'")===(int)scalar($c,'SELECT COUNT(*) FROM t_af_balance_external_operations'),'exactly one existing accounting tx per receipt');
    $saved=scalar($c,"SELECT result_json FROM t_af_balance_external_operations WHERE operation_key='exp:one'");
    $c->query("UPDATE t_af_balance_external_operations SET result_json='{}' WHERE operation_key='exp:one'");denied(fn()=>$g->lookup('exp:one'),'BALANCE_RECEIPT_INTEGRITY');
    $s=$c->prepare("UPDATE t_af_balance_external_operations SET result_json=? WHERE operation_key='exp:one'");$s->bind_param('s',$saved);$s->execute();
    $before=(int)scalar($c,'SELECT COUNT(*) FROM t_af_balance_external_operations');$g->upgrade();check((int)scalar($c,'SELECT COUNT(*) FROM t_af_balance_external_operations')===$before,'upgrade never resets receipts or versions');
    echo "ALL ACS-10.3.1 BALANCE CHECKS PASSED\n";
} finally { $c->close();$admin->query('DROP DATABASE `'.$name.'`');$admin->close(); }
