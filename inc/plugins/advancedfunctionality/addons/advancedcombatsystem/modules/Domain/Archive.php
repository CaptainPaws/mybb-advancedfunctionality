<?php
if (!defined('IN_MYBB')) {die('No direct access');}
/** Projection only; never a mechanical calculator or raw owner-envelope serializer. */
final class AF_ACS_Archive
{
    public const PUBLIC_TYPES=['combat.resolved','encounter.outcome_confirm','correction.review'];
    public const CATEGORIES=['combat','round','encounter','loot','reward','inventory','correction','achievement','archive','other'];
    public static function category(string $type):string
    {$p=explode('.',$type)[0];return in_array($p,self::CATEGORIES,true)?$p:'other';}
    public static function filters(array $r):array
    {
        if(array_diff(array_keys($r),['round','actor','entity','control_group','category','event_type','pid','search']))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
        $out=[];foreach(['round','actor','entity','control_group','category','event_type','pid','search'] as $k){$v=$r[$k]??'';if(!is_string($v))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
            if($v!==''&&in_array($k,['round','actor','entity','control_group','pid'],true))$v=AF_ACS_Repository::integer($v);
            if($v!==''&&$k==='category'&&!in_array($v,self::CATEGORIES,true)||$v!==''&&$k==='event_type'&&!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$v)||$k==='search'&&(!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>120||preg_match('/[\x00-\x1f]/',$v)))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');$out[$k]=$v;
        }return $out;
    }
    public static function matches(array $v,array $f):bool
    {
        foreach(['round'=>'round','category'=>'category','event_type'=>'type'] as $k=>$field)if($f[$k]!==''&&(string)($v[$field]??'')!==$f[$k])return false;
        if($f['pid']!==''&&($v['post']['pid']??null)!==$f['pid'])return false;
        foreach(['actor'=>'actors','entity'=>'entities','control_group'=>'control_groups'] as $key=>$field)if($f[$key]!==''&&!in_array($f[$key],$v[$field]??[],true))return false;
        if($f['search']!==''){
            $text=implode(' ',[$v['type'],$v['category'],$v['reason']??'',$v['post']['pid']??'',...array_column($v['actions']??[],'ability_key')]);
            if(mb_stripos($text,$f['search'])===false)return false;
        }return true;
    }
    public static function actions(array $body,bool $full):array
    {
        $out=[];foreach($body['plan']['actions']??[] as $a){
            $step=['outcome'=>$a['scenario'],'damage'=>$a['allocation']['integer_budget']??0];
            if($full){
                $step+=['actor_id'=>(string)$a['actor_id'],'targets'=>array_map('strval',$a['targets']??[]),'ability_key'=>$a['ability_ref']['source_key']??$a['ability_ref']['key']??null,'rolls'=>$a['rolls']??null,'probabilities'=>$a['probabilities']??null,'costs'=>array_map(static fn($c)=>array_intersect_key($c,array_flip(['kind','resource','amount','qty'])),$a['costs']??[]),'components'=>array_map(static fn($c)=>array_intersect_key($c,array_flip(['damage_type','element','damage_element','raw','after_crit','effective_defense','post_defense','allocated','immunity'])),$a['allocation']['components']??[])];
                // Causal children retain order and identities, without granting raw owner receipts.
                $children=[];foreach($a['effect_responses']??[] as $x)$children[]=array_intersect_key($x,array_flip(['kind','phase','parent_event_id','executed','reason','damage','hp_loss','absorbed','sample']));
                foreach($a['reactions']??[] as $x)$children[]=['kind'=>'reaction']+array_intersect_key($x,array_flip(['id','entity_id','phase','executed','reason','redirect_success','original_target','resolved_target']));
                foreach($a['strikes']??[] as $x)$children[]=['kind'=>'strike']+array_intersect_key($x,array_flip(['strike_index','original_target','resolved_target','scenario','rolls']));
                $step['children']=$children;
            }$out[]=$step;
        }return $out;
    }
    public static function csv(array $events):string
    {
        $stream=fopen('php://temp','w+');$headers=['event_id','event_sequence','category','type','round','pid','detail_level','reason','details_json'];fputcsv($stream,$headers,',','"','');
        foreach($events as $e){$cells=[$e['event_id'],$e['event_sequence']??'',$e['category'],$e['type'],$e['round']??'',$e['post']['pid']??'',$e['detail_level'],$e['reason']??'',json_encode(array_diff_key($e,array_flip(['reason'])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
            $cells=array_map(static function($v){$s=(string)$v;return preg_match('/\A[\s\x00-\x20\x{FEFF}]*[=+@-]/u',$s)?"'".$s:$s;},$cells);fputcsv($stream,$cells,',','"','');
        }rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
    public static function cursor(array $payload,string $key):string
    {
        if(!function_exists('openssl_encrypt')||!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new RuntimeException('ACS_ARCHIVE_CURSOR_UNAVAILABLE');
        $iv=random_bytes(12);$tag='';$c=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',hex2bin($key),OPENSSL_RAW_DATA,$iv,$tag,'acs-archive-v1');
        if($c===false)throw new RuntimeException('ACS_ARCHIVE_CURSOR_UNAVAILABLE');return rtrim(strtr(base64_encode($iv.$tag.$c),'+/','-_'),'=');
    }
    public static function openCursor(string $token,string $key):array
    {
        if(strlen($token)>4096||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$token)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');
        $raw=base64_decode(strtr($token,'-_','+/'),true);if($raw===false||strlen($raw)<29)throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin($key),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'acs-archive-v1');
        if($plain===false)throw new RuntimeException('ACS_ARCHIVE_CURSOR_INVALID');return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    }
}
