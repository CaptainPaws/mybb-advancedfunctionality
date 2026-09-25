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

function talent_stats_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = af_charactersheets_arpg_build_stats_from_kb(
    ['hp_base' => 100, 'attack_power_base' => 10, 'defense_base' => 5],
    [],
    1
);
$talent = ['modifiers' => [
    ['type' => 'stat_modifier', 'stat_key' => 'atk', 'mode' => 'flat', 'value' => 25],
    ['type' => 'stat_modifier', 'stat_key' => 'def', 'mode' => 'flat', 'value' => 15],
    ['type' => 'stat_modifier', 'stat_key' => 'crit_rate', 'mode' => 'percent', 'value' => 5],
    ['type' => 'stat_modifier', 'stat_key' => 'luck', 'mode' => 'flat', 'value' => 10],
]];

$equipped = af_charactersheets_arpg_apply_equipment_rules($base, [$talent]);
talent_stats_assert($equipped['character_attack_power'] === 35.0, 'ATK talent modifier did not enter totals');
talent_stats_assert($equipped['character_defense'] === 20.0, 'DEF talent modifier did not enter totals');
talent_stats_assert($equipped['character_crit_rate'] === 5.0, 'Crit Rate percent-point modifier did not enter totals');
talent_stats_assert(af_charactersheets_arpg_final_luck($equipped) === 10.0, 'Luck helper did not expose the final total');

$unequipped = af_charactersheets_arpg_apply_equipment_rules($base, []);
talent_stats_assert($unequipped['character_attack_power'] === 10.0, 'ATK talent bonus survived unequip');
talent_stats_assert($unequipped['character_defense'] === 5.0, 'DEF talent bonus survived unequip');
talent_stats_assert($unequipped['character_crit_rate'] === 0.0, 'Crit Rate talent bonus survived unequip');
talent_stats_assert(af_charactersheets_arpg_final_luck($unequipped) === 0.0, 'Luck talent bonus survived unequip');

$registry = af_kb_arpg_character_stat_registry();
talent_stats_assert(array_keys($registry) === ['hp', 'atk', 'def', 'speed', 'crit_rate', 'crit_dmg', 'element_damage_bonus', 'mastery', 'healing_bonus', 'shield_strength', 'status_hit', 'status_resist', 'luck'], 'Canonical ARPG registry changed');
talent_stats_assert(!isset($registry['armor']), 'ARPG armor remains in the canonical registry');

echo "ARPG talent stat mechanics regression checks passed.\n";
