<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

const AF_ATF_ACS_INTENTS = 'af_atf_acs_thread_intents';

function af_atf_acs_install(): void
{
    global $db;
    if (!$db->table_exists(AF_ATF_ACS_INTENTS)) { $db->write_query('CREATE TABLE IF NOT EXISTS `'.TABLE_PREFIX.AF_ATF_ACS_INTENTS.'` (
        token char(32) NOT NULL, claim_id char(32) NOT NULL,
        uid int unsigned NOT NULL, fid int unsigned NOT NULL, tid int unsigned NULL,
        PRIMARY KEY (token), UNIQUE KEY thread_intent (tid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); }
    if (!$db->table_exists('af_atf_acs_episode_modes')) {
        $db->write_query('CREATE TABLE IF NOT EXISTS `'.TABLE_PREFIX.'af_atf_acs_episode_modes` (
            tid int unsigned NOT NULL, mode varchar(8) NOT NULL, version int unsigned NOT NULL,
            actor_uid int unsigned NOT NULL, updated_at int unsigned NOT NULL,
            PRIMARY KEY (tid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}

/** Opt-in union. Only explicitly scoped, active groups can grant forum eligibility. */
function af_atf_acs_forum_enabled(int $fid): bool
{
    if (!af_atf_is_enabled() || $fid <= 0) { return false; }
    foreach (af_atf_get_groups_cached() as $group) {
        if ((int)($group['active'] ?? 0) === 1 && (int)($group['acs_enabled'] ?? 0) === 1
            && ($group['forums_raw'] ?? '') !== '' && isset($group['forums_set'][$fid])) {
            return true;
        }
    }
    return false;
}

function af_atf_acs_blocker(int $fid, bool $editing = false): ?string
{
    global $db;
    if (!af_atf_acs_forum_enabled($fid)) { return 'scope'; }
    if (!af_is_addon_enabled('advancedcombatsystem')) { return 'disabled'; }
    if (!$editing && (!$db->table_exists(AF_ATF_ACS_INTENTS) || !$db->table_exists('af_atf_acs_episode_modes'))) { return 'storage'; }
    if (!function_exists('af_advancedcombatsystem_atf_create_blocker')) { return 'bootstrap'; }
    return af_advancedcombatsystem_atf_create_blocker($fid);
}

function af_atf_acs_available(int $fid): bool
{
    return af_atf_acs_blocker($fid) === null;
}

function af_atf_acs_episode_reason(string $code): string
{
    return match ($code) {
        'ACS_EPISODE_BATTLE_STARTED' => 'Тип эпизода нельзя изменить после начала боя.',
        'ACS_EPISODE_COMBAT_HISTORY' => 'История боевых или неподтверждённых операций блокирует смену режима.',
        'ACS_EPISODE_OBLIGATIONS' => 'Смена режима недоступна до разрешения обязательств ACS.',
        'ACS_EPISODE_MULTIPLE_SESSIONS' => 'Смена режима недоступна для темы с несколькими сессиями.',
        'ACS_EPISODE_ENCOUNTER_STATE' => 'Состояние подготовки столкновения не позволяет подтвердить обратимость.',
        'ACS_EPISODE_SESSION_STATE' => 'Текущее состояние сессии блокирует смену режима.',
        'ACS_EPISODE_UNPUBLISHED' => 'Смена режима доступна в опубликованной теме.',
        'ACS_EPISODE_FORUM_DISABLED' => 'Этот форум не разрешает включение ACS.',
        'ACS_EPISODE_VERSION_CONFLICT' => 'Тип эпизода уже изменился. Откройте форму заново.',
        'ACS_EPISODE_GM_REQUIRED', 'ACS_EPISODE_EDIT_REQUIRED', 'ACS_CREATE_DENIED', 'permission' =>
            'У вашей учётной записи нет права изменить тип эпизода.',
        'storage', 'ACS_EPISODE_STORAGE_UNAVAILABLE' => 'Обновите хранилище режима эпизода через активацию AdvancedThreadFields.',
        'disabled' => 'Боевой режим недоступен, пока ACS отключён.',
        'native' => 'Для боевого эпизода нужны права MyBB на создание и просмотр тем.',
        default => 'Состояние ACS не подтверждено. Проверьте настройки и состояние сессии.',
    };
}

function af_atf_acs_mode_input(int $fid, int $tid = 0): string
{
    global $mybb;
    $state = null;
    $blocker = af_atf_acs_blocker($fid, $tid > 0);
    if ($tid > 0 && function_exists('af_advancedcombatsystem_atf_edit_state')) {
        try {
            $state = af_advancedcombatsystem_atf_edit_state($tid);
            if ($state !== null) { $blocker = $state['blocker']; }
        } catch (Throwable $error) {
            if ($blocker === null) { $blocker = 'schema'; }
        }
    }
    if (!af_atf_acs_forum_enabled($fid) && empty($state['version']) && empty($state['sessions'])) { return ''; }
    $saved = $state['mode'] ?? 'normal';
    $session = $state['session'] ?? null;
    $mechanics = '';
    if ($session !== null) {
        $mechanics = match ($session['encounter_status']) {
            null => 'Сессия создана. Столкновение ещё не настроено.',
            'preparation' => 'Столкновение в подготовке.',
            'active' => 'Столкновение активно.',
            'paused' => 'Столкновение приостановлено.',
            'pending_resolution' => 'Столкновение ожидает завершения.',
            'completed' => 'Столкновение завершено.',
            default => 'Состояние столкновения требует отдельной проверки.',
        };
    } elseif ($saved === 'combat' && empty($state['sessions'])) {
        $mechanics = 'Создание сессии не подтверждено.';
    }
    $status = $mechanics === '' ? '' : '<p>'.htmlspecialchars_uni($mechanics).'</p>';
    $setup = $saved === 'combat' && !empty($session['can_manage'])
        ? '<p><a href="showthread.php?tid='.$tid.'&amp;acs_setup=1&amp;acs_session='
            .htmlspecialchars_uni((string)$session['id']).'&amp;gm_step=2">Настроить бой</a></p>' : '';
    if ($blocker !== null || ($tid > 0 && $state === null)) {
        return '<div class="atf-compose__field"><strong>Тип эпизода · '.($saved === 'combat' ? 'Боевой эпизод (ACS)' : 'Обычный эпизод')
            .'</strong><p>'.htmlspecialchars_uni(af_atf_acs_episode_reason($blocker ?? 'schema')).'</p>'.$status.$setup.'</div>';
    }
    $requested = $mybb->request_method === 'post' ? $mybb->get_input('acs_mode') : '';
    $selected = in_array($requested, ['normal','combat'], true) ? $requested : $saved;
    if ($tid === 0) {
        $token = $mybb->request_method === 'post' ? $mybb->get_input('acs_intent') : '';
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) { $token = bin2hex(random_bytes(16)); }
        $hidden = '<input type="hidden" name="acs_intent" value="'.$token.'">';
    } else {
        $version = $requested !== '' ? $mybb->get_input('acs_mode_version') : (string)$state['version'];
        $hidden = '<input type="hidden" name="acs_mode_version" value="'.htmlspecialchars_uni($version).'">';
    }
    return '<fieldset class="atf-compose__episode"><legend>Тип эпизода</legend>'
        .'<label><input type="radio" name="acs_mode" value="normal"'.($selected === 'normal' ? ' checked' : '').'> Обычный эпизод</label> '
        .'<label><input type="radio" name="acs_mode" value="combat"'.($selected === 'combat' ? ' checked' : '').'> Боевой эпизод (ACS)</label>'
        .$hidden.'</fieldset>'.$status.$setup;
}

function af_atf_acs_validate_edit(array $context): void
{
    global $mybb;
    $mode = $mybb->get_input('acs_mode');
    if ($mode === '') { return; }
    $version = $mybb->get_input('acs_mode_version');
    if (!in_array($mode, ['normal','combat'], true) || empty($context['is_first'])
        || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $version)
        || !function_exists('af_advancedcombatsystem_atf_change_mode')) {
        error('Смена типа эпизода недоступна. Откройте форму заново.');
    }
    if (!af_atf_is_preview_request()) {
        $GLOBALS['af_atf_acs_edit'] = ['tid'=>(int)$context['tid'],'pid'=>(int)$context['pid'],'mode'=>$mode,'version'=>(int)$version];
    }
}

function af_atf_acs_finish_edit(): void
{
    global $tid, $lang;
    $edit = $GLOBALS['af_atf_acs_edit'] ?? null;
    if ($edit === null || (int)$tid !== $edit['tid']) { return; }
    try { af_advancedcombatsystem_atf_change_mode((string)$tid, $edit['pid'], $edit['mode'], $edit['version']); }
    catch (Throwable $error) {
        $lang->redirect_postedited .= ' Изменение типа эпизода не подтверждено. '
            .af_atf_acs_episode_reason($error->getMessage()).' Проверьте состояние в теме.';
    }
}

function af_atf_acs_has_topic_intent(int $tid): bool
{
    global $db;
    if (!$db->table_exists(AF_ATF_ACS_INTENTS)) { return false; }
    $query = $db->write_query('SELECT tid FROM '.TABLE_PREFIX.AF_ATF_ACS_INTENTS.' WHERE tid='.$tid.' LIMIT 1', true);
    if (!$query) { throw new RuntimeException('ACS_ATF_INTENT_STORAGE_UNAVAILABLE'); }
    return (bool)$db->fetch_array($query);
}

function af_atf_acs_intent(string $token): ?array
{
    global $db;
    $query = $db->write_query('SELECT token,claim_id,uid,fid,tid FROM `'.TABLE_PREFIX.AF_ATF_ACS_INTENTS
        ."` WHERE token='".$db->escape_string($token)."' LIMIT 1", true);
    if (!$query) { throw new RuntimeException('ACS_ATF_INTENT_STORAGE_UNAVAILABLE'); }
    return $db->fetch_array($query) ?: null;
}

function af_atf_acs_pending_for_actor(int $tid, int $uid): bool
{
    global $db;
    if (!$db->table_exists(AF_ATF_ACS_INTENTS)) { return false; }
    return (bool)$db->fetch_array($db->simple_select(AF_ATF_ACS_INTENTS, 'tid', "tid={$tid} AND uid={$uid}", ['limit'=>1]));
}

/** The native newthread action has already passed CSRF and forum checks. */
function af_atf_acs_validate_mode(int $fid): void
{
    global $mybb;
    $mode = $mybb->get_input('acs_mode');
    if ($mode === '' || $mode === 'normal') { return; }
    $token = $mybb->get_input('acs_intent');
    if ($mode !== 'combat' || !af_atf_acs_available($fid) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) {
        error('Боевой эпизод ACS недоступен для этого форума или пользователя.');
    }
    if (af_atf_is_preview_request() || $mybb->get_input('savedraft')) { return; }

    $uid = (int)$mybb->user['uid'];
    try { $prior = af_atf_acs_intent($token); }
    catch (Throwable $error) { error('Не удалось проверить прежний запрос боевого эпизода.'); }
    if ($prior !== null) { af_atf_acs_replay_topic($prior, $uid, $fid); }
    $GLOBALS['af_atf_acs_request'] = ['token'=>$token, 'uid'=>$uid, 'fid'=>$fid];
}

/** Never interpret an unfinished native insert as a failed insert that can be repeated. */
function af_atf_acs_replay_topic(array $prior, int $uid, int $fid): void
{
    if ((int)$prior['uid'] === $uid && (int)$prior['fid'] === $fid && (int)$prior['tid'] > 0
        && af_advancedcombatsystem_atf_can_open((int)$prior['tid'])) {
        redirect(get_thread_link((int)$prior['tid']), 'Тема уже создана. Проверьте состояние ACS в теме.');
    }
    error('Этот запрос уже обработан или ещё выполняется. Проверьте форум и список своих тем перед повторным созданием.');
}

/** Run only at the native insert boundary, after validation. */
function af_atf_acs_claim_thread(&$handler): void
{
    global $db;
    $request = $GLOBALS['af_atf_acs_request'] ?? null;
    if ($request === null || !empty($handler->data['savedraft'])) { return; }
    if ((int)($handler->data['fid'] ?? 0) !== $request['fid'] || (int)($handler->data['uid'] ?? 0) !== $request['uid']) {
        error('Не удалось подтвердить параметры боевого эпизода.');
    }
    $claim = bin2hex(random_bytes(16));
    ['token'=>$token, 'uid'=>$uid, 'fid'=>$fid] = $request;
    $ok = $db->write_query('INSERT INTO `'.TABLE_PREFIX.AF_ATF_ACS_INTENTS."` (token,claim_id,uid,fid) VALUES ('$token','$claim',$uid,$fid)
        ON DUPLICATE KEY UPDATE token=token", true);
    if (!$ok) { error('Не удалось сохранить запрос боевого эпизода. Тема не создана.'); }
    try { $row = af_atf_acs_intent($token); }
    catch (Throwable $error) { error('Не удалось подтвердить запрос боевого эпизода. Тема не создана.'); }
    if ($row === null) { error('Не удалось прочитать запрос боевого эпизода. Тема не создана.'); }
    if ($row['claim_id'] !== $claim) { af_atf_acs_replay_topic($row, $uid, $fid); }
    $GLOBALS['af_atf_acs_request']['claim_id'] = $claim;
}

/** MyBB supplies the result only after the thread and its first post were persisted. */
function af_atf_acs_bind_thread(&$handler): void
{
    global $db;
    $request = $GLOBALS['af_atf_acs_request'] ?? [];
    $tid = (int)($handler->return_values['tid'] ?? 0);
    $pid = (int)($handler->return_values['pid'] ?? 0);
    if (empty($request['claim_id']) || $tid <= 0 || $pid <= 0) { return; }
    $query = $db->write_query('SELECT fid,uid,firstpost,visible FROM `'.TABLE_PREFIX."threads` WHERE tid=$tid LIMIT 1", true);
    $thread = $query ? $db->fetch_array($query) : null;
    if (!$thread || (int)$thread['uid'] !== $request['uid'] || (int)$thread['fid'] !== $request['fid']
        || (int)$thread['firstpost'] !== $pid || (int)$thread['visible'] === -2) { return; }
    ['token'=>$token, 'claim_id'=>$claim] = $request;
    // Unique TID also fences concurrent publication of the same native draft.
    $db->write_query('UPDATE IGNORE `'.TABLE_PREFIX.AF_ATF_ACS_INTENTS."` SET tid=$tid WHERE token='$token' AND claim_id='$claim' AND tid IS NULL", true);
    try { $row = af_atf_acs_intent($token); }
    catch (Throwable $error) { return; }
    if ($row !== null && $row['claim_id'] === $claim && (int)$row['tid'] === $tid) {
        $GLOBALS['af_atf_acs_request']['tid'] = $tid;
        $GLOBALS['af_atf_acs_request']['visible'] = (int)$thread['visible'] === 1;
    }
}

function af_atf_acs_finish_thread(): void
{
    global $tid, $fid, $lang, $mybb;
    if ((int)$tid <= 0 || $mybb->get_input('savedraft') || !af_atf_acs_forum_enabled((int)$fid)
        || !function_exists('af_advancedcombatsystem_atf_seed_mode')) { return; }
    if (empty($GLOBALS['af_atf_acs_request']['claim_id'])) {
        if ($mybb->get_input('acs_mode') !== 'combat' && !empty($mybb->user['uid'])) {
            try { af_advancedcombatsystem_atf_seed_mode((string)$tid, (int)$fid, 'normal'); }
            catch (Throwable $error) { error_log('ACS ATF episode mode not seeded; tid='.(int)$tid); }
        }
        return;
    }
    try {
        if ((int)($GLOBALS['af_atf_acs_request']['tid'] ?? 0) !== (int)$tid) {
            throw new RuntimeException('ACS_ATF_BINDING_UNCONFIRMED');
        }
        af_advancedcombatsystem_atf_seed_mode((string)$tid, (int)$fid, 'combat');
        if (!empty($GLOBALS['af_atf_acs_request']['visible'])) {
            af_advancedcombatsystem_atf_create((string)$tid);
        } else {
            $lang->redirect_newthread .= ' Сессия ACS ожидает публикации темы. После одобрения откройте настройку боя в теме.';
        }
    } catch (Throwable $error) {
        $lang->redirect_newthread .= ' Создание сессии ACS не подтверждено. Проверьте состояние темы и продолжите через настройку боя.';
        $code = preg_match('/\AACS_[A-Z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_ATF_CREATE_UNCONFIRMED';
        error_log('ACS ATF thread creation '.$code.' tid='.(int)$tid);
    }
}
