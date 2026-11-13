<?php
declare(strict_types=1);
require_once __DIR__.'/Effects.php';
require_once __DIR__.'/Items.php';

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
                        'status_definition_ref'=>$definition['status_definition_ref'],'action_index'=>$actionIndex]+(isset($definition['instance']['mechanical_parameters']['control_effect'])?['control_context'=>['controller_uid'=>$scope['controller_uid']??null,'base_assignment_version'=>$scope['assignments'][$event['target']]??null]]:[]) ]]);
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
    public static function interactionActorStats(array $context,string $actor):array
    {$trace=[];return self::stats($context,$actor,$trace);}
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
    /** Reactions consume the existing per-EntityTurn slot. Child effects never recurse here. */
    private static function passiveChildren(array $spec,array $context,string $source,string $attacker,string $cause,array &$state,array &$visited):array
    {
        if(empty($spec['passives']))return [];$g=$spec['passives'];$order=AF_ACS_CausalGraph::order($g['nodes'],$g['roots'],$g['max_depth']);$trace=[];
        foreach($order as $id){$visit=$cause.':passive:'.$id;if(isset($visited[$visit]))continue;$visited[$visit]=true;$n=$g['nodes'][$id];$target=$n['target']==='self'?$source:$attacker;
            if(!isset($state['entities'][$target]))self::fail('passive_target_missing');if($state['entities'][$target]['life_state']==='dead')continue;
            if($n['kind']==='heal')$op=['kind'=>'heal','entity_id'=>$target,'amount'=>$n['value']];
            else{$allocation=self::damage([['source_index'=>0,'damage_type'=>$n['damage_type'],'damage_element'=>$n['damage_element'],'raw'=>$n['value'],'after_crit'=>$n['value']]],$target,$context,[]);$op=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];}
            $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[$op]]);$state=$r->serverState();$trace[]=['causal_id'=>$visit,'cause'=>$cause,'source_ref'=>$n['source_ref'],'effects'=>$r->serverTrace(),'active_reactions_emitted'=>false];
        }return $trace;
    }
    /** The current target can change once; child Effects never re-enter this active queue. */
    private static function reactions(array $context,string $phase,int $index,string &$target,bool $hit,int $loss,string $mode,array &$state,array &$slots,array &$updates,string $attacker,array $originalTargets,bool $singleTarget,bool &$redirected,array &$visited):array
    {
        $queue=[];foreach($context['reactions']??[] as $row)if($row['status']==='active'&&$row['phase']===$phase)$queue[]=$row;
        usort($queue,static fn($a,$b)=>$a['priority']<=>$b['priority']?:$a['registered_order']<=>$b['registered_order']?:strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id']));$trace=[];
        foreach($queue as $row){$source=$row['entity_id'];$spec=$row['spec'];$protected=$row['protected_targets']??[$source];$kind=$spec['effect']['kind'];
            if(!in_array($target,$protected,true))continue;$slot=$slots[$source]??null;$reason=null;
            if($slot&&!in_array($slot['used']??null,[0,1],true))self::fail('reaction_limit_context_invalid');
            if(!$slot)$reason='source_turn_unavailable';elseif(!($row['enabled']??false))$reason='controller_unavailable';
            elseif(!isset($state['entities'][$source])||$state['entities'][$source]['life_state']!=='active')$reason='source_inactive';
            elseif($slot['used']!==0)$reason='limit_used';elseif(in_array($phase,['after_hit','after_damage'],true)&&!$hit)$reason='hit_required';
            if($reason===null){$behaviorCandidates=$context['behavior_targets'][$source]??[];if(!array_key_exists($source,$context['behavior_targets']??[]))foreach($protected as $id)$behaviorCandidates[$id]=['kind'=>$context['reaction_kinds'][$id]??'npc','relationship'=>$context['relationships'][$source][$id]??($source===$id?'self':'ally')];
                foreach($behaviorCandidates as $id=>&$candidate)if(isset($state['entities'][$id]))$candidate['active']=$state['entities'][$id]['life_state']==='active';unset($candidate);
                $b=AF_ACS_Behavior::evaluate($state['status_instances']??[],$source,$spec['ability_ref']['key'],'reaction',$behaviorCandidates,['kind'=>'allied_only'],$context['status_scope']??[]);
                if($b['status']!=='READY'||array_diff($protected,$b['legal_targets']))$reason=$b['code']??'ACS_BEHAVIOR_TARGET_BLOCKED';
            }
            if($reason===null&&$kind==='intercept'){
                if(!$singleTarget)$reason='single_target_required';elseif($redirected)$reason='redirect_already_used';
                elseif($source===$attacker||$source===$target||!in_array($source,$context['redirect_targets'][$attacker]??[],true)||($context['relationships'][$source][$target]??null)!=='ally')$reason='redirect_target_ineligible';
            }
            if($reason===null&&$kind==='intercept')try{$ability=$context['environment_ability'];if(isset($state['spatial']))AF_ACS_Spatial::check($state['spatial'],$attacker,$source,$ability['range']??null);AF_ACS_Cover::check($state,$attacker,$source,$ability);}catch(DomainException $e){if(!in_array($e->getMessage(),['ACS_COVER_BLOCKED','ACS_SPATIAL_TARGET_BLOCKED'],true))throw $e;$reason='redirect_target_unavailable';}
            if($reason===null&&$kind==='mass_defense')foreach($protected as $id)if(!isset($state['entities'][$id])||$state['entities'][$id]['life_state']==='dead'||!in_array($context['relationships'][$source][$id]??null,['self','ally'],true))$reason='protected_target_unavailable';
            $input=['actor.hp'=>$state['entities'][$source]['hp']??0,'actor.max_hp'=>$state['entities'][$source]['max_hp']??0,'actor.life_state'=>$state['entities'][$source]['life_state']??'dead','actor.kind'=>$context['reaction_kinds'][$source]??null,'event.phase'=>$phase,'event.hit_success'=>$hit,'event.hp_loss'=>$loss];
            if($reason===null&&!AF_ACS_Effects::reactionCondition($spec['condition'],$input))$reason='condition_false';
            $cause=$context['reaction_causes'][$index]??($mode==='preview'?'preview-action:'.$index:null);
            if(!is_string($cause)||$mode==='seal'&&!preg_match('/\Asealed-action:[1-9][0-9]{0,19}\z/D',$cause))self::fail('reaction_causal_identity_missing');
            $key='reaction:'.$row['id'].':'.$cause.':'.$phase;$roll=$context['reaction_rolls'][$key]??null;$entry=['id'=>$row['id'],'entity_id'=>$source,'phase'=>$phase,'priority'=>$row['priority'],'registered_order'=>$row['registered_order'],'causal_key'=>$key,'executed'=>false,'reason'=>$reason,'P'=>$spec['probability'],'roll_ref'=>null];
            if($reason===null){$costs=$spec['costs'];if($kind==='mass_defense')foreach($protected as $_)array_push($costs,...$spec['per_target_costs']);
                $affordable=AF_ACS_Effects::attempt($state,['actor_id'=>$source,'costs'=>$costs,'effects'=>[]]);
                if(!$affordable['ready']){$code=$affordable['diagnostics'][0]['code'];if(!in_array($code,['effects_cost_unaffordable','effects_resource_invalid','effects_actor_unavailable'],true))self::fail($code);$entry['reason']=$code;}
                else{
                    if(!empty($spec['passives']))AF_ACS_CausalGraph::order($spec['passives']['nodes'],$spec['passives']['roots'],$spec['passives']['max_depth']);
                    if($spec['probability']<100&&$mode==='seal'&&!$roll)self::fail('reaction_roll_pending');
                    $beforePools=array_keys($state['barriers']);$attempt=AF_ACS_Effects::reactionAttempt($state,$source,$spec,(($context['status_scope']??[])+($context['control_context']??[])),$roll['sample']??null,$mode,$protected);$entry['executed']=$attempt['executed'];$entry['reason']=$attempt['reason'];
                    if($attempt['executed']){$state=$attempt['state'];$slots[$source]['used']=1;$entry['effects']=$attempt['trace'];$entry['effect_ref']=$spec['effect_ref'];$entry['roll_ref']=$roll===null?null:['id'=>$roll['id'],'causal_key'=>$key,'check_kind'=>'reaction','roll_index'=>0];
                        if($kind==='intercept'){$entry['original_target']=$target;$entry['redirect_success']=$attempt['redirect_success'];if($attempt['redirect_success']){$target=$source;$redirected=true;}$entry['resolved_target']=$target;}
                        $newPools=array_values(array_diff(array_keys($state['barriers']),$beforePools));$updates[$row['id']]=['before_version'=>$row['reaction_version'],'after_version'=>$row['reaction_version']+1,'execution'=>['causal_key'=>$key,'entity_turn_id'=>$slot['id'],'phase'=>$phase,'roll_ref'=>$entry['roll_ref']]+($newPools?['pool_ids'=>$newPools]:[])];
                        $entry['passives']=self::passiveChildren($spec,$context,$source,$attacker,$key,$state,$visited);
                    }
                }
            }$trace[]=$entry;
        }return $trace;
    }
    public static function targetSummary(array $step):array
    {
        $out=[];if(isset($step['prepared_action']))$out['prepared_action']=$step['prepared_action'];if(isset($step['cover']))$out['cover']=$step['cover'];if(isset($step['spatial']))$out['spatial']=$step['spatial'];if(isset($step['cooperation']))$out['cooperation']=$step['cooperation'];if(isset($step['object_interaction']))$out['interaction']=array_intersect_key($step['object_interaction'],array_flip(['entity_id','key','outcome','progress']));if(in_array($step['scenario'],['search','share','environment'],true))$out['operation']=$step['scenario'];if (isset($step['item_action'])) $out['item']=array_intersect_key($step['item_action'],array_flip(['operation','item_id','qty']));
        if(isset($step['spawns']))$out['spawn_hash']=hash('sha256',serialize($step['spawns']));
        foreach(['original_target','resolved_target'] as $k)if(isset($step[$k]))$out[$k]=$step[$k];
        if(isset($step['strikes']))foreach($step['strikes'] as $strike)$out['strikes'][]=array_intersect_key($strike,array_flip(['original_target','resolved_target','scenario','rolls','probabilities']))+['damage'=>$strike['allocation']['integer_budget']];return $out;
    }
    public static function reactionHash(AF_ACS_ResolverResult $result,array $context):?string
    {
        if(empty($context['reactions']))return null;
        $traces=[];foreach($result->serverPlan()['actions'] as $action)$traces[]=isset($action['strikes'])?array_column($action['strikes'],'reactions'):($action['reactions']??[]);
        return hash('sha256',json_encode(['rolls'=>$context['reaction_rolls']??[],'limits'=>$result->serverPlan()['reaction_limits'],'updates'=>$result->serverPlan()['reaction_updates'],'traces'=>$traces,'barriers'=>$result->serverState()['barriers'],'resources'=>array_map(static fn($e)=>$e['resources'],$result->serverState()['entities'])],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }
    public static function resolve(array $context,array $orderedActions,array $fixedRandomTape=[],string $mode='preview'): AF_ACS_ResolverResult
    {
        if(array_diff(array_keys($context),['schema_version','state','stats','defenses','element_catalog','turns','encounter_status','abilities','allowed_targets','modifier_stages','source_snapshots','penetration','reflections','relationships','status_immunities','status_scope','entity_end_boundaries','reactions','reaction_turns','reaction_kinds','reaction_causes','reaction_rolls','redirect_targets','item_specs','item_state','spawn_counts','spawn_sources','control_context','behavior_targets','perception_actors','action_abilities']))self::fail('resolver_context_unsupported');
        if(!in_array($mode,['preview','seal'],true))self::fail('resolver_mode_unsupported');
        if($mode==='preview'&&$fixedRandomTape!==[])self::fail('preview_random_tape_forbidden');
        if($mode==='seal'&&(!array_is_list($fixedRandomTape)||count($fixedRandomTape)!==count($orderedActions)||!$fixedRandomTape))self::fail('resolver_tape_invalid');
        if(($context['schema_version']??null)!==1||($context['encounter_status']??null)!=='active'||!array_is_list($orderedActions)||!$orderedActions)self::fail('resolver_context_invalid');
        $checked=AF_ACS_Effects::simulate($context['state'],['costs'=>[],'effects'=>[]]);$state=$checked->serverState();$initial=$checked->serverDependencies();$initialTrace=$checked->serverTrace();$redirectCandidates=$context['redirect_targets']??[];$plans=[];$spawnCounts=$context['spawn_counts']??[];$spawnPlan=[];$itemState=$context['item_state']??null;$slots=$context['reaction_turns']??[];$reactionUpdates=[];$visited=[];
        foreach($orderedActions as $i=>$action){
            if(!is_array($action)||array_diff(array_keys($action),['actor_id','ability_key','targets','scenario']))self::fail('resolver_action_invalid');
            $actor=(string)($action['actor_id']??'');$key=$action['ability_key']??null;$targets=$action['targets']??null;$scenario=$action['scenario']??null;
            if(!is_string($key)||!is_array($targets)||!array_is_list($targets)||!$targets||count(array_unique($targets))!==count($targets)||($mode==='preview'&&!in_array($scenario,['hit','crit','miss'],true))||($mode==='seal'&&array_key_exists('scenario',$action)))self::fail('resolver_action_unsupported');
            $turn=$context['turns'][$actor]??null;$ability=$context['action_abilities'][$i]??($context['abilities'][$actor][$key]??null);
            if(!is_array($ability)||!is_array($turn)||($turn['status']??null)!=='pending'||($turn['started']??null)!==true||($turn['group_id']??null)!==($state['entities'][$actor]['group_id']??null))self::fail('resolver_turn_or_source_invalid');
            if(!AF_ACS_Effects::ordinaryActionAllowed($state,$actor))self::fail('ACS_STATUS_CONTROL_BLOCKED');
            $behaviorCandidates=$context['behavior_targets'][$actor]??[];
            if(!array_key_exists($actor,$context['behavior_targets']??[]))foreach($context['allowed_targets'][$actor]??[] as $id)$behaviorCandidates[$id]=['relationship'=>$context['relationships'][$actor][$id]??'hostile','kind'=>$context['reaction_kinds'][$id]??'npc'];
            foreach($behaviorCandidates as $id=>&$candidate)if(isset($state['entities'][$id]))$candidate['active']=$state['entities'][$id]['life_state']==='active';unset($candidate);
            AF_ACS_Behavior::check($state['status_instances']??[],$actor,$key,isset($context['item_specs'][$i])?($context['item_specs'][$i]['operation']==='consume'?'item_consume':'equipment_change'):(isset($ability['spawns'])?'spawn':'ordinary'),$targets,$behaviorCandidates,$ability['target_policy']??['kind'=>isset($ability['spawns'])||isset($context['item_specs'][$i])?'self_only':'any_entity'],($context['status_scope']??[])+['minimum_targets'=>$ability['target_count']['min']??1]);
            if(!isset($ability['cover_action'])&&!isset($ability['spatial_action'])&&isset($state['spatial']))foreach($targets as $rangeTarget)AF_ACS_Spatial::check($state['spatial'],$actor,$rangeTarget,$ability['range']??null);
            if(isset($ability['prepared_action'])){
                require_once __DIR__.'/PreparedAction.php';
                $contract=AF_ACS_PreparedAction::contract($ability['prepared_action']);
                if(($ability['costs'][0]??null)!==['kind'=>'ap','amount'=>$contract['ap_cost']])self::fail('ACS_PREPARED_UNSUPPORTED');
                if(count($orderedActions)!==1||count($targets)!==1||!isset($state['entities'][$targets[0]])||!in_array($targets[0],$context['allowed_targets'][$actor]??[],true))self::fail('ACS_PREPARED_UNSUPPORTED');
                $target=$targets[0];if($target!==$actor&&!AF_ACS_Awareness::targetable('identified',$state['perception_subjects']['entity:'.$target]['perception']['stealth']??null,$state['status_instances']??[],$target))self::fail('resolver_target_forbidden');
                if(!AF_ACS_Control::allowed($ability['target_policy'],$context['relationships'][$actor][$target]??'', $context['reaction_kinds'][$target]??''))self::fail('ACS_TARGET_POLICY_DENIED');
                AF_ACS_Cover::check($state,$actor,$target,$ability);
                if($mode==='seal'&&$fixedRandomTape[$i]!==AF_ACS_PreparedAction::TAPE)self::fail('resolver_tape_invalid');
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'prepared','rolls'=>null,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],
                    'probabilities'=>['P_hit'=>null,'P_crit'=>null,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace,'prepared_action'=>$contract];continue;
            }
            if(isset($ability['cover_action'])){
                $op=$ability['cover_action'];if($targets!==[$actor])self::fail('resolver_target_forbidden');$cost=AF_ACS_Cover::assign($state,$op['id'],$actor,$op['kind']==='enter');$costs=[['kind'=>'ap','amount'=>$cost]];$trace=[];$state=self::apply($state,[],$actor,$costs,$trace);$rolls=null;
                if($mode==='seal'){$t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$rolls=$t+['hit_used'=>false,'crit_used'=>false];}
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'cover','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$costs,'probabilities'=>['P_hit'=>null,'P_crit'=>null,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace,'cover'=>['operation'=>$op['kind']]];continue;
            }
            if(isset($ability['spatial_action'])||isset($ability['spatial_effect'])){
                foreach($targets as $spatialTarget)if($spatialTarget!==$actor&&!AF_ACS_Awareness::targetable('identified',$state['perception_subjects']['entity:'.$spatialTarget]['perception']['stealth']??null,$state['status_instances']??[],$spatialTarget))self::fail('resolver_target_forbidden');
                $s=&$state['spatial'];$op=$ability['spatial_action']??$ability['spatial_effect'];$kind=$op['kind'];$mover=$actor;
                if(isset($ability['spatial_action'])){
                    if($kind==='move'){if($targets!==[$actor])self::fail('resolver_target_forbidden');$sourceZone=AF_ACS_Spatial::position($s,$actor);if($sourceZone!==null)AF_ACS_Cover::edge($state,$sourceZone,$op['id'],true);$cost=AF_ACS_Spatial::move($s,$actor,$op['id']);AF_ACS_Cover::moved($state,$actor);}
                    else{if($targets!==[$op['id']]||($state['entities'][$op['id']]['life_state']??null)!=='active')self::fail('resolver_target_forbidden');$cost=AF_ACS_Spatial::contact($s,$actor,$op['id'],$kind==='engage');}
                    $costs=[['kind'=>'ap','amount'=>$cost]];
                }else{
                    AF_ACS_Cover::check($state,$actor,$targets[0],$ability);
                    if(count($targets)!==1||($state['entities'][$targets[0]]['life_state']??null)!=='active')self::fail('resolver_target_forbidden');$mover=$targets[0];$destination=AF_ACS_Spatial::destination($s,$op['destination']);$sourceZone=AF_ACS_Spatial::position($s,$mover);if($kind!=='teleport'&&$sourceZone!==null)AF_ACS_Cover::edge($state,$sourceZone,$destination,true);AF_ACS_Spatial::move($s,$mover,$destination,true,$kind==='teleport');AF_ACS_Cover::moved($state,$mover);$costs=$ability['costs'];
                }unset($s);
                $rolls=null;if($mode==='seal'){$t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$rolls=$t+['hit_used'=>false,'crit_used'=>false];}
                $trace=[];$state=self::apply($state,[],$actor,$costs,$trace);
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'spatial','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$costs,'probabilities'=>['P_hit'=>null,'P_crit'=>null,'crit_rate'=>null,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace,'spatial'=>['operation'=>$kind,'entity_id'=>$mover]];continue;
            }
            if (isset($context['item_specs'][$i])) {
                $spec=$context['item_specs'][$i];
                if (($ability['item_action']??null)!==true || $ability['source_ref']!==$spec['pin'] || !is_array($itemState)) self::fail('ACS_ITEM_DEPENDENCY_CONFLICT');
                $rolls=null;
                if ($mode==='seal') {
                    $t=$fixedRandomTape[$i];
                    if (array_keys($t)!==['rng_version','hit','crit'] || $t['rng_version']!=='uniform53-v1') self::fail('resolver_tape_invalid');
                    AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);
                    $rolls=$t+['hit_used'=>false,'crit_used'=>false];
                }
                $r=AF_ACS_Items::apply($spec,$actor,$targets,$state,$context['stats'],$itemState);
                $state=$r['state'];$context['stats']=$r['stats'];$itemState=$r['virtual'];
                $context['defenses'][$actor]['def']=$context['stats'][$actor]['def'];
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'used','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$r['costs'],
                    'probabilities'=>['P_hit'=>100,'P_crit'=>0,'crit_rate'=>0,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'item_action'=>$spec,'modifier_trace'=>$r['modifier_trace'],'effects_trace'=>[$r['trace']]];
                continue;
            }
            if(isset($ability['cooperative_action'])||isset($ability['object_interaction']))foreach($targets as $environmentTarget)AF_ACS_Cover::check($state,$actor,$environmentTarget,$ability);
            if(isset($ability['cooperative_action'])){
                $binding=$ability['cooperative_action'];$id=$binding['entity_id'];$selection=$binding['selection'];
                if($targets!==[$id]||!in_array($id,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
                $v=$state['objectives'][$id]??throw new DomainException('ACS_OBJECTIVE_BLOCKED');if($v['id']!==$binding['objective_id'])self::fail('ACS_OBJECTIVE_BLOCKED');
                $modifierTrace=[];$actorStats=self::stats($context,$actor,$modifierTrace);AF_ACS_Cooperation::eligible($v,$state['objects'][$id],$actor,$context['reaction_kinds'][$actor],$actorStats,$context['abilities'][$actor],$selection);
                if($selection['operation']==='assist'&&($state['entities'][$selection['recipient']]['life_state']??null)!=='active')self::fail('ACS_OBJECTIVE_BLOCKED');
                if($selection['operation']==='assist'){$recipient=$selection['recipient'];if(!in_array($context['relationships'][$actor][$recipient]??null,['self','ally','allied'],true)||!AF_ACS_Awareness::targetable('identified',$state['perception_subjects']['entity:'.$recipient]['perception']['stealth']??null,$state['status_instances']??[],$recipient))self::fail('ACS_OBJECTIVE_BLOCKED');if(isset($state['spatial']))AF_ACS_Spatial::check($state['spatial'],$actor,$recipient,$ability['range']??null);AF_ACS_Cover::check($state,$actor,$recipient,$ability);}
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);$rolls=null;$outcome=null;$completed=false;
                if($mode==='seal'){
                    $t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$spec=$v['definition']['action'];$rolls=$t+['hit_used'=>$selection['operation']!=='assist'&&in_array('roll',$spec['check']['bindings'],true),'crit_used'=>false];$score=null;
                    if($selection['operation']!=='assist'){$dynamic=[];foreach($spec['check']['bindings'] as $name=>$bindingValue)$dynamic[$name]=match($bindingValue){'roll'=>$t['hit']['u'],'progress'=>$v['progress'],'assist'=>AF_ACS_Cooperation::assistance($v,$actor),default=>$actorStats[substr($bindingValue,5)]??throw new DomainException('ACS_OBJECTIVE_BLOCKED')};$f=$spec['check']['formula'];$formulaTrace=[];$score=(float)self::formula(['formula'=>$f,'pin'=>array_intersect_key($f,array_flip(['key','version','fingerprint']))],$dynamic,'interaction_score',false,$formulaTrace);}
                    $cause=$context['reaction_causes'][$i]??throw new DomainException('ACS_INTERACTION_CAUSE_REQUIRED');$r=AF_ACS_Cooperation::apply($v,$actor,$selection,$cause,$score);$state['objectives'][$id]=$r['objective'];$outcome=$r['outcome'];$completed=$r['objective']['completion_cause']===$cause;
                    $nodes=[$cause=>['children'=>[]]];foreach($r['completion'] as $effectIndex=>$effect){$child=$cause.':completion:'.$effectIndex;$nodes[$cause]['children'][]=$child;$nodes[$child]=['children'=>[]];if($effect['kind']==='heal_finisher')$state=self::apply($state,[['kind'=>'heal','entity_id'=>$actor,'amount'=>$effect['amount']]],null,[],$trace);else{$object=$state['objects'][$id];if(in_array($object['status'],['expired','destroyed'],true))self::fail('ACS_OBJECTIVE_BLOCKED');if($object['status']!==$effect['status']){$object['status']=$object['interaction']['activation']=$effect['status'];++$object['object_version'];AF_ACS_Environment::instance($object);$state['objects'][$id]=$object;}}}AF_ACS_CausalGraph::order($nodes,[$cause],2);
                }
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>$outcome??'cooperation_preview','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],'probabilities'=>['P_hit'=>null,'P_crit'=>null,'crit_rate'=>null,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace,
                    'cooperation'=>['entity_id'=>$id,'operation'=>$selection['operation'],'outcome'=>$outcome,'progress'=>$mode==='seal'?$state['objectives'][$id]['progress']:null,'cap'=>$v['definition']['cap'],'completed'=>$completed]];continue;
            }
            if(isset($ability['object_interaction'])){
                $binding=$ability['object_interaction'];$id=$binding['entity_id'];if($targets!==[$id]||!in_array($id,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
                $v=$state['objects'][$id]??throw new DomainException('resolver_target_forbidden');$spec=AF_ACS_ObjectInteraction::action($v,$binding['key']);if($spec!==$binding['action'])self::fail('ACS_SEAL_PIN_CHANGED');
                $modifierTrace=[];$actorStats=self::stats($context,$actor,$modifierTrace);AF_ACS_ObjectInteraction::eligible($v,$spec,$actor,$context['reaction_kinds'][$actor],$actorStats,$context['abilities'][$actor]);
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);$rolls=null;$outcome=null;$allocation=['integer_budget'=>0,'components'=>[]];
                if($mode==='seal'){
                    $t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$rolls=$t+['hit_used'=>in_array('roll',$spec['check']['bindings'],true),'crit_used'=>false];
                    $dynamic=[];foreach($spec['check']['bindings'] as $name=>$bindingValue)$dynamic[$name]=match($bindingValue){'roll'=>$t['hit']['u'],'progress'=>$v['interaction']['typed']['progress']??throw new DomainException('ACS_INTERACTION_UNSUPPORTED'),default=>$actorStats[substr($bindingValue,5)]??throw new DomainException('ACS_INTERACTION_BLOCKED')};
                    $f=$spec['check']['formula'];$formulaTrace=[];$score=self::formula(['formula'=>$f,'pin'=>array_intersect_key($f,array_flip(['key','version','fingerprint']))],$dynamic,'interaction_score',false,$formulaTrace);
                    $r=AF_ACS_ObjectInteraction::outcome($v,$spec,$actor,$context['reaction_causes'][$i]??throw new DomainException('ACS_INTERACTION_CAUSE_REQUIRED'),$score);$state['objects'][$id]=$r['object'];$outcome=$r['outcome'];
                    foreach($r['effects'] as $effectIndex=>$effect){if($effect['kind']==='heal_actor')$op=['kind'=>'heal','entity_id'=>$actor,'amount'=>$effect['amount']];else{$damage=self::damage([['source_index'=>$effectIndex,'damage_type'=>$effect['damage_type'],'damage_element'=>null,'raw'=>$effect['amount'],'after_crit'=>$effect['amount']]],$actor,$context,[]);$allocated=$damage->serverData();$allocation['integer_budget']+=$allocated['integer_budget'];$allocation['components']=[...$allocation['components'],...$allocated['components']];$op=['kind'=>'damage','targets'=>[['entity_id'=>$actor,'allocation'=>$damage]]];}$state=self::apply($state,[$op],null,[],$trace);}
                }
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>$outcome??'interaction_preview','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],'probabilities'=>['P_hit'=>null,'P_crit'=>null,'crit_rate'=>null,'can_crit'=>false],'allocation'=>$allocation,'effects_trace'=>$trace,'object_interaction'=>['entity_id'=>$id,'key'=>$spec['key'],'outcome'=>$outcome,'progress'=>$mode==='seal'?$state['objects'][$id]['interaction']['typed']['progress']:null,'tool'=>$binding['tool']]];continue;
            }
            if(isset($ability['hidden_mass_effect'])){
                if($targets!==[$actor]||!in_array($actor,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
                $rolls=null;if($mode==='seal'){$t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$rolls=$t+['hit_used'=>false,'crit_used'=>false];}
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);$state=AF_ACS_Environment::mass($state,$ability['hidden_mass_effect'],$actor,$ability);
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'environment','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],'probabilities'=>['P_hit'=>100,'P_crit'=>0,'crit_rate'=>0,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace];continue;
            }
            if(isset($ability['awareness_action'])){
                if($targets!==[$actor]||!in_array($actor,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
                $rolls=null;$sample=null;$spec=$ability['awareness_action'];
                if($mode==='seal'){$t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$sample=$t['hit'];$rolls=$t+['hit_used'=>$spec['operation']==='search','crit_used'=>false];}
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);
                $cause=$mode==='seal'?($context['reaction_causes'][$i]??throw new DomainException('ACS_AWARENESS_CAUSE_REQUIRED')):'preview:'.$i;
                $state=AF_ACS_Awareness::action($state,$state['perception_subjects']??[],$context['perception_actors'][$actor],$spec,$cause,$sample,$mode);
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>$spec['operation'],'rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],
                    'probabilities'=>['P_hit'=>$spec['chance'],'P_crit'=>0,'crit_rate'=>0,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'effects_trace'=>$trace];continue;
            }
            if(isset($ability['spawns'])){
                require_once __DIR__.'/Spawn.php';
                if($targets!==[$actor] || !in_array($actor,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
                $rolls=null;if($mode==='seal'){
                    $t=$fixedRandomTape[$i];if(array_keys($t)!==['rng_version','hit','crit']||$t['rng_version']!=='uniform53-v1')self::fail('resolver_tape_invalid');
                    AF_ACS_Effects::statusSample($t['hit']);AF_ACS_Effects::statusSample($t['crit']);$rolls=$t+['hit_used'=>false,'crit_used'=>false];
                }
                $intents=AF_ACS_Spawn::plan($ability,$actor,$i,$context['spawn_sources'][$actor]??[],$spawnCounts);
                $spawnPlan=[...$spawnPlan,...$intents];if(count($spawnPlan)>128)self::fail('ACS_SPAWN_COUNT_INVALID');
                $trace=[];$state=self::apply($state,[],$actor,$ability['costs'],$trace);
                $plans[]=['action_index'=>$i,'actor_id'=>$actor,'targets'=>$targets,'scenario'=>'spawned','rolls'=>$rolls,'ability_ref'=>$ability['source_ref'],'costs'=>$ability['costs'],
                    'probabilities'=>['P_hit'=>100,'P_crit'=>0,'crit_rate'=>0,'can_crit'=>false],'allocation'=>['integer_budget'=>0,'components'=>[]],'spawns'=>$intents,'effects_trace'=>$trace];
                continue;
            }
            $context['redirect_targets'][$actor]=$redirectCandidates[$actor]??[];
            if(AF_ACS_Behavior::constrained($state['status_instances']??[],$actor,'ordinary',$context['status_scope']??[])){
                $behaviorResult=AF_ACS_Behavior::evaluate($state['status_instances']??[],$actor,$key,'ordinary',$behaviorCandidates,$ability['target_policy']??['kind'=>'any_entity'],$context['status_scope']??[]);
                $context['redirect_targets'][$actor]=array_values(array_intersect($redirectCandidates[$actor]??[],$behaviorResult['legal_targets']));
            }
            if(isset($ability['target_policy']))$context['redirect_targets'][$actor]=array_values(array_filter($context['redirect_targets'][$actor],static fn($id)=>AF_ACS_Control::allowed($ability['target_policy'],$context['relationships'][$actor][$id]??'', $context['reaction_kinds'][$id]??'')));
            $singleTarget=count($targets)===1&&($ability['targeting']??'single_target')!=='multiple';$strikePlans=[];$redirected=false;foreach($targets as $strikeIndex=>$originalTarget){
            $target=(string)$originalTarget;if($target!==$actor&&!AF_ACS_Awareness::targetable('identified',$state['perception_subjects']['entity:'.$target]['perception']['stealth']??null,$state['status_instances']??[],$target))self::fail('resolver_target_forbidden');if(isset($ability['target_policy'])&&!AF_ACS_Control::allowed($ability['target_policy'],$context['relationships'][$actor][$target]??'', $context['reaction_kinds'][$target]??''))self::fail('ACS_TARGET_POLICY_DENIED');if(!isset($state['entities'][$target])||!in_array($target,$context['allowed_targets'][$actor]??[],true))self::fail('resolver_target_forbidden');
            if(is_array($ability)&&array_diff(array_keys($ability),['supported','source_ref','components','costs','hit_policy','lifesteal','hp_cost_policy','statuses','removals','targeting','target_count','target_policy','range','environment_policy']))self::fail('resolver_ability_unsupported');
            if(($ability['supported']??null)!==true||!is_array($ability['source_ref']??null))self::fail('resolver_capability_blocked');
            if(count($targets)>1&&($ability['targeting']??null)!=='multiple'||($ability['targeting']??null)==='multiple'&&(count($targets)<($ability['target_count']['min']??1)||count($targets)>($ability['target_count']['max']??1)))self::fail('resolver_target_count_invalid');
            self::ref($ability['source_ref']);
            $preflight=[];foreach($context['reflections'][$target]??[] as $rule)self::rule($rule,$preflight,true);$preflight=[];foreach($ability['lifesteal']??[] as $rule)self::rule($rule,$preflight,false);
            $modifierTrace=[];$formulaTrace=[];$a=self::stats($context,$actor,$modifierTrace);$s=self::stats($context,$target,$modifierTrace);$coverPolicy=AF_ACS_Cover::check($state,$actor,$target,$ability);if($coverPolicy!==null)$s['speed']=AF_ACS_Numeric::operation('add',$s['speed'],$coverPolicy['speed_bonus']);$strikeContext=AF_ACS_Cover::defenses($context,$target,$coverPolicy);$reactionContext=$context;$reactionContext['environment_ability']=$ability;$parts=[];$rawParts=[];
            foreach($ability['components']??[] as $component){
                if(!array_key_exists('value',$component)&&($component['unit']??null)!=='damage')self::fail('resolver_damage_unit_unsupported');
                $raw=array_key_exists('value',$component)?AF_ACS_Numeric::number($component['value']):self::formula($component,$component['inputs']??[],$component['unit'],false,$formulaTrace);
                $rawParts[]=['kind'=>'direct_attack','raw'=>$raw]+(isset($component['kb_effect_index'])?['kb_effect_index'=>$component['kb_effect_index']]:[]);$parts[]=$component;
            }
            if(!$parts&&empty($ability['statuses'])&&empty($ability['removals']))self::fail('resolver_components_missing');
            $prob=$parts?AF_ACS_HitCrit::strike($a['accuracy']??null,$s['speed']??null,$a['crit_rate']??null,$a['crit_dmg']??null,$rawParts,null,$ability['hit_policy']??null)->serverData():AF_ACS_HitCrit::hit($a['accuracy']??null,$s['speed']??null,$ability['hit_policy']??null)+['crit_rate'=>0,'can_crit'=>false];
            $statusIndexes=self::statusRollIndexes($context,$actor,$target,$ability);$statusSamples=[];$rollTrace=null;
            if($mode==='seal'){
                $t=$fixedRandomTape[$i];if(count($targets)>1){if(array_keys($t)!==['rng_version','targets']||$t['rng_version']!=='uniform53-v1'||array_map('strval',array_keys($t['targets']))!==$targets)self::fail('resolver_tape_invalid');$t=$t['targets'][$originalTarget];}
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
            $allocation=$parts?self::damage($components,$target,$strikeContext):new AF_ACS_DamageResult(['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'integer_budget'=>0,'allocation_sum'=>0,'components'=>[]],[]);$effects=[];$details=[];
            if($scenario!=='miss'&&$parts)$effects[]=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];
            $hasStatuses=!empty($ability['statuses'])||!empty($ability['removals']);$hasReactions=!empty($context['reactions']);$deferred=$hasStatuses||$hasReactions;$reactionTrace=[];
            $state=self::apply($state,$deferred?[]:$effects,$strikeIndex===0?$actor:null,$strikeIndex===0?$ability['costs']:[],$details,$ability['hp_cost_policy']??null);
            $event=self::statusEvent($context,$actor,$target,$a,$s);
            $statusResults=self::statuses($ability,'on_cast',$event,$statusSamples,$mode,$i,$state,(($context['status_scope']??[])+($context['control_context']??[])));
            if($hasReactions){$reactionTrace=[...self::reactions($reactionContext,'on_targeted',$i,$target,false,0,$mode,$state,$slots,$reactionUpdates,$actor,$targets,$singleTarget,$redirected,$visited),...self::reactions($reactionContext,'before_hit',$i,$target,false,0,$mode,$state,$slots,$reactionUpdates,$actor,$targets,$singleTarget,$redirected,$visited)];}
            if($target!==$actor&&!AF_ACS_Awareness::targetable('identified',$state['perception_subjects']['entity:'.$target]['perception']['stealth']??null,$state['status_instances']??[],$target))self::fail('resolver_target_forbidden');
            if($target!==(string)$originalTarget){
                if(isset($state['spatial']))AF_ACS_Spatial::check($state['spatial'],$actor,$target,$ability['range']??null);
                $s=self::stats($context,$target,$modifierTrace);$coverPolicy=AF_ACS_Cover::check($state,$actor,$target,$ability);if($coverPolicy!==null)$s['speed']=AF_ACS_Numeric::operation('add',$s['speed'],$coverPolicy['speed_bonus']);$strikeContext=AF_ACS_Cover::defenses($context,$target,$coverPolicy);$prob=$parts?AF_ACS_HitCrit::strike($a['accuracy'],$s['speed'],$a['crit_rate'],$a['crit_dmg'],$rawParts,null,$ability['hit_policy']??null)->serverData():AF_ACS_HitCrit::hit($a['accuracy'],$s['speed'],$ability['hit_policy']??null)+['crit_rate'=>0,'can_crit'=>false];
                if($mode==='seal'){$hit=100*$t['hit']['u']<$prob['P_hit'];$crit=$hit&&$prob['can_crit']&&100*$t['crit']['u']<$prob['crit_rate'];$scenario=$hit?($crit?'crit':'hit'):'miss';$rollTrace=['rng_version'=>$t['rng_version'],'hit'=>$t['hit'],'crit'=>$t['crit'],'hit_success'=>$hit,'crit_success'=>$crit,'crit_used'=>$hit&&$prob['can_crit']];}
                $critical=$scenario==='miss'||!$parts?null:AF_ACS_HitCrit::strike($a['accuracy'],$s['speed'],$a['crit_rate'],$a['crit_dmg'],$rawParts,$scenario==='crit',$ability['hit_policy']??null)->serverData();
                $components=[];foreach($parts as $n=>$part)$components[]=['source_index'=>$part['source_index'],'damage_type'=>$part['damage_type'],'damage_element'=>$part['damage_element']??null,'raw'=>$rawParts[$n]['raw'],'after_crit'=>$scenario==='miss'?0:$critical['components'][$n]['raw_after_crit']];
                $allocation=$parts?self::damage($components,$target,$strikeContext):new AF_ACS_DamageResult(['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'integer_budget'=>0,'allocation_sum'=>0,'components'=>[]],[]);$effects=[];if($scenario!=='miss'&&$parts)$effects[]=['kind'=>'damage','targets'=>[['entity_id'=>$target,'allocation'=>$allocation]]];
                if(self::statusRollIndexes($context,$actor,$target,$ability)!==$statusIndexes)self::fail('redirect_status_tape_conflict');$event=self::statusEvent($context,$actor,$target,$a,$s);
            }
            $event['hit_success']=$scenario!=='miss';
            $statusResults=[...$statusResults,...self::statuses($ability,'on_hit',$event,$statusSamples,$mode,$i,$state,(($context['status_scope']??[])+($context['control_context']??[])))];
            if($hasReactions)$reactionTrace=[...$reactionTrace,...self::reactions($reactionContext,'after_hit',$i,$target,$scenario!=='miss',0,$mode,$state,$slots,$reactionUpdates,$actor,$targets,$singleTarget,$redirected,$visited)];
            if($deferred)$state=self::apply($state,$effects,null,[],$details);
            $loss=0;$absorbed=0;foreach($details[$deferred?1:0]['events'] as $damageEvent)if($damageEvent['type']==='damage'){$loss=AF_ACS_Numeric::integer($loss+$damageEvent['hp_loss']);$absorbed=AF_ACS_Numeric::integer($absorbed+$damageEvent['absorbed']);}
            $event['hp_loss']=$loss;
            $statusResults=[...$statusResults,...self::statuses($ability,'on_damage',$event,$statusSamples,$mode,$i,$state,(($context['status_scope']??[])+($context['control_context']??[])))];
            if($hasReactions)$reactionTrace=[...$reactionTrace,...self::reactions($reactionContext,'after_damage',$i,$target,$scenario!=='miss',$loss,$mode,$state,$slots,$reactionUpdates,$actor,$targets,$singleTarget,$redirected,$visited)];
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
            $strikePlans[]=['action_index'=>$i,'original_target'=>(string)$originalTarget,'resolved_target'=>$target,'conditional'=>$mode==='preview','rolls'=>$rollTrace,'scenario'=>$scenario,'actor_id'=>$actor,'targets'=>[(string)$originalTarget],'ability_ref'=>$ability['source_ref'],'costs'=>$strikeIndex===0?$ability['costs']:[],
                'probabilities'=>array_intersect_key($prob,array_flip(['P_hit','P_evade','crit_rate','can_crit']))+['P_crit'=>$prob['can_crit']?$prob['crit_rate']:0,'crit_scope'=>'conditional_on_hit'],'allocation'=>$allocation->serverData(),'causal_id'=>$causal,'derived'=>$derived,'formula_trace'=>$formulaTrace,'modifier_trace'=>$modifierTrace,'effects_trace'=>$details]+($hasStatuses?['status_evaluations'=>$statusResults]:[])+($reactionTrace?['reactions'=>$reactionTrace]:[]);
            }
            $plan=$strikePlans[0];if(count($strikePlans)>1){$plan['strikes']=$strikePlans;$plan['targets']=$targets;$plan['scenario']='multiple';$plan['allocation']=['trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'integer_budget'=>array_sum(array_column(array_column($strikePlans,'allocation'),'integer_budget')),'components'=>[]];}
            $plans[]=$plan;
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
            'end_condition_check_required'=>true,'encounter_transition'=>null,'persistent'=>false]+($endTraces?['entity_end_boundaries'=>$endTraces]:[])+($initialTrace['events']?['initial_effects_trace'=>$initialTrace]:[])+(!empty($context['reactions'])?['reaction_limits'=>$slots,'reaction_updates'=>$reactionUpdates]:[]);
        if($spawnPlan)$plan['spawns']=$spawnPlan;
        if ($itemState!==null) $plan['item_state']=$itemState;
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
