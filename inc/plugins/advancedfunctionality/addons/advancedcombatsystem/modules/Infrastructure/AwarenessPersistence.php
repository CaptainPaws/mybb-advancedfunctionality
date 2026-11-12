<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/Awareness.php';

/** Materialized knowledge, separate from objects/states/effects. Internal API, never a public DTO. */
trait AF_ACS_AwarenessPersistence
{
    public function awarenessProjection(string $sid,string $eid):array
    {
        if(!$this->sql('SELECT id FROM '.$this->table('encounters').' WHERE session_id=? AND id=?',[$sid,$eid])['rows'])throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');$out=[];
        foreach($this->sql('SELECT * FROM '.$this->table('awareness').' WHERE encounter_id=? ORDER BY id',[$eid])['rows'] as $row){
            $payload=json_decode($row['details_json'],true,16,JSON_THROW_ON_ERROR);if(($payload['schema_version']??null)!==1||array_diff(array_keys($payload),['schema_version','details']))throw new RuntimeException('ACS_AWARENESS_STATE_CONFLICT');
            $v=['schema_version'=>1,'encounter_id'=>$eid,'subject_kind'=>$row['subject_kind'],'subject_id'=>(string)$row['subject_id'],'audience_kind'=>$row['audience_kind'],'audience_id'=>(string)$row['audience_id'],'level'=>$row['level'],'awareness_version'=>(int)$row['awareness_version'],'details'=>$payload['details']??null];AF_ACS_Awareness::row($v);
            $out[AF_ACS_Awareness::key($v['subject_kind'],$v['subject_id'],$v['audience_kind'],$v['audience_id'])]=$v;
        }return $out;
    }
    public function awarenessAttempts(string $sid,string $eid):array
    {
        $out=[];foreach($this->sql('SELECT operation_key,payload_hash,request_json,result_json FROM '.$this->table('operations').' WHERE session_id=? AND encounter_id=? AND kind=? AND status=? ORDER BY operation_key',[$sid,$eid,'awareness.check','committed'])['rows'] as $row){$p=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);if(!hash_equals($row['payload_hash'],hash('sha256',$row['result_json']))||$row['request_json']!==$row['result_json']||$row['operation_key']!=='awareness:'.hash('sha256',$eid.':'.$p['attempt']))throw new RuntimeException('ACS_AWARENESS_STATE_CONFLICT');AF_ACS_Awareness::attempt($p['attempt'],$p['check']);$out[$p['attempt']]=$p['check'];}return $out;
    }
    /** Only immutable pinned perception contracts are used; no current/live KB acquisition. */
    public function perceptionSubjects(string $sid,string $eid):array
    {
        $objects=$this->objectProjection($sid,$eid);$rows=$this->sql('SELECT e.id,e.kind,e.source_entity_id,e.control_group_id,s.payload_json,s.content_hash,p.side_id FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' s ON s.id=e.template_snapshot_id AND s.session_id=e.session_id LEFT JOIN '.$this->table('participants').' p ON p.entity_id=e.id AND p.encounter_id=? WHERE e.session_id=? ORDER BY e.id',[$eid,$sid])['rows'];
        $byId=[];foreach($rows as $row)$byId[(string)$row['id']]=$row;$out=[];
        foreach($rows as $row){$id=(string)$row['id'];$object=$row['kind']==='object';if($object?!isset($objects[$id]):$row['side_id']===null)continue;
            if(!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');$p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
            $definition=$object?$objects[$id]['definition']:($p['source_snapshot']['data']['definition']??[]);$perception=AF_ACS_Awareness::perception($definition['perception']??null);
            $source=$byId[$object?(string)$row['source_entity_id']:$id]??null;if(!$source||$source['control_group_id']===null||$source['side_id']===null)continue;
            $out[($object?'object':'entity').':'.$id]=['kind'=>$object?'object':'entity','id'=>$id,'perception'=>$perception,'source'=>['entity_id'=>(string)$source['id'],'group_id'=>(string)$source['control_group_id'],'side_id'=>(string)$source['side_id'],'encounter_id'=>$eid]];
        }return $out;
    }
    protected function persistAwareness(string $sid,string $eid,array $before,array $after,array $beforeAttempts=[],array $afterAttempts=[]):array
    {
        $this->transactions->assertInternalBoundary();$actual=$this->awarenessProjection($sid,$eid);$subjects=$this->perceptionSubjects($sid,$eid);$changes=[];
        if(array_diff_key($before,$after)||array_diff_key($beforeAttempts,$afterAttempts))throw new RuntimeException('ACS_AWARENESS_STATE_CONFLICT');
        foreach($after as $key=>$v){$old=$before[$key]??null;if($old===$v)continue;AF_ACS_Awareness::row($v);
            if(($actual[$key]??null)!==$old||$v['awareness_version']<=($old['awareness_version']??0))throw new RuntimeException('ACS_AWARENESS_VERSION_CONFLICT');
            if($v['encounter_id']!==$eid||$key!==AF_ACS_Awareness::key($v['subject_kind'],$v['subject_id'],$v['audience_kind'],$v['audience_id'])||!isset($subjects[$v['subject_kind'].':'.$v['subject_id']]))throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');
            $details=self::json(['schema_version'=>1,'details'=>$v['details']]);
            if($old===null)$this->sql('INSERT INTO '.$this->table('awareness').' (encounter_id,subject_kind,subject_id,audience_kind,audience_id,level,awareness_version,details_json) VALUES (?,?,?,?,?,?,?,?)',[$eid,$v['subject_kind'],$v['subject_id'],$v['audience_kind'],$v['audience_id'],$v['level'],$v['awareness_version'],$details]);
            elseif($this->sql('UPDATE '.$this->table('awareness').' SET level=?,awareness_version=?,details_json=? WHERE encounter_id=? AND subject_kind=? AND subject_id=? AND audience_kind=? AND audience_id=? AND awareness_version=?',[$v['level'],$v['awareness_version'],$details,$eid,$v['subject_kind'],$v['subject_id'],$v['audience_kind'],$v['audience_id'],$old['awareness_version']])['affected']!==1)throw new RuntimeException('ACS_AWARENESS_VERSION_CONFLICT');
            $changes[]=['before'=>$old,'after'=>$v];
        }
        foreach($afterAttempts as $key=>$check){if(isset($beforeAttempts[$key])){if($beforeAttempts[$key]!==$check)throw new RuntimeException('ACS_AWARENESS_STATE_CONFLICT');continue;}
            AF_ACS_Awareness::attempt($key,$check);$payload=self::json(['schema_version'=>1,'attempt'=>$key,'check'=>$check]);$operation='awareness:'.hash('sha256',$eid.':'.$key);
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$operation,$sid,$eid,'awareness.check',hash('sha256',$payload),'committed',$payload,$payload,time()]);
        }return $changes;
    }
    public function changeAwareness(array $session,array $encounter,array $request):array
    {
        $this->commandWrite();$sid=$session['id'];$eid=$encounter['id'];$key=AF_ACS_Awareness::key($request['subject_kind'],$request['subject_id'],$request['audience_kind'],$request['audience_id']);$before=$this->awarenessProjection($sid,$eid);$old=$before[$key]??null;
        if((string)($old['awareness_version']??0)!==$request['expected_awareness_version'])throw new RuntimeException('ACS_AWARENESS_VERSION_CONFLICT');
        $v=['schema_version'=>1,'encounter_id'=>$eid,'subject_kind'=>$request['subject_kind'],'subject_id'=>$request['subject_id'],'audience_kind'=>$request['audience_kind'],'audience_id'=>$request['audience_id'],'level'=>$request['level'],'awareness_version'=>($old['awareness_version']??0)+1,'details'=>$request['details']];
        AF_ACS_Awareness::row($v);$audience=$request['audience_kind'];$aid=$request['audience_id'];
        $valid=match($audience){'encounter'=>$aid===$eid,'side'=>(bool)$this->sql('SELECT id FROM '.$this->table('sides').' WHERE encounter_id=? AND id=?',[$eid,$aid])['rows'],'actor'=>(bool)$this->sql('SELECT entity_id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$aid])['rows'],'control_group'=>(bool)$this->sql('SELECT p.entity_id FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id WHERE p.encounter_id=? AND e.session_id=? AND e.control_group_id=?',[$eid,$sid,$aid])['rows'],default=>false};
        if(!$valid)throw new RuntimeException('ACS_AWARENESS_UNAVAILABLE');$after=$before;$after[$key]=$v;$changes=$this->persistAwareness($sid,$eid,$before,$after);
        $session=$this->change($session,$encounter,'awareness_change',[]);$session['_command_result']+=['awareness'=>$changes,'reason'=>$request['reason']];return $session;
    }
}
