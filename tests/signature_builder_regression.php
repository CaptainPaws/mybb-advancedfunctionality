<?php
/** In-memory AF/MyBB boundary: no database service, no network and no browser. */
define('IN_MYBB',1);define('TABLE_PREFIX','test_');define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function af_apui_is_enabled(){return true;}
function verify_post_check($key,$silent=false){return $key==='csrf';}
function inline_error($messages){return implode(' ',$messages);}
function error($message){throw new RuntimeException($message);}
function ag_remote_is_domain_allowed($host){return $host==='images.example.org';}
function ag_get_owned_media($uid,$id){return $uid===7&&$id===10?['type'=>'local','status'=>'approved','mime'=>'image/gif']:null;}
function ag_resolve_media_url($id,$variant){return $id===10?'https://example.org/original.gif':'https://example.org/private.gif';}
function ag_retain_owned_media($uid,$id){return $uid===7&&$id===10;}
class SigBuilderMyBB {
    public $user=['uid'=>7,'postnum'=>20,'signature'=>'[b]Original signature[/b]','showimages'=>1];
    public $usergroup=['canusercp'=>1,'canusesig'=>1];public $settings=['bburl'=>'https://example.org','sigmycode'=>1,'sigimgcode'=>1];
    public $input=[];public $request_method='get';public $post_code='csrf';function get_input($key){return $this->input[$key]??'';}
}
class SigBuilderDB {
    public $rows=[],$users=[7=>'[b]Original signature[/b]'], $writes=0;
    function table_exists($table){return true;}
    function escape_string($s){return addslashes($s);}
    function simple_select($table,$fields='*',$where='',$options=[]){preg_match('/uid=(\d+)/',$where,$m);return ['table'=>$table,'uid'=>(int)($m[1]??0)];}
    function query($sql){preg_match('/b.uid=(\d+)/',$sql,$m);return ['table'=>'join','uid'=>(int)$m[1]];}
    function fetch_array($q){$id=$q['uid'];return isset($this->rows[$id])?($this->rows[$id]+($q['table']==='join'?['native_signature'=>$this->users[$id]]:[])):false;}
    function fetch_field($q,$field){return $q['table']==='users'?$this->users[$q['uid']]:($this->rows[$q['uid']][$field]??null);}
    function update_query($table,$fields,$where){preg_match('/uid=(\d+)/',$where,$m);foreach($fields as $key=>$value)$this->rows[(int)$m[1]][$key]=is_string($value)?stripslashes($value):$value;$this->writes++;}
    function insert_query($table,$fields){$id=$fields['uid'];$this->rows[$id]=['draft_json'=>null,'published_json'=>null,'published_hash'=>'','legacy_backup'=>null];$this->update_query($table,$fields,'uid='.$id);}
}
$mybb=new SigBuilderMyBB;$db=new SigBuilderDB;$headerinclude='';$signature='<p>Original parsed signature</p>';
require AF_ADDONS.'advancedprofileui/modules/signature_editor.php';
require AF_ADDONS.'advancedprofileui/modules/signature_builder.php';
require AF_ADDONS.'adaptivethemeframework/postbit-signature.php';
class SigBuilderTemplates {public $cache=[];function get($name,...$args){return file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/usercp_editsig.html');}}
$templates=new SigBuilderTemplates;
function check_sig($value,$message){if(!$value)throw new RuntimeException($message);}
function reject_sig(callable $fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new LogicException('Expected validation failure');}
$v=af_apui_sig_defaults();$v['title']='<script> [b]Text[/b]';$v['text']="First\nSecond";$v['background']['media_id']=10;$v['images'][0]['url']='https://images.example.org/portrait.gif';$v['buttons']=[['label'=>'World','url'=>'https://example.org/world']];
$validated=af_apui_sig_validate($v,7);$portable=af_apui_sig_compile($validated);
check_sig(!str_starts_with($portable,'{')&&str_contains($portable,'[url=')&&str_contains($portable,'[img]'),'Portable MyBB signature, not JSON');
check_sig(str_contains($portable,'&#91;b&#93;')&&!str_contains($portable,'<script>'),'Plain field text must not become HTML/BBCode');
foreach(array_keys(af_apui_sig_templates()) as $template){$state=$v;$state['template']=$template;$state=af_apui_sig_validate($state,7);$html=af_apui_sig_render($state);check_sig(str_contains($html,'--'.$template)&&!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Shared safe renderer: '.$template);check_sig($state['images']===$validated['images'],'Template switching must retain unused data');}
$preview=af_apui_sig_preview($validated,7);check_sig(str_contains($preview['html'],'<details open')&&str_contains($preview['html'],'atf-gothic-frame'),'Use existing open signature frame in preview');
$state=$v;$state['background']['url']='javascript:alert(1)';reject_sig(fn()=>af_apui_sig_validate($state,7));
$state=$v;$state['buttons'][0]['url']='data:text/html,bad';reject_sig(fn()=>af_apui_sig_validate($state,7));
$state=$v;$state['background']['media_id']=11;reject_sig(fn()=>af_apui_sig_validate($state,7));
$state=$v;$state['images'][]=$state['images'][0];reject_sig(fn()=>af_apui_sig_validate($state,7));
$state=$v;$state['buttons']=array_fill(0,7,$state['buttons'][0]);reject_sig(fn()=>af_apui_sig_validate($state,7));
$state=$v;$state['background']['media_id']=null;$state['background']['url']='https://example.org/active.svg';reject_sig(fn()=>af_apui_sig_validate($state,7));
$mybb->settings['sigimgcode']=0;reject_sig(fn()=>af_apui_sig_validate($v,7,true,true));$mybb->settings['sigimgcode']=1;
// Merely opening the constructor leaves legacy text and AF storage untouched.
af_apui_sig_page();check_sig($db->writes===0&&$db->users[7]===$mybb->user['signature'],'Opening must never migrate or write');
check_sig(str_contains($af_apui_signature_builder_html,'Original parsed signature')&&str_contains($af_apui_signature_builder_html,'af_sig_confirm_replace'),'Explicit legacy migration');
foreach(['updateposts','value="enable"','value="disable"','value="0"','data-af-ae-skip'] as $field)check_sig(str_contains($af_apui_signature_builder_html,$field),'Keep native controls and skip free editor: '.$field);
$mybb->request_method='post';$mybb->input=['action'=>'do_editsig','my_post_key'=>'csrf','af_signature_builder'=>'1','af_signature_model'=>json_encode($v)];
af_apui_sig_start();check_sig($mybb->input['action']==='editsig'&&$db->writes===0,'Unconfirmed migration must not publish');
$mybb->input['action']='do_editsig';$mybb->input['af_sig_confirm_replace']='1';unset($error);af_apui_sig_start();
check_sig($mybb->input['signature']===$portable&&$db->writes===0,'Compile before native MyBB verification, with no early persistence');
// Only the accepted native-save hooks persist a draft, backup, then the publication hash.
af_apui_sig_publish_start();check_sig($db->rows[7]['published_json']===null&&$db->rows[7]['legacy_backup']==='[b]Original signature[/b]','Backup and draft before native update');
$db->users[7]=$portable;af_apui_sig_publish_end();
check_sig(af_apui_sig_owned($db->rows[7],$portable)&&$db->rows[7]['draft_json']===null,'Publish model only after native stored BBCode matches');
$post=['uid'=>7,'signature'=>'Native parsed signature'];af_apui_sig_postbit($post);check_sig(str_contains($post['signature'],'af-apui-signature-composition')&&str_contains($post['signature'],'original.gif'),'Public output uses published shared renderer and original GIF');
$hidden=['uid'=>7,'signature'=>''];af_apui_sig_postbit($hidden);check_sig($hidden['signature']==='','Native visibility gate cannot be bypassed');
// Draft updates do not change published text or presentation.
$before=$db->rows[7]['published_json'];$draft=$v;$draft['title']='Draft only';$mybb->input['af_signature_model']=json_encode($draft);
af_apui_sig_api('draft',7);check_sig($db->users[7]===$portable&&$db->rows[7]['published_json']===$before,'Draft is not a second published signature');
reject_sig(fn()=>af_apui_sig_api('draft',8));$mybb->input['my_post_key']='bad';reject_sig(fn()=>af_apui_sig_api('draft',7));$mybb->input['my_post_key']='csrf';
$db->users[7]='Edited outside constructor';unset($GLOBALS['af_apui_sig_rows']);$post=['uid'=>7,'signature'=>'Externally edited parsed content'];af_apui_sig_postbit($post);check_sig($post['signature']==='Externally edited parsed content','External native edits detach stale structured output');
// Invalid submitted media must not leak unowned URLs through the error redisplay/seed.
$attack=$v;$attack['background']['media_id']=11;$GLOBALS['af_apui_sig_submitted']=$attack;$mybb->request_method='get';af_apui_sig_page();check_sig(!str_contains($af_apui_signature_builder_html,'private.gif'),'Unowned media URL must not leak in a seed or preview');
$roundtrip=af_apui_sig_validate(af_apui_sig_decode(json_encode($validated)),7);check_sig($roundtrip===$validated,'Model round-trip');
echo "Signature builder passed: four safe compositions, portable BBCode, no GET migration, explicit backup, native-save boundaries, drafts, hash detachment, CSRF/UID/media checks and shared preview.\n";
