<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/AmbushPanelService.php';

/** Existing AF API transport with a shared, testable strict request boundary. */
final class AF_ACS_AmbushController
{
    public static function handle(AF_ACS_AmbushPanelService $service,string $op,array $input,string $method):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        if(!in_array($op,['ambush_status','ambush_ready','ambush_start'],true))throw new RuntimeException('ACS_AMBUSH_INPUT_INVALID');
        $fields=$op==='ambush_status'?['session_id','encounter_id']:['session_id','encounter_id','expected_version','encounter_version','operation_key','reason'];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_AMBUSH_INPUT_INVALID');
        foreach($input as $value)if(!is_string($value))throw new RuntimeException('ACS_AMBUSH_INPUT_INVALID');
        if($method!==($op==='ambush_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        return $op==='ambush_status'?$service->snapshot($input['session_id'],$input['encounter_id']):$service->command(substr($op,7),$input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_AMBUSH_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $f)unset($input[$f]);
            $data=self::handle(new AF_ACS_AmbushPanelService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);
            $response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $candidate=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
            $code=in_array($candidate,['ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_AMBUSH_INPUT_INVALID','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_DISABLED','ACS_PREPARED_UNSUPPORTED','ACS_AMBUSH_EXECUTION_BLOCKED','ACS_ROSTER_BLOCKED','ACS_FIRST_ROUND_EXISTS','INV_PREPARED_REVOKED','effects_cost_unaffordable'],true)?$candidate:'ACS_AMBUSH_UNAVAILABLE';
            http_response_code(in_array($code,['ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_FIRST_ROUND_EXISTS'],true)?409:403);
            $response=['ok'=>false,'error'=>['code'=>$code,'message'=>match($code){
                'ACS_VERSION_CONFLICT'=>'Версия изменилась. Проверьте результат и обновите панель.',
                'ACS_RECOVERY_REQUIRED'=>'Исход требует проверки. Не создавайте новый ключ запуска.',
                'ACS_PREPARED_UNSUPPORTED','ACS_AMBUSH_EXECUTION_BLOCKED'=>'Контракт стартовой подготовки не поддерживается.',
                default=>'Запуск не подтверждён. Проверьте результат прежней операции и права доступа.'}]];
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
