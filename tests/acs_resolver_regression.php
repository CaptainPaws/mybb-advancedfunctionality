<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Resolver.php';
// Existing narrow Effects/Damage/KB fixtures, no new simulator or production writes.
require __DIR__.'/acs_effects_regression.php';
function resolver_check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function resolver_block(callable $call,string $code):void{try{$call();}catch(AF_ACS_FormulaError $e){resolver_check($e->diagnosticCode===$code,'Expected '.$code.' got '.$e->diagnosticCode);return;}throw new RuntimeException('Missing '.$code);}
function resolver_formula(string $key,array $expression,string $type,string $rounding,string $unit):array{
    $d=['key'=>$key,'version'=>'1','inputs'=>['basis'=>'number'],'expression'=>$expression,'output_type'=>$type,'output_unit'=>$unit,'rounding'=>$rounding,'notes'=>'Fixture'];
    $s=af_kb_arpg_formula_snapshot($d);return ['formula'=>$s,'pin'=>array_intersect_key($s,array_flip(['key','version','fingerprint'])),'unit'=>$unit,'basis_input'=>'basis','rule_id'=>$key,'source_ref'=>effect_ref($key),'requires_active'=>false];
}
$ref=effect_ref('strike');$virtual=effect_state();$virtual['entities'][1]['hp']=10;$virtual['entities'][1]['max_hp']=20;
$context=['schema_version'=>1,'state'=>$virtual,'encounter_status'=>'active','stats'=>[1=>['accuracy'=>20,'speed'=>20,'crit_rate'=>50,'crit_dmg'=>50],2=>['accuracy'=>20,'speed'=>20,'crit_rate'=>50,'crit_dmg'=>50]],
    'defenses'=>[1=>['def'=>0],2=>['def'=>100]],'element_catalog'=>$catalog,'turns'=>[1=>['status'=>'pending','started'=>true,'group_id'=>'1']],
    'abilities'=>[1=>['strike'=>['supported'=>true,'source_ref'=>$ref,'components'=>[['source_index'=>0,'damage_type'=>'physical','value'=>20]],'costs'=>[['kind'=>'ap','amount'=>1]]]]], 'allowed_targets'=>[1=>['2']]];
$actions=[['actor_id'=>'1','ability_key'=>'strike','targets'=>['2'],'scenario'=>'hit']];
$r=AF_ACS_Resolver::resolve($context,$actions);resolver_check($r->serverState()['entities'][2]['hp']===10&&$r->serverState()['groups'][1]['ap']['current']===2,'Base preview wrong');
resolver_check($r->serverPlan()['actions'][0]['probabilities']['P_hit']===75.0,'Probability wrong');
resolver_check(AF_ACS_Resolver::resolve($context,$actions)->serverPlan()===$r->serverPlan(),'Trace not deterministic');
resolver_check(!str_contains(json_encode($r),'probabilities'),'Private plan serialized');
$withFormula=$context;$spec=resolver_formula('base-value',['type'=>'input','name'=>'basis'],'number','none','damage');
$withFormula['abilities'][1]['strike']['components'][0]=['source_index'=>0,'damage_type'=>'physical','formula'=>$spec['formula'],'pin'=>$spec['pin'],'parameters'=>['basis'=>20],'unit'=>'damage'];
$compiled=AF_ACS_Resolver::resolve($withFormula,$actions);resolver_check($compiled->serverState()['entities'][2]['hp']===10&&count($compiled->serverPlan()['actions'][0]['formula_trace'])===1,'Formula was not executed by the unified engine');
$withModifiers=$context;$withModifiers['modifier_stages']=[1=>['accuracy'=>[['phase'=>'hit','policy'=>['stat_keys'=>['accuracy'],'phases'=>['hit'],'unit'=>'stat_points','percent_unit'=>'percent','factor_unit'=>'factor','rounding'=>'none','output_type'=>'number'],
    'sources'=>[['snapshot_id'=>'1','source_id'=>'effect:1','source_type'=>'effect','source_version'=>['fingerprint'=>$ref['fingerprint']],'modifiers'=>[['schema'=>AF_ACS_Modifiers::SCHEMA,'stat_key'=>'accuracy','operation'=>'add_flat','value'=>20,'unit'=>'stat_points','phase'=>'hit','priority'=>0,'condition_ref'=>null]]]]]]]];
$modified=AF_ACS_Resolver::resolve($withModifiers,$actions);resolver_check($modified->serverPlan()['actions'][0]['probabilities']['P_hit']===85.0&&count($modified->serverPlan()['actions'][0]['modifier_trace'])===1,'Modifier engine not applied');
$crit=$actions;$crit[0]['scenario']='crit';resolver_check(AF_ACS_Resolver::resolve($context,$crit)->serverState()['entities'][2]['hp']===5,'Conditional crit wrong');
$miss=$actions;$miss[0]['scenario']='miss';$r=AF_ACS_Resolver::resolve($context,$miss);resolver_check($r->serverState()['entities'][2]['hp']===20&&$r->serverState()['groups'][1]['ap']['current']===2,'Miss did not pay cost or dealt damage');
$element=$context;$element['abilities'][1]['strike']['components'][0]['damage_type']='elemental';$element['abilities'][1]['strike']['components'][0]['damage_element']='space';$element['defenses'][2]=['resistances'=>['space'=>['value'=>100,'unit'=>'RES','scope'=>'elemental']]];
resolver_check(AF_ACS_Resolver::resolve($element,$actions)->serverState()['entities'][2]['hp']===10,'17th element failed');
$barrier=$context;$barrier['state']['barriers']=[1=>effect_pool(5,1,0,['2'])];resolver_check(AF_ACS_Resolver::resolve($barrier,$actions)->serverState()['entities'][2]['hp']===15,'Barrier not applied');
$half=resolver_formula('life-half',['type'=>'operation','operator'=>'divide','args'=>[['type'=>'input','name'=>'basis'],['type'=>'literal','value'=>2]]],'integer','half_up','hp');
$reflect=resolver_formula('reflect-all',['type'=>'input','name'=>'basis'],'number','none','damage')+['basis'=>'reflect_hp_loss','damage_type'=>'true','penetration'=>[]];
$combo=$barrier;$combo['reflections']=[2=>[$reflect],1=>[$reflect]];$combo['abilities'][1]['strike']['lifesteal']=[$half];
$r=AF_ACS_Resolver::resolve($combo,$actions);$plan=$r->serverPlan()['actions'][0];
resolver_check($r->serverState()['entities'][1]['hp']===8,'Lifesteal was not based on HP loss after reflection (10-5+3)');
resolver_check(count($plan['derived'])===2&&$plan['derived'][0]['kind']==='reflection'&&$plan['derived'][1]['basis_hp_loss']===5,'Causal reflection/lifesteal order or anti-cycle failed');
$overkill=$context;$overkill['defenses'][2]=['def'=>0];$overkill['state']['entities'][2]['hp']=5;$overkill['abilities'][1]['strike']['lifesteal']=[$half];
$over=AF_ACS_Resolver::resolve($overkill,$actions);resolver_check($over->serverPlan()['actions'][0]['derived'][0]['basis_hp_loss']===5&&$over->serverState()['entities'][1]['hp']===13,'Overkill counted as lifesteal');
$down=$combo;$down['state']['entities'][1]['hp']=3;$revived=AF_ACS_Resolver::resolve($down,$actions);resolver_check($revived->serverState()['entities'][1]['hp']===3&&$revived->serverState()['entities'][1]['life_state']==='active','Generated lifesteal failed after incapacitated');
$down['abilities'][1]['strike']['lifesteal'][0]['requires_active']=true;resolver_check(AF_ACS_Resolver::resolve($down,$actions)->serverState()['entities'][1]['hp']===0,'requires_active ignored');
$physical=$context;$physical['defenses'][1]=['def'=>100];$physical['penetration']=['physical'=>['percent'=>[100]]];$physical['reflections']=[2=>[array_replace($reflect,['damage_type'=>'physical'])]];
resolver_check(AF_ACS_Resolver::resolve($physical,$actions)->serverPlan()['actions'][0]['derived'][0]['allocation']['integer_budget']===10,'Reflection inherited original attacker penetration');
$zero=$combo;$zero['state']['barriers'][1]['strength']=20;$zero['state']['barriers'][1]['maximum_strength']=20;$zero['reflections']=[];
$z=AF_ACS_Resolver::resolve($zero,$actions);resolver_check($z->serverState()['entities'][1]['hp']===10&&$z->serverPlan()['actions'][0]['derived'][0]['calculated']===0,'Absorbed damage healed through lifesteal');
$duplicate=$combo;$duplicate['abilities'][1]['strike']['lifesteal'][]=$half;resolver_block(fn()=>AF_ACS_Resolver::resolve($duplicate,$actions),'resolver_derived_source_invalid');
$bad=$actions;$bad[0]['targets']=['999'];resolver_block(fn()=>AF_ACS_Resolver::resolve($context,$bad),'resolver_target_forbidden');
resolver_block(fn()=>AF_ACS_Resolver::resolve($context,$actions,[],'seal'),'resolver_mode_unsupported');resolver_block(fn()=>AF_ACS_Resolver::resolve($context,$actions,[0.1]),'preview_random_tape_forbidden');
$insufficient=$actions;for($i=0;$i<3;$i++)$insufficient[]=$actions[0];$before=$context;
try{AF_ACS_Resolver::resolve($context,$insufficient);throw new RuntimeException('AP overrun accepted');}catch(AF_ACS_FormulaError $e){resolver_check($e->diagnosticCode==='effects_cost_unaffordable','Unexpected AP error');}
resolver_check($context===$before,'Atomic failure mutated input');
echo "ACS Resolver: conditional hit/crit/miss, physical/space/barrier, reflection anti-cycle, HP-loss lifesteal/order, invalid target/AP and immutable preview passed\n";
