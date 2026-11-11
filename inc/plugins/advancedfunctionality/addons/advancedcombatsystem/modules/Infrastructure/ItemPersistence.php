<?php
if (!defined('IN_MYBB')) {die('No direct access');}
trait AF_ACS_ItemPersistence
{
    public function itemSealDefinition(string $sid,array $seal): array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('snapshots').' WHERE session_id=? AND id=?',[$sid,$seal['definition_snapshot_id']])['rows'][0]??null;
        if (!$row || !hash_equals($row['content_hash'],hash('sha256',$row['payload_json']))) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        return json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition'];
    }
    public function itemEquipment(string $sid,string $actor): array
    {
        return $this->sql('SELECT e.*,s.payload_json,s.content_hash FROM '.$this->table('equipment').' e JOIN '.$this->table('snapshots').' s ON s.id=e.item_snapshot_id AND s.session_id=e.session_id WHERE e.session_id=? AND e.entity_id=? ORDER BY e.slot_key',[$sid,$actor])['rows'];
    }
    public function itemEntity(string $sid,string $actor): array
    {
        return $this->sql('SELECT e.*,s.payload_json,s.content_hash,st.stats_json FROM '.$this->table('entities').' e JOIN '.$this->table('snapshots').' s ON s.id=e.template_snapshot_id AND s.session_id=e.session_id JOIN '.$this->table('entity_states').' st ON st.entity_id=e.id WHERE e.session_id=? AND e.id=?',[$sid,$actor])['rows'][0]??throw new RuntimeException('ACS_ITEM_SOURCE_UNAVAILABLE');
    }
    public function freeItemEquipment(array $session,string $actor,array $spec): array
    {
        $this->commandWrite();
        $engaged=$this->sql('SELECT p.id FROM '.$this->table('participants').' p JOIN '.$this->table('encounters')." e ON e.id=p.encounter_id WHERE e.session_id=? AND p.entity_id=? AND p.participation='engaged' AND e.status IN ('active','paused')",[$session['id'],$actor])['rows'];
        if ($engaged) throw new RuntimeException('ACS_ITEM_FREE_EQUIP_IN_COMBAT');
        $this->projectItemEquipment($session['id'],[$actor=>[$spec['slot']=>['spec'=>$spec]]]);
        $session['_command_result']=['entity_id'=>$actor,'item_id'=>$spec['item_id'],'ap_cost'=>0,'reservation'=>$spec['reservation']['reservation']['id']];return $session;
    }
    protected function projectItemEquipment(string $sid,array $changes): void
    {
        foreach ($changes as $actor=>$slots) foreach ($slots as $slot=>$change) {
            if (!isset($change['spec'])) continue;
            $spec=$change['spec'];$old=$this->sql('SELECT * FROM '.$this->table('equipment').' WHERE session_id=? AND entity_id=? AND slot_key=? FOR UPDATE',[$sid,(string)$actor,$slot])['rows'][0]??null;
            if (($old?(int)$old['equipment_version']:0)!==$spec['equipment_version']) throw new RuntimeException('ACS_ITEM_EQUIPMENT_CAS_CONFLICT');
            $entity=$this->sql('SELECT entity_version FROM '.$this->table('entities').' WHERE session_id=? AND id=? FOR UPDATE',[$sid,(string)$actor])['rows'][0]??null;
            if (!$entity || $this->sql('UPDATE '.$this->table('entities').' SET entity_version=entity_version+1 WHERE session_id=? AND id=? AND entity_version=?',[$sid,(string)$actor,$entity['entity_version']])['affected']!==1) throw new RuntimeException('ACS_ITEM_EQUIPMENT_CAS_CONFLICT');
            $snapshot=$this->insertSnapshot($sid,['source_system'=>'advancedinventory','source_key'=>(string)$spec['item_id'],'contract_id'=>'ic-acs.active-equipment.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>AF_ACS_Numeric::POLICY,'engine_version'=>'acs-items-1','item'=>$spec]);
            $source=self::json(['schema_version'=>1,'item_fingerprint'=>$spec['item_fingerprint'],'reservation'=>$spec['reservation']]);
            if ($old) {
                if ($this->sql('UPDATE '.$this->table('equipment').' SET inventory_item_id=?,item_snapshot_id=?,equipment_version=equipment_version+1,source_json=?,status=? WHERE id=? AND equipment_version=?',[$spec['item_id'],$snapshot,$source,'active',$old['id'],$old['equipment_version']])['affected']!==1) throw new RuntimeException('ACS_ITEM_EQUIPMENT_CAS_CONFLICT');
            } else $this->sql('INSERT INTO '.$this->table('equipment').' (session_id,entity_id,slot_key,inventory_item_id,item_snapshot_id,status,equipment_version,source_json) VALUES (?,?,?,?,?,?,1,?)',[$sid,(string)$actor,$slot,$spec['item_id'],$snapshot,'active',$source]);
        }
    }
}
