<?php
declare(strict_types=1);
require_once __DIR__.'/Spatial.php';
/** Explicit object policies only. No geometry, RNG, SQL or damage formula. */
final class AF_ACS_Cover
{
    private static function fail(string $code='ACS_COVER_UNSUPPORTED'):never{throw new DomainException($code);}
    private static function fields(array $v,array $keys):void{if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    public static function definition(mixed $d):?array
    {
        if($d===null)return null;if(!is_array($d))self::fail();self::fields($d,['schema','category','capacity','occupy_ap','leave_ap','speed_bonus','def_bonus','res_bonuses','blocks_line_of_effect','same_zone','on_move','on_destroy']);
        if($d['schema']!=='ic-acs.cover.v1'||!in_array($d['category'],['partial','substantial','complete'],true)||$d['same_zone']!==true||$d['on_move']!=='leave'||$d['on_destroy']!=='remove'||!is_bool($d['blocks_line_of_effect'])||!is_array($d['res_bonuses']))self::fail();
        foreach(['capacity','occupy_ap','leave_ap'] as $k)AF_ACS_Numeric::integer($d[$k],1,1000000);foreach(['speed_bonus','def_bonus'] as $k)AF_ACS_Numeric::integer($d[$k],0,1000000);
        foreach($d['res_bonuses'] as $key=>$value){if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_]{0,47}\z/D',$key))self::fail();AF_ACS_Numeric::integer($value,0,1000000);}return $d;
    }
    public static function obstacle(mixed $d):?array
    {
        if($d===null)return null;if(!is_array($d))self::fail();self::fields($d,['schema','edges']);if($d['schema']!=='ic-acs.obstacle.v1'||!is_array($d['edges'])||!array_is_list($d['edges'])||!$d['edges']||count($d['edges'])>64)self::fail();$seen=[];
        foreach($d['edges'] as $edge){self::fields($edge,['from','to','blocks_movement','blocks_line_of_effect','on_destroy']);foreach(['from','to'] as $k)if(!is_string($edge[$k])||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$edge[$k]))self::fail();$key=$edge['from'].':'.$edge['to'];if($edge['from']===$edge['to']||isset($seen[$key])||!is_bool($edge['blocks_movement'])||!is_bool($edge['blocks_line_of_effect']))self::fail();$seen[$key]=true;
            if($edge['on_destroy']!==null){self::fields($edge['on_destroy'],['status','line_of_effect']);if(!in_array($edge['on_destroy']['status'],['open','blocked'],true)||!is_bool($edge['on_destroy']['line_of_effect']))self::fail();}}
        return $d;
    }
    public static function policy(mixed $d):array
    {
        if($d===null)return ['schema'=>'ic-acs.environment-target.v1','ignores_cover'=>false,'requires_line_of_effect'=>false,'affects_zone'=>null,'penetrates_obstacles'=>false];
        if(!is_array($d))self::fail();self::fields($d,['schema','ignores_cover','requires_line_of_effect','affects_zone','penetrates_obstacles']);if($d['schema']!=='ic-acs.environment-target.v1')self::fail();foreach(['ignores_cover','requires_line_of_effect','penetrates_obstacles'] as $k)if(!is_bool($d[$k]))self::fail();if($d['affects_zone']!==null&&(!is_string($d['affects_zone'])||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$d['affects_zone'])))self::fail();return $d;
    }
    /** Keep old non-cover dependencies unchanged, but do not invalidate every attack for an unrelated wall. */
    public static function legacyObjects(array $objects):array{return array_filter($objects,static fn($o)=>!isset($o['definition']['cover'])&&!isset($o['definition']['obstacle']));}
    public static function initial(array $assignments,array $objects):array{return ['assignments'=>$assignments,'reads'=>[],'initial_assignments'=>$assignments,'initial_objects'=>$objects];}
    public static function select(array $assignments,array $objects,string $token):mixed
    {
        [$type,$id]=explode(':',$token,2);return match($type){
            'object'=>$objects[$id]??null,
            'target'=>array_filter($assignments,static fn($r)=>$r['protected_entity_id']===$id),
            'capacity'=>array_filter($assignments,static fn($r)=>$r['cover_entity_id']===$id),
            'edge'=>array_filter($objects,static function($o)use($id){foreach($o['definition']['obstacle']['edges']??[] as $e)if($e['from'].':'.$e['to']===$id)return true;return false;}),
            default=>throw new DomainException('ACS_COVER_UNSUPPORTED')};
    }
    public static function read(array &$state,string $token):void
    {if(!isset($state['cover']))return;if(!array_key_exists($token,$state['cover']['reads']))$state['cover']['reads'][$token]=self::select($state['cover']['initial_assignments'],$state['cover']['initial_objects'],$token);}
    public static function assigned(array &$state,string $target):?array
    {
        self::read($state,'target:'.$target);$found=null;foreach($state['cover']['assignments']??[] as $r)if($r['protected_entity_id']===$target&&$r['status']==='active'){
            self::read($state,'object:'.$r['cover_entity_id']);$o=$state['objects'][$r['cover_entity_id']]??null;if(!$o||$o['status']!=='active')continue;
            $s=&$state['spatial'];$a=AF_ACS_Spatial::position($s,$target);$b=AF_ACS_Spatial::position($s,$r['cover_entity_id']);unset($s);if($a===null||$a!==$b)continue;if($found!==null)self::fail();$found=$r;
        }return $found;
    }
    public static function assign(array &$state,string $cover,string $target,bool $enter):int
    {
        self::read($state,'object:'.$cover);$o=$state['objects'][$cover]??null;$d=self::definition($o['definition']['cover']??null);if(!$o||!$d||$enter&&$o['status']!=='active')self::fail('ACS_COVER_BLOCKED');
        self::read($state,'target:'.$target);self::read($state,'capacity:'.$cover);$key=$cover.':'.$target;$old=$state['cover']['assignments'][$key]??null;
        if((($old['status']??'inactive')==='active')===$enter)self::fail('ACS_COVER_BLOCKED');
        if($enter){if(self::assigned($state,$target)!==null)self::fail('ACS_COVER_BLOCKED');$used=0;foreach($state['cover']['assignments'] as $r)if($r['cover_entity_id']===$cover&&$r['status']==='active')++$used;if($used>=$d['capacity'])self::fail('ACS_COVER_BLOCKED');$s=&$state['spatial'];$a=AF_ACS_Spatial::position($s,$cover);$b=AF_ACS_Spatial::position($s,$target);unset($s);if($a===null||$a!==$b)self::fail('ACS_COVER_BLOCKED');}
        $state['cover']['assignments'][$key]=['cover_entity_id'=>$cover,'protected_entity_id'=>$target,'status'=>$enter?'active':'inactive','assignment_version'=>($old['assignment_version']??0)+1,'policy'=>$d];return $enter?$d['occupy_ap']:$d['leave_ap'];
    }
    public static function edge(array &$state,string $from,string $to,bool $movement,bool $penetrates=false):void
    {
        $s=&$state['spatial'];if($s['pin']===null)return;$a=$s['zones'][$from]['key']??null;$b=$s['zones'][$to]['key']??null;unset($s);if($a===null||$b===null)self::fail();$key=$a.':'.$b;self::read($state,'edge:'.$key);
        foreach(self::select([], $state['objects']??[], 'edge:'.$key) as $o)if($o['status']==='active')foreach($o['definition']['obstacle']['edges'] as $e)if($e['from'].':'.$e['to']===$key&&($movement?$e['blocks_movement']:($e['blocks_line_of_effect']&&!$penetrates)))self::fail('ACS_COVER_BLOCKED');
    }
    public static function check(array &$state,string $actor,string $target,array $ability):?array
    {
        $p=self::policy($ability['environment_policy']??null);if($actor===$target)return null;if(!isset($state['cover']))return null;if(($state['spatial']['pin']??null)===null)foreach($state['objects']??[] as $object)if($object['status']==='active'&&isset($object['definition']['obstacle']))self::fail();
        if($actor!==$target&&($state['spatial']['pin']??null)!==null){$s=&$state['spatial'];$from=AF_ACS_Spatial::position($s,$actor);$to=AF_ACS_Spatial::position($s,$target);unset($s);if($from===null||$to===null)self::fail('ACS_COVER_BLOCKED');self::edge($state,$from,$to,false,$p['penetrates_obstacles']);}
        if($p['affects_zone']!==null){$s=&$state['spatial'];$to=AF_ACS_Spatial::position($s,$target);$zone=AF_ACS_Spatial::destination($s,$p['affects_zone']);unset($s);if($zone!==$to)self::fail('ACS_COVER_BLOCKED');}
        if($p['requires_line_of_effect']){$range=$ability['range']??['schema'=>'ic-acs.range.v1','distances'=>['engaged','near','medium','far'],'line_of_effect'=>true];$range['line_of_effect']=true;AF_ACS_Spatial::check($state['spatial'],$actor,$target,$range);}
        $r=self::assigned($state,$target);if(!$r)return null;if($r['policy']['blocks_line_of_effect']&&!$p['penetrates_obstacles'])self::fail('ACS_COVER_BLOCKED');return $p['ignores_cover']?null:$r['policy'];
    }
    public static function defenses(array $context,string $target,?array $d):array
    {
        if($d===null)return $context;$v=&$context['defenses'][$target];$v['def']=AF_ACS_Numeric::operation('add',$v['def'],$d['def_bonus']);foreach($d['res_bonuses'] as $key=>$bonus){if(!isset($v['resistances'][$key]))self::fail();$v['resistances'][$key]['value']=AF_ACS_Numeric::operation('add',$v['resistances'][$key]['value'],$bonus);}unset($v);return $context;
    }
    public static function moved(array &$state,string $entity):void
    {self::read($state,'target:'.$entity);foreach($state['cover']['assignments']??[] as $key=>$r)if($r['status']==='active'&&$r['protected_entity_id']===$entity){$state['cover']['assignments'][$key]['status']='inactive';++$state['cover']['assignments'][$key]['assignment_version'];}}
    /** Explicit terminal transition consequences; called by existing Effects exactly once per destruction. */
    public static function destroyed(array &$state,string $id):void
    {
        $o=$state['objects'][$id];if(isset($o['definition']['cover'])){self::read($state,'capacity:'.$id);foreach($state['cover']['assignments']??[] as $key=>$r)if($r['cover_entity_id']===$id&&$r['status']==='active'){$state['cover']['assignments'][$key]['status']='inactive';++$state['cover']['assignments'][$key]['assignment_version'];}}
        foreach($o['definition']['obstacle']['edges']??[] as $e)if($e['on_destroy']!==null){if(($state['spatial']['pin']??null)===null)self::fail();$s=&$state['spatial'];$from=AF_ACS_Spatial::destination($s,$e['from']);$to=AF_ACS_Spatial::destination($s,$e['to']);$key=$from.':'.$to;if(!isset($s['connections'][$key]))self::fail();if(!array_key_exists('connections:'.$key,$s['reads']))$s['reads']['connections:'.$key]=$s['connections'][$key];$s['connections'][$key]['status']=$e['on_destroy']['status'];$s['connections'][$key]['line_of_effect']=$e['on_destroy']['line_of_effect'];++$s['connections'][$key]['version'];unset($s);}
    }
    public static function summary(array $before,array $after):?string
    {
        if(!isset($after['cover']))return null;$changes=[];foreach($after['cover']['assignments'] as $k=>$r)if(($before['cover']['assignments'][$k]??null)!==$r)$changes[$k]=$r;$objects=[];foreach($after['objects']??[] as $id=>$o)if((isset($o['definition']['cover'])||isset($o['definition']['obstacle']))&&($before['objects'][$id]??null)!==$o)$objects[$id]=$o;
        return !$after['cover']['reads']&&!$changes&&!$objects?null:hash('sha256',serialize([$after['cover']['reads'],$changes,$objects]));
    }
}
