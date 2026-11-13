<?php
/** Actual owner-pinned registered reaction and prepared action share one phase queue. */
$GLOBALS['acsPreparedExecutionPhase']=$argv[3]??'after_damage';
$GLOBALS['acsPreparedPhaseDefinitions']=static function(array &$payload,array $fixture):void {
    extract($fixture);$priority=(int)($argv[2]??99);$phase=$argv[4]??$GLOBALS['acsPreparedExecutionPhase'];
    $a=af_kb_default_type_profile_payload_arpg('arpg_ability');$a=array_replace($a,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>'self','duration_unit'=>'own_turn','cooldown_unit'=>'own_turn','max_charges'=>0,'effects'=>[['kind'=>'heal','value'=>3,'can_crit'=>false]]]);
    itemInsert($link,'af_kb_entries',snapshot_entry(990,'arpg_ability','phase-heal',$a));
    $t=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'phase-heal-trigger','version'=>'1','event'=>$phase,'priority'=>$priority,'condition_ref'=>null,'effect_ref'=>['type'=>'arpg_ability','key'=>'phase-heal','effect_index'=>0],'limits'=>['per_round'=>1],'formula_refs'=>[]];
    itemInsert($link,'af_kb_entries',snapshot_entry(991,'arpg_mechanics','phase-heal-trigger',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'trigger_template','entries'=>[$t]]));
    $issues=[];$grant=af_kb_arpg_acs_reference('arpg_ability','phase-heal','fixture',$issues);snapshot_assert(!$issues,'Reaction owner grant');
    $payload['pinned_abilities']['phase-heal']=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','phase-heal',7);
    $payload['source_snapshot']['abilities'][]=['definition'=>['references'=>['ability_kb_key'=>$grant]]];
};
$GLOBALS['acsPreparedPhaseBeforeParent']=static function(array $fixture):void {
    extract($fixture);require_once AF_ADDONS.'advancedcombatsystem/modules/Application/ReactionService.php';
    (new AF_ACS_ReactionService($repo,$access))->command('register',['session_id'=>$sid,'encounter_id'=>'1','entity_id'=>$actor,'trigger_key'=>'phase-heal-trigger','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'operation_key'=>'phase_reaction_register1','reason'=>'Actual causal priority fixture']);
};
$GLOBALS['acsPreparedPhaseAssert']=static function(array $fixture):void {
    extract($fixture);
    $before=$captureAll();$r=$writer->commit($parent['submission_id'],$parent['state_version']);$after=$captureAll();
    $payload=json_decode($link->query("SELECT payload_json FROM t_af_acs_combat_results WHERE submission_id='".$parent['submission_id']."'")->fetch_row()[0],true);
    $trace=$payload['plan']['actions'][0]['reactions'];snapshot_assert(count($trace)===2,'Missing phase participants');
    $expected=(int)($argv[2]??99)<100?['reaction','prepared']:['prepared','reaction'];
    if(isset($argv[4])){$phases=AF_ACS_Effects::REACTION_PHASES;$expected=array_search($argv[4],$phases,true)<array_search($GLOBALS['acsPreparedExecutionPhase'],$phases,true)?['reaction','prepared']:['prepared','reaction'];}
    snapshot_assert(array_map(static fn($e)=>$e['queue_kind']??'reaction',$trace)===$expected,'Wrong shared priority/time order '.json_encode($trace));
    $execution=json_decode($after['prepared_executions'][0]['result_json'],true);snapshot_assert($execution['outcome']==='executed'&&count($execution['owner_receipts'])===1,'Prepared not executed');
    snapshot_assert($repo->individualTurns(['1'])[0]['reaction_used']==='1','Ordinary reaction not charged separately');
    snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$r&&$captureAll()===$after,'Order changed at replay');
    // IDs beyond float precision: same priority and same registration order use decimal length/lexical comparison.
    $a=['priority'=>100,'registered_order'=>'18446744073709551600','id'=>'9007199254740993'];$b=$a;$b['id']='9007199254740992';
    snapshot_assert(AF_ACS_PreparedPhase::compare($a,$b)>0,'BIGINT IDs lost precision');$b['registered_order']='18446744073709551601';snapshot_assert(AF_ACS_PreparedPhase::compare($a,$b)<0,'BIGINT registration lost precision');
    echo 'ACS prepared phase order: '.$GLOBALS['acsPreparedExecutionPhase'].', priority '.($argv[2]??99).", actual registered reaction/prepared order, independent budgets, BIGINT ties and replay passed\n";
};
require __DIR__.'/acs_prepared_execution_sql.php';
