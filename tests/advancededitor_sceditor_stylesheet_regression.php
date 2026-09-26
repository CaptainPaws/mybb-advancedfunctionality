<?php
/** Regression coverage for SCEditor's singular `style` option. */

$source = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read AdvancedEditor source.');
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
