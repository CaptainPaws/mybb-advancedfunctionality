<?php
declare(strict_types=1);
$core=$argv[1]??'';$mode=$argv[2]??'disabled';
if (!is_file($core.'/newreply.php'))throw new RuntimeException('Provide disposable upstream mybb_1841');
if ($mode==='native-disabled' && !defined('IN_MYBB')) {
    $dir=sys_get_temp_dir().'/acs-entry-'.bin2hex(random_bytes(8));mkdir($dir);
    file_put_contents($dir.'/global.php','<?php require '.var_export(__FILE__,true).';');
    register_shutdown_function(static function()use($dir):void {unlink($dir.'/global.php');rmdir($dir);});
    chdir($dir);require $core.'/newreply.php';throw new RuntimeException('Native fallthrough');
}
if(!defined('IN_MYBB'))define('IN_MYBB',1);
if(!defined('THIS_SCRIPT'))define('THIS_SCRIPT',$mode==='edit'?'editpost.php':'newreply.php');
define('MYBB_ROOT',$mode==='source'?'/tmp/acs-unverified-source/':rtrim($core,'/').'/');
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
if(!function_exists('af_is_addon_enabled')){function af_is_addon_enabled(string $id):bool{return !str_contains($GLOBALS['mode'],'disabled');}}
$mybb=new class($mode) {
    public array $input,$user=['uid'=>7],$settings=['bburl'=>'http://fixture.local'];public string $version='1.8.41';
    public function __construct(string $mode){$this->input=['acs_mode'=>$mode==='narrative'?'narrative':'combat','acs_operation'=>$mode==='narrative'?'':'publish','action'=>'acs_publication'];}
    public function get_input($key){return $this->input[$key]??'';}
};
if(in_array($mode,['late','source'],true)){define('AF_ACS_ENVIRONMENT','test');define('AF_ACS_PUBLICATION_TEST_UIDS',[7]);define('AF_ACS_PUBLICATION_TEST_ORIGIN','http://fixture.local');}
$_SERVER['HTTP_HOST']='fixture.local';require_once $core.'/inc/class_plugins.php';$plugins=new pluginSystem;
require AF_ADDONS.'advancedcombatsystem/modules/Bootstrap/EditorPublicationEntry.php';
af_advancedcombatsystem_reply_guard();
if($mode==='late'){$plugins->add_hook('global_end','acs_fixture_late',PHP_INT_MAX);$plugins->run_hooks('global_end');}
if(in_array($mode,['narrative','edit'],true)){af_advancedcombatsystem_reply_entry();echo 'PASSTHROUGH';exit;}
throw new RuntimeException('Combat fell through');
if(!function_exists('acs_fixture_late')){function acs_fixture_late():void {throw new RuntimeException('Unexpected late hook execution');}}
