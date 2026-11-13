<?php
if(($argv[2]??'')==='worker'){require __DIR__.'/fixtures/PreparedOwnerWorker.php';exit;}
$GLOBALS['acsPreparedOwnerMultiple']=($argv[2]??'')==='multi';
/** Authoritative owner/ACS writer with distinct real authenticated request contexts. */
$GLOBALS['acsPreparedPhaseDefinitions']=static function(array &$payload,array $f):void {
    if(($f['argv'][2]??'')!=='schema')return;extract($f);global $db;
    $items=$link->query('SELECT * FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);$link->query('ALTER TABLE t_af_advinv_reservations DROP COLUMN prepared_binding_hash');
    $tx=new AF_ACS_TransactionManager($link,'owner-upgrade:'.$database);$adapter=AF_ACS_InventoryAdapter::forCombat($db,7);itemFail(fn()=>$tx->run(fn()=>$adapter->bindPreparedReservation([])),'INV_PREPARED_SCHEMA_NOT_READY');
    itemFail(fn()=>$tx->run(fn()=>AF_ADVINV_OwnerSchema::upgradePrepared($link,'t_')),'INV_AMBIENT_TRANSACTION');
    AF_ADVINV_OwnerSchema::upgradePrepared($link,'t_');AF_ADVINV_OwnerSchema::upgradePrepared($link,'t_');snapshot_assert($items===$link->query('SELECT * FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Owner schema upgrade changed property');
};
$GLOBALS['acsPreparedPhaseBeforeParent']=static function(array $f):void {
    extract($f);global $mybb;
    itemInsert($link,'users',['uid'=>8]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>8,'role'=>'gm','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $mode=$argv[2]??'normal';
    if($mode==='delegate'){
        require_once AF_ADDONS.'advancedcombatsystem/modules/Application/DelegationService.php';
        (new AF_ACS_DelegationService($repo,$access))->command('grant',['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'owner_trigger_delegate01','owner_uid'=>'7','delegate_uid'=>'8','entity_ids'=>[$actor],'permitted_actions'=>['ordinary'],'duration_seconds'=>'3600','reason'=>'Combat only, no item grant']);
    }
    if($mode==='revoked'){$p=json_decode($permissions,true);$p['inventory_spending_enabled']=false;$j=json_encode($p);$st=$link->prepare('UPDATE t_af_acs_controllers SET permissions_json=?,assignment_version=assignment_version+1 WHERE entity_id=?');$st->bind_param('ss',$j,$actor);$st->execute();}
    if($mode==='generation')$link->query('UPDATE t_af_acs_controllers SET assignment_version=assignment_version+1 WHERE entity_id='.$actor);
    if($mode==='expired')$link->query("UPDATE t_af_acs_prepared_actions SET status='expired',prepared_version=prepared_version+1 WHERE id=".$receipt['prepared_action_id']);
    if($mode==='ownership')$link->query('UPDATE t_af_advinv_items SET uid=8 WHERE id=23');
    $link->query('UPDATE t_af_acs_controllers SET controller_uid=8,assignment_version=assignment_version+1 WHERE entity_id='.$npc);$link->query('UPDATE t_posts SET uid=8 WHERE pid=501');
    // This is a new authenticated request in the fixture, never an owner impersonation in production.
    $mybb->user['uid']=8;
};
$GLOBALS['acsPreparedPhaseAssert']=static function(array $f):void {
    extract($f);global $mybb,$db;$mode=$argv[2]??'normal';$before=$captureAll();
    if($mode==='expired'){$r=$writer->commit($parent['submission_id'],$parent['state_version']);snapshot_assert(!$captureAll()['prepared_executions']&&$captureAll()['inv_items']===$before['inv_items'],'Expired obligation consumed');echo "Prepared owner: expired obligation never dispatched or consumed passed\n";return;}
    (new ReflectionMethod($writer,'armPreparedCausal'))->invoke($writer,$parent,(new ReflectionMethod($writer,'frozen'))->invoke($writer,$parent));$armed=$captureAll();$cause=json_decode($armed['prepared_executions'][0]['result_json'],true);
    $binding=$repo->preparedTriggerItems($repo->preparedRows($sid,'1',$actor)[0])[0];$key='acs:prepared:trigger-consume:'.$receipt['prepared_action_id'].':'.$cause['trigger_event_id'].':'.$binding['cost_index'];
    $intent=AF_ACS_ItemActionService::request('consume',$key,$sid,$actor,8,['reservation_id'=>$binding['reserve_receipt']['reservation']['id'],'qty'=>$binding['qty'],'expected_version'=>$binding['reserve_receipt']['reservation']['version']]);$intent['actor']['authorization_ref']='acs-prepared:'.$receipt['prepared_action_id'].':'.$cause['trigger_event_id'].':'.$binding['cost_index'];$intent['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($intent);
    $adapter=AF_ACS_InventoryAdapter::forCombat($db,8);$tx=new AF_ACS_TransactionManager($link,'owner-fixture:'.$database);
    if(in_array($mode,['revoked','generation'],true)){
        $r=$writer->commit($parent['submission_id'],$parent['state_version']);$after=$captureAll();$e=json_decode($after['prepared_executions'][0]['result_json'],true);snapshot_assert($e['outcome']==='skipped'&&$e['reason']==='INV_PREPARED_REVOKED'&&$e['terminal_state']==='active'&&$after['inv_items']===$armed['inv_items'],'Revoked generation consumed or cancellation released');echo "Prepared owner: $mode owner-side decision denies future consume, retains reservation passed\n";return;
    }
    if($mode==='ownership'){itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'INV_PREPARED_BINDING_INTEGRITY');snapshot_assert($captureAll()===$armed,'Ownership integrity failure partially committed');echo "Prepared owner: changed ownership fails closed with parent rollback passed\n";return;}
    if($mode==='unavailable'){$GLOBALS['disabled']=['advancedinventory'];itemFail(fn()=>AF_ACS_InventoryAdapter::forCombat($db,8),'ACS_INVENTORY_UNAVAILABLE');try{$writer->commit($parent['submission_id'],$parent['state_version']);}catch(Throwable $e){}$GLOBALS['disabled']=[];$after=$captureAll();snapshot_assert($after['inv_items']===$armed['inv_items']&&!array_filter($after['inv_external_operations'],fn($x)=>$x['operation_key']===$key),'Unavailable Inventory consumed');echo "Prepared owner: unavailable Inventory never confirms consume passed\n";return;}
    if($mode==='orphan'){
        $tx->run(fn()=>$adapter->participatePreparedConsume($intent)->serverReceipt());require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';$r=(new AF_ACS_RecoveryService($db,$access))->recover($parent['submission_id'],$parent['state_version']);snapshot_assert($r['status']==='RECOVERY_REQUIRED'&&!$r['mechanics_confirmed'],'Orphan owner result did not quarantine');$orphan=$captureAll();snapshot_assert($orphan['prepared_executions'][0]['status']==='armed'&&$orphan['rolls']===$armed['rolls'],'Orphan rerolled/executed');snapshot_assert($tx->run(fn()=>$adapter->lookupPreparedConsume($intent))['status']==='committed','Quarantined historical lookup lost owner receipt');echo "Prepared owner: orphan receipt quarantines without blind consume/release passed\n";return;
    }
    // B has no ordinary Inventory permission, even when B has a combat delegation or GM role.
    $lookup=AF_ACS_ItemActionService::request('lookup',$key.':ordinary-lookup',$sid,$actor,8,['target_operation_key'=>$binding['reserve_receipt']['operation_key']]);itemFail(fn()=>$tx->run(fn()=>$adapter->lookupParticipant($lookup)),'INV_SPENDING_NOT_AUTHORIZED');
    itemFail(fn()=>$tx->run(fn()=>$adapter->participate('consume',$intent)),'INV_SPENDING_NOT_AUTHORIZED');
    $delegated=AF_ACS_ItemActionService::request('consume',$key,$sid,$actor,8,$intent['payload'],'item_consume');itemFail(fn()=>$tx->run(fn()=>$adapter->participate('consume',$delegated)),'INV_SPENDING_NOT_AUTHORIZED');
    $ordinary=AF_ACS_ItemActionService::request('consume',$key,$sid,$actor,8,$intent['payload']);itemFail(fn()=>$tx->run(fn()=>$adapter->participate('consume',$ordinary)),'INV_SPENDING_NOT_AUTHORIZED');
    $bindingRow=json_decode($armed['inv_reservations'][1]['prepared_binding_json'],true);unset($bindingRow['grant_generation'],$bindingRow['owner_item_digest']);itemFail(fn()=>$tx->run(fn()=>$adapter->bindPreparedReservation($bindingRow)),'INV_SPENDING_NOT_AUTHORIZED');
    $bad=$intent;$bad['payload']['qty']++;$bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);itemFail(fn()=>$tx->run(fn()=>$adapter->participatePreparedConsume($bad)),'INV_PREPARED_BINDING_INTEGRITY');
    $bad=$intent;$bad['payload']['reservation_id']=$armed['inv_reservations'][0]['id'];$bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);itemFail(fn()=>$tx->run(fn()=>$adapter->participatePreparedConsume($bad)),'INV_PREPARED_BINDING_INTEGRITY');
    $bad=$intent;$bad['payload']['item_id']=22;$bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);itemFail(fn()=>$tx->run(fn()=>$adapter->participatePreparedConsume($bad)),'INV_INVALID_FIELDS');
    itemInsert($link,'users',['uid'=>9]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>9,'role'=>'gm','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);$mybb->user['uid']=9;$gm=$intent;$gm['actor']['uid']=9;$gm['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($gm);$gmAdapter=AF_ACS_InventoryAdapter::forCombat($db,9);itemFail(fn()=>$tx->run(fn()=>$gmAdapter->participatePreparedConsume($gm)),'INV_SPENDING_NOT_AUTHORIZED');$mybb->user['uid']=8;
    itemFail(fn()=>$tx->run(fn()=>(new AF_ADVINV_OwnerProtocol($db))->participatePreparedConsume($intent)),'INV_PREPARED_POLICY_REQUIRED');
    itemFail(fn()=>$adapter->participatePreparedConsume($intent),'INV_OWNER_TRANSACTION_REQUIRED');$bad=$intent;$bad['operation_key'].=':other';$bad['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($bad);itemFail(fn()=>$tx->run(fn()=>$adapter->participatePreparedConsume($bad)),'INV_PREPARED_BINDING_INTEGRITY');
    itemFail(fn()=>$tx->run(fn()=>$adapter->participate('consume',$ordinary)),'INV_SPENDING_NOT_AUTHORIZED');
    if($mode==='race'){
        $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->begin_transaction();$rival->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7 FOR UPDATE');$workers=[];
        for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$socket,'worker',$database,$parent['submission_id'],$parent['state_version']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
        foreach($workers as [$process,$pipes]){$boot='';while(($line=fgets($pipes[1]))!==false){$boot.=$line;if(trim($line)==='READY')break;}if($line===false)throw new RuntimeException('Worker bootstrap failed '.$boot.stream_get_contents($pipes[2]));}
        // Both independent authenticated requests are live while the owner writer is fenced.
        usleep(2000000);$rival->commit();$rival->close();$results=[];
        foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);snapshot_assert($code===0,'Concurrent writer failed '.$output.$error);$results[]=json_decode(trim($output),true,64,JSON_THROW_ON_ERROR);}
        $after=$captureAll();$terminal=$writer->receipt($parent['submission_id']);foreach($results as $result)if(!isset($result['conflict']))snapshot_assert($result===$terminal,'Racing receipts differ');snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$terminal&&$captureAll()===$after,'Racing conflict did not reconcile via durable receipt');snapshot_assert(count($after['prepared_executions'])===1&&$after['prepared_executions'][0]['status']==='executed'&&count(array_filter($after['inv_external_operations'],fn($x)=>$x['operation_key']===$key))===1&&$after['rolls']===$armed['rolls'],'Concurrent triggers duplicated execution/consume/RNG');echo "Prepared owner: two real concurrent authenticated requests, one terminal receipt/execution/consume and fixed rolls passed\n";return;
    }
    $beforeFault=$captureAll();$link->query("CREATE TRIGGER owner_trigger_late BEFORE INSERT ON t_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owner trigger late rollback'");itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER owner_trigger_late');snapshot_assert($captureAll()===$beforeFault,'Owner/ACS late rollback leaked');
    $link->loseAck=true;require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';$r=(new AF_ACS_RecoveryService($db,$access))->recover($parent['submission_id'],$parent['state_version']);snapshot_assert($r['mechanics_confirmed'],'Foreign owner lost ACK');
    $after=$captureAll();$e=json_decode($after['prepared_executions'][0]['result_json'],true);snapshot_assert($e['outcome']==='executed'&&count($e['owner_receipts'])===($mode==='multi'?2:1)&&$mybb->user['uid']===8&&$e['owner_receipts'][0]['actor_uid']===8,'Runtime identity changed or item not consumed');
    snapshot_assert(!$link->query('SELECT id FROM t_af_advinv_items WHERE id=23')->fetch_row()&&$after['rolls']===$armed['rolls']&&count($after['inv_reservations'])===count($armed['inv_reservations']),'Second reserve/RNG or missing consume');
    snapshot_assert($after['native_posts']===$before['native_posts']&&count($after['turns'])===count($before['turns'])&&$repo->individualTurns(['1'])[0]['reaction_used']==='0'&&(string)$repo->clockTurn($sid,'1')['ap_spent']==='3','Extra native post/turn/prepared AP/reaction');
    $terminal=$writer->receipt($parent['submission_id']);snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$terminal&&$captureAll()===$after,'Foreign duplicate');
    if($mode==='multiple'){snapshot_assert(count($after['prepared_executions'])===2,'Distinct trigger events lost');$second=json_decode($after['prepared_executions'][1]['result_json'],true);snapshot_assert($second['outcome']==='skipped'&&$second['reason']==='preparation_unavailable','Second distinct trigger consumed');}
    // A later revocation cannot undo the successful receipt, and does not require another consume.
    $link->query('UPDATE t_af_acs_controllers SET assignment_version=assignment_version+1 WHERE entity_id='.$actor);snapshot_assert(AF_ADVINV_OwnerProtocol::canonical($tx->run(fn()=>$adapter->lookupPreparedConsume($intent)))===AF_ADVINV_OwnerProtocol::canonical($e['owner_receipts'][0]),'Historical receipt changed after revocation');snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$terminal,'Committed parent lost receipt after revoke');
    echo "Prepared owner: A7/B8 $mode, exact reservation/qty/pin, no GM/delegate authority, rollback, lost ACK, once/replay and history after revoke passed\n";
};
require __DIR__.'/acs_prepared_execution_sql.php';
