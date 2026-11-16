<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/StatisticsService.php';
final class AF_ACS_StatisticsController
{
    public static function dispatch():never
    {
        global $db,$mybb;header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
            $op=$mybb->get_input('op');$r=$mybb->request_method==='get'?$_GET:$_POST;
            foreach(['action','addon','op','my_post_key'] as $k)unset($r[$k]);
            foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
            $service=new AF_ACS_StatisticsService($db,new AF_ACS_SessionAccess);
            if($op==='statistics_sync'){
                if($mybb->request_method!=='post')throw new RuntimeException('ACS_POST_REQUIRED');
                $keys=['session_id','encounter_id','visibility'];if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
                $data=$service->synchronize($r['session_id'],$r['encounter_id'],$r['visibility']);
            }elseif(in_array($op,['statistics_status','statistics_recover'],true)){
                $keys=['session_id','encounter_id'];if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
                if($mybb->request_method!==($op==='statistics_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
                $data=$op==='statistics_status'?$service->status($r['session_id'],$r['encounter_id']):$service->recover($r['session_id'],$r['encounter_id']);
            }elseif($op==='statistics_profile'){
                if($mybb->request_method!=='get'||!isset($r['owner_uid'])||empty($mybb->usergroup['canviewprofiles'])||empty($mybb->usergroup['canview']))throw new RuntimeException('ACS_STATISTICS_ACCESS_DENIED');
                $uid=$r['owner_uid'];unset($r['owner_uid']);$data=$service->profile($uid,$r);
            }else throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
            $response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){http_response_code(409);$response=['ok'=>false,'error'=>['code'=>preg_match('/\AACS_(?:STATISTICS_[A-Z_]+|GM_REQUIRED|POST_REQUIRED|CSRF_REQUIRED)\z/D',$e->getMessage())?$e->getMessage():'ACS_STATISTICS_UNAVAILABLE']];}
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
