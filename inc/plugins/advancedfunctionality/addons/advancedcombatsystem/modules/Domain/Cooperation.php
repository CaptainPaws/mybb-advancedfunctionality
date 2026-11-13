<?php
declare(strict_types=1);
require_once __DIR__.'/ObjectInteraction.php';

/** Confirmed sequential cooperation over one pinned object. No clocks, SQL or sampling. */
final class AF_ACS_Cooperation
{
    private static function fail(string $code='ACS_COOPERATION_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(array $v,array $keys):void
    {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    public static function definition(?array $d):?array
    {
        if($d===null)return null;
        self::fields($d,['schema','kind','label','visibility','cap','object_statuses','object_changed','contributor_inactive','action','assist','completion']);
        if(!in_array($d['visibility'],['known','gm'],true))self::fail();
        if($d['schema']!=='ic-acs.cooperation.v1'||!in_array($d['kind'],['cooperative_check','cooperative_objective'],true)
            ||!is_string($d['label'])||trim($d['label'])===''||mb_strlen($d['label'])>96||!is_array($d['object_statuses'])||!array_is_list($d['object_statuses'])||!$d['object_statuses']||array_diff($d['object_statuses'],['active','inactive'])
            ||!in_array($d['object_changed'],['reconfirm','block'],true)||$d['contributor_inactive']!=='retain')self::fail();
        AF_ACS_Numeric::integer($d['cap'],1,1000000);
        // Reuse the Stage-8.2.1 typed Formula/outcome/cost contract; assist is a new explicit input.
        $a=$d['action'];$validation=$a;
        foreach($validation['check']['bindings'] as &$binding)if($binding==='assist')$binding='progress';unset($binding);
        AF_ACS_ObjectInteraction::definition(['schema'=>'ic-acs.object-interactions.v1','states'=>['open'],'initial_state'=>'open','progress'=>['initial'=>0,'cap'=>$d['cap'],'decay_per_round'=>0],'actions'=>[$validation]]);
        if($a['allowed_states']!==['open']||$a['tool']!==null)self::fail();
        foreach($a['outcomes'] as $o)if($o['state']!=='open'||$o['activation']!==null||$o['repair']!==0||$o['effects']!==[]||$o['progress']!==null&&($o['progress']['operation']!=='add'||$o['progress']['value']<0))self::fail();
        if($d['assist']!==null){
            $s=$d['assist'];if(array_diff(array_keys($s),['ap_cost','value','duration_rounds','uses','stacking','max_stacks','allow_self']))self::fail();
            foreach(['ap_cost','value','duration_rounds','allow_self'] as $field)if(!array_key_exists($field,$s))self::fail();
            AF_ACS_Numeric::integer($s['ap_cost'],1,1000000);AF_ACS_Numeric::integer($s['value'],1,1000000);AF_ACS_Numeric::integer($s['duration_rounds'],1,1000);
            AF_ACS_Numeric::integer($s['uses']??1,1,1000);$stacking=$s['stacking']??'reject';
            if(!is_bool($s['allow_self'])||!in_array($stacking,['reject','replace','sum'],true))self::fail();
            AF_ACS_Numeric::integer($s['max_stacks']??1,1,8);if($stacking!=='sum'&&($s['max_stacks']??1)!==1)self::fail();
            if(!in_array('assist',$a['check']['bindings'],true))self::fail();
        }elseif(in_array('assist',$a['check']['bindings'],true))self::fail();
        if(!is_array($d['completion'])||!array_is_list($d['completion'])||count($d['completion'])>8)self::fail();
        foreach($d['completion'] as $effect){
            if(($effect['kind']??null)==='heal_finisher'){self::fields($effect,['kind','amount']);AF_ACS_Numeric::integer($effect['amount'],1,1000000);}
            elseif(($effect['kind']??null)==='object_activation'){self::fields($effect,['kind','status']);if(!in_array($effect['status'],['active','inactive'],true))self::fail();}
            else self::fail();
        }return $d;
    }
    public static function initial(string $id,array $object):array
    {
        $d=self::definition($object['definition']['cooperation']);
        return ['schema_version'=>1,'id'=>$id,'entity_id'=>$object['entity_id'],'encounter_id'=>$object['encounter_id'],'definition'=>$d,'status'=>'open','progress'=>0,'objective_version'=>1,
            'bound_object_version'=>$object['object_version'],'completion_cause'=>null,'records'=>[],'assists'=>[],'last_boundary'=>null];
    }
    public static function instance(array $v):void
    {
        self::fields($v,['schema_version','id','entity_id','encounter_id','definition','status','progress','objective_version','bound_object_version','completion_cause','records','assists','last_boundary']);
        foreach(['id','entity_id','encounter_id'] as $key)if(!is_string($v[$key])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[$key]))self::fail('ACS_OBJECTIVE_STATE_INVALID');
        $d=self::definition($v['definition']);AF_ACS_Numeric::integer($v['progress'],0,$d['cap']);AF_ACS_Numeric::integer($v['objective_version'],1);AF_ACS_Numeric::integer($v['bound_object_version'],1);
        if($v['schema_version']!==1||!in_array($v['status'],['open','completed'],true)||($v['status']==='completed')!==($v['progress']===$d['cap'])||!is_array($v['records'])||!is_array($v['assists'])||count($v['records'])>16000)self::fail('ACS_OBJECTIVE_STATE_INVALID');
        foreach($v['records'] as $key=>$r){
            if(!preg_match('/\Asealed-action:[1-9][0-9]*\z/D',(string)$key))self::fail('ACS_OBJECTIVE_STATE_INVALID');
            self::fields($r,['actor','recipient','kind','outcome','score','value','before','after']);
            if(!is_string($r['actor'])||!preg_match('/\A[1-9][0-9]*\z/D',$r['actor'])||!in_array($r['kind'],['assist','cooperative_check','cooperative_objective'],true)||!in_array($r['outcome'],['assisted','critical_success','success','partial_success','failure'],true))self::fail('ACS_OBJECTIVE_STATE_INVALID');
            if($r['kind']==='assist'){if(!is_string($r['recipient'])||!preg_match('/\A[1-9][0-9]*\z/D',$r['recipient'])||$r['score']!==null||$r['outcome']!=='assisted'||$r['value']!==0)self::fail('ACS_OBJECTIVE_STATE_INVALID');}else{if($r['recipient']!==null)self::fail('ACS_OBJECTIVE_STATE_INVALID');AF_ACS_Numeric::number($r['score']);}
            foreach(['value','before','after'] as $field)AF_ACS_Numeric::integer($r[$field],0,$d['cap']);if($r['after']-$r['before']!==$r['value'])self::fail('ACS_OBJECTIVE_STATE_INVALID');
        }
        foreach($v['assists'] as $key=>$s){
            self::fields($s,['source','recipient','definition','remaining','uses_remaining','status','cause']);
            if(!isset($v['records'][$key])||$v['records'][$key]['kind']!=='assist'||$s['cause']!==(string)$key||$s['source']!==$v['records'][$key]['actor']||$s['recipient']!==$v['records'][$key]['recipient']||$s['definition']!==$d['assist']||!in_array($s['status'],['active','used','expired','replaced'],true))self::fail('ACS_OBJECTIVE_STATE_INVALID');
            AF_ACS_Numeric::integer($s['remaining'],0,$d['assist']['duration_rounds']);AF_ACS_Numeric::integer($s['uses_remaining'],0,$d['assist']['uses']??1);
            if($s['status']==='active'&&($s['remaining']===0||$s['uses_remaining']===0))self::fail('ACS_OBJECTIVE_STATE_INVALID');
        }
        if($v['status']==='completed'?!isset($v['records'][$v['completion_cause']??'']):$v['completion_cause']!==null)self::fail('ACS_OBJECTIVE_STATE_INVALID');
        if($v['last_boundary']!==null&&!preg_match('/\Around:[1-9][0-9]*:next\z/D',$v['last_boundary']))self::fail('ACS_OBJECTIVE_STATE_INVALID');
    }
    public static function selection(string $key):?array
    {
        if($key==='coop-check')return ['operation'=>'cooperative_check','recipient'=>null];
        if($key==='coop-contribute')return ['operation'=>'cooperative_objective','recipient'=>null];
        if(preg_match('/\Acoop-assist-([1-9][0-9]{0,19})\z/D',$key,$m))return ['operation'=>'assist','recipient'=>$m[1]];
        if(str_starts_with($key,'coop-'))self::fail();return null;
    }
    public static function eligible(array $v,array $object,string $actor,string $kind,array $stats,array $abilities,array $selection):void
    {
        self::instance($v);$d=$v['definition'];$a=$d['action'];
        if($v['status']!=='open'||!in_array($object['status'],$d['object_statuses'],true)||$d['object_changed']==='block'&&$object['object_version']!==$v['bound_object_version'])self::fail('ACS_OBJECTIVE_BLOCKED');
        if(!in_array($kind,$a['actor_kinds'],true)||array_diff($a['required_abilities'],array_keys($abilities)))self::fail('ACS_OBJECTIVE_BLOCKED');
        foreach($a['minimum_stats'] as $stat=>$min)if(!isset($stats[$stat])||$stats[$stat]<$min)self::fail('ACS_OBJECTIVE_BLOCKED');
        if($selection['operation']==='assist'){
            if($d['assist']===null||!$d['assist']['allow_self']&&$selection['recipient']===$actor)self::fail('ACS_OBJECTIVE_BLOCKED');
            $active=array_filter($v['assists'],static fn($s)=>$s['status']==='active'&&$s['recipient']===$selection['recipient']);
            if(($d['assist']['stacking']??'reject')==='reject'&&$active||($d['assist']['stacking']??'reject')==='sum'&&count($active)>=($d['assist']['max_stacks']??1))self::fail('ACS_ASSIST_STACK_BLOCKED');
        }else{
            if($selection['operation']!==$d['kind'])self::fail();
            $records=array_filter($v['records'],static fn($r)=>$r['kind']!=='assist'&&($a['retry']['scope']==='object'||$r['actor']===$actor));
            if(count($records)>=$a['retry']['limit']||array_intersect(array_column($records,'outcome'),$a['retry']['terminal_outcomes']))self::fail('ACS_INTERACTION_RETRY_DENIED');
        }
    }
    public static function assistance(array $v,string $actor):int
    {return array_sum(array_map(static fn($s)=>$s['definition']['value'],array_filter($v['assists'],static fn($s)=>$s['status']==='active'&&$s['recipient']===$actor)));}
    public static function apply(array $v,string $actor,array $selection,string $cause,?float $score):array
    {
        if(!preg_match('/\Asealed-action:[1-9][0-9]*\z/D',$cause))self::fail();
        if(isset($v['records'][$cause])){if($v['records'][$cause]['actor']!==$actor||$v['records'][$cause]['kind']!==$selection['operation']||$v['records'][$cause]['recipient']!==$selection['recipient'])self::fail();return ['objective'=>$v,'completion'=>[],'outcome'=>$v['records'][$cause]['outcome']];}
        $d=$v['definition'];$before=$v['progress'];$outcome='assisted';
        if($selection['operation']==='assist'){
            if(($d['assist']['stacking']??'reject')==='replace')foreach($v['assists'] as &$s)if($s['status']==='active'&&$s['recipient']===$selection['recipient'])$s['status']='replaced';unset($s);
            $v['assists'][$cause]=['source'=>$actor,'recipient'=>$selection['recipient'],'definition'=>$d['assist'],'remaining'=>$d['assist']['duration_rounds'],'uses_remaining'=>$d['assist']['uses']??1,'status'=>'active','cause'=>$cause];
        }else{
            $selected=null;$last=count($d['action']['outcomes'])-1;foreach($d['action']['outcomes'] as $i=>$o)if($score!==null&&$score>=$o['from']&&($score<$o['to']||$i===$last&&$score<=$o['to'])){$selected=$o;break;}if($selected===null)self::fail();
            $outcome=$selected['kind'];$v['progress']=min($d['cap'],$v['progress']+($selected['progress']['value']??0));
            if(in_array('assist',$d['action']['check']['bindings'],true))foreach($v['assists'] as &$s)if($s['status']==='active'&&$s['recipient']===$actor){if(--$s['uses_remaining']===0)$s['status']='used';}unset($s);
        }
        $v['records'][$cause]=['actor'=>$actor,'recipient'=>$selection['recipient'],'kind'=>$selection['operation'],'outcome'=>$outcome,'score'=>$score,'value'=>$v['progress']-$before,'before'=>$before,'after'=>$v['progress']];
        $completion=[];if($v['progress']===$d['cap']){$v['status']='completed';$v['completion_cause']=$cause;$completion=$d['completion'];foreach($v['assists'] as &$s)if($s['status']==='active'){$s['status']='expired';$s['remaining']=0;}unset($s);}
        ++$v['objective_version'];self::instance($v);return ['objective'=>$v,'completion'=>$completion,'outcome'=>$outcome];
    }
    public static function boundary(array $state,array $boundary,string $key,array &$events):array
    {
        if($boundary['phase']!=='round_end')return $state;
        foreach($state['objectives']??[] as $id=>$v){if($v['encounter_id']!==$boundary['encounter_id']||$v['last_boundary']===$key||$v['status']!=='open')continue;$old=$v;
            foreach($v['assists'] as &$s)if($s['status']==='active'&&--$s['remaining']===0)$s['status']='expired';unset($s);
            if($v['assists']!==$old['assists']){$v['last_boundary']=$key;++$v['objective_version'];$state['objectives'][$id]=$v;$events[]=['type'=>'assist.boundary','objective_id'=>$v['id'],'boundary'=>$key];}
        }return $state;
    }
}
