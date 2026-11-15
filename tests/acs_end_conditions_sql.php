<?php
/** A1.27 on the existing authoritative response/Inventory harness; disposable DB only. */
$GLOBALS['acsEndFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    $service=new AF_ACS_EncounterService($repo,$access);
    itemInsert($link,'af_acs_sides',['id'=>2,'encounter_id'=>1,'name'=>'Opposition','side_version'=>1,'policy_json'=>'{"schema_version":1}']);
    itemInsert($link,'af_acs_side_relationships',['encounter_id'=>1,'side_a'=>1,'side_b'=>2,'relationship'=>'hostile','relation_version'=>1]);
    $link->query('UPDATE t_af_acs_participants SET side_id=2 WHERE entity_id='.$victim);
    $policy=['schema'=>AF_ACS_EndCondition::SCHEMA,'conditions'=>['last_active_side','no_active_sides'],'allow_continuation'=>true,'mutual_outcomes'=>['draw','inconclusive']];
    // Preparation-only configuration through the actual audited service. Existing fixture roster retained.
    $link->query("UPDATE t_af_acs_encounters SET status='preparation',round_number=0 WHERE id=1");
    $request=static function(string $key)use($repo,$sid):array{return ['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'operation_key'=>$key,'reason'=>'Disposable A1.27 evidence'];};
    foreach([['conditions'=>['unknown']],['allow_continuation'=>1],['mutual_outcomes'=>['victory']],['extra'=>true],['conditions'=>[['kind'=>'last_active_side']]]] as $change)itemFail(fn()=>AF_ACS_EndCondition::policy(array_replace($policy,$change)),'ACS_END_CONDITION_UNSUPPORTED');
    $mixed=[['side_id'=>'1','participation'=>'engaged','life_state'=>'active'],['side_id'=>'2','participation'=>'engaged','life_state'=>'incapacitated']];
    snapshot_assert(AF_ACS_EndCondition::evaluate($policy,$mixed,[['side_a'=>'1','side_b'=>'2','relationship'=>'allied']])===null,'Allied side treated as defeated enemy');
    $r=$request('end_policy_fixture01')+['end_conditions'=>$policy];$configured=$service->command('end_conditions_set',$r);$c=$captureAll();snapshot_assert($service->command('end_conditions_set',$r)===$configured&&$c===$captureAll(),'Configure replay');
    $link->query("UPDATE t_af_acs_encounters SET status='active',round_number=2 WHERE id=1");
    $ownerVersion=(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0];
    $reserve=AF_ACS_InventoryAdapter::forCombat($db,7)->reserve(AF_ACS_ItemActionService::request('reserve','acs:end:retained:reservation',$sid,$actor,7,['owner_uid'=>7,'item_id'=>23,'qty'=>1,'session_id'=>$sid,'expected_inventory_version'=>$ownerVersion]));
    snapshot_assert($reserve['status']==='prepared','Retained owner reservation failed');
    $base=$captureAll();
    $pending=static function()use($link):array{return $link->query("SELECT * FROM t_af_acs_encounter_resolutions WHERE status='pending'")->fetch_assoc()??throw new RuntimeException('Expected pending');};
    $decide=static function(string $key)use($request,$pending):array{return $request($key)+['resolution_id'=>(string)$pending()['id'],'outcome'=>'victory'];};
    $link->query('UPDATE t_af_acs_entity_states SET hp=1 WHERE entity_id='.$victim);$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp' AND entity_id=".$victim);
    // Fail after the pending CAS, proving that damage, receipts AND pending are one transaction.
    $before=$captureAll();$link->query("CREATE TRIGGER end_pending_fail BEFORE INSERT ON t_af_acs_operations FOR EACH ROW BEGIN IF NEW.kind='encounter.pending_resolution' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='end rollback'; END IF; END");
    itemFail(fn()=>$publish(),'ACS_DATABASE_ERROR');$link->loseAck=false;$link->query('DROP TRIGGER end_pending_fail');
    $after=$captureAll();foreach(['entity_states','encounter_resolutions','events','combat_receipts','inv_items','inv_reservations'] as $t)snapshot_assert($after[$t]===$before[$t],'End rollback '.$t);
    // Restore the fixture, including publication/seal rows created before the failed mechanical tx.
    $reset($base);$link->query('UPDATE t_af_acs_entity_states SET hp=1 WHERE entity_id='.$victim);$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp' AND entity_id=".$victim);
    [$result,$after,$draws,$receipt]=$publish(true);$p=$pending();$candidate=json_decode($p['candidate_json'],true);
    snapshot_assert($repo->encounter($sid,'1')['status']==='pending_resolution'&&$candidate['winner_side_id']==='1'&&!$repo->confirmedOutcome($sid,'1'),'Normal victory not pending');
    snapshot_assert($repo->entity($sid,$victim)['life_state']==='incapacitated','Incapacitated became dead');
    snapshot_assert($after['inv_reservations']===$base['inv_reservations'],'End cleared reservations');
    $combat=$captureAll();$turn=$repo->currentRound($repo->encounter($sid,'1'));$other=array_values(array_filter(iterator_to_array($repo->allRoundTurns($turn['id'])),fn($t)=>$t['control_group_id']===$repo->entity($sid,$victim)['control_group_id']))[0];
    itemFail(fn()=>(new AF_ACS_DraftService($repo,$access))->save(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>$other['id'],'expected_draft_version'=>'0','operation_key'=>'end_pending_draft01','actions'=>[['actor_id'=>$victim,'ability_key'=>'response-shield','targets'=>[$victim],'parameters'=>[]]],'prose'=>'Must be denied']),'ACS_');
    itemFail(fn()=>(new AF_ACS_QueryService($repo,$access))->inspect($sid,'1',$actor,$actor),'preview_encounter_not_active');
    snapshot_assert($captureAll()===$combat,'Pending action mutated state');
    // An established noncombat Inventory recovery obligation remains available after completion.
    itemInsert($link,'af_acs_operations',['operation_key'=>'acs:end:recovery:obligation','session_id'=>$sid,'encounter_id'=>1,'kind'=>'inventory.release','payload_hash'=>hash('sha256','fixture'),'status'=>'recovery_required','operation_version'=>1,'external_system'=>'advancedinventory','request_json'=>'{"schema_version":1}','result_json'=>'{"schema_version":1}','updated_at'=>time()]);
    $combat=$captureAll();
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/EndConditionController.php';
    $dto=AF_ACS_EndConditionController::handle($service,'outcome_status',['session_id'=>$sid,'encounter_id'=>'1'],'get');snapshot_assert($dto['pending']['resolution_id']===(string)$p['id']&&$captureAll()===$combat,'GM read is not read-only');
    itemFail(fn()=>AF_ACS_EndConditionController::handle($service,'outcome_confirm',['session_id'=>$sid,'encounter_id'=>'1','winner'=>'2'],'post'),'ACS_END_INPUT_INVALID');
    $confirm=$decide('end_confirm_once01');
    $bad=$confirm;$bad['winner_side_id']='2';itemFail(fn()=>$service->command('outcome_confirm',$bad),'ACS_END_OUTCOME_DENIED');
    $bad=$confirm;$bad['expected_version']='1';itemFail(fn()=>$service->command('outcome_confirm',$bad),'ACS_VERSION_CONFLICT');
    $bad=$confirm;$bad['encounter_version']='1';itemFail(fn()=>$service->command('outcome_confirm',$bad),'ACS_VERSION_CONFLICT');
    $bad=$confirm;$bad['outcome']='draw';itemFail(fn()=>$service->command('outcome_confirm',$bad),'ACS_END_OUTCOME_DENIED');
    $mybb->request_method='get';itemFail(fn()=>$service->command('outcome_confirm',$confirm),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $mybb->input['my_post_key']='bad';itemFail(fn()=>$service->command('outcome_confirm',$confirm),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $mybb->user['uid']=9;itemFail(fn()=>$service->command('outcome_confirm',$confirm),'ACS_');$mybb->user['uid']=7;
    itemInsert($link,'users',['uid'=>8]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>8,'role'=>'player','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $mybb->user['uid']=8;itemFail(fn()=>$service->command('outcome_confirm',$confirm),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $nativeDenied=new class extends AF_ACS_SessionAccess {public function thread(string $tid,?string $uid=null):array{throw new RuntimeException('ACS_NATIVE_DENIED');}};
    itemFail(fn()=>(new AF_ACS_EncounterService($repo,$nativeDenied))->command('outcome_confirm',$confirm),'ACS_NATIVE_DENIED');

    $combat=$captureAll();
    $link->query("CREATE TRIGGER end_confirm_fail BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='confirmation rollback'");$before=$captureAll();itemFail(fn()=>$service->command('outcome_confirm',$confirm),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER end_confirm_fail');snapshot_assert($captureAll()===$before&&!$repo->confirmedOutcome($sid,'1'),'Confirmation rollback');
    // Real second writer contends while the first command holds the session lock.
    $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');$rival->query('SET SESSION innodb_lock_wait_timeout=1');
    $rivalService=new AF_ACS_EncounterService(new AF_ACS_EncounterRepository(new InventoryFixtureDatabase($rival)),$access);$contended=false;
    $contendingAccess=new class($access,$rivalService,$confirm,$contended) extends AF_ACS_SessionAccess {
        private bool $once=false;public function __construct(private $native,private $rival,private array $input,public bool &$contended){}
        public function thread(string $tid,?string $uid=null):array{if(!$this->once){$this->once=true;$r=$this->input;$r['operation_key']='end_concurrent_other01';itemFail(fn()=>$this->rival->command('outcome_confirm',$r),'ACS_MAINTENANCE_BUSY');$this->contended=true;}return $this->native->thread($tid,$uid);}
    };
    $link->loseAck=true;itemFail(fn()=>(new AF_ACS_EncounterService($repo,$contendingAccess))->command('outcome_confirm',$confirm),'ACS_TRANSACTION_ERROR');snapshot_assert($contended,'Second writer not attempted');
    $confirmed=$service->command('outcome_confirm',$confirm);$outcome=$repo->confirmedOutcome($sid,'1');snapshot_assert($outcome['outcome_id']===$confirmed['outcome_id']&&$outcome['outcome']['winner_side_id']==='1','No stable confirmed identity');
    $finished=$captureAll();snapshot_assert($service->command('outcome_confirm',$confirm)===$confirmed&&$captureAll()===$finished,'Repeated confirmation changed history');
    $different=$request('end_confirm_new_key01')+['resolution_id'=>(string)$p['id'],'outcome'=>'victory'];itemFail(fn()=>$service->command('outcome_confirm',$different),'ACS_ENCOUNTER_TRANSITION_BLOCKED');
    snapshot_assert($link->query("SELECT status FROM t_af_acs_operations WHERE operation_key='acs:end:recovery:obligation'")->fetch_row()[0]==='recovery_required','Completion cleared recovery');
    $changed=$confirm;$changed['reason']='Different decision under same key';itemFail(fn()=>$service->command('outcome_confirm',$changed),'ACS_OPERATION_KEY_CONFLICT');
    $rival->close();foreach(['rolls','combat_results','combat_receipts','entity_states','inv_items','inv_reservations'] as $t)snapshot_assert($finished[$t]===$combat[$t],'GM confirmation rewrote '.$t);
    // A real after_damage reflected child incapacitates the other side before end evaluation.
    $reset($base);$seed($victim,'cast-mirror');$link->query('UPDATE t_af_acs_entity_states SET hp=1');$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp'");
    [$result,$after]=$publish();$p=$pending();$candidate=json_decode($p['candidate_json'],true);$response=$result['plan']['actions'][0]['effect_responses'][0];
    snapshot_assert($response['executed']&&$candidate['condition']==='no_active_sides'&&$candidate['winner_side_id']===null,'End ran before reflect');
    foreach([$actor,$victim] as $id)snapshot_assert($repo->entity($sid,$id)['life_state']==='incapacitated','Mutual became death');
    $bad=$decide('end_mutual_no_win01');itemFail(fn()=>$service->command('outcome_confirm',$bad),'ACS_END_OUTCOME_DENIED');
    $continue=$request('end_allow_continue01')+['resolution_id'=>(string)$p['id']];$before=$captureAll();$continued=$service->command('outcome_continue',$continue);snapshot_assert($repo->encounter($sid,'1')['status']==='active'&&!$repo->confirmedOutcome($sid,'1'),'Continuation failed');$after=$captureAll();foreach(['entity_states','turns','entity_turns','rolls','inv_reservations','combat_results'] as $t)snapshot_assert($before[$t]===$after[$t],'Continuation refilled/reopened '.$t);snapshot_assert($service->command('outcome_continue',$continue)===$continued,'Continuation replay');
    // Confirm a mutual outcome from the same real mechanical setup.
    $reset($base);$seed($victim,'cast-mirror');$link->query('UPDATE t_af_acs_entity_states SET hp=1');$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp'");$publish();$r=$decide('end_mutual_draw01');$r['outcome']='draw';$service->command('outcome_confirm',$r);snapshot_assert($repo->confirmedOutcome($sid,'1')['outcome']===['kind'=>'draw','winner_side_id'=>null],'Draw assigned winner');
    $populated=$captureAll();$migrations->run();$migrations->run();snapshot_assert($captureAll()===$populated,'Migration replay mutated populated state');
    echo "ACS end conditions SQL: full response/rollback, victory pending, mutual incapacitation, fixed history, POST/CSRF/GM/CAS, concurrent writer, lost ACK/replay, continuation and confirmed identity passed\n";
};
require __DIR__.'/acs_effect_responses_sql.php';
