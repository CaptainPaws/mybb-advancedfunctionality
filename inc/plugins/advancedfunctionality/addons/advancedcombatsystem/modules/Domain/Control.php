<?php
declare(strict_types=1);
/** Independent relationship targeting and temporary controller overlays. No owner writes or clocks. */
final class AF_ACS_Control
{
    private static function fail(string $code):never {throw new DomainException($code);}
    public static function targetPolicy(array $d):array
    {
        $p=$d['target_policy']??['kind'=>($d['targeting']??null)==='self'?'self_only':'hostile_only'];
        if(is_string($p))$p=['kind'=>$p];
        if(!is_array($p)||array_diff(array_keys($p),['kind','relationships','entity_kinds'])||!in_array($p['kind']??null,['hostile_only','allied_only','self_only','any_entity','custom'],true))self::fail('ACS_TARGET_POLICY_UNSUPPORTED');
        if($p['kind']==='custom'){
            foreach(['relationships'=>['self','allied','neutral','hostile'],'entity_kinds'=>['character','pet','npc','monster','summon']] as $k=>$allowed)if(!is_array($p[$k]??null)||!array_is_list($p[$k])||!$p[$k]||array_diff($p[$k],$allowed)||count(array_unique($p[$k]))!==count($p[$k]))self::fail('ACS_TARGET_POLICY_UNSUPPORTED');
        }elseif(count($p)!==1)self::fail('ACS_TARGET_POLICY_UNSUPPORTED');
        return $p;
    }
    public static function allowed(array $p,string $relationship,string $kind):bool
    {
        if(($p['kind']??null)==='object_only')return $kind==='object';
        if(!in_array($kind,['character','pet','npc','monster','summon'],true))return false;
        $relationship=$relationship==='ally'?'allied':$relationship;
        return match($p['kind']){'hostile_only'=>$relationship==='hostile','allied_only'=>in_array($relationship,['allied','self'],true),'self_only'=>$relationship==='self','any_entity'=>true,
            'custom'=>in_array($relationship,$p['relationships'],true)&&in_array($kind,$p['entity_kinds'],true),default=>false};
    }
    public static function spec(array $s):void
    {
        if(array_diff(array_keys($s),['schema','controller','priority','permitted_actions','ability_keys'])||count($s)!==5||$s['schema']!=='ic-acs.control-effect.v1'||$s['controller']!=='caster'||!is_int($s['priority'])||abs($s['priority'])>2147483647||$s['permitted_actions']!==['ordinary']||!is_array($s['ability_keys'])||!array_is_list($s['ability_keys'])||!$s['ability_keys']||count($s['ability_keys'])>64||count(array_unique($s['ability_keys']))!==count($s['ability_keys']))self::fail('ACS_CONTROL_EFFECT_UNSUPPORTED');
        foreach($s['ability_keys'] as $key)if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$key))self::fail('ACS_CONTROL_EFFECT_UNSUPPORTED');
    }
    public static function overlay(array $instances,string $target,?string $encounter=null):?array
    {
        $rows=[];foreach($instances as $row)if($row['status']==='active'&&$row['target_entity_id']===$target&&($encounter===null||$row['encounter_id']===$encounter)&&isset($row['spec']['mechanical_parameters']['control_effect'])){
            self::spec($row['spec']['mechanical_parameters']['control_effect']);if(($row['remaining_duration']??0)<1)self::fail('ACS_CONTROL_EFFECT_STATE_INVALID');
            if(!isset($row['control_overlay'])||!is_array($row['control_overlay']))self::fail('ACS_CONTROL_EFFECT_CONTEXT_REQUIRED');
            $rows[]=$row;
        }
        usort($rows,static fn($a,$b)=>$b['spec']['mechanical_parameters']['control_effect']['priority']<=>$a['spec']['mechanical_parameters']['control_effect']['priority']?:$b['applied_order']<=>$a['applied_order']);
        return $rows[0]??null;
    }
    public static function stateHash(array $instances):?string
    { $rows=array_filter($instances,static fn($r)=>isset($r['spec']['mechanical_parameters']['control_effect']));return $rows?hash('sha256',serialize($rows)):null;}
    public static function action(?array $grant,string $key):void
    {
        if(isset($grant['control_overlay'])&&!in_array($key,$grant['control_overlay']['ability_keys'],true))self::fail('ACS_CONTROL_EFFECT_ACTION_DENIED');
    }
    public static function friendlyFire(array $warnings,array $dependencies):array
    {
        return ['required'=>(bool)$warnings,'warnings'=>$warnings,'confirmation_hash'=>$warnings?hash('sha256',serialize([$warnings,$dependencies])):null];
    }
}
