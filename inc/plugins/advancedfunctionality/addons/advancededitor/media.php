<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_advancededitor_media_config(): array
{
    global $mybb;
    $enabled=!empty($mybb->settings['af_advancedgallery_enabled']) && (int)($mybb->user['uid'] ?? 0)>0;
    if (function_exists('af_is_addon_enabled')) $enabled=$enabled && af_is_addon_enabled('advancedgallery');
    $allowed=array_filter(array_map('intval',explode(',',(string)($mybb->settings['af_advancedgallery_can_upload_groups'] ?? ''))));
    $groups=array_merge([(int)($mybb->user['usergroup'] ?? 0)],array_map('intval',explode(',',(string)($mybb->user['additionalgroups'] ?? ''))));
    if ($allowed && !array_intersect($allowed,$groups)) $enabled=false;
    $root=dirname(__DIR__).'/advancedgallery/assets/';
    if (!$enabled || !is_file($root.'advancedgallery.js')) return ['enabled'=>false];
    $base=rtrim((string)($mybb->settings['bburl'] ?? ''),'/');
    $assets=$base.'/inc/plugins/advancedfunctionality/addons/advancedgallery/assets/';
    return ['enabled'=>true,'pickerUrl'=>$base.'/gallery.php?action=picker','dataUrl'=>$base.'/gallery.php?action=picker_data',
        'js'=>$assets.'advancedgallery.js?v='.substr(hash_file('sha256',$root.'advancedgallery.js'),0,16),
        'css'=>$assets.'advancedgallery.css?v='.substr(hash_file('sha256',$root.'advancedgallery.css'),0,16)];
}
