<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/SafeError.php';
require_once __DIR__.'/../Infrastructure/VersionQuery.php';
final class AF_ACS_VersionController
{
    public static function dispatch():never
    {
        global $mybb,$db;$start=hrtime(true);$memory=memory_get_usage(true);
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            $r=$_GET;foreach(['action','addon','op'] as $key)unset($r[$key]);
            if($mybb->request_method!=='get'||array_diff(array_keys($r),['session_id','tid','encounter_id','draft_id','profile'])||!isset($r['session_id'],$r['tid']))throw new RuntimeException('ACS_VERSIONS_INPUT_INVALID');
            foreach($r as $v)if(!is_string($v))throw new RuntimeException('ACS_VERSIONS_INPUT_INVALID');
            $query=new AF_ACS_VersionQuery($db);$data=$query->current($r['session_id'],$r['tid'],$r['encounter_id']??'',$r['draft_id']??'');
            $profile=($r['profile']??'')==='1'&&$data['can_profile'];unset($data['can_profile']);
            if($profile){$data['profile']=['version_query_count'=>$query->queries,'server_ms'=>round((hrtime(true)-$start)/1e6,3),'memory_delta_bytes'=>memory_get_usage(true)-$memory,'peak_memory_bytes'=>memory_get_peak_usage(true)];header('Server-Timing: acs_versions;dur='.$data['profile']['server_ms']);}
            $result=['ok'=>true,'data'=>$data];
        }catch(Throwable $error){http_response_code(409);$result=['ok'=>false,'error'=>AF_ACS_SafeError::from($error,'versions')];}
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(!empty($profile))header('X-ACS-Payload-Bytes: '.strlen($json));echo $json;exit;
    }
}
