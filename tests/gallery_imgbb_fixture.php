<?php
/** Isolated gallery_test DB only. Fake provider transport; never calls a live ImgBB account. */
define('AG_TEST_BOOTSTRAP_ONLY', true);
require __DIR__.'/gallery_media_api_integration.php';
require_once AF_ADDONS.'advancedgallery/providers/admin.php';
function af_rebuild_and_reload_settings() {
    global $db,$mybb;
    $q=$db->simple_select('settings');while($r=$db->fetch_array($q)) $mybb->settings[$r['name']]=$r['value'];
}
function af_apf_is_enabled() {return true;}
function af_apf_validate_secondary_avatar_upload() {}
function is_member($groups) {global $mybb;return ag_user_in_groups(array_map('intval',explode(',',(string)$groups)));}
class ImgBBTestCache {
    function read($key) {return $key==='attachtypes' ? [['mimetype'=>'image/png','avatarfile'=>1,'groups'=>'2,4','maxsize'=>100],['mimetype'=>'image/gif','avatarfile'=>1,'groups'=>'2,4','maxsize'=>100]] : [];}
}
$cache=new ImgBBTestCache();
const IMGBB_TEST_KEY='fixture_only_not_a_real_imgbb_key';
class ImgBBTestProvider extends AG_ImgBBProvider {
    protected function request(array $source): array {
        $fields=$this->uploadFields($source);
        check(array_keys($fields)===['key','image'] && $fields['key']===IMGBB_TEST_KEY && $fields['image'] instanceof CURLFile,'Multipart key/image contract; expiration must be absent');
        file_put_contents('/tmp/ag-imgbb-requests.log',json_encode(['bytes'=>$source['bytes'],'mime'=>$source['mime']])."\n",FILE_APPEND);
        if (isset($_GET['api_failure'])) return ['code'=>503,'body'=>json_encode(['success'=>false,'error'=>['message'=>IMGBB_TEST_KEY]])];
        $ext=$source['ext'];
        return ['code'=>200,'body'=>json_encode(['success'=>true,'status'=>200,'data'=>[
            'id'=>'fixture_'.bin2hex(random_bytes(4)),'url'=>'https://i.ibb.co/fixture/original.'.$ext,
            'url_viewer'=>'https://ibb.co/fixture','width'=>$source['width'],'height'=>$source['height'],'size'=>$source['bytes'],
            'image'=>['mime'=>$source['mime'],'extension'=>$ext],
            'thumb'=>['url'=>'https://i.ibb.co/fixture/thumb.'.$ext],
            'delete_url'=>'https://ibb.co/fixture/PRIVATE_DELETE_TOKEN',
        ]])];
    }
    public function downloadOriginal(array $media): string {
        check($this->validOriginalUrl($media['remote_url']),'Invalid provider source');
        $path=ag_external_temp_file();
        file_put_contents($path,base64_decode($media['mime']==='image/gif'?'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7':'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII='));
        return $path;
    }
}
ag_register_storage_provider(new ImgBBTestProvider());
if (PHP_SAPI==='cli') {
    if (($argv[1]??'')==='--init') {
        foreach (['af_gallery_external_media','af_gallery_provider_secrets','af_gallery_album_media','af_gallery_albums','af_gallery_media','af_gallery_logs','templates','settings','settinggroups'] as $table) $db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
        $db->write_query('CREATE TABLE test_templates (tid INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(191),template MEDIUMTEXT,sid INT,version VARCHAR(20),dateline INT) ENGINE=InnoDB');
        $db->write_query('CREATE TABLE test_settinggroups (gid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,disporder INT,isdefault INT) ENGINE=InnoDB');
        $db->write_query('CREATE TABLE test_settings (sid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,optionscode TEXT,value TEXT,disporder INT,gid INT) ENGINE=InnoDB');
        af_advancedgallery_install();
        foreach (['enabled'=>'1','can_upload_groups'=>'2,4','autoapprove_groups'=>'2,4','max_storage_mb'=>'1','max_albums'=>'1','imgbb_enabled'=>'1','imgbb_max_mb'=>'1','imgbb_groups'=>'2,4','imgbb_allow_url'=>'1','imgbb_default'=>'1'] as $key=>$value) $db->update_query('settings',['value'=>$value],"name='af_advancedgallery_{$key}'");
        $db->insert_query('af_gallery_provider_secrets',['provider'=>'imgbb','api_key'=>IMGBB_TEST_KEY]);
        $db->write_query("INSERT INTO test_af_gallery_media (uid_owner,type,status,filesize,created_at,updated_at,description,embed_html) VALUES (7,'local','approved',100,1,1,'unchanged','')");
        $original=$db->fetch_array($db->simple_select('af_gallery_media','*','id=1'));
        af_advancedgallery_deactivate();af_advancedgallery_activate();ag_ensure_provider_schema();
        check($original===$db->fetch_array($db->simple_select('af_gallery_media','*','id=1')),'Migration changed local media');
        check(ag_provider_secret('imgbb')===IMGBB_TEST_KEY && $mybb->settings['af_advancedgallery_imgbb_enabled']==='1','Reactivation reset secret/settings');
        check(!str_contains(json_encode($mybb->settings),IMGBB_TEST_KEY),'Secret copied into settings');
        check(!str_contains(ag_provider_upload_controls(),IMGBB_TEST_KEY) && !str_contains(ag_provider_admin_widget(),IMGBB_TEST_KEY),'API key in HTML');
        $mybb->user['uid']=8;ag_get_default_album_id(8,true);$mybb->user['uid']=7;
        ag_get_default_album_id(7,true);
        file_put_contents('/tmp/ag-imgbb-requests.log','');
        echo "ImgBB fixture initialized; additive migration, private key and reactivation passed.\n";
    } else {
        af_rebuild_and_reload_settings();
        check(ag_storage_usage(7)['used_bytes']===100,'External uploads changed local quota');
        $q=$db->write_query("SELECT m.storage_path,e.delete_url FROM test_af_gallery_media m INNER JOIN test_af_gallery_external_media e ON e.media_id=m.id");
        while($r=$db->fetch_array($q)) check($r['storage_path']==='' && str_contains($r['delete_url'],'PRIVATE_DELETE_TOKEN'),'External metadata/original storage');
        check(!glob('/tmp/ag-external-*'),'Temporary originals leaked after HTTP request');
        check(count_rows('af_gallery_media',"type='local'")===1,'Persistent local originals created');
        check((int)$db->fetch_field($db->write_query('SELECT COUNT(*) AS n FROM test_af_gallery_external_media e LEFT JOIN test_af_gallery_media m ON m.id=e.media_id WHERE m.id IS NULL'),'n')===0,'Orphan external metadata after deletion');
        check(!str_contains(ag_provider_admin_widget(),'PRIVATE_DELETE_TOKEN') && !str_contains(ag_provider_admin_widget(),IMGBB_TEST_KEY),'Private URL/key in admin HTML');
        $_POST=['imgbb_enabled'=>'1','imgbb_max_mb'=>'1','imgbb_groups'=>'2,4','imgbb_allow_url'=>'1','imgbb_default'=>'1','imgbb_api_key'=>''];
        ag_provider_admin_save();check(ag_provider_secret('imgbb')===IMGBB_TEST_KEY,'Blank ACP field erased key');
        $_POST['imgbb_api_key']='fixture_alternative_private_key';ag_provider_admin_save();
        check(ag_provider_secret('imgbb')==='fixture_alternative_private_key' && !str_contains(ag_provider_admin_widget(),'fixture_alternative_private_key'),'ACP replacement key exposed');
        $_POST['imgbb_remove_key']='1';ag_provider_admin_save();check(ag_provider_secret('imgbb')==='','Explicit key removal failed');
        unset($_POST['imgbb_remove_key']);$_POST['imgbb_api_key']=IMGBB_TEST_KEY;ag_provider_admin_save();$_POST=[];
        af_advancedgallery_activate();check(ag_provider_secret('imgbb')===IMGBB_TEST_KEY,'Reactivation reset API key');
        check((int)$mybb->settings['af_advancedgallery_imgbb_max_mb']===1,'Reactivation reset ImgBB size');
        echo "ImgBB persistence passed: external metadata, unchanged local quota, no leaked temp files/secrets, repeated activation.\n";
    }
    exit;
}
af_rebuild_and_reload_settings();
$mybb->user['uid']=(int)($_GET['fixture_uid']??7);$mybb->user['usergroup']=(int)($_GET['fixture_group']??2);
$mybb->usergroup=['canusercp'=>1,'canuploadavatars'=>1];$mybb->request_method=strtolower($_SERVER['REQUEST_METHOD']);
$mybb->settings['bburl']='http://127.0.0.1:8101';
if (isset($_GET['pending'])) $mybb->settings['af_advancedgallery_autoapprove_groups']='4';
try {
    switch ($_GET['action']??'page') {
        case 'page': header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><meta charset="utf-8">'.ag_storage_widget().ag_render_upload().'<script src="gallery.php?action=js"></script>';break;
        case 'js':header('Content-Type: application/javascript');readfile(AF_ADDONS.'advancedgallery/assets/advancedgallery.js');break;
        case 'upload_do':ag_handle_upload();break;
        case 'picker':ag_render_picker_modal();break;
        case 'picker_data':ag_handle_picker_data();break;
        case 'picker_select':ag_handle_picker_select();break;
        case 'avatar_original':
            register_shutdown_function(static function(){if(empty($GLOBALS['af_disable_pre_output'])) echo 'Unexpected AF HTML shutdown output';});
            ag_handle_avatar_original();break;
        case 'validate_avatar':
            $purpose=(string)$mybb->get_input('purpose');if(!in_array($purpose,['account_avatar','character_avatar'],true))error_no_permission();
            ag_avatar_validate_context($purpose);ag_json_response(['ok'=>true]);break;
        case 'delete':ag_handle_delete();break;
        case 'move':ag_handle_album_add_media();break;
        case 'inspect':
            $media=ag_get_owned_media((int)$mybb->user['uid'],(int)($_GET['id']??0));
            if (!$media) error_no_permission();
            ag_json_response(['media'=>$media,'direct'=>ag_media_is_direct_image($media),'bbcode'=>ag_media_bbcode($media,$media['remote_url']),'storage'=>ag_storage_usage((int)$mybb->user['uid'])]);break;
        case 'fixture_album':ag_json_response(['id'=>ag_get_default_album_id((int)$mybb->user['uid'],true)]);break;
        case 'view_check': $media=ag_get_owned_media((int)($_GET['owner']??7),(int)($_GET['id']??0));if(!$media || !ag_can_browse_media($media))error_no_permission();ag_json_response(['ok'=>true]);break;
    }
} catch (GalleryFixtureRedirect $e) {header('Content-Type: application/json');echo json_encode(['redirect'=>$e->getMessage()]);}
catch (GalleryFixtureError $e) {http_response_code($e->getCode()?:422);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
