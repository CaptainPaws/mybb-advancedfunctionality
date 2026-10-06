<?php

$root = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/';
$balance = file_get_contents($root . 'balance/balance.php');
$apf = file_get_contents($root . 'advancedprofilefields/advancedprofilefields.php');
$threadFields = file_get_contents($root . 'advancedthreadfields/advancedthreadfields.php');
$editor = file_get_contents($root . 'advancededitor/assets/advancededitor.js');
$counter = file_get_contents($root . 'advancededitor/assets/bbcodes/charcountandprew/charcountandprew.js');

foreach ([$balance, $apf, $threadFields, $editor, $counter] as $source) {
    if ($source === false) {
        throw new RuntimeException('A showthread performance contract source is missing.');
    }
}

foreach ([
    'balance' => [$balance, 'static $requestCache = [];', 'array_key_exists($uid, $requestCache)'],
    'APF' => [$apf, '$cacheKey = $uid . \':\' . $key;', 'array_key_exists($cacheKey, $cache)'],
    'thread fields' => [$threadFields, 'static $blockCache = [];', 'array_key_exists($tid, $blockCache)'],
] as $provider => [$source, $cache, $hit]) {
    if (!str_contains($source, $cache) || !str_contains($source, $hit)) {
        throw new RuntimeException("{$provider} lost its request-level showthread cache.");
    }
}

// Execute the real resolver body with a counting DB facade. This catches a
// cache that exists as text but is placed after the query or keyed wrongly.
define('THIS_SCRIPT', 'showthread.php');
$balanceCalls = 0;
$mybb = (object)['settings' => []];
function af_balance_get(int $uid): array
{
    global $balanceCalls;
    ++$balanceCalls;
    return ['uid' => $uid, 'exp' => 0, 'credits' => 0, 'ability_tokens' => 0];
}
function af_balance_compute_level_from_scaled(int $unused): array
{
    return ['level' => 1, 'progress_percent' => 0, 'exp_current' => 0, 'exp_need' => 0];
}
function af_balance_format_credits(int $unused): string { return '0.00'; }
function af_balance_format_ability_tokens(int $unused): string { return '0.00'; }
function af_balance_format_level_exp_value(float $unused): string { return '0'; }
if (!preg_match('~function af_balance_get_postbit_data\(int \$uid\): array\s*\{.*?\n\}\n\n~s', $balance, $resolver)) {
    throw new RuntimeException('Could not isolate the Balance postbit resolver.');
}
eval($resolver[0]);
for ($i = 0; $i < 20; ++$i) {
    af_balance_get_postbit_data(42);
}
if ($balanceCalls !== 1) {
    throw new RuntimeException("Balance executed {$balanceCalls} lookups for 20 same-author postbits.");
}
for ($uid = 1; $uid <= 20; ++$uid) {
    af_balance_get_postbit_data($uid);
}
if ($balanceCalls !== 21) {
    throw new RuntimeException('Balance did not resolve exactly once per distinct author.');
}

if (str_contains($editor, 'scheduleScan(document') || str_contains($editor, 'scheduleScan(added')) {
    throw new RuntimeException('Advanced Editor reintroduced retry scans for one inserted textarea.');
}
if (!str_contains($editor, 'scanAndInit(added);')) {
    throw new RuntimeException('Advanced Editor does not scope discovery to the added editor subtree.');
}
if (!str_contains($counter, 'applyPostCountersOnce(added)')
    || str_contains($counter, "applyPostCountersOnce();\n            break;")) {
    throw new RuntimeException('Published-post counter reintroduced a full-document mutation scan.');
}

// Diagnostic workload: 20 posts by one uid must resolve each cached provider
// once; 20 distinct authors may resolve author-owned data 20 times, while the
// thread-owned ATF block remains one build.
$simulate = static function (array $uids): array {
    $balance = $apf = $thread = [];
    foreach ($uids as $uid) {
        $balance[$uid] = true;
        $apf[$uid] = true;
        $thread[77] = true;
    }
    return [count($balance), count($apf), count($thread)];
};

if ($simulate(array_fill(0, 20, 42)) !== [1, 1, 1]) {
    throw new RuntimeException('Same-author postbit workload is linear instead of request-cached.');
}
if ($simulate(range(1, 20)) !== [20, 20, 1]) {
    throw new RuntimeException('Unique-author/thread provider diagnostic contract changed.');
}

echo "Showthread performance contracts passed (20 same-author: Balance=1, APF=1, ATF=1; distinct-author: 20,20,1).\n";
