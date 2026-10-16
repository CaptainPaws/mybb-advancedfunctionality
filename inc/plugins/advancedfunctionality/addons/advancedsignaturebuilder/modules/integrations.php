<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_studio_url(int $uid): string {
    $url='apstudio.php?target=postbit&component=signature';
    if (function_exists('af_aa_personal_active_row') && ($row=af_aa_personal_active_row($uid))) $url.='&edit='.(int)$row['id'];
    return $url;
}
function af_asb_can_edit(): bool {
    $mybb=$GLOBALS['mybb'];$user=$mybb->user;$group=$mybb->usergroup;
    return (int)($user['uid']??0)>0 && !empty($group['canusercp']) && !empty($group['canusesig'])
        && (int)($user['postnum']??0)>=(int)($group['canusesigxposts']??0)
        && (empty($user['suspendsignature']) || (int)($user['suspendsigtime']??0)>0 && (int)$user['suspendsigtime']<=(defined('TIME_NOW')?TIME_NOW:time()));
}
/** Integration API: published content only, never drafts, and only for the matching native signature. */
function af_asb_published_html(int $uid,?string $native=null,bool $images=true): string {
    if (!af_asb_enabled()) return '';
    $row=af_asb_row($uid);$native??=(string)($row['native_signature']??'');
    if (!af_asb_owned($row,$native)) return '';
    try {return af_asb_render(af_asb_validate(af_asb_decode($row['published_json']),$uid,false),$images);}
    catch(Throwable $e){return '';}
}
function af_asb_studio_link(): string {
    if (!af_asb_enabled() || !af_asb_can_edit() || !af_asb_asset_allowed('studio_link')) return '';
    return '<section class="af-asb-studio-link"><h2>Подпись</h2><p>Содержимое и композиция редактируются в UserCP.</p><a href="usercp.php?action=editsig">Открыть конструктор подписи</a></section>';
}

/** Native MyBB profile parsing/visibility has already run; the owner is never the visitor. */
function af_asb_profile(): void {
    global $memprofile,$signature,$headerinclude,$mybb;
    $uid=(int)($memprofile['uid']??0);
    if ($uid<1 || trim((string)$signature)==='' || !af_asb_enabled()) return;
    $html=af_asb_published_html($uid,(string)($memprofile['signature']??''),!isset($mybb->user['showimages']) || (int)$mybb->user['showimages']===1);
    if ($html==='') return; // Keep legacy signatures and native suspension/visibility gates intact.
    $atf=function_exists('af_adaptivethemeframework_signature_surface')
        && (!function_exists('af_adaptivethemeframework_presentation_is_active') || af_adaptivethemeframework_presentation_is_active());
    $signature=$atf?af_adaptivethemeframework_signature_surface($html,'profile-'.$uid,$uid)
        :'<div class="af-aa-signature-user-'.$uid.'"><div class="atf-post__signature">'.$html.'</div></div>';
    $settings=function_exists('af_aa_surface_settings')?af_aa_surface_settings($uid,'postbit'):null;
    if ($settings!==null && function_exists('af_aa_signature_compile_css')) {
        $headerinclude.='<style data-asb-profile-owner="'.$uid.'">'.af_aa_signature_compile_css($uid,$settings).'</style>';
        if (function_exists('af_aa_studio_fonts_link')) $headerinclude.=af_aa_studio_fonts_link();
    }
}
