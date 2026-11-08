<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../../Domain/Equipment.php';

/** Explicit server-only projection. Does not call a renderer, calculator, consume or reserve. */
final class AF_ACS_EquipmentAdapter
{
    public function prepare(array $cs,object $inventory,string $sessionId,int $actor): array
    {
        global $mybb;
        if ($actor<1 || $actor!==(int)($mybb->user['uid']??0) || ($cs['identity']['owner_uid']??null)!==$actor
            || ($cs['ready']??null)!==true || ($cs['equipment_policy']??null)!=='already_included_do_not_reapply'
            || !function_exists('af_cs_snapshot_definition') || !function_exists('af_inv_candidate_slots_for_item')) throw new RuntimeException('ACS_EQUIPMENT_SOURCE_UNAVAILABLE');
        $source=$inventory->serverData();$uid=$source['owner_uid'];
        if ($uid!==$actor) throw new RuntimeException('ACS_EQUIPMENT_ACCESS_DENIED');
        $items=[];foreach ($source['items'] as $item) $items[(int)$item['id']]=$item;
        $baseline=[];foreach ($cs['equipment'] as $item) $baseline[$item['item_id']]=$item;
        $bindings=[];
        foreach ($source['equipped'] as $binding) $bindings[]=['slot'=>$binding['equip_slot'],'binding'=>$binding,'support'=>false];
        foreach ($source['support'] as $binding) $bindings[]=['slot'=>$binding['slot_code'],'binding'=>$binding,'support'=>true];
        usort($bindings,static fn($a,$b)=>strcmp($a['slot'],$b['slot']));
        $records=[];$ids=[];$slots=[];$reads=[];
        foreach ($bindings as $binding) {
            $id=(int)$binding['binding']['item_id'];$slot=(string)$binding['slot'];$item=$items[$id]??null;
            if (!$item || isset($ids[$id]) || isset($slots[$slot]) || strlen($slot)>64 || (int)$item['uid']!==$uid || $item['entity']!=='equipment' || $item['kb_type']!=='arpg_item') throw new RuntimeException('ACS_EQUIPMENT_BINDING_INVALID');
            $ids[$id]=true;$slots[$slot]=true;$issues=[];
            $definition=af_cs_snapshot_definition('arpg_item',(string)$item['kb_key'],$reads,$issues);
            if (!$definition || array_filter($issues,static fn($i)=>($i['severity']??'error')==='error')) throw new RuntimeException('ACS_EQUIPMENT_DEFINITION_UNAVAILABLE');
            $ref=['type'=>'arpg_item','key'=>$item['kb_key'],'version'=>$definition['version']];
            $candidate=$item;$candidate['meta_json']=$definition['entry']['meta_json'];
            if ($binding['support']) {
                if (!af_inv_is_consumable_item($candidate) || !isset(af_inv_support_slots()[$slot])) throw new RuntimeException('ACS_EQUIPMENT_SLOT_INVALID');
            } elseif (!in_array($slot,af_inv_candidate_slots_for_item($candidate),true)) throw new RuntimeException('ACS_EQUIPMENT_SLOT_INVALID');
            if (!empty(af_advinv_resolve_appearance_item($candidate)['is_visual_item'])) throw new RuntimeException('ACS_EQUIPMENT_COSMETIC_SOURCE');
            $included=isset($baseline[$id]);
            if (!$binding['support'] && !$included) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
            if ($included && ($baseline[$id]['slot']!==$slot || af_kb_arpg_acs_fingerprint($baseline[$id]['binding'])!==af_kb_arpg_acs_fingerprint($binding['binding']) || $baseline[$id]['kb_ref']!==$ref || $baseline[$id]['instance_fingerprint']!==af_kb_arpg_acs_fingerprint($item))) throw new RuntimeException('ACS_EQUIPMENT_SOURCE_CHANGED');
            $reserved=0;$bound=[];
            foreach ($source['reservations'] as $reservation) {
                if ((int)$reservation['item_id']!==$id) continue;
                $qty=(int)$reservation['remaining_qty'];if ($qty<1) throw new RuntimeException('ACS_EQUIPMENT_RESERVATION_INVALID');
                $reserved+=$qty;
                if ($reservation['context_system']==='acs' && $reservation['context_key']===$sessionId) $bound[]=['id'=>(string)$reservation['id'],'remaining_qty'=>$qty,'version'=>(int)$reservation['reservation_version'],'status'=>'active'];
            }
            if ($reserved>(int)$item['qty']) throw new RuntimeException('ACS_EQUIPMENT_RESERVATION_INVALID');
            $trace=array_values(array_filter($cs['source_breakdown'],static fn($r)=>($r['source_type']??null)==='equipment' && ($r['source_id']??null)==='inventory:'.$id));
            $record=['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-equipment-1','inventory_item_id'=>$id,'owner_uid'=>$uid,
                'slot'=>$slot,'owned_qty'=>(int)$item['qty'],'inventory_version'=>$source['inventory_version'],'kb_ref'=>$ref,'mechanical_properties'=>$definition['rules'],
                'instance_fingerprint'=>af_kb_arpg_acs_fingerprint($item),'binding_fingerprint'=>af_kb_arpg_acs_fingerprint($binding['binding']),
                'source_provenance'=>['kind'=>'permanent_inventory','baseline_fingerprint'=>$cs['fingerprint'],'included_trace'=>$trace],
                'baseline_included'=>$included,'reservation_binding'=>$bound,'observed_global_reserved_qty'=>$reserved,'status'=>'active'];
            $record['snapshot_identity']=AF_ACS_Equipment::fingerprint($record);$records[]=$record;
        }
        // KB definitions are owner-authorized and pinned; source drift does not silently select latest.
        foreach ($reads as $read) { $check=[];$rows=af_cs_snapshot_select($read['table'],$read['where'],$check);if (af_kb_arpg_acs_fingerprint($rows)!==$read['hash']) throw new RuntimeException('ACS_EQUIPMENT_SOURCE_CHANGED'); }
        $bundle=['schema_version'=>1,'contract_id'=>'IC-ACS.equipment.v1','numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-equipment-1',
            'owner_uid'=>$uid,'inventory_version'=>$source['inventory_version'],'inventory_total'=>$source['total'],'baseline_fingerprint'=>$cs['fingerprint'],
            'base_characteristics'=>$cs['base_characteristics'],'registered_characteristics'=>$cs['characteristics'],'equipment_policy'=>'already_included_do_not_reapply',
            'records'=>$records,'temporary_use'=>[],'disabled'=>[],'cosmetics'=>'excluded'];
        $bundle['fingerprint']=AF_ACS_Equipment::fingerprint($bundle);AF_ACS_Equipment::baseline($bundle,$cs);return $bundle;
    }
}
