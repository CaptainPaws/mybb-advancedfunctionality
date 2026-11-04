<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/CombatCommitWriter.php';

/** Server-only recovery commands. Never loads/calls Gateway or creates a MyBB post. */
final class AF_ACS_RecoveryService
{
    private AF_ACS_SubmissionStore $store;
    private AF_ACS_EncounterRepository $preparation;
    private AF_ACS_CombatCommitWriter $writer;
    private AF_ACS_SessionAccess $access;

    public function __construct(private object $database, ?AF_ACS_SessionAccess $access = null)
    {
        $this->access=$access??new AF_ACS_SessionAccess;
        $this->store=new AF_ACS_SubmissionStore($database);
        $this->preparation=new AF_ACS_EncounterRepository($database);
        $this->writer=new AF_ACS_CombatCommitWriter($database,$this->access);
    }

    private function context(string $id, bool $gmOnly = false): array
    {
        $this->store->assertMybbBoundary($this->database);
        $uid=$this->access->uid();
        $row=$this->store->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        $this->access->thread($row['tid']);
        $roles=array_column($this->preparation->roles($row['session_id'],$uid),'role');
        $gm=(bool)array_intersect($roles,['owner','gm']);
        if (!$roles || (!$gm && ($gmOnly || $uid!==$row['author_uid']))) { throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED'); }
        return [$row,$gm,$uid];
    }

    private function projection(array $row, bool $gm, ?array $receipt): array
    {
        $post=$this->store->recoveryPost($row['submission_id']);
        $result=['schema_version'=>1,'submission_id'=>$row['submission_id'],'status'=>$receipt?'COMMITTED':strtoupper($row['status']),
            'last_phase'=>$row['last_phase'],'state_version'=>$row['state_version'],'pid'=>$row['pid'],
            'mechanics_confirmed'=>$receipt!==null,'receipt'=>$receipt,'post'=>$post,
            'requires_master'=>$row['status']==='recovery_required','can_resume_as_author'=>$row['status']==='post_confirmed' && $post['state']==='confirmed' && $row['author_uid']===$this->access->uid(),
            'available_commands'=>[]];
        if ($gm) {
            $result['context']=array_intersect_key($row,array_flip(['session_id','encounter_id','turn_id','draft_id','author_uid','tid','request_hash']));
            $result['context']['sealed_hash']=hash('sha256',$row['sealed_json']);
            $result['audit']=$this->store->recoveryAudit($row['submission_id']);$result['candidates']=[];
            foreach ($result['audit'] as $a) {
                $e=json_decode($a['evidence_json'],true,32,JSON_THROW_ON_ERROR);
                if (isset($e['gateway']['observed_pid'])) {
                    $pid=(string)$e['gateway']['observed_pid'];
                    $result['candidates'][$pid]=['pid'=>$pid,'identity_proven'=>false,'source'=>'native_diagnostic_observation'];
                }
            }
            $result['candidates']=array_values($result['candidates']);
            if ($row['status']==='recovery_required') {
                $result['available_commands'][]='hold';
                if (in_array($post['state'],['confirmed','moderation_pending'],true)) { $result['available_commands'][]='resume_known_post'; }
                if ($row['pid']===null && in_array($row['last_phase'],['registered','validating'],true)) { $result['available_commands'][]='reject_unsent'; }
            }
        }
        return $result;
    }

    /** Read-only authenticated lookup. A changed/deleted committed post does not undo the receipt. */
    public function inspect(string $id): array
    {
        [$row,$gm]=$this->context($id);
        $receipt=$this->writer->receiptForRecovery($id);
        // Refresh the journal after receipt lookup, which may observe a concurrent commit.
        [$row,$gm]=$this->context($id);
        if ($row['status']==='committed' && !$receipt) { $receipt=$this->writer->receiptForRecovery($id); }
        return $this->projection($row,$gm,$receipt);
    }

    /** Receipt first; only the original authenticated author may invoke the mechanical writer. */
    public function recover(string $id, string $expectedStateVersion): array
    {
        $this->access->csrf();[$row,$gm,$uid]=$this->context($id);
        if ($receipt=$this->writer->receiptForRecovery($id)) { return $this->inspect($id); }
        if ($row['state_version']!==AF_ACS_Repository::integer($expectedStateVersion)) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        if (in_array($row['status'],['rejected','registered','validating','recovery_required'],true)) { return $this->inspect($id); }
        $post=$this->store->recoveryPost($id);
        if ($row['status']==='moderation_pending') {
            if ($post['state']==='moderation_pending' || ($post['state']==='confirmed' && $uid!==$row['author_uid'])) { return $this->inspect($id); }
            if ($post['state']==='confirmed') {
                require_once __DIR__.'/ModerationService.php';
                return (new AF_ACS_ModerationService($this->database,$this->access))->resumeApproved($id,$expectedStateVersion);
            }
        }
        if ($row['status']!=='post_confirmed' || $post['state']!=='confirmed') {
            $this->store->recoveryQuarantine($id,$row['state_version']);return $this->inspect($id);
        }
        if ($uid!==$row['author_uid']) { return $this->inspect($id); }
        try {
            $this->writer->commit($id,$row['state_version']);
        } catch (Throwable $error) {
            // Unknown acknowledgement: never infer rollback solely from an exception.
            if ($receipt=$this->writer->receiptForRecovery($id)) { return $this->inspect($id); }
            [$current]=$this->context($id);
            if ($current['status']==='post_confirmed') { $this->store->recoveryQuarantine($id,$current['state_version']); }
        }
        return $this->inspect($id);
    }

    /** Manual CAS decision, with persisted owner/GM checks repeated under the journal lock. */
    public function resolve(string $id, string $expectedStateVersion, string $strategy, string $reason): array
    {
        $this->access->csrf();$this->context($id,true);
        // No pid input: unknown native identity cannot be proven by selecting a candidate.
        $this->store->resolveRecovery($id,$expectedStateVersion,$strategy,$reason);
        return $this->inspect($id);
    }
}
