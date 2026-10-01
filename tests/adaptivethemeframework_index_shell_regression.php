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
foreach (['forumbit_depth2_forum', 'forumbit_depth2_forum_lastpost'] as $title) {
    if (str_contains((string)$template, $title) || preg_match("~['\"]{$title}['\"]~", (string)$php)) {
        throw new RuntimeException("ATF index migration must not own {$title}.");
    }
}
if (!str_contains((string)$template, 'class="atf-page atf-index"')
    || !str_contains((string)$template, 'class="atf-index__section atf-index__forums"')) {
    throw new RuntimeException('ATF index page and compatibility section are missing.');
}
if (!str_contains((string)$php, "ownership_state' => 'manual_override'")
    || !str_contains((string)$php, "ownership_state' => 'restore_conflict'")
    || !str_contains((string)$php, "ownership_state' => 'restored'")) {
    throw new RuntimeException('Index lease does not fail closed for manual edits and restore conflicts.');
}
preg_match_all('~^\s*([^@\s}][^{}]*?)\s*\{~m', preg_replace('~/\*.*?\*/~s', '', (string)$css), $blocks);
foreach ($blocks[1] as $selector) {
    if (str_contains($selector, '.atf-index') && !str_contains($selector, 'body.atf-active')) {
        throw new RuntimeException('Index CSS is not isolated by the ATF active root.');
    }
}

echo "Adaptive Theme Framework index shell contract passed.\n";
