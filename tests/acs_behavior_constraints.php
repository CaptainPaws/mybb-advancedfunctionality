<?php
declare(strict_types=1);
require __DIR__.'/acs_reaction_registry.php';
function behavior_spec(array $rules,array $overrides=[]):array {
    return array_replace(['schema'=>'ic-acs.behavior-constraint.v1','priority'=>10,'scope'=>['ordinary','reaction'],
        'compatibility'=>['group'=>'attention','mode'=>'exclusive'],'on_no_legal_target'=>'block','rules'=>$rules],$overrides);
}
function behavior_apply(array $spec,string $source='2',string $target='1'):array {
    return array_replace(instance_apply(instance_spec(['repeat_rule'=>'independent','duration'=>['value'=>1,'unit'=>'target_turn'],
        'mechanical_parameters'=>['behavior_constraint'=>$spec]]),$source),['target'=>$target]);
}
$candidates=['1'=>['kind'=>'character','relationship'=>'self','active'=>true],'2'=>['kind'=>'monster','relationship'=>'hostile','active'=>true],'3'=>['kind'=>'monster','relationship'=>'hostile','active'=>true]];
$world=['session_id'=>'1','encounter_id'=>'1'];$policy=['kind'=>'hostile_only'];
$plain=effect_state();$plain['entities'][3]=$plain['entities'][2];$plain['status_instances']=[];$plain['next_status_order']=1;
$eval=static fn(array $s,?array $c=null)=>AF_ACS_Behavior::evaluate($s['status_instances'],'1','strike','ordinary',$c??$candidates,$policy,$world);
$taunt=behavior_spec([['kind'=>'target_priority','target'=>'source']]);
$s=instance_run($plain,[behavior_apply($taunt,'3')])->serverState();
resolver_check($eval($s)['legal_targets']===['3'],'Taunt priority ignored legal source');
$hidden=$candidates;unset($hidden['3']);$r=$eval($s,$hidden);
resolver_check($r===['status'=>'BLOCKED','code'=>'ACS_BEHAVIOR_NO_LEGAL_TARGET','legal_targets'=>[]],'Hidden source leaked/fallback invented');
$ignore=$taunt;$ignore['on_no_legal_target']='ignore_rule';$fallback=instance_run($plain,[behavior_apply($ignore,'3')])->serverState();
resolver_check($eval($fallback,$hidden)['legal_targets']===['2'],'Explicit ignore_rule fallback did not retain base targets');
resolver_check(AF_ACS_Behavior::evaluate($s['status_instances'],'1','strike','ordinary',$candidates,$policy,$world+['minimum_targets'=>2])['status']==='BLOCKED','Insufficient cardinality not blocked');
$inactive=$candidates;$inactive[3]['active']=false;resolver_check($eval($s,$inactive)['status']==='BLOCKED','Unavailable source selected');
$fear=behavior_spec([['kind'=>'target_exclusion','target'=>'source']]);
$feared=instance_run($plain,[behavior_apply($fear)])->serverState();resolver_check($eval($feared)['legal_targets']===['3'],'Fear did not exclude source');
$locked=instance_run($plain,[behavior_apply(behavior_spec([['kind'=>'target_lock','target'=>'source']]))])->serverState();resolver_check($eval($locked)['legal_targets']===['2'],'Target lock ignored');
$compulsion=behavior_spec([['kind'=>'action_restriction','mode'=>'allow','actions'=>['ordinary'],'ability_keys'=>['required-action']]]);
$compelled=instance_run($plain,[behavior_apply($compulsion)])->serverState();resolver_check($eval($compelled)['code']==='ACS_BEHAVIOR_ACTION_BLOCKED','Compulsion action allow list ignored');
$denied=$compulsion;$denied['rules'][0]['mode']='deny';$denied['rules'][0]['ability_keys']=['strike'];resolver_check($eval(instance_run($plain,[behavior_apply($denied)])->serverState())['status']==='BLOCKED','Deny rule ignored');
$high=$taunt;$high['priority']=11;$conflict=instance_run($plain,[behavior_apply($taunt),behavior_apply($high,'3')])->serverState();resolver_check($eval($conflict)['legal_targets']===['3'],'Highest priority did not win');
$tied=instance_run($plain,[behavior_apply($taunt),behavior_apply($taunt,'3')])->serverState();resolver_check($eval($tied)['legal_targets']===['2'],'Stable oldest application tie-break failed');
$reversed=$tied;$reversed['status_instances']=array_reverse($reversed['status_instances'],true);resolver_check($eval($tied)===$eval($reversed),'Storage iteration changed outcome');
$combine=$fear;$combine['compatibility']['mode']='combine';$none=instance_run($plain,[behavior_apply($combine),behavior_apply($combine,'3')])->serverState();resolver_check($eval($none)['code']==='ACS_BEHAVIOR_NO_LEGAL_TARGET','Empty intersection not structured');
$mixed=instance_run($plain,[behavior_apply($taunt),behavior_apply($combine,'3')])->serverState();resolver_check($eval($mixed)['status']==='UNSUPPORTED','Mixed compatibility silently guessed');
$another=$combine;$another['compatibility']['group']='avoidance';$cross=instance_run($plain,[behavior_apply($taunt),behavior_apply($another)])->serverState();resolver_check($eval($cross)['status']==='BLOCKED','Independent groups failed intersection');
$wrong=$world;$wrong['encounter_id']='99';resolver_check(AF_ACS_Behavior::evaluate($s['status_instances'],'1','strike','ordinary',$candidates,$policy,$wrong)['legal_targets']===['2','3'],'Foreign encounter constraint applied');
foreach([['on_no_legal_target'=>'random'],['priority'=>'10'],['rules'=>[['kind'=>'forced_flee','target'=>'source']]],['scope'=>['prepared']],['controller_uid'=>'7']] as $bad)resolver_block(fn()=>AF_ACS_Behavior::spec(array_replace($taunt,$bad)),'ACS_BEHAVIOR_UNSUPPORTED');
// Existing Clock alone owns duration and duplicate boundaries.
$ended=AF_ACS_Clock::advance($s,tick_boundary('entity_end','730','1'))->serverState();
resolver_check($ended['status_instances']['new:1']['status']==='removed'&&$eval($ended)['legal_targets']===['2','3'],'Expired constraint still active');
resolver_check(AF_ACS_Clock::advance($ended,tick_boundary('entity_end','730','1'))->serverState()===$ended,'Duplicate boundary changed constraint');
resolver_check($ended['entities']===$s['entities']&&$ended['groups']===$s['groups'],'Constraint expiry restored resources/AP');
// Manual fixed target and sealed execution go through the same Resolver gate.
$b=$context;$b['state']=$s;$b['state']['entities'][1]['hp']=20;$b['stats'][3]=$b['stats'][2];$b['defenses'][3]=$b['defenses'][2];$b['allowed_targets'][1]=['2','3'];$b['behavior_targets'][1]=$candidates;$b['status_scope']=$world;
$a=[['actor_id'=>'1','ability_key'=>'strike','targets'=>['2'],'scenario'=>'hit']];
resolver_block(fn()=>AF_ACS_Resolver::resolve($b,$a),'ACS_BEHAVIOR_TARGET_BLOCKED');
$a[0]['targets']=['3'];AF_ACS_Resolver::resolve($b,$a);
$a[0]['targets']=['2'];unset($a[0]['scenario']);$tape=(new AF_ACS_RandomSource(static fn()=>0))->action();resolver_block(fn()=>AF_ACS_Resolver::resolve($b,$a,[$tape],'seal'),'ACS_BEHAVIOR_TARGET_BLOCKED');
$b['state']=$ended;$b['state']['entities'][1]['hp']=20;AF_ACS_Resolver::resolve($b,$a,[$tape],'seal');
// Behavior on a reactor blocks execution without paying its costs or consuming a slot.
$react=$ctx;$react['state']=instance_run($react['state'],[behavior_apply($compulsion,'1','2')])->serverState();$react['state']['entities'][2]['hp']=20;$react['state']['entities'][2]['resources']['ep']['current']=5;$react['reactions']=[reaction_entry('733',reaction_fixture('on_targeted',0,1))];$react['reaction_turns'][2]['used']=0;
$r=AF_ACS_Resolver::resolve($react,[['actor_id'=>'1','ability_key'=>'strike','targets'=>['2'],'scenario'=>'hit']]);$trace=$r->serverPlan()['actions'][0]['reactions'];
resolver_check(!$trace[0]['executed']&&$trace[0]['reason']==='ACS_BEHAVIOR_ACTION_BLOCKED'&&$r->serverPlan()['reaction_limits'][2]['used']===0&&$r->serverState()['entities'][2]['resources']['ep']['current']===5,'Reaction bypassed constraint or spent budget');
echo "ACS behavior: typed rules, priority/compatibility/ties, no legal/hidden/inactive target, explicit fallback, Clock expiry/replay, manual/sealed Resolver and reaction gates passed\n";
