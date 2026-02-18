<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_handle_api(): void
{
    global $mybb;

    if (!af_charactersheets_is_enabled()) {
        af_charactersheets_json_response(['success' => false, 'error' => 'Addon disabled']);
    }

    $do = (string)$mybb->get_input('do');
    $sheet_id = (int)$mybb->get_input('sheet_id');
    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        af_charactersheets_json_response(['success' => false, 'error' => 'Sheet not found']);
    }

    $fid_for_mod = 0;
    if (!empty($sheet['tid'])) {
        global $db;
        $tid = (int)$sheet['tid'];
        if ($tid > 0) {
            $fid_for_mod = (int)$db->fetch_field(
                $db->simple_select('threads', 'fid', 'tid=' . $tid, ['limit' => 1]),
                'fid'
            );
        }
    }

    $can_edit = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_manage = af_cs_can_manage_sheet((int)($mybb->user['uid'] ?? 0), (int)($sheet['uid'] ?? 0));
    $can_award = af_charactersheets_user_can_award_exp($mybb->user ?? [], $fid_for_mod);
    $can_staff_reset = af_charactersheets_user_can_staff_reset($mybb->user ?? [], $fid_for_mod);
    $is_staff = af_cs_is_staff($mybb->user ?? [], $fid_for_mod);

    if (in_array($do, [
        'save_attributes',
        'save_choice',
        'grant_exp',
        'cs_skill_buy',
        'buy_skill',
        'cs_skill_set_rank',
        'cs_skill_unbuy',
        'add_knowledge',
        'remove_knowledge',
        'delete_sheet',
        'add_ability',
        'remove_ability',
        'toggle_ability',
        'inventory_add_item',
        'inventory_remove_item',
        'inventory_set_qty',
        'inventory_toggle_item',
        'equip_augmentation',
        'unequip_augmentation',
        'equip_equipment',
        'unequip_equipment',
        'reset_attributes',
        'reset_skills',
    ], true)) {
        verify_post_check($mybb->get_input('my_post_key'));
    }

    if ($do === 'delete_sheet') {
        $reason = trim((string)$mybb->get_input('reason'));
        if (!af_charactersheets_user_can_delete_sheet($sheet, $mybb->user ?? [])) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        if (!af_charactersheets_delete_sheet($sheet_id, $mybb->user ?? [], $reason)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Delete failed']);
        }

        $redirect = (string)$mybb->get_input('redirect');
        if ($redirect === '') {
            $redirect = 'misc.php?action=af_charactersheets';
        }

        af_charactersheets_json_response([
            'success' => true,
            'deleted' => true,
            'redirect' => $redirect,
        ]);
    }

    $base = af_charactersheets_json_decode((string)($sheet['base_json'] ?? ''));
    $build = af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''));
    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $build = af_charactersheets_normalize_build($build);
    $updated_skill_key = '';

    if ($do === 'save_attributes') {
        if (!$can_manage) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        $allocations = $mybb->get_input('allocations', MyBB::INPUT_ARRAY);
        $allowed = array_keys(af_charactersheets_default_attributes());
        $sanitized = [];
        foreach ($allowed as $key) {
            $value = (int)($allocations[$key] ?? 0);
            if ($value < 0) {
                $value = 0;
            }
            $sanitized[$key] = $value;
        }

        if (!empty($build['attributes_locked']) && !$is_staff) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Attributes are locked']);
        }

        $prev_build = $build;
        $build['attributes_allocated'] = $sanitized;
        $build['allocated_stats'] = $sanitized;
        $prev_spent = 0;
        foreach ((array)($prev_build['attributes_allocated'] ?? []) as $value) {
            $prev_spent += (int)$value;
        }
        $new_spent = 0;
        foreach ($sanitized as $value) {
            $new_spent += (int)$value;
        }
        $delta = $new_spent - $prev_spent;
        $view = af_charactersheets_compute_sheet_view($sheet);
        $available = (int)($view['pool_max'] ?? 0) - $prev_spent;
        if ($delta > $available) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Not enough attribute points']);
        }
        if ($is_staff) {
            $build['attributes_locked'] = 0;
            $build['locked_attributes'] = 0;
        } else {
            $build['attributes_locked'] = 1;
            $build['locked_attributes'] = 1;
        }
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
        if ($delta !== 0) {
            af_charactersheets_log_points(
                $sheet_id,
                'attribute',
                -$delta,
                'attributes_allocation',
                ['delta' => $delta]
            );
        }
    } elseif ($do === 'save_choice') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }

        $choice_key = (string)$mybb->get_input('choice_key');
        $choice_value = (string)$mybb->get_input('choice_value');
        $choice_values = array_values(array_filter(array_map('trim', explode(',', $choice_value)), static function ($value) {
            return $value !== '';
        }));
        $allowed_attr_choices = ['race_attr_bonus_choice', 'class_attr_bonus_choice', 'theme_attr_bonus_choice'];
        $is_stat_bonus_choice = (bool)preg_match('/^(race|class|theme)_stat_bonus_choice(?:_.+)?$/', $choice_key);
        if (!empty($build['attributes_locked']) && !$is_staff && (in_array($choice_key, $allowed_attr_choices, true) || $is_stat_bonus_choice)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Attributes are locked']);
        }

        if (in_array($choice_key, $allowed_attr_choices, true)) {
            if (!array_key_exists($choice_value, af_charactersheets_default_attributes())) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attribute']);
            }
        } elseif ($is_stat_bonus_choice) {
            if (empty($choice_values)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attributes']);
            }
            foreach ($choice_values as $value) {
                if (!array_key_exists($value, af_charactersheets_default_attributes())) {
                    af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attribute']);
                }
            }
            $choice_value = implode(',', $choice_values);
        } elseif (strpos($choice_key, 'skill_bonus_choice_') === 0) {
            $skills = af_charactersheets_get_skills_catalog(true);
            $allowed = array_map(static function ($row) {
                return (string)($row['slug'] ?? '');
            }, $skills);
            if ($choice_value === '' || !in_array($choice_value, $allowed, true)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid skill']);
            }
        } else {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid choice']);
        }
        $build['choices'][$choice_key] = $choice_value;
        $build['picks'][$choice_key] = $choice_value;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif (in_array($do, ['cs_skill_buy',
        'buy_skill', 'cs_skill_set_rank', 'cs_skill_unbuy'], true)) {
        if (!$can_manage) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $skill_key = trim((string)$mybb->get_input('skill_key'));
        if ($skill_key === '') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid skill']);
        }

        $context = cs_resolve_character_kb_context($sheet_id);
        $skill_map = [];
        foreach ((array)($context['skills_all'] ?? []) as $resolved) {
            $key = (string)($resolved['key'] ?? '');
            if ($key !== '') {
                $skill_map[$key] = $resolved;
            }
        }
        if (!isset($skill_map[$skill_key])) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Unknown skill']);
        }

        $rows = af_charactersheets_get_sheet_skills($sheet_id);
        $existing = [];
        foreach ($rows as $row) {
            if ((string)($row['skill_key'] ?? '') === $skill_key) {
                $existing = $row;
                break;
            }
        }

        $view = af_charactersheets_compute_sheet_view($sheet);
        $available = (int)($view['skill_pool_remaining'] ?? 0);
        $skill_data = (array)($skill_map[$skill_key]['data']['skill'] ?? []);
        $rank_max = max(1, (int)($skill_data['rank_max'] ?? $skill_data['max_rank'] ?? 1));

        if ($do === 'cs_skill_buy' || $do === 'buy_skill') {
            $current_rank = max(0, (int)($existing['skill_rank'] ?? 0));
            $source = (string)($existing['source'] ?? 'manual');
            $next_rank = $current_rank + 1;
            if ($next_rank > $rank_max) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Rank max reached']);
            }
            if ($available < 1) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Not enough skill points']);
            }
            if ($source === '' || $source === 'manual') {
                $source = 'manual';
            }
            af_charactersheets_upsert_sheet_skill($sheet_id, (int)($sheet['uid'] ?? 0), $skill_key, $next_rank, 1, $source);
            $updated_skill_key = $skill_key;
        } elseif ($do === 'cs_skill_set_rank') {
            $next_rank = (int)$mybb->get_input('skill_rank');
            $current_rank = max(0, (int)($existing['skill_rank'] ?? 0));
            $source = (string)($existing['source'] ?? 'manual');
            if ($next_rank < 0 || $next_rank > $rank_max) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Rank out of bounds']);
            }
            if ($source !== 'manual') {
                af_charactersheets_json_response(['success' => false, 'error' => 'Fixed skill cannot be modified']);
            }
            $delta = $next_rank - $current_rank;
            if ($delta > $available) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Not enough skill points']);
            }
            $is_active = $next_rank > 0 ? 1 : 0;
            af_charactersheets_upsert_sheet_skill($sheet_id, (int)($sheet['uid'] ?? 0), $skill_key, $next_rank, $is_active, 'manual');
            $updated_skill_key = $skill_key;
        } else {
            if (empty($existing) || (string)($existing['source'] ?? 'manual') !== 'manual') {
                af_charactersheets_json_response(['success' => false, 'error' => 'Only manual skill can be reset']);
            }
            af_charactersheets_delete_sheet_skill($sheet_id, $skill_key);
        }
        $build['locked_skills'] = 0;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'add_knowledge' || $do === 'remove_knowledge') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $type = (string)$mybb->get_input('type');
        $key = (string)$mybb->get_input('key');
        if (!in_array($type, ['knowledge', 'language'], true) || $key === '') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid knowledge request']);
        }
        $knowledge = (array)($build['knowledge'] ?? []);
        $list_key = $type === 'knowledge' ? 'knowledges' : 'languages';
        $selected = array_values(array_unique(array_filter((array)($knowledge[$list_key] ?? []))));

        if ($do === 'add_knowledge') {
            $view = af_charactersheets_compute_sheet_view($sheet);
            $remaining = $type === 'knowledge'
                ? (int)($view['knowledge']['remaining'] ?? 0)
                : (int)($view['languages']['remaining'] ?? 0);
            if ($remaining <= 0) {
                af_charactersheets_json_response(['success' => false, 'error' => 'No slots available']);
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            if (empty($entry)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Unknown knowledge']);
            }
            if (!in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        } else {
            $selected = array_values(array_filter($selected, static function ($item) use ($key) {
                return $item !== $key;
            }));
        }

        $knowledge[$list_key] = $selected;
        $build['knowledge'] = $knowledge;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'add_ability' || $do === 'remove_ability' || $do === 'toggle_ability') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $type = (string)$mybb->get_input('type');
        $key = (string)$mybb->get_input('key');
        if ($type === '' || $key === '') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid ability']);
        }
        $entry = af_charactersheets_kb_get_entry($type, $key);
        if (empty($entry)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Unknown ability']);
        }

        $abilities = (array)($build['abilities'] ?? []);
        $owned = (array)($abilities['owned'] ?? []);
        $slots_total = (int)($abilities['slots_total'] ?? 0);

        if ($do === 'add_ability') {
            if ($slots_total <= count($owned)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'No slots available']);
            }
            foreach ($owned as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string)($item['type'] ?? '') === $type && (string)($item['key'] ?? '') === $key) {
                    af_charactersheets_json_response(['success' => false, 'error' => 'Ability already owned']);
                }
            }
            $owned[] = [
                'type' => $type,
                'key' => $key,
                'equipped' => false,
                'slot' => '',
                'added_at' => TIME_NOW,
            ];
        } elseif ($do === 'remove_ability') {
            $owned = array_values(array_filter($owned, static function ($item) use ($type, $key) {
                return !is_array($item) || (string)($item['type'] ?? '') !== $type || (string)($item['key'] ?? '') !== $key;
            }));
        } else {
            $equip = (int)$mybb->get_input('equipped') === 1;
            $found = false;
            foreach ($owned as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string)($item['type'] ?? '') === $type && (string)($item['key'] ?? '') === $key) {
                    $owned[$idx]['equipped'] = $equip;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Ability not owned']);
            }
            if ($slots_total <= 0) {
                foreach ($owned as $idx => $item) {
                    if (is_array($item)) {
                        $owned[$idx]['equipped'] = false;
                    }
                }
            } else {
                $equipped_count = 0;
                foreach ($owned as $item) {
                    if (!empty($item['equipped'])) {
                        $equipped_count++;
                    }
                }
                if ($equipped_count > $slots_total) {
                    for ($i = count($owned) - 1; $i >= 0 && $equipped_count > $slots_total; $i--) {
                        if (!empty($owned[$i]['equipped'])) {
                            $owned[$i]['equipped'] = false;
                            $equipped_count--;
                        }
                    }
                }
            }
        }

        $abilities['owned'] = array_values($owned);
        $build['abilities'] = $abilities;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif (in_array($do, ['inventory_add_item', 'inventory_remove_item', 'inventory_set_qty', 'inventory_toggle_item'], true)) {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $type = (string)$mybb->get_input('type');
        $key = (string)$mybb->get_input('key');
        if ($type === '' || $key === '') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid item']);
        }
        $entry = af_charactersheets_kb_get_entry($type, $key);
        if (empty($entry)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Unknown item']);
        }

        $inventory = (array)($build['inventory'] ?? []);
        $items = (array)($inventory['items'] ?? []);
        $found = false;
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (af_charactersheets_get_inventory_item_type($item) === $type && af_charactersheets_get_inventory_item_key($item) === $key) {
                $found = true;
                $items[$idx]['kb_type'] = $type;
                $items[$idx]['kb_key'] = $key;
                if ($do === 'inventory_add_item') {
                    $items[$idx]['qty'] = (int)($item['qty'] ?? 0) + max(1, (int)$mybb->get_input('qty'));
                } elseif ($do === 'inventory_set_qty') {
                    $qty = (int)$mybb->get_input('qty');
                    if ($qty <= 0) {
                        unset($items[$idx]);
                    } else {
                        $items[$idx]['qty'] = $qty;
                    }
                } elseif ($do === 'inventory_toggle_item') {
                    $items[$idx]['equipped'] = (int)$mybb->get_input('equipped') === 1;
                } else {
                    unset($items[$idx]);
                }
                break;
            }
        }

        if (!$found && $do === 'inventory_toggle_item') {
            af_charactersheets_json_response(['success' => false, 'error' => 'Item not found']);
        }

        if (!$found && $do !== 'inventory_remove_item' && $do !== 'inventory_toggle_item') {
            $qty = max(1, (int)$mybb->get_input('qty'));
            $items[] = [
                'kb_type' => $type,
                'kb_key' => $key,
                'qty' => $qty,
                'equipped' => false,
                'slot' => '',
            ];
        }

        $inventory['items'] = array_values($items);
        $build['inventory'] = $inventory;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'equip_augmentation' || $do === 'unequip_augmentation') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $slot = (string)$mybb->get_input('slot');
        $slot_configs = af_charactersheets_get_augmentation_slots();
        if ($slot === '' || !array_key_exists($slot, $slot_configs)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid slot']);
        }
        $max_equipped = (int)($slot_configs[$slot]['max_equipped'] ?? 1);

        $augmentations = (array)($build['augmentations'] ?? []);
        $slots = (array)($augmentations['slots'] ?? []);

        if ($do === 'equip_augmentation') {
            $type = (string)$mybb->get_input('type');
            $key = (string)$mybb->get_input('key');
            if ($type === '' || $key === '') {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid augmentation']);
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            if (empty($entry)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Unknown augmentation']);
            }
            $allowed_slots = af_charactersheets_pick_augmentation_slots($entry);
            if ($allowed_slots && !in_array($slot, $allowed_slots, true)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Slot not allowed for this augmentation']);
            }

            $qty_owned = 0;
            $inventory = (array)($build['inventory'] ?? []);
            foreach ((array)($inventory['items'] ?? []) as $inv_item) {
                if (!is_array($inv_item)) {
                    continue;
                }
                if (af_charactersheets_get_inventory_item_type($inv_item) === $type
                    && af_charactersheets_get_inventory_item_key($inv_item) === $key
                ) {
                    $qty_owned = (int)($inv_item['qty'] ?? 0);
                    break;
                }
            }

            if ($qty_owned <= 0) {
                $owned = (array)($augmentations['owned'] ?? []);
                foreach ($owned as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if ((string)($item['type'] ?? '') === $type && (string)($item['key'] ?? '') === $key) {
                        $qty_owned = 1;
                        break;
                    }
                }
            }

            if ($qty_owned <= 0) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Augmentation not owned']);
            }

            $equipped_count = 0;
            foreach ($slots as $slot_value) {
                foreach (af_charactersheets_normalize_slot_items($slot_value) as $equipped_item) {
                    if (!is_array($equipped_item)) {
                        continue;
                    }
                    if ((string)($equipped_item['type'] ?? $equipped_item['kb_type'] ?? '') === $type
                        && (string)($equipped_item['key'] ?? $equipped_item['kb_key'] ?? '') === $key
                    ) {
                        $equipped_count++;
                    }
                }
            }

            if ($equipped_count >= $qty_owned) {
                af_charactersheets_json_response(['success' => false, 'error' => 'No available augmentation copies']);
            }

            if ($max_equipped <= 1) {
                $slots[$slot] = [
                    'type' => $type,
                    'key' => $key,
                ];
            } else {
                $slot_items = af_charactersheets_normalize_slot_items($slots[$slot] ?? []);
                if (count($slot_items) >= $max_equipped) {
                    af_charactersheets_json_response(['success' => false, 'error' => 'Slot is full']);
                }
                $slot_items[] = [
                    'type' => $type,
                    'key' => $key,
                ];
                $slots[$slot] = $slot_items;
            }
        } else {
            $remove_key = (string)$mybb->get_input('key');
            if ($max_equipped <= 1) {
                $slots[$slot] = null;
            } else {
                $slot_items = af_charactersheets_normalize_slot_items($slots[$slot] ?? []);
                if ($remove_key !== '') {
                    $slot_items = array_values(array_filter($slot_items, function ($item) use ($remove_key) {
                        if (!is_array($item)) {
                            return false;
                        }
                        $item_key = (string)($item['key'] ?? $item['kb_key'] ?? '');
                        return $item_key !== $remove_key;
                    }));
                } else {
                    $slot_items = [];
                }
                $slots[$slot] = $slot_items;
            }
        }

        $augmentations['slots'] = $slots;
        $build['augmentations'] = $augmentations;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'equip_equipment' || $do === 'unequip_equipment') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $slot = (string)$mybb->get_input('slot');
        $slots_allowed = array_keys(af_charactersheets_get_equipment_slots());
        if ($slot === '' || !in_array($slot, $slots_allowed, true)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid slot']);
        }

        $equipment = (array)($build['equipment'] ?? []);
        $slots = (array)($equipment['slots'] ?? []);

        if ($do === 'equip_equipment') {
            $type = (string)$mybb->get_input('type');
            $key = (string)$mybb->get_input('key');
            if ($type === '' || $key === '') {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid equipment']);
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            if (empty($entry)) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Unknown equipment']);
            }
            $owned = (array)($equipment['owned'] ?? []);
            $owned_match = false;
            foreach ($owned as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string)($item['type'] ?? '') === $type && (string)($item['key'] ?? '') === $key) {
                    $owned_match = true;
                    break;
                }
            }
            if (!$owned_match) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Equipment not owned']);
            }
            $slots[$slot] = [
                'type' => $type,
                'key' => $key,
            ];
        } else {
            $slots[$slot] = null;
        }

        $equipment['slots'] = $slots;
        $build['equipment'] = $equipment;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'reset_attributes') {
        if (!$can_staff_reset) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        if (!af_charactersheets_reset_attributes($sheet_id)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Reset failed']);
        }
    } elseif ($do === 'reset_skills') {
        if (!$can_staff_reset) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        if (!af_charactersheets_reset_skills($sheet_id)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Reset failed']);
        }
    } elseif ($do === 'grant_exp') {
        $amount_raw = (string)$mybb->get_input('amount');
        $reason = trim((string)$mybb->get_input('reason'));
        $result = af_charactersheets_award_exp_manual($sheet, $mybb->user ?? [], $fid_for_mod, $amount_raw, $reason);
        if (empty($result['success'])) {
            if (($result['error'] ?? '') === 'Permission denied') {
                http_response_code(403);
            }
            af_charactersheets_json_response(['success' => false, 'error' => $result['error'] ?? 'EXP update failed']);
        }
    } else {
        af_charactersheets_json_response(['success' => false, 'error' => 'Unknown action']);
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    $view = af_charactersheets_compute_sheet_view($sheet);
    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);
    $build = af_charactersheets_normalize_build(af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')));
    $attributes_locked = !empty($build['attributes_locked']);
    $can_edit_attributes = ($can_edit && !$attributes_locked) || $is_staff;

    $attributes_html = af_charactersheets_build_attributes_html($view, $can_edit_attributes, $can_view_ledger, $can_staff_reset, $attributes_locked);
    $progress_html = af_charactersheets_build_progress_html($view, $sheet, $can_award, $can_view_ledger);
    $skills_locked = !empty($build['locked_skills']);
    $can_manage_skills = $can_manage && (!$skills_locked || $is_staff);
    $skills_html = af_charactersheets_build_skills_html($view, $can_manage_skills, $can_view_ledger, $can_staff_reset, $skills_locked);
    $knowledge_html = af_charactersheets_build_knowledge_html($view, $can_edit, $can_view_ledger);
    $abilities_html = af_charactersheets_build_abilities_html($build, $can_edit);
    $inventory_html = af_charactersheets_build_inventory_html($build, $can_edit);
    $augmentations_html = af_charactersheets_build_augments_html($build, $can_edit, $view);
    $equipment_html = af_charactersheets_build_equipment_html($build, $can_edit);
    $mechanics_html = af_charactersheets_build_mechanics_html($view);

    af_charactersheets_json_response([
        'success' => true,
        'ok' => true,
        'pool' => [
            'total' => (int)($view['skill_pool_total'] ?? 0),
            'spent' => (int)($view['skill_pool_spent'] ?? 0),
            'available' => (int)($view['skill_pool_remaining'] ?? 0),
            'remaining' => (int)($view['skill_pool_remaining'] ?? 0),
        ],
        'skill_row' => $updated_skill_key !== '' ? af_charactersheets_find_skill_view_row($view, $updated_skill_key) : null,
        'view' => $view,
        'attributes_html' => $attributes_html,
        'progress_html' => $progress_html,
        'skills_html' => $skills_html,
        'html_skills' => $skills_html,
        'knowledge_html' => $knowledge_html,
        'abilities_html' => $abilities_html,
        'inventory_html' => $inventory_html,
        'augmentations_html' => $augmentations_html,
        'equipment_html' => $equipment_html,
        'mechanics_html' => $mechanics_html,
    ]);
}

function af_charactersheets_json_response(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_charactersheets_find_skill_view_row(array $view, string $skill_key): ?array
{
    if ($skill_key === '') {
        return null;
    }
    foreach ((array)($view['skills'] ?? []) as $skill) {
        if (!is_array($skill)) {
            continue;
        }
        if ((string)($skill['skill_key'] ?? '') === $skill_key) {
            return $skill;
        }
    }
    return null;
}
