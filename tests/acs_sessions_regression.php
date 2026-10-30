<?php
declare(strict_types=1);
// Only a disposable local MariaDB socket; creates/drops a random fixture DB.
$socket=$argv[1]??'';
if(!str_starts_with($socket,'/tmp/acs221-socket/') || !file_exists($socket))throw new RuntimeException('Pass the isolated ACS test socket');
define('IN_MYBB',true);define('IN_ADMINCP',true);define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');define('TABLE_PREFIX','fixture_');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$mybb=new class {
 public array $settings=['af_advancedcombatsystem_enabled'=>'1','af_acs_default_member_role'=>'observer'];
 public array $user=['uid'=>2,'usergroup'=>4];public array $usergroup=['cancp'=>1];
 public array $admin=['permissions'=>['advancedfunctionality'=>['index'=>1]]];public array $input=['my_post_key'=>'token'];public string $post_code='token';public string $request_method='post';public int $version_code=1840;
 public function get_input(string $key): string{return (string)($this->input[$key]??'');}
};
function af_is_addon_enabled(string $id):bool{return ($GLOBALS['mybb']->settings['af_'.$id.'_enabled']??'')==='1';}
function verify_post_check(string $key,bool $silent=false):bool{return $key==='token';}
function af_clear_addon_lifecycle_diagnostic(string $id):void{}
function af_store_addon_lifecycle_diagnostic(...$args):void{}
function htmlspecialchars_uni(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function af_sync_addon_languages(...$args):void{}
function af_load_addon_lang(...$args):void{$manifest=require AF_ADDONS.'advancedcombatsystem/manifest.php';$GLOBALS['lang']=(object)$manifest['lang']['english']['admin'];}
function af_adaptivethemeframework_register_component(array $component):bool{$GLOBALS['component']=$component;return true;}
function get_user(int $uid):array{return in_array($uid,[2,3,4,5,6],true)?['uid'=>$uid]:[];}
function get_thread(int $tid):array{return $tid===10?['tid'=>10,'fid'=>7,'uid'=>2,'visible'=>1,'subject'=>'Test <thread>']:[];}
function get_forum(int $fid):array{return $fid===7?['fid'=>7,'parentlist'=>'7','type'=>'f','password'=>'']:[];}
$hidden=[];
function forum_permissions(int $fid,int $uid):array{return ['canview'=>!in_array($uid,$GLOBALS['hidden'],true),'canviewthreads'=>1];}
function is_moderator(...$args):bool{return false;}
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $f,string $code):void{try{$f();}catch(Throwable $e){ensure(str_contains($e->getMessage(),$code),'Wrong failure: '.$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
require AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';
ensure(af_advancedcombatsystem_init()==='inactive' && !class_exists('AF_ACS_SessionService',false) && !class_exists('AF_ACS_EncounterService',false),'Ordinary bootstrap loaded command stack');
$server=new mysqli('localhost','root','','',0,$socket);$database='acs221_'.bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
 $link=new mysqli('localhost','root','',$database,0,$socket);$link->set_charset('utf8mb4');$db=$link;
 require AF_ADDONS.'advancedcombatsystem/modules/Administration/Migrations.php';
 (new AF_ACS_Migrations($link))->run();
 $link->query('CREATE TABLE fixture_users (uid int unsigned PRIMARY KEY) ENGINE=InnoDB');$link->query('INSERT INTO fixture_users VALUES(2),(3),(4),(5),(6)');
 $link->set_charset('utf8');$link->query("SET SESSION sql_mode=''");
 $connectionBefore=$link->query('SELECT @@character_set_connection,@@session.sql_mode')->fetch_row();
 $service=af_advancedcombatsystem_sessions();
 $input=['tid'=>'10','owner_uid'=>'2','reason'=>'Fixture session','operation_key'=>'create_fixture_0001'];
 $result=$service->command('create',$input);$id=$result['session_id'];
 ensure($service->command('create',$input)===$result,'Duplicate create changed result');
 ensure($link->query('SELECT COUNT(*) FROM fixture_af_acs_sessions')->fetch_row()[0]==='1','Duplicate session');
 ensure($link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()[0]==='1','Duplicate audit');
 $view=$service->view($id);ensure($view['members'][0]['role']==='owner' && $view['session']['status']==='active','Owner/status wrong');
 fails(fn()=>$service->command('create',array_replace($input,['tid'=>'99','operation_key'=>'create_bad_thread_1'])),'ACS_THREAD_UNAVAILABLE');
 $update=['session_id'=>$id,'expected_version'=>$result['session_version'],'uid'=>'3','role'=>'gm','reason'=>'Appoint GM','operation_key'=>'appoint_gm_0000001'];
 $gm=$service->command('set_role',$update);ensure($service->command('set_role',$update)===$gm,'Role retry not idempotent');
 fails(fn()=>$service->command('set_role',array_replace($update,['operation_key'=>'stale_role_0000001'])),'ACS_VERSION_CONFLICT');
 $mybb->user['uid']=3;$mybb->usergroup['cancp']=0;
 fails(fn()=>$service->command('set_role',['session_id'=>$id,'expected_version'=>$gm['session_version'],'uid'=>'4','role'=>'gm','reason'=>'Escalation','operation_key'=>'escalate_gm_000001']),'ACS_ROLE_ESCALATION_DENIED');
 $member=$service->command('set_role',['session_id'=>$id,'expected_version'=>$gm['session_version'],'uid'=>'4','role'=>'player','reason'=>'Join player','operation_key'=>'join_player_000001']);
 $mybb->user['uid']=5;
 fails(fn()=>$service->view($id),'ACS_SESSION_UNAVAILABLE');ensure(!$service->list()['items'],'Private session leaked in list');
 fails(fn()=>$service->command('create',array_replace($input,['owner_uid'=>'5','operation_key'=>'unauthorized_00001'])),'ACS_CREATE_DENIED');
 $mybb->user['uid']=4;
 fails(fn()=>$service->command('set_role',['session_id'=>$id,'expected_version'=>$member['session_version'],'uid'=>'5','role'=>'player','reason'=>'Forbidden','operation_key'=>'forbidden_0000001']),'ACS_SESSION_UNAVAILABLE');
 $hidden=[4];fails(fn()=>$service->view($id),'ACS_THREAD_UNAVAILABLE');$hidden=[];
 $mybb->user['uid']=2;$mybb->usergroup['cancp']=1;
 $mybb->request_method='get';fails(fn()=>$service->command('create',$input),'ACS_POST_REQUIRED');$mybb->request_method='post';
 $mybb->input['my_post_key']='bad';fails(fn()=>$service->command('create',$input),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
 $snapshot=$service->view($id);$mybb->settings['af_acs_default_member_role']='player';ensure($service->command('create',$input)===$result,'Changed defaults broke retry');ensure(json_decode($service->view($id)['session']['config_json'],true)['default_member_role']==='observer','New defaults changed old session');
 af_advancedcombatsystem_deactivate();fails(fn()=>af_advancedcombatsystem_sessions(),'ACS_DISABLED');af_advancedcombatsystem_activate();
 ensure(af_advancedcombatsystem_sessions()->view($id)===$snapshot,'Reactivation changed session/roles/version');
 ensure($link->query('SELECT @@character_set_connection,@@session.sql_mode')->fetch_row()===$connectionBefore,'ACS changed the shared ATF connection settings');
 ensure($link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()[0]==='3','Denied command wrote audit');
 fails(fn()=>$service->command('reopen',[]),'ACS_SESSION_TRANSITION_BLOCKED');
 // A later assignment increments role_version instead of deleting/recreating its history identity.
 $state=$service->view($id)['session'];
 $changed=$service->command('set_role',['session_id'=>$id,'expected_version'=>$state['session_version'],'uid'=>'4','role'=>'observer','reason'=>'Observe','operation_key'=>'observe_player_001']);
 ensure($link->query("SELECT role_version FROM fixture_af_acs_session_roles WHERE uid=4")->fetch_row()[0]==='2','Role version reset');
 $transfer=$service->command('transfer_owner',['session_id'=>$id,'expected_version'=>$changed['session_version'],'uid'=>'4','reason'=>'Owner handover','operation_key'=>'transfer_owner_001']);
 ensure($service->view($id)['session']['owner_uid']==='4','Ownership transfer failed');
 $remove=['session_id'=>$id,'expected_version'=>$transfer['session_version'],'uid'=>'3','reason'=>'Remove member','operation_key'=>'remove_member_0001'];
 $removed=$service->command('remove_member',$remove);ensure($service->command('remove_member',$remove)===$removed,'Removal retry changed result');
 fails(fn()=>$service->command('remove_member',array_replace($remove,['uid'=>'4','expected_version'=>$removed['session_version'],'operation_key'=>'remove_owner_00001'])),'ACS_OWNER_PROTECTED');
 ensure(!(new AF_ACS_AuthorizationService)->decision('entity.control',$id,AF_ACS_Role::Owner)['allowed'],'Admin obtained character bypass');
 $mybb->user['uid']=5;$mybb->user['usergroup']=8;$mybb->usergroup['cancp']=0;$mybb->settings['af_acs_session_gm_groups']='8';
 $own=$service->command('create',array_replace($input,['owner_uid'=>'5','operation_key'=>'gm_create_00000001']));
 ensure($service->view($own['session_id'])['session']['owner_uid']==='5','Configured GM could not create');
 fails(fn()=>$service->command('create',array_replace($input,['operation_key'=>'gm_other_owner_001'])),'ACS_CREATE_DENIED');
 $mybb->user['uid']=2;$mybb->usergroup['cancp']=1;$mybb->request_method='get';$mybb->input=['acs_section'=>'sessions','session_id'=>$id];
 $db=new class($link) {public function __construct(public mysqli $write_link){} public function table_exists(string $name):bool{return false;}};
 require AF_ADDONS.'advancedcombatsystem/admin.php';
 ob_start();AF_Admin_Advancedcombatsystem::dispatch();$html=ob_get_clean();
 ensure(str_contains($html,'Доступные сессии') && str_contains($html,'Назначить / изменить роль') && !str_contains($html,'Test <thread>'),'ACP dispatch or escaping failed');
 require AF_ADDONS.'advancedcombatsystem/modules/Presentation/ThreadComponents.php';$mybb->settings['af_adaptivethemeframework_enabled']='1';
 af_advancedcombatsystem_register_thread_component();ensure($GLOBALS['component']['slot']==='thread.before_posts','Wrong ATF extension');
 ensure(str_contains(af_advancedcombatsystem_thread_session_link(['tid'=>10]),'session_id='.$id),'GM topic link missing');
 ensure(!class_exists('AF_ACS_EncounterService',false) && !class_exists('AF_ACS_EncountersPage',false),'Ordinary topic loaded GM stack');
 $mybb->user['uid']=6;ensure(af_advancedcombatsystem_thread_session_link(['tid'=>10])==='','Foreign topic link leaked');
 $mybb->user['uid']=2;ensure(af_advancedcombatsystem_thread_session_link(['tid'=>999])==='','Ordinary topic changed');
 $link->query('RENAME TABLE fixture_af_acs_session_roles TO fixture_roles_unavailable');
 ensure(af_advancedcombatsystem_thread_session_link(['tid'=>10])==='','Broken optional slot did not fail closed');
 $link->query('RENAME TABLE fixture_roles_unavailable TO fixture_af_acs_session_roles');
 echo "ACS sessions: create/retry, roles/CAS/audit, denials/CSRF/native access, defaults/reactivation, configured GM, owner transfer and ACP/ATF dispatch passed\n";
}finally{$server->query('DROP DATABASE `'.$database.'`');}
