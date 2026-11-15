<?php
/** Focused A1.29 probability/capability checks, no storage or RNG. */
define('IN_MYBB',1);
require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Resolver.php';
function participation_assert(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
function participation_denied(callable $call,string $code):void {try{$call();}catch(Throwable $e){participation_assert($e->getMessage()===$code,'Unexpected rejection '.$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
$policy=['schema'=>AF_ACS_Participation::SCHEMA,'spatial'=>'unmapped','obstacles'=>'none','pursuit'=>'capable_hostile','release_to'=>'surrendered','rejoin_from'=>['surrendered','escaped','withdrawn']];
AF_ACS_Participation::policy($policy);
foreach([['evacuation'=>true],['obstacles'=>'ignore'],['spatial'=>'teleport'],['rejoin_from'=>['captured']],['rejoin_from'=>['escaped','escaped']]] as $bad)participation_denied(fn()=>AF_ACS_Participation::policy(array_replace($policy,$bad)),'ACS_PARTICIPATION_UNSUPPORTED');
$state=['spatial'=>AF_ACS_Spatial::empty(),'objects'=>[],'status_instances'=>[],'participants'=>[],'entities'=>[]];
foreach(['1','2','3'] as $id){$state['participants'][$id]=['participation'=>'engaged'];$state['entities'][$id]=['life_state'=>'active'];}
$context=['stats'=>['1'=>['speed'=>10],'2'=>['speed'=>5],'3'=>['speed'=>100]],'relationships'=>['1'=>['2'=>'hostile','3'=>'hostile']],'participation_visible'=>['2'=>true,'3'=>true],'status_scope'=>['session_id'=>'1','encounter_id'=>'1'],'behavior_targets'=>[]];
$check=AF_ACS_Participation::check($context,$state,'1',$policy);
participation_assert(!$check['automatic']&&$check['probability']===AF_ACS_Participation::probability(10,100),'Not the fastest capable pursuer');
$state['entities']['3']['life_state']='incapacitated';$check=AF_ACS_Participation::check($context,$state,'1',$policy);
participation_assert($check['probability']===70.0,'Incapacitated pursuer considered capable');
$context['stats']['1']['speed']=$context['stats']['2']['speed']=0;$check=AF_ACS_Participation::check($context,$state,'1',$policy);participation_assert($check['probability']===60.0&&!$check['automatic'],'0/0 policy');
$state['participants']['2']['participation']='surrendered';$check=AF_ACS_Participation::check($context,$state,'1',$policy);participation_assert($check===['automatic'=>true,'probability'=>100.0],'No pursuer not automatic');
$state['participants']['2']['participation']='engaged';$context['participation_visible']['2']=false;
participation_denied(function()use($context,$state,$policy){AF_ACS_Participation::check($context,$state,'1',$policy);},'ACS_PARTICIPATION_UNAVAILABLE');
participation_denied(function()use($state,$policy){AF_ACS_Participation::spatial($state,array_replace($policy,['spatial'=>'same_zone']),'1');},'ACS_PARTICIPATION_SPATIAL_UNSUPPORTED');
$state['objects']['9']=['status'=>'active'];participation_denied(function()use($state,$policy){AF_ACS_Participation::spatial($state,$policy,'1');},'ACS_PARTICIPATION_OBSTACLE_UNSUPPORTED');
$mapped=$state;$mapped['objects']=[];$context['participation_visible']['2']=true;$mapPolicy=array_replace($policy,['spatial'=>'same_zone']);
$zone=['key'=>'a','name'=>'A','visibility'=>'public','line_of_effect'=>true];$definition=['schema'=>'af_kb.arpg.rules.v1','type_profile'=>'service_mechanics','version'=>'1','service_kind'=>'combat_template','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>'participation-map','version'=>'1','phases'=>[],'limits'=>[],'formula_refs'=>[],'spatial_definition'=>['schema'=>'ic-acs.spatial.v1','mode'=>'zones','default_zone'=>null,'zones'=>[$zone],'connections'=>[],'legacy_range'=>['near','engaged'],'leave_engagement'=>'allow','engage_ap'=>1,'leave_ap'=>1,'line_of_effect'=>true]]]];
$mapped['spatial']['pin']=['ready'=>true,'source'=>['type'=>'arpg_mechanics'],'data'=>['definition'=>$definition]];$mapped['spatial']['zones']['10']=['id'=>'10']+$zone;
$mapped['spatial']['positions']['1']=['entity_id'=>'1','zone_id'=>'10','visibility'=>'known','version'=>1];
participation_denied(function()use($context,$mapped,$mapPolicy){AF_ACS_Participation::check($context,$mapped,'1',$mapPolicy);},'ACS_PARTICIPATION_SPATIAL_UNSUPPORTED');
$mapped['spatial']['positions']['2']=['entity_id'=>'2','zone_id'=>'10','visibility'=>'known','version'=>1];$check=AF_ACS_Participation::check($context,$mapped,'1',$mapPolicy);participation_assert(!$check['automatic']&&$check['probability']===60.0,'Same-zone pursuit not checked');AF_ACS_Participation::spatial($mapped,$mapPolicy,'1','2');
$ref=['key'=>'fixture','version'=>'1','fingerprint'=>'sha256:'.str_repeat('a',64)];
$entity=['hp'=>10,'max_hp'=>10,'life_state'=>'active','recovery_blocked'=>false,'group_id'=>'1','resources'=>[],'source_ref'=>$ref,'version'=>1];
$virtual=['schema_version'=>1,'entities'=>['1'=>$entity,'2'=>$entity],'groups'=>['1'=>['ap'=>['current'=>3,'maximum'=>3],'version'=>1]],'barriers'=>[],'next_barrier_order'=>1,'participants'=>['1'=>['participation'=>'engaged','version'=>1,'side_id'=>'1','group_id'=>'1'],'2'=>['participation'=>'escaped','version'=>1,'side_id'=>'2','group_id'=>'1']]];
$prepared=['schema_version'=>1,'state'=>$virtual,'encounter_status'=>'active','stats'=>[],'defenses'=>[],'element_catalog'=>[],'turns'=>['1'=>['status'=>'pending','started'=>true,'group_id'=>'1']],'abilities'=>['1'=>['strike'=>['supported'=>true,'source_ref'=>$ref,'components'=>[['source_index'=>0,'damage_type'=>'physical','value'=>1]],'costs'=>[['kind'=>'ap','amount'=>1]]]]],'allowed_targets'=>['1'=>['2']]];
$action=[['actor_id'=>'1','ability_key'=>'strike','targets'=>['2'],'scenario'=>'hit']];
participation_denied(fn()=>AF_ACS_Resolver::resolve($prepared,$action),'resolver_target_forbidden');
$prepared['state']['participants']['1']['participation']='surrendered';participation_denied(fn()=>AF_ACS_Resolver::resolve($prepared,$action),'ACS_STATUS_CONTROL_BLOCKED');
echo "ACS participation domain: strict policy, exact Speed formula/0-0, fastest capable hostile, surrender/incapacity exclusion, no pursuer, hidden/unsupported spatial/obstacle refusal passed\n";
