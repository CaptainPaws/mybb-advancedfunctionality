<?php
/** Isolated gallery_test database + /tmp fixture files; never use a forum DB. */
define('IN_MYBB',1);define('TABLE_PREFIX','test_');define('TIME_NOW',time());
define('MYBB_ROOT','/tmp/ae-sticker-fixture/');
class StickerTestDB {
    public mysqli $connection;
    public function __construct() {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->connection=new mysqli(getenv('AG_TEST_DB_HOST') ?: 'af-gallery-test-db','root','','gallery_test');
        $this->connection->set_charset('utf8mb4');
    }
    public function build_create_table_collation() {return 'DEFAULT CHARSET=utf8mb4';}
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

function verify_post_check($key,$silent=false) {return $key==='fixture-key';}
function is_moderator($fid=0,$action='',$uid=0) {return $uid===9;}
function check($value,$message) {if(!$value) throw new RuntimeException($message);}
$db=new StickerTestDB();
$mybb=(object)['user'=>['uid'=>(int)($_GET['uid']??7),'usergroup'=>(int)($_GET['group']??2),'additionalgroups'=>(string)($_GET['additional']??'')],'usergroup'=>[],'settings'=>['bburl'=>'http://127.0.0.1:8102'],'request_method'=>strtolower($_SERVER['REQUEST_METHOD']??'get'),'input'=>$_POST+$_GET];
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/stikers/stikers.php';
if(PHP_SAPI==='cli') {
 if(($argv[1]??'')==='--init') {
  foreach([AF_AE_STIKERS_TABLE,AF_AE_STIKERS_CATEGORY_TABLE,AF_AE_STIKERS_RECENT_TABLE,'usergroups'] as $table) $db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
  $db->write_query('CREATE TABLE test_usergroups (gid INT PRIMARY KEY,cancp INT DEFAULT 0,issupermod INT DEFAULT 0)');
  $db->write_query('INSERT INTO test_usergroups VALUES (2,0,0),(4,1,0),(6,0,1)');
  af_advancededitor_stikers_ensure_schema();
  $message='';check(af_advancededitor_stikers_save_settings(['user_quota_mb'=>1],$message),$message);
  $dir=af_advancededitor_stikers_get_user_folder(7);@mkdir($dir,0775,true);
  file_put_contents($dir.'/legacy.png',str_repeat('x',100));
  $db->insert_query(AF_AE_STIKERS_TABLE,['title'=>'legacy','slug'=>'legacy','path'=>$db->escape_string($dir.'/legacy.png'),'url'=>'https://forum.test/legacy.png','is_user_sticker'=>1,'uid'=>7]);
  file_put_contents(af_advancededitor_stikers_abs().'obshee/system.png',str_repeat('s',10000));
  $db->insert_query(AF_AE_STIKERS_TABLE,['title'=>'system','slug'=>'system','path'=>$db->escape_string(af_advancededitor_stikers_abs().'obshee/system.png'),'url'=>'https://forum.test/system.png','is_user_sticker'=>0,'uid'=>7]);
  $db->write_query('ALTER TABLE '.TABLE_PREFIX.AF_AE_STIKERS_TABLE.' DROP INDEX uid, DROP COLUMN uid');
  af_advancededitor_stikers_ensure_schema();
  check((int)$db->fetch_field($db->simple_select(AF_AE_STIKERS_TABLE,'SUM(uid) AS n'),'n')===0,'Migration guessed legacy owners');
  $db->update_query(AF_AE_STIKERS_TABLE,['uid'=>7],"slug='legacy' OR slug='system'");
  check(af_ae_sticker_usage(7)['used_bytes']===100,'Legacy bytes/system exclusion');
  af_advancededitor_stikers_save_settings(['thumb_size'=>70],$message);
  af_advancededitor_stikers_ensure_schema();
  check(af_advancededitor_stikers_get_settings()['user_quota_mb']===1 && af_advancededitor_stikers_get_settings()['thumb_size']===70,'Repeated ensure/save reset quotas');
  echo "Sticker fixture ready: safe defaults, preserved settings, additive owner migration and existing local/system accounting.\n";
 }
 exit;
}
if(($_GET['action']??'')==='config') {$message='';af_advancededitor_stikers_save_settings($_POST,$message);af_advancededitor_stikers_json(['success'=>true]);}
if(($_GET['action']??'')==='js') {header('Content-Type: application/javascript');readfile(dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/stikers/stikers.js');exit;}
if(($_GET['action']??'')==='page') {
 $config=af_advancededitor_stikers_get_client_config();
 foreach(['listUrl','uploadUrl','deleteUrl','recentUrl'] as $key) $config[$key].='&'.http_build_query(['uid'=>$mybb->user['uid'],'group'=>$mybb->user['usergroup'],'additional'=>$mybb->user['additionalgroups']]);
 echo '<!doctype html><meta charset="utf-8"><script>window.afAePayload={postKey:"fixture-key",stikers:'.json_encode($config).'};window.af_modal={open:function(o){document.body.innerHTML=o.html;},close:function(){}};</script><script src="?action=js"></script><button onclick="af_ae_stikers_exec({insert:function(){}})">Стикеры</button>';exit;
}
af_advancededitor_stikers_handle_ajax();
