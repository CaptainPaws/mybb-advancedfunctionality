<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/HitCrit.php';
function hc_check(bool $ok,string $label): void {if (!$ok) throw new RuntimeException($label);}
function hc_error(array $r,string $code): void {hc_check(!$r['ready'] && $r['diagnostics'][0]['code']===$code,'Expected '.$code.' got '.json_encode($r));}
foreach ([[0,0,75],[0,10,45],[10,0,95],[5,5,75],[80,20,93]] as [$a,$s,$expected]) {
    $r=AF_ACS_HitCrit::hit($a,$s);hc_check($r['P_hit']==$expected && $r['P_evade']==100-$expected,'H1 failed');
}
$components=[['kind'=>'ordinary_attack','raw'=>10],['kind'=>'dot','raw'=>4],['kind'=>'direct_attack','raw'=>0.4]];
$r=AF_ACS_HitCrit::strike(20,20,100,50,$components,true);
$data=$r->serverData();hc_check($data['components'][0]['raw_after_crit']===15.0 && $data['components'][1]['raw_after_crit']===4 && $data['components'][2]['raw_after_crit']===0.6000000000000001,'Critical components/defaults/precision failed');
hc_check($r->serverTrace()===AF_ACS_HitCrit::strike(20,20,100,50,$components,true)->serverTrace(),'Not deterministic');
hc_check(!str_contains(json_encode($r),'raw_component'),'Private trace serialized');
$r=AF_ACS_HitCrit::strike(20,20,0,50,$components,false)->serverData();hc_check($r['components'][0]['raw_after_crit']===10,'Crit 0% failed');
$r=AF_ACS_HitCrit::strike(20,20,50,50,$components,null)->serverData();hc_check($r['critical_outcome']===null && $r['components'][0]['raw_after_crit']===null,'Preview invented outcome');
hc_error(AF_ACS_HitCrit::attempt(0,0,0,50,$components,true),'crit_outcome_conflict');
hc_error(AF_ACS_HitCrit::attempt(0,0,50,50,$components,'true'),'crit_outcome_invalid');
hc_error(AF_ACS_HitCrit::attempt(0,0,100,50,$components,false),'crit_outcome_conflict');
foreach (['dot','reflected','natural','healing','shield'] as $kind) hc_error(AF_ACS_HitCrit::attempt(0,0,50,50,[['kind'=>$kind,'raw'=>5]],true),'crit_not_allowed');
hc_error(AF_ACS_HitCrit::attempt(-1,0,50,50,$components,false),'hit_crit_negative_stat');
hc_error(AF_ACS_HitCrit::attempt(1,0,101,50,$components,false),'crit_rate_out_of_range');
hc_error(AF_ACS_HitCrit::attempt(1,0,50,-1,$components,false),'hit_crit_negative_stat');
hc_error(AF_ACS_HitCrit::attempt(INF,0,50,50,$components,false),'ACS_NUMERIC_INFINITY');
hc_error(AF_ACS_HitCrit::attempt(0,0,50,50,[['kind'=>'unknown','raw'=>1]],false),'crit_component_unsupported');
hc_error(AF_ACS_HitCrit::attempt(0,0,50,50,[['kind'=>'direct_attack','raw'=>1,'can_crit'=>true]],true),'crit_component_unsupported');
// Real owner-normalized ability and existing authenticated adapter, write-forbidden fixture.
require __DIR__.'/acs_integration_adapters.php';
$db->tables=$before;
$abilityRules=af_kb_default_type_profile_payload_arpg('arpg_ability');
$abilityRules=array_replace($abilityRules,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'ability','version'=>'1.0',
    'type'=>'active','slot'=>'active','damage_type'=>'physical','targeting'=>'single_enemy','cooldown_unit'=>'own_turn','duration_unit'=>'own_turn',
    'auto_hit'=>true,'can_crit'=>false]);
$db->tables['af_kb_entries'][]=snapshot_entry(191,'arpg_ability','policy_fixture',$abilityRules);
$owner=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','policy_fixture',7);
hc_check($owner['ready'],'KB policy fixture not ready '.json_encode($owner['diagnostics']));
$policy=AF_ACS_HitCritPolicy::fromDefinition($owner,$owner['source'],$owner['definition_version']);
hc_check(AF_ACS_HitCrit::hit(0,10,$policy)['P_hit']===100,'Pinned KB auto_hit not applied');
hc_error(AF_ACS_HitCrit::attempt(1,1,50,50,[['kind'=>'direct_attack','raw'=>10]],true,$policy),'crit_not_allowed');
$abilityRules['can_crit']=true;$abilityRules['auto_hit']=false;
$db->tables['af_kb_entries'][count($db->tables['af_kb_entries'])-1]=snapshot_entry(191,'arpg_ability','policy_fixture',$abilityRules);
$allow=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','policy_fixture',7);
$yes=AF_ACS_HitCritPolicy::fromDefinition($allow,$allow['source'],$allow['definition_version']);
hc_check(AF_ACS_HitCrit::strike(0,0,50,50,[['kind'=>'healing','raw'=>10]],true,$yes)->serverData()['components'][0]['raw_after_crit']===15.0,'Explicit KB override ignored');
try {AF_ACS_HitCritPolicy::fromDefinition($allow,$owner['source'],$owner['definition_version']);throw new RuntimeException('Wrong pin accepted');}
catch(AF_ACS_FormulaError $e) {hc_check($e->diagnosticCode==='hit_crit_source_mismatch','Wrong policy error');}
hc_check(AF_ACS_HitCrit::hit(0,10,$policy)['P_hit']===100,'Retained policy changed on live KB edit');
$abilityRules['effects']=[['kind'=>'heal','value'=>10,'can_crit'=>false]];
$db->tables['af_kb_entries'][count($db->tables['af_kb_entries'])-1]=snapshot_entry(191,'arpg_ability','policy_fixture',$abilityRules);
$effectOwner=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','policy_fixture',7);
hc_check($effectOwner['ready'],'Effect policy fixture blocked');
$effectPolicy=AF_ACS_HitCritPolicy::fromDefinition($effectOwner,$effectOwner['source'],$effectOwner['definition_version']);
hc_error(AF_ACS_HitCrit::attempt(1,1,50,50,[['kind'=>'healing','raw'=>10,'kb_effect_index'=>0]],true,$effectPolicy),'crit_not_allowed');
hc_error(AF_ACS_HitCrit::attempt(1,1,50,50,[['kind'=>'direct_attack','raw'=>10,'kb_effect_index'=>99]],true,$effectPolicy),'crit_component_reference_missing');
$stateBefore=$db->tables;$readsBefore=count($db->queries);
AF_ACS_HitCrit::strike(1,1,50,50,[['kind'=>'direct_attack','raw'=>10]],true,$yes);
hc_check($db->tables===$stateBefore && count($db->queries)===$readsBefore,'Domain performed I/O');
echo "ACS Hit/Crit: H1 0/0, 0/N, N/0, equal; C1 0/100%, one supplied strike outcome, component defaults, pinned KB overrides, preview no outcome and pure traces passed\n";
