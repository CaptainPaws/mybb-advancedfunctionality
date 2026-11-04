<?php
/** Focused ACP diagnostics fixture; no DB or production MyBB required. */
define('IN_MYBB',true);define('IN_ADMINCP',true);
$root=sys_get_temp_dir().'/acs413-'.bin2hex(random_bytes(6)).'/';mkdir($root);define('MYBB_ROOT',$root);
function htmlspecialchars_uni($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function af_is_addon_enabled($id){return true;}
$mybb=new class {public $version='1.8.40';public $user=['uid'=>1];public $usergroup=['cancp'=>1];public $admin=['permissions'=>['advancedfunctionality'=>['index'=>1]]];};
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function acs413_callback(){}
$db=new class {
    public $table_prefix='private_prefix_';public $read_link;public $write_link;public $queries=[];public $fail=false;public $split=false;
    public $password='SECRET_DB_PASSWORD';public $hostname='SECRET_HOST';
    function __construct(){$this->read_link=new stdClass;$this->write_link=$this->read_link;}
    function query($sql,$hidden,$writer){
        check(str_starts_with($sql,'SELECT '),'non-read query');check($hidden===1,'SQL errors not hidden');$this->queries[]=$sql;
        if($this->fail)throw new RuntimeException('SECRET_DB_EXCEPTION');
        if(str_contains($sql,'VERSION()'))return (object)['rows'=>[['version'=>'10.11.19-MariaDB','connection_id'=>$writer&&$this->split?'22':'11']]];
        if(str_contains($sql,'information_schema.TABLES'))return (object)['rows'=>[['TABLE_NAME'=>$this->table_prefix.'posts','ENGINE'=>'InnoDB'],['TABLE_NAME'=>$this->table_prefix.'users','ENGINE'=>'MyISAM'],['TABLE_NAME'=>$this->table_prefix.'af_acs_sessions','ENGINE'=>'MyISAM']]];
        if(str_contains($sql,'information_schema.TRIGGERS'))return (object)['rows'=>[['TRIGGER_NAME'=>'SECRET_TRIGGER_NAME','EVENT_OBJECT_TABLE'=>$this->table_prefix.'posts','ACTION_TIMING'=>'AFTER','EVENT_MANIPULATION'=>'INSERT']]];
        throw new RuntimeException('Unexpected SQL');
    }
    function fetch_array($q){return array_shift($q->rows);}
    function free_result($q){}
};
$plugins=(object)['hooks'=>[
    'datahandler_post_insert_post'=>[7=>['acs413_callback'=>['function'=>'acs413_callback','file'=>'/private/SECRET_PATH/inc/plugins/testplugin.php']]],
    'datahandler_post_update_end'=>[10=>['deferred'=>['function'=>'deferred_not_loaded','file'=>'/private/SECRET_PATH/inc/plugins/advancedfunctionality/addons/balance/balance.php']]],
    'unrelated_hook'=>[10=>[['function'=>'SECRET_UNRELATED']]],
]];
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Administration/PublicationDiagnostics.php';
try {
    $d=new AF_ACS_PublicationDiagnostics;$r=$d->collect();
    check($r['mybb'][3]['status']==='BLOCKED','missing handler');
    mkdir($root.'inc');mkdir($root.'inc/datahandlers');file_put_contents($root.'inc/datahandlers/post.php','<?php /* modified fixture, not core */');
    $modified=$d->collect();check($modified['mybb'][4]['status']==='WARNING','changed core hash');
    unlink($root.'inc/datahandlers/post.php');rmdir($root.'inc/datahandlers');rmdir($root.'inc');
    check($r['publication']['status']==='BLOCKED','publish readiness');
    check(count($r['hooks'])===2&&$r['hooks'][0]['priority']===7,'native hook shape/priority');
    check($r['hooks'][1]['owner']==='balance','deferred source owner');
    $tables=array_column($r['tables'],null,'name');
    check($tables['posts']['status']==='PASS'&&$tables['users']['status']==='PASS','MyBB engines are informational');
    check($tables['af_acs_sessions']['status']==='BLOCKED','owned ACS engine still required');
    check($tables['af_acs_rolls']['status']==='BLOCKED','required current ACS table');
    check($r['database'][1]['status']==='PASS','same connection observation');
    $db->split=true;check($d->collect()['database'][1]['status']==='NOT_VERIFIED','split route');$db->split=false;
    $db->fail=true;$failure=$d->collect();check($failure['database'][0]['status']==='NOT_VERIFIED','metadata failure');$db->fail=false;
    ob_start();AF_ACS_PublicationDiagnostics::render();$html=ob_get_clean();
    check(preg_match('~data:application/json;base64,([A-Za-z0-9+/=]+)~',$html,$m)===1,'export download');
    $json=base64_decode($m[1],true);$export=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    foreach(['SECRET_','private_prefix_','SECRET_DB_EXCEPTION','password','hostname','my_post_key'] as $secret)check(!str_contains($json,$secret),'export secret: '.$secret);
    check($export['publication']['status']==='BLOCKED','export status');
    $before=count($db->queries);$mybb->usergroup['cancp']=0;
    try{$d->collect();throw new LogicException('access granted');}catch(RuntimeException $e){check($e->getMessage()==='ACS_ADMIN_PERMISSION_REQUIRED','deny permission');}
    check(count($db->queries)===$before,'query before authorization');
    $mybb->usergroup['cancp']=1;$mybb->admin['permissions']=[];
    try{$d->collect();throw new LogicException('AF grant bypass');}catch(RuntimeException $e){check($e->getMessage()==='ACS_ADMIN_PERMISSION_REQUIRED','AF permission');}
    require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php';
    $before=count($db->queries);ob_start();AF_Admin_Advancedcombatsystem::dispatch();$denied=ob_get_clean();
    check(str_contains($denied,'Access denied')&&count($db->queries)===$before,'ACP dispatch denial');
    echo 'Publication diagnostics: ACP/AF permissions, read-only SQL, engines/current ACS tables, hooks/priority/owner, split/failure states and anonymized export passed'.PHP_EOL;
}finally{rmdir($root);}
