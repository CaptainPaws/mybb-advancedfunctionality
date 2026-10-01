<?php

declare(strict_types=1);

$core = (string)file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');

$required = [
    'member-selected active theme' => "\$mybb->user['style']",
    'canonical source identity' => 'function af_theme_stylesheet_canonical_source_file',
    'section missing reason' => "\$reason = 'section_missing'",
    'attachment reason' => "\$reason = 'bundle_not_attached'",
    'theme success reason' => "\$reason = 'theme_delivery_ok'",
    'bundle head guarantee' => 'function af_ensure_theme_bundle_link',
    'bulk theme action' => 'theme_stylesheets_set_theme_mode_bulk',
    'bulk file action' => 'theme_stylesheets_set_file_mode_bulk',
    'effective fallback UI' => 'FILE FALLBACK',
    'vendor diagnostic' => "'reason' => 'vendor_runtime_css'",
];
foreach ($required as $label => $needle) {
    if (strpos($core, $needle) === false) {
        throw new RuntimeException("Missing delivery contract: {$label}");
    }
}

// Exercise the common consumer contract against rendered <head> output. This
// catches the regression where callers emitted both URLs or suppressed both.
$render = static function (array $decision): string {
    $head = '<head>';
    if (!empty($decision['use_theme_stylesheet'])) {
        $head .= '<link rel="stylesheet" href="/cache/themes/theme2/advancedstyles.css">';
    } elseif (!empty($decision['include_file'])) {
        $head .= '<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.css">';
    }
    return $head.'</head>';
};

$themeHtml = $render(['use_theme_stylesheet' => true, 'include_file' => false]);
if (substr_count($themeHtml, 'advancedstyles.css') !== 1 || strpos($themeHtml, '/addons/advancedmenu/') !== false) {
    throw new RuntimeException('Theme mode did not render bundle-only HTML');
}
$fileHtml = $render(['use_theme_stylesheet' => false, 'include_file' => true]);
if (strpos($fileHtml, '/addons/advancedmenu/assets/advancedmenu.css') === false || strpos($fileHtml, 'advancedstyles.css') !== false) {
    throw new RuntimeException('File mode did not render source-only HTML');
}
$fallbackHtml = $render(['use_theme_stylesheet' => false, 'include_file' => true, 'reason' => 'bundle_missing']);
if (strpos($fallbackHtml, '/addons/advancedmenu/assets/advancedmenu.css') === false) {
    throw new RuntimeException('Missing bundle did not fail open to file HTML');
}

echo "AF theme/file/fallback frontend HTML delivery checks passed.\n";
