<?php
declare(strict_types=1);
require __DIR__.'/acs_status_evaluation.php';
function instance_spec(array $overrides=[]):array {
    return array_replace(['schema_version'=>1,'effect_key'=>'poison','scope'=>'combat','category'=>'poison','polarity'=>'negative','repeat_rule'=>'refresh','source_scope'=>'source',
        'duration'=>['value'=>4,'unit'=>'own_turn'],'stack_max'=>1,'stack_per_apply'=>1,'cleansable'=>true,'dispellable'=>true,'mechanical_parameters'=>['power'=>4]],$overrides);
}
function instance_apply(array $spec,string $source='1'):array {
    $definition=status_definition();return ['kind'=>'status_apply','session_id'=>'1','encounter_id'=>'1','source'=>$source,'target'=>'2','spec'=>$spec,
        'effect_definition_ref'=>$definition['effect_definition_ref'],'status_definition_ref'=>$definition['status_definition_ref'],'action_index'=>0];
}
function instance_remove(array $overrides=[]):array {
    return ['kind'=>'status_remove','session_id'=>'1','encounter_id'=>'1','target'=>'2','policy'=>array_replace(['kind'=>'cleanse','categories'=>[],'limit'=>1,'remove_stacks'=>null,'chance'=>100],$overrides)];
}
function instance_run(array $state,array $ops):AF_ACS_EffectsResult{return AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>$ops]);}
$empty=effect_state();$empty['entities'][3]=$empty['entities'][1];$empty['status_instances']=[];$empty['next_status_order']=1;
$base=instance_spec();$created=instance_run($empty,[instance_apply($base),instance_apply($base,'3')])->serverState();
resolver_check(count($created['status_instances'])===2,'Sources merged');
$same=instance_run($created,[instance_apply(array_replace($base,['duration'=>['value'=>2,'unit'=>'own_turn'],'mechanical_parameters'=>['power'=>999]]))])->serverState();
resolver_check(count($same['status_instances'])===2&&$same['status_instances']['new:1']['remaining_duration']===4&&$same['status_instances']['new:1']['spec']['mechanical_parameters']['power']===4,'Refresh changed strength/shortened duration');
$long=instance_run($same,[instance_apply(array_replace($base,['duration'=>['value'=>9,'unit'=>'own_turn']]))])->serverState();
resolver_check($long['status_instances']['new:1']['remaining_duration']===9&&$long['status_instances']['new:1']['version']===3,'Refresh max/version failed');
$independent=instance_run($empty,[instance_apply(instance_spec(['repeat_rule'=>'independent'])),instance_apply(instance_spec(['repeat_rule'=>'independent']))])->serverState();
resolver_check(count($independent['status_instances'])===2,'Independent merged');
$shared=instance_spec(['source_scope'=>'shared']);$v=instance_run($empty,[instance_apply($shared),instance_apply($shared,'3')])->serverState();
resolver_check(count($v['status_instances'])===1&&$v['status_instances']['new:1']['source_entity_id']==='1','Explicit shared scope/provenance failed');
$reject=instance_spec(['repeat_rule'=>'reject']);$v=instance_run($empty,[instance_apply($reject),instance_apply($reject)])->serverState();
resolver_check(count($v['status_instances'])===1&&$v['status_instances']['new:1']['version']===1,'Reject reapplied');
$extend=instance_spec(['repeat_rule'=>'extend']);$v=instance_run($empty,[instance_apply($extend),instance_apply($extend)])->serverState();
resolver_check($v['status_instances']['new:1']['remaining_duration']===8,'Extend not additive');
$stack=instance_spec(['repeat_rule'=>'stack','stack_max'=>3,'stack_per_apply'=>2]);
$v=instance_run($empty,[instance_apply($stack)])->serverState();$v['status_instances']['new:1']['remaining_duration']=1;
$capped=instance_run($v,[instance_apply($stack),instance_apply($stack)])->serverState();
resolver_check($capped['status_instances']['new:1']['stacks']===3&&$capped['status_instances']['new:1']['remaining_duration']===4,'Stack cap disabled refresh');
$longStack=instance_run($capped,[instance_apply(array_replace($stack,['duration'=>['value'=>8,'unit'=>'own_turn']]))])->serverState();
resolver_check($longStack['status_instances']['new:1']['stacks']===3&&$longStack['status_instances']['new:1']['remaining_duration']===8,'Explicit duration override failed at stack cap');
$partial=instance_run($capped,[instance_remove(['remove_stacks'=>1])])->serverState();
resolver_check($partial['status_instances']['new:1']['stacks']===2&&$partial['status_instances']['new:1']['remaining_duration']===4,'Partial cleanse refreshed duration');
$removed=instance_run($partial,[instance_remove(['remove_stacks'=>2])])->serverState();
resolver_check($removed['status_instances']['new:1']['stacks']===0&&$removed['status_instances']['new:1']['status']==='removed','Zero stacks not removed');
$eligible=instance_run($empty,[instance_apply(instance_spec(['effect_key'=>'protected','cleansable'=>false])),instance_apply(instance_spec(['effect_key'=>'other','category'=>'other'])),
    instance_apply(instance_spec(['effect_key'=>'first-eligible'])),instance_apply(instance_spec(['effect_key'=>'last-eligible']))])->serverState();
$oldest=instance_run($eligible,[instance_remove(['categories'=>['poison']])])->serverState();
resolver_check($oldest['status_instances']['new:1']['status']==='active'&&$oldest['status_instances']['new:2']['status']==='active'&&$oldest['status_instances']['new:3']['status']==='removed'&&$oldest['status_instances']['new:4']['status']==='active','Cleanse did not choose oldest eligible');
$v=instance_run($eligible,[instance_remove(['kind'=>'dispel'])])->serverState();resolver_check($v===$eligible,'Dispel removed negative');
$positive=instance_run($eligible,[instance_apply(instance_spec(['effect_key'=>'blessing','polarity'=>'positive']))])->serverState();
$v=instance_run($positive,[instance_remove(['kind'=>'dispel'])])->serverState();resolver_check($v['status_instances']['new:5']['status']==='removed'&&$v['status_instances']['new:1']['status']==='active','Dispel wrong polarity');
$protectedPositive=instance_run($empty,[instance_apply(instance_spec(['polarity'=>'positive','dispellable'=>false]))])->serverState();
resolver_check(instance_run($protectedPositive,[instance_remove(['kind'=>'dispel'])])->serverState()===$protectedPositive,'Dispel ignored prohibition');
$all=instance_run($eligible,[instance_remove(['limit'=>null])])->serverState();
resolver_check(count(array_filter($all['status_instances'],fn($r)=>$r['status']==='active'))===1,'Mass cleanse ignored prohibition');
$chance=instance_remove(['chance'=>25]);$chance['roll']=['sample_n'=>9007199254740991,'u'=>9007199254740991/9007199254740992.0];
resolver_check(instance_run($eligible,[$chance])->serverState()===$eligible,'Failed cleanse probability changed state');
$chance['roll']=['sample_n'=>0,'u'=>0.0];resolver_check(instance_run($eligible,[$chance])->serverState()['status_instances']['new:2']['status']==='removed','Fixed cleanse roll failed');
$scopes=instance_run($empty,[instance_apply($base),instance_apply(instance_spec(['effect_key'=>'session-effect','scope'=>'session'])),instance_apply(instance_spec(['effect_key'=>'persistent-effect','scope'=>'persistent']))])->serverState();
$end=instance_run($scopes,[['kind'=>'status_combat_end','encounter_id'=>'1']])->serverState();
resolver_check($end['status_instances']['new:1']['status']==='removed'&&$end['status_instances']['new:2']['status']==='active'&&$end['status_instances']['new:3']['status']==='active','Scope boundary removed session/persistent');
resolver_check(instance_run($end,[['kind'=>'status_combat_end','encounter_id'=>'1']])->serverState()===$end,'Boundary not idempotent');
foreach(['own_turn','target_turn','round'] as $unit){$v=instance_run($empty,[instance_apply(instance_spec(['duration'=>['value'=>4,'unit'=>$unit]]))])->serverState();resolver_check($v['status_instances']['new:1']['duration_unit']===$unit,'Duration unit lost');}
$before=$empty;effect_error(AF_ACS_Effects::attempt($empty,['costs'=>[],'effects'=>[instance_apply($base),instance_apply(instance_spec(['schema_version'=>99]))]]),'status_instance_schema_unsupported');
resolver_check($empty===$before,'Failed virtual sequence left instance');
$corrupt=$created;$corrupt['status_instances']['new:1']['spec']['schema_version']=99;
effect_error(AF_ACS_Effects::attempt($corrupt,['costs'=>[],'effects'=>[]]),'status_instance_schema_unsupported');

// Resolver uses the same lifecycle in its declared phases, on detached state during seal.
$lifeContext=$statusContext;$lifeContext['state']=$empty;$lifeContext['status_scope']=['session_id'=>'1','encounter_id'=>'1'];
$lifeContext['abilities'][1]['strike']['statuses']=[status_definition()+['instance'=>$base]];
$lifeTape=(new AF_ACS_RandomSource(static fn()=>0))->action([1]);
$resolved=AF_ACS_Resolver::resolve($lifeContext,$sealedActions,[$lifeTape],'seal');
resolver_check(count($resolved->serverState()['status_instances'])===1&&$lifeContext['state']===$empty,'Seal did not simulate lifecycle or mutated input');
resolver_check(AF_ACS_Resolver::resolve($lifeContext,$actions)->serverState()['status_instances']===[],'Preview invented probabilistic application');
$cleanContext=$lifeContext;$cleanContext['state']=$resolved->serverState();$cleanContext['abilities'][1]['strike']['statuses']=[];
$cleanContext['abilities'][1]['strike']['components']=[];$cleanContext['abilities'][1]['strike']['removals']=[['effect_definition_ref'=>array_replace(status_definition()['effect_definition_ref'],['effect_index'=>2]),
    'trigger'=>'on_cast','policy'=>instance_remove()['policy']]];
$cleanTape=(new AF_ACS_RandomSource(static fn()=>0))->action();
$cleaned=AF_ACS_Resolver::resolve($cleanContext,$sealedActions,[$cleanTape],'seal');
resolver_check($cleaned->serverState()['status_instances']['new:1']['status']==='removed','Resolver cleanse did not use Effects');
$cleanContext['abilities'][1]['strike']['removals'][0]['policy']['chance']=100.0;
resolver_check(AF_ACS_Resolver::resolve($cleanContext,$actions)->serverState()['status_instances']['new:1']['status']==='removed','Guaranteed float chance required RNG in preview');
$cleanContext['abilities'][1]['strike']['removals'][0]['policy']['chance']=25;
resolver_check(AF_ACS_Resolver::statusRollIndexes($cleanContext,'1','2',$cleanContext['abilities'][1]['strike'])===[2],'Cleanse probability roll not reserved');
$cleanTape=(new AF_ACS_RandomSource(static fn()=>0))->action([2]);
resolver_check(AF_ACS_Resolver::resolve($cleanContext,$sealedActions,[$cleanTape],'seal')->serverState()['status_instances']['new:1']['status']==='removed','Cleanse tape failed');

$cleanRules=$abilityRules;$cleanRules['effects']=[['kind'=>'cleanse','categories'=>['poison'],'remove_stacks'=>1,'mass'=>true,'chance'=>25,'chance_scope'=>'operation']];
$durationOverride=AF_ACS_Effects::instanceSpec($statusDef,['status_key'=>'status-fixture','polarity'=>'negative','duration_value'=>3,'duration_unit'=>'round']);
resolver_check($durationOverride['duration']===['value'=>3,'unit'=>'round'],'Concrete effect duration override ignored');
resolver_block(fn()=>AF_ACS_Effects::instanceSpec($statusDef,['status_key'=>'status-fixture','polarity'=>'negative','duration_value'=>3]),'status_duration_override_unit_missing');
$db->tables['af_kb_entries'][]=snapshot_entry(982,'arpg_ability','cleanse-fixture',$cleanRules);
$cleanDto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','cleanse-fixture',7);
resolver_check($cleanDto['ready'],'Owner cleanse DTO failed');$cleanPrepared=$project->invoke($query,$cleanDto);
resolver_check($cleanPrepared['components']===[]&&$cleanPrepared['removals'][0]['policy']['chance']===25&&$cleanPrepared['removals'][0]['policy']['limit']===null,'KB cleanse fields dropped');
$bad=$cleanDto;unset($bad['data']['ability_profile']['effects'][0]['parameters']['chance_scope']);
resolver_block(fn()=>$project->invoke($query,$bad),'status_removal_probability_scope_unsupported');
$ownerSaved=$db->tables;$invalidDef=$statusDef;$invalidDef['schema']='unknown.status.v99';
foreach($db->tables['af_kb_entries'] as &$entry)if($entry['key']==='status-fixture')$entry=snapshot_entry(980,'arpg_mechanics','status-fixture',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'status_def','entries'=>[$invalidDef]]);unset($entry);
$unknown=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','status-strike',7);
resolver_check(!$unknown['ready'],'Unknown KB schema accepted');$db->tables=$ownerSaved;
echo "ACS status instances: source/shared identity, refresh/strength, independent/reject/extend, capped stacking/refresh, partial/oldest/category/mass cleanse, dispel/prohibitions/probability, scope/units, unknown schemas, atomic virtual failure and Resolver/KB sealed lifecycle passed\n";
