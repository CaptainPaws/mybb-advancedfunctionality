<?php
declare(strict_types=1);
// Reuse the isolated round fixture and its real ACS persistence, no production DB.
$GLOBALS['acsResourcesFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/ApiController.php';
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    $access=new class extends AF_ACS_SessionAccess {
        public function thread(string $tid,?string $uid=null):array {if($tid!=='51')throw new RuntimeException('ACS_THREAD_UNAVAILABLE');return ['tid'=>51,'fid'=>1,'closed'=>0,'uid'=>99];}
    };
    $repo=new AF_ACS_EncounterRepository($link);$drafts=new AF_ACS_DraftService($repo,$access);
    // Migration 006 readback/resume and repeat, before any draft exists.
    $link->query('DROP TABLE fixture_af_acs_drafts');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=0 WHERE version='006_drafts'");$migrations->run();$migrations->run();
    $actor=$result['entity_id'];$turn=$groups[$actor];
    $capture=static function()use($link):array{
        $out=[];foreach(['sessions','session_roles','encounters','rounds','turns','entity_turns','control_groups','participants','entities','entity_states','resources','controllers','snapshots','events','event_details','diagnostics','cooldowns'] as $t)$out[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;
    };
    $before=$capture();$ownerBefore=$db->tables;
    $input=['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turn,'expected_draft_version'=>'0','operation_key'=>'draft_create_fixture_01','prose'=>"Отыгрыш [b]героя[/b]\nСтрока 2",'actions'=>[['actor_id'=>$actor,'ability_key'=>'unsealed-plan','targets'=>[$first['entity_id']],'parameters'=>['choice'=>'first']]]];
    $saved=AF_ACS_ApiController::handle($drafts,'draft_save',$input);snapshot_assert($saved['draft_version']==='1','Create did not return version 1');
    snapshot_assert($drafts->save($input)===$saved,'Operation replay changed receipt');
    $restored=$drafts->restore($saved['draft_id']);snapshot_assert($restored['prose']===$input['prose']&&$restored['actions'][0]['parameters']===['choice'=>'first']&&$restored['actions'][0]['actor_id']===$actor&&!$restored['validated_for_execution'],'Round trip lost data');
    $other=$input;$other['operation_key']='draft_duplicate_create_02';blocked(fn()=>$drafts->save($other),'ACS_DRAFT_ALREADY_EXISTS');
    $edited=$input;$edited['draft_id']=$saved['draft_id'];$edited['expected_draft_version']='1';$edited['operation_key']='draft_update_fixture_03';$edited['prose']='Новый текст';$edited['actions']=[];
    $secondSave=$drafts->save($edited);snapshot_assert($secondSave['draft_version']==='2','CAS update failed');
    snapshot_assert($drafts->save($edited)===$secondSave,'CAS retry incremented version');
    $stale=$edited;$stale['operation_key']='draft_stale_fixture_04';$stale['prose']='Устаревшая вкладка';blocked(fn()=>$drafts->save($stale),'ACS_DRAFT_VERSION_CONFLICT');
    snapshot_assert($drafts->restore($saved['draft_id'])['prose']==='Новый текст','Stale tab overwrote prose');
    $sameKey=$edited;$sameKey['prose']='Changed payload';blocked(fn()=>$drafts->save($sameKey),'ACS_OPERATION_KEY_CONFLICT');
    $GLOBALS['fixture_deny_post']=true;blocked(fn()=>$drafts->save($edited),'ACS_DRAFT_UNAVAILABLE');unset($GLOBALS['fixture_deny_post']);
    $GLOBALS['fixture_reply_own_only']=true;blocked(fn()=>$drafts->save($edited),'ACS_DRAFT_UNAVAILABLE');unset($GLOBALS['fixture_reply_own_only']);
    $mybb->user['suspendposting']=1;blocked(fn()=>$drafts->save($edited),'ACS_DRAFT_UNAVAILABLE');$mybb->user['suspendposting']=0;
    $mybb->request_method='get';blocked(fn()=>$drafts->save($edited),'ACS_POST_REQUIRED');
    $read=AF_ACS_ApiController::handle($drafts,'draft_restore',['draft_id'=>$saved['draft_id']]);snapshot_assert($read['draft_version']==='2','GET restore failed');
    $readBefore=$link->query('SELECT * FROM fixture_af_acs_operations ORDER BY 1')->fetch_all(MYSQLI_ASSOC);$drafts->restore($saved['draft_id']);snapshot_assert($link->query('SELECT * FROM fixture_af_acs_operations ORDER BY 1')->fetch_all(MYSQLI_ASSOC)===$readBefore,'Restore wrote ledger');
    $mybb->request_method='post';$mybb->input=['my_post_key'=>'invalid'];blocked(fn()=>$drafts->save($edited),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];
    $mybb->user['uid']=8;blocked(fn()=>$drafts->restore($saved['draft_id']),'ACS_DRAFT_UNAVAILABLE');
    $foreign=$input;$foreign['operation_key']='draft_foreign_fixture_05';blocked(fn()=>$drafts->save($foreign),'ACS_DRAFT_UNAVAILABLE');$mybb->user['uid']=7;
    $foreign=$input;$foreign['turn_id']=$groups[$first['entity_id']];$foreign['operation_key']='draft_gm_bypass_fixture06';blocked(fn()=>$drafts->save($foreign),'ACS_DRAFT_UNAVAILABLE');
    $inject=$input;$inject['operation_key']='draft_seal_inject_fixture';$inject['actions'][0]['rolls']=[99];blocked(fn()=>$drafts->save($inject),'ACS_DRAFT_INPUT_INVALID');
    // Post-update replay of original creation returns only its historic receipt, not a new row/content overwrite.
    snapshot_assert($drafts->save($input)===$saved&&$drafts->restore($saved['draft_id'])['draft_version']==='2','Old replay changed current draft');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_drafts')->fetch_row()[0]==='1','Duplicate draft created');
    $link->query('UPDATE fixture_af_acs_turns SET status=\'passed\' WHERE id='.$turn);
    snapshot_assert(!$drafts->restore($saved['draft_id'])['editable'],'Closed turn offered editing');
    blocked(fn()=>$drafts->save($edited),'ACS_DRAFT_TURN_UNAVAILABLE');
    $link->query('UPDATE fixture_af_acs_turns SET status=\'pending\' WHERE id='.$turn);
    $link->query('UPDATE fixture_af_acs_controllers SET status=\'inactive\' WHERE entity_id='.$actor);
    // The shared pet still grants group access; actions were removed, so revoke its control too.
    $pet=$registeredPet['entity_id'];$link->query('UPDATE fixture_af_acs_controllers SET status=\'inactive\' WHERE entity_id='.$pet);
    blocked(fn()=>$drafts->restore($saved['draft_id']),'ACS_DRAFT_UNAVAILABLE');
    $link->query('UPDATE fixture_af_acs_controllers SET status=\'active\' WHERE entity_id IN ('.$actor.','.$pet.')');
    snapshot_assert($capture()===$before&&$db->tables===$ownerBefore,'Draft affected mechanics, events, diagnostics or owner data');
    $ops=$link->query("SELECT request_json,result_json FROM fixture_af_acs_operations WHERE kind='draft.save'")->fetch_all(MYSQLI_ASSOC);snapshot_assert(count($ops)===2&&!str_contains(json_encode($ops),'Новый текст'),'Receipt leaked prose');
    $migrations->run();snapshot_assert($drafts->restore($saved['draft_id'])['draft_version']==='2','Repeat migrations reset draft');
    echo "ACS Drafts: migration resume/repeat, create/replay/update, CAS stale tab, restore, CSRF/control/ownership, no RNG/combat events/mechanical mutations passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
