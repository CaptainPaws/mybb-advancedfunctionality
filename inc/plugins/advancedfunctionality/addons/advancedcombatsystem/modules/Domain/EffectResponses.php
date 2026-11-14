<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';
require_once __DIR__.'/CausalGraph.php';

/** Typed response data and structural plans. Damage/Effects remain the only mechanical writers. */
final class AF_ACS_EffectResponses
{
    public static function fail(string $code='ACS_EFFECT_RESPONSE_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(mixed $v,array $keys):void {if(!is_array($v)||array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    public static function reflect(mixed $v):array
    {try{return self::reflectValue($v);}catch(DomainException $e){self::fail();}}
    private static function reflectValue(mixed $v):array
    {
        self::fields($v,['schema','event_kind','phase','selection','priority','basis','factor','damage_type','damage_element','requires_active','chance','target_policy','range','environment_policy','chain']);
        if($v['schema']!=='ic-acs.reflect-effect.v1'||$v['event_kind']!=='combat.primary'||$v['phase']!=='after_damage'||$v['selection']!=='first'||!in_array($v['basis'],['reflect_absorbed','reflect_hp_loss','reflect_incoming'],true)||!is_bool($v['requires_active'])||!in_array($v['damage_type'],['physical','elemental','true'],true))self::fail();
        AF_ACS_Numeric::integer($v['priority'],-2147483648,2147483647);AF_ACS_Numeric::integer($v['chance'],0,100);if(AF_ACS_Numeric::number($v['factor'])<0)self::fail();
        if($v['damage_type']==='elemental'?(!is_string($v['damage_element'])||!preg_match('/\A[a-z][a-z0-9_]{0,47}\z/D',$v['damage_element'])):$v['damage_element']!==null)self::fail();
        AF_ACS_Control::targetPolicy(['targeting'=>'single_enemy','target_policy'=>$v['target_policy']]);if(AF_ACS_Spatial::range($v['range'])!==$v['range'])self::fail();$p=AF_ACS_Cover::policy($v['environment_policy']);if($p!==$v['environment_policy']||$p['penetrates_obstacles']||$p['affects_zone']!==null)self::fail();
        if($v['chain']!==null){self::fields($v['chain'],['max_depth','per_cause','receive','emit']);AF_ACS_Numeric::integer($v['chain']['max_depth'],2,2147483647);if($v['chain']['per_cause']!==1||!is_bool($v['chain']['receive'])||!is_bool($v['chain']['emit']))self::fail();}
        return $v;
    }
    public static function absorb(mixed $v):array
    {try{return self::absorbValue($v);}catch(DomainException $e){self::fail();}}
    private static function absorbValue(mixed $v):array
    {
        self::fields($v,['schema','mode','stage','priority','absorbs','resource','definition_hash','conversion','limit','requires_active']);
        if(!is_bool($v['requires_active'])||$v['schema']!=='ic-acs.absorb-effect.v1'||!in_array($v['mode'],['barrier','resource'],true)||!is_array($v['absorbs'])||!array_is_list($v['absorbs'])||!$v['absorbs']||array_diff($v['absorbs'],['physical','elemental'])||count(array_unique($v['absorbs']))!==count($v['absorbs']))self::fail();AF_ACS_Numeric::integer($v['priority'],-2147483648,2147483647);
        if($v['mode']==='barrier'){if($v['stage']!=='existing_barrier_queue'||$v['resource']!==null||$v['definition_hash']!==null||$v['conversion']!==null||$v['limit']!==null)self::fail();}
        else {if($v['stage']!=='after_barriers_before_hp'||!is_string($v['resource'])||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$v['resource'])||in_array($v['resource'],['hp','ap'],true)||!is_string($v['definition_hash'])||!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$v['definition_hash']))self::fail();self::fields($v['conversion'],['resource_per_damage','rounding']);if($v['conversion']['resource_per_damage']!==1||$v['conversion']['rounding']!=='half_up')self::fail();AF_ACS_Numeric::integer($v['limit'],1,9007199254740991);}
        return $v;
    }
    public static function has(array $state,?string $kind=null):bool
    {foreach($state['status_instances']??[] as $r)foreach($kind===null?['reflect_effect','absorb_effect']:[$kind] as $k)if($r['status']==='active'&&isset($r['spec']['mechanical_parameters'][$k]))return true;return false;}
    public static function validateStatus(array $s):void
    {
        $m=$s['mechanical_parameters'];$keys=array_intersect(array_keys($m),['reflect_effect','absorb_effect']);if(!$keys)return;
        if(count($keys)!==1||count($m)!==1||$s['scope']!=='combat'||$s['repeat_rule']!=='independent'||$s['source_scope']!=='source'||$s['duration']['value']<1||isset($s['upkeep'])||!empty($s['periodic']))self::fail();
        isset($m['reflect_effect'])?self::reflect($m['reflect_effect']):self::absorb($m['absorb_effect']);
    }
    /** Existing entity-owned resource row definition, never an invented balance or HTTP capability. */
    public static function resourceDefinitions(array $rows):array
    {
        $out=[];foreach($rows as $r){if(in_array($r['resource_key'],['hp','ap'],true))continue;try{$d=json_decode($r['definition_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException){continue;}$s=$d['source']??null;
            if(($d['schema_version']??null)!==1||!is_array($s)||array_diff(array_keys($s),['current','maximum','is_integer'])||($s['is_integer']??null)!==true||!is_int($s['maximum']??null)||$s['maximum']!==(int)$r['maximum_value']||!is_int($s['current']??null)||$s['current']<0||$s['current']>$s['maximum'])continue;
            $out[$r['resource_key']]='sha256:'.hash('sha256',$r['definition_json']);
        }return $out;
    }
    public static function eligible(array $state,string $entity,string $kind,array $scope):array
    {
        $rows=[];foreach($state['status_instances']??[] as $r)if($r['status']==='active'&&$r['session_id']===$scope['session_id']&&$r['encounter_id']===$scope['encounter_id']&&$r['target_entity_id']===$entity&&isset($r['spec']['mechanical_parameters'][$kind])){
            if($r['source_entity_id']!==$entity)self::fail();self::validateStatus($r['spec']);if(!AF_ACS_FormLayers::enabled($state['status_instances'],$r)||!AF_ACS_FormLayers::ability($state['status_instances'],$entity,$r['effect_definition_ref']['key'],$r['response_requirement']??null))continue;$overlay=AF_ACS_Control::overlay($state['status_instances'],$entity,$scope['encounter_id']);if($overlay!==null&&!in_array($r['effect_definition_ref']['key'],$overlay['spec']['mechanical_parameters']['control_effect']['ability_keys'],true))continue;$rows[]=$r;
        }
        usort($rows,static fn($a,$b)=>$a['spec']['mechanical_parameters'][$kind]['priority']<=>$b['spec']['mechanical_parameters'][$kind]['priority']?:$a['applied_order']<=>$b['applied_order']?:strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id']));return $rows;
    }
    /** One selected response at each edge; explicit emit/receive, no implicit reflect-of-reflect. */
    public static function chain(array $state,string $attacker,string $target,string $root,array $scope):array
    {
        $nodes=[$root=>['children'=>[]]];$path=[];$parent=$root;$depth=1;$maximum=2;$reflected=false;
        while(true){$rows=self::eligible($state,$target,'reflect_effect',$scope);if($reflected)$rows=array_values(array_filter($rows,static fn($r)=>($r['spec']['mechanical_parameters']['reflect_effect']['chain']['receive']??false)===true));$row=$rows[0]??null;if($row===null)break;$p=$row['spec']['mechanical_parameters']['reflect_effect'];
            if(!preg_match('/\A[1-9][0-9]*\z/D',$row['id']))self::fail();$id=$root.':reflect:'.$row['id'];$nodes[$parent]['children'][]=$id;
            if(isset($nodes[$id])){AF_ACS_CausalGraph::order($nodes,[$root],$maximum);self::fail('ACS_CAUSAL_CYCLE');}
            $nodes[$id]=['children'=>[]];$maximum=$reflected?min($maximum,$p['chain']['max_depth']??2):($p['chain']['max_depth']??2);
            AF_ACS_CausalGraph::order($nodes,[$root],$maximum);$path[]=['id'=>$id,'parent'=>$parent,'effect_id'=>$row['id'],'source'=>$target,'target'=>$attacker,'depth'=>$depth,'policy'=>$p];
            if(($p['chain']['emit']??false)!==true)break;$parent=$id;[$attacker,$target]=[$target,$attacker];$reflected=true;++$depth;
        }return $path;
    }
    public static function root(string $cause,string $original,string $resolved):string {return $cause.':target:'.$original.':resolved:'.$resolved.':primary';}
    /** Potential chains are sealed conservatively, including possible registered intercept recipients. */
    public static function requests(array $context,array $actions):array
    {
        $out=[];foreach($actions as $index=>$d){$a=$d['action'];$ability=$context['action_abilities'][$index]??($context['abilities'][$a['actor_id']][$a['ability_key']]??null);if(empty($ability['components'])||isset($ability['prepared_action']))continue;$cause='sealed-action:'.$d['id'];foreach($a['targets'] as $original){$targets=[$original];if(count($a['targets'])===1)foreach($context['reactions']??[] as $r)if($r['status']==='active'&&!empty($r['enabled'])&&($r['spec']['effect']['kind']??null)==='intercept'&&in_array($original,$r['protected_targets']??[],true))$targets[]=$r['entity_id'];
            foreach(array_unique($targets) as $resolved)foreach(self::chain($context['state'],$a['actor_id'],$resolved,self::root($cause,$original,$resolved),$context['status_scope']) as $edge)if($edge['policy']['chance']>0&&$edge['policy']['chance']<100)$out[$edge['id']]=$edge;
        }}return $out;
    }
}
