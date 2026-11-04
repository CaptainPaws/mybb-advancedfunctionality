<?php
declare(strict_types=1);
$GLOBALS['acsSealFixture']=static function(array $f):void{
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/SealService.php';
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    $migrations=new AF_ACS_Migrations($link);$link->query('DROP TABLE fixture_af_acs_rolls');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=1 WHERE version='007_sealed_actions'");$migrations->run();$migrations->run();snapshot_assert($migrations->status()['schema_version']===AF_ACS_Migrations::VERSION,'007 resume/repeat failed');
    $originalSnapshots=$link->query('SELECT * FROM fixture_af_acs_snapshots ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $drafts=new AF_ACS_DraftService($repo,$access);$draws=0;$pet=$registeredPet['entity_id'];$petState=$repo->entity($sid,$pet);$petResources=$repo->entityResources($pet);
    $random=new AF_ACS_RandomSource(function()use(&$draws,$link,$pet){$draws++;if($draws===5){
        // Test-only conflicting source change after deterministic preflight and before final simulation.
        $link->query("UPDATE fixture_af_acs_entity_states SET hp=0,life_state='incapacitated',state_version=state_version+1 WHERE entity_id=".$pet);
        $link->query("UPDATE fixture_af_acs_resources SET current_value=0,resource_version=resource_version+1 WHERE entity_id=".$pet." AND resource_key='hp'");
    }return 0;});$seal=new AF_ACS_SealService($repo,$access,$random);
    $beforeGame=[];foreach(['sessions','encounters','rounds','turns','entity_turns','entities','entity_states','resources','controllers','events','event_details','cooldowns'] as $t)$beforeGame[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    $initial=$drafts->restore($saved['draft_id']);
    $req=['draft_id'=>$saved['draft_id'],'expected_draft_version'=>$initial['draft_version'],'operation_key'=>'seal_batch_fixture_01'];
    $view=$seal->command('seal',$req);snapshot_assert($draws===4&&$view['active_count']===2&&$view['quota_used']===2&&!$view['blocked']&&$view['confirmable'],'Seal batch failed: '.json_encode($view));
    snapshot_assert($seal->command('seal',$req)===$view&&$draws===4,'Duplicate seal rerolled');
    $rolls=$link->query('SELECT * FROM fixture_af_acs_rolls ORDER BY id')->fetch_all(MYSQLI_ASSOC);$immutable=$repo->sealRows($saved['draft_id']);
    $sealedDraft=$drafts->restore($saved['draft_id']);
    $status=$seal->status($saved['draft_id']);snapshot_assert($status['history']===$view['history']&&$draws===4,'GET status changed tape');
    $req2=$req;$req2['expected_draft_version']=$view['draft_version'];blocked(fn()=>$seal->command('seal',$req2),'ACS_OPERATION_KEY_CONFLICT');
    $req2['operation_key']='seal_nothing_fixture_02';blocked(fn()=>$seal->command('seal',$req2),'ACS_SEAL_NOTHING_TO_APPEND');
    $bad=['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$saved['draft_id'],'expected_draft_version'=>$view['draft_version'],'operation_key'=>'seal_edit_prefix_fixture','prose'=>'','actions'=>$actions];$bad['actions'][0]['targets']=[$registeredPet['entity_id']];
    blocked(fn()=>$drafts->save($bad),'ACS_SEAL_IMMUTABLE');
    $confirm=['draft_id'=>$saved['draft_id'],'expected_draft_version'=>$view['draft_version'],'operation_key'=>'seal_confirm_fixture_03','confirmation_hash'=>$status['confirmation_hash']];$confirmed=$seal->command('confirm',$confirm);snapshot_assert($confirmed['confirmed']&&$draws===4,'Confirm generated rolls or failed');
    $prose=['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$saved['draft_id'],'expected_draft_version'=>$confirmed['draft_version'],'operation_key'=>'seal_prose_only_fixture','prose'=>'Only prose changed','actions'=>$actions];$proseSaved=$drafts->save($prose);$confirmed['draft_version']=$proseSaved['draft_version'];$confirmedDraft=$drafts->restore($saved['draft_id']);snapshot_assert($seal->status($saved['draft_id'])['confirmed'],'Prose invalidated mechanical confirmation');
    $mybb->input['af_aa_mask']='changed-decoration';snapshot_assert($seal->status($saved['draft_id'])['confirmation_hash']===$confirmed['confirmation_hash']&&$seal->status($saved['draft_id'])['confirmed'],'Mask invalidated confirmation');unset($mybb->input['af_aa_mask']);
    // A real source state change is simulated explicitly in the isolated fixture, not by seal.
    $target=$first['entity_id'];$oldState=$repo->entity($sid,$target);$resources=$repo->entityResources($target);
    $link->query('UPDATE fixture_af_acs_entity_states SET hp=35,state_version=state_version+1 WHERE entity_id='.$target);
    $link->query("UPDATE fixture_af_acs_resources SET current_value=35,resource_version=resource_version+1 WHERE entity_id=".$target." AND resource_key='hp'");
    $changed=$seal->status($saved['draft_id']);snapshot_assert($changed['changed']&&!$changed['confirmed']&&!$changed['blocked']&&$draws===4,'Conflict did not reuse tape');
    $oldConfirm=$confirm;$oldConfirm['expected_draft_version']=$confirmed['draft_version'];$oldConfirm['operation_key']='seal_stale_confirm_04';blocked(fn()=>$seal->command('confirm',$oldConfirm),'ACS_SEAL_SUMMARY_CHANGED');
    $oldConfirm['operation_key']='seal_reconfirm_fixture05';$oldConfirm['confirmation_hash']=$changed['confirmation_hash'];$again=$seal->command('confirm',$oldConfirm);snapshot_assert($again['confirmed']&&$draws===4,'Reconfirm rerolled');
    // Restore the fixture's external change so the final invariants compare only seal consequences.
    $st=$link->prepare('UPDATE fixture_af_acs_entity_states SET hp=?,state_version=? WHERE entity_id=?');$st->bind_param('sss',$oldState['hp'],$oldState['state_version'],$target);$st->execute();
    foreach($resources as $r){$st=$link->prepare('UPDATE fixture_af_acs_resources SET current_value=?,resource_version=? WHERE id=?');$st->bind_param('sss',$r['current_value'],$r['resource_version'],$r['id']);$st->execute();}
    $cancel=['draft_id'=>$saved['draft_id'],'expected_draft_version'=>$again['draft_version'],'operation_key'=>'seal_cancel_fixture_06'];$cancelled=$seal->command('cancel_tail',$cancel);
    snapshot_assert($cancelled['active_count']===1&&$cancelled['quota_used']===2&&$seal->command('cancel_tail',$cancel)===$cancelled&&$draws===4,'Cancel refunded quota or repeated');
    $cancelledDraft=$drafts->restore($saved['draft_id']);
    $now=$cancelledDraft;snapshot_assert(count($now['actions'])===1&&count($now['sealed_prefix'])===1,'Cancel prefix projection wrong');
    $append=['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$saved['draft_id'],'expected_draft_version'=>$now['draft_version'],'operation_key'=>'seal_append_draft_07','prose'=>'После отмены','actions'=>$actions];$appended=$drafts->save($append);
    $seal2=['draft_id'=>$saved['draft_id'],'expected_draft_version'=>$appended['draft_version'],'operation_key'=>'seal_append_fixture_08'];$next=$seal->command('seal',$seal2);snapshot_assert($next['quota_used']===3&&$next['active_count']===2&&$draws===6,'Append did not consume remaining quota');
    snapshot_assert($next['blocked'],'Post-draw conflict discarded the attempted seal');
    $st=$link->prepare('UPDATE fixture_af_acs_entity_states SET hp=?,life_state=?,state_version=? WHERE entity_id=?');$st->bind_param('ssss',$petState['hp'],$petState['life_state'],$petState['state_version'],$pet);$st->execute();
    foreach($petResources as $r){$st=$link->prepare('UPDATE fixture_af_acs_resources SET current_value=?,resource_version=? WHERE id=?');$st->bind_param('sss',$r['current_value'],$r['resource_version'],$r['id']);$st->execute();}
    snapshot_assert(!$seal->status($saved['draft_id'])['blocked']&&$draws===6,'Blocked seal did not recover with retained tape');

    $cancel['expected_draft_version']=$next['draft_version'];$cancel['operation_key']='seal_cancel_fixture_09';$last=$seal->command('cancel_tail',$cancel);
    $append['expected_draft_version']=$last['draft_version'];$append['operation_key']='seal_append_draft_10';$lastDraft=$drafts->save($append);$seal2['expected_draft_version']=$lastDraft['draft_version'];$seal2['operation_key']='seal_exhausted_fixture11';blocked(fn()=>$seal->command('seal',$seal2),'ACS_SEAL_QUOTA_EXHAUSTED');snapshot_assert($draws===6,'Exhausted quota drew new rolls');
    snapshot_assert(array_slice($link->query('SELECT * FROM fixture_af_acs_rolls ORDER BY id')->fetch_all(MYSQLI_ASSOC),0,4)===$rolls,'Original tape rewritten');
    foreach($immutable as $row){$updated=$repo->sealRows($saved['draft_id'])[$row['action_index']];unset($updated['status'],$row['status']);snapshot_assert($updated===$row,'Sealed immutable payload changed');}
    foreach($beforeGame as $t=>$rows)snapshot_assert($link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)===$rows,'Seal changed combat state '.$t);
    $afterSnapshots=$link->query('SELECT * FROM fixture_af_acs_snapshots ORDER BY id')->fetch_all(MYSQLI_ASSOC);snapshot_assert(array_slice($afterSnapshots,0,count($originalSnapshots))===$originalSnapshots,'Original CombatSnapshots changed');
    $mybb->input=['my_post_key'=>'invalid'];blocked(fn()=>$seal->command('seal',$seal2),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];
    $link->query("UPDATE fixture_af_acs_encounters SET status='paused' WHERE id=".$eid);blocked(fn()=>$seal->command('seal',$seal2),'ACS_SEAL_TURN_UNAVAILABLE');$link->query("UPDATE fixture_af_acs_encounters SET status='active' WHERE id=".$eid);
    $mybb->user['uid']=8;blocked(fn()=>$seal->status($saved['draft_id']),'ACS_DRAFT_UNAVAILABLE');$mybb->user['uid']=7;
    $mybb->request_method='get';blocked(fn()=>$seal->command('seal',$seal2),'ACS_POST_REQUIRED');$mybb->request_method='post';
    snapshot_assert($draws===6,'Read/denied operation used RNG');
    if(getenv('ACS_SEAL_DOM_FIXTURE')==='1'){$mybb->input=['acs_encounter'=>$eid];file_put_contents('/tmp/acs423-seal-fixture.json',json_encode(['html'=>AF_ACS_BuilderController::renderBuilder($sid,'51',$builder),'context'=>$builder->context($sid,$eid,$turnId),'initial'=>$initial,'sealed'=>$view,'sealedDraft'=>$sealedDraft,'changed'=>$changed,'confirmed'=>$confirmed,'confirmedDraft'=>$confirmedDraft,'cancelled'=>$cancelled,'cancelledDraft'=>$cancelledDraft],JSON_THROW_ON_ERROR));}
    if (isset($GLOBALS['acsPublicationFixture'])) { ($GLOBALS['acsPublicationFixture'])(get_defined_vars()); }
    echo "ACS Seal: one tape/replay, pinned append-only actions, cancel history/no quota refund, shared AP quota, conflict/reconfirm same rolls, mask separation and no combat mutations passed\n";
};
require __DIR__.'/acs_builder_regression.php';
