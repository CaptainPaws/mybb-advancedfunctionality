<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$mybb = (object)['settings'=>['bburl'=>'https://board.test'], 'user'=>['uid'=>5, 'username'=>'Имя Тест'], 'usergroup'=>[]];
$templates = null;
require AF_ADDONS.'advancedmenu/advancedmenu.php';

$raw = '/member.php?action=profile&uid={uid}';
if (af_advancedmenu_expand_url_placeholders($raw) !== '/member.php?action=profile&uid=5') {
    throw new RuntimeException('UID placeholder was not expanded at render time.');
}
if (af_advancedmenu_expand_url_placeholders('/member.php?user={username}') !== '/member.php?user='.rawurlencode('Имя Тест')) {
    throw new RuntimeException('Username placeholder is not URL encoded.');
}

$item = ['location'=>'top', 'slug'=>'profile', 'title'=>'Профиль', 'url'=>$raw, 'icon'=>'', 'hint'=>''];
$_SERVER['REQUEST_URI'] = '/member.php?action=profile&uid=5';
$html = af_advancedmenu_render_item($item);
foreach (['uid=5', 'is-active'] as $needle) {
    if (strpos($html, $needle) === false) throw new RuntimeException('Rendered own-profile item misses '.$needle);
}
$_SERVER['REQUEST_URI'] = '/member.php?action=profile&uid=10';
if (strpos(af_advancedmenu_render_item($item), 'is-active') !== false) {
    throw new RuntimeException('Another user profile must not activate own-profile item.');
}
$_SERVER['REQUEST_URI'] = '/shop.php?action=view&id=3';
if (!af_advancedmenu_url_is_active('/shop.php')) throw new RuntimeException('Path-only section matching failed.');

$mybb->user = ['uid'=>0, 'username'=>''];
if (af_advancedmenu_item_is_visible($item) || af_advancedmenu_render_item($item) !== '') {
    throw new RuntimeException('Guest received a uid=0 placeholder link.');
}

echo "advancedmenu dynamic URL and active state regression: OK\n";
