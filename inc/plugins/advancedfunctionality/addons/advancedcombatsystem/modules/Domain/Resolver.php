<?php
declare(strict_types=1);
require_once __DIR__.'/Effects.php';

/** One deterministic preview path. Context/plans are trusted prepared/pinned server data, never POST payloads. */
final class AF_ACS_Resolver
{
    public const VERSION='acs-resolver-2';
    private static function statusEvent(array $context,string $actor,string $target,array $a,array $s):array
    {
        if(array_key_exists('status_resist',$s)&&array_key_exists('status_resistance',$s)&&$s['status_resist']!==$s['status_resistance'])self::fail('status_resistance_alias_conflict');
        return ['source'=>$actor,'target'=>$target,'relationship'=>$context['relationships'][$actor][$target]??null,
            'immunities'=>$context['status_immunities'][$target]??[],'status_hit'=>$a['status_hit']??null,
            'status_resistance'=>(array_key_exists('status_resistance',$s)?$s['status_resistance']:($s['status_resist']??null)),'cast_success'=>true,'hit_success'=>false,'hp_loss'=>0];
    }
    /** Reserve separate checks even when the eventual hit/immunity gate makes them unused. */
    public static function statusRollIndexes(array $context,string $actor,string $target,array $ability):array
    {
        $indexes=[];$seen=[];$last=-1;$trace=[];
        $event=self::statusEvent($context,$actor,$target,self::stats($context,$actor,$trace),self::stats($context,$target,$trace));
        if(!is_array($ability['statuses']??[])||!array_is_list($ability['statuses']??[]))self::fail('status_components_invalid');
        foreach($ability['statuses']??[] as $definition){
            if(!is_array($definition))self::fail('status_components_invalid');
            $index=$definition['effect_definition_ref']['effect_index']??null;
            if(!is_int($index)||$index<0||$index>2147483647||$index<=$last||isset($seen[$index]))self::fail('status_component_order_invalid');
            $seen[$index]=true;$last=$index;
            $ref=$definition['effect_definition_ref'];
            if(array_intersect_key($ref,array_flip(['key','version','fingerprint']))!==($ability['source_ref']??null))self::fail('status_effect_source_mismatch');
            foreach($ability['components']??[] as $component)if(($component['kb_effect_index']??$component['source_index']??null)===$index)self::fail('status_effect_index_conflict');
            $evaluation=AF_ACS_Effects::status(array_diff_key($definition,['instance'=>true]),$event)->serverData();
            if(array_key_exists('instance',$definition)){
                if(!is_array($definition['instance']))self::fail('status_instance_schema_unsupported');AF_ACS_Effects::statusSpec($definition['instance']);
                if($definition['instance']['polarity']!==$definition['polarity'])self::fail('status_instance_definition_mismatch');
            }
            if(!$evaluation['supported'])self::fail($evaluation['reasons'][0]);
            if($evaluation['trace']['probability_check'])$indexes[]=$index;
        }
        if(!is_array($ability['removals']??[])||!array_is_list($ability['removals']??[]))self::fail('status_components_invalid');
        foreach($ability['removals']??[] as $removal){
            if(!is_array($removal))self::fail('status_components_invalid');
            self::removal($removal,$ability);$index=$removal['effect_definition_ref']['effect_index'];
            if(isset($seen[$index]))self::fail('status_effect_index_conflict');$seen[$index]=true;
            if($removal['policy']['chance']<100)$indexes[]=$index;
        }
        sort($indexes,SORT_NUMERIC);return $indexes;
    }
    private static function removal(array $removal,array $ability):void
    {
        if(array_diff(array_keys($removal),['effect_definition_ref','trigger','policy'])||!in_array($removal['trigger']??null,['on_cast','on_hit','on_damage'],true))self::fail('status_removal_contract_invalid');
        AF_ACS_Effects::removalSpec($removal['policy']??[]);$ref=$removal['effect_definition_ref']??[];
        if(($ref['type']??null)!=='arpg_ability'||!is_int($ref['effect_index']??null)||$ref['effect_index']<0||$ref['effect_index']>2147483647
            ||array_intersect_key($ref,array_flip(['key','version','fingerprint']))!==$ability['source_ref'])self::fail('status_removal_reference_invalid');
        foreach($ability['components']??[] as $component)if(($component['kb_effect_index']??$component['source_index']??null)===$ref['effect_index'])self::fail('status_effect_index_conflict');
    }
    private static function statuses(array $ability,string $phase,array $event,array $samples,string $mode,int $actionIndex,array &$state,array $scope):array
    {
        $out=[];$operations=[];
        foreach($ability['statuses']??[] as $definition)$operations[]=['kind'=>'apply','definition'=>$definition,'index'=>$definition['effect_definition_ref']['effect_index']];
        foreach($ability['removals']??[] as $removal)$operations[]=['kind'=>'remove','definition'=>$removal,'index'=>$removal['effect_definition_ref']['effect_index']];
        usort($operations,static fn($a,$b)=>$a['index']<=>$b['index']);
        foreach($operations as $operation){
            $definition=$operation['definition'];if(($definition['trigger']??null)!==$phase)continue;$index=$operation['index'];
            $event['roll_ref']=isset($samples[$index])?['action_index'=>$actionIndex,'check_kind'=>'status','roll_index'=>$index]:null;
            if($operation['kind']==='apply'){
                $evaluation=AF_ACS_Effects::status(array_diff_key($definition,['instance'=>true]),$event,$samples[$index]??null,$mode)->serverData();
                if($evaluation['success']===true&&isset($definition['instance'])){
                    $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_apply','session_id'=>$scope['session_id']??null,'encounter_id'=>$scope['encounter_id']??null,
                        'source'=>$event['source'],'target'=>$event['target'],'spec'=>$definition['instance'],'effect_definition_ref'=>$definition['effect_definition_ref'],
                        'status_definition_ref'=>$definition['status_definition_ref'],'action_index'=>$actionIndex]]]);
                    $state=$r->serverState();$evaluation['instance_events']=$r->serverTrace()['events'];
                }
            }else{
                $gate=$event['cast_success']&&match($phase){'on_cast'=>true,'on_hit'=>$event['hit_success'],'on_damage'=>$event['hp_loss']>0};
                $evaluation=['effect_definition_ref'=>$definition['effect_definition_ref'],'source'=>$event['source'],'target'=>$event['target'],'trigger'=>$phase,
                    'supported'=>true,'policy'=>$definition['policy'],'roll_ref'=>$event['roll_ref'],'success'=>$gate?null:false,'instance_events'=>[]];
                if($gate&&($mode==='seal'||$definition['policy']['chance']==100)){
                    $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_remove','target'=>$event['target'],'session_id'=>$scope['session_id']??null,'encounter_id'=>$scope['encounter_id']??null,'policy'=>$definition['policy'],'roll'=>$samples[$index]??null]]]);
                    $state=$r->serverState();$evaluation['instance_events']=$r->serverTrace()['events'];$evaluation['success']=$evaluation['instance_events'][0]['success'];
                }
            }
            $out[]=$evaluation;
        }
        return $out;
    }
    private static function fail(string $code): never {throw new AF_ACS_FormulaError($code,'resolver');}
    private static function ref(mixed $ref):void
    {if(!is_array($ref)||array_diff(array_keys($ref),['key','version','fingerprint'])||!is_string($ref['key']??null)||$ref['key']===''||!is_string($ref['version']??null)||$ref['version']===''||!is_string($ref['fingerprint']??null)||!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$ref['fingerprint']))self::fail('resolver_source_invalid');}
    private static function rule(array $rule,array &$seen,bool $reflection):void
    {
        if(array_diff(array_keys($rule),array_merge(['rule_id','source_ref','formula','pin','parameters','unit','basis_input','requires_active'],$reflection?['basis','damage_type','damage_element','penetration','incoming_stage','can_crit']:[])))self::fail('resolver_derived_contract_unsupported');
        if(!is_string($rule['basis_input']??null)||!is_string($rule['unit']??null))self::fail('resolver_derived_contract_unsupported');
        $id=$rule['rule_id']??null;$ref=$rule['source_ref']??null;
        if(!is_string($id)||!preg_match('/\A[a-zA-Z0-9_.:-]{1,96}\z/D',$id)||isset($seen[$id])||!is_array($ref)
            ||!is_string($ref['key']??null)||!is_string($ref['version']??null)||!is_string($ref['fingerprint']??null)||!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$ref['fingerprint']))self::fail('resolver_derived_source_invalid');
        self::ref($ref);
        if(!is_bool($rule['requires_active']??null)||!is_array($rule['formula']??null)||!is_array($rule['pin']??null)||(array_key_exists('parameters',$rule)&&!is_array($rule['parameters'])))self::fail('resolver_derived_contract_unsupported');
        if($rule['unit']!==($reflection?'damage':'hp'))self::fail('resolver_derived_unit_unsupported');
        if($reflection&&(!in_array($rule['basis']??null,['reflect_absorbed','reflect_hp_loss','reflect_incoming'],true)||!is_array($rule['penetration']??null)||($rule['can_crit']??false)!==false))self::fail('reflection_contract_unsupported');
        if($reflection&&$rule['basis']==='reflect_incoming'&&($rule['incoming_stage']??null)!=='post_defense_integer_budget')self::fail('reflection_contract_unsupported');
        AF_ACS_Formula::compile($rule['formula'],$rule['pin'],$rule['unit']);$seen[$id]=true;
    }
    private static function formula(array $spec,array $dynamic,string $unit,bool $integer,array &$trace): int|float
    {
        $s=$spec['formula']??null;$pin=$spec['pin']??null;
        if(!is_array($s)||!is_array($pin))self::fail('resolver_formula_missing');
        if(!$integer && (($s['definition']['rounding']??null)!=='none'||($s['definition']['output_type']??null)!=='number'))self::fail('resolver_raw_rounding_unsupported');
        if($integer&&($s['definition']['output_type']??null)!=='integer')self::fail('resolver_resource_rounding_required');
        $r=AF_ACS_Formula::compile($s,$pin,$unit)->evaluate($dynamic,$spec['parameters']??[]);$trace[]=$r->serverTrace();return $r->serverResult()->clamped;
    }
    private static function stats(array $context,string $id,array &$trace): array
    {
        $stats=$context['stats'][$id]??null;if(!is_array($stats))self::fail('resolver_stats_missing');
        foreach($context['modifier_stages'][$id]??[] as $stat=>$stages)foreach($stages as $stage){
            $r=AF_ACS_Modifiers::stage($stats[$stat]??null,$stat,$stage['phase'],$stage['sources'],$stage['policy'],$context['source_snapshots'][$id]??null);$stats[$stat]=$r->serverResult()->clamped;$trace[]=$r->serverTrace();
        }return $stats;
    }
    private static function damage(array $components,string $target,array $context,?array $penetration=null): AF_ACS_DamageResult
    {
        if(!isset($context['defenses'][$target]))self::fail('resolver_defense_missing');
        return AF_ACS_Damage::calculate($components,$context['defenses'][$target],$penetration??($context['penetration']??[]),$context['element_catalog']);
    }
    private static function apply(array $state,array $effects,?string $actor,array $costs,array &$trace,?array $hpCostPolicy=null): array
    {
        $action=['costs'=>$costs,'effects'=>$effects];if($hpCostPolicy!==null)$action['hp_cost_policy']=$hpCostPolicy;if($actor!==null)$action['actor_id']=$actor;
        $r=AF_ACS_Effects::simulate($state,$action);$step=$r->serverTrace();$step['end_condition_check_required']=false;$step['end_condition_check_deferred']=true;$trace[]=$step;return $r->serverState();
    }
    public static function resolve(array $context,array $orderedActions,array $fixedRandomTape=[],string $mode='preview'): AF_ACS_ResolverResult
    {
        if(array_diff(array_keys($context),['schema_version','state','stats','defenses','element_catalog','turns','encounter_status','abilities','allowed_targets','modifier_stages','source_snapshots','penetration','reflections','relationships','status_immunities','status_scope','entity_end_boundaries']))self::fail('resolver_context_unsupported');
        if(!in_array($mode,['preview','seal'],true))self::fail('resolver_mode_unsupported');
        if($mode==='preview'&&$fixedRandomTape!==[])self::fail('preview_random_tape_forbidden');
        if($mode==='seal'&&(!array_is_list($fixedRandomTape)||count($fixedRandomTape)!==count($orderedActions)||!$fixedRandomTape))self::fail('resolver_tape_invalid');
        if(($context['schema_version']??null)!==1||($context['encounter_status']??null)!=='active'||!array_is_list($orderedActions)||!$orderedActions)self::fail('resolver_context_invalid');
        $state=AF_ACS_Effects::simulate($context['state'],['costs'=>[],'effects'=>[]])->serverState();$initial=$state;$plans=[];
        foreach($orderedActions as $i=>$action){
            if(!is_array($action)||array_diff(array_keys($action),['actor_id','ability_key','targets','scenario']))self::fail('resolver_action_invalid');
            $actor=(string)($action['actor_id']??'');$key=$action['ability_key']??null;$targets=$action['targets']??null;$scenario=$action['scenario']??null;
            if(!is_string($key)||!is_array($targets)||!array_is_list($targets)||count($targets)!==1||($mode==='preview'&&!in_array($scenario,['hit','crit','miss'],true))||($mode==='seal'&&array_key_exists('scenario',$action)))self::fail('resolver_action_unsupported');
            $turn=$context['turns'][$actor]??null;$ability=$context['abilities'][$actor][$key]??null;
            if(!is_array($ability)||!is_array($turn)||($turn['status']??null)!=='pending'||($turn['started']??null)!==true||($turn['group_id']??null)!==($state['entities'][$actor]['group_id']??null))self::fail('resolver_turn_or_source_invalid');
            if(!AF_ACS_Effects::ordinaryActionAllowed($state,$actor))self::fail('ACS_STATUS_CONTROL_BLOCKED');
            $target=(string)$targets[0];if(!isset($state['entities'][$target])||!in_array($target,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
            if(is_array($ability)&&array_diff(array_keys($ability),['supported','source_ref','components','costs','hit_policy','lifesteal','hp_cost_policy','statuses','removals']))self::fail('resolver_ability_unsupported');
            if(($ability['supported']??null)!==true||!is_array($ability['source_ref']??null))self::fail('resolver_capability_blocked');
            self::ref($ability['source_ref']);
            $preflight=[];foreach($context['reflections'][$target]??[] as $rule)self::rule($rule,$preflight,true);$preflight=[];foreach($ability['lifesteal']??[] as $rule)self::rule($rule,$preflight,false);
            $modifierTrace=[];$formulaTrace=[];$a=self::stats($context,$actor,$modifierTrace);$s=self::stats($context,$target,$modifierTrace);$parts=[];$rawParts=[];
            foreach($ability['components']??[] as $component){
                if(!array_key_exists('value',$component)&&($component['unit']??null)!=='damage')self::fail('resolver_damage_unit_unsupported');
                $raw=array_key_exists('value',$component)?AF_ACS_Numeric::number($component['value']):self::formula($component,$component['inputs']??[],$component['unit'],false,$formulaTrace);
                $rawParts[]=['kind'=>'direct_attack','raw'=>$raw]+(isset($component['kb_effect_index'])?['kb_effect_index'=>$component['kb_effect_index']]:[]);$parts[]=$component;
            }
            if(!$parts&&empty($ability['statuses'])&&empty($ability['removals']))self::fail('resolver_components_missing');
            $prob=$parts?AF_ACS_HitCrit::strike($a['accuracy']??null,$s['speed']??null,$a['crit_rate']??null,$a['crit_dmg']??null,$rawParts,null,$ability['hit_policy']??null)->serverData():AF_ACS_HitCrit::hit($a['accuracy']??null,$s['speed']??null,$ability['hit_policy']??null)+['crit_rate'=>0,'can_crit'=>false];
            $statusIndexes=self::statusRollIndexes($context,$actor,$target,$ability);$statusSamples=[];$rollTrace=null;
            if($mode==='seal'){
                $t=$fixedRandomTape[$i];
                if(!is_array($t)||array_keys($t)!==($statusIndexes?['rng_version','hit','crit','status']:['rng_version','hit','crit'])||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');
                if($statusIndexes){
                    if(!is_array($t['status'])||array_keys($t['status'])!==$statusIndexes)self::fail('resolver_status_tape_invalid');
                    foreach($t['status'] as $sample)AF_ACS_Effects::statusSample(is_array($sample)?$sample:null);
                    $statusSamples=$t['status'];
                }
                foreach(['hit','crit'] as $kind){$v=$t[$kind];if(!is_array($v)||array_keys($v)!==['sample_n','u']||!is_int($v['sample_n'])||$v['sample_n']<0||$v['sample_n']>9007199254740991||!is_float($v['u'])||!is_finite($v['u'])||$v['u']!==$v['sample_n']/9007199254740992.0)self::fail('resolver_tape_invalid');}
                $hit=AF_ACS_Numeric::operation('multiply',100,$t['hit']['u'])<$prob['P_hit'];$crit=$hit&&$prob['can_crit']&&AF_ACS_Numeric::operation('multiply',100,$t['crit']['u'])<$prob['crit_rate'];
                $scenario=$hit?($crit?'crit':'hit'):'miss';
                $rollTrace=['rng_version'=>$t['rng_version'],'hit'=>$t['hit'],'crit'=>$t['crit'],'hit_success'=>$hit,'crit_success'=>$crit,'crit_used'=>$hit&&$prob['can_crit']];
            }
            $outcome=$scenario==='crit';if($scenario==='miss')$outcome=false;
            if($outcome&&$prob['crit_rate']==0)self::fail('resolver_scenario_impossible');
            if($scenario==='hit'&&$prob['crit_rate']==100&&$prob['can_crit'])self::fail('resolver_scenario_impossible');
            if($scenario==='miss'&&$prob['P_evade']==0)self::fail('resolver_scenario_impossible');
            // For a miss no critical check is claimed, including rate100.
            $critical=$scenario==='miss'||!$parts?null:AF_ACS_HitCrit::strike($a['accuracy'],$s['speed'],$a['crit_rate'],$a['crit_dmg'],$rawParts,$outcome,$ability['hit_policy']??null)->serverData();
            $components=[];foreach($parts as $n=>$part)$components[]=['source_index'=>$part['source_index'],'damage_type'=>$part['damage_type'],'damage_element'=>$part['damage_element']??null,
                'raw'=>$rawParts[$n]['raw'],'after_crit'=>$scenario==='miss'?0:$critical['components'][$n]['raw_after_crit']];
            $allocation=$parts?self::damage($components,$target,$context):new AF_ACS_DamageResult(['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'integer_budget'=>0,'allocation_sum'=>0,'components'=>[]],[]);$effects=[];$details=[];
            if($scenario!=='miss'&&$parts)$effects[]=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];
            $hasStatuses=!empty($ability['statuses'])||!empty($ability['removals']);
            $state=self::apply($state,$hasStatuses?[]:$effects,$actor,$ability['costs'],$details,$ability['hp_cost_policy']??null);
            $event=self::statusEvent($context,$actor,$target,$a,$s);
            $statusResults=self::statuses($ability,'on_cast',$event,$statusSamples,$mode,$i,$state,$context['status_scope']??[]);
            $event['hit_success']=$scenario!=='miss';
            $statusResults=[...$statusResults,...self::statuses($ability,'on_hit',$event,$statusSamples,$mode,$i,$state,$context['status_scope']??[])];
            if($hasStatuses)$state=self::apply($state,$effects,null,[],$details);
            $loss=0;$absorbed=0;foreach($details[$hasStatuses?1:0]['events'] as $damageEvent)if($damageEvent['type']==='damage'){$loss=AF_ACS_Numeric::integer($loss+$damageEvent['hp_loss']);$absorbed=AF_ACS_Numeric::integer($absorbed+$damageEvent['absorbed']);}
            $event['hp_loss']=$loss;
            $statusResults=[...$statusResults,...self::statuses($ability,'on_damage',$event,$statusSamples,$mode,$i,$state,$context['status_scope']??[])];
            $seenReflection=[];$seenLifesteal=[];
            $causal='action:'.$i.':primary';$derived=[];
            if($scenario!=='miss')foreach($context['reflections'][$target]??[] as $rindex=>$rule){
                self::rule($rule,$seenReflection,true);
                if(!is_array($rule['penetration']??null)||(($rule['basis']??null)==='reflect_incoming'&&($rule['incoming_stage']??null)!=='post_defense_integer_budget'))self::fail('reflection_contract_unsupported');
                if(!in_array($rule['basis']??null,['reflect_absorbed','reflect_hp_loss','reflect_incoming'],true)||!is_bool($rule['requires_active']??null)||($rule['can_crit']??false)!==false)self::fail('reflection_contract_unsupported');
                if($rule['requires_active']&&$state['entities'][$target]['life_state']!=='active')continue;
                $basis=match($rule['basis']){'reflect_absorbed'=>$absorbed,'reflect_hp_loss'=>$loss,'reflect_incoming'=>$allocation->serverData()['integer_budget']};
                $value=self::formula($rule,[$rule['basis_input']=>$basis],$rule['unit'],false,$formulaTrace);
                $reflected=self::damage([['source_index'=>0,'damage_type'=>$rule['damage_type'],'damage_element'=>$rule['damage_element']??null,'raw'=>$value,'after_crit'=>$value]],$actor,$context,$rule['penetration']);
                $state=self::apply($state,[['kind'=>'damage','targets'=>[['entity_id'=>$actor,'allocation'=>$reflected]]]],null,[],$details);
                $derived[]=['id'=>'action:'.$i.':reflection:'.$rindex,'cause'=>$causal,'kind'=>'reflection','source_ref'=>$rule['source_ref'],'basis'=>$basis,'allocation'=>$reflected->serverData(),'crit_applied'=>false,'on_hit_emitted'=>false,'can_trigger_reflection'=>false,'can_trigger_lifesteal'=>false];
            }
            if($scenario!=='miss')foreach($ability['lifesteal']??[] as $lindex=>$rule){
                self::rule($rule,$seenLifesteal,false);
                if(!is_bool($rule['requires_active']??null))self::fail('lifesteal_contract_unsupported');
                if($state['entities'][$actor]['life_state']==='dead'||$rule['requires_active']&&$state['entities'][$actor]['life_state']!=='active')continue;
                if($loss===0){$derived[]=['id'=>'action:'.$i.':lifesteal:'.$lindex,'cause'=>$causal,'kind'=>'lifesteal','basis_hp_loss'=>0,'calculated'=>0,'skipped'=>'zero_hp_loss'];continue;}
                $amount=self::formula($rule,[$rule['basis_input']=>$loss],$rule['unit'],true,$formulaTrace);
                $state=self::apply($state,[['kind'=>'heal','entity_id'=>$actor,'amount'=>$amount]],null,[],$details);
                $derived[]=['id'=>'action:'.$i.':lifesteal:'.$lindex,'cause'=>$causal,'kind'=>'lifesteal','source_ref'=>$rule['source_ref'],'basis_hp_loss'=>$loss,'calculated'=>$amount];
            }
            $plans[]=['action_index'=>$i,'conditional'=>$mode==='preview','rolls'=>$rollTrace,'scenario'=>$scenario,'actor_id'=>$actor,'targets'=>[$target],'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],
                'probabilities'=>array_intersect_key($prob,array_flip(['P_hit','P_evade','crit_rate','can_crit']))+['P_crit'=>$prob['can_crit']?$prob['crit_rate']:0,'crit_scope'=>'conditional_on_hit'],'allocation'=>$allocation->serverData(),'causal_id'=>$causal,'derived'=>$derived,'formula_trace'=>$formulaTrace,'modifier_trace'=>$modifierTrace,'effects_trace'=>$details]+($hasStatuses?['status_evaluations'=>$statusResults]:[]);
        }
        $endTraces=[];
        if(!empty($context['entity_end_boundaries'])){
            require_once __DIR__.'/Clock.php';
            foreach($context['entity_end_boundaries'] as $boundary){
                $r=AF_ACS_Clock::advance($state,$boundary);$state=$r->serverState();$trace=$r->serverTrace();
                if($trace['events'])$endTraces[$trace['boundary_key']]=$trace;
            }
        }
        $plan=['engine_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'mode'=>$mode,'conditional'=>$mode==='preview','actions'=>$plans,
            'end_condition_check_required'=>true,'encounter_transition'=>null,'persistent'=>false]+($endTraces?['entity_end_boundaries'=>$endTraces]:[]);
        try{$copy=json_decode(json_encode($plan,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail('resolver_trace_invalid');}
        if($copy!==$plan)self::fail('ACS_NUMERIC_SERIALIZATION_PRECISION');
        return new AF_ACS_ResolverResult($state,$copy,$initial);
    }
}
final class AF_ACS_ResolverResult implements JsonSerializable
{
    public function __construct(private readonly array $state,private readonly array $plan,private readonly array $initial){}
    public function serverState():array{return $this->state;}
    public function serverPlan():array{return $this->plan;}
    public function serverInitial():array{return $this->initial;}
    public function jsonSerialize():array{return ['engine_version'=>AF_ACS_Resolver::VERSION,'mode'=>$this->plan['mode'],'conditional'=>$this->plan['conditional'],'persistent'=>false];}
}
