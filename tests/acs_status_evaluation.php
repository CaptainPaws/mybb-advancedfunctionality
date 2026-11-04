<?php
declare(strict_types=1);
// Reuse the narrow source/read-only, Damage, Effects and Resolver fixtures.
require __DIR__.'/acs_resolver_regression.php';
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/RandomSource.php';
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/SealPersistence.php';
function status_definition(int $index=1,string $trigger='on_hit'):array {
    return ['effect_type'=>'status','effect_definition_ref'=>['type'=>'arpg_ability','key'=>'strike','version'=>'1','fingerprint'=>effect_ref()['fingerprint'],'effect_index'=>$index],
        'status_definition_ref'=>effect_ref('poison'),'status_type'=>'debuff','polarity'=>'negative','trigger'=>$trigger,'base_chance'=>40];
}
function status_event():array{return ['source'=>'1','target'=>'2','relationship'=>'hostile','immunities'=>[],
    'cast_success'=>true,'hit_success'=>true,'hp_loss'=>0,'status_hit'=>0,'status_resistance'=>0,'roll_ref'=>['action_index'=>0,'check_kind'=>'status','roll_index'=>1]];}
$d=status_definition();$event=status_event();$sample=['sample_n'=>0,'u'=>0.0];
$evaluate=static fn(array $d,array $e,?array $roll=null,string $mode='preview')=>AF_ACS_Effects::status($d,$e,$roll,$mode)->serverData();
$v=$evaluate($d,$event);resolver_check($v['P_status']===40&&$v['success']===null,'0/0 is not B or preview rolled');
$higher=$event;$higher['status_hit']=30;$higher['status_resistance']=10;
resolver_check($evaluate($d,$higher)['P_status']===55.0,'H/R formula wrong');
resolver_check($evaluate(array_replace($d,['base_chance'=>0]),$higher)['P_status']===15.0,'Unapproved B=0 veto');
resolver_check($evaluate(array_replace($d,['base_chance'=>100]),$higher)['P_status']===100,'Upper clamp');
$lower=$event;$lower['status_resistance']=10;
resolver_check($evaluate(array_replace($d,['base_chance'=>0]),$lower)['P_status']===0,'Lower clamp');
$immune=$event;$immune['immunities']=[['key'=>'poison','scope'=>'status_key']];
$v=$evaluate($d,$immune,$sample,'seal');resolver_check($v['success']===false&&$v['immunity']['immune']&&!$v['roll_used'],'Immunity lost priority');
$immune['immunities']=[['scope'=>'status_type','key'=>'debuff']];resolver_check($evaluate($d,$immune,$sample,'seal')['success']===false,'Type immunity ignored');
$ally=$event;$ally['relationship']='ally';unset($ally['status_hit'],$ally['status_resistance']);
$positive=array_replace($d,['polarity'=>'positive']);$v=$evaluate($positive,$ally,null,'seal');
resolver_check($v['success']===true&&!$v['roll_used']&&$v['roll_ref']===null&&$v['P_status']===100,'Positive ally rolled');
$ally['status_hit']=0;$ally['status_resistance']=0;$explicit=$positive;$explicit['probability_check']=true;
resolver_check($evaluate($explicit,$ally,$sample,'seal')['roll_used'],'Explicit positive check ignored');
$bad=$d;$bad['probability_check']=false;resolver_check(!$evaluate($bad,$event)['supported'],'Negative bypass accepted');
resolver_check($evaluate($d,$event,$sample,'seal')['success']===true,'on_hit zero damage failed');
resolver_check($evaluate(status_definition(1,'on_damage'),$event,$sample,'seal')['success']===false,'Absorption triggered on_damage');
$missEvent=$event;$missEvent['hit_success']=false;
resolver_check($evaluate(status_definition(1,'on_cast'),$missEvent,$sample,'seal')['success']===true,'on_cast incorrectly requires hit');
$missEvent['cast_success']=false;resolver_check($evaluate(status_definition(1,'on_cast'),$missEvent,$sample,'seal')['success']===false,'Failed cast triggered');
foreach([['trigger'=>'mystery'],['effect_type'=>'mystery'],['status_definition_ref'=>null],['base_chance'=>null]] as $bad)
    resolver_check(!$evaluate(array_replace($d,$bad),$event)['supported'],'Unsupported definition executed');
$result=AF_ACS_Effects::status($d,$event,$sample,'seal');
resolver_check(!str_contains(json_encode($result),'poison')&&!str_contains(json_encode($result),'P_status'),'Private status leaked via DTO');

$statusContext=$context;$statusContext['stats'][1]['status_hit']=0;$statusContext['stats'][2]['status_resistance']=0;
$statusContext['relationships']=[1=>[2=>'hostile']];$statusContext['abilities'][1]['strike']['statuses']=[status_definition(1,'on_damage'),status_definition(2,'on_hit'),status_definition(3,'on_cast')];
$draws=0;$rng=new AF_ACS_RandomSource(static function()use(&$draws){$draws++;return 0;});
$indexes=AF_ACS_Resolver::statusRollIndexes($statusContext,'1','2',$statusContext['abilities'][1]['strike']);
resolver_check($indexes===[1,2,3],'Separate status keys/order lost');$tape=$rng->action($indexes);
resolver_check($draws===5,'Wrong RNG draw count');
$sealedActions=$actions;unset($sealedActions[0]['scenario']);
$resolve=static fn(array $c,array $t)=>AF_ACS_Resolver::resolve($c,$sealedActions,[$t],'seal');
$r=$resolve($statusContext,$tape);$plan=$r->serverPlan()['actions'][0];$statuses=$plan['status_evaluations'];
resolver_check(array_column($statuses,'trigger')===['on_cast','on_hit','on_damage'],'Trigger phases moved');
resolver_check(array_column(array_column($statuses,'effect_definition_ref'),'effect_index')===[3,2,1],'KB index lost');
resolver_check(array_column($statuses,'success')===[true,true,true],'Fixed status rolls failed');
resolver_check($resolve($statusContext,$tape)->serverPlan()===$r->serverPlan()&&$draws===5,'Replay rerolled or changed outcome');
$different=$tape;$different['status'][2]=['sample_n'=>9007199254740991,'u'=>9007199254740991/9007199254740992.0];
resolver_check($resolve($statusContext,$different)->serverPlan()['actions'][0]['status_evaluations'][1]['success']===false,'Status used hit roll');
$absorbed=$statusContext;$absorbed['state']['barriers']=[1=>effect_pool(100,1,0,['2'])];
$v=$resolve($absorbed,$tape)->serverPlan()['actions'][0]['status_evaluations'];
resolver_check($v[1]['success']===true&&$v[2]['success']===false,'Absorption gates wrong');
$zero=$statusContext;$zero['abilities'][1]['strike']['components'][0]['value']=0;
resolver_check($resolve($zero,$tape)->serverPlan()['actions'][0]['status_evaluations'][1]['success']===true,'Zero damage hit rejected');
$legacy=$statusContext;unset($legacy['stats'][2]['status_resistance']);$legacy['stats'][2]['status_resist']=0;
resolver_check($resolve($legacy,$tape)->serverPlan()===$r->serverPlan(),'Legacy alias differs');
$legacy['stats'][2]['status_resistance']=10;resolver_block(fn()=>$resolve($legacy,$tape),'status_resistance_alias_conflict');
$missing=$tape;unset($missing['status'][1]);resolver_block(fn()=>$resolve($statusContext,$missing),'resolver_status_tape_invalid');
$corrupt=$tape;$corrupt['status'][1]['u']=0.5;resolver_block(fn()=>$resolve($statusContext,$corrupt),'status_roll_invalid');
$samePhase=$statusContext;$samePhase['abilities'][1]['strike']['statuses']=[status_definition(1),status_definition(2)];
$twoTape=$rng->action([1,2]);$v=$resolve($samePhase,$twoTape)->serverPlan()['actions'][0]['status_evaluations'];
resolver_check(array_column(array_column($v,'effect_definition_ref'),'effect_index')===[1,2],'Within-phase order lost');
$pure=$statusContext;$pure['abilities'][1]['strike']['components']=[];
resolver_check($resolve($pure,$tape)->serverState()['entities'][2]['hp']===$pure['state']['entities'][2]['hp'],'Status-only action caused damage');
$before=$statusContext;$beforeDraws=$draws;AF_ACS_Resolver::resolve($statusContext,$actions);
resolver_check($before===$statusContext&&$draws===$beforeDraws,'Preview mutated state or used RNG');
$allyContext=$samePhase;$allyContext['relationships'][1][2]='ally';foreach($allyContext['abilities'][1]['strike']['statuses'] as &$s)$s['polarity']='positive';unset($s);
resolver_check(AF_ACS_Resolver::statusRollIndexes($allyContext,'1','2',$allyContext['abilities'][1]['strike'])===[],'Positive status reserved a roll');
$plainTape=$rng->action();$v=$resolve($allyContext,$plainTape)->serverPlan()['actions'][0]['status_evaluations'];
resolver_check(!array_filter($v,fn($v)=>$v['roll_used']),'Positive status consumed roll');

// Real owner lookup/normalization into the existing QueryService ability projection. Owner DB forbids writes.
require_once AF_ADDONS.'advancedcombatsystem/modules/Application/QueryService.php';
$statusDef=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'status-fixture','version'=>'1','type'=>'debuff',
    'scope'=>'combat','duration'=>['value'=>1,'unit'=>'own_turn'],'phase'=>'on_turn_start','decrement'=>['event'=>'on_turn_end','amount'=>1],
    'repeat_rule'=>'refresh','max_stacks'=>1,'removal_conditions'=>[],'expiry_conditions'=>[],'immunities'=>[],
    'resistances'=>[],'periodic_effects'=>[],'formula_refs'=>[],'events'=>[]];
$db->tables['af_kb_entries'][]=snapshot_entry(980,'arpg_mechanics','status-fixture',
    ['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'status_def','entries'=>[$statusDef]]);
$abilityRules=af_kb_default_type_profile_payload_arpg('arpg_ability');
$abilityRules=array_replace($abilityRules,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','type'=>'active','targeting'=>'single_enemy',
    'damage_type'=>'physical','cooldown_unit'=>'own_turn','duration_unit'=>'own_turn','max_charges'=>0,
    'effects'=>[['kind'=>'damage','damage_type'=>'physical','value'=>10],
        ['kind'=>'status','status_key'=>'status-fixture','base_chance'=>0,'polarity'=>'negative','trigger'=>'on_hit']]]);
$db->tables['af_kb_entries'][]=snapshot_entry(981,'arpg_ability','status-strike',$abilityRules);
$ownerBefore=$db->tables;
$dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','status-strike',7);
resolver_check($dto['ready'],'Owner status DTO not ready: '.json_encode($dto['diagnostics']));
$queryReflection=new ReflectionClass(AF_ACS_QueryService::class);$query=$queryReflection->newInstanceWithoutConstructor();
$project=$queryReflection->getMethod('ability');$prepared=$project->invoke($query,$dto);
resolver_check(count($prepared['statuses'])===1&&$prepared['statuses'][0]['base_chance']===0,'Query lost B=0/status component');
resolver_check($prepared['statuses'][0]['effect_definition_ref']['effect_index']===1&&$prepared['statuses'][0]['status_definition_ref']['version']==='1','Pinned effect identity lost');
$db->tables['af_kb_entries']=array_values(array_filter($db->tables['af_kb_entries'],fn($entry)=>$entry['key']!=='status-fixture'));
resolver_check(!(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','status-strike',7)['ready'],'Missing KB definition accepted');
$db->tables=$ownerBefore;
$missingDto=$dto;$missingDto['data']['ability_profile']['effects'][1]['references']['status_key']['resolved']=false;
resolver_block(fn()=>$project->invoke($query,$missingDto),'status_definition_missing');
$noB=$dto;unset($noB['data']['ability_profile']['effects'][1]['parameters']['base_chance']);
resolver_block(fn()=>$project->invoke($query,$noB),'status_application_contract_missing');
$override=$dto;$override['data']['ability_profile']['effects'][1]['parameters']['trigger']='on_mystery';
resolver_block(fn()=>$project->invoke($query,$override),'status_trigger_unsupported');
$pin=$dto;$pin['data']['ability_profile']['effects'][1]['references']['status_key']['definition']['type']='changed';
resolver_block(fn()=>$project->invoke($query,$pin),'status_definition_pin_invalid');
resolver_check($db->tables===$ownerBefore,'Owner adapter/projection mutated DB');
resolver_check(hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'evaluations'=>$statuses]))===
    hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'evaluations'=>$r->serverPlan()['actions'][0]['status_evaluations']])),'Confirmation evaluation envelope drifted');

// Exercise the production persistence trait with captured SQL rows, not a second tape implementation.
final class StatusTapeFixture {
    use AF_ACS_SealPersistence;
    public array $rows=[];public array $inserts=[];private int $nextId=10;
    public function __construct(){$this->sealWriting=true;}
    private function table(string $name):string{return 'fixture_'.$name;}
    public static function integer(string $v,string $max='18446744073709551615',bool $zero=false):string{
        if(!preg_match('/\A[0-9]+\z/D',$v)||(!$zero&&$v==='0')||strlen($v)>strlen($max)||(strlen($v)===strlen($max)&&strcmp($v,$max)>0))throw new RuntimeException('invalid_fixture_integer');return $v;
    }
    public static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private function insertSnapshot(string $sid,array $metadata,array $payload):string{return '9';}
    private function sql(string $sql,array $args):array{
        if(str_starts_with($sql,'SELECT check_kind')){usort($this->rows,fn($a,$b)=>strcmp($a['check_kind'],$b['check_kind'])?:$a['roll_index']<=>$b['roll_index']);return ['rows'=>$this->rows];}
        $this->inserts[]=[$sql,$args];
        if(str_contains($sql,'fixture_rolls'))$this->rows[]=['check_kind'=>$args[3],'roll_index'=>$args[4],'rng_version'=>$args[5],'value_json'=>$args[6]];
        return ['id'=>(string)$this->nextId++];
    }
}
$store=new StatusTapeFixture;
$store->appendSeal(['id'=>'1','session_id'=>'1'],['actor_id'=>'1','ability_key'=>'strike'],[],$ref,1,'fixture-operation',0,$tape);
$roundtrip=$store->sealTape('10');resolver_check($roundtrip===$tape,'Persistent tape/index/float roundtrip changed');
resolver_check($resolve($statusContext,$roundtrip)->serverPlan()===$r->serverPlan(),'Persisted tape changed result');
foreach(array_slice($store->inserts,1) as [$sql,$args])resolver_check($args[2]==='sealed-action:10','Wrong persistent causal key');
resolver_check(count($store->rows)===5,'Missing persisted status rows');
$legacyStore=new StatusTapeFixture;
$legacyStore->appendSeal(['id'=>'1','session_id'=>'1'],['actor_id'=>'1','ability_key'=>'strike'],[],$ref,1,'fixture-operation',0,$plainTape);
resolver_check($legacyStore->sealTape('10')===$plainTape,'Legacy two-roll tape invalidated');

echo "ACS status evaluation: 0/0, H/R/clamps/B=0, immunity, positive ally/override, trigger phases/order, zero damage/absorption, owner KB/Query pins, missing definition, fixed/corrupt/missing and SQL tape roundtrip, aliases and read-only DTO passed\n";
