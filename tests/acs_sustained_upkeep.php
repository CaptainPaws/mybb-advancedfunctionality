<?php
declare(strict_types=1);
require __DIR__.'/acs_status_ticks.php';
function upkeep_fixture(array $costs,array $mechanics=[]):array {
    global $base;
    $spec=$base;$spec['duration']=['value'=>10,'unit'=>'target_turn'];$spec['repeat_rule']='independent';
    $spec['upkeep']=AF_ACS_Effects::upkeepSpec(array_map(static fn($c)=>['kind'=>'resource','resource_key'=>$c[0],'value'=>$c[1],'timing'=>'sustain','period'=>['value'=>1,'unit'=>'own_turn']],$costs),$mechanics);
    return $spec;
}
$state=$empty;$state['entities'][1]['resources']=['ep'=>['current'=>5,'maximum'=>5]];
$paid=instance_run($state,[instance_apply(upkeep_fixture([['hp',2],['ep',3],['ap',1]]))])->serverState();
$r=AF_ACS_Clock::advance($paid,tick_boundary('entity_start','101','1'));$after=$r->serverState();
resolver_check($after['entities'][1]['hp']===$state['entities'][1]['hp']-2&&$after['entities'][1]['resources']['ep']['current']===2&&$after['groups'][1]['ap']['current']===$state['groups'][1]['ap']['current']-1,'Successful HP/EP/AP upkeep missing');
resolver_check($after['status_instances']['new:1']['last_upkeep']['paid'],'Payment result absent');
resolver_check(AF_ACS_Clock::advance($after,tick_boundary('entity_start','101','1'))->serverState()===$after,'Duplicate paid twice');
$failed=instance_run($state,[instance_apply(upkeep_fixture([['hp',2],['ep',6]])),instance_apply(upkeep_fixture([['ep',4]])),instance_apply(upkeep_fixture([['ep',2]]))])->serverState();
$after=AF_ACS_Clock::advance($failed,tick_boundary('entity_start','102','1'))->serverState();
resolver_check($after['entities'][1]['hp']===$state['entities'][1]['hp']&&$after['entities'][1]['resources']['ep']['current']===1,'Partial debit or refund after later failure');
resolver_check($after['status_instances']['new:1']['status']==='removed'&&$after['status_instances']['new:2']['last_upkeep']['paid']&&$after['status_instances']['new:3']['status']==='removed','Independent FIFO failed');
$custom=$failed;$custom['status_instances']['new:3']['upkeep_priority']=1;$custom['status_instances']['new:2']['upkeep_priority']=2;$custom['status_instances']['new:1']['upkeep_priority']=3;
$after=AF_ACS_Clock::advance($custom,tick_boundary('entity_start','103','1'))->serverState();
resolver_check($after['status_instances']['new:3']['last_upkeep']['paid']&&$after['status_instances']['new:2']['status']==='removed'&&$after['entities'][1]['resources']['ep']['current']===3,'Stored player priority ignored');
$hp=$state['entities'][1]['hp'];$strict=instance_run($state,[instance_apply(upkeep_fixture([['hp',$hp]]))])->serverState();
$after=AF_ACS_Clock::advance($strict,tick_boundary('entity_start','104','1'))->serverState();resolver_check($after['entities'][1]['hp']===$hp&&$after['status_instances']['new:1']['status']==='removed','Strict HP minimum bypassed');
$sacrifice=instance_run($state,[instance_apply(upkeep_fixture([['hp',$hp]],['upkeep_hp_allow_zero'=>true])),instance_apply(upkeep_fixture([['ep',1]]))])->serverState();
$after=AF_ACS_Clock::advance($sacrifice,tick_boundary('entity_start','105','1'))->serverState();resolver_check($after['entities'][1]['hp']===0&&$after['entities'][1]['life_state']==='incapacitated'&&$after['status_instances']['new:1']['status']==='removed'&&$after['status_instances']['new:2']['status']==='removed','Sacrifice did not immediately stop sustained');
$inactive=$paid;$inactive['entities'][1]['hp']=0;$inactive['entities'][1]['life_state']='incapacitated';
$healed=instance_run($inactive,[['kind'=>'heal','entity_id'=>'1','amount'=>3]])->serverState();resolver_check($healed['entities'][1]['life_state']==='active'&&$healed['status_instances']['new:1']['status']==='removed','Healing resurrected sustained');
$autonomous=instance_run($state,[instance_apply(upkeep_fixture([['ep',1]],['autonomous'=>true]))])->serverState();$autonomous['entities'][1]['hp']=0;$autonomous['entities'][1]['life_state']='incapacitated';
$after=AF_ACS_Clock::advance($autonomous,tick_boundary('entity_start','106','1'))->serverState();resolver_check($after['status_instances']['new:1']['status']==='active'&&$after['entities'][1]['resources']['ep']['current']===4,'Explicit autonomous exception lost');
// Mandatory DoT incapacitates source before its upkeep; later HoT cannot revive a stopped effect.
[$dot]=tick_fixture('damage',$hp,2);$a=instance_apply($dot);$a['target']='1';
$ordered=instance_run($state,[instance_apply(upkeep_fixture([['ep',1]])),$a])->serverState();
$after=AF_ACS_Clock::advance($ordered,tick_boundary('entity_start','107','1'),[1=>[]],$catalog)->serverState();
resolver_check($after['entities'][1]['hp']===0&&$after['entities'][1]['resources']['ep']['current']===5&&$after['status_instances']['new:1']['status']==='removed','Upkeep preceded mandatory DoT');
$control=$base;$control['effect_key']='silence';$control['duration']=['value'=>1,'unit'=>'target_turn'];$op=instance_apply($control);$op['target']='1';
$controlled=instance_run($state,[instance_apply(upkeep_fixture([['ep',1]],['interrupt_on_status_keys'=>['silence']])),$op])->serverState();resolver_check($controlled['status_instances']['new:1']['status']==='removed'&&$controlled['status_instances']['new:1']['termination']['reason']==='kb_control','KB interruption ignored');
$uncontrolled=instance_run($state,[instance_apply(upkeep_fixture([['ep',1]])),$op])->serverState();resolver_check($uncontrolled['status_instances']['new:1']['status']==='active','Invented silence interruption');
$other=AF_ACS_Clock::advance($paid,tick_boundary('entity_start','108','2'))->serverState();resolver_check(!isset($other['status_instances']['new:1']['last_upkeep']),'Target boundary paid source upkeep');
resolver_check($state['entities'][1]['resources']['ep']['current']===5,'Pure evaluation mutated input');
echo "ACS sustained: successful HP/EP/AP, atomic shortfall, independent payments/FIFO/custom priority, strict HP/exception, immediate interruption/no revival/autonomy/control, tick priority and duplicate boundary passed\n";
