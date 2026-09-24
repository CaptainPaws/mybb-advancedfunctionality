<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$fontAwesome = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php');

if (substr_count($fontAwesome, "font-awesome-6/css/all.min.css?v=") !== 1) {
    throw new RuntimeException('The frontend renderer must emit exactly one canonical Font Awesome URL');
}
if (!str_contains($fontAwesome, "!af_afo_page_has_asset(\$page, 'font-awesome-6/css/all.min.css')")) {
    throw new RuntimeException('The global Font Awesome link is not deduplicated');
}
if (!str_contains($fontAwesome, 'Keep the vendor stylesheet at its original URL')) {
    throw new RuntimeException('Font Awesome must not be copied into advancedstyles.css');
}
if (!str_contains($core, "\$resolved['addon_id'] === 'advancedfontawesome'")
    || !str_contains($core, "'is_vendor_runtime_css' => true")) {
    throw new RuntimeException('Theme mode can still prune the canonical Font Awesome stylesheet');
}
if (!str_contains($core, 'return af_ensure_utf8_document_charset($page);')) {
    throw new RuntimeException('The frontend output does not pass through the UTF-8 boundary');
}
if (preg_match('/(?:utf8_encode|utf8_decode|mb_convert_encoding|iconv)\s*\(/i', $core)) {
    throw new RuntimeException('AF core must preserve UTF-8 bytes rather than transcode them');
}
if (!str_contains($core, "preg_match('//u', \$rawKey)") || !str_contains($core, "ltrim((string)\$v, \"\\xEF\\xBB\\xBF\")")) {
    throw new RuntimeException('Generated language files do not validate UTF-8 and remove source BOMs');
}

$start = strpos($core, 'function af_ensure_utf8_document_charset(');
$brace = strpos($core, '{', $start);
$depth = 0;
$function = '';
for ($i = $brace, $length = strlen($core); $i < $length; $i++) {
    if ($core[$i] === '{') $depth++;
    if ($core[$i] === '}' && --$depth === 0) {
        $function = substr($core, $start, $i - $start + 1);
        break;
    }
}
if ($function === '') throw new RuntimeException('Could not extract UTF-8 boundary');
eval($function);

$russian = 'Мод-меню';
$page = '<!doctype html><html><head><meta charset="windows-1251"></head><body>'.$russian.'</body></html>';
$result = af_ensure_utf8_document_charset($page);
if (!str_contains($result, '<meta charset="UTF-8">') || !str_contains($result, $russian)) {
    throw new RuntimeException('UTF-8 boundary changed source bytes or retained the wrong charset');
}
if (str_contains($result, 'Ð') || str_starts_with($result, "\xEF\xBB\xBF")) {
    throw new RuntimeException('UTF-8 boundary introduced mojibake or a BOM');
}

echo "Font Awesome global URL and UTF-8 output boundary passed.\n";
