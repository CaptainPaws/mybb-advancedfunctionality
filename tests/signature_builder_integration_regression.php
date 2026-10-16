<?php
/** Exercise the actual ATF composer and MyBB's raw-cache -> get(eslashes) -> eval contract. */
require __DIR__.'/signature_builder_regression.php';
define('THIS_SCRIPT','usercp.php');
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
function af_is_addon_enabled($id){return (string)($GLOBALS['mybb']->settings['af_'.$id.'_enabled']??'0')==='1';}
class ASBHooks {public $hooks=[];function add_hook($hook,$callback,$priority=10){$this->hooks[$hook][$priority][]=$callback;}}
class NativeContractTemplates {
    public $cache=[];
    function get($title,$eslashes=1,$htmlcomments=1){
        $text=$this->cache[$title];
        // Exact MyBB 1.8.40 escaping contract; cache always contains raw template source.
        return $eslashes?str_replace("\\'","'",addslashes($text)):$text;
    }
}
class ASBMigrationDB extends SigBuilderDB {
    public $legacy=[],$sql=[];
    function build_create_table_collation(){return '';}
    function write_query($sql){
        $this->sql[]=$sql;
        if(str_starts_with($sql,'INSERT IGNORE INTO test_af_asb_signatures')) { foreach($this->legacy as $uid=>$row)if(!isset($this->rows[$uid]))$this->rows[$uid]=$row; }
        elseif(!str_starts_with($sql,'CREATE TABLE'))throw new RuntimeException('Unexpected migration query');
    }
}
// Activation migration retains every column, is repeatable and never overwrites ASB's newer copy.
$migrate=new ASBMigrationDB;$backupDb=$db;$db=$migrate;
$old=['uid'=>7,'draft_json'=>'{"title":"Draft"}','published_json'=>'{"title":"Published"}','published_hash'=>str_repeat('a',64),'legacy_backup'=>'[b]Original GIF[/b]','updated_at'=>123];
$migrate->legacy=[7=>$old,8=>array_replace($old,['uid'=>8])];
$migrate->rows[8]=array_replace($old,['uid'=>8,'draft_json'=>'newer ASB draft']);
af_asb_install_schema();af_asb_install_schema();
check_sig($migrate->rows[7]===$old&&$migrate->rows[8]['draft_json']==='newer ASB draft'&&$migrate->legacy[7]===$old,'Idempotent migration preserves all columns, old backup and newer ASB rows');
check_sig(!array_filter($migrate->sql,fn($sql)=>str_contains($sql,'DROP')||str_contains($sql,'UPDATE test_af_apui')),'Old table must not be changed or removed');
$db=$backupDb;unset($GLOBALS['af_asb_rows'],$GLOBALS['af_asb_submitted']);
$mybb->settings['af_adaptivethemeframework_enabled']='1';$mybb->settings['bbname']='Forum';
$mybb->input=['action'=>'editsig'];$mybb->request_method='get';
$lang=(object)['edit_sig'=>'Изменить подпись','edit_sig_note'=>'Rules','edit_sig_note2'=>'Limits','enable_sig_posts'=>'Enable','disable_sig_posts'=>'Disable','leave_sig_settings'=>'Keep','update_sig'=>'Save','preview'=>'Preview'];
$plugins=new ASBHooks;
af_advancedsignaturebuilder_init();$hooks=$plugins->hooks;af_advancedsignaturebuilder_init();
check_sig($plugins->hooks===$hooks&&count($hooks['usercp_start'][100])===1,'One hook registration, even on repeated bootstrap');
$templates=new NativeContractTemplates;
$source=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/usercp_editsig.html');$templates->cache['usercp_editsig']=$source;
$headerinclude='';$header='<header id="preserved-menu">AdvancedMenu</header>';$footer='<footer>Footer</footer>';$error='';$sig='[b]Native[/b]';$smilieinserter='';$codebuttons='<script data-native-editor>native()</script>';
af_asb_prepare_page();af_adaptivethemeframework_compose_ucp_profile_surface();
check_sig(str_contains($headerinclude,'af_asb_builder.css')&&str_contains($headerinclude,'signature-builder.js'),'ATF slot prepares owner assets before MyBB evaluates headerinclude');
check_sig($templates->cache['usercp_editsig']===$source,'ASB must not rewrite ATF-owned page source or persist a lease');
eval('$rendered = "'.$templates->get('usercp_editsig').'";');
foreach(['class="pun atf-page-shell','id="preserved-menu"','atf-ucp-navigation--global','atf-ucp-navigation--local','aria-current="page"','class="af-asb__layout"','data-af-asb-builder','name="af_signature_builder"','name="updateposts"','value="do_editsig"','Конструктор подписи','data-asb-text="title"','data-asb-image="3"'] as $needle)check_sig(str_contains($rendered,$needle),'Rendered UserCP contract missing '.$needle);
check_sig(substr_count($rendered,'class="af-asb__template')===5,'Four server-rendered template cards and one card grid');
check_sig(!str_contains($rendered,'\\"')&&!str_contains($rendered,'data-native-editor')&&substr_count($rendered,'<form ')===1,'One real form, no service-value escaping and no unused editor runtime');
$withAssets=$rendered;af_asb_pre_output($withAssets);$once=$withAssets;af_asb_pre_output($withAssets);
check_sig($withAssets===$once&&str_contains($withAssets,'/advancedsignaturebuilder/assets/signature-builder.js'),'Owner-owned editor assets, only once');
$public='<html><head></head><body><section class="af-asb-signature"></section></body></html>';af_asb_pre_output($public);
check_sig(str_contains($public,'signature-view.css')&&!str_contains($public,'signature-builder.js')&&!str_contains($public,'af_asb_builder.css'),'Public signatures must not load the constructor runtime');
// Reproduce the predecessor's error to ensure this test detects escaped-cache corruption.
$templates->cache['probe']='<form><input value="enable"></form>';
$templates->cache['probe']=$templates->get('probe',1,0);
eval('$broken = "'.$templates->get('probe').'";');
check_sig(str_contains($broken,'\\"enable\\"'),'Regression fixture must detect double-escaped cache');
// With the addon disabled, ATF's native form renders inside the same navigation shell.
$mybb->settings['af_advancedsignaturebuilder_enabled']='0';af_adaptivethemeframework_compose_ucp_profile_surface();
eval('$native = "'.$templates->get('usercp_editsig').'";');
check_sig(str_contains($native,'name="signature"')&&str_contains($native,'data-native-editor')&&!str_contains($native,'data-af-asb-builder')&&str_contains($native,'atf-ucp-navigation--local'),'Disabled ASB must restore native form in the same shell');
// Without ATF or APUI, use the explicit stock shell and keep the native UserCP navigation.
$mybb->settings['af_adaptivethemeframework_enabled']='0';$mybb->settings['af_advancedsignaturebuilder_enabled']='1';$usercpnav='<td id="native-usercpnav">Native navigation</td>';
af_asb_prepare_page();eval('$fallback = "'.$templates->get('usercp_editsig').'";');
check_sig(str_contains($fallback,'id="native-usercpnav"')&&str_contains($fallback,'data-af-asb-builder')&&!str_contains($fallback,'\\"'),'Stock fallback respects raw-cache contract');
$manifest=require AF_ASB_BASE.'manifest.php';
check_sig($manifest['id']==='advancedsignaturebuilder'&&$manifest['frontend']['directory_fallback']===false,'Independent manifest identity and contextual permissions');
foreach(['editor','preview','view','studio_link'] as $resource)check_sig(isset($manifest['frontend']['resources'][$resource]),'Declared frontend resource '.$resource);
foreach(['install','activate','deactivate','upgrade','uninstall'] as $operation)check_sig(function_exists('af_advancedsignaturebuilder_'.$operation),'Lifecycle '.$operation);
$apui=file_get_contents(AF_ADDONS.'advancedprofileui/advancedprofileui.php');check_sig(!str_contains($apui,'af_apui_sig_')&&!is_file(AF_ADDONS.'advancedprofileui/modules/signature_builder.php'),'No duplicate APUI ownership or hooks');
// The changed section source upgrades through ATF's existing ledger, retaining its original backup.
require __DIR__.'/fixtures/atf_ownership_database.php';
$db=new AtfDatabase();$path=AF_ADAPTIVETHEMEFRAMEWORK_BASE.'templates/usercp_editsig.html';
check_sig(af_adaptivethemeframework_acquire_template('usercp_editsig',$path),'Acquire section through existing ATF ownership');
$prior='<html><body>Previously installed ATF signature form</body></html>';$priorHash=af_adaptivethemeframework_checksum($prior);
foreach($db->tables['templates'] as &$template)if($template['title']==='usercp_editsig'&&(int)$template['sid']>0)$template['template']=$prior;unset($template);
foreach($db->tables['af_adaptivethemeframework_template_ownership'] as &$lease)if($lease['template_name']==='usercp_editsig'){
    $backup=$lease['previous_content'];$lease['atf_seed_content']=$prior;$lease['atf_seed_checksum']=$priorHash;$lease['atf_installed_checksum']=$priorHash;
}unset($lease);
check_sig(af_adaptivethemeframework_acquire_template('usercp_editsig',$path)&&af_adaptivethemeframework_acquire_template('usercp_editsig',$path),'Clean previous seed upgrades and reacquires without conflict');
foreach($db->tables['af_adaptivethemeframework_template_ownership'] as $lease)if($lease['template_name']==='usercp_editsig')check_sig($lease['previous_content']===$backup,'Original ATF template backup must survive section upgrades');
echo "ASB integration passed: idempotent migration, one owner, raw template cache, ATF navigation and native fallback, stock fallback, isolated assets and independent lifecycle.\n";
