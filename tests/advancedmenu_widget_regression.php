<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$mybb = (object)[
    'user'=>['uid'=>7],
    'usergroup'=>[],
    'post_code'=>'token',
];
$theme_select = '<form id="theme_select" method="get"><select name="theme" onchange="MyBB.changeTheme();"><option value="2">Светлая</option><option value="3" selected>Тёмная</option></select></form>';

require AF_ADDONS.'advancedmenu/advancedmenu.php';

$items = af_menu_collect_registry(true);
$theme = $items['theme_switcher'] ?? null;
if (!$theme || $theme['type'] !== 'widget') throw new RuntimeException('Theme switcher is not a widget.');
if ($theme['section'] !== 'settings' || $theme['allowed_containers'] !== ['user_drawer']) {
    throw new RuntimeException('Theme widget placement contract changed.');
}
if (($theme['widget_config']['provider'] ?? '') !== 'mybb_footer_theme_select') {
    throw new RuntimeException('Theme widget no longer declares the MyBB provider.');
}
if (isset($theme['action']['url'])) throw new RuntimeException('Widget must not expose a fake URL.');

$rendered = af_advancedmenu_render_registry_item($theme);
foreach (['data-af-am-widget="theme_switcher"', 'id="theme_select"', 'MyBB.changeTheme()', 'Светлая', 'Тёмная'] as $needle) {
    if (strpos($rendered, $needle) === false) throw new RuntimeException('Theme provider markup missing: '.$needle);
}
if (strpos($rendered, '<a ') !== false) throw new RuntimeException('Widget was rendered as a link.');

$future = ['key'=>'font_size', 'label'=>'Размер текста', 'type'=>'widget',
    'renderer'=>static fn(array $item): string => '<button type="button">'.htmlspecialchars_uni($item['widget_config']['value']).'</button>',
    'widget_config'=>['value'=>'Крупный']];
if (!af_menu_register_item($future)) throw new RuntimeException('Generic widget registration failed.');
$futureRendered = af_advancedmenu_render_registry_item($GLOBALS['af_advancedmenu_system_registry']['font_size']);
if (strpos($futureRendered, 'Крупный') === false || strpos($futureRendered, '<a ') !== false) {
    throw new RuntimeException('Generic widget renderer/config contract failed.');
}

$runtime = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$admin = file_get_contents(AF_ADDONS.'advancedmenu/admin.php');
foreach (["ADD COLUMN `section`", 'data-af-am-widget="theme_switcher"'] as $needle) {
    if (strpos($runtime, $needle) === false) throw new RuntimeException('Widget persistence/move contract missing: '.$needle);
}
foreach (['af_menu_sections()', "'section'=>\$section"] as $needle) {
    if (strpos($admin, $needle) === false) throw new RuntimeException('ACP section management missing: '.$needle);
}

echo "advancedmenu widget regression: OK\n";
