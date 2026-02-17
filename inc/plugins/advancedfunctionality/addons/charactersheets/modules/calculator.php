<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * Contract for bonuses in KB rules data JSON (af_kb.rules.v1):
 * {
 *   "bonuses": [
 *     {"type":"attribute_bonus","target":"str","value":1},
 *     {"type":"attribute_bonus","requires_choice":true,"value":2},
 *     {"type":"attribute_points","value":2},
 *     {"type":"skill_points","value":1},
 *     {"type":"skill_bonus","target":"analysis","value":1},
 *     {"type":"skill_bonus","requires_choice":true,"value":1},
 *     {"type":"knowledge_choice","value":1},
 *     {"type":"language_choice","value":1},
 *     {"type":"knowledge","target":"lore_key"},
 *     {"type":"language","target":"common"},
 *     {"type":"hp_bonus","value":5},
 *     {"type":"humanity_bonus","value":-1},
 *     {"type":"armor_bonus","value":1},
 *     {"type":"shield_bonus","value":1},
 *     {"type":"weapon_bonus","value":2},
 *     {"type":"ac_bonus","value":1}
 *   ]
 * }
 */
function af_charactersheets_normalize_bonus_items(string $source, string $key): array
{
    $resolved = af_charactersheets_kb_resolve_entry($source, $key);
    if (empty($resolved)) {
        return [];
    }

    $items = [];
    $attributes = af_charactersheets_default_attributes();

    $data = (array)($resolved['data'] ?? []);
    $sets = [];
    if (!empty($data['bonuses']) && is_array($data['bonuses'])) {
        $sets[] = $data['bonuses'];
    }
    if (!empty($data['modifiers']) && is_array($data['modifiers'])) {
        $sets[] = $data['modifiers'];
    }
    $items = array_merge($items, af_charactersheets_rules_to_bonus_items($data, $source, $attributes));
    if (!empty($data['rules']) && is_array($data['rules'])) {
        $items = array_merge($items, af_charactersheets_rules_to_bonus_items($data['rules'], $source, $attributes));
    }
    foreach (['stats', 'attributes'] as $dataKey) {
        if (!empty($data[$dataKey]) && is_array($data[$dataKey])) {
            foreach ($data[$dataKey] as $stat => $value) {
                if (array_key_exists($stat, $attributes)) {
                    $items[] = [
                        'source' => $source,
                        'type' => 'attribute_bonus',
                        'target' => $stat,
                        'value' => (float)$value,
                        'requires_choice' => false,
                    ];
                }
            }
        }
    }

    foreach ($sets as $set) {
        foreach ($set as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            $target = (string)($item['target'] ?? $item['stat'] ?? $item['attribute'] ?? $item['skill'] ?? $item['key'] ?? '');
            $value = $item['value'] ?? $item['amount'] ?? 0;
            $requires_choice = !empty($item['requires_choice']) || !empty($item['choice']) || !empty($item['requiresChoice']);

            if ($type === '') {
                if ($target !== '' && array_key_exists($target, $attributes)) {
                    $type = 'attribute_bonus';
                } elseif ($target !== '') {
                    $type = 'skill_bonus';
                }
            }

            if ($type === '') {
                continue;
            }

            $items[] = [
                'source' => $source,
                'type' => $type,
                'target' => $target !== '' ? $target : null,
                'value' => (float)$value,
                'requires_choice' => $requires_choice,
            ];
        }
    }

    $normalized = [];
    $i = 0;
    foreach ($items as $item) {
        $item['id'] = $source . ':' . $key . ':' . $i++;
        $normalized[] = $item;
    }

    return $normalized;
}

function af_charactersheets_rules_to_bonus_items(array $rules, string $source, array $attributes): array
{
    if (empty($rules)) {
        return [];
    }

    $schema = (string)($rules['schema'] ?? '');
    if ($schema !== '' && $schema !== 'af_kb.rules.v1') {
        return [];
    }

    $items = [];
    $fixed = $rules['fixed_bonuses'] ?? [];
    if (is_array($fixed)) {
        foreach (['stats', 'attributes'] as $fixedKey) {
            if (empty($fixed[$fixedKey]) || !is_array($fixed[$fixedKey])) {
                continue;
            }
            foreach ($fixed[$fixedKey] as $stat => $value) {
                if (array_key_exists($stat, $attributes)) {
                    $items[] = [
                        'source' => $source,
                        'type' => 'attribute_bonus',
                        'target' => $stat,
                        'value' => (float)$value,
                        'requires_choice' => false,
                    ];
                }
            }
        }
    }

    return $items;
}

function af_charactersheets_collect_bonus_items(array $kb_sources): array
{
    $items = [];
    $sources = [
        'race' => (string)($kb_sources['race'] ?? ''),
        'class' => (string)($kb_sources['class'] ?? ''),
        'theme' => (string)($kb_sources['theme'] ?? ''),
    ];
    foreach ($sources as $source => $key) {
        if ($key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($source, $key));
    }
    return $items;
}

function af_charactersheets_collect_build_bonus_items(array $build): array
{
    $items = [];

    $abilities = (array)($build['abilities'] ?? []);
    foreach ((array)($abilities['owned'] ?? []) as $ability) {
        if (empty($ability['equipped'])) {
            continue;
        }
        $type = (string)($ability['type'] ?? '');
        $key = (string)($ability['key'] ?? '');
        if ($type === '' || $key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($type, $key));
    }

    $inventory = (array)($build['inventory'] ?? []);
    foreach ((array)($inventory['items'] ?? []) as $item) {
        if (empty($item['equipped'])) {
            continue;
        }
        $type = af_charactersheets_get_inventory_item_type($item);
        $key = af_charactersheets_get_inventory_item_key($item);
        if ($type === '' || $key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($type, $key));
    }

    $augmentations = (array)($build['augmentations'] ?? []);
    foreach ((array)($augmentations['slots'] ?? []) as $slotItem) {
        foreach (af_charactersheets_normalize_slot_items($slotItem) as $slot_entry) {
            if (!is_array($slot_entry)) {
                continue;
            }
            $type = (string)($slot_entry['type'] ?? $slot_entry['kb_type'] ?? '');
            $key = (string)($slot_entry['key'] ?? $slot_entry['kb_key'] ?? '');
            if ($type === '' || $key === '') {
                continue;
            }
            $items = array_merge($items, af_charactersheets_normalize_bonus_items($type, $key));
        }
    }

    $equipment = (array)($build['equipment'] ?? []);
    foreach ((array)($equipment['slots'] ?? []) as $slotItem) {
        if (!is_array($slotItem)) {
            continue;
        }
        $type = (string)($slotItem['type'] ?? '');
        $key = (string)($slotItem['key'] ?? '');
        if ($type === '' || $key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($type, $key));
    }

    return $items;
}

function af_charactersheets_extract_hp_from_entry(array $entry): float
{
    if (empty($entry)) {
        return 0.0;
    }

    $hp = 0.0;
    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $hp += af_charactersheets_extract_hp_from_data($meta);

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        $hp += af_charactersheets_extract_hp_from_data($data);
    }

    return $hp;
}

function af_charactersheets_extract_hp_from_data(array $data): float
{
    if (empty($data)) {
        return 0.0;
    }

    $hp = 0.0;
    if (!empty($data['stats']) && is_array($data['stats']) && isset($data['stats']['hp'])) {
        $hp += (float)$data['stats']['hp'];
    }
    foreach (['bonuses', 'modifiers'] as $listKey) {
        if (empty($data[$listKey]) || !is_array($data[$listKey])) {
            continue;
        }
        foreach ($data[$listKey] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type !== 'hp_bonus') {
                continue;
            }
            $hp += (float)($item['value'] ?? $item['amount'] ?? 0);
        }
    }

    return $hp;
}

function af_charactersheets_extract_humanity_cost_from_entry(array $entry): float
{
    if (empty($entry)) {
        return 0.0;
    }

    $cost = 0.0;
    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $cost += af_charactersheets_extract_humanity_cost_from_data($meta);

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        $cost += af_charactersheets_extract_humanity_cost_from_data($data);
    }

    return max(0.0, $cost);
}

function af_charactersheets_extract_humanity_cost_from_data(array $data): float
{
    if (empty($data)) {
        return 0.0;
    }

    $cost = 0.0;
    if (isset($data['humanity_cost'])) {
        $cost += (float)$data['humanity_cost'];
    }
    foreach (['bonuses', 'modifiers'] as $listKey) {
        if (empty($data[$listKey]) || !is_array($data[$listKey])) {
            continue;
        }
        foreach ($data[$listKey] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            if ($type !== 'humanity_cost') {
                continue;
            }
            $cost += (float)($item['value'] ?? $item['amount'] ?? 0);
        }
    }

    return $cost;
}


function cs_build_character_state($uid, $sheet_id): array
{
    $sheet = af_charactersheets_get_sheet_by_id((int)$sheet_id);
    if (empty($sheet)) {
        return [];
    }
    if ((int)($uid ?? 0) > 0 && (int)($sheet['uid'] ?? 0) !== (int)$uid) {
        // allow admins/moderators downstream; state build itself is read-only
    }

    return af_charactersheets_compute_sheet_view($sheet);
}

function af_charactersheets_compute_sheet_view(array $sheet): array
{
    global $mybb;

    $sheet_id = (int)($sheet['id'] ?? 0);
    if ($sheet_id > 0) {
        af_charactersheets_sync_fixed_skills($sheet_id);
    }

    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $build = af_charactersheets_normalize_build($build);

    $attributes_base = af_charactersheets_default_attributes();
    $attributes_allocated = array_merge(af_charactersheets_default_attributes(), (array)($build['allocated_stats'] ?? $build['attributes_allocated'] ?? []));

    $choices = (array)($build['choices'] ?? []);
    $errors = [];
    $bonus = af_charactersheets_default_attributes();
    $choice_requirements = [];
    $skill_choice_requirements = [];
    $bonus_attr_points = 0;
    $bonus_skill_points = 0;
    $bonus_knowledge_choices = 0;
    $bonus_language_choices = 0;
    $bonus_skill_map = [];
    $bonus_languages = [];
    $bonus_knowledges = [];
    $bonus_sources = [];
    $bonus_hp = 0;
    $bonus_humanity = 0;
    $bonus_armor = 0;
    $bonus_shield = 0;
    $bonus_weapon = 0;
    $bonus_ac = 0;

    $kb_sources = cs_get_sheet_kb_sources($sheet);

    $sources = [
        'race' => (string)($kb_sources['race'] ?? ''),
        'class' => (string)($kb_sources['class'] ?? ''),
        'theme' => (string)($kb_sources['theme'] ?? ''),
    ];

    $choice_map = [
        'race' => 'race_attr_bonus_choice',
        'class' => 'class_attr_bonus_choice',
        'theme' => 'theme_attr_bonus_choice',
    ];

    $bonus_items = af_charactersheets_collect_bonus_items($kb_sources);
    $bonus_items = array_merge($bonus_items, af_charactersheets_collect_build_bonus_items($build));
    foreach ($bonus_items as $item) {
        $type = (string)($item['type'] ?? '');
        $target = $item['target'] ?? null;
        $value = $item['value'] ?? 0;
        $requires_choice = !empty($item['requires_choice']);
        $source = (string)($item['source'] ?? '');
        if ($source !== '' && !isset($bonus_sources[$source])) {
            $bonus_sources[$source] = true;
        }

        if ($type === 'attribute_bonus') {
            if ($requires_choice) {
                $choice_key = $choice_map[$source] ?? '';
                $chosen = $choice_key !== '' ? (string)($choices[$choice_key] ?? '') : '';
                $choice_requirements[$source] = [
                    'choice_key' => $choice_key,
                    'chosen' => $chosen,
                    'value' => (float)$value,
                ];
                if ($chosen === '') {
                    if (!in_array('Не выбран бонус для ' . $source, $errors, true)) {
                        $errors[] = 'Не выбран бонус для ' . $source;
                    }
                    continue;
                }
                $target = $chosen;
            }
            if ($target !== null && array_key_exists($target, $bonus)) {
                $bonus[$target] += (float)$value;
            }
            continue;
        }

        if ($type === 'attribute_points') {
            $bonus_attr_points += (int)$value;
            continue;
        }

        if ($type === 'skill_points') {
            $bonus_skill_points += (int)$value;
            continue;
        }

        if ($type === 'skill_bonus') {
            if ($requires_choice && (string)$target === '') {
                $choice_key = 'skill_bonus_choice_' . md5((string)($item['id'] ?? $source . ':' . $value));
                $chosen = (string)($choices[$choice_key] ?? '');
                $skill_choice_requirements[] = [
                    'choice_key' => $choice_key,
                    'chosen' => $chosen,
                    'value' => (float)$value,
                ];
                if ($chosen === '') {
                    continue;
                }
                $target = $chosen;
            }
            if (is_string($target) && $target !== '') {
                $bonus_skill_map[$target] = ($bonus_skill_map[$target] ?? 0) + (float)$value;
            }
            continue;
        }

        if ($type === 'knowledge_choice') {
            $bonus_knowledge_choices += (int)$value;
            continue;
        }

        if ($type === 'language_choice') {
            $bonus_language_choices += (int)$value;
            continue;
        }

        if ($type === 'knowledge') {
            if (is_string($target) && $target !== '') {
                $bonus_knowledges[] = $target;
            }
            continue;
        }

        if ($type === 'language') {
            if (is_string($target) && $target !== '') {
                $bonus_languages[] = $target;
            }
            continue;
        }

        if ($type === 'hp_bonus') {
            if (in_array($source, ['race', 'class'], true)) {
                continue;
            }
            $bonus_hp += (float)$value;
            continue;
        }

        if ($type === 'humanity_bonus') {
            $bonus_humanity += (float)$value;
            continue;
        }

        if ($type === 'armor_bonus') {
            $bonus_armor += (float)$value;
            continue;
        }

        if ($type === 'shield_bonus') {
            $bonus_shield += (float)$value;
            continue;
        }

        if ($type === 'weapon_bonus') {
            $bonus_weapon += (float)$value;
            continue;
        }

        if ($type === 'ac_bonus') {
            $bonus_ac += (float)$value;
            continue;
        }
    }

    $kb_context = cs_resolve_character_kb_context((int)($sheet['id'] ?? 0));
    $source_rules_map = [];
    foreach (['race', 'class', 'theme'] as $src) {
        $source_rules_map[$src] = cs_kb_rules_normalize((array)($kb_context['sources'][$src]['rules'] ?? []));
    }

    $rules_aggregate = (array)($kb_context['aggregate'] ?? af_cs_aggregate_rules(array_values((array)($kb_context['sources'] ?? []))));
    foreach (['str','dex','con','int','wis','cha'] as $statKey) {
        $bonus[$statKey] += (float)($rules_aggregate['fixed']['stats'][$statKey] ?? 0);
        $bonus[$statKey] += (float)($rules_aggregate['fixed_bonuses']['stats'][$statKey] ?? 0);
    }
    $bonus_attr_points += (int)($rules_aggregate['points_pools']['attribute_points'] ?? 0);
    $bonus_skill_points += (int)($rules_aggregate['points_pools']['skill_points'] ?? 0);
    $bonus_language_choices += (int)($rules_aggregate['points_pools']['language_slots'] ?? 0);

    $final = [];
    foreach ($attributes_base as $key => $value) {
        $final[$key] = (float)$value + (float)($attributes_allocated[$key] ?? 0) + (float)($bonus[$key] ?? 0);
    }

    $spent = 0;
    foreach ($attributes_allocated as $value) {
        $spent += (int)$value;
    }
    $attr_base_pool = (int)($mybb->settings['af_charactersheets_attr_pool_max'] ?? 0);
    $pool_max = $attr_base_pool + $bonus_attr_points;
    $remaining = $pool_max - $spent;
    if ($remaining < 0) {
        $errors[] = 'Превышен лимит очков пула.';
    }

    $attr_cap = (int)($mybb->settings['af_charactersheets_attr_cap'] ?? 0);
    if ($attr_cap > 0) {
        foreach ($final as $key => $value) {
            if ($value > $attr_cap) {
                $errors[] = 'Превышен лимит атрибутов (' . $key . ').';
            }
        }
    }

    $exp = (float)($progress['exp'] ?? 0);
    $level_data = af_charactersheets_compute_level($exp);
    $progress['level'] = (int)($progress['level'] ?? $level_data['level']);

    $attributes_labels = af_charactersheets_get_attribute_labels();
    $choice_details = [];
    foreach ($choice_requirements as $source => $data) {
        $entry = af_charactersheets_kb_get_entry($source, (string)$sources[$source]);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        if ($label === '') {
            $label = $source;
        }
        $choice_details[] = [
            'source' => $source,
            'label' => $label,
            'choice_key' => $data['choice_key'],
            'chosen' => $data['chosen'],
        ];
    }

    $bonus_source_labels = [];
    foreach (array_keys($bonus_sources) as $source) {
        $entry = af_charactersheets_kb_get_entry($source, (string)($sources[$source] ?? ''));
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        if ($label === '') {
            $label = $source;
        }
        $bonus_source_labels[] = $label;
    }

    $kb_debug = [];
    foreach (['race', 'class', 'theme'] as $src) {
        $source_debug = (array)($kb_context['sources'][$src] ?? []);
        $rules = (array)($source_rules_map[$src] ?? []);
        $kb_debug[$src] = [
            'key' => (string)($sources[$src] ?? ''),
            'schema' => (string)($source_debug['schema'] ?? ($rules['schema'] ?? '')),
            'valid' => !empty($source_debug['valid']),
            'reason' => (string)($source_debug['reason'] ?? ''),
            'rules' => $rules,
            'fixed' => (array)($rules['fixed'] ?? []),
            'fixed_bonuses' => (array)($rules['fixed_bonuses'] ?? []),
            'choices' => (array)($rules['choices'] ?? []),
        ];
    }
    $skills_rows = af_charactersheets_get_sheet_skills((int)($sheet['id'] ?? 0));
    $skills_map = [];
    foreach ($skills_rows as $row) {
        $skills_map[(string)($row['skill_key'] ?? '')] = $row;
    }

    $skills_view = [];
    $manual_spent = 0;
    foreach ((array)($kb_context['skills_all'] ?? []) as $skill_resolved) {
        $skill_key = (string)($skill_resolved['key'] ?? '');
        $data = (array)($skill_resolved['data'] ?? []);
        if ((string)($data['type_profile'] ?? '') !== 'skill') {
            continue;
        }
        $skill_data = (array)($data['skill'] ?? []);
        $attr_key = (string)($skill_data['key_stat'] ?? '');
        $base_mod = (int)floor((float)($final[$attr_key] ?? 0));
        $row = (array)($skills_map[$skill_key] ?? []);
        $skill_rank = max(0, (int)($row['skill_rank'] ?? 0));
        $is_active = (int)($row['is_active'] ?? 0) === 1;
        $source = (string)($row['source'] ?? '');
        $bonus_val = (float)($bonus_skill_map[$skill_key] ?? 0);
        $total = $base_mod + ($is_active ? $skill_rank : 0) + $bonus_val;
        if ($source === 'manual' && $is_active) {
            $manual_spent += $skill_rank;
        }

        $skills_view[] = [
            'skill_key' => $skill_key,
            'title' => (string)($skill_resolved['title'] ?? $skill_key),
            'category' => (string)($skill_data['category'] ?? 'general'),
            'skill_rank' => $skill_rank,
            'rank_max' => max(1, (int)($skill_data['rank_max'] ?? 1)),
            'source' => $source,
            'is_active' => $is_active,
            'trained_only' => !empty($skill_data['trained_only']),
            'notes' => (string)($skill_data['notes'] ?? ''),
            'attr_key' => $attr_key,
            'attr_label' => $attributes_labels[$attr_key] ?? $attr_key,
            'base' => $base_mod,
            'bonus' => $bonus_val,
            'total' => $total,
        ];
    }

    $skill_pool_spent = $manual_spent;
    $level = (int)($level_data['level'] ?? 1);
    $skill_base_per_level = (int)($mybb->settings['af_charactersheets_skill_points_per_level'] ?? 0);
    $skill_base_start = (int)($mybb->settings['af_charactersheets_skill_points_start'] ?? 0);
    $skill_pool_total = ($skill_base_per_level * max(1, $level)) + $skill_base_start + $bonus_skill_points;
    $skill_pool_remaining = $skill_pool_total - $skill_pool_spent;
    if ($skill_pool_remaining < 0) {
        $errors[] = 'Превышен лимит очков навыков.';
    }

    $resolved_rules = [
        'fixed' => (array)($rules_aggregate['fixed'] ?? []),
        'fixed_bonuses' => (array)($rules_aggregate['fixed_bonuses'] ?? []),
        'hp_base_total' => (int)($rules_aggregate['hp_base_total'] ?? 0),
    ];

    $knowledge_build = (array)($build['knowledge'] ?? []);
    $knowledge_selected = array_values(array_unique(array_filter((array)($knowledge_build['knowledges'] ?? []))));
    $language_selected = array_values(array_unique(array_filter((array)($knowledge_build['languages'] ?? []))));
    $bonus_languages = array_values(array_unique($bonus_languages));
    $bonus_knowledges = array_values(array_unique($bonus_knowledges));

    $knowledge_base_choices = 0;
    $knowledge_per_int = (float)($mybb->settings['af_charactersheets_knowledge_per_int'] ?? 0);
    $int_value = (float)($final['int'] ?? 0);
    $knowledge_from_int = (int)floor($int_value * $knowledge_per_int);
    $knowledge_total_choices = $knowledge_base_choices + $knowledge_from_int + $bonus_knowledge_choices;
    if ($knowledge_total_choices < 1) {
        $knowledge_total_choices = 1;
    }
    $language_total_choices = (int)($resolved_rules['fixed']['language_slots'] ?? 0) + (int)($resolved_rules['fixed_bonuses']['language_slots'] ?? 0) + $bonus_language_choices;
    if ($language_total_choices < 1) {
        $language_total_choices = 1;
    }

    $knowledge_remaining = $knowledge_total_choices - count($knowledge_selected);
    $language_remaining = $language_total_choices - count($language_selected);
    if ($knowledge_remaining < 0) {
        $errors[] = 'Превышен лимит знаний.';
    }
    if ($language_remaining < 0) {
        $errors[] = 'Превышен лимит языков.';
    }

    $dex_score = (float)($final['dex'] ?? 0);
    $con_score = (float)($final['con'] ?? 0);
    $wis_score = (float)($final['wis'] ?? 0);
    $int_score = (float)($final['int'] ?? 0);
    $dex_mod = (int)floor(($dex_score - 10) / 2);
    $con_mod = (int)floor(($con_score - 10) / 2);
    $wis_mod = (int)floor(($wis_score - 10) / 2);
    $int_mod = (int)floor(($int_score - 10) / 2);
    $legacy_equipment = [];
    if (!empty($build['equipment_bonuses']) && is_array($build['equipment_bonuses'])) {
        $legacy_equipment = $build['equipment_bonuses'];
    }
    if (isset($build['equipment']) && is_array($build['equipment'])) {
        foreach (['armor_bonus', 'shield_bonus', 'weapon_bonus'] as $legacyKey) {
            if (array_key_exists($legacyKey, $build['equipment'])) {
                $legacy_equipment[$legacyKey] = $build['equipment'][$legacyKey];
            }
        }
    }
    if (isset($build['inventory']) && is_array($build['inventory'])) {
        foreach (['armor_bonus', 'shield_bonus', 'weapon_bonus'] as $legacyKey) {
            if (array_key_exists($legacyKey, $build['inventory'])) {
                $legacy_equipment[$legacyKey] = $build['inventory'][$legacyKey];
            }
        }
    }
    $armor_from_equipped = (int)($legacy_equipment['armor_bonus'] ?? 0) + (int)$bonus_armor;
    $shield_bonus = (int)($legacy_equipment['shield_bonus'] ?? 0) + (int)$bonus_shield;
    $weapon_bonus = (int)($legacy_equipment['weapon_bonus'] ?? 0) + (int)$bonus_weapon;
    $armor_rules_bonus = (int)($resolved_rules['fixed']['armor'] ?? 0) + (int)($resolved_rules['fixed_bonuses']['armor'] ?? 0) + (int)$bonus_ac;
    $armor_equip_bonus_total = $armor_from_equipped + $armor_rules_bonus + $shield_bonus;
    $ac_total = 10 + $dex_mod + $con_mod + $armor_equip_bonus_total;

    $humanity_base = 100.0;

    $hp_base_total = (float)($resolved_rules['hp_base_total'] ?? 0);
    $hp_fixed_total = (float)(($rules_aggregate['fixed_hp_total'] ?? ($resolved_rules['fixed_bonuses']['hp'] ?? 0)) + $bonus_hp);
    $hp_con = (float)$con_mod;

    $augmentation_slots = (array)($build['augmentations']['slots'] ?? []);
    $humanity_from_augments = 0.0;
    foreach ($augmentation_slots as $slotItem) {
        foreach (af_charactersheets_normalize_slot_items($slotItem) as $slot_entry) {
            if (!is_array($slot_entry)) {
                continue;
            }
            $aug_type = (string)($slot_entry['type'] ?? $slot_entry['kb_type'] ?? '');
            $aug_key = (string)($slot_entry['key'] ?? $slot_entry['kb_key'] ?? '');
            if ($aug_type === '' || $aug_key === '') {
                continue;
            }
            $entry = af_charactersheets_kb_get_entry($aug_type, $aug_key);
            $humanity_from_augments += af_charactersheets_extract_humanity_cost_from_entry($entry);
        }
    }
    $humanity_penalty = max(0.0, $humanity_from_augments);

    $hp_total = (int)floor($hp_base_total + $hp_fixed_total + $hp_con);
    $humanity_total = (int)floor($humanity_base - $humanity_penalty);

    return [
        'base' => $attributes_base,
        'allocated' => $attributes_allocated,
        'bonus' => $bonus,
        'final' => $final,
        'pool_max' => $pool_max,
        'spent' => $spent,
        'remaining' => $remaining,
        'errors' => $errors,
        'choices' => $choices,
        'choice_details' => $choice_details,
        'skill_choice_details' => $skill_choice_requirements,
        'labels' => $attributes_labels,
        'level' => $level_data['level'],
        'level_percent' => $level_data['percent'],
        'level_exp_label' => number_format($exp, 2, '.', ' ') . ' / ' . number_format($level_data['next_req'], 2, '.', ' '),
        'exp' => $exp,
        'next_req' => $level_data['next_req'],
        'skills' => $skills_view,
        'kb_context' => $kb_context,
        'ctx' => [
            'sources' => (array)($kb_context['sources'] ?? []),
            'aggregate' => $rules_aggregate,
            'build' => $build,
            'progress' => $progress,
        ],
        'skill_pool_total' => $skill_pool_total,
        'skill_pool_spent' => $skill_pool_spent,
        'skill_pool_remaining' => $skill_pool_remaining,
        'bonus_items' => $bonus_items,
        'bonus_attr_points' => $bonus_attr_points,
        'bonus_skill_points' => $bonus_skill_points,
        'bonus_sources' => $bonus_source_labels,
        'mechanics' => [
            'armor_bonus' => $armor_from_equipped,
            'shield_bonus' => $shield_bonus,
            'weapon_bonus' => $weapon_bonus,
            'damage_base' => '1d4',
            'damage_total' => '1d4 + ' . (string)$weapon_bonus . ' + ' . (string)$final['str'],
            'ac_total' => $ac_total,
            'hp_total' => $hp_total,
            'speed_total' => (int)($rules_aggregate['speed_total'] ?? (($rules_aggregate['speed_base_total'] ?? 0) + ($resolved_rules['fixed_bonuses']['speed'] ?? 0))),
            'humanity_total' => $humanity_total,
            'hp_breakdown' => [
                'hp_base_total' => $hp_base_total,
                'fixed_total' => $hp_fixed_total,
                'from_con_mod' => $hp_con,
            ],
            'humanity_breakdown' => [
                'base' => $humanity_base,
                'from_augs' => $humanity_penalty,
            ],
            'saves' => [
                'reflex' => $dex_mod,
                'will' => $wis_mod,
                'fortitude' => $con_mod,
                'perception' => $int_mod,
            ],
        ],
        'knowledge' => [
            'selected' => $knowledge_selected,
            'bonus' => $bonus_knowledges,
            'total_choices' => $knowledge_total_choices,
            'remaining' => $knowledge_remaining,
        ],
        'debug' => $kb_debug + [
            'hp_base_total' => (int)($rules_aggregate['hp_base_total'] ?? 0),
            'fixed_hp_total' => (int)($rules_aggregate['fixed_hp_total'] ?? 0),
            'speed_total' => (int)($rules_aggregate['speed_total'] ?? 0),
            'bonus_attribute_points' => (int)($rules_aggregate['bonus_attribute_points'] ?? 0),
            'bonus_skill_points' => (int)($rules_aggregate['bonus_skill_points'] ?? 0),
            'con_mod' => $con_mod,
            'dex_mod' => $dex_mod,
            'armor_equip_bonus_total' => $armor_equip_bonus_total,
        ],
        'languages' => [
            'selected' => $language_selected,
            'bonus' => $bonus_languages,
            'total_choices' => $language_total_choices,
            'remaining' => $language_remaining,
        ],
    ];
}
