<?php

$root = dirname(__DIR__);
$addons = $root . '/inc/plugins/advancedfunctionality/addons/';
$atf = file_get_contents($addons . 'adaptivethemeframework/adaptivethemeframework.php');
$template = file_get_contents($addons . 'adaptivethemeframework/templates/postbit_classic.html');
$css = file_get_contents($addons . 'adaptivethemeframework/assets/adaptivethemeframework.css');
$apui = file_get_contents($addons . 'advancedprofileui/advancedprofileui.php');
$apf = file_get_contents($addons . 'advancedprofilefields/advancedprofilefields.php');
$editorJs = file_get_contents($addons . 'advancededitor/assets/advancededitor.js');

foreach ([$atf, $template, $css, $apui, $apf, $editorJs] as $source) {
    if ($source === false) {
        throw new RuntimeException('A required postbit/editor source is missing.');
    }
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
    || !str_contains($editorJs, 'af-ae-atf-iframe-theme')) {
    throw new RuntimeException('WYSIWYG iframe theme synchronization is missing.');
}

echo "ATF postbit, pagination and editor regression checks passed.\n";
