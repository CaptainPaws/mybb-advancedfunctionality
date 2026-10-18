<?php
/** Reuse Gallery permission fixture; exercise real ATF component without a forum DB. */
require __DIR__.'/gallery_avatar_regression.php';
define('THIS_SCRIPT','usercp.php');
define('AF_APF_SECONDARY_AVATAR_KEY','profile_secondary_avatar');
function af_apf_secondary_avatar_path(int $uid): string {return '';}
function af_apf_is_enabled(): bool {return !empty($GLOBALS['mybb']->settings['af_advancedprofilefields_enabled']);}
function af_apf_get_secondary_avatar(int $uid): string {return 'https://forum.test/secondary.gif';}
function af_apf_validate_secondary_avatar_upload(array $file): string {return '';}
require AF_ADDONS.'adaptivethemeframework/avatar-editor.php';
$mybb->post_code='valid';$mybb->input=['action'=>'avatar'];$mybb->settings['af_advancedgallery_enabled']=1;
$mybb->settings['af_advancedprofilefields_enabled']=1;$mybb->settings['allowremoteavatars']=1;
$mybb->settings['maxavatardims']='200x250';$mybb->settings['maxavatarsize']=400;
$mybb->usergroup['canuploadavatars']=1;$mybb->usergroup['canusercp']=1;
$useravatar=['image'=>'https://forum.test/primary.gif'];$headerinclude='';$avatar_error='';
af_atf_avatar_editor_prepare();$html=$GLOBALS['atf_avatar_editor'];
avatar_check(substr_count($html,'data-avatar-card=')===2 && substr_count($html,'<form ')===2,'Two independent forms missing');
avatar_check(str_contains($html,'value="do_avatar"') && str_contains($html,'value="do_apf_secondary_avatar"'),'Native route isolation missing');
avatar_check(str_contains($html,'200 × 250') && str_contains($html,'400 КБ'),'Dynamic limits absent');
avatar_check(substr_count($html,'name="my_post_key"')===2 && substr_count($html,'data-gallery-purpose=')===2,'CSRF/Gallery context missing');
avatar_check(!str_contains($html,'Выбрать из моей галереи'),'Old standalone Gallery UI remains');
avatar_check(str_contains($headerinclude,'data-atf-avatar-runtime'),'Editor runtime missing');
$primaryCaps=af_avatar_capabilities('primary');
if (function_exists('imagecreatefromwebp')) avatar_check(in_array('image/webp',$primaryCaps['mimes'],true) && str_contains($html,'JPG, PNG, WebP, GIF'),'Working WebP decoder not reflected in UI');
$page='<html><head></head><body>'.$html.'</body></html>';af_advancedgallery_pre_output($page);
avatar_check((bool)preg_match('/advancedgallery\.js\?v=[a-f0-9]{16}/',$page),'Gallery content revision missing');
avatar_check(str_contains($page,'"account_avatar"') && str_contains($page,'"character_avatar"'),'PHP purpose registry missing from config');
$once=$page;af_advancedgallery_pre_output($page);avatar_check(substr_count($page,'/advancedgallery.js?v=')===1,'Duplicate Gallery runtime');
af_atf_avatar_editor_prepare();avatar_check(substr_count($headerinclude,'data-atf-avatar-runtime')===1,'Duplicate runtime');
avatar_check(ag_avatar_available('character_avatar'),'Character Gallery context unavailable');
avatar_check(in_array('image/webp',ag_avatar_allowed_mimes('character_avatar'),true),'APF WebP lost');
$mybb->usergroup['canuploadavatars']=0;avatar_check(ag_avatar_available('character_avatar') && !ag_avatar_available(),'Primary and APF permissions coupled');$mybb->usergroup['canuploadavatars']=1;
$mybb->settings['af_advancedgallery_enabled']=0;af_atf_avatar_editor_prepare();
avatar_check((bool)preg_match('/data-avatar-tab="gallery" disabled/',$GLOBALS['atf_avatar_editor']),'Disabled Gallery tab remains live');
avatar_check(str_contains($GLOBALS['atf_avatar_editor'],'name="avatarupload"') && str_contains($GLOBALS['atf_avatar_editor'],'name="secondary_avatar_upload"'),'Gallery disabled broke native uploads');
$mybb->settings['af_advancedgallery_enabled']=1;
$mybb->settings['af_advancedprofilefields_enabled']=0;af_atf_avatar_editor_prepare();
avatar_check(substr_count($GLOBALS['atf_avatar_editor'],'<form ')===1,'Disabled APF has active form');
$mybb->settings['af_advancedprofilefields_enabled']=1;af_atf_avatar_editor_prepare();
if (($path=getenv('ATF_AVATAR_HTML_OUTPUT'))!==false)file_put_contents($path,$GLOBALS['atf_avatar_editor']);
$mybb->input=['action'=>'profile'];$before=$headerinclude;af_atf_avatar_editor_prepare();avatar_check($headerinclude===$before,'Runtime leaked to profile');
echo "Avatar editor passed: two native routes, independent permissions, CSRF, runtime scope, dynamic limits, disabled Gallery/APF fallback and no retired UI.\n";
