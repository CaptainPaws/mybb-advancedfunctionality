<?php

declare(strict_types=1);

define('IN_MYBB', 1);
$root = dirname(__DIR__);
$addons = $root.'/inc/plugins/advancedfunctionality/addons';

$globalAddons = [
    'advresponsivelayout' => ['file' => 'advresponsivelayout.php', 'resource' => "AF_ADVRWD_ID, 'runtime'"],
    'advancedaccountswitcher' => ['file' => 'advancedaccountswitcher.php', 'resource' => "AF_AAS_ID, 'pre_output'"],
    'advancedalertsandmentions' => ['file' => 'advancedalertsandmentions.php', 'resource' => "AF_AAM_ID, 'pre_output'"],
    'advancedfontawesome' => ['file' => 'advancedfontawesome.php', 'resource' => "AF_AFO_ID, 'pre_output'"],
    'advancedmenu' => ['file' => 'advancedmenu.php', 'resource' => "AF_AM_ID, 'pre_output'"],
    'headerwelcomeavatar' => ['file' => 'headerwelcomeavatar.php', 'resource' => "'headerwelcomeavatar', 'pre_output'"],
];

foreach ($globalAddons as $id => $expectation) {
    $manifest = require $addons.'/'.$id.'/manifest.php';
    if (($manifest['frontend']['mode'] ?? null) !== 'global') {
        throw new RuntimeException("{$id} must explicitly declare global frontend mode");
    }

    $source = file_get_contents($addons.'/'.$id.'/'.$expectation['file']);
    if (!is_string($source)
        || !str_contains($source, 'af_frontend_asset_allowed('.$expectation['resource'].')')) {
        throw new RuntimeException("{$id} owner-owned frontend output must use the permission API");
    }
}

$alertsSource = file_get_contents($addons.'/advancedalertsandmentions/advancedalertsandmentions.php');
if (!is_string($alertsSource)
    || !str_contains($alertsSource, "af_frontend_asset_allowed(AF_AAM_ID, 'bootstrap')")) {
    throw new RuntimeException('Alerts template-owned assets must be gated before bootstrap output');
}

$fastNews = require $addons.'/fastnews/manifest.php';
if (isset($fastNews['frontend']) || isset($fastNews['assets'])) {
    throw new RuntimeException('FastNews has no frontend assets and must not declare artificial frontend metadata');
}

echo "Global addon frontend manifests and owner-owned gates passed.\n";
