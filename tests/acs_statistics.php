<?php
/** ACS-10.4.2 requested focused reducer checks; no database or Resolver execution. */
define('IN_MYBB',1);
require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Statistics.php';
function statsCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$identities=['1'=>['owner_uid'=>'7','kind'=>'character','control_group_id'=>'10','group_owner_uid'=>'7'],'2'=>['owner_uid'=>'7','kind'=>'pet','control_group_id'=>'10','group_owner_uid'=>'7'],'3'=>['owner_uid'=>'8','kind'=>'character','control_group_id'=>'20','group_owner_uid'=>'8']];
$action=static fn($actor,$events)=>['actor_id'=>$actor,'targets'=>['3'],'resolved_target'=>'3','rolls'=>['hit_success'=>true,'crit_success'=>true],'effects_trace'=>[['events'=>$events]]];
$body=['persistent'=>true,'plan'=>['mode'=>'seal','conditional'=>false,'actions'=>[
    $action('1',[
        ['type'=>'damage','entity_id'=>'3','allocated'=>999,'hp_loss'=>7],
        ['type'=>'barrier_absorb','entity_id'=>'3','amount'=>4],
        ['type'=>'heal','entity_id'=>'1','calculated'=>100,'effective_recovery'=>2],
        ['type'=>'cost_paid','entity_id'=>'1','kind'=>'ap','amount'=>1],
    ])+['status_evaluations'=>[['source'=>'1','instance_events'=>[['type'=>'status_created','id'=>'new:1']]]],'reactions'=>[['executed'=>true,'entity_id'=>'3','redirect_success'=>true,'effects'=>['events'=>[]]]]],
    $action('2',[['type'=>'damage','entity_id'=>'3','allocated'=>500,'hp_loss'=>3]])+['item_action'=>['operation'=>'consume','qty'=>1]],
]]];
$stats=new AF_ACS_Statistics;$stats->combat($body);$subjects=$stats->subjects($identities);$indexed=[];
foreach($subjects as $s)$indexed[$s['subject_kind'].':'.$s['subject_key']]=$s['metrics'];
statsCheck($indexed['entity:1']['actual_hp_damage']===7&&$indexed['entity:1']['effective_healing']===2,'actual damage/effective recovery');
statsCheck($indexed['control_group:10']['actual_hp_damage']===10&&$indexed['pet:2']['actual_hp_damage']===3,'pet/group double counting');
statsCheck($indexed['entity:3']['received_damage']===10&&$indexed['entity:3']['barrier_absorption']===4,'received HP/barrier accounting');
statsCheck($indexed['entity:1']['hits']===1&&$indexed['entity:1']['critical_hits']===1&&$indexed['entity:1']['ap_spent']===1&&$indexed['entity:1']['applied_statuses']===1,'hit/crit/AP/status');
statsCheck($indexed['entity:3']['reactions']===1&&$indexed['entity:3']['intercepts']===1&&$indexed['entity:2']['items_consumed']===1,'reactions/intercepts/items');
$multi=$body;$multi['plan']['actions']=[$body['plan']['actions'][0]+['strikes'=>[$body['plan']['actions'][0],$body['plan']['actions'][1]]]];
$m=new AF_ACS_Statistics;$m->combat($multi);statsCheck($m->subjects($identities)===$subjects,'multi-target container double counted first strike');
$again=new AF_ACS_Statistics;$again->combat($body);statsCheck($again->subjects($identities)===$subjects,'reduction replay differs');
$preview=$body;$preview['persistent']=false;
try{(new AF_ACS_Statistics)->combat($preview);throw new RuntimeException('preview admitted');}catch(RuntimeException $e){statsCheck($e->getMessage()==='ACS_STATISTICS_UNCONFIRMED','preview guard');}
$seal=$body;$seal['plan']['mode']='preview';try{(new AF_ACS_Statistics)->combat($seal);throw new RuntimeException('conditional plan admitted');}catch(RuntimeException $e){statsCheck($e->getMessage()==='ACS_STATISTICS_UNCONFIRMED','seal guard');}
$clock=['boundary_key'=>'entity:1:start','events'=>[['effect_id'=>'9','ticks'=>[['effects'=>['events'=>[['type'=>'damage','entity_id'=>'3','hp_loss'=>2]]]]]]]];
$clock['events'][]=['type'=>'cost_paid','entity_id'=>'1','kind'=>'ap','amount'=>2];$clock['events'][]=['type'=>'object.passive','entity_id'=>'55','effects'=>['events'=>[['type'=>'heal','entity_id'=>'1','effective_recovery'=>3]]]];$clock['events'][]=['type'=>'object.boundary','entity_id'=>'55','before'=>['source_entity_id'=>'1']];
$c=new AF_ACS_Statistics;$c->clock($clock,['9'=>['source_entity_id'=>'1']]);$c->clock($clock,['9'=>['source_entity_id'=>'1']]);$rows=$c->subjects($identities);statsCheck($rows[0]['metrics']['actual_hp_damage']===2&&$rows[0]['metrics']['ap_spent']===2&&$rows[0]['metrics']['effective_healing']===3,'Clock boundary duplication');
echo "ACS statistics reducer: actual/raw, healing, barrier, group/pet, strikes, replay, preview/seal, metrics and Clock dedupe passed\n";
