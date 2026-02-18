<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

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

function af_charactersheets_sum_resource_slots_from_grants(array $grants, string $resource_key): int
{
    $resource_key = trim($resource_key);
    if ($resource_key === '') {
        return 0;
    }

    $slots = 0;
    foreach ($grants as $grant) {
        if (!is_array($grant)) {
            continue;
        }

        if ((string)($grant['op'] ?? '') !== 'resource') {
            continue;
        }

        if ((string)($grant['key'] ?? '') !== $resource_key) {
            continue;
        }

        $value = (int)($grant['value'] ?? 0);
        if ((string)($grant['mode'] ?? 'add') === 'set') {
            $slots = $value;
            continue;
        }

        $slots += $value;
    }

    return $slots;
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

function af_charactersheets_format_damage_total(string $base, int $bonus): string
{
    if ($bonus === 0) {
        return $base;
    }

    return $bonus > 0
        ? $base . '+' . $bonus
        : $base . $bonus;
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
    $attributes_allocated = array_merge(af_charactersheets_zero_attributes(), (array)($build['allocated_stats'] ?? $build['attributes_allocated'] ?? []));

    $choices = (array)($build['choices'] ?? []);
    $errors = [];
    $bonus = af_charactersheets_zero_attributes();
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
                $choice_key = $source !== '' ? ($source . '_attr_bonus_choice') : '';
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
    $skill_pick_choices = af_charactersheets_collect_skill_pick_choices($kb_context, $build);
    $skill_choice_grant_ranks = [];
    $skill_choice_rank_maxes = [];
    $skill_pick_choice_details = [];
    foreach ($skill_pick_choices as $choice) {
        $selected_values = (array)($choice['selected'] ?? []);
        $grant_mode = (string)($choice['grant_mode'] ?? 'rank');
        $rank_value = max(1, (int)($choice['rank_value'] ?? 1));
        $rank_max_value = max(0, (int)($choice['rank_max_value'] ?? 0));
        $points_value = max(0, (int)($choice['points_value'] ?? 0));

        if ($grant_mode === 'rank') {
            foreach ($selected_values as $skill_key) {
                $skill_choice_grant_ranks[$skill_key] = max((int)($skill_choice_grant_ranks[$skill_key] ?? 0), $rank_value);
                if ($rank_max_value > 0) {
                    $skill_choice_rank_maxes[$skill_key] = max((int)($skill_choice_rank_maxes[$skill_key] ?? 0), $rank_max_value);
                }
            }
        } elseif ($grant_mode === 'points' && $points_value > 0) {
            $bonus_skill_points += count($selected_values) * $points_value;
        }

        $skill_pick_choice_details[] = [
            'source' => (string)($choice['source'] ?? ''),
            'id' => (string)($choice['id'] ?? ''),
            'choice_key' => (string)($choice['choice_key'] ?? ''),
            'pick' => max(1, (int)($choice['pick'] ?? 1)),
            'options' => (array)($choice['options'] ?? []),
            'selected' => $selected_values,
            'grant_mode' => $grant_mode,
            'rank_value' => $rank_value,
            'rank_max_value' => $rank_max_value,
            'points_value' => $points_value,
        ];
    }

    foreach (['race', 'class', 'theme'] as $sourceKey) {
        $resolved = (array)($kb_context[$sourceKey] ?? []);
        foreach (af_charactersheets_extract_knowledge_grants($resolved, 'knowledge') as $key) {
            $bonus_knowledges[] = $key;
        }
        foreach (af_charactersheets_extract_knowledge_grants($resolved, 'language') as $key) {
            $bonus_languages[] = $key;
        }
    }

    foreach (['str','dex','con','int','wis','cha'] as $statKey) {
        $bonus[$statKey] += (float)($rules_aggregate['fixed']['stats'][$statKey] ?? 0);
        $bonus[$statKey] += (float)($rules_aggregate['fixed_bonuses']['stats'][$statKey] ?? 0);
    }

    foreach (['race', 'class', 'theme'] as $src) {
        $source_choices = (array)($source_rules_map[$src]['choices'] ?? []);
        foreach ($source_choices as $choice) {
            if (!is_array($choice) || (string)($choice['type'] ?? '') !== 'stat_bonus') {
                continue;
            }
            if ((string)($choice['mode'] ?? 'add') !== 'add') {
                continue;
            }
            $pick = max(0, (int)($choice['pick'] ?? 0));
            $value = (float)($choice['value'] ?? 0);
            if ($pick < 1 || $value == 0.0) {
                continue;
            }

            $choice_id = trim((string)($choice['id'] ?? ''));
            $choice_key = $src . '_stat_bonus_choice' . ($choice_id !== '' ? ('_' . $choice_id) : '');
            $selected = $choices[$choice_key] ?? [];
            if (is_string($selected)) {
                $selected = array_filter(array_map('trim', explode(',', $selected)));
            }
            if (!is_array($selected)) {
                $selected = [];
            }
            $selected = array_values(array_unique(array_filter($selected, static function ($stat) use ($bonus) {
                return is_string($stat) && array_key_exists($stat, $bonus);
            })));
            if (count($selected) > $pick) {
                $selected = array_slice($selected, 0, $pick);
            }

            $choice_requirements[] = [
                'source' => $src,
                'choice_key' => $choice_key,
                'chosen' => $selected,
                'pick' => $pick,
            ];

            if (count($selected) < $pick) {
                $errors[] = 'Не выбран бонус атрибутов для ' . $src;
                continue;
            }

            foreach ($selected as $stat) {
                $bonus[$stat] += $value;
            }
        }
    }
    $bonus_attr_points += (int)($rules_aggregate['points_pools']['attribute_points'] ?? 0);
    $bonus_skill_points += (int)($rules_aggregate['fixed']['skill_points'] ?? 0)
        + (int)($rules_aggregate['fixed_bonuses']['skill_points'] ?? 0);
    $bonus_knowledge_choices += af_charactersheets_sum_resource_slots_from_grants((array)($rules_aggregate['grants'] ?? []), 'knowledge_slots');
    $bonus_language_choices += af_charactersheets_sum_resource_slots_from_grants((array)($rules_aggregate['grants'] ?? []), 'language_slots');

    $exp = (float)($progress['exp'] ?? 0);
    $level_data = af_charactersheets_compute_level($exp);
    $progress['level'] = (int)($progress['level'] ?? $level_data['level']);
    $level_for_bonus = max(1, (int)($level_data['level'] ?? 1));
    $auto_stat_bonus = 1 + intdiv($level_for_bonus, 5);

    $final = [];
    foreach ($attributes_base as $key => $value) {
        $final[$key] = (float)($attributes_allocated[$key] ?? 0) + (float)($bonus[$key] ?? 0) + (float)$auto_stat_bonus;
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


    $attributes_labels = af_charactersheets_get_attribute_labels();
    $choice_details = [];
    foreach ($choice_requirements as $data) {
        $source = (string)($data['source'] ?? '');
        $entry = af_charactersheets_kb_get_entry($source, (string)$sources[$source]);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        if ($label === '') {
            $label = $source;
        }
        $choice_details[] = [
            'source' => $source,
            'label' => $label,
            'choice_key' => (string)($data['choice_key'] ?? ''),
            'chosen' => $data['chosen'],
            'pick' => (int)($data['pick'] ?? 1),
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
    $grant_skill_ranks = [];
    $grant_skill_rank_maxes = [];
    foreach (['race', 'class', 'theme'] as $source) {
        $resolved = (array)($kb_context[$source] ?? []);
        foreach (af_charactersheets_extract_skill_grants($resolved, $source) as $grant) {
            $skill_key = (string)($grant['skill_key'] ?? '');
            if ($skill_key === '') {
                continue;
            }
            $grant_skill_ranks[$skill_key] = max(
                (int)($grant_skill_ranks[$skill_key] ?? 0),
                max(1, (int)($grant['skill_rank'] ?? 1))
            );
            $grant_skill_rank_maxes[$skill_key] = max(
                (int)($grant_skill_rank_maxes[$skill_key] ?? 0),
                max(0, (int)($grant['rank_max'] ?? 0))
            );
        }
    }
    foreach ($skill_choice_grant_ranks as $skill_key => $skill_rank) {
        $grant_skill_ranks[(string)$skill_key] = max((int)($grant_skill_ranks[(string)$skill_key] ?? 0), (int)$skill_rank);
    }
    foreach ($skill_choice_rank_maxes as $skill_key => $rank_max) {
        $grant_skill_rank_maxes[(string)$skill_key] = max((int)($grant_skill_rank_maxes[(string)$skill_key] ?? 0), (int)$rank_max);
    }

    $skills_map = [];
    foreach ($skills_rows as $row) {
        $skill_key = (string)($row['skill_key'] ?? '');
        if ($skill_key === '') {
            continue;
        }

        $source = (string)($row['source'] ?? 'manual');
        $existing = (array)($skills_map[$skill_key] ?? []);
        if (!empty($existing)) {
            $fixedSources = ['race', 'class', 'theme', 'race_choice', 'class_choice', 'theme_choice'];
            $existingSource = (string)($existing['source'] ?? 'manual');
            if (in_array($existingSource, $fixedSources, true)) {
                if (in_array($source, $fixedSources, true)
                    && (int)($row['skill_rank'] ?? 0) > (int)($existing['skill_rank'] ?? 0)
                ) {
                    $skills_map[$skill_key] = $row;
                }
                continue;
            }
            if (!in_array($source, $fixedSources, true)
                && (int)($row['skill_rank'] ?? 0) <= (int)($existing['skill_rank'] ?? 0)
            ) {
                continue;
            }
        }
        $skills_map[$skill_key] = $row;
    }

    $skill_rank_bonus_map = [
        0 => 0,
        1 => 2,
        2 => 5,
        3 => 10,
        4 => 15,
        5 => 20,
    ];
    $skills_view = [];
    $skills_all = (array)($kb_context['skills_all'] ?? []);
    $skills_catalog_fetched = count($skills_all);
    $skills_catalog_keys = [];
    $skills_filter_reason_counts = [
        'missing_key' => 0,
        'not_skill' => 0,
    ];
    $manual_spent = 0;
    foreach ($skills_all as $skill_resolved) {
        $skill_key = (string)($skill_resolved['key'] ?? '');
        if ($skill_key === '') {
            $skills_filter_reason_counts['missing_key']++;
            continue;
        }
        if (count($skills_catalog_keys) < 5) {
            $skills_catalog_keys[] = $skill_key;
        }

        $data = (array)($skill_resolved['data'] ?? []);
        $skill_data = (array)($data['skill'] ?? []);
        $resolved_type = (string)($skill_resolved['type_key'] ?? $skill_resolved['entry']['type'] ?? '');
        $is_skill = ($resolved_type === 'skill')
            || ((string)($data['type_profile'] ?? '') === 'skill')
            || !empty($skill_data);
        if (!$is_skill) {
            $skills_filter_reason_counts['not_skill']++;
            continue;
        }

        $attr_key = af_charactersheets_resolve_skill_attribute_key($skill_data, $data);
        $skill_entry = af_charactersheets_kb_get_entry('skill', $skill_key);
        $skill_entry_data = (array)($skill_entry['data'] ?? []);
        $skill_entry_data_skill = (array)($skill_entry_data['skill'] ?? []);
        $key_stat = (string)($skill_entry_data_skill['key_stat'] ?? '');
        if (trim($key_stat) === '') {
            $key_stat = (string)($skill_entry_data_skill['attribute'] ?? '');
        }
        $key_stat = strtolower(trim($key_stat));
        if (!in_array($key_stat, ['str', 'dex', 'con', 'int', 'wis', 'cha'], true)) {
            $key_stat = '';
        }
        $key_stat_label = $key_stat !== '' ? (string)($attributes_labels[$key_stat] ?? '') : '';
        $base_mod = (int)floor((float)($final[$attr_key] ?? 0));
        $row = (array)($skills_map[$skill_key] ?? []);
        $skill_rank = max(0, (int)($row['skill_rank'] ?? 0));
        $is_active = (int)($row['is_active'] ?? 0) === 1;
        $source = (string)($row['source'] ?? '');
        $bonus_val = (float)($bonus_skill_map[$skill_key] ?? 0);
        $rank_bonus = (float)($skill_rank_bonus_map[$skill_rank] ?? 0);
        if (!array_key_exists($skill_rank, $skill_rank_bonus_map) && $skill_rank > 5) {
            $rank_bonus = 20 + (($skill_rank - 5) * 5);
        }
        $total = $base_mod + ($is_active ? $rank_bonus : 0) + $bonus_val;
        $grant_rank = max(0, (int)($grant_skill_ranks[$skill_key] ?? 0));
        if ($is_active) {
            $manual_spent += max(
                0,
                af_charactersheets_skill_rank_total_cost($skill_rank) - af_charactersheets_skill_rank_total_cost($grant_rank)
            );
        }

        $kb_rank_max = max(0, (int)($skill_data['rank_max_final'] ?? $skill_data['rank_max'] ?? $skill_data['max_rank'] ?? 0));
        $rank_max_from_sources = max(0, (int)($grant_skill_rank_maxes[$skill_key] ?? 0));
        $rank_max_candidates = [];
        if ($kb_rank_max > 0) {
            $rank_max_candidates[] = $kb_rank_max;
        }
        if ($rank_max_from_sources > 0) {
            $rank_max_candidates[] = $rank_max_from_sources;
        }
        $rank_max = $rank_max_candidates ? max($rank_max_candidates) : 5;

        $skills_view[] = [
            'skill_key' => $skill_key,
            'title' => (string)($skill_resolved['title'] ?? $skill_key),
            'category' => (string)($skill_data['category'] ?? 'general'),
            'skill_rank' => $skill_rank,
            'rank_max' => max(1, $rank_max),
            'rank_min' => $grant_rank,
            'source' => $source,
            'is_active' => $is_active,
            'trained_only' => !empty($skill_data['trained_only']),
            'notes' => (string)($skill_data['notes'] ?? ''),
            'attr_key' => $attr_key,
            'attr_label' => $attributes_labels[$attr_key] ?? $attr_key,
            'key_stat' => $key_stat,
            'key_stat_label' => $key_stat_label,
            'base' => $base_mod,
            'rank_bonus' => $rank_bonus,
            'bonus' => $bonus_val,
            'total' => $total,
        ];
    }

    $skills_catalog_after_filter = count($skills_view);
    if ($skills_catalog_fetched <= 0 || $skills_catalog_after_filter <= 0) {
        af_charactersheets_log('skills_catalog: fetched=' . $skills_catalog_fetched . ' type=skill', [
            'first_keys' => $skills_catalog_keys,
        ]);
        if ($skills_catalog_after_filter <= 0) {
            $reason = [];
            foreach ($skills_filter_reason_counts as $reason_key => $reason_count) {
                if ($reason_count > 0) {
                    $reason[] = $reason_key . ':' . $reason_count;
                }
            }
            af_charactersheets_log('skills_catalog: after_filter=' . $skills_catalog_after_filter . ' reason=' . ($reason ? implode(',', $reason) : 'none'));
        }
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

    $knowledge_base_choices = (int)($mybb->settings['af_charactersheets_knowledge_base_choices'] ?? 0);
    $knowledge_per_int = (float)($mybb->settings['af_charactersheets_knowledge_per_int'] ?? 0);
    $int_value = (float)($final['int'] ?? 0);
    $knowledge_from_int = (int)floor($int_value * $knowledge_per_int);
    $knowledge_total_choices = $knowledge_base_choices
        + (int)($resolved_rules['fixed']['knowledge_slots'] ?? 0)
        + (int)($resolved_rules['fixed_bonuses']['knowledge_slots'] ?? 0)
        + $knowledge_from_int
        + $bonus_knowledge_choices;
    $language_total_choices = (int)($resolved_rules['fixed']['language_slots'] ?? 0)
        + (int)($resolved_rules['fixed_bonuses']['language_slots'] ?? 0)
        + $bonus_language_choices;

    $knowledge_remaining = $knowledge_total_choices - count($knowledge_selected);
    $language_remaining = $language_total_choices - count($language_selected);
    if ($knowledge_remaining < 0) {
        $errors[] = 'Превышен лимит знаний.';
    }
    if ($language_remaining < 0) {
        $errors[] = 'Превышен лимит языков.';
    }

    $dex_final = (int)floor((float)($final['dex'] ?? 0));
    $con_final = (int)floor((float)($final['con'] ?? 0));
    $wis_final = (int)floor((float)($final['wis'] ?? 0));
    $int_final = (int)floor((float)($final['int'] ?? 0));
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
    $ac_total = $dex_final + $con_final + $armor_equip_bonus_total;

    $humanity_base = 100.0;

    $hp_base_breakdown = [
        'race' => (float)($source_rules_map['race']['hp_base'] ?? 0),
        'class' => (float)($source_rules_map['class']['hp_base'] ?? 0),
        'theme' => (float)($source_rules_map['theme']['hp_base'] ?? 0),
    ];
    $hp_fixed_breakdown = [
        'race' => (float)($source_rules_map['race']['fixed_bonuses']['hp'] ?? 0),
        'class' => (float)($source_rules_map['class']['fixed_bonuses']['hp'] ?? 0),
        'theme' => (float)($source_rules_map['theme']['fixed_bonuses']['hp'] ?? 0),
        'extra' => (float)$bonus_hp,
    ];
    $hp_base_total = array_sum($hp_base_breakdown);
    $hp_fixed_total = array_sum($hp_fixed_breakdown);
    $hp_con = (float)$con_final;
    $race_speed = (int)($source_rules_map['race']['speed'] ?? 0);
    $damage_bonus_total = $weapon_bonus + (int)floor((float)($final['str'] ?? 0));

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
        'allocated' => $attributes_allocated,
        'base' => array_fill_keys(array_keys($attributes_base), (float)$auto_stat_bonus),
        'bonus' => $bonus,
        'final' => $final,
        'pool_max' => $pool_max,
        'spent' => $spent,
        'remaining' => $remaining,
        'errors' => $errors,
        'choices' => $choices,
        'choice_details' => $choice_details,
        'skill_choice_details' => $skill_choice_requirements,
        'skill_pick_choice_details' => $skill_pick_choice_details,
        'labels' => $attributes_labels,
        'level' => $level_data['level'],
        'level_percent' => $level_data['percent'],
        'level_exp_label' => number_format((float)($level_data['exp_in_level'] ?? 0), 2, '.', ' ') . ' / ' . number_format((float)($level_data['exp_need'] ?? 0), 2, '.', ' '),
        'exp' => $exp,
        'next_req' => $level_data['next_req'],
        'prev_req_total' => (float)($level_data['prev_req_total'] ?? 0),
        'next_req_total' => (float)($level_data['next_req_total'] ?? 0),
        'exp_in_level' => (float)($level_data['exp_in_level'] ?? 0),
        'exp_need' => (float)($level_data['exp_need'] ?? 0),
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
            'damage_bonus' => $damage_bonus_total,
            'auto_stat_bonus' => $auto_stat_bonus,
            'damage_total' => af_charactersheets_format_damage_total('1d4', $damage_bonus_total),
            'ac_total' => $ac_total,
            'hp_total' => $hp_total,
            'speed_total' => $race_speed,
            'humanity_total' => $humanity_total,
            'hp_breakdown' => [
                'hp_base_total' => $hp_base_total,
                'hp_base' => $hp_base_breakdown,
                'fixed_total' => $hp_fixed_total,
                'fixed_bonuses_hp' => $hp_fixed_breakdown,
                'from_con' => $hp_con,
            ],
            'humanity_breakdown' => [
                'base' => $humanity_base,
                'from_augs' => $humanity_penalty,
            ],
            'saves' => [
                'reflex' => $dex_final,
                'will' => $wis_final,
                'fortitude' => $con_final,
                'fort' => $con_final,
                'perception' => $int_final,
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
            'con_final' => $con_final,
            'dex_final' => $dex_final,
            'race_speed' => $race_speed,
            'damage_bonus' => $damage_bonus_total,
            'auto_stat_bonus' => $auto_stat_bonus,
            'armor_equip_bonus_total' => $armor_equip_bonus_total,
            'hp_base_breakdown' => $hp_base_breakdown,
            'fixed_hp_breakdown' => $hp_fixed_breakdown,
            'hp_total' => $hp_total,
        ],
        'languages' => [
            'selected' => $language_selected,
            'bonus' => $bonus_languages,
            'total_choices' => $language_total_choices,
            'remaining' => $language_remaining,
        ],
    ];
}
