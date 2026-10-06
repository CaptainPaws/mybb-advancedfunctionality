<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');

function extractedFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) return '';
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    return '';
}

$sync = extractedFunction($core, 'af_sync_theme_stylesheets');
$delivery = extractedFunction($core, 'af_theme_stylesheet_delivery_decision');
$registration = extractedFunction($core, 'af_register_theme_stylesheet');
$setMode = extractedFunction($core, 'af_theme_stylesheet_set_delivery_mode');
$actions = extractedFunction($core, 'af_theme_stylesheets_execute_action');
$checks = [
    'per-source basename' => "basename(\$sourceFileRel)",
    'readable collision fallback' => "\$owner.'-'.$stem",
    'canonical source path registry identity' => "'source_file'          => ltrim(str_replace('\\\\', '/', (string)(\$entry['file'] ?? '')), '/')",
    'manifest attachment persisted' => "af_build_theme_stylesheet_attach_string((array)\$entry['attach'])",
    'theme stylesheet seed created per source' => "'stylesheet'         => \$seedCssEsc",
    'installed checksum ownership field' => "'installed_checksum'   => \$installedChecksum",
    'theme checksum recorded' => "'current_checksum'     => \$currentChecksum",
    'manual state persisted' => "'sync_state'           => \$syncState",
    'legacy bundle migration retained' => 'af_theme_stylesheet_migrate_legacy_bundle',
    'recovery directory retained' => "'/css_recovery'",
    'legacy rows remain non-destructive' => 'Migration is intentionally non-destructive',
    'force warning says physical seed' => 'Force Resync replaces Theme CSS with the addon physical CSS file',
    'force confirmation is required' => "if (!\$confirmed)",
    'bundle section editor retained only for legacy migration' => 'theme_stylesheet_section',
];
$failed = [];
foreach ($checks as $label => $needle) {
    if (strpos($core, $needle) === false) $failed[] = $label;
}

if (strpos($sync, 'af_register_theme_stylesheet') === false || strpos($sync, 'af_theme_stylesheet_sync_bundle') !== false) {
    $failed[] = 'normal sync uses per-source registration';
}
if (strpos($delivery, 'AF_THEME_BUNDLE_NAME') !== false
    || strpos($delivery, 'af_theme_stylesheet_bundle_state') !== false
    || strpos($delivery, 'advancedstyles.css') !== false) {
    $failed[] = 'frontend delivery is independent from the legacy bundle';
}
if (strpos($registration, 'currentChecksum === $seedChecksum') === false
    || strpos($registration, 'currentChecksum === $installedChecksum') === false
    || strpos($registration, 'customized_seed_changed') === false) {
    $failed[] = 'checksum evidence controls seed replacement';
}
if (strpos($setMode, 'af_theme_stylesheet_sync_bundle') !== false
    || strpos($setMode, "'stylesheet'") !== false) {
    $failed[] = 'mode switch preserves existing Theme CSS';
}
if (strpos($actions, "af_sync_theme_stylesheets(true, \$addonId)") === false
    || strpos($actions, "if (!\$confirmed)") === false) {
    $failed[] = 'only confirmed Force Resync invokes destructive sync';
}
if (preg_match("~delete_query\\(\\s*'themestylesheets'.*AF_THEME_BUNDLE~s", $core)) {
    $failed[] = 'bundle/legacy destructive deletion';
}
if (preg_match("~af_ensure_theme_stylesheet_registry_row.*?insert_query\\('themestylesheets'~s", $core)) {
    $failed[] = 'registry placeholder creates a MyBB stylesheet';
}

if ($failed) {
    fwrite(STDERR, 'FAIL: '.implode(', ', $failed).PHP_EOL);
    exit(1);
}

echo "AF per-source Theme stylesheet regression checks passed.\n";
