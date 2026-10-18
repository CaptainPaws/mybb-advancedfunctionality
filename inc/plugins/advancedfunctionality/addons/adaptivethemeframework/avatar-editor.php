<?php
/** UserCP inner composition only; MyBB and APF retain all persistence. */
if (!defined('IN_MYBB')) die('No direct access');
require_once dirname(__DIR__,2).'/avatar.php';

function af_atf_avatar_editor_prepare(): void
{
    global $mybb,$headerinclude,$useravatar,$avatar_error;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT!=='usercp.php' || $mybb->get_input('action')!=='avatar') return;
    $uid=(int)($mybb->user['uid']??0);
    $allowed=$uid>0 && !empty($mybb->usergroup['canusercp']);
    $primaryCaps=af_avatar_capabilities('primary');
    $primaryTypes=$primaryCaps['mime_limits'];
    $secondary=$allowed && function_exists('af_apf_is_enabled') && af_apf_is_enabled() && function_exists('af_apf_get_secondary_avatar');
    $gallery=function_exists('ag_can_view') && ag_can_view() && function_exists('ag_avatar_source');
    $maxWidth=$primaryCaps['max_width'];$maxHeight=$primaryCaps['max_height'];
    $primaryBytes=$primaryCaps['max_bytes'];
    $cards=[[
        'key'=>'primary','title'=>'Основной аватар','subtitle'=>'Аватар, отображаемый рядом с вашими сообщениями на форуме',
        'action'=>'do_avatar','field'=>'avatarupload','remove'=>'remove','save'=>'Сохранить основной аватар',
        'current'=>(string)($useravatar['image']??''),'has_current'=>!empty($mybb->user['avatar']),
        'upload'=>$allowed && !empty($mybb->usergroup['canuploadavatars']) && (bool)$primaryTypes,
        'url'=>$allowed && !empty($mybb->settings['allowremoteavatars']),
        'url_hint'=>!empty($mybb->settings['allowremoteavatars'])?'URL проверяется штатным обработчиком MyBB. Также поддерживается Gravatar по email.':'URL аватаров отключены настройками MyBB. Используйте загрузку файла или Gallery.',
        'gallery'=>$gallery && ag_avatar_available('account_avatar'),'purpose'=>'account_avatar',
        'error'=>(string)($avatar_error??''),
        'limits'=>($maxWidth||$maxHeight?'Максимальный размер: '.($maxWidth?:'∞').' × '.($maxHeight?:'∞').' px':'Размер изображения: без ограничения').($primaryBytes?' · до '.($primaryBytes/1024).' КБ':''),
    ]];
    if ($secondary) $cards[]=[
        'key'=>'secondary','title'=>'Аватар персонажа','subtitle'=>'Дополнительный аватар, отображаемый в постах и профиле персонажа',
        'action'=>'do_apf_secondary_avatar','field'=>'secondary_avatar_upload','remove'=>'remove_secondary_avatar','save'=>'Сохранить дополнительный аватар',
        'current'=>af_apf_get_secondary_avatar($uid),'has_current'=>af_apf_get_secondary_avatar($uid)!=='',
        'upload'=>true,'url'=>false,'url_hint'=>'URL не поддерживается обработчиком аватара персонажа. Загрузите файл или выберите изображение из галереи.',
        'gallery'=>$gallery && ag_avatar_available('character_avatar'),'purpose'=>'character_avatar',
        'error'=>!empty($GLOBALS['af_apf_secondary_avatar_error'])?htmlspecialchars_uni($GLOBALS['af_apf_secondary_avatar_error']):'',
        'limits'=>'Пропорции сохраняются'.(!empty($mybb->settings['maxavatarsize'])?' · до '.(int)$mybb->settings['maxavatarsize'].' КБ':''),
    ];
    foreach ($cards as &$card) {
        $caps=af_avatar_capabilities($card['key']);
        foreach (['mimes','mime_limits','max_bytes','max_width','max_height'] as $key) $card[$key]=$caps[$key];
        $card['notice']=$caps['notice'];$card['state']=af_avatar_state($card['key']);
    }
    unset($card);
    ob_start();require __DIR__.'/templates/components/avatar-editor.php';
    $GLOBALS['atf_avatar_editor']=ob_get_clean();
    // The new component owns both inner cards; APF's legacy slot is no longer rendered.
    $GLOBALS['af_apf_secondary_avatar']='';
    $url=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.avatar-editor.js?v='.filemtime(__DIR__.'/assets/adaptivethemeframework.avatar-editor.js');
    if (!str_contains((string)$headerinclude,'data-atf-avatar-runtime')) $headerinclude.='<script data-atf-avatar-runtime src="'.htmlspecialchars_uni($url).'" defer></script>';
}

/** MyBB's GD resize cannot preserve GIF animation. Reject only this editor's oversized GIFs. */
function af_atf_avatar_native_guard(): void
{
    global $mybb;
    if ($mybb->request_method!=='post' || !$mybb->get_input('atf_avatar_editor') || !empty($mybb->input['remove'])) return;
    verify_post_check($mybb->get_input('my_post_key'));
    $file=$_FILES['avatarupload']??[];
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return; // Native MyBB reports malformed uploads.
    $image=@getimagesize($file['tmp_name']);
    if (($image[2]??0)!==IMAGETYPE_GIF) return;
    $limits=preg_split('/[x|]/i',(string)($mybb->settings['maxavatardims']??''));
    $w=(int)($limits[0]??0);$h=(int)($limits[1]??0);
    if (($w && $image[0]>$w) || ($h && $image[1]>$h)) error('GIF превышает размеры аватара. Выберите GIF подходящего размера: уменьшение средствами MyBB может потерять анимацию.');
}
