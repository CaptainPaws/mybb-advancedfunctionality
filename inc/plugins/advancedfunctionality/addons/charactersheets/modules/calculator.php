<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * Contract for bonuses in KB JSON (meta_json or blocks[].data_json):
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
    $entry = af_charactersheets_kb_get_entry($source, $key);
    if (empty($entry)) {
        return [];
    }

    $items = [];
    $attributes = af_charactersheets_default_attributes();

    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $sets = [];
    if (!empty($meta['bonuses']) && is_array($meta['bonuses'])) {
        $sets[] = $meta['bonuses'];
    }
    if (!empty($meta['modifiers']) && is_array($meta['modifiers'])) {
        $sets[] = $meta['modifiers'];
    }
    $items = array_merge($items, af_charactersheets_rules_to_bonus_items($meta, $source, $attributes));
    if (!empty($meta['rules']) && is_array($meta['rules'])) {
        $items = array_merge($items, af_charactersheets_rules_to_bonus_items($meta['rules'], $source, $attributes));
    }
    foreach (['stats', 'attributes'] as $metaKey) {
        if (!empty($meta[$metaKey]) && is_array($meta[$metaKey])) {
            foreach ($meta[$metaKey] as $stat => $value) {
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

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        if (empty($data)) {
            continue;
        }
        $items = array_merge($items, af_charactersheets_rules_to_bonus_items($data, $source, $attributes));
        if (!empty($data['rules']) && is_array($data['rules'])) {
            $items = array_merge($items, af_charactersheets_rules_to_bonus_items($data['rules'], $source, $attributes));
        }
        if (!empty($data['bonuses']) && is_array($data['bonuses'])) {
            $sets[] = $data['bonuses'];
        }
        if (!empty($data['modifiers']) && is_array($data['modifiers'])) {
            $sets[] = $data['modifiers'];
        }
        foreach (['stats', 'attributes'] as $blockKey) {
            if (!empty($data[$blockKey]) && is_array($data[$blockKey])) {
                foreach ($data[$blockKey] as $stat => $value) {
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
    $choices = $rules['choices'] ?? [];
    if (is_array($choices)) {
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $type = (string)($choice['type'] ?? '');
            $value = (float)($choice['value'] ?? 0);
            $pick = (int)($choice['pick'] ?? 1);
            if ($pick <= 0) {
                $pick = 1;
            }
            if (in_array($type, ['stat_bonus', 'stat_plus_2', 'stat_plus', 'attribute_points'], true)) {
                $items[] = [
                    'source' => $source,
                    'type' => 'attribute_points',
                    'target' => null,
                    'value' => (float)($value * $pick),
                    'requires_choice' => true,
                ];
            }
        }
    }

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

function af_charactersheets_collect_bonus_items(array $base): array
{
    $items = [];
    $sources = [
        'race' => (string)($base['race_key'] ?? ''),
        'class' => (string)($base['class_key'] ?? ''),
        'themes' => (string)($base['theme_key'] ?? ''),
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
        $type = (string)($item['type'] ?? '');
        $key = (string)($item['key'] ?? '');
        if ($type === '' || $key === '') {
            continue;
        }
        $items = array_merge($items, af_charactersheets_normalize_bonus_items($type, $key));
    }

    $augmentations = (array)($build['augmentations'] ?? []);
    foreach ((array)($augmentations['slots'] ?? []) as $slotItem) {
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

function af_charactersheets_compute_sheet_view(array $sheet): array
{
    global $mybb;

    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $build = af_charactersheets_normalize_build($build);

    $attributes_base = array_merge(af_charactersheets_default_attributes(), (array)($base['attributes_base'] ?? []));
    $attributes_allocated = array_merge(af_charactersheets_default_attributes(), (array)($build['attributes_allocated'] ?? []));

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

    $sources = [
        'race' => (string)($base['race_key'] ?? ''),
        'class' => (string)($base['class_key'] ?? ''),
        'themes' => (string)($base['theme_key'] ?? ''),
    ];

    $choice_map = [
        'race' => 'race_attr_bonus_choice',
        'class' => 'class_attr_bonus_choice',
        'themes' => 'theme_attr_bonus_choice',
    ];

    $bonus_items = af_charactersheets_collect_bonus_items($base);
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

    $final = [];
    foreach ($attributes_base as $key => $value) {
        $final[$key] = (float)$value + (float)($attributes_allocated[$key] ?? 0) + (float)($bonus[$key] ?? 0);
    }

    $spent = 0;
    foreach ($attributes_allocated as $value) {
        $spent += (int)$value;
    }
    $pool_remaining = (int)($progress['attr_points_free'] ?? 0) + $bonus_attr_points;
    $pool_max = $pool_remaining + $spent;
    $remaining = $pool_remaining;
    if ($pool_remaining < 0) {
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

    $skills_catalog = af_charactersheets_get_skills_catalog(true);
    $skills_invested = (array)($build['skills'] ?? []);
    $skills_view = [];
    $skills_spent = 0;
    foreach ($skills_catalog as $skill) {
        $slug = (string)$skill['slug'];
        $attr_key = (string)$skill['attr_key'];
        $base_val = (float)($final[$attr_key] ?? 0);
        $base_mod = (int)floor($base_val);
        $invested = (int)($skills_invested[$slug] ?? 0);
        if ($invested < 0) {
            $invested = 0;
        }
        $skills_spent += $invested;
        $bonus_val = (float)($bonus_skill_map[$slug] ?? 0);
        $total = $base_mod + $invested + $bonus_val;

        $skills_view[] = [
            'slug' => $slug,
            'title' => (string)$skill['title'],
            'attr_key' => $attr_key,
            'attr_label' => $attributes_labels[$attr_key] ?? $attr_key,
            'base' => $base_mod,
            'invested' => $invested,
            'bonus' => $bonus_val,
            'total' => $total,
        ];
    }

    $skill_pool_remaining = (int)($progress['skill_points_free'] ?? 0) + $bonus_skill_points;
    $skill_pool_total = $skill_pool_remaining + $skills_spent;
    if ($skill_pool_remaining < 0) {
        $errors[] = 'Превышен лимит очков навыков.';
    }

    $knowledge_build = (array)($build['knowledge'] ?? []);
    $knowledge_selected = array_values(array_unique(array_filter((array)($knowledge_build['knowledges'] ?? []))));
    $language_selected = array_values(array_unique(array_filter((array)($knowledge_build['languages'] ?? []))));
    $bonus_languages = array_values(array_unique($bonus_languages));
    $bonus_knowledges = array_values(array_unique($bonus_knowledges));

    $knowledge_base_choices = (int)($mybb->settings['af_charactersheets_knowledge_base_choices'] ?? 0);
    $knowledge_per_int = (float)($mybb->settings['af_charactersheets_knowledge_per_int'] ?? 0);
    $int_value = (float)($final['int'] ?? 0);
    $knowledge_from_int = (int)floor($int_value * $knowledge_per_int);
    $knowledge_total_choices = $knowledge_base_choices + $knowledge_from_int + $bonus_knowledge_choices;
    if ($knowledge_total_choices < 1) {
        $knowledge_total_choices = 1;
    }
    $language_total_choices = 1 + $bonus_language_choices;
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

    $dex_mod = (int)floor((float)($final['dex'] ?? 0));
    $con_mod = (int)floor((float)($final['con'] ?? 0));
    $wis_mod = (int)floor((float)($final['wis'] ?? 0));
    $int_mod = (int)floor((float)($final['int'] ?? 0));
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
    $armor_bonus = (int)($legacy_equipment['armor_bonus'] ?? 0) + (int)$bonus_armor;
    $shield_bonus = (int)($legacy_equipment['shield_bonus'] ?? 0) + (int)$bonus_shield;
    $weapon_bonus = (int)($legacy_equipment['weapon_bonus'] ?? 0) + (int)$bonus_weapon;
    $ac_total = 10 + $dex_mod + $armor_bonus + $shield_bonus + (int)$bonus_ac;

    $humanity_setting = $mybb->settings['af_charactersheets_humanity_base'] ?? null;
    $humanity_base = $humanity_setting === '' || $humanity_setting === null ? 100.0 : (float)$humanity_setting;

    $race_entry = af_charactersheets_kb_get_entry('race', (string)($base['race_key'] ?? ''));
    $class_entry = af_charactersheets_kb_get_entry('class', (string)($base['class_key'] ?? ''));
    $hp_race = af_charactersheets_extract_hp_from_entry($race_entry);
    $hp_class = af_charactersheets_extract_hp_from_entry($class_entry);
    $hp_con = (float)($final['con'] ?? 0);
    $hp_bonus_kb = (float)$bonus_hp;

    $augmentation_slots = (array)($build['augmentations']['slots'] ?? []);
    $humanity_from_augments = 0.0;
    foreach ($augmentation_slots as $slotItem) {
        if (!is_array($slotItem)) {
            continue;
        }
        $aug_type = (string)($slotItem['type'] ?? '');
        $aug_key = (string)($slotItem['key'] ?? '');
        if ($aug_type === '' || $aug_key === '') {
            continue;
        }
        $aug_bonus_items = af_charactersheets_normalize_bonus_items($aug_type, $aug_key);
        foreach ($aug_bonus_items as $bonus_item) {
            if ((string)($bonus_item['type'] ?? '') === 'humanity_bonus') {
                $humanity_from_augments += (float)($bonus_item['value'] ?? 0);
            }
        }
    }
    $humanity_penalty = abs(min(0.0, $humanity_from_augments));
    $humanity_bonus = (float)$bonus_humanity - min(0.0, $humanity_from_augments);

    $hp_total = (int)floor($hp_race + $hp_class + $hp_con + $hp_bonus_kb);
    $humanity_total = (int)floor($humanity_base - $humanity_penalty + $humanity_bonus);

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
        'skill_pool_total' => $skill_pool_total,
        'skill_pool_spent' => $skills_spent,
        'skill_pool_remaining' => $skill_pool_remaining,
        'bonus_items' => $bonus_items,
        'bonus_attr_points' => $bonus_attr_points,
        'bonus_skill_points' => $bonus_skill_points,
        'bonus_sources' => $bonus_source_labels,
        'mechanics' => [
            'armor_bonus' => $armor_bonus,
            'shield_bonus' => $shield_bonus,
            'weapon_bonus' => $weapon_bonus,
            'ac_total' => $ac_total,
            'hp_total' => $hp_total,
            'humanity_total' => $humanity_total,
            'hp_breakdown' => [
                'race' => $hp_race,
                'class' => $hp_class,
                'from_con' => $hp_con,
                'from_kb' => $hp_bonus_kb,
            ],
            'humanity_breakdown' => [
                'base' => $humanity_base,
                'from_augs' => $humanity_penalty,
                'from_kb' => $humanity_bonus,
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
        'languages' => [
            'selected' => $language_selected,
            'bonus' => $bonus_languages,
            'total_choices' => $language_total_choices,
            'remaining' => $language_remaining,
        ],
    ];
}
