<?php
declare(strict_types=1);
// Extends real draft/seal services against their disposable, local-only SQL fixture.
$GLOBALS['acsPublicationFixture'] = static function (array $fixture): void {
    extract($fixture); global $mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/SubmissionStore.php';
    $store = new AF_ACS_SubmissionStore($link);
    $tapeBefore=$link->query('SELECT * FROM fixture_af_acs_rolls ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $gameBefore=[];
    foreach(['sessions','encounters','rounds','turns','entity_turns','entity_states','resources','events','event_details'] as $t) {
        $gameBefore[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    }
    // Remove an unsealed suffix using existing CAS. Keep every historical attempt/roll.
    $d=$drafts->restore($saved['draft_id']); $active=[];
    foreach($repo->sealRows($d['draft_id'],true) as $a) $active[]=$repo->sealPayload($a)['action'];
    $drafts->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$d['draft_id'],
        'expected_draft_version'=>$d['draft_version'],'operation_key'=>'publication_prepare_fixture','prose'=>'Проза сохраняется','actions'=>$active]);
    $v=$seal->status($d['draft_id']);
    $confirmed=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$v['draft_version'],
        'operation_key'=>'publication_confirm_fixture','confirmation_hash'=>$v['confirmation_hash']]);
    $request=['schema_version'=>1,'submission_id'=>str_repeat('a',32),'draft_id'=>$d['draft_id'],'author_uid'=>'7',
        'expected_draft_version'=>$confirmed['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']];
    $auth=static function(array $row)use($access):void {
        if($access->uid()!==$row['author_uid'])throw new RuntimeException('ACS_PERMISSION_DENIED');
        $access->draftThread('51');
    };
    $link->query("CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON fixture_af_acs_publication_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    blocked(fn()=>$store->register($request,$auth),'ACS_DATABASE_ERROR');
    snapshot_assert($store->submission($request['submission_id'])===null,'Failed audit retained intent');
    snapshot_assert($repo->draft($d['draft_id'],'7')['draft_version']===$confirmed['draft_version'],'Rollback lost draft');
    $link->query('DROP TRIGGER fixture_fail_audit');
    $r=$store->register($request,$auth);
    snapshot_assert($r['status']==='registered'&&$r['state_version']==='1','Missing registered phase');
    snapshot_assert($store->register(array_reverse($request,true),$auth)===$r,'Registration retry changed receipt');
    snapshot_assert(!$drafts->restore($d['draft_id'])['editable'],'Frozen draft editable');
    $mybb->user['uid']=8;blocked(fn()=>$store->register($request,$auth),'ACS_PERMISSION_DENIED');$mybb->user['uid']=7;
    blocked(fn()=>$store->register(array_replace($request,['confirmation_hash'=>str_repeat('0',64)]),$auth),'ACS_SUBMISSION_CONFLICT');
    $store->validating($r['submission_id'],'1',$auth);
    blocked(fn()=>$store->validating($r['submission_id'],'1',$auth),'ACS_VERSION_CONFLICT');
    $refused=$store->rejectBeforePosting($r['submission_id'],'2','fixture_not_started',$auth);
    snapshot_assert($refused['active_turn_id']===null&&$repo->draft($d['draft_id'],'7')['status']==='sealed','Proven pre-post refusal lost draft/claim');
    $request['submission_id']=str_repeat('b',32);$request['expected_draft_version']=$repo->draft($d['draft_id'],'7')['draft_version'];
    $r=$store->register($request,$auth);
    $other=new AF_ACS_SubmissionStore(new mysqli('localhost','root','',$link->query('SELECT DATABASE()')->fetch_row()[0],0,$GLOBALS['argv'][1]));
    snapshot_assert($other->register($request,$auth)===$r,'Independent connection lost durable replay');
    blocked(fn()=>$other->register(array_replace($request,['submission_id'=>str_repeat('c',32)]),$auth),'ACS_SEALED_SUBMISSION_REQUIRED');
    $controls=['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']];
    $r=$store->validating($r['submission_id'],$r['state_version'],$auth);
    blocked(fn()=>$store->posting($r['submission_id'],$r['state_version'],array_replace($controls,['simulation_hash'=>str_repeat('0',64)]),$auth),'ACS_SEAL_SUMMARY_CHANGED');
    $r=$store->posting($r['submission_id'],$r['state_version'],$controls,$auth);
    $r=$other->quarantine($r['submission_id'],$r['state_version'],'fixture_response_lost',$auth);
    snapshot_assert($r['status']==='recovery_required'&&$r['last_phase']==='posting'&&$r['pid']===null,'Unknown outcome promoted or phase lost');
    snapshot_assert($store->register($request,$auth)===$r,'Unknown publication restarted');
    blocked(fn()=>$store->rejectBeforePosting($r['submission_id'],$r['state_version'],'unsafe_retry',$auth),'ACS_POST_OUTCOME_UNKNOWN');
    blocked(fn()=>$store->confirmPost($r['submission_id'],$r['state_version'],'123',$auth),'ACS_POST_CONFIRMATION_DENIED');
    blocked(fn()=>AF_ACS_Publication::requireTransition('recovery_required','registered'),'ACS_PUBLICATION_TRANSITION_DENIED');
    blocked(fn()=>AF_ACS_Publication::requireTransition('committed','committing'),'ACS_PUBLICATION_TRANSITION_DENIED');
    blocked(fn()=>$repo->command('fixture:blocked-command','fixture.command',['schema_version'=>1,'session_id'=>$sid,'actor_uid'=>'7',
        'expected_version'=>$repo->session($sid)['session_version']],static function(){},static function(){throw new LogicException('Barrier bypass');}),'ACS_PUBLICATION_UNRESOLVED');
    foreach(AF_ACS_Publication::STATES as $state) snapshot_assert(AF_ACS_Publication::card($state)['mechanics_confirmed']===($state==='committed'),'Pending damage confirmed');
    foreach($gameBefore as $t=>$rows) snapshot_assert($link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)===$rows,'Mechanics changed: '.$t);
    snapshot_assert($link->query('SELECT * FROM fixture_af_acs_rolls ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$tapeBefore&&$draws===6,'Tape changed');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_publication_audit')->fetch_row()[0]==='7','Audit lost/duplicated transitions');
    snapshot_assert(count($store->pending($sid))===1,'Pending intent lost');
    try {
        $link->query("INSERT INTO fixture_af_acs_submissions SELECT REPEAT('d',32),session_id,encounter_id,turn_id,draft_id,author_uid,tid,pid,active_turn_id,status,last_phase,state_version,request_hash,request_json,sealed_json,evidence_json,result_json,created_at,updated_at FROM fixture_af_acs_submissions WHERE submission_id=REPEAT('b',32)");
        throw new LogicException('Duplicate turn allowed');
    } catch(mysqli_sql_exception $e) { snapshot_assert($e->getCode()===1062,'Wrong unique-turn error'); }
    $migrations->run();snapshot_assert($store->submission($r['submission_id'])===$r,'Migration removed pending intent');
    // Isolated known-pid readback fixture: simulate durable POSTING records on other turns.
    // These SQL posts are fixture setup only; ACS never inserts/updates MyBB posts.
    $link->query('CREATE TABLE fixture_posts (pid int unsigned PRIMARY KEY,uid int unsigned,tid int unsigned,visible int,message text) ENGINE=MyISAM');
    $link->query("INSERT INTO fixture_posts VALUES (123,7,51,0,'pending prose'),(124,7,51,1,'visible prose'),(125,8,51,1,'foreign author')");
    foreach (['e'=>998,'f'=>999] as $letter=>$turn) {
        $key=str_repeat($letter,32);
        $statement=$link->prepare("INSERT INTO fixture_af_acs_submissions SELECT ?,session_id,encounter_id,?,draft_id,author_uid,tid,NULL,?,'posting','posting',3,request_hash,request_json,sealed_json,evidence_json,result_json,created_at,updated_at FROM fixture_af_acs_submissions WHERE submission_id=REPEAT('b',32)");
        $statement->bind_param('sii',$key,$turn,$turn);$statement->execute();$statement->close();
    }
    blocked(fn()=>$store->confirmPost(str_repeat('e',32),'3','125',$auth),'ACS_POST_CONFIRMATION_DENIED');
    blocked(fn()=>$store->confirmPost(str_repeat('e',32),'3','126',$auth),'ACS_POST_CONFIRMATION_DENIED');
    $moderated=$store->confirmPost(str_repeat('e',32),'3','123',$auth);
    snapshot_assert($moderated['status']==='moderation_pending'&&$moderated['pid']==='123','Moderation incorrectly confirmed mechanics');
    blocked(fn()=>$store->confirmPost(str_repeat('f',32),'3','123',$auth),'ACS_DATABASE_ERROR');
    snapshot_assert($store->submission(str_repeat('f',32))['state_version']==='3','Duplicate PID partially changed journal');
    $known=$store->confirmPost(str_repeat('f',32),'3','124',$auth);
    snapshot_assert($known['status']==='post_confirmed'&&!AF_ACS_Publication::card($known['status'])['mechanics_confirmed'],'Known post applied mechanics');
    $link->query('DELETE FROM fixture_posts WHERE pid=124');
    $known=$store->quarantine($known['submission_id'],$known['state_version'],'fixture_post_deleted',$auth);
    snapshot_assert($known['pid']==='124'&&$known['last_phase']==='post_confirmed','Quarantine lost confirmed PID/phase');
    $migrations->run();
    snapshot_assert($link->query("SHOW TABLE STATUS LIKE 'fixture_posts'")->fetch_assoc()['Engine']==='MyISAM','Forum engine migrated');
    snapshot_assert($link->query('SELECT message,visible FROM fixture_posts WHERE pid=123')->fetch_assoc()===['message'=>'pending prose','visible'=>'0'],'ACS changed prose/visibility');
    echo "ACS R2 journal: rollback, CAS, durable replay, frozen draft, quarantine/no retry, dependency barrier, immutable tape and statuses passed\n";
};
require __DIR__.'/acs_seal_regression.php';
