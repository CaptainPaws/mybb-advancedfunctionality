<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

function af_advancedcombatsystem_atf_access(): AF_ACS_SessionAccess
{
    require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
    return new AF_ACS_SessionAccess;
}

function af_advancedcombatsystem_atf_can_create(int $fid): bool
{
    return af_advancedcombatsystem_atf_create_blocker($fid) === null;
}

function af_advancedcombatsystem_atf_create_blocker(int $fid): ?string
{
    global $mybb;
    if (!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated'])) { return 'disabled'; }
    if (empty($mybb->user['uid'])) { return 'permission'; }
    try {
        $access = af_advancedcombatsystem_atf_access();
        $permissions = forum_permissions($fid, (int)$access->uid());
        $forum = get_forum($fid);
        if (!$forum || $forum['type'] !== 'f' || empty($forum['open']) || !empty($forum['linkto'])
            || empty($permissions['canview']) || empty($permissions['canviewthreads'])
            || empty($permissions['canpostthreads']) || !empty($mybb->user['suspendposting'])) { return 'native'; }
        if (!$access->canCreate()) { return 'permission'; }
        return af_advancedcombatsystem_is_installed() ? null : 'schema';
    } catch (Throwable $error) { return 'schema'; }
}

function af_advancedcombatsystem_atf_mode_repository(): AF_ACS_EpisodeModeRepository
{
    global $db;
    require_once __DIR__.'/../Infrastructure/EpisodeModeRepository.php';
    return new AF_ACS_EpisodeModeRepository($db);
}

function af_advancedcombatsystem_atf_mode_permission(array $thread, array $state, string $requested, AF_ACS_SessionAccess $access): ?string
{
    $actor = $access->uid();
    $fid = (int)$thread['fid'];
    $permissions = forum_permissions($fid, (int)$actor);
    if ((int)$thread['visible'] !== 1) { return 'ACS_EPISODE_UNPUBLISHED'; }
    if (!is_moderator($fid, 'caneditposts', (int)$actor)
        && ((string)$thread['uid'] !== $actor || empty($permissions['caneditposts']))) {
        return 'ACS_EPISODE_EDIT_REQUIRED';
    }
    if ($requested === 'combat' && !$state['sessions']
        && (!function_exists('af_atf_acs_forum_enabled') || !af_atf_acs_forum_enabled($fid))) {
        return 'ACS_EPISODE_FORUM_DISABLED';
    }
    if (!$state['sessions'] && !af_advancedcombatsystem_atf_can_create($fid)) { return 'ACS_CREATE_DENIED'; }
    return null;
}

function af_advancedcombatsystem_atf_edit_state(int $tid): ?array
{
    if (!af_is_addon_enabled('advancedcombatsystem')) { return null; }
    $access = af_advancedcombatsystem_atf_access();
    $thread = $access->thread((string)$tid);
    $repo = af_advancedcombatsystem_atf_mode_repository();
    $state = $repo->modeState((string)$tid);
    $target = $state['mode'] === 'combat' ? 'normal' : 'combat';
    $blocker = af_advancedcombatsystem_atf_mode_permission($thread, $state, $target, $access)
        ?? $repo->canChangeEpisodeMode($state, $target, $access->uid());
    return $state + ['session'=>$repo->atfTopicStatus((string)$tid, $access->uid()), 'blocker'=>$blocker];
}

function af_advancedcombatsystem_atf_change_mode(string $tid, int $pid, string $mode, int $version): void
{
    $access = af_advancedcombatsystem_atf_access();
    $access->csrf();
    if (!in_array($mode, ['normal','combat'], true)) { throw new RuntimeException('ACS_EPISODE_MODE_INVALID'); }
    $repo = af_advancedcombatsystem_atf_mode_repository();
    $state = $repo->changeEpisodeMode($tid, $mode, $version, $access->uid(), static function (array $state) use ($repo,$access,$tid,$pid,$mode) {
        get_thread((int)$tid, true);
        $visible = $access->thread($tid);
        $thread = $repo->episodeNativeThread($tid);
        if (!$thread || (int)$thread['fid'] !== (int)$visible['fid'] || (int)$thread['firstpost'] !== $pid) { throw new RuntimeException('ACS_EPISODE_EDIT_REQUIRED'); }
        $reason = af_advancedcombatsystem_atf_mode_permission($thread, $state, $mode, $access);
        if ($reason !== null) { throw new RuntimeException($reason); }
    });
    if ($mode === 'combat' && !$state['sessions']) { af_advancedcombatsystem_atf_create($tid); }
}

function af_advancedcombatsystem_atf_seed_mode(string $tid, int $fid, string $mode): void
{
    $access = af_advancedcombatsystem_atf_access();
    $access->csrf();
    $repo = af_advancedcombatsystem_atf_mode_repository();
    $actor = $access->uid();
    if (!$repo->episodeModeStorageAvailable()) { return; }
    $repo->changeEpisodeMode($tid, $mode, 0, $actor, static function (array $state) use ($repo,$actor,$tid,$fid) {
        $thread = $repo->episodeNativeThread($tid);
        if (!$thread || (string)$thread['uid'] !== $actor || (int)$thread['fid'] !== $fid
            || !in_array((int)$thread['visible'], [0,1], true) || (int)$thread['firstpost'] <= 0
            || $state['sessions'] || !af_atf_acs_forum_enabled($fid)) {
            throw new RuntimeException('ACS_EPISODE_EDIT_REQUIRED');
        }
    });
}

function af_advancedcombatsystem_atf_can_open(int $tid): bool
{
    try { af_advancedcombatsystem_atf_access()->thread((string)$tid); return true; }
    catch (Throwable $error) { return false; }
}

function af_advancedcombatsystem_atf_create(string $tid): array
{
    $access = af_advancedcombatsystem_atf_access();
    $access->csrf();
    // A draft publication may have populated MyBB's thread cache earlier.
    get_thread((int)$tid, true);
    $thread = $access->thread($tid);
    $fid = (int)$thread['fid'];
    if ((int)$thread['visible'] !== 1 || !function_exists('af_atf_acs_forum_enabled') || !af_atf_acs_forum_enabled($fid)
        || !af_advancedcombatsystem_atf_can_create($fid)) {
        throw new RuntimeException('ACS_CREATE_DENIED');
    }
    return af_advancedcombatsystem_sessions()->command('create', [
        'tid' => $tid,
        'operation_key' => 'atf-thread-create-'.$tid,
        'reason' => 'Настройка боевого эпизода ATF',
    ], true);
}
