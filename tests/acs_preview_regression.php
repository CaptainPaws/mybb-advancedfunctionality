<?php
declare(strict_types=1);
// Reuse disposable MariaDB round fixture, no production or new framework.
$GLOBALS['acsResourcesFixture']=static function(array $f):void{
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/QueryService.php';
    $actor=$result['entity_id'];$target=$first['entity_id'];$mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // Explicit test seed: attach a fully owner-normalized ability to the immutable fixture before baseline capture.
    $rules=af_kb_default_type_profile_payload_arpg('arpg_ability');
    $rules=array_replace($rules,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'ability','version'=>'1.0','type'=>'active','slot'=>'active','damage_type'=>'physical','targeting'=>'single_enemy',
        'cooldown_unit'=>'own_turn','duration_unit'=>'own_turn','effects'=>[['kind'=>'damage','damage_type'=>'physical','value'=>10]]]);
    $rules['max_charges']=0; // No charge capacity/consumption contract in this prepared definition.
    $db->tables['af_kb_entries'][]=snapshot_entry(950,'arpg_ability','preview-fixture',$rules);
    $ability=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','preview-fixture',7);snapshot_assert($ability['ready'],'Ability fixture blocked: '.json_encode($ability['diagnostics']));
    $formula=['key'=>'preview-damage','version'=>'1','inputs'=>[],'expression'=>['type'=>'literal','value'=>10],'output_type'=>'number','output_unit'=>'damage','rounding'=>'none','notes'=>'Prepared fixture'];
    $db->tables['af_kb_entries'][]=snapshot_entry(952,'arpg_mechanics','preview-damage',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','service_kind'=>'formula_def','entries'=>[$formula]]);
    $formulaRules=$rules;$formulaRules['effects']=[['kind'=>'damage','damage_type'=>'physical','formula_ref'=>'preview-damage']];
    $db->tables['af_kb_entries'][]=snapshot_entry(953,'arpg_ability','preview-formula',$formulaRules);
    $formulaAbility=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','preview-formula',7);snapshot_assert($formulaAbility['ready'],'Formula ability fixture blocked');
    $entity=$repo->entity($sid,$actor);$payload=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);$dependency=$ability;$dependency['source']['key']='dependency-only';$dependency['source']['id']=951;
    $payload['pinned_abilities']=['preview-fixture'=>$ability,'dependency-only'=>$dependency,'preview-formula'=>$formulaAbility];
    $issues=[];$grant=af_kb_arpg_acs_reference('arpg_ability','preview-fixture','fixture',$issues);snapshot_assert(!$issues&&$grant['resolved'],'Direct grant fixture failed');
    $payload['source_snapshot']['abilities'][]=['definition'=>['references'=>['ability_kb_key'=>$grant]]];
    $issues=[];$formulaGrant=af_kb_arpg_acs_reference('arpg_ability','preview-formula','fixture',$issues);snapshot_assert(!$issues,'Formula grant fixture blocked');
    $payload['source_snapshot']['abilities'][]=['definition'=>['references'=>['ability_kb_key'=>$formulaGrant]]];
    $json=AF_ACS_Repository::json($payload);$hash=hash('sha256',$json);
    $st=$link->prepare('UPDATE fixture_af_acs_snapshots SET payload_json=?,content_hash=? WHERE id=?');$st->bind_param('sss',$json,$hash,$entity['template_snapshot_id']);$st->execute();
    $turnId=$groups[$actor];$turn=$repo->clockTurn($sid,$turnId);$roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);
    foreach($roster['members'] as &$m)if($m['entity_id']===$actor)$m['content_hash']=$hash;unset($m);unset($roster['fingerprint']);$roster['fingerprint']=hash('sha256',AF_ACS_Repository::json($roster));$rjson=AF_ACS_Repository::json($roster);
    $st=$link->prepare('UPDATE fixture_af_acs_turns SET roster_json=? WHERE id=?');$st->bind_param('ss',$rjson,$turnId);$st->execute();
    $view=$service->view($sid,$eid);
    $service->clock()->command('entity_start',['session_id'=>$sid,'expected_version'=>$view['session']['session_version'],'encounter_id'=>$eid,'encounter_version'=>$view['encounter']['encounter_version'],
        'entity_turn_id'=>$entityTurns[$actor]['id'],'operation_key'=>'preview_boundary_fixture','reason'=>'Prepare start before preview']);
    $q=new AF_ACS_QueryService($repo,$access);$p=$q->inspect($sid,$eid,$actor,$target);
    snapshot_assert($p['choices']['preview-fixture']['supported'],'Pinned ability unsupported: '.json_encode($p['choices']));
    $input=['session_id'=>$sid,'encounter_id'=>$eid,'actor_id'=>$actor,'target_id'=>$target,'ability_key'=>'preview-fixture','scenario'=>'hit','versions'=>$p['versions']];
    $capture=static function()use($link):array{
        $out=[];foreach(['sessions','encounters','rounds','turns','entity_turns','entities','entity_states','resources','controllers','snapshots','operations','events','event_details','diagnostics'] as $table)$out[$table]=$link->query('SELECT * FROM fixture_af_acs_'.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;
    };
    $before=$capture();$ownerBefore=$db->tables;$preview=$q->preview($input);snapshot_assert($preview['conditional']&&$preview['persistent']===false&&$preview['integer_budget']===10,'Base endpoint preview wrong');
    snapshot_assert($q->preview($input)===$preview,'Preview generated a new result');snapshot_assert($capture()===$before&&$db->tables===$ownerBefore,'Preview mutated SQL/owner data');
    $formulaInput=$input;$formulaInput['ability_key']='preview-formula';$ownerReads=count($db->queries);snapshot_assert($q->preview($formulaInput)['integer_budget']===10,'Pinned formula endpoint failed');snapshot_assert(count($db->queries)===$ownerReads,'Preview looked up live KB/CS');
    $bad=$input;$bad['target_id']=$actor;blocked(fn()=>$q->preview($bad),'preview_target_forbidden');
    $bad=$input;$bad['versions']['actor_state_version']='999';blocked(fn()=>$q->preview($bad),'preview_version_conflict');
    $bad=$input;$bad['ability_key']='dependency-only';blocked(fn()=>$q->preview($bad),'resolver_turn_or_source_invalid');
    $bad=$input;$bad['damage']=99999;blocked(fn()=>$q->preview($bad),'preview_input_invalid');
    $mybb->request_method='get';blocked(fn()=>$q->preview($input),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $mybb->input=['my_post_key'=>'wrong'];blocked(fn()=>$q->preview($input),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];
    $mybb->user['uid']=9;blocked(fn()=>$q->preview($input),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $foreign=$input;$foreign['actor_id']=$target;$foreign['target_id']=$actor;blocked(fn()=>$q->preview($foreign),'preview_target_forbidden');
    snapshot_assert($capture()===$before&&$db->tables===$ownerBefore,'Rejected preview wrote diagnostics/game rows');
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/BuilderController.php';
    $mybb->request_method='get';$mybb->input=['preview_actor'=>$actor,'preview_target'=>$target];
    ob_start();AF_ACS_BuilderController::render($sid,$eid,'51',$q);$html=ob_get_clean();
    snapshot_assert(str_contains($html,'Условный сценарий')&&str_contains($html,'preview-fixture')&&str_contains($html,'preview_versions'),'Preview form unavailable');
    snapshot_assert($capture()===$before,'Preview GET form mutated SQL');
    echo "ACS Preview: persisted pins/turn/control/versions, conditional result replay, GM visibility, CSRF/GET, attacker injection and zero SQL mutations passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
