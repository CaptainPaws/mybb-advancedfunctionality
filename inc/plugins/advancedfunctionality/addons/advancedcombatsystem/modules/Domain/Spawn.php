<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';

/** Opt-in consumer of B1 summon effects. No SQL, clocks, KB reads or live identities. */
final class AF_ACS_Spawn
{
    public const SCHEMA='ic-acs.spawn.v1';
    public static function fail(string $code): never {throw new DomainException($code);}
    private static function fields(mixed $v,array $keys): void
    {
        if(!is_array($v) || array_diff(array_keys($v),$keys) || array_diff($keys,array_keys($v)))self::fail('ACS_SPAWN_SCHEMA_UNSUPPORTED');
    }
    public static function descriptor(array $s): array
    {
        self::fields($s,['schema','kind','template','count','scope','group','controller','side','zone_policy','duration','limits','conditions','upkeep','on_spawn_effect','despawn']);
        if($s['schema']!==self::SCHEMA || !in_array($s['kind'],['spawn_entity','spawn_object'],true) || $s['scope']!=='encounter')self::fail('ACS_SPAWN_SCHEMA_UNSUPPORTED');
        $object=$s['kind']==='spawn_object';self::fields($s['template'],['type','key','version','fingerprint']);
        if($s['template']['type']!==($object?'arpg_mechanics':'arpg_bestiary') || !is_string($s['template']['key']) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,95}\z/D',$s['template']['key'])
            || !is_string($s['template']['version']) || $s['template']['version']==='' || !is_string($s['template']['fingerprint']) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$s['template']['fingerprint']))self::fail('ACS_SPAWN_TEMPLATE_INVALID');
        if(!is_int($s['count']) || $s['count']<1 || $s['count']>100)self::fail('ACS_SPAWN_COUNT_INVALID');
        if($s['side']!=='source' || $s['zone_policy']!=='unplaced')self::fail('ACS_SPAWN_SPATIAL_UNSUPPORTED');
        if($object ? ($s['group']!=='none'||$s['controller']!=='none') : !in_array([$s['group'],$s['controller']],[['source','source'],['independent','gm_pool']],true))self::fail('ACS_SPAWN_CONTROL_UNSUPPORTED');
        self::fields($s['duration'],['value','unit']);
        if(!is_int($s['duration']['value']) || $s['duration']['value']<1 || $s['duration']['value']>2147483647 || !in_array($s['duration']['unit'],['own_turn','round'],true))self::fail('ACS_SPAWN_LIFETIME_UNSUPPORTED');
        self::fields($s['limits'],['per_source','per_encounter']);
        foreach($s['limits'] as $limit)if(!is_int($limit)||$limit<1||$limit>10000||$limit<$s['count'])self::fail('ACS_SPAWN_LIMIT_INVALID');
        if($s['conditions']!==['source_active'] || $s['upkeep']!==[] || $s['on_spawn_effect']!==[] || $s['despawn']!=='lifetime_end')self::fail('ACS_SPAWN_CAPABILITY_UNSUPPORTED');
        return $s;
    }
    public static function hasAbility(array $dto): bool
    {
        foreach($dto['data']['ability_profile']['effects']??[] as $e)if(($e['parameters']['effect_type']??null)==='summon')return true;
        return false;
    }
    public static function template(array $spec,array $dto): array
    {
        $s=self::descriptor($spec);$ref=$s['template'];
        if(($dto['ready']??null)!==true || ($dto['contract_id']??null)!=='IC-KB.read.v1' || ($dto['source']['type']??null)!==$ref['type'] || ($dto['source']['key']??null)!==$ref['key']
            || ($dto['definition_version']['declared_version']??null)!==$ref['version'] || ($dto['definition_version']['fingerprint']??null)!==$ref['fingerprint'])self::fail('ACS_SPAWN_TEMPLATE_INVALID');
        $d=$dto['data']['definition'];
        if($s['kind']==='spawn_entity'){
            $allowed=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','family','archetype','faction','rank','threat_tier','level','combat_stats','resists','resistances','weaknesses','ability_keys','loot','magic_direction_ref','damage_element','modifiers','math_modifiers','equipment','base_stats','permanent_modifiers','resources','conditions','grants','passive_effects','triggers','immunities'];
            if(array_diff(array_keys($d),$allowed))self::fail('ACS_SPAWN_TEMPLATE_FIELDS_UNSUPPORTED');
            foreach(['loot','modifiers','math_modifiers','equipment','base_stats','permanent_modifiers','resources','conditions','grants','passive_effects','triggers','weaknesses'] as $f)if(!empty($d[$f]))self::fail('ACS_SPAWN_TEMPLATE_FIELDS_UNSUPPORTED');
            $hp=AF_ACS_Numeric::number($dto['data']['combat_stats']['hp']??null);
            if($hp<1 || $hp>9007199254740991 || floor((float)$hp)!=(float)$hp)self::fail('ACS_SPAWN_TEMPLATE_INVALID');
            return ['hp'=>(int)$hp,'stats'=>$dto['data']['combat_stats'],'properties'=>null];
        }
        // A passive object is an explicit typed combat_template, not an inferred bestiary NPC.
        if(array_diff(array_keys($d),['schema','type_profile','version','revision','service_kind','entries']) || ($d['service_kind']??null)!=='combat_template' || count($d['entries']??[])!==1)self::fail('ACS_SPAWN_OBJECT_INVALID');
        $entry=$d['entries'][0];self::fields($entry,['schema','key','version','phases','limits','formula_refs','object_definition']);
        if($entry['schema']!=='af_kb.arpg.mechanic.v1' || $entry['phases']!==[] || $entry['limits']!==[] || $entry['formula_refs']!==[])self::fail('ACS_SPAWN_OBJECT_INVALID');
        $o=$entry['object_definition'];self::fields($o,['schema','name','properties']);self::fields($o['properties'],['description','tags']);
        if($o['schema']!=='ic-acs.object-template.v1' || !is_string($o['name']) || trim($o['name'])==='' || mb_strlen($o['name'])>191 || !is_string($o['properties']['description']) || strlen($o['properties']['description'])>4096
            || !is_array($o['properties']['tags']) || !array_is_list($o['properties']['tags']) || count($o['properties']['tags'])>32)self::fail('ACS_SPAWN_OBJECT_INVALID');
        foreach($o['properties']['tags'] as $tag)if(!is_string($tag)||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$tag))self::fail('ACS_SPAWN_OBJECT_INVALID');
        return ['hp'=>null,'stats'=>null,'properties'=>$o];
    }
    public static function compile(array $dto): array
    {
        $d=$dto['data']['definition'];
        $fields=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),$fields) || ($d['type']??null)!=='active' || ($d['targeting']??null)!=='self')self::fail('ACS_SPAWN_ABILITY_UNSUPPORTED');
        foreach(['range','cast_time','cooldown','cooldown_value','duration','duration_value','max_charges','resources','cost_value','cost_resource','costs','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','damage_value','heal_value','shield_value','auto_hit','can_crit'] as $f)if(!empty($d[$f]))self::fail('ACS_SPAWN_ABILITY_UNSUPPORTED');
        $cost=$d['action_cost']??null;if(!is_int($cost)||$cost<1||$cost>2147483647)self::fail('ACS_SPAWN_COST_UNSUPPORTED');
        $spawns=[];
        foreach($dto['data']['ability_profile']['effects']??[] as $effect){
            $p=$effect['parameters'];if(array_diff(array_keys($p),['kind','effect_type','target','targeting','damage_type','spawn','notes']) || ($p['effect_type']??null)!=='summon' || ($p['target']??null)!=='self')self::fail('ACS_SPAWN_EFFECT_UNSUPPORTED');
            $i=$effect['source_index'];$spec=self::descriptor($p['spawn']??[]);$template=$dto['spawn_templates'][$i]??[];self::template($spec,$template);
            $spawns[]=['effect_index'=>$i,'spec'=>$spec,'template'=>$template];
        }
        if(!$spawns || count($spawns)>16)self::fail('ACS_SPAWN_EFFECT_UNSUPPORTED');
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],
            'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$cost]],'targeting'=>'self','spawns'=>$spawns];
    }
    public static function plan(array $ability,string $actor,int $index,array $source,array &$counts): array
    {
        if(empty($source['side_id']) || empty($source['control_group_id']))self::fail('ACS_SPAWN_SCOPE_INVALID');
        $out=[];
        foreach($ability['spawns'] as $binding){$s=self::descriptor($binding['spec']);self::template($s,$binding['template']);
            if($s['group']==='independent' && !in_array($source['kind'],['npc','monster'],true))self::fail('ACS_SPAWN_CONTROL_UNSUPPORTED');
            $key=$s['template']['type'].':'.$s['template']['key'];$a='source:'.$actor.':'.$key;$e='encounter:'.$key;
            if(($counts[$a]??0)+$s['count']>$s['limits']['per_source'] || ($counts[$e]??0)+$s['count']>$s['limits']['per_encounter'])self::fail('ACS_SPAWN_LIMIT_REACHED');
            $counts[$a]=($counts[$a]??0)+$s['count'];$counts[$e]=($counts[$e]??0)+$s['count'];
            for($n=0;$n<$s['count'];++$n)$out[]=['action_index'=>$index,'source_entity_id'=>$actor,'effect_index'=>$binding['effect_index'],'ordinal'=>$n,'spec'=>$s];
        }
        return $out;
    }
}
