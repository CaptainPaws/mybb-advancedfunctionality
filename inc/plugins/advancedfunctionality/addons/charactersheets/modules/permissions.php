<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_charactersheets_user_is_admin_or_moderator(array $user, int $fid = 0): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if (!empty($user['cancp']) || !empty($mybb->usergroup['cancp'])) {
        return true;
    }

    if (!empty($user['issupermod']) || !empty($mybb->usergroup['issupermod'])) {
        return true;
    }

    if (!empty($user['canmodcp']) || !empty($mybb->usergroup['canmodcp'])) {
        return true;
    }

    if ($fid > 0 && function_exists('is_moderator')) {
        if (is_moderator($fid)) {
            return true;
        }
    }

    return false;
}

function af_charactersheets_user_can_award_exp(array $user, int $fid = 0): bool
{
    return af_charactersheets_user_is_admin_or_moderator($user, $fid);
}

function af_charactersheets_user_can_view_ledger(array $sheet, array $user, int $fid = 0): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if ((int)($sheet['uid'] ?? 0) === $uid) {
        return true;
    }

    if (!empty($mybb->usergroup['cancp']) || !empty($user['cancp'])) {
        return true;
    }
    if (!empty($mybb->usergroup['issupermod']) || !empty($user['issupermod'])) {
        return true;
    }
    if (!empty($mybb->usergroup['canmodcp']) || !empty($user['canmodcp'])) {
        return true;
    }

    if ($fid > 0 && function_exists('is_moderator')) {
        if (is_moderator($fid)) {
            return true;
        }
    }

    return false;
}

function af_charactersheets_user_can_view_pools(array $sheet, array $user, int $fid = 0): bool
{
    return af_charactersheets_user_can_view_ledger($sheet, $user, $fid);
}

function af_charactersheets_user_can_edit_sheet(array $sheet, array $user): bool
{
    global $mybb;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if ((int)($sheet['uid'] ?? 0) === $uid) {
        return true;
    }

    $is_admin = !empty($mybb->usergroup['cancp']) || !empty($user['cancp']);
    $is_modcp = !empty($mybb->usergroup['canmodcp']) || !empty($user['canmodcp']);
    $is_supermod = !empty($user['issupermod']);

    return $is_admin || $is_modcp || $is_supermod;
}

function af_cs_can_manage_sheet(int $viewerUid, int $sheetUid): bool
{
    global $mybb;

    if ($viewerUid <= 0) {
        return false;
    }

    if ($viewerUid === $sheetUid) {
        return true;
    }

    $is_admin = !empty($mybb->usergroup['cancp']);
    $is_modcp = !empty($mybb->usergroup['canmodcp']);
    $is_supermod = !empty($mybb->user['issupermod']);

    return $is_admin || $is_modcp || $is_supermod;
}

function af_charactersheets_user_can_accept(array $user, int $fid): bool
{
    global $mybb;

    if (empty($mybb->settings['af_charactersheets_accept_groups'])) {
        return false;
    }

    $groups = af_charactersheets_csv_to_ids($mybb->settings['af_charactersheets_accept_groups']);
    if (empty($groups)) {
        return false;
    }

    $primary = (int)($user['usergroup'] ?? 0);
    if ($primary > 0 && in_array($primary, $groups, true)) {
        return true;
    }

    $additional = array_filter(array_map('intval', explode(',', (string)($user['additionalgroups'] ?? ''))));
    foreach ($additional as $gid) {
        if (in_array($gid, $groups, true)) {
            return true;
        }
    }

    if ($fid > 0 && function_exists('is_moderator')) {
        if (is_moderator($fid)) {
            return true;
        }
    }

    return false;
}

function af_charactersheets_user_can_delete_sheet(array $sheet, array $user): bool
{
    global $mybb, $db;

    $uid = (int)($user['uid'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    if (!empty($mybb->usergroup['cancp']) || !empty($user['cancp'])) {
        return true;
    }
    if (!empty($mybb->usergroup['issupermod']) || !empty($user['issupermod'])) {
        return true;
    }
    if (!empty($mybb->usergroup['canmodcp']) || !empty($user['canmodcp'])) {
        return true;
    }

    $tid = (int)($sheet['tid'] ?? 0);
    if ($tid > 0 && function_exists('is_moderator')) {
        $fid = (int)$db->fetch_field(
            $db->simple_select('threads', 'fid', 'tid=' . $tid, ['limit' => 1]),
            'fid'
        );
        if ($fid > 0 && is_moderator($fid)) {
            return true;
        }
    }

    return false;
}
