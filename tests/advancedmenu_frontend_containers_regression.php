<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$mybb = (object)['settings'=>['bburl'=>'https://board.test'], 'user'=>['uid'=>1], 'usergroup'=>[]];
require AF_ADDONS.'advancedmenu/advancedmenu.php';

$GLOBALS['af_aam_unread'] = 7;
$modal = af_advancedmenu_render_registry_item([
    'key'=>'advanced_alerts', 'label'=>'Alerts', 'icon'=>'fa-solid fa-bell', 'type'=>'modal',
    'action'=>['trigger_selector'=>'#af_aam_header_link', 'modal_selector'=>'#af_aam_modal'],
    'badge_provider'=>static fn(): int => 7,
]);
if (strpos($modal, 'id="af_aam_header_link"') === false || strpos($modal, 'href="#"') === false) {
    throw new RuntimeException('Alerts must retain its modal trigger rather than become a URL.');
}
if (strpos($modal, 'af-am-badge') === false || strpos($modal, '>7</span>') === false) {
    throw new RuntimeException('Unread badge was not rendered.');
}

$source = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$css = file_get_contents(AF_ADDONS.'advancedmenu/assets/advancedmenu.css');
$characters = file_get_contents(AF_ADDONS.'advancedcharacters/advancedcharacters.php');
foreach (['af-am-main', 'af-am-secondary', "af_menu_configured_registry()", "empty(\$item['enabled'])"] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Missing frontend container contract: '.$needle);
}
foreach (['position: sticky', 'max-width: 100vw', '#header .top_links', '#header .panel_links', '#footer .upper'] as $needle) {
    if (strpos($css, $needle) === false) throw new RuntimeException('Missing layout rule: '.$needle);
}
if (strpos($css, '.user_links') !== false) throw new RuntimeException('user_links must remain available for stage 4.');
if (strpos($characters, "!empty(\$mybb->settings['af_advancedmenu_enabled'])") === false) {
    throw new RuntimeException('Characters legacy injection is not guarded by AdvancedMenu.');
}

echo "advancedmenu frontend containers regression: OK\n";
