<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/RecoveryService.php';

/** Approval permits author-driven revalidation; the moderator never impersonates the author. */
final class AF_ACS_ModerationService
{
    private AF_ACS_SubmissionStore $store;
    private AF_ACS_CombatCommitWriter $writer;
    private AF_ACS_RecoveryService $recovery;
    private AF_ACS_SessionAccess $access;
    public function __construct(private object $database, ?AF_ACS_SessionAccess $access = null)
    {
        $this->access=$access??new AF_ACS_SessionAccess;
        $this->store=new AF_ACS_SubmissionStore($database);
        $this->writer=new AF_ACS_CombatCommitWriter($database,$this->access);
        $this->recovery=new AF_ACS_RecoveryService($database,$this->access);
    }

    public function resumeApproved(string $id, string $expectedVersion): array
    {
        $this->store->assertMybbBoundary($this->database);$this->access->csrf();
        $row=$this->store->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        if ($this->access->uid()!==$row['author_uid']) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        $this->access->thread($row['tid']);
        if ($this->writer->receipt($id)) { return $this->recovery->inspect($id); }
        if ($row['state_version']!==AF_ACS_Repository::integer($expectedVersion)) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        if ($row['status']!=='moderation_pending') { return $this->recovery->recover($id,$expectedVersion); }
        $post=$this->store->recoveryPost($id);
        if ($post['state']==='moderation_pending') { return $this->recovery->inspect($id); }
        if ($post['state']!=='confirmed') {
            $this->store->recoveryQuarantine($id,$row['state_version']);return $this->recovery->inspect($id);
        }
        try {
            require_once __DIR__.'/../Bootstrap/PostLifecycleHooks.php';
            if (!AF_ACS_PostLifecycleHooks::supported()) { throw new RuntimeException('ACS_MODERATION_BINDING_UNVERIFIED'); }
            $controls=$this->writer->validateApprovedPost($id,$row['state_version']);
            $row=$this->store->confirmApprovedPost($id,$row['state_version'],$controls);
        } catch (Throwable $error) {
            if ($this->writer->receipt($id)) { return $this->recovery->inspect($id); }
            $current=$this->store->submission($id);
            // Never quarantine a newer successful confirmation based on a stale preflight.
            if ($current['state_version']!==$row['state_version']) { throw $error; }
            if ($current['status']==='moderation_pending') {
                $reason=match($error->getMessage()) {
                    'ACS_MODERATION_BINDING_UNVERIFIED'=>'approval_source_unverified',
                    'ACS_COMBAT_CONFIRMATION_CONFLICT','ACS_COMBAT_DEPENDENCY_CONFLICT'=>'approval_state_conflict',
                    'ACS_COMBAT_CONTROL_DENIED','ACS_COMBAT_PERMISSION_DENIED'=>'approval_permission_denied',
                    default=>'approval_revalidation_failed',
                };
                $this->store->recoveryQuarantine($id,$current['state_version'],$reason);
            }
            return $this->recovery->inspect($id);
        }
        return $this->recovery->recover($id,$row['state_version']);
    }
}
