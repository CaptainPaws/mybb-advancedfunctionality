<?php
declare(strict_types=1);
// Disposable SQL fixture, known native pid only. No Gateway or production source required.
$GLOBALS['acsRecoveryFixture']=static function(array $f):void {
    extract($f);global $mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
    $native=(object)['read_link'=>$link,'write_link'=>$link];$recovery=new AF_ACS_RecoveryService($native,$access);
    $make=static function(string $phase='post_confirmed')use($reset,$store,$link,$baseDraft,$confirmed,$sid,&$next,&$mybb):array {
        $reset();$mybb->user['uid']=7;$mybb->input=['my_post_key'=>'token'];
        $link->query("REPLACE INTO fixture_posts VALUES(501,7,51,1,1,'unchanged native prose')");
        $link->query("UPDATE fixture_threads SET fid=1,visible=1,closed='0'");$link->query("UPDATE fixture_forums SET open=1,type='f'");
        $link->query("INSERT INTO fixture_af_acs_session_roles(session_id,uid,role,permissions_json,role_version) VALUES(".$sid.",8,'gm','{\"schema_version\":1}',1) ON DUPLICATE KEY UPDATE expires_at=NULL");
        $id=str_pad(dechex(++$next),32,'0',STR_PAD_LEFT);
        $r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$baseDraft['id'],'author_uid'=>'7','expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        if($phase==='registered')return $r;
        $r=$store->validating($id,$r['state_version'],static function(){});if($phase==='validating')return $r;
        $r=$store->posting($id,$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        if($phase==='posting')return $r;
        if($phase==='moderation_pending')$link->query('UPDATE fixture_posts SET visible=0');
        return $store->confirmPost($id,$r['state_version'],'501',static function(){},'1');
    };
    $mechanics=static function()use($capture):array {return array_intersect_key($capture(),array_flip(['entity_states','resources','turns','entity_turns','events','event_details','combat_results','post_links','combat_receipts','outbox','rolls','sealed_actions','snapshots']));};
    $r=$make();$id=$r['submission_id'];$out=$recovery->recover($id,$r['state_version']);
    snapshot_assert($out['status']==='COMMITTED'&&$out['mechanics_confirmed'],'Known post recovery did not commit');$after=$capture();
    snapshot_assert($recovery->recover($id,'1')['receipt']===$out['receipt']&&$capture()===$after,'Lost response applied mechanics twice');
    $mybb->user['uid']=8;snapshot_assert($recovery->inspect($id)['receipt']===$out['receipt'],'GM receipt lookup failed');
    $link->query('DELETE FROM fixture_posts WHERE pid=501');
    $moved=$recovery->recover($id,'1');snapshot_assert($moved['status']==='COMMITTED'&&$moved['post']['state']==='missing'&&$capture()===$after,'Deleted committed post undid history');
    $r=$make();$before=$mechanics();
    $link->query("CREATE TRIGGER fixture_recovery_fail BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery fixture'");
    $out=$recovery->recover($r['submission_id'],$r['state_version']);$link->query('DROP TRIGGER fixture_recovery_fail');
    snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Unknown/rollback commit mutated mechanics');
    $mybb->user['uid']=8;$oldVersion=$out['state_version'];
    $ready=$recovery->resolve($r['submission_id'],$oldVersion,'resume_known_post','Native confirmation verified; retry rolled-back ACS');
    snapshot_assert($ready['status']==='POST_CONFIRMED'&&!$ready['mechanics_confirmed']&&!$ready['can_resume_as_author'],'GM impersonated author');
    snapshot_assert((string)$ready['audit'][0]['actor_uid']==='8'&&$ready['audit'][0]['reason']==='gm_recovery_resume_known','GM decision not separately audited');
    blocked(fn()=>$recovery->resolve($r['submission_id'],$oldVersion,'resume_known_post','Duplicate decision'),'ACS_VERSION_CONFLICT');
    $mybb->user['uid']=7;$out=$recovery->recover($r['submission_id'],$ready['state_version']);snapshot_assert($out['status']==='COMMITTED','Author did not finish safe retry');
    // Unknown pid with a real matching candidate remains unproven, even for a GM.
    $r=$make('posting');$r=$store->quarantine($r['submission_id'],$r['state_version'],'fixture_native_unknown',static function(){},['observed_pid'=>'501']);
    $before=$mechanics();$mybb->user['uid']=8;$out=$recovery->inspect($r['submission_id']);
    snapshot_assert($out['candidates'][0]['pid']==='501'&&!$out['candidates'][0]['identity_proven']&&$out['pid']===null,'Candidate became identity');
    blocked(fn()=>$recovery->resolve($r['submission_id'],$r['state_version'],'resume_known_post','Same author and time'),'ACS_RECOVERY_POST_NOT_PROVEN');
    blocked(fn()=>$recovery->resolve($r['submission_id'],$r['state_version'],'reject_unsent','No receipt is not proof'),'ACS_POST_OUTCOME_UNKNOWN');
    $held=$recovery->resolve($r['submission_id'],$r['state_version'],'hold','Need external native identity evidence');
    snapshot_assert($held['status']==='RECOVERY_REQUIRED'&&$held['last_phase']==='posting'&&$held['pid']===null&&$mechanics()===$before,'Hold changed history');
    blocked(fn()=>$repo->assertNoObligations($sid,$eid,$turnId),'ACS_ROUND_OBLIGATIONS_BLOCKED');
    // Pre-dispatch interruption can be explicitly rejected, never RESET to REGISTERED.
    foreach(['registered','validating'] as $phase) {
        $r=$make($phase);$r=$store->quarantine($r['submission_id'],$r['state_version'],'fixture_pre_dispatch',static function(){});$mybb->user['uid']=8;
        $out=$recovery->resolve($r['submission_id'],$r['state_version'],'reject_unsent','Durable journal proves no dispatch');
        snapshot_assert($out['status']==='REJECTED'&&$repo->draft($baseDraft['id'],'7')['status']==='sealed','Unsent draft was not safely released');
    }
    $r=$make('posting');$before=$mechanics();$out=$recovery->recover($r['submission_id'],$r['state_version']);
    snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Interrupted POSTING retried a post');
    foreach(["DELETE FROM fixture_posts WHERE pid=501"=> 'missing',"UPDATE fixture_posts SET visible=-1"=>'unavailable',"UPDATE fixture_posts SET tid=52"=>'identity_conflict',"UPDATE fixture_posts SET uid=9"=>'identity_conflict',"UPDATE fixture_posts SET fid=2"=>'identity_conflict',"UPDATE fixture_threads SET fid=2"=>'identity_conflict'] as $sql=>$state) {
        $r=$make();$before=$mechanics();$link->query($sql);$out=$recovery->recover($r['submission_id'],$r['state_version']);
        snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$out['post']['state']===$state&&$mechanics()===$before,'Invalid native post committed: '.$sql);
    }
    $r=$make('moderation_pending');$before=$mechanics();$out=$recovery->recover($r['submission_id'],$r['state_version']);
    snapshot_assert($out['status']==='MODERATION_PENDING'&&$mechanics()===$before,'Pending moderation spent HP/AP');
    $link->query('UPDATE fixture_posts SET visible=1');$out=$recovery->recover($r['submission_id'],$r['state_version']);
    snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Approval bypassed revalidation');
    $r=$make();$link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1');$before=$mechanics();
    $out=$recovery->recover($r['submission_id'],$r['state_version']);snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Recovery accepted stale seal');
    // Legacy confirmation without original forum evidence is not guessed from the current thread.
    $r=$make();$link->query("UPDATE fixture_af_acs_publication_audit SET evidence_json='{\"schema_version\":1,\"pid\":\"501\",\"visible\":\"1\"}' WHERE reason='native_pid_readback'");
    $out=$recovery->recover($r['submission_id'],$r['state_version']);snapshot_assert($out['post']['state']==='identity_evidence_missing'&&$out['status']==='RECOVERY_REQUIRED','Legacy evidence was fabricated');
    $r=$make('posting');$out=$recovery->recover($r['submission_id'],$r['state_version']);
    $link->query('DELETE FROM fixture_af_acs_session_roles WHERE uid=7');
    $link->query("INSERT INTO fixture_af_acs_session_roles(session_id,uid,role,permissions_json,role_version) VALUES(".$sid.",7,'player','{\"schema_version\":1}',1)");
    $before=$capture();blocked(fn()=>$recovery->resolve($r['submission_id'],$out['state_version'],'hold','Author is not GM'),'ACS_RECOVERY_PERMISSION_DENIED');
    snapshot_assert($capture()===$before,'Player wrote a manual decision');
    // A second submission cannot escape the unresolved turn/draft claim.
    blocked(fn()=>$store->register(['schema_version'=>1,'submission_id'=>str_repeat('f',32),'draft_id'=>$baseDraft['id'],'author_uid'=>'7','expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){}),'ACS_SEALED_SUBMISSION_REQUIRED');
    // Native access/CSRF/SQL role checks, including revocation between preflight and lock.
    $r=$make('posting');$out=$recovery->recover($r['submission_id'],$r['state_version']);$version=$out['state_version'];$before=$capture();
    $mybb->input['my_post_key']='bad';blocked(fn()=>$recovery->resolve($r['submission_id'],$version,'hold','Reason'),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $mybb->user['uid']=9;blocked(fn()=>$recovery->inspect($r['submission_id']),'ACS_RECOVERY_PERMISSION_DENIED');
    $mybb->user['uid']=8;$link->query('UPDATE fixture_af_acs_session_roles SET expires_at=1 WHERE uid=8');
    blocked(fn()=>$recovery->resolve($r['submission_id'],$version,'hold','Expired GM'),'ACS_RECOVERY_PERMISSION_DENIED');
    $link->query('UPDATE fixture_af_acs_session_roles SET expires_at=NULL WHERE uid=8');snapshot_assert($capture()===$before,'Unauthorized request wrote audit');
    $database=$link->query('SELECT DATABASE()')->fetch_row()[0];$socket=$GLOBALS['argv'][1];
    $race=new class('localhost','root','',$database,0,$socket) extends mysqli {
        public ?Closure $beforeBegin=null;
        public function begin_transaction(int $flags=0,?string $name=null):bool {if($cb=$this->beforeBegin){$this->beforeBegin=null;$cb();}return parent::begin_transaction($flags,$name);}
    };
    $racer=new AF_ACS_RecoveryService((object)['read_link'=>$race,'write_link'=>$race],$access);
    $race->beforeBegin=static function()use($link):void {$link->query("DELETE FROM fixture_af_acs_session_roles WHERE uid=8");};
    blocked(fn()=>$racer->resolve($r['submission_id'],$version,'hold','Revoked during request'),'ACS_RECOVERY_PERMISSION_DENIED');
    snapshot_assert($store->submission($r['submission_id'])['state_version']===$version,'Revoked GM wrote a decision');
    $r=$make('posting');$out=$recovery->recover($r['submission_id'],$r['state_version']);$version=$out['state_version'];$mybb->user['uid']=8;$contended=false;
    $race->beforeBegin=static function()use($recovery,$r,$version,&$contended):void {blocked(fn()=>$recovery->resolve($r['submission_id'],$version,'hold','Concurrent request'),'ACS_MAINTENANCE_BUSY');$contended=true;};
    $winner=$racer->resolve($r['submission_id'],$version,'hold','Winner');
    blocked(fn()=>$recovery->resolve($r['submission_id'],$version,'hold','Stale duplicate'),'ACS_VERSION_CONFLICT');
    snapshot_assert($contended&&$winner['status']==='RECOVERY_REQUIRED','Concurrent recovery lost quarantine');
    snapshot_assert($capture()['rolls']===$baseline['rolls']&&$draws===6,'Recovery changed RNG');
    snapshot_assert((int)$link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]===1&&!class_exists('AF_ACS_MybbPostGateway',false),'Recovery created/loaded native publication');
    $mybb->user['uid']=7;$reset();echo "ACS Recovery: receipt replay, known/unknown pid, rollback/resume, moderation/deletion/move, stale seal, GM audit/CAS/revocation/contention, barriers and immutable RNG passed\n";
};
require __DIR__.'/acs_combat_commit_regression.php';
