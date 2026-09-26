<?php

declare(strict_types=1);

define('IN_MYBB', 1);
$root = dirname(__DIR__);
$addons = $root . '/inc/plugins/advancedfunctionality/addons';
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality.php');
if (!is_string($core)) {
    throw new RuntimeException('Unable to read AdvancedFunctionality core');
}

/** Extract a top-level function without bootstrapping MyBB. */
function contextualExtractFunction(string $source, string $name): string
{
    $tokens = token_get_all($source);
    $capture = false;
    $named = false;
    $depth = 0;
    $result = '';
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$capture && is_array($token) && $token[0] === T_FUNCTION) {
            $capture = true;
            $named = false;
            $result = $text;
            continue;
        }
        if (!$capture) continue;
        $result .= $text;
        if (!$named && is_array($token) && $token[0] === T_STRING) {
            if ($token[1] !== $name) {
                $capture = false;
                $result = '';
                continue;
            }
            $named = true;
        }
        if ($named && $text === '{') $depth++;
        if ($named && $text === '}' && --$depth === 0) return $result;
    }
    throw new RuntimeException("Could not extract {$name}");
}

foreach (['af_normalize_script_name', 'af_resolve_frontend_manifest', 'af_frontend_route_matches',
          'af_frontend_asset_decision', 'af_frontend_asset_allowed'] as $function) {
    eval(contextualExtractFunction($core, $function));
}

$manifests = [];
foreach (['advancedrules', 'advancedcharacters', 'advancedposteravatar', 'advancedprofilefields', 'advancedprofileui'] as $id) {
    $manifests[$id] = require $addons . '/' . $id . '/manifest.php';
    $frontend = af_resolve_frontend_manifest($manifests[$id]);
    if ($frontend['mode'] !== 'contextual' || $frontend['directory_fallback']) {
        throw new RuntimeException("{$id} must be contextual and disable directory fallback after its permission gate");
    }
}

$contexts = [
    'root' => ['script' => 'index.php', 'action' => ''],
    'index' => ['script' => 'index.php', 'action' => ''],
    'forumdisplay' => ['script' => 'forumdisplay.php', 'action' => ''],
    'showthread' => ['script' => 'showthread.php', 'action' => ''],
    'member' => ['script' => 'member.php', 'action' => 'profile'],
    'online' => ['script' => 'online.php', 'action' => ''],
    'memberlist' => ['script' => 'memberlist.php', 'action' => ''],
    'rules' => ['script' => 'misc.php', 'action' => 'advancedrules'],
    'characters' => ['script' => 'characters.php', 'action' => ''],
];

foreach ($contexts as $name => $context) {
    $rulesExpected = $name === 'rules';
    $charactersExpected = $name === 'characters';
    $avatarExpected = in_array($name, ['root', 'index', 'forumdisplay', 'online'], true);
    if (af_frontend_asset_allowed($manifests['advancedrules'], null, $context) !== $rulesExpected
        || af_frontend_asset_allowed($manifests['advancedcharacters'], null, $context) !== $charactersExpected
        || af_frontend_asset_allowed($manifests['advancedposteravatar'], null, $context) !== $avatarExpected) {
        throw new RuntimeException("Unexpected route decision for {$name}");
    }
}

foreach (['advancedprofilefields' => 'has_apf_output', 'advancedprofileui' => 'has_apui_output'] as $id => $fact) {
    foreach ([$contexts['showthread'], $contexts['member'], $contexts['index'], $contexts['forumdisplay']] as $context) {
        if (af_frontend_asset_allowed($manifests[$id], null, $context)
            || !af_frontend_asset_allowed($manifests[$id], null, $context, [$fact => true])
            || af_frontend_asset_allowed($manifests[$id], null, $context, [$fact => false])) {
            throw new RuntimeException("{$id} must require its rendered component fact");
        }
    }
}

$ownerGates = [
    'advancedposteravatar/advancedposteravatar.php' => "af_frontend_asset_allowed(AF_APA_ID, 'pre_output')",
    'advancedprofilefields/advancedprofilefields.php' => "af_frontend_asset_allowed(AF_APF_ID, 'pre_output', null, ['has_apf_output' => \$hasOutput])",
    'advancedprofileui/advancedprofileui.php' => "af_frontend_asset_allowed(AF_APUI_ID, 'pre_output', null, ['has_apui_output' => true])",
];
foreach ($ownerGates as $file => $needle) {
    $source = file_get_contents($addons . '/' . $file);
    if (!is_string($source) || !str_contains($source, $needle)) {
        throw new RuntimeException("Missing owner permission gate in {$file}");
    }
}

foreach (['smarturltitles', 'indexredirect'] as $id) {
    $manifest = require $addons . '/' . $id . '/manifest.php';
    if (isset($manifest['frontend']) || isset($manifest['assets'])) {
        throw new RuntimeException("{$id} is server-side and must not declare frontend metadata");
    }
    $assetFiles = glob($addons . '/' . $id . '/assets/*.{css,js}', GLOB_BRACE) ?: [];
    if ($assetFiles !== []) {
        throw new RuntimeException("{$id} must not expose placeholder frontend assets");
    }
}

if (is_dir($addons . '/privacyshield')) {
    throw new RuntimeException('Privacy Shield unexpectedly appeared; it requires a separate response-marker audit');
}

echo "Contextual/profile addon frontend matrix passed.\n";
