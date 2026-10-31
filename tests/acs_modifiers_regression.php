<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Modifiers.php';
function modifier_assert(bool $ok,string $label): void {if (!$ok) throw new RuntimeException($label);}
function modifier_row(string $op,int|float $value,int $priority=0): array {
    return ['schema'=>AF_ACS_Modifiers::SCHEMA,'stat_key'=>'accuracy','operation'=>$op,'value'=>$value,
        'unit'=>match($op){'add_relative_percent'=>'percent','multiply'=>'factor',default=>'stat_points'},'phase'=>'stat','priority'=>$priority,'condition_ref'=>null];
}
function modifier_source(string $id,array $rows): array {return ['snapshot_id'=>$id,'source_id'=>'effect:'.$id,'source_type'=>'effect','source_version'=>['fingerprint'=>'sha256:'.str_repeat('a',64)],'modifiers'=>$rows];}
$policy=['stat_keys'=>['accuracy'],'phases'=>['base','stat','damage'],'unit'=>'stat_points','percent_unit'=>'percent','factor_unit'=>'factor','rounding'=>'none','output_type'=>'number'];
function modifier_try(array $sources,?array $policyOverride=null): array {global $policy;return AF_ACS_Modifiers::attempt(100,'accuracy','stat',$sources,$policyOverride??$policy);}
function modifier_error(array $r,string $code): void {modifier_assert(!$r['ready'] && $r['evaluation']===null && $r['diagnostics'][0]['code']===$code,'Expected '.$code.' got '.json_encode($r));}
$sources=[modifier_source('10',[modifier_row('multiply',2,-99),modifier_row('add_flat',10,999),modifier_row('add_relative_percent',20)]),
    modifier_source('2',[modifier_row('add_relative_percent',10),modifier_row('add_flat',10)])];
$r=modifier_try($sources);modifier_assert($r['ready'],'Valid pipeline blocked');
modifier_assert($r['evaluation']->serverResult()->raw===312.0,'(100+20)*1.3*2 failed');
modifier_assert($r['evaluation']->serverTrace()===modifier_try(array_reverse($sources))['evaluation']->serverTrace(),'Source input order changed trace/result');
$trace=$r['evaluation']->serverTrace()['ordered_sources'];
modifier_assert(array_column($trace,'operation')===['add_flat','add_flat','add_relative_percent','add_relative_percent','multiply'],'Priority moved operation type');
modifier_assert($trace[2]['source_snapshot_id']==='2' && $trace[3]['source_snapshot_id']==='10','Numeric source ID tie-break incorrect');
$sets=[modifier_source('1',[modifier_row('set',20,1),modifier_row('add_flat',1000,99),modifier_row('set',7,2)]),modifier_source('2',[modifier_row('set',7.0,2)])];
modifier_assert(modifier_try($sets)['evaluation']->serverResult()->raw===7,'Highest-priority compatible set failed');
$sets[1]['modifiers'][0]['value']=8;modifier_error(modifier_try($sets),'modifier_set_conflict');
$sets[1]['modifiers'][0]['priority']=0;modifier_assert(modifier_try($sets)['ready'],'Losing set conflict incorrectly blocked');
$clamps=[modifier_source('1',[modifier_row('clamp_min',5),modifier_row('clamp_max',4)])];modifier_error(modifier_try($clamps),'modifier_bounds_conflict');
$clamps=[modifier_source('1',[modifier_row('set',2.6),modifier_row('clamp_max',2.4)])];
$p=$policy;$p['rounding']='half_up';
$r=modifier_try($clamps,$p)['evaluation']->serverResult();modifier_assert($r->raw===2.6 && $r->rounded===3.0 && $r->clamped===2.4,'Clamp before rounding');
$p['output_type']='integer';modifier_error(modifier_try($clamps,$p),'ACS_NUMERIC_BOUNDS_INVALID');
$bad=modifier_source('1',[modifier_row('add_flat',1)]);$bad['modifiers'][0]['unit']='HP';modifier_error(modifier_try([$bad]),'modifier_unit_mismatch');
$bad['modifiers'][0]=modifier_row('add_flat',1);$bad['modifiers'][0]['condition_ref']='condition_template:fixture';modifier_error(modifier_try([$bad]),'modifier_condition_unresolved');
$bad['modifiers'][0]=modifier_row('add_flat',INF);modifier_error(modifier_try([$bad]),'ACS_NUMERIC_INFINITY');
$bad['modifiers'][0]=modifier_row('add_flat',1);$bad['modifiers'][0]['phase']='unknown';modifier_error(modifier_try([$bad]),'invalid_math_modifier');
$bad['modifiers'][0]['phase']='damage';modifier_assert(modifier_try([$bad])['evaluation']->serverResult()->raw===100.0,'Another stage executed implicitly');
modifier_error(modifier_try([$sources[0],$sources[0]]),'modifier_source_invalid');
$before=$sources;modifier_try($sources);modifier_assert($sources===$before,'Pure pipeline mutated sources');
modifier_assert(!str_contains(json_encode(modifier_try($sources)),'ordered_sources') && !str_contains(json_encode(modifier_try($sources)),'v_flat'),'Private trace leaked');
// Actual owner DTO/provenance, not a hand-written equipment inclusion flag.
require __DIR__.'/combat_snapshot_readonly.php';
$eq=modifier_source('91',[modifier_row('add_flat',5)]);$eq['source_type']='equipment';$eq['source_id']='inventory:9';
$eq['source_version']=$dto['equipment'][0]['kb_ref']['version'];$eq['source_path']='base_stats';
$before=$db->tables;
$r=AF_ACS_Modifiers::attempt(25.0,'accuracy','stat',[$eq],$policy,$dto);
modifier_assert($r['ready'] && $r['evaluation']->serverResult()->raw===25.0,'Equipment +5 applied twice');
modifier_assert($r['evaluation']->serverTrace()['ordered_sources'][0]['included_in_total']===true,'Exclusion trace missing');
$badDto=$dto;unset($badDto['source_breakdown']);modifier_error(AF_ACS_Modifiers::attempt(25.0,'accuracy','stat',[$eq],$policy,$badDto),'equipment_provenance_incomplete');
modifier_error(AF_ACS_Modifiers::attempt(20.0,'accuracy','stat',[$eq],$policy,$dto),'equipment_provenance_incomplete');
$eq['source_type']='effect';modifier_error(AF_ACS_Modifiers::attempt(25.0,'accuracy','stat',[$eq],$policy,$dto),'invalid_math_modifier');
modifier_assert($db->tables===$before,'Modifier stage wrote source state');
$errors=af_kb_arpg_math_modifier_errors(modifier_row('add_flat',5));modifier_assert(!$errors,'Pipeline fixture differs from KB math schema');
echo "ACS Modifiers: M1 sums/order/set conflicts, terminal clamp, source ties, explicit phases and real CS equipment exclusion/read-only passed\n";
