<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Initial retained equipment evidence. No equip action, stat arithmetic or live owner reads. */
final class AF_ACS_Equipment
{
    public static function fingerprint(array $payload): string
    {
        return af_kb_arpg_acs_fingerprint($payload);
    }
    public static function baseline(array $bundle,array $cs): array
    {
        if (($bundle['contract_id']??null)!=='IC-ACS.equipment.v1' || ($cs['ready']??null)!==true
            || ($cs['equipment_policy']??null)!=='already_included_do_not_reapply'
            || ($bundle['baseline_fingerprint']??null)!==($cs['fingerprint']??null)
            || ($bundle['fingerprint']??null)!==self::fingerprint(array_diff_key($bundle,['fingerprint'=>true]))
            || ($bundle['registered_characteristics']??null)!==($cs['characteristics']??null)
            || ($bundle['base_characteristics']??null)!==($cs['base_characteristics']??null)
            || !empty($bundle['temporary_use']) || !empty($bundle['disabled'])) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
        $seen=[];$slots=[];$included=[];
        foreach ($bundle['records']??[] as $record) {
            $id=$record['inventory_item_id']??null;$slot=$record['slot']??null;
            if (!is_int($id) || $id<1 || !is_string($slot) || isset($seen[$id]) || isset($slots[$slot])
                || ($record['snapshot_identity']??null)!==self::fingerprint(array_diff_key($record,['snapshot_identity'=>true]))
                || ($record['owner_uid']??null)!==($cs['identity']['owner_uid']??null)
                || ($record['status']??null)!=='active' || !is_bool($record['baseline_included']??null)) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
            $seen[$id]=$record;$slots[$slot]=true;
            if ($record['baseline_included']) $included[$id]=$record;
        }
        foreach ($cs['equipment']??[] as $item) {
            $id=$item['item_id'];$record=$included[$id]??null;
            if (!$record || $record['instance_fingerprint']!==$item['instance_fingerprint'] || $record['kb_ref']!==$item['kb_ref'] || $record['slot']!==$item['slot']) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
            unset($included[$id]);
        }
        if ($included) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
        $traces=[];
        foreach ($cs['source_breakdown']??[] as $trace) {
            if (($trace['source_type']??null)!=='equipment') continue;
            if (!preg_match('/\Ainventory:([1-9][0-9]*)\z/D',$trace['source_id']??'',$match)) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
            $record=$seen[(int)$match[1]]??null;$key=$trace['source_id'].':'.($trace['stat_key']??'').':'.($trace['source_path']??'');
            if (!$record || !$record['baseline_included'] || ($trace['included_in_total']??null)!==true || isset($traces[$key])
                || ($trace['source_version']['fingerprint']??null)!==$record['kb_ref']['version']['fingerprint']) throw new RuntimeException('ACS_EQUIPMENT_PROVENANCE_BLOCKED');
            $traces[$key]=true;
        }
        // Totals already contain the proven CS equipment contribution. Never add rules again.
        return $cs['characteristics'];
    }
}
