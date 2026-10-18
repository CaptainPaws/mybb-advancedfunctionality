<?php
/** Isolated MySQL/InnoDB regression fixture. Never point this at a forum database.
 * AG_TEST_DB_HOST defaults to af-gallery-test-db, database is always gallery_test.
 * CLI initializes legacy Gallery tables then tests additive migration and providers.
 * php -S ... this-file.php exposes test-only HTTP fixtures for the real upload handlers.
 */
define('IN_MYBB', 1);
define('TABLE_PREFIX', 'test_');
define('TIME_NOW', time());
define('MYBB_ROOT', '/tmp/ag-media-fixture/');
define('AF_ADDONS', dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
class MyBB {
    const INPUT_INT = 1, INPUT_ARRAY = 2;
    public array $user = ['uid'=>7, 'usergroup'=>2, 'additionalgroups'=>''];
    public array $settings = [
        'af_advancedgallery_enabled'=>1, 'af_advancedgallery_can_upload_groups'=>'2,4',
        'af_advancedgallery_can_moderate_groups'=>'4', 'af_advancedgallery_autoapprove_groups'=>'2,4',
        'af_advancedgallery_allowed_ext'=>'png,jpg,jpeg,gif,webp', 'af_advancedgallery_upload_max_mb'=>1,
        'af_advancedgallery_thumb_w'=>32, 'af_advancedgallery_thumb_h'=>32,
        'af_advancedgallery_max_albums'=>2, 'af_advancedgallery_max_media_per_album'=>200,
        'af_advancedgallery_max_storage_mb'=>0, 'uploadspath'=>'uploads', 'bburl'=>'http://127.0.0.1:8099',
    ];
    public string $post_code='fixture-key', $request_method='get';
    public function get_input($key, $type=0) {
        $v=$_POST[$key] ?? $_GET[$key] ?? ($type===self::INPUT_ARRAY ? [] : '');
        return $type===self::INPUT_INT ? (int)$v : ($type===self::INPUT_ARRAY ? (array)$v : (string)$v);
    }
}
class GalleryTestDB {
    public mysqli $connection;
    public function __construct() {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->connection=new mysqli(getenv('AG_TEST_DB_HOST') ?: 'af-gallery-test-db','root','','gallery_test');
        $this->connection->set_charset('utf8mb4');
    }
    public function write_query($sql) {return $this->connection->query($sql);}
    public function query($sql) {return $this->write_query($sql);}
    public function escape_string($s) {return $this->connection->real_escape_string((string)$s);}
    public function simple_select($table,$fields='*',$where='',$options=[]) {
        $sql='SELECT '.$fields.' FROM '.TABLE_PREFIX.$table.($where!=='' ? ' WHERE '.$where : '');
        if(isset($options['order_by'])) $sql.=' ORDER BY '.$options['order_by'].' '.($options['order_dir'] ?? 'ASC');
        if(isset($options['limit'])) $sql.=' LIMIT '.(int)($options['limit_start']??0).','.(int)$options['limit'];
        return $this->write_query($sql);
    }
    public function fetch_array($q) {return $q->fetch_assoc() ?: false;}
    public function fetch_field($q,$key) {$r=$this->fetch_array($q);return $r[$key] ?? null;}
    public function insert_query($table,$row) {
        $values=array_map(fn($v)=>"'".$v."'",array_values($row));
        $this->write_query('INSERT INTO '.TABLE_PREFIX.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',$values).')');
        return $this->insert_id();
    }
    public function update_query($table,$row,$where) {
        $values=[];foreach($row as $k=>$v)$values[]=$k."='".$v."'";
        return $this->write_query('UPDATE '.TABLE_PREFIX.$table.' SET '.implode(',',$values).' WHERE '.$where);
    }
    public function delete_query($table,$where) {return $this->write_query('DELETE FROM '.TABLE_PREFIX.$table.' WHERE '.$where);}
    public function drop_table($table) {return $this->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);}
    public function insert_id() {return $this->connection->insert_id;}
    public function table_exists($table) {return (bool)$this->fetch_array($this->write_query("SHOW TABLES LIKE '".TABLE_PREFIX.$table."'"));}
    public function field_exists($field,$table) {return (bool)$this->fetch_array($this->write_query('SHOW COLUMNS FROM '.TABLE_PREFIX.$table." LIKE '{$field}'"));}
}
class GalleryFixtureTemplates {
    public function get($name) {
        $source=file_get_contents(AF_ADDONS.'advancedgallery/templates/advancedgallery.html');
        preg_match('~<!-- TEMPLATE: '.preg_quote($name,'~').' -->\s*(.*?)(?=<!-- TEMPLATE:|\z)~s',$source,$m);
        return str_replace(['\\','"'],['\\\\','\\"'],$m[1] ?? '');
    }
}
class GalleryFixtureError extends RuntimeException {}
class GalleryFixtureRedirect extends RuntimeException {}
function error($message) {throw new GalleryFixtureError($message);}
function error_no_permission() {throw new GalleryFixtureError('forbidden',403);}
function verify_post_check($key) {if($key!=='fixture-key') throw new GalleryFixtureError('csrf',403);}
function redirect($url,$message='') {throw new GalleryFixtureRedirect($url);}
function htmlspecialchars_uni($s) {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function get_user($uid) {return ['uid'=>$uid,'username'=>'Fixture '.$uid];}
function check($v,$message) {if(!$v)throw new RuntimeException($message);}
function count_rows($table,$where='') {global $db;return (int)$db->fetch_field($db->simple_select($table,'COUNT(*) AS n',$where),'n');}
$db=new GalleryTestDB();$mybb=new MyBB();$lang=new stdClass();$templates=new GalleryFixtureTemplates();
require AF_ADDONS.'advancedgallery/advancedgallery.php';

if(PHP_SAPI==='cli-server') {
    $mybb->request_method=strtolower($_SERVER['REQUEST_METHOD']);
    $mybb->user['uid']=(int)($_GET['fixture_uid']??$_COOKIE['fixture_uid']??7);
    $mybb->user['usergroup']=(int)($_GET['fixture_group']??2);
    if(isset($_GET['storage_limit']))$mybb->settings['af_advancedgallery_max_storage_mb']=(int)$_GET['storage_limit'];
    if(isset($_GET['pending']))$mybb->settings['af_advancedgallery_autoapprove_groups']='4';
    if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/uploads/')) {
        $path=MYBB_ROOT.ltrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
        if(str_contains($path,'..')||!is_file($path)){http_response_code(404);exit;}
        header('Content-Type: image/png');readfile($path);exit;
    }
    try {
        switch($_GET['action']??'') {
            case 'fixture_page':
                header('Content-Type: text/html');
                echo '<!doctype html><html><body><textarea></textarea><script>window.AF_GalleryPickerConfig={pickerUrl:"gallery.php?action=picker",dataUrl:"gallery.php?action=picker_data"};</script><script src="gallery.php?action=fixture_js"></script></body></html>';break;
            case 'fixture_js':header('Content-Type: application/javascript');readfile(AF_ADDONS.'advancedgallery/assets/advancedgallery.js');break;
            case 'picker': ag_render_picker_modal();break;
            case 'picker_data':ag_handle_picker_data();break;
            case 'storage_usage':ag_json_response(['ok'=>true,'storage'=>ag_storage_usage((int)$mybb->user['uid'])]);break;
            case 'picker_select':ag_handle_picker_select();break;
            case 'upload_do':ag_handle_upload();break;
            case 'remote_add_do':ag_handle_remote_add_do();break;
            case 'delete':ag_handle_delete();break;
            case 'bulk_do':ag_handle_bulk_do();break;
            case 'album_edit_do':ag_handle_album_edit_do();break;
            case 'album_delete':ag_handle_album_delete();break;
            case 'owned':header('Content-Type: application/json');echo json_encode(ag_get_owned_media((int)($_GET['owner']??7),(int)$_GET['id']));break;
            default:http_response_code(404);
        }
    } catch(GalleryFixtureRedirect $e){header('Content-Type: application/json');echo json_encode(['redirect'=>$e->getMessage()]);}
    catch(GalleryFixtureError $e){http_response_code($e->getCode()?:422);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
    exit;
}
if(($argv[1]??'')==='--storage') {require __DIR__.'/gallery_storage_integration.php';exit;}
if(($argv[1]??'')==='--race') {echo ag_get_or_create_system_album(7,'appearance_avatars');exit;}
if(($argv[1]??'')==='--retain-race') {echo ag_retain_owned_media(7,(int)$argv[2]) ? 'retained' : 'refused';exit;}
if(($argv[1]??'')==='--delete-race') {echo ag_delete_unretained_media((int)$argv[2]) ? 'deleted' : 'kept';exit;}
if(($argv[1]??'')==='--quota-race') {
    $mybb->user['uid']=40;$mybb->settings['af_advancedgallery_max_storage_mb']=1;
    try {
        ag_enforce_storage_limit(40,600000);
        usleep(100000);
        $db->write_query("INSERT INTO test_af_gallery_media (uid_owner,created_at,updated_at,description,embed_html,filesize) VALUES (40,1,1,'','',600000)");
        echo 'saved';
    } catch(GalleryFixtureError $e){echo 'limited';}
    exit;
}
if(($argv[1]??'')==='--after-http') {
    check(count_rows('af_gallery_albums',"uid_owner=30")===0,'GET picker/invalid upload created album');
    $media=$db->fetch_array($db->simple_select('af_gallery_media','*',"uid_owner=7 AND appearance_retained=1 AND original_name<>''",['limit'=>1]));
    check((bool)$media,'No retained upload');
    check(is_file(af_ag_abs_path($media['storage_path'])),'Retained file disappeared');
    check(is_file(af_ag_abs_path($media['thumb_path'])),'Thumbnail missing');
    check(getimagesize(af_ag_abs_path($media['thumb_path']))[0]===32,'Thumbnail not resized');
    check(str_starts_with($media['storage_path'],'uploads/af_gallery/7/'.date('Y/m').'/'),'Upload structure changed');
    check(!ag_delete_unretained_media((int)$media['id']),'Retained deletion allowed');
    $album=ag_get_system_album(7,'appearance_profile_bg');
    check($album['visibility']==='private' && $album['system_key']==='appearance_profile_bg','Forged visibility/key accepted');
    for($i=0;$i<8;$i++) {
        $path=MYBB_ROOT.'uploads/race-'.$i.'.png';file_put_contents($path,'fixture bytes');
        $db->write_query("INSERT INTO test_af_gallery_media (uid_owner,type,status,mime,storage_path,created_at,updated_at,description,embed_html) VALUES (7,'local','approved','image/png','uploads/race-{$i}.png',1,1,'','')");
        $mid=$db->insert_id();$workers=[];
        foreach(['--retain-race','--delete-race'] as $mode){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,$mode,(string)$mid],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
        $results=[];foreach($workers as [$proc,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Delete/retain race failed: '.$err);}
        if($results[0]==='retained')check($results[1]==='kept'&&is_file($path),'Published selection lost file');
        if($results[1]==='deleted')check($results[0]==='refused'&&!is_file($path),'Deleted file selected');
    }
    echo "HTTP persistence/path/thumbnail/retention checks passed.\n";exit;
}
foreach(['af_gallery_album_media','af_gallery_albums','af_gallery_media','af_gallery_logs'] as $t)$db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$t);
$db->write_query('DROP TABLE IF EXISTS test_settings');$db->write_query('DROP TABLE IF EXISTS test_settinggroups');
$db->write_query("CREATE TABLE test_settinggroups (gid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,disporder INT,isdefault INT) ENGINE=InnoDB");
$db->write_query("CREATE TABLE test_settings (sid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,optionscode TEXT,value TEXT,disporder INT,gid INT) ENGINE=InnoDB");
ag_ensure_storage_setting();
$db->update_query('settings',['value'=>17],"name='af_advancedgallery_max_storage_mb'");ag_ensure_storage_setting();
check((int)$db->fetch_field($db->simple_select('settings','value',"name='af_advancedgallery_max_storage_mb'"),'value')===17,'Reactivation reset storage quota');
$source=file_get_contents(AF_ADDONS.'advancedgallery/advancedgallery.php');
preg_match_all('~CREATE TABLE \{TABLE_PREFIX\}(af_gallery_[a-z_]+) \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;~s',$source,$matches);
check(count($matches[0])===4,'Legacy schema fixture not found');
foreach($matches[0] as $sql)$db->write_query(str_replace('{TABLE_PREFIX}',TABLE_PREFIX,$sql));
$db->write_query("INSERT INTO test_af_gallery_albums (uid_owner,title,description,created_at,updated_at) VALUES (7,'Old album','unchanged',1,1),(7,'Other album','unchanged',2,2)");
$before=[];$q=$db->simple_select('af_gallery_albums');while($r=$db->fetch_array($q))$before[]=$r;
check(ag_get_system_album(7,'appearance_avatars')===null,'Reader should work before upgrade');
ag_ensure_default_album_schema();ag_ensure_system_album_schema();ag_ensure_system_album_schema();
check(count_rows('af_gallery_albums')===2,'Migration created empty albums');
$q=$db->simple_select('af_gallery_albums');$after=[];while($r=$db->fetch_array($q)){check($r['system_key']===null,'Legacy key not NULL');unset($r['system_key'],$r['is_default']);$after[]=$r;}
check($before===$after,'Legacy rows changed');
check(ag_get_system_album(7,'appearance_avatars')===null,'Missing album lookup');
check(ag_picker_initial_album(7,'appearance_avatars')===-2,'Missing initial purpose');
check(count_rows('af_gallery_albums')===2,'Read created album');
$children=[];
for($i=0;$i<12;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$ids=[];foreach($children as [$proc,$pipes]){$ids[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Race failed: '.$err);}
check(count(array_unique($ids))===1,'Concurrent duplicate albums');
$id=(int)$ids[0];check($id>0,'No system ID');
check(af_ag_resolve_upload_path()===MYBB_ROOT.'uploads','Relative upload root');
$mybb->settings['uploadspath']='/tmp/gallery-absolute';check(af_ag_resolve_upload_path()==='/tmp/gallery-absolute','Absolute upload root changed');
$mybb->settings['uploadspath']='uploads';
check(count_rows('af_gallery_albums',"uid_owner=7 AND system_key='appearance_avatars'")===1,'Duplicate purpose');
$mybb->user['uid']=8;$other=ag_get_or_create_system_album(8,'appearance_avatars');check($other!==$id,'UID collision');
$mybb->user['uid']=7;
foreach(array_keys(ag_system_album_purposes()) as $purpose){$aid=ag_get_or_create_system_album(7,$purpose);$a=ag_get_album($aid);check($a['visibility']==='private' && $a['is_default']==='0','Wrong private/default');}
check(ag_get_or_create_system_album(7,'appearance_avatars')===$id,'Repeated call differs');
$db->update_query('af_gallery_albums',['title'=>'Renamed'],"id='{$id}'");check(ag_get_system_album(7,'appearance_avatars')['id']==$id,'Title changed purpose');
$mybb->user['uid']=9;check(ag_get_system_album(7,'appearance_avatars')===null,'Foreign private lookup');check(!ag_can_view_album(ag_get_album($id)),'Foreign system browse');
$mybb->user['usergroup']=4;check(ag_can_view_album(ag_get_album($id)),'Moderator denied');
$mybb->user=['uid'=>7,'usergroup'=>2,'additionalgroups'=>''];
try{ag_enforce_album_limits(7);throw new RuntimeException('Ordinary quota not enforced');}catch(GalleryFixtureError $e){}
$mybb->settings['af_advancedgallery_max_albums']=3;ag_enforce_album_limits(7);
$default=ag_get_default_album_id(7,false);check($default===2,'System adopted as ordinary default');
ag_set_default_album(7,$id);check(ag_get_default_album_id(7,false)===2,'System set as default');
try{ag_get_or_create_system_album(7,'unknown');throw new RuntimeException('Unknown accepted');}catch(InvalidArgumentException $e){}
$mybb->user['usergroup']=5;try{ag_get_or_create_system_album(7,'appearance_sheet_bg');throw new RuntimeException('Upload gate bypassed');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'permission'),'Wrong permission failure');}
$mybb->user['usergroup']=2;
// Existing ordinary media must remain selectable and deletable until explicitly retained.
$db->write_query("INSERT INTO test_af_gallery_media (uid_owner,type,status,created_at,updated_at,title,description,embed_html,mime,storage_path,preview_path,thumb_path,filesize) VALUES (7,'local','approved',1,1,'Existing','', '', 'image/png','uploads/old.png','uploads/old.png','uploads/old_thumb.png',1048576),(8,'local','approved',1,1,'Foreign','', '', 'image/png','uploads/foreign.png','','',10)");
check(ag_get_owned_media(7,1)!==null && ag_get_owned_media(7,2)===null,'Ownership failed');
check(!ag_retain_owned_media(7,2),'Foreign retained');
check(ag_resolve_media_url(1,'bad')==='','Invalid variant');
$mybb->user['uid']=0;check(ag_resolve_media_url(1,'full')==='http://127.0.0.1:8099/uploads/old.png','Public URL denied');
$mybb->user['uid']=7;check(ag_retain_owned_media(7,1),'Existing media cannot be retained');
check(!ag_delete_unretained_media(1),'Retained ID deleted');
$mybb->settings['af_advancedgallery_max_storage_mb']=1;
try{ag_enforce_storage_limit(7,1);throw new RuntimeException('Quota bypassed');}catch(GalleryFixtureError $e){}
$mybb->settings['af_advancedgallery_max_storage_mb']=0;
$workers=[];
for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--quota-race'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
$results=[];foreach($workers as [$proc,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Quota race failed: '.$err);}
sort($results);check($results===['limited','saved']&&count_rows('af_gallery_media',"uid_owner=40")===1,'Concurrent storage quota bypass');
// Leave ordinary media and purposes for HTTP tests; no uploads yet.
echo "Gallery MySQL integration passed: migration twice, legacy preservation, 12 concurrent creators, UID/privacy/permissions, defaults/quota, ownership, URLs, retention.\n";
