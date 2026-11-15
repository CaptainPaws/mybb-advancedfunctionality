<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/RewardService.php';
final class AF_ACS_RewardController
{
    public static function handle(AF_ACS_RewardService $s,string $op,array $input,string $method):array
    {
        if($method!==($op==='reward_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='reward_status'){
            if(array_diff(array_keys($input),['session_id','encounter_id','after'])||!isset($input['session_id'],$input['encounter_id']))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            return $s->view($input['session_id'],$input['encounter_id'],$input['after']??'0');
        }
        if($op==='reward_recover'){
            if(count($input)!==3||array_diff(array_keys($input),['session_id','reward_id','expected_operation_version']))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            return $s->recover($input['session_id'],$input['reward_id'],$input['expected_operation_version']);
        }
        if($op==='reward_issue')return $s->issue($input);
        if(isset($input['allocations'])&&is_string($input['allocations']))$input['allocations']=json_decode($input['allocations'],true,32,JSON_THROW_ON_ERROR);
        return $s->command($op,$input);
    }
    public static function dispatch():never
    {
        global $db,$mybb;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>32768)throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $k)unset($input[$k]);
            $result=self::handle(new AF_ACS_RewardService($db,new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);$response=['ok'=>true,'data'=>$result];
        }catch(Throwable $e){
            $allowed=['ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_REWARD_INPUT_INVALID','ACS_REWARD_UNSUPPORTED','ACS_REWARD_DEFINITION_UNAVAILABLE','ACS_REWARD_ALREADY_PINNED','ACS_REWARD_CONFIRMED_OUTCOME_REQUIRED','ACS_REWARD_SOURCE_UNAVAILABLE','ACS_REWARD_ALLOCATION_INVALID','ACS_REWARD_ALLOCATION_CLOSED','ACS_REWARD_INTEGRITY','ACS_BALANCE_RECEIPT_INVALID','ACS_PERMANENT_SYNC_BLOCKED','ACS_ENCOUNTER_TRANSITION_BLOCKED'];
            $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'ACS_REWARD_UNAVAILABLE';http_response_code(in_array($code,['ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED'],true)?403:409);$response=['ok'=>false,'error'=>['code'=>$code]];
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
    }
}
