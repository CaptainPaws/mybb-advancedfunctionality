<?php
/** Disposable MySQL only. No forum bootstrap, no production credentials.
 * docker run --rm --network container:<disposable-mysql> -v "$PWD:/app:ro" af-dev:local php /app/tests/acs_inventory_owner_sql.php
 */
define('IN_MYBB', 1);
define('TABLE_PREFIX', 't_');
$enabled = true;
function af_is_addon_enabled($addon): bool { global $enabled; return $enabled; }
$base = dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/';
require_once $base.'advancedinventory/modules/OwnerProtocol.php';
require_once $base.'advancedinventory/modules/OwnerSchema.php';
require_once $base.'advancedcombatsystem/modules/Infrastructure/Adapters/InventoryAdapter.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

final class FixtureAccess implements AF_ADVINV_OwnerAccess
{
    public bool $spend = true;
    public int $calls = 0;
    public bool $revokeUnderLock = false;
    public function __construct(public int $actor = 10) {}
    public function uid(): int { return $this->actor; }
    public function authorize(string $operation, int $ownerUid, array $scope, string $ref, bool $mutation): void
    {
        $this->calls++;
        if ($this->revokeUnderLock && $this->calls >= 2) $this->spend = false;
        // Fixture server policy: own consumables only, two explicit sessions, separate spending right.
        if ($ownerUid !== $this->actor || !in_array($scope['context_key'], ['s1','s2'], true) || $scope['system'] !== 'acs' || $ref !== 'fixture-grant'
            || ($mutation && !$this->spend)) throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
    }
}
final class FaultConnection extends mysqli
{
    public bool $loseCommit = false;
    public bool $failReceipt = false;
    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $ok = parent::commit($flags, $name);
        if ($this->loseCommit) { $this->loseCommit = false; throw new mysqli_sql_exception('lost acknowledgement', 2013); }
        return $ok;
    }
    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->failReceipt && str_starts_with($query, 'INSERT INTO `t_af_advinv_external_operations`')) {
            $this->failReceipt = false;
            throw new mysqli_sql_exception('late receipt fault', 1644);
        }
        return parent::prepare($query);
    }
}
function connectFixture(?string $database = null, bool $fault = false): mysqli
{
    $class = $fault ? FaultConnection::class : mysqli::class;
    $db = new $class('127.0.0.1', 'root', '', $database, 3306);
    $db->set_charset('utf8mb4');
    return $db;
}
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException('FAIL: '.$message); }
function fails(callable $fn, string $code): void
{
    try { $fn(); } catch (Throwable $e) { check($e->getMessage() === $code, $code.' got '.$e->getMessage()); return; }
    throw new RuntimeException('FAIL: expected '.$code);
}
function request(string $operation, string $key, array $payload, string $session = 's1', int $actor = 10): array
{
    $r = ['schema_version'=>1,'operation'=>$operation,'operation_key'=>'acs:'.$key,'actor'=>['uid'=>$actor,'authorization_ref'=>'fixture-grant'],'scope'=>['system'=>'acs','context_key'=>$session],'payload'=>$payload];
    $r['payload_hash'] = AF_ADVINV_OwnerProtocol::payloadHash($r);
    return $r;
}
function reserveRequest(string $key, int $item, int $qty, int $version, string $session = 's1'): array
{
    return request('reserve',$key,['owner_uid'=>10,'item_id'=>$item,'qty'=>$qty,'session_id'=>$session,'expected_inventory_version'=>$version],$session);
}
function lookupRequest(string $key, string $session = 's1'): array { return request('lookup','lookup-'.$key,['target_operation_key'=>'acs:'.$key],$session); }
function scalar(mysqli $db, string $sql): string { return (string)$db->query($sql)->fetch_row()[0]; }
function version(mysqli $db): int { return (int)scalar($db,'SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=10'); }

if (($argv[1] ?? '') === 'worker') {
    $db = connectFixture($argv[2]);
    $r = json_decode(base64_decode($argv[3]),true,32,JSON_THROW_ON_ERROR);
    echo "ready\n";
    flush();
    $service = new AF_ADVINV_OwnerProtocol($db, new FixtureAccess());
    echo json_encode($service->{$r['operation']}($r),JSON_THROW_ON_ERROR)."\n";
    exit;
}
function parallel(mysqli $locker, string $database, array $requests): array
{
    $locker->begin_transaction();
    $locker->query('SELECT uid FROM t_af_advinv_owner_state WHERE uid=10 FOR UPDATE');
    $workers=[];
    foreach ($requests as $r) {
        $process = proc_open([PHP_BINARY,__FILE__,'worker',$database,base64_encode(json_encode($r,JSON_THROW_ON_ERROR))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        check(is_resource($process),'worker started');
        fclose($pipes[0]);
        check(trim(fgets($pipes[1]))==='ready','two independent handles ready');
        $workers[]=[$process,$pipes];
    }
    $deadline = microtime(true) + 3;
    do {
        $waiters = (int)scalar($locker, 'SELECT COUNT(*) FROM performance_schema.data_lock_waits');
        if ($waiters >= 2) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    $locker->commit();
    check($waiters >= 2, 'both independent DB handles actually waited for the owner guard');
    $out=[];
    foreach ($workers as [$process,$pipes]) {
        $text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($process)===0,'worker exit '.$error);
        $out[]=json_decode(trim($text),true,32,JSON_THROW_ON_ERROR);
    }
    return $out;
}

if (defined('ACS_INV_FIXTURE_LIBRARY')) return;

$database = 'acs612_fixture_'.bin2hex(random_bytes(8));
$admin = connectFixture();
$admin->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
$db = connectFixture($database);
try {
    // Existing owner tables, matching Inventory's actual schema (not a second Inventory).
    $proposal=file_get_contents(dirname(__DIR__).'/docs/contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql');
    $items=substr($proposal,strpos($proposal,'CREATE TABLE af_advinv_items'));
    $items=substr($items,0,strpos($items,';'));
    $db->query(str_replace('CREATE TABLE af_advinv_items','CREATE TABLE t_af_advinv_items',$items));
    $db->query('CREATE TABLE t_users (uid INT UNSIGNED PRIMARY KEY) ENGINE=MyISAM');
    $db->query('INSERT INTO t_users VALUES (10),(11)');
    $db->query('CREATE TABLE t_af_advinv_equipped (uid INT UNSIGNED NOT NULL,equip_slot VARCHAR(64) NOT NULL,item_id INT UNSIGNED NOT NULL,updated_at INT UNSIGNED NOT NULL,PRIMARY KEY(uid,equip_slot),KEY uid_item(uid,item_id)) ENGINE=InnoDB');
    $db->query('CREATE TABLE t_af_advinv_support_slots (uid INT UNSIGNED NOT NULL,slot_code VARCHAR(64) NOT NULL,item_id BIGINT UNSIGNED NOT NULL,sortorder INT NOT NULL DEFAULT 0,created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(uid,slot_code),KEY uid_item(uid,item_id),KEY uid_sortorder(uid,sortorder)) ENGINE=InnoDB');
    foreach (AF_ADVINV_OwnerSchema::statements(TABLE_PREFIX) as $sql) $db->query($sql);
    $db->query("INSERT INTO t_af_advinv_items(id,uid,entity,subtype,kb_type,qty,created_at,updated_at) VALUES (21,10,'equipment','consumable','arpg_item',3,1,1),(22,10,'equipment','ammo','arpg_item',1,1,1),(23,11,'equipment','consumable','arpg_item',3,1,1)");
    $access=new FixtureAccess();
    $owner=new AF_ADVINV_OwnerProtocol($db,$access);
    $adapter=new AF_ACS_InventoryAdapter($owner);
    $r=reserveRequest('r1',21,2,1);
    $first=$adapter->reserve($r);
    check($first['status']==='prepared' && $first['reservation']['version']===1,'reserve durable');
    check(scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='3','reserve not deletion');
    check($owner->reserve($r)==$first,'duplicate reserve replays before version');
    $changed=$r;$changed['payload']['qty']=1;$changed['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($changed);
    fails(fn()=>$owner->reserve($changed),'INV_IDEMPOTENCY_CONFLICT');
    check($owner->lookup(lookupRequest('r1'))==$first,'lookup immutable');
    check($owner->reserve(reserveRequest('short',21,2,version($db)))['code']==='INSUFFICIENT_AVAILABLE','over reserve');
    $stale=$owner->reserve(reserveRequest('stale',21,1,1));
    check($stale['code']==='VERSION_CONFLICT' && $owner->lookup(lookupRequest('stale'))==$stale,'durable rejection');
    check($owner->reserve(reserveRequest('foreign',23,1,version($db)))['code']==='ITEM_NOT_OWNED','foreign item');
    foreach ([0,-1,'1',true,1.0,2147483648] as $qty) {
        $bad=$r;$bad['operation_key']='acs:bad';$bad['payload']['qty']=$qty;
        if (!is_float($qty)) $bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);
        fails(fn()=>$owner->reserve($bad),'INV_INVALID_INTEGER');
    }
    $bad=$r;$bad['payload_hash']=str_repeat('0',64);fails(fn()=>$owner->reserve($bad),'INV_PAYLOAD_HASH_MISMATCH');
    $missing=request('consume','missing',['reservation_id'=>'999','qty'=>1,'expected_version'=>1]);
    $missingResult=$owner->consume($missing);
    check($missingResult['code']==='RESERVATION_NOT_FOUND','durable missing reservation');
    $db->query("INSERT INTO t_af_advinv_reservations(id,operation_key,owner_uid,item_id,initial_qty,remaining_qty,context_system,context_key,status,reservation_version,created_at,updated_at) VALUES (999,'acs:future-id',11,23,1,1,'acs','s2','active',1,1,1)");
    check($owner->consume($missing)==$missingResult,'rejected replay independent of future reservation ownership');
    $db->query("DELETE FROM t_af_advinv_reservations WHERE id=999");
    $rid=$first['external_ref'];
    $consume=request('consume','c1',['reservation_id'=>$rid,'qty'=>1,'expected_version'=>1]);
    $access->spend=false;
    fails(fn()=>$owner->consume($consume),'INV_SPENDING_NOT_AUTHORIZED');
    $forged=$consume;$forged['actor']['uid']=11;$forged['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($forged);fails(fn()=>$owner->consume($forged),'INV_ACCESS_DENIED');
    $access->spend=true;
    $over=request('consume','over',['reservation_id'=>$rid,'qty'=>3,'expected_version'=>1]);
    check($owner->consume($over)['code']==='INSUFFICIENT_RESERVED','excess consume rejected');
    check(scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='3','over request keeps row');
    check($owner->consume(request('consume','stale-consume',['reservation_id'=>$rid,'qty'=>1,'expected_version'=>2]))['code']==='VERSION_CONFLICT','reservation version conflict');
    $consumed=$owner->consume($consume);
    check($consumed['status']==='committed' && $consumed['reservation']['remaining_qty']===1,'partial consume');
    check($owner->consume($consume)==$consumed && scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='2','duplicate consume');
    $access->spend=false;fails(fn()=>$owner->consume($consume),'INV_SPENDING_NOT_AUTHORIZED');$access->spend=true;
    $wrongScope=request('consume','other-scope',['reservation_id'=>$rid,'qty'=>1,'expected_version'=>2],'s2');
    fails(fn()=>$owner->consume($wrongScope),'INV_SCOPE_DENIED');
    fails(fn()=>$owner->lookup(lookupRequest('r1','s2')),'INV_ACCESS_DENIED');
    $rel=request('release','release',['reservation_id'=>$rid,'reason'=>'Явное завершение / сессии']);
    $bad=$rel;$bad['payload']['reason']='  ';$bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);fails(fn()=>$owner->release($bad),'INV_INVALID_TEXT');
    $access->spend=false;fails(fn()=>$owner->release($rel),'INV_SPENDING_NOT_AUTHORIZED');$access->spend=true;
    $released=$owner->release($rel);
    check($released['status']==='released' && $released['qty']===1,'release unused only');
    check($owner->release($rel)==$released && scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='2','duplicate release no restore');
    check($owner->release(request('release','release-again',['reservation_id'=>$rid,'reason'=>'Again']))['qty']===0,'new release terminal no op');
    check($owner->consume(request('consume','terminal',['reservation_id'=>$rid,'qty'=>1,'expected_version'=>3]))['code']==='RESERVATION_NOT_ACTIVE','terminal consume denied');
    $pairs=parallel($db,$database,[reserveRequest('race1',22,1,version($db),'s1'),reserveRequest('race2',22,1,version($db),'s2')]);
    check(count(array_filter($pairs,fn($r)=>$r['status']==='prepared'))===1,'two sessions one unit');
    check(scalar($db,"SELECT SUM(remaining_qty) FROM t_af_advinv_reservations WHERE item_id=22 AND status='active'")==='1','global reserve sum');
    $win=array_values(array_filter($pairs,fn($r)=>$r['status']==='prepared'))[0];
    $session=$win['scope']['context_key'];
    $final=request('consume','race-consume',['reservation_id'=>$win['external_ref'],'qty'=>1,'expected_version'=>1],$session);
    $db->query("INSERT INTO t_af_advinv_equipped VALUES (10,'weapon_mainhand',22,1)");
    $db->query("INSERT INTO t_af_advinv_support_slots(uid,slot_code,item_id) VALUES (10,'support_1',22)");
    $pairs=parallel($db,$database,[$final,$final]);
    check($pairs[0]==$pairs[1] && $pairs[0]['status']==='committed','concurrent duplicate consume receipt');
    check(scalar($db,'SELECT COUNT(*) FROM t_af_advinv_items WHERE id=22')==='0','zero deletes owner row');
    check(scalar($db,'SELECT COUNT(*) FROM t_af_advinv_equipped WHERE item_id=22')==='0' && scalar($db,'SELECT COUNT(*) FROM t_af_advinv_support_slots WHERE item_id=22')==='0','binding cleanup');
    check($owner->lookup(lookupRequest('race-consume',$session))==$pairs[0],'receipt survives item deletion');
    $fdb=connectFixture($database,true);$fault=new AF_ADVINV_OwnerProtocol($fdb,new FixtureAccess());
    $fr=$fault->reserve(reserveRequest('fault-reserve',21,1,version($db)));
    $fc=request('consume','lost-commit',['reservation_id'=>$fr['external_ref'],'qty'=>1,'expected_version'=>1]);
    $fdb->loseCommit=true;
    check($fault->consume($fc)['status']==='recovery_required','unknown commit acknowledged unknown');
    $known=$owner->lookup(lookupRequest('lost-commit'));
    check($known['status']==='committed' && $owner->consume($fc)==$known,'lookup lost ack no second spend');
    $fr=$owner->reserve(reserveRequest('rollback-reserve',21,1,version($db)));
    $snapshot=[scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21'),version($db)];
    $fdb->failReceipt=true;
    $rollback=request('consume','rollback',['reservation_id'=>$fr['external_ref'],'qty'=>1,'expected_version'=>1]);
    check($fault->consume($rollback)['status']==='recovery_required','late receipt fault');
    check($owner->lookup(lookupRequest('rollback'))['status']==='not_found','rollback no receipt');
    check([scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21'),version($db)]===$snapshot,'owner quantity/version rollback');
    check(scalar($db,'SELECT remaining_qty FROM t_af_advinv_reservations WHERE id='.(int)$fr['external_ref'])==='1','reservation rollback');
    // Native MyBB may disable mysqli exceptions. False execute still rolls back.
    $db->query("CREATE TRIGGER t_receipt_fault BEFORE INSERT ON t_af_advinv_external_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'");
    mysqli_report(MYSQLI_REPORT_OFF);
    $off=request('consume','off-failure',['reservation_id'=>$fr['external_ref'],'qty'=>1,'expected_version'=>1]);
    check($owner->consume($off)['status']==='recovery_required','false-return execute recognized');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db->query('DROP TRIGGER t_receipt_fault');
    check([scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21'),version($db)]===$snapshot,'false-return rollback');
    $access->calls=0;$access->revokeUnderLock=true;
    fails(fn()=>$owner->consume($rollback),'INV_SPENDING_NOT_AUTHORIZED');
    $access->revokeUnderLock=false;$access->spend=true;
    $db->begin_transaction();$db->query('UPDATE t_af_advinv_items SET qty=2 WHERE id=21');
    fails(fn()=>$owner->consume($rollback),'INV_AMBIENT_TRANSACTION');
    check(scalar($db,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='2','caller transaction not rolled back');
    $db->rollback();
    $db->autocommit(false);fails(fn()=>$owner->consume($rollback),'INV_AMBIENT_TRANSACTION');$db->autocommit(true);
    $none=new AF_ACS_InventoryAdapter();check($none->reserve($r)['code']==='ACS_INVENTORY_WRITERS_NOT_INTEGRATED','default gateway closed');
    $enabled=false;check($adapter->consume($rollback)['code']==='ACS_INVENTORY_UNAVAILABLE','missing dependency');$enabled=true;
    // Missing schema and nontransactional bindings are deployment errors, not fake success.
    $db->query("INSERT INTO t_af_advinv_reservations(operation_key,owner_uid,item_id,initial_qty,remaining_qty,context_system,context_key,status,reservation_version,created_at,updated_at) VALUES ('acs:orphan',10,21,1,1,'acs','s1','active',1,1,1)");
    $orphan=(string)$db->insert_id;
    fails(fn()=>$owner->consume(request('consume','orphan-consume',['reservation_id'=>$orphan,'qty'=>1,'expected_version'=>1])),'INV_RESERVATION_UNCONFIRMED');
    $db->query("DELETE FROM t_af_advinv_reservations WHERE operation_key='acs:orphan'");
    $db->query('ALTER TABLE t_af_advinv_reservations DROP INDEX reservation_once, ADD INDEX reservation_once(operation_key)');
    fails(fn()=>$owner->reserve(reserveRequest('nonunique',21,1,version($db))),'INV_SCHEMA_NOT_READY');
    $db->query('ALTER TABLE t_af_advinv_reservations DROP INDEX reservation_once, ADD UNIQUE INDEX reservation_once(operation_key)');
    $db->query('ALTER TABLE t_af_advinv_support_slots ENGINE=MyISAM');
    fails(fn()=>$owner->reserve(reserveRequest('wrong-engine',21,1,version($db))),'INV_SCHEMA_NOT_READY');
    $db->query('ALTER TABLE t_af_advinv_support_slots ENGINE=InnoDB');
    $realAccess=new AF_ADVINV_MybbOwnerAccess();
    $mybb=(object)['user'=>['uid'=>10],'usergroup'=>['canview'=>1],'request_method'=>'get'];
    fails(fn()=>$realAccess->authorize('consume',11,['system'=>'inventory','context_key'=>'owner:11'],'inventory-owner-v1',true),'INV_SPENDING_NOT_AUTHORIZED');
    fails(fn()=>$realAccess->authorize('consume',10,['system'=>'acs','context_key'=>'s1'],'fixture-grant',true),'INV_SPENDING_NOT_AUTHORIZED');
    fails(fn()=>$realAccess->authorize('consume',10,['system'=>'inventory','context_key'=>'owner:10'],'inventory-owner-v1',true),'INV_CSRF_REQUIRED');
    $fdb->close();check($fault->lookup(lookupRequest('r1'))['status']==='recovery_required','unavailable writer not not_found');
    echo "PASS: reserve race (independent handles), over-request, partial/duplicate/concurrent consume, duplicate release, durable rejected/lookup, payload conflict, permissions/revocation, scope, lost COMMIT ack, late rollback, ambient tx, dependency gate; MySQL ".$db->server_info." PHP ".PHP_VERSION."\n";
} finally {
    $db->close();$admin->query('DROP DATABASE `'.$database.'`');$admin->close();
}
