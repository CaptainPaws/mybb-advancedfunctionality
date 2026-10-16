<?php
/** PID snapshots reuse assignments. No post columns, upload handler or consumable inventory. */
if (!defined('IN_MYBB')) die('No direct access');
define('AF_AA_MASK_TARGET','apui_post_mask');
function af_aa_mask_install_schema(): void {
    global $db;
    foreach (['mask_owner_uid'=>'INT UNSIGNED NULL','mask_snapshot_json'=>'MEDIUMTEXT NULL',
        'mask_topbar_media_id'=>'INT UNSIGNED NULL','mask_sidebar_media_id'=>'INT UNSIGNED NULL'] as $field=>$definition) {
        if (!$db->field_exists($field,AF_AA_ASSIGNMENTS_TABLE_NAME)) $db->write_query('ALTER TABLE '.AF_AA_ASSIGNMENTS_TABLE.' ADD '.$field.' '.$definition);
    }
    unset($GLOBALS['af_aa_mask_schema_ready']);
}
function af_aa_mask_available(): bool {
    global $db;
    return $GLOBALS['af_aa_mask_schema_ready']??=($db->table_exists(AF_AA_ASSIGNMENTS_TABLE_NAME)
        && $db->field_exists('mask_snapshot_json',AF_AA_ASSIGNMENTS_TABLE_NAME)
        && $db->field_exists('mask_owner_uid',AF_AA_ASSIGNMENTS_TABLE_NAME)
        && $db->field_exists('mask_topbar_media_id',AF_AA_ASSIGNMENTS_TABLE_NAME)
        && $db->field_exists('mask_sidebar_media_id',AF_AA_ASSIGNMENTS_TABLE_NAME));
}
function af_aa_mask_ids(array $ids): array {return array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));}
function af_aa_mask_media_ids(array $s): array {
    $ids=[];foreach(['author_bg_media_id','name_bg_media_id','plaque_bg_media_id','avatar_media_id'] as $key) if(!empty($s[$key])) $ids[]=$s[$key];
    foreach($s['components']??[] as $fields) foreach(['background_media_id','image_media_id'] as $key) if(!empty($fields[$key])) $ids[]=$fields[$key];
    return af_aa_mask_ids($ids);
}
/** One assignment/post query and one Gallery provider query for the whole page. */
function af_aa_mask_preload(array $pids): void {
    global $db;
    if (!af_aa_is_enabled() || !af_aa_mask_available()) return;
    $cache=&$GLOBALS['af_aa_mask_rows'];if (!is_array($cache)) $cache=[];
    $ids=array_values(array_filter(af_aa_mask_ids($pids),static fn($id)=>!array_key_exists($id,$cache)));
    if (!$ids) return;
    foreach($ids as $id) $cache[$id]=null;
    $q=$db->write_query('SELECT a.*,p.uid AS post_uid,p.visible AS post_visible FROM '.AF_AA_ASSIGNMENTS_TABLE.' a JOIN '.TABLE_PREFIX.'posts p ON p.pid=a.entity_id'
        ." WHERE a.entity_type='post' AND a.target_key='".AF_AA_MASK_TARGET."' AND a.entity_id IN (".implode(',',$ids).')');
    $media=[];
    while($row=$db->fetch_array($q)) {
        if ((int)$row['mask_owner_uid']!==(int)$row['post_uid']) continue;
        try {$s=af_aa_personal_decode((string)$row['mask_snapshot_json'],(int)$row['post_uid'],true,false,true);}catch(AF_AA_PersonalException $e){continue;}
        $row['snapshot']=$s;$cache[(int)$row['entity_id']]=$row;
        $media=array_merge($media,af_aa_mask_media_ids($s),[(int)$row['mask_topbar_media_id'],(int)$row['mask_sidebar_media_id']]);
    }
    if (function_exists('ag_preload_media_ids')) ag_preload_media_ids($media);
}
function af_aa_mask_row(int $pid): ?array {af_aa_mask_preload([$pid]);return $GLOBALS['af_aa_mask_rows'][$pid]??null;}
function af_aa_mask_require_edit(int $pid): array {
    global $mybb;
    $uid=(int)($mybb->user['uid']??0);af_aa_personal_actor($uid);
    if (!af_aa_mask_available()) throw new AF_AA_PersonalException('Upgrade AdvancedAppearance first.',503);
    $post=get_post($pid);if (!$post || (int)$post['uid']!==$uid || (int)$post['visible']===-1) throw new AF_AA_PersonalException('You cannot change this post mask.',403);
    $thread=get_thread((int)$post['tid']);$forum=$thread?get_forum((int)$thread['fid']):[];
    if (!$thread || !$forum || $forum['type']!=='f' || !empty($mybb->user['suspendposting'])) throw new AF_AA_PersonalException('Post editing is not permitted.',403);
    $permissions=forum_permissions((int)$forum['fid']);
    if (empty($permissions['canview']) || empty($permissions['canviewthreads']) || check_forum_password((int)$forum['fid'],0,true)) throw new AF_AA_PersonalException('Forum access denied.',403);
    $mod=is_moderator((int)$forum['fid'],'caneditposts');
    if (!$mod && (empty($forum['open']) || empty($permissions['caneditposts']) || (int)$thread['closed']===1
        || ((int)($mybb->usergroup['edittimelimit']??0)>0 && (int)$post['dateline']<TIME_NOW-60*(int)$mybb->usergroup['edittimelimit']))) throw new AF_AA_PersonalException('Post editing is not permitted.',403);
    foreach ([$post,$thread] as $row) {
        if ((int)$row['visible']===0 && !is_moderator((int)$forum['fid'],'canviewunapprove')
            && !( !empty($mybb->settings['showownunapproved']) && (int)$row['uid']===$uid)) throw new AF_AA_PersonalException('Unapproved post access denied.',403);
        if ((int)$row['visible']===-1 && !is_moderator((int)$forum['fid'],'canviewdeleted')) throw new AF_AA_PersonalException('Deleted thread access denied.',403);
        if ((int)$row['visible']<-1 && (int)$row['uid']!==$uid) throw new AF_AA_PersonalException('Draft access denied.',403);
    }
    return $post;
}
function af_aa_mask_require_create(int $fid,int $tid): void {
    global $mybb;
    af_aa_personal_actor((int)$mybb->user['uid']);
    $thread=$tid?get_thread($tid):[];if($tid && !$thread) throw new AF_AA_PersonalException('Thread not found.',404);
    if($thread) $fid=(int)$thread['fid'];$forum=get_forum($fid);$p=forum_permissions($fid);
    if (!$forum || $forum['type']!=='f' || empty($forum['open']) || !empty($mybb->user['suspendposting'])
        || empty($p['canview']) || empty($p['canviewthreads']) || empty($p[$tid?'canpostreplys':'canpostthreads'])
        || check_forum_password($fid,0,true) || ($thread && (int)$thread['closed']===1 && !is_moderator($fid,'canpostclosedthreads'))) throw new AF_AA_PersonalException('Posting is not permitted.',403);
    if ($thread && ((int)$thread['visible']===-1 || ((int)$thread['visible']===0 && !is_moderator($fid,'canviewunapprove')
        && !( !empty($mybb->settings['showownunapproved']) && (int)$thread['uid']===(int)$mybb->user['uid'])))) throw new AF_AA_PersonalException('Thread access denied.',403);
}
/** Resolve the source now; clients cannot submit snapshots, owners, canonical keys or CSS. */
function af_aa_mask_prepare(int $uid,int $pid,string $json): array {
    af_aa_personal_actor($uid);if($pid) af_aa_mask_require_edit($pid);
    if (strlen($json)>2048) throw new AF_AA_PersonalException('Mask request too large.',422);
    try {$v=json_decode($json,false,5,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new AF_AA_PersonalException('Invalid mask JSON.',422);}
    if (!$v instanceof stdClass || array_diff(array_keys((array)$v),['mode','preset_id','topbar_avatar_media_id','sidebar_avatar_media_id'])) throw new AF_AA_PersonalException('Unknown mask fields.',422);
    $v=(array)$v;$mode=$v['mode']??'';
    if (!in_array($mode,['set','clear'],true)) throw new AF_AA_PersonalException('Invalid mask operation.',422);
    if($mode==='clear') return ['mode'=>'clear'];
    af_aa_personal_target('postbit');
    $source=$v['preset_id']??0;
    if ($source==='keep') {
        $existing=$pid?af_aa_mask_row($pid):null;if(!$existing) throw new AF_AA_PersonalException('No saved snapshot.',422);
        $source=(int)$existing['preset_id'];$s=$existing['snapshot'];
    } else {
        if(!is_int($source)||$source<0||$source>4294967295) throw new AF_AA_PersonalException('Invalid source preset.',422);
        if($source===0) $s=[];
        else {$row=af_aa_personal_owned_row($uid,$source);if($row['target_key']!=='postbit'||$row['publication_state']!=='ready'||!$row['enabled']) throw new AF_AA_PersonalException('Preset is not ready.',422);$s=af_aa_personal_decode($row['settings_json'],$uid,true,false);}
    }
    // A mask never inherits an unfinished draft and never accepts arbitrary JSON from the picker.
    af_aa_personal_decode(af_aa_personal_encode($s),$uid,false,true,true);
    $out=['mode'=>'set','source_preset_id'=>$source,'snapshot'=>$s];
    foreach(['topbar','sidebar'] as $role) {
        $id=$v[$role.'_avatar_media_id']??null;
        if($id!==null && (!is_int($id)||$id<=0||$id>4294967295)) throw new AF_AA_PersonalException('Invalid avatar media ID.',422);
        if($id!==null) af_aa_personal_decode('{"avatar_media_id":'.$id.'}',$uid,false);
        $out[$role.'_avatar_media_id']=$id;
    }
    // Unlike a workshop draft, a publishable mask requires approved raster media.
    foreach(array_merge(af_aa_mask_media_ids($s),[$out['topbar_avatar_media_id'],$out['sidebar_avatar_media_id']]) as $id) if($id) {
        $row=ag_get_owned_media($uid,(int)$id);if(!$row || $row['status']!=='approved') throw new AF_AA_PersonalException('Mask media must be approved.',422);
    }
    return $out;
}
/** Called only after the real MyBB handler successfully wrote a post/draft. */
function af_aa_mask_commit(int $pid,int $uid,array $prepared): void {
    global $db;
    $post=get_post($pid);if(!$post || (int)$post['uid']!==$uid) throw new AF_AA_PersonalException('Post ownership changed.',409);
    af_aa_personal_transaction($uid,static function()use($pid,$uid,$post,$prepared,$db){
        $where="entity_type='post' AND entity_id='{$pid}' AND target_key='".AF_AA_MASK_TARGET."'";
        if($prepared['mode']==='clear') {$db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME,$where);return;}
        $s=$prepared['snapshot'];af_aa_personal_decode(af_aa_personal_encode($s),$uid,true,true,true);
        foreach(['topbar','sidebar'] as $role) if($prepared[$role.'_avatar_media_id']!==null && !ag_retain_owned_media($uid,$prepared[$role.'_avatar_media_id'])) throw new AF_AA_PersonalException('Avatar could not be retained.',409);
        $source=(int)$prepared['source_preset_id'];$enabled=(int)$post['visible']===-2?0:1;$json=$db->escape_string(af_aa_personal_encode($s));
        $top=$prepared['topbar_avatar_media_id']===null?'NULL':(int)$prepared['topbar_avatar_media_id'];$side=$prepared['sidebar_avatar_media_id']===null?'NULL':(int)$prepared['sidebar_avatar_media_id'];
        $db->write_query('INSERT INTO '.AF_AA_ASSIGNMENTS_TABLE." (entity_type,entity_id,target_key,preset_id,is_enabled,created_at,updated_at,mask_owner_uid,mask_snapshot_json,mask_topbar_media_id,mask_sidebar_media_id)
            VALUES ('post',{$pid},'".AF_AA_MASK_TARGET."',{$source},{$enabled},".TIME_NOW.','.TIME_NOW.",{$uid},'{$json}',{$top},{$side})
            ON DUPLICATE KEY UPDATE preset_id={$source},is_enabled={$enabled},updated_at=".TIME_NOW.",mask_owner_uid={$uid},mask_snapshot_json='{$json}',mask_topbar_media_id={$top},mask_sidebar_media_id={$side}");
    });
    unset($GLOBALS['af_aa_mask_rows'][$pid]);
}
function af_aa_mask_validate_handler($handler): void {
    global $mybb;
    $script=defined('THIS_SCRIPT')?THIS_SCRIPT:'';
    if(!in_array($script,['newthread.php','newreply.php','editpost.php','xmlhttp.php'],true)) return;
    if($script==='xmlhttp.php' && ($mybb->get_input('action')!=='edit_post'||$mybb->get_input('do')!=='update_post')) return;
    unset($GLOBALS['af_aa_mask_pending'][spl_object_id($handler)]);
    $uid=(int)($mybb->user['uid']??0);$pid=(int)($handler->data['pid']??0);
    $raw=$mybb->input['af_aa_mask']??null;
    if($raw===null || $raw==='') {
        // Server MyBB drafts remember their inactive snapshot even after a different browser is used.
        if ($pid && $handler->method==='insert') {$row=af_aa_mask_row($pid);if($row && !$row['is_enabled'] && (int)$row['mask_owner_uid']===$uid) $raw=json_encode(['mode'=>'set','preset_id'=>'keep','topbar_avatar_media_id'=>$row['mask_topbar_media_id']===null?null:(int)$row['mask_topbar_media_id'],'sidebar_avatar_media_id'=>$row['mask_sidebar_media_id']===null?null:(int)$row['mask_sidebar_media_id']]);}
        if($raw===null||$raw==='') return;
    }
    try {
        if(!is_string($raw) || $mybb->request_method!=='post' || !verify_post_check($mybb->get_input('my_post_key'),true)) throw new AF_AA_PersonalException('Invalid mask CSRF/request.',403);
        if(!af_aa_mask_available()) throw new AF_AA_PersonalException('Upgrade AdvancedAppearance first.',503);
        if($handler->method==='insert' && isset($handler->data['uid']) && (int)$handler->data['uid']!==$uid) throw new AF_AA_PersonalException('Mask author mismatch.',403);
        $prepared=af_aa_mask_prepare($uid,$pid,$raw);
        $GLOBALS['af_aa_mask_pending'][spl_object_id($handler)]=['uid'=>$uid,'mask'=>$prepared];
    } catch(AF_AA_PersonalException $e) {unset($GLOBALS['af_aa_mask_pending'][spl_object_id($handler)]);$handler->set_error('Маска: '.$e->getMessage());}
}
function af_aa_mask_after_handler($handler): void {
    $key=spl_object_id($handler);$pending=$GLOBALS['af_aa_mask_pending'][$key]??null;unset($GLOBALS['af_aa_mask_pending'][$key]);
    if(!$pending || !$handler->pid) return;
    try {af_aa_mask_commit((int)$handler->pid,$pending['uid'],$pending['mask']);}
    catch(Throwable $e) {error_log('[AdvancedAppearance] post saved; mask persistence failed for pid '.(int)$handler->pid);$handler->return_values['af_aa_mask_warning']='Сообщение сохранено, но маска не применена. Откройте редактирование для повторной попытки.';}
}
function af_aa_mask_after_merge($handler): void {
    // Native automatic merge keeps the old PID and its existing appearance; never overwrite it implicitly.
    unset($GLOBALS['af_aa_mask_pending'][spl_object_id($handler)]);
    $draft=(int)($handler->data['pid']??0);if($draft && $draft!==(int)$handler->pid) af_aa_mask_delete_post($draft);
}
function af_aa_mask_preload_postbit(array &$post): void {
    global $pids;
    if(defined('THIS_SCRIPT')&&!in_array(THIS_SCRIPT,['showthread.php','newreply.php'],true))return;
    $ids=[(int)($post['pid']??0)];if(defined('THIS_SCRIPT')&&THIS_SCRIPT==='showthread.php'&&is_string($pids)) {preg_match_all('/\d+/',$pids,$m);$ids=array_merge($ids,$m[0]);}
    af_aa_mask_preload($ids);
}
function af_aa_mask_runtime(int $pid,int $uid): ?array {
    $row=af_aa_mask_row($pid);
    if(!$row || !$row['is_enabled'] || (int)$row['mask_owner_uid']!==$uid || (int)$row['post_visible']===-2) return null;
    return $row;
}
function af_aa_mask_css(int $pid,int $uid,array $s): string {
    $row=af_aa_mask_row($pid);if(!empty($row['mask_topbar_media_id']))$s['avatar_media_id']=(int)$row['mask_topbar_media_id'];
    return str_replace('.af-aa-postbit-user-'.$uid,'#post_'.$pid.'.af-aa-postbit-mask-'.$pid,af_aa_personal_compile_css($uid,$s));
}
function af_aa_mask_postbit(array &$post): void {
    if(defined('THIS_SCRIPT')&&!in_array(THIS_SCRIPT,['showthread.php','newreply.php'],true))return;
    $pid=(int)($post['pid']??0);$uid=(int)($post['uid']??0);$row=af_aa_mask_runtime($pid,$uid);if(!$row)return;
    // Same ATF template/classes/slots. Only the scope token changes so a live UID preset cannot leak into a snapshot.
    $post['af_aa_user_class']='af-aa-postbit-mask-'.$pid;
    $post['af_aa_post_appearance']=['pid'=>$pid,'owner_uid'=>$uid,'source_preset_id'=>(int)$row['preset_id'],'settings'=>$row['snapshot']];
    $top=(int)($row['mask_topbar_media_id']??0);$side=(int)($row['mask_sidebar_media_id']??0);
    $post['af_atf_primary_avatar']=(string)($post['useravatar']??'');
    af_aa_postbit_apply_images($post,$row['snapshot']);
    foreach(['topbar'=>$top,'sidebar'=>$side] as $role=>$id) if($id && function_exists('ag_resolve_media_url') && ($url=ag_resolve_media_url($id,'full'))!=='') {
        $post[$role==='topbar'?'af_atf_primary_avatar':'af_atf_secondary_avatar']='<img src="'.htmlspecialchars_uni($url).'" alt="'.htmlspecialchars_uni($post['username']??'').'" loading="lazy" decoding="async" />';
    }
    $config=af_aa_studio_canvas($uid,$row['snapshot']);
    $style='<style data-af-aa-mask-css="'.$pid.'">'.af_aa_mask_css($pid,$uid,$row['snapshot']).'</style>';
    $effect='<script type="application/json" data-af-appearance-post-effects-config>'.json_encode((object)$config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
    $post['af_atf_slots']['post.before_body']=($post['af_atf_slots']['post.before_body']??'').$style.$effect;
}
function af_aa_mask_delete_post($pid): void {
    global $db;if(!af_aa_mask_available())return;$pid=(int)$pid;
    $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id='{$pid}' AND target_key='".AF_AA_MASK_TARGET."'");unset($GLOBALS['af_aa_mask_rows'][$pid]);
}
function af_aa_mask_delete_thread_start($tid): void {
    global $db;if(!af_aa_mask_available())return;$ids=[];$q=$db->simple_select('posts','pid','tid='.(int)$tid);while($r=$db->fetch_array($q))$ids[]=(int)$r['pid'];$GLOBALS['af_aa_mask_deleted_threads'][(int)$tid]=$ids;
}
function af_aa_mask_delete_thread_end($tid): void {
    global $db;$ids=$GLOBALS['af_aa_mask_deleted_threads'][(int)$tid]??[];unset($GLOBALS['af_aa_mask_deleted_threads'][(int)$tid]);if(!$ids)return;
    $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND target_key='".AF_AA_MASK_TARGET."' AND entity_id IN (".implode(',',$ids).')');foreach($ids as $pid)unset($GLOBALS['af_aa_mask_rows'][$pid]);
}
/** Native Quick Edit replaces the message contents, so preserve ATF's original inner wrapper. */
function af_aa_mask_quick_edit_response(): void {
    global $post,$mybb;
    if(!empty($mybb->settings['af_adaptivethemeframework_enabled']) && isset($post['message'])) $post['message']='<div class="atf-post__message-body">'.$post['message'].'</div>';
}
function af_aa_mask_register_hooks(): void {
    global $plugins;
    foreach(['datahandler_post_validate_post','datahandler_post_validate_thread'] as $hook)$plugins->add_hook($hook,'af_aa_mask_validate_handler',80);
    foreach(['datahandler_post_insert_post_end','datahandler_post_insert_thread_end','datahandler_post_update_end'] as $hook)$plugins->add_hook($hook,'af_aa_mask_after_handler',80);
    $plugins->add_hook('datahandler_post_insert_merge','af_aa_mask_after_merge',80);
    $plugins->add_hook('postbit','af_aa_mask_preload_postbit',4);$plugins->add_hook('postbit','af_aa_mask_postbit',1110);
    $plugins->add_hook('class_moderation_delete_post','af_aa_mask_delete_post',80);
    $plugins->add_hook('class_moderation_delete_thread_start','af_aa_mask_delete_thread_start',80);
    $plugins->add_hook('class_moderation_delete_thread','af_aa_mask_delete_thread_end',80);
    $plugins->add_hook('xmlhttp_update_post','af_aa_mask_quick_edit_response',80);
    $plugins->add_hook('pre_output_page','af_aa_mask_editor_output',2);
}
function af_aa_mask_runtime_payload(int $pid,int $uid): array {
    $row=af_aa_mask_runtime($pid,$uid);$user=get_user($uid);$native=af_aa_preview_abs_url((string)($user['avatar']??''));
    if($native==='') $native=rtrim((string)($GLOBALS['mybb']->settings['bburl']??''),'/').'/images/default_avatar.png';
    $active=af_aa_personal_active_row($uid);$s=[];
    if($row)$s=$row['snapshot'];elseif($active) $s=af_aa_personal_decode($active['settings_json'],$uid,true,false,true);
    $top=(int)($row['mask_topbar_media_id']??0);$side=(int)($row['mask_sidebar_media_id']??0);
    $topUrl=$top?ag_resolve_media_url($top,'full'):'';
    $topHtml='<img src="'.htmlspecialchars_uni($topUrl?:$native).'" alt="'.htmlspecialchars_uni($user['username']??'').'" loading="lazy" decoding="async" />';
    $sideHtml=function_exists('af_adaptivethemeframework_resolve_post_secondary_avatar')?af_adaptivethemeframework_resolve_post_secondary_avatar(['uid'=>$uid,'username'=>$user['username']??'']):'';
    if($side && ($url=ag_resolve_media_url($side,'full'))!=='') $sideHtml='<img src="'.htmlspecialchars_uni($url).'" alt="'.htmlspecialchars_uni($user['username']??'').'" loading="lazy" decoding="async" />';
    $avatarPost=['username'=>$user['username']??'','af_atf_primary_avatar'=>$topHtml,'af_atf_secondary_avatar'=>$sideHtml];
    af_aa_postbit_apply_images($avatarPost,$s);
    if (!$top) $topHtml=$avatarPost['af_atf_primary_avatar'];
    if (!$side) $sideHtml=$avatarPost['af_atf_secondary_avatar'];
    $css=$row?af_aa_mask_css($pid,$uid,$s):str_replace('.af-aa-postbit-user-'.$uid,'#post_'.$pid.'.af-aa-postbit-user-'.$uid,af_aa_personal_compile_css($uid,$s));
    return ['pid'=>$pid,'masked'=>(bool)$row,'css'=>$css,'canvas'=>af_aa_studio_canvas($uid,$s),'topbar_html'=>$topHtml,'sidebar_html'=>$sideHtml];
}
function af_aa_mask_api(): void {
    global $mybb;
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
    try {
        $raw=file_get_contents('php://input');if(strlen($raw)>8192)throw new AF_AA_PersonalException('Request too large.',413);
        try {$in=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new AF_AA_PersonalException('Invalid request.',422);}
        if(!is_array($in)||$mybb->request_method!=='post'||!is_string($in['my_post_key']??null)||!verify_post_check($in['my_post_key'],true))throw new AF_AA_PersonalException('Invalid CSRF/request.',403);
        foreach(['pid','fid','tid'] as $key)if(isset($in[$key])&&(!is_int($in[$key])||$in[$key]<0||$in[$key]>4294967295))throw new AF_AA_PersonalException('Invalid identifier.',422);
        $uid=(int)($mybb->user['uid']??0);af_aa_personal_actor($uid);
        if(!af_aa_mask_available())throw new AF_AA_PersonalException('Upgrade AdvancedAppearance first.',503);
        $pid=$in['pid']??0;if($pid)af_aa_mask_require_edit($pid);else af_aa_mask_require_create($in['fid']??0,$in['tid']??0);
        $op=$in['operation']??'load';
        if($op==='load') {
            $row=$pid?af_aa_mask_row($pid):null;$presets=[];foreach(af_aa_personal_list($uid,'postbit')['presets'] as $p)if($p['enabled']&&$p['publication_state']==='ready')$presets[]=['id'=>$p['id'],'title'=>$p['title']];
            $result=['presets'=>$presets,'mask'=>$row?['source_preset_id'=>(int)$row['preset_id'],'topbar_avatar_media_id'=>$row['mask_topbar_media_id']===null?null:(int)$row['mask_topbar_media_id'],'sidebar_avatar_media_id'=>$row['mask_sidebar_media_id']===null?null:(int)$row['mask_sidebar_media_id']]:null];
        } elseif($op==='preview') {
            if(!is_string($in['mask_json']??null))throw new AF_AA_PersonalException('Mask payload required.',422);
            $p=af_aa_mask_prepare($uid,$pid,$in['mask_json']);$s=$p['snapshot']??[];
            if(!empty($p['topbar_avatar_media_id']))$s['avatar_media_id']=$p['topbar_avatar_media_id'];
            $result=['css'=>af_aa_personal_compile_css($uid,$s,true),'canvas'=>af_aa_studio_canvas($uid,$s),
                'sidebar_url'=>!empty($p['sidebar_avatar_media_id'])?ag_resolve_media_url($p['sidebar_avatar_media_id'],'full'):''];
        } elseif($op==='runtime'&&$pid) $result=af_aa_mask_runtime_payload($pid,$uid);
        else throw new AF_AA_PersonalException('Unknown mask operation.',422);
        echo json_encode(['ok'=>true,'data'=>$result],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    }catch(AF_AA_PersonalException $e){http_response_code($e->getCode()?:422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_HEX_TAG|JSON_HEX_AMP);}
    catch(Throwable $e){error_log('[AdvancedAppearance] mask API failed');http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Mask request failed.']);}exit;
}
function af_aa_mask_editor_output(string &$page): void {
    global $mybb;
    $script=defined('THIS_SCRIPT')?THIS_SCRIPT:'';$uid=(int)($mybb->user['uid']??0);
    if(defined('IN_ADMINCP')||!af_aa_is_enabled()||!af_aa_mask_available()||!$uid||!empty($mybb->usergroup['isbannedgroup'])||empty($mybb->settings['af_advancededitor_enabled'])
        || !in_array($script,['newthread.php','newreply.php','editpost.php','showthread.php'],true)||!str_contains($page,'</body>'))return;
    $base=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedappearance/assets/';
    $version=(string)max(filemtime(__DIR__.'/assets/post-masks.js'),filemtime(__DIR__.'/assets/post-mask-dialog.js'),filemtime(__DIR__.'/assets/post-masks.css'));
    $button=['cmd'=>'af_post_mask','label'=>'Маска','title'=>'Маска сообщения','iconClass'=>'fa-solid fa-masks-theater','handler'=>'post_mask','capability'=>'post-mask'];
    $runtime=['activation'=>'click','requires'=>[],'js'=>[$base.'post-mask-dialog.js?v='.$version],'css'=>[$base.'post-masks.css?v='.$version]];
    $GLOBALS['af_ae_external_buttons']['af_post_mask']=$button;$GLOBALS['af_ae_external_capabilities']['post-mask']=$runtime;
    if(function_exists('af_advancededitor_shell_attach_button'))$page=af_advancededitor_shell_attach_button($page,$button);
    $pending=$mybb->input['af_aa_mask']??'';if(!is_string($pending)||strlen($pending)>2048)$pending='';
    $seed=['uid'=>$uid,'csrf'=>$mybb->post_code,'endpoint'=>'apstudio.php?api=mask','pending'=>$pending];
    $markup=function_exists('af_adaptivethemeframework_post_element')?af_aa_studio_preview_markup($uid):'';
    $tags='<template id="af-aa-mask-preview-template" data-aa-gallery-picker>'.$markup.'</template><script type="application/json" id="af-aa-mask-seed">'.json_encode($seed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>'
        .'<script>window.afAeCapabilities=window.afAeCapabilities||{};window.afAeCapabilities["post-mask"]='.json_encode($runtime,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';window.afAeButtons=window.afAeButtons||[];window.afAeButtons.push('.json_encode($button,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');</script>'
        .'<script src="'.htmlspecialchars_uni($base.'post-masks.js?v='.$version).'" defer></script>';
    $atf='/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css';
    if(!str_contains($page,'assets/surfaces/postbit.css'))$tags.='<link rel="stylesheet" href="'.htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').$atf).'" />';
    $page=str_replace('</body>',$tags.'</body>',$page);
}

/** Public per-post provider for ATF component contexts; no viewer-owned identity inference. */
function af_aa_get_post_appearance_context(int $pid,int $uid): ?array {
    if(defined('THIS_SCRIPT')&&!in_array(THIS_SCRIPT,['showthread.php','newreply.php'],true))return null;
    $row=af_aa_mask_runtime($pid,$uid);if(!$row)return null;
    return ['pid'=>$pid,'owner_uid'=>$uid,'source_preset_id'=>(int)$row['preset_id'],'settings'=>$row['snapshot'],
        'topbar_avatar_media_id'=>$row['mask_topbar_media_id']===null?null:(int)$row['mask_topbar_media_id'],
        'sidebar_avatar_media_id'=>$row['mask_sidebar_media_id']===null?null:(int)$row['mask_sidebar_media_id']];
}
