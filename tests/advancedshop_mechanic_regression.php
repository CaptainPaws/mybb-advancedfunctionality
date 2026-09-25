<?php
/** Static contract regression test for shop mechanic isolation and presentation. */
$root = dirname(__DIR__);
$php = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php');
$admin = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/admin.php');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/assets/advancedshop.css');
$fullpage = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_fullpage.html');
$card = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_product_card.html');
$slots = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_manage_slots.html');

$checks = [
    'shop mechanic uses existing settings_json contract' => strpos($php, "['mechanic_key']") !== false,
    'picker mechanic is derived from current shop' => strpos($php, '$mechanicFilter = af_advancedshop_shop_mechanic($shop);') !== false,
    'create validates source mechanic server-side' => substr_count($php, 'af_advancedshop_assert_source_matches_shop($shop, $sourcePayload);') >= 2,
    'public listing suppresses legacy mismatches' => strpos($php, 'never exposed for sale on a public shop page') !== false,
    'legacy mismatch warning is absent from manage UI' => strpos($php, 'Legacy mechanic mismatches (not shown publicly)') === false,
    'mechanic resolver does not infer from shop code' => strpos($php, "strpos(\$code, 'arpg')") === false,
    'type options come from compatibility-filtered registry' => strpos($php, 'af_advancedshop_kb_type_options') !== false
        && strpos($slots, '{$kb_type_options}') !== false,
    'compatibility is validated server-side' => strpos($php, 'Unknown or inactive KB type:') !== false
        && substr_count($php, 'af_advancedshop_assert_source_matches_shop($shop, $sourcePayload);') >= 2,
    'public shop offers canonical all tab' => strpos($php, '>Показать все</a>') !== false,
    'public all view excludes disabled categories' => strpos($php, "AND s.cat_id IN(") !== false,
    'manage slots uses equal viewport-bounded columns' => strpos($css, 'grid-template-columns:repeat(2,minmax(0,1fr))') !== false
        && strpos($css, 'max-height:calc(100dvh - 12rem)') !== false
        && strpos($css, 'overflow-y:auto') !== false,
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
