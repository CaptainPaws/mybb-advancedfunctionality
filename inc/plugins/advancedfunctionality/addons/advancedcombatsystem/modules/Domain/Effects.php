<?php
declare(strict_types=1);
require_once __DIR__.'/Damage.php';
require_once __DIR__.'/CausalGraph.php';
require_once __DIR__.'/Spawn.php';
require_once __DIR__.'/Environment.php';
require_once __DIR__.'/Awareness.php';
require_once __DIR__.'/Control.php';
require_once __DIR__.'/Behavior.php';

/** A single action on a detached virtual state. No globals, time, RNG, SQL or user callbacks. */
final class AF_ACS_Effects
{
    public const VERSION='acs-effects-1';
    public static function instanceSpec(array $definition,array $p,array $tickDefinitions=[],?array $upkeep=null):array
    {
        if(($definition['schema']??null)!=='af_kb.arpg.mechanic.v1')self::fail('status_instance_schema_unsupported');
        $allowed=['schema','key','version','type','duration','phase','decrement','repeat_rule','max_stacks','removal_conditions','expiry_conditions','immunities','resistances','periodic_effects','formula_refs','events',
            'scope','category','source_scope','stack_max','stack_per_apply','cleansable','dispellable','mechanical_parameters'];
        if(array_diff(array_keys($definition),$allowed))self::fail('status_instance_schema_unsupported');
        foreach(['removal_conditions','expiry_conditions','events'] as $field)if(!empty($definition[$field]))self::fail('status_instance_callbacks_unsupported');
        if(array_key_exists('stack_max',$definition)&&$definition['stack_max']!==$definition['max_stacks'])self::fail('status_stack_alias_conflict');
        $stack=((array_key_exists('repeat_rule',$definition)?$definition['repeat_rule']:'refresh'))==='stack';
        $spec=['schema_version'=>1,'effect_key'=>$p['status_key']??null,'scope'=>$definition['scope']??null,'category'=>(array_key_exists('category',$definition)?$definition['category']:$definition['type']??null),
            'polarity'=>$p['polarity']??null,'repeat_rule'=>(array_key_exists('repeat_rule',$definition)?$definition['repeat_rule']:'refresh'),'source_scope'=>(array_key_exists('source_scope',$definition)?$definition['source_scope']:'source'),'duration'=>$definition['duration']??null,
            'stack_max'=>$stack?($definition['stack_max']??$definition['max_stacks']??null):1,'stack_per_apply'=>$stack?($definition['stack_per_apply']??null):1,
            'cleansable'=>(array_key_exists('cleansable',$definition)?$definition['cleansable']:true),'dispellable'=>(array_key_exists('dispellable',$definition)?$definition['dispellable']:true),'mechanical_parameters'=>(array_key_exists('mechanical_parameters',$definition)?$definition['mechanical_parameters']:[])];
        $duration=null;
        foreach(['duration','duration_value'] as $field)if(array_key_exists($field,$p)&&$p[$field]!==null&&$p[$field]!==''){
            $value=AF_ACS_Numeric::integer($p[$field],0,2147483647);
            if($duration!==null&&$duration!==$value)self::fail('status_duration_alias_conflict');$duration=$value;
        }
        if($duration!==null){
            if(!in_array($p['duration_unit']??null,['own_turn','target_turn','round'],true))self::fail('status_duration_override_unit_missing');
            $spec['duration']=['value'=>$duration,'unit'=>$p['duration_unit']];
        }elseif(array_key_exists('duration_unit',$p)&&$p['duration_unit']!==null&&$p['duration_unit']!==''&&$p['duration_unit']!==($spec['duration']['unit']??null))self::fail('status_duration_override_value_missing');
        if(!empty($definition['periodic_effects'])){
            if(!is_array($definition['periodic_effects'])||!array_is_list($definition['periodic_effects'])||count($tickDefinitions)!==count($definition['periodic_effects']))self::fail('status_tick_definition_missing');
            $event=$definition['decrement']['event']??null;
            if(AF_ACS_Numeric::number($definition['decrement']['amount']??null)!=1||!in_array($event,$spec['duration']['unit']==='round'?['on_round_end']:['on_turn_start','on_turn_end'],true))self::fail('status_tick_decrement_unsupported');
            $spec['duration_event']=$event;
            $ticks=[];foreach($definition['periodic_effects'] as $i=>$periodic){
                if(!is_array($periodic)||!is_array($periodic['period']??null)||count($periodic['period'])!==2||AF_ACS_Numeric::number($periodic['period']['value']??null)!=1||($periodic['period']['unit']??null)!==$spec['duration']['unit']
                    ||($periodic['phase']??null)!==($spec['duration']['unit']==='round'?'on_round_start':'on_turn_start')||($definition['phase']??null)!==$periodic['phase'])self::fail('status_tick_timing_unsupported');
                $ticks[]=self::tickSpec($tickDefinitions[$i],$periodic['effect']??[]);
            }
            $spec['periodic']=$ticks;
        }elseif($tickDefinitions)self::fail('status_tick_definition_unexpected');
        else{
            $end=$spec['duration']['unit']==='round'?'on_round_end':'on_turn_end';$originalEnd=($definition['duration']['unit']??null)==='round'?'on_round_end':'on_turn_end';
            $declared=$definition['decrement']['event']??null;
            if(($declared!==$end&&!($duration!==null&&$declared===$originalEnd))||AF_ACS_Numeric::number($definition['decrement']['amount']??null)!=1)self::fail('status_duration_decrement_unsupported');
        }
        if($upkeep!==null)$spec['upkeep']=$upkeep;
        self::statusSpec($spec);return $spec;
    }
    /** Exact retained effect component, never a live ability cast, text formula or implicit stack multiplier. */
    public static function tickSpec(array $snapshot,array $expected):array
    {
        self::fields($snapshot,['source_ref','source_json','effect_ref']);$ref=$snapshot['source_ref']??[];
        self::ref($ref);if(($snapshot['effect_ref']??null)!==$expected||($expected['type']??null)!=='arpg_ability'||($expected['key']??null)!==$ref['key'])self::fail('status_tick_reference_invalid');
        if(!is_string($snapshot['source_json']??null)||!hash_equals($ref['fingerprint'],'sha256:'.hash('sha256',$snapshot['source_json'])))self::fail('status_tick_pin_invalid');
        try{$definition=json_decode($snapshot['source_json'],true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail('status_tick_pin_invalid');}
        if(($definition['schema']??null)!=='af_kb.arpg.rules.v1'||($definition['version']??null)!==$ref['version'])self::fail('status_tick_schema_unsupported');
        if(!is_array($definition['effects']??null)||!array_is_list($definition['effects']))self::fail('status_tick_schema_unsupported');
        $index=AF_ACS_Numeric::integer($expected['effect_index']??null,0,2147483647);$effect=$definition['effects'][$index]??null;
        if(!is_array($effect))self::fail('status_tick_definition_missing');
        self::fields($effect,['kind','effect_type','value','value_mode','damage_type','damage_element','element','can_crit','notes']);
        $kind=$effect['effect_type']??$effect['kind']??null;
        if(isset($effect['kind'],$effect['effect_type'])&&$effect['kind']!==$effect['effect_type'])self::fail('status_tick_type_conflict');
        if(!in_array($kind,['damage','heal'],true)||($effect['can_crit']??false)!==false||!in_array($effect['value_mode']??'flat',['flat',''],true))self::fail('status_tick_effect_unsupported');
        if(array_key_exists('can_crit',$definition)&&!is_bool($definition['can_crit']))self::fail('status_tick_effect_unsupported');
        if(!array_key_exists('can_crit',$effect)&&($definition['can_crit']??false)===true)self::fail('status_tick_effect_unsupported');
        $value=AF_ACS_Numeric::number($effect['value']??null);if($value<0)self::fail('status_tick_value_invalid');
        $result=['kind'=>$kind,'value'=>$value,'effect_ref'=>$expected,'source_ref'=>$ref];
        if($kind==='heal')AF_ACS_Numeric::integer($value);
        else{
            if(!in_array($effect['damage_type']??null,['physical','elemental','true'],true))self::fail('status_tick_damage_type_missing');
            $result['damage_type']=$effect['damage_type'];$result['damage_element']=$effect['damage_element']??null;
            if($result['damage_type']==='elemental'&&(!is_string($result['damage_element'])||isset($effect['element'])&&$effect['element']!==$result['damage_element']))self::fail('status_tick_element_missing');
        }return $result;
    }
    /** Typed KB sustain costs, assigned only to an unambiguous single status component. */
    public static function upkeepSpec(array $costs,array $mechanics=[]):?array
    {
        if(!$costs)return null;
        if(!array_is_list($costs))self::fail('upkeep_cost_schema_invalid');$prepared=[];
        foreach($costs as $cost){
            if(!is_array($cost))self::fail('upkeep_cost_schema_invalid');self::fields($cost,['kind','resource_key','value','timing','period']);
            if(($cost['kind']??null)!=='resource'||($cost['timing']??null)!=='sustain'||!is_array($cost['period']??null)||($cost['period']['unit']??null)!=='own_turn'||array_diff(array_keys($cost['period']),['value','unit'])||AF_ACS_Numeric::number($cost['period']['value']??null)!=1)self::fail('upkeep_cost_schema_unsupported');
            $key=$cost['resource_key']??null;if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$key))self::fail('upkeep_resource_invalid');
            $amount=self::integer($cost['value']??null);$kind=in_array($key,['hp','ap'],true)?$key:'resource';
            $prepared[]=['kind'=>$kind,'amount'=>$amount]+($kind==='resource'?['resource'=>$key]:[]);
        }
        foreach(['autonomous','upkeep_hp_allow_zero'] as $field)if(array_key_exists($field,$mechanics)&&!is_bool($mechanics[$field]))self::fail('upkeep_policy_invalid');
        $keys=$mechanics['interrupt_on_status_keys']??[];if(!is_array($keys)||!array_is_list($keys))self::fail('upkeep_policy_invalid');
        foreach($keys as $key)if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$key))self::fail('upkeep_policy_invalid');
        return ['costs'=>$prepared,'autonomous'=>$mechanics['autonomous']??false,'hp_allow_zero'=>$mechanics['upkeep_hp_allow_zero']??false,'interrupt_on_status_keys'=>$keys];
    }
    private static function stopSustained(array &$row,string $reason,string $cause,array &$events):void
    {
        $row['status']='removed';$row['stacks']=0;$row['termination']=['reason'=>$reason,'causal_event'=>$cause];self::bump($row);
        $events[]=['type'=>'sustained_stopped','id'=>$row['id'],'reason'=>$reason,'causal_event'=>$cause];
    }
    /** Called after every atomic effect, before later healing can hide an incapacitation. */
    private static function interruptSustained(array &$state,array &$events,string $cause):void
    {
        AF_ACS_Spawn::interrupt($state,$events);
        foreach($state['status_instances']??[] as $id=>$row){
            if($row['status']!=='active'||!isset($row['spec']['upkeep']))continue;$policy=$row['spec']['upkeep'];$source=$row['source_entity_id'];$reason=null;
            if(isset($state['entities'][$source])&&$state['entities'][$source]['life_state']!=='active'&&!$policy['autonomous'])$reason='source_inactive';
            foreach($state['status_instances'] as $control)if($control['status']==='active'&&$control['target_entity_id']===$source&&in_array($control['spec']['effect_key'],$policy['interrupt_on_status_keys'],true))$reason='kb_control';
            if($reason!==null)self::stopSustained($state['status_instances'][$id],$reason,$cause,$events);
        }
    }
    /** Only Clock submits this operation, with costs read from the immutable instance. */
    private static function upkeep(array &$state,array $op,array &$events,array &$signals):void
    {
        self::fields($op,['kind','id','boundary']);$id=(string)($op['id']??'');$key=$op['boundary']??null;
        if(!is_string($key)||!preg_match('/\Aentity_turn:([1-9][0-9]{0,19}):start\z/D',$key,$m))self::fail('upkeep_boundary_invalid');self::id($m[1]);
        $row=$state['status_instances'][$id]??null;if(!$row||!isset($row['spec']['upkeep']))self::fail('upkeep_instance_invalid');
        if($row['status']!=='active'||($row['last_upkeep']['boundary_key']??null)===$key)return;
        $source=$row['source_entity_id'];if(!isset($state['entities'][$source]))self::fail('upkeep_source_missing');$p=$row['spec']['upkeep'];
        $copy=$state;$paid=[];$transitions=[];$reason=null;
        try{self::payCosts($copy,$source,$p['costs'],$p['hp_allow_zero'],$paid,$transitions);}
        catch(AF_ACS_FormulaError $e){if(!in_array($e->getMessage(),['effects_cost_unaffordable','effects_resource_invalid'],true))throw $e;$reason=$e->getMessage();}
        if($reason===null){$state=$copy;$events=[...$events,...$paid];$signals=[...$signals,...$transitions];}
        else self::stopSustained($state['status_instances'][$id],$reason,$key,$events);
        $state['status_instances'][$id]['last_upkeep']=['boundary_key'=>$key,'paid'=>$reason===null,'reason'=>$reason,'costs'=>$p['costs']];self::bump($state['status_instances'][$id]);
        $events[]=['type'=>'upkeep_result','id'=>$id,'source_entity_id'=>$source]+$state['status_instances'][$id]['last_upkeep'];
        self::interruptSustained($state,$events,$key);
    }
    public const REACTION_PHASES=['on_targeted','before_hit','after_hit','after_damage'];
    private static function retainedNode(array $node):array
    {
        self::fields($node,['json','ref']);self::ref($node['ref']??null);
        if(!is_string($node['json']??null)||!hash_equals($node['ref']['fingerprint'],'sha256:'.hash('sha256',$node['json'])))self::fail('reaction_pin_invalid');
        try{$d=json_decode($node['json'],true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail('reaction_pin_invalid');}
        if(!is_array($d)||($d['version']??null)!==$node['ref']['version'])self::fail('reaction_pin_invalid');return $d;
    }
    /** Bounded retained KB graph, not a second effects interpreter. */
    public static function reactionSpec(array $snapshot):array
    {
        self::fields($snapshot,['schema_version','trigger','ability','condition','passives']);if(($snapshot['schema_version']??null)!==1)self::fail('reaction_schema_unsupported');
        $t=self::retainedNode($snapshot['trigger']??[]);$a=self::retainedNode($snapshot['ability']??[]);
        self::fields($t,['schema','key','version','event','priority','condition_ref','effect_ref','limits','formula_refs']);
        if(($t['schema']??null)!=='af_kb.arpg.mechanic.v1'||($t['key']??null)!==$snapshot['trigger']['ref']['key']||!in_array($t['event']??null,self::REACTION_PHASES,true)||!empty($t['formula_refs']))self::fail('reaction_trigger_unsupported');
        $priority=AF_ACS_Numeric::integer($t['priority']??null,-2147483648,2147483647);
        if(!is_array($t['limits']??null)||array_diff(array_keys($t['limits']),['per_round','max_depth','per_cause'])||isset($t['limits']['per_round'])&&$t['limits']['per_round']!==1)self::fail('reaction_limits_unsupported');
        $predicate=null;
        if(($t['condition_ref']??null)!==null){$c=self::retainedNode($snapshot['condition']??[]);self::fields($c,['schema','key','version','predicate']);
            if(($c['schema']??null)!=='af_kb.arpg.mechanic.v1'||$t['condition_ref']!=='condition_template:'.$snapshot['condition']['ref']['key']||($c['key']??null)!==$snapshot['condition']['ref']['key'])self::fail('reaction_condition_unsupported');
            $predicate=$c['predicate']??null;if(!is_array($predicate))self::fail('reaction_condition_unsupported');self::reactionCondition($predicate,['event.phase'=>$t['event']],true);
        }elseif(($snapshot['condition']??null)!==null)self::fail('reaction_condition_unsupported');
        self::fields($a,['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value']);
        if(!empty($a['auto_hit']))self::fail('reaction_ability_override_unsupported');
        if(($a['type']??'active')!=='active'||!is_array($a['effects']??null)||!array_is_list($a['effects']))self::fail('reaction_effect_unsupported');
        $ref=$t['effect_ref']??[];self::fields($ref,['type','key','effect_index']);$index=AF_ACS_Numeric::integer($ref['effect_index']??null,0,2147483647);
        if(($ref['type']??null)!=='arpg_ability'||($ref['key']??null)!==$snapshot['ability']['ref']['key']||($a['schema']??null)!=='af_kb.arpg.rules.v1'||!in_array($a['targeting']??null,['self','single_ally','multiple','all_allies'],true))self::fail('reaction_effect_unsupported');
        foreach(['resources','cost_value','cost_resource','conditions','application_conditions','usage_limits','timing','formula_refs','formula_ref','math_modifiers','modifiers','base_stats','reflection','lifesteal','triggers','max_charges','cooldown','cooldown_value','cast_time','duration','duration_value','range','action_cost','stacking','upgrade_requirements','application_mode','summon','grants','immunities','formula_profile','damage_value','heal_value','shield_value'] as $field)if(!empty($a[$field]))self::fail('reaction_ability_override_unsupported');
        $e=$a['effects'][$index]??null;if(!is_array($e))self::fail('reaction_effect_unsupported');
        self::fields($e,['kind','effect_type','target','targeting','value','value_mode','can_crit','notes','categories','limit','mass','chance','chance_scope','remove_stacks','control_type','scope','scope_key','absorbs','barrier_priority','duration_value','duration_unit','costs_per_target']);
        foreach(['target','targeting'] as $field)if(array_key_exists($field,$e)&&$e[$field]!==$a['targeting'])self::fail('reaction_effect_unsupported');
        if(array_key_exists('can_crit',$e)&&!is_bool($e['can_crit'])||array_key_exists('can_crit',$a)&&!is_bool($a['can_crit']))self::fail('reaction_effect_unsupported');
        $kind=$e['effect_type']??$e['kind']??null;
        if(isset($e['kind'],$e['effect_type'])&&$e['kind']!==$e['effect_type']||($e['can_crit']??false)!==false||($a['can_crit']??false)!==false)self::fail('reaction_effect_unsupported');
        $selection=$a['targeting'];$extraCosts=[];$targetCount=null;
        if(array_key_exists('target_count',$a)){if(!is_array($a['target_count'])||array_diff(array_keys($a['target_count']),['min','max']))self::fail('reaction_target_count_unsupported');$min=AF_ACS_Numeric::integer($a['target_count']['min']??null,1,100);$max=AF_ACS_Numeric::integer($a['target_count']['max']??null,$min,100);$targetCount=['min'=>$min,'max'=>$max];}
        if($kind==='control'&&($e['control_type']??null)==='intercept'){
            if($selection!=='single_ally'||!in_array($t['event'],['on_targeted','before_hit'],true)||array_diff(array_keys($e),['kind','effect_type','target','targeting','control_type','chance','can_crit','notes']))self::fail('reaction_intercept_unsupported');
            $probability=array_key_exists('chance',$e)?AF_ACS_Numeric::number($e['chance']):100;if($probability<0||$probability>100)self::fail('reaction_intercept_unsupported');$effect=['kind'=>'intercept'];
        }elseif($kind==='shield'){
            if(!in_array($selection,['multiple','all_allies'],true)||($e['mass']??null)!==true||!in_array($t['event'],['on_targeted','before_hit','after_hit'],true)||array_diff(array_keys($e),['kind','effect_type','target','targeting','mass','value','value_mode','scope','scope_key','absorbs','barrier_priority','duration_value','duration_unit','costs_per_target','can_crit','notes']))self::fail('reaction_mass_defense_unsupported');
            if(!in_array($e['scope']??null,['shared_pool','per_target'],true)||!is_array($e['absorbs']??null)||!array_is_list($e['absorbs'])||array_diff($e['absorbs'],['physical','elemental'])||!$e['absorbs']||!in_array($e['value_mode']??'flat',['flat',''],true))self::fail('reaction_mass_defense_unsupported');
            $duration=['value'=>$e['duration_value']??null,'unit'=>$e['duration_unit']??null];if(!is_array($duration)||array_diff(array_keys($duration),['value','unit'])||!in_array($duration['unit']??null,['own_turn','target_turn','round'],true)||$e['scope']==='shared_pool'&&$duration['unit']==='target_turn')self::fail('reaction_mass_defense_unsupported');
            AF_ACS_Numeric::integer($duration['value']??null,1,2147483647);$value=AF_ACS_Numeric::integer($e['value']??null,1);$barrierPriority=AF_ACS_Numeric::integer($e['barrier_priority']??0,-2147483648,2147483647);
            if($e['scope']==='shared_pool'&&(!is_string($e['scope_key']??null)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$e['scope_key'])))self::fail('reaction_mass_defense_unsupported');
            $extraCosts=self::reactionCosts($e['costs_per_target']??[]);$effect=['kind'=>'mass_defense','strength'=>$value,'scope'=>$e['scope'],'scope_key'=>$e['scope_key']??null,'absorbs'=>$e['absorbs'],'priority'=>$barrierPriority,'duration'=>$duration];$probability=100;
        }elseif($kind==='heal'){
            foreach(['categories','limit','mass','chance','chance_scope','remove_stacks'] as $field)if(array_key_exists($field,$e))self::fail('reaction_effect_unsupported');
            if(!in_array($e['value_mode']??'flat',['flat',''],true))self::fail('reaction_effect_unsupported');$effect=['kind'=>'heal','amount'=>self::integer($e['value']??null)];$probability=100;
        }elseif(in_array($kind,['cleanse','dispel'],true)){
            if(array_key_exists('value',$e)||array_key_exists('value_mode',$e)||array_key_exists('mass',$e)&&!is_bool($e['mass'])||($e['mass']??false)||array_key_exists('limit',$e)&&$e['limit']!==1||array_key_exists('chance_scope',$e)&&$e['chance_scope']!=='operation')self::fail('reaction_mass_or_override_unsupported');
            $policy=['kind'=>$kind,'categories'=>array_key_exists('categories',$e)?$e['categories']:[],'limit'=>1,'remove_stacks'=>$e['remove_stacks']??null,'chance'=>array_key_exists('chance',$e)?$e['chance']:100];self::removalSpec($policy);$effect=['kind'=>'status_remove','policy'=>$policy];$probability=$policy['chance'];
        }else self::fail('reaction_effect_unsupported');
        if(in_array($kind,['heal','cleanse','dispel'],true)&&($selection!=='self'||array_intersect(array_keys($e),['control_type','scope','scope_key','absorbs','barrier_priority','duration_value','duration_unit','costs_per_target'])))self::fail('reaction_effect_unsupported');
        $passives=self::passiveSpec($a,$snapshot['passives']??[],$t['limits']);
        $costs=self::reactionCosts($a['costs']??[]);
        return ['phase'=>$t['event'],'priority'=>$priority,'condition'=>$predicate,'effect'=>$effect,'probability'=>$probability,'costs'=>$costs,'trigger_ref'=>$snapshot['trigger']['ref'],'ability_ref'=>$snapshot['ability']['ref'],'effect_ref'=>$ref]+($selection!=='self'?['selection'=>$selection,'per_target_costs'=>$extraCosts]:[])+($passives?['passives'=>$passives]:[])+($targetCount!==null?['target_count'=>$targetCount]:[]);
    }
    private static function passiveRefs(array $ability):array
    {
        $refs=[];if(!is_array($ability['passive_effects']??[])||!array_is_list($ability['passive_effects']??[]))self::fail('passive_schema_unsupported');
        foreach($ability['passive_effects']??[] as $p){if(!is_array($p)||array_diff(array_keys($p),['kind','control_type','effect_ref'])||($p['kind']??null)!=='control'||($p['control_type']??null)!=='passive')self::fail('passive_schema_unsupported');$ref=$p['effect_ref']??[];self::fields($ref,['type','key','effect_index']);if(($ref['type']??null)!=='arpg_ability'||!is_string($ref['key']??null))self::fail('passive_schema_unsupported');AF_ACS_Numeric::integer($ref['effect_index']??null,0,2147483647);$refs[]=$ref['key'].':'.$ref['effect_index'];}return $refs;
    }
    private static function passiveSpec(array $ability,array $snapshots,array $limits):array
    {
        $roots=self::passiveRefs($ability);if(!$roots){if($snapshots)self::fail('passive_schema_unsupported');return [];}
        $max=AF_ACS_Numeric::integer($limits['max_depth']??null,1,2147483647);if(($limits['per_cause']??null)!==1)self::fail('passive_repeat_limit_required');$nodes=[];
        foreach($snapshots as $snapshot){self::fields($snapshot,['source','effect_index']);$a=self::retainedNode($snapshot['source']??[]);$index=AF_ACS_Numeric::integer($snapshot['effect_index']??null,0,2147483647);$id=$snapshot['source']['ref']['key'].':'.$index;$e=$a['effects'][$index]??null;
            if(isset($nodes[$id])||($a['schema']??null)!=='af_kb.arpg.rules.v1'||!is_array($e))self::fail('passive_schema_unsupported');
            self::fields($a,['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value']);
            foreach(['resources','cost_value','cost_resource','conditions','application_conditions','usage_limits','timing','formula_refs','formula_ref','math_modifiers','modifiers','base_stats','reflection','lifesteal','triggers','max_charges','cooldown','cooldown_value','cast_time','duration','duration_value','range','action_cost','stacking','upgrade_requirements','application_mode','summon','grants','immunities','formula_profile','damage_value','heal_value','shield_value'] as $field)if(!empty($a[$field]))self::fail('passive_schema_unsupported');
            if(!empty($a['auto_hit'])||!empty($a['target_count'])||!in_array($a['type']??'active',['active','passive'],true)||($a['targeting']??null)!=='self'||count($a['effects'])!==1||$index!==0)self::fail('passive_schema_unsupported');
            self::fields($e,['kind','effect_type','target','value','value_mode','damage_type','damage_element','can_crit','notes']);$kind=$e['effect_type']??$e['kind']??null;
            if(isset($e['kind'],$e['effect_type'])&&$e['kind']!==$e['effect_type']||!in_array($kind,['heal','damage'],true)||!in_array($e['target']??null,['self','attacker'],true)||($e['can_crit']??false)!==false||($a['can_crit']??false)!==false||($e['value_mode']??'flat')!=='flat'||!empty($a['costs']))self::fail('passive_schema_unsupported');
            $node=['kind'=>$kind,'target'=>$e['target'],'value'=>$kind==='heal'?self::integer($e['value']??null):AF_ACS_Numeric::number($e['value']??null),'source_ref'=>$snapshot['source']['ref'],'children'=>self::passiveRefs($a)];
            if($node['value']<0)self::fail('passive_schema_unsupported');if($kind==='damage'){if(!in_array($e['damage_type']??null,['physical','elemental','true'],true))self::fail('passive_schema_unsupported');$node+=['damage_type'=>$e['damage_type'],'damage_element'=>$e['damage_element']??null];}$nodes[$id]=$node;
        }
        // Cycles are diagnosed by Resolver on the retained graph, before adopting any result.
        return ['nodes'=>$nodes,'roots'=>$roots,'max_depth'=>$max,'per_cause'=>1];
    }
    private static function reactionCosts(array $raw):array
    {
        $costs=[];if(!is_array($raw)||!array_is_list($raw))self::fail('reaction_cost_unsupported');
        foreach($raw as $cost){if(!is_array($cost))self::fail('reaction_cost_unsupported');self::fields($cost,['kind','resource_key','value','timing']);$key=$cost['resource_key']??null;
            if(($cost['kind']??null)!=='resource'||($cost['timing']??null)!=='use'||!is_string($key)||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$key)||$key==='ap')self::fail('reaction_cost_unsupported');
            $costs[]=['kind'=>$key==='hp'?'hp':'resource','amount'=>self::integer($cost['value']??null)]+($key==='hp'?[]:['resource'=>$key]);
        }
        return $costs;
    }
    /** Closed KB predicate vocabulary; no display names, masks or arbitrary object paths. */
    public static function reactionCondition(?array $p,array $input,bool $validate=false,int $depth=0):bool
    {
        if($p===null)return true;if($depth>12)self::fail('reaction_condition_unsupported');$op=$p['operator']??null;
        if(in_array($op,['all','any'],true)){self::fields($p,['operator','conditions']);$children=$p['conditions']??null;
            if(!is_array($children)||!array_is_list($children)||count($children)<1||count($children)>8)self::fail('reaction_condition_unsupported');$values=[];
            foreach($children as $c){if(!is_array($c))self::fail('reaction_condition_unsupported');$values[]=self::reactionCondition($c,$input,$validate,$depth+1);}return $op==='all'?!in_array(false,$values,true):in_array(true,$values,true);
        }
        if($op==='not'){self::fields($p,['operator','conditions']);if(!is_array($p['conditions']??null)||!array_is_list($p['conditions'])||count($p['conditions'])!==1||!is_array($p['conditions'][0]))self::fail('reaction_condition_unsupported');return !self::reactionCondition($p['conditions'][0],$input,$validate,$depth+1);}
        self::fields($p,['operator','input','value']);$path=$p['input']??null;$value=$p['value']??null;
        if(!in_array($op,['eq','neq','lt','lte','gt','gte'],true)||!is_string($path)||!in_array($path,['actor.hp','actor.max_hp','actor.life_state','actor.kind','event.phase','event.hit_success','event.hp_loss'],true)||!is_scalar($value))self::fail('reaction_condition_unsupported');
        $numeric=in_array($path,['actor.hp','actor.max_hp','event.hp_loss'],true);
        if($numeric)AF_ACS_Numeric::number($value);elseif(!in_array($op,['eq','neq'],true)||($path==='event.hit_success'?!is_bool($value):!is_string($value)))self::fail('reaction_condition_unsupported');
        if($validate&&isset($input['event.phase'])&&($path==='event.hp_loss'&&$input['event.phase']!=='after_damage'||$path==='event.hit_success'&&!in_array($input['event.phase'],['after_hit','after_damage'],true)))self::fail('reaction_condition_phase_unsupported');
        if($validate)return true;if(!array_key_exists($path,$input))self::fail('reaction_condition_context_missing');$actual=$input[$path];
        return match($op){'eq'=>$numeric?$actual==$value:$actual===$value,'neq'=>$numeric?$actual!=$value:$actual!==$value,'lt'=>$actual<$value,'lte'=>$actual<=$value,'gt'=>$actual>$value,'gte'=>$actual>=$value};
    }
    /** All costs and the one permitted component succeed atomically or leave the attack untouched. */
    public static function reactionAttempt(array $state,string $source,array $spec,array $scope,?array $roll,string $mode,array $targets=[]):array
    {
        if($mode==='preview'&&$spec['probability']<100)return ['executed'=>false,'reason'=>'probability_pending','state'=>$state,'trace'=>[]];
        $effect=$spec['effect'];$ops=[];$costs=$spec['costs'];
        if($effect['kind']==='intercept'){
            if($spec['probability']<100){self::statusSample($roll);$success=100*$roll['u']<$spec['probability'];}else $success=true;
        }elseif($effect['kind']==='mass_defense'){
            if(!$targets)self::fail('reaction_protected_targets_missing');foreach($targets as $_)array_push($costs,...$spec['per_target_costs']);$sets=$effect['scope']==='shared_pool'?[$targets]:array_map(static fn($id)=>[$id],$targets);
            $order=$state['next_barrier_order'];foreach($sets as $set){$pool=['source_entity_id'=>$source,'source_ref'=>$spec['ability_ref'],'scope'=>$effect['scope'],'scope_key'=>$effect['scope']==='per_target'?$set[0]:$effect['scope_key'],'targets'=>$set,'strength'=>$effect['strength'],'maximum_strength'=>$effect['strength'],'barrier_priority'=>$effect['priority'],'absorbs'=>$effect['absorbs'],'duration'=>$effect['duration']];$ops[]=['kind'=>'barrier_apply','pool_id'=>'new:'.($order++),'pool'=>$pool];}
        }elseif($effect['kind']==='heal')$effect['entity_id']=$source;
        else{$effect+=['target'=>$source,'session_id'=>$scope['session_id']??null,'encounter_id'=>$scope['encounter_id']??null,'roll'=>$roll];}
        if(!in_array($effect['kind'],['intercept','mass_defense'],true))$ops[]=$effect;
        try{$r=self::simulate($state,['actor_id'=>$source,'costs'=>$costs,'effects'=>$ops]);return ['executed'=>true,'reason'=>null,'state'=>$r->serverState(),'trace'=>$r->serverTrace()]+($effect['kind']==='intercept'?['redirect_success'=>$success]:[]);}
        catch(AF_ACS_FormulaError $e){if(!in_array($e->diagnosticCode,['effects_cost_unaffordable','effects_resource_invalid','effects_actor_unavailable'],true))throw $e;return ['executed'=>false,'reason'=>$e->diagnosticCode,'state'=>$state,'trace'=>[]];}
    }
    public static function ordinaryActionAllowed(array $state,string $entity):bool
    {
        if(isset($state['summons'][$entity])&&($state['summons'][$entity]['status']!=='active'||$state['summons'][$entity]['pending_independent']))return false;
        foreach($state['status_instances']??[] as $row)if($row['status']==='active'&&$row['target_entity_id']===$entity&&($row['spec']['mechanical_parameters']['blocks_ordinary_actions']??false)===true)return false;
        return true;
    }
    /** Closed prepared lifecycle contract; duration is stored, never advanced here. */
    public static function statusSpec(array $spec):void
    {
        self::fields($spec,['schema_version','effect_key','scope','category','polarity','repeat_rule','source_scope','duration','stack_max','stack_per_apply','cleansable','dispellable','mechanical_parameters','periodic','duration_event','upkeep']);
        if(($spec['schema_version']??null)!==1)self::fail('status_instance_schema_unsupported');
        foreach(['effect_key','category'] as $field)if(!is_string($spec[$field]??null)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$spec[$field]))self::fail('status_instance_key_invalid');
        if(!in_array($spec['scope']??null,['combat','session','persistent'],true)||!in_array($spec['polarity']??null,['negative','positive'],true)
            ||!in_array($spec['repeat_rule']??null,['refresh','stack','independent','reject','extend'],true)||!in_array($spec['source_scope']??null,['source','shared'],true))self::fail('status_instance_policy_unsupported');
        $d=$spec['duration']??null;if(!is_array($d))self::fail('status_duration_invalid');self::fields($d,['value','unit']);
        AF_ACS_Numeric::integer($d['value']??null,0,2147483647);if(!in_array($d['unit']??null,['own_turn','target_turn','round'],true))self::fail('status_duration_invalid');
        $max=AF_ACS_Numeric::integer($spec['stack_max']??null,1,2147483647);$per=AF_ACS_Numeric::integer($spec['stack_per_apply']??null,1,2147483647);
        if($per>$max||($spec['repeat_rule']!=='stack'&&($per!==1||$max!==1)))self::fail('status_stack_contract_invalid');
        foreach(['cleansable','dispellable'] as $field)if(!is_bool($spec[$field]??null))self::fail('status_removal_policy_invalid');
        if(!is_array($spec['mechanical_parameters']??null))self::fail('status_mechanics_invalid');
        if(array_key_exists('blocks_ordinary_actions',$spec['mechanical_parameters'])&&!is_bool($spec['mechanical_parameters']['blocks_ordinary_actions']))self::fail('status_control_contract_invalid');
        if(($spec['mechanical_parameters']['blocks_ordinary_actions']??false)===true&&($spec['duration']['unit']!=='target_turn'||(!empty($spec['periodic'])&&($spec['duration_event']??null)!=='on_turn_end')))self::fail('status_control_timing_unsupported');
        if(array_intersect(array_keys($spec['mechanical_parameters']),['invisible','hidden','concealed','reveal_on_action']))self::fail('ACS_AWARENESS_UNSUPPORTED');
        if(isset($spec['mechanical_parameters']['stealth'])){AF_ACS_Awareness::stealth($spec['mechanical_parameters']['stealth']);if($spec['scope']!=='combat')self::fail('ACS_AWARENESS_UNSUPPORTED');}
        if(isset($spec['periodic'])&&!in_array($spec['duration_event']??null,$spec['duration']['unit']==='round'?['on_round_end']:['on_turn_start','on_turn_end'],true))self::fail('status_tick_decrement_unsupported');
        if(array_key_exists('behavior_constraint',$spec['mechanical_parameters'])){
            AF_ACS_Behavior::spec($spec['mechanical_parameters']['behavior_constraint']);
            if($spec['scope']!=='combat'||$spec['source_scope']!=='source'||$spec['duration']['value']<1||isset($spec['mechanical_parameters']['control_effect'])||!empty($spec['periodic'])||isset($spec['upkeep']))self::fail('ACS_BEHAVIOR_UNSUPPORTED');
        }
        if(isset($spec['mechanical_parameters']['control_effect'])){
            AF_ACS_Control::spec($spec['mechanical_parameters']['control_effect']);
            if($spec['scope']!=='combat'||$spec['repeat_rule']!=='independent'||$spec['source_scope']!=='source'||$spec['duration']['value']<1||!in_array($spec['duration']['unit'],['target_turn','round'],true)||isset($spec['upkeep'])||!empty($spec['periodic'])||!empty($spec['mechanical_parameters']['blocks_ordinary_actions']))self::fail('ACS_CONTROL_EFFECT_UNSUPPORTED');
        }
        if(isset($spec['upkeep'])){
            $u=$spec['upkeep'];if(!is_array($u))self::fail('upkeep_policy_invalid');self::fields($u,['costs','autonomous','hp_allow_zero','interrupt_on_status_keys']);
            if(!is_array($u['costs']??null)||!array_is_list($u['costs']))self::fail('upkeep_policy_invalid');
            foreach($u['costs'] as $c){if(!is_array($c))self::fail('upkeep_policy_invalid');self::fields($c,['kind','amount','resource']);if(!in_array($c['kind']??null,['hp','ap','resource'],true))self::fail('upkeep_policy_invalid');}
            $raw=[];foreach($u['costs'] as $c)$raw[]=['kind'=>'resource','resource_key'=>$c['kind']==='resource'?$c['resource']:$c['kind'],'value'=>$c['amount'],'timing'=>'sustain','period'=>['value'=>1,'unit'=>'own_turn']];
            if(!$raw||self::upkeepSpec($raw,['autonomous'=>$u['autonomous']??null,'upkeep_hp_allow_zero'=>$u['hp_allow_zero']??null,'interrupt_on_status_keys'=>$u['interrupt_on_status_keys']??null])!==$u||$spec['source_scope']!=='source')self::fail('upkeep_policy_invalid');
        }
        if(isset($spec['periodic'])&&(!is_array($spec['periodic'])||!array_is_list($spec['periodic'])||!$spec['periodic']))self::fail('status_tick_definition_missing');
    }
    private static function statusInstance(string $id,array $row):void
    {
        self::fields($row,['schema_version','id','session_id','encounter_id','source_entity_id','target_entity_id','spec','effect_definition_ref','status_definition_ref','stacks','remaining_duration','duration_unit','applied_order','version','status','origin_action_index','last_tick_boundary','last_duration_boundary','upkeep_priority','last_upkeep','termination','control_overlay']);
        if(($row['schema_version']??null)!==1||($row['id']??null)!==$id||(!preg_match('/\Anew:[1-9][0-9]*\z/D',$id)&&self::id($id)!==$id))self::fail('status_instance_identity_invalid');
        foreach(['session_id','encounter_id','source_entity_id','target_entity_id'] as $field)self::id($row[$field]??null);
        if(!is_array($row['spec']??null))self::fail('status_instance_schema_unsupported');self::statusSpec($row['spec']);
        if(str_starts_with($id,'new:')&&$id!=='new:'.($row['applied_order']??''))self::fail('status_instance_identity_invalid');
        foreach(['last_tick_boundary','last_duration_boundary'] as $field)if(array_key_exists($field,$row)){
            if(!is_string($row[$field])||!preg_match('/\A(?:entity_turn|round):([1-9][0-9]{0,19}):(?:start|end|ap_refresh|next)\z/D',$row[$field],$match))self::fail('status_boundary_invalid');self::id($match[1]);
        }
        if(isset($row['upkeep_priority']))AF_ACS_Numeric::integer($row['upkeep_priority'],1,2147483647);
        if(isset($row['last_upkeep'])){
            $u=$row['last_upkeep'];if(!is_array($u))self::fail('upkeep_result_invalid');self::fields($u,['boundary_key','paid','reason','costs']);
            if(!is_bool($u['paid']??null)||!preg_match('/\Aentity_turn:[1-9][0-9]{0,19}:start\z/D',$u['boundary_key']??'')||($u['costs']??null)!==($row['spec']['upkeep']['costs']??null)||!in_array($u['reason']??null,[null,'effects_cost_unaffordable','effects_resource_invalid'],true)||$u['paid']!==($u['reason']===null))self::fail('upkeep_result_invalid');
        }
        if(isset($row['termination'])){self::fields($row['termination'],['reason','causal_event']);if($row['status']!=='removed'||!in_array($row['termination']['reason']??null,['source_inactive','kb_control','effects_cost_unaffordable','effects_resource_invalid'],true)||!is_string($row['termination']['causal_event']??null))self::fail('upkeep_result_invalid');}
        if(isset($row['spec']['mechanical_parameters']['control_effect'])){
            $c=$row['control_overlay']??null;if(!is_array($c)||array_diff(array_keys($c),['controller_uid','base_assignment_version'])||count($c)!==2)self::fail('ACS_CONTROL_EFFECT_CONTEXT_REQUIRED');
            self::id($c['controller_uid']??null);self::id($c['base_assignment_version']??null);
        }elseif(isset($row['control_overlay']))self::fail('ACS_CONTROL_EFFECT_UNSUPPORTED');
        self::ref($row['status_definition_ref']??null);$ref=$row['effect_definition_ref']??null;
        if(!is_array($ref)||!in_array($ref['type']??null,['arpg_ability','arpg_item','arpg_bestiary'],true))self::fail('status_definition_missing');
        self::fields($ref,['type','key','version','fingerprint','effect_index']);self::ref(array_intersect_key($ref,array_flip(['key','version','fingerprint'])));
        AF_ACS_Numeric::integer($ref['effect_index']??null,0,2147483647);self::integer($row['origin_action_index']??null);
        $stacks=AF_ACS_Numeric::integer($row['stacks']??null,0,$row['spec']['stack_max']);
        AF_ACS_Numeric::integer($row['remaining_duration']??null,0,2147483647);
        if(($row['duration_unit']??null)!==$row['spec']['duration']['unit']||!in_array($row['status']??null,['active','removed'],true)
            ||($row['status']==='active')!==($stacks>0)||self::integer($row['version']??null)<1||self::integer($row['applied_order']??null)<1)self::fail('status_instance_state_invalid');
    }
    /** Removal policy is a concrete KB effect, not status resistance or an immunity bypass. */
    public static function removalSpec(array $policy):void
    {
        self::fields($policy,['kind','categories','limit','remove_stacks','chance']);
        if(!in_array($policy['kind']??null,['cleanse','dispel'],true)||!is_array($policy['categories']??null)||!array_is_list($policy['categories']))self::fail('status_removal_policy_invalid');
        foreach($policy['categories'] as $category)if(!is_string($category)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$category))self::fail('status_removal_policy_invalid');
        if($policy['limit']!==null)AF_ACS_Numeric::integer($policy['limit'],1,2147483647);
        if($policy['remove_stacks']!==null)AF_ACS_Numeric::integer($policy['remove_stacks'],1,2147483647);
        $chance=AF_ACS_Numeric::number($policy['chance']??null);if($chance<0||$chance>100)self::fail('status_removal_policy_invalid');
    }
    private static function statusOperation(array &$state,array $op,array &$events):void
    {
        if(!isset($state['status_instances'],$state['next_status_order']))self::fail('status_state_missing');
        $kind=$op['kind'];
        if($kind==='status_apply'){
            self::fields($op,['kind','session_id','encounter_id','source','target','spec','effect_definition_ref','status_definition_ref','action_index','control_context']);
            self::statusSpec($op['spec']);self::id($op['session_id']);self::id($op['encounter_id']);self::integer($op['action_index']);self::ref($op['status_definition_ref']);
            $ref=$op['effect_definition_ref'];self::fields($ref,['type','key','version','fingerprint','effect_index']);
            if(!in_array($ref['type']??null,['arpg_ability','arpg_item','arpg_bestiary'],true))self::fail('status_definition_missing');
            self::ref(array_intersect_key($ref,array_flip(['key','version','fingerprint'])));AF_ACS_Numeric::integer($ref['effect_index']??null,0,2147483647);$spec=$op['spec'];$source=self::id($op['source']);$target=self::id($op['target']);
            if(!isset($state['entities'][$source],$state['entities'][$target])||$state['entities'][$target]['life_state']==='dead')self::fail('status_entity_unavailable');
            $matches=[];
            foreach($state['status_instances'] as $id=>$row)if($row['status']==='active'&&$row['session_id']===$op['session_id']&&$row['target_entity_id']===$target
                &&$row['spec']['effect_key']===$spec['effect_key']&&$row['spec']['scope']===$spec['scope']&&($spec['scope']!=='combat'||$row['encounter_id']===$op['encounter_id'])
                &&($spec['source_scope']==='shared'||$row['source_entity_id']===$source))$matches[]=(string)$id;
            if($spec['repeat_rule']!=='independent'&&count($matches)>1)self::fail('status_identity_ambiguous');
            if($matches&&$spec['repeat_rule']!=='independent'){
                $id=$matches[0];$row=&$state['status_instances'][$id];
                if($row['spec']['source_scope']!==$spec['source_scope']||$row['duration_unit']!==$spec['duration']['unit']||$row['status_definition_ref']['key']!==$op['status_definition_ref']['key'])self::fail('status_reapply_conflict');
                if($spec['repeat_rule']==='reject'){$events[]=['type'=>'status_rejected','id'=>$id];unset($row);return;}
                if($spec['repeat_rule']==='stack'){
                    if(array_diff_key($row['spec'],['duration'=>true])!==array_diff_key($spec,['duration'=>true])||$row['status_definition_ref']!==$op['status_definition_ref'])self::fail('status_stack_definition_changed');
                    $row['stacks']=min($spec['stack_max'],self::integer($row['stacks']+$spec['stack_per_apply']));
                }
                $row['remaining_duration']=$spec['repeat_rule']==='extend'?AF_ACS_Numeric::integer($row['remaining_duration']+$spec['duration']['value'],0,2147483647):max($row['remaining_duration'],$spec['duration']['value']);
                self::bump($row);$events[]=['type'=>'status_refreshed','id'=>$id,'incoming_ref'=>$op['effect_definition_ref'],'stacks'=>$row['stacks'],'remaining_duration'=>$row['remaining_duration']];unset($row);
            }else{
                $order=self::integer($state['next_status_order']);$id='new:'.$order;
                if(isset($state['status_instances'][$id]))self::fail('status_instance_identity_invalid');
                $row=['schema_version'=>1,'id'=>$id,'session_id'=>$op['session_id'],'encounter_id'=>$op['encounter_id'],'source_entity_id'=>$source,'target_entity_id'=>$target,
                    'spec'=>$spec,'effect_definition_ref'=>$op['effect_definition_ref'],'status_definition_ref'=>$op['status_definition_ref'],'stacks'=>$spec['stack_per_apply'],
                    'remaining_duration'=>$spec['duration']['value'],'duration_unit'=>$spec['duration']['unit'],'applied_order'=>$order,'version'=>1,'status'=>'active','origin_action_index'=>$op['action_index']];
                if(isset($spec['mechanical_parameters']['control_effect']))$row['control_overlay']=$op['control_context']??throw new DomainException('ACS_CONTROL_EFFECT_CONTEXT_REQUIRED');
                self::statusInstance($id,$row);$state['status_instances'][$id]=$row;$state['next_status_order']=self::integer($order+1);$events[]=['type'=>'status_created','id'=>$id];
            }
        }elseif($kind==='status_remove'){
            self::fields($op,['kind','target','session_id','encounter_id','policy','roll']);self::id($op['session_id']??null);self::id($op['encounter_id']??null);self::removalSpec($op['policy']);$policy=$op['policy'];$target=self::id($op['target']);
            if(!isset($state['entities'][$target]))self::fail('status_entity_unavailable');
            $eligible=[];foreach($state['status_instances'] as $id=>$row)if($row['status']==='active'&&$row['target_entity_id']===$target&&$row['session_id']===$op['session_id']&&($row['spec']['scope']!=='combat'||$row['encounter_id']===$op['encounter_id'])
                &&$row['spec']['polarity']===($policy['kind']==='cleanse'?'negative':'positive')&&$row['spec'][$policy['kind']==='cleanse'?'cleansable':'dispellable']
                &&(!$policy['categories']||in_array($row['spec']['category'],$policy['categories'],true)))$eligible[]=(string)$id;
            usort($eligible,static fn($a,$b)=>$state['status_instances'][$a]['applied_order']<=>$state['status_instances'][$b]['applied_order']?:strcmp($a,$b));
            if($policy['limit']!==null)$eligible=array_slice($eligible,0,$policy['limit']);
            $success=true;if($policy['chance']<100){self::statusSample($op['roll']??null);$success=AF_ACS_Numeric::operation('multiply',100,$op['roll']['u'])<$policy['chance'];}
            $events[]=['type'=>'status_removal_check','kind'=>$policy['kind'],'success'=>$success,'selected'=>$eligible];
            if($success)foreach($eligible as $id){$row=&$state['status_instances'][$id];$row['stacks']=$policy['remove_stacks']===null?0:max(0,$row['stacks']-$policy['remove_stacks']);if(!$row['stacks'])$row['status']='removed';self::bump($row);$events[]=['type'=>'status_removed','id'=>$id,'stacks'=>$row['stacks']];unset($row);}
        }elseif($kind==='status_duration_boundary'){
            self::fields($op,['kind','id','boundary','tick','decrement']);$id=(string)$op['id'];$row=&$state['status_instances'][$id];
            if(!is_array($row)||!is_string($op['boundary']??null)||!is_bool($op['tick']??null))self::fail('status_boundary_invalid');
            $decrement=$op['decrement']??true;
            if(!is_bool($decrement))self::fail('status_boundary_invalid');
            if(($decrement?($row['last_duration_boundary']??null):($row['last_tick_boundary']??null))===$op['boundary']){unset($row);return;}
            if($row['status']==='active'){
                if($decrement){$row['remaining_duration']=max(0,$row['remaining_duration']-1);$row['last_duration_boundary']=$op['boundary'];}
                if($op['tick'])$row['last_tick_boundary']=$op['boundary'];
                if($decrement&&$row['remaining_duration']===0){$row['stacks']=0;$row['status']='removed';}
                self::bump($row);$events[]=['type'=>'status_duration','id'=>$id,'remaining'=>$row['remaining_duration'],'status'=>$row['status']];
            }unset($row);
        }elseif($kind==='status_combat_end'){
            self::fields($op,['kind','encounter_id']);self::id($op['encounter_id']);
            foreach($state['status_instances'] as $id=>&$row)if($row['status']==='active'&&$row['spec']['scope']==='combat'&&$row['encounter_id']===$op['encounter_id']){
                $row['stacks']=0;$row['status']='removed';self::bump($row);$events[]=['type'=>'status_combat_ended','id'=>(string)$id];
            }unset($row);
        }else self::fail('status_operation_unsupported');
    }
    /** S1.1–S1.3: applicability only. Never creates an instance or executes status payloads. */
    public static function status(array $definition,array $event,?array $roll=null,string $mode='preview'): AF_ACS_StatusEvaluation
    {
        $result=['effect_definition_ref'=>$definition['effect_definition_ref']??null,'status_definition_ref'=>$definition['status_definition_ref']??null,
            'source'=>$event['source']??null,'target'=>$event['target']??null,'trigger'=>$definition['trigger']??null,
            'immunity'=>null,'P_status'=>null,'roll_ref'=>$event['roll_ref']??null,'roll_used'=>false,'success'=>null,'supported'=>false,'reasons'=>[],
            'trace'=>['version'=>'acs-status-evaluation-1','numeric_policy'=>AF_ACS_Numeric::POLICY,'persistent'=>false]];
        try {
            if(!in_array($mode,['preview','seal'],true)||($mode==='preview'&&$roll!==null))self::fail('status_mode_invalid');
            self::fields($definition,['effect_type','effect_definition_ref','status_definition_ref','status_type','polarity','trigger','base_chance','probability_check']);
            if(($definition['effect_type']??null)!=='status')self::fail('status_effect_type_unsupported');
            if(!in_array($definition['trigger']??null,['on_cast','on_hit','on_damage'],true))self::fail('status_trigger_unsupported');
            $ref=$definition['effect_definition_ref']??null;
            if(!is_array($ref)||!in_array($ref['type']??null,['arpg_ability','arpg_item','arpg_bestiary'],true))self::fail('status_definition_missing');
            self::fields($ref,['type','key','version','fingerprint','effect_index']);
            self::ref(array_intersect_key($ref,array_flip(['key','version','fingerprint'])));
            AF_ACS_Numeric::integer($ref['effect_index']??null,0,2147483647);self::ref($definition['status_definition_ref']??null);
            if(!is_string($definition['status_type']??null)||!preg_match('/\A[a-zA-Z][a-zA-Z0-9_.\/-]{0,63}\z/D',$definition['status_type']))self::fail('status_definition_missing');
            if(!in_array($definition['polarity']??null,['negative','positive'],true))self::fail('status_polarity_missing');
            $b=AF_ACS_Numeric::number($definition['base_chance']??null);if($b<0||$b>100)self::fail('status_base_chance_invalid');
            self::id($event['source']??null);self::id($event['target']??null);
            if(!in_array($event['relationship']??null,['ally','self','hostile'],true))self::fail('status_relationship_missing');
            foreach(['cast_success','hit_success'] as $field)if(!is_bool($event[$field]??null))self::fail('status_event_invalid');
            $loss=self::integer($event['hp_loss']??null);
            $check=!($definition['polarity']==='positive'&&in_array($event['relationship'],['ally','self'],true));
            if(array_key_exists('probability_check',$definition)){
                if(!is_bool($definition['probability_check'])||(!$definition['probability_check']&&$check))self::fail('status_override_unsupported');
                $check=$definition['probability_check'];
            }
            $immune=false;$immunities=$event['immunities']??null;
            if(!is_array($immunities)||!array_is_list($immunities))self::fail('status_immunity_contract_unsupported');
            foreach($immunities as $scope){
                if(!is_array($scope)||count($scope)!==2||array_diff(array_keys($scope),['scope','key'])||!is_string($scope['key']??null)||$scope['key']==='')self::fail('status_immunity_contract_unsupported');
                $match=match($scope['scope']){'status_key'=>$scope['key']===$definition['status_definition_ref']['key'],
                    'status_type'=>$scope['key']===$definition['status_type'],default=>throw new AF_ACS_FormulaError('status_immunity_scope_unsupported','effects')};
                $immune=$immune||$match;
            }
            $p=100;$h=null;$r=null;
            if($check){
                $h=AF_ACS_Numeric::number($event['status_hit']??null);$r=AF_ACS_Numeric::number($event['status_resistance']??null);
                if($h<0||$r<0)self::fail('status_negative_stat');
                $p=$b;
                if($h!=0||$r!=0){
                    $delta=AF_ACS_Numeric::operation('subtract',$h,$r);$sum=AF_ACS_Numeric::operation('add',$h,$r);
                    $p=AF_ACS_Numeric::terminal(AF_ACS_Numeric::operation('add',$b,AF_ACS_Numeric::operation('divide',AF_ACS_Numeric::operation('multiply',30,$delta),$sum)),'none','number',0,100)->clamped;
                }
            }
            $gate=$event['cast_success']&&match($definition['trigger']){'on_cast'=>true,'on_hit'=>$event['hit_success'],'on_damage'=>$loss>0};
            $result['supported']=true;$result['immunity']=['immune'=>$immune];$result['P_status']=$p;
            $result['trace']+=['base_chance'=>$b,'status_hit'=>$h,'status_resistance'=>$r,'probability_check'=>$check,'trigger_satisfied'=>$gate];
            if(!$gate||$immune){$result['success']=false;$result['reasons']=[$immune?'immune':'trigger_not_satisfied'];}
            elseif(!$check){$result['success']=true;$result['roll_ref']=null;}
            elseif($mode==='seal'){
                self::statusSample($roll);if(!is_array($result['roll_ref']))self::fail('status_roll_binding_missing');
                $result['roll_used']=true;$result['success']=AF_ACS_Numeric::operation('multiply',100,$roll['u'])<$p;
                $result['reasons']=$result['success']?[]:['probability_failed'];
            }
        }catch(AF_ACS_FormulaError $e){$result['supported']=false;$result['success']=false;$result['reasons']=[$e->diagnosticCode];}
        catch(DomainException $e){$result['supported']=false;$result['success']=false;$result['reasons']=[$e->getMessage()];}
        return new AF_ACS_StatusEvaluation($result);
    }
    /** Shared sealed sample validation; no coercion, random generation or rounding. */
    public static function statusSample(?array $sample):void
    {
        if($sample===null||array_keys($sample)!==['sample_n','u']||!is_int($sample['sample_n'])||$sample['sample_n']<0||$sample['sample_n']>9007199254740991
            ||!is_float($sample['u'])||!is_finite($sample['u'])||$sample['u']!==$sample['sample_n']/9007199254740992.0)self::fail('status_roll_invalid');
    }
    private static function fail(string $code): never {throw new AF_ACS_FormulaError($code,'effects');}
    private static function id(mixed $id): string
    {
        if ((!is_string($id)&&!is_int($id)) || !preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$id)
            || (strlen((string)$id)===20 && strcmp((string)$id,'18446744073709551615')>0)) self::fail('effects_identity_invalid');
        return (string)$id;
    }
    private static function order(string|int $a,string|int $b): int {return strlen((string)$a)<=>strlen((string)$b) ?: strcmp((string)$a,(string)$b);}
    private static function fields(array $row,array $allowed): void {if(array_diff(array_keys($row),$allowed))self::fail('effects_schema_invalid');}
    private static function integer(mixed $value): int {return AF_ACS_Numeric::integer($value);}
    private static function ref(mixed $ref): void
    {
        if (!is_array($ref) || !is_string($ref['key']??null) || !preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$ref['key']) || !is_string($ref['version']??null) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D',$ref['version'])
            || !is_string($ref['fingerprint']??null) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$ref['fingerprint'])) self::fail('effects_source_invalid');
        self::fields($ref,['key','version','fingerprint']);
    }
    private static function resource(mixed $r): void
    {
        if(!is_array($r))self::fail('effects_resource_invalid');self::fields($r,['current','maximum']);
        if(self::integer($r['current']??null)>self::integer($r['maximum']??null))self::fail('effects_resource_invalid');
    }
    private static function poolId(mixed $id):string
    {
        if(is_string($id)&&preg_match('/\Anew:(?:0|[1-9][0-9]*)\z/D',$id))return $id;return self::id($id);
    }
    private static function pool(array $p,array $entities): void
    {
        self::fields($p,['source_entity_id','source_ref','scope','scope_key','targets','strength','maximum_strength','barrier_priority','applied_order','status','version','absorbs','duration','last_duration_boundary']);
        if(!isset($entities[self::id($p['source_entity_id']??null)]))self::fail('effects_entity_missing');self::ref($p['source_ref']??null);
        if(!in_array($p['scope']??null,['per_target','shared_pool'],true) || !is_string($p['scope_key']??null) || $p['scope_key']===''
            || !is_array($p['targets']??null) || !array_is_list($p['targets']) || !$p['targets'])self::fail('effects_barrier_invalid');
        $seen=[];foreach($p['targets'] as $id){$id=self::id($id);if(!isset($entities[$id])||isset($seen[$id]))self::fail('effects_barrier_invalid');$seen[$id]=true;}
        if($p['scope']==='per_target' && (count($seen)!==1 || $p['scope_key']!==(string)$p['targets'][0]))self::fail('effects_barrier_invalid');
        $strength=self::integer($p['strength']??null);$maximum=self::integer($p['maximum_strength']??null);
        if($strength>$maximum || !in_array($p['status']??null,['active','destroyed','replaced','expired'],true)
            || ($p['status']==='active' && $strength===0) || ($p['status']==='destroyed' && $strength!==0))self::fail('effects_barrier_invalid');
        AF_ACS_Numeric::integer($p['barrier_priority']??null,-AF_ACS_Numeric::MAX_INTEGER);
        if(self::integer($p['version']??null)<1)self::fail('effects_version_invalid');self::integer($p['applied_order']??null);
        if(!is_array($p['absorbs']??null)||!array_is_list($p['absorbs']))self::fail('effects_filter_unsupported');
        foreach($p['absorbs'] as $filter)if(!is_string($filter)||!in_array($filter,['physical','elemental'],true))self::fail('effects_filter_unsupported');
        if(count(array_unique($p['absorbs']))!==count($p['absorbs']))self::fail('effects_filter_unsupported');
        if(isset($p['last_duration_boundary'])&&(!is_string($p['last_duration_boundary'])||!preg_match('/\A(?:entity_turn:[1-9][0-9]*:end|round:[1-9][0-9]*:next)\z/D',$p['last_duration_boundary'])))self::fail('effects_duration_invalid');
        $d=$p['duration']??null;
        if(!is_array($d)||!in_array($d['unit']??null,['own_turn','target_turn','round'],true)||AF_ACS_Numeric::number($d['value']??null)<0)self::fail('effects_duration_invalid');
        self::fields($d,['value','unit']);
    }
    private static function state(array $state): array
    {
        self::fields($state,['schema_version','entities','groups','barriers','next_barrier_order','status_instances','next_status_order','summons','objects','objectives','awareness','awareness_attempts','perception_subjects']);
        if(($state['schema_version']??null)!==1)self::fail('effects_schema_invalid');
        foreach(['entities','groups','barriers'] as $field)if(!is_array($state[$field]??null))self::fail('effects_schema_invalid');
        foreach($state['groups'] as $id=>$group){self::id($id);if(!is_array($group))self::fail('effects_schema_invalid');self::fields($group,['ap','version']);self::resource($group['ap']??null);if(self::integer($group['version']??null)<1)self::fail('effects_version_invalid');}
        foreach($state['entities'] as $id=>$e){
            self::id($id);if(!is_array($e))self::fail('effects_schema_invalid');
            self::fields($e,['hp','max_hp','life_state','recovery_blocked','group_id','resources','source_ref','version']);self::ref($e['source_ref']??null);
            $hp=self::integer($e['hp']??null);$max=self::integer($e['max_hp']??null);
            if($hp>$max || !is_bool($e['recovery_blocked']??null)||!in_array($e['life_state']??null,['active','incapacitated','dead'],true)
                || ($e['life_state']==='active'&&($hp===0||$e['recovery_blocked']))||($e['life_state']==='dead'&&$hp!==0)
                || ($e['life_state']==='incapacitated'&&$hp>0&&!$e['recovery_blocked']))self::fail('effects_hp_state_invalid');
            if(!isset($state['groups'][self::id($e['group_id']??null)]))self::fail('effects_group_missing');
            if(self::integer($e['version']??null)<1||!is_array($e['resources']??null))self::fail('effects_resource_invalid');
            foreach($e['resources'] as $key=>$resource){if(!is_string($key)||!preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D',$key)||in_array(strtolower($key),['hp','ap'],true))self::fail('effects_resource_invalid');self::resource($resource);}
        }
        if(array_key_exists('status_instances',$state)||array_key_exists('next_status_order',$state)){
            if(!is_array($state['status_instances']??null)||self::integer($state['next_status_order']??null)<1)self::fail('status_state_invalid');
            $orders=[];foreach($state['status_instances'] as $id=>$row){if(!is_array($row))self::fail('status_state_invalid');self::statusInstance((string)$id,$row);
                if($row['applied_order']>=$state['next_status_order']||isset($orders[$row['applied_order']]))self::fail('status_order_invalid');$orders[$row['applied_order']]=true;}
        }
        foreach($state['summons']??[] as $id=>$summon){AF_ACS_Spawn::instance($summon);if((string)$id!==$summon['entity_id'])self::fail('ACS_SUMMON_IDENTITY_INVALID');}
        foreach($state['objects']??[] as $id=>$object){AF_ACS_Environment::instance($object);if((string)$id!==$object['entity_id'])self::fail('ACS_OBJECT_IDENTITY_INVALID');}
        foreach($state['objectives']??[] as $id=>$v){AF_ACS_Cooperation::instance($v);if((string)$id!==$v['entity_id'])self::fail('ACS_OBJECTIVE_STATE_INVALID');}
        foreach($state['awareness']??[] as $key=>$row){AF_ACS_Awareness::row($row);if($key!==AF_ACS_Awareness::key($row['subject_kind'],$row['subject_id'],$row['audience_kind'],$row['audience_id']))self::fail('ACS_AWARENESS_UNSUPPORTED');}
        foreach($state['awareness_attempts']??[] as $key=>$check)AF_ACS_Awareness::attempt($key,$check);
        foreach($state['perception_subjects']??[] as $subject)AF_ACS_Awareness::perception($subject['perception']);
        $next=self::integer($state['next_barrier_order']??null);
        foreach($state['barriers'] as $id=>$p){self::poolId($id);if(!is_array($p))self::fail('effects_barrier_invalid');self::pool($p,$state['entities']);if($p['applied_order']>=$next)self::fail('effects_barrier_order_invalid');}
        try{$json=json_encode($state,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);$copy=json_decode($json,true,64,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){self::fail('effects_schema_invalid');}
        if($copy!==$state)self::fail('ACS_NUMERIC_SERIALIZATION_PRECISION');
        foreach(['entities','groups','barriers'] as $field)uksort($copy[$field],self::order(...));
        return $copy;
    }
    private static function bump(array &$row): void {$row['version']=self::integer($row['version']+1);}
    private static function hp(array &$e,int $hp,string $id,array &$signals): void
    {
        $before=$e['life_state'];$changed=$e['hp']!==$hp;$e['hp']=$hp;
        if($e['life_state']!=='dead')$e['life_state']=$hp===0?'incapacitated':($e['recovery_blocked']?'incapacitated':'active');
        if($before!==$e['life_state'])$signals[]=['type'=>'life_transition','entity_id'=>$id,'from'=>$before,'to'=>$e['life_state']];
        if($hp===0&&$before==='active')$signals[]=['type'=>'stop_sustained_required','entity_id'=>$id];
        if($changed||$before!==$e['life_state'])self::bump($e);
    }
    private static function allocation(AF_ACS_DamageResult $allocation): array
    {
        $d=$allocation->serverData();
        if(($d['trace_version']??null)!==AF_ACS_Damage::VERSION||($d['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||!is_array($d['components']??null)||!array_is_list($d['components']))self::fail('effects_allocation_invalid');
        $budget=self::integer($d['integer_budget']??null);$sum=0;$previous=-1;
        foreach($d['components'] as $c){
            if(!is_array($c)||!is_int($c['source_index']??null)||$c['source_index']<=$previous||!in_array($c['damage_type']??null,['physical','elemental','true'],true))self::fail('effects_allocation_invalid');
            $previous=$c['source_index'];$sum=self::integer($sum+self::integer($c['allocated']??null));
        }
        if($budget!==$sum||($d['allocation_sum']??null)!==$sum)self::fail('effects_allocation_invalid');return $d;
    }
    private static function payCosts(array &$v,?string $actor,array $costs,bool $sacrifice,array &$events,array &$signals):void
    {
        foreach($costs as $cost){
            if(!is_array($cost))self::fail('effects_cost_invalid');self::fields($cost,['kind','resource','amount']);$amount=self::integer($cost['amount']??null);
            $e=&$v['entities'][$actor];$kind=$cost['kind']??null;
            if($kind==='hp'){
                if(array_key_exists('resource',$cost))self::fail('effects_cost_invalid');
                if($amount>$e['hp']||(!$sacrifice&&$e['hp']-$amount<1))self::fail('effects_cost_unaffordable');
                self::hp($e,$e['hp']-$amount,$actor,$signals);
            } elseif($kind==='ap'){
                if(array_key_exists('resource',$cost))self::fail('effects_cost_invalid');$g=&$v['groups'][$e['group_id']];
                if($amount>$g['ap']['current'])self::fail('effects_cost_unaffordable');$g['ap']['current']-=$amount;if($amount)self::bump($g);unset($g);
            } elseif($kind==='resource'){
                $key=$cost['resource']??null;if(!is_string($key)||!isset($e['resources'][$key]))self::fail('effects_resource_invalid');
                if($amount>$e['resources'][$key]['current'])self::fail('effects_cost_unaffordable');$e['resources'][$key]['current']-=$amount;if($amount)self::bump($e);
            } else self::fail('effects_cost_invalid');
            $events[]=['type'=>'cost_paid','entity_id'=>$actor,'kind'=>$kind,'resource'=>$cost['resource']??null,'amount'=>$amount,'is_damage'=>false];unset($e);
        }
    }
    /** Costs first, then effects in the retained action/target/component order. Failure returns no state. */
    public static function simulate(array $state,array $action): AF_ACS_EffectsResult
    {
        $v=self::state($state);$dependencies=$v;$events=[];$signals=[];
        self::interruptSustained($v,$events,'state_check');
        self::fields($action,['actor_id','costs','hp_cost_policy','effects']);
        foreach(['costs','effects'] as $field)if(!is_array($action[$field]??null)||!array_is_list($action[$field]))self::fail('effects_action_invalid');
        $actor=isset($action['actor_id'])?self::id($action['actor_id']):null;
        if(($actor!==null&&(!isset($v['entities'][$actor])||$v['entities'][$actor]['life_state']!=='active'))||($action['costs']&&$actor===null))self::fail('effects_actor_unavailable');
        $sacrifice=false;
        if(array_key_exists('hp_cost_policy',$action)){
            $p=$action['hp_cost_policy'];if(!is_array($p)||!is_bool($p['allow_zero']??null))self::fail('effects_cost_policy_invalid');
            self::fields($p,['allow_zero','source_ref']);self::ref($p['source_ref']??null);$sacrifice=$p['allow_zero'];
        }
        self::payCosts($v,$actor,$action['costs'],$sacrifice,$events,$signals);
        self::interruptSustained($v,$events,'action_cost');
        foreach($action['effects'] as $op){
            if(!is_array($op)||!is_string($op['kind']??null))self::fail('effects_operation_invalid');$kind=$op['kind'];
            if($kind==='object_damage'){
                self::fields($op,['kind','entity_id','expected_version','amount','damage_type']);$id=self::id($op['entity_id']??null);
                if(!isset($v['objects'][$id]))self::fail('ACS_OBJECT_UNAVAILABLE');$old=$v['objects'][$id];
                $v['objects'][$id]=AF_ACS_Environment::damage($old,$op['expected_version'],$op['amount'],$op['damage_type']);
                $events[]=['type'=>'object.damage','entity_id'=>$id,'before'=>$old,'after'=>$v['objects'][$id]];
            }elseif($kind==='object_operation'){
                self::fields($op,['kind','entity_id','operation','expected_version','amount','damage_type']);$id=self::id($op['entity_id']??null);
                if(!isset($v['objects'][$id]))self::fail('ACS_OBJECT_UNAVAILABLE');
                $old=$v['objects'][$id];$v['objects'][$id]=AF_ACS_Environment::operation($old,$op['operation'],$op['expected_version'],$op['amount']??null,$op['damage_type']??null);
                $events[]=['type'=>'object.'.$op['operation'],'entity_id'=>$id,'before'=>$old,'after'=>$v['objects'][$id]];
            }elseif($kind==='status_upkeep'){self::upkeep($v,$op,$events,$signals);}
            elseif(in_array($kind,['status_apply','status_remove','status_combat_end','status_duration_boundary'],true)){
                self::statusOperation($v,$op,$events);
            }elseif($kind==='damage'){
                self::fields($op,['kind','targets']);if(!is_array($op['targets']??null)||!array_is_list($op['targets']))self::fail('effects_operation_invalid');$seen=[];
                foreach($op['targets'] as $target){
                    if(!is_array($target))self::fail('effects_operation_invalid');self::fields($target,['entity_id','allocation']);$id=self::id($target['entity_id']??null);
                    if(isset($seen[$id]))self::fail('effects_target_duplicate');$seen[$id]=true;
                    if(!isset($v['entities'][$id])||$v['entities'][$id]['life_state']==='dead')self::fail('effects_target_unavailable');
                    if(!(($target['allocation']??null) instanceof AF_ACS_DamageResult))self::fail('effects_allocation_invalid');$d=self::allocation($target['allocation']);
                    foreach($d['components'] as $c){
                        $left=$c['allocated'];$absorbed=0;$queue=array_keys($v['barriers']);
                        usort($queue,static fn($a,$b)=>$v['barriers'][$a]['barrier_priority']<=>$v['barriers'][$b]['barrier_priority'] ?: $v['barriers'][$a]['applied_order']<=>$v['barriers'][$b]['applied_order'] ?: self::order($a,$b));
                        if($c['damage_type']!=='true')foreach($queue as $poolId){
                            $p=&$v['barriers'][$poolId];
                            if(!$left||$p['status']!=='active'||!in_array($id,array_map('strval',$p['targets']),true)||!in_array($c['damage_type'],$p['absorbs'],true)){unset($p);continue;}
                            $take=min($left,$p['strength']);$p['strength']-=$take;$left-=$take;$absorbed+=$take;if($take)self::bump($p);
                            if($p['strength']===0)$p['status']='destroyed';
                            $events[]=['type'=>'barrier_absorb','pool_id'=>(string)$poolId,'entity_id'=>$id,'source_index'=>$c['source_index'],'amount'=>$take];unset($p);
                        }
                        $e=&$v['entities'][$id];$loss=min($left,$e['hp']);self::hp($e,$e['hp']-$loss,$id,$signals);unset($e);
                        $events[]=['type'=>'damage','entity_id'=>$id,'source_index'=>$c['source_index'],'damage_type'=>$c['damage_type'],'allocated'=>$c['allocated'],'absorbed'=>$absorbed,'hp_loss'=>$loss,'overkill'=>$left-$loss];
                    }
                    $events[]=['type'=>'strike_budget','entity_id'=>$id,'integer_budget'=>$d['integer_budget']];
                }
            } elseif($kind==='heal'||$kind==='set_max_hp'){
                self::fields($op,['kind','entity_id','amount']);$id=self::id($op['entity_id']??null);$amount=self::integer($op['amount']??null);
                if(!isset($v['entities'][$id]))self::fail('effects_entity_missing');$e=&$v['entities'][$id];
                if($kind==='heal'){
                    if($e['life_state']==='dead')self::fail('effects_dead_heal_forbidden');$recovery=min($amount,$e['max_hp']-$e['hp']);self::hp($e,$e['hp']+$recovery,$id,$signals);
                    $events[]=['type'=>'heal','entity_id'=>$id,'calculated'=>$amount,'effective_recovery'=>$recovery,'overheal'=>$amount-$recovery];
                } else {
                    $old=$e['max_hp'];$e['max_hp']=$amount;$hpVersion=$e['version'];self::hp($e,min($e['hp'],$amount),$id,$signals);if($old!==$amount&&$hpVersion===$e['version'])self::bump($e);
                    $events[]=['type'=>'max_hp_changed','entity_id'=>$id,'before'=>$old,'after'=>$amount,'current_hp'=>$e['hp']];
                }unset($e);
            } elseif($kind==='barrier_apply'){
                self::fields($op,['kind','pool_id','pool']);$id=self::poolId($op['pool_id']??null);if(isset($v['barriers'][$id])||!is_array($op['pool']??null))self::fail('effects_barrier_identity_conflict');
                $p=$op['pool'];self::fields($p,['source_entity_id','source_ref','scope','scope_key','targets','strength','maximum_strength','barrier_priority','absorbs','duration']);
                $p['barrier_priority']=array_key_exists('barrier_priority',$p)?$p['barrier_priority']:0;$p['status']=($p['strength']??null)===0?'destroyed':'active';$p['version']=1;$p['applied_order']=$v['next_barrier_order'];self::pool($p,$v['entities']);
                try{$p=json_decode(json_encode($p,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail('effects_barrier_invalid');}
                $matches=[];foreach($v['barriers'] as $oldId=>$old)if($old['status']==='active'&&(string)$old['source_entity_id']===(string)$p['source_entity_id']&&$old['source_ref']['key']===$p['source_ref']['key']&&$old['scope']===$p['scope']&&$old['scope_key']===$p['scope_key'])$matches[]=$oldId;
                if(count($matches)>1)self::fail('effects_barrier_stack_unsupported');foreach($matches as $oldId){$v['barriers'][$oldId]['status']='replaced';self::bump($v['barriers'][$oldId]);}
                $v['next_barrier_order']=self::integer($v['next_barrier_order']+1);$v['barriers'][$id]=$p;$events[]=['type'=>'barrier_applied','pool_id'=>$id,'replaced_ids'=>array_map('strval',$matches),'applied_order'=>$p['applied_order']];
            } elseif($kind==='barrier_restore'){
                self::fields($op,['kind','pool_id','amount']);$id=self::poolId($op['pool_id']??null);
                if(!isset($v['barriers'][$id])||$v['barriers'][$id]['status']!=='active')self::fail('effects_barrier_restore_forbidden');$p=&$v['barriers'][$id];
                $room=$p['maximum_strength']-$p['strength'];$amount=array_key_exists('amount',$op)?self::integer($op['amount']):$room;$restore=min($amount,$room);$p['strength']+=$restore;if($restore)self::bump($p);unset($p);
                $events[]=['type'=>'barrier_restored','pool_id'=>$id,'restored'=>$restore,'excess'=>$amount-$restore];
            } else self::fail('effects_operation_unsupported');
            self::interruptSustained($v,$events,'effect:'.$kind);
        }
        $v=self::state($v);
        return new AF_ACS_EffectsResult($v,['engine_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'events'=>$events,'signals'=>$signals,
            'ordinary_actions_require_recheck'=>array_values(array_unique(array_column(array_filter($signals,static fn($s)=>$s['type']==='stop_sustained_required'),'entity_id'))),'end_condition_check_required'=>true,'encounter_transition'=>null,'current_action_may_complete'=>true],$dependencies);
    }
    public static function attempt(array $state,array $action): array
    {
        try{return ['ready'=>true,'evaluation'=>self::simulate($state,$action),'diagnostics'=>[]];}
        catch(AF_ACS_FormulaError $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$e->diagnostic()]];}
        catch(DomainException $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$e->getMessage(),'path'=>'effects']]];}
    }
}
final class AF_ACS_StatusEvaluation implements JsonSerializable
{
    public function __construct(private readonly array $data){}
    public function serverData():array{return $this->data;}
    public function jsonSerialize():array{return ['supported'=>$this->data['supported'],'persistent'=>false];}
}
final class AF_ACS_EffectsResult implements JsonSerializable
{
    public function __construct(private readonly array $state,private readonly array $trace,private readonly array $dependencies) {}
    public function serverState(): array{return $this->state;}
    public function serverTrace(): array{return $this->trace;}
    public function serverDependencies(): array{return $this->dependencies;}
    public function jsonSerialize(): array{return ['ready'=>true,'engine_version'=>AF_ACS_Effects::VERSION,'persistent'=>false];}
}
