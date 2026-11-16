<?php
/** Focused disposable fixture, real combat/outcome, IC-BALANCE and appended review.
 * Second combat terminal record is an explicitly seeded coherent SQL fixture. */
function is_moderator(...$args):bool{return ($GLOBALS['achievement_native_moderator']??true)&&($args[2]??7)===7;}
$GLOBALS['acsAchievementConfigureFixture']=static function(array $f):void {
    extract($f);global $db,$mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/AchievementService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/CorrectionService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/AchievementController.php';
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');$ach=new AF_ACS_AchievementService($db,$access);$definitions=[];
    $recipes=['once'=>['one_time',1,'control_group','visible',['reward_type'=>'experience','amount_minor'=>10]],'progress'=>['progressive',2,'owner','visible',null],'repeat'=>['repeatable',1,'control_group','visible',['reward_type'=>'currency','amount_minor'=>3]],'secret'=>['progressive',3,'actor','hidden_until_unlocked',null],'gm'=>['one_time',1,'owner','gm_only',null],'unlocked'=>['one_time',1,'owner','hidden_until_unlocked',null],'completed'=>['one_time',1,'control_group','visible',null]];
    foreach($recipes as $key=>[$mode,$threshold,$subject,$visibility,$reward]){
        $timing=$key==='completed'?'on_encounter_completed':'on_confirmed_event';
        $d=['schema'=>AF_ACS_Achievements::SCHEMA,'achievement_key'=>'ach-'.$key,'name'=>'Achievement '.$key,'mode'=>$mode,'timing'=>$timing,'subject'=>$subject,'visibility'=>$visibility,'threshold'=>$threshold,'identity_policy'=>'pinned','condition'=>['kind'=>'confirmed_count','selection'=>$timing==='on_confirmed_event'?'primary_actors':'registered_participants','entity_kinds'=>['character','pet','summon'],'outcomes'=>$timing==='on_encounter_completed'?['victory']:[]],'reward'=>$reward];
        if($key==='once')unset($d['subject']); // Schema default is control_group.
        $kb='ach-'.$key;$rules=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','version'=>'1','service_kind'=>'combat_template','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>$kb,'version'=>'1','phases'=>[],'limits'=>[],'formula_refs'=>[],'achievement'=>$d]]];
        itemInsert($link,'af_kb_entries',snapshot_entry(2400+count($definitions),'arpg_mechanics',$kb,$rules));$definitions[$key]=[$d,$rules];
        $ach->command('achievement_configure',$request('achievement_pin_'.str_pad($key,16,'x'))+['kb_key'=>$kb]);
    }
    snapshot_assert((int)$link->query('SELECT COUNT(*) FROM t_af_acs_achievement_progress')->fetch_row()[0]===0,'Configure/preview/seal awarded progress');
    // Future encounter has the same identities, with an explicit compatible V2 for once.
    $e=$link->query('SELECT * FROM t_af_acs_encounters WHERE id=1')->fetch_assoc();unset($e['id']);$e['start_operation_id']=null;$c=json_decode($e['config_json'],true);unset($c['achievement_sources']);$e['config_json']=AF_ACS_Repository::json($c);itemInsert($link,'af_acs_encounters',$e);$future=(string)$link->insert_id;$GLOBALS['achievementFuture']=$future;
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    foreach($definitions as $key=>[$d,$rules]){
        $kb='ach-'.$key;
        if($key==='once'){$kb='ach-once-v2';$d['identity_policy']='compatible_carry';$rules['version']='2';$rules['entries'][0]['key']=$kb;$rules['entries'][0]['version']='2';$rules['entries'][0]['achievement']=$d;itemInsert($link,'af_kb_entries',snapshot_entry(2499,'arpg_mechanics',$kb,$rules));}
        $r=$request('achievement_future_'.str_pad($key,16,'x'));$r['encounter_id']=$future;$r['encounter_version']=$repo->encounter($sid,$future)['encounter_version'];$ach->command('achievement_configure',$r+['kb_key'=>$kb]);
    }
    $GLOBALS['achievementDefinitions']=$definitions;$GLOBALS['achievement_pre_count']=0;
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
};
$GLOBALS['acsAchievementEvaluationFixture']=static function(array $f):void {
    extract($f);global $db,$mybb;
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    $ach=new AF_ACS_AchievementService($db,$access);$correction=new AF_ACS_CorrectionService($db,$access);$future=$GLOBALS['achievementFuture'];
    $source=(string)$link->query("SELECT id FROM t_af_acs_events WHERE event_type='combat.resolved' AND encounter_id=1 ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    $outcome=$repo->confirmedOutcome($sid,'1');$terminal=$outcome['terminal_event_id'];
    $r=static fn($key,$eid='1')=>['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Achievement SQL evidence'];
    $evalRequests=[];$eval=static function($key,$event,$op,$eid='1')use($ach,$r,&$evalRequests){$q=$evalRequests[$op]??($r($op,$eid)+['achievement_key'=>'ach-'.$key,'source_event_id'=>$event]);$evalRequests[$op]=$q;return $ach->command('achievement_evaluate',$q);};
    $before=$captureAll();$earned=[];
    $invalid=(string)$link->query("SELECT id FROM t_af_acs_events WHERE event_type<>'combat.resolved' AND event_type<>'encounter.outcome_confirm' LIMIT 1")->fetch_row()[0];
    itemFail(fn()=>$eval('once',$invalid,'achievement_invalid_source01'),'ACS_ACHIEVEMENT_SOURCE_INVALID');
    $link->query("CREATE TRIGGER achievement_fault BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='achievement rollback'");itemFail(fn()=>$eval('once',$source,'achievement_once_rollback01'),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER achievement_fault');
    snapshot_assert((int)$link->query('SELECT COUNT(*) FROM t_af_acs_achievement_events')->fetch_row()[0]===0,'Achievement rollback leaked contribution/reward');
    foreach($GLOBALS['achievementDefinitions'] as $key=>$definition){
        $event=$key==='completed'?$terminal:$source;$reply=$eval($key,$event,'achievement_eval_'.str_pad($key,16,'x'));$earned[$key]=$reply;
        $snap=$captureAll();snapshot_assert($eval($key,$event,'achievement_eval_'.str_pad($key,16,'x'))===$reply&&$captureAll()===$snap,'Same operation replay changed history');
        snapshot_assert($eval($key,$event,'achievement_newkey_'.str_pad($key,16,'x'))===$reply,'Duplicate source event created contribution');
    }
    snapshot_assert(count($earned['once']['contributions'])===1&&count($earned['completed']['contributions'])===2,'Group/pet contribution dedupe');
    $onceId=(string)$link->query("SELECT id FROM t_af_acs_achievement_progress WHERE achievement_key='ach-once'")->fetch_row()[0];
    // Explicit SQL seed of a second confirmed terminal record; no Resolver rerun/roll change.
    $ev=$link->query('SELECT * FROM t_af_acs_events WHERE id='.$source)->fetch_assoc();$oldSubmission=$ev['submission_id'];$newSubmission='fixture-achievement-second';unset($ev['id']);$ev['encounter_id']=$future;$ev['session_sequence']=$repo->session($sid)['next_sequence'];$ev['event_sequence']=100;$ev['operation_key']='combat:'.$newSubmission;$ev['submission_id']=$newSubmission;itemInsert($link,'af_acs_events',$ev);$second=(string)$link->insert_id;
    $link->query('UPDATE t_af_acs_sessions SET next_sequence=next_sequence+1 WHERE id='.$sid);
    $details=$link->query('SELECT * FROM t_af_acs_event_details WHERE event_id='.$source)->fetch_assoc();$body=json_decode($details['payload_json'],true);$body['submission_id']=$newSubmission;$body['achievement_context']['config_snapshot_id']=$repo->encounter($sid,$future)['config_snapshot_id'];$petStep=$body['plan']['actions'][0];$petStep['actor_id']=$GLOBALS['rewardPet'];$body['plan']['actions'][]=$petStep;$payload=AF_ACS_Repository::json($body);$hash=hash('sha256',$payload);$details['event_id']=$second;$details['payload_json']=$payload;$details['content_hash']=hash('sha256',$payload."\n".$details['visibility_json']);itemInsert($link,'af_acs_event_details',$details);
    $sub=$link->query("SELECT * FROM t_af_acs_submissions WHERE submission_id='".$oldSubmission."'")->fetch_assoc();$sub['submission_id']=$newSubmission;$sub['encounter_id']=$future;$sub['turn_id']=9999;$sub['pid']=9999;$sub['active_turn_id']=null;itemInsert($link,'af_acs_submissions',$sub);
    $cr=$link->query("SELECT * FROM t_af_acs_combat_results WHERE submission_id='".$oldSubmission."'")->fetch_assoc();$cr['submission_id']=$newSubmission;$cr['turn_id']=9999;$cr['event_id']=$second;$cr['payload_json']=$payload;$cr['result_hash']=$hash;itemInsert($link,'af_acs_combat_results',$cr);
    $receipt=$link->query("SELECT * FROM t_af_acs_combat_receipts WHERE submission_id='".$oldSubmission."'")->fetch_assoc();$rc=json_decode($receipt['receipt_json'],true);$rc['submission_id']=$newSubmission;$rc['turn_id']='9999';$rc['pid']='9999';$rc['event_id']=$second;$rc['result_hash']=$hash;$receipt['submission_id']=$newSubmission;$receipt['turn_id']=9999;$receipt['pid']=9999;$receipt['receipt_json']=AF_ACS_Repository::json($rc);$receipt['receipt_hash']=hash('sha256',$receipt['receipt_json']);itemInsert($link,'af_acs_combat_receipts',$receipt);
    $op=$link->query("SELECT * FROM t_af_acs_operations WHERE operation_key='combat:".$oldSubmission."'")->fetch_assoc();$op['operation_key']='combat:'.$newSubmission;$op['encounter_id']=$future;$op['request_json']=AF_ACS_Repository::json(['schema_version'=>1,'actor_uid'=>'7','submission_id'=>$newSubmission,'request_hash'=>$sub['request_hash']]);$op['payload_hash']=hash('sha256',$op['request_json']);$op['result_json']=AF_ACS_Repository::json(['schema_version'=>1,'session_id'=>$sid,'boundary_key'=>'combat:'.$newSubmission]+$rc);itemInsert($link,'af_acs_operations',$op);
    itemFail(fn()=>$eval('once',$second,'achievement_version_denied01',$future),'ACS_ACHIEVEMENT_DEFINITION_MIGRATION_REQUIRED');
    $p=$repo->achievementProgress($onceId);$migration=$ach->command('achievement_migrate',$r('achievement_migrate_once01',$future)+['achievement_key'=>'ach-once','progress_id'=>$onceId,'expected_progress_version'=>$p['progress_version']]);snapshot_assert($migration['awards_preserved']===1,'Version migration reset earned identity');
    foreach(['once','progress','repeat','secret','gm','unlocked'] as $key)$earned[$key.'2']=$eval($key,$second,'achievement_second_'.str_pad($key,16,'x'),$future);
    snapshot_assert($repo->achievementProgress($onceId)['progress']['awards']===1&&$earned['once2']['reward_ids']===[],'KB version duplicated one_time award');
    $progress=(string)$link->query("SELECT id FROM t_af_acs_achievement_progress WHERE achievement_key='ach-progress'")->fetch_row()[0];snapshot_assert($repo->achievementProgress($progress)['progress']['review_count']===2&&$repo->achievementProgress($progress)['progress']['awards']===1,'Progressive did not increment/unlock');
    $repeat=(string)$link->query("SELECT id FROM t_af_acs_achievement_progress WHERE achievement_key='ach-repeat'")->fetch_row()[0];snapshot_assert($repo->achievementProgress($repeat)['progress']['awards']===2&&count($earned['repeat2']['contributions'])===1,'Repeatable threshold or pet dedupe');
    // Explicit foreign-scope permission seed: terminal replay must not disclose global before/after.
    $foreign=$link->query('SELECT * FROM t_af_acs_sessions WHERE id='.$sid)->fetch_assoc();unset($foreign['id']);$foreign['owner_uid']=8;itemInsert($link,'af_acs_sessions',$foreign);$foreignSid=(string)$link->insert_id;
    itemInsert($link,'af_acs_session_roles',['session_id'=>$foreignSid,'uid'=>8,'role'=>'owner','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $scopeSeed=$link->query('SELECT * FROM t_af_acs_achievement_events WHERE progress_id='.$onceId.' LIMIT 1')->fetch_assoc();unset($scopeSeed['id']);$scopeSeed['session_id']=$foreignSid;$scopeSeed['encounter_id']=99999;$scopeSeed['source_event_id']=99999;$scopeSeed['progress_key']='fixture:foreign-scope';$scopeSeed['event_id']=null;itemInsert($link,'af_acs_achievement_events',$scopeSeed);$scopeSeedId=(string)$link->insert_id;
    itemFail(fn()=>$eval('once',$source,'achievement_eval_'.str_pad('once',16,'x')),'ACS_GM_REQUIRED');
    itemFail(fn()=>$eval('once',$source,'achievement_foreign_replay01'),'ACS_GM_REQUIRED');
    snapshot_assert(!in_array('ach-once',array_column($ach->view($sid)['achievements'],'achievement_key'),true),'GM view exposed foreign hidden progress');
    $link->query('DELETE FROM t_af_acs_achievement_events WHERE id='.$scopeSeedId); // Remove only this temporary test permission seed.
    // A player sees no secret progress, gm_only rows, hidden counters or raw proofs.
    $link->query("UPDATE t_af_acs_session_roles SET role='player' WHERE session_id=".$sid.' AND uid=7');
    itemFail(fn()=>$ach->view($sid,'1'),'ACS_ACHIEVEMENT_INPUT_INVALID');
    itemFail(fn()=>$eval('once',$source,'achievement_eval_'.str_pad('once',16,'x')),'ACS_GM_REQUIRED');
    $player=AF_ACS_AchievementController::handle($db,$access,'achievement_status',['session_id'=>$sid],'get');$json=json_encode($player);
    snapshot_assert(!str_contains($json,'ach-secret')&&!str_contains($json,'ach-gm')&&str_contains($json,'ach-unlocked')&&!str_contains($json,'progress_id')&&array_keys($player)===['achievements'],'Hidden achievement DTO/counter leak');
    $link->query("UPDATE t_af_acs_session_roles SET role='owner' WHERE session_id=".$sid.' AND uid=7');
    // All achievement rewards go through the existing RewardService and actual Balance owner.
    $rewardIds=[];foreach($earned as $reply)$rewardIds=array_merge($rewardIds,$reply['reward_ids']);$rewardIds=array_values(array_unique($rewardIds));$balanceBefore=af_balance_get(7);
    $reward=new AF_ACS_RewardService($db,$access);$link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    foreach($rewardIds as $id){$state=$reward->inspect($sid,$id);$q=['session_id'=>$sid,'reward_id'=>$id,'expected_version'=>$repo->session($sid)['session_version'],'expected_reward_version'=>$state['reward_version'],'operation_key'=>'achievement_issue_'.str_pad($id,16,'0',STR_PAD_LEFT),'reason'=>'Achievement immutable award'];$issued=$reward->issue($q);snapshot_assert($issued['status']==='issued'&&$reward->issue($q)===$issued,'Achievement reward duplicate credit');}
    $balance=af_balance_get(7);snapshot_assert($balance['exp']===$balanceBefore['exp']+10&&$balance['credits']===$balanceBefore['credits']+6,'Wrong one_time/repeatable reward totals');
    $stable=$captureAll();$property=['balance'=>$link->query('SELECT * FROM t_af_balance ORDER BY uid')->fetch_all(MYSQLI_ASSOC),'rewards'=>$link->query('SELECT * FROM t_af_acs_rewards ORDER BY id')->fetch_all(MYSQLI_ASSOC),'progress_events'=>$link->query('SELECT * FROM t_af_acs_achievement_events ORDER BY id')->fetch_all(MYSQLI_ASSOC)];
    $plan=['schema'=>'ic-acs.correction-review.v1','disposition'=>'exclude','recipient_contract'=>'acs.derived-review.v1','compensation'=>'balance'];$requestCorrection=$r('achievement_correction01')+['original_event_id'=>$source,'expected_review_version'=>'0','plan'=>$plan];
    $GLOBALS['achievement_native_moderator']=false;itemFail(fn()=>$correction->command($requestCorrection),'ACS_CORRECTION_PERMISSION_REQUIRED');$GLOBALS['achievement_native_moderator']=true;
    $link->query("CREATE TRIGGER correction_fault BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='correction rollback'");itemFail(fn()=>$correction->command($requestCorrection),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER correction_fault');snapshot_assert($captureAll()===$stable&&(int)$link->query('SELECT COUNT(*) FROM t_af_acs_correction_reviews')->fetch_row()[0]===0,'Correction rollback changed original or progress');
    $corrected=$correction->command($requestCorrection);$revision=$corrected['review'];snapshot_assert($revision['review_version']==='1'&&$revision['aggregate']['included_event_count']===0&&$revision['compensation_status']==='blocked','Correction aggregate revision/compensation barrier');
    $post=$captureAll();snapshot_assert($correction->command($requestCorrection)===$corrected&&$captureAll()===$post,'Correction replay appended twice');
    snapshot_assert($repo->achievementProgress($onceId)['progress']['awards']===1&&$repo->achievementProgress($progress)['progress']['review_count']===1,'Correction erased achievement or did not review aggregate');
    $include=$r('achievement_correction_restore01')+['original_event_id'=>$source,'expected_review_version'=>'1','plan'=>array_replace($plan,['disposition'=>'include','compensation'=>'none'])];$restored=$correction->command($include);snapshot_assert($restored['review']['review_version']==='2'&&$restored['review']['previous_review_id']===$revision['review_id'],'Append-only aggregate revision chain');
    $p=$repo->achievementProgress($progress);$ach->command('achievement_review',$r('achievement_review_accept01')+['progress_id'=>$progress,'expected_progress_version'=>$p['progress_version']]);
    foreach(['balance'=>'SELECT * FROM t_af_balance ORDER BY uid','rewards'=>'SELECT * FROM t_af_acs_rewards ORDER BY id','progress_events'=>'SELECT * FROM t_af_acs_achievement_events ORDER BY id'] as $key=>$sql)snapshot_assert($property[$key]===$link->query($sql)->fetch_all(MYSQLI_ASSOC),'Automatic clawback/reaward/history mutation '.$key);
    $after=$captureAll();foreach(['combat_results','combat_receipts','sealed_actions','rolls','entity_states','resources','snapshots','inv_items','loot_pools','loot_entries','turns','entity_turns','cooldowns'] as $table)snapshot_assert($stable[$table]===$after[$table],'Correction rewrote '.$table);
    $oldEvents=array_column($after['events'],null,'id');foreach($stable['events'] as $event)snapshot_assert($oldEvents[$event['id']]===$event,'Original event changed');
    $obligations=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert(array_filter($obligations['explicitly_blocked'],fn($x)=>$x['source']==='compensation')&&array_filter($obligations['pending'],fn($x)=>$x['source']==='achievement_review'),'Review/blocked compensation obligations disappeared');
    snapshot_assert($repo->session($sid)['status']==='archived','Achievement/correction reopened archived session');$migrations->run();
    if(isset($GLOBALS['acsArchiveFixture']))($GLOBALS['acsArchiveFixture'])(get_defined_vars());
    echo "ACS achievements/reviews SQL: one_time/progressive/repeatable, confirmed timing, group/pet, hidden DTO, explicit version migration, real reward once, append-only correction/revision, blocked clawback, rollback/replay and archive passed\n";
};
require __DIR__.'/acs_reward_distribution_sql.php';
