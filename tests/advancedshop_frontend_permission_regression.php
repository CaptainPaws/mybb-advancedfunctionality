<?php
$manifest = require __DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedshop/manifest.php';
$source = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_shop_component' => true]]) {
    throw new RuntimeException('Shop frontend contract mismatch.');
}
$routes = $manifest['frontend']['routes'] ?? [];
foreach ([['script' => 'shop.php'], ['script' => 'shop_manage.php']] as $route) {
    if (!in_array($route, $routes, true)) {
        throw new RuntimeException('Shop page route missing: ' . $route['script']);
    }
}
foreach (["af_frontend_asset_allowed(AF_ADVSHOP_ID, 'component_runtime'", "af_frontend_asset_allowed(AF_ADVSHOP_ID, 'pre_output'", "['has_shop_component' => true]"] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Shop owner-owned permission guard missing: ' . $needle);
    }
}
$config = strpos($source, 'window.AFSHOP.endpointScript');
$runtime = strpos($source, "advancedshop.js?v=");
if ($config === false || $runtime === false || $config > $runtime) {
    throw new RuntimeException('Shop config must precede its runtime.');
}
foreach (['inventory.php', 'kb.php', 'index.php', 'showthread.php', 'charactersheets.php'] as $forbidden) {
    if (in_array(['script' => $forbidden], $routes, true)) {
        throw new RuntimeException('Shop backend/integration dependency leaked into routes: ' . $forbidden);
    }
}

echo "Advanced Shop frontend permission checks passed.\n";
