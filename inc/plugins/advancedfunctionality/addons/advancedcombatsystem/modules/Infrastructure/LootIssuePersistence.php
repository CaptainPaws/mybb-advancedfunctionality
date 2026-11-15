<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/LootIssue.php';
require_once __DIR__.'/OperationStore.php';

trait AF_ACS_LootIssuePersistence
{
    /** Non-locking evidence reads are safe under owner guard; never acquire ACS locks from owner. */
    public function lootIssueExternalBoundary():void {$this->transactions->assertExternalBoundary();}
    public function lootIssueFacts(string $sid,string $eid,string $id):array
    {
        $entry=$this->allocationEntry($sid,$eid,$id);$pool=$this->lootPool($sid,$eid,$entry['source_key']);$this->lootReplay($sid,$eid,$entry['source_key']);
        if(!$pool||($this->confirmedOutcome($sid,$eid)['outcome_id']??null)!==$pool['generation']['outcome']['outcome_id'])throw new RuntimeException('ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');
        $allocation=$this->allocationReceipt($sid,$eid,$entry)['allocation']??throw new RuntimeException('ACS_LOOT_ISSUE_NOT_ALLOCATED');
        if($allocation['status']!=='awaiting_issue'||$entry['recipient_uid']===null||$entry['recipient_group_id']===null||!in_array($entry['status'],['awaiting_issue','pending_issue','issue_rejected','issued'],true))throw new RuntimeException('ACS_LOOT_ISSUE_NOT_ALLOCATED');
        $generated=null;foreach($pool['generation']['entries'] as $g)if($g['entry_id']===$id)$generated=$g;
        if(!$generated)throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        return ['session_id'=>$sid,'encounter_id'=>$eid,'entry'=>$entry,'pool'=>$pool,'allocation'=>$allocation,'generated'=>$generated];
    }
    public function lootIssueIntent(string $sid,string $eid,string $id):array
    {
        $facts=$this->lootIssueFacts($sid,$eid,$id);$key=$facts['entry']['issue_operation_key'];
        if($key===null||$key!==AF_ACS_LootIssue::key($facts['entry']))throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        $row=(new AF_ACS_OperationStore($this->connection))->get($key)??throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        if($row['kind']!=='inventory.grant'||$row['session_id']!==$sid||$row['external_system']!=='advancedinventory'||$row['external_ref']!==$key||!hash_equals($row['payload_hash'],hash('sha256',$row['request_json'])))throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        $intent=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_LootIssue::binding($facts,$intent);
        $prepare=$this->lootIssuePrepareReceipt($sid,$eid,$id);
        if(($prepare['issue']['intent_hash']??null)!==hash('sha256',AF_ADVINV_OwnerProtocol::canonical($intent))||($prepare['issue']['issuer_uid']??null)!==(string)$intent['actor']['uid']||($prepare['issue']['item_snapshot_id']??null)!==$facts['entry']['item_snapshot_id'])throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        return $facts+['operation'=>$row,'intent'=>$intent,'prepare'=>$prepare];
    }
    private function lootIssuePrepareReceipt(string $sid,string $eid,string $id):array
    {
        $rows=$this->sql('SELECT e.id AS event_id,e.actor_uid,o.result_json,o.request_json,o.payload_hash,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('operations').' o JOIN '.$this->table('events').' e ON e.operation_key=o.operation_key AND e.event_type=o.kind AND e.session_id=o.session_id AND e.encounter_id=o.encounter_id JOIN '.$this->table('event_details')." d ON d.event_id=e.id WHERE o.session_id=? AND o.encounter_id=? AND o.kind='loot.issue_prepare' AND o.status='committed' AND JSON_UNQUOTE(JSON_EXTRACT(o.result_json,'$.issue.entry_id'))=? LIMIT 2",[$sid,$eid,$id])['rows'];
        if(count($rows)!==1)throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');$row=$rows[0];
        if(!hash_equals($row['payload_hash'],hash('sha256',$row['request_json']))||!hash_equals($row['content_hash'],hash('sha256',$row['payload_json']."\n".$row['visibility_json'])))throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        $result=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);$details=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);if(($result['session_id']??null)!==$sid||($result['encounter_id']??null)!==$eid||($result['event_id']??null)!==(string)$row['event_id']||($result['issue']['issuer_uid']??null)!==(string)$row['actor_uid']||($details['request']??null)!==json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR)||($details['result']['issue']??null)!==($result['issue']??null))throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');return $result;
    }
    public function lootIssueReplay(string $sid,string $eid,string $id,string $version,string $actor):?array
    {
        $entry=$this->allocationEntry($sid,$eid,$id);if($entry['issue_operation_key']===null)return null;
        $saved=$this->lootIssueIntent($sid,$eid,$id);if($entry['allocation_version']!==$version)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if((string)$saved['intent']['actor']['uid']!==$actor)throw new RuntimeException('ACS_LOOT_ISSUER_REQUIRED');return $saved['prepare'];
    }
    public function prepareLootIssue(array $session,string $eid,string $id,string $version,array $intent):array
    {
        $this->commandWrite();$facts=$this->lootIssueFacts($session['id'],$eid,$id);$e=$facts['entry'];
        if($e['allocation_version']!==$version)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($e['status']!=='awaiting_issue'||$e['issue_operation_key']!==null)throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
        AF_ACS_LootIssue::binding($facts,$intent);
        if($this->sql('UPDATE '.$this->table('loot_entries')." SET status='pending_issue',issue_operation_key=? WHERE id=? AND allocation_version=? AND status='awaiting_issue' AND issue_operation_key IS NULL",[$intent['operation_key'],$id,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        (new AF_ACS_OperationStore($this->connection))->inventoryRegisterLoot($session,$intent);
        $session['_command_result']=['encounter_id'=>$eid,'issue'=>['entry_id'=>$id,'pool_id'=>$e['pool_id'],'allocation_version'=>$version,'recipient_uid'=>$e['recipient_uid'],'item_snapshot_id'=>$e['item_snapshot_id'],'qty'=>$facts['generated']['qty'],'issuer_uid'=>(string)$intent['actor']['uid'],'operation_key'=>$intent['operation_key'],'payload_hash'=>$intent['payload_hash'],'intent_hash'=>hash('sha256',AF_ADVINV_OwnerProtocol::canonical($intent)),'status'=>'pending_issue']];return $this->issueSequence($session,$eid);
    }
    private function issueSequence(array $session,string $eid):array
    {
        $e=$this->encounter($session['id'],$eid,true);self::integer($e['encounter_version'],'18446744073709551614');self::integer($e['next_event_sequence'],'18446744073709551614');
        $this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=?',[$eid]);$session['_command_result']['encounter_sequence']=$e['next_event_sequence'];return $session;
    }
    public function acknowledgeLootIssue(array $session,string $eid,string $id):array
    {
        $this->commandWrite();$saved=$this->lootIssueIntent($session['id'],$eid,$id);$row=$saved['operation'];
        if(!in_array($row['status'],['committed','rejected'],true))throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        $receipt=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_LootIssue::receipt($saved['intent'],$receipt);
        $e=$saved['entry'];$status=$row['status']==='committed'?'issued':'issue_rejected';
        if($e['status']!=='pending_issue'||$this->sql('UPDATE '.$this->table('loot_entries').' SET status=? WHERE id=? AND status=\'pending_issue\' AND issue_operation_key=? AND allocation_version=? AND recipient_uid=?',[$status,$id,$e['issue_operation_key'],$e['allocation_version'],$e['recipient_uid']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $pending=$this->sql('SELECT id FROM '.$this->table('loot_entries')." WHERE pool_id=? AND status<>'issued' LIMIT 1",[$e['pool_id']])['rows'];
        self::integer($saved['pool']['pool_version'],'18446744073709551614');
        $this->sql('UPDATE '.$this->table('loot_pools').' SET pool_version=pool_version+1'.($pending?'':",status='issued'").' WHERE id=?',[$e['pool_id']]);
        $session['_command_result']=['encounter_id'=>$eid,'issue'=>['entry_id'=>$id,'allocation_version'=>$e['allocation_version'],'recipient_uid'=>$e['recipient_uid'],'operation_key'=>$row['operation_key'],'payload_hash'=>$saved['intent']['payload_hash'],'owner_receipt'=>$receipt,'status'=>$status]];return $this->issueSequence($session,$eid);
    }
    public function lootIssueRawItem(string $key):array
    {
        $rows=$this->sql('SELECT * FROM `'.$this->prefix.'af_kb_entries` WHERE BINARY type=BINARY ? AND BINARY `key`=BINARY ? ORDER BY id LIMIT 2',['arpg_item',$key])['rows'];
        if(count($rows)!==1)throw new RuntimeException('ACS_LOOT_OWNER_DEFINITION_CHANGED');return $rows[0];
    }
    public function lootIssueSnapshot(string $sid,string $id):array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$id,$sid])['rows'][0]??null;
        if(!$row||!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_LOOT_ITEM_PIN_INVALID');return json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition'];
    }
}
