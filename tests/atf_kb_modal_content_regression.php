<?php

declare(strict_types=1);

$assetPath = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.js';
$source = file_get_contents($assetPath);

if ($source === false) {
    fwrite(STDERR, "Unable to read AdvancedThreadFields JavaScript.\n");
    exit(1);
}

function atf_kb_modal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$showStart = strpos($source, 'show(entry) {');
$showEnd = strpos($source, 'backdrop.hidden = false;', $showStart === false ? 0 : $showStart);
atf_kb_modal_assert($showStart !== false && $showEnd !== false, 'ATF KB modal renderer was not found');

$showSource = substr($source, $showStart, $showEnd - $showStart);
atf_kb_modal_assert(strpos($showSource, 'entry.short_html') === false, 'ATF KB modal must not display the short description');
atf_kb_modal_assert(strpos($showSource, 'entry.banner_url') !== false, 'ATF KB modal no longer displays the KB banner');
atf_kb_modal_assert(strpos($showSource, 'entry.body_html') !== false, 'ATF KB modal no longer displays the KB-rendered full body');
atf_kb_modal_assert(strpos($showSource, 'entry.sections_html') !== false, 'ATF KB modal no longer consumes canonical KB display sections');
atf_kb_modal_assert(strpos($showSource, 'entry.blocks') === false, 'ATF KB modal fell back to its legacy duplicate block model');
atf_kb_modal_assert(
    strpos($showSource, 'entry.banner_url') < strpos($showSource, 'entry.body_html')
        && strpos($showSource, 'entry.body_html') < strpos($showSource, 'entry.sections_html'),
    'ATF KB modal content order must remain banner, full body, display sections'
);

fwrite(STDOUT, "ATF KB modal content regression checks passed.\n");
