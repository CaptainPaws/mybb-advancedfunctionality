<?php
declare(strict_types=1);
if (($argv[3]??'')==='--entry')ob_start();
define('AF_ACS_ENVIRONMENT','test');define('AF_ACS_PUBLICATION_TEST_UIDS',[7,8]);define('AF_ACS_PUBLICATION_TEST_ORIGIN','http://fixture.local');
$GLOBALS['acsEditorPublicationFixture']=static function(array $f):void {
    extract($f);global $mybb,$db,$plugins;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/EditorPublicationController.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/EditorPublicationService.php';
    $mybb->settings['bburl']='http://fixture.local';$_SERVER['HTTP_HOST']='fixture.local';
    $editor=new AF_ACS_EditorPublicationService($db,$accessNative);
    $input=static function(string $op,array $extra=[],string $method='post')use(&$mybb):void {
        $mybb->request_method=$method;$mybb->input=['my_post_key'=>'token','acs_mode'=>'combat','acs_operation'=>$op,'tid'=>'51','action'=>'do_newreply']+$extra;
    };
    $make=static function()use($reset,$plugins,$store,$baseDraft,&$mybb):void {
        $reset();$mybb->request_method='post';$mybb->user['uid']=7;
    };
    if (($GLOBALS['argv'][3]??'')==='--entry') {
        $make();$input('prepare',['acs_draft_id'=>$baseDraft['id'],'acs_draft_version'=>$baseDraft['draft_version'],'acs_confirmation_hash'=>$baseDraft['confirmation_hash'],'message'=>$baseDraft['prose']]);
        $ready=AF_ACS_EditorPublicationController::handle($accessNative);$id=$ready['operation']['submission_id'];
        $input('publish',['acs_submission_id'=>$id,'message'=>$baseDraft['prose']]);$mybb->input['action']='acs_publication';
        require MYBB_ROOT.'inc/class_plugins.php';$plugins=new pluginSystem;
        $observer=new class {public array $calls=[];public function observe():void {global $plugins;$this->calls[]=$plugins->current_hook;}};
        foreach(['newreply_do_newreply_start','newreply_do_newreply_end','datahandler_post_insert_post'] as $hook)$plugins->add_hook($hook,[$observer,'observe']);
        $name=$link->query('SELECT DATABASE()')->fetch_row()[0];$marker=$GLOBALS['argv'][4];
        register_shutdown_function(static function()use($link,$name,$marker,$observer):void {
            $ok=$link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]==='2' && $link->query('SELECT COUNT(*) FROM fixture_af_acs_combat_receipts')->fetch_row()[0]==='1';
            foreach(['newreply_do_newreply_start','newreply_do_newreply_end','datahandler_post_insert_post'] as $h)$ok=$ok&&count(array_keys($observer->calls,$h,true))===1;
            file_put_contents($marker,json_encode(['ok'=>$ok,'hooks'=>$observer->calls]));
            if(preg_match('/\Aacs222_[a-f0-9]+\z/D',$name))$link->query('DROP DATABASE `'.$name.'`');
        });
        require_once AF_ADDONS.'advancedcombatsystem/modules/Bootstrap/EditorPublicationEntry.php';
        af_advancedcombatsystem_reply_guard();ob_clean();$plugins->run_hooks('global_end');throw new RuntimeException('Native fallthrough');
    }
    $make();$input('compose',['acs_draft_id'=>$baseDraft['id']],'get');
    $d=AF_ACS_EditorPublicationController::handle($accessNative);snapshot_assert($d['confirmed']&&$d['summary']&&!$d['operation'],'Composer did not use confirmed sealed draft');
    $before=$capture();$prose="New prose\n[b]same sealed actions[/b]";
    // Existing autosave contract; no new persistence or editor instance.
    $mybb->request_method='post';$saved=(new AF_ACS_DraftService($repo,$accessNative))->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],'operation_key'=>'editor_narrative_save_01','prose'=>$prose,'actions'=>$d['actions']]);
    $prepare=['acs_draft_id'=>$d['draft_id'],'acs_draft_version'=>$saved['draft_version'],'acs_confirmation_hash'=>$d['confirmation_hash'],'message'=>$prose];
    $input('prepare',$prepare);$ready=AF_ACS_EditorPublicationController::handle($accessNative);$id=$ready['operation']['submission_id'];
    $plugins->context=AF_ACS_MybbPostContext::fromStore($store,$id,$ready['operation']['state_version']);
    snapshot_assert($ready['summary']&&$ready['operation']['status']==='REGISTERED','Prepare did not freeze confirmed draft');
    $again=AF_ACS_EditorPublicationController::handle($accessNative);snapshot_assert($again['operation']['submission_id']===$id,'Lost prepare response changed submission');
    $input('publish',['acs_submission_id'=>$id,'message'=>'Unsaved changed prose']);blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_EDITOR_FROZEN_PROSE_CHANGED');
    snapshot_assert($countPosts()===1,'Changed prose reached native insert');
    $input('publish',['acs_submission_id'=>$id,'message'=>str_replace("\n","\r\n",$prose),'subject'=>'Combat']);
    $mybb->input['af_aa_mask']='cosmetic-only';$out=AF_ACS_EditorPublicationController::handle($accessNative);
    snapshot_assert($out['status']==='COMMITTED'&&$countPosts()===2,'Single reply publication failed');$calls=$plugins->calls;$after=$capture();
    snapshot_assert(AF_ACS_EditorPublicationController::handle($accessNative)===$out&&$capture()===$after&&$plugins->calls===$calls,'Repeated HTTP request duplicated mechanics/post');
    $input('status',['acs_submission_id'=>$id],'get');$status=AF_ACS_EditorPublicationController::handle($accessNative);snapshot_assert($status['receipt']===$out['receipt'],'Status did not return stable receipt');
    $input('compose',['acs_draft_id'=>$baseDraft['id']],'get');$closed=AF_ACS_EditorPublicationController::handle($accessNative);snapshot_assert($closed['summary']===null&&$closed['operation']['status']==='COMMITTED','Committed composer recomputed summary');
    snapshot_assert($capture()['rolls']===$before['rolls'],'Editor changed sealed tape');
    foreach(['quick','moderation','late_throw'] as $case) {
        $make();$input('prepare',['acs_draft_id'=>$baseDraft['id'],'acs_draft_version'=>$baseDraft['draft_version'],'acs_confirmation_hash'=>$baseDraft['confirmation_hash'],'message'=>$baseDraft['prose']]);
        $r=AF_ACS_EditorPublicationController::handle($accessNative);$id=$r['operation']['submission_id'];$plugins->context=AF_ACS_MybbPostContext::fromStore($store,$id,$r['operation']['state_version']);
        $input('publish',['acs_submission_id'=>$id,'message'=>$baseDraft['prose'],'method'=>'quickreply']);
        if($case==='moderation')$GLOBALS['acsGatewayModeration']=true;if($case==='late_throw')$plugins->mode='late_throw';
        $before=$capture();$out=AF_ACS_EditorPublicationController::handle($accessNative);
        snapshot_assert($out['status']===['quick'=>'COMMITTED','moderation'=>'MODERATION_PENDING','late_throw'=>'RECOVERY_REQUIRED'][$case],$case.' wrong outcome');
        $calls=$plugins->calls;$posts=$countPosts();AF_ACS_EditorPublicationController::handle($accessNative);snapshot_assert($calls===$plugins->calls&&$countPosts()===$posts,$case.' repeated native lifecycle');
        snapshot_assert($capture()['rolls']===$before['rolls'],$case.' rerolled tape');
        if($case!=='quick')foreach(['entity_states','resources','turns','combat_receipts','outbox'] as $t)snapshot_assert($capture()[$t]===$before[$t],$case.' applied unconfirmed mechanics');
    }
    $make();$input('prepare',['acs_draft_id'=>$baseDraft['id'],'acs_draft_version'=>$baseDraft['draft_version'],'acs_confirmation_hash'=>$baseDraft['confirmation_hash'],'message'=>$baseDraft['prose']]);
    $_FILES=['attachments'=>['name'=>'file.txt']];blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_MYBB_ATTACHMENTS_UNSUPPORTED');$_FILES=[];
    $mybb->input['posthash']=str_repeat('a',32);$link->query("INSERT INTO fixture_attachments VALUES(1,0,7,REPEAT('a',32),1)");
    blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_MYBB_ATTACHMENTS_UNSUPPORTED');$link->query('TRUNCATE fixture_attachments');unset($mybb->input['posthash']);
    $mybb->input['savedraft']='1';blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_MYBB_BRANCH_UNSUPPORTED');unset($mybb->input['savedraft']);
    $mybb->input['my_post_key']='invalid';blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $mybb->user['uid']=8;blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_DRAFT_UNAVAILABLE');$mybb->user['uid']=7;
    $_SERVER['HTTP_HOST']='other.local';blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_EDITOR_TEST_DISABLED');$_SERVER['HTTP_HOST']='fixture.local';
    $mybb->user['uid']=9;blocked(fn()=>AF_ACS_EditorPublicationController::handle($accessNative),'ACS_EDITOR_TEST_DISABLED');$mybb->user['uid']=7;
    snapshot_assert($countPosts()===1,'Rejected editor request fell through to native publication');
    require_once AF_ADDONS.'advancedcombatsystem/modules/Bootstrap/EditorPublicationEntry.php';
    $mybb->input=['acs_mode'=>'narrative','acs_operation'=>''];$before=$capture();af_advancedcombatsystem_reply_entry();snapshot_assert($capture()===$before&&$countPosts()===1,'Narrative entry invoked combat');
    echo "ACS editor: compose/autosave/freeze, identity replay, native/quick reply, moderation/recovery, unchanged tape, attachment/CSRF/actor/test-origin gates and narrative passthrough passed\n";
};
require __DIR__.'/acs_publish_service_regression.php';
