<?php

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$template = file_get_contents($addon . '/templates/index.html');
$php = file_get_contents($addon . '/adaptivethemeframework.php');
$css = file_get_contents($addon . '/assets/adaptivethemeframework.css');

foreach (['{$headerinclude}', '{$header}', '{$fastnews}', '{$forums}', '{$boardstats}', '{$footer}'] as $value) {
    if (substr_count((string)$template, $value) !== 1) {
        throw new RuntimeException("Index compatibility value must render exactly once: {$value}");
    }
}
foreach (['forumbit_depth2_forum', 'forumbit_depth2_forum_lastpost', 'forumdisplay_thread'] as $title) {
    $card = file_get_contents($addon . '/templates/' . $title . '.html');
    if (!is_string($card) || !str_contains($php, "'{$title}' =>") || str_contains($card, '<tr')) {
        throw new RuntimeException("ATF must own a semantic card template for {$title}.");
    }
}
if (!str_contains((string)$template, 'class="atf-page atf-index"')
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
