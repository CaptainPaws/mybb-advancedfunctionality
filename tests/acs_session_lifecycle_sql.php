<?php
/** A1.39–40, actual mechanical completion, owner protocol and disposable MySQL only. */
function af_clear_addon_lifecycle_diagnostic($addon):void {}
function is_super_admin($uid):bool {return (int)$uid===7;}
$GLOBALS['acsEndFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/CustodyService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/InventoryOperationService.php';
    $sessions=new AF_ACS_SessionService($repo,$access);
    $request=static fn(string $key)=>['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Disposable A1.39–40 evidence'];
    $blocked=$captureAll();itemFail(fn()=>$sessions->command('request_close',$request('lifecycle_live_enc01')),'ACS_SESSION_OBLIGATIONS_BLOCKED');snapshot_assert($captureAll()===$blocked,'Live close mutated');
    itemFail(fn()=>$sessions->command('close',$request('lifecycle_direct_close01')),'ACS_SESSION_TRANSITION_BLOCKED');
    $encounters=new AF_ACS_EncounterService($repo,$access);
    itemInsert($link,'af_acs_sides',['id'=>2,'encounter_id'=>1,'name'=>'Opposition','side_version'=>1,'policy_json'=>'{"schema_version":1}']);
    itemInsert($link,'af_acs_side_relationships',['encounter_id'=>1,'side_a'=>1,'side_b'=>2,'relationship'=>'hostile','relation_version'=>1]);
    $link->query('UPDATE t_af_acs_participants SET side_id=2 WHERE entity_id='.$victim);
    $link->query("UPDATE t_af_acs_encounters SET status='preparation',round_number=0 WHERE id=1");
    $er=static fn(string $key)=>$request($key)+['encounter_id'=>'1','encounter_version'=>$repo->encounter($sid,'1')['encounter_version']];
    $encounters->command('end_conditions_set',$er('lifecycle_policy_set01')+['end_conditions'=>['schema'=>AF_ACS_EndCondition::SCHEMA,'conditions'=>['last_active_side','no_active_sides'],'allow_continuation'=>true,'mutual_outcomes'=>['draw']]]);
    $link->query("UPDATE t_af_acs_encounters SET status='active',round_number=2 WHERE id=1");
    $link->query('UPDATE t_af_acs_entity_states SET hp=1 WHERE entity_id='.$victim);$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp' AND entity_id=".$victim);
    $publish(true);$resolution=$link->query("SELECT id FROM t_af_acs_encounter_resolutions WHERE status='pending'")->fetch_row()[0];
    $encounters->command('outcome_confirm',$er('lifecycle_outcome_confirm01')+['resolution_id'=>(string)$resolution,'outcome'=>'victory']);
    $outcome=$repo->confirmedOutcome($sid,'1');
    // Establish custody, then a real transfer whose owner commit loses its ACK.
    $custody=new AF_ACS_CustodyService($db,$access);
    $cr=static fn(string $key,string $v)=>$request($key)+['expected_custody_version'=>$v,'inventory_item_id'=>'20','owner_entity_id'=>$actor,'custodian_entity_id'=>''];
    $held=$custody->command('confiscate',$cr('lifecycle_custody_hold01','0'));
    itemInsert($link,'users',['uid'=>8]);
    $ownerLink=new ItemAckConnection('localhost','root','',$database,0,$socket);$ownerLink->set_charset('utf8mb4');
    $policy=new class implements AF_ADVINV_OwnerAccess {
        public function uid():int {return 7;}
        public function authorize(string $op,int $owner,array $scope,string $ref,bool $mutation):void {if($ref!=='fixture-lifecycle-transfer')throw new RuntimeException('INV_ACCESS_DENIED');}
    };
    $owner=new AF_ADVINV_OwnerProtocol($ownerLink,$policy);
    $inventory=new AF_ACS_InventoryOperationService($db,$access,new AF_ACS_InventoryAdapter($owner));
    $intent=['schema_version'=>1,'operation'=>'transfer','operation_key'=>'acs:lifecycle:transfer:unknown','actor'=>['uid'=>7,'authorization_ref'=>'fixture-lifecycle-transfer'],'scope'=>['system'=>'acs','context_key'=>$sid],'payload'=>['item_id'=>22,'qty'=>1,'from_uid'=>7,'to_uid'=>8,'expected_versions'=>['from'=>(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0],'to'=>1]]];
    $intent['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($intent);$ownerLink->loseAck=true;
    snapshot_assert($inventory->execute($sid,$intent)['status']==='recovery_required','Expected owner unknown');
    $before=$captureAll();$summary=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert($summary['pending']&&$summary['recovery_required']&&!$summary['explicitly_blocked'],'Missing retained scopes');
    $r=$request('lifecycle_pending_once01');
    $mybb->request_method='get';itemFail(fn()=>$sessions->command('request_close',$r),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $mybb->input['my_post_key']='bad';itemFail(fn()=>$sessions->command('request_close',$r),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $mybb->user['uid']=8;itemFail(fn()=>$sessions->command('request_close',$r),'ACS_SESSION_UNAVAILABLE');$mybb->user['uid']=7;
    $denied=new class extends AF_ACS_SessionAccess {public function thread(string $tid,?string $uid=null):array{throw new RuntimeException('ACS_NATIVE_DENIED');}};
    itemFail(fn()=>(new AF_ACS_SessionService($repo,$denied))->command('request_close',$r),'ACS_NATIVE_DENIED');
    $link->query("CREATE TRIGGER lifecycle_fail BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='lifecycle rollback'");
    itemFail(fn()=>$sessions->command('request_close',$r),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER lifecycle_fail');snapshot_assert($captureAll()===$before,'Lifecycle rollback leaked');
    $pending=$sessions->command('request_close',$r);snapshot_assert($pending['status']==='pending_close','Not pending');$snap=$captureAll();snapshot_assert($sessions->command('request_close',$r)===$pending&&$captureAll()===$snap,'Pending replay');
    itemFail(fn()=>$sessions->command('set_role',$request('lifecycle_pending_role01')+['uid'=>'8','role'=>'player']),'ACS_SESSION_UNAVAILABLE');
    $stale=$request('lifecycle_close_stale01');$stale['expected_version']=$r['expected_version'];itemFail(fn()=>$sessions->command('close',$stale),'ACS_VERSION_CONFLICT');
    // Genuine second writer while the first holds the session lock.
    $r=$request('lifecycle_close_once01');$rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');
    $rivalService=new AF_ACS_SessionService(new AF_ACS_SessionRepository($rival),$access);$contended=false;
    $contending=new class($access,$rivalService,$r,$contended) extends AF_ACS_SessionAccess {
        private bool $once=false;public function __construct(private $native,private $rival,private array $input,public bool &$contended){}
        public function thread(string $tid,?string $uid=null):array {if(!$this->once){$this->once=true;$r=$this->input;$r['operation_key']='lifecycle_concurrent_other01';itemFail(fn()=>$this->rival->command('close',$r),'ACS_MAINTENANCE_BUSY');$this->contended=true;}return $this->native->thread($tid,$uid);}
    };
    $link->loseAck=true;itemFail(fn()=>(new AF_ACS_SessionService($repo,$contending))->command('close',$r),'ACS_TRANSACTION_ERROR');
    $closed=$sessions->command('close',$r);snapshot_assert($closed['status']==='closed'&&$contended,'Close/concurrent writer');$snap=$captureAll();snapshot_assert($sessions->command('close',$r)===$closed&&$captureAll()===$snap,'Close replay');$rival->close();
    $recovery=new AF_ACS_RecoveryService($db,$access);$recovered=$recovery->recoverInventory($sid,$intent['operation_key'],'2');snapshot_assert($recovered['status']==='committed','Recovery after close');
    $r=$request('lifecycle_reopen_once01');$opened=$sessions->command('reopen_session',$r);snapshot_assert($opened['status']==='active'&&$repo->confirmedOutcome($sid,'1')===$outcome,'Reopen restarted encounter');$snap=$captureAll();snapshot_assert($sessions->command('reopen_session',$r)===$opened&&$captureAll()===$snap,'Reopen replay');
    foreach(['entity_states','resources','cooldowns','turns','entity_turns','rounds','encounters','encounter_resolutions','rolls','sealed_actions','combat_results','combat_receipts','snapshots'] as $table)snapshot_assert($captureAll()[$table]===$before[$table],'Lifecycle rewrote/refilled '.$table);
    $intent2=$intent;$intent2['operation_key']='acs:lifecycle:transfer:archived';$intent2['payload']['item_id']=21;$intent2['payload']['expected_versions']=['from'=>(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=7')->fetch_row()[0],'to'=>(int)$link->query('SELECT inventory_version FROM t_af_advinv_owner_state WHERE uid=8')->fetch_row()[0]];$intent2['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($intent2);$ownerLink->loseAck=true;snapshot_assert($inventory->execute($sid,$intent2)['status']==='recovery_required','Second pending transfer');
    $sessions->command('request_close',$request('lifecycle_pending_again01'));$sessions->command('close',$request('lifecycle_close_again01'));
    $r=$request('lifecycle_archive_once01');$archived=$sessions->command('archive',$r);$snap=$captureAll();snapshot_assert($archived['status']==='archived'&&$sessions->command('archive',$r)===$archived&&$captureAll()===$snap,'Archive replay');
    snapshot_assert($sessions->view($sid,true)['can_audit']&&!$sessions->view($sid)['can_manage'],'Archive GM history access');
    itemFail(fn()=>(new AF_ACS_CombatInventoryAccess($link,7))->authorize('reserve',7,['system'=>'acs','context_key'=>$sid],'acs-item:'.$sid.':'.$actor,true),'INV_SPENDING_NOT_AUTHORIZED');
    itemFail(fn()=>$sessions->command('reopen_session',$request('lifecycle_archive_reopen01')),'ACS_SESSION_TRANSITION_BLOCKED');
    snapshot_assert($custody->view($sid)['items'][0]['status']==='held','Archive lost custody');
    snapshot_assert($recovery->recoverInventory($sid,$intent2['operation_key'],'2')['status']==='committed','Pending lookup after archive');
    $returned=$custody->command('return',$cr('lifecycle_archive_return01','1'));snapshot_assert($returned['status']==='returned','Archive custody return');
    snapshot_assert($recovery->recoverInventory($sid,$intent['operation_key'],'2')['status']==='committed','Archive terminal recovery');
    // Source extension fails closed for future obligations without an archive recovery capability.
    $extension=new class($link) extends AF_ACS_SessionObligationsQuery {
        protected function additionalSources():array {return [new class implements AF_ACS_SessionObligationSource {public function recoveryScope():?string{return null;}public function inspectSession(string $sid):array{return [['state'=>'pending','source'=>'future_fixture','reference'=>'obligation-1']];}}];}
    };snapshot_assert(count($extension->inspect($sid)['explicitly_blocked'])===1,'Unknown future obligation silently closed');
    require_once AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';if(!defined('IN_ADMINCP'))define('IN_ADMINCP',true);$native=new class extends stdClass {public function get_input($k){return $this->input[$k]??'';}};foreach(get_object_vars($mybb) as $k=>$value)$native->$k=$value;$mybb=$native;$mybb->version_code=1841;$mybb->usergroup['cancp']=1;
    $populated=$captureAll();snapshot_assert(af_advancedcombatsystem_deactivate()&&af_advancedcombatsystem_activate()&&af_advancedcombatsystem_activate(),'Lifecycle activation');snapshot_assert($captureAll()===$populated,'Activation damaged populated session');
    $ownerLink->close();echo "ACS session lifecycle SQL: pending/close/reopen/archive, CAS/concurrent GM, ACK replay, rollback, native/CSRF, custody+Inventory recovery, no refill/restart, future obligations and populated reactivation passed\n";
};
require __DIR__.'/acs_effect_responses_sql.php';
