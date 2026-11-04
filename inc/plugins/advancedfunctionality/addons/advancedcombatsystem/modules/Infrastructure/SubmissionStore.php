<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/EncounterRepository.php';
require_once __DIR__.'/../Domain/Publication.php';

/** Internal R2 journal. No endpoint, MyBB insertion, Resolver or combat writer. */
final class AF_ACS_SubmissionStore extends AF_ACS_Repository
{
    private AF_ACS_EncounterRepository $preparation;

    public function __construct(object $database)
    {
        parent::__construct($database);
        $this->preparation = new AF_ACS_EncounterRepository($database);
    }

    private static function submissionId(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { throw new InvalidArgumentException('ACS_SUBMISSION_ID_INVALID'); }
        return $id;
    }

    public function submission(string $id): ?array
    {
        $row = $this->sql('SELECT * FROM '.$this->table('submissions').' WHERE submission_id=?', [self::submissionId($id)])['rows'][0] ?? null;
        if ($row) {
            foreach (['session_id','encounter_id','turn_id','draft_id','author_uid','tid','pid','active_turn_id','state_version','created_at','updated_at'] as $field) {
                if ($row[$field] !== null) { $row[$field] = (string)$row[$field]; }
            }
        }
        return $row;
    }

    /** Caller rechecks authenticated ownership/native permissions on initial request AND replay. */
    public function register(array $request, callable $authorize): array
    {
        if (array_diff(array_keys($request), ['schema_version','submission_id','draft_id','author_uid','expected_draft_version','confirmation_hash'])
            || ($request['schema_version'] ?? null) !== 1) { throw new InvalidArgumentException('ACS_SUBMISSION_INPUT_INVALID'); }
        $id = self::submissionId($request['submission_id'] ?? '');
        foreach (['draft_id','author_uid','expected_draft_version'] as $field) { $request[$field] = self::integer($request[$field] ?? null); }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $request['confirmation_hash'] ?? '')) { throw new InvalidArgumentException('ACS_CONFIRMATION_REQUIRED'); }
        // Fixed key order, independent of HTTP/object member ordering.
        $request = ['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$request['draft_id'],'author_uid'=>$request['author_uid'],
            'expected_draft_version'=>$request['expected_draft_version'],'confirmation_hash'=>$request['confirmation_hash']];
        $json = self::json($request); $hash = hash('sha256', $json);
        return $this->transactions->run(function () use ($request, $id, $json, $hash, $authorize) {
            $this->writable();
            $draft = $this->preparation->draft($request['draft_id'], $request['author_uid']);
            if (!$draft) { throw new RuntimeException('ACS_DRAFT_UNAVAILABLE'); }
            $session = $this->lockSession($draft['session_id']);
            $draft = $this->preparation->draft($request['draft_id'], $request['author_uid']);
            $authorize($draft);
            $old = $this->submission($id);
            if ($old) {
                if (!hash_equals($old['request_hash'], $hash)) { throw new RuntimeException('ACS_SUBMISSION_CONFLICT'); }
                return $old;
            }
            if ($draft['draft_version'] !== $request['expected_draft_version'] || $draft['status'] !== 'sealed'
                || $draft['pid'] !== null || $draft['confirmation_hash'] === null
                || !hash_equals($draft['confirmation_hash'], $request['confirmation_hash'])) { throw new RuntimeException('ACS_SEALED_SUBMISSION_REQUIRED'); }
            if ($this->sql('SELECT submission_id FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') LIMIT 1", [$draft['session_id']])['rows']) {
                throw new RuntimeException('ACS_PUBLICATION_UNRESOLVED');
            }
            if ($this->sql('SELECT submission_id FROM '.$this->table('submissions').' WHERE active_turn_id=? LIMIT 1', [$draft['turn_id']])['rows']) {
                throw new RuntimeException('ACS_TURN_ALREADY_SUBMITTED');
            }
            $seals = [];
            foreach ($this->preparation->sealRows($draft['id'], true) as $row) {
                $payload = $this->preparation->sealPayload($row); $tape = $this->preparation->sealTape((string)$row['id']);
                if (!hash_equals($payload['tape_hash'], hash('sha256', self::json(['schema_version'=>1,'tape'=>$tape])))) { throw new RuntimeException('ACS_SEAL_TAPE_INVALID'); }
                $this->preparation->verifySealDefinition($draft['session_id'], $row, $payload);
                $seals[] = ['id'=>(string)$row['id'],'sealed_hash'=>$row['sealed_hash'],'payload'=>$payload,'tape'=>$tape];
            }
            if (!$seals) { throw new RuntimeException('ACS_SEALED_SUBMISSION_REQUIRED'); }
            $snapshot = self::json(['schema_version'=>1,'draft_version'=>$draft['draft_version'],'prose'=>$draft['prose'],
                'mechanics_json'=>$draft['mechanics_json'],'dependency_json'=>$draft['dependency_json'],'confirmation_hash'=>$draft['confirmation_hash'],'seals'=>$seals]);
            $empty = self::json(['schema_version'=>1]); $now = time();
            $this->sql('INSERT INTO '.$this->table('submissions').' (submission_id,session_id,encounter_id,turn_id,draft_id,author_uid,tid,pid,active_turn_id,status,last_phase,state_version,request_hash,request_json,sealed_json,evidence_json,result_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NULL,?,?,?,1,?,?,?,?,?,?,?)',
                [$id,$draft['session_id'],$draft['encounter_id'],$draft['turn_id'],$draft['id'],$draft['author_uid'],$session['tid'],$draft['turn_id'],'registered','registered',$hash,$json,$snapshot,$empty,$empty,$now,$now]);
            // Freeze prose/actions together; rejected-before-post can explicitly release this draft.
            if ($this->sql('UPDATE '.$this->table('drafts')." SET status='publishing',draft_version=draft_version+1,updated_at=? WHERE id=? AND draft_version=?", [$now,$draft['id'],$draft['draft_version']])['affected'] !== 1) { throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT'); }
            $this->audit($this->submission($id), null, 'registered', $draft['author_uid'], 'intent_registered', ['schema_version'=>1]);
            return $this->submission($id);
        });
    }

    private function audit(array $row, ?string $from, string $to, string $actor, string $reason, array $evidence): void
    {
        $this->sql('INSERT INTO '.$this->table('publication_audit').' (submission_id,state_version,from_status,to_status,actor_uid,reason,evidence_json,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$row['submission_id'],$row['state_version'],$from,$to,self::integer($actor,'4294967295',true),self::key($reason,64),self::json($evidence),time()]);
    }

    /** All journal writes are short ACS-only transactions; authorization callback must be read-only. */
    private function change(string $id, string $version, callable $authorize, callable $choose): array
    {
        self::integer($version, '18446744073709551614');
        return $this->transactions->run(function () use ($id, $version, $authorize, $choose) {
            $this->writable(); $row = $this->submission($id);
            if (!$row) { throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND'); }
            $this->lockSession($row['session_id']); $row = $this->submission($id);
            $authorize($row);
            if ($row['state_version'] !== $version) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            [$to, $reason, $evidence, $pid] = $choose($row);
            AF_ACS_Publication::requireTransition($row['status'], $to);
            $last = $to === 'recovery_required' ? $row['last_phase'] : $to;
            if ($pid !== null) {
                self::integer($pid, '4294967295');
                if ($row['pid'] !== null && $row['pid'] !== $pid) { throw new RuntimeException('ACS_POST_LINK_CONFLICT'); }
            } else { $pid = $row['pid']; }
            if ($this->sql('UPDATE '.$this->table('submissions').' SET status=?,last_phase=?,pid=?,state_version=state_version+1,evidence_json=?,updated_at=? WHERE submission_id=? AND state_version=?',
                [$to,$last,$pid,self::json($evidence),time(),$id,$version])['affected'] !== 1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            if ($to === 'rejected') {
                // Only a proven pre-POSTING refusal can reach this method's rejection branch.
                $this->sql('UPDATE '.$this->table('submissions').' SET active_turn_id=NULL WHERE submission_id=?', [$id]);
                $this->sql('UPDATE '.$this->table('drafts')." SET status='sealed',draft_version=draft_version+1,updated_at=? WHERE id=? AND status='publishing'", [time(),$row['draft_id']]);
            }
            $updated = $this->submission($id);
            $this->audit($updated, $row['status'], $to, $row['author_uid'], $reason, $evidence);
            return $updated;
        });
    }

    public function validating(string $id, string $version, callable $authorize): array
    {
        return $this->change($id,$version,$authorize,static fn($row)=>['validating','validation_started',['schema_version'=>1],null]);
    }

    /** Persist BEFORE handing control to native MyBB. A replay in POSTING must never insert again. */
    public function posting(string $id, string $version, array $controls, callable $authorize): array
    {
        foreach (['dependency_hash','simulation_hash','confirmation_hash'] as $field) {
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $controls[$field] ?? '')) { throw new RuntimeException('ACS_PUBLICATION_CONTROLS_REQUIRED'); }
        }
        return $this->change($id,$version,$authorize,static function ($row) use ($controls) {
            $sealed = json_decode($row['sealed_json'], true, 64, JSON_THROW_ON_ERROR);
            $meta = json_decode($sealed['dependency_json'], true, 32, JSON_THROW_ON_ERROR)['seal'] ?? [];
            foreach (['dependency_hash','simulation_hash'] as $field) {
                if (!hash_equals($meta[$field] ?? '',$controls[$field])) { throw new RuntimeException('ACS_SEAL_SUMMARY_CHANGED'); }
            }
            if (!hash_equals($sealed['confirmation_hash'],$controls['confirmation_hash'])) { throw new RuntimeException('ACS_SEAL_SUMMARY_CHANGED'); }
            return ['posting','native_dispatch_started',['schema_version'=>1,'controls'=>$controls],null];
        });
    }

    public function rejectBeforePosting(string $id, string $version, string $reason, callable $authorize): array
    {
        return $this->change($id,$version,$authorize,static function ($row) use ($reason) {
            if (!in_array($row['status'],['registered','validating'],true)) { throw new RuntimeException('ACS_POST_OUTCOME_UNKNOWN'); }
            return ['rejected',self::key($reason,64),['schema_version'=>1,'proof'=>'native_dispatch_not_started'],null];
        });
    }

    /** No timeout-based retry/release. last_phase survives quarantine; existing PID is retained. */
    public function quarantine(string $id, string $version, string $reason, callable $authorize): array
    {
        return $this->change($id,$version,$authorize,static fn($row)=>['recovery_required',self::key($reason,64),['schema_version'=>1,'last_known_phase'=>$row['last_phase']],null]);
    }

    /** Known pid only; this readback is insufficient to discover a lost pid from author/time. */
    public function confirmPost(string $id, string $version, string $pid, callable $authorize): array
    {
        self::integer($pid,'4294967295');
        return $this->change($id,$version,$authorize,function ($row) use ($pid) {
            if ($row['status'] !== 'posting') { throw new RuntimeException('ACS_POST_CONFIRMATION_DENIED'); }
            // Authoritative writer read, independent of MyBB read replicas/caches.
            $post = $this->sql('SELECT pid,uid,tid,visible FROM `'.$this->prefix.'posts` WHERE pid=?',[$pid])['rows'][0] ?? null;
            if (!$post || (string)$post['uid'] !== $row['author_uid'] || (string)$post['tid'] !== $row['tid']
                || !in_array((string)$post['visible'],['0','1'],true)) { throw new RuntimeException('ACS_POST_CONFIRMATION_DENIED'); }
            $to = (string)$post['visible'] === '0' ? 'moderation_pending' : 'post_confirmed';
            return [$to,'native_pid_readback',['schema_version'=>1,'pid'=>$pid,'visible'=>(string)$post['visible']],$pid];
        });
    }

    /** Bounded internal projection. Callers must authorize access before returning any rows. */
    public function pending(string $sid): array
    {
        $rows = $this->sql('SELECT submission_id,turn_id,draft_id,author_uid,tid,pid,status,last_phase,state_version,updated_at FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') ORDER BY created_at,submission_id LIMIT 100",[self::integer($sid)])['rows'];
        foreach ($rows as &$row) {
            foreach (['turn_id','draft_id','author_uid','tid','pid','state_version','updated_at'] as $field) {
                if ($row[$field] !== null) { $row[$field] = (string)$row[$field]; }
            }
        }
        unset($row);
        return $rows;
    }
}
