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
foreach (['AF_AM_TABLE_OVERRIDES', 'label_override', 'icon_override', 'Provider reloads must never overwrite administrator choices'] as $needle) {
    if (strpos($runtime, $needle) === false) throw new RuntimeException("override contract missing: {$needle}");
}
foreach (['save_order', 'sortorder', 'source_addon', 'allowed_containers'] as $needle) {
    if (strpos($admin, $needle) === false) throw new RuntimeException("ACP management missing: {$needle}");
}

// The manifest container migration used to collapse `secondary` to legacy
// `top` in the form action.  On POST that selected `main`, so add/edit saved
// into the wrong container.  Keep the logical container throughout the CRUD
// round trip, and bind edit submissions to the id which populated the form.
foreach ([
    "'loc' => \$logicalContainer",
    "generate_hidden_field('item_id'",
    "\$postedId !== \$id",
    "container='\".\$db->escape_string(\$logicalContainer)",
] as $needle) {
    if (strpos($admin, $needle) === false) throw new RuntimeException("ACP CRUD round-trip guard missing: {$needle}");
}

// MyBB's DB helpers and Form fields perform their own escaping.  Reintroducing
// either pre-escape corrupts quotes/ampersands after edit and frontend render.
foreach ([
    "'title'      => \$db->escape_string",
    "generate_text_box('url', htmlspecialchars_uni",
] as $forbidden) {
    if (strpos($admin, $forbidden) !== false) throw new RuntimeException("ACP value is escaped twice: {$forbidden}");
}

if (strpos($admin, "\$mybb->request_method !== 'post'") === false
    || substr_count($admin, "verify_post_check(\$mybb->get_input('my_post_key'))") < 4) {
    throw new RuntimeException('Destructive custom-item operations must be POST + CSRF protected.');
}

echo "advancedmenu management regression: OK\n";
