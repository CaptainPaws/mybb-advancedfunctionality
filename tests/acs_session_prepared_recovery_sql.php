<?php
/** Terminal paid preparation remains recoverable outside gameplay; existing owner grant required. */
$GLOBALS['acsPreparedExecutionFixture']=static function(array $f):void {
    extract($f);global $db;
    $sessions=new AF_ACS_SessionService($repo,$access);
    $request=static fn(string $key)=>['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Prepared recovery lifecycle fixture'];
    itemFail(fn()=>$sessions->command('request_close',$request('prepared_lifecycle_live01')),'ACS_SESSION_OBLIGATIONS_BLOCKED');
    $link->query("CREATE TRIGGER prepared_lifecycle_unknown BEFORE INSERT ON t_af_advinv_external_operations FOR EACH ROW BEGIN IF NEW.operation_type='release' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='release unavailable'; END IF; END");
    $cancelled=$service->cancel($terminal($receipt,'prepared_lifecycle_cancel01'));$link->query('DROP TRIGGER prepared_lifecycle_unknown');snapshot_assert($cancelled['cost_state']==='recovery_required','No prepared obligation');
    // Fixture seed: unstarted preparation encounter; no completed outcome invented.
    $link->query("UPDATE t_af_acs_encounters SET status='preparation',round_number=0 WHERE id=1");
    $before=$captureAll();
    $sessions->command('request_close',$request('prepared_lifecycle_pending01'));$sessions->command('close',$request('prepared_lifecycle_close01'));
    if(($argv[2]??'closed')==='archived')$sessions->command('archive',$request('prepared_lifecycle_archive01'));
    $rows=$service->view($sid,'1',$actor);snapshot_assert($rows[0]['can_release'],'No recovery scope after close');
    $r=$terminal($receipt,'prepared_lifecycle_release01');$result=$service->cancel($r,true);snapshot_assert($result['cost_state']==='settled','Closed prepared release failed');$after=$captureAll();snapshot_assert($service->cancel($r,true)===$result&&$captureAll()===$after,'Closed prepared release replay');
    foreach(['entity_states','resources','turns','entity_turns','cooldowns','rolls','sealed_actions','snapshots','inv_items'] as $t)snapshot_assert($before[$t]===$after[$t],'Recovery refilled or rewrote '.$t);
    snapshot_assert(!array_filter($after['inv_reservations'],fn($r)=>$r['status']==='active'),'Unused reserve not released');
    echo "ACS session prepared recovery SQL: retained obligation, native read scope, owner release after close/archive, immutable costs/history and replay passed\n";
};
require __DIR__.'/acs_prepared_items_sql.php';
