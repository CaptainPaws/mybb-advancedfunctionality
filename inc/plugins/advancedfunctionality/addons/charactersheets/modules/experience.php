<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_compute_level_legacy(float $exp): array
{
    global $mybb;

    $level_cap = (int)($mybb->settings['af_charactersheets_level_cap'] ?? 0);
    $base = (float)($mybb->settings['af_charactersheets_level_req_base'] ?? 0);
    $step = (float)($mybb->settings['af_charactersheets_level_req_step'] ?? 0);

    if ($base <= 0) {
        return [
            'level' => 1,
            'percent' => 0,
            'next_req' => 0,
            'prev_req_total' => 0,
            'next_req_total' => 0,
            'exp_in_level' => 0,
            'exp_need' => 0,
            'exp_current' => 0,
            'progress_percent' => 0,
            'cap' => max(1, $level_cap),
        ];
    }

    $level = 1;
    $next_req = $base;
    $remaining = $exp;
    $prev_req_total = 0.0;

    while ($remaining >= $next_req && ($level_cap <= 0 || $level < $level_cap)) {
        $remaining -= $next_req;
        $prev_req_total += $next_req;
        $level++;
        $next_req = $base + (($level - 1) * $step);
        if ($next_req <= 0) {
            break;
        }
    }

    $next_req_total = $prev_req_total + max(0.0, $next_req);
    $exp_in_level = max(0.0, $exp - $prev_req_total);
    $exp_need = max(0.0, $next_req_total - $prev_req_total);

    $percent = 0;
    if ($exp_need > 0) {
        $percent = (int)floor(($exp_in_level / $exp_need) * 100);
        if ($percent < 0) {
            $percent = 0;
        } elseif ($percent > 100) {
            $percent = 100;
        }
    }

    return [
        'level' => $level,
        'percent' => $percent,
        'progress_percent' => $percent,
        'next_req' => $next_req,
        'prev_req_total' => $prev_req_total,
        'next_req_total' => $next_req_total,
        'exp_in_level' => $exp_in_level,
        'exp_current' => $exp_in_level,
        'exp_need' => $exp_need,
        'cap' => max(1, $level_cap),
    ];
}

function af_charactersheets_compute_level(float $exp): array
{
    if (function_exists('af_balance_compute_level')) {
        $computed = af_balance_compute_level($exp);
        if (is_array($computed)) {
            $percent = (int)($computed['progress_percent'] ?? $computed['percent'] ?? 0);
            $expCurrent = (float)($computed['exp_current'] ?? $computed['exp_in_level'] ?? 0);
            $expNeed = (float)($computed['exp_need'] ?? $computed['next_req'] ?? 0);
            $prevReqTotal = (float)($computed['prev_req_total'] ?? 0);
            $nextReqTotal = (float)($computed['next_req_total'] ?? ($prevReqTotal + $expNeed));

            return [
                'level' => (int)($computed['level'] ?? 1),
                'cap' => (int)($computed['cap'] ?? 1),
                'percent' => $percent,
                'progress_percent' => $percent,
                'next_req' => $expNeed,
                'prev_req_total' => $prevReqTotal,
                'next_req_total' => $nextReqTotal,
                'exp_in_level' => $expCurrent,
                'exp_current' => $expCurrent,
                'exp_need' => $expNeed,
            ];
        }
    }

    return af_charactersheets_compute_level_legacy($exp);
}

function af_charactersheets_award_exp_manual(array $sheet, array $user, int $fid, string $amount_raw, string $reason): array
{
    return ['success' => false, 'error' => 'Manual EXP adjust moved to balance_manage'];
}


function af_charactersheets_balance_snapshot(int $uid): array
{
    $uid = (int)$uid;
    $balance = function_exists('af_balance_get') ? af_balance_get($uid) : ['exp' => 0, 'credits' => 0, 'ability_tokens' => 0];
    $exp_total = ((float)($balance['exp'] ?? 0)) / (defined('AF_BALANCE_EXP_SCALE') ? AF_BALANCE_EXP_SCALE : 100);
    $level_data = af_charactersheets_compute_level($exp_total);

    return [
        'exp' => $exp_total,
        'exp_display' => number_format($level_data['exp_in_level'] ?? 0, 2, '.', ' '),
        'exp_next_display' => number_format($level_data['exp_need'] ?? 0, 2, '.', ' '),
        'exp_need' => (float)($level_data['exp_need'] ?? 0),
        'level' => (int)($level_data['level'] ?? 1),
        'progress_percent' => (int)($level_data['progress_percent'] ?? $level_data['percent'] ?? 0),
        'credits_display' => function_exists('af_balance_format_credits') ? af_balance_format_credits((int)($balance['credits'] ?? 0)) : number_format(((int)($balance['credits'] ?? 0))/100, 2, '.', ' '),
        'ability_tokens_display' => function_exists('af_balance_format_ability_tokens') ? af_balance_format_ability_tokens((int)($balance['ability_tokens'] ?? 0)) : number_format(((int)($balance['ability_tokens'] ?? 0))/100, 2, '.', ' '),
        'ability_tokens_symbol' => (string)($GLOBALS['mybb']->settings['af_balance_ability_tokens_symbol'] ?? '♦'),
    ];
}

function af_charactersheets_grant_exp(int $sheet_id, float $amount, string $event_key, string $event_type, array $meta): bool
{
    global $db, $mybb;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_SHEETS_TABLE) || !$db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        return false;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $current_exp = (float)($progress['exp'] ?? 0);
    $new_exp = $current_exp + $amount;

    if ($new_exp < 0 && empty($mybb->settings['af_charactersheets_exp_allow_overdraw'])) {
        return false;
    }

    $progress['exp'] = $new_exp;

    $level_data = af_charactersheets_compute_level($new_exp);
    $prev_level = (int)($progress['level'] ?? 1);
    $progress['level'] = $level_data['level'];

    $delta = $level_data['level'] - $prev_level;
    if ($delta > 0) {
        $skill_points_per_level = (int)($mybb->settings['af_charactersheets_skill_points_per_level'] ?? 0);
        $progress['skill_points_free'] = (int)($progress['skill_points_free'] ?? 0) + $delta * $skill_points_per_level;
    }

    // --- ledger meta normalize (reason + awarded by) ---
    if (!isset($meta['reason'])) {
        $meta['reason'] = '';
    } else {
        $meta['reason'] = trim((string)$meta['reason']);
    }

    // "Кто начислил" имеет смысл прежде всего для ручных/админских начислений.
    // Если бэк при grant_exp не положил by_* сам — добавим.
    if (!isset($meta['by_uid']) && !isset($meta['by_username'])) {
        $actor_uid = (int)($mybb->user['uid'] ?? 0);

        // считаем "ручным", если event_type явно manual/award/grant/admin/moderator
        $manual_types = ['manual', 'award', 'grant', 'grant_exp', 'admin', 'moderator'];
        $is_manual = in_array($event_type, $manual_types, true);

        if ($is_manual && $actor_uid > 0) {
            $meta['by_uid'] = $actor_uid;
            $meta['by_username'] = (string)($mybb->user['username'] ?? '');
        } else {
            // автособытия (посты/регистрация) — можно показывать как "Система"
            $meta['by_uid'] = 0;
            $meta['by_username'] = 'Система';
        }
    }

    $db->insert_query(AF_CS_EXP_LEDGER_TABLE, [
        'sheet_id' => $sheet_id,
        'uid' => (int)($sheet['uid'] ?? 0),
        'event_key' => $db->escape_string($event_key),
        'event_type' => $db->escape_string($event_type),
        'amount' => $amount,
        'meta_json' => $db->escape_string(af_charactersheets_json_encode($meta)),
        'created_at' => TIME_NOW,
    ]);

    af_charactersheets_update_sheet_json($sheet_id, af_charactersheets_json_decode((string)($sheet['base_json'] ?? '')), af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')), $progress);

    return true;
}

function af_charactersheets_handle_accept_exp(int $tid, int $accepted_by_uid): void
{
    if (!function_exists('af_balance_handle_accept')) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_tid($tid);
    if (empty($sheet)) {
        return;
    }

    af_balance_handle_accept((int)($sheet['uid'] ?? 0), $tid, $accepted_by_uid);
}

function af_charactersheets_log_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): void
{
    global $db;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_POINTS_LEDGER_TABLE)) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return;
    }

    $db->insert_query(AF_CS_POINTS_LEDGER_TABLE, [
        'sheet_id' => $sheet_id,
        'uid' => (int)($sheet['uid'] ?? 0),
        'point_type' => $db->escape_string($type),
        'amount' => $amount,
        'reason' => $db->escape_string($reason),
        'meta_json' => $db->escape_string(af_charactersheets_json_encode($meta)),
        'created_at' => TIME_NOW,
    ]);
}

function af_charactersheets_add_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): bool
{
    if ($amount <= 0) {
        return false;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $key = $type === 'skill' ? 'skill_points_free' : 'attr_points_free';
    $progress[$key] = (int)($progress[$key] ?? 0) + $amount;

    af_charactersheets_update_sheet_json($sheet_id, af_charactersheets_json_decode((string)($sheet['base_json'] ?? '')), af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')), $progress);
    af_charactersheets_log_points($sheet_id, $type, $amount, $reason, $meta);

    return true;
}

function af_charactersheets_spend_points(int $sheet_id, string $type, int $amount, string $reason, array $meta = []): bool
{
    if ($amount <= 0) {
        return false;
    }

    $sheet = af_charactersheets_get_sheet_by_id($sheet_id);
    if (empty($sheet)) {
        return false;
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $key = $type === 'skill' ? 'skill_points_free' : 'attr_points_free';
    $current = (int)($progress[$key] ?? 0);
    if ($current < $amount) {
        return false;
    }

    $progress[$key] = $current - $amount;
    af_charactersheets_update_sheet_json($sheet_id, af_charactersheets_json_decode((string)($sheet['base_json'] ?? '')), af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')), $progress);
    af_charactersheets_log_points($sheet_id, $type, -$amount, $reason, $meta);

    return true;
}

function af_charactersheets_member_do_register_end(): void
{
    // Moved to Balance addon.
}

function af_charactersheets_post_do_newpost_end(): void
{
    // Moved to Balance addon.
}

function af_charactersheets_is_exp_forum_allowed(int $fid): bool
{
    global $mybb;

    if ($fid <= 0) {
        return false;
    }

    $mode = (string)($mybb->settings['af_charactersheets_exp_forum_mode'] ?? 'include');
    $category_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_categories'] ?? ''));
    $forum_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_forums'] ?? ''));
    $exclude_ids = af_charactersheets_csv_to_ids((string)($mybb->settings['af_charactersheets_exp_forum_exclude'] ?? ''));
    if ($exclude_ids && in_array($fid, $exclude_ids, true)) {
        return false;
    }

    $selected = array_values(array_unique(array_merge(
        $forum_ids,
        af_charactersheets_expand_forum_ids_with_children($category_ids)
    )));

    if ($mode === 'exclude') {
        if (!$selected) {
            return true;
        }
        return !in_array($fid, $selected, true);
    }

    if (!$selected) {
        return true;
    }
    return in_array($fid, $selected, true);
}

function af_charactersheets_render_exp_ledger_html(int $sheet_id, int $limit = 30): string
{
    global $db, $lang;

    if ($sheet_id <= 0 || !$db->table_exists(AF_CS_EXP_LEDGER_TABLE)) {
        return '';
    }

    $rows = [];
    $query = $db->simple_select(
        AF_CS_EXP_LEDGER_TABLE,
        '*',
        "sheet_id=".(int)$sheet_id,
        ['order_by' => 'created_at', 'order_dir' => 'DESC', 'limit' => (int)$limit]
    );

    while ($r = $db->fetch_array($query)) {
        $meta = af_charactersheets_json_decode((string)($r['meta_json'] ?? ''));
        if (!is_array($meta)) $meta = [];

        $reason = trim((string)($meta['reason'] ?? ''));
        if ($reason === '') {
            // фолбэк — если причины нет (старые записи/автонаграды)
            $reason = (string)($r['event_type'] ?? $r['event_key'] ?? '');
        }

        $by = trim((string)($meta['by_username'] ?? ''));
        if ($by === '') $by = '—';

        $ts = (int)($r['created_at'] ?? 0);
        $date = $ts > 0 ? my_date('d.m.Y', $ts) : '—';
        $time = $ts > 0 ? my_date('H:i', $ts) : '—';

        $amount = (float)($r['amount'] ?? 0);
        $amount_label = ($amount > 0 ? '+' : '') . (string)(int)$amount;

        $rows[] = [
            'reason' => htmlspecialchars_uni($reason),
            'amount' => htmlspecialchars_uni($amount_label),
            'date'   => htmlspecialchars_uni($date),
            'time'   => htmlspecialchars_uni($time),
            'by'     => htmlspecialchars_uni($by),
        ];
    }

    if (!$rows) {
        return '<div class="af-cs-ledger" data-afcs-ledger hidden><div class="af-cs-ledger__empty">История пуста.</div></div>';
    }

    $html = '<div class="af-cs-ledger" data-afcs-ledger hidden>';
    $html .= '<table class="tborder af-cs-ledger__table" cellspacing="0" cellpadding="6" border="0">';
    $html .= '<thead><tr>';
    $html .= '<th>Причина</th>';
    $html .= '<th style="width:110px;">Значение</th>';
    $html .= '<th style="width:110px;">Дата</th>';
    $html .= '<th style="width:80px;">Время</th>';
    $html .= '<th style="width:180px;">Кто начислил</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td>'.$row['reason'].'</td>';
        $html .= '<td style="white-space:nowrap;">'.$row['amount'].'</td>';
        $html .= '<td style="white-space:nowrap;">'.$row['date'].'</td>';
        $html .= '<td style="white-space:nowrap;">'.$row['time'].'</td>';
        $html .= '<td>'.$row['by'].'</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    return $html;
}


function af_charactersheets_on_exp_changed($uid, $oldExp, $newExp, $meta): void
{
    $uid = (int)$uid;
    if ($uid <= 0) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    $oldFloat = ((float)$oldExp) / (defined('AF_BALANCE_EXP_SCALE') ? AF_BALANCE_EXP_SCALE : 100);
    $newFloat = ((float)$newExp) / (defined('AF_BALANCE_EXP_SCALE') ? AF_BALANCE_EXP_SCALE : 100);
    $progress['exp'] = $newFloat;

    $level_data = af_charactersheets_compute_level($newFloat);
    $prev_level = (int)($progress['level'] ?? 1);
    $progress['level'] = (int)$level_data['level'];

    $delta = (int)$level_data['level'] - $prev_level;
    if ($delta > 0) {
        global $mybb;
        $skill_points_per_level = (int)($mybb->settings['af_charactersheets_skill_points_per_level'] ?? 0);
        $progress['skill_points_free'] = (int)($progress['skill_points_free'] ?? 0) + $delta * $skill_points_per_level;
    }

    af_charactersheets_update_sheet_json(
        (int)$sheet['id'],
        af_charactersheets_json_decode((string)($sheet['base_json'] ?? '')),
        af_charactersheets_json_decode((string)($sheet['build_json'] ?? '')),
        $progress
    );
}
