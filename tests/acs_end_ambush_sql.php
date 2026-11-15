<?php
/** End check after the existing fully confirmed encounter-start causal transaction. */
$GLOBALS['acsAmbushApiFixture']=static function(array $f):void {
    extract($f);global $mybb;
    $sides=$repo->sides($eid);$opposition=$change('side_create',['name'=>'End fixture opponent']);
    $change('participant_side',['entity_id'=>$npcId,'side_id'=>$opposition['side_id']]);
    $change('relation_set',['side_a'=>$sides[0]['id'],'side_b'=>$opposition['side_id'],'relationship'=>'hostile']);
    $encounters->command('end_conditions_set',['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'reason'=>'End of paid ambush fixture','operation_key'=>'end_ambush_policy01','end_conditions'=>['schema'=>AF_ACS_EndCondition::SCHEMA,'conditions'=>['last_active_side','no_active_sides'],'allow_continuation'=>false,'mutual_outcomes'=>['inconclusive']]]);
    $link->query('UPDATE t_af_acs_entity_states SET hp=1 WHERE entity_id='.$npcId);$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp' AND entity_id=".$npcId);
    $ambush->command('ready',$ambushRequest());$r=$ambushRequest();$before=$captureAll();
    $result=$ambush->command('start',$r);$after=$captureAll();$pending=$repo->pendingResolution($sid,$eid);
    snapshot_assert($result['status']==='pending_resolution'&&$pending&&$repo->encounter($sid,$eid)['status']==='pending_resolution','Ambush did not reach pending');
    snapshot_assert($repo->entity($sid,$npcId)['life_state']==='incapacitated'&&!$repo->confirmedOutcome($sid,$eid),'Ambush falsely completed or killed');
    snapshot_assert($result['prepared_executions'][0]['outcome']==='executed'&&count($result['prepared_executions'][0]['owner_receipts'])===2,'Ambush skipped owner child');
    snapshot_assert($ambush->command('start',$r)===$result&&$captureAll()===$after,'Ambush ending replay changed state');
    snapshot_assert(count($after['rounds'])===1&&$after['native_posts']===$before['native_posts'],'Ambush end created post/round');
    echo "ACS end ambush SQL: paid start child/items before pending, one roster, immutable start receipt and replay passed\n";
};
require __DIR__.'/acs_ambush_execution_sql.php';
