<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/Custody.php';
trait AF_ACS_CustodyPersistence
{
    public function custodyRows(string $sid,bool $lock=false): array
    {return $this->sql('SELECT * FROM '.$this->table('custody').' WHERE session_id=? ORDER BY id'.($lock?' FOR UPDATE':''),[$sid])['rows'];}
    public function custodyItem(string $sid,string $item): ?array
    {return $this->sql('SELECT * FROM '.$this->table('custody').' WHERE session_id=? AND inventory_item_id=?',[$sid,$item])['rows'][0]??null;}
    public function custodyEquipment(string $sid,string $item): array
    {return $this->sql('SELECT e.*,s.content_hash,s.payload_json FROM '.$this->table('equipment').' e JOIN '.$this->table('snapshots').' s ON s.id=e.item_snapshot_id AND s.session_id=e.session_id WHERE e.session_id=? AND e.inventory_item_id=? ORDER BY e.id',[$sid,$item])['rows'];}
    public function custodyNoPending(string $sid): void
    {
        $this->commandWrite();
        if ($this->sql('SELECT a.id FROM '.$this->table('sealed_actions').' a JOIN '.$this->table('drafts').' d ON d.id=a.draft_id JOIN '.$this->table('encounters')." e ON e.id=d.encounter_id WHERE e.session_id=? AND a.status='sealed' LIMIT 1",[$sid])['rows']) throw new RuntimeException('ACS_CUSTODY_OBLIGATIONS_BLOCKED');
    }
    public function custodySave(array $session,array $request,?array $before,array $payload,array $affected): array
    {
        $this->commandWrite();$sid=$session['id'];$version=$before?(int)$before['custody_version']+1:1;
        $payload['custody_version']=$version;$payload['payload_hash']=AF_ACS_Equipment::fingerprint(array_diff_key($payload,['payload_hash'=>1]));$json=self::json($payload);
        if ($before) {
            if ($this->sql('UPDATE '.$this->table('custody').' SET custodian_entity_id=?,active_user_entity_id=?,status=?,custody_version=?,operation_key=?,payload_json=? WHERE id=? AND custody_version=?',[$payload['custodian_entity_id'],$payload['active_user_entity_id'],$payload['status'],$version,$payload['initial_operation_key'],$json,$before['id'],$before['custody_version']])['affected']!==1) throw new RuntimeException('ACS_CUSTODY_VERSION_CONFLICT');
            $id=(string)$before['id'];
        } else $id=$this->sql('INSERT INTO '.$this->table('custody').' (session_id,inventory_item_id,owner_uid,custodian_entity_id,active_user_entity_id,status,custody_version,operation_key,payload_json) VALUES (?,?,?,?,?,?,?,?,?)',[$sid,$payload['inventory_item_id'],$payload['owner_uid'],$payload['custodian_entity_id'],$payload['active_user_entity_id'],$payload['status'],$version,$payload['initial_operation_key'],$json])['id'];
        if ($request['operation']==='confiscate' || $request['operation']==='return') {
            $status=$request['operation']==='confiscate'?'custody':'disabled';
            $rows=$this->custodyEquipment($sid,(string)$payload['inventory_item_id']);
            foreach ($rows as $row) if ($request['operation']==='confiscate'&&$row['status']==='active' || $request['operation']==='return'&&$row['status']==='custody') {
                if ($this->sql('UPDATE '.$this->table('equipment').' SET status=?,equipment_version=equipment_version+1 WHERE id=? AND equipment_version=?',[$status,$row['id'],$row['equipment_version']])['affected']!==1) throw new RuntimeException('ACS_CUSTODY_VERSION_CONFLICT');
                $affected[]=(string)$row['entity_id'];
            }
        }
        foreach (array_unique(array_filter($affected)) as $entity) {
            if ($this->sql('UPDATE '.$this->table('entities').' SET entity_version=entity_version+1 WHERE session_id=? AND id=?',[$sid,$entity])['affected']!==1) throw new RuntimeException('ACS_CUSTODY_ENTITY_UNAVAILABLE');
        }
        $session['_command_result']=['custody_id'=>$id,'custody_version'=>(string)$version,'status'=>$payload['status'],'owner_uid'=>(string)$payload['owner_uid'],'inventory_item_id'=>(string)$payload['inventory_item_id'],'custodian_entity_id'=>$payload['custodian_entity_id'],'active_user_entity_id'=>$payload['active_user_entity_id'],'reservation_id'=>$payload['reservation']['reservation']['id'],'payload_hash'=>$payload['payload_hash'],'ap_cost'=>0];
        return $session;
    }
    public function custodyOperation(string $sid,string $key,string $uid): ?array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE session_id=? AND operation_key=?',[$sid,$key])['rows'][0]??null;
        if (!$row) return null;
        if (!str_starts_with($row['kind'],'custody.') || !hash_equals($row['payload_hash'],hash('sha256',$row['request_json'])) || (json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR)['actor_uid']??null)!==$uid || $row['status']!=='committed') throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        return json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);
    }
}
