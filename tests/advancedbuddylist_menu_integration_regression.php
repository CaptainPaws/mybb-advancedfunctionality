<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function af_is_addon_enabled(string $id): bool { global $mybb; return isset($mybb->settings['af_'.$id.'_enabled']) && (string)$mybb->settings['af_'.$id.'_enabled']==='1'; }
function af_menu_register_item(array $item): void { $GLOBALS['buddy_item']=$item; }
$mybb=(object)['settings'=>['af_advancedbuddylist_enabled'=>'1','af_advancedbyddylist_enabled'=>'0','af_abdl_enabled'=>'0'],'user'=>['uid'=>42]];
require AF_ADDONS.'advancedbuddylist/advancedbuddylist.php';
af_advancedbuddylist_menu_provider();
$item=$GLOBALS['buddy_item']??[];
if(($item['source_addon']??'')!=='advancedbuddylist'||($item['action']['url']??'')!=='buddy.php'||($item['type']??'')!=='link')throw new RuntimeException('Buddy page menu contract is invalid.');
if(!($item['visibility'])())throw new RuntimeException('Buddy link hidden from member.');
$mybb->settings=['af_advancedbuddylist_enabled'=>'0','af_advancedbyddylist_enabled'=>'1','af_abdl_enabled'=>'1'];if(($item['visibility'])())throw new RuntimeException('Canonical disabled switch lost precedence.');
$mybb->settings['af_advancedbuddylist_enabled']='1';$mybb->user=['uid'=>0];if(($item['visibility'])())throw new RuntimeException('Buddy link visible to guest.');
echo "Advanced Buddy List menu integration checks passed.\n";
