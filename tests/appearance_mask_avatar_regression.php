<?php
/** Fresh AJAX request: no postbit hooks or author cache. No database/network required. */
define('IN_MYBB',1);
$base=dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/';
function load_avatar_function(string $file,string $name): void {
    preg_match('~function '.preg_quote($name,'~').'\(.*?\n\}\r?\n~s',file_get_contents($file),$match);
    if(!$match)throw new RuntimeException('Missing '.$name);
    eval($match[0]);
}
function avatar_check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function htmlspecialchars_uni($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function get_user($uid){return ['uid'=>$uid,'username'=>'Owner','avatar'=>'uploads/native.png'];}
function af_apf_get_system_value($uid,$key){return $GLOBALS['secondary_path']??'';}
define('AF_APF_SECONDARY_AVATAR_KEY','profile_secondary_avatar');
function af_aa_mask_runtime($pid,$uid){return $GLOBALS['mask']??null;}
function af_aa_personal_active_row($uid){return ['settings_json'=>json_encode($GLOBALS['appearance']??[])];}
function af_aa_personal_decode($json,...$args){return json_decode($json,true);}
function af_aa_preview_abs_url($url){return 'https://forum.test/'.$url;}
function ag_resolve_media_url($id,$variant){return $id?'https://forum.test/uploads/media_'.$id.'.png':'';}
function af_aa_personal_compile_css(...$args){return '';}
function af_aa_mask_css(...$args){return '';}
function af_aa_studio_canvas(...$args){return [];}
function af_aa_postbit_image($values){return $values['image_url']??(!empty($values['image_media_id'])?ag_resolve_media_url($values['image_media_id'],'full'):'');}
$mybb=(object)['settings'=>['bburl'=>'https://forum.test']];
load_avatar_function($base.'advancedprofilefields/advancedprofilefields.php','af_apf_get_secondary_avatar');
load_avatar_function($base.'adaptivethemeframework/adaptivethemeframework.php','af_adaptivethemeframework_resolve_post_secondary_avatar');
load_avatar_function($base.'advancedappearance/postbit_studio.php','af_aa_postbit_apply_images');
load_avatar_function($base.'advancedappearance/post_masks.php','af_aa_mask_runtime_payload');
$GLOBALS['secondary_path']='uploads/avatars/secondary_7_0123456789abcdef0123456789abcdef.png';
$initial=af_adaptivethemeframework_resolve_post_secondary_avatar(['uid'=>7,'username'=>'Owner','af_apf_secondary_avatar_url'=>af_apf_get_secondary_avatar(7)]);
foreach([null,['snapshot'=>[],'mask_topbar_media_id'=>null,'mask_sidebar_media_id'=>null]] as $mask){
    $GLOBALS['mask']=$mask;unset($GLOBALS['af_adaptivethemeframework_author_data']);
    $data=af_aa_mask_runtime_payload(101,7);
    avatar_check($data['sidebar_html']===$initial,'Fresh AJAX lost inherited APF avatar');
    avatar_check(str_contains($data['topbar_html'],'uploads/native.png'),'Primary avatar lost');
}
$GLOBALS['mask']=['snapshot'=>['components'=>['secondary_avatar'=>['image_media_id'=>8]]],'mask_topbar_media_id'=>9,'mask_sidebar_media_id'=>10];
$data=af_aa_mask_runtime_payload(101,7);
avatar_check(str_contains($data['sidebar_html'],'media_10.png')&&str_contains($data['topbar_html'],'media_9.png'),'Explicit mask avatar precedence changed');
$GLOBALS['mask']['mask_sidebar_media_id']=null;
avatar_check(str_contains(af_aa_mask_runtime_payload(101,7)['sidebar_html'],'media_8.png'),'Snapshot avatar precedence changed');
$GLOBALS['mask']=null;$GLOBALS['secondary_path']='';
avatar_check(af_aa_mask_runtime_payload(101,7)['sidebar_html']==='','An absent avatar must remain absent');
echo "Mask avatar regression passed: fresh AJAX inherits APF source, native primary, explicit/snapshot precedence and missing secondary.\n";
