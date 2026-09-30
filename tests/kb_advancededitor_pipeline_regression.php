<?php
/** Regression coverage for the KB create/edit -> AdvancedEditor pipeline. */

$root = dirname(__DIR__);
$editorManifest = require $root . '/inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php';
$editor = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
$editorJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js');
$kbJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');

if (!is_string($editor) || !is_string($editorJs) || !is_string($kbJs)) {
    throw new RuntimeException('Unable to read the KB/AdvancedEditor pipeline sources.');
}

$templateFiles = [
    'knowledgebase_edit.html',
    'knowledgebase_type_edit.html',
    'knowledgebase_blocks_edit_item.html',
];
foreach ($templateFiles as $templateFile) {
    $template = file_get_contents(
        $root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/templates/' . $templateFile
    );
    if (!is_string($template) || !str_contains($template, 'class="af-kb-editor"')) {
        throw new RuntimeException("KB editor marker missing from {$templateFile}.");
    }
}

foreach (['kb.php', 'misc.php'] as $script) {
    foreach (['kb_edit', 'kb_type_edit'] as $action) {
        $route = ['script' => $script, 'action' => $action];
        if (!in_array($route, $editorManifest['frontend']['routes'] ?? [], true)) {
            throw new RuntimeException('AdvancedEditor manifest blocks KB route: ' . serialize($route));
        }
    }
}

if (($editorManifest['frontend']['directory_fallback'] ?? null) !== false
    || !in_array(['has_supported_editor' => true], $editorManifest['frontend']['response_rules'] ?? [], true)) {
    throw new RuntimeException('AdvancedEditor late response permission contract changed.');
}

foreach ([
    "'has_kb_editor' => \$hasKbEditor",
    "if (!empty(\$facts['has_kb_editor']))",
    "'textarea.af-kb-editor'",
] as $needle) {
    if (!str_contains($editor, $needle)) {
        throw new RuntimeException('Missing AdvancedEditor KB response/selector contract: ' . $needle);
    }
}

if (!str_contains($editorJs, 'bindDynamicTextareaObserver()')
    || !str_contains($editorJs, 'scheduleScan(added, 6, 90)')) {
    throw new RuntimeException('AdvancedEditor no longer initializes dynamic KB block textareas.');
}

if (!str_contains($kbJs, 'isAdvancedEditorOwnedField(field)')
    || !str_contains($kbJs, 'field.matches(selector)')) {
    throw new RuntimeException('KB can race AdvancedEditor with its fallback toolbar.');
}

echo "KB create/edit/type/block AdvancedEditor pipeline regression checks passed.\n";
