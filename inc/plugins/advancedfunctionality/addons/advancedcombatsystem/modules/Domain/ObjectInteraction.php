<?php
declare(strict_types=1);
require_once __DIR__.'/Formula.php';
require_once __DIR__.'/CausalGraph.php';

/** Explicit pinned KB interpreter. No object-specific handlers, I/O or callbacks. */
final class AF_ACS_ObjectInteraction
{
    private static function fail(string $code='ACS_INTERACTION_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(array $v,array $fields):void {if(array_diff(array_keys($v),$fields)||array_diff($fields,array_keys($v)))self::fail();}
    private static function token(mixed $v):void {if(!is_string($v)||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$v))self::fail();}
    private static function number(mixed $v,int $min=0,int $max=1000000):void {if(!is_int($v)||$v<$min||$v>$max)self::fail();}
    public static function isAction(string $key):bool {return str_starts_with($key,'object:');}
    public static function selection(string $key):array
    {if(!preg_match('/\Aobject:([1-9][0-9]{0,19}):([a-z][a-z0-9_-]{0,47})\z/D',$key,$m))self::fail();return [$m[1],$m[2]];}
    public static function definition(?array $d):?array
    {
        if($d===null)return null;
        self::fields($d,['schema','states','initial_state','progress','actions']);
        if($d['schema']!=='ic-acs.object-interactions.v1'||!is_array($d['states'])||!array_is_list($d['states'])||!$d['states']||count($d['states'])>32||count(array_unique($d['states']))!==count($d['states']))self::fail();
        foreach($d['states'] as $s)self::token($s);if(!in_array($d['initial_state'],$d['states'],true))self::fail();
        if($d['progress']!==null){self::fields($d['progress'],['initial','cap','decay_per_round']);self::number($d['progress']['cap'],1);self::number($d['progress']['initial'],0,$d['progress']['cap']);self::number($d['progress']['decay_per_round'],0,$d['progress']['cap']);}
        if(!is_array($d['actions'])||!array_is_list($d['actions'])||!$d['actions']||count($d['actions'])>16)self::fail();$keys=[];
        foreach($d['actions'] as $a){
            self::fields($a,['key','label','kind','actor_kinds','required_abilities','minimum_stats','allowed_states','costs','tool','check','outcomes','retry']);self::token($a['key']);
            if(isset($keys[$a['key']])||!is_string($a['label'])||trim($a['label'])===''||mb_strlen($a['label'])>96||!in_array($a['kind'],['disarm','unlock','activate','repair','interact'],true))self::fail();$keys[$a['key']]=true;
            foreach(['actor_kinds','required_abilities','allowed_states','costs','outcomes'] as $k)if(!is_array($a[$k])||!array_is_list($a[$k])||count($a[$k])>32)self::fail();
            if(!$a['actor_kinds']||array_diff($a['actor_kinds'],['character','pet','npc','monster','summon'])||!$a['allowed_states']||array_diff($a['allowed_states'],$d['states']))self::fail();foreach($a['required_abilities'] as $key)self::token($key);
            if(!is_array($a['minimum_stats'])||count($a['minimum_stats'])>32)self::fail();foreach($a['minimum_stats'] as $stat=>$n){self::token($stat);self::number($n);}
            $ap=0;$costKinds=[];foreach($a['costs'] as $cost){$resource=($cost['kind']??null)==='resource';self::fields($cost,$resource?['kind','resource','amount']:['kind','amount']);if(!in_array($cost['kind'],['ap','resource'],true))self::fail();if($resource){self::token($cost['resource']);if(in_array($cost['resource'],['hp','ap'],true))self::fail();}self::number($cost['amount'],1);$k=$cost['kind'].':'.($cost['resource']??'');if(isset($costKinds[$k]))self::fail();$costKinds[$k]=true;if($cost['kind']==='ap')$ap++;}if($ap!==1)self::fail();
            if($a['tool']!==null){self::fields($a['tool'],['mode','kb_key','qty']);if(!in_array($a['tool']['mode'],['requires','consumes'],true)||$a['tool']['qty']!==1)self::fail();self::token($a['tool']['kb_key']);}
            self::fields($a['check'],['formula','bindings']);$f=$a['check']['formula'];$pin=array_intersect_key($f,array_flip(['key','version','fingerprint']));AF_ACS_Formula::compile($f,$pin,'interaction_score');
            $fd=$f['definition'];if(array_diff(array_values($fd['inputs']),['number']))self::fail();if(!isset($fd['min_value'],$fd['max_value'])||$fd['output_type']!=='number'||$fd['rounding']!=='none'||!is_array($a['check']['bindings'])||array_diff_key($fd['inputs'],$a['check']['bindings'])||array_diff_key($a['check']['bindings'],$fd['inputs']))self::fail();
            if(in_array('progress',$a['check']['bindings'],true)&&$d['progress']===null)self::fail();
            foreach($a['check']['bindings'] as $binding){if(in_array($binding,['roll','progress'],true))continue;if(!is_string($binding)||!preg_match('/\Astat:[a-z][a-z0-9_]{0,47}\z/D',$binding))self::fail();}
            $edge=$fd['min_value'];$seen=[];if(!$a['outcomes']||count($a['outcomes'])>4)self::fail();
            foreach($a['outcomes'] as $o){self::fields($o,['kind','from','to','state','activation','progress','repair','effects']);if(!in_array($o['kind'],['critical_success','success','partial_success','failure'],true)||isset($seen[$o['kind']])||(!is_int($o['from'])&&!is_float($o['from']))||(!is_int($o['to'])&&!is_float($o['to']))||!is_finite((float)$o['from'])||!is_finite((float)$o['to'])||$o['from']!=$edge||$o['to']<=$o['from']||!in_array($o['state'],$d['states'],true)||!in_array($o['activation'],[null,'active','inactive'],true))self::fail();$seen[$o['kind']]=true;$edge=$o['to'];self::number($o['repair']);
                if($o['progress']!==null){if($d['progress']===null)self::fail();self::fields($o['progress'],['operation','value']);if(!in_array($o['progress']['operation'],['add','set'],true))self::fail();self::number($o['progress']['value'],$o['progress']['operation']==='add'?-$d['progress']['cap']:0,$d['progress']['cap']);}
                if(!is_array($o['effects'])||!array_is_list($o['effects'])||count($o['effects'])>8)self::fail();foreach($o['effects'] as $effect){if(($effect['kind']??null)==='heal_actor'){self::fields($effect,['kind','amount']);}elseif(($effect['kind']??null)==='damage_actor'){self::fields($effect,['kind','amount','damage_type']);if(!in_array($effect['damage_type'],['physical','true'],true))self::fail();}else self::fail();self::number($effect['amount'],1);}
            }if($edge!=$fd['max_value'])self::fail();
            self::fields($a['retry'],['scope','limit','terminal_outcomes']);if(!in_array($a['retry']['scope'],['actor','object'],true))self::fail();self::number($a['retry']['limit'],1,1000);if(!is_array($a['retry']['terminal_outcomes'])||!array_is_list($a['retry']['terminal_outcomes'])||array_diff($a['retry']['terminal_outcomes'],array_keys($seen)))self::fail();
        }return $d;
    }
    public static function initial(array $d):array {return ['state'=>$d['initial_state'],'progress'=>$d['progress']['initial']??null,'attempts'=>[],'contributions'=>[]];}
    public static function validate(array $runtime,array $d):void
    {
        self::fields($runtime,['state','progress','attempts','contributions']);if(!in_array($runtime['state'],$d['states'],true)||!is_array($runtime['attempts'])||!is_array($runtime['contributions'])||count($runtime['contributions'])>16000)self::fail('ACS_OBJECT_STATE_INVALID');
        if($d['progress']===null?$runtime['progress']!==null:(!is_int($runtime['progress'])||$runtime['progress']<0||$runtime['progress']>$d['progress']['cap']))self::fail('ACS_OBJECT_STATE_INVALID');
        foreach($runtime['attempts'] as $key=>$v)if(!is_string($key)||!is_array($v)||array_keys($v)!==['count','terminal']||!is_int($v['count'])||$v['count']<1||$v['count']>1000||!is_bool($v['terminal']))self::fail('ACS_OBJECT_STATE_INVALID');
        foreach($runtime['contributions'] as $key=>$v)if(!preg_match('/\Asealed-action:[1-9][0-9]*\z/D',(string)$key)||!is_array($v)||array_keys($v)!==['actor','action','outcome','before','after'])self::fail('ACS_OBJECT_STATE_INVALID');
    }
    public static function action(array $object,string $key):array
    {$d=self::definition($object['definition']['interactions']??null);foreach($d['actions']??[] as $a)if($a['key']===$key)return $a;self::fail();}
    public static function eligible(array $object,array $a,string $actor,string $kind,array $stats,array $abilities):void
    {
        if(in_array($object['status'],['expired','destroyed'],true)||!in_array($object['interaction']['typed']['state'],$a['allowed_states'],true)||!in_array($kind,$a['actor_kinds'],true)||array_diff($a['required_abilities'],array_keys($abilities)))self::fail('ACS_INTERACTION_BLOCKED');
        foreach($a['minimum_stats'] as $k=>$min)if(!isset($stats[$k])||$stats[$k]<$min)self::fail('ACS_INTERACTION_BLOCKED');
        $attempt=$object['interaction']['typed']['attempts'][$a['key'].':'.($a['retry']['scope']==='actor'?$actor:'all')]??null;
        if($attempt&&($attempt['terminal']||$attempt['count']>=$a['retry']['limit']))self::fail('ACS_INTERACTION_RETRY_DENIED');
    }
    /** One immutable sealed action is one contribution, even if the transport is retried. */
    public static function outcome(array $v,array $a,string $actor,string $cause,int|float $score):array
    {
        if(isset($v['interaction']['typed']['contributions'][$cause])){$old=$v['interaction']['typed']['contributions'][$cause];if($old['actor']!==$actor||$old['action']!==$a['key'])self::fail('ACS_INTERACTION_CAUSE_REQUIRED');return ['object'=>$v,'outcome'=>null,'effects'=>[]];}
        if(!preg_match('/\Asealed-action:[1-9][0-9]*\z/D',$cause))self::fail('ACS_INTERACTION_CAUSE_REQUIRED');
        $last=count($a['outcomes'])-1;$selected=null;foreach($a['outcomes'] as $i=>$o)if($score>=$o['from']&&($score<$o['to']||$i===$last&&$score<=$o['to'])){$selected=$o;break;}if(!$selected)self::fail();$o=$selected;
        $before=$v['interaction']['typed']['progress'];$d=$v['definition']['interactions'];$t=&$v['interaction']['typed'];
        $t['state']=$o['state'];if($o['progress']!==null)$t['progress']=max(0,min($d['progress']['cap'],$o['progress']['operation']==='set'?$o['progress']['value']:$t['progress']+$o['progress']['value']));
        if($o['activation']!==null)$v['status']=$v['interaction']['activation']=$o['activation'];if($o['repair']){if($v['durability']===null)self::fail();$v['durability']=min($v['definition']['mechanics']['durability']['maximum'],$v['durability']+$o['repair']);}
        $attemptKey=$a['key'].':'.($a['retry']['scope']==='actor'?$actor:'all');$t['attempts'][$attemptKey]=['count'=>($t['attempts'][$attemptKey]['count']??0)+1,'terminal'=>in_array($o['kind'],$a['retry']['terminal_outcomes'],true)];
        $t['contributions'][$cause]=['actor'=>$actor,'action'=>$a['key'],'outcome'=>$o['kind'],'before'=>$before,'after'=>$t['progress']];++$v['object_version'];unset($t);
        $nodes=[$cause=>['children'=>[]]];foreach($o['effects'] as $i=>$e){$id=$cause.':object:'.$v['entity_id'].':'.$i;$nodes[$cause]['children'][]=$id;$nodes[$id]=['children'=>[]];}AF_ACS_CausalGraph::order($nodes,[$cause],2);
        return ['object'=>$v,'outcome'=>$o['kind'],'effects'=>$o['effects']];
    }
}
