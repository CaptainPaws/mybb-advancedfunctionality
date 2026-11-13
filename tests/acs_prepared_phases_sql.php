<?php
/** Authoritative MySQL phase/interleaving extension of the existing owner fixture. */
$GLOBALS['acsPreparedExecutionLegacy']=($argv[2]??'')==='legacy';
$GLOBALS['acsPreparedExecutionPhase']=$argv[3]??'after_damage';
$GLOBALS['acsPreparedPhaseAssert']=static function(array $fixture):void {
    extract($fixture);global $db;
    $before=$captureAll();
    $link->query("CREATE TRIGGER phase_late BEFORE INSERT ON t_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='phase rollback'");
    itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER phase_late');
    $armed=$captureAll();snapshot_assert($armed['entity_states']===$before['entity_states']&&$armed['resources']===$before['resources']&&$armed['inv_items']===$before['inv_items']&&$armed['turns']===$before['turns'],'Interleaved rollback leaked');
    snapshot_assert(count($armed['prepared_executions'])===1,'No per-primary-target armed cause');
    $saved=json_decode($armed['prepared_executions'][0]['result_json'],true);
    if(($argv[2]??'')==='legacy'){
        snapshot_assert($saved['policy']===null&&$saved['tape']===null,'Legacy contract acquired implicit phase/tape');$r=$writer->commit($parent['submission_id'],$parent['state_version']);$after=$captureAll();$e=json_decode($after['prepared_executions'][0]['result_json'],true);
        snapshot_assert($e['outcome']==='skipped'&&$e['reason']==='ACS_PREPARED_UNSUPPORTED'&&$e['phase_event']===null&&$e['terminal_state']==='active','Legacy contract executed or cancelled');snapshot_assert($after['inv_items']===$before['inv_items']&&$after['rolls']===$before['rolls'],'Legacy costs or RNG');snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$r&&$captureAll()===$after,'Legacy replay changed state');echo "ACS prepared phases: legacy no-execution contract remains diagnostic UNSUPPORTED without new phase/RNG/costs or blocked primary passed\n";return;
    }
    snapshot_assert($saved['policy']['phase']===$GLOBALS['acsPreparedExecutionPhase'],'Phase moved');
    if(($argv[2]??'')==='corrupt'){
        $broken=$saved;$broken['source_sealed_action_id']='18446744073709551600';$json=AF_ACS_Repository::json($broken);$st=$link->prepare('UPDATE t_af_acs_prepared_executions SET result_json=? WHERE id=?');$st->bind_param('ss',$json,$armed['prepared_executions'][0]['id']);$st->execute();
        $corrupt=$captureAll();itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'ACS_SEAL_TAPE_INVALID');snapshot_assert($captureAll()===$corrupt,'Invalid provenance partially committed parent');echo "ACS prepared phases: corrupted causal provenance fails closed before mechanical writes with retained tape passed\n";return;
    }
    if(($argv[2]??'')==='stale'){
        $link->query('UPDATE t_af_acs_entity_states SET hp=hp-1,state_version=state_version+1 WHERE entity_id='.$actor);
        $link->query("UPDATE t_af_acs_resources SET current_value=current_value-1,resource_version=resource_version+1 WHERE resource_key='hp' AND entity_id=".$actor);
        $stale=$captureAll();itemFail(fn()=>$writer->commit($parent['submission_id'],$parent['state_version']),'ACS_COMBAT_CONFIRMATION_CONFLICT');snapshot_assert($captureAll()===$stale&&$stale['rolls']===$armed['rolls'],'Stale target changed mechanics or tape');echo "ACS prepared phases: stale original target blocks reconfirm with unchanged sealed/causal rolls passed\n";return;
    }
    $r=$writer->commit($parent['submission_id'],$parent['state_version']);$after=$captureAll();$e=json_decode($after['prepared_executions'][0]['result_json'],true);
    snapshot_assert($e['outcome']==='executed'&&$e['phase_event']['phase']===$GLOBALS['acsPreparedExecutionPhase'],'Not executed at pinned phase '.json_encode($e));
    snapshot_assert($e['tape']===$saved['tape']&&$after['rolls']===$armed['rolls'],'Phase replay rerolled');
    snapshot_assert(count($e['owner_receipts'])===1&&$repo->individualTurns(['1'])[0]['reaction_used']==='0','Item/reaction budget incorrect');
    $payload=json_decode($link->query("SELECT payload_json FROM t_af_acs_combat_results WHERE submission_id='".$parent['submission_id']."'")->fetch_row()[0],true);
    $plan=$payload['plan']['actions'][0];$phase=$GLOBALS['acsPreparedExecutionPhase'];$strikes=$plan['strikes']??[$plan];
    snapshot_assert($strikes[0]['reactions'][0]['queue_kind']==='prepared'&&$strikes[0]['reactions'][0]['phase']===$phase,'Phase trace absent');
    $hpBefore=$before['entity_states'][0]['hp'];$childBefore=$e['mechanical_deltas']['entities'][$actor]['before']['hp']??null;
    snapshot_assert($childBefore!==null,'No child HP delta');
    if(in_array($phase,['on_targeted','before_hit','after_hit'],true))snapshot_assert((int)$childBefore===(int)$hpBefore,'Primary damage happened before pre-damage prepared');
    else snapshot_assert((int)$childBefore<(int)$hpBefore,'after_damage ran before damage');
    if(($argv[2]??'')==='multitarget')snapshot_assert($plan['targets']===[$actor,$npc]&&array_column($plan['strikes'],'original_target')===[$actor,$npc],'Sealed multiple target order changed');
    $final=$captureAll();snapshot_assert($writer->commit($parent['submission_id'],$parent['state_version'])===$r&&$captureAll()===$final,'Duplicate phase event changed state');
    snapshot_assert($payload['summary']['virtual_entities']!==$payload['confirmation_summary']['virtual_entities'],'Applied summary reused pre-causal HP');
    $bad=$saved['policy'];$bad['phase']='on_unknown_effect';itemFail(fn()=>AF_ACS_PreparedAction::execution($bad),'ACS_PREPARED_UNSUPPORTED');
    $bad=$saved['policy'];$bad['emit_prepared']=true;itemFail(fn()=>AF_ACS_PreparedAction::execution($bad),'ACS_PREPARED_UNSUPPORTED');
    echo 'ACS prepared phases: '.$phase.', '.($argv[2]??'single').", pre/post damage state, fixed target order, retained tape, atomic item rollback and duplicate passed\n";
};
require __DIR__.'/acs_prepared_execution_sql.php';
