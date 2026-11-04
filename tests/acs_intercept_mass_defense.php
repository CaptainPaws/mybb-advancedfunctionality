<?php
declare(strict_types=1);
require __DIR__.'/acs_reaction_registry.php';
function guard_fixture(string $selection,array $effect,int $cost=1):array {
    $s=reaction_fixture('before_hit',0,$cost,null,$effect);$a=json_decode($s['ability']['json'],true);$a['targeting']=$selection;$s['ability']=reaction_node($a,'guard-effect');return $s;
}
function guard_entry(string $id,string $source,array $snapshot,array $targets,int $order=1):array {
    $r=reaction_entry($id,$snapshot,$order);$r['entity_id']=$source;$r['protected_targets']=$targets;return $r;
}
// Different defender statistics force a fresh hit/defense calculation with exactly the original samples.
$c=$ctx;$c['state']['entities'][2]['hp']=20;$c['state']['entities'][2]['resources']['ep']['current']=5;
$c['state']['entities'][3]=$c['state']['entities'][2];$c['state']['entities'][3]['group_id']='3';$c['state']['groups'][3]=$c['state']['groups'][2];
$c['stats'][3]=$c['stats'][2];$c['stats'][3]['speed']=200;$c['defenses'][3]=['def'=>0];$c['turns'][3]=['status'=>'resolved','started'=>false,'group_id'=>'3'];
$c['reaction_turns'][3]=['id'=>'33','used'=>0,'version'=>1];$c['reaction_kinds'][3]='monster';$c['relationships']=[1=>[2=>'hostile',3=>'hostile'],2=>[2=>'self',3=>'ally'],3=>[2=>'ally',3=>'self']];$c['redirect_targets']=[1=>['2','3']];
$c['reaction_causes']=['sealed-action:8'];$c['reaction_rolls']=[];
$intercept=guard_fixture('single_ally',['kind'=>'control','control_type'=>'intercept']);$c['reactions']=[guard_entry('7','3',$intercept,['2'])];
$attack=$action;unset($attack[0]['scenario']);$fixed=(new AF_ACS_RandomSource(static fn()=>5404319552844595))->action();
$plain=$c;$plain['reactions']=[];$before=AF_ACS_Resolver::resolve($plain,$attack,[$fixed],'seal');$r=AF_ACS_Resolver::resolve($c,$attack,[$fixed],'seal');$p=$r->serverPlan()['actions'][0];
resolver_check($p['original_target']==='2'&&$p['resolved_target']==='3'&&$p['scenario']==='miss'&&$before->serverPlan()['actions'][0]['scenario']!=='miss','Intercept did not recalculate hit against new Speed');
resolver_check($p['rolls']['hit']===$fixed['hit']&&$p['rolls']['crit']===$fixed['crit']&&$r->serverState()['entities'][2]['hp']===20,'Intercept rerolled or damaged original target');
$low=(new AF_ACS_RandomSource(static fn()=>0))->action();$r=AF_ACS_Resolver::resolve($c,$attack,[$low],'seal');$p=$r->serverPlan()['actions'][0];
resolver_check($r->serverState()['entities'][3]['hp']===0&&$r->serverState()['entities'][2]['hp']===20&&$p['allocation']['components'][0]['effective_defense']==0,'Redirect did not use new DEF/sole consequences');
resolver_check(AF_ACS_Resolver::resolve($c,$attack,[$low],'seal')->serverPlan()===$r->serverPlan(),'Replay changed result');
// Shortage skips the redirect without blocking the original attack.
$poor=$c;$poor['state']['entities'][3]['resources']['ep']['current']=0;$r=AF_ACS_Resolver::resolve($poor,$attack,[$low],'seal');resolver_check($r->serverPlan()['actions'][0]['resolved_target']==='2'&&!$r->serverPlan()['actions'][0]['reactions'][0]['executed'],'Unpaid reaction redirected');
// A failed intercept leaves the next source eligible; a successful one cannot redirect again.
$c['state']['entities'][4]=$c['state']['entities'][3];$c['stats'][4]=$c['stats'][3];$c['defenses'][4]=['def'=>0];$c['reaction_turns'][4]=['id'=>'44','used'=>0,'version'=>1];$c['reaction_kinds'][4]='monster';$c['redirect_targets'][1][]='4';$c['relationships'][4]=[2=>'ally',3=>'ally'];
$failure=guard_fixture('single_ally',['kind'=>'control','control_type'=>'intercept','chance'=>0]);$c['reactions']=[guard_entry('6','4',$failure,['2']),guard_entry('7','3',$intercept,['2']),guard_entry('8','4',$intercept,['3'])];$c['reaction_rolls']['reaction:6:sealed-action:8:before_hit']=['id'=>'90','sample'=>$low['hit']];
$r=AF_ACS_Resolver::resolve($c,$attack,[$low],'seal');$tr=$r->serverPlan()['actions'][0]['reactions'];resolver_check(!$tr[0]['redirect_success']&&$tr[1]['redirect_success']&&$tr[2]['reason']==='limit_used','Failed redirect blocked next / second redirect executed');
// Explicit mass pool, ordered multiple attack, one main payment/slot; shared strength is consumed in selected order.
$mass=guard_fixture('multiple',['kind'=>'shield','mass'=>true,'value'=>25,'scope'=>'shared_pool','scope_key'=>'guard','absorbs'=>['physical'],'duration_value'=>2,'duration_unit'=>'own_turn']);
$c['reactions']=[guard_entry('9','3',$mass,['3','2'])];$c['abilities'][1]['strike']['targeting']='multiple';$c['abilities'][1]['strike']['target_count']=['min'=>2,'max'=>2];$c['allowed_targets'][1]=['2','3'];$c['defenses'][2]=['def'=>0];$c['stats'][3]['speed']=20;$c['abilities'][1]['strike']['hit_policy']=['mode'=>'automatic'];
// Use ordinary policy rather than inventing automatic-hit metadata.
unset($c['abilities'][1]['strike']['hit_policy']);$multi=$attack;$multi[0]['targets']=['3','2'];$mt=['rng_version'=>'uniform53-v1','targets'=>[3=>$low,2=>$low]];
$r=AF_ACS_Resolver::resolve($c,$multi,[$mt],'seal');$p=$r->serverPlan()['actions'][0];resolver_check(array_column($p['strikes'],'original_target')===['3','2'],'Selected order changed');
resolver_check(count($r->serverState()['barriers'])===1&&$r->serverState()['entities'][3]['resources']['ep']['current']===4&&count($r->serverPlan()['reaction_updates'])===1,'Mass defense charged per target or created extra activations');
resolver_check($r->serverState()['entities'][3]['hp']===15&&$r->serverState()['entities'][2]['hp']===0,'Shared pool did not absorb in selected order');
// Causal graph validates before any result adoption; repeated DAG child executes once.
resolver_block(fn()=>AF_ACS_CausalGraph::order(['a'=>['children'=>['b']],'b'=>['children'=>['a']]],['a'],10),'ACS_CAUSAL_CYCLE');
resolver_block(fn()=>AF_ACS_CausalGraph::order(['a'=>['children'=>['b']],'b'=>['children'=>[]]],['a'],1),'ACS_CAUSAL_DEPTH_EXCEEDED');
resolver_check(AF_ACS_CausalGraph::order(['a'=>['children'=>['b','b']],'b'=>['children'=>[]]],['a','b'],2)===['a','b'],'Causal repeat protection unstable');
echo "ACS intercept/mass: changed Speed/DEF, original/resolved targets, same hit/crit samples/replay, affordability skip, failed-next/one redirect, one mass payment/slot and ordered shared pool, causal cycle/depth/visited passed\n";

$poolState=$r->serverState();$boundary=['phase'=>'entity_end','id'=>'88','entity_id'=>'3','session_id'=>'1','encounter_id'=>'1','encounter_status'=>'active'];
// Restore a non-depleted pool to exercise time boundaries independently of absorption.
$poolId=array_key_first($poolState['barriers']);$poolState['barriers'][$poolId]['strength']=25;$poolState['barriers'][$poolId]['status']='active';
$clock=AF_ACS_Clock::advance($poolState,$boundary);resolver_check($clock->serverState()['barriers'][$poolId]['duration']['value']===1,'Barrier duration boundary missing');
resolver_check(AF_ACS_Clock::advance($clock->serverState(),$boundary)->serverState()===$clock->serverState(),'Duplicate barrier countdown');$boundary['id']='89';$expired=AF_ACS_Clock::advance($clock->serverState(),$boundary);resolver_check($expired->serverState()['barriers'][$poolId]['status']==='expired','Barrier did not expire');
// A retained passive cycle aborts the same Resolver operation before returning costs/limits/state.
$cyclic=guard_fixture('single_ally',['kind'=>'control','control_type'=>'intercept']);$ca=json_decode($cyclic['ability']['json'],true);$wrapper=['kind'=>'control','control_type'=>'passive','effect_ref'=>['type'=>'arpg_ability','key'=>'passive-child','effect_index'=>0]];$ca['passive_effects']=[$wrapper];$cyclic['ability']=reaction_node($ca,'guard-effect');
$ct=json_decode($cyclic['trigger']['json'],true);$ct['limits']+=['max_depth'=>4,'per_cause'=>1];$cyclic['trigger']=reaction_node($ct,'guard-trigger');
$child=['schema'=>'af_kb.arpg.rules.v1','version'=>'1','targeting'=>'self','effects'=>[['kind'=>'heal','target'=>'self','value'=>1]],'passive_effects'=>[$wrapper]];$cyclic['passives']=[['source'=>reaction_node($child,'passive-child'),'effect_index'=>0]];
$cc=$c;unset($cc['abilities'][1]['strike']['targeting'],$cc['abilities'][1]['strike']['target_count']);$cc['reactions']=[guard_entry('7','3',$cyclic,['2'])];$savedContext=$cc;$savedTape=$low;
resolver_block(fn()=>AF_ACS_Resolver::resolve($cc,$attack,[$low],'seal'),'ACS_CAUSAL_CYCLE');resolver_check($cc===$savedContext&&$low===$savedTape,'Cycle mutated input state/tape');
echo "ACS protection boundaries: countdown/duplicate/expiry and retained causal-cycle rejection preserve state/tape passed\n";

$multiStore=new StatusTapeFixture;$multiTape=['rng_version'=>'uniform53-v1','targets'=>[3=>$low,2=>$low]];
$multiStore->appendSeal(['id'=>'1','session_id'=>'1'],['actor_id'=>'1','ability_key'=>'strike','targets'=>['3','2']],[],$ref,1,'multiple-operation',0,$multiTape);
resolver_check($multiStore->sealTape('10')===$multiTape,'Target-specific tape SQL roundtrip changed selected order or samples');
echo "ACS multiple tape: existing roll rows retain ordered target causal identities and samples passed\n";
