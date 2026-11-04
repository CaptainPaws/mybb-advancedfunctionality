<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Recovery uses the existing journal/lock/audit. No MyBB writes or candidate binding. */
trait AF_ACS_PublicationRecoveryPersistence
{
    private function recoveryActor(array $row, bool $gm, bool $lock = false): string
    {
        $uid=self::integer((string)(int)($GLOBALS['mybb']->user['uid']??0),'4294967295');
        $roles=array_column($this->sql('SELECT role FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?)'.($lock?' FOR UPDATE':''),[$row['session_id'],$uid,time()])['rows'],'role');
        if (!$roles || (($gm || $uid!==$row['author_uid']) && !array_intersect($roles,['owner','gm']))) {
            throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
        }
        return $uid;
    }

    /** Only original, durable confirmation is identity evidence. Observed_pid is never promoted. */
    private function knownPostState(array $row): array
    {
        if ($row['pid']===null) { return ['state'=>'unknown','pid'=>null]; }
        $proof=null;
        $audit=$this->sql('SELECT evidence_json FROM '.$this->table('publication_audit')." WHERE submission_id=? AND reason='native_pid_readback' ORDER BY id",[$row['submission_id']])['rows'];
        foreach ($audit as $event) {
            $e=json_decode($event['evidence_json'],true,32,JSON_THROW_ON_ERROR);
            if (($e['pid']??null)===$row['pid'] && is_string($e['fid']??null) && preg_match('/\A[1-9][0-9]*\z/D',$e['fid'])) { $proof=$e;break; }
        }
        $p=$this->sql('SELECT p.uid,p.tid,p.fid,p.visible,t.fid AS thread_fid,t.visible AS thread_visible,f.open AS forum_open,f.type AS forum_type FROM `'.$this->prefix.'posts` p LEFT JOIN `'.$this->prefix.'threads` t ON t.tid=p.tid LEFT JOIN `'.$this->prefix.'forums` f ON f.fid=t.fid WHERE p.pid=?',[$row['pid']])['rows'][0]??null;
        if (!$p) { return ['state'=>'missing','pid'=>$row['pid']]; }
        if ((string)$p['uid']!==$row['author_uid'] || (string)$p['tid']!==$row['tid']) { return ['state'=>'identity_conflict','pid'=>$row['pid']]; }
        if (!$proof) { return ['state'=>'identity_evidence_missing','pid'=>$row['pid']]; }
        if ((string)$p['fid']!==$proof['fid']
            || (string)$p['thread_fid']!==$proof['fid']) { return ['state'=>'identity_conflict','pid'=>$row['pid']]; }
        if ((string)$p['thread_visible']!=='1' || (string)$p['forum_open']!=='1' || $p['forum_type']!=='f'
            || !in_array((string)$p['visible'],['0','1'],true)) { return ['state'=>'unavailable','pid'=>$row['pid']]; }
        return ['state'=>(string)$p['visible']==='1'?'confirmed':'moderation_pending','pid'=>$row['pid'],'fid'=>$proof['fid'],'visible'=>(string)$p['visible']];
    }

    public function recoveryPost(string $id): array
    {
        $this->transactions->assertExternalBoundary();
        $row=$this->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        $this->recoveryActor($row,false);
        return $this->knownPostState($row);
    }

    /** GM-only diagnostic audit. No text/time/author search and no post contents are exposed. */
    public function recoveryAudit(string $id): array
    {
        $row=$this->submission($id)??throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');$this->recoveryActor($row,true);
        $rows=$this->sql('SELECT state_version,from_status,to_status,actor_uid,reason,evidence_json,created_at FROM '.$this->table('publication_audit').' WHERE submission_id=? ORDER BY id DESC LIMIT 30',[$id])['rows'];
        foreach ($rows as &$event) { foreach (['state_version','actor_uid','created_at'] as $key) { $event[$key]=(string)$event[$key]; } }unset($event);
        return $rows;
    }

    public function recoveryQuarantine(string $id, string $version, string $reason = 'recovery_reconciliation_required'): array
    {
        self::key($reason,64);
        $actor=self::integer((string)(int)($GLOBALS['mybb']->user['uid']??0));
        return $this->change($id,$version,function($r){$this->recoveryActor($r,false,true);},static function($r)use($reason){
            return ['recovery_required',$reason,['schema_version'=>1,'last_known_phase'=>$r['last_phase']],null];
        },$actor);
    }

    /** A manual command never accepts a replacement pid or changes sealed state. */
    public function resolveRecovery(string $id, string $version, string $strategy, string $reason): array
    {
        if (!in_array($strategy,['resume_known_post','reject_unsent','hold'],true) || trim($reason)==='' || strlen($reason)>1024) {
            throw new InvalidArgumentException('ACS_RECOVERY_COMMAND_INVALID');
        }
        $actor=self::integer((string)(int)($GLOBALS['mybb']->user['uid']??0));
        return $this->change($id,$version,function($r){$this->recoveryActor($r,true,true);},function($r)use($strategy,$reason){
            if ($r['status']!=='recovery_required') { throw new RuntimeException('ACS_RECOVERY_STATE_REQUIRED'); }
            if (!hash_equals($r['request_hash'],hash('sha256',$r['request_json']))) { throw new RuntimeException('ACS_COMBAT_REQUEST_CORRUPT'); }
            $e=['schema_version'=>1,'manual'=>true,'strategy'=>$strategy,'reason'=>$reason];
            if ($strategy==='hold') { return ['recovery_required','gm_recovery_hold',$e,null]; }
            if ($strategy==='reject_unsent') {
                $danger=$this->sql('SELECT id FROM '.$this->table('publication_audit')." WHERE submission_id=? AND to_status IN ('posting','post_confirmed','moderation_pending','committing','committed') LIMIT 1",[$r['submission_id']])['rows'];
                if ($r['pid']!==null || !in_array($r['last_phase'],['registered','validating'],true) || $danger) { throw new RuntimeException('ACS_POST_OUTCOME_UNKNOWN'); }
                $e['proof']='native_dispatch_not_started';
                return ['rejected','gm_recovery_reject_unsent',$e,null];
            }
            $post=$this->knownPostState($r);
            if (!in_array($post['state'],['confirmed','moderation_pending'],true)) { throw new RuntimeException('ACS_RECOVERY_POST_NOT_PROVEN'); }
            $e+=['pid'=>$post['pid'],'fid'=>$post['fid'],'visible'=>$post['visible'],'proof'=>'durable_native_confirmation'];
            return [$post['state']==='confirmed'?'post_confirmed':'moderation_pending','gm_recovery_resume_known',$e,$post['pid']];
        },$actor,$strategy==='hold');
    }
}
