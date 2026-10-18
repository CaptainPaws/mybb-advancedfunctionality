<?php
/** Run only through: php tests/gallery_media_api_integration.php --storage
 * Uses that fixture's isolated gallery_test database, never a forum database.
 */
if (!isset($db, $mybb) || PHP_SAPI !== 'cli') exit(1);
function rebuild_settings() { $GLOBALS['storage_rebuilds'] = ($GLOBALS['storage_rebuilds'] ?? 0)+1; }
function af_rebuild_and_reload_settings() {
    global $db, $mybb;
    rebuild_settings();
    $q=$db->simple_select('settings');
    while($row=$db->fetch_array($q)) $mybb->settings[$row['name']]=$row['value'];
}
function build_profile_link($name,$uid) {return $name;}
function is_moderator($fid=0,$action='',$uid=0) {return !empty($GLOBALS['storage_forum_mod']);}
class StorageTestCache {
    function read($key) {return [2=>[],4=>['cancp'=>1],6=>['issupermod'=>1]];}
}
$cache=new StorageTestCache();
foreach(['af_gallery_external_media','af_gallery_provider_secrets','af_gallery_album_media','af_gallery_albums','af_gallery_media','af_gallery_logs','templates','settings','settinggroups'] as $table) $db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
$db->write_query('CREATE TABLE test_templates (tid INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(191),template MEDIUMTEXT,sid INT,version VARCHAR(20),dateline INT) ENGINE=InnoDB');
$db->write_query('CREATE TABLE test_settinggroups (gid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,disporder INT,isdefault INT) ENGINE=InnoDB');
$db->write_query('CREATE TABLE test_settings (sid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),title VARCHAR(191),description TEXT,optionscode TEXT,value TEXT,disporder INT,gid INT) ENGINE=InnoDB');
af_advancedgallery_install();
check((int)$mybb->settings['af_advancedgallery_storage_exempt_staff']===1,'Staff exclusion default');
$custom=['upload_max_mb'=>'3','max_storage_mb'=>'2','can_upload_groups'=>'2,4,8','can_moderate_groups'=>'8','autoapprove_groups'=>'2,4','allowed_ext'=>'png,gif','af_gallery_assets_blacklist'=>'custom.php'];
foreach($custom as $key=>$value) {
    $name=str_starts_with($key,'af_')?$key:'af_advancedgallery_'.$key;
    $db->update_query('settings',['value'=>$db->escape_string($value)],"name='{$name}'");
}
$db->write_query("INSERT INTO test_af_gallery_media (uid_owner,type,status,filesize,created_at,updated_at,description,embed_html) VALUES (7,'local','approved',524288,1,1,'',''),(7,'local','pending',262144,1,1,'',''),(7,'local','rejected',262144,1,1,'',''),(7,'remote','approved',999999999,1,1,'',''),(9,'local','approved',2097152,1,1,'','')");
$albumBefore=ag_get_default_album_id(7,true);
$db->write_query("INSERT INTO test_af_gallery_album_media (album_id,media_id,sort_order,created_at) VALUES ({$albumBefore},2,13,1)");
$snapshot=static function() use ($db) {
    $data=[];
    foreach(['af_gallery_media','af_gallery_albums','af_gallery_album_media'] as $table) {
        $q=$db->simple_select($table);$data[$table]=[];
        while($row=$db->fetch_array($q)) $data[$table][]=$row;
    }
    return $data;
};
$originalData=$snapshot();
$before=$db->fetch_array($db->simple_select('af_gallery_media','COUNT(*) AS n,SUM(filesize) AS bytes'));
$db->delete_query('settings',"name='af_advancedgallery_thumb_h'");
check(af_advancedgallery_deactivate(),'Deactivate failed');
check($before===$db->fetch_array($db->simple_select('af_gallery_media','COUNT(*) AS n,SUM(filesize) AS bytes')),'Deactivate changed media');
for($i=0;$i<2;$i++) check(af_advancedgallery_activate(),'Activation failed');
check($snapshot()===$originalData,'Lifecycle changed albums, media metadata or memberships');
foreach($custom as $key=>$value) {
    $name=str_starts_with($key,'af_')?$key:'af_advancedgallery_'.$key;
    check((string)$mybb->settings[$name]===$value,'Setting reset or runtime stale: '.$name);
}
$mybb->request_method='post';
$_POST=['my_post_key'=>'fixture-key'];
$_FILES=['ag_file'=>['error'=>0,'size'=>3*1048576+1]];
foreach([2,4] as $group) {
    $mybb->user['usergroup']=$group;
    try {ag_handle_upload();throw new RuntimeException('Single file limit bypassed');}catch(GalleryFixtureError $e) {check(str_contains($e->getMessage(),'3 МБ'),'Wrong single file limit');}
}
$_POST=[];$_FILES=[];$mybb->user['usergroup']=2;$mybb->request_method='get';
check((int)$mybb->settings['af_advancedgallery_thumb_h']===320,'Missing setting not restored');
check($before===$db->fetch_array($db->simple_select('af_gallery_media','COUNT(*) AS n,SUM(filesize) AS bytes')),'Reactivate changed media');
check(count_rows('settings',"name='af_advancedgallery_max_storage_mb'")===1,'Duplicate quota setting');
$usage=ag_storage_usage(7);
check($usage['used_bytes']===1048576 && $usage['limit_bytes']==2097152 && $usage['percent']==50,'Local/status accounting or quota wrong');
check(str_contains(ag_storage_widget(),'Доступно: 1.00 МБ'),'User widget wrong');
ag_enforce_storage_limit(7,1048576);
try {ag_enforce_storage_limit(7,1048577);throw new RuntimeException('Quota bypass');}catch(GalleryFixtureError $e){}
// Client-controlled flags do not establish staff privileges.
$_POST['is_admin']=1;$_POST['is_moderator']=1;
check(!ag_storage_exempt(7),'Client staff flag trusted');$_POST=[];
foreach([['usergroup'=>4,'additionalgroups'=>''],['usergroup'=>2,'additionalgroups'=>'4'],['usergroup'=>6,'additionalgroups'=>''],['usergroup'=>8,'additionalgroups'=>'']] as $groups) {
    $mybb->user=array_replace($mybb->user,$groups);check(ag_storage_exempt(7),'Staff group not exempt');
    ag_enforce_storage_limit(7,999999999);
    check(ag_storage_usage(7)['limit_bytes']===0,'Exempt widget still limited');
}
$mybb->user=['uid'=>7,'usergroup'=>2,'additionalgroups'=>''];
$GLOBALS['storage_forum_mod']=true;check(ag_storage_exempt(7),'Forum moderator not exempt');
$mybb->settings['af_advancedgallery_storage_exempt_staff']=0;check(!ag_storage_exempt(7),'Exemption toggle ignored');
$GLOBALS['storage_forum_mod']=false;$mybb->settings['af_advancedgallery_storage_exempt_staff']=1;
$mybb->settings['af_advancedgallery_max_storage_mb']=1;
check(ag_storage_usage(7)['full'] && ag_storage_usage(7)['percent']===100,'Full indicator');
$db->update_query('af_gallery_media',['filesize'=>2097152],"id='1'");
check(ag_storage_usage(7)['percent']===100 && ag_storage_usage(7)['available_bytes']==0,'Over-quota clamp');
$mybb->settings['af_advancedgallery_max_storage_mb']=0;check(ag_storage_usage(7)['limit_bytes']===0,'Unlimited quota');
$aid=ag_get_or_create_system_album(7,'appearance_avatars');
$ordinary=ag_get_default_album_id(7,true);
foreach([$aid,$ordinary] as $album) $db->write_query("INSERT INTO test_af_gallery_album_media (album_id,media_id,sort_order,created_at) VALUES ({$album},1,0,1)");
$stats=ag_storage_admin_stats();
check((int)$stats['local_files']===4 && (int)$stats['users']===2 && (int)$stats['remote_files']===1,'ACP counts');
check((int)$stats['bytes']===4718592 && (int)$stats['system']['files']===1 && (int)$stats['system']['bytes']===2097152,'ACP duplicate/system bytes');
$mybb->settings['uploadspath']='/tmp/ag-storage-network-fixture';
check(!isset(ag_storage_disk()['total']),'Missing storage path guessed parent capacity');
check(!str_contains(ag_storage_admin_widget(),'<progress'),'Unknown capacity displayed invented percentage');
if (!is_dir('/tmp/ag-storage-network-fixture/af_gallery')) mkdir('/tmp/ag-storage-network-fixture/af_gallery',0755,true);
check(ag_storage_disk()['path']==='/tmp/ag-storage-network-fixture/af_gallery','Configured absolute storage path ignored');
check(!isset(ag_storage_disk()['total']),'Unmounted network directory fell back to root volume');
$mybb->settings['uploadspath']='uploads';
// Two real worker connections contend for one owner's final 1 MB.
$workers=[];
for($i=0;$i<2;$i++) {$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/gallery_media_api_integration.php','--quota-race'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
$results=[];
foreach($workers as [$proc,$pipes]) {$results[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);check(proc_close($proc)===0,'Quota worker failed: '.$err);}
sort($results);check($results===['limited','saved'],'Concurrent uploads exceeded quota');
check(count_rows('af_gallery_media','uid_owner=40')===1,'Concurrent insert leaked');
check(ag_delete_unretained_media(1),'Delete failed');
check(ag_storage_usage(7)['used_bytes']===524288,'Delete did not release quota');
require_once AF_ADDONS.'advancedgallery/admin.php';
$admin=AF_Admin_Advancedgallery::render();check(str_contains($admin,'Внешних изображений'),'ACP widget missing');
if ($output=getenv('AG_STORAGE_WIDGET_OUTPUT')) {
    $mybb->settings['af_advancedgallery_max_storage_mb']=1;
    file_put_contents($output,ag_storage_widget());
}
af_advancedgallery_uninstall();
check(!$db->table_exists('af_gallery_media'),'Explicit uninstall left media table');
check(count_rows('settings')===0,'Explicit uninstall left settings');
echo "Storage integration passed: local-only/status accounting, server staff permissions, quota boundaries, two-connection race, deletion, ACP deduplication, actual storage path, repeated install/activate/deactivate and runtime settings preservation.\n";
