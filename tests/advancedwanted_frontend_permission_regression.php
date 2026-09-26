<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = require $root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/manifest.php';
$source = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['routes'] ?? []) !== [['script' => 'wanted.php']]
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_wanted_chip' => true]]
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false) {
    throw new RuntimeException('Wanted must declare only its own route plus its chip response fact');
}
if (str_contains(serialize($manifest['frontend']), 'showthread.php')) {
    throw new RuntimeException('Wanted must not claim the general showthread route');
}
if (!is_string($source)
    || !str_contains($source, "af_wanted_frontend_asset_allowed('catalog')")
    || !str_contains($source, "af_wanted_frontend_asset_allowed('dependency')")
    || !str_contains($source, "af_wanted_frontend_asset_allowed('modal',['has_wanted_chip'=>true])")) {
    throw new RuntimeException('Every owner-owned Wanted injection must use the permission wrapper');
}
if (!str_contains($source, "!af_is_blacklisted(AF_WANTED_ID)")) {
    throw new RuntimeException('Wanted permission wrapper must preserve its legacy blacklist guard');
}

echo "AdvancedWanted frontend permission pilot wiring passed.\n";
