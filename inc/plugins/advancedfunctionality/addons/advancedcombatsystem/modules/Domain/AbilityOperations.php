<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';

/** Typed data operations over the existing status state. No clock, RNG, SQL or owner protocol. */
final class AF_ACS_AbilityOperations
{
    public static function fail(string $code='ACS_EFFECT_OPERATION_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(mixed $v,array $keys):void {if(!is_array($v)||array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    private static function key(mixed $v):void {if(!is_string($v)||!preg_match('/\A[a-z][a-z0-9_.-]{0,95}\z/D',$v))self::fail();}
    public static function descriptor(mixed $v):array
    {try{return self::descriptorValue($v);}catch(DomainException $e){self::fail();}}
    private static function descriptorValue(mixed $v):array
    {
        if(!is_array($v))self::fail();$kind=$v['kind']??null;
        self::fields($v,$kind==='copy_ability'?['schema','kind','ability_key','duration','uses','stat_policy','use_costs']:['schema','kind','effect_keys','removal']);
        if($v['schema']!=='ic-acs.effect-operation.v1'||!in_array($kind,['copy_ability','steal_effect','dispel_effect'],true))self::fail();
        if($kind==='copy_ability'){
            self::key($v['ability_key']);self::fields($v['duration'],['value','unit']);AF_ACS_Numeric::integer($v['duration']['value'],1,2147483647);if(!in_array($v['duration']['unit'],['target_turn','round'],true))self::fail();AF_ACS_Numeric::integer($v['uses'],1,100);
            self::fields($v['stat_policy'],['mode','values']);$p=$v['stat_policy'];if(!in_array($p['mode'],['caster_stats','source_snapshot','custom'],true)||!is_array($p['values']))self::fail();
            if($p['mode']!=='custom'&&$p['values']!==[])self::fail();if($p['mode']==='custom'){self::fields($p['values'],['accuracy','speed','crit_rate','crit_dmg','def']);foreach($p['values'] as $n)if(AF_ACS_Numeric::number($n)<0)self::fail();if($p['values']['crit_rate']>100)self::fail();}
            if(!is_array($v['use_costs'])||!array_is_list($v['use_costs'])||!$v['use_costs'])self::fail();$seen=[];foreach($v['use_costs'] as $c){if(!is_array($c))self::fail();self::fields($c,($c['kind']??null)==='resource'?['kind','amount','resource']:['kind','amount']);if(!in_array($c['kind'],['ap','resource'],true))self::fail();AF_ACS_Numeric::integer($c['amount'],1,2147483647);$k=$c['kind']==='ap'?'ap':$c['resource'];self::key($k);if(isset($seen[$k])||$c['kind']==='resource'&&in_array($k,['ap','hp'],true))self::fail();$seen[$k]=true;}if(!isset($seen['ap'])||$v['use_costs'][0]['kind']!=='ap')self::fail();
        }else{
            if(!is_array($v['effect_keys'])||!array_is_list($v['effect_keys'])||!$v['effect_keys']||count(array_unique($v['effect_keys']))!==count($v['effect_keys']))self::fail();foreach($v['effect_keys'] as $key)self::key($key);
            AF_ACS_Effects::removalSpec($v['removal']);if($v['removal']['chance']!==100||$v['removal']['limit']!==1||$v['removal']['remove_stacks']!==null)self::fail();
        }return $v;
    }
    public static function export(mixed $p):array
    {self::fields($p,['schema','visibility','stat_policies']);if($p['schema']!=='ic-acs.copy-policy.v1'||$p['visibility']!=='identified_entity'||!is_array($p['stat_policies'])||!array_is_list($p['stat_policies'])||!$p['stat_policies']||array_diff($p['stat_policies'],['caster_stats','source_snapshot','custom']))self::fail();return $p;}
    public static function transferable(mixed $p):array
    {self::fields($p,['schema','visibility','sustained','max_transfers']);if($p!==['schema'=>'ic-acs.effect-transfer.v1','visibility'=>'identified_entity','sustained'=>'deny','max_transfers'=>1])self::fail();return $p;}
    public static function compile(array $dto):array
    {
        if(($dto['ready']??null)!==true||($dto['source']['type']??null)!=='arpg_ability'||!is_string($dto['definition_version']['declared_version']??null)||!is_string($dto['definition_version']['fingerprint']??null))self::fail();
        $d=$dto['data']['definition'];$op=self::descriptor($d['effect_operation']);
        $metadata=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','cooldown_unit','duration_unit','level_cap','magic_direction_ref','damage_element','element','target_policy','action_cost','effect_operation','range','spatial_range','environment_policy'];
        $empty=['cast_time','cooldown','cooldown_value','duration','duration_value','max_charges','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),[...$metadata,...$empty])||($dto['ready']??null)!==true||($d['type']??null)!=='active'||($d['targeting']??null)!=='single_enemy')self::fail();foreach($empty as $k)if(!empty($d[$k]))self::fail();AF_ACS_Numeric::integer($d['action_cost']??null,1,2147483647);
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$d['action_cost']]],'target_policy'=>AF_ACS_Control::targetPolicy($d),'range'=>AF_ACS_Spatial::range($d['spatial_range']??($d['range']??null)),'environment_policy'=>AF_ACS_Cover::policy($d['environment_policy']??null),'effect_operation'=>$op];
    }
    public static function grantSpec(array $op):array
    {
        self::descriptor($op);return ['schema_version'=>1,'effect_key'=>'granted-ability','scope'=>'combat','category'=>'granted_ability','polarity'=>'positive','repeat_rule'=>'independent','source_scope'=>'source','duration'=>$op['duration'],'stack_max'=>1,'stack_per_apply'=>1,'cleansable'=>false,'dispellable'=>false,'mechanical_parameters'=>['granted_ability'=>$op],'duration_event'=>$op['duration']['unit']==='round'?'on_round_end':'on_turn_end'];
    }
    public static function grant(array $row):array
    {
        $g=$row['grant']??null;self::fields($g,['binding','remaining_uses','granted_version','uses','cause','origin_event_id']);$op=self::descriptor($row['spec']['mechanical_parameters']['granted_ability']??null);$b=$g['binding'];self::fields($b,['source_entity_id','recipient_entity_id','definition','ability','stats']);
        foreach(['source_entity_id','recipient_entity_id'] as $field)if(!is_string($b[$field])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$b[$field]))self::fail();
        if(isset($b['definition']['temporary_grant']))self::fail();
        if(!is_array($b['stats'])||!is_array($b['ability'])||array_intersect(array_keys($b['ability']),['hit_policy','granted_use','effect_operation','prepared_action','statuses','removals','spawns','awareness_action','hidden_mass_effect','spatial_effect'])||empty($b['ability']['components']))self::fail();
        foreach(['accuracy','speed','crit_rate','crit_dmg','def'] as $stat)if(AF_ACS_Numeric::number($b['stats'][$stat]??null)<0)self::fail();
        if($b['recipient_entity_id']!==$row['target_entity_id']||($b['definition']['source']['key']??null)!==$op['ability_key']||($b['ability']['source_ref']??null)!==['key'=>$op['ability_key'],'version'=>$b['definition']['definition_version']['declared_version']??null,'fingerprint'=>$b['definition']['definition_version']['fingerprint']??null])self::fail();
        AF_ACS_Numeric::integer($g['remaining_uses'],0,$op['uses']);AF_ACS_Numeric::integer($g['granted_version'],1,101);if(!is_array($g['uses'])||!array_is_list($g['uses'])||count(array_unique($g['uses']))!==count($g['uses'])||count($g['uses'])+$g['remaining_uses']!==$op['uses']||$g['granted_version']!==1+count($g['uses']))self::fail();foreach([$g['cause'],...$g['uses']] as $c)if(!is_string($c)||!preg_match('/\A(?:sealed-action:[1-9][0-9]*|preview:[0-9]+)\z/D',$c))self::fail();return $g;
    }
    public static function grantedAbility(array $row):array
    {
        $g=self::grant($row);if($row['status']!=='active'||!$g['remaining_uses'])self::fail('ACS_GRANTED_ABILITY_EXHAUSTED');$a=$g['binding']['ability'];$dto=$g['binding']['definition'];$a['hit_policy']=AF_ACS_HitCritPolicy::fromDefinition($dto,$dto['source'],$dto['definition_version']);$a['costs']=$row['spec']['mechanical_parameters']['granted_ability']['use_costs'];$a['granted_use']=['id'=>$row['id'],'version'=>$g['granted_version'],'pin'=>$a['source_ref'],'policy'=>$row['spec']['mechanical_parameters']['granted_ability']['stat_policy'],'stats'=>$g['binding']['stats']];return $a;
    }
    public static function use(array &$state,string $actor,array $use,string $cause):void
    {
        $r=&$state['status_instances'][$use['id']];if(!is_array($r)||$r['target_entity_id']!==$actor)self::fail();$g=self::grant($r);if(in_array($cause,$g['uses'],true))self::fail('ACS_GRANTED_DUPLICATE_CAUSE');if($r['status']!=='active'||!$g['remaining_uses'])self::fail('ACS_GRANTED_ABILITY_EXHAUSTED');if($g['granted_version']!==$use['version']||$g['binding']['ability']['source_ref']!==$use['pin'])self::fail('ACS_GRANTED_VERSION_CONFLICT');--$r['grant']['remaining_uses'];++$r['grant']['granted_version'];$r['grant']['uses'][]=$cause;++$r['version'];if(!$r['grant']['remaining_uses']){$r['status']='removed';$r['stacks']=0;}unset($r);
    }
    public static function execute(array &$state,array $op,array &$events):void
    {
        if(array_diff(array_keys($op),['kind','ability','source','target','scope','cause','action_index']))self::fail();
        $a=$op['ability'];$p=self::descriptor($a['effect_operation']);$actor=$op['source'];$target=$op['target'];$scope=$op['scope'];$cause=$op['cause'];
        if($p['kind']==='copy_ability'){
            $binding=$a['copy_binding']??null;if(!is_array($binding)||$binding['source_entity_id']!==$target||$binding['recipient_entity_id']!==$actor)self::fail('ACS_COPY_BINDING_REQUIRED');
            $order=$state['next_status_order']++;$id='new:'.$order;$pin=$a['source_ref'];$state['status_instances'][$id]=['schema_version'=>1,'id'=>$id,'session_id'=>$scope['session_id'],'encounter_id'=>$scope['encounter_id'],'source_entity_id'=>$actor,'target_entity_id'=>$actor,'spec'=>self::grantSpec($p),'effect_definition_ref'=>['type'=>'arpg_ability']+$pin+['effect_index'=>0],'status_definition_ref'=>$pin,'stacks'=>1,'remaining_duration'=>$p['duration']['value'],'duration_unit'=>$p['duration']['unit'],'applied_order'=>$order,'version'=>1,'status'=>'active','origin_action_index'=>$op['action_index'],'grant'=>['binding'=>$binding,'remaining_uses'=>$p['uses'],'granted_version'=>1,'uses'=>[],'cause'=>$cause,'origin_event_id'=>null]];$events[]=['type'=>'ability_copied','id'=>$id,'source_entity_id'=>$target];return;
        }
        $eligible=$state;$eligible['status_instances']=array_filter($state['status_instances'],static fn($r)=>in_array($r['spec']['effect_key'],$p['effect_keys'],true));$ids=AF_ACS_Effects::removalCandidates($eligible,$target,$scope['session_id'],$scope['encounter_id'],$p['removal']);
        foreach($ids as $id){$old=$state['status_instances'][$id];$m=$old['spec']['mechanical_parameters'];self::transferable($m['transferable']??null);if(array_diff(array_keys($m),['transferable'])||isset($old['spec']['upkeep'])||($p['kind']==='steal_effect'&&isset($old['transfer']))||$old['duration_unit']!=='round'||$old['spec']['scope']!=='combat'||$old['spec']['repeat_rule']!=='independent')self::fail();
            if($p['kind']==='steal_effect'&&!preg_match('/\A[1-9][0-9]*\z/D',(string)$id))self::fail();
            $state['status_instances'][$id]['status']='removed';$state['status_instances'][$id]['stacks']=0;++$state['status_instances'][$id]['version'];
            if($p['kind']==='steal_effect'){$order=$state['next_status_order']++;$new='new:'.$order;$clone=$old;$clone['id']=$new;$clone['target_entity_id']=$actor;$clone['applied_order']=$order;$clone['version']=1;$clone['transfer']=['parent_effect_id'=>(string)$id,'parent_version'=>$old['version'],'previous_target'=>$target,'action_index'=>$op['action_index'],'cause'=>$cause,'origin_event_id'=>null,'producer_snapshot_id'=>null];$state['status_instances'][$new]=$clone;$events[]=['type'=>'effect_transferred','old_id'=>(string)$id,'new_id'=>$new,'cause'=>$cause];}
            else $events[]=['type'=>'effect_dispelled','id'=>(string)$id,'cause'=>$cause];
        }
    }
}
