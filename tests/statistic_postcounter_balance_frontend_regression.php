<?php
/** Static regression contract for task 5 frontend permission migration. */
$root = dirname(__DIR__);

function af_t5_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function af_t5_manifest(string $root, string $addon): array
{
    $manifest = require $root . '/inc/plugins/advancedfunctionality/addons/' . $addon . '/manifest.php';
    af_t5_assert(is_array($manifest), "{$addon} manifest loads");
    return $manifest;
}

$stat = af_t5_manifest($root, 'advancedstatistic');
af_t5_assert(($stat['frontend']['mode'] ?? '') === 'contextual', 'Statistics is contextual');
af_t5_assert(($stat['frontend']['directory_fallback'] ?? true) === false, 'Statistics disables directory fallback');
af_t5_assert(in_array(['script' => 'index.php'], $stat['frontend']['routes'] ?? [], true), 'Statistics owns index route (including pretty /)');
$statPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedstatistic/advancedstatistic.php');
af_t5_assert(strpos($statPhp, "af_frontend_asset_allowed('advancedstatistic', 'pre_output')") !== false, 'Statistics direct injection uses permission API');

$postCounter = af_t5_manifest($root, 'advancedpostcounter');
af_t5_assert(($postCounter['frontend']['mode'] ?? '') === 'contextual', 'PostCounter is contextual');
af_t5_assert(($postCounter['frontend']['directory_fallback'] ?? true) === false, 'PostCounter disables directory fallback');
$postCounterRoutes = $postCounter['frontend']['routes'] ?? [];
af_t5_assert(in_array(['script' => 'postsactivity.php'], $postCounterRoutes, true), 'PostCounter activity page owns runtime');
af_t5_assert(in_array(['script' => 'postsbyuser.php'], $postCounterRoutes, true), 'PostCounter user posts page owns runtime');
foreach (['showthread.php', 'member.php', 'index.php', 'balancemanage.php'] as $backendOnlyRoute) {
    af_t5_assert(!in_array(['script' => $backendOnlyRoute], $postCounterRoutes, true), "PostCounter data-only route {$backendOnlyRoute} does not inherit runtime");
}
$postCounterPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php');
af_t5_assert(strpos($postCounterPhp, "af_frontend_asset_allowed(AF_APC_ID, 'pre_output')") !== false, 'PostCounter owner injection uses permission API');

$balance = af_t5_manifest($root, 'balance');
af_t5_assert(($balance['frontend']['mode'] ?? '') === 'contextual', 'Balance is contextual');
af_t5_assert(($balance['frontend']['directory_fallback'] ?? true) === false, 'Balance disables directory fallback');
$balanceRoutes = $balance['frontend']['routes'] ?? [];
af_t5_assert(in_array(['script' => 'balancemanage.php'], $balanceRoutes, true), 'Balance standalone page owns runtime');
af_t5_assert(in_array(['script' => 'misc.php', 'action' => 'balance_manage'], $balanceRoutes, true), 'Balance misc manage route owns runtime');
foreach (['showthread.php', 'charactersheets.php', 'shop.php', 'index.php'] as $backendOnlyRoute) {
    af_t5_assert(!in_array(['script' => $backendOnlyRoute], $balanceRoutes, true), "Balance data-only route {$backendOnlyRoute} does not inherit runtime");
}
$balancePhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/balance/balance.php');
af_t5_assert(strpos($balancePhp, "af_balance_frontend_assets_allowed('queue')") !== false, 'Balance queue uses permission API');
af_t5_assert(strpos($balancePhp, "af_balance_frontend_assets_allowed('inline_config')") !== false, 'Balance inline config uses permission API');
$jqueryAt = strpos($balancePhp, '<script src="\' . $bburl . \'/jscripts/jquery.js?ver=1823"></script>');
$headerAt = strpos($balancePhp, '. $headerinclude', $jqueryAt === false ? 0 : $jqueryAt);
af_t5_assert($jqueryAt !== false && $headerAt !== false && $jqueryAt < $headerAt, 'Balance standalone jQuery ordering remains unchanged');

echo "OK\n";
