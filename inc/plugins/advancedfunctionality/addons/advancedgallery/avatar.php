<?php
/** Primary account avatars: Gallery selection feeds the unmodified MyBB upload handler. */
if (!defined('IN_MYBB')) die('No direct access');

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
    global $cache;
    if ($purpose==='character_avatar') return ['image/jpeg','image/png','image/webp','image/gif'];
    $out=[];
    foreach ((array)$cache->read('attachtypes') as $type) {
        if (!empty($type['avatarfile']) && is_member($type['groups']) && (int)$type['maxsize']>0
            && in_array($type['mimetype'],['image/jpeg','image/png','image/gif'],true)) $out[]=$type['mimetype'];
    }
    return array_values(array_unique($out));
}

/** Only an approved, owned local original; never accept a browser-supplied URL/path. */
function ag_avatar_source(int $id,string $purpose='account_avatar'): array
{
    global $mybb;
    if (!ag_avatar_available($purpose)) throw new RuntimeException('Выбор аватара из галереи недоступен.');
    $uid=(int)$mybb->user['uid'];
    $media=ag_get_owned_media($uid,$id);
    if (!$media || (int)$media['uid_owner']!==$uid || $media['status']!=='approved' || $media['type']!=='local') {
        throw new RuntimeException('Изображение недоступно: выберите одобренный файл из своей галереи.');
    }
    $root=realpath(af_ag_resolve_upload_path().'/af_gallery/'.$uid);
    $path=realpath(af_ag_abs_path((string)$media['storage_path']));
    if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Исходный файл галереи недоступен.');
    }
    $image=@getimagesize($path);
    $mime=$image['mime']??'';
    if (!in_array($mime,ag_avatar_allowed_mimes($purpose),true) || $mime!==$media['mime']) {
        throw new RuntimeException('Этот формат не разрешён для аватаров MyBB. Используйте разрешённый JPG, PNG или GIF.');
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
        'url_full'=>ag_resolve_media_url($id,'full')];
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
