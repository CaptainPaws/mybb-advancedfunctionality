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
    else return ''; // Never silently override an explicitly denied Theme/File decision.
    return '<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
}
function af_asb_asset_tags(bool $editor,bool $view): string {
    $tags='';
    if ($editor && af_asb_asset_allowed('editor')) {
        $tags.=af_asb_css_tag('af_asb_builder.css');
        $url=rtrim((string)$GLOBALS['mybb']->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.AF_ASB_ID.'/assets/signature-builder.js?v='.filemtime(AF_ASB_BASE.'assets/signature-builder.js');
        $tags.='<script src="'.htmlspecialchars_uni($url).'" defer></script>';
    }
    if (($editor && af_asb_asset_allowed('preview')) || ($view && af_asb_asset_allowed('view',['has_asb_signature'=>true]))) $tags.=af_asb_css_tag('signature-view.css');
    return $tags;
}
/** ATF's existing component slot calls this before evaluating its UserCP shell. */
function af_asb_prepare_editor_assets(): void {
    global $headerinclude;
    if (!af_asb_enabled() || !af_asb_asset_allowed('editor') || str_contains((string)$headerinclude,'<!--af-asb-assets-->')) return;
    $tags=af_asb_asset_tags(true,true);
    if ($tags!=='') $headerinclude.='<!--af-asb-assets-->'.$tags;
}
/** Public signatures and non-ATF pages use the normal AF owner pipeline. */
function af_asb_pre_output(string &$page): void {
    if (!af_asb_enabled() || !empty($GLOBALS['af_disable_pre_output']) || stripos($page,'</head>')===false || str_contains($page,'<!--af-asb-assets-->')) return;
    $tags=af_asb_asset_tags(str_contains($page,'data-af-asb-builder'),str_contains($page,'af-asb-signature'));
    if ($tags!=='') $page=preg_replace_callback('~</head\s*>~i',static fn($m)=>'<!--af-asb-assets-->'.$tags.$m[0],$page,1);
}
/** New source identity avoids adopting the former APUI signature-builder.css Theme row. */
function af_asb_migrate_builder_stylesheet_mode(): void {
    global $db;
    if (!function_exists('af_discover_theme_stylesheets') || !function_exists('af_ensure_theme_stylesheet_registry_row')) return;
    $entry=null;
    foreach (af_discover_theme_stylesheets() as $candidate) {
        if ($candidate['addon_id']===AF_ASB_ID && $candidate['file']==='assets/af_asb_builder.css') {$entry=$candidate;break;}
    }
    if (!$entry || !$db->table_exists(AF_THEME_STYLESHEETS_TABLE)) return;
    $q=$db->simple_select(AF_THEME_STYLESHEETS_TABLE,'*',"addon_id='advancedsignaturebuilder'");
    $old=[];while ($row=$db->fetch_array($q)) if (($row['source_file']??'')==='assets/signature-builder.css') $old[]=$row;
    foreach ($old as $row) {
        $tid=(int)$row['theme_tid'];$logical=$db->escape_string($entry['logical_id']);
        $lookup=$db->simple_select(AF_THEME_STYLESHEETS_TABLE,'id',"theme_tid='{$tid}' AND addon_id='advancedsignaturebuilder' AND logical_id='{$logical}'");
        $existing=$db->fetch_array($lookup);
        if ($existing) continue; // Repeatable: a user's newer mode choice always wins.
        af_ensure_theme_stylesheet_registry_row($tid,$entry);
        $mode=in_array($row['delivery_mode']??'',['theme','file','auto'],true)?$row['delivery_mode']:'auto';
        $db->update_query(AF_THEME_STYLESHEETS_TABLE,['delivery_mode'=>$mode],"theme_tid='{$tid}' AND addon_id='advancedsignaturebuilder' AND logical_id='{$logical}'");
    }
}
