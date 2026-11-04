<?php
declare(strict_types=1);
require __DIR__.'/acs_status_instances.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Domain/Clock.php';
function tick_fixture(string $kind,int $value,int $duration=3,string $unit='target_turn'):array {
    $effect=['kind'=>$kind,'value'=>$value,'can_crit'=>false];if($kind==='damage')$effect['damage_type']='true';
    $source=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','effects'=>[$effect]];
    $json=json_encode(af_kb_arpg_acs_canonical($source),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ref=['type'=>'arpg_ability','key'=>'tick-fixture','effect_index'=>0];
    $snapshot=['source_ref'=>['key'=>'tick-fixture','version'=>'1','fingerprint'=>'sha256:'.hash('sha256',$json)],'source_json'=>$json,'effect_ref'=>$ref];
    $definition=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'poison','version'=>'1','type'=>'debuff','scope'=>'combat','repeat_rule'=>'refresh','max_stacks'=>1,
        'duration'=>['value'=>$duration,'unit'=>$unit],'phase'=>$unit==='round'?'on_round_start':'on_turn_start','decrement'=>['event'=>$unit==='round'?'on_round_end':'on_turn_start','amount'=>1],'periodic_effects'=>[['effect'=>$ref,'period'=>['value'=>1,'unit'=>$unit],'phase'=>$unit==='round'?'on_round_start':'on_turn_start']],
        'removal_conditions'=>[],'expiry_conditions'=>[],'events'=>[],'mechanical_parameters'=>[]];
    return [AF_ACS_Effects::instanceSpec($definition,['status_key'=>'poison','polarity'=>$kind==='heal'?'positive':'negative'],[$snapshot]),$definition,$snapshot];
}
function tick_boundary(string $phase,string $id,string $entity='2'):array{return ['phase'=>$phase,'id'=>$id,'entity_id'=>$entity,'entity_ids'=>['1','2','3'],'session_id'=>'1','encounter_id'=>'1','encounter_status'=>'active'];}
$initial=$empty;[$poison,$poisonDef,$poisonSnapshot]=tick_fixture('damage',4);
$state=instance_run($initial,[instance_apply($poison)])->serverState();resolver_check($state['entities'][2]['hp']===20,'Apply executed immediate DoT');
for($i=1;$i<=3;$i++){
    $r=AF_ACS_Clock::advance($state,tick_boundary('entity_start',(string)$i),[2=>[]],$catalog);$state=$r->serverState();
    resolver_check($state['entities'][2]['hp']===20-4*$i,'Poison tick count wrong');
    resolver_check(AF_ACS_Clock::advance($state,tick_boundary('entity_start',(string)$i),[2=>[]],$catalog)->serverState()===$state,'Duplicate boundary ticked twice');
}
resolver_check($state['status_instances']['new:1']['status']==='removed'&&$state['status_instances']['new:1']['remaining_duration']===0,'Last tick not before removal');
resolver_check(AF_ACS_Clock::advance($state,tick_boundary('entity_start','4'),[2=>[]],$catalog)->serverState()===$state,'Fourth poison tick');
[$hot]=tick_fixture('heal',3,2);$hurt=$initial;$hurt['entities'][2]['hp']=0;$hurt['entities'][2]['life_state']='incapacitated';
$hurt=instance_run($hurt,[instance_apply($hot)])->serverState();$healed=AF_ACS_Clock::advance($hurt,tick_boundary('entity_start','10'))->serverState();
resolver_check($healed['entities'][2]['hp']===3&&$healed['entities'][2]['life_state']==='active','HoT did not recover active');
$blockedRecovery=$hurt;$blockedRecovery['entities'][2]['recovery_blocked']=true;
resolver_check(AF_ACS_Clock::advance($blockedRecovery,tick_boundary('entity_start','10'))->serverState()['entities'][2]['life_state']==='incapacitated','HoT bypassed recovery state');
$low=$initial;$low['entities'][2]['hp']=3;$low=instance_run($low,[instance_apply($poison)])->serverState();
$down=AF_ACS_Clock::advance($low,tick_boundary('entity_start','20'),[2=>[]],$catalog);
resolver_check($down->serverState()['entities'][2]['life_state']==='incapacitated'&&$down->serverState()['entities'][2]['hp']===0&&count($down->serverTrace()['signals'])>0,'0 HP became dead or transition missing');
$control=instance_spec(['duration'=>['value'=>1,'unit'=>'target_turn'],'mechanical_parameters'=>['blocks_ordinary_actions'=>true]]);
$controlled=instance_run($initial,[instance_apply($control),instance_apply(array_replace($control,['duration'=>['value'=>2,'unit'=>'target_turn']]),'3')])->serverState();
$started=AF_ACS_Clock::advance($controlled,tick_boundary('entity_start','30'))->serverState();
resolver_check(!AF_ACS_Effects::ordinaryActionAllowed($started,'2')&&$started===$controlled,'One-turn control expired at start');
$ended=AF_ACS_Clock::advance($started,tick_boundary('entity_end','30'))->serverState();
resolver_check($ended['status_instances']['new:1']['status']==='removed'&&!AF_ACS_Effects::ordinaryActionAllowed($ended,'2'),'Independent control lost early');
$ended=AF_ACS_Clock::advance($ended,tick_boundary('entity_end','31'))->serverState();resolver_check(AF_ACS_Effects::ordinaryActionAllowed($ended,'2'),'Control not removed at affected end');
$actionContext=$context;$actionContext['state']=instance_run($initial,[array_replace(instance_apply($control),['target'=>'1'])])->serverState();
$actionContext['entity_end_boundaries']=[tick_boundary('entity_end','32','1')];
resolver_block(fn()=>AF_ACS_Resolver::resolve($actionContext,$actions),'ACS_STATUS_CONTROL_BLOCKED');
$endContext=$context;$endContext['state']=$controlled;$endContext['entity_end_boundaries']=[tick_boundary('entity_end','33','2')];
$withEnd=AF_ACS_Resolver::resolve($endContext,$actions);
resolver_check(isset($withEnd->serverPlan()['entity_end_boundaries'][AF_ACS_Clock::entityEnd('33')])&&$withEnd->serverState()['status_instances']['new:1']['status']==='removed','Resolver omitted confirmed end boundary');
[$own]=tick_fixture('damage',2,1,'own_turn');$ownState=instance_run($initial,[instance_apply($own)])->serverState();
resolver_check(AF_ACS_Clock::advance($ownState,tick_boundary('entity_start','40','2'),[2=>[]],$catalog)->serverState()===$ownState,'Own-turn used target boundary');
resolver_check(AF_ACS_Clock::advance($ownState,tick_boundary('entity_start','40','1'),[2=>[]],$catalog)->serverState()['entities'][2]['hp']===18,'Own-turn source did not tick target');
[$roundTick]=tick_fixture('damage',2,1,'round');$roundState=instance_run($initial,[instance_apply($roundTick)])->serverState();
resolver_check(AF_ACS_Clock::advance($roundState,tick_boundary('round_start','50'),[2=>[]],$catalog)->serverState()['entities'][2]['hp']===18,'Round-start tick failed');
$roundStarted=AF_ACS_Clock::advance($roundState,tick_boundary('round_start','50'),[2=>[]],$catalog)->serverState();
resolver_check(AF_ACS_Clock::advance($roundStarted,tick_boundary('round_end','50'))->serverState()['status_instances']['new:1']['status']==='removed','Declared round-end decrement missing');
$endDef=$poisonDef;$endDef['duration']['value']=1;$endDef['decrement']['event']='on_turn_end';$endDef['mechanical_parameters']=['blocks_ordinary_actions'=>true];
$hybrid=AF_ACS_Effects::instanceSpec($endDef,['status_key'=>'poison','polarity'=>'negative'],[$poisonSnapshot]);
$hybridState=instance_run($initial,[instance_apply($hybrid)])->serverState();
resolver_check(AF_ACS_Clock::advance($hybridState,tick_boundary('entity_end','51'))->serverState()===$hybridState,'Duration consumed before first tick');
$hybridStarted=AF_ACS_Clock::advance($hybridState,tick_boundary('entity_start','52'),[2=>[]],$catalog)->serverState();
resolver_check($hybridStarted['entities'][2]['hp']===16&&!AF_ACS_Effects::ordinaryActionAllowed($hybridStarted,'2'),'Hybrid control expired before restrictions');
resolver_check(AF_ACS_Clock::advance($hybridStarted,tick_boundary('entity_end','52'))->serverState()['status_instances']['new:1']['status']==='removed','Hybrid control failed end expiry');
$plain=instance_run($initial,[instance_apply(instance_spec(['duration'=>['value'=>1,'unit'=>'round']]))])->serverState();
resolver_check(AF_ACS_Clock::advance($plain,tick_boundary('round_end','50'))->serverState()['status_instances']['new:1']['status']==='removed','Round-end countdown failed');
$paused=tick_boundary('entity_start','60');$paused['encounter_status']='paused';
try{AF_ACS_Clock::advance($ownState,$paused);throw new RuntimeException('Pause advanced');}catch(RuntimeException $e){resolver_check($e->getMessage()==='ACS_STATUS_CLOCK_PAUSED','Wrong paused error');}
$corrupt=$poisonSnapshot;$corrupt['source_json'].=' ';resolver_block(fn()=>AF_ACS_Effects::instanceSpec($poisonDef,['status_key'=>'poison','polarity'=>'negative'],[$corrupt]),'status_tick_pin_invalid');
resolver_check($initial===$empty,'Pure boundary mutated caller state');
echo "ACS ticks: poison=3/last tick/no apply tick, duplicate, HoT/recovery block, incapacitated/signals, control=1/end/independent sources, own/round boundaries, pause and pinned corruption passed\n";
