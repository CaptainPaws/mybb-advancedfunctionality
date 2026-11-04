<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/PublishService.php';
require_once __DIR__.'/RecoveryService.php';
require_once __DIR__.'/SealService.php';

/** One native reply form, server-owned draft/actions and existing publication protocol. */
final class AF_ACS_EditorPublicationService extends AF_ACS_Repository
{
    private AF_ACS_EncounterRepository $preparation;
    private AF_ACS_SubmissionStore $store;
    private AF_ACS_PublishService $publish;
    private AF_ACS_SessionAccess $access;
    public function __construct(private object $database, ?AF_ACS_SessionAccess $access = null)
    {
        parent::__construct($database);$this->access=$access??new AF_ACS_SessionAccess;
        $this->preparation=new AF_ACS_EncounterRepository($database);$this->store=new AF_ACS_SubmissionStore($database);
        $this->publish=new AF_ACS_PublishService($database,$this->access);
    }
    public static function prose(string $value): string { return str_replace(["\r\n","\r"],"\n",$value); }
    private function draft(string $id,string $tid): array
    {
        $this->store->assertMybbBoundary($this->database);$uid=$this->access->uid();
        $d=$this->preparation->draft(self::integer($id),$uid)??throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $s=$this->preparation->session($d['session_id']);
        if (!$s || (string)$s['tid']!==self::integer($tid,'4294967295')) { throw new RuntimeException('ACS_DRAFT_UNAVAILABLE'); }
        $this->access->thread($tid);
        if (!$this->preparation->roles($d['session_id'],$uid)) { throw new RuntimeException('ACS_DRAFT_UNAVAILABLE'); }
        return $d;
    }
    private function ownedSubmission(string $id,string $tid): array
    {
        $r=$this->store->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        $this->draft($r['draft_id'],$tid);
        if ($r['author_uid']!==$this->access->uid() || $r['tid']!==$tid) { throw new RuntimeException('ACS_COMBAT_PERMISSION_DENIED'); }
        return $r;
    }
    public function status(string $id,string $tid): array
    {
        $this->ownedSubmission($id,$tid);
        $data=(new AF_ACS_RecoveryService($this->database,$this->access))->inspect($id);
        return array_intersect_key($data,array_flip(['schema_version','submission_id','status','state_version','pid','mechanics_confirmed','receipt']));
    }
    public function compose(string $id,string $tid): array
    {
        $d=$this->draft($id,$tid);
        $operation=null;$summary=null;$confirmed=false;
        if (in_array($d['status'],['publishing','published'],true)) {
            $r=$this->sql('SELECT submission_id FROM '.$this->table('submissions')." WHERE draft_id=? AND author_uid=? AND status<>'rejected' ORDER BY created_at DESC,submission_id DESC LIMIT 1",[$id,$this->access->uid()])['rows'][0]??null;
            if (!$r) { throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND'); }
            $operation=$this->status($r['submission_id'],$tid);
            if ($d['status']==='publishing') {
                $view=(new AF_ACS_SealService($this->preparation,$this->access))->status($id);
                if ($view['confirmed']&&!$view['blocked']) { $summary=$view['summary'];$confirmed=true; }
            }
        } else {
            (new AF_ACS_DraftService($this->preparation,$this->access))->restore($id);
            $view=(new AF_ACS_SealService($this->preparation,$this->access))->status($id);
            $summary=$view['summary'];$confirmed=$view['confirmed']&&!$view['blocked'];
        }
        return ['schema_version'=>1,'tid'=>$tid,'author_uid'=>$this->access->uid(),'draft_id'=>$d['id'],'draft_version'=>$d['draft_version'],
            'session_id'=>$d['session_id'],'encounter_id'=>$d['encounter_id'],'turn_id'=>$d['turn_id'],'prose'=>$d['prose'],
            'actions'=>json_decode($d['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions'],
            'confirmation_hash'=>$d['confirmation_hash'],'confirmed'=>$confirmed,'summary'=>$summary,'operation'=>$operation];
    }
    public function assertNoPendingAttachments(string $posthash): void
    {
        if ($posthash==='') { return; }
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$posthash)
            || $this->sql('SELECT aid FROM `'.$this->prefix.'attachments` WHERE pid=0 AND posthash=? LIMIT 1',[$posthash])['rows']) {
            throw new RuntimeException('ACS_MYBB_ATTACHMENTS_UNSUPPORTED');
        }
    }
    public function prepare(string $id,string $tid,string $version,string $confirmation,string $message): array
    {
        $this->access->csrf();$d=$this->draft($id,$tid);
        if (self::prose($message)!==self::prose($d['prose'])) { throw new RuntimeException('ACS_EDITOR_SAVE_REQUIRED'); }
        $summary=null;
        if (!in_array($d['status'],['publishing','published'],true)) {
            $view=(new AF_ACS_SealService($this->preparation,$this->access))->status($id);
            if (!$view['confirmed'] || $view['blocked'] || !hash_equals($view['confirmation_hash']??'',$confirmation)) { throw new RuntimeException('ACS_CONFIRMATION_REQUIRED'); }
            $summary=$view['summary'];
        }
        $r=$this->publish->issue(['draft_id'=>$id,'expected_draft_version'=>$version,'confirmation_hash'=>$confirmation]);
        return ['operation'=>$this->status($r['submission_id'],$tid),'summary'=>$summary];
    }
    public function publish(string $id,string $tid,string $message,array $options): array
    {
        $this->access->csrf();$r=$this->ownedSubmission($id,$tid);
        $snapshot=json_decode($r['sealed_json'],true,64,JSON_THROW_ON_ERROR);
        if (self::prose($message)!==self::prose($snapshot['prose'])) { throw new RuntimeException('ACS_EDITOR_FROZEN_PROSE_CHANGED'); }
        return $this->publish->publish($id,$options);
    }
}
