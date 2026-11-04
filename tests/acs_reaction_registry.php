<?php
declare(strict_types=1);
require __DIR__.'/acs_sustained_upkeep.php';
function reaction_node(array $d,string $key):array{$json=json_encode(af_kb_arpg_acs_canonical($d),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return ['json'=>$json,'ref'=>['key'=>$key,'version'=>$d['version'],'fingerprint'=>'sha256:'.hash('sha256',$json)]];}
function reaction_fixture(string $phase,int $priority=0,int $cost=0,?array $condition=null,array $effect=['kind'=>'heal','value'=>3]):array {
    $t=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'guard-trigger','version'=>'1','event'=>$phase,'priority'=>$priority,'condition_ref'=>$condition===null?null:'condition_template:guard-condition','effect_ref'=>['type'=>'arpg_ability','key'=>'guard-effect','effect_index'=>0],'limits'=>['per_round'=>1],'formula_refs'=>[]];
    $a=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>'self','effects'=>[$effect],'costs'=>[['kind'=>'resource','resource_key'=>'ep','value'=>$cost,'timing'=>'use']]];
    return ['schema_version'=>1,'trigger'=>reaction_node($t,'guard-trigger'),'ability'=>reaction_node($a,'guard-effect'),'condition'=>$condition===null?null:reaction_node(['schema'=>'af_kb.arpg.mechanic.v1','key'=>'guard-condition','version'=>'1','predicate'=>$condition],'guard-condition')];
}
function reaction_entry(string $id,array $snapshot,int $order=1):array{$spec=AF_ACS_Effects::reactionSpec($snapshot);return ['id'=>$id,'entity_id'=>'2','encounter_id'=>'1','phase'=>$spec['phase'],'priority'=>$spec['priority'],'registered_order'=>$order,'status'=>'active','reaction_version'=>1,'spec'=>$spec,'enabled'=>true,'last_execution'=>null];}
// Restore a small ordinary attack context independent of previous status fixture mutations.
$ctx=['schema_version'=>1,'state'=>effect_state(),'encounter_status'=>'active','stats'=>[1=>['accuracy'=>20,'speed'=>20,'crit_rate'=>50,'crit_dmg'=>50],2=>['accuracy'=>20,'speed'=>20,'crit_rate'=>50,'crit_dmg'=>50]],'defenses'=>[1=>['def'=>0],2=>['def'=>100]],'element_catalog'=>$catalog,'turns'=>[1=>['status'=>'pending','started'=>true,'group_id'=>'1'],2=>['status'=>'resolved','started'=>false,'group_id'=>'2']],
    'abilities'=>[1=>['strike'=>['supported'=>true,'source_ref'=>effect_ref('strike'),'components'=>[['source_index'=>0,'damage_type'=>'physical','value'=>20]],'costs'=>[['kind'=>'ap','amount'=>1]]]]],'allowed_targets'=>[1=>['2']],
    'reaction_turns'=>[2=>['id'=>'22','used'=>0,'version'=>1]],'reaction_kinds'=>[2=>'monster'],'status_scope'=>['session_id'=>'1','encounter_id'=>'1']];
$ctx['state']['groups'][2]=$ctx['state']['groups'][1];$ctx['state']['entities'][2]['group_id']='2';
$ctx['state']['entities'][2]['resources']=['ep'=>['current'=>5,'maximum'=>5]];$ctx['state']['entities'][2]['hp']=10;$ctx['state']['status_instances']=[];$ctx['state']['next_status_order']=1;
$action=[['actor_id'=>'1','ability_key'=>'strike','targets'=>['2'],'scenario'=>'hit']];
$ctx['reactions']=[reaction_entry('5',reaction_fixture('after_damage',-999),1),reaction_entry('4',reaction_fixture('before_hit',-100),1),reaction_entry('3',reaction_fixture('on_targeted',10),1),reaction_entry('2',reaction_fixture('on_targeted',0,6),2),reaction_entry('1',reaction_fixture('on_targeted',0,1),2)];
$r=AF_ACS_Resolver::resolve($ctx,$action);$trace=$r->serverPlan()['actions'][0]['reactions'];
resolver_check(array_column($trace,'id')===['1','2','3','4','5'],'Phase/KB priority/FIFO/ID order wrong');resolver_check($trace[0]['executed']&&$trace[1]['reason']==='limit_used'&&$r->serverPlan()['reaction_limits'][2]['used']===1,'Limit not consumed once');
resolver_check($r->serverState()['entities'][2]['resources']['ep']['current']===4&&$r->serverState()['groups'][2]['ap']===$ctx['state']['groups'][2]['ap'],'Reaction spent AP or wrong EP');
$ctx['reactions']=[reaction_entry('1',reaction_fixture('before_hit',0,6)),reaction_entry('2',reaction_fixture('before_hit',1,2))];
$r=AF_ACS_Resolver::resolve($ctx,$action);$trace=$r->serverPlan()['actions'][0]['reactions'];resolver_check(!$trace[0]['executed']&&$trace[0]['reason']==='effects_cost_unaffordable'&&$trace[1]['executed']&&$r->serverState()['entities'][2]['resources']['ep']['current']===3,'Unaffordable reaction blocked next/paid partially');
resolver_check($r->serverState()['entities'][2]['hp']===3,'before_hit heal not before primary damage');
$ctx['reactions']=[reaction_entry('10',reaction_fixture('before_hit',0,99),1),reaction_entry('1',reaction_fixture('before_hit',0,99),2),reaction_entry('2',reaction_fixture('before_hit',0,1),3)];
$r=AF_ACS_Resolver::resolve($ctx,$action);resolver_check(array_column($r->serverPlan()['actions'][0]['reactions'],'id')===['10','1','2'],'Registration order lost to stable ID');
$multi=reaction_fixture('before_hit',0,99);$a=json_decode($multi['ability']['json'],true);array_unshift($a['costs'],['kind'=>'resource','resource_key'=>'hp','value'=>2,'timing'=>'use']);$multi['ability']=reaction_node($a,'guard-effect');
$ctx['reactions']=[reaction_entry('1',$multi),reaction_entry('2',reaction_fixture('before_hit',1,1))];$r=AF_ACS_Resolver::resolve($ctx,$action);resolver_check($r->serverState()['entities'][2]['hp']===3,'Two-resource shortfall partially debited HP');
$ctx['reactions']=[reaction_entry('1',reaction_fixture('after_damage',0,1))];$r=AF_ACS_Resolver::resolve($ctx,$action);resolver_check(!$r->serverPlan()['actions'][0]['reactions'][0]['executed']&&$r->serverState()['entities'][2]['hp']===0,'Incapacitated reaction executed after damage');
$ctx['state']['entities'][2]['hp']=20;$ctx['reactions']=[reaction_entry('1',reaction_fixture('after_hit',0,1,['operator'=>'eq','input'=>'event.hit_success','value'=>true]))];
$miss=$action;$miss[0]['scenario']='miss';$r=AF_ACS_Resolver::resolve($ctx,$miss);resolver_check($r->serverPlan()['actions'][0]['reactions'][0]['reason']==='hit_required','Miss executed after_hit');
$conditionCtx=$ctx;$conditionCtx['reactions']=[reaction_entry('1',reaction_fixture('on_targeted',0,1,['operator'=>'lt','input'=>'actor.hp','value'=>1])),reaction_entry('2',reaction_fixture('before_hit',0,1))];$r=AF_ACS_Resolver::resolve($conditionCtx,$action);resolver_check($r->serverPlan()['actions'][0]['reactions'][0]['reason']==='condition_false'&&$r->serverPlan()['actions'][0]['reactions'][1]['executed'],'Condition was not checked before each reaction');
$broken=reaction_fixture('before_hit');$broken['ability']['json'].=' ';resolver_block(fn()=>AF_ACS_Effects::reactionSpec($broken),'reaction_pin_invalid');
$ctx['reaction_turns'][2]['used']=1;$r=AF_ACS_Resolver::resolve($ctx,$action);resolver_check($r->serverState()['entities'][2]['resources']['ep']['current']===5&&$r->serverPlan()['reaction_updates']===[],'Used limit was spent twice');$ctx['reaction_turns'][2]['used']=0;
resolver_block(fn()=>AF_ACS_Effects::reactionSpec(reaction_fixture('before_hit',0,1,['operator'=>'eq','input'=>'event.hit_success','value'=>true])),'reaction_condition_phase_unsupported');
$predicate=['operator'=>'not','conditions'=>[['operator'=>'lt','input'=>'actor.hp','value'=>1]]];resolver_check(AF_ACS_Effects::reactionCondition($predicate,['actor.hp'=>2]),'KB not grammar mismatch');
resolver_block(fn()=>AF_ACS_Effects::reactionCondition(['operator'=>'eq','input'=>'actor.mask','value'=>'GM'],[],true),'reaction_condition_unsupported');
$ctx['reactions']=[reaction_entry('1',reaction_fixture('on_targeted',0,1,null,['kind'=>'cleanse','chance'=>0]))];$ctx['reaction_causes']=['sealed-action:8'];$key='reaction:1:sealed-action:8:on_targeted';$ctx['reaction_rolls']=[$key=>['id'=>'9','sample'=>['sample_n'=>0,'u'=>0.0]]];
$tape=(new AF_ACS_RandomSource(static fn()=>0))->action();$sealed=$action;unset($sealed[0]['scenario']);$r=AF_ACS_Resolver::resolve($ctx,$sealed,[$tape],'seal');
resolver_check($r->serverPlan()['reaction_limits'][2]['used']===1&&$r->serverState()['entities'][2]['resources']['ep']['current']===4,'Failed reaction outcome refunded execution');
resolver_check(AF_ACS_Resolver::resolve($ctx,$sealed,[$tape],'seal')->serverPlan()===$r->serverPlan()&&$tape['hit']['u']===0.0,'Fixed tape rerolled');
$ctx['reaction_rolls']=[];resolver_block(fn()=>AF_ACS_Resolver::resolve($ctx,$sealed,[$tape],'seal'),'reaction_roll_pending');$ctx['state']['entities'][2]['resources']['ep']['current']=0;
resolver_check(AF_ACS_Resolver::resolve($ctx,$sealed,[$tape],'seal')->serverPlan()['reaction_limits'][2]['used']===0,'Unaffordable reaction required random check');
echo "ACS reactions: phase/priority/registration/ID ordering, atomic affordability skip, separate slot/AP, after resolved, inactive/hit/conditions, fixed causal roll and no-result refund passed\n";
