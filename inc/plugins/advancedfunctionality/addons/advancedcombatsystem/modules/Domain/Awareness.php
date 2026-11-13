<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';
require_once __DIR__.'/Control.php';

/** Knowledge and mechanical concealment are independent. No SQL, RNG, clocks or callbacks. */
final class AF_ACS_Awareness
{
    public const LEVELS=['unknown'=>0,'suspected'=>1,'detected'=>2,'identified'=>3];
    public static function fail():never {throw new DomainException('ACS_AWARENESS_UNSUPPORTED');}
    private static function fields(array $v,array $keys):void {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    public static function details(array $v,string $level):array
    {
        if(!isset(self::LEVELS[$level])||array_diff(array_keys($v),$level==='identified'?['hint','name']:['hint']))self::fail();
        foreach($v as $value)if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||mb_strlen($value)>191)self::fail();
        if($level==='unknown'&&$v!==[])self::fail();return $v;
    }
    public static function stealth(array $s):void
    {
        self::fields($s,['schema','states','targeting']);
        if($s['schema']!=='ic-acs.stealth.v1'||!is_array($s['states'])||!array_is_list($s['states'])||!$s['states']||array_diff($s['states'],['invisible','hidden','concealed'])||count(array_unique($s['states']))!==count($s['states'])||!in_array($s['targeting'],['forbid','identified'],true))self::fail();
    }
    public static function perception(?array $p):?array
    {
        if($p===null)return null;
        self::fields($p,['schema','tags','stealth','disclosure','passive']);
        if($p['schema']!=='ic-acs.perception.v1'||!is_array($p['tags'])||!array_is_list($p['tags'])||count($p['tags'])>16||count(array_unique($p['tags']))!==count($p['tags']))self::fail();
        foreach($p['tags'] as $tag)if(!is_string($tag)||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$tag))self::fail();
        if($p['stealth']!==null)self::stealth($p['stealth']);self::fields($p['disclosure'],['suspected','detected','identified']);
        foreach($p['disclosure'] as $level=>$v)self::details($v,$level);
        if($p['passive']!==null){$v=$p['passive'];$audience=$v['audience']??'control_group';unset($v['audience']);self::fields($v,['event','once','level']);
            if($v['event']!=='source_turn_end'||$v['once']!==true||!in_array($v['level'],['suspected','detected','identified'],true)||!in_array($audience,['actor','control_group','side','encounter'],true))self::fail();}
        return $p;
    }
    public static function key(string $kind,string $id,string $audience,string $audienceId):string {return $kind.':'.$id.':'.$audience.':'.$audienceId;}
    public static function row(array $v):void
    {
        self::fields($v,['schema_version','encounter_id','subject_kind','subject_id','audience_kind','audience_id','level','awareness_version','details']);
        if($v['schema_version']!==1||!in_array($v['subject_kind'],['entity','object','prepared_action'],true)||!in_array($v['audience_kind'],['actor','control_group','side','encounter'],true)||!isset(self::LEVELS[$v['level']])||!is_int($v['awareness_version'])||$v['awareness_version']<1||$v['awareness_version']>9007199254740991)self::fail();
        foreach(['encounter_id','subject_id','audience_id'] as $key)if(!is_string($v[$key])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[$key]))self::fail();
        if($v['audience_kind']==='encounter'&&$v['audience_id']!==$v['encounter_id'])self::fail();self::details($v['details'],$v['level']);
    }
    public static function attempt(string $key,array $check):void
    {
        self::fields($check,['cause','actor_id','subject_kind','subject_id','success','operation']);
        if(!is_bool($check['success'])||!in_array($check['subject_kind'],['entity','object','prepared_action'],true)||!in_array($check['operation'],['search','share','passive'],true))self::fail();
        foreach(['actor_id','subject_id'] as $field)if(!is_string($check[$field])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$check[$field]))self::fail();
        if($check['operation']==='passive'){
            if(!preg_match('/\Aentity_turn:[1-9][0-9]*:end\z/D',$check['cause'])||$key!=='passive:'.$check['subject_kind'].':'.$check['subject_id'])self::fail();
        }elseif(!preg_match('/\Asealed-action:[1-9][0-9]*\z/D',$check['cause'])||$key!==$check['cause'].':'.$check['subject_kind'].':'.$check['subject_id'])self::fail();
    }
    public static function audiences(array $actor):array
    {return ['actor'=>[$actor['entity_id']],'control_group'=>[$actor['group_id']],'side'=>[$actor['side_id']],'encounter'=>[$actor['encounter_id']]];}
    public static function known(array $rows,string $kind,string $id,array $audiences):array
    {
        $out=['level'=>'unknown','details'=>[],'version'=>0];
        foreach($rows as $row)if($row['subject_kind']===$kind&&$row['subject_id']===$id&&in_array($row['audience_id'],$audiences[$row['audience_kind']]??[],true)){
            if(self::LEVELS[$row['level']]>self::LEVELS[$out['level']])$out=['level'=>$row['level'],'details'=>$row['details'],'version'=>$row['awareness_version']];
            elseif($row['level']===$out['level']){$out['details']=array_replace($out['details'],$row['details']);$out['version']=max($out['version'],$row['awareness_version']);}
        }return $out;
    }
    public static function targetable(string $level,?array $base,array $statuses,string $entity):bool
    {
        if($level!=='identified')return false;
        if($base!==null&&$base['targeting']==='forbid')return false;
        foreach($statuses as $row)if($row['status']==='active'&&$row['target_entity_id']===$entity&&isset($row['spec']['mechanical_parameters']['stealth'])){
            $s=$row['spec']['mechanical_parameters']['stealth'];self::stealth($s);if($s['targeting']==='forbid')return false;
        }return true;
    }
    public static function hasAbility(array $dto):bool {return array_key_exists('awareness_action',$dto['data']['definition']??[]);}
    public static function compile(array $dto):array
    {
        $d=$dto['data']['definition'];$spec=$d['awareness_action']??[];$audience=$spec['audience']??'control_group';unset($spec['audience']);
        self::fields($spec,['schema','operation','tag','subject_kind','level','chance']);
        if($spec['schema']!=='ic-acs.awareness-action.v1'||!in_array($spec['operation'],['search','share'],true)||!in_array($spec['subject_kind'],['object','entity','prepared_action'],true)||!is_string($spec['tag'])||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$spec['tag'])||!in_array($spec['level'],['suspected','detected','identified'],true)||!is_int($spec['chance'])||$spec['chance']<0||$spec['chance']>100||!in_array($audience,['actor','control_group','side','encounter'],true)||$spec['operation']==='share'&&$spec['chance']!==100)self::fail();
        $metadata=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','cooldown_unit','duration_unit','level_cap','magic_direction_ref','damage_element','element','target_policy','action_cost','awareness_action'];
        $empty=['range','cast_time','cooldown','cooldown_value','duration','duration_value','max_charges','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),[...$metadata,...$empty])||($d['type']??null)!=='active'||($d['targeting']??null)!=='self'||AF_ACS_Control::targetPolicy($d)!==['kind'=>'self_only']||!is_int($d['action_cost']??null)||$d['action_cost']<1||$d['action_cost']>2147483647)self::fail();
        foreach($empty as $key)if(!empty($d[$key]))self::fail();
        if(($dto['ready']??null)!==true||!is_string($dto['definition_version']['declared_version']??null))self::fail();
        $spec['audience']=$audience;
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],'target_policy'=>['kind'=>'self_only'],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$d['action_cost']]],'targeting'=>'self','awareness_action'=>$spec];
    }
    public static function disclose(array &$state,array $subject,array $actor,string $audience,string $level,array $details):void
    {
        $audiences=self::audiences($actor);$aid=$audiences[$audience][0];$key=self::key($subject['kind'],$subject['id'],$audience,$aid);
        $old=$state['awareness'][$key]??null;if($old!==null&&self::LEVELS[$old['level']]>=self::LEVELS[$level])return;
        $state['awareness'][$key]=['schema_version'=>1,'encounter_id'=>$actor['encounter_id'],'subject_kind'=>$subject['kind'],'subject_id'=>$subject['id'],'audience_kind'=>$audience,'audience_id'=>$aid,'level'=>$level,'awareness_version'=>($old['awareness_version']??0)+1,'details'=>self::details($details,$level)];
    }
    /** A single sealed check for a KB tag scope, independent of the number/existence of hidden subjects. */
    public static function action(array $state,array $subjects,array $actor,array $spec,string $cause,?array $sample,string $mode):array
    {
        $success=null;if($mode==='seal'){AF_ACS_Effects::statusSample($sample);$success=$spec['operation']==='share'||100*$sample['u']<$spec['chance'];}
        foreach($subjects as $subject){if($subject['kind']==='prepared_action'&&empty($subject['active']))continue;$p=$subject['perception'];if($p===null||$subject['kind']!==$spec['subject_kind']||!in_array($spec['tag'],$p['tags'],true))continue;
            $attempt=$cause.':'.$subject['kind'].':'.$subject['id'];if(isset($state['awareness_attempts'][$attempt])||$mode!=='seal')continue;
            $details=$p['disclosure'][$spec['level']];$eligible=true;if($spec['operation']==='share'){$known=self::known($state['awareness'],$subject['kind'],$subject['id'],self::audiences($actor));$eligible=self::LEVELS[$known['level']]>=self::LEVELS[$spec['level']];$details=array_intersect_key($known['details'],array_fill_keys($spec['level']==='identified'?['hint','name']:['hint'],true));}
            if($success&&$eligible)self::disclose($state,$subject,$actor,$spec['audience'],$spec['level'],$details);
            $state['awareness_attempts'][$attempt]=['cause'=>$cause,'actor_id'=>$actor['entity_id'],'subject_kind'=>$subject['kind'],'subject_id'=>$subject['id'],'success'=>$success&&$eligible,'operation'=>$spec['operation']];
        }return $state;
    }
    /** Fixed passive checks only on the declared source Clock boundary, once per instance. */
    public static function boundary(array $state,array $boundary,string $key,array &$events):array
    {
        if($boundary['phase']!=='entity_end')return $state;
        foreach($state['perception_subjects']??[] as $subject){$p=$subject['perception'];$passive=$p['passive']??null;$actor=$subject['source'];
            if($passive===null||$actor['entity_id']!==($boundary['entity_id']??null)||$actor['encounter_id']!==$boundary['encounter_id'])continue;
            $attempt='passive:'.$subject['kind'].':'.$subject['id'];if(isset($state['awareness_attempts'][$attempt]))continue;
            self::disclose($state,$subject,$actor,$passive['audience']??'control_group',$passive['level'],$p['disclosure'][$passive['level']]);
            $state['awareness_attempts'][$attempt]=['cause'=>$key,'actor_id'=>$actor['entity_id'],'subject_kind'=>$subject['kind'],'subject_id'=>$subject['id'],'success'=>true,'operation'=>'passive'];
            $events[]=['type'=>'awareness.passive','attempt'=>$attempt,'cause'=>$key];
        }return $state;
    }
}
