<?php
/** Short fixture: real Gallery providers, no forum DB. Optional multipart HTTP + stock MyBB upload. */
define('IN_MYBB',1);
define('MYBB_ROOT',sys_get_temp_dir().'/ag-avatar-regression/');
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
class MyBB {
    const INPUT_INT=1;
    public array $user=['uid'=>7,'usergroup'=>2,'additionalgroups'=>''];
    public array $usergroup=['canusercp'=>1,'canuploadavatars'=>1];
    public array $settings=['af_advancedgallery_enabled'=>1,'bburl'=>'http://localhost','uploadspath'=>'uploads','maxavatardims'=>'100x100',
        'avatarsize'=>100,'avatarresizing'=>'never','avataruploadpath'=>MYBB_ROOT.'avatars','usecdn'=>0,'cdnpath'=>''];
    public string $request_method='post';
    public array $input=[];
    public function get_input($key,$type=0) { $v=$this->input[$key]??'';return $type===1?(int)$v:(string)$v; }
}
class AvatarDB {
    public array $media=[],$queries=[];
    function simple_select($table,$fields,$where,$options=[]) {
        $this->queries[]=$where;
        if (!preg_match("/id='(\d+)'/",$where,$id)) return [];
        $row=$this->media[(int)$id[1]]??[];
        if (str_contains($where,"uid_owner='7'") && ($row['uid_owner']??0)!==7) return [];
        return $row;
    }
    function fetch_array($value){return $value;}
}
class AvatarCache {
    public array $types=[['mimetype'=>'image/gif','avatarfile'=>1,'groups'=>'2','maxsize'=>100],['mimetype'=>'image/png','avatarfile'=>1,'groups'=>'2','maxsize'=>100]];
    function read($key){return $this->types;}
}
function is_member($groups){return str_contains((string)$groups,'2');}
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function verify_post_check($key){if ($key!=='valid')throw new RuntimeException('CSRF');}
function error_no_permission(){throw new RuntimeException('Permission');}
function error($message){throw new RuntimeException($message);}
function avatar_check($condition,$message){if (!$condition)throw new RuntimeException($message);}
function avatar_reject($callback,$message){try{$callback();}catch(RuntimeException $e){return;}throw new RuntimeException($message);}
$mybb=new MyBB();$db=new AvatarDB();$cache=new AvatarCache();
require AF_ADDONS.'advancedgallery/advancedgallery.php';
@mkdir(MYBB_ROOT.'uploads/af_gallery/7',0755,true);@mkdir(MYBB_ROOT.'avatars',0755,true);
$gif=base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$original=MYBB_ROOT.'uploads/af_gallery/7/original.gif';file_put_contents($original,$gif);
$db->media[42]=['id'=>42,'uid_owner'=>7,'status'=>'approved','type'=>'local','mime'=>'image/gif','storage_path'=>'uploads/af_gallery/7/original.gif'];
$mybb->input=$_POST;
if (PHP_SAPI!=='cli') {
    header('Content-Type: application/json');
    try {
        if (!empty($_POST['disabled'])) $mybb->settings['af_advancedgallery_enabled']=0;
        if (!empty($_POST['denied'])) $mybb->usergroup['canuploadavatars']=0;
        if (!empty($_POST['foreign'])) $db->media[42]['uid_owner']=8;
        if (!empty($_POST['mime_denied'])) $cache->types=[];
        if (!empty($_POST['too_small'])) $mybb->settings['avatarsize']=.001;
        ag_avatar_validate_submission();
        // Optional exact upstream upload_avatar(): tests must supply MyBB 1.8.40 source.
        require getenv('MYBB_UPLOAD_SOURCE');
        class AvatarPlugins {function run_hooks($hook,$value=null){return $value;}}
        $plugins=new AvatarPlugins();
        $lang=(object)['error_uploadfailed'=>'Upload failed','error_avatartype'=>'Type denied','error_uploadsize'=>'Size denied'];
        $result=upload_avatar();
        echo json_encode(['result'=>$result,'original_hash'=>hash_file('sha256',$original),
            'avatar_hash'=>isset($result['avatar'])?hash_file('sha256',$result['avatar']):null]);
    } catch (Throwable $e) {http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
    return;
}
$source=ag_avatar_source(42);
avatar_check($source['path']===$original && $source['mime']==='image/gif','Wrong original');
avatar_check(ag_picker_initial_album(7,'account_avatar')===-1,'Picker must initially show all own media');
foreach (['uid_owner'=>8,'status'=>'pending','type'=>'remote','mime'=>'image/webp','storage_path'=>'../outside.gif'] as $field=>$value) {
    $old=$db->media[42][$field];$db->media[42][$field]=$value;
    avatar_reject(fn()=>ag_avatar_source(42),'Unsafe image accepted: '.$field);$db->media[42][$field]=$old;
}
file_put_contents($original,substr_replace($gif,pack('v',101),6,2));
avatar_reject(fn()=>ag_avatar_source(42),'Oversized GIF would lose animation');file_put_contents($original,$gif);
$cache->types[0]['avatarfile']=0;avatar_reject(fn()=>ag_avatar_source(42),'MyBB format restriction ignored');$cache->types[0]['avatarfile']=1;
$mybb->usergroup['canuploadavatars']=0;avatar_reject(fn()=>ag_avatar_source(42),'Avatar permission ignored');$mybb->usergroup['canuploadavatars']=1;
$mybb->settings['af_advancedgallery_enabled']=0;avatar_check(!ag_avatar_available(),'Disabled Gallery available');$mybb->settings['af_advancedgallery_enabled']=1;
$mybb->input=['ag_avatar_confirm'=>'1','ag_avatar_media_id'=>42,'my_post_key'=>'invalid'];avatar_reject('ag_avatar_validate_submission','CSRF ignored');
$mybb->input['my_post_key']='valid';$_FILES=['avatarupload'=>['tmp_name'=>$original,'error'=>0]];avatar_reject('ag_avatar_validate_submission','Synthetic server file accepted as upload');
// Standard avatar submit is untouched even when an unrelated stale media ID exists.
$mybb->input=['ag_avatar_media_id'=>999,'my_post_key'=>'invalid'];ag_avatar_validate_submission();
class AvatarTemplates {
    public array $cache=['usercp_avatar'=>'<form><input name="avatarupload" type="file"></form>'];
    function get($name,$escape=1,$comments=1){return $this->cache[$name];}
}
$templates=new AvatarTemplates();ag_avatar_usercp_block();$once=$templates->cache['usercp_avatar'];ag_avatar_usercp_block();
avatar_check($once===$templates->cache['usercp_avatar'] && substr_count($once,'<form')===1 && str_contains($once,'data-ag-avatar-picker'),'Form injection/duplicate failure');
$mybb->settings['af_advancedgallery_enabled']=0;$templates=new AvatarTemplates();ag_avatar_usercp_block();avatar_check(!str_contains($templates->cache['usercp_avatar'],'data-ag-avatar-picker'),'Disabled Gallery changes UserCP');
avatar_check(hash_file('sha256',$original)===hash('sha256',$gif),'Gallery original changed');
echo "Gallery avatar passed: ownership, approved local original, MIME/group permissions, CSRF, native upload boundary, standard submit, single form, disabled fallback, all-own initial view.\n";
// Stubs used only by the optional real MyBB HTTP upload fixture.
function get_extension($name){return pathinfo($name,PATHINFO_EXTENSION);}
function my_strtolower($s){return strtolower($s);}
function copy_file_to_cdn($path,&$cdn=''){return false;}
function my_chmod($path,$mode){return true;}
