<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$mybb = (object)[
    'settings'=>['bburl'=>'https://board.test'],
    'user'=>['uid'=>42],
    'usergroup'=>[],
    'post_code'=>'logout-token',
];
require AF_ADDONS.'advancedmenu/advancedmenu.php';

af_menu_collect_registry(true);
$items = $GLOBALS['af_advancedmenu_system_registry'];
foreach (['profile', 'usercp', 'new_posts', 'private_messages', 'todays_posts', 'logout'] as $key) {
    if (($items[$key]['default_container'] ?? '') !== 'user_drawer') {
        throw new RuntimeException($key.' was not migrated to user_drawer.');
    }
}
if (($items['profile']['section'] ?? '') !== 'profile'
    || ($items['new_posts']['section'] ?? '') !== 'links'
    || ($items['logout']['section'] ?? '') !== 'settings') {
    throw new RuntimeException('Core drawer section assignment changed.');
}
if (strpos($items['logout']['action']['url'], 'logoutkey=logout-token') === false) {
    throw new RuntimeException('Logout system action does not carry the session token.');
}

$buddySource = file_get_contents(AF_ADDONS.'advancedbyddylist/advancedbyddylist.php');
foreach (["'key'=>'friends'", "'type'=>'modal'", "'default_container'=>'user_drawer'", "'handler'=>'MyBB.popupWindow'"] as $needle) {
    if (strpos($buddySource, $needle) === false) throw new RuntimeException('Friends drawer contract missing: '.$needle);
}

echo "advancedmenu user drawer regression: OK\n";
