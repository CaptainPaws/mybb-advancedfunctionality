<?php
declare(strict_types=1);

define('IN_MYBB', 1);
require_once __DIR__ . '/../inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php';

function assert_same(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.00001) {
        fwrite(STDERR, $message . ': expected ' . $expected . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

$origin = ['hp_base' => 100, 'hp_per_level' => 10, 'attack_power_base' => 12];
$archetype = ['hp_base' => 20, 'hp_per_level' => 2, 'attack_power_base' => 3];
$variant = ['modifiers' => [
    ['stat_key' => 'hp', 'mode' => 'flat', 'value' => 150],
    ['stat_key' => 'atk', 'mode' => 'flat', 'value' => 25],
    ['stat_key' => 'armor', 'mode' => 'flat', 'value' => 5],
    ['stat_key' => 'speed', 'mode' => 'flat', 'value' => -2],
    ['kind' => 'legacy_unknown', 'value' => 999],
]];

$withoutVariant = af_charactersheets_arpg_build_stats_from_kb($origin, $archetype, 3);
assert_same(144.0, $withoutVariant['character_hp'], 'Origin-only calculation changed');
assert_same(15.0, $withoutVariant['character_attack_power'], 'Origin-only attack changed');

$withVariant = af_charactersheets_arpg_build_stats_from_kb($origin, $archetype, 3, $variant);
assert_same(294.0, $withVariant['character_hp'], 'Origin variant HP modifier was not added');
assert_same(40.0, $withVariant['character_attack_power'], 'Origin variant attack modifier was not added');
assert_same(5.0, $withVariant['character_armor'], 'Origin variant armor modifier was not added');
assert_same(-2.0, $withVariant['character_speed'], 'Negative origin variant modifier was not added');

$requiredExample = af_charactersheets_arpg_apply_flat_modifiers([
    'character_hp' => 1000,
    'character_attack_power' => 100,
    'character_armor' => 20,
    'character_speed' => 10,
], $variant);
assert_same(1150.0, $requiredExample['character_hp'], 'Required HP VM example failed');
assert_same(125.0, $requiredExample['character_attack_power'], 'Required damage VM example failed');
assert_same(25.0, $requiredExample['character_armor'], 'Required armor VM example failed');
assert_same(8.0, $requiredExample['character_speed'], 'Required negative speed VM example failed');

$kbSource = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
if ($kbSource === false) {
    fwrite(STDERR, "Unable to read KB source\n");
    exit(1);
}
foreach ([
    "define('AF_KB_REL_RACE_HAS_VARIANT', 'race_has_variant')",
    "define('AF_KB_REL_ORIGIN_HAS_VARIANT', 'origin_has_variant')",
    "'from_type' => \$db->escape_string(AF_KB_TYPE_ORIGIN)",
    "'to_type' => \$db->escape_string(AF_KB_TYPE_ORIGIN_VARIANT)",
    "\$errors[] = 'Parent origin is required.'",
    "origin_parent_key=' . htmlspecialchars_uni(\$key)",
    "define('AF_KB_TYPE_RACE_VARIANT', 'race_variant')",
    "'modifier_stat_options' => \$typeKey === 'arpg_origin_variant' ? af_kb_arpg_character_stat_keys() : []",
    "['path' => 'rules.modifiers', 'type' => 'array', 'required' => true",
] as $contract) {
    if (strpos($kbSource, $contract) === false) {
        fwrite(STDERR, 'Missing relation contract: ' . $contract . PHP_EOL);
        exit(1);
    }
}

foreach (['hp_base', 'defense_base', 'attack_power_base', 'hp_per_level'] as $duplicatedOriginField) {
    $defaultsFunctionStart = strpos($kbSource, 'function af_kb_default_type_profile_payload_arpg');
    $variantDefaultsStart = strpos($kbSource, "'arpg_origin_variant' => [", (int)$defaultsFunctionStart);
    $archetypeDefaultsStart = strpos($kbSource, "'arpg_archetype' => [", (int)$variantDefaultsStart);
    $variantDefaults = substr($kbSource, (int)$variantDefaultsStart, (int)$archetypeDefaultsStart - (int)$variantDefaultsStart);
    if (strpos($variantDefaults, "'" . $duplicatedOriginField . "'") !== false) {
        fwrite(STDERR, 'Origin core field duplicated in variant defaults: ' . $duplicatedOriginField . PHP_EOL);
        exit(1);
    }
}

echo "origin variant regression checks passed\n";
