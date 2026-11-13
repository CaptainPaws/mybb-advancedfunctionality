<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';

/** Read-only originating principal of an immutable server-issued obligation. Never Inventory authority. */
final class AF_ACS_PreparedExecutionAccess extends AF_ACS_SessionAccess
{
    public function __construct(private AF_ACS_SessionAccess $native,private string $principal) {}
    public function uid():string { return $this->principal; }
    public function csrf():void { throw new RuntimeException('ACS_PREPARED_READ_ONLY'); }
    public function administrator():bool { return false; }
    public function canCreate():bool { return false; }
    public function user(string $uid):array { return $this->native->user($uid); }
    public function thread(string $tid,?string $uid=null):array { return $this->native->thread($tid,$uid??$this->principal); }
    public function draftThread(string $tid):void
    {
        $t=$this->thread($tid);$u=$this->user($this->principal);$p=forum_permissions((int)$t['fid'],(int)$this->principal);
        if(empty($p['canpostreplys'])||!empty($u['suspendposting'])||!empty($u['isbannedgroup'])||(!empty($p['canonlyreplyownthreads'])&&(string)$t['uid']!==$this->principal)||(!empty($t['closed'])&&!is_moderator((int)$t['fid'],'canpostclosedthreads',(int)$this->principal)))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
    }
}
