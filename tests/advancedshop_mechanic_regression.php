<?php
/** Static contract regression test for shop mechanic isolation and presentation. */
$root = dirname(__DIR__);
$php = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php');
$admin = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/admin.php');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/assets/advancedshop.css');
$fullpage = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_fullpage.html');
$card = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_product_card.html');

$checks = [
    'shop mechanic uses existing settings_json contract' => strpos($php, "['mechanic_key']") !== false,
    'picker mechanic is derived from current shop' => strpos($php, '$mechanicFilter = af_advancedshop_shop_mechanic($shop);') !== false,
    'create validates source mechanic server-side' => substr_count($php, 'af_advancedshop_assert_source_matches_shop($shop, $sourcePayload);') >= 2,
    'public listing suppresses legacy mismatches' => strpos($php, 'never exposed for sale on a public shop page') !== false,
    'legacy mismatches are reported without deletion' => strpos($php, 'Legacy mechanic mismatches (not shown publicly)') !== false,
    'admin exposes DnD/ARPG shop mechanic' => strpos($admin, 'name="mechanic_key"') !== false,
    'MyBB site title participates in title' => strpos($fullpage, '{$page_title} - {$bbname}') !== false,
    'product uses semantic shop card' => strpos($card, '<article class="af-shop-card') !== false,
    'shop controls have visible focus state' => strpos($css, '.af-shop-cat-link:hover,.af-shop-cat-link:focus-visible') !== false,
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS" : "FAIL") . ': ' . $label . PHP_EOL;
    if (!$passed) { $failed[] = $label; }
}
exit($failed ? 1 : 0);
