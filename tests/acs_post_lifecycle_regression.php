<?php
declare(strict_types=1);
// Real upstream class_moderation + plugin dispatcher, bounded native helper/counter fixtures.
function update_user_counters(...$a):void { $GLOBALS['acsLifecycleCounters'][]=$a; }
function mark_reports(...$a):void {}
function remove_attachments(...$a):void {}
$GLOBALS['acsPostLifecycleFixture']=static function(array $f):void {
    extract($f);global $db,$mybb,$plugins;
    require_once MYBB_ROOT.'inc/class_plugins.php';require_once MYBB_ROOT.'inc/class_moderation.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Bootstrap/PostLifecycleHooks.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/ModerationService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/PublicationStatusQuery.php';
    $link->query('ALTER TABLE fixture_threads ADD firstpost int DEFAULT 1');
    $link->query('ALTER TABLE fixture_forums ADD usepostcounts int DEFAULT 1');
    $link->query('CREATE TABLE fixture_reportedcontent(id int,type varchar(20)) ENGINE=MyISAM');
    $native=new Moderation;$moderation=new AF_ACS_ModerationService($db,$accessNative);
    $query=new AF_ACS_PublicationStatusQuery($db);
    $make=static function(bool $pending=true)use($issue,$store,$link,$confirmed,$db,&$plugins,&$mybb):array {
        AF_ACS_PostLifecycleHooks::flush();$r=$issue();
        $link->query("UPDATE fixture_threads SET fid=1,visible=1,closed='0'");
        $link->query("INSERT INTO fixture_posts(pid,uid,tid,fid,visible,message,dateline) VALUES(501,7,51,1,".($pending?'0':'1').",'Unchanged prose',0)");
        $r=$store->validating($r['submission_id'],$r['state_version'],static function(){});
        $r=$store->posting($r['submission_id'],$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        $r=$store->confirmPost($r['submission_id'],$r['state_version'],'501',static function(){},'1');
        $plugins=new pluginSystem;
        snapshot_assert(AF_ACS_PostLifecycleHooks::register($plugins),'Verified native registration blocked');
        $other=new class($store,$db) {
            public function __construct(private AF_ACS_SubmissionStore $store,private object $db) {}
            public function before(&$args):void { global $plugins;$nested=[];$plugins->run_hooks('fixture_nested_hook',$nested); }
            public function after(&$args):void { $this->store->assertMybbBoundary($this->db); }
        };
        $plugins->add_hook('class_moderation_approve_posts',[$other,'before'],50);
        $plugins->add_hook('class_moderation_approve_posts',[$other,'after'],200);

        $mybb->user['uid']=8;return $r;
    };
    $mechanics=static fn()=>array_intersect_key($capture(),array_flip(['entity_states','resources','turns','entity_turns','events','event_details','combat_results','combat_receipts','post_links','outbox','sealed_actions','rolls','snapshots']));
    $r=$make();$before=$mechanics();
    snapshot_assert($query->forPost('1',$accessNative)===null,'Narrative post acquired ACS status');
    snapshot_assert(!$query->forPost('501',$accessNative)['mechanics_confirmed'],'Pending card claims damage');
    $native->approve_posts([501]);AF_ACS_PostLifecycleHooks::flush();$r=$store->submission($r['submission_id']);
    snapshot_assert($r['status']==='moderation_pending'&&$mechanics()===$before,'Approval impersonated author/applied mechanics');
    $card=$query->forPost('501',$accessNative);snapshot_assert($card['approval_ready']&&!$card['mechanics_confirmed'],'Approved status unavailable');
    $audit=$capture()['publication_audit'];$ids=[501];$plugins->run_hooks('class_moderation_approve_posts',$ids);AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($audit===$capture()['publication_audit'],'Repeated observer duplicated audit');
    blocked(fn()=>$moderation->resumeApproved($r['submission_id'],$r['state_version']),'ACS_COMBAT_PERMISSION_DENIED');
    $mybb->user['uid']=7;$out=$service->publish($r['submission_id']);
    snapshot_assert($out['status']==='COMMITTED','Approved author path failed: '.json_encode($out));$receipt=$out['receipt'];$after=$mechanics();
    snapshot_assert($query->forPost('501',$accessNative)['mechanics_confirmed'],'COMMITTED status lost verified receipt');
    $mybb->user['uid']=8;$native->soft_delete_posts([501]);AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($store->submission($r['submission_id'])['status']==='committed'&&$mechanics()===$after,'Deletion rolled back committed history');
    $mybb->user['uid']=7;snapshot_assert($moderation->resumeApproved($r['submission_id'],'1')['receipt']===$receipt,'Changed post changed terminal receipt');
    $native->restore_posts([501]);AF_ACS_PostLifecycleHooks::flush();snapshot_assert($mechanics()===$after,'Restore replayed combat');
    $r=$make();$native->approve_posts([501]);AF_ACS_PostLifecycleHooks::flush();$r=$store->submission($r['submission_id']);$mybb->user['uid']=7;
    $recovery=new AF_ACS_RecoveryService($db,$accessNative);
    snapshot_assert($recovery->recover($r['submission_id'],$r['state_version'])['status']==='COMMITTED','Recovery did not use approved author path');
    $r=$make();$native->approve_posts([501]);AF_ACS_PostLifecycleHooks::flush();
    $link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1');$before=$mechanics();$r=$store->submission($r['submission_id']);$mybb->user['uid']=7;
    $out=$moderation->resumeApproved($r['submission_id'],$r['state_version']);snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Approval accepted stale seal');
    $r=$make();$before=$mechanics();$native->delete_post(501);AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required'&&$mechanics()===$before,'Hard delete before commit did not quarantine');
    $r=$make(false);$before=$mechanics();$native->unapprove_posts([501]);AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required'&&$mechanics()===$before,'Unapproval failed to block commit');
    $r=$make();$before=$mechanics();$native->soft_delete_posts([501]);AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required'&&$mechanics()===$before,'Soft delete pending failed');
    // These native branches hook before/mid-write. Exercise their real dispatcher and argument shape.
    $r=$make(false);$before=$mechanics();$args=['tid'=>51,'new_fid'=>2];$plugins->run_hooks('class_moderation_move_simple',$args);
    snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required','Pre-move hook left a commit window');
    $link->query('UPDATE fixture_threads SET fid=2');$link->query('UPDATE fixture_posts SET fid=2 WHERE pid=501');AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($mechanics()===$before,'Move changed combat');$r=$store->submission($r['submission_id']);
    snapshot_assert(str_contains($r['evidence_json'],'identity_conflict'),'Deferred move readback missing');
    $audit=$capture()['publication_audit'];$plugins->run_hooks('class_moderation_move_simple',$args);AF_ACS_PostLifecycleHooks::flush();snapshot_assert($audit===$capture()['publication_audit'],'Move replay duplicated observation');
    $r=$make(false);$args=['pids'=>[501],'tid'=>51,'moveto'=>1,'newsubject'=>'','destination_tid'=>52];$plugins->run_hooks('class_moderation_split_posts',$args);
    $link->query('UPDATE fixture_posts SET tid=52 WHERE pid=501');AF_ACS_PostLifecycleHooks::flush();snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required','Split/move failed to quarantine');
    $r=$make();$id=51;$plugins->run_hooks('class_moderation_delete_thread_start',$id);$link->query('DELETE FROM fixture_posts WHERE pid=501');AF_ACS_PostLifecycleHooks::flush();
    snapshot_assert($store->submission($r['submission_id'])['status']==='recovery_required','Thread deletion lost original links');
    $r=$make();$native->approve_posts([501]);AF_ACS_PostLifecycleHooks::flush();$r=$store->submission($r['submission_id']);$before=$capture();$mybb->user['uid']=7;$mybb->input['my_post_key']='invalid';
    blocked(fn()=>$moderation->resumeApproved($r['submission_id'],$r['state_version']),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';snapshot_assert($capture()===$before,'CSRF changed approved operation');
    $link->query("UPDATE fixture_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":false}'");$before=$mechanics();
    snapshot_assert($moderation->resumeApproved($r['submission_id'],$r['state_version'])['status']==='RECOVERY_REQUIRED'&&$mechanics()===$before,'Approval bypassed control grant');
    $mybb->version='1.8.42';snapshot_assert(!AF_ACS_PostLifecycleHooks::register(new pluginSystem),'Unverified core accepted');$mybb->version='1.8.41';
    snapshot_assert($capture()['rolls']===$baseline['rolls'],'Lifecycle rerolled tape');
    snapshot_assert($link->query("SHOW TABLE STATUS LIKE 'fixture_posts'")->fetch_assoc()['Engine']==='MyISAM','Lifecycle migrated native table');
    AF_ACS_PostLifecycleHooks::flush();echo "ACS lifecycle: real native approve/unapprove/delete/restore, author revalidation, conflict/grants/CSRF, immutable receipt, move/split/thread hooks and idempotent observations passed\n";
};
require __DIR__.'/acs_publish_service_regression.php';
