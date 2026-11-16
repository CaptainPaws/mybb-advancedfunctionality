<?php
/** Narrow disposable combat/outcome + reward fixture. Uses real IC-BALANCE.
 * ACS_REWARD_SYNC_FIXTURE=1 selects the separate unsupported CS branch. */
$GLOBALS['acsLootExpectedGroups']=2;
$GLOBALS['acsLootParticipantsFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RewardService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/RewardController.php';
    require_once AF_ADDONS.'balance/balance.php';
    af_balance_ensure_schema();
    if(!$db->table_exists('settings'))$link->query('CREATE TABLE t_settings(name VARCHAR(100) PRIMARY KEY,value TEXT) ENGINE=MyISAM');
    $link->query("INSERT INTO t_settings(name,value) VALUES('af_balance_credits_scale_migrated','1') ON DUPLICATE KEY UPDATE value='1'");
    (new AF_BALANCE_RewardCredit($link))->upgrade();
    $mybb->usergroup['canview']=1;$mybb->usergroup['isbannedgroup']=0;
    $actorRow=$link->query('SELECT * FROM t_af_acs_entities WHERE id='.$actor)->fetch_assoc();$groupRow=$link->query('SELECT * FROM t_af_acs_control_groups WHERE id='.$actorRow['control_group_id'])->fetch_assoc();$state=$link->query('SELECT * FROM t_af_acs_entity_states WHERE entity_id='.$actor)->fetch_assoc();
    itemInsert($link,'users',['uid'=>8]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>8,'role'=>'player','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $g=$groupRow;unset($g['id']);$g['owner_uid']=8;itemInsert($link,'af_acs_control_groups',$g);$second=(string)$link->insert_id;
    $e=$actorRow;unset($e['id']);$e['owner_uid']=8;$e['sheet_id']=108;$e['control_group_id']=$second;$e['name']='Registered reward group';itemInsert($link,'af_acs_entities',$e);$entity=(string)$link->insert_id;
    $s=$state;$s['entity_id']=$entity;itemInsert($link,'af_acs_entity_states',$s);
    $c=$link->query('SELECT * FROM t_af_acs_controllers WHERE entity_id='.$actor.' LIMIT 1')->fetch_assoc();unset($c['id']);$c['entity_id']=$entity;$c['controller_uid']=8;itemInsert($link,'af_acs_controllers',$c);
    itemInsert($link,'af_acs_participants',['encounter_id'=>1,'entity_id'=>$entity,'side_id'=>1,'participation'=>'withdrawn','registered_sequence'=>1,'participant_version'=>1,'visibility_json'=>'{"schema_version":1,"scope":"gm"}']);
    $pet=$actorRow;unset($pet['id']);$pet['kind']='pet';$pet['source_entity_id']=$actor;$pet['name']='Explicit reward pet';itemInsert($link,'af_acs_entities',$pet);$petId=(string)$link->insert_id;
    $s=$state;$s['entity_id']=$petId;itemInsert($link,'af_acs_entity_states',$s);$c['entity_id']=$petId;$c['controller_uid']=7;itemInsert($link,'af_acs_controllers',$c);
    itemInsert($link,'af_acs_participants',['encounter_id'=>1,'entity_id'=>$petId,'side_id'=>1,'participation'=>'withdrawn','registered_sequence'=>1,'participant_version'=>1,'visibility_json'=>'{"schema_version":1,"scope":"gm"}']);
    $GLOBALS['rewardGroups']=['group:'.$actorRow['control_group_id'],'group:'.$second];$GLOBALS['rewardPet']=$petId;
    $service=new AF_ACS_RewardService($db,$access);$defs=[];
    $recipes=getenv('ACS_REWARD_SYNC_FIXTURE')?['sync'=>['permanent_sync','each',1]]:['each-exp'=>['experience','each',125],'split-credits'=>['currency','split_equal',101],'manual-credits'=>['currency','manual',201],'pet-exp'=>['experience','each',25],'offline-credits'=>['currency','each',100],'ack-credits'=>['currency','each',7]];
    foreach($recipes as $key=>[$type,$mode,$amount]){
        $d=['schema'=>AF_ACS_RewardDistribution::SCHEMA,'reward_type'=>$type,'mode'=>$mode,'amount_minor'=>$amount,'remainder_policy'=>$mode==='split_equal'?'recipient_key_order':null,'outcomes'=>['victory'],'eligibility'=>['side'=>'winner','life_states'=>['active','incapacitated','dead'],'participation'=>['engaged','withdrawn']],'subject'=>$key==='pet-exp'?'entity':'control_group','entity_kinds'=>$key==='pet-exp'?['pet']:[],'permanent_sync'=>$type==='permanent_sync'?['owner'=>'charactersheets','kb_definition'=>'enduring-scar','change'=>['attribute'=>'strength','delta'=>1]]:null];
        $kb='reward-'.$key;$rules=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','version'=>'1','service_kind'=>'combat_template','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>$kb,'version'=>'1','phases'=>[],'limits'=>[],'formula_refs'=>[],'reward_source'=>$d]]];
        itemInsert($link,'af_kb_entries',snapshot_entry(2100+count($defs),'arpg_mechanics',$kb,$rules));
        $service->command('reward_configure',$request('reward_configure_'.str_replace('-','_',$key))+['kb_key'=>$kb]);$defs[$kb]=$d;
    }
    $kb=array_key_first($defs);itemFail(fn()=>$service->command('reward_distribute',$request('reward_before_outcome01')+['kb_key'=>$kb,'allocations'=>[]]),'ACS_REWARD_CONFIRMED_OUTCOME_REQUIRED');
    if(isset($GLOBALS['acsAchievementConfigureFixture']))($GLOBALS['acsAchievementConfigureFixture'])(get_defined_vars());
    $GLOBALS['rewardDefinitions']=$defs;
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
};
$GLOBALS['acsLootAllocationFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RewardService.php';
    $service=new AF_ACS_RewardService($db,$access);$groups=$GLOBALS['rewardGroups'];$defs=$GLOBALS['rewardDefinitions'];$plans=[];
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    $requestReward=static fn($key,$kb,$alloc=[])=>['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'operation_key'=>$key,'reason'=>'Pinned reward fixture','kb_key'=>$kb,'allocations'=>$alloc];
    if(isset($GLOBALS['acsStage10LootFixture']))($GLOBALS['acsStage10LootFixture'])(get_defined_vars());
    // Current ownership/participation/KB mutation cannot rewrite already frozen eligibility.
    $link->query('UPDATE t_af_acs_control_groups SET owner_uid=9,group_version=group_version+1 WHERE id='.substr($groups[1],6));
    $link->query("UPDATE t_af_kb_entries SET active=0 WHERE id>=2100");
    $base=$captureAll();
    foreach($defs as $kb=>$d){
        $manual=$d['mode']==='manual'?[['recipient_key'=>$groups[0],'amount_minor'=>70],['recipient_key'=>$groups[1],'amount_minor'=>131]]:[];
        $r=$requestReward('reward_distribute_'.substr(hash('sha256',$kb),0,12),$kb,$manual);
        if(!$plans){
            $mybb->user['uid']=8;itemFail(fn()=>$service->command('reward_distribute',$r),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
            $mybb->request_method='get';itemFail(fn()=>$service->command('reward_distribute',$r),'ACS_POST_REQUIRED');$mybb->request_method='post';
            $mybb->input['my_post_key']='bad';itemFail(fn()=>$service->command('reward_distribute',$r),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
            $link->query("CREATE TRIGGER reward_plan_fault BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='reward plan rollback'");try{$service->command('reward_distribute',$r);throw new RuntimeException('Expected rollback');}catch(RuntimeException $error){if($error->getMessage()!=='ACS_DATABASE_ERROR')throw $error;}$link->query('DROP TRIGGER reward_plan_fault');
            snapshot_assert((int)$link->query('SELECT COUNT(*) FROM t_af_acs_rewards')->fetch_row()[0]===0,'Distribution rollback leaked rewards');
        }
        if($d['mode']==='manual'){$bad=$r;$bad['operation_key']='reward_bad_manual_group01';$bad['allocations']=[['recipient_key'=>'group:999999','amount_minor'=>201]];itemFail(fn()=>$service->command('reward_distribute',$bad),'ACS_REWARD_ALLOCATION_INVALID');}
        $plan=$service->command('reward_distribute',$r);$plans[$kb]=$plan;
        snapshot_assert($service->command('reward_distribute',$r)===$plan,'Same command duplicate');$again=$requestReward('reward_replay_'.substr(hash('sha256',$kb),0,12),$kb,$manual);$again['expected_version']='1';snapshot_assert($service->command('reward_distribute',$again)===$plan,'Same source/new key duplicated rewards');
        $rows=$plan['reward_plan']['rewards'];snapshot_assert(count($rows)===($d['subject']==='entity'?1:2),'Group/pet multiplier or missing recipient');
        if($d['mode']==='split_equal')snapshot_assert(array_sum(array_column($plan['reward_plan']['allocations'],'amount_minor'))===101&&abs($rows[0]['payload']['recipient']['amount_minor']-$rows[1]['payload']['recipient']['amount_minor'])===1,'Split lost minor remainder');
        if($d['mode']==='manual'){$again['allocations'][0]['amount_minor']=71;itemFail(fn()=>$service->command('reward_distribute',$again),'ACS_REWARD_ALLOCATION_CLOSED');}
        snapshot_assert(array_column(array_column($rows,'payload'),'recipient')[0]['uid']===7,'Frozen owner changed');
    }
    $again=$captureAll();snapshot_assert($encounters->command('outcome_confirm',$confirm)===$confirmed&&$captureAll()===$again,'GM confirmation repeated rewards');
    if(getenv('ACS_REWARD_SYNC_FIXTURE')){
        $row=$plans['reward-sync']['reward_plan']['rewards'][0];$state=$service->inspect($sid,$row['reward_id']);snapshot_assert($state['status']==='blocked'&&$state['blocked_reason']==='charactersheets_durable_sync_api_missing','CS unsupported branch not blocked');
        $issue=['session_id'=>$sid,'reward_id'=>$row['reward_id'],'expected_version'=>$repo->session($sid)['session_version'],'expected_reward_version'=>$state['reward_version'],'operation_key'=>'reward_sync_issue_no01','reason'=>'Unsupported sync'];snapshot_assert($service->issue($issue)['status']==='blocked','CS issued without gateway');
        snapshot_assert((int)$link->query("SELECT COUNT(*) FROM t_af_balance_external_operations")->fetch_row()[0]===0,'CS branch called Balance');
        itemFail(fn()=>$sessions->command('request_close',$sr('reward_sync_close_block01')),'ACS_SESSION_OBLIGATIONS_BLOCKED');
    }else{
        $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
        $issueRequest=static function($id,$key)use($repo,$sid,$service){$s=$service->inspect($sid,$id);return ['session_id'=>$sid,'reward_id'=>$id,'expected_version'=>$repo->session($sid)['session_version'],'expected_reward_version'=>$s['reward_version'],'operation_key'=>$key,'reason'=>'Exact reward recipient issue'];};
        $expectedExp=[7=>0,8=>0];$expectedCr=[7=>0,8=>0];
        foreach($plans as $kb=>$plan)foreach($plan['reward_plan']['rewards'] as $row){$p=$row['payload'];if($p['reward_type']==='experience')$expectedExp[$p['recipient']['uid']]+=$p['recipient']['amount_minor'];else $expectedCr[$p['recipient']['uid']]+=$p['recipient']['amount_minor'];}
        // Offline before owner prepare: recipient stays pending; settled recipients never replay.
        $offline=new class($db,$access) extends AF_ACS_BalanceAdapter {public bool $offline=true;public function version(int $uid):int{if($uid===8&&$this->offline)throw new RuntimeException('offline');return parent::version($uid);}};
        $partial=new AF_ACS_RewardService($db,$access,$offline);$offlineRows=$plans['reward-offline-credits']['reward_plan']['rewards'];
        $first=$partial->issue($issueRequest($offlineRows[0]['reward_id'],'reward_partial_first01'));$second=$partial->issue($issueRequest($offlineRows[1]['reward_id'],'reward_partial_second01'));
        snapshot_assert($first['status']==='issued'&&$second['status']==='pending'&&$second['owner_state']==='unavailable','Partial recipients lost state');$offline->offline=false;
        $txBefore=(int)$link->query('SELECT COUNT(*) FROM t_af_balance_tx')->fetch_row()[0];snapshot_assert($partial->issue($issueRequest($offlineRows[0]['reward_id'],'reward_partial_again01'))===$first,'Settled recipient reissued');
        snapshot_assert($partial->issue($issueRequest($offlineRows[1]['reward_id'],'reward_pending_retry01'))['status']==='issued'&&(int)$link->query('SELECT COUNT(*) FROM t_af_balance_tx')->fetch_row()[0]===$txBefore+1,'Pending-only retry failed');
        // Owner committed but its ACK was lost. Only same-key lookup may settle ACS.
        $ack=new class($db,$access) extends AF_ACS_BalanceAdapter {public function credit(string $sid,string $id):array{$this->link->loseAck=true;return parent::credit($sid,$id);}};
        $ackService=new AF_ACS_RewardService($db,$access,$ack);$ackId=$plans['reward-ack-credits']['reward_plan']['rewards'][0]['reward_id'];
        $unknown=$ackService->issue($issueRequest($ackId,'reward_lost_ack_issue01'));snapshot_assert($unknown['status']==='recovery_required','Lost ACK was treated failed/issued');
        $source=$repo->rewardIntentEvidence($sid,$ackId);$auth=new AF_ACS_BalanceRewardAuthority($link,$access,$sid,$ackId);$forged=$source['intent'];$forged['uid']=9;itemFail(fn()=>$auth->authorize($forged),'BALANCE_REWARD_UNAUTHORIZED');
        $txBefore=(int)$link->query('SELECT COUNT(*) FROM t_af_balance_tx')->fetch_row()[0];$recovery=new AF_ACS_RecoveryService($db,$access);
        snapshot_assert($service->inspect($sid,$ackId)['status']==='recovery_required','Unknown reward must remain unresolved until lookup');
        // Fail ACS acknowledgement after a proven owner credit; both ACS status projections rollback.
        $id=$plans['reward-each-exp']['reward_plan']['rewards'][0]['reward_id'];
        $link->query("CREATE TRIGGER reward_ack_fault BEFORE INSERT ON t_af_acs_events FOR EACH ROW BEGIN IF NEW.event_type='reward.committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ack rollback'; END IF; END");
        itemFail(fn()=>$service->issue($issueRequest($id,'reward_acs_ack_fault01')),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER reward_ack_fault');$state=$service->inspect($sid,$id);snapshot_assert($state['status']==='recovery_required','ACS acknowledgement rollback lost barrier');
        $badAdapter=new class($db,$access) extends AF_ACS_BalanceAdapter {public function lookup(string $sid,string $id):array{$r=parent::lookup($sid,$id);$r['uid']=9;unset($r['result_hash']);$r['result_hash']=hash('sha256',AF_BALANCE_RewardCredit::json($r));return $r;}};
        itemFail(fn()=>(new AF_ACS_RewardService($db,$access,$badAdapter))->recover($sid,$id,$state['operation_version']),'ACS_BALANCE_RECEIPT_INVALID');
        $recovery->recoverReward($sid,$id,$state['operation_version']);
        // Another SQL request arrives after dispatch CAS, before owner COMMIT.
        $raceId=$plans['reward-pet-exp']['reward_plan']['rewards'][0]['reward_id'];
        $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');$rivalService=new AF_ACS_RewardService($rival,$access);$raceRequest=$issueRequest($raceId,'reward_race_primary01');
        $raceAdapter=new class($db,$access,$rivalService,$raceRequest) extends AF_ACS_BalanceAdapter {
            public bool $contended=false;
            public function __construct($db,$access,private AF_ACS_RewardService $rival,private array $request){parent::__construct($db,$access);}
            public function credit(string $sid,string $id):array{$r=$this->request;$r['operation_key']='reward_race_rival01';$state=$this->rival->issue($r);snapshot_assert($state['status']==='recovery_required'&&$state['lookup_state']==='not_found','Rival attempted a second dispatch');$this->contended=true;return parent::credit($sid,$id);}
        };
        snapshot_assert((new AF_ACS_RewardService($db,$access,$raceAdapter))->issue($raceRequest)['status']==='issued'&&$raceAdapter->contended,'Two-request owner dispatch race');$rival->close();
        // Leave one real pending recipient through close/archive; recovery has its own scope.
        $leave=$plans['reward-manual-credits']['reward_plan']['rewards'][1]['reward_id'];
        foreach($plans as $plan)foreach($plan['reward_plan']['rewards'] as $row){$id=$row['reward_id'];if($id===$leave||$id===$ackId)continue;$service->issue($issueRequest($id,'reward_issue_'.str_pad($id,16,'0',STR_PAD_LEFT)));}
        $pending=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert(!$pending['explicitly_blocked']&&array_filter($pending['pending'],fn($x)=>$x['source']==='reward'),'Pending reward missing recovery scope');
        $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
        $sessions->command('request_close',$sr('reward_pending_close01'));$sessions->command('close',$sr('reward_session_close01'));$sessions->command('archive',$sr('reward_session_archive01'));
        snapshot_assert($service->inspect($sid,$leave)['status']==='pending','Archive dropped pending reward');$service->issue($issueRequest($leave,'reward_archived_issue01'));
        $txBefore=(int)$link->query('SELECT COUNT(*) FROM t_af_balance_tx')->fetch_row()[0];
        snapshot_assert($recovery->recoverReward($sid,$ackId,$unknown['operation_version'])['status']==='issued'&&(int)$link->query('SELECT COUNT(*) FROM t_af_balance_tx')->fetch_row()[0]===$txBefore,'Archived recovery credited again');
        snapshot_assert($repo->session($sid)['status']==='archived','Reward recovery reopened session');
        foreach([7,8] as $uid){$bal=af_balance_get($uid);snapshot_assert($bal['exp']===$expectedExp[$uid]&&$bal['credits']===$expectedCr[$uid],'Wrong exact Balance total '.json_encode([$uid,$bal,$expectedExp,$expectedCr]));}
        $snap=$captureAll();$migrations->run();snapshot_assert($captureAll()===$snap,'Migration regenerated reward');
        snapshot_assert((int)$link->query("SELECT COUNT(*) FROM t_af_balance_external_operations")->fetch_row()[0]===(int)$link->query("SELECT COUNT(*) FROM t_af_acs_rewards WHERE status='issued'")->fetch_row()[0],'Duplicate/missing owner receipt');
    }
    $after=$captureAll();foreach(['snapshots','entity_states','resources','sealed_actions','rolls','combat_results','combat_receipts','turns','entity_turns','cooldowns','inv_items','inv_owner_state','inv_reservations'] as $table)snapshot_assert($base[$table]===$after[$table],'Rewards changed active/mechanical snapshot or Inventory '.$table);
    if(isset($GLOBALS['acsStage10PaidFixture']))($GLOBALS['acsStage10PaidFixture'])(get_defined_vars());
    if(isset($GLOBALS['acsAchievementEvaluationFixture']))($GLOBALS['acsAchievementEvaluationFixture'])(get_defined_vars());
    echo 'ACS reward distribution SQL '.(getenv('ACS_REWARD_SYNC_FIXTURE')?'BLOCKED CS sync':'each/split/manual, frozen groups+pet, exact Balance, partial/offline, lost ACK, forged receipt, ACK rollback, closed/archive recovery').' passed'.PHP_EOL;
};
require __DIR__.'/acs_loot_sql.php';
