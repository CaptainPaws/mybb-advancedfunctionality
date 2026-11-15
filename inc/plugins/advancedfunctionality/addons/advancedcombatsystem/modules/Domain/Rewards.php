<?php
if (!defined('IN_MYBB')) {die('No direct access');}
/** Loot generation only. No allocation, ownership, Luck fallback or reward issuance. */
final class AF_ACS_Rewards
{
    public const SCHEMA='ic-acs.loot-source.v1';
    public const ENGINE='acs-loot-1';
    private static function fields(array $v,array $keys):void
    {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');}
    private static function choices(mixed $v,array $allowed):void
    {if(!is_array($v)||!array_is_list($v)||!$v||count($v)!==count(array_unique($v))||array_diff($v,$allowed))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');}
    public static function definition(array $d):array
    {
        self::fields($d,['schema','source_kind','mode','draws','replacement','luck_modifier','conditions','eligibility','entries']);
        if($d['schema']!==self::SCHEMA||!in_array($d['source_kind'],['monster','encounter','kb'],true)||!in_array($d['mode'],['guaranteed','independent_roll','weighted_pool'],true)||$d['luck_modifier']!==null||!is_int($d['draws'])||$d['draws']<0||$d['draws']>128||!is_bool($d['replacement']))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        self::fields($d['conditions'],['outcomes','source_life_states','source_participation','source_side']);
        self::choices($d['conditions']['outcomes'],['victory','draw','inconclusive']);
        if($d['source_kind']==='monster'){
            self::choices($d['conditions']['source_life_states'],['active','incapacitated','dead']);
            self::choices($d['conditions']['source_participation'],['engaged','surrendered','escaped','withdrawn','captured']);
            if($d['conditions']['source_side']!=='loser')throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        }elseif($d['conditions']['source_side']!=='any'||$d['conditions']['source_life_states']!==[]||$d['conditions']['source_participation']!==[])throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        self::fields($d['eligibility'],['side','life_states','participation']);
        if(!in_array($d['eligibility']['side'],['winner','all'],true))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        self::choices($d['eligibility']['life_states'],['active','incapacitated','dead']);self::choices($d['eligibility']['participation'],['engaged','surrendered','escaped','withdrawn','captured']);
        if(!is_array($d['entries'])||!array_is_list($d['entries'])||!$d['entries']||count($d['entries'])>128)throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        $seen=[];
        foreach($d['entries'] as $e){
            if(!is_array($e))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');self::fields($e,['key','item','qty','chance','weight']);
            if(!is_string($e['key'])||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$e['key'])||isset($seen[$e['key']])||!is_int($e['qty'])||$e['qty']<1||$e['qty']>1000000)throw new RuntimeException('ACS_LOOT_UNSUPPORTED');$seen[$e['key']]=true;
            self::fields($e['item'],['type','key','version','fingerprint']);
            if($e['item']['type']!=='arpg_item'||!is_string($e['item']['key'])||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$e['item']['key'])||!is_string($e['item']['version'])||$e['item']['version']===''||!is_string($e['item']['fingerprint'])||!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$e['item']['fingerprint']))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
            if(!is_int($e['weight'])||$e['weight']<0||$e['weight']>1000000||(!is_int($e['chance'])&&!is_float($e['chance']))||!is_finite((float)$e['chance'])||$e['chance']<0||$e['chance']>100)throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
            if($d['mode']==='weighted_pool'?$e['weight']===0||$e['chance']!=100:$e['weight']!==0||$d['mode']==='guaranteed'&&$e['chance']!=100)throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        }
        if($d['mode']==='weighted_pool'?($d['draws']<1||!$d['replacement']&&$d['draws']>count($d['entries'])):($d['draws']!==0||$d['replacement']))throw new RuntimeException('ACS_LOOT_UNSUPPORTED');
        return $d;
    }
    /** Frozen group facts at the mechanical end boundary, never current controller/HP reads. */
    public static function eligibility(array $d,array $facts,array $outcome):array
    {
        $groups=[];foreach($facts as $f){
            if(in_array($f['kind'],['pet','summon','object'],true)||$f['source_entity_id']!==null)continue;
            if($d['eligibility']['side']==='winner'&&($outcome['winner_side_id']===null||$f['side_id']!==$outcome['winner_side_id']))continue;
            if(!in_array($f['life_state'],$d['eligibility']['life_states'],true)||!in_array($f['participation'],$d['eligibility']['participation'],true))continue;
            $group=$f['control_group_id'];if($group===null)throw new RuntimeException('ACS_LOOT_ELIGIBILITY_UNAVAILABLE');
            $groups[$group]??=['control_group_id'=>$group,'owner_uid'=>$f['group_owner_uid'],'members'=>[]];$groups[$group]['members'][]=$f['entity_id'];
        }return array_values($groups);
    }
    public static function permitted(array $d,array $source,array $facts,array $outcome):bool
    {
        if(!in_array($outcome['kind'],$d['conditions']['outcomes'],true))return false;
        if($d['source_kind']!=='monster')return true;
        foreach($facts as $f)if($f['entity_id']===$source['entity_id'])return $f['kind']==='monster'&&$outcome['winner_side_id']!==null&&$f['side_id']!==$outcome['winner_side_id']&&in_array($f['life_state'],$d['conditions']['source_life_states'],true)&&in_array($f['participation'],$d['conditions']['source_participation'],true);
        return false;
    }
    /** Pure tape replay. Caller creates each required sample once in the existing rolls ledger. */
    public static function generate(array $d,array $tape):array
    {
        self::definition($d);$n=$d['mode']==='guaranteed'?0:($d['mode']==='independent_roll'?count($d['entries']):$d['draws']);
        if(count($tape)!==$n)throw new RuntimeException('ACS_LOOT_TAPE_INVALID');
        foreach($tape as $r)if(!is_int($r['sample_n']??null)||$r['sample_n']<0||$r['sample_n']>9007199254740991||($r['u']??null)!==$r['sample_n']/9007199254740992.0)throw new RuntimeException('ACS_LOOT_TAPE_INVALID');
        if($d['mode']==='guaranteed')return $d['entries'];
        $out=[];$available=$d['entries'];
        if($d['mode']==='independent_roll'){foreach($available as $i=>$e)if(100*$tape[$i]['u']<$e['chance'])$out[]=$e;return $out;}
        foreach($tape as $r){$point=$r['u']*array_sum(array_column($available,'weight'));$sum=0;foreach($available as $i=>$e){$sum+=$e['weight'];if($point<$sum){$out[]=$e;if(!$d['replacement'])unset($available[$i]);break;}}}
        return $out;
    }
}
