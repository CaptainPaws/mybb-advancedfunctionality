<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function af_avatar_render(array $user, string $context, array $options = []) {
    return '<a class="shared-avatar" href="member.php?action=profile&amp;uid='.(int)$user['uid'].'">avatar</a>';
}
function my_date($format, $timestamp) { return 'recently'; }
$mybb = (object)[
    'settings'=>['bburl'=>'https://board.test'],
    'user'=>['uid'=>42, 'username'=>'Drawer User', 'lastvisit'=>123],
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

$account = af_advancedmenu_render_drawer_account();
foreach (['shared-avatar', 'uid=42', 'Drawer User', 'Профиль', 'action=logout&amp;logoutkey=logout-token', 'recently'] as $needle) {
    if (strpos($account, $needle) === false) throw new RuntimeException('Member account header missing: '.$needle);
}
if (substr_count($account, 'shared-avatar') !== 1) {
    throw new RuntimeException('Drawer must call the shared avatar renderer exactly once.');
}

$mybb->user = ['uid'=>0];
$guest = af_advancedmenu_render_drawer_account();
foreach (['action=login', 'Войти', 'action=register', 'Регистрация'] as $needle) {
    if (strpos($guest, $needle) === false) throw new RuntimeException('Guest account header missing: '.$needle);
}
if (strpos($guest, 'shared-avatar') !== false || strpos($guest, 'action=logout') !== false) {
    throw new RuntimeException('Guest drawer exposes member avatar/actions.');
}

$welcomeSource = file_get_contents(AF_ADDONS.'headerwelcomeavatar/headerwelcomeavatar.php');
if (strpos($welcomeSource, "function_exists('af_advancedmenu_render_drawer_account')") === false) {
    throw new RuntimeException('Legacy welcome addon does not yield frontend ownership to AdvancedMenu.');
}

$buddySource = file_get_contents(AF_ADDONS.'advancedbyddylist/advancedbyddylist.php');
foreach (["'key'=>'friends'", "'type'=>'modal'", "'default_container'=>'user_drawer'", "'handler'=>'MyBB.popupWindow'"] as $needle) {
    if (strpos($buddySource, $needle) === false) throw new RuntimeException('Friends drawer contract missing: '.$needle);
}

echo "advancedmenu user drawer regression: OK\n";
