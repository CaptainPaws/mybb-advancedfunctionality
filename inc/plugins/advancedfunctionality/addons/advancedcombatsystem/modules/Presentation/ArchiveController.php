<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/ArchiveService.php';
final class AF_ACS_ArchiveController
{
    public static function handle(AF_ACS_ArchiveService $service,string $op,array $r,string $method):array
    {
        if($op==='archive_policy'){if($method!=='post')throw new RuntimeException('ACS_POST_REQUIRED');return $service->policy($r);}
        if($method!=='get')throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
        $fields=['session_id','encounter_id','cursor','round','actor','entity','control_group','category','event_type','pid','search',...($op==='archive_export'?['format','extended']:[]),...($op==='archive_manifest'?['section','after']:[]),...($op==='archive_roster'?['after']:[])];
        if(array_diff(array_keys($r),$fields)||!isset($r['session_id'],$r['encounter_id']))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
        $sid=AF_ACS_Repository::integer($r['session_id']);$eid=AF_ACS_Repository::integer($r['encounter_id']);$f=array_intersect_key($r,array_flip(['round','actor','entity','control_group','category','event_type','pid','search']));
        if($op==='archive_export'){if(!in_array($r['extended']??'0',['0','1'],true))throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');return $service->export($sid,$eid,$f,$r['cursor']??'',$r['format']??'json',($r['extended']??'0')==='1');}
        if($op==='archive_roster')return $service->roster($sid,$eid,$r['after']??'0');
        if($op==='archive_manifest')return $service->manifest($sid,$eid,$r['section']??'snapshots',$r['after']??'0');
        if($op!=='archive_events')throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');return $service->page($sid,$eid,$f,$r['cursor']??'');
    }
    public static function dispatch():never
    {
        global $db,$mybb;header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_ARCHIVE_INPUT_INVALID');
            $r=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $k)unset($r[$k]);
            $op=$mybb->get_input('op');$data=self::handle(new AF_ACS_ArchiveService($db,new AF_ACS_SessionAccess),$op,$r,$mybb->request_method);
            if($op==='archive_export'){header('Content-Type: '.$data['content_type']);header('Content-Disposition: attachment; filename="'.$data['filename'].'"');if($data['next_cursor']!==null)header('X-ACS-Next-Cursor: '.$data['next_cursor']);echo $data['body'];exit;}
            header('Content-Type: application/json; charset=UTF-8');$response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $code=preg_match('/\AACS_ARCHIVE_[A-Z_]+\z/D',$e->getMessage())||in_array($e->getMessage(),['ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT'],true)?$e->getMessage():'ACS_ARCHIVE_UNAVAILABLE';
            http_response_code(in_array($code,['ACS_ARCHIVE_ACCESS_DENIED','ACS_ARCHIVE_NATIVE_DENIED','ACS_ARCHIVE_EXPORT_DENIED','ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED'],true)?403:409);header('Content-Type: application/json; charset=UTF-8');$response=['ok'=>false,'error'=>['code'=>$code]];
        }echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
    }
}
