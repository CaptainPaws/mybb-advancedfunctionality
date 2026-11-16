<?php
/** Existing Stage 9 actual paid child -> completion -> statistics -> lifecycle.
 * ACS_STAGE10_END=prepared selects ordinary prepared instead of encounter-start ambush. */
$GLOBALS['acsStage10AdvancedFixture']=static function(array $f):void {
    extract($f);global $db,$mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/StatisticsService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/SessionService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/SessionRepository.php';
    $eid=(string)($eid??'1');$mybb->user['uid']=7;$service=new AF_ACS_EncounterService($repo,$access);
    $pending=$repo->pendingResolution($sid,$eid);snapshot_assert($pending&&!$repo->confirmedOutcome($sid,$eid),'Advanced effect bypassed GM confirmation');
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');$before=$captureAll();
    $r=['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'resolution_id'=>$pending['resolution_id'],'outcome'=>'victory','operation_key'=>'stage10_advanced_confirm01','reason'=>'Actual paid Stage 9 child completion'];
    $confirmed=$service->command('outcome_confirm',$r);$after=$captureAll();snapshot_assert($service->command('outcome_confirm',$r)===$confirmed&&$captureAll()===$after,'Advanced completion GM replay duplicated');
    $stats=new AF_ACS_StatisticsService($db,$access);$GLOBALS['disabled']=['advancedstatistic'];$out=$stats->synchronize($sid,$eid,'private');$GLOBALS['disabled']=[];snapshot_assert($out['publication']==='pending','Advanced consumer failure blocked completion');
    $repoStats=new AF_ACS_StatisticsRepository($db);$rows=$repoStats->pending($eid);snapshot_assert(count($rows)===1,'Missing completed advanced aggregate');$payload=json_decode($rows[0]['payload_json'],true)['payload'];
    $damage=0;$ap=0;$items=0;foreach($payload['subjects'] as $subject)if($subject['subject_kind']==='entity'){$damage+=$subject['metrics']['actual_hp_damage'];$ap+=$subject['metrics']['ap_spent'];$items+=$subject['metrics']['items_consumed'];}
    snapshot_assert($damage>0&&$ap>0,'Advanced applied damage/on_prepare AP absent from completed statistics');
    if(getenv('ACS_STAGE10_END')!=='prepared')snapshot_assert($items>=2,'Actual ambush owner consumptions absent');
    $sessions=new AF_ACS_SessionService(new AF_ACS_SessionRepository($db),$access);
    $sr=static fn($key)=>['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Stage 10 no refill / retained advanced receipt'];
    $sessions->command('request_close',$sr('stage10_advanced_pending01'));$sessions->command('close',$sr('stage10_advanced_close01'));
    $closed=$captureAll();$sessions->command('reopen_session',$sr('stage10_advanced_reopen01'));$opened=$captureAll();
    foreach(['entities','entity_states','resources','encounters','rounds','turns','entity_turns','cooldowns','sealed_actions','rolls','combat_results','combat_receipts','prepared_actions','prepared_executions'] as $table)if(isset($closed[$table]))snapshot_assert($closed[$table]===$opened[$table],'Advanced reopen refilled/reexecuted '.$table);
    $sessions->command('request_close',$sr('stage10_advanced_pending02'));$sessions->command('close',$sr('stage10_advanced_close02'));$sessions->command('archive',$sr('stage10_advanced_archive01'));
    $recovery=new AF_ACS_RecoveryService($db,$access);snapshot_assert($recovery->recoverStatistics($sid,$eid)['publication']==='delivered','Archived advanced statistics recovery failed');
    $after=$captureAll();foreach(['rolls','sealed_actions','combat_results','combat_receipts','entity_states','resources','prepared_actions','prepared_executions','inv_items','inv_reservations'] as $table)if(isset($before[$table]))snapshot_assert($before[$table]===$after[$table],'Advanced Stage 10 changed mechanics '.$table);
    snapshot_assert($repo->encounter($sid,$eid)['status']==='completed'&&$repo->session($sid)['status']==='archived','Advanced lifecycle restarted completed encounter');
    echo 'ACS Stage 10 '.(getenv('ACS_STAGE10_END')==='prepared'?'prepared':'ambush')." completion: real paid child, repeated GM outcome, completed damage/AP/items, consumer outage, close/reopen no refill, archived recovery and immutable mechanics passed\n";
};
require __DIR__.(getenv('ACS_STAGE10_END')==='prepared'?'/acs_end_prepared_sql.php':'/acs_end_ambush_sql.php');
