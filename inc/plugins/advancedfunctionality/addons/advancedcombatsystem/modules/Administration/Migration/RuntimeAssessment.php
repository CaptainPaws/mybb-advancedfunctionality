<?php
if(!defined('IN_MYBB'))die('No direct access');
/** Read-only, best-effort observations. Neither a maintenance controller nor a DDL executor. */
final class AF_ACS_MigrationRuntimeAssessment
{
    public static function collect(callable $select,object $db):array
    {
        global $mybb;
        $try=static function($sql)use($select){try{return $select($sql);}catch(Throwable $e){return null;}};
        $variables=$try("SHOW VARIABLES WHERE Variable_name IN ('offline_mode','read_only','super_read_only','event_scheduler','datadir','tmpdir','innodb_tmpdir')");
        $vars=[];foreach($variables??[] as $r)$vars[strtolower($r['Variable_name'])]=$r['Value'];
        $writers=$try("SELECT COUNT(*) AS n FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_USER IS NOT NULL AND PROCESSLIST_ID IS NOT NULL AND PROCESSLIST_ID<>CONNECTION_ID()");
        $prefix=(string)$db->table_prefix;$tasks=null;
        if(preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix))$tasks=$try('SELECT COUNT(*) AS enabled FROM `'.$prefix.'tasks` WHERE enabled=1');
        $socket=false;
        try {if(isset($db->read_link)&&$db->read_link instanceof mysqli)$socket=str_contains($db->read_link->host_info,'UNIX socket');}catch(Throwable $e){}
        $spaces=[];
        foreach(['data'=>'datadir','temporary'=>'tmpdir','innodb_temporary'=>'innodb_tmpdir'] as $role=>$key){
            $path=$vars[$key]??null;$bytes=null;
            if($socket&&is_string($path)&&$path!==''&&($real=@realpath($path))!==false)$bytes=@disk_free_space($real);
            $spaces[$role]=['free_bytes'=>is_float($bytes)||is_int($bytes)?(int)$bytes:null,'status'=>$bytes!==null&&$bytes!==false?'OBSERVED_NOT_PROVEN':'UNAVAILABLE'];
        }
        $bytes=@disk_free_space(sys_get_temp_dir());$spaces['php_temporary']=['free_bytes'=>is_float($bytes)||is_int($bytes)?(int)$bytes:null,'status'=>'PHP_FILESYSTEM_ONLY'];
        return ['php_version'=>PHP_VERSION,'php_limit_seconds'=>(int)ini_get('max_execution_time'),'board_closed'=>!empty($mybb->settings['boardclosed']),
            'visible_other_connections'=>isset($writers[0]['n'])?(int)$writers[0]['n']:null,'enabled_tasks'=>isset($tasks[0]['enabled'])?(int)$tasks[0]['enabled']:null,
            'offline_mode'=>isset($vars['offline_mode'])?in_array(strtoupper($vars['offline_mode']),['ON','1'],true):null,
            'event_scheduler'=>isset($vars['event_scheduler'])&&in_array($vars['event_scheduler'],['ON','OFF','DISABLED'],true)?$vars['event_scheduler']:null,
            'space'=>$spaces,'http_execution'=>['allowed'=>false,'reasons'=>['ddl_duration_unbounded','http_disconnect_outcome','full_verification_unbounded','maintenance_not_proven']]];
    }
}
