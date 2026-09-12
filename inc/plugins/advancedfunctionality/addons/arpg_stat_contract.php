<?php
/**
 * Shared ARPG Origin modifier contract.
 *
 * The canonical modifier keys deliberately remain the keys already consumed by
 * Character Sheets.  origin_field documents the corresponding Origin schema
 * field; level_scaled tells aggregators to apply the delta before the existing
 * per-level formula.
 */

if (!function_exists('af_arpg_origin_modifier_stat_definitions')) {
    function af_arpg_origin_modifier_stat_definitions(): array
    {
        return [
            'hp' => ['label' => 'HP', 'origin_field' => 'hp_base', 'vm_field' => 'character_hp'],
            'def' => ['label' => 'Defense', 'origin_field' => 'defense_base', 'vm_field' => 'character_defense'],
            'atk' => ['label' => 'Attack Power', 'origin_field' => 'attack_power_base', 'vm_field' => 'character_attack_power'],
            'speed' => ['label' => 'Movement Speed', 'origin_field' => 'movement_speed', 'vm_field' => 'character_speed'],
            'crit_dmg' => ['label' => 'Crit Damage', 'origin_field' => 'crit_damage_base', 'vm_field' => 'character_crit_damage'],
            'mastery' => ['label' => 'Elemental Mastery', 'origin_field' => 'elemental_mastery_base', 'vm_field' => 'character_elemental_mastery'],
            'element_damage_bonus' => ['label' => 'Elemental Damage Bonus', 'origin_field' => 'elemental_damage_bonus_base', 'vm_field' => 'character_element_damage_bonus'],
            'healing_bonus' => ['label' => 'Healing Bonus', 'origin_field' => 'healing_bonus_base', 'vm_field' => 'character_healing_bonus'],
            'shield_strength' => ['label' => 'Shield Bonus', 'origin_field' => 'shield_bonus_base', 'vm_field' => 'character_shield_strength'],
            'hp_per_level' => ['label' => 'HP per Level', 'origin_field' => 'hp_per_level', 'vm_field' => 'character_hp', 'level_scaled' => true],
            'defense_per_level' => ['label' => 'Defense per Level', 'origin_field' => 'defense_per_level', 'vm_field' => 'character_defense', 'level_scaled' => true],
            'attack_power_per_level' => ['label' => 'Attack Power per Level', 'origin_field' => 'attack_power_per_level', 'vm_field' => 'character_attack_power', 'level_scaled' => true],
            'elemental_mastery_per_level' => ['label' => 'Elemental Mastery per Level', 'origin_field' => 'elemental_mastery_per_level', 'vm_field' => 'character_elemental_mastery', 'level_scaled' => true],
        ];
    }
}

if (!function_exists('af_arpg_origin_modifier_operations')) {
    function af_arpg_origin_modifier_operations(): array
    {
        return ['flat'];
    }
}

if (!function_exists('af_arpg_apply_origin_variant_modifiers')) {
    function af_arpg_apply_origin_variant_modifiers(array $stats, array $rules, int $levelSteps = 0): array
    {
        $definitions = af_arpg_origin_modifier_stat_definitions();
        foreach ((array)($rules['modifiers'] ?? []) as $modifier) {
            if (!is_array($modifier) || (string)($modifier['mode'] ?? 'flat') !== 'flat') {
                continue;
            }
            $definition = $definitions[trim((string)($modifier['stat_key'] ?? ''))] ?? null;
            if (!is_array($definition) || !is_numeric($modifier['value'] ?? null)) {
                continue;
            }
            $target = (string)$definition['vm_field'];
            $scale = !empty($definition['level_scaled']) ? max(0, $levelSteps) : 1;
            $stats[$target] = (float)($stats[$target] ?? 0) + ((float)$modifier['value'] * $scale);
        }
        return $stats;
    }
}

if (!function_exists('af_arpg_validate_origin_variant_modifier')) {
    function af_arpg_validate_origin_variant_modifier($modifier): array
    {
        $errors = [];
        if (!is_array($modifier)) {
            return ['must be an object'];
        }
        $statKey = trim((string)($modifier['stat_key'] ?? ''));
        $mode = trim((string)($modifier['mode'] ?? 'flat'));
        if (!isset(af_arpg_origin_modifier_stat_definitions()[$statKey])) {
            $errors[] = 'has unsupported stat_key';
        }
        if (!in_array($mode, af_arpg_origin_modifier_operations(), true)) {
            $errors[] = 'has unsupported mode';
        }
        if (!array_key_exists('value', $modifier) || !is_numeric($modifier['value'])) {
            $errors[] = 'value must be numeric';
        }
        if (isset($modifier['notes']) && !is_string($modifier['notes'])) {
            $errors[] = 'notes must be a string';
        }
        return $errors;
    }
}
