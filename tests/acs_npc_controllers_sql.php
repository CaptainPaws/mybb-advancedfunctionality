<?php
/** Focused NPC modes on the existing authoritative/disposable Inventory-stage fixture. */
$GLOBALS['acsCustodyFixture']=static function(array $fixture):void {
    extract($fixture);global $db,$mybb;
    $mybb=new class($mybb) {
        public array $user,$usergroup,$settings,$input;public string $request_method='post',$post_code='token';
        public function __construct($old){foreach(['user','usergroup','settings','input'] as $key)$this->$key=$old->$key;}
        public function get_input($key){return $this->input[$key]??'';}
    };
    $access=new class extends AF_ACS_SessionAccess {
        public array $denied=[];
        public function user(string $uid):array {if(!in_array($uid,['7','8','9','10'],true))throw new RuntimeException('ACS_USER_UNAVAILABLE');return ['uid'=>(int)$uid];}
        public function thread(string $tid,?string $uid=null):array {if($tid!=='51'||in_array($uid??$this->uid(),$this->denied,true))throw new RuntimeException('ACS_THREAD_UNAVAILABLE');return ['tid'=>51,'fid'=>2,'uid'=>7,'visible'=>1,'closed'=>'0'];}
    };
    $encounters=new AF_ACS_EncounterService($repo,$access);
    // Existing stage-6 fixtures use explicit reference-only normalized grants for combat.
    // Legacy inline KB profile defaults are not controller policy and remain unsupported.
    $normalizedSource=new class extends AF_ACS_RegistrationSource {
        public function read(string $kind,string $key,int $actor):array {
            $dto=parent::read($kind,$key,$actor);
            if(in_array($kind,['npc','monster'],true))foreach($dto['data']['ability_keys'] as &$profile)$profile['ability']['parameters']=[];
            return $dto;
        }
    };
    $entityService=new AF_ACS_EntityService($entities,$access,$normalizedSource);
    foreach(['8'=>'gm','9'=>'player','10'=>'assistant_gm'] as $uid=>$role) {
        itemInsert($link,'users',['uid'=>$uid]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>$uid,'role'=>$role,'role_version'=>1,'permissions_json'=>'{"schema_version":1}']);
    }
    $ability=af_kb_default_type_profile_payload_arpg('arpg_ability');
    $ability=array_replace($ability,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'ability','version'=>'1.0','type'=>'active','slot'=>'active','damage_type'=>'physical','targeting'=>'single_enemy','cooldown_unit'=>'own_turn','duration_unit'=>'own_turn','max_charges'=>0,'magic_direction_ref'=>$direction,'effects'=>[['kind'=>'damage','damage_type'=>'physical','value'=>10]]]);
    itemInsert($link,'af_kb_entries',snapshot_entry(950,'arpg_ability','npc-strike',$ability));
    $errors=[];$ref=af_kb_arpg_prepare_bestiary_ability_ref(['_editor_ability_key'=>'npc-strike','ability_name'=>'Strike','damage_type'=>'physical','magic_direction_ref'=>$direction,'cooldown_unit'=>'own_turn','duration_unit'=>'own_turn'],$errors);snapshot_assert(!$errors,'Ability grant fixture invalid');
    $monster=af_kb_default_type_profile_payload_arpg('arpg_bestiary');
    $monster['family']=$monster['archetype']=$monster['faction']='fixture';$monster['combat_stats']['hp']=100;$monster['combat_stats']['accuracy']=20;$monster['combat_stats']['status_resist']=0;$monster['resists']=$res;$monster['magic_direction_ref']=$direction;$monster['ability_keys']=[$ref];
    $envelope=af_kb_arpg_envelope_defaults('arpg_bestiary');$envelope['rules']=$monster;$errors=[];
    $json=af_kb_validate_rules_json_by_type_arpg('arpg_bestiary',json_encode($envelope),$errors);snapshot_assert(!$errors,'Monster source invalid');
    itemInsert($link,'af_kb_entries',['id'=>951,'type'=>'arpg_bestiary','key'=>'controller-monster','active'=>1,'data_json'=>$json,'meta_json'=>'{}']);
    $seq=0;
    $register=static function()use($entityService,$repo,$sid,&$seq):array {return $entityService->register(['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'kind'=>'monster','source_key'=>'controller-monster','initial_hp'=>'80','name'=>'NPC <unsafe>','operation_key'=>'npc_register_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT),'reason'=>'Independent NPC fixture']);};
    $npc=$register();$target=$register();$source=$npc['entity_id'];
    // Explicit test-only combat gate. The controller command must preserve, never create it.
    $link->query("UPDATE t_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true}' WHERE entity_id IN (".$source.','.$target['entity_id'].')');
    $eid=null;
    $command=static function(string $op,array $args=[])use($encounters,$repo,$sid,&$eid,&$seq):array {
        $input=['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'npc_command_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT),'reason'=>'NPC controller verification'];
        if($eid!==null)$input+=['encounter_id'=>$eid,'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version']];
        return $encounters->command($op,array_replace($input,$args));
    };
    $eid=$command('create',['name'=>'NPC modes'])['encounter_id'];
    $left=$command('side_create',['name'=>'Left'])['side_id'];$right=$command('side_create',['name'=>'Right'])['side_id'];
    $command('relation_set',['side_a'=>$left,'side_b'=>$right,'relationship'=>'hostile']);
    $command('participant_add',['entity_id'=>$source,'side_id'=>$left]);$command('participant_add',['entity_id'=>$target['entity_id'],'side_id'=>$right]);
    $set=static function(string $mode,array $uids,?string $version=null,string $entity='')use($command,$repo,$sid,$source):array {
        $entity=$entity?:$source;return $command('controller_set',['entity_id'=>$entity,'mode'=>$mode,'uids'=>$uids,'assignment_version'=>$version??$repo->controllers($sid,$entity)[0]['assignment_version']]);
    };
    $shared=$set('shared',['7','8']);
    snapshot_assert(count($repo->participants($eid))===2 && count($repo->rosterSources($sid,$eid))===2 && count($entityService->list($sid)['items'])===3,'Controller JOIN multiplied entity rows');
    $ready=$encounters->readiness($sid,$eid);snapshot_assert($ready['can_start'],'Shared roster blocked '.json_encode($ready));
    $round=$command('round_start');snapshot_assert($round['turn_count']==='2' && $round['entity_turn_count']==='2','One monster received multiple turns');
    $turns=$repo->allRoundTurns($round['round_id']);$turnId=null;
    foreach($turns as $turn) {
        if($turn['control_group_id']===$npc['control_group_id'])$turnId=$turn['id'];
        foreach($repo->individualTurns([$turn['id']]) as $i)$encounters->clock()->command('entity_start',['session_id'=>$sid,'encounter_id'=>$eid,'entity_turn_id'=>$i['id'],'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'operation_key'=>'npc_clock_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT),'reason'=>'Start fixture entity']);
    }
    snapshot_assert($npc['control_group_id']!==$target['control_group_id'] && $turnId!==null,'Independent identity/group lost');
    $pin=$entityService->snapshot($sid,$source);
    $link->query("UPDATE t_af_kb_entries SET data_json='{}' WHERE id=951");
    snapshot_assert($entityService->snapshot($sid,$source)===$pin,'Live KB rebuilt the pinned snapshot');
    $tables=[];foreach(glob(AF_ADDONS.'advancedcombatsystem/migrations/[0-9][0-9][0-9]_*.php') as $file)foreach(array_keys((require $file)['tables']) as $t)$tables[$t]=true;
    $capture=static function(?array $names=null)use($link,$tables):array {$out=[];foreach($names??array_keys($tables) as $t)$out[$t]=$link->query('SELECT * FROM t_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;};
    $baseline=$capture();$reset=static function()use($link,$baseline,$mybb,$access):void {
        foreach($baseline as $t=>$rows){$link->query('DELETE FROM t_af_acs_'.$t);foreach($rows as $r)itemInsert($link,'af_acs_'.$t,$r);}
        $mybb->user['uid']=7;$access->denied=[];$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    };
    $mechanical=['entities','entity_states','resources','control_groups','turns','entity_turns','cooldowns','effects','reactions','sealed_actions','rolls','snapshots'];
    $link->query('UPDATE t_af_acs_turns SET ap_spent=1,seal_quota_spent=1 WHERE id='.$turnId);
    $link->query('UPDATE t_af_acs_entity_turns SET reaction_used=1 WHERE turn_id='.$turnId);
    $before=$capture($mechanical);$exclusive=$set('exclusive',['7']);
    snapshot_assert($capture($mechanical)===$before,'Reassignment restored or rewrote mechanics');
    snapshot_assert($repo->controllerFor($sid,$source,'7')!==null && $repo->controllerFor($sid,$source,'8')===null,'Exclusive allowed another GM');
    itemFail(fn()=>$set('shared',['7','8'],$shared['assignment_version']),'ACS_VERSION_CONFLICT');
    $set('shared',[]);snapshot_assert($repo->controllerFor($sid,$source,'7')===null,'Removing the last controller kept a grant');$set('shared',['8']);
    snapshot_assert($repo->controllerFor($sid,$source,'7')===null && $repo->controllerFor($sid,$source,'8')!==null,'Remove/add controller failed');
    $pool=$set('gm_pool',[]);
    snapshot_assert($capture($mechanical)===$before,'Pool reset AP/reaction/quota or HP');
    foreach(['7','8'] as $uid)snapshot_assert($repo->controllerFor($sid,$source,$uid)!==null,'Session GM denied in pool');
    $link->query('UPDATE t_af_acs_session_roles SET expires_at=1 WHERE uid=8');snapshot_assert($repo->controllerFor($sid,$source,'8')===null,'Expired GM retained pool control');$link->query('UPDATE t_af_acs_session_roles SET expires_at=NULL WHERE uid=8');
    foreach(['9','10','11'] as $uid)snapshot_assert($repo->controllerFor($sid,$source,$uid)===null,'Pool escaped GM role scope');
    snapshot_assert($repo->controllerFor($sid,$actor,'8')===null,'Pool granted player character control');
    // The pet boundary is identical even with an otherwise valid exclusive player assignment.
    $pet=$repo->entity($sid,$actor);$pet['kind']='pet';snapshot_assert(AF_ACS_AuthorizationService::controller($pet,$repo->controllers($sid,$actor),$repo->session($sid),$repo->roles($sid,'8'),'8')===null,'GM gained permanent pet');
    itemFail(fn()=>$set('gm_pool',[],entity:$actor),'ACS_ENTITY_UNAVAILABLE');
    $mybb->user['uid']=9;itemFail(fn()=>$set('exclusive',['7']),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $access->denied=['8'];itemFail(fn()=>$set('exclusive',['8']),'ACS_THREAD_UNAVAILABLE');$access->denied=[];
    $mybb->input['my_post_key']='bad';itemFail(fn()=>$set('exclusive',['7']),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $mybb->request_method='get';itemFail(fn()=>$set('exclusive',['7']),'ACS_POST_REQUIRED');$mybb->request_method='post';
    itemFail(fn()=>$command('controller_set',['entity_id'=>$source,'mode'=>'exclusive','uids'=>['7'],'assignment_version'=>$pool['assignment_version'],'encounter_id'=>'999']),'ACS_ENCOUNTER_UNAVAILABLE');
    // Scope and native posting permission still precede any builder grant in pool mode.
    $builder=new AF_ACS_BuilderService($repo,$access);$mybb->user['uid']=8;
    snapshot_assert(count($builder->context($sid,$eid,$turnId)['actors'])===1,'Pool GM cannot prepare');
    $access->denied=['8'];itemFail(fn()=>$builder->context($sid,$eid,$turnId),'ACS_THREAD_UNAVAILABLE');$access->denied=[];
    $GLOBALS['fixture_deny_post']=true;itemFail(fn()=>$builder->context($sid,$eid,$turnId),'ACS_DRAFT_UNAVAILABLE');$GLOBALS['fixture_deny_post']=false;$mybb->user['uid']=7;
    // All assignment changes produce confirmed append-only actor/reason/before+after audit.
    $audits=$link->query("SELECT e.actor_uid,d.payload_json FROM t_af_acs_events e JOIN t_af_acs_event_details d ON d.event_id=e.id WHERE e.event_type='encounter.controller_set'")->fetch_all(MYSQLI_ASSOC);
    snapshot_assert(count($audits)===5,'Missing assignment audit');foreach($audits as $a){$p=json_decode($a['payload_json'],true);snapshot_assert($a['actor_uid']==7 && $p['request']['reason']==='NPC controller verification' && isset($p['result']['previous_assignment_version'],$p['result']['assignment_version']),'Incomplete actor/reason/version audit');}
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/Encounters.php';
    $mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>'management'];$unchanged=$capture();
    $html=AF_ACS_EncountersPage::render($sid,'51',$encounters);
    snapshot_assert(str_contains($html,'name="assignment_version"') && str_contains($html,'gm_pool') && str_contains($html,'NPC &lt;unsafe&gt;') && !str_contains($html,'NPC <unsafe>'),'Controller UI missing mode/CAS/escaping');
    snapshot_assert($capture()===$unchanged,'GM GET wrote state');
    $reset();$unchanged=$capture();
    $link->query("CREATE TRIGGER npc_fail_audit BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture late audit failure'");
    itemFail(fn()=>$set('exclusive',['8']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER npc_fail_audit');
    snapshot_assert($capture()===$unchanged,'Assignment/audit failure did not roll back together');
    $request=['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'entity_id'=>$source,'assignment_version'=>$shared['assignment_version'],'mode'=>'shared','uids'=>['7','8'],'operation_key'=>'npc_idempotent_assign_01','reason'=>'Confirmed retry'];
    $retry=$encounters->command('controller_set',$request);$unchanged=$capture();snapshot_assert($encounters->command('controller_set',$request)===$retry && $capture()===$unchanged,'Assignment retry duplicated history');
    $reset();
    // Real Draft/Seal/Writer for two assigned GM on the same pending NPC turn.
    $draws=0;$seal=new AF_ACS_SealService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){++$draws;return 0;}));$drafts=new AF_ACS_DraftService($repo,$access);
    $catalog=(new AF_ACS_QueryService($repo,$access))->builderCatalog($sid,$source);snapshot_assert(!empty($catalog[0]['supported']),'NPC catalog unsupported '.json_encode($catalog));
    $actions=[['actor_id'=>$source,'ability_key'=>'npc-strike','targets'=>[$target['entity_id']],'parameters'=>[]]];
    $prepare=static function(int $uid)use($mybb,$drafts,$seal,$sid,$eid,$turnId,$actions,&$seq):array {
        $mybb->user['uid']=$uid;$d=$drafts->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'expected_draft_version'=>'0','operation_key'=>'npc_draft_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT),'actions'=>$actions,'prose'=>'NPC prose']);
        $v=$seal->command('seal',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],'operation_key'=>'npc_seal_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT)]);snapshot_assert(!$v['blocked'],'NPC seal blocked '.json_encode($v));
        $v=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$v['draft_version'],'operation_key'=>'npc_confirm_'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT),'confirmation_hash'=>$v['confirmation_hash']]);return $v;
    };
    $a=$prepare(7);$b=$prepare(8);snapshot_assert($repo->sealQuota($turnId)===2,'Shared controllers got separate seal quotas');
    $mybb->user['uid']=7;$tape=$capture(['rolls','sealed_actions']);$set('shared',['7','8']);
    snapshot_assert(!$seal->status($a['draft_id'])['confirmed'],'Stale assignment confirmation remained current');
    snapshot_assert($capture(['rolls','sealed_actions'])===$tape && $draws===4,'Reassignment rerolled or refunded seals');
    // Reconfirming uses exactly the retained tape. Restore a clean shared generation for contention.
    $reset();$draws=0;$a=$prepare(7);$b=$prepare(8);
    $store=new AF_ACS_SubmissionStore($db);$writer=new AF_ACS_CombatCommitWriter($db,$access);
    $issue=static function(int $uid,array $v,int $pid)use($mybb,$store,$writer,$link):array {
        $mybb->user['uid']=$uid;if($pid!==501)itemInsert($link,'posts',['pid'=>$pid,'uid'=>$uid,'tid'=>51,'fid'=>2,'visible'=>1,'message'=>'Native fixture']);
        $r=$store->issue(['schema_version'=>1,'draft_id'=>$v['draft_id'],'author_uid'=>(string)$uid,'expected_draft_version'=>$v['draft_version'],'confirmation_hash'=>$v['confirmation_hash']],fn()=>null);
        $r=$store->validating($r['submission_id'],$r['state_version'],fn()=>null);$p=$writer->validatePublication($r['submission_id'],$r['state_version']);
        $r=$store->posting($r['submission_id'],$r['state_version'],array_intersect_key($p,array_flip(['dependency_hash','simulation_hash','confirmation_hash'])),fn()=>null);return $store->confirmPost($r['submission_id'],$r['state_version'],(string)$pid,fn()=>null,'2');
    };
    $one=$issue(7,$a,501);
    $secondIntent=['schema_version'=>1,'draft_id'=>$b['draft_id'],'author_uid'=>'8','expected_draft_version'=>$b['draft_version'],'confirmation_hash'=>$b['confirmation_hash']];
    $mybb->user['uid']=8;itemFail(fn()=>$store->issue($secondIntent,fn()=>null),'ACS_PUBLICATION_UNRESOLVED');
    $rival=new mysqli('localhost','root','',$database,0,$socket);$otherWriter=new AF_ACS_CombatCommitWriter(new InventoryFixtureDatabase($rival),$access);$otherStore=new AF_ACS_SubmissionStore(new InventoryFixtureDatabase($rival));
    $race=new class('localhost','root','',$database,0,$socket) extends mysqli {public ?Closure $beforeBegin=null;public function begin_transaction(int $flags=0,?string $name=null):bool {if($cb=$this->beforeBegin){$this->beforeBegin=null;$cb();}return parent::begin_transaction($flags,$name);}};
    $raceWriter=new AF_ACS_CombatCommitWriter(new InventoryFixtureDatabase($race),$access);$contended=false;$mybb->user['uid']=7;
    $race->beforeBegin=static function()use($mybb,$otherStore,$secondIntent,&$contended):void {$mybb->user['uid']=8;try{itemFail(fn()=>$otherStore->issue($secondIntent,fn()=>null),'ACS_MAINTENANCE_BUSY');$contended=true;}finally{$mybb->user['uid']=7;}};
    $before=$capture($mechanical);
    $link->query("CREATE TRIGGER npc_fail_terminal BEFORE INSERT ON t_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture terminal rollback'");
    itemFail(fn()=>$raceWriter->commit($one['submission_id'],$one['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER npc_fail_terminal');
    snapshot_assert($capture($mechanical)===$before && $repo->draft($b['draft_id'],'8')['status']==='sealed','Failed terminal commit retired the competing plan');
    $receipt=$raceWriter->commit($one['submission_id'],$one['state_version']);snapshot_assert($contended && $receipt['status']==='COMMITTED','Concurrent winner failed');
    $after=$capture($mechanical);snapshot_assert($writer->commit($one['submission_id'],$one['state_version'])===$receipt,'Duplicate commit changed receipt');$mybb->user['uid']=8;
    itemFail(fn()=>$otherStore->issue($secondIntent,fn()=>null),'ACS_SEALED_SUBMISSION_REQUIRED');
    itemFail(fn()=>$otherWriter->commit($one['submission_id'],$one['state_version']),'ACS_COMBAT_PERMISSION_DENIED');
    snapshot_assert($capture($mechanical)===$after && $draws===4,'Losing GM changed mechanics/tape');
    snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_acs_combat_receipts')->fetch_row()[0]==1 && $repo->clockTurn($sid,$turnId)['status']==='resolved' && (string)$repo->clockTurn($sid,$turnId)['ap_spent']==='1','Shared action duplicated or AP budget changed');
    $mybb->user['uid']=8;$loser=$drafts->restore($b['draft_id']);snapshot_assert($loser['prose']==='NPC prose' && !$loser['editable'] && $loser['status']==='cancelled','Losing plan/prose was not retained read-only');
    $repo->assertNoObligations($sid,$eid,$turnId);
    $reset();$set('gm_pool',[]);$v=$prepare(8);$poolSubmission=$issue(8,$v,503);
    $before=$capture($mechanical);$race->beforeBegin=static function()use($rival,$source):void {$rival->query('UPDATE t_af_acs_controllers SET assignment_version=assignment_version+1 WHERE entity_id='.$source." AND status='active'");};
    itemFail(fn()=>$raceWriter->commit($poolSubmission['submission_id'],$poolSubmission['state_version']),'ACS_COMBAT_DEPENDENCY_CONFLICT');
    snapshot_assert($capture($mechanical)===$before && $writer->receipt($poolSubmission['submission_id'])===null,'Assignment race applied stale mechanics');
    $reset();$set('gm_pool',[]);$v=$prepare(8);$poolSubmission=$issue(8,$v,504);
    $poolReceipt=$writer->commit($poolSubmission['submission_id'],$poolSubmission['state_version']);
    snapshot_assert($poolReceipt['status']==='COMMITTED' && (string)$repo->clockTurn($sid,$turnId)['ap_spent']==='1','gm_pool publication failed');
    $mybb->user['uid']=7;$before=$capture($mechanical);$set('exclusive',['7']);
    snapshot_assert($capture($mechanical)===$before && $repo->clockTurn($sid,$turnId)['status']==='resolved','Reassignment reopened a resolved turn');
    echo "ACS NPC controllers: exclusive/shared/gm_pool, native+role scope, assignment CAS/audit, immutable NPC identity/roster/resources/tape, stale confirmation and two-GM SQL contention passed\n";
};
require __DIR__.'/acs_combat_items_sql.php';
