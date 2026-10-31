<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Damage.php';
// Existing real KB/CS/adapter fixture. All owner persistence methods are write-forbidden.
require __DIR__.'/acs_integration_adapters.php';
$db->tables=$before;
$catalog=(new AF_ACS_KnowledgeBaseAdapter)->primaryElements(7);
function damage_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function damage_component(int $i,float|int $raw,string $type='true',?string $element=null): array {return ['source_index'=>$i,'damage_type'=>$type,'damage_element'=>$element,'raw'=>$raw,'after_crit'=>$raw];}
function damage_result(array $rows,array $target=[],array $penetration=[]): array {global $catalog;return AF_ACS_Damage::calculate($rows,$target,$penetration,$catalog)->serverData();}
function damage_error(array $r,string $code): void {damage_check(!$r['ready']&&$r['evaluation']===null&&$r['diagnostics'][0]['code']===$code,'Expected '.$code.' got '.json_encode($r));}
damage_check($catalog['ready'],'Canonical catalog unavailable');
$r=damage_result([damage_component(0,100,'physical')],['def'=>100]);damage_check($r['components'][0]['post_defense']===50.0 && $r['integer_budget']===50,'Physical DEF failed');
$target=['resistances'=>['fire'=>['scope'=>'elemental','unit'=>'RES','value'=>100]],'vulnerabilities'=>['fire'=>[20,30]],'immunities'=>['fire'=>false]];
$r=damage_result([damage_component(0,100,'elemental','fire')],$target);damage_check($r['components'][0]['post_defense']===75.0,'RES then additive vulnerability failed');
$r=damage_result([damage_component(0,100,'physical')],['def'=>100],['physical'=>['percent'=>[20,30],'flat'=>[10,10]]]);
damage_check($r['components'][0]['effective_defense']===30.0 && $r['integer_budget']===77,'Percent before flat failed');
$r=damage_result([damage_component(0,10,'physical')],['def'=>100],['physical'=>['percent'=>[80,80],'flat'=>[]]]);damage_check($r['components'][0]['effective_defense']===0.0,'Penetration cap failed');
$target['immunities']['fire']=true;$r=damage_result([damage_component(0,100,'elemental','fire')],$target);damage_check($r['integer_budget']===0 && $r['components'][0]['allocated']===0,'Vulnerability cancelled immunity');
$r=damage_result([damage_component(0,0,'physical')],['def'=>0]);damage_check($r['integer_budget']===0,'Zero became minimum one');
$r=damage_result([damage_component(0,1,'physical')],['def'=>1000]);damage_check($r['integer_budget']===0 && $r['components'][0]['post_defense']>0,'Sub-unit damage forced to one');
$r=damage_result([damage_component(0,100,'physical')],['def'=>100],['physical'=>['percent'=>[-30]]]);damage_check($r['components'][0]['effective_defense']===100.0,'Negative penetration not clamped');
$r=damage_result([damage_component(0,0.4),damage_component(1,0.4)]);damage_check(array_column($r['components'],'allocated')===[1,0] && $r['integer_budget']===1,'0.4+0.4 rounded separately');
$r=damage_result([damage_component(0,2.7),damage_component(1,1.8),damage_component(2,0.6)]);damage_check(array_column($r['components'],'allocated')===[3,2,0] && $r['allocation_sum']===$r['integer_budget'],'Largest remainder failed');
$rows=[damage_component(10,0.4),damage_component(2,0.4)];
$a=AF_ACS_Damage::calculate($rows,[],[],$catalog);$b=AF_ACS_Damage::calculate(array_reverse($rows),[],[],$catalog);
damage_check($a->serverData()===$b->serverData() && $a->serverTrace()===$b->serverTrace(),'Input order changed KB tie');
damage_check($a->serverData()['components'][0]['source_index']===2 && $a->serverData()['components'][0]['allocated']===1,'Tie does not use KB source index');
damage_check(!str_contains(json_encode($a),'post_defense'),'Hidden target data serialized');
foreach(array_keys($catalog['data']) as $key) {
    $t=['resistances'=>[$key=>['scope'=>'elemental','unit'=>'RES','value'=>100]]];
    damage_check(damage_result([damage_component(0,100,'elemental',$key)],$t)['integer_budget']===50,'Primary RES unsupported: '.$key);
}
damage_check($catalog['data']['air']['source_key']==='wind','KB legacy normalization not preserved');
foreach(['wind','blood','metal','sound'] as $key)damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'elemental',$key)],[],[],$catalog),'unknown_element');
damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'elemental','fire')],[],[],$catalog),'required_resistance_missing');
damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'elemental','fire')],['immunities'=>['fire'=>true]],[],$catalog),'required_resistance_missing');
damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'elemental')],[],[],$catalog),'damage_element_missing');
$t=['resistances'=>['fire'=>['scope'=>'elemental','unit'=>'RES','value'=>-1]]];damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'elemental','fire')],$t,[],$catalog),'damage_negative_value');
damage_error(AF_ACS_Damage::attempt([damage_component(0,10),damage_component(0,10)],[],[],$catalog),'damage_component_invalid');
damage_error(AF_ACS_Damage::attempt([damage_component(0,10,'physical')],['def'=>10],['physical'=>['flat'=>[-1]]],$catalog),'damage_negative_value');
$t=['def'=>100,'resistances'=>['fire'=>['scope'=>'elemental','unit'=>'RES','value'=>100]],'immunities'=>['fire'=>true]];
$r=damage_result([damage_component(0,10)],$t);damage_check($r['integer_budget']===10 && $r['components'][0]['effective_defense']===null && $r['components'][0]['bypasses_ordinary_barriers'],'True damage did not bypass defense');
$cs=AF_ACS_HitCrit::strike(1,1,100,50,[['kind'=>'direct_attack','raw'=>100]],true)->serverData()['components'][0];
$c=damage_component(0,$cs['raw_component'],'physical');$c['after_crit']=$cs['raw_after_crit'];$r=damage_result([$c],['def'=>100]);damage_check($r['total_raw']===100.0 && $r['components'][0]['post_defense']===75.0,'Crit applied after defense or twice');
$state=$db->tables;$reads=count($db->queries);damage_result([damage_component(0,1)]);damage_check($db->tables===$state && count($db->queries)===$reads,'Damage performed I/O');
echo "ACS Damage: DEF/17 RES, KB wind provenance, percent/flat penetration, additive vulnerability/immunity, true/zero damage, one mixed budget and deterministic largest remainder passed\n";
