<?php

declare(strict_types=1);

$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
$start = strpos($core, 'function af_theme_stylesheet_section_id');
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

$cases = [
    '', "a{color:red}", "a{color:red}\n", "a{\r\n color:red;\r\n}",
    "/* русский комментарий 😀 */\n.ёж{--имя:'значение'}",
    <<<'CSS'
/* comments */ /* AF-SECTION-V1 fake */ x{content:'*/ \\ "';}
CSS,
    "x{background:url(data:image/svg+xml;base64,PHN2Zy8+)}",
    "@media (width > 1px){x{display:block}}\n@font-face{font-family:x;src:url('x.woff2')}",
    str_repeat(".long{--value:'abcdef'}\n", 20000),
];
foreach ($cases as $index => $body) {
    $encoded = af_theme_stylesheet_encode_section(['addon_id' => 'case', 'logical_id' => (string)$index, 'source_file' => "case{$index}.css"], $body);
    $roundTrip = af_theme_stylesheet_parse_bundle($encoded);
    $actual = (string)(array_values($roundTrip['sections'] ?? [])[0]['body'] ?? "\0");
    if (empty($roundTrip['ok']) || $actual !== $body) throw new RuntimeException("codec property case {$index} did not round-trip");
}

// Reproduce the production symptom: native ACP changed the body without
// changing length/checksum metadata. Re-signing preserves it as a manual edit.
$edited = str_replace('color: red', 'color: tan', $bundle);
$mismatch = af_theme_stylesheet_parse_bundle($edited);
if (($mismatch['error'] ?? '') !== 'section checksum mismatch') throw new RuntimeException('production checksum mismatch was not reproduced');
$resigned = af_theme_stylesheet_resign_edited_bundle($edited);
if (empty($resigned['ok']) || empty(af_theme_stylesheet_parse_bundle($resigned['source'])['ok']) || !str_contains($resigned['source'], 'color: tan')) {
    throw new RuntimeException('ACP body edit was not preserved and re-signed');
}

echo "AF stylesheet property and ACP re-sign checks passed.\n";
