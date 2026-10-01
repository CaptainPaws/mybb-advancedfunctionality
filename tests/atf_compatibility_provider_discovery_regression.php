<?php

define('IN_MYBB', true);
$addons = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/';
define('AF_ADDONS', $addons);

$GLOBALS['provider_enabled'] = false;
function af_is_addon_enabled(string $id): bool
{
    return $id === 'advancedposteravatar' && $GLOBALS['provider_enabled'];
}
function af_discover_addons(): array
{
    $manifest = require AF_ADDONS . 'advancedposteravatar/manifest.php';
    $manifest['path'] = AF_ADDONS . 'advancedposteravatar/';
    $manifest['bootstrap'] = $manifest['path'] . $manifest['bootstrap'];
    return [$manifest];
}

require $addons . 'adaptivethemeframework/adaptivethemeframework.php';
$template = 'forumbit_depth2_forum_lastpost';
$seed = file_get_contents($addons . 'adaptivethemeframework/templates/' . $template . '.html');
$polluted = '<apa_uid_[{$lastpost_data[\'lastposteruid\']}]>' . $seed . '<apa_end>';

// C: a disabled owner is neither bootstrapped nor registered.
if (af_adaptivethemeframework_normalize_compatible_template($template, $polluted) !== null
    || !empty($GLOBALS['af_adaptivethemeframework_compatibility_normalizers'])) {
    throw new RuntimeException('disabled compatibility provider was used');
}

// A: activation discovery precedes normal addon/global initialization.
$GLOBALS['provider_enabled'] = true;
$result = af_adaptivethemeframework_normalize_compatible_template($template, $polluted);
if (($result['normalized_content'] ?? null) !== $seed) {
    throw new RuntimeException('activation-time provider discovery did not normalize APA markers');
}
$trace = $GLOBALS['af_adaptivethemeframework_normalizer_diagnostic'] ?? [];
$call = $trace['calls'][0] ?? [];
if (($trace['normalizers'][0] ?? '') !== 'advancedposteravatar::legacy_markers'
    || ($call['start_marker_count'] ?? 0) !== 1
    || ($call['end_marker_count'] ?? 0) !== 1
    || ($call['normalized_checksum'] ?? '') !== hash('sha256', $seed)) {
    throw new RuntimeException('safe marker/checksum diagnostic is incomplete');
}

// B: later frontend init attempts the same stable identity without duplication.
af_advancedposteravatar_init();
if (count($GLOBALS['af_adaptivethemeframework_compatibility_normalizers'] ?? []) !== 1) {
    throw new RuntimeException('frontend init duplicated compatibility normalizer registration');
}

$diagnostic = af_adaptivethemeframework_normalizer_diagnostic($seed, $polluted, $result);
foreach (['apa_called=yes', 'apa_changed=yes', 'start_marker_count=1', 'end_marker_count=1', 'matches_seed=yes'] as $field) {
    if (!str_contains($diagnostic, $field)) throw new RuntimeException("diagnostic omits {$field}");
}

echo "ATF compatibility provider discovery/load-order regression passed.\n";
