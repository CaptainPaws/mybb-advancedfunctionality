<?php
/** Shared avatar contracts; persistence remains owned by MyBB/APF. */
if (!defined('IN_MYBB')) die('No direct access');

function af_avatar_state(string $role): array
{
    global $mybb,$db;
    $uid=(int)($mybb->user['uid']??0);
    if ($uid<=0 || empty($mybb->usergroup['canusercp']) || !in_array($role,['primary','secondary'],true)) error_no_permission();
    $path='';$dimensions='';$type='';
    if ($role==='primary') {
        $row=$db->fetch_array($db->simple_select('users','avatar,avatardimensions,avatartype',"uid='{$uid}'",['limit'=>1]));
        if (!$row) throw new RuntimeException('Не удалось прочитать сохранённый аватар пользователя.');
        $url=(string)$row['avatar'];$dimensions=(string)$row['avatardimensions'];$type=(string)$row['avatartype'];
        if ($type==='upload') {
            $candidate=strtok($url,'?');
            $path=str_starts_with($candidate,'/')?$candidate:rtrim(MYBB_ROOT,'/').'/'.$candidate;
            $root=realpath((string)$mybb->settings['avataruploadpath']);
            $actual=realpath($path);
            $path=$root && $actual && str_starts_with($actual,$root.DIRECTORY_SEPARATOR)?$actual:'';
        }
    } else {
        if (!function_exists('af_apf_is_enabled') || !af_apf_is_enabled()) throw new RuntimeException('Обработчик аватара персонажа отключён.');
        // Read the existing APF storage, not a user-supplied uid or URL.
        unset($GLOBALS['af_apf_system_value_cache'][$uid.':'.AF_APF_SECONDARY_AVATAR_KEY]);
        $relative=af_apf_secondary_avatar_path($uid);$url=af_apf_get_secondary_avatar($uid);
        $path=$relative!==''?rtrim(MYBB_ROOT,'/').'/'.$relative:'';$type=$relative!==''?'upload':'';
    }
    $hash=$path!=='' && is_file($path)?hash_file('sha256',$path):'';
    return ['uid'=>$uid,'role'=>$role,'url'=>$url,'has_avatar'=>$url!=='','dimensions'=>$dimensions,'type'=>$type,
        'mime'=>$path!=='' && is_file($path)?((@getimagesize($path))['mime']??''):'',
        'version'=>hash('sha256',json_encode([$url,$dimensions,$type,$hash],JSON_UNESCAPED_SLASHES))];
}

function af_avatar_json(array $data,int $status=200): void
{
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, private');
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

/** Only called after an owning handler has committed; verify the actual stored value. */
function af_avatar_saved_response(string $role,string $expected): void
{
    global $mybb;
    if ($mybb->get_input('atf_avatar_async')!=='1') return;
    verify_post_check($mybb->get_input('my_post_key'));
    try {
        $state=af_avatar_state($role);
        $actual=$role==='primary'?$state['url']:af_apf_secondary_avatar_path((int)$mybb->user['uid']);
        if ($state['type']==='upload' && $state['has_avatar'] && $state['mime']==='') throw new RuntimeException('Данные записаны, но файл аватара недоступен. Обновите страницу и обратитесь к администратору.');
        if ($actual!==$expected && $actual!==stripslashes($expected)) throw new RuntimeException('Проверка сохранённых данных аватара не прошла. Обновите страницу перед следующей попыткой.');
        af_avatar_json(['ok'=>true,'avatar'=>$state]);
    } catch (RuntimeException $e) {af_avatar_json(['ok'=>false,'error'=>$e->getMessage()],500);}
}

function af_avatar_native_saved(): void
{
    global $updated_avatar;
    if (isset($updated_avatar['avatar'])) af_avatar_saved_response('primary',(string)$updated_avatar['avatar']);
}

/** Read-only recovery after an ambiguous response: never repeats a save. */
function af_avatar_state_route(): void
{
    global $mybb;
    if ($mybb->get_input('action')!=='af_avatar_state') return;
    verify_post_check($mybb->get_input('my_post_key'));
    try {af_avatar_json(['ok'=>true,'avatar'=>af_avatar_state($mybb->get_input('role'))]);}
    catch (RuntimeException $e) {af_avatar_json(['ok'=>false,'error'=>$e->getMessage()],422);}
}

/** One source for server validation, Gallery, input accept and client limits. */
function af_avatar_capabilities(string $role): array
{
    global $mybb,$cache;
    $types=['image/jpeg'=>IMAGETYPE_JPEG,'image/png'=>IMAGETYPE_PNG,'image/webp'=>IMAGETYPE_WEBP,'image/gif'=>IMAGETYPE_GIF];
    if ($role==='secondary') return ['mimes'=>array_keys($types),'image_types'=>$types,'mime_limits'=>[],
        'max_bytes'=>max(0,(int)($mybb->settings['maxavatarsize']??0))*1024,'max_width'=>0,'max_height'=>0,'webp_target'=>'','notice'=>''];
    $limits=[];
    foreach ((array)$cache->read('attachtypes') as $type) {
        if (!empty($type['avatarfile']) && is_member($type['groups']) && (int)$type['maxsize']>0) $limits[strtolower($type['mimetype'])]=(int)$type['maxsize']*1024;
    }
    $target=isset($limits['image/png'])?'image/png':(isset($limits['image/jpeg'])?'image/jpeg':'');
    $decoder=function_exists('imagecreatefromwebp') && ($target==='image/png'?function_exists('imagepng'):function_exists('imagejpeg'));
    $notice='';
    if (isset($limits['image/webp']) && (!$decoder || $target==='')) {
        $notice=$target===''?'Для WebP необходимо разрешить PNG или JPG в типах аватаров ACP.':'Для обработки WebP на сервере требуется GD с поддержкой WebP.';
        unset($limits['image/webp']);
    }
    if (isset($limits['image/bmp'])) $types['image/bmp']=IMAGETYPE_BMP;
    $types=array_intersect_key($types,$limits);
    $dims=preg_split('/[x|]/i',(string)($mybb->settings['maxavatardims']??''));
    return ['mimes'=>array_keys($types),'image_types'=>$types,'mime_limits'=>array_intersect_key($limits,$types),
        'max_bytes'=>(int)round(max(0,(float)($mybb->settings['avatarsize']??0))*1024),'max_width'=>max(0,(int)($dims[0]??0)),
        'max_height'=>max(0,(int)($dims[1]??0)),'webp_target'=>$target,'notice'=>$notice];
}

function af_avatar_failure(string $message): void
{
    global $mybb;
    if ($mybb->get_input('atf_avatar_async')==='1') af_avatar_json(['ok'=>false,'error'=>$message],422);
    error(htmlspecialchars_uni($message));
}

/** Convert only the genuine multipart temp file; the original Gallery file is never touched. */
function af_avatar_primary_compat(): void
{
    global $mybb;
    if ($mybb->request_method!=='post' || !empty($mybb->input['remove'])) return;
    $file=$_FILES['avatarupload']??[];
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return;
    $info=@getimagesize($file['tmp_name']);
    if (($info[2]??0)!==IMAGETYPE_WEBP) return;
    verify_post_check($mybb->get_input('my_post_key'));
    if (empty($mybb->user['uid']) || empty($mybb->usergroup['canusercp']) || empty($mybb->usergroup['canuploadavatars'])) error_no_permission();
    $caps=af_avatar_capabilities('primary');
    if (!in_array('image/webp',$caps['mimes'],true)) af_avatar_failure($caps['notice']?:'WebP не разрешён для аватаров вашей группы.');
    $bytes=filesize($file['tmp_name']);$limit=$caps['mime_limits']['image/webp'];
    if ($caps['max_bytes']>0) $limit=min($limit,$caps['max_bytes']);
    if ($bytes<=0 || $bytes>$limit) af_avatar_failure('WebP превышает допустимый размер '.round($limit/1024,2).' КБ.');
    // VP8X animation bit is authoritative; never flatten an animated WebP silently.
    $stream=@fopen($file['tmp_name'],'rb');
    if (!$stream) af_avatar_failure('Временный файл загрузки недоступен. Выберите изображение повторно.');
    $header=fread($stream,30);fclose($stream);
    if (substr($header,12,4)==='VP8X' && (ord($header[20])&2)) af_avatar_failure('Анимированный WebP нельзя безопасно преобразовать для MyBB. Используйте GIF подходящего размера.');
    $tooLarge=($caps['max_width'] && $info[0]>$caps['max_width']) || ($caps['max_height'] && $info[1]>$caps['max_height']);
    $resize=($mybb->settings['avatarresizing']??'')==='auto' || (($mybb->settings['avatarresizing']??'')==='user' && $mybb->get_input('auto_resize')==='1');
    if ($tooLarge && !$resize) af_avatar_failure('Изображение превышает допустимые размеры '.($caps['max_width']?:'∞').' × '.($caps['max_height']?:'∞').' px.');
    if ($info[0]*$info[1]>16000000) af_avatar_failure('Изображение слишком велико для безопасной обработки. Уменьшите его разрешение.');
    $memory=(string)ini_get('memory_limit');
    if (preg_match('/^(\d+)([KMG]?)$/i',$memory,$m)) {
        $available=(int)$m[1]*1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]);
        if ($info[0]*$info[1]*12+memory_get_usage(true)>$available*.8) af_avatar_failure('Недостаточно памяти для обработки этого изображения. Уменьшите его разрешение.');
    }
    $image=@imagecreatefromwebp($file['tmp_name']);
    if (!$image) af_avatar_failure('Не удалось декодировать WebP. Файл повреждён или его вариант не поддерживается.');
    $temporary=@tempnam(sys_get_temp_dir(),'af-avatar-');
    if (!$temporary) {unset($image);af_avatar_failure('Не удалось подготовить временную копию аватара.');}
    try {
        if ($caps['webp_target']==='image/png') {
            imagealphablending($image,false);imagesavealpha($image,true);
            $ok=@imagepng($image,$temporary);$extension='png';
        } else {$ok=@imagejpeg($image,$temporary,92);$extension='jpg';}
        unset($image);
        if (!$ok || !getimagesize($temporary)) throw new RuntimeException('Не удалось подготовить совместимую копию WebP.');
        $outputSize=filesize($temporary);$outputLimit=$caps['mime_limits'][$caps['webp_target']];
        if ($caps['max_bytes']>0) $outputLimit=min($outputLimit,$caps['max_bytes']);
        if (!$tooLarge && $outputSize>$outputLimit) throw new RuntimeException('Подготовленная копия превышает лимит '.round($outputLimit/1024,2).' КБ. Уменьшите изображение.');
        // Overwrite rather than move: PHP still recognizes this path as a genuine uploaded file.
        if (!@copy($temporary,$file['tmp_name'])) throw new RuntimeException('Не удалось передать подготовленный аватар обработчику MyBB.');
        $_FILES['avatarupload']['name']='avatar.'.$extension;
        $_FILES['avatarupload']['type']=$caps['webp_target'];
        $_FILES['avatarupload']['size']=$outputSize;
    } catch (RuntimeException $e) {@unlink($temporary);af_avatar_failure($e->getMessage());}
    finally {@unlink($temporary);}
}
