<?php
require __DIR__.'/acs_resolver_regression.php';
$context['stats'][1]['def']=0;$context['stats'][2]['def']=100;
$spec=['schema_version'=>1,'operation'=>'swap','item_id'=>91,'qty'=>1,'item_fingerprint'=>'sha256:'.str_repeat('b',64),'pin'=>$ref,'subtype'=>'weapon','slot'=>'weapon_mainhand','snapshot_id'=>'1','bonuses'=>['accuracy'=>10],'effects'=>[],'reservation'=>null];
$item=['actor_id'=>'1','ability_key'=>'item:swap:91','targets'=>['1'],'scenario'=>'miss'];
$c=$context;$c['item_state']=['items'=>[91=>['fingerprint'=>$spec['item_fingerprint'],'available'=>1]],'equipment'=>[1=>['weapon_mainhand'=>['item_id'=>90,'bonuses'=>['accuracy'=>5]]]]];
$c['item_specs']=[0=>$spec];$c['abilities'][1]['item:swap:91']=['supported'=>true,'source_ref'=>$ref,'costs'=>[['kind'=>'ap','amount'=>1]],'item_action'=>true];
$r=AF_ACS_Resolver::resolve($c,[$item,$actions[0]]);
resolver_check($r->serverState()['groups'][1]['ap']['current']===1,'Swap did not cost exactly 1 AP');
resolver_check($r->serverPlan()['actions'][1]['probabilities']['P_hit']>75,'Sequential swap bonus missing');
resolver_check($r->serverPlan()['item_state']['equipment'][1]['weapon_mainhand']['item_id']===91,'Virtual loadout missing');
$bad=$c;$bad['item_specs'][0]['subtype']='armor';resolver_block(fn()=>AF_ACS_Resolver::resolve($bad,[$item]),'ACS_ITEM_EQUIP_DENIED');
$bad=$c;$bad['item_state']['items'][91]['available']=0;resolver_block(fn()=>AF_ACS_Resolver::resolve($bad,[$item]),'ACS_ITEM_INSUFFICIENT_QTY');
$bad=$c;$bad['item_specs'][1]=$spec;resolver_block(fn()=>AF_ACS_Resolver::resolve($bad,[$item,$item]),'ACS_ITEM_INSUFFICIENT_QTY');
$c['item_specs'][0]=array_replace($spec,['operation'=>'consume','subtype'=>'consumable','bonuses'=>[],'effects'=>[['op'=>'add_hp','value'=>5]]]);
$r=AF_ACS_Resolver::resolve($c,[$item]);resolver_check($r->serverState()['entities'][1]['hp']===15&&$r->serverState()['groups'][1]['ap']['current']===2,'Potion miss/noneffect refunded cost or skipped on_use');
$c['state']['entities'][1]['hp']=20;$r=AF_ACS_Resolver::resolve($c,[$item]);resolver_check($r->serverState()['groups'][1]['ap']['current']===2&&$r->serverState()['entities'][1]['hp']===20,'Zero effect refunded cost');
resolver_block(fn()=>AF_ACS_Items::bonuses(['base_stats'=>[['stat_key'=>'accuracy','mode'=>'percent','value'=>5]]]),'ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED');
echo "ACS combat items Domain: swap 1 AP, sequential delta, armor denial, shortage, repeated instance, consume cost on miss/zero effect, provenance passed\n";
