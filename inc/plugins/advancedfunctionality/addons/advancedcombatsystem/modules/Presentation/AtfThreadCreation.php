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

function af_advancedcombatsystem_atf_edit_state(int $tid): ?array
{
    global $db;
    if (!af_is_addon_enabled('advancedcombatsystem')) { return null; }
    $access = af_advancedcombatsystem_atf_access();
    $thread = $access->thread((string)$tid);
    require_once __DIR__.'/../Infrastructure/SessionRepository.php';
    $repo = new AF_ACS_SessionRepository($db);
    $state = $repo->atfTopicStatus((string)$tid, $access->uid());
    return ['existing'=>$state !== null || $repo->hasTopicSession((string)$tid), 'session'=>$state, 'visible'=>(int)$thread['visible'] === 1];
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

function af_advancedcombatsystem_atf_status(array $context): string
{
    global $db;
    if (!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated'])) { return ''; }
    $tid = (int)($context['tid'] ?? 0);
    $fid = (int)($context['fid'] ?? 0);
    if (!$tid || !function_exists('af_atf_acs_forum_enabled') || !af_atf_acs_forum_enabled($fid)) { return ''; }
    try {
        $access = af_advancedcombatsystem_atf_access();
        $thread = $access->thread((string)$tid);
        if ((int)$thread['fid'] !== $fid) { return ''; }
        $actor = $access->uid();
        require_once __DIR__.'/../Infrastructure/SessionRepository.php';
        $repo = new AF_ACS_SessionRepository($db);
        $state = $repo->atfTopicStatus((string)$tid, $actor);
        $url = 'showthread.php?tid='.$tid.'&acs_setup=1&acs_atf_setup=1';
        if ($state !== null) {
            $status = match ($state['encounter_status']) {
                null => 'Сессия создана · столкновение ещё не настроено',
                'active' => 'Столкновение активно',
                'paused' => 'Столкновение приостановлено',
                'pending_resolution' => 'Столкновение ожидает завершения',
                'completed' => 'Столкновение завершено',
                default => 'Сессия создана · настройка столкновения',
            };
            $action = $state['can_manage'] ? '<a href="'.htmlspecialchars_uni($url.'&acs_session='.$state['id'].'&gm_step=2').'">Настроить бой</a>' : '';
        } else {
            $pending = af_atf_acs_pending_for_actor($tid, (int)$actor);
            if ((!$pending && !$access->canCreate()) || $repo->hasTopicSession((string)$tid)) { return ''; }
            $status = $pending ? 'Создание сессии ожидает подтверждения' : '';
            $action = (int)$thread['visible'] === 1 && af_advancedcombatsystem_atf_can_create($fid)
                ? '<a href="'.htmlspecialchars_uni($url).'">Настроить бой</a>' : '';
        }
        return $status === '' && $action === '' ? '' : '<div class="atf-meta" role="status">'
            .htmlspecialchars_uni($status).' '.$action.'</div>';
    } catch (Throwable $error) { return ''; }
}
