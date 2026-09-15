<?php
declare(strict_types=1);

define('IN_MYBB', 1);
function af_charactersheets_json_decode(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
require_once __DIR__ . '/../inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php';
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require_once __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php';

function assert_same(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.00001) {
        fwrite(STDERR, $message . ': expected ' . $expected . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$requiredVariant = ['modifiers' => [
    ['stat_key' => 'hp', 'mode' => 'flat', 'value' => 150, 'notes' => 'Birfolk vitality'],
    ['stat_key' => 'atk', 'mode' => 'flat', 'value' => 25],
    ['stat_key' => 'def', 'mode' => 'flat', 'value' => 5],
    ['stat_key' => 'speed', 'mode' => 'flat', 'value' => -10],
    ['stat_key' => 'crit_dmg', 'mode' => 'flat', 'value' => 12.5],
    ['stat_key' => 'mastery', 'mode' => 'flat', 'value' => 30],
]];
$required = af_charactersheets_arpg_build_stats_from_kb(
    ['hp_base' => 1000, 'attack_power_base' => 100, 'defense_base' => 20, 'movement_speed' => 100],
    [],
    1,
    $requiredVariant
);
assert_same(1150.0, $required['character_hp'], 'Required HP VM example failed');
assert_same(125.0, $required['character_attack_power'], 'Required attack VM example failed');
assert_same(25.0, $required['character_defense'], 'Required defense VM example failed');
assert_same(90.0, $required['character_speed'], 'Required negative speed VM example failed');
assert_same(12.5, $required['character_crit_damage'], 'Crit Damage modifier failed');
assert_same(30.0, $required['character_elemental_mastery'], 'Elemental Mastery modifier failed');

$perLevel = af_charactersheets_arpg_build_stats_from_kb(
    ['hp_base' => 1000, 'hp_per_level' => 50],
    [],
    3,
    ['modifiers' => [['stat_key' => 'hp_per_level', 'mode' => 'flat', 'value' => 10]]]
);
assert_same(1120.0, $perLevel['character_hp'], 'Per-level modifier was not applied before level scaling');

$withEquipment = af_charactersheets_arpg_build_stats_from_kb(
    ['hp_base' => 1000],
    [],
    1,
    ['modifiers' => [['stat_key' => 'hp', 'mode' => 'flat', 'value' => 150]]],
    [[
        'base_stats' => [['stat_key' => 'hp', 'mode' => 'flat', 'value' => 50]],
        'modifiers' => [
            ['stat_key' => 'def', 'mode' => 'flat', 'value' => 8],
            ['stat_key' => 'atk', 'mode' => 'percent', 'value' => 999],
            ['stat_key' => 'speed', 'mode' => 'flat', 'value' => 5, 'condition_text' => 'while sprinting'],
        ],
    ]]
);
assert_same(1200.0, $withEquipment['character_hp'], 'Origin + variant + equipment HP aggregation failed');
assert_same(8.0, $withEquipment['character_defense'], 'Unconditional flat equipment modifier failed');
assert_same(0.0, $withEquipment['character_attack_power'], 'Unsupported equipment percent modifier was calculated');
assert_same(0.0, $withEquipment['character_speed'], 'Conditional equipment modifier was calculated without combat context');

$collections = af_charactersheets_arpg_merge_rule_collections(
    ['resistances' => ['fire' => 2], 'abilities' => ['parent' => 'parent']],
    [
        ['resistances' => ['ice' => 3], 'weaknesses' => ['lightning'], 'immunities' => ['poison'], 'resources' => ['rage' => 5]],
        ['resistances' => ['fire' => 1], 'abilities' => ['variant'], 'skills' => ['survival'], 'proficiencies' => ['bows'], 'grants' => [['op' => 'resource', 'key' => 'focus', 'value' => 2]]],
    ]
);
assert_same(3.0, $collections['resistances']['fire'], 'Variant destroyed or failed to add to parent resistance');
assert_same(3.0, $collections['resistances']['ice'], 'Variant resistance was not collected');
assert_true(isset($collections['abilities']['parent'], $collections['abilities']['variant']), 'Variant destroyed parent abilities');
assert_true(isset($collections['weaknesses']['lightning'], $collections['immunities']['poison']), 'Weakness/immunity collection failed');
assert_true(isset($collections['skills']['survival'], $collections['proficiencies']['bows']), 'Skill/proficiency collection failed');
assert_same(5.0, $collections['resources']['rage'], 'Resource collection failed');
assert_true(count($collections['grants']) === 1, 'Grant collection failed');

$duplicatesAndDecimals = af_charactersheets_arpg_apply_origin_variant_modifiers(
    ['character_hp' => 100],
    ['modifiers' => [
        ['stat_key' => 'hp', 'mode' => 'flat', 'value' => 100],
        ['stat_key' => 'hp', 'mode' => 'flat', 'value' => 50.5],
        ['stat_key' => 'hp', 'mode' => 'flat', 'value' => 0],
        ['stat_key' => 'unsupported', 'mode' => 'flat', 'value' => 999],
        ['stat_key' => 'hp', 'mode' => 'percent', 'value' => 999],
    ]]
);
assert_same(250.5, $duplicatesAndDecimals['character_hp'], 'Duplicate/decimal/zero flat semantics changed');

$withoutVariant = af_charactersheets_arpg_build_stats_from_kb(['hp_base' => 100], [], 1);
assert_same(100.0, $withoutVariant['character_hp'], 'Origin without variant changed');
$legacyVariant = af_charactersheets_arpg_build_stats_from_kb(['hp_base' => 100], [], 1, ['hp_base' => 15]);
assert_same(115.0, $legacyVariant['character_hp'], 'Legacy direct-field variant was not preserved');

$definitions = af_kb_arpg_origin_modifier_stat_definitions();
$expectedKeys = [
    'hp', 'def', 'atk', 'speed', 'crit_dmg', 'mastery', 'element_damage_bonus',
    'healing_bonus', 'shield_strength', 'hp_per_level', 'defense_per_level',
    'attack_power_per_level', 'elemental_mastery_per_level',
];
assert_true(array_keys($definitions) === $expectedKeys, 'Schema-derived modifier selector keys changed');
$variantEnvelope = af_kb_arpg_envelope_defaults('arpg_origin_variant');
$variantEnvelope['rules'] = array_replace_recursive(
    $variantEnvelope['rules'],
    af_kb_default_type_profile_payload_arpg('arpg_origin_variant')
);
assert_true($variantEnvelope['schema'] === 'af_kb.arpg.meta.v1', 'Variant left the canonical ARPG envelope');
assert_true($variantEnvelope['rules']['schema'] === 'af_kb.arpg.rules.v1', 'Variant left the canonical ARPG rules schema');
assert_true($variantEnvelope['rules']['type_profile'] === 'origin_variant', 'Variant type profile changed');
assert_true(array_key_exists('modifiers', $variantEnvelope['rules']), 'Variant modifiers are not nested in rules');
assert_true(!array_key_exists('variant_stats', $variantEnvelope), 'Parallel variant stat payload was introduced');
foreach (['size', 'creature_type', 'racial_bonuses_text', 'racial_traits_text', 'starting_notes'] as $nonMechanical) {
    assert_true(!isset($definitions[$nonMechanical]), 'Non-mechanical Origin field exposed: ' . $nonMechanical);
}
foreach ([-10, 0, 1.25] as $numericValue) {
    assert_true(af_kb_arpg_validate_origin_variant_modifier([
        'stat_key' => 'hp', 'mode' => 'flat', 'value' => $numericValue, 'notes' => 'valid',
    ]) === [], 'Validator rejected a valid numeric modifier');
}
assert_true(af_kb_arpg_validate_origin_variant_modifier(['stat_key' => '', 'mode' => 'flat', 'value' => 0]) !== [], 'Validator accepted an empty stat');
assert_true(af_kb_arpg_validate_origin_variant_modifier(['stat_key' => 'hp', 'mode' => 'percent', 'value' => 1]) !== [], 'Validator accepted an unsupported operation');
assert_true(af_kb_arpg_validate_origin_variant_modifier(['stat_key' => 'hp', 'mode' => 'flat', 'value' => 'invalid']) !== [], 'Validator accepted a non-numeric value');

$json = json_encode(['rules' => $requiredVariant], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$roundTrip = json_decode((string)$json, true);
assert_true($roundTrip['rules']['modifiers'][0] === $requiredVariant['modifiers'][0], 'Modifier JSON round-trip changed data');
$roundTrip['rules']['modifiers'][0]['value'] = 175.25;
$savedAgain = json_decode((string)json_encode($roundTrip), true);
assert_same(175.25, (float)$savedAgain['rules']['modifiers'][0]['value'], 'Edited modifier value was not preserved');
$normalized = af_charactersheets_arpg_extract_entry_rules([
    'data_json' => json_encode(['rules' => $requiredVariant], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
assert_true($normalized['modifiers'][0] === $requiredVariant['modifiers'][0], 'Stored modifier did not reach Character Sheet normalization');

$kbSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$jsSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');
assert_true(is_string($kbSource) && is_string($jsSource), 'Unable to inspect KB sources');
foreach ([
    "\$errors[] = 'Parent origin is required.'",
    "origin_parent_key=' . htmlspecialchars_uni(\$key)",
    "define('AF_KB_TYPE_RACE_VARIANT', 'race_variant')",
    "\$schema['modifier_stat_options'] = af_kb_arpg_character_stat_options()",
    "af_kb_arpg_apply_origin_variant_modifiers(\$stats, \$originVariantRules, \$levelSteps)",
    "\$originSchema = af_kb_default_type_profile_payload_arpg('arpg_origin')",
    "array_key_exists(\$originField, \$originSchema)",
    "'character_origin' => 'arpg_origin'",
    "'character_origin_variant' => 'arpg_origin_variant'",
    "'Происхождение' => 'character_origin'",
    "'Разновидность' => 'character_origin_variant'",
] as $contract) {
    assert_true(strpos($kbSource, $contract) !== false, 'Missing KB/relation contract: ' . $contract);
}
assert_true(strpos($jsSource, "v.label || v.value") !== false, 'Selector does not separate labels from keys');
assert_true(strpos($jsSource, "input.step = 'any'") !== false, 'Decimal modifier input is not enabled');
assert_true(!file_exists(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/arpg_stat_contract.php'), 'Global ARPG stat contract file still exists');

$renderSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');
assert_true(is_string($renderSource), 'Unable to inspect Character Sheet renderer');
assert_true(strpos($renderSource, "'speed_total' => (int)af_charactersheets_arpg_read_numeric_stat") !== false, 'Combat summary bypasses calculated speed VM');

$atfSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfJsSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.js');
assert_true(is_string($atfSource) && is_string($atfJsSource), 'Unable to inspect ATF sources');
foreach ([
    "'character_origin_variant' => 'arpg_origin_variant'",
    "af_kb_get_origin_variants(\$originKey, true)",
    "af_kb_get_origin_parent_for_variant(\$val, true)",
    "'character_origin_variant' => af_charactersheets_pick_field_value",
] as $contract) {
    assert_true(strpos($atfSource, $contract) !== false, 'Missing ATF Origin Variant contract: ' . $contract);
}
assert_true(strpos($atfJsSource, 'initOriginVariantDependency()') !== false, 'Origin Variant dependency is not initialized');
assert_true(strpos($atfJsSource, 'originSelect.addEventListener("change", () => load(false))') !== false, 'Origin changes do not clear/reload variants');
assert_true(strpos($atfJsSource, 'load(true)') !== false, 'Initial Origin Variant is not restored after loading dependent options');

echo "origin variant regression checks passed\n";
echo "stored JSON example: " . $json . "\n";
