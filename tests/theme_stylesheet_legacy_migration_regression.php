<?php

declare(strict_types=1);

$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
$start = strpos($core, 'function af_theme_stylesheet_encode_section');
$end = strpos($core, '/** Explicit, optimistic-locking migration', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL: migration planner functions not found\n");
    exit(1);
}
eval(substr($core, $start, $end - $start));

$sections = [
    ['addon_id' => 'one', 'addon_title' => 'One', 'logical_id' => 'one_main', 'source_file' => 'assets/one.css', 'css' => ".one { color: red; }"],
    ['addon_id' => 'two', 'addon_title' => 'Two', 'logical_id' => 'two_main', 'source_file' => 'assets/two.css', 'css' => ".two { color: blue; }"],
];
$encoded = [];
$legacyBlocks = [];
foreach ($sections as $section) {
    $css = $section['css'];
    unset($section['css']);
    $encoded[] = af_theme_stylesheet_encode_section($section, $css);
    $legacyBlocks[] = "/* =========================================================================="
        ."\n   AF addon: {$section['addon_title']} [{$section['addon_id']}]"
        ."\n   Source: {$section['source_file']}"
        ."\n   ========================================================================== */\n{$css}\n";
}
$freshCss = "/* structured */\n\n".implode("\n", $encoded);
$fresh = ['source' => $freshCss, 'checksum' => sha1($freshCss)];
$legacy = "/* AdvancedFunctionality theme bundle. Edit in MyBB ACP.\n * Normal synchronization preserves an ACP-edited bundle.\n * Force resync intentionally replaces it from the server sources.\n */\n\n".implode("\n", $legacyBlocks);

$classified = af_theme_stylesheet_parse_bundle($legacy);
if (($classified['status'] ?? '') !== 'legacy' || ($classified['error'] ?? '') !== 'no structured sections') {
    fwrite(STDERR, "FAIL: marker-free CSS was not classified as legacy\n"); exit(1);
}
$partial = af_theme_stylesheet_parse_bundle($legacy."\n/* AF-SECTION-V1 broken */");
if (($partial['status'] ?? 'corrupt') === 'legacy') {
    fwrite(STDERR, "FAIL: partial marker was classified as legacy\n"); exit(1);
}
$plain = af_theme_stylesheet_plan_legacy_migration($legacy, $fresh);
if (empty($plain['ok']) || !empty($plain['manual_override'])) {
    fwrite(STDERR, "FAIL: unchanged legacy bundle migration plan\n"); exit(1);
}
$plainParsed = af_theme_stylesheet_parse_bundle($plain['source']);
if (empty($plainParsed['ok']) || count($plainParsed['sections']) !== 2) {
    fwrite(STDERR, "FAIL: unchanged migration is not a two-section bundle\n"); exit(1);
}
$editedLegacy = str_replace('.two { color: blue; }', ".two { color: purple; }\n.custom { display: block; }", $legacy);
$edited = af_theme_stylesheet_plan_legacy_migration($editedLegacy, $fresh);
$editedParsed = af_theme_stylesheet_parse_bundle((string)($edited['source'] ?? ''));
$bodies = array_column(array_values($editedParsed['sections'] ?? []), 'body');
if (empty($edited['ok']) || empty($edited['manual_override']) || substr_count(implode("\n", $bodies), '.one { color: red; }') !== 1
    || substr_count(implode("\n", $bodies), '.custom { display: block; }') !== 1 || strpos(implode("\n", $bodies), 'color: purple') === false) {
    fwrite(STDERR, "FAIL: manual CSS was lost or duplicated\n"); exit(1);
}
$appended = af_theme_stylesheet_plan_legacy_migration($legacy."
.manual-tail { z-index: 7; }", $fresh);
$appendedParsed = af_theme_stylesheet_parse_bundle((string)$appended['source']);
$manualSections = array_filter($appendedParsed['sections'] ?? [], static fn(array $section): bool => ($section['meta']['addon_id'] ?? '') === '__manual_overrides__');
if (count($manualSections) !== 1 || substr_count((string)$appended['source'], '.manual-tail { z-index: 7; }') !== 1) {
    fwrite(STDERR, "FAIL: unmatched manual tail was not isolated exactly once\n"); exit(1);
}

// Once structured, the migration endpoint rejects before backup/write. The
// planner/parser round-trip must itself remain byte-stable.
if (af_theme_stylesheet_parse_bundle((string)$edited['source'])['status'] !== 'structured') {
    fwrite(STDERR, "FAIL: migrated bundle does not remain structured\n"); exit(1);
}

$required = ['af_theme_stylesheet_create_recovery', "'length' => strlen(\$css)", "'sha1' => sha1(\$css)", 'CSS changed after the migration page was opened'];
foreach ($required as $needle) {
    if (strpos($core, $needle) === false) { fwrite(STDERR, "FAIL: missing safety contract {$needle}\n"); exit(1); }
}
echo "AF legacy stylesheet migration regression checks passed.\n";
