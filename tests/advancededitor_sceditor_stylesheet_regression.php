<?php
/** Regression coverage for SCEditor's singular `style` option. */

$source = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read AdvancedEditor source.');
}

$core = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality.php');
if (!is_string($core)) {
    throw new RuntimeException('Unable to read AdvancedFunctionality core source.');
}

$manifest = require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php';
$registered = [];
foreach ((array)($manifest['theme_stylesheets'] ?? []) as $entry) {
    $registered[(string)($entry['file'] ?? '')] = (string)($entry['id'] ?? '');
}
foreach (['assets/advancededitor.css', 'assets/bbcodes/tables/tables.css'] as $requiredCss) {
    if (empty($registered[$requiredCss])) {
        throw new RuntimeException("AdvancedEditor theme CSS is not registered: {$requiredCss}");
    }
}

$tableCss = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/tables/tables.css');
if (!is_string($tableCss) || !str_contains($tableCss, '.af-ae-tables-dropdown') || !str_contains($tableCss, '.af-ae-tables-dropdown__grid')) {
    throw new RuntimeException('Registered table picker CSS lost its scoped popup/grid selectors.');
}

if (!str_contains($core, '$bundleContainsSource = af_theme_stylesheet_bundle_contains_source(')
    || !str_contains($core, '&& $bundleContainsSource;')) {
    throw new RuntimeException('Theme delivery suppresses source CSS without proving that its bundle section exists.');
}
if (!str_contains($source, "return '';\n    }\n\n    if (empty(\$decision['include_file']))")) {
    throw new RuntimeException('AdvancedEditor re-emits an already attached advancedstyles.css for each pack.');
}

if (!preg_match(
    '~function\s+af_advancededitor_strip_own_assets\s*\([^)]*\)\s*:\s*void\s*\{(?<body>.*?)\n\}~s',
    $source,
    $stripMatch
)) {
    throw new RuntimeException('AdvancedEditor asset stripper is missing.');
}
eval('function af_advancededitor_strip_own_assets_test(string &$page): void {' . $stripMatch['body'] . "\n}");
$page = '<link rel="stylesheet" href="/cache/themes/theme7/advancedstyles.css?v=current">'
    . '<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.css">';
af_advancededitor_strip_own_assets_test($page);
if (!str_contains($page, '/cache/themes/theme7/advancedstyles.css?v=current')) {
    throw new RuntimeException('AdvancedEditor asset stripper removed the shared theme bundle.');
}
if (str_contains($page, '/advancededitor/assets/advancededitor.css')) {
    throw new RuntimeException('AdvancedEditor asset stripper did not remove its source-file link.');
}

if (!preg_match(
    '~function\s+af_advancededitor_build_sceditor_content_css\s*\([^)]*\)\s*:\s*string\s*\{(?<body>.*?)\n\}~s',
    $source,
    $match
)) {
    throw new RuntimeException('SCEditor content stylesheet builder is missing.');
}

// Evaluate only the self-contained builder so this test does not need MyBB.
eval('function af_advancededitor_build_sceditor_content_css_test(string $baseCssUrl): string {' . $match['body'] . "\n}");

$base = 'https://warprift.ru/jscripts/sceditor/styles/jquery.sceditor.mybb.css';
$actual = af_advancededitor_build_sceditor_content_css_test("  {$base}  ");
if ($actual !== $base) {
    throw new RuntimeException('SCEditor style must remain the single MyBB content stylesheet URL.');
}
if (str_contains($actual, ',')) {
    throw new RuntimeException('SCEditor style contains a comma-joined URL list.');
}

if (!str_contains($source, 'af_advancededitor_build_sceditor_content_css($sceditorContentCssBase)')) {
    throw new RuntimeException('Payload does not build style exclusively from the base content stylesheet.');
}
$javascript = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js');
if (!is_string($javascript) || !str_contains($javascript, "style: (P.sceditorContentCss || P.sceditorCss || '')")) {
    throw new RuntimeException('SCEditor initialization no longer consumes the validated payload value.');
}

$gallery = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js');
if (!is_string($gallery) || preg_match('~options\s*\.\s*style\s*=~', $gallery)) {
    throw new RuntimeException('AdvancedGallery must not rewrite the SCEditor style option.');
}

echo "AdvancedEditor SCEditor stylesheet regression checks passed.\n";
