<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/LootAllocationService.php';
final class AF_ACS_LootAllocationController
{
    public static function handle(AF_ACS_LootAllocationService $service,string $op,array $input,string $method):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        if($method!==($op==='loot_allocation_view'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='loot_allocation_view'){
            if(array_diff(array_keys($input),['session_id','encounter_id'])||count($input)!==2||!is_string($input['session_id'])||!is_string($input['encounter_id']))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            return $service->view($input['session_id'],$input['encounter_id']);
        }
        $command=match($op){'loot_gm_assign'=>'gm_assign','loot_free_claim'=>'free_claim','loot_claim'=>'claim','loot_allocation_close'=>'close',default=>throw new RuntimeException('ACS_LOOT_INPUT_INVALID')};
        return $service->command($command,$input);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $f)unset($input[$f]);
            $data=self::handle(new AF_ACS_LootAllocationService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);$response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $code=in_array($e->getMessage(),['ACS_LOOT_INPUT_INVALID','ACS_LOOT_ALLOCATION_UNSUPPORTED','ACS_LOOT_NOT_ELIGIBLE','ACS_LOOT_ALLOCATION_CLOSED','ACS_LOOT_ALLOCATION_INVALID','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_RECOVERY_REQUIRED'],true)?$e->getMessage():'ACS_LOOT_ALLOCATION_UNAVAILABLE';
            http_response_code(in_array($code,['ACS_LOOT_NOT_ELIGIBLE','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED'],true)?403:409);$response=['ok'=>false,'error'=>['code'=>$code]];
        }echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
}
