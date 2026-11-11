<?php
/** Targeted disposable integration: real owner API and ACS journals, never a forum DB. */
$GLOBALS['acsCustodyFixture']=static function(array $fixture):void {
 extract($fixture);global $mybb,$db;
 require_once AF_ADDONS.'advancedcombatsystem/modules/Application/InventoryOperationService.php';
 require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
 require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/InventoryObligationsQuery.php';
 require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/InventoryObligations.php';
 require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
 $link->query('INSERT INTO t_users VALUES(8)');
 $link->query("ALTER TABLE t_af_kb_types ADD mechanic_key VARCHAR(32),ADD active INT DEFAULT 1");$link->query("INSERT INTO t_af_kb_types VALUES(1,'arpg_item','arpg_item','arpg',1)");
 $meta=json_encode(['rules'=>['schema'=>'af_kb.arpg.rules.v1','item_kind'=>'consumable']],JSON_UNESCAPED_SLASHES);
 itemInsert($link,'af_kb_entries',['id'=>88,'type'=>'arpg_item','key'=>'recovery-potion','active'=>1,'data_json'=>'{}','meta_json'=>$meta]);
 $policy=new class implements AF_ADVINV_OwnerAccess {
  public function uid():int {global $mybb;return (int)$mybb->user['uid'];}
  public function authorize(string $op,int $owner,array $scope,string $ref,bool $mutation):void {if ($this->uid()!==7 || $scope['system']!=='acs' || $ref!=='fixture-property-grant')throw new RuntimeException('INV_ACCESS_DENIED');}
 };
 $ownerLink=new class('localhost','root','',$database,0,$socket) extends mysqli {
  public bool $loseAck=false,$lateACSFailure=false;
  public function commit(int $flags=0,?string $name=null):bool {$ok=parent::commit($flags,$name);if($this->lateACSFailure){$this->lateACSFailure=false;$this->query("CREATE TRIGGER fail_owner_ack BEFORE INSERT ON t_af_acs_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late ack failure'");}if($this->loseAck){$this->loseAck=false;throw new mysqli_sql_exception('owner ack lost',2013);}return $ok;}
 };
 $ownerLink->set_charset('utf8mb4');$owner=new AF_ADVINV_OwnerProtocol($ownerLink,$policy);
 $service=new AF_ACS_InventoryOperationService($db,$access,new AF_ACS_InventoryAdapter($owner));$recovery=new AF_ACS_RecoveryService($db,$access);$operations=new AF_ACS_OperationStore($db);
 $make=static function(string $op,string $key,array $payload)use($sid):array {$r=['schema_version'=>1,'operation'=>$op,'operation_key'=>'acs:632:'.$key,'actor'=>['uid'=>7,'authorization_ref'=>'fixture-property-grant'],'scope'=>['system'=>'acs','context_key'=>$sid],'payload'=>$payload];$r['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($r);return $r;};
 $hash=hash('sha256',$meta);$grant=$make('grant','grant-lost',['recipient_uid'=>7,'normalized_item'=>['qty'=>2,'definition_ref'=>['type'=>'arpg_item','key'=>'recovery-potion','version'=>'sha256:'.$hash,'hash'=>$hash],'snapshot_json'=>$meta,'entity'=>'equipment','subtype'=>'consumable'],'provenance'=>['system'=>'acs','source_key'=>'fixture:reward:632']]);
 $ownerLink->loseAck=true;$unknown=$service->execute($sid,$grant);snapshot_assert($unknown['status']==='recovery_required','Unknown grant guessed success');
 $row=$operations->get($grant['operation_key']);$total=$link->query('SELECT SUM(qty) FROM t_af_advinv_items')->fetch_row()[0];$events=$link->query('SELECT COUNT(*) FROM t_af_acs_events')->fetch_row()[0];
 itemFail(fn()=>$recovery->recoverInventory($sid,$grant['operation_key'],'1'),'ACS_VERSION_CONFLICT');
 itemFail(fn()=>(new AF_ACS_ItemActionService($repo,$access))->prepare($sid,$actor,'item:consume:22'),'ACS_INVENTORY_RECOVERY_REQUIRED');
 $GLOBALS['disabled']=['advancedinventory'];$unavailable=$recovery->recoverInventory($sid,$grant['operation_key'],$row['operation_version']);
 snapshot_assert($unavailable['status']==='recovery_required'&&$unavailable['lookup_state']==='unavailable','Disabled owner guessed outcome');
 $page=(new AF_ACS_InventoryObligationsQuery($db))->page($sid,$access);snapshot_assert($page['inventory']==='unavailable'&&$page['operations'][0]['status']==='recovery_required','Outage panel lost obligation');$GLOBALS['disabled']=[];
 $mybb->user['uid']=8;itemFail(fn()=>$recovery->recoverInventory($sid,$grant['operation_key'],$row['operation_version']),'ACS_INVENTORY_RECOVERY_PERMISSION_DENIED');$mybb->user['uid']=7;
 $found=$recovery->recoverInventory($sid,$grant['operation_key'],$row['operation_version']);snapshot_assert($found['status']==='committed'&&$found['result']['owner_receipt']['payload_hash']===$grant['payload_hash'],'Recovery lost owner receipt');
 snapshot_assert($recovery->recoverInventory($sid,$grant['operation_key'],'1')===$found,'Terminal recovery not idempotent');
 snapshot_assert($service->execute($sid,$grant)===$found&&$link->query('SELECT SUM(qty) FROM t_af_advinv_items')->fetch_row()[0]===$total,'Duplicate grant');
 $reordered=array_reverse($grant,true);$reordered['scope']=array_reverse($grant['scope'],true);snapshot_assert($service->execute($sid,$reordered)===$found,'Canonical retry conflicted');
 $change=$grant;$change['payload']['normalized_item']['qty']=1;$change['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($change);itemFail(fn()=>$service->execute($sid,$change),'ACS_OPERATION_KEY_CONFLICT');
 // Owner commits; failure of the later ACS audit cannot undo that external effect or clear its barrier.
 $version=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0];
 $transfer=$make('transfer','transfer-late',['item_id'=>22,'qty'=>1,'from_uid'=>7,'to_uid'=>8,'expected_versions'=>['from'=>$version,'to'=>1]]);
 $ownerLink->lateACSFailure=true;itemFail(fn()=>$service->execute($sid,$transfer),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER fail_owner_ack');
 snapshot_assert($operations->get($transfer['operation_key'])['status']==='recovery_required','Late ack failure cleared barrier');
 $moved=$recovery->recoverInventory($sid,$transfer['operation_key'],'2');snapshot_assert($moved['status']==='committed','Transfer lookup failed');
 snapshot_assert($service->execute($sid,$transfer)===$moved&&$link->query('SELECT SUM(qty) FROM t_af_advinv_items')->fetch_row()[0]===$total,'Duplicate transfer');
 // A standalone transfer invalidates the old pinned kit in another real ACS session.
 $second=$entities->command('fixture:recovery2','session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$entities->insertSession('51','7',['schema_version'=>1]));$sid2=$second['session_id'];
 $e2=$entityService->register(['session_id'=>$sid2,'expected_version'=>$second['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','operation_key'=>'recovery_character2','reason'=>'Cross-session equipment dependency']);$actor2=$e2['entity_id'];
 $v7=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0];$v8=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=8')->fetch_row()[0];
 $kitMove=$make('transfer','kit-transfer',['item_id'=>20,'qty'=>1,'from_uid'=>7,'to_uid'=>8,'expected_versions'=>['from'=>$v7,'to'=>$v8]]);$ownerLink->loseAck=true;$kitUnknown=$service->execute($sid,$kitMove);snapshot_assert($kitUnknown['status']==='recovery_required','Kit move not held for recovery');
 $e=$repo->itemEntity($sid2,$actor2);$reg=json_decode($e['payload_json'],true,64,JSON_THROW_ON_ERROR);
 itemFail(fn()=>AF_ACS_ItemActionService::loadoutStats($repo,$sid2,$actor2,$reg,$reg['source_snapshot']['characteristics']),'ACS_INVENTORY_RECOVERY_REQUIRED');
 $recovery->recoverInventory($sid,$kitMove['operation_key'],'2');
 itemFail(fn()=>AF_ACS_ItemActionService::loadoutStats($repo,$sid2,$actor2,$reg,$reg['source_snapshot']['characteristics']),'ACS_ITEM_DEPENDENCY_CONFLICT');
 // Partial stack remains valid for a newly prepared snapshot after the owner version advances.
 $stack=$link->query('SELECT * FROM t_af_advinv_items WHERE id=21')->fetch_assoc();$stack['id']=1000;$stack['subtype']='consumable';$stack['kb_key']='potion';$stack['meta_json']=$link->query("SELECT meta_json FROM t_af_kb_entries WHERE `key`='potion'")->fetch_row()[0];$stack['qty']=2;itemInsert($link,'af_advinv_items',$stack);
 itemInsert($link,'af_advinv_support_slots',['uid'=>7,'slot_code'=>'support_1','item_id'=>1000,'sortorder'=>0,'created_at'=>1,'updated_at'=>1]);
 $registerStack=static function(string $key)use($entities,$entityService):array {$s=$entities->command('fixture:'.$key,'session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$entities->insertSession('51','7',['schema_version'=>1]));$e=$entityService->register(['session_id'=>$s['session_id'],'expected_version'=>$s['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','operation_key'=>$key,'reason'=>'Partial stack snapshot']);return [$s['session_id'],$e['entity_id']];};
 [$oldSid,$oldActor]=$registerStack('recovery_stack_before');$v7=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0];$v8=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=8')->fetch_row()[0];
 $partial=$make('transfer','partial-stack',['item_id'=>1000,'qty'=>1,'from_uid'=>7,'to_uid'=>8,'expected_versions'=>['from'=>$v7,'to'=>$v8]]);snapshot_assert($service->execute($sid,$partial)['status']==='committed','Partial transfer rejected');
 $oldReg=json_decode($repo->itemEntity($oldSid,$oldActor)['payload_json'],true,64,JSON_THROW_ON_ERROR);itemFail(fn()=>AF_ACS_ItemActionService::loadoutStats($repo,$oldSid,$oldActor,$oldReg,$oldReg['source_snapshot']['characteristics']),'ACS_ITEM_DEPENDENCY_CONFLICT');
 [$freshSid,$freshActor]=$registerStack('recovery_stack_after');$fresh=json_decode($repo->itemEntity($freshSid,$freshActor)['payload_json'],true,64,JSON_THROW_ON_ERROR);$freshStats=AF_ACS_ItemActionService::loadoutStats($repo,$freshSid,$freshActor,$fresh,$fresh['source_snapshot']['characteristics']);snapshot_assert($freshStats===$fresh['source_snapshot']['characteristics'],'Fresh pin blocked by old transfer');
 // Proved absence remains pending: this coordinator deliberately exposes no blind retry command.
 $absent=$make('transfer','not-dispatched',['item_id'=>21,'qty'=>1,'from_uid'=>7,'to_uid'=>8,'expected_versions'=>['from'=>1,'to'=>1]]);
 $operations->inventoryRegister($sid,$absent,fn()=>null);$operations->inventoryTransition($absent['operation_key'],'1','recovery_required',['schema_version'=>1],fn()=>null);
 $none=$recovery->recoverInventory($sid,$absent['operation_key'],'2');snapshot_assert($none['status']==='recovery_required'&&$none['lookup_state']==='not_found','not_found guessed rejection or retried');
 $mybb->request_method='get';$before=$link->query('SELECT COUNT(*) FROM t_af_acs_events')->fetch_row()[0];$page=(new AF_ACS_InventoryObligationsQuery($db))->page($sid,$access);$html=AF_ACS_InventoryObligations::render($page,'token');
 snapshot_assert(str_contains($html,'recovery_required')&&str_contains($html,'lookup')&&!str_contains($html,$meta),'Panel hides pending state or leaks raw Inventory');
 snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_acs_events')->fetch_row()[0]===$before,'GET panel wrote state');$mybb->request_method='post';
 $sessions=new AF_ACS_SessionService($repo,$access);$close=['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'inventory_close_6321','reason'=>'Preserve pending obligations'];
 $closed=$sessions->command('close',$close);snapshot_assert($closed['status']==='closed'&&$sessions->command('close',$close)===$closed,'Close/replay failed');
 $archive=$close;$archive['expected_version']=$closed['session_version'];$archive['operation_key']='inventory_archive632';$sessions->command('archive',$archive);
 $migrations->run();$migrations->assertInstalled();snapshot_assert($operations->get($absent['operation_key'])['status']==='recovery_required'&&$operations->get($grant['operation_key'])['status']==='committed','Archive/reactivation erased operations');
 $bad=$found['result']['owner_receipt'];$bad['payload_hash']=str_repeat('0',64);unset($bad['result_hash']);$bad['result_hash']=hash('sha256',AF_ADVINV_OwnerProtocol::canonical($bad));itemFail(fn()=>AF_ACS_InventoryReceipt::verify($grant,$bad),'ACS_INVENTORY_OPERATION_INTEGRITY');
 echo "ACS Inventory recovery: durable grant/transfer identities, lost owner response, lookup-only reconciliation, duplicate prevention, CAS, dependency outage, late acknowledgement rollback, pending archive/reactivation and GM panel passed\n";
};
require __DIR__.'/acs_combat_items_sql.php';
