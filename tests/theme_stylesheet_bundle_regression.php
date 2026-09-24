<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality.php');
$router = file_get_contents($root . '/inc/plugins/advancedfunctionality/admin/router.php');

$checks = [
    'fixed bundle name' => "define('AF_THEME_BUNDLE_NAME', 'advancedstyles.css')",
    'deterministic source sort' => "strtolower((string)\$a['addon_id'])",
    'readable addon marker' => 'AF addon: {$safeTitle} [{$addonId}]',
    'normal sync manual guard' => "\$write = \$force || (!\$manual",
    'legacy override migration' => 'Preserved legacy ACP overrides',
    'legacy rows retained' => 'Migration is intentionally non-destructive',
    'bundle delivery precedence' => 'Unified mode has precedence',
    'file mode detaches bundle' => "\$mode === 'theme' ? 'global' : ''",
];

$failed = [];
foreach ($checks as $label => $needle) {
    if (strpos($core, $needle) === false) {
        $failed[] = $label;
    }
}
if (strpos($router, 'AF_THEME_BUNDLE_NAME') === false || strpos($router, 'theme_stylesheets_set_file_mode') === false) {
    $failed[] = 'ACP bundle controls';
}
if (preg_match("~delete_query\\(\\s*'themestylesheets'.*AF_THEME_BUNDLE~s", $core)) {
    $failed[] = 'bundle/legacy destructive deletion';
}

if ($failed) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "AF unified theme stylesheet regression checks passed." . PHP_EOL;
