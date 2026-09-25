<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
define('TABLE_PREFIX', 'mybb_');

require AF_ADDONS.'advancedmenu/advancedmenu.php';

$expected = ['main'=>'Основное меню', 'secondary'=>'Дополнительное меню', 'user_drawer'=>'Пользовательское меню'];
if (af_menu_containers() !== $expected) throw new RuntimeException('logical containers changed');
foreach (['top'=>'main', 'top_links'=>'main', 'panel'=>'user_drawer', 'panel_links'=>'user_drawer', 'user_links'=>'user_drawer'] as $old=>$new) {
    if (af_menu_normalize_container($old) !== $new) throw new RuntimeException("legacy mapping failed: {$old}");
}

af_menu_register_item(['key'=>'stage_two_item', 'label'=>'Stage two', 'default_container'=>'panel_links']);
$registry = $GLOBALS['af_advancedmenu_system_registry'];
if ($registry['stage_two_item']['default_container'] !== 'user_drawer') throw new RuntimeException('registry default was not normalized');
if ($registry['stage_two_item']['allowed_containers'] !== array_keys($expected)) throw new RuntimeException('default allowed containers missing');

$runtime = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$admin = file_get_contents(AF_ADDONS.'advancedmenu/admin.php');
foreach (['AF_AM_TABLE_OVERRIDES', 'label_override', 'icon_override', "if ($".'exists) continue'] as $needle) {
    if (strpos($runtime, $needle) === false) throw new RuntimeException("override contract missing: {$needle}");
}
foreach (['save_order', 'sortorder', 'source_addon', 'allowed_containers'] as $needle) {
    if (strpos($admin, $needle) === false) throw new RuntimeException("ACP management missing: {$needle}");
}

echo "advancedmenu management regression: OK\n";
