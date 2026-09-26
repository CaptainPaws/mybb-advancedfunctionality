<?php
$manifest = require __DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedappearance/manifest.php';
$source = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['routes'] ?? []) !== [['script' => 'apstudio.php'], ['script' => 'fittingroom.php']]
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_appearance_runtime' => true]]
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false) {
    throw new RuntimeException('Appearance frontend contract mismatch.');
}
foreach ([
    "af_frontend_asset_allowed(AF_AA_ID, 'runtime_css'",
    "af_frontend_asset_allowed(AF_AA_ID, 'modal_scope'",
    "af_frontend_asset_allowed(AF_AA_ID, 'page_runtime'",
    "['has_appearance_runtime' => true]",
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Appearance owner-owned permission guard missing: ' . $needle);
    }
}
foreach (['shop.php', 'index.php', 'member.php', 'showthread.php'] as $forbidden) {
    if (str_contains(serialize($manifest['frontend']['routes']), $forbidden)) {
        throw new RuntimeException('Appearance integration leaked into page routes: ' . $forbidden);
    }
}

echo "Advanced Appearance frontend permission checks passed.\n";
