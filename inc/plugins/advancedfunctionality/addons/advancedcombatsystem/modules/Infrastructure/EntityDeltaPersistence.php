<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
/** The existing entity/resource CAS projector shared by combat and encounter start. */
trait AF_ACS_EntityDeltaPersistence
{
    private function projectEntityDeltas(array $f,AF_ACS_ResolverResult $result):array {return $this->projectEntityStateDeltas($f,$result->serverInitial(),$result->serverState());}
    private function projectEntityStateDeltas(array $f,array $before,array $after):array
    {
        $changes=[];
        $states=[];foreach($f['entity_states'] as $state)$states[(string)$state['entity_id']]=$state;$resources=[];
        foreach ($f['resources'] as $r) { $resources[(string)$r['entity_id']][$r['resource_key']]=$r; }
        foreach ($after['entities'] as $id=>$value) {
            $id=(string)$id;$old=$before['entities'][$id];$s=$states[$id];$resource=$resources[$id]['hp']??null;
            if (array_keys($old['resources'])!==array_keys($value['resources']) || $value['recovery_blocked'] || !$resource
                || count($resources[$id])!==count($old['resources'])+1 || (string)$resource['is_integer']!=='1'
                || (float)$resource['current_value']!==(float)$old['hp'] || (float)$resource['maximum_value']!==(float)$old['max_hp']) {
                throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            }
            foreach($old['resources'] as $key=>$balance){$stored=$resources[$id][$key]??null;
                if(!$stored||(string)$stored['is_integer']!=='1'||(string)$stored['current_value']!==(string)$balance['current']||(string)$stored['maximum_value']!==(string)$balance['maximum'])throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            }
            foreach($old['resources'] as $key=>$balance)if($value['resources'][$key]['maximum']!==$balance['maximum'])throw new RuntimeException('ACS_COMBAT_RESOURCE_WRITER_UNSUPPORTED');
            if ($value===$old) { continue; }
            self::integer((string)$resource['resource_version'],'18446744073709551614');
            if ($this->sql('UPDATE '.$this->table('entity_states').' SET hp=?,hp_max=?,life_state=?,state_version=? WHERE entity_id=? AND state_version=?',
                [$value['hp'],$value['max_hp'],$value['life_state'],$value['version'],$id,$s['state_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            if ($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,maximum_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',
                [$value['hp'],$value['max_hp'],$resource['id'],$resource['resource_version']])['affected']!==1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            foreach($value['resources'] as $key=>$balance){$stored=$resources[$id][$key];if($balance['current']===$old['resources'][$key]['current'])continue;self::integer((string)$stored['resource_version'],'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',[$balance['current'],$stored['id'],$stored['resource_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');}
            $changes[]=['entity_id'=>$id,'before'=>$old,'after'=>$value];
        }
        return $changes;
    }
}
