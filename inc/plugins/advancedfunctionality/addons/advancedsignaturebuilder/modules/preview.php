<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_preview(array $model,int $uid,?array $appearance=null): array {
    $html=af_asb_render($model,!isset($GLOBALS['mybb']->user['showimages']) || (int)$GLOBALS['mybb']->user['showimages']===1,true);
    $key=function_exists('af_adaptivethemeframework_post_element')?af_adaptivethemeframework_post_element($uid):'';
    $atf=function_exists('af_adaptivethemeframework_signature_markup') && function_exists('af_adaptivethemeframework_gothic_frame')
        && (!function_exists('af_adaptivethemeframework_presentation_is_active') || af_adaptivethemeframework_presentation_is_active());
    if ($atf) $html=af_adaptivethemeframework_signature_markup($html,'builder-preview',af_adaptivethemeframework_gothic_frame(),true);
    else $html='<div class="atf-post__signature">'.$html.'</div>';
    $html='<div id="aa-studio-preview"><article class="atf-post af-atf-display af-aa-postbit-user-'.$uid.'" data-element="'.htmlspecialchars_uni($key).'" data-element-surface="postbit"><div class="atf-post__content">'.$html.'</div></article></div>';
    // An optional Appearance-owned, already validated preset supports studio callers.
    $s=$appearance ?? (function_exists('af_aa_surface_settings')?af_aa_surface_settings($uid,'postbit'):null);
    $css='';if ($s!==null && function_exists('af_aa_personal_compile_css')) $css=af_aa_personal_compile_css($uid,$s,true);
    return ['html'=>$html,'css'=>$css];
}
