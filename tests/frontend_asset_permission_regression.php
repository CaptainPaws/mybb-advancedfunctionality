<?php

declare(strict_types=1);

$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
if ($core === false) {
    throw new RuntimeException('Unable to read AdvancedFunctionality core');
}

/** Extract one top-level named function from the plugin without bootstrapping MyBB. */
function extractFunction(string $source, string $name): string
{
    $tokens = token_get_all($source);
    $capturing = false;
    $foundName = false;
    $depth = 0;
    $result = '';

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$capturing && is_array($token) && $token[0] === T_FUNCTION) {
            $capturing = true;
            $foundName = false;
            $depth = 0;
            $result = $text;
            continue;
        }
        if (!$capturing) {
            continue;
        }

        $result .= $text;
        if (!$foundName && is_array($token) && $token[0] === T_STRING) {
            if ($token[1] !== $name) {
                $capturing = false;
                $result = '';
                continue;
            }
            $foundName = true;
        }
        if ($foundName && $text === '{') {
            $depth++;
        } elseif ($foundName && $text === '}' && --$depth === 0) {
            return $result;
        }
    }

    throw new RuntimeException("Could not extract {$name}");
}

foreach ([
    'af_normalize_script_name',
    'af_is_ajax_request',
    'af_frontend_request_context',
    'af_resolve_frontend_manifest',
    'af_frontend_route_matches',
    'af_frontend_asset_decision',
    'af_frontend_asset_allowed',
] as $function) {
    eval(extractFunction($core, $function));
}

$wantedContext = ['script' => 'wanted.php', 'action' => '', 'fid' => 0, 'tid' => 0];
$indexContext = ['script' => 'index.php', 'action' => '', 'fid' => 0, 'tid' => 0];

if (!af_frontend_asset_allowed(['id' => 'legacy'], null, $indexContext)) {
    throw new RuntimeException('A manifest without frontend metadata must remain allowed');
}
if (!af_frontend_asset_allowed(['id' => 'global', 'frontend' => ['mode' => 'global']], null, $indexContext)) {
    throw new RuntimeException('Global frontend mode must be allowed');
}
$resourceAware = [
    'id' => 'bundle',
    'frontend' => [
        'mode' => 'global',
        'directory_fallback' => false,
        'resources' => [
            'thread.js' => ['mode' => 'contextual', 'routes' => [['script' => 'showthread.php']]],
        ],
    ],
];
if (!af_frontend_asset_allowed($resourceAware, 'thread.js', ['script' => 'showthread.php'])
    || af_frontend_asset_allowed($resourceAware, 'thread.js', $indexContext)
    || !af_frontend_asset_allowed($resourceAware, 'unknown.js', $indexContext)) {
    throw new RuntimeException('Resource frontend rules must narrow the parent addon decision');
}
$resourceManifest = af_resolve_frontend_manifest($resourceAware);
if ($resourceManifest['directory_fallback'] !== false) {
    throw new RuntimeException('Global manifests must be able to disable directory fallback');
}

$contextual = [
    'id' => 'wanted',
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script' => 'wanted.php']],
    ],
];
if (!af_frontend_asset_allowed($contextual, null, $wantedContext)
    || af_frontend_asset_allowed($contextual, null, $indexContext)
    || af_frontend_asset_allowed($contextual, null, ['script' => 'member.php'])) {
    throw new RuntimeException('Contextual script matching returned an unexpected decision');
}

$responseAware = [
    'id' => 'wanted',
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script' => 'wanted.php']],
        'response_rules' => [['has_wanted_chip' => true]],
    ],
];
if (!af_frontend_asset_allowed($responseAware, 'modal', $indexContext, ['has_wanted_chip' => true])
    || af_frontend_asset_allowed($responseAware, 'modal', $indexContext)
    || af_frontend_asset_allowed($responseAware, 'modal', $indexContext, ['has_wanted_chip' => false])) {
    throw new RuntimeException('Response-aware permission did not require the declared response fact');
}

$actionManifest = [
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script' => 'usercp.php', 'action' => 'application_ucp']],
    ],
];
if (!af_frontend_asset_allowed($actionManifest, null, ['script' => 'usercp.php', 'action' => 'application_ucp'])
    || af_frontend_asset_allowed($actionManifest, null, ['script' => 'usercp.php', 'action' => 'other'])) {
    throw new RuntimeException('Contextual action matching returned an unexpected decision');
}

$forumManifest = [
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script' => 'showthread.php', 'fid' => [1, 2, 3]]],
    ],
];
if (!af_frontend_asset_allowed($forumManifest, null, ['script' => 'showthread.php', 'fid' => 2])
    || af_frontend_asset_allowed($forumManifest, null, ['script' => 'showthread.php', 'fid' => 8])) {
    throw new RuntimeException('Contextual fid matching returned an unexpected decision');
}

foreach ([
    ['frontend' => 'wrong'],
    ['frontend' => ['mode' => []]],
    ['frontend' => ['mode' => 'unknown']],
    ['frontend' => ['mode' => 'contextual', 'routes' => 'wanted.php']],
    ['frontend' => ['mode' => 'contextual', 'routes' => [['script' => []]]]],
    ['frontend' => ['mode' => 'contextual', 'routes' => [], 'response_rules' => 'wrong']],
    ['frontend' => ['mode' => 'contextual', 'routes' => [], 'response_rules' => [[]]]],
    ['frontend' => ['mode' => 'contextual', 'routes' => [], 'directory_fallback' => 'no']],
] as $malformed) {
    $decision = af_frontend_asset_decision($malformed, null, $indexContext, ['has_component' => true]);
    if (!$decision['allowed'] || !$decision['legacy_fallback'] || $decision['valid']) {
        throw new RuntimeException('Malformed frontend metadata did not fail open to legacy mode');
    }
    if ($decision['response_facts'] !== ['has_component' => true]) {
        throw new RuntimeException('Response facts were not kept separate in the decision result');
    }
}

$_SERVER['SCRIPT_NAME'] = '/forum/showthread.php';
$_SERVER['REQUEST_METHOD'] = 'post';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_REQUEST = ['action' => 'View', 'fid' => '12', 'tid' => '481'];
$requestContext = af_frontend_request_context();
if ($requestContext['script'] !== 'showthread.php'
    || $requestContext['action'] !== 'view'
    || $requestContext['fid'] !== 12
    || $requestContext['tid'] !== 481
    || !$requestContext['ajax']
    || $requestContext['method'] !== 'POST'
    || !isset($requestContext['response_capabilities']['late_response_facts'])) {
    throw new RuntimeException('Frontend request context was not normalized');
}

$collectorStart = strpos($core, 'function af_collect_enabled_addon_assets()');
$collectorEnd = strpos($core, 'function af_should_skip_assets_injection(', $collectorStart);
$collector = substr($core, $collectorStart, $collectorEnd - $collectorStart);
$permissionPosition = strpos($collector, 'af_frontend_asset_allowed($meta)');
$blacklistPosition = strpos($collector, 'af_is_blacklisted($id)');
if ($permissionPosition === false || $blacklistPosition === false || $permissionPosition > $blacklistPosition) {
    throw new RuntimeException('Collector permission must run before the legacy blacklist');
}
if (!str_contains($collector, "\$manifestAssets = \$meta['assets'] ?? null")
    || !str_contains($collector, "scandir(\$assetsDir)")
    || !str_contains($collector, "empty(\$frontend['directory_fallback'])")) {
    throw new RuntimeException('Manifest assets or directory fallback was removed');
}

echo "Frontend context, manifest resolver, route matcher, and permission API passed.\n";
