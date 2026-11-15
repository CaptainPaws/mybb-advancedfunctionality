<?php
/** Real combat -> generation -> allocation -> standalone owner grant. Disposable SQL only. */
$GLOBALS['acsLootIssueFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/LootIssueController.php';
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    // Begin this dedicated issue fixture in a closed, populated session; archive uses the real command below.
    $link->query("UPDATE t_af_acs_sessions SET status='closed' WHERE id=".$sid);
    $link->query('ALTER TABLE t_af_kb_types ADD mechanic_key VARCHAR(32),ADD active INT DEFAULT 1');$link->query("INSERT INTO t_af_kb_types VALUES(1,'arpg_item','arpg_item','arpg',1)");
    $ownerLink=new class('localhost','root','',$database,0,$socket) extends ItemAckConnection {
        public ?Closure $afterCommit=null;
        public function commit(int $flags=0,?string $name=null):bool {$ok=parent::commit($flags,$name);if($this->afterCommit!==null){$call=$this->afterCommit;$this->afterCommit=null;$call();}return $ok;}
    };$ownerLink->set_charset('utf8mb4');
    $factory=static fn($s,$e,$id,$a)=>AF_ACS_InventoryAdapter::forLootIssue($ownerLink,$s,$e,$id,$a);
    $issue=new AF_ACS_LootIssueService($db,$access,$factory);$before=$captureAll();
    $request=static fn(string $key,string $id)=>['session_id'=>$sid,'encounter_id'=>'1','entry_id'=>$id,'expected_version'=>$repo->session($sid)['session_version'],'expected_allocation_version'=>'1','operation_key'=>$key,'reason'=>'Disposable safe loot issue'];
    $gmEntry=$entryIds['guaranteed'][0];$pendingEntry=$entryIds['weighted_pool'][1];$lostEntry=$entryIds['weighted_pool'][0];$rejectEntry=$entryIds['independent_roll'][0];$lateEntry=$entryIds['guaranteed'][1];
    $total=static fn(int $uid)=>(int)$link->query('SELECT COALESCE(SUM(qty),0) FROM t_af_advinv_items WHERE uid='.$uid)->fetch_row()[0];
    $mybb->user['uid']=8;itemFail(fn()=>$issue->issue($request('issue_player_denied01',$gmEntry)),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $bad=$request('issue_bad_recipient01',$gmEntry)+['recipient_uid'=>'7'];itemFail(fn()=>$issue->issue($bad),'ACS_LOOT_INPUT_INVALID');
    $mybb->request_method='get';itemFail(fn()=>$issue->issue($request('issue_get_denied01',$gmEntry)),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $mybb->input['my_post_key']='bad';itemFail(fn()=>$issue->issue($request('issue_csrf_denied01',$gmEntry)),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    // Two SQL requests interleave after intent commit, before the first owner dispatch.
    $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');$rivalDb=new InventoryFixtureDatabase($rival);$competing=new AF_ACS_LootIssueService($rivalDb,$access,$factory);$raced=false;
    $raceFactory=static function($s,$e,$id,$a)use($factory,$competing,$request,&$raced){$adapter=$factory($s,$e,$id,$a);if(!$raced){$raced=true;$won=$competing->issue($request('issue_competing_request01',$id));snapshot_assert($won['status']==='issued','Competing issue failed '.json_encode($won));}return $adapter;};
    $r=$request('issue_success_closed01',$gmEntry);$qty=$total(8);$success=(new AF_ACS_LootIssueService($db,$access,$raceFactory))->issue($r);
    snapshot_assert($success['status']==='issued'&&$success['recipient_uid']==='8'&&$raced&&$total(8)===$qty+2,'Allocated -> issued / wrong recipient '.json_encode($success));$rival->close();
    $snapshot=$captureAll();snapshot_assert($issue->issue($r)===$success&&$captureAll()===$snapshot,'Duplicate issue mutated owner/ACS');snapshot_assert($issue->issue($request('issue_other_client_key01',$gmEntry))===$success&&$captureAll()===$snapshot,'New client key created grant');
    $GLOBALS['disabled']=['advancedinventory'];$p=$request('issue_unavailable_closed01',$pendingEntry);$held=$issue->issue($p);$GLOBALS['disabled']=[];
    snapshot_assert($held['status']==='pending_issue'&&$held['owner_status']==='prepared'&&$held['recovery_code']==='ACS_INVENTORY_UNAVAILABLE','Unavailable Inventory lost intent '.json_encode($held));
    $saved=$repo->lootIssueIntent($sid,'1',$pendingEntry);$intent=$saved['intent'];$adapter=$factory($sid,'1',$pendingEntry,$access);
    foreach(['recipient','qty','key'] as $change){$changed=$intent;if($change==='recipient')$changed['payload']['recipient_uid']=8;elseif($change==='qty')++$changed['payload']['normalized_item']['qty'];else $changed['operation_key'].=':new';$changed['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($changed);itemFail(fn()=>$adapter->grant($changed),'INV_LOOT_INTENT_CONFLICT');}
    $lookupAdapter=AF_ACS_InventoryAdapter::forRecovery($db,$intent,$access);itemFail(fn()=>$lookupAdapter->grant($intent),'INV_LOOT_INTENT_CONFLICT');
    $default=$intent;$default['operation_key']='inventory:loot:default-denied';$default['actor']['authorization_ref']='inventory-owner-v1';$default['scope']=['system'=>'inventory','context_key'=>'owner:7'];$default['payload']['provenance']=['system'=>'inventory','source_key'=>'no-default-loot-grant'];$default['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($default);itemFail(fn()=>(new AF_ADVINV_OwnerProtocol($ownerLink))->grant($default),'INV_GRANT_NOT_AUTHORIZED');
    $link->query("UPDATE t_af_acs_session_roles SET role='gm' WHERE uid=8 AND session_id=".$sid);$mybb->user['uid']=8;itemFail(fn()=>$issue->issue($request('issue_other_gm_denied01',$pendingEntry)),'ACS_LOOT_ISSUER_REQUIRED');$mybb->user['uid']=7;$link->query("UPDATE t_af_acs_session_roles SET role='player' WHERE uid=8 AND session_id=".$sid);
    $qty=$total(7);$absent=$issue->recover($sid,'1',$pendingEntry,'1');snapshot_assert($absent['status']==='pending_issue'&&$absent['lookup_state']==='not_found'&&$total(7)===$qty,'Lookup not_found dispatched a grant');
    $archive=['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'issue_archive_pending01','reason'=>'Preserve pending exact loot issue'];$sessions->command('archive',$archive);
    $issuedPending=$issue->issue($p);snapshot_assert($issuedPending['status']==='issued'&&$issuedPending['operation_key']===$held['operation_key']&&$total(7)===$qty+1&&$repo->session($sid)['status']==='archived','Archive pending issue changed identity');
    // Owner commits but loses ACK. A semantic receipt forgery is not accepted into the ACS terminal ledger.
    $qty=$total(7);$ownerLink->loseAck=true;$lost=$issue->issue($request('issue_owner_lost_ack01',$lostEntry));snapshot_assert($lost['status']==='pending_issue'&&$lost['owner_status']==='recovery_required'&&$total(7)===$qty+2,'Lost ACK guessed outcome '.json_encode($lost));
    $key=$lost['operation_key'];$stmt=$link->prepare('SELECT result_json,result_hash FROM t_af_advinv_external_operations WHERE operation_key=?');$stmt->bind_param('s',$key);$stmt->execute();$original=$stmt->get_result()->fetch_assoc();$forged=json_decode($original['result_json'],true);unset($forged['result_hash']);++$forged['qty'];$forged['result_hash']=hash('sha256',AF_ADVINV_OwnerProtocol::canonical($forged));$json=AF_ADVINV_OwnerProtocol::canonical($forged);$hash=$forged['result_hash'];$stmt=$link->prepare('UPDATE t_af_advinv_external_operations SET result_json=?,result_hash=? WHERE operation_key=?');$stmt->bind_param('sss',$json,$hash,$key);$stmt->execute();
    $corrupt=$issue->recover($sid,'1',$lostEntry,'2');snapshot_assert($corrupt['status']==='pending_issue'&&$corrupt['owner_status']==='recovery_required'&&$corrupt['recovery_code']==='ACS_LOOT_ISSUE_RECEIPT_INVALID','Forged receipt accepted '.json_encode($corrupt));
    $stmt->bind_param('sss',$original['result_json'],$original['result_hash'],$key);$stmt->execute();$ownerRecovered=(new AF_ACS_RecoveryService($db,$access))->recoverInventory($sid,$key,'2');snapshot_assert($ownerRecovered['status']==='committed'&&$repo->allocationEntry($sid,'1',$lostEntry)['status']==='pending_issue','Generic Inventory acknowledgement falsely issued loot');$recovered=(new AF_ACS_RecoveryService($db,$access))->recoverLoot($sid,'1',$lostEntry,'3');snapshot_assert($recovered['status']==='issued'&&$total(7)===$qty+2,'Owner lookup reran grant');
    // Exact owner definition conflict after the ACS intent is durable: reject once, keep winner/key forever.
    $meta=$link->query("SELECT meta_json FROM t_af_kb_entries WHERE type='arpg_item' AND `key`='potion'")->fetch_row()[0];$qty=$total(7);$changedOnce=false;
    $rejectFactory=static function($s,$e,$id,$a)use($factory,$link,$meta,&$changedOnce){$adapter=$factory($s,$e,$id,$a);if(!$changedOnce){$changedOnce=true;$changed=$meta."\n";$stmt=$link->prepare("UPDATE t_af_kb_entries SET meta_json=? WHERE type='arpg_item' AND `key`='potion'");$stmt->bind_param('s',$changed);$stmt->execute();}return $adapter;};
    $rejected=(new AF_ACS_LootIssueService($db,$access,$rejectFactory))->issue($request('issue_owner_rejected01',$rejectEntry));$stmt=$link->prepare("UPDATE t_af_kb_entries SET meta_json=? WHERE type='arpg_item' AND `key`='potion'");$stmt->bind_param('s',$meta);$stmt->execute();
    snapshot_assert($rejected['status']==='issue_rejected'&&$rejected['owner_receipt']['code']==='DEFINITION_CONFLICT'&&$total(7)===$qty,'Owner reject consumed/reassigned loot '.json_encode($rejected));snapshot_assert($issue->issue($request('issue_rejected_repeat01',$rejectEntry))===$rejected&&$total(7)===$qty,'Rejected intent regenerated');
    // Rollback ACS owner acknowledgement after owner COMMIT, then rollback the separate loot-entry acknowledgement.
    $qty=$total(7);$ownerLink->afterCommit=static function()use($link){$link->query("CREATE TRIGGER issue_owner_ack_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW BEGIN IF JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.status'))='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ACS owner ack failed'; END IF; END");};
    $late=$issue->issue($request('issue_late_ack_rollback01',$lateEntry));$link->query('DROP TRIGGER issue_owner_ack_late');snapshot_assert($late['status']==='pending_issue'&&$late['owner_status']==='recovery_required'&&$total(7)===$qty+1,'Late ACS ack undid owner/guessed issue '.json_encode($late));
    $link->query("CREATE TRIGGER issue_entry_ack_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW BEGIN IF JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.result.issue.status'))='issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ACS entry ack failed'; END IF; END");
    $late2=$issue->recover($sid,'1',$lateEntry,'2');$link->query('DROP TRIGGER issue_entry_ack_late');snapshot_assert($late2['status']==='pending_issue'&&$late2['owner_status']==='committed'&&$total(7)===$qty+1,'Entry ack rollback lost owner receipt');
    $final=(new AF_ACS_RecoveryService($db,$access))->recoverLoot($sid,'1',$lateEntry,'3');snapshot_assert($final['status']==='issued'&&$total(7)===$qty+1,'RecoveryService repeated grant');
    // Provenance is independently unique in Inventory, even a different privileged server policy cannot issue it twice.
    $source=$repo->lootIssueIntent($sid,'1',$gmEntry)['intent'];$same=$source;$same['operation_key'].=':other';$same['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($same);
    $testPolicy=new class implements AF_ADVINV_OwnerAccess {public function uid():int{return 7;}public function authorize(string $op,int $uid,array $s,string $ref,bool $m):void {if($op!=='grant')throw new RuntimeException('INV_ACCESS_DENIED');}};
    $priorTotal=$total(8);$duplicate=(new AF_ADVINV_OwnerProtocol($ownerLink,$testPolicy))->grant($same);snapshot_assert($duplicate['status']==='rejected'&&$duplicate['code']==='PROVENANCE_CONFLICT'&&$total(8)===$priorTotal,'New grant key bypassed provenance');
    $after=$captureAll();foreach(['rolls','loot_claims','snapshots','sealed_actions','combat_results','combat_receipts','entity_states','resources','rounds','turns','entity_turns','cooldowns','custody'] as $table)snapshot_assert($before[$table]===$after[$table],'Issue rewrote '.$table);
    $oldEntries=array_column($before['loot_entries'],null,'id');foreach($after['loot_entries'] as $e){$old=$oldEntries[$e['id']];foreach(['recipient_uid','recipient_group_id','allocation_version','result_json','qty','item_snapshot_id'] as $field)snapshot_assert($old[$field]===$e[$field],'Issue changed winner/generated '.$field);}
    foreach($repo->allocationEntries($sid,'1') as $e)$repo->allocationReceipt($sid,'1',$e);
    $populated=$captureAll();$migrations->run();snapshot_assert($captureAll()===$populated,'Migration lost issue identity');$ownerLink->close();
    echo "ACS loot issue SQL: exact intent, allocated->issued, concurrent/duplicate requests, payload/recipient conflict, unavailable/closed/archive, lookup-only recovery, owner reject, provenance dedupe, ACK loss, receipt integrity, both ACS acknowledgement rollbacks and reserved winner passed\n";
};
require __DIR__.'/acs_loot_allocation_sql.php';
