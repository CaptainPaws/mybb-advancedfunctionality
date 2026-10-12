<?php
/** Destructive isolated fixture: only database appearance_test, aa_test_ tables. Never use production. */
define('IN_MYBB',1);define('TABLE_PREFIX','aa_test_');define('TIME_NOW',time());define('MYBB_ROOT','/tmp/appearance-fixture/');
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
class AppearanceFixtureMyBB {
    const INPUT_INT=1, INPUT_ARRAY=2;
    public array $user=['uid'=>7,'usergroup'=>2,'additionalgroups'=>''];
    public array $usergroup=['canview'=>1,'isbannedgroup'=>0,'cancp'=>0];
    public string $request_method='get', $post_code='appearance-fixture-key';
    public array $settings=['af_advancedappearance_enabled'=>1,'af_advancedgallery_enabled'=>1,
        'af_advancedgallery_can_upload_groups'=>'2','af_advancedgallery_can_moderate_groups'=>'4',
        'af_advancedappearance_free_slots_postbit'=>1,'af_advancedappearance_max_slots_postbit'=>20,
        'af_advancedappearance_personal_types'=>'postbit','bburl'=>'http://127.0.0.1:8100'];
    public function get_input($key,$type=0){$v=$_POST[$key]??$_GET[$key]??($type===2?[]:'');return $type===1?(int)$v:($type===2?(array)$v:(string)$v);}
}
class_alias(AppearanceFixtureMyBB::class,'MyBB');
class AppearanceFixtureDB {
    public mysqli $connection;
    public function __construct(){mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$this->connection=new mysqli(getenv('AA_TEST_DB_HOST')?:'af-appearance-test-mysql','root','','appearance_test');$this->connection->set_charset('utf8mb4');}
    public function write_query($sql){return $this->connection->query($sql);}
    public function query($sql){return $this->write_query($sql);}
    public function escape_string($v){return $this->connection->real_escape_string((string)$v);}
    public function simple_select($table,$fields='*',$where='',$o=[]){$sql='SELECT '.$fields.' FROM '.TABLE_PREFIX.$table.($where!==''?' WHERE '.$where:'');if(isset($o['order_by']))$sql.=' ORDER BY '.$o['order_by'].' '.($o['order_dir']??'ASC');if(isset($o['limit']))$sql.=' LIMIT '.(int)($o['limit_start']??0).','.(int)$o['limit'];return $this->write_query($sql);}
    public function fetch_array($q){return $q->fetch_assoc()?:false;}
    public function fetch_field($q,$key){$row=$this->fetch_array($q);return $row[$key]??null;}
    public function insert_id(){return (int)$this->connection->insert_id;}
    public function insert_query($table,$row){$this->write_query('INSERT INTO '.TABLE_PREFIX.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_map(fn($v)=>"'{$v}'",array_values($row))).')');return $this->insert_id();}
    public function update_query($table,$row,$where){$parts=[];foreach($row as $key=>$v)$parts[]=$key."='{$v}'";return $this->write_query('UPDATE '.TABLE_PREFIX.$table.' SET '.implode(',',$parts).' WHERE '.$where);}
    public function delete_query($table,$where){return $this->write_query('DELETE FROM '.TABLE_PREFIX.$table.' WHERE '.$where);}
    public function table_exists($table){return (bool)$this->fetch_array($this->write_query("SHOW TABLES LIKE '".TABLE_PREFIX.$table."'"));}
    public function field_exists($field,$table){return (bool)$this->fetch_array($this->write_query('SHOW COLUMNS FROM '.TABLE_PREFIX.$table." LIKE '{$field}'"));}
    public function build_create_table_collation(){return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';}
}
function af_ensure_settinggroup($name,$title,$description){return 1;}
function af_ensure_setting($group,$name,$title,$desc,$type,$value,$order){global $db;$row=['name'=>$db->escape_string($name),'value'=>$db->escape_string($value)];$id=$db->fetch_field($db->simple_select('settings','sid',"name='{$name}'"),'sid');if($id)$db->update_query('settings',$row,"sid={$id}");else $db->insert_query('settings',$row);}
function error_no_permission(){throw new AF_AA_PersonalException('forbidden',403);}
function error($message){throw new AF_AA_PersonalException($message,422);}
function verify_post_check($key,$silent=false){if($key!=='appearance-fixture-key')throw new AF_AA_PersonalException('csrf',403);return true;}
function get_user($uid){return ['uid'=>$uid,'username'=>'Fixture '.$uid];}
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$plugins=new class {public function add_hook(...$args){}};
$db=new AppearanceFixtureDB();$mybb=new AppearanceFixtureMyBB();$lang=new stdClass();
require AF_ADDONS.'advancedgallery/advancedgallery.php';require AF_ADDONS.'advancedappearance/advancedappearance.php';
function appearance_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function appearance_count($table,$where=''){global $db;return (int)$db->fetch_field($db->simple_select($table,'COUNT(*) AS n',$where),'n');}
function appearance_fails(callable $fn,int $code){try{$fn();}catch(AF_AA_PersonalException $e){appearance_check($e->getCode()===$code,'Unexpected refusal '.$e->getMessage());return;}throw new RuntimeException('Expected refusal '.$code);}

if(PHP_SAPI==='cli-server') {
    $mybb->request_method=strtolower($_SERVER['REQUEST_METHOD']);
    $mybb->user['uid']=(int)($_GET['fixture_uid']??7);
    if(isset($_GET['free_slots']))$mybb->settings['af_advancedappearance_free_slots_postbit']=(int)$_GET['free_slots'];
    if(($_GET['fixture_action']??'')==='render') {
        $uid=(int)($_GET['owner']??7);$base=file_get_contents(AF_ADDONS.'adaptivethemeframework/assets/surfaces/postbit.css');$html='<!doctype html><html><head><style>'.$base.'</style>'.af_aa_render_page_css([$uid]).'</head><body class="atf-active">';
        foreach([101,102] as $pid)$html.='<article class="atf-post af-aa-postbit-user-'.$uid.'" id="post_'.$pid.'" data-element="fire"><div class="atf-post__layout"><aside class="atf-post__sidebar"><div class="atf-post__sidebar-inner">Author</div></aside><section class="atf-post__content"><div class="atf-post__message-body">Fixture text</div></section></div></article>';
        echo $html.'</body></html>';exit;
    }
    try {af_aa_render_apstudio_page();}catch(AF_AA_PersonalException $e){http_response_code($e->getCode());echo json_encode(['error'=>$e->getMessage()]);}exit;
}
if(($argv[1]??'')==='--race') {
    $mybb->user['uid']=50;
    try{echo 'created:'.af_aa_personal_create(50,'postbit','Concurrent','{}');}catch(AF_AA_PersonalException $e){echo 'refused:'.$e->getCode();}exit;
}
if(($argv[1]??'')==='--after-http') {
    $mybb->user['uid']=60;appearance_check(appearance_count('af_aa_presets','owner_uid=60')===1,'HTTP exceeded slots');
    $mybb->user['uid']=7;appearance_check(appearance_count('af_aa_presets','owner_uid=7')===1,'HTTP own lifecycle failed');
    $slot=af_aa_personal_slots(7,'postbit');appearance_check($slot['used']===1&&$slot['available']===0,'HTTP slot accounting');
    echo "Appearance HTTP persistence and quota checks passed.\n";exit;
}
foreach(['af_aa_slot_grants','af_aa_assignments','af_aa_presets','af_aa_active','af_gallery_media','settings'] as $table)$db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
$db->write_query('CREATE TABLE '.TABLE_PREFIX."settings (sid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),value TEXT) ENGINE=InnoDB");
af_aa_ensure_schema();
// Seed actual legacy shop definitions/assignments before migrating. Include trusted legacy CSS.
$db->write_query('INSERT INTO '.AF_AA_PRESETS_TABLE." (slug,title,description,target_key,settings_json,created_at,updated_at) VALUES
    ('legacy','Shop preset','Keep me','apui_postbit_pack','{\"postbit_author_bg_url\":\"https://example.test/legacy.png\",\"custom_css\":\"{{selector}} .legacy{color:red;}\"}',1,1)");
$db->write_query('INSERT INTO '.AF_AA_ASSIGNMENTS_TABLE." (entity_type,entity_id,target_key,preset_id) VALUES ('user',7,'apui_postbit_pack',1)");
$before=$db->fetch_array($db->simple_select(AF_AA_PRESETS_TABLE_NAME,'*','id=1'));
$assignmentBefore=$db->fetch_array($db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME,'*','entity_id=7'));
$db->write_query('ALTER TABLE '.AF_AA_PRESETS_TABLE.' ENGINE=MyISAM');
$db->write_query('ALTER TABLE '.AF_AA_ASSIGNMENTS_TABLE.' ENGINE=MyISAM');
af_aa_personal_install_schema();af_aa_personal_install_schema();
$after=$db->fetch_array($db->simple_select(AF_AA_PRESETS_TABLE_NAME,'*','id=1'));$extra=$after;
foreach(['owner_uid','schema_version','draft_json','publication_state'] as $key)unset($after[$key]);
appearance_check($before===$after,'Migration changed legacy preset');
appearance_check($assignmentBefore===$db->fetch_array($db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME,'*','entity_id=7')),'Migration changed active shop preset');
appearance_check((int)$extra['owner_uid']===0&&(int)$extra['schema_version']===1,'Wrong legacy ownership');
foreach([AF_AA_PRESETS_TABLE_NAME,AF_AA_ASSIGNMENTS_TABLE_NAME,'af_aa_slot_grants'] as $table){$r=$db->fetch_array($db->write_query("SHOW TABLE STATUS WHERE Name='".TABLE_PREFIX.$table."'"));appearance_check($r['Engine']==='InnoDB','Nontransactional table');}
af_aa_ensure_settings();af_aa_personal_ensure_settings();
$db->update_query('settings',['value'=>5],"name='af_advancedappearance_free_slots_postbit'");
af_aa_personal_ensure_settings();appearance_check((int)$db->fetch_field($db->simple_select('settings','value',"name='af_advancedappearance_free_slots_postbit'"),'value')===5,'ACP value reset');
$db->update_query('settings',['value'=>0],"name='af_advancedappearance_enabled'");af_aa_ensure_settings();appearance_check((int)$db->fetch_field($db->simple_select('settings','value',"name='af_advancedappearance_enabled'"),'value')===0,'Enabled setting reset');
$slots=af_aa_personal_slots(7,'postbit');appearance_check($slots['capacity']===1&&$slots['total_with_system']===2&&$slots['used']===0,'Initial free/system slots');
appearance_check(af_aa_personal_system_preset('postbit')['id']===0&&appearance_count(AF_AA_PRESETS_TABLE_NAME)===1,'System row created');
$legacy=af_aa_get_user_preset_settings_for_target(7,AF_AA_TARGET_APUI_POSTBIT_PACK,af_aa_get_apui_defaults());
appearance_check($legacy['settings']['postbit_author_bg_url']==='https://example.test/legacy.png','Legacy runtime lost');
$mine=af_aa_personal_create(7,'postbit','Личный стиль','{"text_color":"#112233","font_family":"serif","effect_preset":"soft_glow"}');
appearance_fails(fn()=>af_aa_personal_create(7,'postbit','Over quota'),409);
appearance_fails(fn()=>af_aa_personal_clone(7,$mine,'No capacity'),409);
af_aa_personal_rename(7,$mine,'Renamed');appearance_check(af_aa_personal_owned_row(7,$mine)['title']==='Renamed','Rename failed');
af_aa_personal_activate(7,'postbit',$mine);
appearance_check(af_aa_personal_active_row(7)['id']===$mine,'Active assignment not changed');
$css=af_aa_render_page_css([7]);appearance_check(str_contains($css,'color:#112233')&&str_contains($css,'Georgia, serif'),'Published settings not applied');
appearance_check(!str_contains($css,'legacy.png'),'Legacy main assignment leaked into personal style');
af_aa_personal_save(7,$mine,'{"text_color":"#445566"}',true);
appearance_check(str_contains(af_aa_render_page_css([7]),'color:#112233')&&!str_contains(af_aa_render_page_css([7]),'color:#445566'),'Draft changed live style');
af_aa_personal_save(7,$mine,'{"text_color":"#445566"}');appearance_check(str_contains(af_aa_render_page_css([7]),'color:#445566'),'Publish did not invalidate cache');
appearance_check(af_aa_personal_owned_row(7,$mine)['draft_json']===null,'Draft not cleared');
$mybb->settings['af_advancedappearance_free_slots_postbit']=2;$clone=af_aa_personal_clone(7,$mine,'Clone');appearance_check($clone!==$mine,'Clone failed');
af_aa_personal_delete(7,$clone);appearance_check(af_aa_personal_slots(7,'postbit')['available']===1,'Delete did not free slot');
$mybb->settings['af_advancedappearance_free_slots_postbit']=1;
$mybb->user['uid']=8;appearance_fails(fn()=>af_aa_personal_save(8,$mine,'{}'),404);appearance_fails(fn()=>af_aa_personal_create(7,'postbit','Foreign actor'),403);
$bad=['{','[]','null','{"custom_css":"body{}"}','{"font_family":"Arial;display:none"}','{"effect_preset":"firestorm"}','{"overlay_opacity":2}','{"overlay_opacity":true}','{"text_color":"red"}','{"author_bg_media_id":"1"}','{"grid_columns":2}'];
foreach($bad as $json)appearance_fails(fn()=>af_aa_personal_create(8,'postbit','Bad',$json),422);
appearance_fails(fn()=>af_aa_personal_create(8,'profile','Reserved'),422);
$mybb->settings['af_advancedappearance_personal_title_limit']=3;appearance_fails(fn()=>af_aa_personal_create(8,'postbit','Длинное'),422);
$mybb->settings['af_advancedappearance_personal_title_limit']=80;
$mybb->settings['af_advancedappearance_personal_json_limit']=4;appearance_fails(fn()=>af_aa_personal_create(8,'postbit','Too large','{"text_color":"#112233"}'),422);
$mybb->settings['af_advancedappearance_personal_json_limit']=4096;
$mybb->user['uid']=7;appearance_fails(fn()=>af_aa_personal_delete(7,0),422);appearance_fails(fn()=>af_aa_personal_save(7,1,'{}'),404);
// Gallery API is used as shipped at stage 2; no Appearance Gallery SQL in production code.
$db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_media (id INT PRIMARY KEY,uid_owner INT,type VARCHAR(16),status VARCHAR(16),mime VARCHAR(80),storage_path VARCHAR(255),preview_path VARCHAR(255),thumb_path VARCHAR(255),appearance_retained TINYINT DEFAULT 0) ENGINE=InnoDB");
$db->write_query('INSERT INTO '.TABLE_PREFIX."af_gallery_media VALUES (1,7,'local','approved','image/png','uploads/mine.png','','',0),(2,8,'local','approved','image/png','uploads/foreign.png','','',0),(3,7,'local','pending','image/png','uploads/pending.png','','',0)");
// The Gallery stage-2 schema readiness cache normally comes from install; provider ownership/retention stay real.
$GLOBALS['ag_system_album_schema_ready']=true;
appearance_fails(fn()=>af_aa_personal_save(7,$mine,'{"author_bg_media_id":2}'),422);
af_aa_personal_save(7,$mine,'{"author_bg_media_id":3}',true);appearance_fails(fn()=>af_aa_personal_save(7,$mine,'{"author_bg_media_id":3}'),422);
$mybb->settings['af_advancedappearance_personal_media_limit']=1;
appearance_fails(fn()=>af_aa_personal_save(7,$mine,'{"author_bg_media_id":1,"name_bg_media_id":3}',true),422);
af_aa_personal_save(7,$mine,'{"author_bg_media_id":1,"name_bg_media_id":1}');
$mybb->settings['af_advancedappearance_personal_media_limit']=3;
af_aa_personal_save(7,$mine,'{"author_bg_media_id":1,"overlay_opacity":0.3,"accent_color":"#225588"}');
appearance_check((int)$db->fetch_field($db->simple_select('af_gallery_media','appearance_retained','id=1'),'appearance_retained')===1,'Published reference not retained');
appearance_check(str_contains(af_aa_render_page_css([7]),'uploads/mine.png'),'Gallery media not resolved');
$mybb->settings['af_advancedappearance_personal_types']='';
af_aa_personal_activate(7,'postbit',0);appearance_check(!af_aa_personal_active_row(7),'Reset kept active personal preset');
$mybb->settings['af_advancedappearance_personal_types']='postbit';
// Old shop choice continues to work after choosing a personal preset/reset.
af_aa_upsert_user_assignment(7,AF_AA_TARGET_APUI_POSTBIT_PACK,1);
appearance_check(str_contains(af_aa_render_page_css([7]),'legacy.png'),'Shop reactivation broken');
// Legacy catalog helpers must not overwrite/remove a personal row even for a forged ID.
af_aa_delete_front_preset($mine);appearance_check(af_aa_personal_owned_row(7,$mine)['id']===$mine,'Legacy delete removed personal definition');
af_aa_toggle_front_preset($mine,0);appearance_check((int)af_aa_personal_owned_row(7,$mine)['enabled']===1,'Legacy toggle changed personal');
// Lowering slot capacity preserves records/current assignment and allows deletion.
$mybb->settings['af_advancedappearance_max_slots_postbit']=0;appearance_check(af_aa_personal_slots(7,'postbit')['used']===1,'Max reduction deleted records');
af_aa_personal_save(7,$mine,'{}');appearance_fails(fn()=>af_aa_personal_clone(7,$mine,'Over maximum'),409);
$mybb->settings['af_advancedappearance_max_slots_postbit']=20;
af_aa_personal_delete(7,$mine);appearance_check(af_aa_personal_slots(7,'postbit')['available']===1,'Last deletion did not release slot');
$draft=af_aa_personal_create(7,'postbit','Draft','{"text_color":"#abcdef"}',true);
appearance_fails(fn()=>af_aa_personal_activate(7,'postbit',$draft),422);af_aa_personal_save(7,$draft,'{"text_color":"#abcdef"}');af_aa_personal_activate(7,'postbit',$draft);
$children=[];for($i=0;$i<12;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$created=0;foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);appearance_check(proc_close($proc)===0,'Concurrent creation crashed '.$err);if(str_starts_with($out,'created:'))$created++;else appearance_check($out==='refused:409','Wrong concurrent error '.$out);}
appearance_check($created===1&&appearance_count(AF_AA_PRESETS_TABLE_NAME,'owner_uid=50')===1,'Parallel creation exceeded slots');
// Prepared bought capacity is read-only here: future purchase integration inserts idempotent grants.
$db->write_query('INSERT INTO '.TABLE_PREFIX."af_aa_slot_grants (uid,preset_type,capacity,source_key) VALUES (7,'postbit',2,'fixture-only')");
appearance_check(af_aa_personal_slots(7,'postbit')['purchased']===2&&af_aa_personal_slots(7,'postbit')['capacity']===3,'Grant capacity not counted');
$mybb->settings['af_advancedappearance_max_slots_postbit']=2;appearance_check(af_aa_personal_slots(7,'postbit')['capacity']===2,'Maximum not enforced');
$db->delete_query('af_aa_slot_grants',"source_key='fixture-only'");
echo "Appearance integration passed: additive migration/engine/ACP preservation, system/free slots, CRUD/clone/draft/activation/reset, Gallery ownership/retention, legacy runtime/catalog protection, lowered limits, 12 concurrent creators, grants/max contract.\n";
