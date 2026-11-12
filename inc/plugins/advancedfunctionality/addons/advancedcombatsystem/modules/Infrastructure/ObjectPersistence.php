<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/Environment.php';

/** Object projection on the existing spawn identity. Writes require the parent ACS transaction. */
trait AF_ACS_ObjectPersistence
{
    public function objectProjection(string $sid,string $eid):array
    {
        $out=[];
        $rows=$this->sql('SELECT e.id,e.source_entity_id,e.spawn_key,e.definition_json,s.payload_json,s.content_hash,o.entity_id AS stored_id,o.encounter_id AS stored_encounter,o.status,o.object_version,o.durability,o.interaction_json,o.progress_json FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' s ON s.id=e.template_snapshot_id AND s.session_id=e.session_id LEFT JOIN '.$this->table('objects').' o ON o.entity_id=e.id WHERE e.session_id=? AND e.kind=? ORDER BY e.id',[$sid,'object'])['rows'];
        foreach($rows as $row){
            if(!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$d=json_decode($row['definition_json'],true,64,JSON_THROW_ON_ERROR);$spawn=$p['spawn']??[];
            if(($p['contract_id']??null)!=='ic-acs.spawn-snapshot.v1'||($d['spawn']??null)!==$spawn||($spawn['spec']['kind']??null)!=='spawn_object'||$row['spawn_key']!=='spawn:'.($spawn['source_event_id']??'').':'.($spawn['spawn_index']??'')||(string)$row['source_entity_id']!==($spawn['source_entity_id']??null))throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
            if($spawn['encounter_id']!==$eid)continue;
            if(!$this->sql('SELECT id FROM '.$this->table('events').' WHERE id=? AND session_id=? AND encounter_id=?',[$spawn['source_event_id'],$sid,$eid])['rows'])throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
            $projection=AF_ACS_Spawn::template($spawn['spec'],$p['source_snapshot']);
            if($p['object_definition']!==$projection['properties'])throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
            $initial=AF_ACS_Environment::initial((string)$row['id'],$eid,(string)$row['source_entity_id'],$projection['properties'],$spawn['spec']['duration']);
            $v=$row['stored_id']===null?$initial:json_decode($row['progress_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_Environment::instance($v);
            foreach(['entity_id','encounter_id','source_entity_id','definition','unit'] as $k)if($v[$k]!==$initial[$k])throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
            if($v['remaining']>$initial['remaining']||$row['stored_id']!==null&&((string)$row['stored_encounter']!==$eid||$v['status']!==$row['status']||$v['object_version']!==(int)$row['object_version']||$v['durability']!==($row['durability']===null?null:(int)$row['durability'])||$v['interaction']!==json_decode($row['interaction_json'],true,32,JSON_THROW_ON_ERROR)))throw new RuntimeException('ACS_OBJECT_STATE_INVALID');
            $out[(string)$row['id']]=$v;
        }
        return $out;
    }
    protected function insertObject(array $v):void
    {
        $this->transactions->assertInternalBoundary();AF_ACS_Environment::instance($v);
        $this->sql('INSERT INTO '.$this->table('objects').' (entity_id,encounter_id,status,object_version,durability,interaction_json,progress_json) VALUES (?,?,?,?,?,?,?)',[$v['entity_id'],$v['encounter_id'],$v['status'],$v['object_version'],$v['durability'],self::json($v['interaction']),self::json($v)]);
    }
    protected function persistObjects(string $sid,string $eid,array $before,array $after):array
    {
        $this->transactions->assertInternalBoundary();$changes=[];
        $actual=$this->objectProjection($sid,$eid);
        if(array_keys($before)!==array_keys($after))throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
        foreach($after as $id=>$v){$old=$before[$id];if($old===$v)continue;
            if(($actual[$id]??null)!==$old||$v['object_version']<=$old['object_version'])throw new RuntimeException('ACS_OBJECT_VERSION_CONFLICT');AF_ACS_Environment::instance($v);
            foreach(['entity_id','encounter_id','source_entity_id','definition','unit'] as $k)if($v[$k]!==$old[$k])throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
            if(in_array($old['status'],['expired','destroyed'],true))throw new RuntimeException('ACS_OBJECT_TERMINAL');
            $stored=$this->sql('SELECT object_version FROM '.$this->table('objects').' WHERE entity_id=? FOR UPDATE',[(string)$id])['rows'];
            // Legacy stage-7 objects materialize only on an authorized change, never a GET.
            if(!$stored)$this->insertObject($old);
            if($this->sql('UPDATE '.$this->table('objects').' SET status=?,object_version=?,durability=?,interaction_json=?,progress_json=? WHERE entity_id=? AND encounter_id=? AND object_version=?',[$v['status'],$v['object_version'],$v['durability'],self::json($v['interaction']),self::json($v),(string)$id,$eid,$old['object_version']])['affected']!==1)throw new RuntimeException('ACS_OBJECT_VERSION_CONFLICT');
            $changes[]=['entity_id'=>(string)$id,'before'=>$old,'after'=>$v];
        }
        return $changes;
    }
}
