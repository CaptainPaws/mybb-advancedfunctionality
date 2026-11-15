<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/EncounterService.php';

/** Backend-only GM endpoint on the existing AF transport. No client winner authority. */
final class AF_ACS_EndConditionController
{
    public static function handle(AF_ACS_EncounterService $service,string $op,array $input,string $method):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $fields=['session_id','encounter_id'];
        if($op!=='outcome_status')$fields=[...$fields,'expected_version','encounter_version','reason','operation_key',...match($op){'end_conditions_set'=>['end_conditions'],'outcome_confirm'=>['resolution_id','outcome'],'outcome_continue'=>['resolution_id'],default=>throw new RuntimeException('ACS_END_INPUT_INVALID')}];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_END_INPUT_INVALID');
        foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_END_INPUT_INVALID');
        if($method!==($op==='outcome_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='outcome_status')return $service->resolution($input['session_id'],$input['encounter_id']);
        if($op==='end_conditions_set')$input['end_conditions']=json_decode($input['end_conditions'],true,16,JSON_THROW_ON_ERROR);
        return $service->command($op,$input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_END_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $field)unset($input[$field]);
            $data=self::handle(new AF_ACS_EncounterService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);
            $response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $code=in_array($e->getMessage(),['ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_END_INPUT_INVALID','ACS_END_OUTCOME_DENIED','ACS_END_CONTINUATION_DENIED','ACS_END_CONDITION_UNSUPPORTED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_RECOVERY_REQUIRED','ACS_END_STATE_CONFLICT','ACS_ENCOUNTER_TRANSITION_BLOCKED'],true)?$e->getMessage():'ACS_END_UNAVAILABLE';
            http_response_code(in_array($code,['ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_RECOVERY_REQUIRED','ACS_END_STATE_CONFLICT','ACS_ENCOUNTER_TRANSITION_BLOCKED'],true)?409:403);
            $response=['ok'=>false,'error'=>['code'=>$code,'message'=>'Решение не подтверждено. Проверьте сохранённый результат, права и версии.']];
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
