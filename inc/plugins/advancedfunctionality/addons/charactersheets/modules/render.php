<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_build_sheet_inner_html(string $slug): string
{
    global $db, $templates, $headerinclude, $mybb;

    if (function_exists('af_front_ensure_header_bits')) {
        af_front_ensure_header_bits();
    }

    $accept_row = af_charactersheets_get_accept_row_by_slug($slug);
    if (empty($accept_row)) {
        return '';
    }

    $tid = (int)($accept_row['tid'] ?? 0);
    $uid = (int)($accept_row['uid'] ?? 0);

    // ВАЖНО: берем uid из threads тоже, но accept_row приоритетнее как “источник истины”
    $thread = [];
    if ($tid > 0) {
        $thread = $db->fetch_array($db->simple_select(
            'threads',
            'tid,fid,uid,username,subject',
            'tid=' . $tid,
            ['limit' => 1]
        ));
        if (!is_array($thread)) {
            $thread = [];
        }
    }

    // Если в threads uid есть — ок, если нет/0 — используем accept_row uid
    if ($uid > 0 && (int)($thread['uid'] ?? 0) !== $uid) {
        $thread['uid'] = $uid;
    } elseif ((int)($thread['uid'] ?? 0) > 0 && $uid <= 0) {
        $uid = (int)$thread['uid'];
    } else {
        $thread['uid'] = (int)($thread['uid'] ?? 0);
    }

    $user = [];
    if ($uid > 0) {
        $user = $db->fetch_array($db->simple_select('users', 'uid,username', 'uid=' . $uid, ['limit' => 1]));
        if (!is_array($user)) {
            $user = [];
        }
    }

    $thread_url = function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid);

    $atf_fields = af_charactersheets_get_atf_fields($tid);
    $atf_index = af_charactersheets_index_fields($atf_fields);

    $character_name_en = af_charactersheets_pick_field_value($atf_index, ['character_name_en', 'character_name', 'char_name', 'name']);
    if ($character_name_en === '') {
        $character_name_en = (string)($thread['subject'] ?? '');
    }
    if ($character_name_en === '') {
        $character_name_en = $user['username'] ?? 'Лист персонажа';
    }

    $character_name_ru = af_charactersheets_pick_field_value($atf_index, ['character_name_ru']);
    $character_nicknames = af_charactersheets_pick_field_value($atf_index, ['character_nicknames', 'character_nickname', 'nickname']);

    $sheet = af_charactersheets_get_sheet_by_slug($slug);
    if (empty($sheet)) {
        $sheet = af_charactersheets_autocreate_sheet($tid, $thread);
    }
    if (empty($sheet)) {
        return '';
    }

    $sheet_view = af_charactersheets_compute_sheet_view($sheet);
    $build = af_charactersheets_normalize_build(
        af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''))
    );
    $can_edit_sheet = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_manage_sheet = af_cs_can_manage_sheet((int)($mybb->user['uid'] ?? 0), (int)($sheet['uid'] ?? 0));
    // fid нужен для is_moderator(), возьмём из threads если можем
    $fid_for_mod = (int)($thread['fid'] ?? 0);
    $can_staff_reset = af_charactersheets_user_can_staff_reset($mybb->user ?? [], $fid_for_mod);
    $is_staff = af_cs_is_staff($mybb->user ?? [], $fid_for_mod);
    $attributes_locked = !empty($build['attributes_locked']);
    $can_edit_attributes = ($can_edit_sheet && !$attributes_locked) || $is_staff;
    $can_award_exp = af_charactersheets_user_can_award_exp($mybb->user ?? [], $fid_for_mod);
    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $sheet_title = htmlspecialchars_uni($character_name_en);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $can_delete_sheet = af_charactersheets_user_can_delete_sheet($sheet, $mybb->user ?? []);
    $delete_redirect = $thread_url !== '' ? $thread_url : af_charactersheets_url(['action' => 'list']);
    $sheet_header_actions_html = af_charactersheets_build_header_actions_html(
        $can_delete_sheet,
        $delete_redirect
    );
    $sheet_info_table_html = af_charactersheets_build_info_table_html($atf_index, $sheet_view);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($sheet_view, $can_edit_attributes, $can_view_ledger, $can_staff_reset, $attributes_locked);
    $sheet_bonus_html = af_charactersheets_build_bonus_html($atf_index);
    $skills_locked = !empty($build['locked_skills']);
    $can_manage_skills = $can_manage_sheet && (!$skills_locked || $is_staff);
    $sheet_skills_html = af_charactersheets_build_skills_html($sheet_view, $can_manage_skills, $can_view_ledger, $can_staff_reset, $skills_locked);
    $sheet_knowledge_html = af_charactersheets_build_knowledge_html($sheet_view, $can_edit_sheet, $can_view_ledger, $is_staff);
    $sheet_abilities_html = af_charactersheets_build_abilities_html((int)($sheet['uid'] ?? 0));
    $sheet_augments_html = af_charactersheets_build_augments_html($build, $can_edit_sheet, $sheet_view, $uid);
    $sheet_equipment_html = af_charactersheets_build_equipment_html($build, $can_edit_sheet, $uid);
    $sheet_mechanics_html = af_charactersheets_build_mechanics_html($sheet_view);
    $sheet_mechanics_title = htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mechanics_title', 'Механика'));

    $sheet_progress_html = af_charactersheets_build_progress_html($sheet_view, $sheet, $can_award_exp, $can_view_ledger);

    $sheet_portrait_url = af_charactersheets_get_portrait_url($atf_index);
    $sheet_level_value = (int)($sheet_view['level'] ?? 1);
    $sheet_level_percent = (int)($sheet_view['level_percent'] ?? 0);
    $sheet_level_exp_label = htmlspecialchars_uni((string)($sheet_view['level_exp_label'] ?? ''));
    $sheet_name_ru = htmlspecialchars_uni($character_name_ru !== '' ? $character_name_ru : '—');
    $sheet_nicknames = htmlspecialchars_uni($character_nicknames !== '' ? $character_nicknames : '—');
    $sheet_id = (int)($sheet['id'] ?? 0);
    $sheet_post_key = htmlspecialchars_uni($mybb->post_code);
    $sheet_owner_uid = (int)$uid;
    $bonus_items_json = htmlspecialchars_uni(af_charactersheets_json_encode((array)($sheet_view['bonus_items'] ?? [])));

    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n";
    af_charactersheets_ensure_assets_in_headerinclude();
    if (function_exists('af_assets_inject_headerinclude')) {
        af_assets_inject_headerinclude([]);
    }

    $tplInner = $templates->get('charactersheet_inner');
    eval("\$sheet_inner = \"" . $tplInner . "\";");

    return af_charactersheets_canonicalize_assets_html($sheet_inner);
}

function af_charactersheets_render_sheet_page(string $slug): void
{
    global $templates, $mybb, $headerinclude, $htmloption, $theme;

    if (function_exists('af_front_ensure_header_bits')) {
        af_front_ensure_header_bits();
    }

    $sheet_inner = af_charactersheets_build_sheet_inner_html($slug);
    if ($sheet_inner === '') {
        error_no_permission();
        exit;
    }

    $isEmbed = (string)$mybb->get_input('embed') === '1';
    $isAjax = (string)$mybb->get_input('ajax') === '1';
    $surface = strtolower(trim((string)$mybb->get_input('af_apui_surface')));
    $isSheetSurfaceRequest = ($surface === 'sheet');

    // ajax/fragments only for partial in-page rendering (profile tabs, inline loads).
    // iframe modal route from postbit must be content-only (no forum chrome),
    // but still keep {$headerinclude} so theme/plugin assets are available.
    if ($isAjax && !$isEmbed && !$isSheetSurfaceRequest) {
        $page = $sheet_inner;
    } elseif ($isEmbed) {
        $tplModal = $templates->get('charactersheet_modal');
        eval("\$page = \"" . $tplModal . "\";");
    } else {
        $tplFull = $templates->get('charactersheet_fullpage');
        eval("\$page = \"" . $tplFull . "\";");
    }

    $page = af_charactersheets_canonicalize_assets_html($page);
    output_page($page);
    exit;
}

/* -------------------- HELPERS -------------------- */

function af_charactersheets_build_attributes_html(array $view, bool $can_edit, bool $can_view_pool, bool $can_staff_reset = false, bool $attributes_locked = false): string
{
    $rows = [];
    $labels = (array)($view['labels'] ?? []);
    foreach ($labels as $key => $label) {
        $base = (float)($view['base'][$key] ?? 0);
        $allocated = (float)($view['allocated'][$key] ?? 0);
        $bonus = (float)($view['bonus'][$key] ?? 0);
        $final = (float)($view['final'][$key] ?? 0);

        $input = $can_edit
            ? '<input type="number" min="0" step="1" value="' . htmlspecialchars_uni((string)$allocated)
                . '" data-afcs-attr-input="' . htmlspecialchars_uni($key) . '" class="af-cs-attr-input" />'
            : '<span class="af-cs-attr-readonly">' . htmlspecialchars_uni((string)$allocated) . '</span>';

        $rows[] = '<div class="af-cs-attr-card">'
            . '<div class="af-cs-attr-card__head">'
            . '<div class="af-cs-attr-label">' . htmlspecialchars_uni($label) . '</div>'
            . '<div class="af-cs-attr-final">' . htmlspecialchars_uni((string)$final) . '</div>'
            . '</div>'
            . '<div class="af-cs-attr-card__meta">'
            . '<div class="af-cs-attr-card__stat"><span>Авто (ур.)</span><strong>' . htmlspecialchars_uni((string)$base) . '</strong></div>'
            . '<div class="af-cs-attr-card__stat"><span>Бонус</span><strong>' . htmlspecialchars_uni((string)$bonus) . '</strong></div>'
            . '<div class="af-cs-attr-card__stat"><span>Распределено</span>' . $input . '</div>'
            . '</div>'
            . '</div>';
    }

    $attributes_rows_html = implode('', $rows);
    $attributes_pool_max = (int)($view['pool_max'] ?? 0);
    $attributes_pool_remaining = (int)($view['remaining'] ?? 0);
    $attributes_pool_spent = (int)($view['spent'] ?? 0);
    $attributes_pool_html = '';
    if ($can_view_pool) {
        $attributes_pool_html = '<div class="af-cs-attr-pool">'
            . '<div>Пул: <strong>' . htmlspecialchars_uni((string)$attributes_pool_max) . '</strong></div>'
            . '<div>Распределено: <strong data-afcs-pool-spent>' . htmlspecialchars_uni((string)$attributes_pool_spent) . '</strong></div>'
            . '<div>Осталось: <strong data-afcs-pool-remaining>' . htmlspecialchars_uni((string)$attributes_pool_remaining) . '</strong></div>'
            . '</div>';
    }

    $error_items = [];
    foreach ((array)($view['errors'] ?? []) as $error) {
        $error_items[] = '<div class="af-cs-warning">' . htmlspecialchars_uni((string)$error) . '</div>';
    }
    $attributes_errors_html = implode('', $error_items);

    $choice_items = [];
    foreach ((array)($view['choice_details'] ?? []) as $choice) {
        $choice_key = (string)($choice['choice_key'] ?? '');
        $label = (string)($choice['label'] ?? '');
        $pick = max(1, (int)($choice['pick'] ?? 1));
        if ($choice_key === '') {
            continue;
        }
        $chosen_values = $view['choices'][$choice_key] ?? ($choice['chosen'] ?? []);
        if (is_string($chosen_values)) {
            $chosen_values = array_filter(array_map('trim', explode(',', $chosen_values)));
        }
        if (!is_array($chosen_values)) {
            $chosen_values = [$chosen_values];
        }
        $chosen_values = array_values(array_unique(array_filter($chosen_values, 'is_string')));

        $selects = [];
        for ($i = 0; $i < $pick; $i++) {
            $select_options = '<option value="">—</option>';
            foreach ($labels as $key => $attrLabel) {
                $selected = (($chosen_values[$i] ?? '') === $key) ? ' selected' : '';
                $select_options .= '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>'
                    . htmlspecialchars_uni($attrLabel) . '</option>';
            }
            $selects[] = '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '" data-afcs-choice-index="' . $i . '">' . $select_options . '</select>';
        }
        $choice_items[] = '<div class="af-cs-choice-row">'
            . '<label>' . htmlspecialchars_uni($label) . ($pick > 1 ? ' (выбрать ' . $pick . ')' : '') . '</label>'
            . '<div class="af-cs-choice-row__selects">' . implode('', $selects) . '</div>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить</button>'
            . '</div>';
    }
    $attributes_choice_html = ($can_edit && $choice_items)
        ? '<div class="af-cs-choices">' . implode('', $choice_items) . '</div>'
        : '';
    $attributes_actions_html = $can_edit
        ? '<button type="button" class="af-cs-btn" data-afcs-save-attributes="1">Сохранить распределение</button>'
        : '<div class="af-cs-muted">' . ($attributes_locked ? 'Распределение зафиксировано.' : 'Редактирование недоступно.') . '</div>';

    if ($can_staff_reset) {
        $attributes_actions_html .= '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-reset-attributes="1">Сброс</button>';
    }

    $attributes_can_edit = $can_edit ? 1 : 0;
    $attributes_gear_html = $can_edit
        ? '<button type="button" class="af-cs-attrs__gear" data-afcs-attrs-toggle aria-label="Редактировать распределение" title="Редактировать распределение"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>'
        : '';

    global $templates;
    $tpl = $templates->get('charactersheet_attributes');
    eval("\$out = \"" . $tpl . "\";");
    return $out;

}

function af_charactersheets_build_progress_html(array $view, array $sheet, bool $can_award, bool $can_view_ledger): string
{
    $sheet_id = (int)($sheet['id'] ?? 0);
    $level = (int)($view['level'] ?? 1);
    $exp = (float)($view['exp'] ?? 0);
    $next = (float)($view['next_req'] ?? 0);
    $percent = (int)($view['level_percent'] ?? 0);
    $exp_label = htmlspecialchars_uni((string)($view['level_exp_label'] ?? ''));

    $skill_points_free = (int)($view['skill_pool_remaining'] ?? 0);

    $points_html = '';
    if ($can_view_ledger) {
        $points_html = '<div>Свободные очки навыков: <strong>' . htmlspecialchars_uni((string)$skill_points_free) . '</strong></div>';
        $bonus_sources = (array)($view['bonus_sources'] ?? []);
        if ($bonus_sources) {
            $points_html .= '<div class="af-cs-muted">Источник бонусов: '
                . htmlspecialchars_uni(implode(', ', $bonus_sources))
                . '</div>';
        }
    }
    $ledger_toggle_html = '';
    $ledger_block_html = '';
    $manual_award_html = '';
    $manual_award_toggle_html = '';

    global $templates;
    $tpl = $templates->get('charactersheet_progress');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_stats_html(array $index): string
{
    return '';
}

function af_charactersheets_render_stat_bar(string $label, string $value, int $percent): string
{
    $percent = max(0, min(100, $percent));
    return '<div class="af-cs-stat">'
        . '<div class="af-cs-stat__label">' . htmlspecialchars_uni($label) . '</div>'
        . '<div class="af-cs-stat__value">' . htmlspecialchars_uni($value) . '</div>'
        . '<div class="af-cs-progress" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">'
        . '<span style="width:' . $percent . '%"></span>'
        . '</div>'
        . '</div>';
}

function af_charactersheets_render_stat_value(string $label, string $value): string
{
    return '<div class="af-cs-stat">'
        . '<div class="af-cs-stat__label">' . htmlspecialchars_uni($label) . '</div>'
        . '<div class="af-cs-stat__value">' . htmlspecialchars_uni($value) . '</div>'
        . '</div>';
}

function af_cs_kb_skill_meta(string $skillKey): array
{
    static $cache = [];

    $skillKey = trim($skillKey);
    if ($skillKey === '') {
        return [
            'attribute' => '',
            'rank_max' => 5,
            'title_ru' => '',
            'title_en' => '',
        ];
    }

    if (isset($cache[$skillKey])) {
        return $cache[$skillKey];
    }

    $meta = [
        'attribute' => '',
        'rank_max' => 5,
        'title_ru' => '',
        'title_en' => '',
    ];

    $entry = af_charactersheets_kb_get_entry('skill', $skillKey);
    if (empty($entry)) {
        $entry = af_charactersheets_kb_get_entry('skills', $skillKey);
    }
    if (empty($entry)) {
        return $cache[$skillKey] = $meta;
    }

    $meta['title_ru'] = trim((string)($entry['title_ru'] ?? ''));
    $meta['title_en'] = trim((string)($entry['title_en'] ?? ''));

    $rules = cs_kb_get_data_rules($entry);
    $skill = is_array($rules['skill'] ?? null) ? $rules['skill'] : [];

    $attribute = strtolower(trim((string)($skill['attribute'] ?? '')));
    if ($attribute === '') {
        $attribute = strtolower(trim((string)($skill['key_stat'] ?? '')));
    }
    $allowed = af_charactersheets_default_attributes();
    if ($attribute !== '' && array_key_exists($attribute, $allowed)) {
        $meta['attribute'] = $attribute;
    }

    $rankMax = (int)($skill['rank_max'] ?? $rules['rank_max'] ?? 0);
    if ($rankMax > 0) {
        $meta['rank_max'] = $rankMax;
    }

    return $cache[$skillKey] = $meta;
}

function af_charactersheets_build_skills_html(array $view, bool $can_manage, bool $can_view_pool, bool $can_staff_reset = false, bool $skills_locked = false): string
{
    $skills = (array)($view['skills'] ?? []);
    $active_grouped = [];
    $catalog_grouped = [];
    $fixed_sources = [
        'race' => 'раса',
        'race_variant' => 'вариант расы',
        'class' => 'класс',
        'theme' => 'тема',
        'race_choice' => 'выбор расы',
        'race_variant_choice' => 'выбор варианта расы',
        'class_choice' => 'выбор класса',
        'theme_choice' => 'выбор темы',
    ];
    $rank_names = [];
    $rank_config = af_charactersheets_skill_rank_config();
    foreach ($rank_config as $rank => $row) {
        $rank = (int)$rank;
        $title = af_charactersheets_is_ru()
            ? trim((string)($row['title_ru'] ?? ''))
            : trim((string)($row['title_en'] ?? ''));
        if ($title === '') {
            $title = trim((string)($row['title_ru'] ?? $row['title_en'] ?? ''));
        }
        $rank_names[$rank] = $title !== '' ? $title : ('Ранг ' . $rank);
    }

    foreach ($skills as $skill) {
        $category_raw = trim((string)($skill['category'] ?? ''));
        $category = $category_raw !== '' ? $category_raw : 'skills';
        if (!empty($skill['is_active'])) {
            if (!isset($active_grouped[$category])) {
                $active_grouped[$category] = [];
            }
            $active_grouped[$category][] = $skill;
        }

        $skill_rank = max(0, (int)($skill['skill_rank'] ?? 0));
        $rank_max = max(1, (int)($skill['rank_max'] ?? 1));
        if ($skill_rank < $rank_max) {
            if (!isset($catalog_grouped[$category])) {
                $catalog_grouped[$category] = [];
            }
            $catalog_grouped[$category][] = $skill;
        }
    }

    $available_skill_points = max(0, (int)($view['skill_pool_remaining'] ?? 0));

    $build_rank_chip = static function (int $rank, int $rank_max, array $rank_names): string {
        $rank_name = (string)($rank_names[$rank] ?? ('Ранг ' . $rank));
        $rank_class = 'af-cs-rank-chip--rank-' . max(0, $rank);
        return '<span class="af-cs-rank-chip ' . htmlspecialchars_uni($rank_class) . '">' . htmlspecialchars_uni($rank_name) . ' (' . $rank . '/' . $rank_max . ')</span>';
    };

    $build_attr_label = static function (string $attr_label): string {
        $attr_label = trim($attr_label);
        if ($attr_label === '') {
            return '';
        }
        return '<span>(' . htmlspecialchars_uni($attr_label) . ')</span>';
    };

    $skill_attribute_labels = [
        'str' => 'Сила',
        'dex' => 'Ловкость',
        'con' => 'Телосложение',
        'int' => 'Интеллект',
        'wis' => 'Мудрость',
        'cha' => 'Харизма',
    ];
    $sheet_attributes = (array)($view['final'] ?? []);
    $is_debug_user = !empty($GLOBALS['mybb']->usergroup['cancp'])
        || !empty($GLOBALS['mybb']->usergroup['issupermod'])
        || !empty($GLOBALS['mybb']->usergroup['canmodcp']);

    $items = [];
    $catalog_items = [];

    foreach ($active_grouped as $category => $rows) {
        if (strtolower($category) !== 'general') {
            $items[] = '<div class="af-cs-skill-category">' . htmlspecialchars_uni($category) . '</div>';
        }

        foreach ($rows as $skill) {
            $skill_key = (string)($skill['skill_key'] ?? '');
            $title = (string)($skill['title'] ?? $skill_key);
            $attribute = strtolower(trim((string)($skill['attribute'] ?? $skill['attr_key'] ?? $skill['key_stat'] ?? '')));
            $attr_label = trim((string)($skill['attribute_label'] ?? $skill['attr_label'] ?? ($skill_attribute_labels[$attribute] ?? '')));
            $skill_rank = max(0, (int)($skill['skill_rank'] ?? 0));
            $rank_max = max(1, (int)($skill['rank_max'] ?? 5));
            $source = (string)($skill['source'] ?? 'manual');
            $notes = (string)($skill['notes'] ?? '');
            $rank_bonus = af_charactersheets_skill_rank_bonus_for_rank($skill_rank);
            $sheet_attr_value = (float)($sheet_attributes[$attribute] ?? 0);
            $total_value = (float)($skill['total'] ?? ($rank_bonus + $sheet_attr_value));
            $total_label = af_charactersheets_format_signed($total_value);
            $source_chip = '';
            if (isset($fixed_sources[$source])) {
                $source_chip = '<span class="af-cs-rank-chip is-source">Получено: ' . htmlspecialchars_uni($fixed_sources[$source]) . '</span>';
            }

            $controls = '';
            if ($can_manage && $skill_key !== '') {
                $can_increase = $skill_rank < $rank_max;
                if ($can_increase) {
                    $next_rank = $skill_rank + 1;
                    $next_rank_cost = af_charactersheets_skill_rank_cost_for_target($next_rank);
                    $buy_disabled = $available_skill_points >= $next_rank_cost ? '' : ' disabled';
                    $action_label = $skill_rank > 0 ? 'Улучшить' : 'Купить';
                    $action_btn = '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-skill-buy="1" data-skill-key="' . htmlspecialchars_uni($skill_key) . '"' . $buy_disabled . '>' . $action_label . '</button>';
                } else {
                    $action_btn = '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" disabled>Макс</button>';
                }

                $reset_btn = '';
                if ($can_staff_reset && $skill_rank > 0) {
                    $reset_btn = '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-skill-unbuy="1" data-skill-key="' . htmlspecialchars_uni($skill_key) . '">Сброс</button>';
                }
                $controls = '<div class="af-cs-skill-controls-inline">' . $action_btn . $reset_btn . '</div>';
            }

            $debug_attribute = $is_debug_user ? '<!-- afcs skill ' . htmlspecialchars_uni($skill_key) . ' attribute=' . htmlspecialchars_uni($attribute !== '' ? $attribute : '—') . ' -->' : '';
            $items[] = '<div class="af-cs-skill-item" data-attribute="' . htmlspecialchars_uni($attribute) . '">'
                . '<div class="af-cs-skill-left">'
                . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title) . $build_attr_label($attr_label) . '</div>'
                . ($notes !== '' ? '<div class="af-cs-muted">' . htmlspecialchars_uni($notes) . '</div>' : '')
                . $debug_attribute
                . '<div class="af-cs-skill-meta">' . $build_rank_chip($skill_rank, $rank_max, $rank_names) . $source_chip . '</div>'
                . '</div>'
                . '<div class="af-cs-skill-right">'
                . '<div class="af-cs-skill-total">' . htmlspecialchars_uni($total_label) . '</div>'
                . $controls
                . '</div>'
                . '</div>';
        }
    }

    foreach ($catalog_grouped as $category => $rows) {
        if (strtolower($category) !== 'general') {
            $catalog_items[] = '<div class="af-cs-skill-category">' . htmlspecialchars_uni($category) . '</div>';
        }

        foreach ($rows as $skill) {
            $skill_key = (string)($skill['skill_key'] ?? '');
            if ($skill_key === '') {
                continue;
            }
            $title = (string)($skill['title'] ?? $skill_key);
            $attribute = strtolower(trim((string)($skill['attribute'] ?? $skill['attr_key'] ?? $skill['key_stat'] ?? '')));
            $attr_label = trim((string)($skill['attribute_label'] ?? $skill['attr_label'] ?? ($skill_attribute_labels[$attribute] ?? '')));
            $skill_rank = max(0, (int)($skill['skill_rank'] ?? 0));
            $rank_max = max(1, (int)($skill['rank_max'] ?? 5));
            $source = (string)($skill['source'] ?? 'manual');
            $notes = (string)($skill['notes'] ?? '');

            $source_chip = '';
            if (isset($fixed_sources[$source])) {
                $source_chip = '<span class="af-cs-rank-chip is-source">Получено: ' . htmlspecialchars_uni($fixed_sources[$source]) . '</span>';
            }

            $next_rank = $skill_rank + 1;
            $next_rank_cost = af_charactersheets_skill_rank_cost_for_target($next_rank);
            $buy_disabled = $available_skill_points >= $next_rank_cost ? '' : ' disabled';
            $action_label = $skill_rank > 0 ? 'Улучшить' : 'Купить';
            $catalog_action_btn = '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-skill-buy="1" data-skill-key="' . htmlspecialchars_uni($skill_key) . '"' . $buy_disabled . '>' . $action_label . '</button>';

            $catalog_debug_attribute = $is_debug_user ? '<!-- afcs skill ' . htmlspecialchars_uni($skill_key) . ' attribute=' . htmlspecialchars_uni($attribute !== '' ? $attribute : '—') . ' -->' : '';
            $catalog_items[] = '<div class="af-cs-skill-catalog-item" data-attribute="' . htmlspecialchars_uni($attribute) . '">'
                . '<div class="af-cs-skill-catalog-item__main">'
                . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title) . $build_attr_label($attr_label) . '</div>'
                . ($notes !== '' ? '<div class="af-cs-muted">' . htmlspecialchars_uni($notes) . '</div>' : '')
                . $catalog_debug_attribute
                . '<div class="af-cs-skill-meta">'
                . $build_rank_chip($skill_rank, $rank_max, $rank_names)
                . $source_chip
                . '</div>'
                . '</div>'
                . '<div class="af-cs-skill-catalog-item__action">' . $catalog_action_btn . '</div>'
                . '</div>';
        }
    }

    if (!$items) {
        $items[] = '<div class="af-cs-muted">Навыков пока нет.</div>';
    }

    if ($is_debug_user && !empty($view['debug_skills_attributes']) && is_array($view['debug_skills_attributes'])) {
        foreach ((array)$view['debug_skills_attributes'] as $dbgKey => $dbgStat) {
            $items[] = '<!-- afcs skill: ' . htmlspecialchars_uni((string)$dbgKey) . ' attribute=' . htmlspecialchars_uni((string)$dbgStat) . ' -->';
        }
    }

    $skills_html = implode('', $items);
    $catalog_html = '';
    if ($can_manage) {
        $catalog_html = '<div class="af-cs-skill-catalog" data-afcs-skill-catalog-panel hidden>'
            . '<div class="af-cs-skill-catalog__head">'
            . '<strong>Каталог навыков</strong>'
            . '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-skill-catalog-close="1">Закрыть</button>'
            . '</div>'
            . '<div class="af-cs-skill-catalog__list">'
            . ($catalog_items ? implode('', $catalog_items) : '<div class="af-cs-muted">Навыки для покупки не найдены.</div>')
            . '</div>'
            . '</div>';
    }

    $skill_pool_html = '';
    if ($can_view_pool) {
        $buy_disabled = $available_skill_points > 0 ? '' : ' disabled';
        $skill_pool_html = '<div class="af-cs-skill-pool">'
            . '<div>Пул навыков: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_total'] ?? 0)) . '</strong></div>'
            . '<div>Потрачено: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_spent'] ?? 0)) . '</strong></div>'
            . '<div>Доступно: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_remaining'] ?? 0)) . '</strong></div>'
            . ($can_manage ? '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-skill-catalog-open="1"' . $buy_disabled . '>Купить навык</button>' : '')
            . ($can_staff_reset ? '<button type="button" class="af-cs-skill-btn af-cs-skill-btn--wide" data-afcs-reset-skills="1">Сброс</button>' : '')
            . '</div>';
    }

    $choice_html = '';
    $skill_pick_choice_items = [];
    $skill_choice_source_labels = [
        'race' => 'раса',
        'race_variant' => 'вариант расы',
        'class' => 'класс',
        'theme' => 'тема',
    ];
    foreach ((array)($view['skill_pick_choice_details'] ?? []) as $choice) {
        $choice_key = (string)($choice['choice_key'] ?? '');
        if ($choice_key === '') {
            continue;
        }
        $pick = max(1, (int)($choice['pick'] ?? 1));
        $source = (string)($choice['source'] ?? '');
        $source_label = (string)($skill_choice_source_labels[$source] ?? $source);
        $id_label = (string)($choice['id'] ?? '');
        $label = 'Выбор навыка' . ($id_label !== '' ? (' (' . $id_label . ')') : '');
        if ($source_label !== '') {
            $label .= ' · ' . $source_label;
        }

        $selected = array_values(array_unique(array_filter((array)($choice['selected'] ?? []))));
        $options = (array)($choice['options'] ?? []);
        $selects = [];
        for ($i = 0; $i < $pick; $i++) {
            $current = (string)($selected[$i] ?? '');
            $option_html = '<option value="">— выбрать навык —</option>';
            foreach ($options as $option_key => $option_title) {
                $is_used = in_array((string)$option_key, $selected, true) && $current !== (string)$option_key;
                $selected_attr = $current === (string)$option_key ? ' selected' : '';
                $disabled_attr = $is_used ? ' disabled' : '';
                $option_html .= '<option value="' . htmlspecialchars_uni((string)$option_key) . '"' . $selected_attr . $disabled_attr . '>' . htmlspecialchars_uni((string)$option_title) . '</option>';
            }
            $selects[] = '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '" data-afcs-choice-index="' . $i . '">' . $option_html . '</select>';
        }

        $status = 'Не выбрано';
        if ($selected) {
            $labels = [];
            foreach ($selected as $selected_key) {
                $labels[] = (string)($options[$selected_key] ?? $selected_key);
            }
            $status = 'Выбрано: ' . implode(', ', $labels);
        }

        $grant_mode = (string)($choice['grant_mode'] ?? 'rank');
        $grant_hint = $grant_mode === 'points'
            ? 'Бонус: +' . max(0, (int)($choice['points_value'] ?? 0)) . ' очк. навыков за выбор'
            : 'Бонус: ранг ' . max(1, (int)($choice['rank_value'] ?? 1)) . ' за выбор';

        $apply_btn = $can_manage
            ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить выбор</button>'
            : '';

        $skill_pick_choice_items[] = '<div class="af-cs-choice-row">'
            . '<div><strong>' . htmlspecialchars_uni($label) . '</strong><div class="af-cs-muted">Выбрать: ' . $pick . '. ' . htmlspecialchars_uni($grant_hint) . '</div><div class="af-cs-muted">' . htmlspecialchars_uni($status) . '</div></div>'
            . '<div class="af-cs-choice-row__selects">' . implode('', $selects) . '</div>'
            . $apply_btn
            . '</div>';
    }
    if ($skill_pick_choice_items) {
        $choice_html = '<div class="af-cs-skill-pool"><div><strong>Бесплатные выборы</strong></div><div class="af-cs-choices">' . implode('', $skill_pick_choice_items) . '</div></div>';
    }

    global $templates;
    $tpl = $templates->get('charactersheet_skills');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_knowledge_html(array $view, bool $can_edit, bool $can_view_pool, bool $can_manage_selected = false): string
{
    global $mybb;

    $knowledge_entries = af_charactersheets_get_kb_entries_by_type('knowledge');
    $language_entries = af_charactersheets_get_kb_entries_by_type('language');

    $knowledge_selected = array_values(array_unique(array_filter((array)($view['knowledge']['selected'] ?? []))));
    $knowledge_bonus = array_values(array_unique(array_filter((array)($view['knowledge']['bonus'] ?? []))));
    $knowledge_remaining = max(0, (int)($view['knowledge']['remaining'] ?? 0));
    $knowledge_total = max(0, (int)($view['knowledge']['total_choices'] ?? 0));
    $knowledge_selected_count = count($knowledge_selected);
    $knowledge_bonus_count = count($knowledge_bonus);

    $language_selected = array_values(array_unique(array_filter((array)($view['languages']['selected'] ?? []))));
    $language_bonus = array_values(array_unique(array_filter((array)($view['languages']['bonus'] ?? []))));
    $language_remaining = max(0, (int)($view['languages']['remaining'] ?? 0));
    $language_total = max(0, (int)($view['languages']['total_choices'] ?? 0));
    $language_selected_count = count($language_selected);
    $language_bonus_count = count($language_bonus);

    $source_labels = [
        'race' => 'Раса',
        'race_variant' => 'Вариант расы',
        'class' => 'Класс',
        'theme' => 'Тема',
        'other' => 'Прочее',
    ];

    $sum_grant_slots = static function (array $grants, string $resource): int {
        $sum = 0;
        foreach ($grants as $grant) {
            if (!is_array($grant)) {
                continue;
            }
            $type = strtolower(trim((string)($grant['type'] ?? $grant['op'] ?? '')));
            if ($type !== 'resource' && $type !== 'resource_slot' && $type !== 'slot') {
                continue;
            }
            $name = strtolower(trim((string)($grant['resource'] ?? $grant['name'] ?? $grant['target'] ?? '')));
            if ($name !== strtolower($resource)) {
                continue;
            }
            $sum += (int)($grant['amount'] ?? $grant['value'] ?? 0);
        }
        return $sum;
    };

    $source_rules = [
        'race' => cs_kb_rules_normalize((array)($view['ctx']['sources']['race']['rules'] ?? [])),
        'race_variant' => cs_kb_rules_normalize((array)($view['ctx']['sources']['race_variant']['rules'] ?? [])),
        'class' => cs_kb_rules_normalize((array)($view['ctx']['sources']['class']['rules'] ?? [])),
        'theme' => cs_kb_rules_normalize((array)($view['ctx']['sources']['theme']['rules'] ?? [])),
    ];

    $knowledge_source_breakdown = [];
    $language_source_breakdown = [];
    foreach ($source_rules as $source_key => $rules) {
        $knowledge_source_breakdown[$source_key] = (int)($rules['fixed']['knowledge_slots'] ?? 0)
            + (int)($rules['fixed_bonuses']['knowledge_slots'] ?? 0)
            + $sum_grant_slots((array)($rules['grants'] ?? []), 'knowledge_slots');
        $language_source_breakdown[$source_key] = (int)($rules['fixed']['language_slots'] ?? 0)
            + (int)($rules['fixed_bonuses']['language_slots'] ?? 0)
            + $sum_grant_slots((array)($rules['grants'] ?? []), 'language_slots');
    }

    $int_value = (float)($view['final']['int'] ?? 0);
    $knowledge_base_choices = (int)($mybb->settings['af_charactersheets_knowledge_base_choices'] ?? 0);
    $knowledge_per_int = (float)($mybb->settings['af_charactersheets_knowledge_per_int'] ?? 0);
    $knowledge_from_int = (int)floor($int_value * $knowledge_per_int);

    $knowledge_sources = [
        'Базовые слоты' => $knowledge_base_choices,
        'INT модификатор' => $knowledge_from_int,
        $source_labels['race'] => (int)$knowledge_source_breakdown['race'],
        $source_labels['race_variant'] => (int)$knowledge_source_breakdown['race_variant'],
        $source_labels['class'] => (int)$knowledge_source_breakdown['class'],
        $source_labels['theme'] => (int)$knowledge_source_breakdown['theme'],
    ];
    $knowledge_known_sum = array_sum($knowledge_sources);
    if ($knowledge_total > $knowledge_known_sum) {
        $knowledge_sources[$source_labels['other']] = $knowledge_total - $knowledge_known_sum;
    }

    $language_sources = [
        $source_labels['race'] => (int)$language_source_breakdown['race'],
        $source_labels['race_variant'] => (int)$language_source_breakdown['race_variant'],
        $source_labels['class'] => (int)$language_source_breakdown['class'],
        $source_labels['theme'] => (int)$language_source_breakdown['theme'],
    ];
    $language_known_sum = array_sum($language_sources);
    if ($language_total > $language_known_sum) {
        $language_sources[$source_labels['other']] = $language_total - $language_known_sum;
    }

    $render_source_chips = static function (array $sources): string {
        $chips = [];
        foreach ($sources as $label => $value) {
            if ((int)$value === 0) {
                continue;
            }
            $chips[] = '<span class="af-cs-knowledge-source-chip"><em>' . htmlspecialchars_uni((string)$label) . '</em><strong>+' . htmlspecialchars_uni((string)(int)$value) . '</strong></span>';
        }
        if (!$chips) {
            return '<span class="af-cs-muted">Нет бонусных источников.</span>';
        }
        return implode('', $chips);
    };

    $knowledge_options = '<option value="">— выбрать знание —</option>';
    foreach ($knowledge_entries as $entry) {
        $key = (string)($entry['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        $knowledge_options .= '<option value="' . htmlspecialchars_uni($key) . '">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</option>';
    }

    $language_options = '<option value="">— выбрать язык —</option>';
    foreach ($language_entries as $entry) {
        $key = (string)($entry['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        $language_options .= '<option value="' . htmlspecialchars_uni($key) . '">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</option>';
    }

    $render_item = static function (string $kind, string $key, bool $is_bonus, bool $can_edit, bool $can_manage_selected): string {
        $entry = af_charactersheets_kb_get_entry($kind, $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }

        $status = $is_bonus
            ? '<em class="af-cs-knowledge-tag af-cs-knowledge-tag--bonus">Авто</em>'
            : '<em class="af-cs-knowledge-tag af-cs-knowledge-tag--manual">' . ($can_manage_selected ? 'Выбор' : 'Выбор зафиксирован') . '</em>';

        $remove = '';
        if (!$is_bonus && $can_edit && $can_manage_selected) {
            $remove = '<button type="button" class="af-cs-knowledge-remove" data-afcs-knowledge-remove="1" data-afcs-knowledge-type="' . htmlspecialchars_uni($kind) . '" data-afcs-knowledge-key="' . htmlspecialchars_uni($key) . '" title="Убрать">×</button>';
        }

        return '<article class="af-cs-knowledge-card' . ($is_bonus ? ' is-bonus' : '') . '">'
            . '<div class="af-cs-knowledge-card__head">'
            . '<strong class="af-cs-knowledge-title">' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</strong>'
            . $status
            . $remove
            . '</div>'
            . ($desc !== '' ? '<div class="af-cs-knowledge-desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
            . '</article>';
    };

    $knowledge_selected_items = [];
    foreach ($knowledge_selected as $key) {
        $knowledge_selected_items[] = $render_item('knowledge', (string)$key, false, $can_edit, $can_manage_selected);
    }
    if (!$knowledge_selected_items) {
        $knowledge_selected_items[] = '<div class="af-cs-knowledge-empty"><strong>Пока пусто</strong><span>Выбранные вручную знания появятся здесь.</span></div>';
    }

    $knowledge_bonus_items = [];
    foreach ($knowledge_bonus as $key) {
        $knowledge_bonus_items[] = $render_item('knowledge', (string)$key, true, false, false);
    }
    if (!$knowledge_bonus_items) {
        $knowledge_bonus_items[] = '<div class="af-cs-knowledge-empty"><strong>Нет автодобавлений</strong><span>Бонусные знания от расы, класса или темы пока отсутствуют.</span></div>';
    }

    $language_selected_items = [];
    foreach ($language_selected as $key) {
        $language_selected_items[] = $render_item('language', (string)$key, false, $can_edit, $can_manage_selected);
    }
    if (!$language_selected_items) {
        $language_selected_items[] = '<div class="af-cs-knowledge-empty"><strong>Пока пусто</strong><span>Выбранные вручную языки появятся здесь.</span></div>';
    }

    $language_bonus_items = [];
    foreach ($language_bonus as $key) {
        $language_bonus_items[] = $render_item('language', (string)$key, true, false, false);
    }
    if (!$language_bonus_items) {
        $language_bonus_items[] = '<div class="af-cs-knowledge-empty"><strong>Нет автодобавлений</strong><span>Бонусные языки от источников пока не выданы.</span></div>';
    }

    $knowledge_selected_items_html = implode('', $knowledge_selected_items);
    $knowledge_bonus_items_html = implode('', $knowledge_bonus_items);
    $language_selected_items_html = implode('', $language_selected_items);
    $language_bonus_items_html = implode('', $language_bonus_items);

    $knowledge_form = '';
    $language_form = '';
    if ($can_edit) {
        $knowledge_form = '<div class="af-cs-knowledge-form">'
            . '<select class="af-cs-knowledge-select" data-afcs-knowledge-select="knowledge" aria-label="Выбор знания">' . $knowledge_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost af-cs-knowledge-add-btn" data-afcs-knowledge-add="1" data-afcs-knowledge-type="knowledge">Добавить</button>'
            . '</div>';
        if (!$can_manage_selected && $knowledge_selected_count > 0) {
            $knowledge_form .= '<div class="af-cs-muted">Выбранные знания зафиксированы и недоступны для удаления.</div>';
        }
        $language_form = '<div class="af-cs-knowledge-form">'
            . '<select class="af-cs-knowledge-select" data-afcs-knowledge-select="language" aria-label="Выбор языка">' . $language_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost af-cs-knowledge-add-btn" data-afcs-knowledge-add="1" data-afcs-knowledge-type="language">Добавить</button>'
            . '</div>';
        if (!$can_manage_selected && $language_selected_count > 0) {
            $language_form .= '<div class="af-cs-muted">Выбранные языки зафиксированы и недоступны для удаления.</div>';
        }
    }

    $knowledge_overview_html = '';
    if ($can_view_pool) {
        $knowledge_overview_html = '<div class="af-cs-knowledge-overview-grid">'
            . '<section class="af-cs-knowledge-overview-card">'
            . '<h4>Слоты знаний</h4>'
            . '<div class="af-cs-knowledge-counters">'
            . '<div><span>Всего</span><strong>' . htmlspecialchars_uni((string)$knowledge_total) . '</strong></div>'
            . '<div><span>Занято вручную</span><strong>' . htmlspecialchars_uni((string)$knowledge_selected_count) . '</strong></div>'
            . '<div><span>Свободно</span><strong>' . htmlspecialchars_uni((string)$knowledge_remaining) . '</strong></div>'
            . '<div><span>Авто/бонус</span><strong>' . htmlspecialchars_uni((string)$knowledge_bonus_count) . '</strong></div>'
            . '</div>'
            . '<div class="af-cs-knowledge-sources">' . $render_source_chips($knowledge_sources) . '</div>'
            . '</section>'
            . '<section class="af-cs-knowledge-overview-card">'
            . '<h4>Слоты языков</h4>'
            . '<div class="af-cs-knowledge-counters">'
            . '<div><span>Всего</span><strong>' . htmlspecialchars_uni((string)$language_total) . '</strong></div>'
            . '<div><span>Занято вручную</span><strong>' . htmlspecialchars_uni((string)$language_selected_count) . '</strong></div>'
            . '<div><span>Свободно</span><strong>' . htmlspecialchars_uni((string)$language_remaining) . '</strong></div>'
            . '<div><span>Авто/бонус</span><strong>' . htmlspecialchars_uni((string)$language_bonus_count) . '</strong></div>'
            . '</div>'
            . '<div class="af-cs-knowledge-sources">' . $render_source_chips($language_sources) . '</div>'
            . '</section>'
            . '</div>';
    }

    $knowledge_pool_html = '';
    $language_pool_html = '';

    global $templates;
    $tpl = $templates->get('charactersheet_knowledge');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_render_kb_icon(array $entry, string $fallbackLabel = ''): string
{
    $icon_url = (string)($entry['icon_url'] ?? '');
    $icon_class = (string)($entry['icon_class'] ?? '');
    if (function_exists('af_kb_build_icon_html')) {
        $html = af_kb_build_icon_html($icon_url, $icon_class);
        if ($html !== '') {
            return $html;
        }
    } else {
        $url = trim($icon_url);
        if ($url !== '') {
            return '<img class="af-cs-kb-icon-img" src="' . htmlspecialchars_uni($url) . '" alt="" loading="lazy" />';
        }
        if ($icon_class !== '') {
            return '<i class="' . htmlspecialchars_uni($icon_class) . '"></i>';
        }
    }

    $label = trim($fallbackLabel);
    if ($label !== '') {
        $letter = function_exists('mb_substr') ? mb_substr($label, 0, 1) : substr($label, 0, 1);
    } else {
        $letter = '?';
    }
    return '<span class="af-cs-kb-icon-placeholder">' . htmlspecialchars_uni($letter) . '</span>';
}

function af_charactersheets_render_slot_icon(array $slot_config): string
{
    $icon = trim((string)($slot_config['icon'] ?? ''));
    if ($icon === '') {
        return '<span class="af-cs-slot-icon af-cs-slot-icon--empty"></span>';
    }
    if (preg_match('#^https?://#i', $icon) || strpos($icon, '/') !== false) {
        return '<span class="af-cs-slot-icon"><img src="' . htmlspecialchars_uni($icon) . '" alt="" loading="lazy" /></span>';
    }
    return '<span class="af-cs-slot-icon"><i class="' . htmlspecialchars_uni($icon) . '"></i></span>';
}

function af_charactersheets_build_abilities_html(int $ownerUid): string
{
    $abilities_html = '<div class="af-cs-abilities-empty">Способности пока не куплены.</div>';

    if ($ownerUid > 0 && function_exists('af_advinv_export_charactersheet_abilities_state')) {
        $state = af_advinv_export_charactersheet_abilities_state($ownerUid);
        $items = [];
        if (isset($state['items']) && is_array($state['items'])) {
            $items = (array)$state['items'];
        } elseif (is_array($state) && array_values($state) === $state) {
            $items = $state;
        }
        $selected_item_id = (int)($state['selected_item_id'] ?? 0);
        if ($selected_item_id <= 0) {
            $selected_item_id = !empty($items) ? (int)($items[0]['id'] ?? 0) : 0;
        }

        if (!empty($items) && function_exists('af_advinv_render_abilities_list') && function_exists('af_advinv_render_abilities_preview_stack')) {
            $list_html = af_advinv_render_abilities_list($items, $selected_item_id);
            $preview_html = af_advinv_render_abilities_preview_stack($items, $selected_item_id);

            $abilities_html = '<div class="af-cs-abilities-workspace" data-afcs-abilities-root="1">'
                . '<div class="af-cs-abilities-list-pane">' . $list_html . '</div>'
                . '<div class="af-cs-abilities-preview-pane">' . $preview_html . '</div>'
                . '</div>';
        }
    }

    global $templates;
    $tpl = $templates->get('charactersheet_abilities');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_feats_html(array $index): string
{
    $feats_html = '<div class="af-cs-feat-item">'
        . '<div class="af-cs-feat-title">Способностей пока нет</div>'
        . '<div class="af-cs-feat-meta">Заглушка магазина</div>'
        . '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_feats');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_render_kb_chip(string $type, string $key, string $fallbackLabel = ''): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $entry = af_charactersheets_kb_get_entry($type, $key);
    if (empty($entry) && $type === 'race_variant') {
        $entry = af_charactersheets_kb_get_entry('racevariant', $key);
    }
    $label = af_charactersheets_kb_pick_text($entry, 'title');
    if ($label === '') {
        $label = $fallbackLabel;
    }
    if ($label === '') {
        $label = $key;
    }

    $url = 'misc.php?action=kb&type=' . rawurlencode($type) . '&key=' . rawurlencode($key);

    return '<a class="af-cs-chip af-cs-chip--link" href="' . htmlspecialchars_uni($url)
        . '" target="_blank" rel="noopener">' . htmlspecialchars_uni($label) . '</a>';
}

function af_charactersheets_build_header_actions_html(
    bool $can_delete,
    string $delete_redirect
): string
{
    $public_actions_html = af_charactersheets_build_public_header_actions_html();
    $moderator_actions_html = af_charactersheets_build_moderator_header_actions_html($can_delete, $delete_redirect);

    $items = [];
    if ($public_actions_html !== '') {
        $items[] = $public_actions_html;
    }
    if ($moderator_actions_html !== '') {
        $items[] = $moderator_actions_html;
    }

    if (empty($items)) {
        return '';
    }

    return implode('', $items);
}

function af_charactersheets_build_public_header_actions_html(): string
{
    return '';
}

function af_charactersheets_build_moderator_header_actions_html(bool $can_delete, string $delete_redirect): string
{
    if (!$can_delete) {
        return '';
    }

    return '<button type="button" class="af-cs-btn af-cs-btn--compact af-cs-btn--danger" data-afcs-delete-sheet'
        . ' data-afcs-delete-redirect="' . htmlspecialchars_uni($delete_redirect) . '" title="Удалить"'
        . ' aria-label="Удалить"><i class="fa-solid fa-xmark"></i></button>';
}

function af_charactersheets_build_kb_cards_html(array $fields): string
{
    $mapped = af_charactersheets_kb_mapping();
    $cards = [];
    $index = af_charactersheets_index_fields($fields);

    foreach ($mapped as $fieldName => $data) {
        $kbType = (string)$data['type'];
        $label = (string)$data['label'];
        $field = $index[$fieldName] ?? [];

        $key = (string)($field['value'] ?? '');
        $entry = $key !== '' ? af_charactersheets_kb_get_entry($kbType, $key) : [];
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        if ($title === '') {
            $title = (string)($field['value_label'] ?? '');
        }
        if ($title === '') {
            $title = $label;
        }

        $blockKey = $kbType === 'themes' ? 'knowledges' : 'characteristics';
        $body_html = af_charactersheets_kb_get_block_html($entry, $blockKey);

        $cards[] = '<div class="af-cs-kb-card">'
            . '<div class="af-cs-kb-title">' . htmlspecialchars_uni($title) . '</div>'
            . ($body_html !== '' ? '<div class="af-cs-kb-body">' . $body_html . '</div>' : '')
            . '</div>';
    }

    $rct_cards_html = implode('', $cards);

    global $templates;
    $tpl = $templates->get('charactersheet_rct_cards');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_resolve_effect_kb_entry(string $op, string $key): array
{
    $op = trim($op);
    $key = trim($key);
    if ($op === '' || $key === '') {
        return [];
    }

    $types = [$op, 'effect', 'condition'];
    foreach ($types as $type) {
        $entry = af_charactersheets_kb_get_entry($type, $key);
        if (!empty($entry)) {
            return $entry;
        }
    }

    return [];
}

function af_charactersheets_grant_pick_text(array $grant, string $field): string
{
    $isRu = af_charactersheets_is_ru();
    $localizedField = $field . ($isRu ? '_ru' : '_en');
    $raw = trim((string)($grant[$localizedField] ?? ''));
    if ($raw !== '') {
        return $raw;
    }

    return '';
}

function af_charactersheets_format_grant_value(array $grant): string
{
    $value = trim((string)($grant['value'] ?? ''));
    if ($value === '') {
        return '';
    }

    $unit = trim((string)($grant['unit'] ?? ''));

    return $unit !== '' ? ($value . ' ' . $unit) : $value;
}

function af_charactersheets_build_effects_chip_html(array $sheet_view): string
{
    $grants = (array)($sheet_view['ctx']['aggregate']['grants'] ?? []);
    if (!$grants) {
        return '<span class="af-cs-muted">—</span>';
    }

    $chips = [];
    foreach ($grants as $grant) {
        if (!is_array($grant)) {
            continue;
        }

        $op = trim((string)($grant['op'] ?? ''));
        if (!in_array($op, ['sense', 'resistance'], true)) {
            continue;
        }

        $key = trim((string)($grant['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $entry = af_charactersheets_resolve_effect_kb_entry($op, $key);
        $title = af_charactersheets_grant_pick_text($grant, 'title');
        if ($title === '') {
            $title = af_charactersheets_kb_pick_text($entry, 'title');
        }
        if ($title === '') {
            $title = $key;
        }

        $label = $title;
        $formattedValue = af_charactersheets_format_grant_value($grant);
        if ($formattedValue !== '') {
            $label .= ': ' . $formattedValue;
        }

        $hint = af_charactersheets_grant_pick_text($grant, 'desc');
        if ($hint === '') {
            $hint = af_charactersheets_kb_pick_text($entry, 'short');
        }
        if ($hint === '') {
            $hint = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $chipAttrs = '';
        if ($hint !== '') {
            $tooltip = trim(strip_tags($hint));
            if ($tooltip !== '') {
                $chipAttrs = ' title="' . htmlspecialchars_uni($tooltip) . '"';
            }
        }

        $chips[] = '<span class="af-cs-chip"' . $chipAttrs . '>' . htmlspecialchars_uni($label) . '</span>';
    }

    if (!$chips) {
        return '<span class="af-cs-muted">—</span>';
    }

    return implode('', $chips);
}

function af_charactersheets_build_info_table_html(array $index, array $sheet_view = []): string
{
    global $mybb;

    $age = af_charactersheets_pick_field_value($index, ['character_age', 'age']);
    $gender = af_charactersheets_pick_field_value($index, ['character_gen', 'character_gender', 'gender']);
    $race_key = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
    $race_variant_key = af_charactersheets_pick_field_value($index, ['character_race_variant', 'race_variant', 'racevariant'], false);
    $class_key = af_charactersheets_pick_field_value($index, ['character_class', 'class'], false);
    $theme_key = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
    $race_label = af_charactersheets_pick_field_value($index, ['character_race', 'race'], true);
    $race_variant_label = af_charactersheets_pick_field_value($index, ['character_race_variant', 'race_variant', 'racevariant'], true);
    $class_label = af_charactersheets_pick_field_value($index, ['character_class', 'class'], true);
    $theme_label = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], true);

    $items = [];
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Возраст</div><div class="af-cs-info-value">' . htmlspecialchars_uni($age !== '' ? $age : '—') . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Пол</div><div class="af-cs-info-value">' . htmlspecialchars_uni($gender !== '' ? $gender : '—') . '</div></div>';

    $chip_html = '';
    $chip_html .= af_charactersheets_render_kb_chip('race', $race_key, $race_label);
    $chip_html .= af_charactersheets_render_kb_chip('race_variant', $race_variant_key, $race_variant_label);
    $chip_html .= af_charactersheets_render_kb_chip('class', $class_key, $class_label);
    $chip_html .= af_charactersheets_render_kb_chip('themes', $theme_key, $theme_label);
    if ($chip_html === '') {
        $chip_html = '<span class="af-cs-muted">—</span>';
    }
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Основа</div><div class="af-cs-info-value">' . $chip_html . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Эффекты</div><div class="af-cs-info-value">' . af_charactersheets_build_effects_chip_html($sheet_view) . '</div></div>';
    $wallet_raw = (int)($sheet_view['credits'] ?? 0);
    $wallet = function_exists('af_balance_format_credits') ? af_balance_format_credits($wallet_raw) : number_format($wallet_raw / 100, 2, '.', ' ');
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Кошелёк</div><div class="af-cs-info-value" data-afcs-wallet-value>' . htmlspecialchars_uni($wallet) . ' ¢</div></div>';

    if (!empty($mybb->settings['af_balance_ability_tokens_show_sheet'])) {
        $ability_raw = (int)($sheet_view['ability_tokens'] ?? 0);
        $ability_wallet = function_exists('af_balance_format_ability_tokens') ? af_balance_format_ability_tokens($ability_raw) : number_format($ability_raw / 100, 2, '.', ' ');
        $ability_symbol = htmlspecialchars_uni((string)($mybb->settings['af_balance_ability_tokens_symbol'] ?? '♦'));
        $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Ability Tokens</div><div class="af-cs-info-value">' . htmlspecialchars_uni($ability_wallet) . ' ' . $ability_symbol . '</div></div>';
    }

    return '<div class="af-cs-info-table">' . implode('', $items) . '</div>';
}

function af_charactersheets_build_bonus_html(array $index): string
{
    global $mybb;

    $mapping = af_charactersheets_kb_mapping();
    $columns = [];

    $race_field = $index['character_race'] ?? [];
    $race_key = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
    $race_entry = $race_key !== '' ? af_charactersheets_kb_get_entry('race', $race_key) : [];
    $race_title = af_charactersheets_kb_pick_text($race_entry, 'title');
    if ($race_title === '') {
        $race_title = af_charactersheets_pick_field_value($index, ['character_race', 'race'], true);
    }
    if ($race_title === '') {
        $race_title = (string)($race_field['value_label'] ?? 'Раса');
    }

    $race_variant_field = $index['character_race_variant'] ?? [];
    $race_variant_key = af_charactersheets_pick_field_value($index, ['character_race_variant', 'race_variant', 'racevariant'], false);
    $race_variant_entry = $race_variant_key !== '' ? af_charactersheets_kb_get_entry('race_variant', $race_variant_key) : [];
    if (empty($race_variant_entry) && $race_variant_key !== '') {
        $race_variant_entry = af_charactersheets_kb_get_entry('racevariant', $race_variant_key);
    }
    $race_variant_title = af_charactersheets_kb_pick_text($race_variant_entry, 'title');
    if ($race_variant_title === '') {
        $race_variant_title = af_charactersheets_pick_field_value($index, ['character_race_variant', 'race_variant', 'racevariant'], true);
    }
    if ($race_variant_title === '') {
        $race_variant_title = (string)($race_variant_field['value_label'] ?? '');
    }

    $race_text_html = af_cs_render_kb_bonuses_text('race', $race_key, af_charactersheets_is_ru());
    if ($race_text_html === '') {
        $race_text_html = '<div class="af-cs-muted">Нет данных</div>';
    }

    $race_variant_text_html = '';
    if ($race_variant_key !== '') {
        $race_variant_text_html = af_cs_render_kb_bonuses_text('race_variant', $race_variant_key, af_charactersheets_is_ru());
        if ($race_variant_text_html === '') {
            $race_variant_text_html = af_cs_render_kb_bonuses_text('racevariant', $race_variant_key, af_charactersheets_is_ru());
        }
    }

    $race_card_title = 'Раса: ' . ($race_title !== '' ? $race_title : '—');
    if ($race_variant_title !== '') {
        $race_card_title .= ' (подраса: ' . $race_variant_title . ')';
    }

    $race_package_sections = '<div class="af-cs-bonus-race-package__section"><div class="af-cs-bonus-race-package__subtitle">Бонусы расы</div><div class="af-cs-bonus-body">' . $race_text_html . '</div></div>';
    if ($race_variant_title !== '' || $race_variant_text_html !== '') {
        if ($race_variant_text_html === '') {
            $race_variant_text_html = '<div class="af-cs-muted">Нет данных</div>';
        }
        $race_package_sections .= '<div class="af-cs-bonus-race-package__section"><div class="af-cs-bonus-race-package__subtitle">Бонусы подрасы</div><div class="af-cs-bonus-body">' . $race_variant_text_html . '</div></div>';
    }
    $columns[] = '<div class="af-cs-bonus-card af-cs-bonus-card--race"><div class="af-cs-bonus-title">' . htmlspecialchars_uni($race_card_title) . '</div>' . $race_package_sections . '</div>';

    foreach ($mapping as $fieldName => $data) {
        if ($fieldName === 'character_race' || $fieldName === 'character_race_variant') {
            continue;
        }
        $field = $index[$fieldName] ?? [];
        $key = (string)($field['value'] ?? '');

        if ((string)($data['type'] ?? '') === 'themes' && $key === '') {
            $key = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
        }

        $entry = $key !== '' ? af_charactersheets_kb_get_entry((string)$data['type'], $key) : [];
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        if ($title === '') {
            $title = (string)$data['label'];
        }

        $text_html = af_cs_render_kb_bonuses_text((string)$data['type'], $key, af_charactersheets_is_ru());

        if ((string)($data['type'] ?? '') === 'themes' && function_exists('af_cs_is_staff') && af_cs_is_staff((array)($mybb->user ?? []))) {
            $theme_entry_id = (int)($entry['id'] ?? 0);
            $theme_block_found = 0;
            $theme_bonus_len = 0;

            if ($theme_entry_id > 0) {
                global $db;
                if (is_object($db) && $db->table_exists('af_kb_blocks')) {
                    $where = "entry_id={$theme_entry_id} AND block_key='bonuses'";
                    if (!function_exists('af_kb_can_edit') || !af_kb_can_edit()) {
                        $where .= ' AND active=1';
                    }
                    $block = $db->fetch_array($db->simple_select('af_kb_blocks', 'content_ru,content_en', $where, ['limit' => 1]));
                    if (is_array($block) && !empty($block)) {
                        $theme_block_found = 1;
                        $theme_bonus_len = strlen(trim((string)($block[af_charactersheets_is_ru() ? 'content_ru' : 'content_en'] ?? '')));
                    }
                }
            }

            af_charactersheets_log('bonus theme diagnostics', [
                'theme_key' => $key,
                'theme_entry_found' => $theme_entry_id > 0 ? 1 : 0,
                'theme_entry_id' => $theme_entry_id,
                'theme_bonuses_block_found' => $theme_block_found,
                'theme_bonuses_content_len' => $theme_bonus_len,
            ]);
        }

        if ($text_html === '') {
            $text_html = '<div class="af-cs-muted">Нет данных</div>';
        }

        $cardClass = 'af-cs-bonus-card';
        if ((string)($data['type'] ?? '') === 'class') {
            $cardClass .= ' af-cs-bonus-card--class';
        } elseif ((string)($data['type'] ?? '') === 'themes') {
            $cardClass .= ' af-cs-bonus-card--theme';
        }

        $columns[] = '<div class="' . $cardClass . '">'
            . '<div class="af-cs-bonus-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-cs-bonus-body">' . $text_html . '</div>'
            . '</div>';
    }

    if (!$columns) {
        return '';
    }

    return '<div class="af-cs-bonus-grid">' . implode('', $columns) . '</div>';
}

function af_charactersheets_lang(?string $key = null, string $fallback = ''): string
{
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;
        if (function_exists('af_charactersheets_load_lang')) {
            af_charactersheets_load_lang();
        }
    }

    // Если вызвали без ключа — просто возвращаем fallback (или пустую строку)
    if ($key === null || $key === '') {
        return $fallback;
    }

    global $lang;

    $value = '';
    if (is_object($lang) && isset($lang->$key)) {
        $value = (string)$lang->$key;
    }

    return $value !== '' ? $value : $fallback;
}


function af_charactersheets_build_mechanics_html(array $view): string
{
    global $mybb;

    $debug_enabled = false;
    if (function_exists('af_cs_is_staff')) {
        $debug_enabled = af_cs_is_staff((array)($mybb->user ?? []));
    }
    $debug_enabled = $debug_enabled && (int)($mybb->get_input('af_cs_debug') ?? 0) === 1;

    $mechanics = (array)($view['mechanics'] ?? []);
    $ac_total = (int)($mechanics['ac_total'] ?? 0);
    $hp_total = (int)($mechanics['hp_total'] ?? 0);
    $speed_total = (int)($mechanics['speed_total'] ?? 0);
    $saves = (array)($mechanics['saves'] ?? []);
    $reflex = (int)($saves['reflex'] ?? 0);
    $will = (int)($saves['will'] ?? 0);
    $fortitude = (int)($saves['fortitude'] ?? 0);
    $perception = (int)($saves['perception'] ?? 0);

    $debug = (array)($view['debug'] ?? []);
    $debug_lines = [];
    foreach (['race', 'race_variant', 'class', 'theme'] as $src) {
        $entry = (array)($debug[$src] ?? []);
        $key = (string)($entry['key'] ?? '-');
        $schema = (string)($entry['schema'] ?? '');
        if ($schema !== 'af_kb.rules.v1') {
            $debug_lines[] = $src . '_key=' . $key . ' schema=' . ($schema !== '' ? $schema : '-') . ' ' . $src . ' schema invalid / empty rules';
            continue;
        }

        $rules = (array)($entry['rules'] ?? []);
        $line = $src . '_key=' . $key
            . ' schema=' . $schema
            . ' hp_base=' . (int)($rules['hp_base'] ?? 0)
            . ' speed=' . (int)($rules['speed'] ?? 0)
            . ' fixed_hp=' . (int)($rules['fixed_bonuses']['hp'] ?? 0);

        foreach ((array)($rules['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $choice_type = (string)($choice['type'] ?? '');
            if ($choice_type === 'stat_bonus' && (string)($choice['mode'] ?? 'add') === 'add') {
                $pick = max(0, (int)($choice['pick'] ?? 0));
                $value = max(0, (int)($choice['value'] ?? 1));
                $line .= ' | stat_bonus[id=' . (string)($choice['id'] ?? '-') . ' pick=' . $pick . ' value=' . $value . '] => bonus_attr_points=' . ($pick * $value);
            }
            if ($choice_type === 'skill_pick_choice' && (string)($choice['grant_mode'] ?? '') === 'skill_points') {
                $pick = max(0, (int)($choice['pick'] ?? 0));
                $points_value = max(0, (int)($choice['points_value'] ?? 0));
                $line .= ' | skill_pick_choice[id=' . (string)($choice['id'] ?? '-') . ' pick=' . $pick . ' points_value=' . $points_value . ' grant_mode=skill_points] => bonus_skill_points=' . ($pick * $points_value);
            }
        }

        $debug_lines[] = $line;
    }

    $hp_base_breakdown = (array)($debug['hp_base_breakdown'] ?? []);
    $fixed_hp_breakdown = (array)($debug['fixed_hp_breakdown'] ?? []);
    $debug_lines[] = 'HP BREAKDOWN: race.hp_base=' . (int)($hp_base_breakdown['race'] ?? 0)
        . ' class.hp_base=' . (int)($hp_base_breakdown['class'] ?? 0)
        . ' theme.hp_base=' . (int)($hp_base_breakdown['theme'] ?? 0)
        . ' race.fixed_bonuses.hp=' . (int)($fixed_hp_breakdown['race'] ?? 0)
        . ' class.fixed_bonuses.hp=' . (int)($fixed_hp_breakdown['class'] ?? 0)
        . ' theme.fixed_bonuses.hp=' . (int)($fixed_hp_breakdown['theme'] ?? 0)
        . ' hp_from_items=' . (int)($debug['hp_from_items'] ?? 0)
        . ' hp_from_con=' . (int)($debug['hp_from_con'] ?? 0)
        . ' hp_total=' . (int)($debug['hp_total'] ?? 0);

    $debug_lines[] = 'TOTAL: hp_base_total=' . (int)($debug['hp_base_total'] ?? 0)
        . ' hp_from_sources=' . (int)($debug['hp_from_sources'] ?? 0)
        . ' hp_from_items=' . (int)($debug['hp_from_items'] ?? 0)
        . ' hp_from_con=' . (int)($debug['hp_from_con'] ?? 0)
        . ' fixed_hp_total=' . (int)($debug['fixed_hp_total'] ?? 0)
        . ' speed_total=' . (int)($debug['speed_total'] ?? 0)
        . ' bonus_attribute_points=' . (int)($debug['bonus_attribute_points'] ?? 0)
        . ' bonus_skill_points=' . (int)($debug['bonus_skill_points'] ?? 0);

    foreach ((array)($debug['rules_trace'] ?? []) as $trace) {
        if (!is_array($trace)) {
            continue;
        }
        $debug_lines[] = 'TRACE source=' . (string)($trace['source'] ?? '-')
            . ' kind=' . (string)($trace['kind'] ?? '-')
            . ' payload=' . json_encode((array)($trace['payload'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $debug_comment = "
<!-- AF_CS_DEBUG
"
        . implode("
", $debug_lines)
        . "
-->";

    $damage_total = (string)($mechanics['damage_total'] ?? '1d4');

    $cards = [];
    $cards[] = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-staticon"><i class="fa-solid fa-shield-halved af-cs-staticon__icon" aria-hidden="true"></i><div class="af-cs-staticon__content"><div class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_ac', 'Класс брони')) . '</div><span class="af-cs-staticon__value">' . htmlspecialchars_uni((string)$ac_total) . '</span></div></div>'
        . '</div>';
    $cards[] = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-staticon"><i class="fa-solid fa-heart af-cs-staticon__icon" aria-hidden="true"></i><div class="af-cs-staticon__content"><div class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_hp', 'Здоровье')) . '</div><span class="af-cs-staticon__value">' . htmlspecialchars_uni((string)$hp_total) . '</span></div></div>'
        . '</div>';
    $cards[] = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-staticon"><i class="fa-solid fa-gun af-cs-staticon__icon" aria-hidden="true"></i><div class="af-cs-staticon__content"><div class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_damage', 'Урон')) . '</div><span class="af-cs-staticon__value">' . htmlspecialchars_uni($damage_total) . '</span></div></div>'
        . '</div>';
    $cards[] = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-staticon"><i class="fa-solid fa-person-running af-cs-staticon__icon" aria-hidden="true"></i><div class="af-cs-staticon__content"><div class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_speed', 'Скорость')) . '</div><span class="af-cs-staticon__value">' . htmlspecialchars_uni((string)$speed_total) . '</span></div></div>'
        . '</div>';

    $cards[] = '<div class="af-cs-save-card"><i class="fa-solid fa-heart-pulse af-cs-save-card__icon" aria-hidden="true"></i><div class="af-cs-save-card__content"><span class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_fortitude', 'Стойкость / Fortitude')) . '</span><strong>' . htmlspecialchars_uni(sprintf('%+d', $fortitude)) . '</strong></div></div>';
    $cards[] = '<div class="af-cs-save-card"><i class="fa-solid fa-bolt af-cs-save-card__icon" aria-hidden="true"></i><div class="af-cs-save-card__content"><span class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_reflex', 'Рефлекс / Reflex')) . '</span><strong>' . htmlspecialchars_uni(sprintf('%+d', $reflex)) . '</strong></div></div>';
    $cards[] = '<div class="af-cs-save-card"><i class="fa-solid fa-brain af-cs-save-card__icon" aria-hidden="true"></i><div class="af-cs-save-card__content"><span class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_will', 'Воля / Will')) . '</span><strong>' . htmlspecialchars_uni(sprintf('%+d', $will)) . '</strong></div></div>';
    $cards[] = '<div class="af-cs-save-card"><i class="fa-solid fa-eye af-cs-save-card__icon" aria-hidden="true"></i><div class="af-cs-save-card__content"><span class="af-cs-mech-title">' . htmlspecialchars_uni(af_charactersheets_lang('af_charactersheets_mech_perception', 'Восприятие / Perception')) . '</span><strong>' . htmlspecialchars_uni(sprintf('%+d', $perception)) . '</strong></div></div>';

    $debug_line = '<!-- AF_CS_DEBUG mechanics: race=' . htmlspecialchars_uni((string)($debug['race']['key'] ?? ''))
        . ' schema=' . htmlspecialchars_uni((string)($debug['race']['schema'] ?? ''))
        . ' hp_base=' . (int)($debug['hp_base_total'] ?? 0)
        . ' hp_from_sources=' . (int)($debug['hp_from_sources'] ?? 0)
        . ' hp_from_items=' . (int)($debug['hp_from_items'] ?? 0)
        . ' hp_from_con=' . (int)($debug['hp_from_con'] ?? 0)
        . ' AC=' . $ac_total
        . ' speed=' . $speed_total
        . ' -->';

    $debug_panel = '';
    if ($debug_enabled) {
        $debug_json = htmlspecialchars_uni(json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        $debug_panel = '<details class="af-cs-debug-rules"><summary>Debug applied rules</summary><pre>' . $debug_json . '</pre></details>';
    }

    return '<div class="af-cs-mechanics-grid">' . implode('', $cards) . '</div>' . $debug_panel . ($debug_enabled ? ($debug_comment . $debug_line) : '');
}

function af_charactersheets_build_inventory_html(int $uid): string
{
    $inventory_embed_url = '';
    $inventory_full_url = '';
    if ($uid > 0) {
        if (function_exists('af_advancedinventory_url')) {
            $inventory_embed_url = af_advancedinventory_url('inventory', ['uid' => $uid, 'embed' => 1], false);
            $inventory_full_url = af_advancedinventory_url('inventory', ['uid' => $uid], false);
        } else {
            $inventory_embed_url = 'inventory.php?uid=' . $uid . '&embed=1';
            $inventory_full_url = 'inventory.php?uid=' . $uid;
        }
    }

    $inventory_embed_url_attr = htmlspecialchars_uni($inventory_embed_url);
    $inventory_full_url_attr = htmlspecialchars_uni($inventory_full_url);
    global $templates;
    $tpl = $templates->get('charactersheet_inventory');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_pick_inventory_category(array $entry): string
{
    if (empty($entry)) {
        return 'tools';
    }

    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    $category = (string)($meta['inventory_category'] ?? '');
    if ($category !== '') {
        return $category;
    }

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        $category = (string)($data['inventory_category'] ?? '');
        if ($category !== '') {
            return $category;
        }
    }

    return 'tools';
}

function af_charactersheets_pick_augmentation_slots(array $entry): array
{
    if (empty($entry)) {
        return [];
    }

    $slots = [];
    $meta = af_charactersheets_json_decode((string)($entry['meta_json'] ?? ''));
    if (!empty($meta['augmentation_slots']) && is_array($meta['augmentation_slots'])) {
        foreach ($meta['augmentation_slots'] as $slot) {
            if ($slot === '') {
                continue;
            }
            $slots[] = (string)$slot;
        }
    }
    if (!empty($meta['slot'])) {
        $slots[] = (string)$meta['slot'];
    }
    if (!empty($meta['slots']) && is_array($meta['slots'])) {
        foreach ($meta['slots'] as $slot) {
            if ($slot === '') {
                continue;
            }
            $slots[] = (string)$slot;
        }
    }

    $blocks = af_charactersheets_kb_get_blocks($entry);
    foreach ($blocks as $block) {
        $data = af_charactersheets_json_decode((string)($block['data_json'] ?? ''));
        if (!empty($data['augmentation_slots']) && is_array($data['augmentation_slots'])) {
            foreach ($data['augmentation_slots'] as $slot) {
                if ($slot === '') {
                    continue;
                }
                $slots[] = (string)$slot;
            }
        }
        if (!empty($data['slot'])) {
            $slots[] = (string)$data['slot'];
        }
        if (!empty($data['slots']) && is_array($data['slots'])) {
            foreach ($data['slots'] as $slot) {
                if ($slot === '') {
                    continue;
                }
                $slots[] = (string)$slot;
            }
        }
    }

    $slots = array_values(array_unique(array_filter($slots)));
    return $slots;
}

function af_charactersheets_build_augments_html(array $build, bool $can_edit, array $view = [], int $uid = 0): string
{
    $augmentations = (array)($build['augmentations'] ?? []);
    $slots = (array)($augmentations['slots'] ?? []);
    $owned = (array)($augmentations['owned'] ?? []);

    $slot_configs = af_charactersheets_get_augmentation_slots();
    $slot_cards = [];
    foreach ($slot_configs as $slot => $config) {
        $slot_title = (string)($config['title'] ?? $slot);
        $current = $slots[$slot] ?? null;
        $slot_items = af_charactersheets_normalize_slot_items($current);
        $equipped_count = count($slot_items);
        $is_empty = $equipped_count <= 0;

        $first_item = $slot_items[0] ?? [];
        $first_type = (string)($first_item['type'] ?? $first_item['kb_type'] ?? '');
        $first_key = (string)($first_item['key'] ?? $first_item['kb_key'] ?? '');
        $first_entry = ($first_type !== '' && $first_key !== '') ? af_charactersheets_kb_get_entry($first_type, $first_key) : [];
        $first_title = af_charactersheets_kb_pick_text($first_entry, 'title');
        if ($first_title === '') {
            $first_title = $first_key;
        }
        $first_desc = af_charactersheets_kb_pick_text($first_entry, 'short');
        if ($first_desc === '') {
            $first_desc = af_charactersheets_kb_pick_text($first_entry, 'description');
        }
        $first_humanity = $first_entry ? af_charactersheets_extract_humanity_cost_from_entry($first_entry) : 0.0;

        $line_items = [];
        foreach ($slot_items as $slot_item) {
            if (!is_array($slot_item)) {
                continue;
            }
            $item_type = (string)($slot_item['type'] ?? $slot_item['kb_type'] ?? '');
            $item_key = (string)($slot_item['key'] ?? $slot_item['kb_key'] ?? '');
            if ($item_type === '' || $item_key === '') {
                continue;
            }
            $entry = af_charactersheets_kb_get_entry($item_type, $item_key);
            $title = af_charactersheets_kb_pick_text($entry, 'title');
            $item_title = $title !== '' ? $title : $item_key;
            $line_items[] = $item_title;
        }
        $slot_items_label = $line_items ? implode(' • ', $line_items) : '';
        $slot_stats = $first_entry ? trim(preg_replace('/\s+/', ' ', strip_tags(af_charactersheets_kb_get_block_html($first_entry, 'bonuses')))) : '';
        $slot_dot_classes = 'af-cs-slot af-cs-slot--augmentation af-cs-augmentation-slot-card' . ($is_empty ? ' af-cs-slot--empty' : '');

        $dot_attrs = ' data-afcs-augmentation-slot-dot="1"'
            . ' data-afcs-augmentation-slot="' . htmlspecialchars_uni($slot) . '"'
            . ' data-afcs-augmentation-popover-title="' . htmlspecialchars_uni($is_empty ? $slot_title : $first_title) . '"'
            . ' data-afcs-augmentation-popover-slot="' . htmlspecialchars_uni($slot_title) . '"'
            . ' data-afcs-augmentation-popover-desc="' . htmlspecialchars_uni($is_empty ? 'Слот пуст.' : $first_desc) . '"'
            . ' data-afcs-augmentation-popover-stats="' . htmlspecialchars_uni($slot_stats) . '"'
            . ' data-afcs-augmentation-popover-humanity="' . htmlspecialchars_uni($first_humanity > 0 ? ('−' . (string)$first_humanity) : '') . '"'
            . ' data-afcs-augmentation-popover-items="' . htmlspecialchars_uni($slot_items_label) . '"'
            . ' data-afcs-augmentation-popover-key="' . htmlspecialchars_uni($first_key) . '"';

        $slot_cards[] = '<button type="button" class="' . $slot_dot_classes . '"' . $dot_attrs . '>'
            . '<div class="af-cs-slot-header">'
            . '<span class="af-cs-slot-icon">' . (!$is_empty ? af_charactersheets_render_kb_icon($first_entry, $first_title) : af_charactersheets_render_slot_icon($config)) . '</span>'
            . '<span class="af-cs-slot-label">' . htmlspecialchars_uni($slot_title) . '</span>'
            . '</div>'
            . '<div class="af-cs-slot-title">' . htmlspecialchars_uni($is_empty ? 'Слот пуст' : $first_title) . ($equipped_count > 1 ? ' <span class="af-cs-slot-count">+' . ($equipped_count - 1) . '</span>' : '') . '</div>'
            . (!$is_empty && $first_desc !== '' ? '<div class="af-cs-slot-note">' . htmlspecialchars_uni($first_desc) . '</div>' : '')
            . '</button>';
    }

    $available_cards = [];
    if ($can_edit) {
        $inventory_items = $uid > 0 && function_exists('af_advinv_export_charactersheet_augmentations_inventory')
            ? af_advinv_export_charactersheet_augmentations_inventory($uid)
            : (array)((array)($build['inventory'] ?? [])['items'] ?? []);
        $available_map = [];
        foreach ($inventory_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = af_charactersheets_get_inventory_item_type($item);
            $key = af_charactersheets_get_inventory_item_key($item);
            if ($type === '' || $key === '') {
                continue;
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            $category = af_charactersheets_pick_inventory_category($entry);
            if ($category !== 'augmentations') {
                continue;
            }
            $qty = (int)($item['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $stack_key = $type . ':' . $key;
            $available_map[$stack_key] = [
                'type' => $type,
                'key' => $key,
                'qty' => $qty,
            ];
        }

        foreach ($owned as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? $item['kb_type'] ?? '');
            $key = (string)($item['key'] ?? $item['kb_key'] ?? '');
            if ($type === '' || $key === '') {
                continue;
            }
            $stack_key = $type . ':' . $key;
            if (!isset($available_map[$stack_key])) {
                $available_map[$stack_key] = [
                    'type' => $type,
                    'key' => $key,
                    'qty' => 1,
                ];
            }
        }

        foreach ($available_map as $item) {
            $type = (string)($item['type'] ?? '');
            $key = (string)($item['key'] ?? '');
            if ($type === '' || $key === '') {
                continue;
            }
            $entry = af_charactersheets_kb_get_entry($type, $key);
            $title = af_charactersheets_kb_pick_text($entry, 'title');
            $desc = af_charactersheets_kb_pick_text($entry, 'short');
            if ($desc === '') {
                $desc = af_charactersheets_kb_pick_text($entry, 'description');
            }
            $bonus_html = af_charactersheets_kb_get_block_html($entry, 'bonuses');
            $bonus_text = trim(preg_replace('/\s+/', ' ', strip_tags($bonus_html)));
            $allowed_slots = af_charactersheets_pick_augmentation_slots($entry);
            $slot_options = $allowed_slots ?: array_keys($slot_configs);
            $humanity_cost = af_charactersheets_extract_humanity_cost_from_entry($entry);

            $slot_select = '';
            $equip_btn = '';
            $slot_default_attr = '';
            if (count($slot_options) > 1) {
                $options = '<option value="">— слот —</option>';
                foreach ($slot_configs as $slot => $slotConfig) {
                    if ($allowed_slots && !in_array($slot, $allowed_slots, true)) {
                        continue;
                    }
                    $options .= '<option value="' . htmlspecialchars_uni($slot) . '">' . htmlspecialchars_uni((string)($slotConfig['title'] ?? $slot)) . '</option>';
                }
                $slot_select = '<select data-afcs-augmentation-slot-select="1">' . $options . '</select>';
            } elseif (count($slot_options) === 1) {
                $slot_default_attr = ' data-afcs-augmentation-slot-default="' . htmlspecialchars_uni((string)$slot_options[0]) . '"';
            }

            $equip_btn = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-augmentation-equip="1"'
                . ' data-afcs-augmentation-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-augmentation-key="' . htmlspecialchars_uni($key) . '"'
                . $slot_default_attr . '>Надеть</button>';

            $default_slot = count($slot_options) === 1 ? (string)$slot_options[0] : '';
            $available_cards[] = '<div class="af-cs-augment-card af-cs-augment-card--available" data-afcs-augmentation-card="1"'
                . ' data-afcs-augmentation-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-augmentation-key="' . htmlspecialchars_uni($key) . '"'
                . ' data-afcs-augmentation-slot-default="' . htmlspecialchars_uni($default_slot) . '"'
                . ' data-afcs-augmentation-popover-title="' . htmlspecialchars_uni($title !== '' ? $title : $key) . '"'
                . ' data-afcs-augmentation-popover-desc="' . htmlspecialchars_uni($desc) . '"'
                . ' data-afcs-augmentation-popover-slot="' . htmlspecialchars_uni(implode(', ', array_map(function ($slotCode) use ($slot_configs) {
                    return (string)($slot_configs[$slotCode]['title'] ?? $slotCode);
                }, $slot_options))) . '"'
                . ' data-afcs-augmentation-popover-stats="' . htmlspecialchars_uni($bonus_text) . '"'
                . ' data-afcs-augmentation-popover-humanity="' . htmlspecialchars_uni($humanity_cost > 0 ? ('−' . (string)$humanity_cost) : '') . '">'
                . '<div class="af-cs-augment-card__icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
                . '<div class="af-cs-augment-card__body">'
                . '<div class="af-cs-augment-card__title">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</div>'
                . ($desc !== '' ? '<div class="af-cs-augment-card__desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
                . ($humanity_cost > 0 ? '<div class="af-cs-augment-card__humanity">Стоимость человечности: ' . htmlspecialchars_uni((string)$humanity_cost) . '</div>' : '')
                . ($bonus_html !== '' ? '<div class="af-cs-augment-card__effects">' . $bonus_html . '</div>' : '')
                . '<div class="af-cs-augment-card__actions">' . $slot_select . $equip_btn . '</div>'
                . '</div>'
                . '</div>';
        }

        if (!$available_cards) {
            $available_cards[] = '<div class="af-cs-muted">Аугментации не куплены.</div>';
        }
    }

    $humanity_total = (float)($view['mechanics']['humanity_total'] ?? 0);
    $humanity_base = (float)($view['mechanics']['humanity_breakdown']['base'] ?? 100);
    if ($humanity_base <= 0.0) {
        $humanity_base = 100.0;
    }
    $humanity_percent = max(0, min(100, ($humanity_total / $humanity_base) * 100));
    $humanity_html = '<div class="af-cs-augmentation-humanity-block">'
        . '<div class="af-cs-augmentation-humanity">'
        . '<span>Человечность</span>'
        . '<strong>' . htmlspecialchars_uni(rtrim(rtrim(number_format($humanity_total, 2, '.', ''), '0'), '.')) . '%</strong>'
        . '</div>'
        . '<div class="af-cs-progress__bar"><span style="width:' . htmlspecialchars_uni(number_format((float)$humanity_percent, 2, '.', '')) . '%"></span></div>'
        . '</div>';

    $augment_edit_gear = $can_edit
        ? '<button type="button" class="af-cs-attrs__gear af-cs-augmentations__gear" data-afcs-augments-edit-toggle="1" aria-label="Редактировать аугментации" title="Редактировать аугментации"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>'
        : '';

    $augmentations_html = '<div class="af-cs-augmentations-ui af-cs-augmentations-rpg' . ($can_edit ? ' af-cs-augmentations-ui--can-edit' : ' af-cs-augmentations-ui--public') . '" data-afcs-augmentation-root="1" data-afcs-augmentation-can-edit="' . ($can_edit ? '1' : '0') . '">'
        . '<div class="af-cs-augmentations-top-row top-row">'
        . '<div class="af-cs-augmentations-column af-cs-augmentations-slots-block slots-block">'
        . '<div class="af-cs-panel-title af-cs-panel-title--with-actions"><span>Слоты аугментаций</span>' . $augment_edit_gear . '</div>'
        . $humanity_html
        . '<div class="af-cs-slot-grid af-cs-augmentation-slot-grid">' . implode('', $slot_cards) . '</div>'
        . '<div class="af-cs-augment-popover" data-afcs-augmentation-popover hidden></div>'
        . '</div>'
        . '<div class="af-cs-augmentations-column af-cs-augmentations-preview-column preview-block">'
        . '<div class="af-cs-panel-title">Превью</div>'
        . '<div class="af-cs-augment-preview" data-afcs-augmentation-preview>'
        . '<div class="af-cs-augment-preview__title">Выберите слот</div>'
        . '<div class="af-cs-augment-preview__desc">Нажмите на слот или аугментацию, чтобы увидеть данные.</div>'
        . '</div>'
        . '</div>'
        . '</div>';

    if ($can_edit) {
        $augmentations_html .= '<div class="af-cs-augmentations-edit-section" data-afcs-augmentation-edit-section="1" hidden>'
            . '<div class="af-cs-augmentations-available-row available-row">'
            . '<div class="af-cs-augmentations-column af-cs-augmentations-column--full af-cs-augmentations-available-block available-block">'
            . '<div class="af-cs-panel-title">Доступные аугментации</div>'
            . '<div class="af-cs-augmentations-list">' . implode('', $available_cards) . '</div>'
            . '</div>'
            . '</div>'
            . '<div class="af-cs-augmentations-edit-actions actions-row">'
            . '<button type="button" class="af-cs-btn" data-afcs-augmentation-edit-save="1">Сохранить</button>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-augmentation-edit-cancel="1">Отменить</button>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    $augmentations_html .= '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_augmentations');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_equipment_html(array $build, bool $can_edit, int $uid = 0): string
{
    $state = $uid > 0 && function_exists('af_advinv_export_charactersheet_equipment_state')
        ? af_advinv_export_charactersheet_equipment_state($uid)
        : ['items' => [], 'groups' => [], 'equipped' => []];

    $slot_labels = af_inv_equipment_slots();
    $active_weapon_slot = (string)((array)($build['equipment'] ?? [])['active_weapon_slot'] ?? '');
    $weapon_slots = ['weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_melee', 'weapon_ranged'];
    $slot_order = [
        'head', 'body', 'back', 'hands', 'legs', 'feet', 'belt', 'artifact', 'accessory_1', 'accessory_2',
        'weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_melee', 'weapon_ranged',
        'ammo', 'gear',
        'support_1', 'support_2', 'support_3', 'support_4',
    ];

    $all_slots = [];
    foreach (array_keys((array)$slot_labels) as $slot_code) {
        $all_slots[$slot_code] = true;
    }
    foreach (array_keys((array)($state['equipped'] ?? [])) as $slot_code) {
        $all_slots[(string)$slot_code] = true;
    }
    $all_slots = array_values(array_unique(array_merge($slot_order, array_keys($all_slots))));

    $equipped_map = (array)($state['equipped'] ?? []);
    if ($active_weapon_slot === '' || empty((array)($equipped_map[$active_weapon_slot] ?? []))) {
        foreach ($weapon_slots as $weapon_slot_code) {
            if (!empty((array)($equipped_map[$weapon_slot_code] ?? []))) {
                $active_weapon_slot = $weapon_slot_code;
                break;
            }
        }
    }

    $preview_slots_map = [];
    foreach ($all_slots as $slot_code) {
        $slot_code = (string)$slot_code;
        $slot_item = (array)($equipped_map[$slot_code] ?? []);
        $slot_label = (string)($slot_item['slot_label'] ?? ($slot_labels[$slot_code] ?? $slot_code));
        $item_title = (string)($slot_item['title'] ?? 'Пусто');
        $item_id = (int)($slot_item['item_id'] ?? 0);
        $icon = trim((string)($slot_item['icon'] ?? ''));
        $icon_html = $icon !== ''
            ? '<img src="' . htmlspecialchars_uni($icon) . '" alt="' . htmlspecialchars_uni($item_title) . '" loading="lazy">'
            : '<span class="af-cs-slot-icon af-cs-slot-icon--empty"></span>';
        $entry = ($slot_item && !empty($slot_item['kb_key']))
            ? af_charactersheets_kb_get_entry((string)($slot_item['kb_type'] ?? ''), (string)($slot_item['kb_key'] ?? ''))
            : [];
        $bonus_html = $entry ? af_charactersheets_kb_get_block_html($entry, 'bonuses') : '';
        $is_weapon_slot = in_array($slot_code, $weapon_slots, true);
        $is_active_weapon = $is_weapon_slot && $item_id > 0 && $slot_code === $active_weapon_slot;

        $slot_markup = '<button type="button" class="af-cs-slot af-cs-slot--equipment' . ($item_id > 0 ? ' is-filled' : '') . ($is_active_weapon ? ' is-active-weapon' : '') . '"'
            . ' data-afcs-equipment-slot-dot="1"'
            . ' data-afcs-equipment-slot="' . htmlspecialchars_uni($slot_code) . '"'
            . ' data-afcs-equipment-slot-label="' . htmlspecialchars_uni($slot_label) . '"'
            . ' data-afcs-equipment-preview-item-id="' . $item_id . '"'
            . ($item_id > 0 ? ' data-afcs-equipment-preview-trigger="' . $item_id . '"' : '')
            . ' data-afcs-equipment-popover-kind="equipped"'
            . ' data-afcs-equipment-popover-title="' . htmlspecialchars_uni($item_title) . '"'
            . ' data-afcs-equipment-popover-desc="' . htmlspecialchars_uni((string)($slot_item['description'] ?? '')) . '"'
            . ' data-afcs-equipment-popover-slot="' . htmlspecialchars_uni($slot_label) . '"'
            . ' data-afcs-equipment-popover-stats="' . htmlspecialchars_uni(strip_tags($bonus_html)) . '"'
            . ' data-afcs-equipment-popover-icon="' . htmlspecialchars_uni($icon) . '"'
            . ($is_weapon_slot ? ' data-afcs-equipment-popover-can-set-active="1"' : '')
            . ($is_active_weapon ? ' data-afcs-equipment-popover-is-active-weapon="1"' : '')
            . '>'
            . '<span class="af-cs-slot__icon">' . $icon_html . '</span>'
            . '<span class="af-cs-slot__label">' . htmlspecialchars_uni($slot_label) . '</span>'
            . '<span class="af-cs-slot__item">' . htmlspecialchars_uni($item_id > 0 ? $item_title : 'Пусто') . '</span>'
            . ($is_active_weapon ? '<span class="af-cs-slot__state-badge">Активное оружие</span>' : '')
            . '</button>';

        $preview_slots_map[$slot_code] = $slot_markup;
    }

    $available_cards = [];
    if ($can_edit) {
        foreach ((array)($state['items'] ?? []) as $index => $item) {
            $itemId = (int)($item['id'] ?? 0);
            $title = (string)($item['title'] ?? 'Предмет');
            $desc = trim((string)($item['short_description'] ?? $item['description'] ?? ''));
            $entry = af_charactersheets_kb_get_entry((string)($item['kb_type'] ?? ''), (string)($item['kb_key'] ?? ''));
            $bonus_html = $entry ? af_charactersheets_kb_get_block_html($entry, 'bonuses') : '';
            $candidate_slots = (array)($item['candidate_slots'] ?? []);
            $subtype = trim((string)($item['subtype'] ?? ''));
            if ($subtype === '' && function_exists('af_advinv_classify_equipment_from_kb_meta')) {
                $subtype = af_advinv_classify_equipment_from_kb_meta(
                    af_advinv_decode_meta_json((string)($item['meta_json'] ?? ''))
                );
            }
            if ($subtype === 'artifact') {
                $subtype = 'gear';
            }
            if (!in_array($subtype, ['armor', 'weapon', 'ammo', 'consumable', 'gear'], true)) {
                $subtype = 'gear';
            }
            if ($subtype === 'consumable') {
                $candidate_slots = ['support_1', 'support_2', 'support_3', 'support_4'];
            }
            $candidate_slots = array_values(array_unique(array_filter(array_map('strval', $candidate_slots))));
            $default_slot = (string)($candidate_slots[0] ?? '');
            $is_equipped = ((string)($item['equipped_slot'] ?? '') !== '');

            $available_cards[] = '<div class="af-cs-augment-card af-cs-equipment-item-card' . ($index === 0 ? ' is-active' : '') . ($is_equipped ? ' is-equipped' : '') . '"'
                . ' data-afcs-equipment-card="1"'
                . ' data-afcs-equipment-item-id="' . $itemId . '"'
                . ' data-afcs-equipment-filter-kind="' . htmlspecialchars_uni($subtype) . '"'
                . ' data-afcs-equipment-default-slot="' . htmlspecialchars_uni($default_slot) . '"'
                . ' data-afcs-equipment-candidate-slots="' . htmlspecialchars_uni(implode(',', $candidate_slots)) . '">'
                . '<div class="af-cs-augment-card__icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
                . '<div class="af-cs-augment-card__body">'
                . '<div class="af-cs-augment-card__title">' . htmlspecialchars_uni($title) . '</div>'
                . ($desc !== '' ? '<div class="af-cs-augment-card__desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
                . '<div class="af-cs-inventory-row"><span>Количество</span><strong>' . max(1, (int)($item['qty'] ?? 1)) . '</strong></div>'
                . '<div class="af-cs-sr-only" data-afcs-equipment-card-meta="1"'
                    . ' data-afcs-equipment-popover-kind="inventory"'
                    . ' data-afcs-equipment-popover-title="' . htmlspecialchars_uni($title) . '"'
                    . ' data-afcs-equipment-popover-desc="' . htmlspecialchars_uni($desc) . '"'
                    . ' data-afcs-equipment-popover-slot="' . htmlspecialchars_uni(implode(', ', array_map(function ($code) use ($slot_labels) { return (string)($slot_labels[$code] ?? $code); }, $candidate_slots))) . '"'
                    . ' data-afcs-equipment-popover-stats="' . htmlspecialchars_uni(strip_tags($bonus_html)) . '"'
                    . ' data-afcs-equipment-popover-icon="' . htmlspecialchars_uni((string)($item['icon'] ?? '')) . '"'
                    . ' data-afcs-equipment-default-slot="' . htmlspecialchars_uni($default_slot) . '"'
                    . ($is_equipped ? ' data-afcs-equipment-popover-equipped-slot="' . htmlspecialchars_uni((string)($item['equipped_slot'] ?? '')) . '"' : '')
                    . '></div>'
                . '</div>'
                . '</div>';
        }

        if (!$available_cards) {
            $available_cards[] = '<div class="af-cs-muted">Подходящая экипировка не найдена.</div>';
        }
    }

    $group_slots = [
        'armor' => ['head', 'body', 'back', 'hands', 'legs', 'feet'],
        'weapon' => ['weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_melee', 'weapon_ranged', 'ammo'],
        'support' => ['support_1', 'support_2', 'support_3', 'support_4'],
        'accessories' => ['accessory_1', 'accessory_2', 'belt', 'artifact'],
        'other' => ['gear'],
    ];
    $group_titles = [
        'armor' => 'Броня',
        'weapon' => 'Оружие',
        'support' => 'Быстрые слоты',
        'accessories' => 'Аксессуары',
        'other' => 'Прочее',
    ];

    $slots_grouped_html = [];
    foreach ($group_slots as $group_key => $group_slot_codes) {
        $group_cards = [];
        foreach ($group_slot_codes as $slot_code) {
            if (isset($preview_slots_map[$slot_code])) {
                $group_cards[] = $preview_slots_map[$slot_code];
            }
        }
        if (!$group_cards) {
            continue;
        }
        $slots_grouped_html[] = '<section class="af-cs-equipment-slots-group" data-afcs-equipment-group="' . htmlspecialchars_uni($group_key) . '">'
            . '<h4 class="af-cs-panel-title af-cs-panel-title--small">' . htmlspecialchars_uni((string)($group_titles[$group_key] ?? $group_key)) . '</h4>'
            . '<div class="af-cs-equipment-slot-grid">' . implode('', $group_cards) . '</div>'
            . '</section>';
    }

    $gear_btn = $can_edit
        ? '<button type="button" class="af-cs-attrs__gear af-cs-equipment__gear" data-afcs-equipment-edit-toggle="1" aria-label="Редактировать экипировку" title="Редактировать экипировку"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>'
        : '';

    $info_preview_html = '<div class="af-cs-augment-preview af-cs-equipment-info-preview" data-afcs-equipment-info-preview="1">'
        . '<div class="af-cs-augment-preview__title">Выберите слот</div>'
        . '<div class="af-cs-augment-preview__desc">Наведите или нажмите на слот экипировки, чтобы увидеть детали.</div>'
        . '</div>';

    $equipment_html = '<div class="af-cs-augmentations-ui af-cs-equipment-ui af-cs-equipment-ui--public" data-afcs-equipment-root="1" data-afcs-equipment-mode="public" data-afcs-equipment-can-edit="' . ($can_edit ? '1' : '0') . '">'
        . '<div class="af-cs-augmentations-top-row af-cs-equipment-top-row">'
            . '<div class="af-cs-augmentations-column af-cs-equipment-slots-column">'
                . '<div class="af-cs-panel-title af-cs-panel-title--with-actions"><span>Надетая экипировка</span>' . $gear_btn . '</div>'
                . '<div class="af-cs-equipment-preview" data-afcs-equipment-preview-root="1">' . implode('', $slots_grouped_html) . '</div>'
            . '</div>'
            . '<div class="af-cs-augmentations-column af-cs-augmentations-preview-column">'
                . '<div class="af-cs-panel-title">Превью</div>'
                . $info_preview_html
            . '</div>'
        . '</div>'
        . ($can_edit
            ? ('<div class="af-cs-equipment-edit-section" data-afcs-equipment-edit-section="1" hidden>'
                . '<div class="af-cs-augmentations-column af-cs-augmentations-column--full af-cs-equipment-available-block">'
                    . '<div class="af-cs-panel-title">Доступные предметы</div>'
                    . '<div class="af-cs-equipment-filters">'
                        . '<button type="button" class="af-cs-tab-btn is-active" data-afcs-equipment-filter="all">Все</button>'
                        . '<button type="button" class="af-cs-tab-btn" data-afcs-equipment-filter="armor">Броня</button>'
                        . '<button type="button" class="af-cs-tab-btn" data-afcs-equipment-filter="weapon">Оружие</button>'
                        . '<button type="button" class="af-cs-tab-btn" data-afcs-equipment-filter="ammo">Боеприпасы</button>'
                        . '<button type="button" class="af-cs-tab-btn" data-afcs-equipment-filter="consumable">Расходники</button>'
                        . '<button type="button" class="af-cs-tab-btn" data-afcs-equipment-filter="gear">Снаряжение / аксессуары</button>'
                    . '</div>'
                    . '<div class="af-cs-augmentations-list af-cs-equipment-cards-grid" data-afcs-equipment-cards-grid="1">' . implode('', $available_cards) . '</div>'
                . '</div>'
                . '<div class="af-cs-equipment-edit-actions">'
                    . '<button type="button" class="af-cs-btn" data-afcs-equipment-edit-save="1">Сохранить</button>'
                    . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-equipment-edit-cancel="1">Отменить</button>'
                . '</div>'
            . '</div>')
            : '')
        . '<div class="af-cs-equip-popover" data-afcs-equipment-popover="1" hidden></div>'
        . '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_equipment');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_render_catalog_page(): void
{
    global $db, $templates, $header, $headerinclude, $footer;

    $rows = [];
    if ($db->table_exists(AF_CS_TABLE)) {
        $q = $db->simple_select(AF_CS_TABLE, 'tid,uid,sheet_slug', 'sheet_created=1', ['order_by' => 'tid', 'order_dir' => 'DESC']);
        while ($row = $db->fetch_array($q)) {
            if (!empty($row['sheet_slug'])) {
                $rows[] = $row;
            }
        }
    }

    $tids = array_map(static function ($row) {
        return (int)($row['tid'] ?? 0);
    }, $rows);
    $atf_map = af_charactersheets_get_atf_fields_map($tids);

    $cards = [];
    foreach ($rows as $row) {
        $tid = (int)($row['tid'] ?? 0);
        $slug = (string)($row['sheet_slug'] ?? '');
        $fields = $atf_map[$tid] ?? [];
        $index = af_charactersheets_index_fields($fields);

        $name_en = af_charactersheets_pick_field_value($index, ['character_name_en', 'character_name', 'char_name', 'name']);
        $name_ru = af_charactersheets_pick_field_value($index, ['character_name_ru']);
        $race = af_charactersheets_pick_field_value($index, ['character_race', 'race']);
        $class = af_charactersheets_pick_field_value($index, ['character_class', 'class']);
        $theme = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme']);
        $portrait = af_charactersheets_get_portrait_url($index);

        $sheet_url = af_charactersheets_url(['slug' => $slug]);
        $sheet_url = htmlspecialchars_uni($sheet_url);
        $card_name_en = htmlspecialchars_uni($name_en !== '' ? $name_en : $slug);
        $card_name_ru = htmlspecialchars_uni($name_ru);
        $card_race_label = htmlspecialchars_uni($race);
        $card_class_label = htmlspecialchars_uni($class);
        $card_theme_label = htmlspecialchars_uni($theme);
        $card_race_chip = $race !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($race) . '</span>' : '';
        $card_class_chip = $class !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($class) . '</span>' : '';
        $card_theme_chip = $theme !== '' ? '<span class="af-cs-chip">' . htmlspecialchars_uni($theme) . '</span>' : '';
        $card_portrait = htmlspecialchars_uni($portrait);

        $tpl = $templates->get('charactersheets_catalog_card');
        eval("\$card_html = \"" . $tpl . "\";");
        $cards[] = $card_html;
    }

    $cards_html = implode('', $cards);
    if ($cards_html === '') {
        $cards_html = '<div class="af-cs-muted">Листы персонажей пока не созданы.</div>';
    }

    $assets = af_charactersheets_get_asset_urls();
    $asset_version = af_charactersheets_get_asset_version();
    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($assets['css']) . '?v=' . $asset_version . '" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($assets['js']) . '?v=' . $asset_version . '"></script>' . "\n";

    $page_title = 'Каталог листов персонажей';
    $tpl = $templates->get('charactersheets_catalog');
    eval("\$page = \"" . $tpl . "\";");
    output_page($page);
    exit;
}
