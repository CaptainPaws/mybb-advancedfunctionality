<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
define('MYBB_ROOT', __DIR__.'/../');
define('TABLE_PREFIX', 'mybb_');

$enabled = ['advancedappearance', 'advancedpostcounter', 'advancedalertsandmentions', 'advancedaccountswitcher'];
$mybb = (object)['settings'=>array_fill_keys(array_map(static fn($id) => 'af_'.$id.'_enabled', $enabled), '1'),
    'user'=>['uid'=>0], 'usergroup'=>[]];

function af_is_addon_enabled(string $id): bool { global $mybb; return ($mybb->settings['af_'.$id.'_enabled'] ?? '0') === '1'; }
function af_discover_addons(): array {
    global $enabled;
    $out = [];
    foreach ($enabled as $id) {
        $meta = require AF_ADDONS.$id.'/manifest.php';
        $meta['path'] = AF_ADDONS.$id.'/';
        $out[] = $meta;
    }
    return $out;
}
function htmlspecialchars_uni(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

require AF_ADDONS.'advancedmenu/advancedmenu.php';

// This is the ACP condition being protected: no frontend addon bootstrap has
// defined any provider callback before discovery starts.
foreach ($enabled as $id) {
    if (function_exists('af_'.$id.'_menu_provider')) throw new RuntimeException($id.' provider was unexpectedly bootstrapped');
}
$catalogue = af_menu_collect_registry(true);
$expected = [
    'presets'=>['advancedappearance','link','user_drawer'],
    'fitting_room'=>['advancedappearance','link','user_drawer'],
    'post_activity'=>['advancedpostcounter','link','user_drawer'],
    'advanced_alerts'=>['advancedalertsandmentions','modal','secondary'],
    'advanced_account_switcher'=>['advancedaccountswitcher','modal','secondary'],
];
foreach ($expected as $key => [$source, $type, $container]) {
    if (!isset($catalogue[$key])) throw new RuntimeException('ACP catalogue misses '.$key);
    $item = $catalogue[$key];
    if ($item['source_addon'] !== $source || $item['type'] !== $type || $item['default_container'] !== $container) {
        throw new RuntimeException($key.' has incorrect provider metadata');
    }
}
foreach ($enabled as $id) {
    if (function_exists('af_'.$id.'_menu_provider')) throw new RuntimeException($id.' frontend bootstrap ran during discovery');
}

// Exercise the same normalized rows the ACP renderer places in its three
// container tabs; visibility must not remove logged-out/modal providers here.
$tabs = array_fill_keys(array_keys(af_menu_containers()), []);
foreach ($catalogue as $key => $item) $tabs[$item['default_container']][$key] = $item;
foreach (['advanced_alerts','advanced_account_switcher'] as $key) {
    if (!isset($tabs['secondary'][$key])) throw new RuntimeException('Secondary ACP tab misses '.$key);
}
foreach (['presets','fitting_room','post_activity'] as $key) {
    if (!isset($tabs['user_drawer'][$key])) throw new RuntimeException('User drawer ACP tab misses '.$key);
}
if (!array_key_exists('main', $tabs)) throw new RuntimeException('Main ACP tab is unavailable');

// Provider + override remains one canonical entity and both ACP and frontend
// consume its resulting section.
$moved = af_menu_apply_override($catalogue['presets'], [
    'source_addon'=>'advancedappearance', 'item_key'=>'presets', 'enabled'=>1,
    'container'=>'user_drawer', 'section'=>'settings', 'sortorder'=>7,
]);
if ($moved['canonical_identity'] !== 'advancedappearance::presets' || $moved['section'] !== 'settings') {
    throw new RuntimeException('Presets override did not merge into its provider entity');
}
$rendered = af_advancedmenu_render_registry_item($moved);
if (substr_count($rendered, 'af-am-presets') !== 1) throw new RuntimeException('Moved provider did not render exactly once');

// Modal metadata and fallback URLs survive catalogue normalization.
foreach (['advanced_alerts','advanced_account_switcher'] as $key) {
    $action = $catalogue[$key]['action'];
    foreach (['trigger_selector','modal_selector','owner_template'] as $field) {
        if (empty($action[$field])) throw new RuntimeException($key.' lost '.$field);
    }
    if (empty($action['url'])) throw new RuntimeException($key.' lost fallback URL');
    $html = af_advancedmenu_render_registry_item(af_menu_apply_override($catalogue[$key], null));
    if (strpos($html, 'af-am-modal-trigger') === false || strpos($html, htmlspecialchars_uni($action['url'])) === false) {
        throw new RuntimeException($key.' modal/fallback rendering regressed');
    }
}

echo "advancedmenu manifest provider discovery regression: OK\n";
