<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionRepository.php';

/** Internal projection writes are reachable only inside the audited command transaction. */
final class AF_ACS_EntityRepository extends AF_ACS_SessionRepository
{
    public function register(array $session, array $registration): array
    {
        $this->commandWrite();
        $sid=(string)$session['id'];
        $owner=$registration['owner_uid'];
        $kind=$registration['kind'];
        $sheet=$registration['sheet_id'];
        $spawn=$kind==='pet'?'permanent:'.$sid.':'.$owner.':'.hash('sha256',$registration['source']['source_key']):null;
        if($spawn!==null && $this->sql('SELECT id FROM '.$this->table('entities').' WHERE spawn_key=? FOR UPDATE',[$spawn])['rows'])throw new RuntimeException('ACS_ENTITY_ALREADY_REGISTERED');
        // One permanent identity per session; independent bestiary instances remain unlimited.
        if ($sheet!==null && $this->sql('SELECT id FROM '.$this->table('entities').' WHERE session_id=? AND sheet_id=? FOR UPDATE',[$sid,$sheet])['rows']) {
            throw new RuntimeException('ACS_ENTITY_ALREADY_REGISTERED');
        }
        $group=null;
        if ($owner!==null) {
            $groups=$this->sql('SELECT id FROM '.$this->table('control_groups').' WHERE session_id=? AND owner_uid=? AND kind=? FOR UPDATE',[$sid,$owner,'player'])['rows'];
            if(count($groups)>1)throw new RuntimeException('ACS_GROUP_AMBIGUOUS');
            $group=$groups?(string)$groups[0]['id']:null;
        }
        if ($group===null) $group=$this->sql('INSERT INTO '.$this->table('control_groups').' (session_id,owner_uid,kind,group_version,config_json) VALUES (?,?,?,?,?)',[$sid,$owner,$owner===null?'independent':'player',1,self::json(['schema_version'=>1])])['id'];
        $snapshot=$this->insertSnapshot($sid,$registration['source'],$registration['snapshot']);
        $definition=['schema_version'=>1,'registration_version'=>'acs-registration-1','source'=>$registration['source'],
            'source_fingerprint'=>$registration['source_fingerprint'],'participation'=>null];
        $id=$this->sql('INSERT INTO '.$this->table('entities').' (session_id,control_group_id,kind,owner_uid,sheet_id,template_snapshot_id,source_entity_id,spawn_key,name,entity_version,definition_json) VALUES (?,?,?,?,?,?,NULL,?,?,?,?)',[$sid,$group,$kind,$owner,$sheet,$snapshot,$spawn,self::text($registration['name'],191),1,self::json($definition)])['id'];
        $hp=$registration['hp'];$max=$registration['hp_max'];
        $this->sql('INSERT INTO '.$this->table('entity_states').' (entity_id,life_state,hp,hp_max,state_version,stats_json,layers_json) VALUES (?,?,?,?,?,?,?)',[$id,$hp==='0'?'incapacitated':'active',$hp,$max,1,self::json(['schema_version'=>1,'values'=>$registration['stats']]),self::json(['schema_version'=>1,'layers'=>[]])]);
        foreach ($registration['resources'] as $key=>$resource) {
            $this->sql('INSERT INTO '.$this->table('resources').' (entity_id,resource_key,current_value,maximum_value,is_integer,resource_version,definition_json) VALUES (?,?,?,?,?,?,?)',[$id,self::key($key,64),$resource['current'],$resource['maximum'],$resource['is_integer']?1:0,1,self::json(['schema_version'=>1,'source'=>$resource])]);
        }
        $this->sql('INSERT INTO '.$this->table('controllers').' (session_id,entity_id,controller_uid,mode,status,assignment_version,valid_from,valid_until,permissions_json) VALUES (?,?,?,?,?,?,?,NULL,?)',[$sid,$id,$registration['controller_uid'],'exclusive','active',1,time(),self::json(['schema_version'=>1,'scope'=>'initial_assignment','combat_operations_enabled'=>false])]);
        $session['_command_result']=['entity_id'=>$id,'snapshot_id'=>$snapshot,'snapshot_hash'=>hash('sha256',self::json($registration['snapshot'])),'control_group_id'=>$group];
        return $session;
    }

    /** Bounded metadata only; full protected snapshots are a separate authorized read. */
    public function entities(string $sessionId,string $after='0',?string $ownerUid=null): array
    {
        $scope=$ownerUid===null?'':' AND e.owner_uid=?';
        $parameters=[self::integer($sessionId),self::integer($after,zero:true)];
        if($ownerUid!==null)$parameters[]=self::integer($ownerUid,'4294967295');
        $rows=$this->sql('SELECT e.id,e.kind,e.name,e.owner_uid,e.sheet_id,e.control_group_id,e.template_snapshot_id,e.entity_version,s.life_state,c.controller_uid FROM '.$this->table('entities').' e JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id JOIN '.$this->table('controllers')." c ON c.entity_id=e.id AND c.status='active' WHERE e.session_id=? AND e.id>? ".$scope." ORDER BY e.id LIMIT 100",$parameters)['rows'];
        foreach($rows as &$row)foreach(['id','owner_uid','sheet_id','control_group_id','template_snapshot_id','entity_version','controller_uid'] as $key)if($row[$key]!==null)$row[$key]=(string)$row[$key];
        return $rows;
    }
    public function snapshot(string $sessionId,string $entityId): ?array
    {
        return $this->sql('SELECT e.owner_uid,e.kind,s.* FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' s ON s.id=e.template_snapshot_id AND s.session_id=e.session_id WHERE e.session_id=? AND e.id=?',[self::integer($sessionId),self::integer($entityId)])['rows'][0]??null;
    }
}
