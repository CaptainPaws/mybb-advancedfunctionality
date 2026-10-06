<?php
require_once __DIR__ . "/fixtures/atf_css.php";

define('IN_MYBB', true);
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

function atf_topic_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$base = AF_ADDONS . 'adaptivethemeframework/';
$template = (string)file_get_contents($base . 'templates/forumdisplay_thread.html');
$listTemplate = (string)file_get_contents($base . 'templates/forumdisplay_threadlist.html');
$css = atf_test_css($base, 'forumdisplay.php');
$php = (string)file_get_contents($base . 'adaptivethemeframework.php');

atf_topic_assert(!str_contains($template, '<tr') && !str_contains($template, '<td'), 'Topic card must not skin the stock table row.');
foreach (['atf-topic-card__status', 'atf-topic-card__main', 'atf-topic-card__stats', 'atf-topic-card__lastpost'] as $part) {
    atf_topic_assert(str_contains($template, $part), "Missing semantic topic-card part: {$part}");
}
foreach ([
    '{$folder}', '{$folder_label}', '{$icon}', '{$prefix}', '{$gotounread}', "{\$thread['threadprefix']}",
    "{\$thread['subject']}", "{\$thread['profilelink']}", "{\$thread['start_datetime']}",
    "{\$thread['replies']}", "{\$thread['views']}", '{$lastposterlink}', '{$lastpostdate}',
    "{\$thread['multipage']}", "{\$thread['atf_rating']}", "{\$thread['atf_modbit']}",
    "{\$thread['atf_meta_chips']}", "{\$thread['atf_lastposter_avatar']}",
] as $value) {
    atf_topic_assert(str_contains($template, $value), "Preserved forumdisplay value is absent: {$value}");
}
atf_topic_assert(str_contains($template, '{$thread_type_class}'), 'Sticky/regular state class is absent.');
atf_topic_assert(str_contains($template, 'atf-topic-card--{$folder}'), 'Unread/closed/moved folder state is absent.');
atf_topic_assert(str_contains($php, "render_slot('thread.meta_chips'") && str_contains($php, "render_slot('thread.lastposter_avatar'"), 'Topic providers do not use the ATF slot API.');
atf_topic_assert(str_contains($css, '@media (max-width: 48rem)') && str_contains($css, '.atf-topic-card { grid-template-columns: 1fr;'), 'Mobile one-column contract is absent.');
atf_topic_assert(str_contains($css, 'grid-template-columns: auto minmax(0, 1fr) max-content minmax(12rem, 18rem) auto;'), 'Desktop bounded grid contract is absent.');
atf_topic_assert(str_contains($listTemplate, '<section class="atf-topic-list__cards">{$threads}</section>'), 'Topic cards must have a non-table list owner.');
atf_topic_assert(!preg_match('~<table[^>]*>[^<]*(?:<(?!/table)[^>]*>[^<]*)*\{\$threads\}~s', $listTemplate), 'Topic cards must not render inside a table.');

$GLOBALS['af_adaptivethemeframework_components'] = [];
af_adaptivethemeframework_register_component(['owner' => 'mybb', 'key' => 'chips_test', 'slot' => 'thread.meta_chips', 'html' => '<b>chips</b>']);
af_adaptivethemeframework_register_component(['owner' => 'mybb', 'key' => 'avatar_test', 'slot' => 'thread.lastposter_avatar', 'html' => '<img alt="avatar">']);
$thread = ['tid' => 42, 'subject' => 'Normal', 'lastposteruid' => 7, 'lastposter' => 'Poster'];
$fid = 3;
$rating = '<td id="rating"><ul class="star_rating"></ul></td>';
$modbit = '<td><input type="checkbox" name="inlinemod_42" value="1"></td>';
af_adaptivethemeframework_compose_thread_card();
atf_topic_assert($thread['atf_meta_chips'] === '<b>chips</b>', 'Chips provider output was not composed.');
atf_topic_assert($thread['atf_lastposter_avatar'] === '<img alt="avatar">', 'Avatar provider output was not composed.');
atf_topic_assert(!str_contains($thread['atf_rating'], '<td') && str_contains($thread['atf_rating'], 'star_rating'), 'Rating content was not retained semantically.');
atf_topic_assert(!str_contains($thread['atf_modbit'], '<td') && str_contains($thread['atf_modbit'], 'inlinemod_42'), 'Moderation checkbox was not retained semantically.');

$GLOBALS['af_adaptivethemeframework_components'] = [];
af_adaptivethemeframework_compose_thread_card();
atf_topic_assert($thread['atf_meta_chips'] === '' && $thread['atf_lastposter_avatar'] === '', 'Cards must tolerate chips/avatar providers being off.');

foreach (['newfolder', 'closefolder', 'movefolder'] as $folderVariant) {
    atf_topic_assert(str_replace('{$folder}', $folderVariant, $template) !== $template, "State variant did not render: {$folderVariant}");
}
atf_topic_assert(str_replace("{\$thread['multipage']}", '<a>2</a>', $template) !== $template, 'Multi-page links did not render.');

echo "ATF forumdisplay topic-card normal/sticky/closed/moved/multipage/provider contract passed.\n";
