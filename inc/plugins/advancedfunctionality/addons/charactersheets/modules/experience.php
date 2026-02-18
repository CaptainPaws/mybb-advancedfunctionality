<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_compute_level(float $exp): array
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
        ];
    }

    $level = 1;
    $next_req = $base;
    $remaining = $exp;

    while ($remaining >= $next_req && ($level_cap <= 0 || $level < $level_cap)) {
        $remaining -= $next_req;
        $level++;
        $next_req = $base + (($level - 2) * $step);
        if ($next_req <= 0) {
            break;
        }
    }

    $percent = 0;
    if ($next_req > 0) {
        $percent = (int)floor(($remaining / $next_req) * 100);
        if ($percent < 0) {
            $percent = 0;
        } elseif ($percent > 100) {
            $percent = 100;
        }
    }

    return [
        'level' => $level,
        'percent' => $percent,
        'next_req' => $next_req,
    ];
}

function af_charactersheets_award_exp_manual(array $sheet, array $user, int $fid, string $amount_raw, string $reason): array
{
    global $mybb;

    if (!af_charactersheets_user_can_award_exp($user, $fid)) {
        return ['success' => false, 'error' => 'Permission denied'];
    }

    $amount = af_charactersheets_to_number($amount_raw);
    if ($amount === null || $amount == 0.0) {
        return ['success' => false, 'error' => 'Amount is invalid'];
    }

    if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_negative'])) {
        return ['success' => false, 'error' => 'Negative EXP not allowed'];
    }

    $progress = af_charactersheets_json_decode((string)($sheet['progress_json'] ?? ''));
    if ($amount < 0 && empty($mybb->settings['af_charactersheets_exp_allow_overdraw'])) {
        $current_exp = (float)($progress['exp'] ?? 0);
        if ($current_exp + $amount < 0) {
            return ['success' => false, 'error' => 'Not enough EXP to subtract'];
        }
    }

    $sheet_id = (int)($sheet['id'] ?? 0);
    $event_key = 'manual:' . $sheet_id . ':' . TIME_NOW . ':' . mt_rand(1000, 9999);
    $meta = [
        'reason' => trim($reason),
        'awarded_by' => (int)($user['uid'] ?? 0),
        'awarded_by_username' => (string)($user['username'] ?? ''),
    ];

    if (!af_charactersheets_grant_exp($sheet_id, (float)$amount, $event_key, 'manual', $meta)) {
        return ['success' => false, 'error' => 'EXP update failed'];
    }

    return ['success' => true];
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
    global $mybb;

    $exp_on_accept = (float)($mybb->settings['af_charactersheets_exp_on_accept'] ?? 0);
    if ($exp_on_accept <= 0) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_tid($tid);
    if (empty($sheet)) {
        return;
    }

    af_charactersheets_grant_exp(
        (int)$sheet['id'],
        $exp_on_accept,
        'accept:' . $tid,
        'accept',
        ['tid' => $tid, 'accepted_by' => $accepted_by_uid]
    );
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
    global $mybb;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $exp_on_register = (float)($mybb->settings['af_charactersheets_exp_on_register'] ?? 0);
    if ($exp_on_register <= 0) {
        return;
    }

    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    af_charactersheets_grant_exp(
        (int)$sheet['id'],
        $exp_on_register,
        'register:' . $uid,
        'register',
        ['uid' => $uid]
    );
}

function af_charactersheets_post_do_newpost_end(): void
{
    global $mybb, $db;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    $exp_per_char = (float)($mybb->settings['af_charactersheets_exp_per_char'] ?? 0);
    if ($exp_per_char <= 0) {
        return;
    }

    $pid = (int)($mybb->input['pid'] ?? 0);
    if ($pid <= 0) {
        return;
    }

    $post_row = $db->fetch_array($db->simple_select('posts', '*', 'pid=' . $pid, ['limit' => 1]));
    if (empty($post_row['uid'])) {
        return;
    }

    if ((int)($post_row['visible'] ?? 1) !== 1) {
        return;
    }

    $fid = (int)($post_row['fid'] ?? 0);
    if (!af_charactersheets_is_exp_forum_allowed($fid)) {
        return;
    }

    $uid = (int)($post_row['uid'] ?? 0);
    $sheet = af_charactersheets_get_sheet_by_uid($uid);
    if (empty($sheet)) {
        return;
    }

    $message = (string)($post_row['message'] ?? $mybb->get_input('message'));
    $chars = function_exists('my_strlen') ? my_strlen($message) : strlen($message);
    if ($chars <= 0) {
        return;
    }

    $amount = $chars * $exp_per_char;
    af_charactersheets_grant_exp(
        (int)$sheet['id'],
        $amount,
        'post:' . $pid,
        'post',
        ['pid' => $pid, 'chars' => $chars, 'fid' => $fid]
    );
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
