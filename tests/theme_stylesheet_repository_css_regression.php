<?php

declare(strict_types=1);
define('IN_MYBB', 1);

$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
$start = strpos($core, 'function af_theme_stylesheet_section_id');
$end = strpos($core, 'function af_theme_stylesheet_get_bundle_row', $start);
if ($start === false || $end === false) throw new RuntimeException('section codec functions missing');
eval(substr($core, $start, $end - $start));

$root = dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons';
$manifests = glob($root.'/*/manifest.php') ?: [];
$blocks = [];
$files = [];
foreach ($manifests as $manifestFile) {
    $manifest = require $manifestFile;
    $addon = (string)($manifest['id'] ?? basename(dirname($manifestFile)));
    foreach ((array)($manifest['theme_stylesheets'] ?? []) as $entry) {
        $file = str_replace('\\', '/', (string)($entry['file'] ?? ''));
        $logical = (string)($entry['id'] ?? '');
        $path = dirname($manifestFile).'/'.$file;
        if ($logical === '' || !is_file($path)) throw new RuntimeException("invalid manifest CSS {$addon}:{$logical}:{$file}");
        $body = file_get_contents($path);
        if (!is_string($body)) throw new RuntimeException("unreadable manifest CSS {$path}");
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $blocks[] = af_theme_stylesheet_encode_section(['addon_id' => $addon, 'logical_id' => $logical, 'source_file' => $file], $body);
        $files[] = $addon.':'.$file;
    }
}
$bundle = "/* AdvancedFunctionality theme bundle (structured v1) */\n\n".implode("\n", $blocks);
$parsed = af_theme_stylesheet_parse_bundle($bundle);
if (empty($parsed['ok']) || count($parsed['sections']) !== count($files)) {
    throw new RuntimeException('real repository CSS bundle failed: '.($parsed['error'] ?? 'section count mismatch'));
}
if ($bundle !== "/* AdvancedFunctionality theme bundle (structured v1) */\n\n".implode("\n", $blocks)) throw new RuntimeException('bundle is not deterministic');
echo 'AF repository CSS bundle passed: '.count($files)." manifest sources.\n";
