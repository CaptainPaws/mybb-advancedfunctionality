<?php
require_once __DIR__ . "/fixtures/atf_css.php";

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$manifest = require $addon . '/manifest.php';
$css = atf_test_css($addon, 'showthread.php');
if (!is_string($css) || $css === '') {
    throw new RuntimeException('ATF design system CSS is missing.');
}

$required = ['atf-page', 'atf-section', 'atf-card', 'atf-grid', 'atf-stack', 'atf-meta', 'atf-button', 'atf-tabs', 'atf-chip', 'atf-form-row'];
foreach ($required as $component) {
    if (!str_contains($css, '.' . $component)) {
        throw new RuntimeException("Missing ATF component: {$component}");
    }
}
foreach (['.trow1', '.trow2', '.thead', '.af-apui-'] as $legacy) {
    if (str_contains(file_get_contents($addon . '/assets/adaptivethemeframework.css'), $legacy)) {
        throw new RuntimeException("ATF CSS depends on legacy selector: {$legacy}");
    }
}

/* All selector blocks must carry the activation boundary (at-rules aside). */
$cssWithoutComments = preg_replace('~/\*.*?\*/~s', '', $css);
preg_match_all('~^\s*([^@\s}][^{}]*?)\s*\{~m', (string)$cssWithoutComments, $blocks);
foreach ($blocks[1] as $selector) {
    if (!str_contains($selector, 'body.atf-active')) {
        throw new RuntimeException('Unscoped ATF selector block: ' . trim($selector));
    }
}

define('IN_MYBB', true);
define('AF_ADDONS', dirname($addon) . '/');
require $addon . '/adaptivethemeframework.php';
$page = '<!doctype html><html><body class="legacy"><main>ok</main></body></html>';
af_adaptivethemeframework_mark_page($page);
if (!str_contains($page, 'class="legacy atf-active"')) {
    throw new RuntimeException('ATF activation marker was not added to an existing body class.');
}
af_adaptivethemeframework_mark_page($page);
if (substr_count($page, 'atf-active') !== 1) {
    throw new RuntimeException('ATF activation marker is not idempotent.');
}

echo "Adaptive Theme Framework isolated design system passed.\n";
