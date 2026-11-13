<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Domain/Cooperation.php';

/** Projection/CAS over the existing journal; caller owns the ACS transaction. */
trait AF_ACS_CooperationPersistence
{
    public function objectiveProjection(string $sid,string $eid):array
    {
        $objects=$this->objectProjection($sid,$eid);$out=[];
        $rows=$this->sql('SELECT o.*,e.template_snapshot_id FROM '.$this->table('objectives').' o JOIN '.$this->table('entities').' e ON e.id=o.object_entity_id WHERE e.session_id=? AND o.encounter_id=? ORDER BY o.id',[$sid,$eid])['rows'];
        foreach($rows as $row){
            $v=json_decode($row['progress_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_Cooperation::instance($v);$object=$objects[$v['entity_id']]??null;
            if(!$object||$v['definition']!==($object['definition']['cooperation']??null)||$v['id']!==(string)$row['id']||$v['encounter_id']!==$eid||$v['entity_id']!==(string)$row['object_entity_id']
                ||(string)$row['definition_snapshot_id']!==(string)$row['template_snapshot_id']||$v['status']!==$row['status']||$v['objective_version']!==(int)$row['objective_version'])throw new RuntimeException('ACS_OBJECTIVE_STATE_INVALID');
            $records=$this->sql('SELECT c.*,e.session_id AS entity_session,j.session_id AS event_session,j.encounter_id,j.event_type FROM '.$this->table('contributions').' c JOIN '.$this->table('events').' j ON j.id=c.source_event_id JOIN '.$this->table('entities').' e ON e.id=c.entity_id WHERE c.objective_id=? ORDER BY c.id',[$v['id']])['rows'];
            $found=[];$completed=null;
            foreach($records as $record){$prefix='objective:'.$v['id'].':';if(!str_starts_with($record['contribution_key'],$prefix))throw new RuntimeException('ACS_CONTRIBUTION_INVALID');$cause=substr($record['contribution_key'],strlen($prefix));$r=$v['records'][$cause]??null;
                if(!$r||json_decode($record['payload_json'],true,32,JSON_THROW_ON_ERROR)!==['schema_version'=>1,'record'=>$r]||$r['actor']!==(string)$record['entity_id']||$record['event_type']!=='combat.resolved'||(string)$record['entity_session']!==$sid||(string)$record['event_session']!==$sid||(string)$record['encounter_id']!==$eid)throw new RuntimeException('ACS_CONTRIBUTION_INVALID');
                $found[$cause]=true;if($cause===$v['completion_cause'])$completed=(string)$record['source_event_id'];
            }
            if(count($found)!==count($v['records'])||$completed!==($row['completed_event_id']===null?null:(string)$row['completed_event_id']))throw new RuntimeException('ACS_CONTRIBUTION_INVALID');
            $out[$v['entity_id']]=$v;
        }
        foreach($objects as $id=>$v)if(isset($v['definition']['cooperation'])&&!isset($out[$id]))throw new RuntimeException('ACS_OBJECTIVE_STATE_INVALID');
        return $out;
    }
    protected function insertObjective(array $object,string $snapshot):void
    {
        if(!isset($object['definition']['cooperation']))return;
        $this->transactions->assertInternalBoundary();
        $id=$this->sql('INSERT INTO '.$this->table('objectives').' (encounter_id,object_entity_id,definition_snapshot_id,status,objective_version,completed_event_id,progress_json) VALUES (?,?,?, ?,1,NULL,?)',[$object['encounter_id'],$object['entity_id'],$snapshot,'open','{}'])['id'];
        $v=AF_ACS_Cooperation::initial($id,$object);AF_ACS_Cooperation::instance($v);
        $this->sql('UPDATE '.$this->table('objectives').' SET progress_json=? WHERE id=?',[self::json($v),$id]);
    }
    protected function persistObjectives(string $sid,string $eid,array $before,array $after,?string $event=null):array
    {
        $this->transactions->assertInternalBoundary();$actual=$this->objectiveProjection($sid,$eid);$changes=[];
        if(array_keys($before)!==array_keys($after))throw new RuntimeException('ACS_OBJECTIVE_STATE_INVALID');
        foreach($after as $objectId=>$v){$old=$before[$objectId];if($v===$old)continue;AF_ACS_Cooperation::instance($v);
            if(($actual[$objectId]??null)!==$old||$v['objective_version']<=$old['objective_version']||$old['status']!=='open')throw new RuntimeException('ACS_OBJECTIVE_VERSION_CONFLICT');
            foreach(['id','entity_id','encounter_id','definition','bound_object_version'] as $key)if($v[$key]!==$old[$key])throw new RuntimeException('ACS_OBJECTIVE_STATE_INVALID');
            if(array_diff_key($old['records'],$v['records'])||array_diff_key($old['assists'],$v['assists']))throw new RuntimeException('ACS_CONTRIBUTION_INVALID');
            foreach($old['records'] as $cause=>$r)if($v['records'][$cause]!==$r)throw new RuntimeException('ACS_CONTRIBUTION_INVALID');
            $added=array_diff_key($v['records'],$old['records']);
            if($added&&($event===null||!$this->sql('SELECT id FROM '.$this->table('events').' WHERE id=? AND session_id=? AND encounter_id=? AND event_type=?',[$event,$sid,$eid,'combat.resolved'])['rows']))throw new RuntimeException('ACS_CONTRIBUTION_EVENT_REQUIRED');
            foreach($added as $cause=>$r){
                if(!$this->sql('SELECT id FROM '.$this->table('entities').' WHERE id=? AND session_id=?',[$r['actor'],$sid])['rows'])throw new RuntimeException('ACS_CONTRIBUTION_INVALID');
                // UNIQUE is global and authoritative; never INSERT IGNORE a mismatched contribution.
                $this->sql('INSERT INTO '.$this->table('contributions').' (objective_id,entity_id,source_event_id,contribution_key,payload_json) VALUES (?,?,?,?,?)',[$v['id'],$r['actor'],$event,'objective:'.$v['id'].':'.$cause,self::json(['schema_version'=>1,'record'=>$r])]);
            }
            $completed=$v['status']==='completed'?$event:null;if($v['status']==='completed'&&!isset($added[$v['completion_cause']]))throw new RuntimeException('ACS_CONTRIBUTION_EVENT_REQUIRED');
            if($this->sql('UPDATE '.$this->table('objectives').' SET status=?,objective_version=?,completed_event_id=?,progress_json=? WHERE id=? AND encounter_id=? AND objective_version=?',[$v['status'],$v['objective_version'],$completed,self::json($v),$v['id'],$eid,$old['objective_version']])['affected']!==1)throw new RuntimeException('ACS_OBJECTIVE_VERSION_CONFLICT');
            $changes[]=['type'=>$v['status']==='completed'?'objective.completed':'objective.updated','objective_id'=>$v['id'],'before'=>$old,'after'=>$v,'source_event_id'=>$event,'contribution_keys'=>array_map(static fn($cause)=>'objective:'.$v['id'].':'.$cause,array_keys($added))];
        }return $changes;
    }
}
