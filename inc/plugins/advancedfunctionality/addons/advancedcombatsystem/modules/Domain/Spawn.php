<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';

/** Opt-in consumer of B1 summon effects. Pure spawn/lifecycle rules. No SQL, live KB reads or wall clock. */
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
        $policy=$s['lifecycle']??null;unset($s['lifecycle']);
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
        if($s['conditions']!==['source_active'] || $s['on_spawn_effect']!==[] || $s['despawn']!=='lifetime_end')self::fail('ACS_SPAWN_CAPABILITY_UNSUPPORTED');
        if($object&&($s['upkeep']!==[]||$policy!==null))self::fail('ACS_SPAWN_CAPABILITY_UNSUPPORTED');
        if($s['upkeep']){require_once __DIR__.'/Effects.php';AF_ACS_Effects::upkeepSpec($s['upkeep']);}
        if($policy!==null){self::policy($policy);$s['lifecycle']=$policy;}
        return $s;
    }
    public static function policy(?array $p): array
    {
        $p??=['admission'=>'next_round','source_inactive'=>'persist','disappear_on'=>['self_dead']];
        self::fields($p,['admission','source_inactive','disappear_on']);
        if(!in_array($p['admission'],['current_round','next_round'],true)||!in_array($p['source_inactive'],['persist','despawn','independent'],true)
            ||!is_array($p['disappear_on'])||!array_is_list($p['disappear_on'])||array_diff($p['disappear_on'],['self_dead','encounter_end'])||count(array_unique($p['disappear_on']))!==count($p['disappear_on']))self::fail('ACS_SUMMON_POLICY_UNSUPPORTED');
        return $p;
    }
    public static function initial(string $id,string $source,string $encounter,array $spec,int $version=1): array
    {
        return ['entity_id'=>$id,'source_entity_id'=>$source,'encounter_id'=>$encounter,'status'=>'active','remaining'=>$spec['duration']['value'],'unit'=>$spec['duration']['unit'],
            'policy'=>self::policy($spec['lifecycle']??null),'upkeep'=>$spec['upkeep']?AF_ACS_Effects::upkeepSpec($spec['upkeep']):null,'last_duration_boundary'=>null,'last_upkeep'=>null,
            'independent'=>$spec['group']==='independent','pending_independent'=>false,'reason'=>null,'version'=>$version];
    }
    public static function instance(array $v):void
    {
        self::fields($v,['entity_id','source_entity_id','encounter_id','status','remaining','unit','policy','upkeep','last_duration_boundary','last_upkeep','independent','pending_independent','reason','version']);
        foreach(['entity_id','source_entity_id','encounter_id'] as $k)if(!is_string($v[$k])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[$k]))self::fail('ACS_SUMMON_IDENTITY_INVALID');
        if(!in_array($v['status'],['active','despawned'],true)||!is_int($v['remaining'])||$v['remaining']<0||!in_array($v['unit'],['round','own_turn'],true)||!is_bool($v['independent'])||!is_bool($v['pending_independent'])||!is_int($v['version'])||$v['version']<1)self::fail('ACS_SUMMON_STATE_INVALID');
        self::policy($v['policy']);
        if($v['version']>9007199254740991||$v['status']==='active'&&$v['remaining']===0||$v['pending_independent']&&!$v['independent'])self::fail('ACS_SUMMON_STATE_INVALID');
        if($v['last_duration_boundary']!==null&&(!is_string($v['last_duration_boundary'])||!preg_match($v['unit']==='round'?'/\Around:[1-9][0-9]*:next\z/D':'/\Aentity_turn:[1-9][0-9]*:end\z/D',$v['last_duration_boundary'])))self::fail('ACS_SUMMON_STATE_INVALID');
        if($v['last_upkeep']!==null){$u=$v['last_upkeep'];self::fields($u,['boundary_key','paid','reason','costs']);if(!is_bool($u['paid'])||!is_string($u['boundary_key'])||!preg_match('/\Aentity_turn:[1-9][0-9]*:start\z/D',$u['boundary_key'])||$u['costs']!==($v['upkeep']['costs']??null))self::fail('ACS_SUMMON_STATE_INVALID');}
    }
    private static function stop(array &$v,string $reason):void {$v['status']='despawned';$v['pending_independent']=false;$v['reason']=$reason;++$v['version'];}
    /** Every atomic Effects operation rechecks irreversible source/self conditions. */
    public static function interrupt(array &$state,array &$events):void
    {
        foreach($state['summons']??[] as $id=>$v){if($v['status']!=='active')continue;$before=$v;
            if(($state['entities'][$id]['life_state']??null)==='dead'&&in_array('self_dead',$v['policy']['disappear_on'],true))self::stop($v,'self_dead');
            elseif(isset($state['entities'][$v['source_entity_id']])&&$state['entities'][$v['source_entity_id']]['life_state']!=='active'&&!$v['independent']){
                if($v['policy']['source_inactive']==='despawn')self::stop($v,'source_inactive');
                elseif($v['policy']['source_inactive']==='independent'){$v['independent']=true;$v['pending_independent']=true;$v['reason']='source_inactive';++$v['version'];}
            }
            if($v!==$before){$state['summons'][$id]=$v;$events[]=['type'=>$v['status']==='despawned'?'summon.despawned':'summon.independent','entity_id'=>(string)$id,'status'=>$v['status'],'reason'=>$v['reason']];}
        }
        foreach($state['status_instances']??[] as $id=>$row)if($row['status']==='active'&&(($state['summons'][$row['target_entity_id']]['status']??null)==='despawned'||(($state['summons'][$row['source_entity_id']]['status']??null)==='despawned'&&(isset($row['spec']['upkeep'])||$row['duration_unit']==='own_turn')))){
            $row['status']='removed';$row['stacks']=0;++$row['version'];$state['status_instances'][$id]=$row;
        }
        foreach($state['barriers'] as $id=>$pool)if($pool['status']==='active'){
            $allGone=$pool['targets']&&!array_filter($pool['targets'],static fn($target)=>($state['summons'][$target]['status']??null)!=='despawned');
            if(($state['summons'][$pool['source_entity_id']]['status']??null)==='despawned'||$allGone){$pool['status']='expired';++$pool['version'];$state['barriers'][$id]=$pool;}
        }
    }
    /** Called only by the existing Clock after mandatory ticks and status upkeep. */
    public static function boundary(array $state,array $boundary,string $key,array &$events):array
    {
        foreach(array_keys($state['summons']??[]) as $id){$v=$state['summons'][$id];if($v['status']!=='active'||$v['encounter_id']!==$boundary['encounter_id'])continue;
            $eligible=$v['unit']==='round'?$boundary['phase']==='round_end':($boundary['phase']==='entity_end'&&($boundary['entity_id']??null)===(string)$id);
            if($eligible&&$v['last_duration_boundary']!==$key){$v['remaining']=max(0,$v['remaining']-1);$v['last_duration_boundary']=$key;++$v['version'];if(!$v['remaining'])self::stop($v,'lifetime_end');$state['summons'][$id]=$v;$events[]=['type'=>$v['status']==='despawned'?'summon.despawned':'summon.duration','entity_id'=>(string)$id,'remaining'=>$v['remaining'],'reason'=>$v['reason']];}
            if($v['status']==='active'&&$v['upkeep']!==null&&$boundary['phase']==='entity_start'&&($boundary['entity_id']??null)===$v['source_entity_id']&&($v['last_upkeep']['boundary_key']??null)!==$key){
                $reason=null;$costs=$v['upkeep']['costs'];
                try{$paid=AF_ACS_Effects::simulate($state,['actor_id'=>$v['source_entity_id'],'costs'=>$costs,'effects'=>[]]);$state=$paid->serverState();$events[]=['type'=>'summon_upkeep_costs','trace'=>$paid->serverTrace()];}
                catch(AF_ACS_FormulaError $e){if(!in_array($e->diagnosticCode,['effects_cost_unaffordable','effects_resource_invalid','effects_actor_unavailable'],true))throw $e;$reason=$e->diagnosticCode;}
                $v=$state['summons'][$id];if($reason!==null)self::stop($v,'upkeep_shortage');$v['last_upkeep']=['boundary_key'=>$key,'paid'=>$reason===null,'reason'=>$reason,'costs'=>$costs];++$v['version'];$state['summons'][$id]=$v;$events[]=['type'=>$reason===null?'summon.upkeep':'summon.despawned','entity_id'=>(string)$id,'payment'=>$v['last_upkeep']];
            }
        }
        self::interrupt($state,$events);return $state;
    }
    public static function presencePolicy(array $dto):array
    {
        $d=$dto['data']['definition']??[];$e=$d['entries'][0]??[];$p=$e['presence_policy']??[];
        if(($dto['ready']??null)!==true||($dto['contract_id']??null)!=='IC-KB.read.v1'||($dto['source']['type']??null)!=='arpg_mechanics'||($d['service_kind']??null)!=='combat_template'||count($d['entries']??[])!==1)self::fail('ACS_PRESENCE_POLICY_UNSUPPORTED');
        self::fields($e,['schema','key','version','phases','limits','formula_refs','presence_policy']);self::fields($p,['schema','partial_withdraw','rejoin','requires_active']);
        if($e['schema']!=='af_kb.arpg.mechanic.v1'||$e['phases']!==[]||$e['limits']!==[]||$e['formula_refs']!==[]||($p['schema']!=='ic-acs.presence.v1'||$p['partial_withdraw']!==true||$p['rejoin']!==true||$p['requires_active']!==true))self::fail('ACS_PRESENCE_POLICY_UNSUPPORTED');
        return $p;
    }
    public static function encounterEnd(array $summons,string $eid):array
    {
        foreach($summons as &$v)if($v['encounter_id']===$eid&&$v['status']==='active'&&in_array('encounter_end',$v['policy']['disappear_on'],true))self::stop($v,'encounter_end');unset($v);return $summons;
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
        $o=$entry['object_definition'];if(($o['schema']??null)==='ic-acs.object-template.v1'){self::fields($o,['schema','name','properties']);self::fields($o['properties'],['description','tags']);}require_once __DIR__.'/Environment.php';AF_ACS_Environment::definition($o);
        return ['hp'=>null,'stats'=>null,'properties'=>$o];
    }
    public static function compile(array $dto): array
    {
        $d=$dto['data']['definition'];
        $fields=['target_policy','schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),$fields) || ($d['type']??null)!=='active' || ($d['targeting']??null)!=='self')self::fail('ACS_SPAWN_ABILITY_UNSUPPORTED');
        foreach(['range','cast_time','cooldown','cooldown_value','duration','duration_value','max_charges','resources','cost_value','cost_resource','costs','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','damage_value','heal_value','shield_value','auto_hit','can_crit'] as $f)if(!empty($d[$f]))self::fail('ACS_SPAWN_ABILITY_UNSUPPORTED');
        require_once __DIR__.'/Control.php';if(AF_ACS_Control::targetPolicy($d)['kind']!=='self_only')self::fail('ACS_SPAWN_TARGET_UNSUPPORTED');
        $cost=$d['action_cost']??null;if(!is_int($cost)||$cost<1||$cost>2147483647)self::fail('ACS_SPAWN_COST_UNSUPPORTED');
        $spawns=[];
        foreach($dto['data']['ability_profile']['effects']??[] as $effect){
            $p=$effect['parameters'];if(array_diff(array_keys($p),['kind','effect_type','target','targeting','damage_type','spawn','notes']) || ($p['effect_type']??null)!=='summon' || ($p['target']??null)!=='self')self::fail('ACS_SPAWN_EFFECT_UNSUPPORTED');
            $i=$effect['source_index'];$spec=self::descriptor($p['spawn']??[]);$template=$dto['spawn_templates'][$i]??[];self::template($spec,$template);
            $spawns[]=['effect_index'=>$i,'spec'=>$spec,'template'=>$template];
        }
        if(!$spawns || count($spawns)>16)self::fail('ACS_SPAWN_EFFECT_UNSUPPORTED');
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],
            'target_policy'=>['kind'=>'self_only'],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$cost]],'targeting'=>'self','spawns'=>$spawns];
    }
    public static function plan(array $ability,string $actor,int $index,array $source,array &$counts): array
    {
        if(($source['kind']??null)==='summon')self::fail('ACS_SPAWN_CONTROL_UNSUPPORTED');
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
