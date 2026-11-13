<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';

/** Directed, non-geometric zones. Reads are tracked against the initial projection. */
final class AF_ACS_Spatial
{
    public const DISTANCES=['engaged','near','medium','far','unreachable'];
    private static function fail(string $code='ACS_SPATIAL_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(array $v,array $keys):void {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    public static function definition(array $dto):array
    {
        $d=$dto['data']['definition']??[];if(array_diff(array_keys($d),['schema','type_profile','version','revision','service_kind','entries']))self::fail();$entries=$d['entries']??[];
        if(($dto['ready']??null)!==true||($dto['source']['type']??null)!=='arpg_mechanics'||($d['service_kind']??null)!=='combat_template'||count($entries)!==1)self::fail();
        $e=$entries[0];self::fields($e,['schema','key','version','phases','limits','formula_refs','spatial_definition']);
        if($e['schema']!=='af_kb.arpg.mechanic.v1'||$e['phases']!==[]||$e['limits']!==[]||$e['formula_refs']!==[])self::fail();
        $s=$e['spatial_definition'];self::fields($s,['schema','mode','default_zone','zones','connections','legacy_range','leave_engagement','engage_ap','leave_ap','line_of_effect']);
        if($s['schema']!=='ic-acs.spatial.v1'||!in_array($s['mode'],['single_zone','zones'],true)||!in_array($s['leave_engagement'],['allow','block'],true)||!is_bool($s['line_of_effect'])||!is_array($s['zones'])||!array_is_list($s['zones'])||!$s['zones']||count($s['zones'])>64||!is_array($s['connections'])||!array_is_list($s['connections'])||count($s['connections'])>512)self::fail();
        AF_ACS_Numeric::integer($s['engage_ap'],1,1000000);AF_ACS_Numeric::integer($s['leave_ap'],1,1000000);self::range(['schema'=>'ic-acs.range.v1','distances'=>$s['legacy_range'],'line_of_effect'=>false]);$keys=[];
        foreach($s['zones'] as $z){self::fields($z,['key','name','visibility','line_of_effect']);if(!is_string($z['key'])||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$z['key'])||isset($keys[$z['key']])||!is_string($z['name'])||trim($z['name'])===''||mb_strlen($z['name'])>191||!in_array($z['visibility'],['public','gm'],true)||!is_bool($z['line_of_effect']))self::fail();$keys[$z['key']]=true;}
        if($s['mode']==='single_zone'?(count($keys)!==1||!isset($keys[$s['default_zone']??''])):$s['default_zone']!==null)self::fail();$edges=[];
        foreach($s['connections'] as $c){if(array_diff(array_keys($c),['from','to','status','range','ap_cost','line_of_effect'])||array_diff(['from','to','status','range','line_of_effect'],array_keys($c)))self::fail();$key=$c['from'].':'.$c['to'];if(!isset($keys[$c['from']],$keys[$c['to']])||$c['from']===$c['to']||isset($edges[$key])||!in_array($c['status'],['open','blocked'],true)||!in_array($c['range'],['near','medium','far'],true)||!is_bool($c['line_of_effect']))self::fail();AF_ACS_Numeric::integer($c['ap_cost']??1,1,1000000);$edges[$key]=true;}
        return $s;
    }
    public static function range(mixed $r):?array
    {
        if(in_array($r,[null,'',[],0,'0',false],true))return null;
        if(!is_array($r))self::fail();self::fields($r,['schema','distances','line_of_effect']);if($r['schema']!=='ic-acs.range.v1'||!is_array($r['distances'])||!array_is_list($r['distances'])||!$r['distances']||array_diff($r['distances'],self::DISTANCES)||in_array('unreachable',$r['distances'],true)||!is_bool($r['line_of_effect']))self::fail();return $r;
    }
    public static function empty():array {return ['schema_version'=>1,'pin'=>null,'zones'=>[],'connections'=>[],'positions'=>[],'engagements'=>[],'reads'=>[]];}
    public static function instance(array $s):void
    {
        self::fields($s,['schema_version','pin','zones','connections','positions','engagements','reads']);if($s['schema_version']!==1)self::fail();if($s['pin']!==null)self::definition($s['pin']);
        foreach(['zones','connections','positions','engagements','reads'] as $k)if(!is_array($s[$k]))self::fail();
        foreach($s['positions'] as $id=>$p)if((string)$id!==$p['entity_id']||$p['zone_id']!==null&&!isset($s['zones'][$p['zone_id']])||!in_array($p['visibility'],['known','gm'],true)||!is_int($p['version'])||$p['version']<1)self::fail();
        foreach($s['connections'] as $key=>$c)if($key!==$c['from'].':'.$c['to']||!isset($s['zones'][$c['from']],$s['zones'][$c['to']])||!in_array($c['status'],['open','blocked'],true))self::fail();
        foreach($s['engagements'] as $key=>$e)if($key!==self::pair($e['a'],$e['b'])||!in_array($e['status'],['active','inactive'],true))self::fail();
    }
    public static function pair(string $a,string $b):string
    {if($a===$b)self::fail('ACS_SPATIAL_TARGET_BLOCKED');return strlen($a)<strlen($b)||strlen($a)===strlen($b)&&strcmp($a,$b)<0?$a.':'.$b:$b.':'.$a;}
    private static function read(array &$s,string $type,string $key):mixed
    {$token=$type.':'.$key;if(!array_key_exists($token,$s['reads']))$s['reads'][$token]=$s[$type][$key]??null;return $s[$type][$key]??null;}
    public static function position(array &$s,string $id):?string
    {
        $p=self::read($s,'positions',$id);if($p!==null){if($p['zone_id']!==null)self::read($s,'zones',$p['zone_id']);return $p['zone_id'];}
        if($s['pin']===null)return null;$d=self::definition($s['pin']);if($d['mode']==='single_zone')foreach($s['zones'] as $z)if($z['key']===$d['default_zone']){self::read($s,'zones',$z['id']);return $z['id'];}return null;
    }
    public static function disclosed(array $s,string $id,bool $gm):bool
    {if($s['pin']===null||$gm)return true;if(($s['positions'][$id]['visibility']??'known')==='gm')return false;$zone=self::position($s,$id);return $zone!==null&&($s['zones'][$zone]['visibility']??null)==='public';}
    public static function distance(array &$s,string $actor,string $target):string
    {
        $from=self::position($s,$actor);$to=self::position($s,$target);if($from===null||$to===null)return 'unreachable';$contact=self::read($s,'engagements',self::pair($actor,$target));
        if($from===$to)return ($contact['status']??null)==='active'?'engaged':'near';$edge=self::read($s,'connections',$from.':'.$to);return ($edge['status']??null)==='open'?$edge['range']:'unreachable';
    }
    public static function check(array &$s,string $actor,string $target,?array $range):void
    {
        if($actor===$target)return;
        if($s['pin']===null){if($range!==null)self::fail();return;}$d=self::definition($s['pin']);$range??=['schema'=>'ic-acs.range.v1','distances'=>$d['legacy_range'],'line_of_effect'=>false];$distance=self::distance($s,$actor,$target);
        if(!in_array($distance,$range['distances'],true))self::fail('ACS_SPATIAL_TARGET_BLOCKED');
        if($range['line_of_effect']){if(!$d['line_of_effect'])self::fail();$from=self::position($s,$actor);$to=self::position($s,$target);$loe=$from===$to?$s['zones'][$from]['line_of_effect']:$s['connections'][$from.':'.$to]['line_of_effect'];if(!$loe)self::fail('ACS_SPATIAL_TARGET_BLOCKED');}
    }
    private static function contacts(array &$s,string $id):array
    {$rows=[];foreach($s['engagements'] as $key=>$e)if(in_array($id,[$e['a'],$e['b']],true))$rows[$key]=$e;$token='contacts:'.$id;if(!array_key_exists($token,$s['reads'])){$baseline=$rows;foreach($s['reads'] as $readKey=>$old)if(str_starts_with($readKey,'engagements:')){$pair=substr($readKey,12);if(in_array($id,explode(':',$pair),true)){if($old===null)unset($baseline[$pair]);else $baseline[$pair]=$old;}}ksort($baseline);$s['reads'][$token]=$baseline;}return $rows;}
    public static function move(array &$s,string $entity,string $destination,bool $forced=false,bool $teleport=false):int
    {
        if($s['pin']===null||!isset($s['zones'][$destination]))self::fail();$d=self::definition($s['pin']);$from=self::position($s,$entity);self::read($s,'zones',$destination);if($from===null||$from===$destination)self::fail('ACS_SPATIAL_TARGET_BLOCKED');$cost=0;
        if(!$teleport){$edge=self::read($s,'connections',$from.':'.$destination);if(($edge['status']??null)!=='open')self::fail('ACS_SPATIAL_TARGET_BLOCKED');$cost=$edge['ap_cost'];}
        foreach(self::contacts($s,$entity) as $key=>$e)if($e['status']==='active'){if(!$forced&&$d['leave_engagement']==='block')self::fail('ACS_SPATIAL_CONTACT_BLOCKED');self::read($s,'engagements',$key);$s['engagements'][$key]['status']='inactive';++$s['engagements'][$key]['version'];}
        $old=$s['positions'][$entity]??null;$s['positions'][$entity]=['entity_id'=>$entity,'zone_id'=>$destination,'version'=>($old['version']??0)+1,'visibility'=>$old['visibility']??'known'];return $cost;
    }
    public static function contact(array &$s,string $a,string $b,bool $enter):int
    {
        if($s['pin']===null)self::fail();$d=self::definition($s['pin']);$key=self::pair($a,$b);$old=self::read($s,'engagements',$key);if(self::position($s,$a)!==self::position($s,$b)||self::position($s,$a)===null||(($old['status']??'inactive')==='active')===$enter)self::fail('ACS_SPATIAL_TARGET_BLOCKED');
        $parts=explode(':',$key);$s['engagements'][$key]=['a'=>$parts[0],'b'=>$parts[1],'version'=>($old['version']??0)+1,'status'=>$enter?'active':'inactive'];return $enter?$d['engage_ap']:$d['leave_ap'];
    }
    public static function destination(array &$s,string $key):string
    {
        foreach($s['zones'] as $id=>$z)if($z['key']===$key){self::read($s,'zones',(string)$id);return (string)$id;}self::fail();
    }
    public static function delta(array $before,array $after):array
    {$out=[];foreach(['positions','engagements'] as $type)foreach($after[$type] as $key=>$row)if(($before[$type][$key]??null)!==$row)$out[$type][$key]=['before'=>$before[$type][$key]??null,'after'=>$row];return $out;}
    public static function summary(array $before,array $after):?string
    {if(!$after['reads'])return null;return hash('sha256',serialize(['reads'=>$after['reads'],'changes'=>self::delta($before,$after)]));}
    public static function compileEffect(array $dto):array
    {
        $d=$dto['data']['definition'];$e=$d['spatial_effect'];self::fields($e,['schema','kind','destination','break_engagements']);if($e['schema']!=='ic-acs.forced-movement.v1'||!in_array($e['kind'],['forced_move','teleport'],true)||!is_string($e['destination'])||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$e['destination'])||$e['break_engagements']!==true)self::fail();
        $copy=$d;unset($copy['spatial_effect'],$copy['range'],$copy['spatial_range']);$metadata=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','cooldown_unit','duration_unit','level_cap','magic_direction_ref','damage_element','element','target_policy','action_cost'];foreach($copy as $key=>$v)if(!in_array($key,$metadata,true)&&!in_array($v,[null,'',[],false,0],true))self::fail();
        if(($dto['ready']??null)!==true||($d['type']??null)!=='active'||($d['targeting']??null)!=='single_enemy')self::fail();$cost=AF_ACS_Numeric::integer($d['action_cost']??null,1,1000000);
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$cost]],'target_policy'=>AF_ACS_Control::targetPolicy($d),'range'=>self::range($d['spatial_range']??($d['range']??null)),'spatial_effect'=>$e];
    }
}
