<?php

$root = dirname(__DIR__);
$addons = $root . '/inc/plugins/advancedfunctionality/addons/';
$atf = file_get_contents($addons . 'adaptivethemeframework/adaptivethemeframework.php');
$template = file_get_contents($addons . 'adaptivethemeframework/templates/postbit_classic.html');
$paginationTemplate = file_get_contents($addons . 'adaptivethemeframework/templates/multipage.html');
$quickReplyTemplate = file_get_contents($addons . 'adaptivethemeframework/templates/showthread_quickreply.html');
$css = file_get_contents($addons . 'adaptivethemeframework/assets/adaptivethemeframework.css');
$apui = file_get_contents($addons . 'advancedprofileui/advancedprofileui.php');
$apf = file_get_contents($addons . 'advancedprofilefields/advancedprofilefields.php');
$editorJs = file_get_contents($addons . 'advancededitor/assets/advancededitor.js');

foreach ([$atf, $template, $css, $apui, $apf, $editorJs] as $source) {
    if ($source === false) {
        throw new RuntimeException('A required postbit/editor source is missing.');
    }
}
foreach (['class="atf-editor atf-quick-reply"', '{$option_signature}', '{$lang->disable_smilies}', 'atf-quick-reply__options', 'atf-quick-reply__actions'] as $needle) {
    if (!str_contains($quickReplyTemplate, $needle)) {
        throw new RuntimeException("ATF quick-reply markup is missing {$needle}.");
    }
}
if (preg_match('~<input[^>]+type="checkbox"(?![^>]*>\s*<span|[^>]*>[^<]*</label>)~', $quickReplyTemplate)) {
    throw new RuntimeException('ATF quick reply contains an unlabeled checkbox.');
}
foreach (['atf-pagination', 'atf-pagination__items', '{$jumptopage}', 'aria-label='] as $needle) {
    if (!str_contains($paginationTemplate, $needle)) {
        throw new RuntimeException("ATF pagination markup is missing {$needle}.");
    }
}
if (!str_contains($css, 'flex-wrap: nowrap') || !str_contains($css, 'display: inline-flex')) {
    throw new RuntimeException('ATF pagination is not a single compact row.');
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
foreach (['.pagination_current', '.atf-pagination a:focus-visible', '[aria-disabled="true"]',
          '.af-apui-stat-item--posts', '.af-apui-postbit-plaque', 'box-sizing: border-box',
          '.sceditor-toolbar', 'textarea.sceditor-source', '.af-ccp-bar'] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException("ATF presentation contract is missing {$needle}.");
    }
}
if (str_contains($css, 'grid-template-columns: repeat(4')) {
    throw new RuntimeException('Post statistics were flattened into a four-column row.');
}
foreach (['body.atf-active .wrapper', 'body.atf-active #content', 'body.atf-active .post {',
          'width: 100vw'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException("Unsafe global layout rule detected: {$forbidden}.");
    }
}
$payloadStart = strpos($apui, 'function af_apui_get_profile_character_payload');
$payloadEnd = strpos($apui, 'function af_apui_render_profile_stats', $payloadStart);
$payloadSource = substr($apui, $payloadStart, $payloadEnd - $payloadStart);
if (!str_contains($payloadSource, 'af_characterworkflow_resolve_active_application')
    || str_contains(strtolower($payloadSource), 'wanted')) {
    throw new RuntimeException('Character name is not sourced exclusively from the approved application.');
}
$apuiCss = file_get_contents($addons . 'advancedprofileui/assets/advancedprofileui.css');
foreach (['margin-inline: 0 !important', 'max-width: 100%', 'margin-bottom: 0'] as $needle) {
    if (!str_contains($apuiCss, $needle)) {
        throw new RuntimeException("The bounded postbit rail contract is missing {$needle}.");
    }
}
if (!str_contains($editorJs, 'afAeApplyWysiwygAtfTheme')
    || !str_contains($editorJs, 'af-ae-atf-iframe-theme')) {
    throw new RuntimeException('WYSIWYG iframe theme synchronization is missing.');
}
foreach (['af:editor-ready', "form.classList.add('atf-quick-edit')", 'announceEditorReady(ta, inst)'] as $needle) {
    if (!str_contains($editorJs, $needle)) {
        throw new RuntimeException("AJAX quick-edit lifecycle is missing {$needle}.");
    }
}
$counterJs = file_get_contents($addons . 'advancededitor/assets/bbcodes/bbcodes/charcountandprew/charcountandprew.js');
foreach (['detail.quickEdit', 'initFormCounterAndPreview(detail.textarea)', "post.querySelector('.atf-post__meta-line')"] as $needle) {
    if (!str_contains($counterJs, $needle)) {
        throw new RuntimeException("Quick-edit counter contract is missing {$needle}.");
    }
}

echo "ATF postbit, pagination and editor regression checks passed.\n";
