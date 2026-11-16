<?php
/** Narrow ACS-10.4.2 integration fixture, reusing the verified completion/review setup.
 * Disposable database only. Second encounter's coherent terminal records are seeded by that setup. */
$GLOBALS['acsArchiveFixture']=static function(array $f):void {
    extract($f);global $db,$mybb,$enabled;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/StatisticsService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/StatisticsAdapter.php';
    $mybb->settings['af_acs_archive_cursor_key']=str_repeat('a',64);
    $firstVisibility=getenv('ACS_STATISTICS_VISIBILITY')?:'private';
    $stats=new AF_ACS_StatisticsRepository($db);$service=new AF_ACS_StatisticsService($db,$access);
    $before=$captureAll();$originalEvents=$link->query('SELECT * FROM t_af_acs_events ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    snapshot_assert((int)$link->query('SELECT COUNT(*) FROM t_af_acs_statistics')->fetch_row()[0]===0,'Combat/confirmation wrote permanent statistics');
    snapshot_assert((int)$link->query("SELECT COUNT(*) FROM t_af_acs_outbox WHERE kind='statistics.refresh' AND status='pending'")->fetch_row()[0]>=2,'Completion/correction lost refresh outbox');
    // A consumer error must preserve the completed outcome and pending aggregate.
    $GLOBALS['disabled']=['advancedstatistic'];
    $first=$service->synchronize($sid,'1',$firstVisibility);snapshot_assert($first['publication']==='pending'&&$repo->encounter($sid,'1')['status']==='completed','Consumer outage rolled back completion or lost pending');
    $GLOBALS['disabled']=[];
    $pending=$stats->pending('1');snapshot_assert(count($pending)===1,'Aggregate publication not pending');$message=json_decode($pending[0]['payload_json'],true);
    $GLOBALS['disabled']=['advancedstatistic'];itemFail(fn()=>AF_ACS_StatisticsAdapter::publishCompletedAggregate($message['operation_key'],$message['encounter_id'],$message['aggregate_version'],$message['visibility'],$message['payload']),'ACS_STATISTICS_CONSUMER_UNAVAILABLE');$GLOBALS['disabled']=[];
    // Consumer commit without producer ACK, followed by idempotent lookup and replay.
    $receipt=AF_ACS_StatisticsAdapter::publishCompletedAggregate($message['operation_key'],$message['encounter_id'],$message['aggregate_version'],$message['visibility'],$message['payload']);
    snapshot_assert(AF_ACS_StatisticsAdapter::lookup($message['operation_key'])===$receipt&&count($stats->pending('1'))===1,'Lost ACK cannot be recovered');
    $replay=$service->synchronize($sid,'1',$firstVisibility);snapshot_assert($replay['publication']==='delivered'&&!$stats->pending('1'),'Retry failed to acknowledge existing receipt');
    $saved=$link->query('SELECT * FROM t_af_acs_statistics ORDER BY id')->fetch_all(MYSQLI_ASSOC);$service->synchronize($sid,'1',$firstVisibility);snapshot_assert($saved===$link->query('SELECT * FROM t_af_acs_statistics ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Same aggregate replay rewrote rows');
    $subjects=$message['payload']['subjects'];$entityTotals=0;$groupTotals=0;foreach($subjects as $subject){if($subject['subject_kind']==='entity')$entityTotals+=$subject['metrics']['actual_hp_damage'];if($subject['subject_kind']==='control_group')$groupTotals+=$subject['metrics']['actual_hp_damage'];}
    snapshot_assert($entityTotals===$groupTotals&&$entityTotals>0,'Entity/group totals double counted pet or lost actual damage');
    snapshot_assert(!str_contains(json_encode($message),'effects_trace')&&!str_contains(json_encode($message),'sealed_json'),'Consumer copied raw combat journal');
    // Complete the existing second terminal fixture with coherent archive links/outcome.
    // These records are seeded evidence, not a claimed second native publication/GM flow.
    foreach($link->query('SELECT * FROM t_af_acs_participants WHERE encounter_id=1')->fetch_all(MYSQLI_ASSOC) as $p){unset($p['id']);$p['encounter_id']=$future;itemInsert($link,'af_acs_participants',$p);}
    $pl=$link->query('SELECT * FROM t_af_acs_post_links WHERE encounter_id=1 LIMIT 1')->fetch_assoc();unset($pl['id']);$pl['submission_id']='fixture-achievement-second';$pl['encounter_id']=$future;$pl['turn_id']=9999;$pl['pid']=9999;itemInsert($link,'af_acs_post_links',$pl);$newLink=(string)$link->insert_id;
    $rr=$link->query("SELECT * FROM t_af_acs_combat_receipts WHERE submission_id='fixture-achievement-second'")->fetch_assoc();$rc=json_decode($rr['receipt_json'],true);$rc['post_link_id']=$newLink;$json=AF_ACS_Repository::json($rc);$st=$link->prepare("UPDATE t_af_acs_combat_receipts SET receipt_json=?,receipt_hash=? WHERE submission_id='fixture-achievement-second'");$hash=hash('sha256',$json);$st->bind_param('ss',$json,$hash);$st->execute();
    $st=$link->prepare("UPDATE t_af_acs_submissions SET result_json=? WHERE submission_id='fixture-achievement-second'");$st->bind_param('s',$json);$st->execute();
    $resolution=$link->query("SELECT * FROM t_af_acs_encounter_resolutions WHERE encounter_id=1 AND status='confirmed'")->fetch_assoc();$terminalOld=$resolution['terminal_event_id'];unset($resolution['id']);$pendingId=$resolution['pending_event_id'];$pending=$link->query('SELECT * FROM t_af_acs_events WHERE id='.$pendingId)->fetch_assoc();unset($pending['id']);$pending['encounter_id']=$future;$pending['session_sequence']=$repo->session($sid)['next_sequence'];$pending['event_sequence']=101;$pending['operation_key']='statistics:fixture-pending';itemInsert($link,'af_acs_events',$pending);$pendingNew=(string)$link->insert_id;
    $pd=$link->query('SELECT * FROM t_af_acs_event_details WHERE event_id='.$pendingId)->fetch_assoc();$pd['event_id']=$pendingNew;itemInsert($link,'af_acs_event_details',$pd);$link->query('UPDATE t_af_acs_sessions SET next_sequence=next_sequence+1 WHERE id='.$sid);
    $resolution['trigger_event_id']=$link->query('SELECT id FROM t_af_acs_events WHERE encounter_id='.$future." AND event_type='combat.resolved' LIMIT 1")->fetch_row()[0];$resolution['pending_event_id']=$pendingNew;$resolution['encounter_id']=$future;$resolution['completed_encounter_id']=$future;$resolution['config_snapshot_id']=$repo->encounter($sid,$future)['config_snapshot_id'];itemInsert($link,'af_acs_encounter_resolutions',$resolution);$resolutionId=(string)$link->insert_id;
    $ev=$link->query('SELECT * FROM t_af_acs_events WHERE id='.$terminalOld)->fetch_assoc();unset($ev['id']);$ev['encounter_id']=$future;$ev['session_sequence']=$repo->session($sid)['next_sequence'];$ev['event_sequence']=102;$ev['operation_key']='statistics:fixture-outcome';itemInsert($link,'af_acs_events',$ev);$terminalNew=(string)$link->insert_id;
    $rc=json_decode($resolution['receipt_json'],true);$rc['encounter_id']=$future;$rc['resolution_id']=$resolutionId;$rc['trigger_event_id']=(string)$resolution['trigger_event_id'];$rc['pending_event_id']=$pendingNew;$rc['terminal_event_id']=$terminalNew;$rc['event_id']=$terminalNew;$rc['outcome_id']='acs-outcome:'.$future.':'.$terminalNew;$json=AF_ACS_Repository::json($rc);$hash=hash('sha256',$json);$st=$link->prepare('UPDATE t_af_acs_encounter_resolutions SET terminal_event_id=?,receipt_json=?,receipt_hash=? WHERE id=?');$st->bind_param('ssss',$terminalNew,$json,$hash,$resolutionId);$st->execute();
    $detail=$link->query('SELECT * FROM t_af_acs_event_details WHERE event_id='.$terminalOld)->fetch_assoc();$bd=json_decode($detail['payload_json'],true);$bd['result']=array_diff_key($rc,array_flip(['schema_version','session_id','actor_uid','event_id']));$detail['event_id']=$terminalNew;$detail['payload_json']=AF_ACS_Repository::json($bd);$detail['content_hash']=hash('sha256',$detail['payload_json']."\n".$detail['visibility_json']);itemInsert($link,'af_acs_event_details',$detail);
    $op=$link->query("SELECT * FROM t_af_acs_operations WHERE operation_key='".$link->real_escape_string($link->query('SELECT operation_key FROM t_af_acs_events WHERE id='.$terminalOld)->fetch_row()[0])."'")->fetch_assoc();$op['operation_key']=$ev['operation_key'];$op['encounter_id']=$future;itemInsert($link,'af_acs_operations',$op);
    $link->query('UPDATE t_af_acs_sessions SET next_sequence=next_sequence+1 WHERE id='.$sid);$link->query("UPDATE t_af_acs_encounters SET status='completed',next_event_sequence=103 WHERE id=".$future);
    $second=$service->synchronize($sid,$future,'public');snapshot_assert($second['publication']==='delivered','Second encounter not published independently');
    $before=$captureAll();$originalEvents=$link->query('SELECT * FROM t_af_acs_events ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    // Active encounters must never acquire permanent aggregates, even with a known outcome.
    $link->query("UPDATE t_af_acs_encounters SET status='active' WHERE id=1");itemFail(fn()=>$stats->refresh($sid,'1',$firstVisibility),'ACS_STATISTICS_COMPLETED_REQUIRED');$link->query("UPDATE t_af_acs_encounters SET status='completed' WHERE id=1");
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    $correction=new AF_ACS_CorrectionService($db,$access);$current=$repo->latestCorrection($sid,'1',$source);
    $q=['session_id'=>$sid,'encounter_id'=>'1','original_event_id'=>$source,'expected_version'=>$repo->session($sid)['session_version'],'expected_review_version'=>$current['review_version'],'operation_key'=>'statistics_correction_exclude01','reason'=>'Statistics revision fixture','plan'=>['schema'=>'ic-acs.correction-review.v1','disposition'=>'exclude','recipient_contract'=>'acs.derived-review.v1','compensation'=>'none']];
    $correction->command($q);$new=$service->synchronize($sid,'1',$firstVisibility);snapshot_assert((int)$new['aggregate_version']>(int)$first['aggregate_version'],'Correction did not advance aggregate version');
    $currentPayload=$link->query('SELECT payload_json FROM t_af_acs_statistics_consumers WHERE encounter_id=1')->fetch_row()[0];$decoded=json_decode($currentPayload,true);foreach($decoded['subjects'] as $s)snapshot_assert($s['metrics']['actual_hp_damage']===0,'Correction accumulated excluded damage');
    // Old acknowledged operation replay never rewinds the current consumer.
    snapshot_assert(AF_ACS_StatisticsAdapter::publishCompletedAggregate($message['operation_key'],$message['encounter_id'],$message['aggregate_version'],$message['visibility'],$message['payload'])===$receipt,'Old receipt changed');
    snapshot_assert($currentPayload===$link->query('SELECT payload_json FROM t_af_acs_statistics_consumers WHERE encounter_id=1')->fetch_row()[0],'Stale aggregate rolled back current version');
    // Restore via a new correction, then exercise server-side visibility independently.
    $q['expected_version']=$repo->session($sid)['session_version'];$q['expected_review_version']=$repo->latestCorrection($sid,'1',$source)['review_version'];$q['operation_key']='statistics_correction_include01';$q['plan']['disposition']='include';$correction->command($q);$service->synchronize($sid,'1',$firstVisibility);
    // Deliver a newer version before an older still-unacknowledged version.
    $delayed=[];
    foreach(['exclude','include'] as $disposition){$q['expected_version']=$repo->session($sid)['session_version'];$q['expected_review_version']=$repo->latestCorrection($sid,'1',$source)['review_version'];$q['operation_key']='statistics_ordered_'.$disposition.'01';$q['plan']['disposition']=$disposition;$correction->command($q);$result=$stats->refresh($sid,'1',$firstVisibility);foreach($stats->pending('1') as $row)if($row['message_key']===$result['operation_key'])$delayed[$disposition]=json_decode($row['payload_json'],true);}
    $publish=static fn($m)=>AF_ACS_StatisticsAdapter::publishCompletedAggregate($m['operation_key'],$m['encounter_id'],$m['aggregate_version'],$m['visibility'],$m['payload']);
    $publish($delayed['include']);$newest=$link->query('SELECT payload_json FROM t_af_acs_statistics_consumers WHERE encounter_id=1')->fetch_row()[0];
    snapshot_assert($publish($delayed['exclude'])['status']==='superseded'&&$link->query('SELECT payload_json FROM t_af_acs_statistics_consumers WHERE encounter_id=1')->fetch_row()[0]===$newest,'Unacknowledged stale consumer version rewound state');$service->synchronize($sid,'1',$firstVisibility);
    $archive=new AF_ACS_ArchiveService($db,$access);
    $policy=static function($eid,$mode,$key)use($repo,$sid){return ['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'expected_policy_version'=>$repo->archivePolicy($sid,$eid)['policy_version'],'mode'=>$mode,'operation_key'=>$key,'reason'=>'Statistics visibility fixture'];};
    $archive->policy($policy('1','reveal_all','statistics_policy_first01'));$archive->policy($policy($future,'reveal_all','statistics_policy_second01'));
    $owner=(string)$link->query("SELECT owner_uid FROM t_af_acs_entities WHERE kind='character' AND owner_uid IS NOT NULL ORDER BY id LIMIT 1")->fetch_row()[0];
    snapshot_assert($service->profile($owner)['completed_encounters']===[],'Hidden participant leaked encounter/totals/filter membership');
    // Fixture visibility change: this tests reader policy, not a new ACS management command.
    $link->query("UPDATE t_af_acs_participants SET visibility_json='{\"schema_version\":1,\"scope\":\"participants\"}'");
    $mybb->user['uid']=(int)$owner;$visible=$service->profile($owner);snapshot_assert(count($visible['completed_encounters'])===2,'Owner did not receive two authorized encounters');
    $fingerprint=static function()use($link){$a=[];$q=$link->query("SHOW TABLES LIKE 't_af_acs_%'");while($r=$q->fetch_row())$a[$r[0]]=hash('sha256',json_encode($link->query('SELECT * FROM `'.$r[0].'`')->fetch_all(MYSQLI_ASSOC)));return $a;};
    $readBefore=$fingerprint();$service->profile($owner,['kind'=>'pet','category'=>'actual_hp_damage']);snapshot_assert($readBefore===$fingerprint(),'Profile GET wrote state');
    // Private excludes another viewer; public still requires native/archive access and role.
    $mybb->user['uid']=8;$participantView=$service->profile($owner);
    snapshot_assert(in_array('1',array_column($participantView['completed_encounters'],'encounter_id'),true)===($firstVisibility==='participants'),'Participants/private audience distinction failed');
    itemInsert($link,'users',['uid'=>9]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>9,'role'=>'observer','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    $mybb->user['uid']=9;$other=$service->profile($owner);snapshot_assert(!in_array('1',array_column($other['completed_encounters'],'encounter_id'),true),'Private/participants aggregate leaked to observer');snapshot_assert(in_array($future,array_column($other['completed_encounters'],'encounter_id'),true),'Public aggregate missing for authorized observer');
    $mybb->user['uid']=7;
    $archive->policy($policy($future,'keep_hidden','statistics_hide_second01'));snapshot_assert(count($service->profile($owner)['completed_encounters'])===1,'Hidden encounter leaked list membership');
    $after=$captureAll();foreach(['combat_results','combat_receipts','sealed_actions','rolls','entity_states','resources','snapshots','inv_items','loot_pools','loot_entries','turns','entity_turns','cooldowns'] as $table)snapshot_assert($before[$table]===$after[$table],'Statistics mutated mechanical source '.$table);
    $retained=array_column($link->query('SELECT * FROM t_af_acs_events')->fetch_all(MYSQLI_ASSOC),null,'id');foreach($originalEvents as $e)snapshot_assert($retained[$e['id']]===$e,'Statistics rewrote immutable source');
    $migrationBefore=$fingerprint();$migrations->run();$migrations->run();snapshot_assert($migrationBefore===$fingerprint(),'Migration replay changed populated statistics/receipts/outbox');
    if(isset($GLOBALS['acsStage10ArchiveFixture']))($GLOBALS['acsStage10ArchiveFixture'])(get_defined_vars());
    echo "ACS statistics SQL: completed/active, two encounters, outbox outage, replay/lost ACK, correction/stale version, hidden/private/public, profile read-only and source immutability passed\n";
};
require __DIR__.'/acs_achievements_reviews_sql.php';
