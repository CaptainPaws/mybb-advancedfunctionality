<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/Rewards.php';
/** Exact minor-unit arithmetic only. No live ownership, Balance or CS writes. */
final class AF_ACS_RewardDistribution
{
    public const SCHEMA='ic-acs.reward-source.v1';
    private static function fields(array $v,array $keys):void
    {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');}
    public static function definition(array $d):array
    {
        self::fields($d,['schema','reward_type','mode','amount_minor','remainder_policy','outcomes','eligibility','subject','entity_kinds','permanent_sync']);
        if($d['schema']!==self::SCHEMA||!in_array($d['reward_type'],['experience','currency','permanent_sync'],true)||!in_array($d['mode'],['each','split_equal','manual'],true)||!is_int($d['amount_minor'])||$d['amount_minor']<1||$d['amount_minor']>9007199254740991||$d['remainder_policy']!==($d['mode']==='split_equal'?'recipient_key_order':null))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        if(!is_array($d['outcomes'])||!array_is_list($d['outcomes'])||!$d['outcomes']||array_diff($d['outcomes'],['victory','draw','inconclusive'])||count(array_unique($d['outcomes']))!==count($d['outcomes']))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        self::fields($d['eligibility'],['side','life_states','participation']);
        if(!in_array($d['eligibility']['side'],['winner','all'],true))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        foreach(['life_states'=>['active','incapacitated','dead'],'participation'=>['engaged','surrendered','escaped','withdrawn','captured']] as $key=>$values)if(!is_array($d['eligibility'][$key])||!array_is_list($d['eligibility'][$key])||!$d['eligibility'][$key]||array_diff($d['eligibility'][$key],$values)||count(array_unique($d['eligibility'][$key]))!==count($d['eligibility'][$key]))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        if(!in_array($d['subject'],['control_group','entity'],true)||!is_array($d['entity_kinds'])||!array_is_list($d['entity_kinds'])||($d['subject']==='control_group'?$d['entity_kinds']!==[]:(!$d['entity_kinds']||array_diff($d['entity_kinds'],['character','npc','monster','pet','summon']))))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        if($d['reward_type']==='permanent_sync'){
            if(!is_array($d['permanent_sync']))throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
            self::fields($d['permanent_sync'],['owner','kb_definition','change']);
            if($d['permanent_sync']['owner']!=='charactersheets'||!is_string($d['permanent_sync']['kb_definition'])||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$d['permanent_sync']['kb_definition'])||!is_array($d['permanent_sync']['change'])||strlen(json_encode($d['permanent_sync']['change'],JSON_THROW_ON_ERROR))>8192)throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
            if($d['mode']!=='each'||$d['amount_minor']!==1)throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        }elseif($d['permanent_sync']!==null)throw new RuntimeException('ACS_REWARD_UNSUPPORTED');
        return $d;
    }
    /** Freeze from confirmed resolution facts, not from a later entity/controller. */
    public static function eligible(array $d,array $facts,array $outcome):array
    {
        $out=[];
        if(!in_array($outcome['kind'],$d['outcomes'],true))return [];
        if($d['subject']==='control_group'){
            foreach(AF_ACS_Rewards::eligibility($d,$facts,$outcome) as $g)if($g['owner_uid']!==null)$out[]=['recipient_key'=>'group:'.$g['control_group_id'],'uid'=>(int)$g['owner_uid'],'control_group_id'=>$g['control_group_id'],'entity_ids'=>$g['members']];
        }else foreach($facts as $f){
            if(!in_array($f['kind'],$d['entity_kinds'],true)||$d['eligibility']['side']==='winner'&&$f['side_id']!==$outcome['winner_side_id']||!in_array($f['life_state'],$d['eligibility']['life_states'],true)||!in_array($f['participation'],$d['eligibility']['participation'],true)||$f['group_owner_uid']===null)continue;
            $out[]=['recipient_key'=>'entity:'.$f['entity_id'],'uid'=>(int)$f['group_owner_uid'],'control_group_id'=>$f['control_group_id'],'entity_ids'=>[$f['entity_id']]];
        }
        usort($out,fn($a,$b)=>strcmp($a['recipient_key'],$b['recipient_key']));
        if(count($out)>200)throw new RuntimeException('ACS_REWARD_RECIPIENT_LIMIT');
        foreach($out as $r)if($r['uid']<1||$r['uid']>4294967295)throw new RuntimeException('ACS_REWARD_RECIPIENT_INVALID');
        return $out;
    }
    public static function allocate(array $d,array $eligible,array $manual):array
    {
        self::definition($d);if(!array_is_list($manual))throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
        if($d['mode']!=='manual'&&$manual)throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
        if(!$eligible&&!$manual)return [];
        $amounts=[];$sum=0;
        foreach($manual as $m){
            if(!is_array($m))throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');self::fields($m,['recipient_key','amount_minor']);
            if(!is_string($m['recipient_key'])||!is_int($m['amount_minor'])||$m['amount_minor']<1||$m['amount_minor']>$d['amount_minor']-$sum||isset($amounts[$m['recipient_key']]))throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
            $sum+=$m['amount_minor'];$amounts[$m['recipient_key']]=$m['amount_minor'];
        }
        if($d['mode']==='manual'&&($sum!==$d['amount_minor']||array_diff(array_keys($amounts),array_column($eligible,'recipient_key'))))throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
        $n=count($eligible);$out=[];
        foreach($eligible as $i=>$e){
            $amount=match($d['mode']){'each'=>$d['amount_minor'],'split_equal'=>intdiv($d['amount_minor'],$n)+($i<$d['amount_minor']%$n?1:0),'manual'=>$amounts[$e['recipient_key']]??0};
            if($amount>0)$out[]=$e+['amount_minor'=>$amount];
        }
        return $out;
    }
    public static function kind(string $type):string
    {return match($type){'experience'=>'exp','currency'=>'credits',default=>throw new RuntimeException('ACS_PERMANENT_SYNC_BLOCKED')};}
    public static function authority(array $payload,string $id,string $issuer):array
    {
        return ['scope'=>'acs.reward','source_ref'=>'acs-reward:'.$id,'uid'=>$payload['recipient']['uid'],'kind'=>self::kind($payload['reward_type']),'amount_minor'=>$payload['recipient']['amount_minor'],'authority_ref'=>'acs-reward:'.$id.':'.$payload['definition_hash'],'confirmation_ref'=>$payload['confirmation_ref']??$payload['outcome_id'],'issuer_ref'=>'acs:reward-issuer:'.$issuer];
    }
    public static function receipt(array $intent,array $authority,array $r):void
    {
        $hash=$r['result_hash']??'';$body=$r;unset($body['result_hash']);
        $canonical=static function(array $v):string{ksort($v,SORT_STRING);return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);};
        if(!is_string($hash)||!hash_equals($hash,hash('sha256',$canonical($body)))||($r['status']??null)!=='committed')throw new RuntimeException('ACS_BALANCE_RECEIPT_INVALID');
        foreach($intent as $k=>$v)if(($r[$k]??null)!==$v)throw new RuntimeException('ACS_BALANCE_RECEIPT_INVALID');
        $ah=hash('sha256',$canonical($authority));
        if(($r['authority_hash']??null)!==$ah||($r['payload_hash']??null)!==hash('sha256',$canonical($intent+['authority_hash'=>$ah]))||($r['owner_version']??null)!==$intent['expected_version']+1||!is_int($r['balance_before']??null)||!is_int($r['balance_after']??null)||$r['balance_before']>PHP_INT_MAX-$intent['amount_minor']||$r['balance_after']!==$r['balance_before']+$intent['amount_minor']||!is_string($r['tx_id']??null)||!preg_match('/\A[1-9][0-9]*\z/D',$r['tx_id']))throw new RuntimeException('ACS_BALANCE_RECEIPT_INVALID');
    }
}
