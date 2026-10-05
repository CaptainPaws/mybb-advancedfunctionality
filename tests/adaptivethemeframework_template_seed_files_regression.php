<?php

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$source = (string)file_get_contents($addon . '/adaptivethemeframework.php');

if ($source === '') {
    throw new RuntimeException('ATF bootstrap is unreadable.');
}

if (!preg_match('~function\\s+af_adaptivethemeframework_template_seeds\\s*\\(\\)\\s*:\\s*array\\s*\\{(.*?)\\n\\}~s', $source, $match)) {
    throw new RuntimeException('ATF template seed map was not found.');
}

preg_match_all(
    "~'([^']+)'\\s*=>\\s*AF_ADAPTIVETHEMEFRAMEWORK_BASE\\s*\\.\\s*'templates/([^']+)'~",
    $match[1],
    $entries,
    PREG_SET_ORDER
);

if (!$entries) {
    throw new RuntimeException('ATF template seed map is empty.');
}

$seen = [];
foreach ($entries as $entry) {
    $template = (string)$entry[1];
    $relative = (string)$entry[2];
    $path = $addon . '/templates/' . $relative;

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
