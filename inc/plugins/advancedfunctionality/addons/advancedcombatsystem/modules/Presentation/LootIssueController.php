<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/LootIssueService.php';
final class AF_ACS_LootIssueController
{
    public static function handle(AF_ACS_LootIssueService $service,string $op,array $input,string $method):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        if($method!==($op==='loot_issue_status'?'get':'post'))throw new RuntimeException('ACS_POST_REQUIRED');
        if($op==='loot_issue')return $service->issue($input);
        $fields=['session_id','encounter_id','entry_id',...($op==='loot_issue_recover'?['expected_operation_version']:[])];
        if(!in_array($op,['loot_issue_status','loot_issue_recover'],true)||array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        return $op==='loot_issue_status'?$service->inspect($input['session_id'],$input['encounter_id'],$input['entry_id']):$service->recover($input['session_id'],$input['encounter_id'],$input['entry_id'],$input['expected_operation_version']);
    }
    public static function dispatch():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $input=$mybb->request_method==='get'?$_GET:$_POST;foreach(['action','addon','op','my_post_key'] as $f)unset($input[$f]);
            $data=self::handle(new AF_ACS_LootIssueService($db,new AF_ACS_SessionAccess),$mybb->get_input('op'),$input,$mybb->request_method);$response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){
            $allowed=['ACS_LOOT_INPUT_INVALID','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_POST_REQUIRED','ACS_CSRF_REQUIRED','ACS_VERSION_CONFLICT','ACS_OPERATION_KEY_CONFLICT','ACS_LOOT_ISSUER_REQUIRED','ACS_LOOT_ISSUE_NOT_ALLOCATED','ACS_LOOT_ISSUE_INVALID','ACS_LOOT_ISSUE_RECEIPT_INVALID','ACS_INVENTORY_OPERATION_INTEGRITY','ACS_LOOT_OWNER_METADATA_UNSUPPORTED','ACS_LOOT_OWNER_DEFINITION_CHANGED','ACS_LOOT_GRANT_KIND_UNSUPPORTED','ACS_LOOT_KB_UNAVAILABLE'];
            $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'ACS_LOOT_ISSUE_UNAVAILABLE';http_response_code(in_array($code,['ACS_GM_REQUIRED','ACS_LOOT_ISSUER_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_THREAD_UNAVAILABLE','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED'],true)?403:409);$response=['ok'=>false,'error'=>['code'=>$code]];
        }echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
}
