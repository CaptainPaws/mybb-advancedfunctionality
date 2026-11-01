<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/DraftService.php';

/** Draft transport only. Does not route into session commands or diagnostic SQL writes. */
final class AF_ACS_ApiController
{
    public static function handle(AF_ACS_DraftService $service,string $op,array $input):array
    {
        if($op==='draft_restore'){
            global $mybb;if($mybb->request_method!=='get'||array_diff(array_keys($input),['draft_id']))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
            return $service->restore((string)($input['draft_id']??''));
        }
        if($op!=='draft_save')throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
        return $service->save($input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
            if((int)($_SERVER['CONTENT_LENGTH']??0)>262144)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
            $op=$mybb->get_input('op');$input=[];
            if($op==='draft_save'){
                if(array_diff(array_keys($_POST),['action','addon','op','my_post_key','session_id','encounter_id','turn_id','draft_id','expected_draft_version','operation_key','prose','actions']))throw new RuntimeException('ACS_DRAFT_INPUT_INVALID');
                foreach(['session_id','encounter_id','turn_id','draft_id','expected_draft_version','operation_key','prose'] as $f)$input[$f]=$mybb->get_input($f);
                $raw=$mybb->get_input('actions');if(strlen($raw)>65536)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');
                $input['actions']=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            }else $input=['draft_id'=>$mybb->get_input('draft_id')];
            $data=self::handle(new AF_ACS_DraftService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$op,$input);
            $result=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $candidate=$e->getMessage();$code=in_array($candidate,['ACS_DRAFT_VERSION_CONFLICT','ACS_DRAFT_ALREADY_EXISTS','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_DRAFT_TURN_UNAVAILABLE','ACS_DRAFT_READ_ONLY','ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED','ACS_DRAFT_UNAVAILABLE','ACS_RATE_LIMIT','ACS_DISABLED','ACS_SCHEMA_NOT_READY','ACS_REQUEST_TOO_LARGE','ACS_DRAFT_INPUT_INVALID'],true)?$candidate:'ACS_DRAFT_UNAVAILABLE';
            $conflict=in_array($code,['ACS_DRAFT_VERSION_CONFLICT','ACS_DRAFT_ALREADY_EXISTS','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_DRAFT_TURN_UNAVAILABLE','ACS_DRAFT_READ_ONLY'],true);
            http_response_code($conflict?409:($code==='ACS_RATE_LIMIT'?429:($code==='ACS_DRAFT_INPUT_INVALID'||$code==='ACS_REQUEST_TOO_LARGE'?422:403)));
            $message=$conflict?'Черновик или ход изменился. Восстановите сохранённую версию; локальный текст не отправляйте повторно вслепую.':($code==='ACS_DRAFT_INPUT_INVALID'?'Некорректный формат черновика.':'Черновик недоступен: проверьте доступ, подтверждение запроса и состояние ACS.');
            $result=['ok'=>false,'error'=>['code'=>$code,'message'=>$message]];
        }
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
