<?php
declare(strict_types=1);
function af_frontend_asset_allowed($addon,$resource=null,$context=null,$facts=[]):bool{return $addon==='advancedcombatsystem'&&$resource==='builder'&&!empty($facts['has_acs_builder']);}
$GLOBALS['acsBuilderFixture']=static function(array $f):void{
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/BuilderService.php';
    $access=new class extends AF_ACS_SessionAccess {
        public function thread(string $tid,?string $uid=null):array {if($tid!=='51')throw new RuntimeException('ACS_THREAD_UNAVAILABLE');return ['tid'=>51,'fid'=>1,'uid'=>7,'closed'=>0];}
    };
    $repo=new AF_ACS_EncounterRepository($link);$builder=new AF_ACS_BuilderService($repo,$access);$mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // Test-only pinned pet grant, prepared before capturing UI/preview invariants.
    $pet=$registeredPet['entity_id'];$row=$repo->entity($sid,$pet);$payload=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
    $payload['pinned_abilities']=['preview-fixture'=>$ability];$payload['source_snapshot']['abilities'][]=['definition'=>['references'=>['ability_kb_key'=>$grant]]];
    $json=AF_ACS_Repository::json($payload);$hash=hash('sha256',$json);$stmt=$link->prepare('UPDATE fixture_af_acs_snapshots SET payload_json=?,content_hash=? WHERE id=?');$stmt->bind_param('sss',$json,$hash,$row['template_snapshot_id']);$stmt->execute();
    $turnId=$groups[$result['entity_id']];$turn=$repo->clockTurn($sid,$turnId);$roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);foreach($roster['members'] as &$m)if($m['entity_id']===$pet)$m['content_hash']=$hash;unset($m);unset($roster['fingerprint']);$roster['fingerprint']=hash('sha256',AF_ACS_Repository::json($roster));$json=AF_ACS_Repository::json($roster);$stmt=$link->prepare('UPDATE fixture_af_acs_turns SET roster_json=? WHERE id=?');$stmt->bind_param('ss',$json,$turnId);$stmt->execute();
    $v=$service->view($sid,$eid);$service->clock()->command('entity_start',['session_id'=>$sid,'expected_version'=>$v['session']['session_version'],'encounter_id'=>$eid,'encounter_version'=>$v['encounter']['encounter_version'],'entity_turn_id'=>$entityTurns[$pet]['id'],'operation_key'=>'builder_pet_start_fixture','reason'=>'Explicit fixture start']);
    $capture=static function()use($link):array{$data=[];foreach(['sessions','session_roles','encounters','rounds','turns','entity_turns','entities','entity_states','resources','controllers','snapshots','operations','events','event_details','diagnostics','cooldowns','drafts'] as $t)$data[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $data;};
    $before=$capture();$ownerBefore=$db->tables;$ctx=$builder->context($sid,$eid,$turnId);snapshot_assert($repo->builderEncounter($sid)===$eid,'Current encounter lookup failed');
    snapshot_assert(count($ctx['actors'])===2&&$ctx['turn']['ap']===3&&!$ctx['publication_enabled'],'Own group/pet/AP context missing');
    $actions=[['actor_id'=>$result['entity_id'],'ability_key'=>'preview-fixture','targets'=>[$first['entity_id']],'parameters'=>[]],['actor_id'=>$pet,'ability_key'=>'preview-fixture','targets'=>[$first['entity_id']],'parameters'=>[]]];
    $input=['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'scope_token'=>$ctx['scope_token'],'scenario'=>'hit','actions'=>$actions];$preview=$builder->preview($input);
    snapshot_assert(count($preview['actions'])===2&&$preview['virtual_groups'][$result['control_group_id']]['ap']['current']===1,'Pet did not share sequential AP');
    snapshot_assert($builder->preview($input)===$preview&&$capture()===$before&&$db->tables===$ownerBefore,'Preview/GET mutated SQL or owner data');
    $bad=$input;$bad['cost']=0;blocked(fn()=>$builder->preview($bad),'builder_input_invalid');
    $bad=$input;$bad['actions'][0]['parameters']=['fake_damage'=>999];blocked(fn()=>$builder->preview($bad),'builder_parameters_unsupported');
    $bad=$input;$bad['actions'][0]['targets']=[$result['entity_id']];blocked(fn()=>$builder->preview($bad),'preview_target_forbidden');
    $bad=$input;$bad['scope_token']='stale';blocked(fn()=>$builder->preview($bad),'builder_version_conflict');
    $bad=$input;$bad['actions']=[...$actions,...$actions];blocked(fn()=>$builder->preview($bad),'effects_cost_unaffordable');
    $mybb->request_method='get';blocked(fn()=>$builder->preview($input),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $mybb->user['uid']=9;blocked(fn()=>$builder->context($sid,$eid),'ACS_DRAFT_UNAVAILABLE');$mybb->user['uid']=7;
    snapshot_assert($capture()===$before,'Rejected builder query changed state');
    // A normal player keeps their own catalog/draft access, never acquires GM-only targets.
    $roles=$link->query('SELECT id,role FROM fixture_af_acs_session_roles WHERE session_id='.$sid.' AND uid=7')->fetch_all(MYSQLI_ASSOC);
    $link->query("UPDATE fixture_af_acs_session_roles SET role='player' WHERE session_id=".$sid.' AND uid=7');
    $player=$builder->context($sid,$eid,$turnId);snapshot_assert(count($player['actors'])===2&&$player['targets']===[]&&$player['preview_scope']==='own_draft_only','GM-only targets leaked to player');
    $bad=$input;$bad['scope_token']=$player['scope_token'];blocked(fn()=>$builder->preview($bad),'preview_target_forbidden');
    foreach($roles as $role){$stmt=$link->prepare('UPDATE fixture_af_acs_session_roles SET role=? WHERE id=?');$stmt->bind_param('ss',$role['role'],$role['id']);$stmt->execute();}
    $drafts=new AF_ACS_DraftService($repo,$access);$saved=$drafts->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'expected_draft_version'=>'0','operation_key'=>'builder_save_fixture_01','prose'=>'Текст [b]героя[/b]','actions'=>$actions]);
    snapshot_assert($builder->context($sid,$eid,$turnId)['draft_id']===$saved['draft_id'],'Reload did not discover own draft');
    snapshot_assert($drafts->restore($saved['draft_id'])['actions']===$actions,'Builder draft round trip failed');
    $afterSave=$capture();snapshot_assert($builder->preview($input)===$preview&&$capture()===$afterSave,'Unsealed draft blocked or changed preview');
    blocked(fn()=>$repo->assertNoObligations($sid,$eid,$turnId),'ACS_ROUND_OBLIGATIONS_BLOCKED');
    $stmt=$link->prepare('UPDATE fixture_af_acs_drafts SET confirmation_hash=? WHERE id=?');$fake=str_repeat('a',64);$stmt->bind_param('ss',$fake,$saved['draft_id']);$stmt->execute();
    blocked(fn()=>$builder->preview($input),'ACS_ROUND_OBLIGATIONS_BLOCKED');$link->query('UPDATE fixture_af_acs_drafts SET confirmation_hash=NULL WHERE id='.$saved['draft_id']);
    $normal=$builder->context($sid,$eid,$turnId);$mybb->input['af_aa_mask']='untrusted-visual-only';snapshot_assert($builder->context($sid,$eid,$turnId)===$normal,'Appearance input changed builder dependencies');unset($mybb->input['af_aa_mask']);
    snapshot_assert($capture()===$afterSave,'Guard checks changed saved state');
    $mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid];
    $html=AF_ACS_BuilderController::renderBuilder($sid,'51',$builder);
    snapshot_assert(str_contains($html,'data-acs-builder')&&str_contains($html,'data-af-ae-skip="1"')&&str_contains($html,'Боевая публикация пока не подключена')&&!str_contains($html,'name="message"'),'Scoped builder/limited prose field missing');
    snapshot_assert($capture()===$afterSave,'Builder render wrote SQL');
    if(getenv('ACS_BUILDER_DOM_FIXTURE')==='1')file_put_contents('/tmp/acs422-builder-fixture.json',json_encode(['html'=>$html,'context'=>$builder->context($sid,$eid,$turnId),'preview'=>$preview,'draft'=>$drafts->restore($saved['draft_id'])],JSON_THROW_ON_ERROR));
    echo "ACS Builder: own group/pet catalog, shared sequential AP, hidden target gate, safe draft reload, preview after save, no mutations and scoped UI passed\n";
};
require __DIR__.'/acs_preview_regression.php';
