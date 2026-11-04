<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/MybbPostContext.php';
require_once __DIR__.'/MybbNativeReply.php';
require_once __DIR__.'/../../Application/DraftService.php';

/** Internal only. No route/hook registration; does not apply combat effects or enable publication UI. */
final class AF_ACS_MybbPostGateway
{
    private static array $inFlight = [];
    public function __construct(
        private AF_ACS_SubmissionStore $store,
        private AF_ACS_EncounterRepository $preparation,
        private AF_ACS_SessionAccess $access,
        private object $database,
    ) {}

    private function boundary(): void { $this->store->assertMybbBoundary($this->database); }

    /** Pure authorization inside journal transactions: no native hooks or foreign writers. */
    private function journalAuthorization(AF_ACS_MybbPostContext $context): Closure
    {
        return function (array $row) use ($context): void {
            if ($this->access->uid() !== $row['author_uid']
                || $row['submission_id'] !== $context->submission['submission_id']
                || !hash_equals($row['request_hash'],$context->submission['request_hash'])
                || !hash_equals(hash('sha256',$row['sealed_json']),hash('sha256',$context->submission['sealed_json']))) {
                throw new RuntimeException('ACS_MYBB_CONTEXT_MISMATCH');
            }
        };
    }

    private function allowedTurn(array $row): void
    {
        $draft=(new AF_ACS_DraftService($this->preparation,$this->access))->restore($row['draft_id']);
        $turn=$this->preparation->clockTurn($row['session_id'],$row['turn_id']);
        if ($draft['status'] !== 'publishing' || $draft['session_id'] !== $row['session_id'] || $draft['turn_id'] !== $row['turn_id']
            || $draft['encounter_id'] !== $row['encounter_id'] || $row['active_turn_id'] !== $row['turn_id']
            || !$turn || $turn['encounter_id'] !== $row['encounter_id'] || $turn['encounter_status'] !== 'active'
            || $turn['status'] !== 'pending' || $turn['round_status'] !== 'open' || $turn['round_number'] !== $turn['current_round']) {
            throw new RuntimeException('ACS_MYBB_TURN_UNAVAILABLE');
        }
    }

    private function result(array $row, AF_ACS_MybbPostStatus $status, string $code, array $errors = []): AF_ACS_MybbPostResult
    {
        return new AF_ACS_MybbPostResult($status,$row['submission_id'],
            in_array($status,[AF_ACS_MybbPostStatus::POST_CONFIRMED,AF_ACS_MybbPostStatus::MODERATION_PENDING],true) ? $row['pid'] : null,
            $row['state_version'],$code,$errors);
    }

    private static function code(Throwable $error): string
    {
        return preg_match('/\AACS_[A-Z_]{1,80}\z/D',$error->getMessage()) ? strtolower($error->getMessage()) : 'native_operation_failed';
    }

    private function uncertain(AF_ACS_MybbPostContext $context, string $code, string $phase, ?object $handler = null): AF_ACS_MybbPostResult
    {
        $row=$context->submission;
        try {
            $this->boundary();
            $row=$this->store->submission($row['submission_id']) ?? $row;
            ($this->journalAuthorization($context))($row);
            if (!in_array($row['status'],['committed','rejected','recovery_required'],true)) {
                $diagnostic=['gateway_phase'=>$phase];
                if ($handler !== null && preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)($handler->pid ?? ''))) {
                    $diagnostic['observed_pid']=(string)$handler->pid; // candidate evidence only, never a confirmed link
                }
                $row=$this->store->quarantine($row['submission_id'],$row['state_version'],$code,$this->journalAuthorization($context),$diagnostic);
            }
        } catch (Throwable $error) {
            // Lost connection/unknown journal COMMIT/foreign ambient tx: keep the last durable phase.
            // Never rollback foreign work, invent a terminal receipt, or call insert_post again.
        }
        return $this->result($row,AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,$code);
    }

    public function publish(AF_ACS_MybbPostContext $context): AF_ACS_MybbPostResult
    {
        $row=$context->submission; $phase='preflight'; $dispatch=false; $handler=null;
        if (isset(self::$inFlight[$row['submission_id']])) {
            return $this->result($row,AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'native_dispatch_in_progress');
        }
        self::$inFlight[$row['submission_id']]=true;
        try {
            $this->boundary();
            $row=$this->store->submission($row['submission_id']) ?? throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
            ($this->journalAuthorization($context))($row);
            $this->access->csrf(); $this->access->thread($row['tid']);
            // Durable outcomes are returned on replay before entering any native creation lifecycle.
            if (in_array($row['status'],['post_confirmed','moderation_pending','committed'],true)) {
                $topic=$this->access->thread($row['tid']);
                $q=$this->database->simple_select('posts','pid,uid,tid,fid,visible',"pid='".(int)$row['pid']."'",['limit'=>1]);
                $post=$this->database->fetch_array($q);
                if (!$post || (string)$post['uid'] !== $row['author_uid'] || (string)$post['tid'] !== $row['tid']
                    || (string)$post['fid'] !== (string)$topic['fid'] || !in_array((string)$post['visible'],['0','1'],true)
                    || ($row['status']==='post_confirmed' && (string)$post['visible']!=='1')) {
                    return $this->uncertain($context,'known_post_changed','replay');
                }
                return $this->result($row,$row['status']==='moderation_pending' ? AF_ACS_MybbPostStatus::MODERATION_PENDING : AF_ACS_MybbPostStatus::POST_CONFIRMED,'durable_replay');
            }
            if ($row['status']==='rejected') { return $this->result($row,AF_ACS_MybbPostStatus::REJECTED,'durable_replay'); }
            if (in_array($row['status'],['posting','committing','recovery_required'],true)) {
                return $this->uncertain($context,'native_outcome_unknown','replay');
            }
            if ($row['state_version'] !== $context->submission['state_version']) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            if ($row['status'] !== 'validating') { throw new RuntimeException('ACS_MYBB_VALIDATING_REQUIRED'); }
            $this->allowedTurn($row);
            $runtime=new AF_ACS_MybbNativeReply($this->database,fn()=>$this->boundary());
            $runtime->bootstrap(); $topic=$runtime->preflight($row,$this->access);
            return $runtime->request($context,$topic,function () use ($context,$runtime,$topic,&$row,&$phase,&$dispatch,&$handler): AF_ACS_MybbPostResult {
                $handler=$runtime->validate($context,$topic);
                if (!$handler->get_validated() || $handler->get_errors()) {
                    $errors=[];
                    foreach ($handler->get_errors() as $error) {
                        $key=is_array($error) ? ($error['error_code'] ?? '') : '';
                        if (is_string($key) && preg_match('/\A[a-z0-9_]{1,80}\z/D',$key)) { $errors[]=$key; }
                    }
                    $row=$this->store->rejectBeforePosting($row['submission_id'],$row['state_version'],'native_validation_failed',$this->journalAuthorization($context));
                    return $this->result($row,AF_ACS_MybbPostStatus::REJECTED,'native_validation_failed',$errors);
                }
                $this->allowedTurn($row); $this->boundary();
                $snapshot=json_decode($row['sealed_json'],true,64,JSON_THROW_ON_ERROR);
                $seal=json_decode($snapshot['dependency_json'],true,32,JSON_THROW_ON_ERROR)['seal'] ?? [];
                $controls=['dependency_hash'=>$seal['dependency_hash'] ?? '', 'simulation_hash'=>$seal['simulation_hash'] ?? '',
                    'confirmation_hash'=>$snapshot['confirmation_hash'],'gateway_request_hash'=>hash('sha256',serialize($handler->data))];
                $phase='posting_journal';
                // If this COMMIT/lock release is unknown, catch below quarantines and NEVER dispatches.
                $row=$this->store->posting($row['submission_id'],$row['state_version'],$controls,$this->journalAuthorization($context));
                $phase='native_insert'; $dispatch=true; $armed=true;
                register_shutdown_function(function () use (&$armed,$context,&$phase,&$handler): void {
                    if ($armed) { $this->uncertain($context,'native_request_interrupted',$phase,$handler); }
                });
                try {
                    $this->boundary();
                    $handler->assertFreshReply($row['author_uid'],$row['tid'],(string)$topic['fid']);
                    $reply=$handler->insert_post(); // only the native handler inserts the MyBB post
                    $this->boundary();
                    if (!empty($reply['merge']) || (string)($reply['pid'] ?? '') !== (string)$handler->pid
                        || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)($reply['pid'] ?? ''))
                        || !in_array((string)($reply['visible'] ?? ''),['0','1'],true)) {
                        throw new RuntimeException('ACS_MYBB_RESULT_UNSUPPORTED');
                    }
                    $phase='native_end_hooks'; $runtime->finish($reply,$handler);
                    $phase='pid_confirmation';
                    $row=$this->store->confirmPost($row['submission_id'],$row['state_version'],(string)$reply['pid'],$this->journalAuthorization($context),(string)$topic['fid']);
                    $armed=false;
                    return $this->result($row,$row['status']==='moderation_pending' ? AF_ACS_MybbPostStatus::MODERATION_PENDING : AF_ACS_MybbPostStatus::POST_CONFIRMED,'native_pid_confirmed');
                } finally { $armed=false; }
            });
        } catch (Throwable $error) {
            $code=self::code($error);
            if (!$dispatch && $phase==='preflight' && in_array($code,[
                'acs_mybb_context_mismatch','acs_csrf_required','acs_post_required','acs_login_required',
            ],true)) {
                // Reject this request, never mutate another actor's intent or write through invalid CSRF.
                return $this->result($row,AF_ACS_MybbPostStatus::REJECTED,$code);
            }
            if (!$dispatch && $phase==='preflight' && $row['status']==='validating' && $row['state_version']===$context->submission['state_version']) {
                try {
                    $this->boundary();
                    // Permission failures do not let another actor reject an existing submission.
                    ($this->journalAuthorization($context))($row);
                    $row=$this->store->rejectBeforePosting($row['submission_id'],$row['state_version'],$code,$this->journalAuthorization($context));
                    return $this->result($row,AF_ACS_MybbPostStatus::REJECTED,$code);
                } catch (Throwable $journalError) { /* retain durable phase */ }
            }
            return $this->uncertain($context,$code,$phase,$handler);
        } finally { unset(self::$inFlight[$context->submission['submission_id']]); }
    }
}
