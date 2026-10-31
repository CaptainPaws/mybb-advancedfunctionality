<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Effects.php';
function effect_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
function effect_ref(string $key='fixture'):array{return ['key'=>$key,'version'=>'1','fingerprint'=>'sha256:'.str_repeat('a',64)];}
function effect_state():array{
    $e=['hp'=>20,'max_hp'=>20,'life_state'=>'active','recovery_blocked'=>false,'group_id'=>'1','resources'=>['energy'=>['current'=>10,'maximum'=>10]],'source_ref'=>effect_ref(),'version'=>1];
    return ['schema_version'=>1,'entities'=>[1=>$e,2=>$e],'groups'=>[1=>['ap'=>['current'=>3,'maximum'=>3],'version'=>1]],'barriers'=>[],'next_barrier_order'=>100];
}
function effect_pool(int $strength,int $order,int $priority=0,array $targets=['1'],string $key='ward'):array{
    return ['source_entity_id'=>'1','source_ref'=>effect_ref($key),'scope'=>count($targets)===1?'per_target':'shared_pool','scope_key'=>count($targets)===1?$targets[0]:'area',
        'targets'=>$targets,'strength'=>$strength,'maximum_strength'=>max(5,$strength),'barrier_priority'=>$priority,'applied_order'=>$order,'status'=>'active','version'=>1,'absorbs'=>['physical','elemental'],'duration'=>['value'=>3,'unit'=>'own_turn']];
}
function effect_allocation(int $n,string $type='physical'):AF_ACS_DamageResult{
    return new AF_ACS_DamageResult(['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'integer_budget'=>$n,'allocation_sum'=>$n,
        'components'=>[['source_index'=>0,'damage_type'=>$type,'allocated'=>$n]]],[]);
}
function effect_damage(array $targets):array{return ['kind'=>'damage','targets'=>array_map(static fn($t)=>['entity_id'=>$t[0],'allocation'=>effect_allocation($t[1],$t[2]??'physical')],$targets)];}
function effect_action(array $effects,array $costs=[]):array{return ['actor_id'=>'1','costs'=>$costs,'effects'=>$effects];}
function effect_error(array $r,string $code):void{effect_assert(!$r['ready']&&$r['evaluation']===null&&$r['diagnostics'][0]['code']===$code,'Expected '.$code.' got '.json_encode($r));}
$s=effect_state();$s['barriers']=[10=>effect_pool(3,1,0,['1'],'old'),11=>effect_pool(3,2,0,['1'],'new')];
$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['1',8]])]));$v=$r->serverState();
effect_assert($v['barriers'][10]['strength']===0&&$v['barriers'][11]['strength']===0&&$v['entities'][1]['hp']===18,'FIFO/partial absorption failed');
effect_assert($s['barriers'][10]['strength']===3&&$r->serverDependencies()['entities'][1]['hp']===20,'Preview/dependencies changed');
$s['barriers'][11]['barrier_priority']=-1;$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['1',3]])]));
effect_assert($r->serverState()['barriers'][10]['strength']===3&&$r->serverState()['barriers'][11]['strength']===0,'Priority did not precede FIFO');
$s['barriers']=[10=>effect_pool(3,1,0,['1'],'aa'),2=>effect_pool(3,1,0,['1'],'bb')];
$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['1',1]])]));effect_assert($r->serverState()['barriers'][2]['strength']===2,'ID tie-break not numeric');
$s=effect_state();$s['barriers']=[1=>effect_pool(5,1,0,['1','2'])];
$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['1',4],['2',4]])]));$v=$r->serverState();
effect_assert($v['entities'][1]['hp']===20&&$v['entities'][2]['hp']===17&&$v['barriers'][1]['status']==='destroyed','Shared pool duplicated');
$reverse=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['2',4],['1',4]])]));effect_assert($reverse->serverState()['entities'][1]['hp']===17,'Sealed target order not preserved');
$s=effect_state();$s['barriers']=[1=>effect_pool(5,1)];$s['barriers'][1]['absorbs']=['physical'];
foreach(['elemental','true'] as $type){$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['1',4,$type]])]));effect_assert($r->serverState()['barriers'][1]['strength']===5&&$r->serverState()['entities'][1]['hp']===16,'Filter/true bypass failed');}
$s=effect_state();$s['barriers']=[1=>effect_pool(1,1,0,['1'],'same'),2=>effect_pool(5,2,0,['1'],'other')];
$r=AF_ACS_Effects::simulate($s,effect_action([['kind'=>'barrier_restore','pool_id'=>'1']]));$p=$r->serverState()['barriers'][1];
effect_assert($p['strength']===5&&$p['applied_order']===1&&$p['duration']===$s['barriers'][1]['duration'],'Restore changed order/duration');
$new=array_diff_key(effect_pool(5,99,0,['1'],'same'),array_flip(['applied_order','status','version']));
$r=AF_ACS_Effects::simulate($s,effect_action([['kind'=>'barrier_apply','pool_id'=>'3','pool'=>$new],effect_damage([['1',1]])]));$v=$r->serverState();
effect_assert($v['barriers'][1]['status']==='replaced'&&$v['barriers'][3]['applied_order']===100&&$v['barriers'][2]['strength']===4,'Replacement did not move to end');
$s['barriers'][1]['strength']=0;$s['barriers'][1]['status']='destroyed';effect_error(AF_ACS_Effects::attempt($s,effect_action([['kind'=>'barrier_restore','pool_id'=>'1']])),'effects_barrier_restore_forbidden');
$s=effect_state();$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['2',50]]),['kind'=>'heal','entity_id'=>'2','amount'=>30]]));
$events=$r->serverTrace()['events'];$damage=array_values(array_filter($events,fn($e)=>$e['type']==='damage'))[0];$heal=array_values(array_filter($events,fn($e)=>$e['type']==='heal'))[0];
effect_assert($damage['hp_loss']===20&&$damage['overkill']===30&&$heal['effective_recovery']===20&&$heal['overheal']===10&&$r->serverState()['entities'][2]['life_state']==='active','HP/overkill/overheal failed');
effect_assert(count(array_filter($r->serverTrace()['signals'],fn($e)=>$e['type']==='stop_sustained_required'))===1,'Sustained stop signal missing');
$r=AF_ACS_Effects::simulate($s,effect_action([['kind'=>'set_max_hp','entity_id'=>'1','amount'=>100]]));effect_assert($r->serverState()['entities'][1]['hp']===20,'Max HP increase scaled current HP');
$r=AF_ACS_Effects::simulate($s,effect_action([['kind'=>'set_max_hp','entity_id'=>'1','amount'=>7]]));effect_assert($r->serverState()['entities'][1]['hp']===7,'Max HP decrease not clamped');
$dead=$s;$dead['entities'][2]['hp']=0;$dead['entities'][2]['life_state']='dead';effect_error(AF_ACS_Effects::attempt($dead,effect_action([['kind'=>'heal','entity_id'=>'2','amount'=>1]])),'effects_dead_heal_forbidden');
$costs=[['kind'=>'hp','amount'=>2],['kind'=>'ap','amount'=>1],['kind'=>'resource','resource'=>'energy','amount'=>3]];
$r=AF_ACS_Effects::simulate($s,effect_action([],$costs));effect_assert($r->serverState()['entities'][1]['hp']===18&&$r->serverState()['groups'][1]['ap']['current']===2&&$r->serverState()['entities'][1]['resources']['energy']['current']===7,'Virtual resource costs failed');
$bad=$costs;$bad[2]['amount']=100;$before=$s;effect_error(AF_ACS_Effects::attempt($s,effect_action([],$bad)),'effects_cost_unaffordable');effect_assert($before===$s,'Failed action left partial state');
effect_error(AF_ACS_Effects::attempt($s,effect_action([],[['kind'=>'hp','amount'=>20]])),'effects_cost_unaffordable');
$a=effect_action([['kind'=>'heal','entity_id'=>'2','amount'=>1]],[['kind'=>'hp','amount'=>20]]);$a['hp_cost_policy']=['allow_zero'=>true,'source_ref'=>effect_ref('approved-sacrifice')];
$r=AF_ACS_Effects::simulate($s,$a);effect_assert($r->serverState()['entities'][1]['life_state']==='incapacitated'&&$r->serverState()['entities'][1]['hp']===0&&$r->serverTrace()['current_action_may_complete'],'Sacrifice failed to finish current action');
effect_error(AF_ACS_Effects::attempt($r->serverState(),effect_action([],[['kind'=>'ap','amount'=>1]])),'effects_actor_unavailable');
$blocked=$s;$blocked['entities'][2]['hp']=0;$blocked['entities'][2]['life_state']='incapacitated';$blocked['entities'][2]['recovery_blocked']=true;
$r=AF_ACS_Effects::simulate($blocked,effect_action([['kind'=>'heal','entity_id'=>'2','amount'=>5]]));effect_assert($r->serverState()['entities'][2]['hp']===5&&$r->serverState()['entities'][2]['life_state']==='incapacitated','Other recovery restriction ignored');
$a=effect_action([],[['kind'=>'ap','amount'=>2]]);$a['actor_id']='2';$paid=AF_ACS_Effects::simulate($s,$a)->serverState();
effect_error(AF_ACS_Effects::attempt($paid,effect_action([],[['kind'=>'ap','amount'=>2]])),'effects_cost_unaffordable');
$zeroPool=array_diff_key(effect_pool(0,99),array_flip(['status','version','applied_order']));
$zeroApplied=AF_ACS_Effects::simulate($s,effect_action([['kind'=>'barrier_apply','pool_id'=>'99','pool'=>$zeroPool]]))->serverState();effect_assert($zeroApplied['barriers'][99]['status']==='destroyed','Zero barrier gained strength');
$r=AF_ACS_Effects::simulate($s,effect_action([effect_damage([['2',0]])]));effect_assert($r->serverState()===$s,'Zero damage changed state');
$hp=20;$refState=$s;$refState['entities'][1]['hp']=&$hp;AF_ACS_Effects::simulate($refState,effect_action([],[['kind'=>'hp','amount'=>2]]));effect_assert($hp===20,'PHP reference leaked preview mutation');
effect_assert(!str_contains(json_encode($r),'hp'),'Private virtual state serialized');
// End-to-end integer allocation, never re-round components during HP consumption.
require __DIR__.'/acs_damage_regression.php';
$allocation=AF_ACS_Damage::calculate([damage_component(0,0.4),damage_component(1,0.4)],[],[],$catalog);
$state=effect_state();$beforeTables=$db->tables;$beforeQueries=count($db->queries);
$r=AF_ACS_Effects::simulate($state,effect_action([['kind'=>'damage','targets'=>[['entity_id'=>'2','allocation'=>$allocation]]]]));
effect_assert($r->serverState()['entities'][2]['hp']===19,'Effects re-rounded allocated damage');
effect_assert($db->tables===$beforeTables&&count($db->queries)===$beforeQueries,'Preview performed I/O');
require_once AF_ADDONS.'advancedcombatsystem/modules/Administration/EffectsDiagnostics.php';
$readsBefore=count($db->queries);ob_start();AF_ACS_EffectsDiagnostics::render();$html=ob_get_clean();
effect_assert(str_contains($html,'acs-effects-1')&&str_contains($html,'HP второго участника|17')&&str_contains($html,'AP группы|2'),'ACP prepared example failed');
effect_assert(count($db->queries)===$readsBefore&&$db->tables===$beforeTables,'ACP example performed I/O');
$permissions=$mybb->admin;$mybb->admin=['permissions'=>[]];$denied=false;
try{AF_ACS_EffectsDiagnostics::render();}catch(RuntimeException $error){$denied=true;}
$mybb->admin=$permissions;effect_assert($denied,'ACP example bypassed native permissions');
echo "ACS Effects: priority/FIFO/ID, filters/true, shared sequential pools, replace/restore, HP/cost/overkill/overheal, atomic virtual-only and integer Damage integration passed\n";
