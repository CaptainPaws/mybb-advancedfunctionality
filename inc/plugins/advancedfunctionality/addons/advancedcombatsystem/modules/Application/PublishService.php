<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/CombatCommitWriter.php';
require_once __DIR__.'/../Infrastructure/Adapters/MybbPostGateway.php';

/** Server API only. The future native entry binding must own the entire reply request. */
final class AF_ACS_PublishService
{
    private AF_ACS_SubmissionStore $store;
    private AF_ACS_EncounterRepository $preparation;
    private AF_ACS_CombatCommitWriter $writer;
    private AF_ACS_MybbPostGateway $gateway;
    private AF_ACS_SessionAccess $access;
    private static array $inFlight=[];

    public function __construct(private object $database, ?AF_ACS_SessionAccess $access = null)
    {
        $this->access=$access??new AF_ACS_SessionAccess;
        $this->store=new AF_ACS_SubmissionStore($database);
        $this->preparation=new AF_ACS_EncounterRepository($database);
        $this->writer=new AF_ACS_CombatCommitWriter($database,$this->access);
        $this->gateway=new AF_ACS_MybbPostGateway($this->store,$this->preparation,$this->access,$database);
    }

    private function own(string $id): array
    {
        $this->store->assertMybbBoundary($this->database);$this->access->csrf();
        $row=$this->store->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        if ($row['author_uid']!==$this->access->uid()) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        $this->access->thread($row['tid']);
        if (!$this->preparation->roles($row['session_id'],$row['author_uid'])) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        return $row;
    }

    /** Only scalar identity checks inside short journal transactions; no native permission hooks. */
    private function authorization(array $expected): Closure
    {
        return static function(array $row) use ($expected): void {
            foreach (['submission_id','author_uid','request_hash','sealed_json'] as $key) {
                if ($row[$key]!==$expected[$key]) { throw new RuntimeException('ACS_SUBMISSION_CONFLICT'); }
            }
        };
    }

    /** Issue/reuse server identity for the same confirmed draft version, even after response loss. */
    public function issue(array $request): array
    {
        $this->store->assertMybbBoundary($this->database);$this->access->csrf();
        if (array_diff(array_keys($request),['draft_id','expected_draft_version','confirmation_hash'])) { throw new InvalidArgumentException('ACS_SUBMISSION_INPUT_INVALID'); }
        $uid=$this->access->uid();
        $draft=$this->preparation->draft(AF_ACS_Repository::integer($request['draft_id']??null),$uid)??throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $session=$this->preparation->session($draft['session_id'])??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $this->access->draftThread($session['tid']);
        if (!$this->preparation->roles($draft['session_id'],$uid)) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        $row=$this->store->issue(['schema_version'=>1,'author_uid'=>$uid]+$request,static function(array $current) use ($draft,$uid): void {
            if ($current['author_uid']!==$uid || $current['session_id']!==$draft['session_id'] || $current['turn_id']!==$draft['turn_id']) {
                throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED');
            }
        });
        return $this->projection($row,$row['status']==='committed'?$this->writer->receipt($row['submission_id']):null);
    }

    private function projection(array $row, ?array $receipt = null, string $code = '', array $errors = []): array
    {
        return ['schema_version'=>1,'submission_id'=>$row['submission_id'],'status'=>$receipt?'COMMITTED':strtoupper($row['status']),
            'state_version'=>$row['state_version'],'pid'=>$row['pid'],'mechanics_confirmed'=>$receipt!==null,
            'receipt'=>$receipt,'code'=>$code,'validation_errors'=>$errors];
    }

    /** No route registration or enablement. Options are native form choices, never mechanical data. */
    public function publish(string $id, array $options = []): array
    {
        $row=$this->own($id);
        if ($receipt=$this->writer->receipt($id)) { return $this->projection($this->own($id),$receipt); }
        if (isset(self::$inFlight[$id])) { return $this->projection($row,null,'ACS_PUBLICATION_IN_PROGRESS'); }
        if (in_array($row['status'],['rejected','moderation_pending','recovery_required'],true)) { return $this->projection($row); }
        self::$inFlight[$id]=true;
        try {
            if (in_array($row['status'],['registered','validating'],true)) {
                try {
                    $this->writer->validatePublication($id,$row['state_version']);
                } catch (Throwable $error) {
                    // Only the version we actually checked can be rejected before native dispatch.
                    $row=$this->store->rejectBeforePosting($id,$row['state_version'],'prepublication_validation_failed',$this->authorization($row));
                    return $this->projection($row,null,'ACS_PUBLICATION_VALIDATION_FAILED');
                }
                if ($row['status']==='registered') {
                    $row=$this->store->validating($id,$row['state_version'],$this->authorization($row));
                }
            }
            // Gateway owns POSTING, its shutdown guard and the single supported native lifecycle.
            $native=$this->gateway->publish(AF_ACS_MybbPostContext::fromStore($this->store,$id,$row['state_version'],$options));
            $row=$this->own($id);
            if ($native->status!==AF_ACS_MybbPostStatus::POST_CONFIRMED) {
                return $this->projection($row,null,$native->code,$native->validationErrors);
            }
            try {
                $receipt=$this->writer->commit($id,$row['state_version']);
                return $this->projection($this->own($id),$receipt);
            } catch (Throwable $error) {
                // A failed acknowledgement may follow a successful commit. Receipt is authoritative.
                if ($receipt=$this->writer->receipt($id)) { return $this->projection($this->own($id),$receipt); }
                $row=$this->own($id);
                if ($row['status']==='post_confirmed') {
                    $row=$this->store->quarantine($id,$row['state_version'],'combat_commit_failed',$this->authorization($row));
                }
                return $this->projection($row,null,'ACS_COMBAT_COMMIT_RECOVERY_REQUIRED');
            }
        } catch (Throwable $error) {
            // Permission/runtime failures can also occur just after native confirmation.
            // An authoritative nonterminal row plus CAS cannot overwrite a committed receipt.
            $this->store->assertMybbBoundary($this->database);
            $current=$this->store->submission($id);
            if ($current && $current['status']==='post_confirmed') {
                $current=$this->store->quarantine($id,$current['state_version'],'publication_pipeline_failed',$this->authorization($row));
                return $this->projection($current,null,'ACS_PUBLICATION_RECOVERY_REQUIRED');
            }
            throw $error;
        } finally { unset(self::$inFlight[$id]); }
    }
}
