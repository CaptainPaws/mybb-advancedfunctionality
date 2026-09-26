<?php
$manifest = require __DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedinventory/manifest.php';
$source = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_inventory_component' => true]]) {
    throw new RuntimeException('Inventory frontend contract mismatch.');
}

$routes = $manifest['frontend']['routes'] ?? [];
foreach ([['script' => 'inventory.php'], ['script' => 'inventories.php'], ['script' => 'abilities.php']] as $route) {
    if (!in_array($route, $routes, true)) {
        throw new RuntimeException('Inventory page route missing: ' . $route['script']);
    }
}

foreach (["af_frontend_asset_allowed(AF_ADVINV_ID", "['has_inventory_component' => true]"] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Inventory owner-owned permission guard missing: ' . $needle);
    }
}

foreach (['shop.php', 'charactersheets.php', 'showthread.php', 'member.php', 'index.php'] as $forbidden) {
    if (in_array(['script' => $forbidden], $routes, true)) {
        throw new RuntimeException('Backend/possible integration leaked into Inventory routes: ' . $forbidden);
    }
}

echo "Advanced Inventory frontend permission checks passed.\n";
