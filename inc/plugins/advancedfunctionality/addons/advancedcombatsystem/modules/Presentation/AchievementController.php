<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/CorrectionService.php';
final class AF_ACS_AchievementController
{
    public static function handle(object $database,AF_ACS_SessionAccess $access,string $op,array $r,string $method):array
    {
        $read=in_array($op,['achievement_status','achievement_profile','correction_status'],true);if($method!==($read?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='achievement_profile'){
            global $mybb;
            if(empty($mybb->usergroup['canviewprofiles'])||empty($mybb->usergroup['canview']))throw new RuntimeException('ACS_GM_REQUIRED');
            if(array_diff(array_keys($r),['owner_uid','cursor'])||!isset($r['owner_uid']))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
            foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
            return (new AF_ACS_AchievementService($database,$access))->profile($r['owner_uid'],$r['cursor']??'');
        }
        if($op==='achievement_status'){
            if(array_diff(array_keys($r),['session_id','after'])||!isset($r['session_id']))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
            return (new AF_ACS_AchievementService($database,$access))->view($r['session_id'],$r['after']??'0');
        }
        if($op==='correction_status'){
            if(count($r)!==3||array_diff(array_keys($r),['session_id','encounter_id','original_event_id']))throw new RuntimeException('ACS_CORRECTION_INPUT_INVALID');foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_CORRECTION_INPUT_INVALID');
            return (new AF_ACS_CorrectionService($database,$access))->view($r['session_id'],$r['encounter_id'],$r['original_event_id']);
        }
        if($op==='correction_event'){
            if(isset($r['plan'])&&is_string($r['plan']))$r['plan']=json_decode($r['plan'],true,32,JSON_THROW_ON_ERROR);
            return (new AF_ACS_CorrectionService($database,$access))->command($r);
        }
        return (new AF_ACS_AchievementService($database,$access))->command($op,$r);
    }
    public static function dispatch():never
    {
        global $mybb,$db;header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>32768)throw new RuntimeException('ACS_ACHIEVEMENT_INPUT_INVALID');
            $r=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $k)unset($r[$k]);
            $response=['ok'=>true,'data'=>self::handle($db,new AF_ACS_SessionAccess,$mybb->get_input('op'),$r,$mybb->request_method)];
        }catch(Throwable $e){
            $code=preg_match('/\AACS_(?:ACHIEVEMENT|CORRECTION)_[A-Z_]+\z/D',$e->getMessage())||in_array($e->getMessage(),['ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_ENCOUNTER_TRANSITION_BLOCKED'],true)?$e->getMessage():'ACS_ACHIEVEMENT_UNAVAILABLE';
            http_response_code(in_array($code,['ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_CORRECTION_PERMISSION_REQUIRED'],true)?403:409);$response=['ok'=>false,'error'=>['code'=>$code]];
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
    }
}
