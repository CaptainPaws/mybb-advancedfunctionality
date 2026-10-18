<?php
/** Isolated forum boundary; real AA/Gallery/ATF/Editor and native JS are used. */
function get_post($pid){global $db;return $db->fetch_array($db->simple_select('posts','*','pid='.(int)$pid))?:[];}
function get_thread($tid){global $db;return $db->fetch_array($db->simple_select('threads','*','tid='.(int)$tid))?:[];}
function get_forum($fid){global $db;return $db->fetch_array($db->simple_select('forums','*','fid='.(int)$fid))?:[];}
function forum_permissions($fid){return array_replace(['canview'=>1,'canviewthreads'=>1,'caneditposts'=>1,'canpostreplys'=>1,'canpostthreads'=>1],$GLOBALS['mask_fixture_permissions']??[]);}
function check_forum_password(...$args){return !empty($GLOBALS['mask_fixture_password']);}
function is_moderator($fid,$permission){return !empty($GLOBALS['mask_fixture_moderator']);}
// Profile Fields is an external data boundary; exercise ATF's real query-free renderer.
function af_apf_get_secondary_avatar(int $uid): string {
    return rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/uploads/avatars/secondary_'.$uid.'_0123456789abcdef0123456789abcdef.png';
}
class MaskFixtureHandler {
    public string $method='insert';public array $data=[], $return_values=[], $errors=[];public int $pid=0;
    public function set_error($error){$this->errors[]=$error;}
}
function appearance_masks_markup(array $record): string {
    $pid=(int)$record['pid'];$post=$record+['username'=>'Fixture '.(int)$record['uid'],'useravatar'=>'<img src="/images/default_avatar.png" alt="Native" />',
        'af_apf_secondary_avatar_url'=>af_apf_get_secondary_avatar((int)$record['uid']),
        'af_atf_primary_avatar'=>'<img src="/images/default_avatar.png" alt="Native" />','af_atf_secondary_avatar'=>'<img src="/images/default_avatar.png" alt="Secondary" />','af_atf_slots'=>['post.before_body'=>'']];
    af_aa_collect_uid_from_postbit($post);$post['af_atf_secondary_avatar']=af_adaptivethemeframework_resolve_post_secondary_avatar($post);af_aa_studio_postbit_avatar($post);af_aa_mask_postbit($post);
    $source=str_replace('appearance_preview',(string)$pid,af_aa_studio_preview_markup((int)$record['uid']));
    $source=str_replace('af-aa-postbit-user-'.(int)$record['uid'],$post['af_aa_user_class'],$source);
    foreach(['primary','secondary'] as $role) $source=preg_replace('~(<div class="atf-post__'.($role==='primary'?'primary-avatar-media':'secondary-avatar').'">).*?(</div>)~s','$1'.$post['af_atf_'.$role.'_avatar'].'$2',$source);
    $source=str_replace('<div class="atf-post__slot atf-post__slot--before">','<div class="atf-post__slot atf-post__slot--before">'.$post['af_atf_slots']['post.before_body'],$source);
    $source=preg_replace('~(<div class="atf-post__message-body">).*?(</div>)~s','$1'.htmlspecialchars_uni($record['message']).'$2',$source);
    return str_replace('</article>','<a href="#" id="quick_edit_post_'.$pid.'" class="quick_edit_button">Быстрая правка</a></article>',$source);
}
function appearance_masks_http(): void {
    global $mybb,$db,$headerinclude,$lang;
    $mybb->input=$_POST+$_GET;$mybb->settings['af_advancededitor_enabled']=1;$mybb->settings['af_adaptivethemeframework_enabled']=1;
    $mybb->user['suspendposting']=0;$mybb->usergroup['edittimelimit']=0;
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/apstudio.php'){af_aa_render_apstudio_page();exit;}
    $pid=(int)($_GET['pid']??$_POST['pid']??0);
    if($path==='/xmlhttp.php'&&($_GET['do']??'')==='get_post'){af_aa_mask_require_edit($pid);header('Content-Type: application/json');echo json_encode(get_post($pid)['message']);exit;}
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $handler=new MaskFixtureHandler();$handler->method=$path==='/editpost.php'||$path==='/xmlhttp.php'?'update':'insert';
        $handler->data=['uid'=>7,'tid'=>10,'fid'=>1,'pid'=>$handler->method==='update'?$pid:0];
        af_aa_mask_validate_handler($handler);
        $text=(string)($_POST['value']??$_POST['message']??'');if($text==='INVALID')$handler->errors[]='Native text validation failed';
        if($handler->errors){header('Content-Type: application/json');echo json_encode(['errors'=>$handler->errors]);exit;}
        if($handler->method==='update'){$handler->pid=$pid;$db->update_query('posts',['message'=>$db->escape_string($text)],'pid='.$pid);}
        else{$handler->pid=(int)$db->insert_query('posts',['uid'=>7,'tid'=>10,'fid'=>1,'visible'=>1,'dateline'=>TIME_NOW,'message'=>$db->escape_string($text)]);}
        af_aa_mask_after_handler($handler);header('Content-Type: application/json');
        if($path==='/xmlhttp.php'){$post=['message'=>htmlspecialchars_uni($text)];$GLOBALS['post']=$post;af_aa_mask_quick_edit_response();echo json_encode(['message'=>$GLOBALS['post']['message'],'editedmsg'=>'Edited']);}
        else echo json_encode(['data'=>appearance_masks_markup(get_post($handler->pid)),'pid'=>$handler->pid]);exit;
    }
    require_once AF_ADDONS.'advancededitor/advancededitor.php';
    $lang=new class {public function load(...$args){} };$form='';
    if($path==='/showthread.php') {
        $GLOBALS['pids']="pid IN('101','102','103')";af_aa_mask_preload([101,102,103]);$posts='';foreach([101,102,103] as $id)$posts.=appearance_masks_markup(get_post($id));
        $form='<div id="posts">'.$posts.'</div><form id="quick_reply_form"><input type="hidden" name="tid" value="10"><input type="hidden" name="my_post_key" value="appearance-fixture-key"><textarea name="message" id="message"></textarea><button type="submit" id="quick_reply_submit">Отправить</button></form><div id="quickreply_spinner" hidden></div>';
    } else {
        $form='<form id="posting" method="post"><input type="hidden" name="tid" value="10"><input type="hidden" name="fid" value="1"><input type="hidden" name="my_post_key" value="appearance-fixture-key">'.($pid?'<input type="hidden" name="pid" value="'.$pid.'">':'').'<textarea name="message" id="message">'.htmlspecialchars_uni($pid?get_post($pid)['message']:'').'</textarea><button type="submit">Отправить</button></form>';
    }
    $native='<script src="/jscripts/jquery.js"></script><script src="/jscripts/jquery.plugins.min.js"></script><script src="/jscripts/jeditable/jeditable.min.js"></script><script>var cookiePrefix="",cookieDomain="",cookiePath="/",cookieSecureFlag="",rootpath="http://127.0.0.1:8100",use_xmlhttprequest=1;var spinner_image="/images/default_avatar.png";var thread_deleted=0,thread_status=0,tid=10;var my_post_key="appearance-fixture-key",visible_replies=3,allowEditReason=1,spinner="…";var lang=new Proxy({save_changes:"Сохранить изменения",cancel_edit:"Отмена правки"},{get:(t,k)=>t[k]||k});window.MyBBSettings={uid:7};</script><script src="/jscripts/general.js"></script><script>Object.assign(lang,{save_changes:"Сохранить изменения",cancel_edit:"Отмена правки"});</script><script src="/jscripts/thread.js"></script>';
    $page='<!doctype html><html><head>'.$headerinclude.$native.'<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css">'.af_aa_render_page_css([7]).'</head><body class="atf-active">'.$form.'</body></html>';
    af_aa_mask_editor_output($page);af_advancededitor_pre_output($page);output_page($page);exit;
}
