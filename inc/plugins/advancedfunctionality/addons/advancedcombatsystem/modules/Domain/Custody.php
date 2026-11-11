<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/Items.php';
require_once __DIR__.'/Equipment.php';

/** Retained custody facts and pure flat-bonus projection. No owner mutation or controller grant. */
final class AF_ACS_Custody
{
    public static function payload(array $row): array
    {
        $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if (($p['schema_version']??null)!==1 || ($p['engine_version']??null)!=='acs-custody-1' || ($p['payload_hash']??null)!==AF_ACS_Equipment::fingerprint(array_diff_key($p,['payload_hash'=>1]))) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');
        foreach (['session_id','inventory_item_id','owner_uid','custodian_entity_id','active_user_entity_id','status','custody_version'] as $key) {
            if (!array_key_exists($key,$p) || ($row[$key]===null)!==($p[$key]===null) || (string)$row[$key]!== (string)$p[$key]) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');
        }
        return $p;
    }
    public static function held(array $rows): array
    {
        $out=[];foreach ($rows as $row) {self::payload($row);if ($row['status']==='held') $out[(string)$row['inventory_item_id']]=$row;}
        return $out;
    }
    public static function bonuses(array $equipment): array
    {
        if (!hash_equals($equipment['content_hash'],hash('sha256',$equipment['payload_json']))) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');
        $p=json_decode($equipment['payload_json'],true,64,JSON_THROW_ON_ERROR);
        return isset($p['item'])?$p['item']['bonuses']:AF_ACS_Items::bonuses($p['mechanical_properties']??[]);
    }
    private static function sum(array &$total,array $delta): void
    {foreach ($delta as $key=>$value) $total[$key]=($total[$key]??0)+AF_ACS_Numeric::number($value);}
    public static function stats(array $registration,array $equipment,array $custody,string $actor,array $stats): array
    {
        $bundle=$registration['equipment_snapshot']??null;$cs=$registration['source_snapshot'];$baseline=[];
        if ($bundle!==null) {
            AF_ACS_Equipment::baseline($bundle,$cs);
            foreach ($bundle['records'] as $record) if ($record['baseline_included']) $baseline[$record['slot']]=$record;
        }
        $held=self::held($custody);$old=[];$new=[];$proof=[];$snapshot='1';
        foreach ($equipment as $row) {
            $slot=$row['slot_key'];$active=$row['status']==='active'&&!isset($held[(string)$row['inventory_item_id']]);
            if (!hash_equals($row['content_hash'],hash('sha256',$row['payload_json']))) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
            if (isset($p['item']) && ((int)$row['inventory_item_id']!==$p['item']['item_id'] || $slot!==$p['item']['slot'] || $p['item']['owner_uid']!==$cs['identity']['owner_uid'])) throw new RuntimeException('ACS_ITEM_PROVENANCE_REQUIRED');
            $changed=$slot==='weapon_mainhand'&&isset($p['item']);
            if (!$changed&&$active) continue;
            if (isset($baseline[$slot])) self::sum($old,AF_ACS_Items::bonuses($baseline[$slot]['mechanical_properties']));
            if ($active) self::sum($new,self::bonuses($row));
            $proof[]=['snapshot'=>$row['item_snapshot_id'],'hash'=>$row['content_hash'],'status'=>$row['status'],'version'=>(string)$row['equipment_version']];$snapshot=(string)$row['item_snapshot_id'];
        }
        foreach ($held as $row) if ((string)$row['active_user_entity_id']===$actor) {
            $p=self::payload($row);self::sum($new,$p['item']['bonuses']);$proof[]=['custody'=>(string)$row['id'],'hash'=>$p['payload_hash']];$snapshot=$p['item']['snapshot_id'];
        }
        if (!$proof) return $stats;
        $touched=array_unique([...array_keys($old),...array_keys($new)]);
        foreach ($cs['source_breakdown']??[] as $trace) if (in_array($trace['stat_key']??null,$touched,true) && isset($trace['before'],$trace['after']) && (($trace['operation']??null)!=='flat' || abs(($trace['after']-$trace['before'])-$trace['value'])>1e-9)) throw new RuntimeException('ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED');
        return AF_ACS_Items::rebase($stats,$old,$new,$snapshot,['fingerprint'=>AF_ACS_Equipment::fingerprint(['baseline'=>$cs['fingerprint']??AF_ACS_Equipment::fingerprint($registration),'proof'=>$proof])])[0];
    }
}
