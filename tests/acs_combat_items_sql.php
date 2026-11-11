<?php
/** Disposable socket only, real Inventory/ACS SQL; no Gateway/native INSERT execution. */
$socket=$argv[1]??'';if (!str_starts_with($socket,'/tmp/acs222-socket/') || !file_exists($socket)) throw new RuntimeException('Disposable socket required');
require __DIR__.'/combat_snapshot_readonly.php';
$sourceRows=$baseline;
define('TABLE_PREFIX','t_');define('AF_CACHE','/tmp/');define('TIME_NOW',time());
require __DIR__.'/fixtures/InventoryFixtureDatabase.php';
require AF_ADDONS.'advancedinventory/modules/OwnerSchema.php';
require AF_ADDONS.'advancedcombatsystem/modules/Administration/Migrations.php';
require AF_ADDONS.'advancedcombatsystem/modules/Application/EntityService.php';
require AF_ADDONS.'advancedcombatsystem/modules/Application/SealService.php';
require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCommitWriter.php';
function verify_post_check($key,$silent=false):bool {return $key==='token';}
function htmlspecialchars_uni($s):string {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function itemInsert(mysqli $link,string $table,array $row):void {$s=$link->prepare('INSERT INTO t_'.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$v=array_values($row);$s->bind_param(str_repeat('s',count($v)),...$v);$s->execute();$s->close();}
function itemFail(callable $call,string $part):void {try{$call();}catch(Throwable $e){$code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();snapshot_assert(str_contains($code,$part),'Expected '.$part.' got '.$code);return;}throw new RuntimeException('Expected '.$part);}
class ItemAckConnection extends mysqli {public bool $loseAck=false;public bool $changeBeforeBegin=false;public ?mysqli $rival=null;public function begin_transaction(int $flags=0,?string $name=null):bool {if($this->changeBeforeBegin){$this->changeBeforeBegin=false;$this->rival->query('UPDATE t_af_advinv_owner_state SET inventory_version=inventory_version+1 WHERE uid=7');}return parent::begin_transaction($flags,$name);}public function commit(int $flags=0,?string $name=null):bool {$ok=parent::commit($flags,$name);if($this->loseAck){$this->loseAck=false;throw new mysqli_sql_exception('item acknowledgement lost',2013);}return $ok;}}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$server=new mysqli('localhost','root','','',0,$socket);$database='acs622_'.bin2hex(random_bytes(6));$server->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
 $link=new ItemAckConnection('localhost','root','',$database,0,$socket);$link->set_charset('utf8mb4');$db=new InventoryFixtureDatabase($link);
 $schema=file_get_contents(dirname(__DIR__).'/docs/contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql');$items=substr($schema,strpos($schema,'CREATE TABLE af_advinv_items'));$items=substr($items,0,strpos($items,';'));$link->query(str_replace('CREATE TABLE af_advinv_items','CREATE TABLE t_af_advinv_items',$items));
 $link->query('CREATE TABLE t_users(uid INT UNSIGNED PRIMARY KEY) ENGINE=MyISAM');$link->query('INSERT INTO t_users VALUES(7)');
 $link->query('CREATE TABLE t_af_advinv_equipped(uid INT UNSIGNED,equip_slot VARCHAR(64),item_id INT UNSIGNED,updated_at INT UNSIGNED,PRIMARY KEY(uid,equip_slot),KEY uid_item(uid,item_id)) ENGINE=InnoDB');
 $link->query('CREATE TABLE t_af_advinv_support_slots(uid INT UNSIGNED,slot_code VARCHAR(64),item_id BIGINT UNSIGNED,sortorder INT DEFAULT 0,created_at BIGINT DEFAULT 0,updated_at BIGINT DEFAULT 0,PRIMARY KEY(uid,slot_code),KEY uid_item(uid,item_id),KEY uid_sortorder(uid,sortorder)) ENGINE=InnoDB');
 foreach(AF_ADVINV_OwnerSchema::statements('t_') as $sql)$link->query($sql);
 $link->query('CREATE TABLE t_af_kb_entries(id INT UNSIGNED PRIMARY KEY,type VARCHAR(64),`key` VARCHAR(191),active INT,data_json LONGTEXT,meta_json LONGTEXT) ENGINE=InnoDB');
 $link->query('CREATE TABLE t_af_kb_types(id INT PRIMARY KEY,type VARCHAR(64),type_key VARCHAR(64)) ENGINE=InnoDB');
 $link->query('CREATE TABLE t_af_cs_sheets(id INT UNSIGNED PRIMARY KEY,uid INT,tid INT,build_json LONGTEXT,progress_json LONGTEXT,updated_at INT) ENGINE=InnoDB');
 $link->query('CREATE TABLE t_af_charactersheets_accept(tid INT,uid INT,kb_entry_id INT) ENGINE=InnoDB');
 $link->query('CREATE TABLE t_threads(tid INT PRIMARY KEY,fid INT,uid INT,visible INT,closed VARCHAR(30) DEFAULT "0") ENGINE=MyISAM');
 $link->query('CREATE TABLE t_forums(fid INT PRIMARY KEY,open INT,type VARCHAR(10)) ENGINE=MyISAM');$link->query("INSERT INTO t_forums VALUES(2,1,'f')");
 $link->query('CREATE TABLE t_posts(pid INT PRIMARY KEY,uid INT,tid INT,fid INT,visible INT,message TEXT) ENGINE=MyISAM');$link->query("INSERT INTO t_posts VALUES(501,7,51,2,1,'native fixture only')");
 $sourceRows['af_advinv_equipped'][0]['equip_slot']='accessory_1';
 $ring=$itemRules;$ring['item']['equip']['slots']=['accessory_1'];$sourceRows['af_kb_entries'][1]['data_json']=json_encode($ring);$sourceRows['af_kb_entries'][1]['meta_json']=json_encode(['rules'=>$ring]);
 foreach(['af_kb_entries','af_cs_sheets','af_charactersheets_accept','threads','af_advinv_items','af_advinv_equipped'] as $table)foreach($sourceRows[$table] as $row) {if($table==='af_advinv_items')$row+=['created_at'=>1,'updated_at'=>1];itemInsert($link,$table,$row);}
 foreach([[20,'old-weapon','weapon',5],[21,'new-weapon','weapon',10],[22,'potion','consumable',0]] as [$id,$key,$kind,$bonus]) {
  $rules=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'item','version'=>'1.0','item'=>['item_kind'=>$kind,'equip'=>['slots'=>[$kind==='weapon'?'weapon_mainhand':'support_1']]]];
  if($bonus)$rules['base_stats']=[['stat_key'=>'accuracy','mode'=>'flat','value'=>$bonus]];
  else $rules['item']['on_use']=['cooldown'=>0,'cost'=>[],'effects'=>[['op'=>'add_hp','value'=>5]]];
  $entry=snapshot_entry($id,'arpg_item',$key,$rules);$entry['meta_json']=json_encode(['rules'=>$rules]);itemInsert($link,'af_kb_entries',$entry);
  itemInsert($link,'af_advinv_items',['id'=>$id,'uid'=>7,'entity'=>'equipment','subtype'=>$kind,'kb_type'=>'arpg_item','kb_key'=>$key,'qty'=>1,'meta_json'=>$entry['meta_json'],'created_at'=>1,'updated_at'=>1]);
 }
 itemInsert($link,'af_advinv_equipped',['uid'=>7,'equip_slot'=>'weapon_mainhand','item_id'=>20,'updated_at'=>1]);
 $plugins=new class {public function add_hook(...$args){}};
 $mybb=new class($mybb) {public array $user,$usergroup,$settings;public string $request_method='post';public array $input=['my_post_key'=>'token'];public function __construct($m){$this->user=$m->user;$this->usergroup=$m->usergroup+['canview'=>1];$this->settings=$m->settings;}public function get_input($key){return $this->input[$key]??'';}};
 require AF_ADDONS.'advancedinventory/advancedinventory.php';
 $migrations=new AF_ACS_Migrations($db);$migrations->run();
 $access=new class extends AF_ACS_SessionAccess {public function thread(string $tid,?string $uid=null):array {return ['tid'=>51,'fid'=>2,'uid'=>7,'visible'=>1,'closed'=>'0'];}};
 $entities=new AF_ACS_EntityRepository($db);$repo=new AF_ACS_EncounterRepository($db);
 $session=$entities->command('fixture:create','session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$entities->insertSession('51','7',['schema_version'=>1]));$sid=$session['session_id'];
 $entityService=new AF_ACS_EntityService($entities,$access,new AF_ACS_RegistrationSource);
 $registered=$entityService->register(['session_id'=>$sid,'expected_version'=>$session['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','operation_key'=>'fixture_register_622','reason'=>'Disposable equipment registration']);$actor=$registered['entity_id'];
 $grant=AF_ACS_Repository::json(['schema_version'=>1,'combat_operations_enabled'=>true,'inventory_spending_enabled'=>true]);$link->query("UPDATE t_af_acs_controllers SET permissions_json='".$link->real_escape_string($grant)."'");
 if (($argv[2]??'')==='free') {
  $items=new AF_ACS_ItemActionService($repo,$access);$request=['session_id'=>$sid,'entity_id'=>$actor,'expected_version'=>$repo->session($sid)['session_version'],'ability_key'=>'item:swap:21','operation_key'=>'items_free_equip_6221'];
  $free=$items->equipFree($request);snapshot_assert($free['ap_cost']===0 && $items->equipFree($request)===$free,'Free equip cost or replay');
  $source=json_decode($repo->itemEntity($sid,$actor)['payload_json'],true,64,JSON_THROW_ON_ERROR);$after=AF_ACS_ItemActionService::loadoutStats($repo,$sid,$actor,$source,$source['source_snapshot']['characteristics']);
  snapshot_assert($after['accuracy']===$source['source_snapshot']['characteristics']['accuracy']+5,'Duplicate weapon bonus');
  snapshot_assert($link->query("SELECT item_id FROM t_af_advinv_equipped WHERE equip_slot='weapon_mainhand'")->fetch_row()[0]==20,'Free equip changed permanent binding');
  echo "ACS free equipment: 0 AP, idempotent CAS, pinned delta once and permanent binding separation passed\n";return;
 }
 // Seed one pending group turn; tests exercise real Builder/Seal/Writer, not a second combat simulator.
 $e=$repo->itemEntity($sid,$actor);$group=$e['control_group_id'];
 itemInsert($link,'af_acs_encounters',['id'=>1,'session_id'=>$sid,'status'=>'active','encounter_version'=>1,'round_number'=>1,'visibility'=>'gm','config_snapshot_id'=>$e['template_snapshot_id'],'config_json'=>'{"schema_version":1}','created_at'=>time()]);
 itemInsert($link,'af_acs_sides',['id'=>1,'encounter_id'=>1,'name'=>'Fixture','side_version'=>1,'policy_json'=>'{"schema_version":1}']);
 itemInsert($link,'af_acs_participants',['encounter_id'=>1,'entity_id'=>$actor,'side_id'=>1,'participation'=>'engaged','registered_sequence'=>1,'participant_version'=>1,'visibility_json'=>'{"schema_version":1,"scope":"gm"}']);
 itemInsert($link,'af_acs_rounds',['id'=>1,'encounter_id'=>1,'round_number'=>1,'status'=>'open','round_version'=>1,'opened_at'=>time()]);
 $roster=['schema_version'=>1,'group_version'=>'1','members'=>[['entity_id'=>$actor,'template_snapshot_id'=>(string)$e['template_snapshot_id'],'content_hash'=>$e['content_hash']]]];$roster['fingerprint']=hash('sha256',AF_ACS_Repository::json($roster));
 itemInsert($link,'af_acs_turns',['id'=>1,'round_id'=>1,'control_group_id'=>$group,'status'=>'pending','ap_budget'=>3,'ap_spent'=>0,'seal_quota_spent'=>0,'pre_round_ap_commitment'=>0,'turn_version'=>1,'roster_json'=>AF_ACS_Repository::json($roster)]);
 itemInsert($link,'af_acs_entity_turns',['id'=>1,'turn_id'=>1,'entity_id'=>$actor,'start_done'=>1,'end_done'=>0,'reaction_used'=>0,'entity_turn_version'=>1]);
 $boundary=AF_ACS_Clock::entityStart('1');itemInsert($link,'af_acs_operations',['operation_key'=>$boundary,'session_id'=>$sid,'encounter_id'=>1,'kind'=>'clock.entity_start','payload_hash'=>hash('sha256','fixture'),'status'=>'committed','operation_version'=>1,'request_json'=>'{"schema_version":1}','result_json'=>AF_ACS_Repository::json(['schema_version'=>1,'entity_turn_id'=>'1','boundary_key'=>$boundary]),'updated_at'=>time()]);
 $builder=new AF_ACS_BuilderService($repo,$access);$scope=$builder->context($sid,'1','1');
 $actions=[['actor_id'=>$actor,'ability_key'=>'item:swap:21','targets'=>[$actor],'parameters'=>[]],['actor_id'=>$actor,'ability_key'=>'item:consume:22','targets'=>[$actor],'parameters'=>[]]];
 if (($argv[2]??'')==='shortage') {
  $over=[$actions[1],$actions[1]];
  itemFail(fn()=>$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>$over,'scenario'=>'miss']),'ACS_ITEM_INSUFFICIENT_QTY');
  $drafts=new AF_ACS_DraftService($repo,$access);$d=$drafts->save(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','expected_draft_version'=>'0','operation_key'=>'items_shortage_6221','actions'=>$over,'prose'=>'No free rolls']);
  $draws=0;$seal=new AF_ACS_SealService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){$draws++;return 0;}));
  itemFail(fn()=>$seal->command('seal',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],'operation_key'=>'items_shortage_seal1']),'ACS_ITEM_RESERVATION_REJECTED');
  snapshot_assert($draws===0 && $repo->sealQuota('1')===0 && $link->query("SELECT COUNT(*) FROM t_af_advinv_reservations WHERE status='active'")->fetch_row()[0]==0,'Shortage leaked partial reserve/tape/quota');
  echo "ACS shortage: full sequence refused before RNG; owner reserve rollback and no partial seal passed\n";return;
 }
 $bad=$actions[0];$bad['ability_key']='item:swap:9';itemFail(fn()=>$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>[$bad],'scenario'=>'hit']),'ACS_ITEM_EQUIP_DENIED');
 $bad['ability_key']='item:pass:22';itemFail(fn()=>$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>[$bad],'scenario'=>'hit']),'ACS_ITEM_PASS_SPATIAL_UNSUPPORTED');
 $GLOBALS['disabled']=['advancedinventory'];itemFail(fn()=>$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>$actions,'scenario'=>'miss']),'ACS_INVENTORY_UNAVAILABLE');$GLOBALS['disabled']=[];
 $preview=$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>$actions,'scenario'=>'miss']);
 snapshot_assert($preview['virtual_entities'][0]['hp']===95,'Potion preview');snapshot_assert($link->query('SELECT qty FROM t_af_advinv_items WHERE id=22')->fetch_row()[0]==1,'Preview consumed');
 $drafts=new AF_ACS_DraftService($repo,$access);$d=$drafts->save(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','expected_draft_version'=>'0','operation_key'=>'items_draft_622_01','actions'=>$actions,'prose'=>'Native prose remains separate']);
 $draws=0;$seal=new AF_ACS_SealService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){$draws++;return 0;}));
 $sealRequest=['draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],'operation_key'=>'items_seal_622_001'];
 $link->query("UPDATE t_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true}'");
 itemFail(fn()=>$seal->command('seal',$sealRequest),'INV_SPENDING_NOT_AUTHORIZED');snapshot_assert($draws===0,'Unauthorized reserve drew RNG');
 $link->query("UPDATE t_af_acs_controllers SET permissions_json='".$link->real_escape_string($grant)."'");
 $sealed=$seal->command('seal',$sealRequest);snapshot_assert(!$sealed['blocked'],'Seal blocked '.json_encode($sealed));snapshot_assert($seal->command('seal',$sealRequest)===$sealed&&$draws===4,'Repeat seal drew again');
 snapshot_assert($link->query('SELECT qty FROM t_af_advinv_items WHERE id=22')->fetch_row()[0]==1,'Seal consumed');
 $restoredScope=$builder->context($sid,'1','1');$retainedChoices=array_values(array_filter($restoredScope['actors'][0]['choices'],fn($c)=>$c['key']==='item:consume:22'));snapshot_assert(count($retainedChoices)===1&&!empty($retainedChoices[0]['sealed_only']),'Reserved seal disappeared from restored UI');
 if (($argv[2]??'')==='cancel') {
  $cancelRequest=['draft_id'=>$d['draft_id'],'expected_draft_version'=>$sealed['draft_version'],'operation_key'=>'items_cancel_622_01'];
  $cancel=$seal->command('cancel_tail',$cancelRequest);snapshot_assert($seal->command('cancel_tail',$cancelRequest)===$cancel,'Duplicate cancel');
  snapshot_assert($cancel['quota_used']===2 && $link->query("SELECT COUNT(*) FROM t_af_advinv_reservations WHERE item_id=22 AND status='active'")->fetch_row()[0]==0,'Cancel refunded quota or kept consumable');
  $cancel=$seal->command('cancel_tail',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$cancel['draft_version'],'operation_key'=>'items_cancel_622_02']);
  snapshot_assert($cancel['quota_used']===2 && $link->query("SELECT COUNT(*) FROM t_af_advinv_reservations WHERE status='active'")->fetch_row()[0]==0,'Outgoing weapon protection leaked');
  $saved=$drafts->save(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','draft_id'=>$d['draft_id'],'expected_draft_version'=>$cancel['draft_version'],'operation_key'=>'items_resave_622_01','actions'=>[$actions[1]],'prose'=>'same prose']);
  $again=$seal->command('seal',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$saved['draft_version'],'operation_key'=>'items_reseal_622_01']);
  snapshot_assert($again['quota_used']===3 && $draws===6,'New attempt did not pay quota');
  $cancel=$seal->command('cancel_tail',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$again['draft_version'],'operation_key'=>'items_cancel_622_03']);
  $saved=$drafts->save(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','draft_id'=>$d['draft_id'],'expected_draft_version'=>$cancel['draft_version'],'operation_key'=>'items_resave_622_02','actions'=>[$actions[1]],'prose'=>'same prose']);
  itemFail(fn()=>$seal->command('seal',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$saved['draft_version'],'operation_key'=>'items_reseal_622_02']),'ACS_SEAL_QUOTA_EXHAUSTED');
  snapshot_assert($draws===6,'Quota bypass drew RNG');echo "ACS combat items cancellation: release, repeat, no quota refund/re-reservation bypass passed\n";return;
 }
 // A second actual ACS session/controller cannot obtain the reserved last unit.
 $s2=$entities->command('fixture:session2','session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$entities->insertSession('51','7',['schema_version'=>1]));
 $e2=$entityService->register(['session_id'=>$s2['session_id'],'expected_version'=>$s2['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','operation_key'=>'fixture_register_622b','reason'=>'Second disposable session']);
 $link->query("UPDATE t_af_acs_controllers SET permissions_json='".$link->real_escape_string($grant)."'");
 $request=AF_ACS_ItemActionService::request('reserve','acs:fixture:session2',$s2['session_id'],$e2['entity_id'],7,['owner_uid'=>7,'item_id'=>22,'qty'=>1,'session_id'=>$s2['session_id'],'expected_inventory_version'=>(new AF_ADVINV_InventoryRead($db))->version(7,7)]);
 $second=AF_ACS_InventoryAdapter::forCombat($db,7)->reserve($request);snapshot_assert($second['status']==='rejected'&&$second['code']==='INSUFFICIENT_AVAILABLE','Neighbor session spent reserved unit');
 $mybb->input['af_aa_mask']='cosmetic-only';snapshot_assert($seal->status($d['draft_id'])['confirmation_hash']===$sealed['confirmation_hash'],'Mask changed item confirmation');
 $confirmed=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$sealed['draft_version'],'operation_key'=>'items_confirm_622_01','confirmation_hash'=>$sealed['confirmation_hash']]);
 $writer=new AF_ACS_CombatCommitWriter($db,$access);
 $store=new AF_ACS_SubmissionStore($db);$submission=$store->issue(['schema_version'=>1,'draft_id'=>$d['draft_id'],'author_uid'=>'7','expected_draft_version'=>$confirmed['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],fn()=>null);
 $id=$submission['submission_id'];$submission=$store->validating($id,$submission['state_version'],fn()=>null);
 $link->query("UPDATE t_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true}'");
 itemFail(fn()=>$writer->validatePublication($id,$submission['state_version']),'INV_SPENDING_NOT_AUTHORIZED');
 $link->query("UPDATE t_af_acs_controllers SET permissions_json='".$link->real_escape_string($grant)."'");
 $preflight=$writer->validatePublication($id,$submission['state_version']);
 $submission=$store->posting($id,$submission['state_version'],array_intersect_key($preflight,array_flip(['dependency_hash','simulation_hash','confirmation_hash'])),fn()=>null);$submission=$store->confirmPost($id,$submission['state_version'],'501',fn()=>null,'2');
 $writer=new AF_ACS_CombatCommitWriter($db,$access);
 $ownerVersion=$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0];
 $link->query('UPDATE t_af_advinv_owner_state SET inventory_version=inventory_version+1 WHERE uid=7');
 itemFail(fn()=>$writer->commit($id,$submission['state_version']),'ACS_COMBAT_CONFIRMATION_CONFLICT');
 $link->query('UPDATE t_af_advinv_owner_state SET inventory_version='.(int)$ownerVersion.' WHERE uid=7');
 $link->rival=new mysqli('localhost','root','',$database,0,$socket);$link->changeBeforeBegin=true;
 itemFail(fn()=>$writer->commit($id,$submission['state_version']),'ACS_ITEM_DEPENDENCY_CONFLICT');
 $link->query('UPDATE t_af_advinv_owner_state SET inventory_version='.(int)$ownerVersion.' WHERE uid=7');
 $link->query("CREATE TRIGGER fail_item_commit BEFORE INSERT ON t_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='item rollback fixture'");
 itemFail(fn()=>$writer->commit($id,$submission['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER fail_item_commit');
 snapshot_assert($link->query('SELECT qty FROM t_af_advinv_items WHERE id=22')->fetch_row()[0]==1,'Owner write escaped ACS rollback');
 require AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
 $recovery=new AF_ACS_RecoveryService($db,$access);snapshot_assert(!$recovery->inspect($id)['mechanics_confirmed'],'Partial receipt survived rollback');
 $link->loseAck=true;$recovered=$recovery->recover($id,$submission['state_version']);
 snapshot_assert($recovered['mechanics_confirmed']===true && $link->loseAck===false,'Recovery after lost atomic owner/ACS acknowledgement');
 $receipt=$writer->receipt($id);snapshot_assert($receipt['status']==='COMMITTED','No item commit');
 snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_advinv_items WHERE id=22')->fetch_row()[0]==0,'No consume');
 snapshot_assert($writer->commit($id,$submission['state_version'])===$receipt&&$writer->receipt($id)===$receipt,'Duplicate/lost response');
 snapshot_assert($link->query('SELECT ap_spent FROM t_af_acs_turns WHERE id=1')->fetch_row()[0]==2,'Item AP commit');
 snapshot_assert($link->query("SELECT inventory_item_id FROM t_af_acs_equipment WHERE slot_key='weapon_mainhand'")->fetch_row()[0]==21,'Combat equipment missing');
 snapshot_assert($link->query("SELECT item_id FROM t_af_advinv_equipped WHERE equip_slot='weapon_mainhand'")->fetch_row()[0]==20,'Permanent equipment changed');
 require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCardQuery.php';require AF_ADDONS.'advancedcombatsystem/modules/Presentation/CombatCard.php';
 $card=array_values((new AF_ACS_CombatCardQuery($db))->forPosts('51',['501'],$access))[0];$html=AF_ACS_CombatCard::render($card);
 snapshot_assert(str_contains($html,'предмет #22') && !str_contains($html,'исход недоступен') && !str_contains($html,'Вероятность попадания'),'Confirmed item card misrepresented use');
 $known=$recovery->inspect($id);snapshot_assert($known['mechanics_confirmed']===true,'Recovery lost terminal owner result');
 snapshot_assert($link->query("SELECT COUNT(*) FROM t_af_advinv_external_operations WHERE operation_type='consume' AND status='committed'")->fetch_row()[0]==1 && $link->query('SELECT COUNT(*) FROM t_af_acs_post_links')->fetch_row()[0]==1,'Duplicate owner cost/post link');
 snapshot_assert(!class_exists('AF_ACS_MybbPostGateway',false) && $link->query('SELECT COUNT(*) FROM t_posts')->fetch_row()[0]==1,'Item operation created a native post');
 echo "ACS combat items SQL: preview/seal/reserve, repeated seal, swap+consume commit, late owner rollback, duplicate submission/lookup, AP and permanent ownership passed\n";
} finally {$server->query('DROP DATABASE `'.$database.'`');}
