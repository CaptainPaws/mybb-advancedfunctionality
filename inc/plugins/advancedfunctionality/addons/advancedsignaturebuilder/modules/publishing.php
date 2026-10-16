<?php
if (!defined('IN_MYBB')) die('No direct access');
/** Runs before UserDataHandler::verify_signature(), never replaces it. */
function af_asb_start(): void {
    global $mybb,$error;
    $action=(string)$mybb->get_input('action');if (!in_array($action,['editsig','do_editsig'],true)) return;
    $uid=(int)($mybb->user['uid']??0);$api=(string)$mybb->get_input('af_asb_api');
    if ($api!=='') {
        $GLOBALS['af_disable_pre_output']=true; // JSON embeds HTML; response-aware asset hooks must not rewrite it.
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
        try {echo json_encode(['ok'=>true,'data'=>af_asb_api($api,$uid)],JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR);}
        catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?422:403);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_HEX_TAG|JSON_HEX_AMP);}
        exit;
    }
    if ($action!=='do_editsig' || $mybb->request_method!=='post' || (string)$mybb->get_input('af_signature_builder')!=='1') return;
    try {
        if (!af_asb_enabled() || !af_asb_can_edit() || !af_asb_asset_allowed('editor') || !verify_post_check($mybb->get_input('my_post_key'),true)) throw new RuntimeException('Нет доступа или неверный CSRF-токен.');
        if (!af_asb_available()) throw new RuntimeException('Обновите AdvancedSignatureBuilder через ACP.');
        $v=af_asb_decode($mybb->get_input('af_signature_model'));$GLOBALS['af_asb_submitted']=$v;
        $v=af_asb_validate($v,$uid,true,true);
        $native=(string)($mybb->user['signature']??'');
        if ($native!=='' && !af_asb_owned(af_asb_row($uid),$native) && (string)$mybb->get_input('af_sig_confirm_replace')!=='1') throw new InvalidArgumentException('Подтвердите переход: исходная подпись будет сохранена в резервной копии.');
        $compiled=af_asb_compile($v);
        $mybb->input['signature']=$compiled;$GLOBALS['af_asb_pending']=['model'=>$v,'compiled'=>$compiled,'legacy'=>$native];
    }catch(Throwable $e){if ($e instanceof AF_ASB_FieldException) $GLOBALS['af_asb_field_error']=['field'=>$e->field,'message'=>$e->getMessage()];$error=inline_error([htmlspecialchars_uni($e->getMessage())]);$mybb->input['action']='editsig';}
}
/** MyBB has accepted length/images/group rules and CSRF. Keep a recoverable draft before its update. */
function af_asb_publish_start(): void {
    global $mybb,$db;
    if (!isset($GLOBALS['af_asb_pending'])) return;
    $p=$GLOBALS['af_asb_pending'];$uid=(int)$mybb->user['uid'];$row=af_asb_row($uid);
    $fields=['draft_json'=>json_encode($p['model'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    if ($row && af_asb_needs_background_republish($row,$p['legacy']) && ($row['background_backup']??null)===null) {
        if (!$db->field_exists('background_backup','af_asb_signatures')) error('Обновите AdvancedSignatureBuilder через ACP для безопасного сохранения исходника.');
        $fields['background_backup']=af_asb_background_backup($row,$p['legacy']);
    }
    if (!$row || $row['legacy_backup']===null) $fields['legacy_backup']=$p['legacy'];
    foreach ($p['model']['images'] as $image) if ($image['media_id'] && (!function_exists('ag_retain_owned_media') || !ag_retain_owned_media($uid,$image['media_id']))) error('Gallery: не удалось закрепить изображение.');
    af_asb_write($uid,$fields);
}
function af_asb_publish_end(): void {
    global $db,$mybb;
    if (!isset($GLOBALS['af_asb_pending'])) return;
    $p=$GLOBALS['af_asb_pending'];$uid=(int)$mybb->user['uid'];
    $actual=(string)$db->fetch_field($db->simple_select('users','signature',"uid={$uid}"),'signature');
    if ($actual!==$p['compiled']) {error_log('[ASB] Native signature changed by another save hook; builder remains a draft.');return;}
    af_asb_write($uid,['published_json'=>json_encode($p['model'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'published_hash'=>hash('sha256',$actual),'draft_json'=>null]);
    $GLOBALS['af_asb_committed']=true;
}
function af_asb_saved_redirect(): void {
    if (empty($GLOBALS['af_asb_committed'])) return;
    redirect('usercp.php?action=editsig&af_sig_saved=1',(string)($GLOBALS['lang']->redirect_sigupdated??'Подпись сохранена.'));
}
/** Native visibility/suspension/show-sig gates ran before this hook. */
function af_asb_postbit(array &$post): void {
    if (trim((string)($post['signature']??''))==='' || !af_asb_enabled()) return;
    $uid=(int)($post['uid']??0);if ($uid<1) return;
    $html=af_asb_published_html($uid,null,!isset($GLOBALS['mybb']->user['showimages']) || (int)$GLOBALS['mybb']->user['showimages']===1);
    if ($html!=='') $post['signature']=$html;
}
