<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$bundle = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php');
$manifest = require $root.'/inc/plugins/advancedfunctionality/addons/advancedjsbandle/manifest.php';

if ($core === false || $bundle === false) {
    throw new RuntimeException('Unable to read bundle sources');
}

foreach (['mode', 'directory_fallback', 'resources'] as $key) {
    if (!array_key_exists($key, $manifest['frontend'] ?? [])) {
        throw new RuntimeException("Bundle frontend contract misses {$key}");
    }
}
if ($manifest['frontend']['directory_fallback'] !== false) {
    throw new RuntimeException('The central directory scan must not enqueue the complete bundle catalog');
}

$permission = strpos($bundle, "af_frontend_asset_allowed(AF_AJSB_ID, \$file)");
$emit = strpos($bundle, 'af_ajsb_build_script_tags($allowedJs, false)');
if ($permission === false || $emit === false || $permission > $emit) {
    throw new RuntimeException('Resource permission must determine the emitted source set');
}
if (!str_contains($bundle, "array_intersect(\$allowed['js'], \$jsFiles)")) {
    throw new RuntimeException('Bundle must preserve declared executable order');
}

$inject = strpos($core, '$page = af_inject_enabled_addon_assets($page);');
$late = strpos($core, 'af_advancedjsbandle_pre_output($page);', $inject ?: 0);
if ($inject === false || $late === false || $late < $inject) {
    throw new RuntimeException('Bundle transformation is not after source-owner injection');
}

foreach ($manifest['frontend']['resources'] as $resource => $rule) {
    if (!is_array($rule) || !isset($rule['mode'])) {
        throw new RuntimeException("Invalid permission rule for {$resource}");
    }
}

echo "AdvancedJSBundle permission, late-transform, and ordering contract passed.\n";
