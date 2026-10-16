<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_asset_allowed(string $resource,array $facts=[]): bool {
    return !function_exists('af_frontend_asset_allowed') || af_frontend_asset_allowed(AF_ASB_ID,$resource,null,$facts);
}
function af_asb_css_tag(string $file): string {
    $relative='assets/'.$file;
    $decision=function_exists('af_theme_stylesheet_delivery_decision')?af_theme_stylesheet_delivery_decision(AF_ASB_ID,$relative):['include_file'=>true];
    if (!empty($decision['use_theme_stylesheet']) && !empty($decision['theme_href'])) $url=$decision['theme_href'];
    elseif (!empty($decision['include_file'])) $url=rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.AF_ASB_ID.'/'.$relative.'?v='.filemtime(AF_ASB_BASE.$relative);
    else return '';
    return '<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}
function af_asb_pre_output(string &$page): void {
    if (!af_asb_enabled() || !empty($GLOBALS['af_disable_pre_output']) || strpos($page,'</head>')===false || str_contains($page,'<!--af-asb-assets-->')) return;
    $editor=str_contains($page,'data-af-asb-builder');$view=str_contains($page,'af-asb-signature');
    $tags='';
    if ($editor && af_asb_asset_allowed('editor')) {
        $tags.=af_asb_css_tag('signature-builder.css');
        $url=rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.AF_ASB_ID.'/assets/signature-builder.js?v='.filemtime(AF_ASB_BASE.'assets/signature-builder.js');
        $tags.='<script src="'.htmlspecialchars_uni($url).'" defer></script>';
    }
    if (($editor && af_asb_asset_allowed('preview')) || ($view && af_asb_asset_allowed('view',['has_asb_signature'=>true]))) $tags.=af_asb_css_tag('signature-view.css');
    if ($tags!=='') $page=str_replace('</head>','<!--af-asb-assets-->'.$tags.'</head>',$page);
}
