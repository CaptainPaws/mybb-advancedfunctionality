<?php
define('ACS_INV_WRITER_LIBRARY',1);
require __DIR__.'/acs_inventory_writers_sql.php';
require AF_ADDONS.'advancedinventory/modules/InventoryRead.php';
require AF_ADDONS.'advancedcombatsystem/modules/Administration/Migrations.php';
final class EquipmentFaultDatabase {
 public mysqli $read_link; public bool $missing=false;public bool $drift=false;public ?mysqli $other=null;
 private InventoryFixtureDatabase $delegate;
 public function __construct(public mysqli $write_link) {$this->read_link=$write_link;$this->delegate=new InventoryFixtureDatabase($write_link);}
 public function __call($name,$args){return $this->delegate->$name(...$args);}
 public function write_query($sql){
  if(str_contains($sql,'LIMIT 100,100')) {
   if($this->missing) $sql=str_replace('LIMIT 100,100','LIMIT 100,99',$sql);
   if($this->drift){$this->other->query('UPDATE t_af_advinv_owner_state SET inventory_version=inventory_version+1 WHERE uid=10');$this->drift=false;}
  }
  return $this->delegate->write_query($sql);
 }
}
$database='acs621_'.bin2hex(random_bytes(5));$admin=connectFixture();$admin->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db=connectFixture($database);
try {
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
    $native=$db;$db=new EquipmentFaultDatabase($native);
    $plugins=new class { public function add_hook(...$args) {} };
    $mybb=(object)['settings'=>['af_advancedinventory_debug_enabled'=>0],'user'=>['uid'=>10],'usergroup'=>['canview'=>1]];
    require AF_ADDONS.'advancedinventory/advancedinventory.php';

 $native->query('INSERT INTO t_af_advinv_owner_state(uid,inventory_version) VALUES (10,1)');
 for($i=100;$i<703;$i++)$native->query("INSERT INTO t_af_advinv_items(id,uid,entity,subtype,kb_type,qty,created_at,updated_at) VALUES ($i,10,'equipment','gear','arpg_item',1,1,1)");
 $reader=new AF_ADVINV_InventoryRead($db);$result=$reader->read(10,10,1);
 check(count($result->serverData()['items'])===605,'pagination >500');check(!str_contains(json_encode($result),'items'),'raw serialization');
 $db->missing=true;fails(fn()=>$reader->read(10,10,1),'INV_INCOMPLETE_SNAPSHOT');$db->missing=false;
 $db->other=connectFixture($database);$db->drift=true;fails(fn()=>$reader->read(10,10,1),'INV_SOURCE_CHANGED');
 fails(fn()=>$reader->read(10,10,1),'INV_VERSION_CONFLICT');
 fails(fn()=>$reader->fence(10,10),'INV_TRANSACTION_REQUIRED');
 $native->begin_transaction();$reader->fence(10,10);$reader->read(10,10,2);$native->rollback();
 $migration=new AF_ACS_Migrations($native);$migration->run();
 check(scalar($native,"SELECT COUNT(*) FROM t_af_acs_schema_migrations WHERE status='completed'")==='13','migration ledger');
 $before=$native->query('SELECT * FROM t_af_acs_schema_migrations ORDER BY version')->fetch_all(MYSQLI_ASSOC);$migration->run();
 check($before===$native->query('SELECT * FROM t_af_acs_schema_migrations ORDER BY version')->fetch_all(MYSQLI_ASSOC),'migration repeat');
 require AF_ADDONS.'knowledgebase/knowledgebase.php';
 require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/EntityRepository.php';
 $fixture=tempnam(sys_get_temp_dir(),'acs621-');
 try {
  $process=proc_open([PHP_BINARY,__DIR__.'/acs_equipment_provenance.php','--export',$fixture],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);check(proc_close($process)===0,$output.$errors);
  $evidence=json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);
 } finally {unlink($fixture);}
 // Retain owner scalar types when exporting evidence for SQL persistence checks.
 $repo=new AF_ACS_EntityRepository($native);
 $created=$repo->command('fixture:create','session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$repo->insertSession('51','7',['schema_version'=>1]));
 $sid=$created['session_id'];$cs=$evidence['cs'];$bundle=$evidence['bundle'];
 $reg=['owner_uid'=>'7','kind'=>'character','sheet_id'=>'3','source'=>['source_system'=>'charactersheets','source_key'=>'3','contract_id'=>'ic-cs.combat-preparation.v1','contract_version'=>'1'],'source_fingerprint'=>$cs['fingerprint'],'snapshot'=>['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-registration-1','source_snapshot'=>$cs,'equipment_snapshot'=>$bundle],'stats'=>$cs['characteristics'],'hp'=>'90','hp_max'=>'100','resources'=>[],'controller_uid'=>'7','name'=>'Fixture'];
 $request=['schema_version'=>1,'actor_uid'=>'7','session_id'=>$sid,'expected_version'=>$created['session_version']];
 $apply=fn($session)=>$repo->register($session,$reg);
 $registered=$repo->command('fixture:register','entity.register',$request,fn()=>null,$apply);
 check($repo->command('fixture:register','entity.register',$request,fn()=>null,$apply)===$registered,'registration retry');
 check(scalar($native,'SELECT COUNT(*) FROM t_af_acs_equipment')==='1','equipment recreated');
 check(scalar($native,'SELECT entity_version FROM t_af_acs_entities')==='2','initial CAS');
 $method=new ReflectionMethod($repo,'initializeEquipment');
 $request['expected_version']=$registered['session_version'];
 fails(fn()=>$repo->command('fixture:stale','entity.register',$request,fn()=>null,function($session)use($method,$repo,$sid,$registered,$bundle,$cs){$method->invoke($repo,$sid,$registered['entity_id'],'1',$bundle,$cs);return $session;}),'ACS_EQUIPMENT_VERSION_CONFLICT');
 $migration->run();check(scalar($native,'SELECT COUNT(*) FROM t_af_acs_equipment')==='1','activation recreated equipment');
 echo "Equipment SQL: pagination, missing page, source drift, version, transaction fence, migration repeat OK\n";
} finally {$admin->query('DROP DATABASE `'.$database.'`');}
