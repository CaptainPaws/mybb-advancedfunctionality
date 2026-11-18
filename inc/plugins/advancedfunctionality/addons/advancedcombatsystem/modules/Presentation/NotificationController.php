<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Application/NotificationService.php';
final class AF_ACS_NotificationController
{
    public static function dispatch():never
    {
        global $mybb,$db;header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            $op=$mybb->get_input('op');$r=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $k)unset($r[$k]);
            if(count($r)!==1||!is_string($r['session_id']??null)||!in_array($op,['alerts_status','alerts_retry'],true))throw new RuntimeException('ACS_ALERT_INPUT_INVALID');
            $access=new AF_ACS_SessionAccess;$repo=new AF_ACS_EncounterRepository($db);$sid=AF_ACS_Repository::integer($r['session_id']);$scope=(new AF_ACS_SessionService($repo,$access))->view($sid);
            if(!$scope['can_audit'])throw new RuntimeException('ACS_GM_REQUIRED');
            if($mybb->request_method!==($op==='alerts_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
            if($op==='alerts_retry'){$access->csrf();$data=(new AF_ACS_NotificationService($db))->drain($sid);}
            else{$rows=(new AF_ACS_NotificationOutbox($db))->pending($sid);$data=['scope'=>'one_due_batch','limit'=>20,'operations'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['message_key','status','attempts','available_at'])),$rows)];}
            $response=['ok'=>true,'data'=>$data];
        }catch(Throwable $error){http_response_code(409);$f=af_advancedcombatsystem_session_failure($error);$response=['ok'=>false,'error'=>['code'=>'ACS_ALERT_UNAVAILABLE','error_id'=>$f['error_id'],'message'=>'Доставка не подтверждена. Прежние ключи сохранены; бой и публикация не повторяются.']];}
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
