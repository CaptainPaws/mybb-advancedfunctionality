<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_component(): string {
    global $mybb,$signature;
    if (!af_asb_can_edit()) return '';
    if (!af_asb_asset_allowed('editor')) return '';
    $uid=(int)$mybb->user['uid'];$row=af_asb_row($uid);$native=(string)($mybb->user['signature']??'');$owned=af_asb_owned($row,$native);
    $model=af_asb_defaults();
    try {
        if (isset($GLOBALS['af_asb_submitted'])) $model=$GLOBALS['af_asb_submitted'];
        elseif (!empty($row['draft_json'])) $model=af_asb_decode($row['draft_json']);
        elseif ($owned) $model=af_asb_decode($row['published_json']);
    }catch(Throwable $e){} // Never replace the existing native signature merely by visiting.
    $model=af_asb_seed_model($model);
    $preview=af_asb_preview(af_asb_defaults(),$uid);
    try {$preview=af_asb_preview(af_asb_validate($model,$uid,true),$uid);}catch(Throwable $e){}
    $mediaUrls=[];foreach(array_merge([$model['background']],$model['images']) as $image) {
        $url='';try {$checked=af_asb_validate(['background'=>$image],$uid,true);$url=af_asb_image_url(array_replace($checked['background'],['enabled'=>true]));}catch(Throwable $e){}
        $mediaUrls[]=$url;
    }
    $seed=['media_urls'=>$mediaUrls,'model'=>$model,'defaults'=>af_asb_defaults(),'templates'=>af_asb_templates(),'limits'=>af_asb_limits(),'csrf'=>$mybb->post_code,'uid'=>$uid,'studio'=>af_asb_studio_url($uid),'field_error'=>$GLOBALS['af_asb_field_error']??null,'image_allowed'=>!empty($mybb->settings['sigmycode']) && !empty($mybb->settings['sigimgcode'])];
    $legacy=$native!=='' && !$owned?'<section class="af-asb__legacy"><h2>Существующая подпись сохранена</h2><p>Произвольный BBCode/HTML нельзя автоматически разложить на поля конструктора. Открытие страницы и сохранение черновика не меняют эту подпись.</p>'.$signature.'<label><input type="checkbox" name="af_sig_confirm_replace" value="1"> Заменить подпись композицией конструктора; исходник сохранить в резервной копии.</label></section>':'';
    $backup=!empty($row['legacy_backup'])?'<details><summary>Исходная подпись до перехода</summary><pre>'.htmlspecialchars_uni($row['legacy_backup']).'</pre></details>':'';
    $status=(string)$mybb->get_input('af_sig_saved')==='1'?'Подпись обновлена.':'';
    return require AF_ASB_BASE.'templates/signature_builder.php';
}
/** Prepare one trusted component before ATF consumes its existing ucp.content slot. */
function af_asb_prepare_page(): void {
    global $templates;
    if (!af_asb_enabled() || !af_asb_can_edit()) return;
    $GLOBALS['af_asb_builder_html']=af_asb_component();
    if ($GLOBALS['af_asb_builder_html']==='') return;
    if (function_exists('af_adaptivethemeframework_presentation_is_active') && af_adaptivethemeframework_presentation_is_active()) return;
    af_asb_prepare_editor_assets();
    // Explicit stock-MyBB fallback. cache accepts RAW template text, never templates->get() output.
    $templates->cache['usercp_editsig']=file_get_contents(AF_ASB_BASE.'templates/usercp_fallback.html');
}
