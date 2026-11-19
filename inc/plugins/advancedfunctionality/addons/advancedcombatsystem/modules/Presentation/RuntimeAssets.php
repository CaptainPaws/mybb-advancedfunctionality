<?php
if(!defined('IN_MYBB')){die('No direct access');}
function af_advancedcombatsystem_runtime_script():string
{
    global $mybb;static $sent=false;if($sent)return '';
    if(!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','runtime',null,['has_acs_runtime'=>true]))return '';
    $sent=true;$url=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/runtime.js?v=1200';
    return '<script defer src="'.htmlspecialchars_uni($url).'"></script>';
}
