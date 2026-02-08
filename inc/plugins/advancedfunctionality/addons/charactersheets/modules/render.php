<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_render_sheet_page(string $slug): void
{
    global $db, $lang, $templates, $header, $headerinclude, $footer, $mybb;

    $accept_row = af_charactersheets_get_accept_row_by_slug($slug);
    if (empty($accept_row)) {
        error_no_permission();
        exit;
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
        if (!is_array($thread)) $thread = [];
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
        if (!is_array($user)) $user = [];
    }

    $profile_url = function_exists('get_profile_link') ? get_profile_link($uid) : ('member.php?action=profile&uid=' . $uid);
    $profile_url = html_entity_decode($profile_url, ENT_QUOTES, 'UTF-8');
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
        // ВАЖНО: автосоздаём с корректным uid
        $sheet = af_charactersheets_autocreate_sheet($tid, $thread);
    }

    $sheet_view = af_charactersheets_compute_sheet_view($sheet);
    $build = af_charactersheets_normalize_build(
        af_charactersheets_json_decode((string)($sheet['build_json'] ?? ''))
    );
    $can_edit_sheet = af_charactersheets_user_can_edit_sheet($sheet, $mybb->user ?? []);
    $can_manage_sheet = af_cs_can_manage_sheet((int)($mybb->user['uid'] ?? 0), (int)($sheet['uid'] ?? 0));
    // fid нужен для is_moderator(), возьмём из threads если можем
    $fid_for_mod = (int)($thread['fid'] ?? 0);
    $can_award_exp = af_charactersheets_user_can_award_exp($mybb->user ?? [], $fid_for_mod);
    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $sheet_title = htmlspecialchars_uni($character_name_en);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $can_delete_sheet = af_charactersheets_user_can_delete_sheet($sheet, $mybb->user ?? []);
    $delete_redirect = $thread_url !== '' ? $thread_url : 'misc.php?action=af_charactersheets';
    $sheet_header_actions_html = af_charactersheets_build_header_actions_html(
        $profile_url,
        $thread_url,
        $can_delete_sheet,
        $delete_redirect,
        $can_award_exp
    );
    $sheet_info_table_html = af_charactersheets_build_info_table_html($atf_index);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_bonus_html = af_charactersheets_build_bonus_html($atf_index);
    $sheet_skills_html = af_charactersheets_build_skills_html($sheet_view, $can_manage_sheet, $can_view_ledger);
    $sheet_knowledge_html = af_charactersheets_build_knowledge_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_abilities_html = af_charactersheets_build_abilities_html($build, $can_edit_sheet);
    $sheet_inventory_html = af_charactersheets_build_inventory_html($build, $can_edit_sheet);
    $sheet_augments_html = af_charactersheets_build_augments_html($build, $can_edit_sheet);
    $sheet_equipment_html = af_charactersheets_build_equipment_html($build, $can_edit_sheet);
    $sheet_mechanics_html = af_charactersheets_build_mechanics_html($sheet_view);

    $sheet_progress_html = af_charactersheets_build_progress_html($sheet_view, $sheet, $can_award_exp, $can_view_ledger);

    $sheet_portrait_url = af_charactersheets_get_portrait_url($atf_index);
    $sheet_level_value = (int)($sheet_view['level'] ?? 1);
    $sheet_level_percent = (int)($sheet_view['level_percent'] ?? 0);
    $sheet_level_exp_label = htmlspecialchars_uni((string)($sheet_view['level_exp_label'] ?? ''));
    $sheet_name_ru = htmlspecialchars_uni($character_name_ru !== '' ? $character_name_ru : '—');
    $sheet_nicknames = htmlspecialchars_uni($character_nicknames !== '' ? $character_nicknames : '—');
    $sheet_id = (int)($sheet['id'] ?? 0);
    $sheet_post_key = htmlspecialchars_uni($mybb->post_code);
    $bonus_items_json = htmlspecialchars_uni(af_charactersheets_json_encode((array)($sheet_view['bonus_items'] ?? [])));

    $page_title = 'Лист персонажа';
    if (!empty($user['username'])) {
        $page_title .= ' — ' . $user['username'];
    } elseif (!empty($character_name_en)) {
        $page_title .= ' — ' . $character_name_en;
    }

    $headerinclude .= "\n" . AF_CS_ASSET_MARK . "\n";
    af_charactersheets_ensure_assets_in_headerinclude();


    $tplInner = $templates->get('charactersheet_inner');
    eval("\$sheet_inner = \"" . $tplInner . "\";");

    $ajax = (string)$mybb->get_input('ajax');
    if ($ajax === '1') {
        $tplModal = $templates->get('charactersheet_modal');
        eval("\$page = \"" . $tplModal . "\";");
    } else {
        $tplFull = $templates->get('charactersheet_fullpage');
        eval("\$page = \"" . $tplFull . "\";");
    }

    // Вырезаем дубли ассетов (в т.ч. если кто-то другой добавил ?v=...)
    $page = af_charactersheets_canonicalize_assets_html($page);

    output_page($page);
    exit;

}

/* -------------------- HELPERS -------------------- */

function af_charactersheets_build_attributes_html(array $view, bool $can_edit, bool $can_view_pool): string
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
            . '<div class="af-cs-attr-card__stat"><span>База</span><strong>' . htmlspecialchars_uni((string)$base) . '</strong></div>'
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
        if ($choice_key === '') {
            continue;
        }
        $select_options = '';
        foreach ($labels as $key => $attrLabel) {
            $selected = ((string)($view['choices'][$choice_key] ?? '') === $key) ? ' selected' : '';
            $select_options .= '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>'
                . htmlspecialchars_uni($attrLabel) . '</option>';
        }
        $choice_items[] = '<div class="af-cs-choice-row">'
            . '<label>' . htmlspecialchars_uni($label) . '</label>'
            . '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '">' . $select_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить</button>'
            . '</div>';
    }
    $attributes_choice_html = ($can_edit && $choice_items)
        ? '<div class="af-cs-choices">' . implode('', $choice_items) . '</div>'
        : '';
    $attributes_actions_html = $can_edit
        ? '<button type="button" class="af-cs-btn" data-afcs-save-attributes="1">Сохранить распределение</button>'
        : '<div class="af-cs-muted">Редактирование недоступно.</div>';

    $attributes_can_edit = $can_edit ? 1 : 0;

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

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $attr_points_free = (int)($progress['attr_points_free'] ?? 0);
    $skill_points_free = (int)($progress['skill_points_free'] ?? 0);
    $bonus_attr_points = (int)($view['bonus_attr_points'] ?? 0);
    $bonus_skill_points = (int)($view['bonus_skill_points'] ?? 0);

    $points_html = '';
    if ($can_view_ledger) {
        $points_html = '<div>Свободные очки атрибутов: <strong>' . htmlspecialchars_uni((string)($attr_points_free + $bonus_attr_points)) . '</strong></div>'
            . '<div>Свободные очки навыков: <strong>' . htmlspecialchars_uni((string)($skill_points_free + $bonus_skill_points)) . '</strong></div>';
        $bonus_sources = (array)($view['bonus_sources'] ?? []);
        if ($bonus_sources) {
            $points_html .= '<div class="af-cs-muted">Источник бонусов: '
                . htmlspecialchars_uni(implode(', ', $bonus_sources))
                . '</div>';
        }
    }
    // ---- LEDGER (только если есть права) ----
    $ledger_html = '';
    $ledger_toggle_html = '';
    $ledger_block_html = '';

    if ($can_view_ledger) {
        $ledger_items = [];
        foreach (af_charactersheets_get_ledger($sheet_id, 10) as $row) {
            $meta = af_charactersheets_json_decode((string)($row['meta_json'] ?? ''));
            $desc = (string)($meta['reason'] ?? $meta['source'] ?? '');

            if ($desc === '' && !empty($meta['tid'])) {
                $desc = 'Тема #' . (int)$meta['tid'];
            }
            if ($desc === '') {
                $desc = strtoupper((string)($row['event_type'] ?? 'exp'));
            }

            $by_uid = (int)($meta['by_uid'] ?? $meta['awarded_by'] ?? $meta['accepted_by'] ?? 0);
            $by_name = $by_uid > 0 ? af_charactersheets_username_by_uid($by_uid) : '';
            $by_label = $by_name !== '' ? $by_name : ($by_uid > 0 ? ('UID ' . $by_uid) : 'Система');

            $amount_label = af_charactersheets_format_decimal($row['amount']);

            $ledger_items[] = '<div class="af-cs-ledger-row">'
                . '<div class="af-cs-ledger-desc">' . htmlspecialchars_uni($desc) . '</div>'
                . '<div class="af-cs-ledger-amount">' . htmlspecialchars_uni($amount_label) . '</div>'
                . '<div class="af-cs-ledger-by">' . htmlspecialchars_uni($by_label) . '</div>'
                . '<div class="af-cs-ledger-date">' . htmlspecialchars_uni(date('d.m.Y H:i', (int)$row['created_at'])) . '</div>'
                . '</div>';

        }

        if (!$ledger_items) {
            $ledger_items[] = '<div class="af-cs-muted">Нет начислений.</div>';
        }

        $ledger_html = implode('', $ledger_items);

        // toggle + block (как ты просила)
        $ledger_toggle_html = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-ledger-toggle><i class="fa-solid fa-clock-rotate-left"></i></button>';
        $ledger_block_html = '<div class="af-cs-ledger" data-afcs-ledger hidden>' . $ledger_html . '</div>';
    }

    // ---- MANUAL AWARD (только если can_award) ----
    $manual_award_html = '';
    $manual_award_toggle_html = '';
    if ($can_award) {
        $manual_award_html = '<div class="af-cs-award-panel" data-afcs-award-panel hidden>'
            . '<form class="af-cs-award-form" data-afcs-award-form="1" autocomplete="off">'
            . '<div class="af-cs-award-form__row">'
            . '<label class="af-cs-award-form__label" for="afcs_award_amount">EXP</label>'
            . '<input id="afcs_award_amount" class="textbox af-cs-award-form__input" type="number" name="amount"'
            . ' step="1" inputmode="numeric" placeholder="Например: 100" required>'
            . '</div>'
            . '<div class="af-cs-award-form__row">'
            . '<label class="af-cs-award-form__label" for="afcs_award_reason">Причина</label>'
            . '<input id="afcs_award_reason" class="textbox af-cs-award-form__input" type="text" name="reason"'
            . ' maxlength="255" placeholder="Например: квест, ивент, награда" required>'
            . '</div>'
            . '<div class="af-cs-award-form__actions">'
            . '<button type="submit" class="button button--primary af-cs-btn af-cs-btn--submit">Отправить</button>'
            . '<button type="button" class="button button--secondary af-cs-btn af-cs-btn--cancel"'
            . ' data-afcs-award-toggle>Закрыть</button>'
            . '</div>'
            . '</form>'
            . '</div>';
    }

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

function af_charactersheets_build_skills_html(array $view, bool $can_manage, bool $can_view_pool): string
{
    $skills = (array)($view['skills'] ?? []);
    $items = [];
    foreach ($skills as $skill) {
        $slug = (string)($skill['slug'] ?? '');
        $title = (string)($skill['title'] ?? '');
        $attr_label = (string)($skill['attr_label'] ?? '');
        $invested = (int)($skill['invested'] ?? 0);
        $total = (float)($skill['total'] ?? 0);

        $controls = '';
        $gear = '';
        if ($can_manage && $slug !== '') {
            $gear = '<button type="button" class="af-cs-skill-gear" data-afcs-skill-toggle aria-label="Управление навыком">'
                . '<i class="fa fa-cog" aria-hidden="true"></i>'
                . '</button>';
            $controls = '<div class="af-cs-skill-controls" data-afcs-skill-controls>'
                . '<button type="button" class="af-cs-skill-btn" data-afcs-skill-change="1" data-slug="' . htmlspecialchars_uni($slug) . '" data-delta="-1">−</button>'
                . '<span class="af-cs-skill-invested">' . htmlspecialchars_uni((string)$invested) . '</span>'
                . '<button type="button" class="af-cs-skill-btn" data-afcs-skill-change="1" data-slug="' . htmlspecialchars_uni($slug) . '" data-delta="1">+</button>'
                . '</div>';
        }
        $total_label = af_charactersheets_format_signed($total);

        $items[] = '<div class="af-cs-skill-item">'
            . '<div class="af-cs-skill-left">'
            . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title)
            . '<span>(' . htmlspecialchars_uni($attr_label) . ')</span>'
            . '</div>'
            . '</div>'
            . '<div class="af-cs-skill-right">'
            . '<div class="af-cs-skill-total">' . htmlspecialchars_uni($total_label) . '</div>'
            . $gear
            . $controls
            . '</div>'
            . '</div>';
    }

    if (!$items) {
        $items[] = '<div class="af-cs-muted">Навыков пока нет.</div>';
    }

    $skills_html = implode('', $items);

    $skill_pool_html = '';
    if ($can_view_pool) {
        $skill_pool_html = '<div class="af-cs-skill-pool">'
            . '<div>Пул навыков: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_total'] ?? 0)) . '</strong></div>'
            . '<div>Распределено: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_spent'] ?? 0)) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)($view['skill_pool_remaining'] ?? 0)) . '</strong></div>'
            . '</div>';
    }

    $choice_html = '';
    if ($can_manage && !empty($view['skill_choice_details'])) {
        $options = '';
        foreach ($skills as $skill) {
            $slug = (string)($skill['slug'] ?? '');
            $title = (string)($skill['title'] ?? '');
            if ($slug === '') {
                continue;
            }
            $options .= '<option value="' . htmlspecialchars_uni($slug) . '">' . htmlspecialchars_uni($title) . '</option>';
        }

        $rows = [];
        foreach ($view['skill_choice_details'] as $choice) {
            $choice_key = (string)($choice['choice_key'] ?? '');
            $chosen = (string)($choice['chosen'] ?? '');
            if ($choice_key === '') {
                continue;
            }
            $select = '<select data-afcs-choice-key="' . htmlspecialchars_uni($choice_key) . '">';
            $select .= '<option value="">— выбрать навык —</option>';
            foreach ($skills as $skill) {
                $slug = (string)($skill['slug'] ?? '');
                $title = (string)($skill['title'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $selected = $chosen === $slug ? ' selected' : '';
                $select .= '<option value="' . htmlspecialchars_uni($slug) . '"' . $selected . '>' . htmlspecialchars_uni($title) . '</option>';
            }
            $select .= '</select>';

            $rows[] = '<div class="af-cs-choice-row">'
                . '<label>Бонус к любому навыку</label>'
                . $select
                . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-choice-save="' . htmlspecialchars_uni($choice_key) . '">Применить</button>'
                . '</div>';
        }
        if ($rows) {
            $choice_html = '<div class="af-cs-choices">' . implode('', $rows) . '</div>';
        }
    }

    global $templates;
    $tpl = $templates->get('charactersheet_skills');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_knowledge_html(array $view, bool $can_edit, bool $can_view_pool): string
{
    $knowledge_entries = af_charactersheets_get_kb_entries_by_type('knowledge');
    $language_entries = af_charactersheets_get_kb_entries_by_type('language');

    $knowledge_selected = (array)($view['knowledge']['selected'] ?? []);
    $knowledge_bonus = (array)($view['knowledge']['bonus'] ?? []);
    $knowledge_remaining = (int)($view['knowledge']['remaining'] ?? 0);
    $knowledge_total = (int)($view['knowledge']['total_choices'] ?? 0);
    $knowledge_selected_count = count($knowledge_selected);

    $language_selected = (array)($view['languages']['selected'] ?? []);
    $language_bonus = (array)($view['languages']['bonus'] ?? []);
    $language_remaining = (int)($view['languages']['remaining'] ?? 0);
    $language_total = (int)($view['languages']['total_choices'] ?? 0);
    $language_selected_count = count($language_selected);

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

    $knowledge_items = [];
    foreach ($knowledge_bonus as $key) {
        $entry = af_charactersheets_kb_get_entry('knowledge', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $knowledge_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span class="af-cs-knowledge-title">' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . ($desc !== '' ? '<small class="af-cs-knowledge-desc">' . htmlspecialchars_uni($desc) . '</small>' : '')
            . '<em class="af-cs-knowledge-badge">Бонус</em>'
            . '</div>';
    }
    foreach ($knowledge_selected as $key) {
        $entry = af_charactersheets_kb_get_entry('knowledge', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $remove = $can_edit
            ? '<button type="button" data-afcs-knowledge-remove="1" data-afcs-knowledge-type="knowledge" data-afcs-knowledge-key="' . htmlspecialchars_uni($key) . '">×</button>'
            : '';
        $knowledge_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span class="af-cs-knowledge-title">' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . ($desc !== '' ? '<small class="af-cs-knowledge-desc">' . htmlspecialchars_uni($desc) . '</small>' : '')
            . $remove
            . '</div>';
    }
    if (!$knowledge_items) {
        $knowledge_items[] = '<div class="af-cs-muted">Пока нет знаний.</div>';
    }

    $language_items = [];
    foreach ($language_bonus as $key) {
        $entry = af_charactersheets_kb_get_entry('language', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $language_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span class="af-cs-knowledge-title">' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . ($desc !== '' ? '<small class="af-cs-knowledge-desc">' . htmlspecialchars_uni($desc) . '</small>' : '')
            . '<em class="af-cs-knowledge-badge">Бонус</em>'
            . '</div>';
    }
    foreach ($language_selected as $key) {
        $entry = af_charactersheets_kb_get_entry('language', $key);
        $label = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $remove = $can_edit
            ? '<button type="button" data-afcs-knowledge-remove="1" data-afcs-knowledge-type="language" data-afcs-knowledge-key="' . htmlspecialchars_uni($key) . '">×</button>'
            : '';
        $language_items[] = '<div class="af-cs-knowledge-chip">'
            . '<span class="af-cs-knowledge-title">' . htmlspecialchars_uni($label !== '' ? $label : $key) . '</span>'
            . ($desc !== '' ? '<small class="af-cs-knowledge-desc">' . htmlspecialchars_uni($desc) . '</small>' : '')
            . $remove
            . '</div>';
    }
    if (!$language_items) {
        $language_items[] = '<div class="af-cs-muted">Пока нет языков.</div>';
    }

    $knowledge_items_html = implode('', $knowledge_items);
    $language_items_html = implode('', $language_items);
    $knowledge_form = '';
    $language_form = '';
    if ($can_edit) {
        $knowledge_form = '<div class="af-cs-knowledge-form">'
            . '<select data-afcs-knowledge-select="knowledge">' . $knowledge_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-knowledge-add="1" data-afcs-knowledge-type="knowledge">Добавить</button>'
            . '</div>';
        $language_form = '<div class="af-cs-knowledge-form">'
            . '<select data-afcs-knowledge-select="language">' . $language_options . '</select>'
            . '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-knowledge-add="1" data-afcs-knowledge-type="language">Добавить</button>'
            . '</div>';
    }

    $knowledge_pool_html = '';
    if ($can_view_pool) {
        $knowledge_pool_html = '<div class="af-cs-knowledge-pool">'
            . '<div>Доступно знаний: <strong>' . htmlspecialchars_uni((string)$knowledge_total) . '</strong></div>'
            . '<div>Выбрано: <strong>' . htmlspecialchars_uni((string)$knowledge_selected_count) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)$knowledge_remaining) . '</strong></div>'
            . '</div>';
    }

    $language_pool_html = '';
    if ($can_view_pool) {
        $language_pool_html = '<div class="af-cs-knowledge-pool">'
            . '<div>Доступно языков: <strong>' . htmlspecialchars_uni((string)$language_total) . '</strong></div>'
            . '<div>Выбрано: <strong>' . htmlspecialchars_uni((string)$language_selected_count) . '</strong></div>'
            . '<div>Осталось: <strong>' . htmlspecialchars_uni((string)$language_remaining) . '</strong></div>'
            . '</div>';
    }

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

function af_charactersheets_build_abilities_html(array $build, bool $can_edit): string
{
    $abilities = (array)($build['abilities'] ?? []);
    $owned = (array)($abilities['owned'] ?? []);

    $cards = [];
    foreach ($owned as $ability) {
        if (!is_array($ability)) {
            continue;
        }
        $type = (string)($ability['type'] ?? '');
        $key = (string)($ability['key'] ?? '');
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
        $equipped = !empty($ability['equipped']);
        $equip_label = $equipped ? 'Снять' : 'Надеть';
        $equip_action = $can_edit
            ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-ability-toggle="1"'
                . ' data-afcs-ability-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-ability-key="' . htmlspecialchars_uni($key) . '"'
                . ' data-afcs-ability-equipped="' . ($equipped ? '0' : '1') . '">' . $equip_label . '</button>'
            : '';

        $cards[] = '<div class="af-cs-ability-card' . ($equipped ? ' is-equipped' : '') . '">'
            . '<div class="af-cs-ability-icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
            . '<div class="af-cs-ability-body">'
            . '<div class="af-cs-ability-title">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</div>'
            . ($desc !== '' ? '<div class="af-cs-ability-desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
            . ($bonus_html !== '' ? '<div class="af-cs-ability-bonus">' . $bonus_html . '</div>' : '')
            . ($equip_action !== '' ? '<div class="af-cs-ability-actions">' . $equip_action . '</div>' : '')
            . '</div>'
            . '</div>';
    }

    if (!$cards) {
        $cards[] = '<div class="af-cs-muted">Способности пока не куплены.</div>';
    }

    $abilities_html = implode('', $cards);

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
    string $profile_url,
    string $thread_url,
    bool $can_delete,
    string $delete_redirect,
    bool $can_award
): string
{
    $items = [];

    if ($profile_url !== '') {
        $items[] = '<a class="af-cs-btn af-cs-btn--compact" href="' . htmlspecialchars_uni($profile_url)
            . '" title="Профиль" aria-label="Профиль"><i class="fa-regular fa-user"></i></a>';
    }

    if ($thread_url !== '') {
        $items[] = '<a class="af-cs-btn af-cs-btn--compact" href="' . htmlspecialchars_uni($thread_url)
            . '" title="Анкета" aria-label="Анкета"><i class="fa-regular fa-id-card"></i></a>';
    }

    if ($can_award) {
        $items[] = '<button type="button" class="af-cs-btn af-cs-btn--compact af-cs-btn--icon" data-afcs-award-toggle'
            . ' title="Ручное начисление" aria-label="Ручное начисление"><i class="fa-solid fa-plus"></i></button>';
    }

    if ($can_delete) {
        $items[] = '<button type="button" class="af-cs-btn af-cs-btn--compact af-cs-btn--danger" data-afcs-delete-sheet'
            . ' data-afcs-delete-redirect="' . htmlspecialchars_uni($delete_redirect) . '" title="Удалить"'
            . ' aria-label="Удалить"><i class="fa-solid fa-xmark"></i></button>';
    }

    if (empty($items)) {
        return '';
    }

    return implode('', $items);
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

function af_charactersheets_build_info_table_html(array $index): string
{
    $age = af_charactersheets_pick_field_value($index, ['character_age', 'age']);
    $gender = af_charactersheets_pick_field_value($index, ['character_gen', 'character_gender', 'gender']);
    $race_key = af_charactersheets_pick_field_value($index, ['character_race', 'race'], false);
    $class_key = af_charactersheets_pick_field_value($index, ['character_class', 'class'], false);
    $theme_key = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], false);
    $race_label = af_charactersheets_pick_field_value($index, ['character_race', 'race'], true);
    $class_label = af_charactersheets_pick_field_value($index, ['character_class', 'class'], true);
    $theme_label = af_charactersheets_pick_field_value($index, ['character_themes', 'character_theme', 'theme'], true);

    $items = [];
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Возраст</div><div class="af-cs-info-value">' . htmlspecialchars_uni($age !== '' ? $age : '—') . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Пол</div><div class="af-cs-info-value">' . htmlspecialchars_uni($gender !== '' ? $gender : '—') . '</div></div>';

    $chip_html = '';
    $chip_html .= af_charactersheets_render_kb_chip('race', $race_key, $race_label);
    $chip_html .= af_charactersheets_render_kb_chip('class', $class_key, $class_label);
    $chip_html .= af_charactersheets_render_kb_chip('themes', $theme_key, $theme_label);
    if ($chip_html === '') {
        $chip_html = '<span class="af-cs-muted">—</span>';
    }
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Чипы</div><div class="af-cs-info-value">' . $chip_html . '</div></div>';
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Кошелёк</div><div class="af-cs-info-value">0 ₵</div></div>';

    return '<div class="af-cs-info-table">' . implode('', $items) . '</div>';
}

function af_charactersheets_build_bonus_html(array $index): string
{
    $mapping = af_charactersheets_kb_mapping();
    $columns = [];
    foreach ($mapping as $fieldName => $data) {
        $field = $index[$fieldName] ?? [];
        $key = (string)($field['value'] ?? '');
        $entry = $key !== '' ? af_charactersheets_kb_get_entry((string)$data['type'], $key) : [];
        $title = (string)$data['label'];
        $text_html = af_charactersheets_kb_get_block_html($entry, 'bonuses');
        $columns[] = '<div class="af-cs-bonus-card">'
            . '<div class="af-cs-bonus-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-cs-bonus-body">' . $text_html . '</div>'
            . '</div>';
    }

    return '<div class="af-cs-bonus-grid">' . implode('', $columns) . '</div>';
}

function af_charactersheets_build_mechanics_html(array $view): string
{
    $mechanics = (array)($view['mechanics'] ?? []);
    $armor_bonus = (int)($mechanics['armor_bonus'] ?? 0);
    $shield_bonus = (int)($mechanics['shield_bonus'] ?? 0);
    $weapon_bonus = (int)($mechanics['weapon_bonus'] ?? 0);
    $ac_total = (int)($mechanics['ac_total'] ?? 0);
    $hp_total = (int)($mechanics['hp_total'] ?? 0);
    $humanity_total = (int)($mechanics['humanity_total'] ?? 0);
    $saves = (array)($mechanics['saves'] ?? []);
    $reflex = af_charactersheets_format_signed($saves['reflex'] ?? 0);
    $will = af_charactersheets_format_signed($saves['will'] ?? 0);
    $fortitude = af_charactersheets_format_signed($saves['fortitude'] ?? 0);
    $perception = af_charactersheets_format_signed($saves['perception'] ?? 0);

    $col1 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Класс брони</div>'
        . '<div class="af-cs-mech-row"><span>Броня</span><span>' . htmlspecialchars_uni(af_charactersheets_format_signed($armor_bonus)) . '</span></div>'
        . '<div class="af-cs-mech-row"><span>Щит</span><span>' . htmlspecialchars_uni(af_charactersheets_format_signed($shield_bonus)) . '</span></div>'
        . '<div class="af-cs-mech-row af-cs-mech-total"><span>Итоговый AC</span><span>' . htmlspecialchars_uni((string)$ac_total) . '</span></div>'
        . '</div>';

    $col2 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Спасброски</div>'
        . '<div class="af-cs-mech-row"><span>Рефлекс</span><span>' . htmlspecialchars_uni($reflex) . '</span></div>'
        . '<div class="af-cs-mech-row"><span>Воля</span><span>' . htmlspecialchars_uni($will) . '</span></div>'
        . '<div class="af-cs-mech-row"><span>Стойкость</span><span>' . htmlspecialchars_uni($fortitude) . '</span></div>'
        . '<div class="af-cs-mech-row"><span>Восприятие</span><span>' . htmlspecialchars_uni($perception) . '</span></div>'
        . '<div class="af-cs-mech-divider"></div>'
        . '<div class="af-cs-mech-row"><span>HP</span><span>' . htmlspecialchars_uni((string)$hp_total) . '</span></div>'
        . '<div class="af-cs-mech-row"><span>Человечность</span><span>' . htmlspecialchars_uni((string)$humanity_total) . '</span></div>'
        . '</div>';

    $weapon_bonus_label = af_charactersheets_format_signed($weapon_bonus);
    $damage_total = '1d4 + ' . $weapon_bonus_label;
    $col3 = '<div class="af-cs-mech-card">'
        . '<div class="af-cs-mech-title">Урон</div>'
        . '<div class="af-cs-mech-row"><span>Базовый</span><span>1d4</span></div>'
        . '<div class="af-cs-mech-row"><span>Бонус оружия</span><span>' . htmlspecialchars_uni($weapon_bonus_label) . '</span></div>'
        . '<div class="af-cs-mech-row af-cs-mech-total"><span>Итог</span><span>' . htmlspecialchars_uni($damage_total) . '</span></div>'
        . '</div>';

    return '<div class="af-cs-mechanics-grid">' . $col1 . $col2 . $col3 . '</div>';
}

function af_charactersheets_build_inventory_html(array $build, bool $can_edit): string
{
    $inventory = (array)($build['inventory'] ?? []);
    $items = (array)($inventory['items'] ?? []);

    $category_labels = [
        'weapon' => 'Оружие',
        'armor' => 'Броня',
        'tools' => 'Инструменты',
        'consumables' => 'Расходники',
        'craft' => 'Ремесленная сумка',
    ];
    $category_keys = array_keys($category_labels);
    $categories = array_fill_keys($category_keys, []);

    $cards = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string)($item['type'] ?? '');
        $key = (string)($item['key'] ?? '');
        $qty = (int)($item['qty'] ?? 0);
        if ($type === '' || $key === '') {
            continue;
        }
        $entry = af_charactersheets_kb_get_entry($type, $key);
        $category = af_charactersheets_pick_inventory_category($entry);
        if (!isset($categories[$category])) {
            $category = 'tools';
        }
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        $desc = af_charactersheets_kb_pick_text($entry, 'short');
        if ($desc === '') {
            $desc = af_charactersheets_kb_pick_text($entry, 'description');
        }
        $bonus_html = af_charactersheets_kb_get_block_html($entry, 'bonuses');
        $equipped = !empty($item['equipped']);
        $equip_action = $can_edit
            ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-inventory-toggle="1"'
                . ' data-afcs-item-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-item-key="' . htmlspecialchars_uni($key) . '"'
                . ' data-afcs-item-equipped="' . ($equipped ? '0' : '1') . '">'
                . ($equipped ? 'Снять' : 'Надеть') . '</button>'
            : '';

        $cards[] = [
            'category' => $category,
            'title' => $title !== '' ? $title : $key,
            'desc' => $desc,
            'bonus_html' => $bonus_html,
            'qty' => $qty,
            'icon' => af_charactersheets_render_kb_icon($entry, $title),
            'equip_action' => $equip_action,
            'equipped' => $equipped,
            'type' => $type,
            'key' => $key,
        ];
    }

    foreach ($cards as $card) {
        $categories[$card['category']][] = $card;
    }

    $tabs = [];
    $panels = [];
    $active_key = '';
    foreach ($category_labels as $key => $label) {
        if ($active_key === '' && !empty($categories[$key])) {
            $active_key = $key;
        }
    }
    if ($active_key === '') {
        $active_key = array_key_first($category_labels) ?: '';
    }
    foreach ($category_labels as $key => $label) {
        $tab_active = $key === $active_key;
        $tab_class = $tab_active ? ' is-active' : '';
        $tabs[] = '<button type="button" class="af-cs-tab-btn' . $tab_class . '" data-afcs-inventory-tab="' . htmlspecialchars_uni($key) . '">' . htmlspecialchars_uni($label) . '</button>';
        $panel_items = [];
        foreach ($categories[$key] as $card) {
            $panel_items[] = '<button type="button" class="af-cs-slot af-cs-slot--item"'
                . ' data-afcs-item-card="1"'
                . ' data-afcs-item-title="' . htmlspecialchars_uni($card['title']) . '"'
                . ' data-afcs-item-desc="' . htmlspecialchars_uni($card['desc']) . '"'
                . ' data-afcs-item-qty="' . htmlspecialchars_uni((string)$card['qty']) . '"'
                . ' data-afcs-item-effects="' . htmlspecialchars_uni($card['bonus_html']) . '"'
                . ' data-afcs-item-type="' . htmlspecialchars_uni($card['type']) . '"'
                . ' data-afcs-item-key="' . htmlspecialchars_uni($card['key']) . '"'
                . ' data-afcs-item-equipped="' . ($card['equipped'] ? '1' : '0') . '"'
                . ' data-afcs-item-action="' . htmlspecialchars_uni($card['equip_action']) . '">'
                . '<span class="af-cs-slot-icon">' . $card['icon'] . '</span>'
                . '<span class="af-cs-slot-qty">' . htmlspecialchars_uni((string)$card['qty']) . '</span>'
                . '</button>';
        }
        if (!$panel_items) {
            $panel_items[] = '<div class="af-cs-muted">Нет предметов.</div>';
        }
        $panel_class = $tab_active ? ' is-active' : '';
        $panels[] = '<div class="af-cs-inventory-panel' . $panel_class . '" data-afcs-inventory-panel="' . htmlspecialchars_uni($key) . '">'
            . '<div class="af-cs-inventory-grid">' . implode('', $panel_items) . '</div>'
            . '<div class="af-cs-inventory-info" data-afcs-inventory-info>'
            . '<div class="af-cs-muted">Выберите предмет, чтобы увидеть детали.</div>'
            . '</div>'
            . '</div>';
    }

    $inventory_html = '<div class="af-cs-inventory-ui">'
        . '<div class="af-cs-inventory-tabs">' . implode('', $tabs) . '</div>'
        . '<div class="af-cs-inventory-panels">' . implode('', $panels) . '</div>'
        . '</div>';

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

function af_charactersheets_build_augments_html(array $build, bool $can_edit): string
{
    $augmentations = (array)($build['augmentations'] ?? []);
    $slots = (array)($augmentations['slots'] ?? []);
    $owned = (array)($augmentations['owned'] ?? []);

    $slot_labels = af_charactersheets_get_augmentation_slots();
    $slot_rows = [];
    foreach ($slot_labels as $slot => $label) {
        $current = $slots[$slot] ?? null;
        $current_title = '—';
        $current_icon = '<span class="af-cs-slot-icon af-cs-slot-icon--empty"></span>';
        if (is_array($current)) {
            $entry = af_charactersheets_kb_get_entry((string)($current['type'] ?? ''), (string)($current['key'] ?? ''));
            $title = af_charactersheets_kb_pick_text($entry, 'title');
            $current_title = $title !== '' ? $title : (string)($current['key'] ?? '—');
            $current_icon = '<span class="af-cs-slot-icon">' . af_charactersheets_render_kb_icon($entry, $current_title) . '</span>';
        }
        $unequip = ($can_edit && is_array($current) && !empty($current['key']))
            ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-augmentation-unequip="1" data-afcs-augmentation-slot="' . htmlspecialchars_uni($slot) . '">Снять</button>'
            : '';
        $slot_rows[] = '<div class="af-cs-slot">'
            . '<div class="af-cs-slot-header">'
            . $current_icon
            . '<div class="af-cs-slot-label">' . htmlspecialchars_uni($label) . '</div>'
            . '</div>'
            . '<div class="af-cs-slot-title">' . htmlspecialchars_uni($current_title) . '</div>'
            . ($unequip !== '' ? '<div class="af-cs-slot-actions">' . $unequip . '</div>' : '')
            . '</div>';
    }

    $available_cards = [];
    foreach ($owned as $item) {
        if (!is_array($item)) {
            continue;
        }
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
        $allowed_slots = af_charactersheets_pick_augmentation_slots($entry);

        $slot_select = '';
        $equip_btn = '';
        if ($can_edit) {
            $options = '<option value="">— слот —</option>';
            foreach ($slot_labels as $slot => $slotLabel) {
                if ($allowed_slots && !in_array($slot, $allowed_slots, true)) {
                    continue;
                }
                $options .= '<option value="' . htmlspecialchars_uni($slot) . '">' . htmlspecialchars_uni($slotLabel) . '</option>';
            }
            $slot_select = '<select data-afcs-augmentation-slot-select="1">' . $options . '</select>';
            $equip_btn = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-augmentation-equip="1"'
                . ' data-afcs-augmentation-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-augmentation-key="' . htmlspecialchars_uni($key) . '">Надеть</button>';
        }

        $available_cards[] = '<div class="af-cs-augment-card">'
            . '<div class="af-cs-augment-card__icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
            . '<div class="af-cs-augment-card__body">'
            . '<div class="af-cs-augment-card__title">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</div>'
            . ($desc !== '' ? '<div class="af-cs-augment-card__desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
            . ($bonus_html !== '' ? '<div class="af-cs-augment-card__effects">' . $bonus_html . '</div>' : '')
            . ($can_edit ? '<div class="af-cs-augment-card__actions">' . $slot_select . $equip_btn . '</div>' : '')
            . '</div>'
            . '</div>';
    }

    if (!$available_cards) {
        $available_cards[] = '<div class="af-cs-muted">Аугментации не куплены.</div>';
    }

    $augmentations_html = '<div class="af-cs-augmentations-ui">'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-panel-title">Слоты аугментаций</div>'
        . '<div class="af-cs-slot-grid">' . implode('', $slot_rows) . '</div>'
        . '</div>'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-panel-title">Доступные аугментации</div>'
        . '<div class="af-cs-augmentations-list">' . implode('', $available_cards) . '</div>'
        . '</div>'
        . '</div>';

    global $templates;
    $tpl = $templates->get('charactersheet_augmentations');
    eval("\$out = \"" . $tpl . "\";");
    return $out;
}

function af_charactersheets_build_equipment_html(array $build, bool $can_edit): string
{
    $equipment = (array)($build['equipment'] ?? []);
    $slots = (array)($equipment['slots'] ?? []);
    $owned = (array)($equipment['owned'] ?? []);

    $slot_labels = af_charactersheets_get_equipment_slots();
    $slot_rows = [];
    foreach ($slot_labels as $slot => $label) {
        $current = $slots[$slot] ?? null;
        $current_title = '—';
        $current_icon = '<span class="af-cs-slot-icon af-cs-slot-icon--empty"></span>';
        if (is_array($current)) {
            $entry = af_charactersheets_kb_get_entry((string)($current['type'] ?? ''), (string)($current['key'] ?? ''));
            $title = af_charactersheets_kb_pick_text($entry, 'title');
            $current_title = $title !== '' ? $title : (string)($current['key'] ?? '—');
            $current_icon = '<span class="af-cs-slot-icon">' . af_charactersheets_render_kb_icon($entry, $current_title) . '</span>';
        }
        $unequip = ($can_edit && is_array($current) && !empty($current['key']))
            ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-equipment-unequip="1" data-afcs-equipment-slot="' . htmlspecialchars_uni($slot) . '">Снять</button>'
            : '';
        $slot_rows[] = '<div class="af-cs-slot">'
            . '<div class="af-cs-slot-header">'
            . $current_icon
            . '<div class="af-cs-slot-label">' . htmlspecialchars_uni($label) . '</div>'
            . '</div>'
            . '<div class="af-cs-slot-title">' . htmlspecialchars_uni($current_title) . '</div>'
            . ($unequip !== '' ? '<div class="af-cs-slot-actions">' . $unequip . '</div>' : '')
            . '</div>';
    }

    $available_cards = [];
    foreach ($owned as $item) {
        if (!is_array($item)) {
            continue;
        }
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

        $slot_select = '';
        $equip_btn = '';
        if ($can_edit) {
            $options = '<option value="">— слот —</option>';
            foreach ($slot_labels as $slot => $slotLabel) {
                $options .= '<option value="' . htmlspecialchars_uni($slot) . '">' . htmlspecialchars_uni($slotLabel) . '</option>';
            }
            $slot_select = '<select data-afcs-equipment-slot-select="1">' . $options . '</select>';
            $equip_btn = '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-equipment-equip="1"'
                . ' data-afcs-equipment-type="' . htmlspecialchars_uni($type) . '"'
                . ' data-afcs-equipment-key="' . htmlspecialchars_uni($key) . '">Надеть</button>';
        }

        $available_cards[] = '<div class="af-cs-augment-card">'
            . '<div class="af-cs-augment-card__icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
            . '<div class="af-cs-augment-card__body">'
            . '<div class="af-cs-augment-card__title">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</div>'
            . ($desc !== '' ? '<div class="af-cs-augment-card__desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
            . ($bonus_html !== '' ? '<div class="af-cs-augment-card__effects">' . $bonus_html . '</div>' : '')
            . ($can_edit ? '<div class="af-cs-augment-card__actions">' . $slot_select . $equip_btn . '</div>' : '')
            . '</div>'
            . '</div>';
    }

    if (!$available_cards) {
        $available_cards[] = '<div class="af-cs-muted">Экипировка не куплена.</div>';
    }

    $equipment_html = '<div class="af-cs-augmentations-ui af-cs-equipment-ui">'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-panel-title">Слоты экипировки</div>'
        . '<div class="af-cs-slot-grid">' . implode('', $slot_rows) . '</div>'
        . '</div>'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-panel-title">Доступная экипировка</div>'
        . '<div class="af-cs-augmentations-list">' . implode('', $available_cards) . '</div>'
        . '</div>'
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

        $sheet_url = 'misc.php?action=af_charactersheet&slug=' . rawurlencode($slug);
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
