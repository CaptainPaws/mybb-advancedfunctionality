<?php
declare(strict_types=1);
// Bounded upstream native handler + real ACS journal, Resolver and transactional writer.
$GLOBALS['acsPublishServiceFixture']=static function(array $f):void {
    extract($f);global $mybb,$db,$plugins;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/PublishService.php';
    $service=new AF_ACS_PublishService($db,$accessNative);
    $link->query('CREATE TABLE fixture_forums (fid int PRIMARY KEY,open int,type varchar(10)) ENGINE=MyISAM');
    $link->query("INSERT INTO fixture_forums VALUES(1,1,'f')");
    // Fixture-only explicit authorization; runtime never upgrades a controller grant.
    foreach($active as $a) $link->query("UPDATE fixture_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":true}' WHERE entity_id=".$a['actor_id']);
    $tables=[];
    foreach(glob(AF_ADDONS.'advancedcombatsystem/migrations/[0-9][0-9][0-9]_*.php') as $file) foreach(array_keys((require $file)['tables']) as $t) if($t!=='schema_migrations')$tables[]=$t;
    $capture=static function()use($link,$tables):array {
        $out=[];foreach($tables as $t)$out[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;
    };
    $baseline=$capture();
    $reset=static function()use($link,$baseline,$plugins,&$mybb):void {
        foreach($baseline as $t=>$rows) {
            $link->query('DELETE FROM fixture_af_acs_'.$t);
            foreach($rows as $r) {
                $v=array_values($r);$st=$link->prepare('INSERT INTO fixture_af_acs_'.$t.' (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_fill(0,count($v),'?')).')');
                $st->bind_param(str_repeat('s',count($v)),...$v);$st->execute();$st->close();
            }
        }
        $link->query('TRUNCATE fixture_posts');$link->query("INSERT INTO fixture_posts(pid,uid,tid,fid,visible,message,dateline) VALUES(1,8,51,1,1,'Topic',0)");
        $mybb->input=['my_post_key'=>'token'];$mybb->user['uid']=7;$plugins->calls=[];$plugins->mode='';
        $GLOBALS['acsGatewayModeration']=false;$GLOBALS['acsGatewayLastPost']=0;
    };
    $request=['draft_id'=>$baseDraft['id'],'expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']];
    $issue=static function()use($reset,$service,$request,$plugins,$store):array {
        $reset();$r=$service->issue($request);
        $plugins->context=AF_ACS_MybbPostContext::fromStore($store,$r['submission_id'],$r['state_version']);return $r;
    };
    $mechanics=static function()use($capture):array {
        return array_intersect_key($capture(),array_flip(['entity_states','resources','turns','events','event_details','sealed_actions','rolls','snapshots','combat_results','combat_receipts','post_links','outbox']));
    };
    $countPosts=static fn()=>(int)$link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0];
    $r=$issue();$id=$r['submission_id'];snapshot_assert($service->issue($request)===$r,'Lost issuance response changed identity');
    $before=$capture();$result=$service->publish($id);
    snapshot_assert($result['status']==='COMMITTED'&&$result['mechanics_confirmed']&&$result['pid']==='2','Native-to-combat chain failed: '.json_encode($result));
    snapshot_assert($countPosts()===2,'Successful publish duplicated post');
    $committed=$capture();$calls=$plugins->calls;
    snapshot_assert($service->issue($request)===$result,'Issuance replay lost terminal receipt');
    snapshot_assert($service->publish($id)===$result,'Lost commit response/repeated submission changed receipt');
    snapshot_assert($capture()===$committed&&$plugins->calls===$calls&&$countPosts()===2,'Replay repeated effects');
    snapshot_assert($committed['rolls']===$before['rolls'],'Publication changed RNG tape');
    foreach(['newreply_do_newreply_start','datahandler_post_insert_post','newreply_do_newreply_end'] as $h) snapshot_assert(count(array_keys($calls,$h,true))===1,'Duplicate native lifecycle');
    $r=$issue();$id=$r['submission_id'];$plugins->mode='service_reenter';
    $GLOBALS['acsPublishReenter']=static function()use($service,$id):void {
        $nested=$service->publish($id);snapshot_assert($nested['status']==='POSTING'&&!$nested['mechanics_confirmed'],'Recursive coordinator dispatched');
    };
    snapshot_assert($service->publish($id)['status']==='COMMITTED'&&$countPosts()===2,'Recursive request disrupted publication');
    unset($GLOBALS['acsPublishReenter']);
    // Lost response after known native confirmation, before any mechanical transaction.
    $r=$issue();$id=$r['submission_id'];$row=$store->validating($id,$r['state_version'],static function(){});
    $gateway->publish(AF_ACS_MybbPostContext::fromStore($store,$id,$row['state_version']));$calls=$plugins->calls;
    snapshot_assert($service->publish($id)['status']==='COMMITTED'&&$calls===$plugins->calls&&$countPosts()===2,'Confirmed-post resume repeated native insertion');
    foreach(['invalid'=>'REJECTED','late_throw'=>'RECOVERY_REQUIRED','state_conflict'=>'RECOVERY_REQUIRED'] as $mode=>$status) {
        $r=$issue();$before=$mechanics();$plugins->mode=$mode;$out=$service->publish($r['submission_id']);
        snapshot_assert($out['status']===$status&&!$out['mechanics_confirmed'],$mode.' wrong outcome: '.json_encode($out));
        $after=$mechanics();if($mode==='state_conflict') { $after['entity_states']=$before['entity_states']; }
        snapshot_assert($after===$before,$mode.' applied mechanics');
        $calls=$plugins->calls;$posts=$countPosts();$service->publish($r['submission_id']);
        snapshot_assert($calls===$plugins->calls&&$posts===$countPosts(),$mode.' replay dispatched again');
        snapshot_assert($posts===($mode==='invalid'?1:2),$mode.' wrong native insertion count');
    }
    $r=$issue();$before=$mechanics();
    $link->query("CREATE TRIGGER fixture_publish_fail BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $out=$service->publish($r['submission_id']);$link->query('DROP TRIGGER fixture_publish_fail');
    snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$out['pid']==='2'&&$mechanics()===$before&&$countPosts()===2,'Post-created combat rollback failed');
    $r=$issue();$before=$mechanics();$GLOBALS['acsGatewayModeration']=true;$out=$service->publish($r['submission_id']);
    snapshot_assert($out['status']==='MODERATION_PENDING'&&!$out['mechanics_confirmed']&&$mechanics()===$before,'Moderation applied mechanics');
    $calls=$plugins->calls;$service->publish($r['submission_id']);snapshot_assert($calls===$plugins->calls&&$countPosts()===2,'Pending replay republished');
    $r=$issue();$link->query("UPDATE fixture_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":false}'");
    snapshot_assert($service->publish($r['submission_id'])['status']==='REJECTED'&&$countPosts()===1,'Missing combat grant reached native insert');
    $r=$issue();$link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1');$out=$service->publish($r['submission_id']);
    snapshot_assert($out['status']==='REJECTED'&&$countPosts()===1&&$plugins->calls===[],'Stale seal reached MyBB');
    $r=$issue();$mybb->input['my_post_key']='invalid';blocked(fn()=>$service->publish($r['submission_id']),'ACS_CSRF_REQUIRED');
    $mybb->input['my_post_key']='token';$mybb->user['uid']=8;blocked(fn()=>$service->publish($r['submission_id']),'ACS_COMBAT_PERMISSION_DENIED');$mybb->user['uid']=7;
    snapshot_assert($store->submission($r['submission_id'])['status']==='registered'&&$countPosts()===1,'Unauthorized request changed intent');
    $r=$issue();$meta=json_decode($store->submission($r['submission_id'])['sealed_json'],true);
    $store->validating($r['submission_id'],$r['state_version'],static function(){});
    $row=$store->submission($r['submission_id']);$controls=json_decode($meta['dependency_json'],true)['seal'];$controls['confirmation_hash']=$meta['confirmation_hash'];
    $store->posting($r['submission_id'],$row['state_version'],$controls,static function(){});
    $out=$service->publish($r['submission_id']);snapshot_assert($out['status']==='RECOVERY_REQUIRED'&&$countPosts()===1&&$plugins->calls===[],'Lost POSTING response retried insert');
    echo "ACS PublishService: issuance/replay, native-to-commit, pre/post-insert failure, rollback, version conflicts, moderation, immutable tape and authorization passed\n";
};
require __DIR__.'/acs_mybb_gateway_regression.php';
