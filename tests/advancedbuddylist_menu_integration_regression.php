<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function af_menu_register_item(array $item): void { $GLOBALS['buddy_item']=$item; }
$mybb=(object)['settings'=>['af_abdl_enabled'=>1],'user'=>['uid'=>42]];
require AF_ADDONS.'advancedbyddylist/advancedbyddylist.php';
af_advancedbyddylist_menu_provider();
$item=$GLOBALS['buddy_item']??[];
if(($item['source_addon']??'')!=='advancedbyddylist'||($item['action']['url']??'')!=='buddy.php'||($item['type']??'')!=='link')throw new RuntimeException('Buddy page menu contract is invalid.');
if(!($item['visibility'])())throw new RuntimeException('Buddy link hidden from member.');
$mybb->user=['uid'=>0];if(($item['visibility'])())throw new RuntimeException('Buddy link visible to guest.');
echo "Advanced Buddy List menu integration checks passed.\n";
