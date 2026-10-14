<?php
/** External workflow fixture; real ATF/APUI templates, compiler, Gallery and ElementTheme code. */
define('AA_SHOP_FIXTURE',true);
require __DIR__.'/appearance_personal_integration.php';
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
require AF_ADDONS.'advancedprofileui/advancedprofileui.php';
require AF_ADDONS.'advancedelementtheme/advancedelementtheme.php';
function af_characterworkflow_resolve_active_application($uid){return ['tid'=>100,'relation'=>['uid'=>$uid,'accepted'=>$uid!==9]];}
function my_number_format($n){return number_format($n);}
function add_breadcrumb(...$args){}
function af_frontend_asset_allowed(...$args){return true;}
$mybb->settings+=['af_adaptivethemeframework_enabled'=>1,'af_advancedprofileui_enabled'=>1];
$mybb->settings['af_advancedappearance_personal_types']='postbit,profile,sheet,theme';
foreach(['profile','sheet','theme'] as $target){$mybb->settings['af_advancedappearance_free_slots_'.$target]=1;$mybb->settings['af_advancedappearance_max_slots_'.$target]=3;}
$GLOBALS['ag_system_album_schema_ready']=true;
$GLOBALS['af_apui_profile_element_keys']=[7=>'fire',8=>'fire',9=>''];
$GLOBALS['af_adaptivethemeframework_post_elements']=[7=>'fire',8=>'fire',9=>''];
$GLOBALS['af_elementtheme_elements']=['fire'=>['key'=>'fire']];
$effect=af_elementtheme_normalize_effects(['enabled'=>true,'preset'=>'embers','surfaces'=>['postbit','profile','sheet']]);
$GLOBALS['af_elementtheme_overrides']=['format'=>7,'styles'=>['fire'=>['variables'=>['--af-element-accent'=>'#cc6633'],'custom_css'=>'','effects'=>$effect]],'surfaces'=>[],
    'css'=>'','effects'=>af_elementtheme_compile_effects(['fire'=>['effects'=>$effect]])];
$headerinclude='<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css"><style>body{background:#101827;margin:0;color:#dbe3ec}.atf-profile,.af-cs-page,.aa-theme-preview{--atf-color-text:#dbe3ec;--atf-color-surface:#192332;--atf-color-border:#445566}</style>';
$header=$footer=$htmloption='';
function output_page($page){af_advancedgallery_pre_output($page);af_advancedelementtheme_pre_output($page);echo $page;}

if(PHP_SAPI==='cli-server'){
    $mybb->request_method=strtolower($_SERVER['REQUEST_METHOD']);
    if(($_GET['api']??'')==='personal'){af_aa_personal_api();exit;}
    if(($_GET['fixture_action']??'')==='runtime'){
        $target=$_GET['target']??'profile';$html=af_aa_studio_surface_preview_markup(7,$target);$other=af_aa_studio_surface_preview_markup(8,$target);
        if($target==='sheet')$html=af_aa_surface_fragment($html,7,'sheet');
        $memprofile=['uid'=>7];$assets=$headerinclude;
        foreach(af_aa_studio_surface_registry()[$target]['css'] as $css)$assets.='<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/'.$css.'">';
        $page='<!doctype html><html><head>'.$assets.'</head><body class="atf-active atf-profile-page">'.$html.$other.'</body></html>';
        af_aa_surfaces_pre_output($page);output_page($page);exit;
    }
    af_aa_render_workshop();exit;
}
