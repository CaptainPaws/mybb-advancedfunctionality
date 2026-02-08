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

    $can_edit = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_award = af_charactersheets_user_can_award_exp($mybb->user ?? []);

    if (in_array($do, ['save_attributes', 'save_choice', 'grant_exp', 'update_skill', 'add_knowledge', 'remove_knowledge', 'delete_sheet'], true)) {
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

    if ($do === 'save_attributes') {
        if (!$can_edit) {
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

        $prev_build = $build;
        $build['attributes_allocated'] = $sanitized;
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
        $available = (int)($progress['attr_points_free'] ?? 0) + (int)($view['bonus_attr_points'] ?? 0);
        if ($delta > $available) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Not enough attribute points']);
        }
        $progress['attr_points_free'] = (int)($progress['attr_points_free'] ?? 0) - $delta;
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
        $allowed_attr_choices = ['race_attr_bonus_choice', 'class_attr_bonus_choice', 'theme_attr_bonus_choice'];
        if (in_array($choice_key, $allowed_attr_choices, true)) {
            if (!array_key_exists($choice_value, af_charactersheets_default_attributes())) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Invalid attribute']);
            }
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
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
    } elseif ($do === 'update_skill') {
        if (!$can_edit) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $slug = (string)$mybb->get_input('slug');
        $delta = (int)$mybb->get_input('delta');
        if ($slug === '' || ($delta !== 1 && $delta !== -1)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Invalid skill update']);
        }
        $skills = af_charactersheets_get_skills_catalog(true);
        $allowed = array_map(static function ($row) {
            return (string)($row['slug'] ?? '');
        }, $skills);
        if (!in_array($slug, $allowed, true)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Unknown skill']);
        }

        $current = (int)($build['skills'][$slug] ?? 0);
        $next = max(0, $current + $delta);

        $view = af_charactersheets_compute_sheet_view($sheet);
        $available = (int)($progress['skill_points_free'] ?? 0) + (int)($view['bonus_skill_points'] ?? 0);
        if ($delta > $available) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Not enough skill points']);
        }
        $progress['skill_points_free'] = (int)($progress['skill_points_free'] ?? 0) - $delta;
        $build['skills'][$slug] = $next;
        af_charactersheets_update_sheet_json($sheet_id, $base, $build, $progress);
        if ($delta !== 0) {
            af_charactersheets_log_points(
                $sheet_id,
                'skill',
                -$delta,
                'skill_allocation',
                ['slug' => $slug, 'delta' => $delta]
            );
        }
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
    } elseif ($do === 'grant_exp') {
        if (!$can_award) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Permission denied']);
        }
        $amount_raw = (string)$mybb->get_input('amount');
        $amount = af_charactersheets_to_number($amount_raw);
        $reason = trim((string)$mybb->get_input('reason'));
        if ($amount === null || $amount == 0.0) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Amount is invalid']);
        }
        if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_negative'])) {
            af_charactersheets_json_response(['success' => false, 'error' => 'Negative EXP not allowed']);
        }
        if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_overdraw'])) {
            $current_exp = (float)($progress['exp'] ?? 0);
            if ($current_exp + $amount < 0) {
                af_charactersheets_json_response(['success' => false, 'error' => 'Not enough EXP to subtract']);
            }
        }
        $event_key = 'manual:' . $sheet_id . ':' . TIME_NOW . ':' . mt_rand(1000, 9999);
        $meta = [
            'reason' => $reason,
            'awarded_by' => (int)($mybb->user['uid'] ?? 0),
            'awarded_by_username' => (string)($mybb->user['username'] ?? ''),
        ];
        if (!af_charactersheets_grant_exp($sheet_id, (float)$amount, $event_key, 'manual', $meta)) {
            af_charactersheets_json_response(['success' => false, 'error' => 'EXP update failed']);
        }
    } else {
        af_charactersheets_json_response(['success' => false, 'error' => 'Unknown action']);
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    $view = af_charactersheets_compute_sheet_view($sheet);
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

    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $attributes_html = af_charactersheets_build_attributes_html($view, $can_edit, $can_view_ledger);
    $progress_html = af_charactersheets_build_progress_html($view, $sheet, $can_award, $can_view_ledger);
    $skills_html = af_charactersheets_build_skills_html($view, $can_edit, $can_view_ledger);
    $knowledge_html = af_charactersheets_build_knowledge_html($view, $can_edit, $can_view_ledger);

    af_charactersheets_json_response([
        'success' => true,
        'view' => $view,
        'attributes_html' => $attributes_html,
        'progress_html' => $progress_html,
        'skills_html' => $skills_html,
        'knowledge_html' => $knowledge_html,
    ]);
}

function af_charactersheets_json_response(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
