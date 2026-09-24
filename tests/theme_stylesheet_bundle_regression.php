<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality.php');
$router = file_get_contents($root . '/inc/plugins/advancedfunctionality/admin/router.php');

$checks = [
    'fixed bundle name' => "define('AF_THEME_BUNDLE_NAME', 'advancedstyles.css')",
    'deterministic source sort' => "strtolower((string)\$a['addon_id'])",
    'authenticated section marker' => 'AF-SECTION-V1',
    'length delimited parser' => "\$bytes = (int)\$meta['bytes']",
    'optimistic conflict check' => "advancedstyles.css changed after this section was opened",
    'byte preserving replacement' => "substr(\$current, 0, (int)\$section['start']).\$replacement.substr(\$current, (int)\$section['end'])",
    'external structure recovery' => 'structure repaired; external recovery',
    'legacy classification' => "'status' => 'legacy'",
    'explicit legacy migration' => 'af_theme_stylesheet_migrate_legacy_bundle',
    'migration optimistic lock' => 'CSS changed after the migration page was opened',
    'recovery directory' => "'/css_recovery'",
    'normal sync manual guard' => "|| (!\$manual && \$currentHash !== (string)\$bundle['checksum'])",
    'pre-registry bundle preservation' => '$adoptingExistingBundle = (bool)($row && !$state && !$force)',
    'pre-registry current hash adoption' => "? sha1((string)(\$row['stylesheet'] ?? ''))",
    'stale registry sid fallback' => 'stale sid after a theme import',
    'legacy override migration' => 'Preserved legacy ACP override',
    'legacy rows retained' => 'Migration is intentionally non-destructive',
    'bundle delivery precedence' => 'Unified mode has precedence',
    'file mode detaches bundle' => "\$mode === 'theme' ? 'global' : ''",
    'source metadata has no sid' => "['stylesheet_sid' => 0, 'updated_at' => TIME_NOW]",
    'bundle source status' => "\$status = \$mode === 'file' ? 'file_source' : 'bundle_source'",
    'bundle detached status' => "\$status = 'bundle_detached'",
    'legacy duplicate cleanup' => 'af_theme_stylesheet_deduplicate_registry',
    'disabled source reconciliation' => 'af_disable_theme_stylesheet_sources',
    'disabled source detached state' => "'is_integrated' => 0",
    'section chips' => 'af-ts-section-chip',
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
if (strpos($router, 'theme_stylesheet_section') === false || strpos($router, 'theme_stylesheets_save_section') === false) {
    $failed[] = 'ACP section editor';
}
if (preg_match("~delete_query\\(\\s*'themestylesheets'.*AF_THEME_BUNDLE~s", $core)) {
    $failed[] = 'bundle/legacy destructive deletion';
}
if (preg_match("~af_ensure_theme_stylesheet_registry_row.*?insert_query\\('themestylesheets'~s", $core)) {
    $failed[] = 'source registry creates a MyBB stylesheet';
}

if ($failed) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "AF unified theme stylesheet regression checks passed." . PHP_EOL;
