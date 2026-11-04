<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** SQL projection only. Mutations are called inside the existing command/combat transaction. */
trait AF_ACS_StatusPersistence
{
    public function statusRows(string $sid,bool $lock=false):array
    {
        return $this->sql('SELECT * FROM '.$this->table('effects').' WHERE session_id=? ORDER BY applied_order,id'.($lock?' FOR UPDATE':''),[self::integer($sid)])['rows'];
    }
    public function statusProjection(string $sid):array
    {
        require_once __DIR__.'/../Domain/Effects.php';
        $rows=$this->statusRows($sid);$instances=[];$next=1;
        foreach($rows as $row){
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$instance=$p['instance']??null;
            if(($p['schema_version']??null)!==1||!is_array($instance))throw new RuntimeException('ACS_STATUS_SCHEMA_UNSUPPORTED');
            $id=self::integer((string)$row['id']);
            foreach(['id','session_id','encounter_id','source_entity_id','target_entity_id'] as $field)if(($instance[$field]??null)!==(string)$row[$field])throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            foreach(['stacks','remaining_duration','applied_order'] as $field)if(($instance[$field]??null)!==(int)self::integer((string)$row[$field],'9007199254740991',true))throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            if(($instance['version']??null)!==(int)self::integer((string)$row['effect_version'],'9007199254740991')||$instance['duration_unit']!==$row['duration_unit']||$instance['status']!==$row['status']
                ||($instance['spec']['scope']??null)!==$row['scope']||($instance['spec']['effect_key']??null)!==$row['effect_key']||$row['parent_effect_id']!==null)throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            $snapshot=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$row['definition_snapshot_id'],$sid])['rows'][0]??null;
            if(!$snapshot||!hash_equals($snapshot['content_hash'],hash('sha256',$snapshot['payload_json'])))throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $dto=json_decode($snapshot['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition']??[];$effect=null;
            foreach($dto['data']['ability_profile']['effects']??[] as $candidate)if(($candidate['source_index']??null)===$instance['effect_definition_ref']['effect_index'])$effect=$candidate;
            if($effect===null)throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $status=$effect['references']['status_key']??[];
            $expectedRef=['type'=>$dto['source']['type']??null,'key'=>$dto['source']['key']??null,'version'=>$dto['definition_version']['declared_version']??null,
                'fingerprint'=>$dto['definition_version']['fingerprint']??null,'effect_index'=>$effect['source_index']];
            $expectedStatus=['key'=>$status['key']??null,'version'=>$status['definition_version']['version']??null,'fingerprint'=>$status['definition_version']['fingerprint']??null];
            if(($status['resolved']??null)!==true||AF_ACS_Effects::instanceSpec($status['definition']??[],$effect['parameters']??[])!==$instance['spec']
                ||$expectedRef!==$instance['effect_definition_ref']||$expectedStatus!==$instance['status_definition_ref'])throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $instances[$id]=$instance;$next=max($next,$instance['applied_order']+1);
        }
        return ['instances'=>$instances,'next_order'=>$next,'fingerprint'=>hash('sha256',self::json(['schema_version'=>1,'rows'=>$rows]))];
    }
    /** Existing combat-reset command only, after persisted encounter completion. */
    protected function expireCombatStatuses(array $session,array $encounter):array
    {
        $this->commandWrite();
        if($encounter['status']!=='completed'||$encounter['completed_at']===null||$encounter['session_id']!==$session['id'])throw new RuntimeException('ACS_STATUS_BOUNDARY_INVALID');
        $this->statusRows($session['id'],true);$projection=$this->statusProjection($session['id']);
        $state=['schema_version'=>1,'entities'=>[],'groups'=>[],'barriers'=>[],'next_barrier_order'=>0,
            'status_instances'=>$projection['instances'],'next_status_order'=>$projection['next_order']];
        $result=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_combat_end','encounter_id'=>$encounter['id']]]]);
        return $this->persistStatusInstances($session['id'],$state['status_instances'],$result->serverState()['status_instances'],[]);
    }
    /** Caller owns the existing ACS lock/transaction and event journal. Never starts or commits a transaction. */
    protected function persistStatusInstances(string $sid,array $before,array $after,array $snapshotIds):array
    {
        require_once __DIR__.'/../Domain/Effects.php';$mapping=[];$changed=[];
        if(array_diff_key($before,$after))throw new RuntimeException('ACS_STATUS_HISTORY_REQUIRED');
        foreach($after as $virtualId=>$instance){
            $virtualId=(string)$virtualId;$old=$before[$virtualId]??null;if($old===$instance)continue;
            if(($instance['session_id']??null)!==$sid)throw new RuntimeException('ACS_STATUS_SCOPE_INVALID');
            if($old===null){
                if(!preg_match('/\Anew:[1-9][0-9]*\z/D',$virtualId))throw new RuntimeException('ACS_STATUS_ID_INVALID');
                $snapshot=$snapshotIds[$instance['origin_action_index']]??null;if($snapshot===null)throw new RuntimeException('ACS_STATUS_PIN_INVALID');
                $id=$this->sql('INSERT INTO '.$this->table('effects').' (session_id,encounter_id,source_entity_id,target_entity_id,definition_snapshot_id,effect_key,scope,status,stacks,remaining_duration,duration_unit,applied_order,effect_version,parent_effect_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)',
                    [$sid,$instance['encounter_id'],$instance['source_entity_id'],$instance['target_entity_id'],$snapshot,$instance['spec']['effect_key'],$instance['spec']['scope'],$instance['status'],$instance['stacks'],$instance['remaining_duration'],$instance['duration_unit'],$instance['applied_order'],$instance['version'],self::json(['schema_version'=>1,'instance'=>$instance])])['id'];
                $instance['id']=$id;$mapping[$virtualId]=$id;
                if($this->sql('UPDATE '.$this->table('effects').' SET payload_json=? WHERE id=? AND session_id=? AND effect_version=?',[self::json(['schema_version'=>1,'instance'=>$instance]),$id,$sid,$instance['version']])['affected']!==1)throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
            }else{
                $id=self::integer($virtualId);$immutable=['id','session_id','encounter_id','source_entity_id','target_entity_id','spec','effect_definition_ref','status_definition_ref','applied_order','duration_unit','origin_action_index'];
                foreach($immutable as $field)if($old[$field]!==$instance[$field])throw new RuntimeException('ACS_STATUS_IMMUTABLE');
                if($instance['version']<=$old['version'])throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
                if($this->sql('UPDATE '.$this->table('effects').' SET status=?,stacks=?,remaining_duration=?,effect_version=?,payload_json=? WHERE session_id=? AND id=? AND effect_version=?',
                    [$instance['status'],$instance['stacks'],$instance['remaining_duration'],$instance['version'],self::json(['schema_version'=>1,'instance'=>$instance]),$sid,$id,$old['version']])['affected']!==1)throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
            }
            $changed[]=['id'=>$id,'virtual_id'=>$virtualId,'before'=>$old,'after'=>$instance];
        }
        return ['id_map'=>$mapping,'changes'=>$changed];
    }
}
