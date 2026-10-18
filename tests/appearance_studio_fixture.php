<?php
/** External workflow/palette fixtures; the ATF resolver and Canvas renderer remain real. */
require_once AF_ADDONS.'advancedprofilefields/postbit-fields.php';
$mybb->settings['allowlinkmycode']=1;
function my_strlen($v){return mb_strlen($v,'UTF-8');}
function my_substr($v,$start,$length=null){return mb_substr($v,$start,$length,'UTF-8');}
function my_strtolower($v){return mb_strtolower($v,'UTF-8');}
$cache=new class {public function read($name){return $name==='profilefields'?[
 ['fid'=>1,'name'=>'ЛЗ','type'=>'text','postbit'=>1,'allowmycode'=>0],
 ['fid'=>2,'name'=>'О персонаже','type'=>'textarea','postbit'=>1,'allowmycode'=>0],
 ['fid'=>3,'name'=>'Скрытое поле','type'=>'text','postbit'=>0]
]:[];}};
$templates=new class {public function get($name){
 $map=['postbit_profilefield'=>'<br /><span class="af-apf-postbit-field af-apf-postbit-field--fid{$field[\'fid\']}" data-field-id="{$field[\'fid\']}"><span class="af-apf-name">{$post[\'fieldname\']}</span>: <span class="af-apf-value">{$post[\'fieldvalue\']}</span></span>'];
 return str_replace('"','\\"',$map[$name]??'');
}};
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
require AF_ADDONS.'advancedelementtheme/advancedelementtheme.php';
function af_apui_get_profile_character_payload($uid){return ['fields'=>['character_element'=>['value'=>'fire']]];}
function af_characterworkflow_resolve_active_application($uid){return ['tid'=>100,'relation'=>[]];}
function af_apui_is_approved_character_application($uid,$tid,$relation){return $uid!==9;}
function af_frontend_asset_allowed(...$args){return true;}
function add_breadcrumb(...$args){}
$GLOBALS['ag_system_album_schema_ready']=true;
$GLOBALS['af_elementtheme_elements']=['fire'=>['key'=>'fire']];
$effect=af_elementtheme_normalize_effects(['enabled'=>true,'preset'=>'embers','surfaces'=>['postbit']]);
$GLOBALS['af_elementtheme_overrides']=['format'=>7,'styles'=>['fire'=>['variables'=>['--af-element-accent'=>'#cc6633'],'custom_css'=>'','effects'=>$effect]],'surfaces'=>[],
    'css'=>'','effects'=>af_elementtheme_compile_effects(['fire'=>['effects'=>$effect]])];
$headerinclude='<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css"><style>body{background:#101827;margin:0}.atf-post{--atf-post-sidebar-width:220px;--atf-color-text:#dbe3ec;--atf-color-surface:#192332}</style>';
$header=$footer=$htmloption='';
function output_page($page){
    af_advancedgallery_pre_output($page);
    af_advancedelementtheme_pre_output($page);
    echo $page;
}
function appearance_studio_render(): void {
    global $headerinclude;
    $content='';foreach([7,8] as $uid) $content.=str_replace('appearance_preview',(string)(100+$uid),af_aa_studio_preview_markup($uid));
    $page='<!doctype html><html><head>'.$headerinclude.'<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css">'.af_aa_render_page_css([7,8]).'</head><body class="atf-active">'.$content.af_aa_studio_effect_payload([7,8]).'</body></html>';
    output_page($page);exit;
}
