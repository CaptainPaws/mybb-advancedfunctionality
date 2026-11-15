<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/InventoryReceipt.php';
/** Fixed identities and receipt evidence, never client item data or current Inventory quantities. */
final class AF_ACS_LootIssue
{
    public static function key(array $entry):string {return 'acs:loot:issue:'.$entry['id'].':'.$entry['allocation_version'];}
    public static function ref(string $eid,array $entry):string {return 'acs-loot-issue:'.$eid.':'.$entry['id'].':'.$entry['allocation_version'];}
    public static function binding(array $facts,array $intent):void
    {
        $e=$facts['entry'];$g=$facts['generated'];$n=$intent['payload']['normalized_item']??[];$hash=$n['definition_ref']['hash']??'';
        if(($intent['schema_version']??null)!==1||($intent['operation']??null)!=='grant'||($intent['operation_key']??null)!==self::key($e)||($intent['actor']['authorization_ref']??null)!==self::ref($facts['encounter_id'],$e)||($intent['scope']??null)!==['system'=>'acs','context_key'=>$facts['session_id']]&&AF_ADVINV_OwnerProtocol::canonical($intent['scope']??[])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','context_key'=>$facts['session_id']])||($intent['payload']['recipient_uid']??null)!==(int)$e['recipient_uid']||($intent['payload']['provenance']??null)!==['system'=>'acs','source_key'=>'loot-entry:'.$e['id']]&&AF_ADVINV_OwnerProtocol::canonical($intent['payload']['provenance']??[])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','source_key'=>'loot-entry:'.$e['id']])||($n['qty']??null)!==$g['qty']||($n['definition_ref']['type']??null)!=='arpg_item'||($n['definition_ref']['key']??null)!==$g['item_ref']['key']||!is_string($hash)||!hash_equals($hash,hash('sha256',$n['snapshot_json']??''))||($n['definition_ref']['version']??null)!=='sha256:'.$hash||($n['entity']??null)!=='equipment'||!in_array($n['subtype']??null,['consumable','ammo'],true)||!hash_equals($intent['payload_hash']??'',AF_ADVINV_OwnerProtocol::payloadHash($intent)))throw new RuntimeException('ACS_LOOT_ISSUE_INVALID');
    }
    public static function receipt(array $intent,array $receipt):void
    {
        AF_ACS_InventoryReceipt::verify($intent,$receipt);
        if(($receipt['provenance']??null)!=$intent['payload']['provenance']||($receipt['qty']??null)!==$intent['payload']['normalized_item']['qty'])throw new RuntimeException('ACS_LOOT_ISSUE_RECEIPT_INVALID');
        if($receipt['status']==='committed'){
            $versions=$receipt['inventory_versions']??[];
            if(($receipt['code']??null)!=='OK'||!is_int($receipt['item_id']??null)||$receipt['item_id']<1||($receipt['destination_item_id']??null)!==$receipt['item_id']||($receipt['external_ref']??null)!==(string)$receipt['item_id']||count($versions)!==1||($versions[0]['uid']??null)!==$intent['payload']['recipient_uid']||!is_int($versions[0]['before']??null)||($versions[0]['after']??null)!==$versions[0]['before']+1)throw new RuntimeException('ACS_LOOT_ISSUE_RECEIPT_INVALID');
        }
    }
}
