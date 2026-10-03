<?php

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$template = file_get_contents($addon . '/templates/index.html');
$php = file_get_contents($addon . '/adaptivethemeframework.php');
$css = file_get_contents($addon . '/assets/adaptivethemeframework.css');

foreach (['{$headerinclude}', '{$header}', '{$forums}', '{$boardstats}', '{$footer}'] as $value) {
    if (substr_count((string)$template, $value) !== 1) {
        throw new RuntimeException("Index compatibility value must render exactly once: {$value}");
    }
}
if (str_contains((string)$template, '{$fastnews}')
    || str_contains((string)$template, 'atf-index__hero')
    || str_contains((string)$template, 'atf-index__title')) {
    throw new RuntimeException('ATF index must not duplicate FastNews or render a board-name hero.');
}
foreach (['forumbit_depth1_cat', 'forumbit_depth2_forum', 'forumbit_depth2_forum_lastpost', 'forumdisplay_thread'] as $title) {
    $card = file_get_contents($addon . '/templates/' . $title . '.html');
    if (!is_string($card) || !str_contains($php, "'{$title}' =>") || str_contains($card, '<tr')) {
        throw new RuntimeException("ATF must own a semantic card template for {$title}.");
    }
}
$category = file_get_contents($addon . '/templates/forumbit_depth1_cat.html');
if (!str_contains((string)$category, '<section class="atf-forum-category"')
    || !str_contains((string)$category, '<div class="atf-forum-category__forums" style="{$expdisplay}" id="cat_{$forum[\'fid\']}_e">')
    || substr_count((string)$category, '{$sub_forums}') !== 1
    || !str_contains((string)$category, 'id="cat_{$forum[\'fid\']}_img" class="expander"')) {
    throw new RuntimeException('Top-level categories must own their rendered cards and retain native collapse state.');
}
if (!str_contains((string)$template, 'class="pun atf-page-shell atf-page atf-index"')
    || !str_contains((string)$template, 'class="atf-index__section atf-index__forums"')) {
    throw new RuntimeException('ATF index page and compatibility section are missing.');
}
if (substr_count($php, "'forum.lastposter_avatar'") < 2
    || !str_contains((string)file_get_contents($addon . '/templates/forumbit_depth2_forum_lastpost.html'), '<atf-forum-avatar')) {
    throw new RuntimeException('Forum avatar slot is not composed into final card output.');
}
if (!str_contains((string)$php, "ownership_state' => af_adaptivethemeframework_db_string('manual_override')")
    || !str_contains((string)$php, "ownership_state' => af_adaptivethemeframework_db_string('restore_conflict')")
    || !str_contains((string)$php, "ownership_state' => af_adaptivethemeframework_db_string('restored')")) {
    throw new RuntimeException('Index lease does not fail closed for manual edits and restore conflicts.');
}
preg_match_all('~^\s*([^@\s}][^{}]*?)\s*\{~m', preg_replace('~/\*.*?\*/~s', '', (string)$css), $blocks);
foreach ($blocks[1] as $selector) {
    if (str_contains($selector, '.atf-index') && !str_contains($selector, 'body.atf-active')) {
        throw new RuntimeException('Index CSS is not isolated by the ATF active root.');
    }
}

echo "Adaptive Theme Framework index shell contract passed.\n";
