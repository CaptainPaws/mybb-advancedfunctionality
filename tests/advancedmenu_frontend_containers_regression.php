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
$aasModal = af_advancedmenu_render_registry_item([
    'key'=>'advanced_account_switcher', 'label'=>'Accounts', 'type'=>'modal',
    'action'=>['trigger_selector'=>'#af_aas_trigger', 'trigger_class'=>'af-aas-trigger', 'modal_selector'=>'#af_aas_modal'],
]);
foreach (['id="af_aas_trigger"', 'class="af-am-link af-am-system-link af-am-modal-trigger af-aas-trigger"', 'data-af-am-modal="#af_aas_modal"'] as $needle) {
    if (strpos($aasModal, $needle) === false) throw new RuntimeException('AAS trigger contract missing: '.$needle);
}
if (strpos($modal, 'af-am-badge') === false || strpos($modal, '>7</span>') === false) {
    throw new RuntimeException('Unread badge was not rendered.');
}

$source = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$css = file_get_contents(AF_ADDONS.'advancedmenu/assets/advancedmenu.css');
$characters = file_get_contents(AF_ADDONS.'advancedcharacters/advancedcharacters.php');
foreach (['af-am-main', 'af-am-secondary', 'af-am-user-drawer', 'af-am-burger', "af_menu_configured_registry()", "empty(\$item['enabled'])"] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Missing frontend container contract: '.$needle);
}
foreach (['position: sticky', 'max-width: 100vw', '#header .top_links', '#header .panel_links', '#footer .upper', '.af-am-member #panel'] as $needle) {
    if (strpos($css, $needle) === false) throw new RuntimeException('Missing layout rule: '.$needle);
}
foreach (['#header .user_links', '.af-am-drawer-overlay', 'body.af-am-drawer-open', 'bottom: 14px'] as $needle) {
    if (strpos($css, $needle) === false) throw new RuntimeException('Missing user drawer layout rule: '.$needle);
}
$js = file_get_contents(AF_ADDONS.'advancedmenu/assets/advancedmenu.js');
foreach (["event.key === 'Escape'", "setAttribute('aria-expanded'", "event.key !== 'Tab'", "overlay.addEventListener('click'"] as $needle) {
    if (strpos($js, $needle) === false) throw new RuntimeException('Missing accessible drawer behavior: '.$needle);
}
foreach (['af_advancedcharacters_menu_provider', 'af_characters_add_moderator_link', 'data-af-characters-mod-link'] as $needle) {
    if (strpos($characters, $needle) !== false) throw new RuntimeException('Characters menu injection remains: '.$needle);
}

if (!preg_match('~<nav class="af-am-bar af-am-main"[^>]*>\'\s*\.\s*\'<button class="af-am-burger"~', $source)) {
    throw new RuntimeException('Burger is not inside the main navigation.');
}

echo "advancedmenu frontend containers regression: OK\n";
