<?php
if (!defined('IN_MYBB')) {die('No direct access');}
final class AF_ACS_Achievements
{
    public const SCHEMA='ic-acs.achievement.v1';
    public static function fields(array $v,array $keys):void
    {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))throw new RuntimeException('ACS_ACHIEVEMENT_UNSUPPORTED');}
    public static function canonical(array $v):string
    {
        $sort=static function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};
        return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function definition(array $d):array
    {
        if(!array_key_exists('subject',$d))$d['subject']='control_group';
        self::fields($d,['schema','achievement_key','name','mode','timing','subject','visibility','threshold','identity_policy','condition','reward']);
        if($d['schema']!==self::SCHEMA||!is_string($d['achievement_key'])||!preg_match('/\A[a-z][a-z0-9_.-]{2,95}\z/D',$d['achievement_key'])||!is_string($d['name'])||trim($d['name'])===''||mb_strlen($d['name'])>120||!in_array($d['mode'],['one_time','progressive','repeatable'],true)||!in_array($d['timing'],['on_confirmed_event','on_encounter_completed'],true)||!in_array($d['subject'],['actor','owner','control_group'],true)||!in_array($d['visibility'],['visible','hidden_until_unlocked','gm_only'],true)||!is_int($d['threshold'])||$d['threshold']<1||$d['threshold']>1000000||$d['mode']==='one_time'&&$d['threshold']!==1||!in_array($d['identity_policy'],['pinned','compatible_carry'],true))throw new RuntimeException('ACS_ACHIEVEMENT_UNSUPPORTED');
        self::fields($d['condition'],['kind','selection','entity_kinds','outcomes']);$c=$d['condition'];
        if($c['kind']!=='confirmed_count'||$c['selection']!==($d['timing']==='on_confirmed_event'?'primary_actors':'registered_participants')||!is_array($c['entity_kinds'])||!array_is_list($c['entity_kinds'])||!$c['entity_kinds']||count(array_unique($c['entity_kinds']))!==count($c['entity_kinds'])||array_diff($c['entity_kinds'],['character','npc','monster','pet','summon'])||!is_array($c['outcomes'])||!array_is_list($c['outcomes'])||($d['timing']==='on_confirmed_event'?$c['outcomes']!==[]:(!$c['outcomes']||array_diff($c['outcomes'],['victory','draw','inconclusive']))))throw new RuntimeException('ACS_ACHIEVEMENT_UNSUPPORTED');
        if($d['reward']!==null){if(!is_array($d['reward']))throw new RuntimeException('ACS_ACHIEVEMENT_UNSUPPORTED');self::fields($d['reward'],['reward_type','amount_minor']);if(!in_array($d['reward']['reward_type'],['experience','currency'],true)||!is_int($d['reward']['amount_minor'])||$d['reward']['amount_minor']<1||$d['reward']['amount_minor']>9007199254740991)throw new RuntimeException('ACS_ACHIEVEMENT_UNSUPPORTED');}
        return $d;
    }
    public static function semantics(array $d):string
    {unset($d['identity_policy']);return hash('sha256',self::canonical($d));}
    /** One contribution per source and subject, irrespective of how many group pets acted. */
    public static function subjects(array $d,array $source):array
    {
        if($d['timing']!==$source['timing']||$source['timing']==='on_encounter_completed'&&!in_array($source['outcome']['kind'],$d['condition']['outcomes'],true))return [];
        $subjects=[];
        foreach($source['facts'] as $f){
            if(!in_array($f['kind'],$d['condition']['entity_kinds'],true)||$source['timing']==='on_confirmed_event'&&!in_array($f['entity_id'],$source['actors'],true)||$f['group_owner_uid']===null||(int)$f['group_owner_uid']<1||(int)$f['group_owner_uid']>4294967295)continue;
            $key=match($d['subject']){'actor'=>'actor:'.$f['entity_id'],'owner'=>'owner:'.$f['group_owner_uid'],'control_group'=>'group:'.$f['control_group_id']};
            $subjects[$key]??=['subject_key'=>$key,'recipient_uid'=>(int)$f['group_owner_uid']];
        }
        ksort($subjects,SORT_STRING);if(count($subjects)>200)throw new RuntimeException('ACS_ACHIEVEMENT_SUBJECT_LIMIT');return array_values($subjects);
    }
    public static function next(array $d,array $p,bool $included):array
    {
        if($p['count']>=9007199254740990)throw new RuntimeException('ACS_ACHIEVEMENT_PROGRESS_LIMIT');
        $p['count']++;if($included)$p['review_count']++;
        $earned=$d['mode']==='repeatable'?intdiv($p['review_count'],$d['threshold']):($p['review_count']>=$d['threshold']?1:0);
        // Historical earned identities survive exclusion/restoration and definition migration.
        $p['awards']=max($p['awards'],$earned);return $p;
    }
    public static function correction(array $p):array
    {
        $fields=['schema','disposition','recipient_contract','compensation'];if(array_diff(array_keys($p),$fields)||array_diff($fields,array_keys($p)))throw new RuntimeException('ACS_CORRECTION_UNSUPPORTED');
        if($p['schema']!=='ic-acs.correction-review.v1'||!in_array($p['disposition'],['exclude','include'],true)||$p['recipient_contract']!=='acs.derived-review.v1'||!in_array($p['compensation'],['none','balance','advancedinventory','charactersheets'],true))throw new RuntimeException('ACS_CORRECTION_UNSUPPORTED');
        return $p;
    }
}
