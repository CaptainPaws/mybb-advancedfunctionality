<?php
/** APStudio embeds the existing UserCP form; no second save path or signature table. */
if (!defined('IN_MYBB')) die('No direct access');

function af_apui_signature_can_edit(): bool
{
    $mybb=$GLOBALS['mybb'];$user=$mybb->user;$group=$mybb->usergroup;
    return (int)($user['uid']??0)>0 && !empty($group['canusercp']) && !empty($group['canusesig'])
        && (int)($user['postnum']??0)>=(int)($group['canusesigxposts']??0)
        && (empty($user['suspendsignature']) || (int)($user['suspendsigtime']??0)>0 && (int)$user['suspendsigtime']<=(defined('TIME_NOW')?TIME_NOW:time()));
}

function af_apui_signature_embed_requested(): bool
{
    return defined('THIS_SCRIPT') && THIS_SCRIPT==='usercp.php'
        && in_array((string)($GLOBALS['mybb']->input['action']??''),['editsig','do_editsig'],true)
        && (string)$GLOBALS['mybb']->get_input('af_apui_signature_embed')==='1';
}

function af_apui_signature_studio_block(): string
{
    global $mybb,$headerinclude;
    if (!af_apui_is_enabled() || !af_apui_signature_can_edit()) return '';
    if (function_exists('af_frontend_asset_allowed') && !af_frontend_asset_allowed(AF_APUI_ID,'signature_studio')) return '';
    $base=rtrim((string)$mybb->settings['bburl'],'/');
    $assets=$base.'/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/';
    $version=max(filemtime(__DIR__.'/../assets/signature-editor.js'),filemtime(__DIR__.'/../assets/signature-editor.css'));
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($assets.'signature-editor.css?v='.$version).'">'
        .'<script src="'.htmlspecialchars_uni($assets.'signature-editor.js?v='.$version).'" defer></script>';
    return '<section class="af-apui-signature-editor"><h2>Подпись</h2>'
        .'<details data-af-apui-signature-editor><summary>Редактировать подпись</summary>'
        .'<iframe title="Редактор подписи" data-af-signature-src="'.htmlspecialchars_uni($base.'/usercp.php?action=editsig&af_apui_signature_embed=1').'" referrerpolicy="same-origin"></iframe>'
        .'</details><noscript><a href="'.htmlspecialchars_uni($base.'/usercp.php?action=editsig').'">Редактировать подпись в UserCP</a></noscript></section>';
}

/** Derive a compact document from this theme's native form, retaining its controls and placeholders. */
function af_apui_signature_embed_source(string $source): ?string
{
    if (!preg_match_all('~<form\b[^>]*>.*?</form>~is',$source,$forms)) return null;
    foreach ($forms[0] as $form) {
        if (!preg_match('~<textarea\b[^>]*\bname=["\']signature["\']~i',$form)) continue;
        $form=str_replace(['{$usercpnav}','{$error}','{$signature}'],'',$form);
        $form=preg_replace('~</form>~i','<input type="hidden" name="af_apui_signature_embed" value="1"></form>',$form,1);
        return '<html><head><title>Подпись</title>{$headerinclude}</head><body class="af-apui-signature-embed">'
            .'<main class="af-apui-signature-embed__main">{$af_apui_signature_status}{$error}{$signature}'.$form.'</main></body></html>';
    }
    return null;
}

function af_apui_signature_embed_template(): void
{
    global $templates,$headerinclude,$mybb,$af_apui_signature_status;
    if (!af_apui_signature_embed_requested() || !af_apui_signature_can_edit()) return;
    $source=af_apui_signature_embed_source($templates->get('usercp_editsig',1,0));
    if ($source===null) return; // Unknown theme: retain its original native form and error reporting.
    $templates->cache['usercp_editsig']=$source; // request-local only; never modify stored templates
    $af_apui_signature_status=(string)$mybb->get_input('af_signature_saved')==='1'?'<p role="status" data-af-signature-saved>Подпись сохранена.</p>':'';
    $url=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/signature-editor.css?v='.filemtime(__DIR__.'/../assets/signature-editor.css');
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}

/** Called only by MyBB after verify_signature, CSRF and the native users.signature update. */
function af_apui_signature_saved(): void
{
    if (!af_apui_signature_embed_requested() || !af_apui_signature_can_edit()) return;
    redirect('usercp.php?action=editsig&af_apui_signature_embed=1&af_signature_saved=1',(string)($GLOBALS['lang']->redirect_sigupdated??'Подпись сохранена.'));
}
