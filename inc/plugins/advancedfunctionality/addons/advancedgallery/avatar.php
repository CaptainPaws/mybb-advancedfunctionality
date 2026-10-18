<?php
/** Primary account avatars: Gallery selection feeds the unmodified MyBB upload handler. */
if (!defined('IN_MYBB')) die('No direct access');
require_once dirname(__DIR__,2).'/avatar.php';

function ag_avatar_available(string $purpose='account_avatar'): bool
{
    global $mybb;
    if (!ag_can_view() || (int)($mybb->user['uid']??0)<=0 || empty($mybb->usergroup['canusercp'])) return false;
    if ($purpose==='account_avatar') return !empty($mybb->usergroup['canuploadavatars']);
    return $purpose==='character_avatar' && function_exists('af_apf_is_enabled') && af_apf_is_enabled()
        && function_exists('af_apf_validate_secondary_avatar_upload');
}

function ag_avatar_allowed_mimes(string $purpose='account_avatar'): array
{
    return af_avatar_capabilities($purpose==='character_avatar'?'secondary':'primary')['mimes'];
}

/** Only an approved, owned original; URLs are resolved from server-owned records. */
function ag_avatar_source(int $id,string $purpose='account_avatar'): array
{
    global $mybb;
    if (!ag_avatar_available($purpose)) throw new RuntimeException('Выбор аватара из галереи недоступен.');
    $uid=(int)$mybb->user['uid'];
    $media=ag_get_owned_media($uid,$id);
    if (!$media || (int)$media['uid_owner']!==$uid || $media['status']!=='approved' || ($media['type']!=='local' && !ag_managed_external_image($media))) {
        throw new RuntimeException('Изображение недоступно: выберите одобренный файл из своей галереи.');
    }
    if ($media['type']==='remote') {
        $provider=ag_storage_providers()[$media['provider']];
        if (!$provider->supportsAvatar() || !ag_external_details($id)) throw new RuntimeException('Этот внешний источник не поддерживает выбор аватара.');
        $path=$provider->downloadOriginal($media);
    } else {
        $root=realpath(af_ag_resolve_upload_path().'/af_gallery/'.$uid);
        $path=realpath(af_ag_abs_path((string)$media['storage_path']));
        if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Исходный файл галереи недоступен.');
        }
    }
    $image=@getimagesize($path);
    $mime=$image['mime']??'';
    if (!in_array($mime,ag_avatar_allowed_mimes($purpose),true) || $mime!==$media['mime']) {
        throw new RuntimeException('Этот формат изображения не поддерживается для выбранного аватара. Проверьте типы аватаров ACP и возможности обработки изображений.');
    }
    if ($purpose==='account_avatar' && $mime==='image/gif' && !empty($mybb->settings['maxavatardims'])) {
        $dims=preg_split('/[|x]/i',(string)$mybb->settings['maxavatardims']);
        $w=(int)($dims[0]??0);$h=(int)($dims[1]??0);
        if (($w && $image[0]>$w) || ($h && $image[1]>$h)) {
            throw new RuntimeException('GIF превышает размеры аватара. Уменьшение средствами MyBB может потерять анимацию; выберите GIF подходящего размера.');
        }
    }
    $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
    return ['path'=>$path,'mime'=>$mime,'filename'=>'gallery-avatar.'.$extension,'media_id'=>$id,
        'url_full'=>$media['type']==='remote' ? rtrim((string)$mybb->settings['bburl'],'/').'/gallery.php?action=avatar_original&id='.$id.'&purpose='.rawurlencode($purpose) : ag_resolve_media_url($id,'full')];
}

/** Runs after native CSRF verification and before upload_avatar(); performs no writes. */
function ag_avatar_validate_submission(): void
{
    ag_avatar_validate_context('account_avatar');
}

function ag_avatar_validate_context(string $purpose): void
{
    global $mybb;
    if ($mybb->request_method!=='post' || !$mybb->get_input('ag_avatar_confirm')) return;
    verify_post_check($mybb->get_input('my_post_key'));
    if (!ag_avatar_available($purpose)) error_no_permission();
    try {
        if (!empty($mybb->input[$purpose==='character_avatar'?'remove_secondary_avatar':'remove'])) throw new RuntimeException('Выберите одно действие: установить или удалить аватар.');
        $source=ag_avatar_source($mybb->get_input('ag_avatar_media_id',MyBB::INPUT_INT),$purpose);
        $field=$purpose==='character_avatar'?'secondary_avatar_upload':'avatarupload';
        $file=$_FILES[$field]??[];
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Не удалось передать изображение в форму аватара. Повторите выбор.');
        }
        $originalHash=@hash_file('sha256',$source['path']);
        $uploadedHash=@hash_file('sha256',$file['tmp_name']);
        if (!is_string($originalHash) || !is_string($uploadedHash) || !hash_equals($originalHash,$uploadedHash)) {
            throw new RuntimeException('Изображение изменилось. Выберите файл из галереи повторно.');
        }
        // Trusted MIME/name; native MyBB still validates image type, group limits, size and dimensions.
        $_FILES[$field]['name']=$source['filename'];
        $_FILES[$field]['type']=$source['mime'];
        $_FILES[$field]['size']=filesize($file['tmp_name']);
    } catch (RuntimeException $e) {
        error(htmlspecialchars_uni($e->getMessage()));
    }
}


/** Before APF's usercp_start(5), never invokes the primary-avatar route. */
function ag_character_avatar_validate_submission(): void
{
    global $mybb;
    if ($mybb->get_input('action')==='do_apf_secondary_avatar') ag_avatar_validate_context('character_avatar');
}

/** Private same-origin delivery avoids CORS and keeps the existing native avatar contract. */
function ag_handle_avatar_original(): void
{
    global $mybb;
    $GLOBALS['af_disable_pre_output']=true; // Preserve binary bytes through AF shutdown buffers.
    $purpose=(string)$mybb->get_input('purpose');
    if (!in_array($purpose,['account_avatar','character_avatar'],true)) error_no_permission();
    try {$source=ag_avatar_source((int)$mybb->get_input('id',MyBB::INPUT_INT),$purpose);}
    catch (RuntimeException $e) {http_response_code(422);exit('Изображение недоступно.');}
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    header('Content-Type: '.$source['mime']);header('Content-Length: '.filesize($source['path']));
    readfile($source['path']);exit;
}
