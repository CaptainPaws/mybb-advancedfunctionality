<?php
/** A1.27 after a real paid prepared child, not the primary's intermediate state. */
$GLOBALS['acsPreparedPhaseBeforeParent']=static function(array $f):void {
    extract($f);global $mybb;
    $service=new AF_ACS_EncounterService($repo,$access);
    $link->query("UPDATE t_af_acs_encounters SET status='preparation',round_number=0 WHERE id=1");
    $service->command('end_conditions_set',['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'reason'=>'Prepared end condition fixture','operation_key'=>'end_prepared_policy01','end_conditions'=>['schema'=>AF_ACS_EndCondition::SCHEMA,'conditions'=>['last_active_side','no_active_sides'],'allow_continuation'=>false,'mutual_outcomes'=>['inconclusive']]]);
    $link->query("UPDATE t_af_acs_encounters SET status='active',round_number=1 WHERE id=1");
    $link->query('UPDATE t_af_acs_entity_states SET hp=5 WHERE entity_id='.$actor);$link->query("UPDATE t_af_acs_resources SET current_value=5 WHERE resource_key='hp' AND entity_id=".$actor);
};
$GLOBALS['acsPreparedPhaseAssert']=static function(array $f):void {
    extract($f);$before=$captureAll();
    $link->query("CREATE TRIGGER end_prepared_fail BEFORE INSERT ON t_af_acs_encounter_resolutions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='prepared end rollback'");itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER end_prepared_fail');
    $armed=$captureAll();foreach(['entity_states','resources','inv_items','inv_reservations','encounter_resolutions','combat_results','combat_receipts'] as $t)snapshot_assert($armed[$t]===$before[$t],'Prepared end rollback '.$t);
    $receipt=$writer->commit($parent['submission_id'],$parent['state_version']);$after=$captureAll();$pending=$repo->pendingResolution($sid,'1');
    snapshot_assert($pending['condition']==='last_active_side'&&$pending['winner_side_id']==='2'&&$repo->encounter($sid,'1')['status']==='pending_resolution','Prepared child did not precede end check');
    $execution=json_decode($after['prepared_executions'][0]['result_json'],true);snapshot_assert($execution['outcome']==='executed'&&$repo->entity($sid,$actor)['life_state']==='incapacitated','No prepared mechanical child');
    $r=['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'operation_key'=>'end_prepared_continue1','reason'=>'Continuation forbidden by pinned rules','resolution_id'=>$pending['resolution_id']];
    itemFail(fn()=>(new AF_ACS_EncounterService($repo,$access))->command('outcome_continue',$r),'ACS_END_CONTINUATION_DENIED');
    snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$receipt&&$captureAll()===$after,'Prepared final blow repeated');
    if(isset($GLOBALS['acsStage10AdvancedFixture']))($GLOBALS['acsStage10AdvancedFixture'])(get_defined_vars());
    echo "ACS end prepared SQL: primary plus paid causal child before pending, no second item consume, late rollback, forbidden continuation and fixed replay passed\n";
};
require __DIR__.'/acs_prepared_execution_sql.php';
