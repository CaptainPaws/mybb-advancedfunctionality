<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

require AF_ADDONS.'advancedmenu/advancedmenu.php';

// The values posted by the ACP are structural keys, never translated labels.
$sections = af_menu_sections();
if ($sections !== ['profile'=>'Профиль', 'links'=>'Ссылки', 'settings'=>'Настройки', 'theme'=>'Тема']) {
    throw new RuntimeException('Canonical section keys changed.');
}
if (af_menu_normalize_section('settings') !== 'settings'
    || af_menu_normalize_section('Настройки') !== 'links') {
    throw new RuntimeException('Section whitelist no longer preserves canonical keys.');
}

$runtime = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$admin = file_get_contents(AF_ADDONS.'advancedmenu/admin.php');

// Cover the complete custom-item round trip: ACP POST -> item table -> drawer.
$contracts = [
    'POST field' => "get_input('section')",
    'edit form field' => "generate_select_box('section', af_menu_sections()",
    'custom UPDATE value' => "'section'=>\$section",
    'full-form save value' => "'section'    => \$section",
];
foreach ($contracts as $description => $needle) {
    if (strpos($admin, $needle) === false) {
        throw new RuntimeException("Custom section {$description} contract missing.");
    }
}

foreach ([
    "`section` varchar(24) NOT NULL DEFAULT 'links'",
    "field_exists('section', AF_AM_TABLE_ITEMS)",
    "af_menu_normalize_section((string)(\$item['section'] ?? 'links')) !== \$section",
] as $needle) {
    if (strpos($runtime, $needle) === false) {
        throw new RuntimeException('Custom section persistence/render contract missing: '.$needle);
    }
}

// Custom rows must be considered in every drawer section, not forced into links.
if (strpos($runtime, "if (\$section === 'links') foreach (\$custom") !== false) {
    throw new RuntimeException('Frontend still forces every custom item into links.');
}

echo "advancedmenu custom section regression: OK\n";
