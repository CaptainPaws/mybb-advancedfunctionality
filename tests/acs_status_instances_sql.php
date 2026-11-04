<?php
declare(strict_types=1);
// Disposable local socket only; never pass production credentials or a forum database.
$socket=$argv[1]??'';
if(!extension_loaded('mysqli')||!str_starts_with($socket,'/tmp/acs512-socket/')||!file_exists($socket)){
    fwrite(STDERR,"Usage: php tests/acs_status_instances_sql.php /tmp/acs512-socket/isolated.sock\n");exit(2);
}
require __DIR__.'/acs_status_instances.php';
define('TABLE_PREFIX','fixture_');
require_once AF_ADDONS.'advancedcombatsystem/modules/Administration/Migrations.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/StatusPersistence.php';
final class StatusSqlFixture extends AF_ACS_Repository {
    use AF_ACS_StatusPersistence;
    public function seed(array $dto):string {
        return $this->transactions->run(function()use($dto){
            $this->writable();
            $this->sql("INSERT INTO ".$this->table('sessions')." (id,tid,owner_uid,status,session_version,next_sequence,schema_version,config_json,created_at,updated_at) VALUES (1,51,7,'active',1,1,1,?,0,0)",[self::json(['schema_version'=>1])]);
            return $this->insertSnapshot('1',['source_system'=>'knowledgebase','source_key'=>'status-strike','contract_id'=>'ic-acs.sealed-definition.v1','contract_version'=>'1'],['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'definition'=>$dto]);
        });
    }
    public function apply(array $before,array $after,array $snapshots,bool $fail=false):array {
        return $this->transactions->run(function()use($before,$after,$snapshots,$fail){
            $this->writable();$this->lockSession('1');$this->statusRows('1',true);
            $result=$this->persistStatusInstances('1',$before,$after,$snapshots);
            $this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1 WHERE id=1');
            if($fail)throw new RuntimeException('status_fixture_fault');return $result;
        });
    }
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$server=new mysqli('localhost','root','','',0,$socket);$database='acs512_'.bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $link=new mysqli('localhost','root','',$database,0,$socket);$link->set_charset('utf8mb4');
    $migrations=new AF_ACS_Migrations($link);$migrations->run();
    $old=$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'010_status_instances' ORDER BY version")->fetch_all(MYSQLI_ASSOC);
    $link->query('DROP TABLE fixture_af_acs_effects');$link->query("DELETE FROM fixture_af_acs_schema_migrations WHERE version='010_status_instances'");$migrations->run();
    $link->query('DROP TABLE fixture_af_acs_effects');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=0 WHERE version='010_status_instances'");
    $migrations->run();$migrations->run();
    resolver_check($migrations->status()['schema_version']==='010_status_instances','010 resume/version failed');
    resolver_check($link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'010_status_instances' ORDER BY version")->fetch_all(MYSQLI_ASSOC)===$old,'001–009 migration records changed');
    $repository=new StatusSqlFixture($link);$snapshot=$repository->seed($dto);
    $definition=$prepared['statuses'][0];$op=instance_apply($definition['instance']);$op['effect_definition_ref']=$definition['effect_definition_ref'];$op['status_definition_ref']=$definition['status_definition_ref'];
    $sqlContext=$context;$sqlContext['state']=$empty;$sqlContext['status_scope']=['session_id'=>'1','encounter_id'=>'1'];
    $sqlContext['stats'][1]['status_hit']=30;$sqlContext['stats'][2]['status_resistance']=10;$sqlContext['relationships']=[1=>[2=>'hostile']];
    $sqlContext['abilities'][1]=['status-strike'=>$prepared];$sqlTape=(new AF_ACS_RandomSource(static fn()=>0))->action([1]);
    $newState=AF_ACS_Resolver::resolve($sqlContext,[['actor_id'=>'1','ability_key'=>'status-strike','targets'=>['2']]],[$sqlTape],'seal')->serverState();
    resolver_check(count($newState['status_instances'])===1,'Resolver did not produce status for SQL transition');
    resolver_check($repository->statusRows('1')===[],'Virtual application wrote SQL');
    $ownerReads=count($db->queries);
    try{$repository->apply([],$newState['status_instances'],[0=>$snapshot],true);throw new RuntimeException('Missing fault');}
    catch(RuntimeException $e){resolver_check($e->getMessage()==='status_fixture_fault','Unexpected rollback error: '.$e->getMessage());}
    resolver_check($repository->statusRows('1')===[]&&(string)$link->query('SELECT session_version FROM fixture_af_acs_sessions WHERE id=1')->fetch_row()[0]==='1','Rollback left partial instance/session mutation');
    $batchOp=$op;$batchOp['source']='3';$batch=instance_run($empty,[$op,$batchOp])->serverState();
    $link->query("CREATE TRIGGER status_fixture_fail BEFORE INSERT ON fixture_af_acs_effects FOR EACH ROW BEGIN IF NEW.source_entity_id=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status fixture failure'; END IF; END");
    try{$repository->apply([],$batch['status_instances'],[0=>$snapshot]);throw new RuntimeException('Missing SQL fault');}
    catch(RuntimeException $e){resolver_check($e->getMessage()==='ACS_DATABASE_ERROR','Wrong SQL fault: '.$e->getMessage());}
    $link->query('DROP TRIGGER status_fixture_fail');resolver_check($repository->statusRows('1')===[],'Second INSERT failure left first instance');
    $persisted=$repository->apply([],$newState['status_instances'],[0=>$snapshot]);$projection=$repository->statusProjection('1');$id=$persisted['id_map']['new:1'];
    resolver_check(count($projection['instances'])===1&&$projection['instances'][$id]['id']===$id&&$projection['instances'][$id]['spec']===$definition['instance'],'SQL projection lost identity/pins');
    $state=$empty;$state['status_instances']=$projection['instances'];$state['next_status_order']=$projection['next_order'];
    $refreshed=instance_run($state,[$op])->serverState();$repository->apply($state['status_instances'],$refreshed['status_instances'],[]);
    $rows=$repository->statusRows('1');
    try{$repository->apply($state['status_instances'],$refreshed['status_instances'],[]);throw new RuntimeException('Missing CAS fault');}
    catch(RuntimeException $e){resolver_check($e->getMessage()==='ACS_STATUS_VERSION_CONFLICT','Wrong CAS error');}
    resolver_check($repository->statusRows('1')===$rows,'Stale CAS mutated instance');
    $beforeCorrupt=$rows[0]['payload_json'];$corrupt=json_decode($beforeCorrupt,true,64,JSON_THROW_ON_ERROR);$corrupt['schema_version']=99;
    $st=$link->prepare('UPDATE fixture_af_acs_effects SET payload_json=? WHERE id=?');$json=json_encode($corrupt,JSON_THROW_ON_ERROR);$st->bind_param('ss',$json,$id);$st->execute();
    try{$repository->statusProjection('1');throw new RuntimeException('Unknown schema accepted');}catch(RuntimeException $e){resolver_check($e->getMessage()==='ACS_STATUS_SCHEMA_UNSUPPORTED','Wrong unknown schema error');}
    $st->bind_param('ss',$beforeCorrupt,$id);$st->execute();
    resolver_check(count($db->queries)===$ownerReads,'Persistent read looked up live KB');
    // The existing combat reset owns the completion cleanup and its idempotent event receipts.
    $encounters=new AF_ACS_EncounterRepository($link);$session=$encounters->session('1');
    $request=['schema_version'=>1,'session_id'=>'1','actor_uid'=>'7','expected_version'=>$session['session_version']];
    $createdEncounter=$encounters->command('status-fixture-create','encounter.create',$request,static function(){},
        fn($session)=>$encounters->createEncounter($session,'Status fixture',['schema_version'=>1,'defaults_version'=>'acs-preparation-1','visibility'=>'hidden']));
    resolver_check($createdEncounter['encounter_id']==='1','Unexpected fixture encounter');
    $link->query("UPDATE fixture_af_acs_encounters SET status='completed',completed_at=1 WHERE id=1");
    $encounter=$encounters->encounter('1','1');$request['expected_version']=$encounters->session('1')['session_version'];
    $reset=fn()=>$encounters->command('status-fixture-reset','clock.combat_reset',$request,static function(){},
        fn($session)=>$encounters->resetCombatCooldowns($session,$encounter,'7'));
    $reset();$afterReset=$encounters->statusRows('1');resolver_check($afterReset[0]['status']==='removed'&&(string)$afterReset[0]['stacks']==='0','Combat reset did not remove status');
    $reset();resolver_check($encounters->statusRows('1')===$afterReset,'Combat reset replay changed version');
    echo "ACS status SQL: migration 010 resume/readback, immutable pins and SQL identity, preview isolation, actual TransactionManager rollback after write/second INSERT, stale CAS, unknown schema and existing combat-reset journal/replay passed\n";
} finally {
    if(isset($link))$link->close();$server->query('DROP DATABASE `'.$database.'`');$server->close();
}
