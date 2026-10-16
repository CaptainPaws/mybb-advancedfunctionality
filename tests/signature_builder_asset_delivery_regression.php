<?php
/** Real AF registration/delivery/normalization functions; reuse the existing stylesheet DB fixture. */
// Reuse its fixture declarations, not the independent runner's unrelated scenarios.
$fixture=file_get_contents(__DIR__.'/theme_stylesheet_activation_runtime_regression.php');
$definitions=substr($fixture,0,strpos($fixture,'$db = new StylesheetDb();'));
eval(str_replace(['<?php','declare(strict_types=1);','final class StylesheetDb'],['','','class StylesheetDb'],$definitions));
define('IN_MYBB',1);define('AF_ADDONS',$root.'/inc/plugins/advancedfunctionality/addons/');define('AF_PLUGIN_ID','advancedfunctionality');define('MYBB_ROOT','/nonexistent-forum/');define('THIS_SCRIPT','usercp.php');
require AF_ADDONS.'advancedsignaturebuilder/advancedsignaturebuilder.php';
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function af_discover_addons(){return array_filter([$GLOBALS['asb_manifest'],$GLOBALS['gallery_manifest']??null]);}
function af_frontend_request_context(){return $GLOBALS['request_context'];}
function af_current_theme_tid(){return 1;}
function af_get_theme_inheritance_tids($tid=null){return [1];}
function af_find_css_candidate_for_file($addon,$file){return $GLOBALS['candidates'][$file]??[];}
function af_theme_stylesheet_base_from_rel($rel){return str_replace('/','__',preg_replace('~\.css$~','',$rel));}
function af_asset_clean_path($url){return (string)parse_url($url,PHP_URL_PATH);}
function af_resolve_af_addon_css_delivery_context($path){
    $prefix='/inc/plugins/advancedfunctionality/addons/advancedsignaturebuilder/';
    if(!str_starts_with($path,$prefix))return ['is_af_addon_css'=>false];
    return ['is_af_addon_css'=>true,'allow_file'=>af_theme_stylesheet_delivery_decision(AF_ASB_ID,substr($path,strlen($prefix)))['include_file']];
}
foreach(['af_normalize_script_name','af_resolve_frontend_manifest','af_frontend_route_matches','af_frontend_asset_decision','af_frontend_asset_allowed','af_find_theme_stylesheet_registry_states_for_file','af_theme_stylesheet_resolve_theme_href_from_state','af_theme_stylesheet_is_attached_to_request','af_theme_stylesheet_is_usable_on_current_request','af_theme_stylesheet_delivery_decision','af_hard_normalize_stylesheet_link_tags'] as $function)eval(extractFunction($core,$function));
$asb_manifest=require AF_ASB_BASE.'manifest.php';$request_context=['script'=>'usercp.php','action'=>'editsig','admin'=>false,'xmlhttp'=>false,'ajax'=>false,'method'=>'GET'];
$mybb=(object)['settings'=>['af_advancedsignaturebuilder_enabled'=>'1','bburl'=>'https://example.org'],'input'=>['action'=>'editsig']];
class ASBStylesheetDb extends StylesheetDb {
    public function simple_select(string $table,string $fields='*',string $where='',array $options=[]): array {
        $all=parent::simple_select($table,$fields,$where,[]);
        if ($table==='themestylesheets' && preg_match("~(?:^| )name='([^']+)'~",$where,$m)) $all['rows']=array_values(array_filter($all['rows'],static fn($r)=>$r['name']===$m[1]));
        if (isset($options['limit'])) $all['rows']=array_slice($all['rows'],0,$options['limit']);
        return $all;
    }
}
$db=new ASBStylesheetDb();
$legacy='.af-sig-builder__columns{display:grid}.af-sig-builder input{background:#102235}';
$legacySid=$db->insert_query('themestylesheets',['tid'=>1,'name'=>'signature-builder.css','stylesheet'=>addslashes($legacy),'attachedto'=>'usercp.php?action=editsig','cachefile'=>'signature-builder.css']);
$builder=$asb_manifest['theme_stylesheets'][0];
$entry=$builder+['addon_id'=>AF_ASB_ID,'logical_id'=>AF_ASB_ID.'__signature_builder','addon_meta'=>['path'=>AF_ASB_BASE],'delivery_hint'=>'auto','disable_theme_integration'=>false];
$entry['file']='assets/signature-builder.css';$entry['stylesheet_name']='signature-builder.css';
// Reproduce the former registration: same basename adopts old APUI CSS as customized_untracked.
$seed_css=file_get_contents(AF_ASB_BASE.'assets/af_asb_builder.css');$old=syncSource($seed_css);
assertSameValue(true,$old['manual_override'],'Fixture did not reproduce old-owner stylesheet adoption');
assertSameValue($legacy,$db->styles[$legacySid]['stylesheet'],'Registration unexpectedly overwrote old APUI CSS');
$oldState=array_key_last($db->registry);$db->registry[$oldState]['delivery_mode']='theme';
// A new source identity copies mode only. It cannot inherit the old row SID or old CSS bytes.
$entry=array_replace($entry,$builder,['logical_id'=>AF_ASB_ID.'__'.$builder['id']]);
af_asb_migrate_builder_stylesheet_mode();$newState=array_key_last($db->registry);
assertSameValue('theme',$db->registry[$newState]['delivery_mode'],'Theme choice was not migrated');
assertSameValue(0,$db->registry[$newState]['stylesheet_sid'],'Migration must not adopt the APUI stylesheet SID');
$db->registry[$newState]['delivery_mode']='file';af_asb_migrate_builder_stylesheet_mode();
assertSameValue('file',$db->registry[$newState]['delivery_mode'],'Repeat migration overwrote newer File mode');
$db->registry[$newState]['delivery_mode']='theme';$new=syncSource($seed_css);$newSid=$new['sid'];
assertSameValue(sha1($seed_css),sha1($db->styles[$newSid]['stylesheet']),'New source did not install actual .af-asb CSS');
assertSameValue($legacy,$db->styles[$legacySid]['stylesheet'],'Legacy CSS/edits must remain intact');
$candidates=[];
foreach($asb_manifest['theme_stylesheets'] as $source){
    $entry=array_replace($source,['addon_id'=>AF_ASB_ID,'logical_id'=>AF_ASB_ID.'__'.$source['id'],'addon_meta'=>['path'=>AF_ASB_BASE],'delivery_hint'=>'auto','disable_theme_integration'=>false]);
    $seed_css=file_get_contents(AF_ASB_BASE.$source['file']);syncSource($seed_css);
    $candidates[$source['file']]=$entry+['source_file_rel'=>$source['file']];
}
// Default context permissions and actual AF resolver are used by the ASB owner callback.
foreach(['theme','file','auto'] as $mode){
    foreach($db->registry as &$state)if($state['source_file']==='assets/af_asb_builder.css')$state['delivery_mode']=$mode;unset($state);
    $headerinclude='';af_asb_prepare_editor_assets();$head='<HTML><HEAD>'.$headerinclude.'</HEAD><BODY><form data-af-asb-builder class="af-asb"><div class="af-asb__layout"></div></form></BODY></HTML>';
    af_advancedsignaturebuilder_pre_output($head);$head=af_hard_normalize_stylesheet_link_tags($head);
    assertSameValue(1,substr_count($head,'signature-builder.js'),'One owner runtime must survive normalization');
    if($mode==='file'){
        assertSameValue(true,str_contains($head,'/assets/af_asb_builder.css'),'File mode lost source CSS');
    }else{
        assertSameValue(false,str_contains($head,'/assets/af_asb_builder.css'),'Theme mode leaked source CSS');
        assertSameValue(true,str_contains($head,'stylesheet='.$newSid),'Theme mode lost its independent stylesheet');
    }
    // Both DB-backed CSS sources share /css.php; their distinct IDs must survive deduplication.
    if($mode!=='file')assertSameValue(2,substr_count($head,'href="https://example.org/css.php?stylesheet='),'Normalizer collapsed distinct DB stylesheet IDs');
    $again=$head;af_advancedsignaturebuilder_pre_output($again);assertSameValue($head,$again,'Repeated owner callback duplicated assets');
}
$denied=$request_context;$denied['action']='profile';
assertSameValue(false,af_frontend_asset_decision($asb_manifest,'editor',$denied)['allowed'],'Editor permission leaked to another UserCP section');
$public=$request_context;$public['script']='showthread.php';$public['action']='';
assertSameValue(false,af_frontend_asset_decision($asb_manifest,'editor',$public)['allowed'],'Constructor runtime leaked to showthread');
assertSameValue(true,af_frontend_asset_decision($asb_manifest,'view',$public)['allowed'],'Published styles denied on showthread');
$withoutMarker='<html><head></head><body>Other page</body></html>';af_advancedsignaturebuilder_pre_output($withoutMarker);
assertSameValue(false,str_contains($withoutMarker,'signature-builder.js'),'Runtime loaded without constructor markup');
echo "ASB asset delivery passed: legacy-owner collision reproduced, independent Theme source, preserved mode/old CSS, real resolver and final normalization, permissions, head timing and no duplicates.\n";

// Exercise the Gallery owner callback on the actual UserCP context, not just a marker test.
require AF_ADDONS.'advancedgallery/advancedgallery.php';
$gallery_manifest=require AF_AG_BASE.'manifest.php';$mybb->settings['af_advancedgallery_enabled']=1;
$picker='<html><head></head><body><form data-af-asb-builder data-aa-gallery-picker></form></body></html>';
af_advancedgallery_pre_output($picker);
assertSameValue(true,str_contains($picker,'/advancedgallery/assets/advancedgallery.js'),'UserCP picker runtime blocked');
assertSameValue(true,str_contains($picker,'/advancedgallery/assets/advancedgallery.css'),'UserCP modal CSS blocked');
assertSameValue(true,str_contains($picker,'AF_GalleryPickerConfig='),'UserCP picker endpoints missing');
af_advancedgallery_pre_output($picker);
assertSameValue(1,substr_count($picker,'src="https://example.org/inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js'),'Gallery callback duplicated runtime');
foreach(['signature_background','signature_images'] as $purpose)assertSameValue(true,isset(ag_system_album_purposes()[$purpose]),'Gallery does not accept signature purpose');
$profile=$public;$profile['script']='member.php';$profile['action']='profile';
assertSameValue(true,af_frontend_asset_decision($asb_manifest,'view',$profile)['allowed'],'Profile view CSS denied');
assertSameValue(false,af_frontend_asset_decision($asb_manifest,'editor',$profile)['allowed'],'Profile loaded constructor runtime');
assertSameValue(false,af_frontend_asset_decision($gallery_manifest,'pre_output',$profile)['allowed'],'Profile loaded Gallery without a picker');
$plain='<html><head></head><body><section class="af-asb-signature"></section></body></html>';af_advancedgallery_pre_output($plain);
assertSameValue(false,str_contains($plain,'advancedgallery.js'),'Gallery runtime leaked without picker host');
echo "Gallery/UserCP delivery passed: real owner CSS/JS/config, signature purposes, one callback and public-profile isolation.\n";
// Actual Theme/File resolution for the viewed profile, not just manifest registration.
$request_context=$profile;
foreach(['theme','file','auto'] as $mode) {
    foreach($db->registry as &$state)if($state['source_file']==='assets/signature-view.css')$state['delivery_mode']=$mode;unset($state);
    $headerinclude='';af_asb_prepare_view_assets();
    $profileHtml='<html><head>'.$headerinclude.'</head><body><section class="af-asb-signature"></section></body></html>';
    af_advancedsignaturebuilder_pre_output($profileHtml);$profileHtml=af_hard_normalize_stylesheet_link_tags($profileHtml);
    assertSameValue(true,str_contains($profileHtml,'rel="stylesheet"'),'Profile lost its actual view CSS link');
    assertSameValue(false,str_contains($profileHtml,'signature-builder.js'),'Profile loads editing runtime');
    assertSameValue(false,str_contains($profileHtml,'af_asb_builder.css'),'Profile loads builder CSS');
    if($mode==='file') assertSameValue(true,str_contains($profileHtml,'assets/signature-view.css'),'Profile File mode loses view stylesheet');
    else assertSameValue(true,str_contains($profileHtml,'css.php?stylesheet='),'Profile Theme mode loses registered stylesheet');
}
echo "Profile delivery passed: final head retains real Theme/File view CSS without builder assets.\n";
