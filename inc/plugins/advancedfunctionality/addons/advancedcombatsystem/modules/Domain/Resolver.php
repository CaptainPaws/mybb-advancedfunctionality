<?php
declare(strict_types=1);
require_once __DIR__.'/Effects.php';

/** One deterministic preview path. Context/plans are trusted prepared/pinned server data, never POST payloads. */
final class AF_ACS_Resolver
{
    public const VERSION='acs-resolver-preview-1';
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
        if(array_diff(array_keys($context),['schema_version','state','stats','defenses','element_catalog','turns','encounter_status','abilities','allowed_targets','modifier_stages','source_snapshots','penetration','reflections']))self::fail('resolver_context_unsupported');
        if($mode!=='preview')self::fail('resolver_mode_unsupported');if($fixedRandomTape!==[])self::fail('preview_random_tape_forbidden');
        if(($context['schema_version']??null)!==1||($context['encounter_status']??null)!=='active'||!array_is_list($orderedActions)||!$orderedActions)self::fail('resolver_context_invalid');
        $state=AF_ACS_Effects::simulate($context['state'],['costs'=>[],'effects'=>[]])->serverState();$initial=$state;$plans=[];
        foreach($orderedActions as $i=>$action){
            if(!is_array($action)||array_diff(array_keys($action),['actor_id','ability_key','targets','scenario']))self::fail('resolver_action_invalid');
            $actor=(string)($action['actor_id']??'');$key=$action['ability_key']??null;$targets=$action['targets']??null;$scenario=$action['scenario']??null;
            if(!is_string($key)||!is_array($targets)||!array_is_list($targets)||count($targets)!==1||!in_array($scenario,['hit','crit','miss'],true))self::fail('resolver_action_unsupported');
            $turn=$context['turns'][$actor]??null;$ability=$context['abilities'][$actor][$key]??null;
            if(!is_array($ability)||!is_array($turn)||($turn['status']??null)!=='pending'||($turn['started']??null)!==true||($turn['group_id']??null)!==($state['entities'][$actor]['group_id']??null))self::fail('resolver_turn_or_source_invalid');
            $target=(string)$targets[0];if(!isset($state['entities'][$target])||!in_array($target,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
            if(is_array($ability)&&array_diff(array_keys($ability),['supported','source_ref','components','costs','hit_policy','lifesteal','hp_cost_policy']))self::fail('resolver_ability_unsupported');
            if(($ability['supported']??null)!==true||!is_array($ability['source_ref']??null))self::fail('resolver_capability_blocked');
            self::ref($ability['source_ref']);
            $preflight=[];foreach($context['reflections'][$target]??[] as $rule)self::rule($rule,$preflight,true);$preflight=[];foreach($ability['lifesteal']??[] as $rule)self::rule($rule,$preflight,false);
            $modifierTrace=[];$formulaTrace=[];$a=self::stats($context,$actor,$modifierTrace);$s=self::stats($context,$target,$modifierTrace);$parts=[];$rawParts=[];
            foreach($ability['components']??[] as $component){
                if(!array_key_exists('value',$component)&&($component['unit']??null)!=='damage')self::fail('resolver_damage_unit_unsupported');
                $raw=array_key_exists('value',$component)?AF_ACS_Numeric::number($component['value']):self::formula($component,$component['inputs']??[],$component['unit'],false,$formulaTrace);
                $rawParts[]=['kind'=>'direct_attack','raw'=>$raw]+(isset($component['kb_effect_index'])?['kb_effect_index'=>$component['kb_effect_index']]:[]);$parts[]=$component;
            }
            if(!$parts)self::fail('resolver_components_missing');
            $prob=AF_ACS_HitCrit::strike($a['accuracy']??null,$s['speed']??null,$a['crit_rate']??null,$a['crit_dmg']??null,$rawParts,null,$ability['hit_policy']??null)->serverData();
            $outcome=$scenario==='crit';if($scenario==='miss')$outcome=false;
            if($outcome&&$prob['crit_rate']==0)self::fail('resolver_scenario_impossible');
            if($scenario==='hit'&&$prob['crit_rate']==100&&$prob['can_crit'])self::fail('resolver_scenario_impossible');
            if($scenario==='miss'&&$prob['P_evade']==0)self::fail('resolver_scenario_impossible');
            // For a miss no critical check is claimed, including rate100.
            $critical=$scenario==='miss'?null:AF_ACS_HitCrit::strike($a['accuracy'],$s['speed'],$a['crit_rate'],$a['crit_dmg'],$rawParts,$outcome,$ability['hit_policy']??null)->serverData();
            $components=[];foreach($parts as $n=>$part)$components[]=['source_index'=>$part['source_index'],'damage_type'=>$part['damage_type'],'damage_element'=>$part['damage_element']??null,
                'raw'=>$rawParts[$n]['raw'],'after_crit'=>$scenario==='miss'?0:$critical['components'][$n]['raw_after_crit']];
            $allocation=self::damage($components,$target,$context);$effects=[];$details=[];
            if($scenario!=='miss')$effects[]=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];
            $state=self::apply($state,$effects,$actor,$ability['costs'],$details,$ability['hp_cost_policy']??null);
            $loss=0;$absorbed=0;foreach($details[0]['events'] as $event)if($event['type']==='damage'){$loss=AF_ACS_Numeric::integer($loss+$event['hp_loss']);$absorbed=AF_ACS_Numeric::integer($absorbed+$event['absorbed']);}
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
            $plans[]=['action_index'=>$i,'conditional'=>true,'scenario'=>$scenario,'actor_id'=>$actor,'targets'=>[$target],'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],
                'probabilities'=>array_intersect_key($prob,array_flip(['P_hit','P_evade','crit_rate','can_crit']))+['P_crit'=>$prob['can_crit']?$prob['crit_rate']:0,'crit_scope'=>'conditional_on_hit'],'allocation'=>$allocation->serverData(),'causal_id'=>$causal,'derived'=>$derived,'formula_trace'=>$formulaTrace,'modifier_trace'=>$modifierTrace,'effects_trace'=>$details];
        }
        $plan=['engine_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'mode'=>'preview','conditional'=>true,'actions'=>$plans,
            'end_condition_check_required'=>true,'encounter_transition'=>null,'persistent'=>false];
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
    public function jsonSerialize():array{return ['engine_version'=>AF_ACS_Resolver::VERSION,'mode'=>'preview','conditional'=>true,'persistent'=>false];}
}
