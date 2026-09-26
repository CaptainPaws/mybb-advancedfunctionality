<?php
/** Static contract for the deliberately narrow Knowledge Base migration. */
$root = dirname(__DIR__);
$manifest = require $root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php';
$source = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_kb_chip' => true]]) {
    throw new RuntimeException('Knowledge Base frontend contract is not contextual/component-aware.');
}

$routes = $manifest['frontend']['routes'] ?? [];
foreach ([
    ['script' => 'kb.php', 'action' => ''],
    ['script' => 'kb.php', 'action' => 'kb_edit'],
    ['script' => 'misc.php', 'action' => 'kb'],
] as $route) {
    if (!in_array($route, $routes, true)) {
        throw new RuntimeException('Missing KB HTML route: ' . serialize($route));
    }
}

foreach (['kb_get', 'kb_list', 'kb_types', 'kb_children', 'knowledgebase_entry',
          'kb_character_apply', 'kb_manage_categories_save'] as $endpoint) {
    foreach (['kb.php', 'misc.php'] as $script) {
        if (in_array(['script' => $script, 'action' => $endpoint], $routes, true)) {
            throw new RuntimeException("Backend/AJAX endpoint leaked into frontend routes: {$endpoint}");
        }
    }
}

foreach ([
    "af_kb_frontend_asset_allowed('page_runtime')",
    "af_kb_frontend_asset_allowed('chip_runtime', ['has_kb_chip' => true])",
    "af_kb_frontend_asset_allowed('sceditor_stack')",
    "af_kb_frontend_asset_allowed('header_ensure')",
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing owner-owned KB permission gate: ' . $needle);
    }
}

if (!str_contains($source, '$jsTag' . "\n" . '                . $chipsJs' . "\n"
    . '                . $insertJs' . "\n" . '                . $langTag' . "\n"
    . '                . $endpointTag' . "\n" . '                . $runtimeModeTag')) {
    throw new RuntimeException('KB runtime/config injection order changed.');
}

foreach (['showthread.php', 'charactersheets.php', 'shop.php', 'inventory.php', 'newthread.php'] as $script) {
    foreach ($routes as $route) {
        if (($route['script'] ?? '') === $script) {
            throw new RuntimeException("Backend consumer or broad integration leaked into KB routes: {$script}");
        }
    }
}

echo "Knowledge Base contextual routes, chip fact, endpoint isolation, and asset order passed.\n";
