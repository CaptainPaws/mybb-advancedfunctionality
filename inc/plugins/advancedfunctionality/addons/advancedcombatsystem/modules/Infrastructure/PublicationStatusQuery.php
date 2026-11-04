<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SubmissionStore.php';
require_once __DIR__.'/TerminalReceiptPersistence.php';
require_once __DIR__.'/Adapters/SessionAccess.php';

/** Authorized read-only projection for future postbit. Does not load Builder or Resolver. */
final class AF_ACS_PublicationStatusQuery extends AF_ACS_Repository
{
    use AF_ACS_TerminalReceiptPersistence;
    public function forPost(string $pid, ?AF_ACS_SessionAccess $access = null): ?array
    {
        $this->transactions->assertExternalBoundary();$access??=new AF_ACS_SessionAccess;$uid=$access->uid();
        $found=$this->sql('SELECT submission_id FROM '.$this->table('submissions').' WHERE pid=?',[self::integer($pid,'4294967295')])['rows'][0]??null;
        if (!$found) { return null; }
        $store=new AF_ACS_SubmissionStore($this->connection);$row=$store->submission($found['submission_id']);
        $access->thread($row['tid']);
        $roles=$this->sql('SELECT role FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?)',[$row['session_id'],$uid,time()])['rows'];
        if (!$roles) { throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED'); }
        $post=$this->sql('SELECT visible FROM `'.$this->prefix.'posts` WHERE pid=?',[$pid])['rows'][0]??null;
        $privileged=$uid===$row['author_uid'] || (bool)array_intersect(array_column($roles,'role'),['owner','gm']);
        if ((!$post || (string)$post['visible']!=='1') && !$privileged) { return null; }
        $receipt=$this->terminal($row);
        if ($receipt) { $row=$store->submission($row['submission_id']); }
        $card=AF_ACS_Publication::card($receipt?'committed':$row['status']);
        $approvalReady=$row['status']==='moderation_pending' && $post && (string)$post['visible']==='1';
        if ($approvalReady) { $card['label']='Сообщение одобрено, боевой результат ожидает подтверждения'; }
        return ['schema_version'=>1,'submission_id'=>$row['submission_id'],'pid'=>$pid,'state_version'=>$row['state_version']]+$card
            +['result_hash'=>$receipt['result_hash']??null,'approval_ready'=>$approvalReady];
    }
}
