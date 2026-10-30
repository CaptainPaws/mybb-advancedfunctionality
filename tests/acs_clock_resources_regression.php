<?php
declare(strict_types=1);
$GLOBALS['acsResourcesFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    $clock=$service->clock();$mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    $link->query('UPDATE fixture_af_acs_operations SET updated_at=0');
    // Real schema runner resume/repeat; disposable fixture only.
    $link->query('DROP TABLE fixture_af_acs_cooldowns');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=0 WHERE version='005_clock_resources'");$migrations->run();$migrations->run();
    $commandClock=static function(string $op,array $extra=[])use($clock,$service,$sid,$eid):array {
        $v=$service->view($sid,$eid);return $clock->command($op,$extra+['session_id'=>$sid,'encounter_id'=>$eid,'encounter_version'=>$v['encounter']['encounter_version'],'expected_version'=>$v['session']['session_version'],'operation_key'=>bin2hex(random_bytes(16)),'reason'=>'Clock fixture']);
    };
    $heroTurn=$groups[$result['entity_id']];$link->query('UPDATE fixture_af_acs_turns SET ap_spent=1 WHERE id='.$heroTurn);
    snapshot_assert($clock->available($sid,$heroTurn,$result['entity_id'])['available']===2 && $clock->available($sid,$heroTurn,$registeredPet['entity_id'])['available']===2,'Owner/pet AP not shared');
    $refresh=$commandClock('ap_refresh',['round_id'=>$round['round']['id']]);$again=$commandClock('ap_refresh',['round_id'=>$round['round']['id']]);snapshot_assert($refresh===$again && $clock->available($sid,$heroTurn,$result['entity_id'])['available']===2,'Duplicate refresh granted AP');
    $mybb->user['uid']=8;blocked(fn()=>$clock->available($sid,$heroTurn,$result['entity_id']),'ACS_AP_ACCESS_DENIED');$mybb->user['uid']=7;
    snapshot_assert($clock->available($sid,$groups[$second['entity_id']],$second['entity_id'])['available']===3,'Independent monster AP changed');
    $entityId=$second['entity_id'];$individual=$entityTurns[$entityId];
    $start=$commandClock('entity_start',['entity_turn_id'=>$individual['id']]);snapshot_assert($commandClock('entity_start',['entity_turn_id'=>$individual['id']])['boundary_key']===$start['boundary_key'],'Duplicate start identity changed');
    // Explicit future-producer fixture: a committed action event + immutable pinned source.
    // There is deliberately no production endpoint to arm a cooldown without publication.
    $prepared=static function(string $sql,array $params=[])use($link):string {
        $stmt=$link->prepare($sql);if($params){$params=array_map(static fn($v)=>$v===null?null:(string)$v,$params);$stmt->bind_param(str_repeat('s',count($params)),...$params);}$stmt->execute();$id=(string)$stmt->insert_id;$stmt->close();return $id;
    };
    $snapshot=$entities->snapshot($sid,$entityId);$pin=$snapshot['pinned_abilities']['claw']['definition_version'];
    $ref=['key'=>'claw','definition_version'=>$pin,'fingerprint'=>$pin['fingerprint']];
    $scope='session';$initial=2;$op='fixture:confirmed-cooldown';$request=AF_ACS_Repository::json(['schema_version'=>1,'fixture'=>true]);
    $prepared('INSERT INTO fixture_af_acs_operations (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$op,$sid,$eid,'fixture.confirmed_action',hash('sha256',$request),'committed',$request,AF_ACS_Repository::json(['schema_version'=>1]),time()]);
    $state=$link->query('SELECT session_version,next_sequence FROM fixture_af_acs_sessions WHERE id='.$sid)->fetch_assoc();$ev=$service->view($sid,$eid)['encounter'];
    $event=$prepared('INSERT INTO fixture_af_acs_events (session_id,encounter_id,session_sequence,event_sequence,operation_key,event_type,actor_uid,source_entity_id,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$sid,$eid,$state['next_sequence'],$ev['next_event_sequence'],$op,'fixture.confirmed_action',7,$entityId,'fixture-publisher',time()]);
    $payload=AF_ACS_Repository::json(['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','confirmed_action'=>['schema_version'=>1,'entity_turn_id'=>$individual['id'],'ability_ref'=>$ref,'cooldown'=>['ability_key'=>'claw','group_key'=>'own','scope'=>$scope,'turns'=>$initial]]]);$visibility=AF_ACS_Repository::json(['schema_version'=>1,'scope'=>'gm']);
    $prepared('INSERT INTO fixture_af_acs_event_details (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$event,'binary64-half_up-v1',$payload,$visibility,hash('sha256',$payload."\n".$visibility)]);
    $link->query('UPDATE fixture_af_acs_sessions SET next_sequence=next_sequence+1,session_version=session_version+1 WHERE id='.$sid);$link->query('UPDATE fixture_af_acs_encounters SET next_event_sequence=next_event_sequence+1,encounter_version=encounter_version+1 WHERE id='.$eid);
    $cooldown=$prepared('INSERT INTO fixture_af_acs_cooldowns (entity_id,ability_key,group_key,scope,remaining_turns,last_boundary_key,cooldown_version) VALUES (?,?,?,?,?,?,1)',[$entityId,'claw','own',$scope,2,AF_ACS_Clock::entityStart($individual['id'])]);
    $source=['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-clock-resources-1','cooldown_id'=>$cooldown,'entity_id'=>$entityId,'ability_key'=>'claw','group_key'=>'own','scope'=>$scope,'initial_turns'=>$initial,'ability_ref'=>$ref,'applied_entity_turn_id'=>$individual['id'],'origin_encounter_id'=>$eid,'confirmation_event_id'=>$event];
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/SnapshotStore.php';(new AF_ACS_SnapshotStore($link))->append($sid,['source_system'=>'acs','source_key'=>'cooldown:'.$cooldown,'contract_id'=>'ic-acs.cooldown-source.v1','contract_version'=>'1'],$source);
    $commandClock('entity_start',['entity_turn_id'=>$individual['id']]);snapshot_assert($repo->cooldowns($sid,$entityId)[0]['remaining_turns']==='2','Cooldown decremented on its application boundary');
    // Two already-opened future round fixtures exercise boundaries; no production next-round API is added.
    $newBoundary=static function(int $n)use($prepared,$link,$eid,$individual,$entityId,$repo,$sid):string {
        $r=$prepared('INSERT INTO fixture_af_acs_rounds (encounter_id,round_number,status,round_version,opened_at) VALUES (?,?,?,1,?)',[$eid,$n,'open',time()]);
        $original=$repo->clockEntityTurn($sid,$individual['id']);$roster=json_decode($original['roster_json'],true);unset($roster['fingerprint']);$roster['round_id']=$r;$roster['round_number']=$n;$roster['fingerprint']=hash('sha256',AF_ACS_Repository::json($roster));
        $t=$prepared('INSERT INTO fixture_af_acs_turns (round_id,control_group_id,status,ap_budget,ap_spent,seal_quota_spent,pre_round_ap_commitment,turn_version,roster_json) VALUES (?,?,?,3,0,0,0,1,?)',[$r,$original['control_group_id'],'pending',AF_ACS_Repository::json($roster)]);
        $e=$prepared('INSERT INTO fixture_af_acs_entity_turns (turn_id,entity_id,start_done,end_done,reaction_used,entity_turn_version) VALUES (?,?,0,0,0,1)',[$t,$entityId]);$link->query('UPDATE fixture_af_acs_encounters SET round_number='.$n.' WHERE id='.$eid);return $e;
    };
    $next=$newBoundary(2);$link->query("UPDATE fixture_af_acs_operations SET status='prepared' WHERE operation_key='fixture:confirmed-cooldown'");blocked(fn()=>$commandClock('entity_start',['entity_turn_id'=>$next]),'ACS_COOLDOWN_UNCONFIRMED');snapshot_assert($repo->cooldowns($sid,$entityId)[0]['remaining_turns']==='2','Unconfirmed cooldown decremented');$link->query("UPDATE fixture_af_acs_operations SET status='committed' WHERE operation_key='fixture:confirmed-cooldown'");$commandClock('entity_start',['entity_turn_id'=>$next]);snapshot_assert($repo->cooldowns($sid,$entityId)[0]['remaining_turns']==='1','Cooldown=2 first next start wrong');
    $commandClock('entity_start',['entity_turn_id'=>$next]);snapshot_assert($repo->cooldowns($sid,$entityId)[0]['remaining_turns']==='1','Duplicate boundary decremented twice');
    $last=$newBoundary(3);$commandClock('entity_start',['entity_turn_id'=>$last]);snapshot_assert($repo->cooldowns($sid,$entityId)[0]['remaining_turns']==='0','Cooldown=2 not ready on second next start');
    // Active combat blocks all rest, including a full rest granted by the GM.
    blocked(fn()=>$commandClock('rest_full',['control_group_id'=>$result['control_group_id'],'confirm_full'=>'1']),'ACS_REST_COMBAT_ACTIVE');
    // A separately confirmed combat-scope slot must reset, without touching the session slot.
    $combatId=$prepared('INSERT INTO fixture_af_acs_cooldowns (entity_id,ability_key,group_key,scope,remaining_turns,last_boundary_key,cooldown_version) VALUES (?,?,?,?,2,?,1)',[$entityId,'claw','combat-slot','combat',AF_ACS_Clock::entityStart($last)]);
    $combatOp='fixture:combat-cooldown';
    $prepared('INSERT INTO fixture_af_acs_operations (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$combatOp,$sid,$eid,'fixture.confirmed_action',hash('sha256',$request),'committed',$request,AF_ACS_Repository::json(['schema_version'=>1]),time()]);
    $v=$service->view($sid,$eid);$ss=$link->query('SELECT next_sequence FROM fixture_af_acs_sessions WHERE id='.$sid)->fetch_row()[0];
    $combatEvent=$prepared('INSERT INTO fixture_af_acs_events (session_id,encounter_id,session_sequence,event_sequence,operation_key,event_type,actor_uid,source_entity_id,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$sid,$eid,$ss,$v['encounter']['next_event_sequence'],$combatOp,'fixture.confirmed_action',7,$entityId,'fixture-publisher',time()]);
    $cp=AF_ACS_Repository::json(['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','confirmed_action'=>['schema_version'=>1,'entity_turn_id'=>$last,'ability_ref'=>$ref,'cooldown'=>['ability_key'=>'claw','group_key'=>'combat-slot','scope'=>'combat','turns'=>2]]]);
    $prepared('INSERT INTO fixture_af_acs_event_details (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$combatEvent,'binary64-half_up-v1',$cp,$visibility,hash('sha256',$cp."\n".$visibility)]);
    $link->query('UPDATE fixture_af_acs_sessions SET next_sequence=next_sequence+1,session_version=session_version+1 WHERE id='.$sid);$link->query('UPDATE fixture_af_acs_encounters SET next_event_sequence=next_event_sequence+1,encounter_version=encounter_version+1 WHERE id='.$eid);
    $combatSource=array_replace($source,['cooldown_id'=>$combatId,'group_key'=>'combat-slot','scope'=>'combat','applied_entity_turn_id'=>$last,'confirmation_event_id'=>$combatEvent]);
    (new AF_ACS_SnapshotStore($link))->append($sid,['source_system'=>'acs','source_key'=>'cooldown:'.$combatId,'contract_id'=>'ic-acs.cooldown-source.v1','contract_version'=>'1'],$combatSource);
    $sessionCooldownBefore=$repo->cooldowns($sid,$entityId)[0];
    $link->query("UPDATE fixture_af_acs_encounters SET status='completed',completed_at=".time().' WHERE id='.$eid);
    $hero=$result['entity_id'];$pet=$registeredPet['entity_id'];
    foreach([[$hero,20],[$pet,10]] as [$id,$hp]){$link->query('UPDATE fixture_af_acs_entity_states SET hp='.$hp.' WHERE entity_id='.$id);$link->query("UPDATE fixture_af_acs_resources SET current_value=".$hp." WHERE entity_id=".$id." AND resource_key='hp'");}
    $view=$service->view($sid,$eid);$restInput=['session_id'=>$sid,'expected_version'=>$view['session']['session_version'],'control_group_id'=>$result['control_group_id'],'reason'=>'Approved short rest','operation_key'=>'short_rest_fixture1'];
    $external=$db->tables;$rest=$clock->command('rest_short',$restInput);snapshot_assert($clock->command('rest_short',$restInput)===$rest,'Rest retry applied twice');
    snapshot_assert($link->query('SELECT hp FROM fixture_af_acs_entity_states WHERE entity_id='.$hero)->fetch_row()[0]==='45' && $link->query('SELECT hp FROM fixture_af_acs_entity_states WHERE entity_id='.$pet)->fetch_row()[0]==='35','25% owner/pet recovery wrong');
    blocked(fn()=>$commandClock('rest_short',['control_group_id'=>$result['control_group_id']]),'ACS_REST_LIMIT');
    snapshot_assert($db->tables===$external,'Rest changed CharacterSheets/KB/Inventory');
    $link->query("UPDATE fixture_af_acs_entity_states SET life_state='dead',hp=0 WHERE entity_id=".$pet);$link->query('UPDATE fixture_af_acs_resources SET current_value=0 WHERE entity_id='.$pet);
    $commandClock('rest_full',['control_group_id'=>$result['control_group_id'],'confirm_full'=>'1']);snapshot_assert($link->query('SELECT hp FROM fixture_af_acs_entity_states WHERE entity_id='.$hero)->fetch_row()[0]==='100' && $link->query('SELECT hp FROM fixture_af_acs_entity_states WHERE entity_id='.$pet)->fetch_row()[0]==='0','Full rest failed or resurrected dead');
    snapshot_assert(AF_ACS_Clock::restHP(0,102,false)===26,'Rest half_up wrong');
    $reset=$commandClock('combat_reset');snapshot_assert($commandClock('combat_reset')===$reset,'Duplicate scope reset changed result');
    snapshot_assert($repo->cooldowns($sid,$entityId)[0]===$sessionCooldownBefore && $repo->cooldowns($sid,$entityId)[1]['remaining_turns']==='0','Combat/session cooldown scopes mixed');
    $before=$link->query('SELECT * FROM fixture_af_acs_cooldowns')->fetch_all(MYSQLI_ASSOC);$mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>'management'];
    $html=AF_ACS_EncountersPage::render($sid,'51',$service);snapshot_assert(str_contains($html,'Подтверждённые восстановления') && str_contains($html,'Полноценный отдых'),'Rest UI/history missing');
    snapshot_assert($link->query('SELECT * FROM fixture_af_acs_cooldowns')->fetch_all(MYSQLI_ASSOC)===$before,'GET changed cooldowns');
    $postView=$service->view($sid,$eid);$mybb->request_method='post';
    $mybb->input=['my_post_key'=>'token','acs_encounter'=>$eid,'acs_tab'=>'management','acs_encounter_op'=>'clock_rest_full','session_id'=>$sid,'tid'=>'51','expected_version'=>$postView['session']['session_version'],'control_group_id'=>$result['control_group_id'],'reason'=>'GM form approval','operation_key'=>'full_rest_form_0001','confirm_full'=>'1'];
    $postHtml=AF_ACS_EncountersPage::render($sid,'51',$service);snapshot_assert(str_contains($postHtml,'Изменение сохранено') && str_contains($postHtml,'Отдых группы'),'Native GM POST dispatch failed');
    $mybb->request_method='post';$mybb->input=['my_post_key'=>'bad'];blocked(fn()=>$commandClock('rest_full',['control_group_id'=>$result['control_group_id'],'confirm_full'=>'1']),'ACS_CSRF_REQUIRED');
    echo "ACS resources: AP shared/independent and duplicate refresh, pinned confirmed cooldown=2, duplicate starts, rest 25%/full/limit/retry/dead, no external writes and read-only UI passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
