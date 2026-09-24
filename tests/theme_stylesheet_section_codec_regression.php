<?php

declare(strict_types=1);

$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
$start = strpos($core, 'function af_theme_stylesheet_encode_section');
$end = strpos($core, 'function af_theme_stylesheet_get_bundle_row', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL: section codec functions not found\n");
    exit(1);
}
eval(substr($core, $start, $end - $start));

$firstCss = ".one::after { content: '/* AF-END-SECTION-V1 */'; }\n/* AF-SECTION-V1 fake */\n.кириллица { color: red; }";
$secondCss = ".two { background: url('data:image/svg+xml,x'); }";
$first = af_theme_stylesheet_encode_section([
    'addon_id' => 'one', 'addon_title' => 'One', 'logical_id' => 'one_main', 'source_file' => 'assets/one.css',
], $firstCss);
$second = af_theme_stylesheet_encode_section([
    'addon_id' => 'two', 'addon_title' => 'Two', 'logical_id' => 'two_main', 'source_file' => 'assets/two.css',
], $secondCss);
$bundle = "/* preamble */\n\n".$first."\n".$second;
$parsed = af_theme_stylesheet_parse_bundle($bundle);
if (empty($parsed['ok']) || count($parsed['sections']) !== 2) {
    fwrite(STDERR, "FAIL: valid length-delimited bundle was not parsed\n");
    exit(1);
}
$bodies = array_column(array_values($parsed['sections']), 'body');
if ($bodies !== [$firstCss, $secondCss]) {
    fwrite(STDERR, "FAIL: section bodies did not round-trip byte-for-byte\n");
    exit(1);
}
$corrupt = substr_replace($bundle, 'X', strpos($bundle, '.one'), 1);
if (!empty(af_theme_stylesheet_parse_bundle($corrupt)['ok'])) {
    fwrite(STDERR, "FAIL: body checksum corruption was accepted\n");
    exit(1);
}

echo "AF stylesheet section codec regression checks passed.\n";
