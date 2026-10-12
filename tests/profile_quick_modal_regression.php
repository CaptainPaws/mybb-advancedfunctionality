<?php
define('IN_MYBB', true); define('AF_APUI_ID','advancedprofileui');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function af_apui_append_query_arg($url,$key,$value) { return $url.(str_contains($url,'?')?'&':'?').urlencode($key).'='.urlencode($value); }
function af_apui_resolve_application_url($user,$payload) { return 'showthread.php?tid=40'; }
function af_apui_get_charactersheet_postbit_payload($uid) { return ['sheet_slug'=>'hero','sheet_url'=>'charactersheets.php?slug=hero','application'=>['tid'=>40]]; }
function get_thread($tid) { return $GLOBALS['fixture_thread']; }
function forum_permissions($fid) { return $GLOBALS['fixture_permissions']; }
function is_moderator($fid,$permission='') { return false; }
function check_forum_password($fid) { if ($GLOBALS['locked']) throw new RuntimeException('Password needed'); }
function af_atf_build_display_block_for_tid_fid($tid,$fid) { $GLOBALS['rendered']++;return '<section class="af-atf-display"><b>Rendered BBCode/fields fixture</b></section>'; }
function af_advancedinventory_url($action,$params,$escaped) { return 'inventory.php?action='.$action.'&'.http_build_query($params); }
function af_inv_user_can_view($viewer,$owner) { return $GLOBALS['inventory_allowed']; }
function af_advinv_get_entities($enabled) { return array_fill_keys(['equipment','abilities','resources','pets'], []); }
function af_advinv_get_ui_subfilters($entity) { return [['code'=>'talent']]; }
function af_advancedinventory_build_equipment_fragment($uid) { return '<div data-section="equipment">Arsenal</div>'; }
function af_advinv_render_entity_tab($entity,$uid,$sub,$page,$embedded,$search,$mode) { return '<div data-section="'.$entity.'">'.$sub.'</div>'; }
$mybb=(object)['user'=>['uid'=>2],'usergroup'=>['canviewprofiles'=>1],'settings'=>['bburl'=>'https://forum.test']];
$fixture_thread=['tid'=>40,'uid'=>9,'fid'=>4,'visible'=>1];$fixture_permissions=['canview'=>1,'canviewthreads'=>1];$locked=false;$rendered=0;$inventory_allowed=true;
$addon=__DIR__.'/../inc/plugins/advancedfunctionality/addons';
require $addon.'/advancedprofileui/modules/profile_providers.php';
function profile_check($value,$message) { if(!$value)throw new RuntimeException($message); }
$context=['uid'=>9,'sheet_payload'=>af_apui_get_charactersheet_postbit_payload(9)];
$items=af_apui_get_profile_quick_link_items($context);
profile_check(array_slice(array_column($items,'key'),0,6)===['application','sheet','arsenal','talents','resources','pets'],'Quick-link ordering');
$quick=af_apui_render_profile_quick_links($context);
profile_check(substr_count($quick,'data-af-apui-modal-url=')===6 && str_contains($quick,'embed=1') && !str_contains($quick,'data-afcs-open'),'Modal metadata');
af_apui_register_profile_quick_link_provider('external',fn($ctx)=>af_apui_profile_quick_link_item('external','External','fa-link','https://example.test/'));
profile_check(!str_contains(substr(af_apui_render_profile_quick_links($context),strpos(af_apui_render_profile_quick_links($context),'data-af-apui-quick-link="external"')-40),'data-af-apui-modal-url='),'External provider was hijacked');
foreach(['application','arsenal','talents','resources','pets'] as $key) { [$status,$payload]=af_apui_profile_quick_content(9,$key);profile_check($status===200 && !str_contains($payload['html'],'postbit'),'Content renderer '.$key); }
$fixture_thread['uid']=8;profile_check(af_apui_profile_quick_content(9,'application')[0]===200, 'Canonical transferred relation was replaced by poster ownership');$fixture_thread['uid']=9;
$fixture_permissions['canview']=0;profile_check(af_apui_profile_quick_content(9,'application')[0]===403,'Private forum leaked');$fixture_permissions['canview']=1;
$fixture_permissions['canonlyviewownthreads']=1;profile_check(af_apui_profile_quick_content(9,'application')[0]===403,'Owner-only forum leaked');unset($fixture_permissions['canonlyviewownthreads']);
$fixture_thread['visible']=0;profile_check(af_apui_profile_quick_content(9,'application')[0]===403,'Unapproved thread leaked');$fixture_thread['visible']=1;
$locked=true;$before=$rendered;try { af_apui_profile_quick_content(9,'application');throw new LogicException('Password bypass'); } catch(RuntimeException $error) { profile_check($rendered===$before,'Render occurred before password gate'); } $locked=false;
$inventory_allowed=false;profile_check(af_apui_profile_quick_content(9,'pets')[0]===403,'Inventory permission bypass');$inventory_allowed=true;
$native=['contact_details'=>'<table><tr><td>Email</td><td><a id="mail" href="member.php?action=emailuser&amp;uid=9">Email</a></td></tr><tr><td>PM disabled</td><td>Unavailable</td></tr></table>', 'buddy_options'=>'<a href="usercp.php?action=editlists">Friend</a>', 'modoptions'=>'<table><tr><td><form method="post" action="modcp.php"><input name="my_post_key" value="token"><button>Moderate</button></form></td></tr></table>'];
$extra=af_apui_render_profile_extra($native);
profile_check(!str_contains($extra,'<table') && !str_contains($extra,'PM disabled') && str_contains($extra,'name="my_post_key" value="token"') && str_contains($extra,'action="modcp.php"') && substr_count($extra,'id="mail"')===1,'Native actions/forms lost');
profile_check(!str_contains(af_apui_render_profile_extra([]),'Функции модератора'),'Empty privileged group shown');
$source=file_get_contents($addon.'/advancedprofileui/advancedprofileui.php');preg_match('~function af_apui_render_atf_profile_navigation\(.*?\n\}~s',$source,$match);eval($match[0]);
$GLOBALS['af_apui_profile_extra']=$extra;$nav=af_apui_render_atf_profile_navigation(['sections'=>array_fill_keys(['info','sheet','rewards','timeline','activity'],'Content')]);
profile_check(!str_contains($nav,'data-tab="sheet"') && str_contains($nav,'data-tab="extra"'),'ATF navigation');
foreach(['advancedprofileui','adaptivethemeframework'] as $owner){$tpl=file_get_contents($addon.'/'.$owner.'/templates/member_profile.html');profile_check(!str_contains($tpl,'data-panel="sheet"') && str_contains($tpl,'data-panel="extra"'),'Template integration '.$owner);}
if(in_array('--browser-fixture',$argv,true)){echo json_encode(compact('quick','extra','nav'));exit;}
echo "Profile quick modals: ordering/providers, renderer reuse, forum/password/status/inventory gates, action tokens, five tabs and both templates passed.\n";
