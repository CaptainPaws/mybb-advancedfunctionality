<?php
if (!defined('IN_MYBB')) {die('No direct access');}
trait AF_ACS_ArchivePersistence
{
    public function archivePolicy(string $sid,string $eid):array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('archive_policies').' WHERE session_id=? AND encounter_id=?',[$sid,$eid])['rows'][0]??null;
        if(!$r)return ['mode'=>'keep_hidden','policy_version'=>'0'];
        $receipt=$this->rewardCommandReceipt($sid,$eid,'archive.policy','$.archive_policy.policy_version',(string)$r['policy_version']);
        if(!$receipt||($receipt['result']['event_id']??null)!==(string)$r['event_id']||($receipt['result']['archive_policy']['mode']??null)!==$r['mode'])throw new RuntimeException('ACS_ARCHIVE_POLICY_INVALID');
        return ['mode'=>$r['mode'],'policy_version'=>(string)$r['policy_version']];
    }
    public function setArchivePolicy(array $s,string $eid,string $version,string $mode):array
    {
        $this->commandWrite();$e=$this->encounter($s['id'],$eid,true)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        if($e['status']!=='completed'||!$this->confirmedOutcome($s['id'],$eid))throw new RuntimeException('ACS_ARCHIVE_COMPLETED_REQUIRED');
        $old=$this->archivePolicy($s['id'],$eid);if($old['policy_version']!==$version)throw new RuntimeException('ACS_VERSION_CONFLICT');$next=(string)((int)self::integer($version,'9007199254740990',true)+1);
        if($version==='0')$this->sql('INSERT INTO '.$this->table('archive_policies').' (session_id,encounter_id,mode,policy_version,event_id) VALUES (?,?,?,?,NULL)',[$s['id'],$eid,$mode,$next]);
        else if($this->sql('UPDATE '.$this->table('archive_policies').' SET mode=?,policy_version=?,event_id=NULL WHERE encounter_id=? AND policy_version=?',[$mode,$next,$eid,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $s['_command_result']=['encounter_id'=>$eid,'archive_policy'=>['mode'=>$mode,'policy_version'=>$next]];return $s;
    }
    public function linkArchivePolicy(array $s,string $event):array
    {$this->transactions->assertInternalBoundary();$this->sql('UPDATE '.$this->table('archive_policies').' SET event_id=? WHERE encounter_id=? AND event_id IS NULL',[$event,$s['_command_result']['encounter_id']]);return $s;}
}
