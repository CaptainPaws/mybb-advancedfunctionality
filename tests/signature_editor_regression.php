<?php
/** Short signature adapter checks: native MyBB form, editor lifecycle and scoped presentation. */
define('IN_MYBB',1);define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
define('MYBB_ROOT',dirname(__DIR__).'/');define('THIS_SCRIPT','usercp.php');define('TABLE_PREFIX','mybb_');define('AF_APUI_ID','advancedprofileui');
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function af_apui_is_enabled(){return true;}
function af_is_blacklisted($id){return true;}
function redirect($url,$message){throw new RuntimeException($url);}
class SignatureDB {
    function table_exists($t){return false;}
    function escape_string($s){return addslashes($s);}
    function simple_select($t,$f='*',$w='',$o=[]){return ['where'=>$w];}
    function fetch_field($q,$f){return '';}
    function fetch_array($q){return false;}
}
class SignatureMyBB {
    public $settings=['bburl'=>'https://example.org','af_advancededitor_enabled'=>1,'sigmycode'=>1,'sigimgcode'=>1,'sigsmilies'=>1,'sightml'=>0];
    public $user=['uid'=>7,'postnum'=>10];public $usergroup=['canusercp'=>1,'canusesig'=>1];
    public $input=['action'=>'editsig','af_apui_signature_embed'=>'1'];public $post_code='signature-key';
    function get_input($key){return $this->input[$key]??'';}
}
$mybb=new SignatureMyBB;$db=new SignatureDB;$headerinclude='';
require AF_ADDONS.'advancededitor/advancededitor.php';
require AF_ADDONS.'advancedprofileui/modules/signature_editor.php';
require AF_ADDONS.'adaptivethemeframework/postbit-signature.php';
function sig_check($value,$message){if(!$value)throw new RuntimeException($message);}
$frame='<span class="atf-gothic-frame"><i class="atf-gothic-corner"></i></span>';
$html='<div class="signature scaleimages"><a href="/profile">Link</a><img src="/original.gif"></div>';
$one=af_adaptivethemeframework_signature_markup($html,'42',$frame);
$two=af_adaptivethemeframework_signature_markup($html,'43',$frame);
sig_check(str_contains($one,$html) && str_contains($one,$frame),'Preserve native signature and existing frame');
sig_check(!preg_match('~<details[^>]*\bopen(?:[\s=>])~',$one),'Disclosure must start collapsed');
preg_match('~aria-controls="([^"]+)"~',$one,$id);sig_check(str_contains($one,'id="'.$id[1].'"')&&!str_contains($two,$id[1]),'Unique signature IDs and controls');
sig_check(af_adaptivethemeframework_signature_markup('', '1',$frame)===''&&af_adaptivethemeframework_signature_markup('<div> </div>','1',$frame)==='','No empty separator');
sig_check(str_contains($one,'Подпись') && str_contains($one,'signature-arrow'),'Disclosure label and arrow');
$native=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/usercp_editsig.html');
$compact=af_apui_signature_embed_source($native);
foreach (['name="signature"','name="my_post_key"','value="do_editsig"','name="preview"','name="updateposts"','{$sig}','{$error}','{$signature}'] as $token) sig_check(str_contains($compact,$token),'Native field missing: '.$token);
sig_check(substr_count($compact,'<textarea')===1 && !str_contains($compact,'atf_ucp_global_navigation'),'Compact form must retain exactly one native input');
sig_check(af_apui_signature_embed_source('<form><textarea name="other"></textarea></form>')===null,'Unknown templates must stay untouched');
$block=af_apui_signature_studio_block();
sig_check(str_contains($block,'Открыть конструктор подписи')&&!str_contains($block,'<iframe'),'APStudio links to the content constructor without embedding a second editor');
sig_check(!str_contains($headerinclude,'sceditor')&&!str_contains($headerinclude,'advancededitor.js'),'Studio bootstrap must stay lightweight');
sig_check(af_advancededitor_is_signature_page()&&!af_advancededitor_assets_disabled_for_current_page(),'Native signature surface must override former blanket UserCP exclusion');
$page='<html><head></head><body><form action="usercp.php" method="post"><input name="my_post_key" value="signature-key"><textarea name="signature" id="signature">[b]Draft &amp; GIF[/b]</textarea><button name="preview">Preview</button></form></body></html>';
sig_check(!af_advancededitor_response_facts(str_replace('<form','<form data-af-signature-builder',$page))['has_supported_editor'],'No full editor in constructor');
$facts=af_advancededitor_response_facts($page);sig_check($facts['has_signature_editor'] && $facts['has_supported_editor'],'Signature response facts');
af_advancededitor_pre_output($page);
sig_check(substr_count($page,'data-af-editor-shell="1"')===1 && substr_count($page,'<textarea')===1,'One native textarea and one toolbar');
foreach (['bold','italic','underline','strike','quote','code','link','image','af_togglemode'] as $cmd) sig_check(str_contains($page,'data-af-command="'.$cmd.'"'),'Missing toolbar command '.$cmd);
sig_check(str_contains($page,'textarea.af-ae-signature-editor')&&str_contains($page,'fontFamilies'),'Signature selector and shared font registry');
sig_check(str_contains($page,'[b]Draft &amp; GIF[/b]'),'Keep original BBCode including validation errors');
sig_check(!preg_match('~src="[^"]*(?:jquery\.sceditor|advancededitor\.js)~',$page),'Heavy WYSIWYG remains on-demand');
sig_check(!str_contains($page,'data-af-command="af_embedvideos"'),'Signature toolbar must follow native video limits');
sig_check(af_advancededitor_render_shells($page,'')===$page,'Lifecycle must not duplicate shells');
$mybb->settings['sigimgcode']=0;
$buttons=af_advancededitor_signature_buttons([['cmd'=>'bold'],['cmd'=>'image'],['cmd'=>'af_stikers']]);
sig_check(array_column($buttons,'cmd')===['bold'],'Respect disabled images');
$mybb->settings['sigmycode']=0;sig_check(!af_advancededitor_response_facts('<textarea name="signature"></textarea>')['has_supported_editor'],'No formatting toolbar when signature MyCode is disabled');
$mybb->input['action']='profile';sig_check(!af_advancededitor_is_signature_page()&&af_advancededitor_assets_disabled_for_current_page(),'Do not unblock unrelated UserCP editors');
$mybb->input['action']='do_editsig';
try{af_apui_signature_saved();throw new LogicException('Expected native post-save redirect');}catch(RuntimeException $e){sig_check(str_contains($e->getMessage(),'af_signature_saved=1'),'Preserve embedded context after native save');}
$mybb->user['suspendsignature']=1;$mybb->user['suspendsigtime']=0;
sig_check(!af_apui_signature_can_edit()&&af_apui_signature_studio_block()==='','Suspended signatures must retain native access restrictions');
echo "Signature checks passed: collapsed independent disclosures, unchanged parsed content/GIF, native form/CSRF/error fields, studio content link, toolbar/fonts and idempotent shell.\n";
