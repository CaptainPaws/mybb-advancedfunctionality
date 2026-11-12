<?php
declare(strict_types=1);
require_once __DIR__.'/Control.php';
require_once __DIR__.'/Formula.php';

/** Typed predicates over existing pinned status instances; no writes, timers, RNG or retargeting. */
final class AF_ACS_Behavior
{
    private static function fail():never {throw new AF_ACS_FormulaError('ACS_BEHAVIOR_UNSUPPORTED','behavior');}
    private static function keys(mixed $v,array $allowed=[]):array
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>64||count(array_unique($v,SORT_REGULAR))!==count($v))self::fail();
        foreach($v as $key)if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_.:-]{0,95}\z/D',$key)||$allowed&&!in_array($key,$allowed,true))self::fail();
        return $v;
    }
    public static function spec(mixed $s):array
    {
        if(!is_array($s)||count($s)!==6||array_diff(array_keys($s),['schema','priority','scope','compatibility','on_no_legal_target','rules'])||($s['schema']??null)!=='ic-acs.behavior-constraint.v1')self::fail();
        if(!is_int($s['priority'])||$s['priority'] < -2147483648||$s['priority']>2147483647)self::fail();
        if(!self::keys($s['scope'],['ordinary','reaction'])||!in_array($s['on_no_legal_target'],['block','ignore_rule'],true))self::fail();
        $c=$s['compatibility'];if(!is_array($c)||count($c)!==2||array_diff(array_keys($c),['group','mode'])||!is_string($c['group']??null)||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$c['group'])||!in_array($c['mode']??null,['exclusive','combine'],true))self::fail();
        if(!is_array($s['rules'])||!array_is_list($s['rules'])||!$s['rules']||count($s['rules'])>16)self::fail();
        foreach($s['rules'] as $r){
            if(!is_array($r))self::fail();
            if(($r['kind']??null)==='action_restriction'){
                if(count($r)!==4||array_diff(array_keys($r),['kind','mode','actions','ability_keys'])||!in_array($r['mode']??null,['allow','deny'],true)||!self::keys($r['actions']??null,['ordinary','reaction','item_consume','equipment_change','spawn']))self::fail();
                self::keys($r['ability_keys']??null);
            }elseif(!in_array($r['kind']??null,['target_lock','target_priority','target_exclusion'],true)||count($r)!==2||array_diff(array_keys($r),['kind','target'])||!in_array($r['target']??null,['source','self'],true))self::fail();
        }
        return $s;
    }
    /** Highest priority, then oldest application, then stable identity. Never status names. */
    private static function active(array $instances,string $actor,string $scope,array $world):array
    {
        $groups=[];
        foreach($instances as $row){
            if(($row['status']??null)!=='active'||($row['target_entity_id']??null)!==$actor||($row['remaining_duration']??0)<1||!array_key_exists('behavior_constraint',$row['spec']['mechanical_parameters']??[]))continue;
            if(($row['spec']['scope']??null)!=='combat'||isset($world['session_id'])&&$row['session_id']!==$world['session_id']||isset($world['encounter_id'])&&$row['encounter_id']!==$world['encounter_id'])continue;
            $spec=self::spec($row['spec']['mechanical_parameters']['behavior_constraint']);if(!in_array($scope,$spec['scope'],true))continue;
            $row['behavior']=$spec;$groups[$spec['compatibility']['group']][]=$row;
        }
        $out=[];foreach($groups as $rows){
            usort($rows,static fn($a,$b)=>$b['behavior']['priority']<=>$a['behavior']['priority']?:$a['applied_order']<=>$b['applied_order']?:strcmp((string)$a['id'],(string)$b['id']));
            $modes=array_unique(array_column(array_column(array_column($rows,'behavior'),'compatibility'),'mode'));
            if(count($modes)!==1)self::fail();
            array_push($out,...($modes[0]==='exclusive'?[$rows[0]]:$rows));
        }
        usort($out,static fn($a,$b)=>$b['behavior']['priority']<=>$a['behavior']['priority']?:$a['applied_order']<=>$b['applied_order']?:strcmp((string)$a['id'],(string)$b['id']));return $out;
    }
    public static function constrained(array $instances,string $actor,string $scope,array $world=[]):bool
    {return (bool)self::active($instances,$actor,$scope,$world);}
    /** Candidates must be server-visible/selectable metadata, never the submitted target list. */
    public static function evaluate(array $instances,string $actor,string $ability,string $category,array $candidates,array $policy,array $world=[]):array
    {
        try {
            $scope=$category==='reaction'?'reaction':'ordinary';$rows=self::active($instances,$actor,$scope,$world);
            $legal=[];foreach($candidates as $id=>$candidate)if(AF_ACS_Control::allowed($policy,$candidate['relationship']??'', $candidate['kind']??''))$legal[]=(string)$id;
            foreach($rows as $row){$s=$row['behavior'];foreach($s['rules'] as $rule){
                if($rule['kind']==='action_restriction'){
                    $match=in_array($category,$rule['actions'],true)&&(!$rule['ability_keys']||in_array($ability,$rule['ability_keys'],true));
                    if(($rule['mode']==='allow')!==$match)return ['status'=>'BLOCKED','code'=>'ACS_BEHAVIOR_ACTION_BLOCKED','legal_targets'=>[]];
                    continue;
                }
                $id=$rule['target']==='source'?$row['source_entity_id']:$actor;
                // Hidden/unavailable sources never become candidates or public diagnostics.
                $available=in_array($id,$legal,true)&&($candidates[$id]['active']??true);
                if($rule['kind']==='target_exclusion'){$next=array_values(array_diff($legal,[$id]));if($next||$s['on_no_legal_target']==='block')$legal=$next;continue;}
                if($available)$legal=[$id];elseif($s['on_no_legal_target']==='block')return ['status'=>'BLOCKED','code'=>'ACS_BEHAVIOR_NO_LEGAL_TARGET','legal_targets'=>[]];
            }}
            if(count($legal)<($world['minimum_targets']??1))return ['status'=>'BLOCKED','code'=>'ACS_BEHAVIOR_NO_LEGAL_TARGET','legal_targets'=>[]];
            return ['status'=>'READY','code'=>null,'legal_targets'=>$legal];
        }catch(AF_ACS_FormulaError $e){if($e->getMessage()!=='ACS_BEHAVIOR_UNSUPPORTED')throw $e;return ['status'=>'UNSUPPORTED','code'=>'ACS_BEHAVIOR_UNSUPPORTED','legal_targets'=>[]];}
    }
    public static function check(array $instances,string $actor,string $ability,string $category,array $targets,array $candidates,array $policy,array $world=[]):void
    {
        if(!self::active($instances,$actor,$category==='reaction'?'reaction':'ordinary',$world))return;
        $result=self::evaluate($instances,$actor,$ability,$category,$candidates,$policy,$world);
        if($result['status']!=='READY')throw new AF_ACS_FormulaError($result['code'],'behavior');
        if(array_diff($targets,$result['legal_targets']))throw new AF_ACS_FormulaError('ACS_BEHAVIOR_TARGET_BLOCKED','behavior');
    }
}
