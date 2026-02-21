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
    $can_staff_reset = af_charactersheets_user_can_staff_reset($mybb->user ?? [], $fid_for_mod);
    $is_staff = af_cs_is_staff($mybb->user ?? [], $fid_for_mod);
    $attributes_locked = !empty($build['attributes_locked']);
    $can_edit_attributes = ($can_edit_sheet && !$attributes_locked) || $is_staff;
    $can_award_exp = af_charactersheets_user_can_award_exp($mybb->user ?? [], $fid_for_mod);
    $can_view_ledger = af_charactersheets_user_can_view_ledger($sheet, $mybb->user ?? [], $fid_for_mod);

    $sheet_title = htmlspecialchars_uni($character_name_en);
    $sheet_subtitle = htmlspecialchars_uni((string)($user['username'] ?? ''));

    $can_delete_sheet = af_charactersheets_user_can_delete_sheet($sheet, $mybb->user ?? []);
    $delete_redirect = $thread_url !== '' ? $thread_url : 'misc.php?action=af_charactersheets';
    $sheet_header_actions_html = af_charactersheets_build_header_actions_html(
        $profile_url,
        $thread_url,
        $uid,
        $tid,
        $can_delete_sheet,
        $delete_redirect,
        $can_award_exp
    );
    $sheet_info_table_html = af_charactersheets_build_info_table_html($atf_index, $sheet_view);
    $sheet_attributes_html = af_charactersheets_build_attributes_html($sheet_view, $can_edit_attributes, $can_view_ledger, $can_staff_reset, $attributes_locked);
    $sheet_bonus_html = af_charactersheets_build_bonus_html($atf_index);
    $skills_locked = !empty($build['locked_skills']);
    $can_manage_skills = $can_manage_sheet && (!$skills_locked || $is_staff);
    $sheet_skills_html = af_charactersheets_build_skills_html($sheet_view, $can_manage_skills, $can_view_ledger, $can_staff_reset, $skills_locked);
    $sheet_knowledge_html = af_charactersheets_build_knowledge_html($sheet_view, $can_edit_sheet, $can_view_ledger);
    $sheet_abilities_html = af_charactersheets_build_abilities_html($build, $can_edit_sheet);
    $sheet_inventory_html = af_charactersheets_build_inventory_html($build, $can_edit_sheet);
    $sheet_augments_html = af_charactersheets_build_augments_html($build, $can_edit_sheet, $sheet_view);
    $sheet_equipment_html = af_charactersheets_build_equipment_html($build, $can_edit_sheet);
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

function af_cs_kb_skill_meta(string $skillKey): array
{
    static $cache = [];

    $skillKey = trim($skillKey);
    if ($skillKey === '') {
        return [
            'key_stat' => '',
            'rank_max' => 5,
            'title_ru' => '',
            'title_en' => '',
        ];
    }

    if (isset($cache[$skillKey])) {
        return $cache[$skillKey];
    }

    $meta = [
        'key_stat' => '',
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

    $rawDataJson = trim((string)($entry['data_json'] ?? $entry['rules_json_raw'] ?? $entry['rules_json'] ?? ''));

    if ($rawDataJson === '' && !empty($entry['id'])) {
        global $db;
        if (is_object($db) && $db->table_exists('af_kb_blocks')) {
            $blockFields = [];
            if ($db->field_exists('data_json', 'af_kb_blocks')) {
                $blockFields[] = 'data_json';
            }
            if ($db->field_exists('content', 'af_kb_blocks')) {
                $blockFields[] = 'content';
            }
            if (!empty($blockFields)) {
                $block = $db->fetch_array($db->simple_select(
                    'af_kb_blocks',
                    implode(',', $blockFields),
                    "entry_id=" . (int)$entry['id'] . " AND block_key='data'" . ($db->field_exists('active', 'af_kb_blocks') ? ' AND active=1' : ''),
                    ['order_by' => 'sortorder,id', 'order_dir' => 'ASC', 'limit' => 1]
                ));
                if (is_array($block)) {
                    $rawDataJson = trim((string)($block['data_json'] ?? $block['content'] ?? ''));
                }
            }
        }
    }

    $decoded = af_charactersheets_json_decode($rawDataJson);
    $skill = is_array($decoded['skill'] ?? null) ? $decoded['skill'] : [];

    $keyStat = strtolower(trim((string)($skill['key_stat'] ?? $skill['attribute'] ?? '')));
    $allowed = af_charactersheets_default_attributes();
    if ($keyStat !== '' && array_key_exists($keyStat, $allowed)) {
        $meta['key_stat'] = $keyStat;
    }

    $rankMax = (int)($skill['rank_max'] ?? 0);
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
    $fixed_sources = ['race' => 'раса', 'class' => 'класс', 'theme' => 'тема', 'race_choice' => 'выбор расы', 'class_choice' => 'выбор класса', 'theme_choice' => 'выбор темы'];
    $rank_names = [
        0 => 'Необученный',
        1 => 'Обученный',
        2 => 'Эксперт',
        3 => 'Мастер',
        4 => 'Легендарный',
        5 => 'Мифический',
    ];

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
    $skill_rank_bonus_map = [0 => 0, 1 => 2, 2 => 5, 3 => 10, 4 => 15, 5 => 20];
    $sheet_attributes = (array)($view['final'] ?? []);

    $items = [];
    $catalog_items = [];

    foreach ($active_grouped as $category => $rows) {
        if (strtolower($category) !== 'general') {
            $items[] = '<div class="af-cs-skill-category">' . htmlspecialchars_uni($category) . '</div>';
        }

        foreach ($rows as $skill) {
            $skill_key = (string)($skill['skill_key'] ?? '');
            $title = (string)($skill['title'] ?? $skill_key);
            $skill_meta = af_cs_kb_skill_meta($skill_key);
            $key_stat = (string)($skill_meta['key_stat'] ?? '');
            $attr_label = (string)($skill_attribute_labels[$key_stat] ?? '');
            $skill_rank = max(0, (int)($skill['skill_rank'] ?? 0));
            $rank_max = max(1, (int)($skill_meta['rank_max'] ?? $skill['rank_max'] ?? 5));
            $source = (string)($skill['source'] ?? 'manual');
            $notes = (string)($skill['notes'] ?? '');
            $rank_bonus = (float)($skill_rank_bonus_map[$skill_rank] ?? 0);
            if (!array_key_exists($skill_rank, $skill_rank_bonus_map) && $skill_rank > 5) {
                $rank_bonus = 20 + (($skill_rank - 5) * 5);
            }
            $sheet_attr_value = (float)($sheet_attributes[$key_stat] ?? 0);
            $total_label = af_charactersheets_format_signed($rank_bonus + $sheet_attr_value);
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

            $items[] = '<div class="af-cs-skill-item">'
                . '<div class="af-cs-skill-left">'
                . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title) . $build_attr_label($attr_label) . '</div>'
                . ($notes !== '' ? '<div class="af-cs-muted">' . htmlspecialchars_uni($notes) . '</div>' : '')
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
            $skill_meta = af_cs_kb_skill_meta($skill_key);
            $key_stat = (string)($skill_meta['key_stat'] ?? '');
            $attr_label = (string)($skill_attribute_labels[$key_stat] ?? '');
            $skill_rank = max(0, (int)($skill['skill_rank'] ?? 0));
            $rank_max = max(1, (int)($skill_meta['rank_max'] ?? $skill['rank_max'] ?? 5));
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

            $catalog_items[] = '<div class="af-cs-skill-catalog-item">'
                . '<div class="af-cs-skill-catalog-item__main">'
                . '<div class="af-cs-skill-name">' . htmlspecialchars_uni($title) . $build_attr_label($attr_label) . '</div>'
                . ($notes !== '' ? '<div class="af-cs-muted">' . htmlspecialchars_uni($notes) . '</div>' : '')
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
    int $uid,
    int $tid,
    bool $can_delete,
    string $delete_redirect,
    bool $can_award
): string
{
    $items = [];

    if ($profile_url !== '' && $uid > 0) {
        $modalProfileUrl = 'misc.php?action=cs_modal_profile&uid=' . $uid;
        $items[] = '<a class="af-cs-btn af-cs-btn--compact" href="' . htmlspecialchars_uni($modalProfileUrl)
            . '" data-afcs-open="1" data-afcs-sheet="' . htmlspecialchars_uni($modalProfileUrl)
            . '" title="Профиль" aria-label="Профиль"><i class="fa-regular fa-user"></i></a>';
    }

    if ($thread_url !== '' && $tid > 0) {
        $modalAppUrl = 'misc.php?action=cs_modal_application&tid=' . $tid;
        $items[] = '<a class="af-cs-btn af-cs-btn--compact" href="' . htmlspecialchars_uni($modalAppUrl)
            . '" data-afcs-open="1" data-afcs-sheet="' . htmlspecialchars_uni($modalAppUrl)
            . '" title="Анкета" aria-label="Анкета"><i class="fa-regular fa-id-card"></i></a>';
    }

    if ($uid > 0) {
        $modalInventoryUrl = 'misc.php?action=inventory&uid=' . $uid . '&ajax=1';
        $items[] = '<a class="af-cs-btn af-cs-btn--compact" href="' . htmlspecialchars_uni($modalInventoryUrl)
            . '" data-afcs-open="1" data-afcs-sheet="' . htmlspecialchars_uni($modalInventoryUrl)
            . '" title="Инвентарь" aria-label="Инвентарь"><i class="fa-solid fa-box-open"></i></a>';
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

        $value = trim((string)($grant['value'] ?? ''));
        $entry = af_charactersheets_resolve_effect_kb_entry($op, $key);
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        if ($title === '') {
            $title = ucfirst(str_replace('_', ' ', $key));
        }

        $label = $title;
        if ($op === 'resistance') {
            $label .= ' res';
        }
        if ($value !== '') {
            $label .= ' ' . $value;
        }

        $hint = af_charactersheets_kb_pick_text($entry, 'short');
        if ($hint === '') {
            $hint = af_charactersheets_kb_pick_text($entry, 'description');
        }
        if ($hint !== '') {
            $tooltip = $title . "\n" . trim(strip_tags($hint));
        } else {
            $tooltip = $label;
        }

        $chips[] = '<span class="af-cs-chip" title="' . htmlspecialchars_uni($tooltip) . '">' . htmlspecialchars_uni($label) . '</span>';
    }

    if (!$chips) {
        return '<span class="af-cs-muted">—</span>';
    }

    return implode('', $chips);
}

function af_charactersheets_build_info_table_html(array $index, array $sheet_view = []): string
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
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Эффекты</div><div class="af-cs-info-value">' . af_charactersheets_build_effects_chip_html($sheet_view) . '</div></div>';
    $wallet_raw = (int)($sheet_view['credits'] ?? 0);
    $wallet = function_exists('af_balance_format_credits') ? af_balance_format_credits($wallet_raw) : number_format($wallet_raw / 100, 2, '.', ' ');
    $items[] = '<div class="af-cs-info-row"><div class="af-cs-info-label">Кошелёк</div><div class="af-cs-info-value" data-afcs-wallet-value>' . htmlspecialchars_uni($wallet) . ' ¢</div></div>';

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
        $title = af_charactersheets_kb_pick_text($entry, 'title');
        if ($title === '') {
            $title = (string)$data['label'];
        }

        $text_html = af_cs_render_kb_bonuses_text((string)$data['type'], $key, af_charactersheets_is_ru());
        if ($text_html === '') {
            $text_html = '<div class="af-cs-muted">Нет данных</div>';
        }

        $columns[] = '<div class="af-cs-bonus-card">'
            . '<div class="af-cs-bonus-title">' . htmlspecialchars_uni($title) . '</div>'
            . '<div class="af-cs-bonus-body">' . $text_html . '</div>'
            . '</div>';
    }

    if (!$columns) {
        return '';
    }

    return '<div class="af-cs-bonus-grid">' . implode('', $columns) . '</div>';
}

function af_charactersheets_lang(string $key, string $fallback = ''): string
{
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;
        if (function_exists('af_charactersheets_load_lang')) {
            af_charactersheets_load_lang();
        }
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
    foreach (['race', 'class', 'theme'] as $src) {
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
        . ' extra.fixed_bonuses.hp=' . (int)($fixed_hp_breakdown['extra'] ?? 0)
        . ' con=' . (int)($debug['con_final'] ?? 0)
        . ' hp_total=' . (int)($debug['hp_total'] ?? 0);

    $debug_lines[] = 'TOTAL: hp_base_total=' . (int)($debug['hp_base_total'] ?? 0)
        . ' fixed_hp_total=' . (int)($debug['fixed_hp_total'] ?? 0)
        . ' speed_total=' . (int)($debug['speed_total'] ?? 0)
        . ' bonus_attribute_points=' . (int)($debug['bonus_attribute_points'] ?? 0)
        . ' bonus_skill_points=' . (int)($debug['bonus_skill_points'] ?? 0);

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
        . ' hp_fixed=' . (int)($debug['fixed_hp_total'] ?? 0)
        . ' con=' . (int)($debug['con_final'] ?? 0)
        . ' AC=' . $ac_total
        . ' speed=' . $speed_total
        . ' -->';

    return '<div class="af-cs-mechanics-grid">' . implode('', $cards) . '</div>' . $debug_comment . $debug_line;
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
        'augmentations' => 'Аугментации',
    ];
    $category_keys = array_keys($category_labels);
    $categories = array_fill_keys($category_keys, []);

    $cards = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = af_charactersheets_get_inventory_item_type($item);
        $key = af_charactersheets_get_inventory_item_key($item);
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
                . ' data-afcs-item-action="' . htmlspecialchars_uni($card['equip_action']) . '"'
                . ' title="' . htmlspecialchars_uni($card['title']) . '">'
                . '<span class="af-cs-slot-icon">' . $card['icon'] . '</span>'
                . '<span class="af-cs-slot-qty">' . htmlspecialchars_uni((string)$card['qty']) . '</span>'
                . '<span class="af-cs-slot-name">' . htmlspecialchars_uni($card['title']) . '</span>'
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

function af_charactersheets_build_augments_html(array $build, bool $can_edit, array $view = []): string
{
    $augmentations = (array)($build['augmentations'] ?? []);
    $slots = (array)($augmentations['slots'] ?? []);
    $owned = (array)($augmentations['owned'] ?? []);

    $slot_configs = af_charactersheets_get_augmentation_slots();
    $slot_rows = [];
    foreach ($slot_configs as $slot => $config) {
        $slot_title = (string)($config['title'] ?? $slot);
        $current = $slots[$slot] ?? null;
        $slot_items = af_charactersheets_normalize_slot_items($current);

        $slot_items_html = '';
        if ($slot_items) {
            $item_lines = [];
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
                $humanity_cost = af_charactersheets_extract_humanity_cost_from_entry($entry);
                $humanity_label = $humanity_cost > 0
                    ? '<span class="af-cs-slot-humanity">−' . htmlspecialchars_uni((string)$humanity_cost) . ' человечности</span>'
                    : '';
                $unequip = ($can_edit && $item_key !== '')
                    ? '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-augmentation-unequip="1"'
                        . ' data-afcs-augmentation-slot="' . htmlspecialchars_uni($slot) . '"'
                        . ' data-afcs-augmentation-key="' . htmlspecialchars_uni($item_key) . '">Снять</button>'
                    : '';
                $item_lines[] = '<div class="af-cs-slot-item">'
                    . '<span class="af-cs-slot-icon">' . af_charactersheets_render_kb_icon($entry, $item_title) . '</span>'
                    . '<div class="af-cs-slot-item__body">'
                    . '<div class="af-cs-slot-title">' . htmlspecialchars_uni($item_title) . '</div>'
                    . $humanity_label
                    . '</div>'
                    . ($unequip !== '' ? '<div class="af-cs-slot-actions">' . $unequip . '</div>' : '')
                    . '</div>';
            }
            $slot_items_html = $item_lines ? '<div class="af-cs-slot-items">' . implode('', $item_lines) . '</div>' : '';
        }

        if ($slot_items_html === '') {
            $slot_items_html = '<div class="af-cs-slot-title">—</div>';
        }

        $slot_rows[] = '<div class="af-cs-slot">'
            . '<div class="af-cs-slot-header">'
            . af_charactersheets_render_slot_icon($config)
            . '<div class="af-cs-slot-label">' . htmlspecialchars_uni($slot_title) . '</div>'
            . '</div>'
            . $slot_items_html
            . '</div>';
    }

    $inventory = (array)($build['inventory'] ?? []);
    $inventory_items = (array)($inventory['items'] ?? []);
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

    $available_cards = [];
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
        $allowed_slots = af_charactersheets_pick_augmentation_slots($entry);
        $slot_options = $allowed_slots ?: array_keys($slot_configs);
        $humanity_cost = af_charactersheets_extract_humanity_cost_from_entry($entry);

        $slot_select = '';
        $equip_btn = '';
        $slot_default_attr = '';
        if ($can_edit) {
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
        }

        $available_cards[] = '<div class="af-cs-augment-card">'
            . '<div class="af-cs-augment-card__icon">' . af_charactersheets_render_kb_icon($entry, $title) . '</div>'
            . '<div class="af-cs-augment-card__body">'
            . '<div class="af-cs-augment-card__title">' . htmlspecialchars_uni($title !== '' ? $title : $key) . '</div>'
            . ($desc !== '' ? '<div class="af-cs-augment-card__desc">' . htmlspecialchars_uni($desc) . '</div>' : '')
            . ($humanity_cost > 0 ? '<div class="af-cs-augment-card__humanity">Стоимость человечности: ' . htmlspecialchars_uni((string)$humanity_cost) . '</div>' : '')
            . ($bonus_html !== '' ? '<div class="af-cs-augment-card__effects">' . $bonus_html . '</div>' : '')
            . ($can_edit ? '<div class="af-cs-augment-card__actions">' . $slot_select . $equip_btn . '</div>' : '')
            . '</div>'
            . '</div>';
    }

    if (!$available_cards) {
        $available_cards[] = '<div class="af-cs-muted">Аугментации не куплены.</div>';
    }

    $humanity_total = (int)($view['mechanics']['humanity_total'] ?? 0);
    $humanity_base = (int)($view['mechanics']['humanity_breakdown']['base'] ?? 100);
    if ($humanity_base <= 0) {
        $humanity_base = 100;
    }
    $humanity_percent = (int)round(max(0, min(100, ($humanity_total / $humanity_base) * 100)));
    $humanity_html = '<div class="af-cs-augmentation-humanity-block">'
        . '<div class="af-cs-augmentation-humanity">'
        . '<span>Человечность</span>'
        . '<strong>' . htmlspecialchars_uni((string)$humanity_total) . '%</strong>'
        . '</div>'
        . '<div class="af-cs-progress__bar"><span style="width:' . htmlspecialchars_uni((string)$humanity_percent) . '%"></span></div>'
        . '</div>';

    $augmentations_html = '<div class="af-cs-augmentations-ui">'
        . '<div class="af-cs-augmentations-column">'
        . '<div class="af-cs-panel-title">Слоты аугментаций</div>'
        . $humanity_html
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
