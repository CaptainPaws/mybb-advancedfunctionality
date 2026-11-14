<?php
/** Start execution with real two-principal owner authorization, no native publication. */
$GLOBALS['acsPreparedOwnerMultiple']=true;
$GLOBALS['acsPreparedStartReveal']=true;
$GLOBALS['acsPreparedStartSecondNoReveal']=true;
$GLOBALS['acsPreparedStartContract']=['schema'=>'ic-acs.prepared-start.v1','event_kind'=>'encounter.start','stage'=>'ambush_resolution','priority'=>100,'on_unavailable'=>'block','emit_prepared'=>false,'emit_active_reactions'=>false,'limits'=>['max_depth'=>2,'per_cause'=>1]];
function get_thread($tid):array {global $db;return $db->write_link->query('SELECT * FROM t_threads WHERE tid='.(int)$tid)->fetch_assoc()?:[];}
$GLOBALS['acsPreparedStartFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb,$db;
    $case=$argv[2]??'normal';
    itemFail(fn()=>AF_ACS_CausalGraph::order(['root'=>['children'=>['child']],'child'=>['children'=>['root']]],['root'],2),'ACS_CAUSAL_CYCLE');
    $unknown=$GLOBALS['acsPreparedStartContract'];$unknown['emit_prepared']=true;itemFail(fn()=>AF_ACS_PreparedAction::startExecution($unknown),'ACS_PREPARED_UNSUPPORTED');$draws=0;$random=new AF_ACS_RandomSource(static function()use(&$draws){++$draws;return 0;});
    $member=array_values(array_filter($repo->participants($eid,'0',201),fn($m)=>$m['entity_id']===$actor))[0];$change('participant_visibility',['entity_id'=>$actor,'visibility_scope'=>'gm','participant_version'=>$member['participant_version']]);
    $v=$sealPlan($actor,'paid-preparation',['prepared_items'=>[['cost_index'=>2,'inventory_item_id'=>'22'],['cost_index'=>3,'inventory_item_id'=>'23'],['cost_index'=>4,'inventory_item_id'=>'24']]],$npcId);$paid=$service->publish($publishRequest($v));
    $second=null;if($case==='multiple')$second=$service->publish($publishRequest($sealPlan($petId,'pre-round-one',[],$npcId)));if($case==='npc')$second=$service->publish($publishRequest($sealPlan($npcId,'pre-round-one',[],$actor)));
    $before=$captureAll();$p=$repo->preparedRows($sid,$eid,$actor)[0];$c=json_decode($p['contract_json'],true);snapshot_assert($c['owner_obligations'][3]['schema']==='prepared_reserved_start_consume.v1'&&count($repo->preparedTriggerItems($p))===2,'No narrow owner start consent/two bindings');
    itemInsert($link,'users',['uid'=>9]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>9,'role'=>'observer','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    itemInsert($link,'users',['uid'=>8]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>8,'role'=>'gm','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $mybb->user['uid']=$case==='same' ? 7 : 8;$ambush=new AF_ACS_AmbushStartService($repo,$access,$random);$ambush->command('ready',$ambushRequest());
    snapshot_assert($case==='same'||$repo->controllerFor($sid,$actor,'8')===null,'GM received body control');
    $requestActor=$mybb->user['uid'];$mybb->user['uid']=9;$observer=new AF_ACS_AudienceService($repo,$access,$sid,$eid);snapshot_assert($observer->knowledge('prepared_action',$paid['prepared_action_id'])['level']==='unknown','Hidden start disclosed before trigger');$mybb->user['uid']=$requestActor;
    $r=$ambushRequest();$hp=(int)$repo->entity($sid,$npcId)['hp'];$readyState=$captureAll();
    if($case==='revoked'){$link->query("UPDATE t_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true,\"inventory_spending_enabled\":false}' WHERE entity_id=".$actor);}
    if($case==='resource')$link->query("UPDATE t_af_acs_resources SET current_value=0,resource_version=resource_version+1 WHERE entity_id=".$actor." AND resource_key='ep'");
    if($case==='target')$link->query("UPDATE t_af_acs_entity_states SET life_state='dead',hp=0,state_version=state_version+1 WHERE entity_id=".$npcId);
    if($case==='target')$link->query('UPDATE t_af_acs_resources SET current_value=0 WHERE resource_key=\'hp\' AND entity_id='.$npcId);
    $server=['schema_version'=>1,'actor_uid'=>$access->uid(),'session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$r['expected_version'],'encounter_version'=>$r['encounter_version'],'reason'=>$r['reason'],'start_operation_id'=>'ambush:'.$sid.':'.$r['operation_key']];
    $authorize=fn()=>$encounters->context($sid,'51');
    if($case==='interrupted'){
        $link->loseAck=true;itemFail(fn()=>$repo->armAmbushStart($server,$authorize),'ACS_TRANSACTION_ERROR');snapshot_assert($draws===0&&!$captureAll()['prepared_executions'],'Intent acknowledgement wrote mechanics/RNG');
        $link->loseAck=true;itemFail(fn()=>$repo->sealAmbushStart($server,$access,$random),'ACS_TRANSACTION_ERROR');snapshot_assert($draws===2&&count($captureAll()['prepared_executions'])===1&&!$captureAll()['rounds'],'Causal seal acknowledgement lost identity');
    }
    if($case==='orphan'){
        $repo->armAmbushStart($server,$authorize);$repo->sealAmbushStart($server,$access,$random);$root=$repo->ambushStartState($sid,$eid)['outcome']['causal_seal'];
        // Deliberate standalone owner outcome fault: valid exact intent, absent ACS mechanical terminal.
        (new AF_ACS_TransactionManager($link,'fixture:orphan-start'))->run(fn()=>(new AF_ACS_PreparedItemService($repo,$access))->consumeTrigger($p,$root['trigger_event_id']));$readyState=$captureAll();
    }
    // Fail after resolution/owner participation when the final immutable event is written.
    if(in_array($case,['normal','multiple','npc','same','interrupted','ack'],true))$link->query("CREATE TRIGGER start_execution_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW BEGIN IF JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.request.start_operation_id')) IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late start resolution rollback'; END IF; END");
    try{$ambush->command('start',$r);throw new RuntimeException('Expected blocked/faulted launch');}catch(Throwable $e){$code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();if(in_array($case,['normal','multiple','npc','same','interrupted','ack'],true))snapshot_assert($code==='ACS_DATABASE_ERROR','Wrong launch error '.$e);elseif($case==='revoked')snapshot_assert($code==='INV_PREPARED_REVOKED','Revoked owner used '.$e);elseif($case==='orphan')snapshot_assert($code==='ACS_RECOVERY_REQUIRED','Orphan owner outcome retried '.$e);elseif($case==='resource')snapshot_assert($code==='effects_cost_unaffordable','Resource contract ignored '.$e);else snapshot_assert(in_array($code,['ACS_PREPARED_TARGET_UNAVAILABLE','resolver_target_forbidden','preview_actor_unavailable'],true),'Invalid target ignored '.$e);}
    if(in_array($case,['normal','multiple','npc','same','interrupted','ack'],true))$link->query('DROP TRIGGER start_execution_late');
    $armed=$captureAll();snapshot_assert(!$armed['rounds']&&!$armed['entity_turns']&&$armed['inv_items']===$readyState['inv_items']&&$armed['inv_reservations']===$readyState['inv_reservations'],'Partial owner consume/roster escaped failure');
    snapshot_assert(count($armed['prepared_executions'])===($second?2:1)&&count($armed['rolls'])===2*($second?2:1)&&$draws===2*($second?2:1),'No fixed causal seal');
    foreach($armed['prepared_executions'] as $x)snapshot_assert($x['status']==='armed','Rollback lost armed execution');
    if($case==='orphan'){snapshot_assert($repo->ambushStartState($sid,$eid)['operation_status']==='recovery_required','No unknown-owner barrier');$barrier=$captureAll();itemFail(fn()=>$ambush->command('start',$r),'ACS_RECOVERY_REQUIRED');snapshot_assert($captureAll()===$barrier,'Blind retry after unknown owner outcome');echo "ACS start orphan: owner outcome preserved, no blind consume/release, recovery barrier and armed tape passed\n";return;}
    if(in_array($case,['revoked','resource','target'],true)){snapshot_assert((int)$repo->entity($sid,$npcId)['hp']===($case==='target'?0:$hp),'Denied launch wrote damage');echo "ACS start denied: $case, atomic costs/HP/roster and retained causal seal passed\n";return;}
    $other=$r;$other['operation_key']='start_another_identity_01';itemFail(fn()=>$ambush->command('start',$other),'ACS_FIRST_ROUND_EXISTS');
    $retry=new AF_ACS_AmbushStartService($repo,$access,new AF_ACS_RandomSource(static function(){throw new RuntimeException('Start rerolled');}));
    if($case==='ack')$link->loseAck=true;$result=$retry->command('start',$r);if($case==='ack')snapshot_assert(!$link->loseAck,'Terminal COMMIT ACK loss was not exercised');$after=$captureAll();snapshot_assert($result['mechanics_confirmed']&&count($result['prepared_executions'])===($second?2:1),'No successful prepared start');
    snapshot_assert($retry->command('start',$r)===$result&&$captureAll()===$after&&$after['rolls']===$armed['rolls'],'Replay changed RNG/mechanics');
    snapshot_assert((int)$repo->entity($sid,$npcId)['hp']===$hp-10*($case==='multiple'?2:1)&&$after['native_posts']===$before['native_posts']&&$after['sealed_actions']===$before['sealed_actions']&&count($after['rounds'])===1&&count($after['turns'])===2,'Missing attack/extra post/round');
    snapshot_assert((int)$repo->entity($sid,$actor)['hp']===90-($case==='npc'?10:0),'Item cost executed on_use or sequential NPC damage was lost');
    foreach([23,24] as $id)snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_advinv_items WHERE id='.$id)->fetch_row()[0]==='0','Trigger item not consumed');
    snapshot_assert((float)$link->query("SELECT current_value FROM t_af_acs_resources WHERE entity_id=".$actor." AND resource_key='ep'")->fetch_row()[0]===2.0,'Trigger EP timing');
    foreach($after['prepared_actions'] as $p)snapshot_assert($p['status']==='executed','Prepared still active');
    $turns=iterator_to_array($repo->allRoundTurns($result['round_id']));$player=array_values(array_filter($turns,fn($t)=>$t['control_group_id']===(string)$group))[0];$enemy=array_values(array_filter($turns,fn($t)=>$t['control_group_id']===$npc['control_group_id']))[0];
    snapshot_assert($player['pre_round_ap_commitment']===($case==='multiple'?'3':'2')&&$player['ap_budget']==='3'&&$player['ap_spent']==='0'&&($case==='multiple'?($player['status']==='passed'&&AF_ACS_Clock::availableAP((int)$player['ap_budget'],0,(int)$player['pre_round_ap_commitment'])===0):($player['status']==='pending'&&AF_ACS_Clock::availableAP((int)$player['ap_budget'],0,(int)$player['pre_round_ap_commitment'])===1))&&$enemy['status']==='pending'&&$enemy['ap_budget']==='3'&&$enemy['pre_round_ap_commitment']===($case==='npc'?'1':'0'),'Repeated AP/free surprise/independent NPC');
    $reveals=array_values(array_filter($after['awareness'],fn($a)=>$a['subject_kind']==='prepared_action'));snapshot_assert(count($reveals)===1&&$reveals[0]['level']==='detected','Explicit reveal missing');
    $mybb->user['uid']=9;$observer=new AF_ACS_AudienceService($repo,$access,$sid,$eid);snapshot_assert($observer->knowledge('prepared_action',$paid['prepared_action_id'])['level']==='detected'&&$observer->knowledge('entity',$actor)['level']==='unknown','Reveal disclosed creature identity');if($second)snapshot_assert($observer->knowledge('prepared_action',$second['prepared_action_id'])['level']==='unknown','Reveal changed a preparation with no reveal policy');
    foreach($observer->notices() as $notice)if(($notice['subject_kind']??null)==='prepared_action')snapshot_assert(!isset($notice['subject_id'],$notice['entity_id'],$notice['owner_uid'],$notice['actor_uid']),'Detected DTO disclosed identity');$mybb->user['uid']=$requestActor;
    foreach($after['entity_turns'] as $et)snapshot_assert($et['reaction_used']==='0','Prepared consumed active reaction budget');
    snapshot_assert(count(array_filter($after['events'],fn($e)=>$e['event_type']==='encounter.start_sealed'))===1&&count(array_filter($after['events'],fn($e)=>$e['event_type']==='encounter.ambush_start'))===1,'Duplicate causal or terminal start event');
    snapshot_assert($case==='same'||($mybb->user['uid']===8&&$repo->controllerFor($sid,$actor,'8')===null),'Impersonated owner/granted body control');
    if($case!=='same')itemFail(fn()=>(new AF_ACS_PreparedItemService($repo,$access))->catalog($sid,$actor,$c),'INV_SPENDING_NOT_AUTHORIZED');
    if($case==='ack'){
        $link->query("UPDATE t_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true,\"inventory_spending_enabled\":false}' WHERE entity_id=".$actor);$revoked=$captureAll();snapshot_assert($retry->command('start',$r)===$result&&$captureAll()===$revoked,'Successful consume lost after later revocation');
        $binding=$c['item_bindings'][1];$trigger=$result['causal_seal']['trigger_event_id'];$intent=AF_ACS_ItemActionService::request('consume','acs:prepared:trigger-consume:'.$paid['prepared_action_id'].':'.$trigger.':3',$sid,$actor,(int)$access->uid(),['reservation_id'=>$binding['reserve_receipt']['reservation']['id'],'qty'=>$binding['qty'],'expected_version'=>$binding['reserve_receipt']['reservation']['version']]);$intent['actor']['authorization_ref']='acs-prepared-start:'.$paid['prepared_action_id'].':'.$trigger.':3';$intent['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($intent);
        $lookup=(new AF_ACS_TransactionManager($link,'fixture:start-history'))->run(fn()=>AF_ACS_InventoryAdapter::forCombat($db,(int)$access->uid())->lookupPreparedConsume($intent));snapshot_assert($lookup['status']==='committed','Historical start receipt lost after revoke');
    }
    echo "ACS start execution: $case, fixed causal tape/replay, actual Resolver damage, owner-authorized two reservations once, source principal preserved, hidden reveal, AP/roster and late rollback passed\n";
};
require __DIR__.'/acs_pre_round_sql.php';
