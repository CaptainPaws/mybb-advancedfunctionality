<?php
/** Regression coverage for the KB-owned SCEditor chip picker integration. */

$root = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/knowledgebase/';
$manifest = require $root . 'manifest.php';
$php = file_get_contents($root . 'knowledgebase.php');
$javascript = file_get_contents($root . 'assets/knowledgebase_insert.js');

if (!is_string($php) || !is_string($javascript)) {
    throw new RuntimeException('Unable to inspect the Knowledge Base editor integration.');
}

if (!in_array(['has_supported_editor' => true], $manifest['frontend']['response_rules'] ?? [], true)) {
    throw new RuntimeException('Knowledge Base does not own a manifest permission for editor responses.');
}

foreach ([
    'hasSupportedEditor',
    'af-kb-editor',
    'af-atf-bbcode-editor',
    "af_kb_frontend_asset_allowed('editor_integration', ['has_supported_editor' => true])",
    'knowledgebase_insert.js',
] as $needle) {
    if (!str_contains($php, $needle)) {
        throw new RuntimeException('Missing KB editor delivery contract: ' . $needle);
    }
}

foreach ([
    "$.sceditor.command.set('af_kb_insert'",
    "toolbar + '|af_kb_insert'",
    "'[kb=' + resolvedType + ':' + item.key + ']'",
    "afKbEndpoint('types'",
    "afKbEndpoint('list'",
    'snapshotSelection(target)',
    'ensureToolbarButtonsForAll()',
] as $needle) {
    if (!str_contains($javascript, $needle)) {
        throw new RuntimeException('Missing legacy KB picker behavior: ' . $needle);
    }
}

if (str_contains($javascript, 'items = window.') || str_contains($php, 'window.afKbEntries=')) {
    throw new RuntimeException('KB picker must continue to load entries lazily from JSON endpoints.');
}

echo "KB SCEditor chip button integration checks passed.\n";
