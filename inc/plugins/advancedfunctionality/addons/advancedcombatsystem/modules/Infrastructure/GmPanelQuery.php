<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Application/EncounterService.php';

/** Read-only inspection of the selected page of participants. No owner calculator or writer. */
final class AF_ACS_GmPanelQuery extends AF_ACS_Repository
{
    public function inspection(string $sid,string $eid,string $tid,array $ids):array
    {
        (new AF_ACS_EncounterService(new AF_ACS_EncounterRepository($this->connection),new AF_ACS_SessionAccess))->context($sid,$tid);
        $ids=array_values(array_unique(array_map(static fn($id)=>self::integer($id),$ids)));if(!$ids)return [];if(count($ids)>100)throw new RuntimeException('ACS_QUERY_LIMIT');
        $rows=$this->sql('SELECT e.id,e.control_group_id,e.template_snapshot_id,s.hp,s.hp_max,s.life_state,x.content_hash,SHA2(x.payload_json,256) AS actual_hash FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id JOIN '.$this->table('snapshots').' x ON x.id=e.template_snapshot_id AND x.session_id=e.session_id WHERE e.session_id=? AND p.encounter_id=? AND e.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$sid,$eid,...$ids])['rows'];
        $out=[];foreach($rows as $r)$out[(string)$r['id']]=['entity_id'=>(string)$r['id'],'group_id'=>(string)$r['control_group_id'],'snapshot_id'=>(string)$r['template_snapshot_id'],'snapshot_valid'=>hash_equals($r['content_hash'],$r['actual_hash']),'hp'=>(string)$r['hp'],'hp_max'=>(string)$r['hp_max'],'life_state'=>$r['life_state'],'resources'=>[]];
        if($out)foreach($this->sql('SELECT entity_id,resource_key,current_value,maximum_value FROM '.$this->table('resources').' WHERE entity_id IN ('.implode(',',array_fill(0,count($out),'?')).') ORDER BY entity_id,resource_key',array_keys($out))['rows'] as $r)$out[(string)$r['entity_id']]['resources'][]=['key'=>$r['resource_key'],'current'=>$r['current_value'],'maximum'=>$r['maximum_value']];
        return array_values($out);
    }
}
