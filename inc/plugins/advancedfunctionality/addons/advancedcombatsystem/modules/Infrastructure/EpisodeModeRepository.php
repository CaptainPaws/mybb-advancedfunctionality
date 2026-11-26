<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionRepository.php';

final class AF_ACS_EpisodeModeRepository extends AF_ACS_SessionRepository
{
    public function episodeNativeThread(string $tid): ?array
    {
        return $this->sql('SELECT fid,uid,firstpost,visible FROM `'.$this->prefix.'threads` WHERE tid=?', [$tid])['rows'][0] ?? null;
    }

    private function topicSessions(string $tid): array
    {
        return $this->sql('SELECT id,owner_uid,status FROM '.$this->table('sessions').' WHERE tid=? ORDER BY id LIMIT 2', [$tid])['rows'];
    }

    public function modeState(string $tid): array
    {
        $saved = $this->episodeMode($tid);
        $sessions = $this->topicSessions($tid);
        $intent = !$saved && !$sessions && function_exists('af_atf_acs_has_topic_intent') && af_atf_acs_has_topic_intent((int)$tid);
        return ['mode'=>$saved['mode'] ?? ($sessions || $intent ? 'combat' : 'normal'),
            'version'=>(int)($saved['version'] ?? 0), 'sessions'=>$sessions];
    }

    public function canChangeEpisodeMode(array $state, string $requested, string $actor): ?string
    {
        if (!$this->episodeModeStorageAvailable()) { return 'ACS_EPISODE_STORAGE_UNAVAILABLE'; }
        if (count($state['sessions']) > 1) { return 'ACS_EPISODE_MULTIPLE_SESSIONS'; }
        $session = $state['sessions'][0] ?? null;
        if ($session === null) { return null; }
        if (!AF_ACS_AuthorizationService::sessionGm($session, $this->roles((string)$session['id'], $actor), $actor)) {
            return 'ACS_EPISODE_GM_REQUIRED';
        }
        if ($requested === $state['mode']) { return null; }
        $sid = (string)$session['id'];
        $encounters = $this->table('encounters');
        if ($this->sql("SELECT 1 FROM $encounters WHERE session_id=? AND (status IN ('active','paused','pending_resolution','completed') OR round_number<>0) LIMIT 1", [$sid])['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('rounds')." r JOIN $encounters e ON e.id=r.encounter_id WHERE e.session_id=? LIMIT 1", [$sid])['rows']) {
            return 'ACS_EPISODE_BATTLE_STARTED';
        }

        if ($this->sql("SELECT 1 FROM $encounters WHERE session_id=? AND status<>'preparation' LIMIT 1", [$sid])['rows']) {
            return 'ACS_EPISODE_ENCOUNTER_STATE';
        }
        if ($session['status'] !== 'active') { return 'ACS_EPISODE_SESSION_STATE'; }
        $obligations = $this->obligations($sid);
        if ($obligations['pending'] || $obligations['recovery_required'] || $obligations['explicitly_blocked'] || $obligations['confirmed_completed']) {
            return 'ACS_EPISODE_OBLIGATIONS';
        }
        foreach (['submissions','rolls','rewards','custody','prepared_actions','pre_round_commitments','loot_pools','encounter_resolutions'] as $name) {
            if ($this->sql('SELECT 1 FROM '.$this->table($name).' WHERE session_id=? LIMIT 1', [$sid])['rows']) {
                return 'ACS_EPISODE_COMBAT_HISTORY';
            }
        }

        $drafts = $this->table('drafts');
        if ($this->sql('SELECT 1 FROM '.$this->table('sealed_actions')
            ." a JOIN $drafts d ON d.id=a.draft_id JOIN $encounters e ON e.id=d.encounter_id WHERE e.session_id=? LIMIT 1", [$sid])['rows']) {
            return 'ACS_EPISODE_COMBAT_HISTORY';
        }
        $events = $this->table('events');
        if ($this->sql('SELECT 1 FROM '.$this->table('combat_results')
            ." c JOIN $events e ON e.id=c.event_id WHERE e.session_id=? LIMIT 1", [$sid])['rows']) {
            return 'ACS_EPISODE_COMBAT_HISTORY';
        }

        // A positive list keeps unknown receipts, resource costs and any
        // future mechanical operation out of the reversible path.
        $safe = ['session.create','session.set_role','session.remove_member','session.transfer_owner',
            'entity.register','encounter.create','encounter.side_create','encounter.side_rename','encounter.side_accent','encounter.side_delete','encounter.relation_set',
            'encounter.participant_add','encounter.participant_remove','encounter.participant_side','encounter.participant_visibility',
            'encounter.controller_assign','encounter.controller_set','encounter.end_conditions_set','encounter.participation_set'];
        $marks = implode(',', array_fill(0, count($safe), '?'));
        if ($this->sql('SELECT 1 FROM '.$this->table('operations')
            ." WHERE session_id=? AND (status<>'committed' OR external_system IS NOT NULL OR kind NOT IN ($marks)) LIMIT 1", [$sid, ...$safe])['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('events')
                ." WHERE session_id=? AND (event_type NOT IN ($marks) OR submission_id IS NOT NULL) LIMIT 1", [$sid, ...$safe])['rows']) {
            return 'ACS_EPISODE_COMBAT_HISTORY';
        }
        return null;
    }

    public function changeEpisodeMode(string $tid, string $mode, int $expected, string $actor, callable $authorize): array
    {
        return $this->transactions->run(function () use ($tid,$mode,$expected,$actor,$authorize) {
            $this->writable();
            if (!$this->episodeModeStorageAvailable()) { throw new RuntimeException('ACS_EPISODE_STORAGE_UNAVAILABLE'); }
            $state = $this->modeState($tid);
            $authorize($state);
            if ($state['version'] !== $expected &&
                !($state['version'] === $expected + 1 && $state['mode'] === $mode)) {
                throw new RuntimeException('ACS_EPISODE_VERSION_CONFLICT');
            }
            $reason = $this->canChangeEpisodeMode($state, $mode, $actor);
            if ($reason !== null) { throw new RuntimeException($reason); }
            if ($state['mode'] !== $mode || $state['version'] === 0) {
                $this->sql('INSERT INTO '.$this->episodeModeTable().' (tid,mode,version,actor_uid,updated_at) VALUES (?,?,1,?,?)
                    ON DUPLICATE KEY UPDATE mode=?,version=version+1,actor_uid=?,updated_at=?',
                    [$tid,$mode,$actor,time(),$mode,$actor,time()]);
            }
            return $state;
        });
    }
}
