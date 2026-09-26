<?php
define('IN_MYBB', 1); define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
define('MYBB_ROOT', __DIR__.'/../'); define('TABLE_PREFIX', 'mybb_');
class RegistryTestPlugins { public function add_hook(...$args): void {} }
$plugins = new RegistryTestPlugins();
$mybb = (object)['user'=>['uid'=>7], 'usergroup'=>['cancp'=>1,'canmodcp'=>1], 'settings'=>['af_advancedaccountswitcher_enabled'=>1,'af_aam_enabled'=>1,'af_advancedalertsandmentions_enabled'=>1,'af_abdl_enabled'=>1]];
require AF_ADDONS.'advancedmenu/advancedmenu.php';
foreach (['advancedaccountswitcher','advancedalertsandmentions','advancedbyddylist','advancedcharacters','advancedpostcounter','advancedappearance'] as $id) require AF_ADDONS.$id.'/'.$id.'.php';
$items = af_menu_collect_registry(true);
$expected = ['advanced_account_switcher'=>'modal','advanced_alerts'=>'modal','friends'=>'modal','modcp'=>'link','admincp'=>'link','new_posts'=>'link','post_activity'=>'link','presets'=>'link','fitting_room'=>'link'];
foreach ($expected as $key=>$type) {
    if (!isset($items[$key])) throw new RuntimeException("missing registry item: $key");
    if ($items[$key]['type'] !== $type) throw new RuntimeException("wrong type for $key");
    foreach (['source_addon','label','icon','default_container','default_sortorder','visibility','action'] as $field) if (!array_key_exists($field,$items[$key])) throw new RuntimeException("$key misses $field");
}
foreach (['presets', 'fitting_room', 'post_activity'] as $key) {
    if ($items[$key]['default_container'] !== 'user_drawer') throw new RuntimeException("$key is not routed to the user drawer");
    if ($items[$key]['allowed_containers'] !== ['user_drawer']) throw new RuntimeException("$key can leak into another menu");
    if ($items[$key]['section'] !== 'links') throw new RuntimeException("$key has the wrong drawer section");
}
if (isset($items['characters'])) throw new RuntimeException('AdvancedCharacters must not register a navigation item');
$af_aam_unread = 12;
if (af_menu_item_badge($items['advanced_alerts']) !== 12) throw new RuntimeException('dynamic alerts badge failed');
if (!af_menu_item_is_visible($items['advanced_account_switcher'])) throw new RuntimeException('AAS visibility failed');
if (strpos(file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php'), 'af_advancedmenu_build_menu_html') === false) throw new RuntimeException('legacy renderer missing');
echo "advancedmenu registry regression: OK\n";
