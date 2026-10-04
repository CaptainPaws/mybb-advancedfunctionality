<?php

$root = dirname(__DIR__);
$addons = $root . '/inc/plugins/advancedfunctionality/addons/';
$atf = file_get_contents($addons . 'adaptivethemeframework/adaptivethemeframework.php');
$template = file_get_contents($addons . 'adaptivethemeframework/templates/postbit_classic.html');
$css = file_get_contents($addons . 'adaptivethemeframework/assets/adaptivethemeframework.css');
$apui = file_get_contents($addons . 'advancedprofileui/advancedprofileui.php');
$apf = file_get_contents($addons . 'advancedprofilefields/advancedprofilefields.php');
$editorJs = file_get_contents($addons . 'advancededitor/assets/advancededitor.js');
$counterJs = file_get_contents($addons . 'advancededitor/assets/bbcodes/bbcodes/charcountandprew/charcountandprew.js');
$quickReply = file_get_contents($addons . 'adaptivethemeframework/templates/showthread_quickreply.html');

foreach ([$atf, $template, $css, $apui, $apf, $editorJs, $counterJs, $quickReply] as $source) {
    if ($source === false) {
        throw new RuntimeException('A required postbit/editor source is missing.');
    }
}

// Render the relevant template fragment, rather than merely checking that a
// selector exists in a source file. This is the DOM contract consumed by the
// browser after the postbit provider has supplied a non-empty slot.
$renderedPost = str_replace(
    "{\$post['af_atf_slots']['post.author.character_name']}",
    '<div class="atf-post__character-name" data-character-field="character_name_ru">Рин</div>',
    $template
);
if (!preg_match('~<div class="atf-post__secondary-avatar">.*?</div>\s*<div class="atf-post__character-name"[^>]*>Рин</div>\s*<section class="atf-post__personal-title">~s', $renderedPost)) {
    throw new RuntimeException('Rendered postbit does not place the character name between avatar and title.');
}
$renderedWithoutCharacter = str_replace("{\$post['af_atf_slots']['post.author.character_name']}", '', $template);
if (str_contains($renderedWithoutCharacter, 'atf-post__character-name')) {
    throw new RuntimeException('Empty character payload renders an empty character-name container.');
}

if (!str_contains($atf, "'post.author.character_name'")) {
    throw new RuntimeException('ATF does not publish a character-name slot.');
}
if (!str_contains($template, "{\$post['af_atf_slots']['post.author.character_name']}")
    || strpos($template, 'post.author.character_name') > strpos($template, 'atf-post__personal-title')) {
    throw new RuntimeException('The character name is not rendered before the personal title.');
}
if (str_contains($template, 'atf-post__sidebar-heading')) {
    throw new RuntimeException('The ambiguous personal-title label is still rendered.');
}
foreach (['character_name_ru', 'atf-post__character-name', "\$characterName === ''"] as $needle) {
    if (!str_contains($apui, $needle)) {
        throw new RuntimeException('Conditional character-name composition is incomplete.');
    }
}
foreach (['af-apf-postbit-field--fid', 'data-field-id='] as $needle) {
    if (!str_contains($apf, $needle)) {
        throw new RuntimeException('APF field identity metadata is missing.');
    }
}
foreach (['.pagination_current', '.pagination a:focus-visible', '[aria-disabled="true"]',
          '.af-apui-stat-item--posts', '.af-apui-postbit-plaque', 'box-sizing: border-box',
          '.sceditor-toolbar', 'textarea.sceditor-source', '.af-ccp-bar'] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException("ATF presentation contract is missing {$needle}.");
    }
}
if (!str_contains($editorJs, 'afAeApplyWysiwygAtfTheme')
    || !str_contains($editorJs, 'af-ae-atf-iframe-theme')
    || !str_contains($editorJs, 'enhanceAtfEditorShell')) {
    throw new RuntimeException('WYSIWYG iframe theme synchronization is missing.');
}
if (!preg_match('~<form[^>]+class="atf-editor atf-editor--quick-reply".*?<header class="atf-editor__header">.*?<div class="atf-editor__composer">.*?<textarea.*?</textarea>.*?<footer class="atf-editor__actions">~s', $quickReply)) {
    throw new RuntimeException('Quick reply does not render the semantic ATF editor DOM.');
}
if (!str_contains($atf, 'atf-pagination pagination') || !str_contains($atf, 'atf-pagination__items')) {
    throw new RuntimeException('Generated multipage markup is not normalized to an ATF nav component.');
}
if (!str_contains($counterJs, "document.body.classList.contains('atf-active')")
    || !str_contains($counterJs, 'MutationObserver(function (records)')) {
    throw new RuntimeException('AJAX quick-edit counter lifecycle is not ATF-aware.');
}

echo "ATF postbit, pagination and editor regression checks passed.\n";
