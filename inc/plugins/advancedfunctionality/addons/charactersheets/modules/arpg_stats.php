<?php
/** Pure shared ARPG arithmetic; no loading, queries, hooks or synchronization. */
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_charactersheets_arpg_merge_runtime_stats(array $characterStats, array $computedKbStats): array
{
    $merged = $characterStats;
    foreach ($computedKbStats as $statKey => $computedValue) {
        $hasRaw = array_key_exists($statKey, $merged);
        $rawValue = $hasRaw ? $merged[$statKey] : null;
        $rawNumeric = is_numeric($rawValue) ? (float)$rawValue : null;
        $computedNumeric = (float)$computedValue;

        // An explicit Accuracy of zero is authoritative, unlike old seeded stats.
        if ($statKey === 'character_accuracy' && $rawNumeric !== null) continue;

        $shouldOverride = !$hasRaw
            || $rawValue === null
            || $rawValue === ''
            || $rawNumeric === 0.0;

        if ($shouldOverride) {
            $merged[$statKey] = $computedNumeric;
        }
    }

    return $merged;
}

function af_charactersheets_arpg_numeric_rule(array $rules, string $key): float
{
    if (!array_key_exists($key, $rules)) {
        return 0.0;
    }
    return (float)$rules[$key];
}

function af_charactersheets_arpg_apply_flat_modifiers(array $stats, array $rules): array
{
    return af_charactersheets_arpg_apply_origin_variant_modifiers($stats, $rules);
}

function af_charactersheets_arpg_apply_origin_variant_modifiers(array $stats, array $rules, int $levelSteps = 0): array
{
    if (function_exists('af_kb_arpg_apply_origin_variant_modifiers')) {
        return af_kb_arpg_apply_origin_variant_modifiers($stats, $rules, $levelSteps);
    }

    $targets = [
        'hp' => ['character_hp', false],
        'def' => ['character_defense', false],
        'atk' => ['character_attack_power', false],
        'accuracy' => ['character_accuracy', false],
        'speed' => ['character_speed', false],
        'crit_rate' => ['character_crit_rate', false],
        'crit_dmg' => ['character_crit_damage', false],
        'crit_damage' => ['character_crit_damage', false],
        'status_hit' => ['character_status_hit', false],
        'status_resist' => ['character_status_resist', false],
        'mastery' => ['character_elemental_mastery', false],
        'element_damage_bonus' => ['character_element_damage_bonus', false],
        'healing_bonus' => ['character_healing_bonus', false],
        'shield_strength' => ['character_shield_strength', false],
        'luck' => ['character_luck', false],
        'hp_per_level' => ['character_hp', true],
        'defense_per_level' => ['character_defense', true],
        'attack_power_per_level' => ['character_attack_power', true],
        'elemental_mastery_per_level' => ['character_elemental_mastery', true],
    ];
    if (function_exists('af_kb_arpg_character_stat_registry')) {
        foreach (af_kb_arpg_character_stat_registry() as $registryKey => $definition) {
            if (!empty($definition['vm_field'])) $targets[(string)$registryKey] = [(string)$definition['vm_field'], false];
        }
    }
    foreach ((array)($rules['modifiers'] ?? []) as $modifier) {
        if (!is_array($modifier) || !in_array((string)($modifier['mode'] ?? 'flat'), ['flat', 'percent'], true) || !is_numeric($modifier['value'] ?? null)) {
            continue;
        }
        $target = $targets[trim((string)($modifier['stat_key'] ?? ''))] ?? null;
        if (!is_array($target)) {
            continue;
        }
        $scale = $target[1] ? max(0, $levelSteps) : 1;
        $stats[$target[0]] = (float)($stats[$target[0]] ?? 0) + (float)$modifier['value'] * $scale;
    }
    return $stats;
}

function af_charactersheets_arpg_apply_equipment_rules(array $stats, array $ruleSources): array
{
    foreach ($ruleSources as $rules) {
        if (!is_array($rules)) {
            continue;
        }
        // Base stats and unconditional modifiers share the canonical stat keys.
        // Percent-valued stats use percentage points, so percent is additive.
        foreach (['base_stats', 'modifiers'] as $bucket) {
            $flatRules = ['modifiers' => []];
            foreach ((array)($rules[$bucket] ?? []) as $modifier) {
                if (!is_array($modifier) || !in_array(trim((string)($modifier['mode'] ?? 'flat')), ['flat', 'percent'], true)) {
                    continue;
                }
                if (trim((string)($modifier['condition_text'] ?? '')) !== '') {
                    continue;
                }
                $flatRules['modifiers'][] = $modifier;
            }
            $stats = af_charactersheets_arpg_apply_origin_variant_modifiers($stats, $flatRules);
        }
    }
    return $stats;
}

function af_charactersheets_arpg_build_stats_from_kb(array $originRules, array $archetypeRules, int $level, array $originVariantRules = [], array $equipmentRuleSources = []): array
{
    $lvlScale = max(0, $level - 1);
    // Direct variant fields are retained as a legacy compatibility input. New
    // variants use rules.modifiers, so the two representations are never
    // synthesized from one another and a modifier is not applied twice.
    $sum = static function (string $key) use ($originRules, $originVariantRules, $archetypeRules): float {
        return af_charactersheets_arpg_numeric_rule($originRules, $key)
            + af_charactersheets_arpg_numeric_rule($originVariantRules, $key)
            + af_charactersheets_arpg_numeric_rule($archetypeRules, $key);
    };

    $stats = [
        'character_hp' => $sum('hp_base') + $lvlScale * $sum('hp_per_level'),
        'character_defense' => $sum('defense_base') + $lvlScale * $sum('defense_per_level'),
        'character_attack_power' => $sum('attack_power_base') + $lvlScale * $sum('attack_power_per_level'),
        'character_crit_damage' => $sum('crit_damage_base'),
        'character_elemental_mastery' => $sum('elemental_mastery_base') + $lvlScale * $sum('elemental_mastery_per_level'),
        'character_element_damage_bonus' => $sum('elemental_damage_bonus_base'),
        'character_healing_bonus' => $sum('healing_bonus_base'),
        'character_shield_strength' => $sum('shield_bonus_base'),
        'character_luck' => $sum('luck_base'),
        'character_speed' => af_charactersheets_arpg_numeric_rule($originRules, 'movement_speed')
            + af_charactersheets_arpg_numeric_rule($originVariantRules, 'movement_speed'),
        'character_crit_rate' => $sum('crit_rate_base'),
        'character_status_hit' => $sum('status_hit_base'),
        'character_status_resist' => $sum('status_resist_base'),
    ];

    foreach ([$originRules, $originVariantRules, $archetypeRules] as $accuracyRules) {
        if (is_numeric($accuracyRules['accuracy_base'] ?? null)) {
            $stats['character_accuracy'] = ($stats['character_accuracy'] ?? 0) + (float)$accuracyRules['accuracy_base'];
        }
    }

    $stats['character_attack_power'] += $sum('base_damage_bonus');
    $stats['character_defense'] += $sum('base_defense_bonus');

    $stats = af_charactersheets_arpg_apply_origin_variant_modifiers($stats, $originVariantRules, $lvlScale);
    $stats = af_charactersheets_arpg_apply_equipment_rules($stats, $equipmentRuleSources);

    foreach ($stats as $key => $value) {
        $stats[$key] = (float)$value;
    }

    return $stats;
}
