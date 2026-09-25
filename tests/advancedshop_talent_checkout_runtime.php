<?php

// Exercise the real Advanced Shop currency adapter used by public checkout.
// The balance module stores both currencies in scaled (minor) units.
define('IN_MYBB', 1);
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');

$testBalances = [7 => ['credits' => 0, 'ability_tokens' => 10000]];
$testDeltas = [];

function af_balance_get(int $uid): array
{
    global $testBalances;
    return $testBalances[$uid] ?? ['credits' => 0, 'ability_tokens' => 0];
}

function af_balance_apply_scaled_delta(int $uid, string $kind, int $scaled, array $meta = [], bool $bypassNegativeAwardRule = false): array
{
    global $testBalances, $testDeltas;
    if (!$bypassNegativeAwardRule) {
        throw new RuntimeException('Checkout must explicitly bypass the negative-award policy.');
    }
    $testBalances[$uid][$kind] += $scaled;
    $testDeltas[] = compact('uid', 'kind', 'scaled', 'meta', 'bypassNegativeAwardRule');
    return $testBalances[$uid];
}

require_once AF_ADDONS . 'advancedshop/advancedshop.php';

function checkout_assert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

checkout_assert(af_shop_get_balance(7, 'ability_tokens') === 10000, 'registered ability_tokens balance must be returned in minor units');
af_shop_sub_balance(7, 'ability_tokens', 1000, 'shop_purchase', ['order_id' => 42]);
checkout_assert(af_shop_get_balance(7, 'ability_tokens') === 9000, 'Talent A must debit 10.00 from 100.00 Ability Tokens');
checkout_assert(count($testDeltas) === 1 && $testDeltas[0]['scaled'] === -1000, 'checkout must use one atomic scaled debit');
checkout_assert($testDeltas[0]['bypassNegativeAwardRule'] === true, 'purchase debit must not be rejected as a negative award');

$insufficientWasVisible = false;
try {
    af_shop_sub_balance(7, 'ability_tokens', 10000, 'shop_purchase');
} catch (RuntimeException $e) {
    $insufficientWasVisible = strpos($e->getMessage(), 'Недостаточно Ability Tokens') !== false;
}
checkout_assert($insufficientWasVisible, 'insufficient Ability Tokens must produce a specific checkout error');
checkout_assert(af_shop_get_balance(7, 'ability_tokens') === 9000, 'failed debit must not change balance');

$shopSource = file_get_contents(AF_ADDONS . 'advancedshop/advancedshop.php');
$shopJs = file_get_contents(AF_ADDONS . 'advancedshop/assets/advancedshop.js');
checkout_assert(strpos($shopSource, "\$payload['entity'] = 'abilities'") !== false, 'Talent grant destination must explicitly set entity=abilities');
checkout_assert(strpos($shopSource, "\$payload['subtype'] = 'talent'") !== false, 'Talent grant destination must force subtype=talent');
checkout_assert(strpos($shopSource, "AND subtype='talent' AND kb_type='arpg_talent' AND kb_key='") !== false, 'duplicate ownership guard must include exact KB type and key');
checkout_assert(strpos($shopJs, "Не удалось выполнить запрос") !== false, 'transport failures must be surfaced by the public checkout client');

echo "Advanced Shop Talent checkout runtime checks passed.\n";
