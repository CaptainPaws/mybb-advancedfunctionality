<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/LootService.php';
final class AF_ACS_LootController
{
    public static function handle(AF_ACS_LootService $service,string $op,array $input,string $method):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        if($method!==($op==='loot_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='loot_status'){
            if(array_diff(array_keys($input),['session_id','encounter_id','source_key'])||count($input)!==3)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            return $service->inspect($input['session_id'],$input['encounter_id'],$input['source_key']);
        }return $service->command($op,$input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $f)unset($input[$f]);
            $data=self::handle(new AF_ACS_LootService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);$response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $allowed=['ACS_LOOT_UNSUPPORTED','ACS_LOOT_INPUT_INVALID','ACS_LOOT_DEFINITION_UNAVAILABLE','ACS_LOOT_ITEM_PIN_INVALID','ACS_LOOT_SOURCE_UNAVAILABLE','ACS_LOOT_SOURCE_ALREADY_PINNED','ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED','ACS_LOOT_ELIGIBILITY_UNAVAILABLE','ACS_LOOT_PIN_INVALID','ACS_LOOT_RESULT_INVALID','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_GM_REQUIRED','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_ENCOUNTER_TRANSITION_BLOCKED','ACS_RECOVERY_REQUIRED'];
            $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'ACS_LOOT_UNAVAILABLE';http_response_code(in_array($code,['ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_GM_REQUIRED'],true)?403:409);$response=['ok'=>false,'error'=>['code'=>$code]];
        }echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
}
