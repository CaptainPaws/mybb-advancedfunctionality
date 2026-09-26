<?php
$manifest = require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedgallery/manifest.php';
$source = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['routes'] ?? []) !== [['script' => 'gallery.php']]
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_gallery_picker' => true]]
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false) throw new RuntimeException('Gallery frontend manifest mismatch.');
foreach (["stripos(\$page, '<html')", 'af_advancededitor_response_facts', "['has_gallery_picker' => \$hasPicker]", "af_frontend_asset_allowed(AF_AG_ID, 'pre_output'"] as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException('Gallery context guard missing: '.$needle);
}
if (str_contains($source, "'showthread.php',\n            'newreply.php'")) throw new RuntimeException('Gallery still uses the coarse editor route list.');
$cfg = strpos($source, '$cfgTag =');
$runtime = strpos($source, '$inject .= $cfgTag . $jsTag;');
if ($cfg === false || $runtime === false || $cfg > $runtime) throw new RuntimeException('Gallery inline config/runtime order changed.');
echo "Advanced Gallery route and picker permission checks passed.\n";
