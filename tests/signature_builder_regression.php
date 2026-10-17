<?php
/** In-memory AF/MyBB boundary: no database service, no network and no browser. */
define('IN_MYBB',1);define('TABLE_PREFIX','test_');define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function verify_post_check($key,$silent=false){return $key==='csrf';}
function bad_word_filter($s){return str_replace('filtered_word','***',$s);}
function inline_error($messages){return implode(' ',$messages);}
function redirect($url,$message){throw new RuntimeException('redirect:'.$url);}
function error($message){throw new RuntimeException($message);}
function ag_remote_is_domain_allowed($host){return $host==='images.example.org';}
function ag_get_owned_media($uid,$id){return $uid===7&&$id===10?['type'=>'local','status'=>'approved','mime'=>'image/gif']:null;}
function ag_resolve_media_url($id,$variant){return $id===10?'https://example.org/original.gif':'https://example.org/private.gif';}
function ag_retain_owned_media($uid,$id){return $uid===7&&$id===10;}
class SigBuilderMyBB {
    public $user=['uid'=>7,'postnum'=>20,'signature'=>'[b]Original signature[/b]','showimages'=>1];
    public $usergroup=['canusercp'=>1,'canusesig'=>1];public $settings=['bburl'=>'https://example.org','sigmycode'=>1,'sigimgcode'=>1,'af_advancedsignaturebuilder_enabled'=>'1'];
    public $input=[];public $request_method='get';public $post_code='csrf';function get_input($key){return $this->input[$key]??'';}
}
class SigBuilderDB {
    public $rows=[],$users=[7=>'[b]Original signature[/b]'], $writes=0;
    function table_exists($table){return true;}
    function field_exists($field,$table){return true;}
    function escape_string($s){return addslashes($s);}
    function simple_select($table,$fields='*',$where='',$options=[]){preg_match('/uid=(\d+)/',$where,$m);return ['table'=>$table,'uid'=>(int)($m[1]??0)];}
    function query($sql){preg_match('/b.uid=(\d+)/',$sql,$m);return ['table'=>'join','uid'=>(int)$m[1]];}
    function fetch_array($q){$id=$q['uid'];return isset($this->rows[$id])?($this->rows[$id]+($q['table']==='join'?['native_signature'=>$this->users[$id]]:[])):false;}
    function fetch_field($q,$field){return $q['table']==='users'?$this->users[$q['uid']]:($this->rows[$q['uid']][$field]??null);}
    function update_query($table,$fields,$where){preg_match('/uid=(\d+)/',$where,$m);foreach($fields as $key=>$value)$this->rows[(int)$m[1]][$key]=is_string($value)?stripslashes($value):$value;$this->writes++;}
    function insert_query($table,$fields){$id=$fields['uid'];$this->rows[$id]=['draft_json'=>null,'published_json'=>null,'published_hash'=>'','legacy_backup'=>null];$this->update_query($table,$fields,'uid='.$id);}
}
$mybb=new SigBuilderMyBB;$db=new SigBuilderDB;$headerinclude='';$signature='<p>Original parsed signature</p>';
require AF_ADDONS.'advancedsignaturebuilder/advancedsignaturebuilder.php';
require AF_ADDONS.'adaptivethemeframework/postbit-signature.php';
class SigBuilderTemplates {public $cache=[];function get($name,...$args){return file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/usercp_editsig.html');}}
$templates=new SigBuilderTemplates;
function check_sig($value,$message){if(!$value)throw new RuntimeException($message);}
function reject_sig(callable $fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new LogicException('Expected validation failure');}
$v=af_asb_defaults();$v['title']='<script> [b]Text[/b]';$v['text']="First\nSecond";$v['template']='card';$v['images'][0]['media_id']=10;$v['buttons']=[['label'=>'World','url'=>'https://example.org/world']];
$validated=af_asb_validate($v,7);$portable=af_asb_compile($validated);
check_sig(!str_starts_with($portable,'{')&&str_contains($portable,'[url=')&&str_contains($portable,'[img]'),'Portable MyBB signature, not JSON');
check_sig(str_contains($portable,'&#91;b&#93;')&&!str_contains($portable,'<script>'),'Plain field text must not become HTML/BBCode');
foreach(array_keys(af_asb_templates()) as $template){$state=$v;$state['template']=$template;$state=af_asb_validate($state,7);$html=af_asb_render($state);check_sig(str_contains($html,'--'.$template)&&!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Shared safe renderer: '.$template);check_sig($state['images']===$validated['images'],'Template switching must retain unused data');}
$off=$validated;$off['show_divider']=false;check_sig(str_contains(af_asb_render($validated),'af-asb-signature__divider')&&!str_contains(af_asb_render($off),'af-asb-signature__divider'),'Divider control affects shared renderer');
$legacyModel=$validated;unset($legacyModel['show_divider']);check_sig(af_asb_validate($legacyModel,7)['show_divider']===true,'Old models inherit divider default without storage migration');
$preview=af_asb_preview($validated,7);check_sig(str_contains($preview['html'],'<details open')&&str_contains($preview['html'],'atf-gothic-frame'),'Use existing open signature frame in preview');
$state=$v;$state['images'][0]['url']='javascript:alert(1)';reject_sig(fn()=>af_asb_validate($state,7));
$state=$v;$state['buttons'][0]['url']='data:text/html,bad';reject_sig(fn()=>af_asb_validate($state,7));
$state=$v;$state['images'][0]['media_id']=11;reject_sig(fn()=>af_asb_validate($state,7));
$state=$v;$state['images'][]=$state['images'][0];reject_sig(fn()=>af_asb_validate($state,7));
$state=$v;$state['buttons']=array_fill(0,7,$state['buttons'][0]);reject_sig(fn()=>af_asb_validate($state,7));
$state=$v;$state['images'][0]['media_id']=null;$state['images'][0]['url']='https://example.org/active.svg';reject_sig(fn()=>af_asb_validate($state,7));
$mybb->settings['sigimgcode']=0;reject_sig(fn()=>af_asb_validate($v,7,true,true));$mybb->settings['sigimgcode']=1;
$filter=$validated;$filter['text']='filtered_word';check_sig(str_contains(af_asb_render($filter),'***'),'Shared renderer retains native word filtering');
// Merely opening the constructor leaves legacy text and AF storage untouched.
$af_asb_builder_html=af_asb_component();check_sig($db->writes===0&&$db->users[7]===$mybb->user['signature'],'Opening must never migrate or write');
check_sig(str_contains($af_asb_builder_html,'Original parsed signature')&&str_contains($af_asb_builder_html,'af_sig_confirm_replace'),'Explicit legacy migration');
foreach(['updateposts','value="enable"','value="disable"','value="0"'] as $field)check_sig(str_contains($af_asb_builder_html,$field),'Keep native controls and skip free editor: '.$field);
$mybb->request_method='post';$mybb->input=['action'=>'do_editsig','my_post_key'=>'csrf','af_signature_builder'=>'1','af_signature_model'=>json_encode($v)];
af_asb_start();check_sig($mybb->input['action']==='editsig'&&$db->writes===0,'Unconfirmed migration must not publish');
$mybb->input['action']='do_editsig';$mybb->input['af_sig_confirm_replace']='1';unset($error);af_asb_start();
check_sig($mybb->input['signature']===$portable&&$db->writes===0,'Compile before native MyBB verification, with no early persistence');
// Only the accepted native-save hooks persist a draft, backup, then the publication hash.
af_asb_publish_start();check_sig($db->rows[7]['published_json']===null&&$db->rows[7]['legacy_backup']==='[b]Original signature[/b]','Backup and draft before native update');
$db->users[7]=$portable;af_asb_publish_end();
try{af_asb_saved_redirect();throw new LogicException('Expected success redirect');}catch(RuntimeException $e){check_sig(str_starts_with($e->getMessage(),'redirect:'),'Publish redirect');}
check_sig(af_asb_owned($db->rows[7],$portable)&&$db->rows[7]['draft_json']===null,'Publish model only after native stored BBCode matches');
$post=['uid'=>7,'signature'=>'Native parsed signature'];af_asb_postbit($post);check_sig(str_contains($post['signature'],'af-asb-signature')&&str_contains($post['signature'],'original.gif'),'Public output uses published shared renderer and original GIF');
$hidden=['uid'=>7,'signature'=>''];af_asb_postbit($hidden);check_sig($hidden['signature']==='','Native visibility gate cannot be bypassed');
// Draft updates do not change published text or presentation.
$before=$db->rows[7]['published_json'];$draft=$v;$draft['title']='Draft only';$mybb->input['af_signature_model']=json_encode($draft);
af_asb_api('draft',7);check_sig($db->users[7]===$portable&&$db->rows[7]['published_json']===$before,'Draft is not a second published signature');
reject_sig(fn()=>af_asb_api('draft',8));$mybb->input['my_post_key']='bad';reject_sig(fn()=>af_asb_api('draft',7));$mybb->input['my_post_key']='csrf';
$db->users[7]='Edited outside constructor';unset($GLOBALS['af_asb_rows']);$post=['uid'=>7,'signature'=>'Externally edited parsed content'];af_asb_postbit($post);check_sig($post['signature']==='Externally edited parsed content','External native edits detach stale structured output');
// Invalid submitted media must not leak unowned URLs through the error redisplay/seed.
$attack=$v;$attack['images'][0]['media_id']=11;$GLOBALS['af_asb_submitted']=$attack;$mybb->request_method='get';$af_asb_builder_html=af_asb_component();check_sig(!str_contains($af_asb_builder_html,'private.gif'),'Unowned media URL must not leak in a seed or preview');
$roundtrip=af_asb_validate(af_asb_decode(json_encode($validated)),7);check_sig($roundtrip===$validated,'Model round-trip');
echo "Signature builder passed: four safe compositions, portable BBCode, no GET migration, explicit backup, native-save boundaries, drafts, hash detachment, CSRF/UID/media checks and shared preview.\n";

$empty=af_asb_defaults();$empty['template']='gallery';
check_sig(af_asb_validate($empty,7)===$empty,'Empty gallery must validate as a draft');
try { af_asb_validate($empty,7,true,true);throw new LogicException('Empty gallery published'); }
catch(AF_ASB_FieldException $e){check_sig($e->field==='images','Publish error must identify image group');}
check_sig(substr_count(af_asb_preview($empty,7)['html'],'class="af-asb-signature__placeholder"')===3,'Preview needs exactly three empty gallery slots');
check_sig(!str_contains(af_asb_render($empty),'af-asb-signature__placeholder'),'Public renderer leaked placeholders');
$one=$empty;$one['images'][1]['media_id']=10;$one=af_asb_validate($one,7,true,true);
check_sig($one['images'][1]['media_id']===10 && $one['images'][0]['media_id']===null,'Media ID must remain in its original slot');
check_sig(substr_count(af_asb_preview($one,7)['html'],'class="af-asb-signature__placeholder"')===2,'One image must replace only its own preview slot');
$one['images'][1]['enabled']=false;reject_sig(fn()=>af_asb_validate($one,7,true,true));
$empty['template']='card';check_sig(substr_count(af_asb_preview($empty,7)['html'],'class="af-asb-signature__placeholder"')===1,'Empty card needs an editor placeholder');
$mybb->request_method='post';$mybb->input=['action'=>'editsig','my_post_key'=>'csrf','af_signature_model'=>json_encode($empty)];
$empty['template']='gallery';$mybb->input['af_signature_model']=json_encode($empty);af_asb_api('draft',7);
check_sig(json_decode($db->rows[7]['draft_json'],true)['template']==='gallery','Empty gallery draft did not persist');
check_sig(substr_count(af_asb_api('preview',7)['html'],'class="af-asb-signature__placeholder"')===3,'API preview incorrectly requires publication fields');
// Restore one published owner model and inspect the public profile boundary with a different visitor.
$db->rows[7]['published_json']=json_encode($validated);$db->rows[7]['published_hash']=hash('sha256',$portable);$db->users[7]=$portable;unset($GLOBALS['af_asb_rows']);
$memprofile=['uid'=>7,'signature'=>'<b>Already parsed by MyBB</b>'];$signature='Native visible signature';$mybb->user['uid']=8;$headerinclude='';
af_asb_profile();check_sig(str_contains($signature,'af-aa-signature-user-7') && str_contains($signature,'af-asb-signature') && !str_contains($signature,'af-aa-signature-user-8'),'Profile must render its owner, never visitor');
$signature='';af_asb_profile();check_sig($signature==='','Profile native visibility gate bypassed');
$signature='Legacy parsed signature';$memprofile['signature']='Legacy signature';$db->users[7]='Legacy signature';unset($GLOBALS['af_asb_rows']);af_asb_profile();check_sig($signature==='Legacy parsed signature','Legacy profile signature was replaced');
check_sig(!str_contains($af_asb_builder_html,'Фон подписи') && substr_count($af_asb_builder_html,'class="af-asb__step" aria-hidden="true">')===4,'Only three numbered steps and preview icon, no background controls');
check_sig(strpos($af_asb_builder_html,'<summary>Параметры публикации</summary>')<strpos($af_asb_builder_html,'class="af-asb__actions"'),'Publication disclosure remains below actions');
check_sig(substr_count($af_asb_builder_html,'class="af-asb__image-remove"')===3,'Three visible-slot removal controls are required');
echo "Signature additions passed: draft/preview/publish validation, exact placeholder slots, media IDs, owner profile and settings order.\n";

foreach(array_keys(af_asb_templates()) as $template) {
    $composition=$validated;$composition['template']=$template;$composition['subtitle']='Подзаголовок';
    foreach($composition['images'] as &$image)$image=['enabled'=>true,'media_id'=>10,'url'=>''];unset($image);
    $native=af_asb_compile($composition);$db->users[7]=$native;$db->rows[7]['published_json']=json_encode($composition);$db->rows[7]['published_hash']=hash('sha256',$native);unset($GLOBALS['af_asb_rows']);
    $post=['uid'=>7,'signature'=>'Visible native signature'];af_asb_postbit($post);
    $memprofile=['uid'=>7,'signature'=>'<p>Parsed HTML, never raw BBCode</p>'];$signature='Visible native signature';af_asb_profile();
    $preview=af_asb_preview($composition,7);$content=af_asb_render($composition);
    check_sig($post['signature']===$content && str_contains($signature,$content) && str_contains($preview['html'],$content),'Composition differs between UserCP, Showthread and Member: '.$template);
}
echo "Four composition contexts passed: same shared content in UserCP preview, postbit and owner profile.\n";
// Retired background: never part of new content, rendered HTML or portable BBCode.
$oldModel=$validated;$oldModel['background']=['enabled'=>true,'url'=>'https://example.org/retired.gif','media_id'=>null];
$clean=af_asb_validate($oldModel,7);
check_sig(!array_key_exists('background',$clean) && !array_key_exists('background',af_asb_seed_model($oldModel)),'Legacy background leaked into editable state');
check_sig(!str_contains(af_asb_render($oldModel),'retired.gif') && !str_contains(af_asb_compile($oldModel),'retired.gif'),'Legacy background leaked into HTML or BBCode');
$oldText='[img]https://example.org/retired.gif[/img]' . "\n" . af_asb_compile($clean);
$legacy=['uid'=>7,'draft_json'=>json_encode($oldModel),'published_json'=>json_encode($oldModel),'published_hash'=>hash('sha256',$oldText),
    'native_signature'=>$oldText,'legacy_backup'=>'Prior ordinary signature','background_backup'=>null];
$plan=af_asb_background_migration_plan($legacy);
check_sig($plan['native']===af_asb_compile($clean) && $plan['fields']['published_hash']===hash('sha256',$plan['native']),'Verified portable background was not repaired with its hash');
check_sig(!array_key_exists('background',json_decode($plan['fields']['published_json'],true)) && !array_key_exists('background',json_decode($plan['fields']['draft_json'],true)),'Migration retains obsolete fields');
check_sig(json_decode($plan['fields']['background_backup'],true)['native_signature']===$oldText && !isset($plan['fields']['legacy_backup']),'Original content/backup was overwritten');
$after=array_replace($legacy,$plan['fields'],['native_signature'=>$plan['native']]);
check_sig(af_asb_background_migration_plan($after)['fields']===[] && af_asb_owned($after,$after['native_signature']),'Migration is not idempotent or detached the signature');
$unowned=$legacy;$unowned['native_signature']='[img]https://example.org/ordinary.gif[/img]';
check_sig(af_asb_background_migration_plan($unowned)['native']===null,'Ordinary IMG signature was modified');
$unprovable=$legacy;$unprovable['native_signature']=$oldText."\nExternally modified";$unprovable['published_hash']=hash('sha256',$unprovable['native_signature']);
$unsafe=af_asb_background_migration_plan($unprovable);
check_sig($unsafe['native']===null && !isset($unsafe['fields']['published_json']) && isset($unsafe['fields']['background_backup']),'Unprovable publication must retain its source for controlled republish');
echo "Retired background passed: clean model/HTML/BBCode, exact old-output proof, original backup, idempotence and safe manual fallback.\n";
$fixtureDb=$db;
$db=new class($legacy) extends SigBuilderDB {
    public array $sql=[];private array $migrationRows;
    function __construct($row){$this->migrationRows=[$row];}
    function query($sql){return new ArrayIterator($this->migrationRows);}
    function fetch_array($q){if(!$q->valid())return false;$row=$q->current();$q->next();return $row;}
    function write_query($sql){$this->sql[]=$sql;}
};
af_asb_migrate_backgrounds();
check_sig(count($db->sql)===1 && str_contains($db->sql[0],'INNER JOIN test_users u') && str_contains($db->sql[0],'u.signature=') && str_contains($db->sql[0],'b.published_hash='),'Native text/hash are not updated atomically');
foreach(['BINARY u.signature=','BINARY b.published_json <=>','BINARY b.draft_json <=>','BINARY b.published_hash <=>'] as $guard)check_sig(str_contains($db->sql[0],$guard),'Migration can overwrite concurrent data: '.$guard);
$db=$fixtureDb;
echo "Migration SQL passed: native text and hash share one guarded update, with old-content compare-and-swap.\n";
$bbcode=$validated;$bbcode['text_format']='bbcode';$bbcode['text']='[b]Текст[/b] <img src=x onerror=alert(1)>';
$checked=af_asb_validate($bbcode,7);$compiled=af_asb_compile($checked);
check_sig(str_contains($compiled,'[b]Текст[/b]') && !str_contains($compiled,'<img src=x') && str_contains($compiled,'&#60;img'),'BBCode compilation loses formatting or permits raw HTML');
check_sig(af_asb_validate(af_asb_decode(json_encode($checked)),7)===$checked,'BBCode format fails draft round trip');
$oldPlain=$checked;unset($oldPlain['text_format']);check_sig(af_asb_validate($oldPlain,7)['text_format']==='plain','Legacy text must remain plain');
$invalid=$checked;$invalid['text_format']='html';reject_sig(fn()=>af_asb_validate($invalid,7));
echo "Editor model passed: typed BBCode format, safe portable HTML escaping and legacy plain-text compatibility.\n";
