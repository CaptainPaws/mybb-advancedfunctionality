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
$variant = ['hp_base' => 15, 'hp_per_level' => 1, 'attack_power_base' => 4];

$withoutVariant = af_charactersheets_arpg_build_stats_from_kb($origin, $archetype, 3);
assert_same(144.0, $withoutVariant['character_hp'], 'Origin-only calculation changed');
assert_same(15.0, $withoutVariant['character_attack_power'], 'Origin-only attack changed');

$withVariant = af_charactersheets_arpg_build_stats_from_kb($origin, $archetype, 3, $variant);
assert_same(161.0, $withVariant['character_hp'], 'Origin variant was not added');
assert_same(19.0, $withVariant['character_attack_power'], 'Origin variant attack was not added');

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
] as $contract) {
    if (strpos($kbSource, $contract) === false) {
        fwrite(STDERR, 'Missing relation contract: ' . $contract . PHP_EOL);
        exit(1);
    }
}

echo "origin variant regression checks passed\n";
