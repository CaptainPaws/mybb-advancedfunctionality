<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function af_frontend_asset_allowed($addon, $component, $route = null, array $context = []): bool {
    return $addon === 'advancedbyddylist'
        && $component === 'modal_caller'
        && ($context['has_buddy_trigger'] ?? false) === true;
}

$mybb = (object)[
    'settings' => ['bburl'=>'https://board.test', 'af_abdl_enabled'=>'1'],
    'user' => ['uid'=>42, 'username'=>'Buddy User'],
    'usergroup' => [],
    'post_code' => 'token',
];

require AF_ADDONS.'advancedmenu/advancedmenu.php';

// Reproduce the real regression: the menu can initialise before an addon
// provider has been loaded. A later registry read must discover that provider.
$before = af_menu_collect_registry(true);
if (isset($before['friends'])) throw new RuntimeException('Buddy provider loaded too early in fixture.');
require AF_ADDONS.'advancedbyddylist/advancedbyddylist.php';
$after = af_menu_collect_registry();
$friends = $after['friends'] ?? null;
if (!$friends || ($friends['source_addon'] ?? '') !== 'advancedbyddylist') {
    throw new RuntimeException('Late Buddy provider was not registered.');
}
if (($friends['default_container'] ?? '') !== 'user_drawer'
    || ($friends['action']['trigger_selector'] ?? '') !== 'a[href*="action=buddypopup"]'
    || ($friends['action']['handler'] ?? '') !== 'MyBB.popupWindow') {
    throw new RuntimeException('Buddy trigger contract changed.');
}

$html = af_advancedmenu_render_registry_item($friends);
foreach (['af-am-friends', 'action=buddypopup&amp;modal=1', 'MyBB.popupWindow(this.href)'] as $needle) {
    if (!str_contains($html, $needle)) throw new RuntimeException('Buddy trigger HTML missing: '.$needle);
}
if (str_contains($html, '&amp;amp;')) throw new RuntimeException('Buddy modal URL was double encoded.');

$page = '<html><head></head><body>'.$html.'</body></html>';
af_advancedbyddylist_pre_output($page);
if (substr_count($page, 'advancedbyddylist.js?v=1') !== 1
    || substr_count($page, 'advancedbyddylist.css?v=1') !== 1) {
    throw new RuntimeException('Buddy caller runtime was not injected exactly once.');
}
af_advancedbyddylist_pre_output($page);
if (substr_count($page, 'advancedbyddylist.js?v=1') !== 1) {
    throw new RuntimeException('Buddy caller runtime was duplicated.');
}

$mybb->user = ['uid'=>0];
if (af_menu_item_is_visible($friends)) throw new RuntimeException('Buddy trigger is visible to guests.');
$guestPage = '<html><head></head><body></body></html>';
af_advancedbyddylist_pre_output($guestPage);
if (str_contains($guestPage, 'advancedbyddylist.js')) {
    throw new RuntimeException('Buddy runtime was loaded for a guest.');
}

$runtime = file_get_contents(AF_ADDONS.'advancedbyddylist/assets/advancedbyddylist.js');
foreach (['window.__afABDLLoaded', 'modal.__afAbdlWired', "addEventListener('click'", 'window.jQuery.modal.close'] as $needle) {
    if (!str_contains($runtime, $needle)) throw new RuntimeException('Modal lifecycle guard missing: '.$needle);
}

echo "Advanced Buddy List menu integration checks passed.\n";
