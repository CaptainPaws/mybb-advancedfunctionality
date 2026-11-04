<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/Adapters/SessionAccess.php';

/** Lazy GM queue; selecting it never changes a publication. */
final class AF_ACS_RecoveryQueueQuery extends AF_ACS_Repository
{
    public function context(string $sid, ?AF_ACS_SessionAccess $access = null): array
    {
        $this->transactions->assertExternalBoundary();$access??=new AF_ACS_SessionAccess;$uid=$access->uid();
        $s=$this->session(self::integer($sid))??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$access->thread((string)$s['tid']);
        $roles=$this->sql('SELECT role FROM '.$this->table('session_roles')." WHERE session_id=? AND uid=? AND role IN ('owner','gm') AND (expires_at IS NULL OR expires_at>?)",[$sid,$uid,time()])['rows'];
        if(!$roles)throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
        return ['session_id'=>$sid,'tid'=>(string)$s['tid']];
    }
    public function page(string $sid,string $after='',?AF_ACS_SessionAccess $access=null): array
    {
        $context=$this->context($sid,$access);
        if($after!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$after))throw new InvalidArgumentException('ACS_SUBMISSION_ID_INVALID');
        $rows=$this->sql('SELECT submission_id,status,last_phase,state_version,pid,turn_id,updated_at FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') AND submission_id>? ORDER BY submission_id LIMIT 21",[$sid,$after])['rows'];
        $more=count($rows)>20;if($more)array_pop($rows);
        foreach($rows as &$r)foreach(['state_version','pid','turn_id','updated_at'] as $k)if($r[$k]!==null)$r[$k]=(string)$r[$k];unset($r);
        return $context+['items'=>$rows,'next_after'=>$more?end($rows)['submission_id']:null];
    }
    public function requireSubmission(string $sid,string $id,?AF_ACS_SessionAccess $access=null): void
    {
        $this->context($sid,$access);
        $r=$this->sql('SELECT submission_id FROM '.$this->table('submissions').' WHERE session_id=? AND submission_id=?',[$sid,$id])['rows'];
        if(!$r)throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
    }
}
