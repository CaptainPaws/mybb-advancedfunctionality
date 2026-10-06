<?php

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
define('IN_MYBB', true);
require_once $addon . '/ownership.php';
$entries = af_adaptivethemeframework_template_seeds();
if (!$entries) throw new RuntimeException('ATF template seed map is empty.');

$seen = [];
foreach ($entries as $template => $path) {
    $relative = basename($path);

    if (isset($seen[$template])) {
        throw new RuntimeException('Duplicate ATF template seed declaration: ' . $template);
    }
    $seen[$template] = true;

    if (!is_file($path)) {
        throw new RuntimeException('Missing ATF template seed: ' . $template . ' -> ' . $relative);
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Unreadable ATF template seed: ' . $template . ' -> ' . $relative);
    }

    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Empty ATF template seed: ' . $template . ' -> ' . $relative);
    }
}

if (!isset($seen['moderation_move'])) {
    throw new RuntimeException('moderation_move must remain in ATF template ownership.');
}

$moderationMove = $addon . '/templates/moderation_move.html';
if (!is_file($moderationMove) || filesize($moderationMove) <= 0) {
    throw new RuntimeException('moderation_move seed is missing or empty.');
}

echo "ATF template seed file regression checks passed.\n";
