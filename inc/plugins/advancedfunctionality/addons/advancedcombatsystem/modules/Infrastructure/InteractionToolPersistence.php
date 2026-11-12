<?php
if(!defined('IN_MYBB')){die('No direct access');}
/** Small same-writer participant. Presence is fenced/read, consumption uses IC-INV-1. */
trait AF_ACS_InteractionToolPersistence
{
    /** Capture dependencies before any participant mutates an owner; the fence remains held. */
    private function interactionToolInventory(array $row,AF_ACS_ResolverResult $result):?array
    {
        if(!array_filter($result->serverPlan()['actions'],static fn($s)=>isset($s['object_interaction']['tool'])))return null;
        global $db;$this->assertReadConnection($db);$uid=(int)$row['author_uid'];$reader=new AF_ADVINV_InventoryRead($db);$reader->fence($uid,$uid);return $reader->read($uid,$uid)->serverData();
    }
    private function commitInteractionTools(array $row,array $frozen,AF_ACS_ResolverResult $result,array $prepared,?array $current):array
    {
        $this->transactions->assertInternalBoundary();$steps=array_filter($result->serverPlan()['actions'],static fn($s)=>isset($s['object_interaction']['tool']));if(!$steps)return [];
        global $db;$this->assertReadConnection($db);$uid=(int)$row['author_uid'];if($current===null)throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');$receipts=[];
        foreach($steps as $index=>$step){$tool=$step['object_interaction']['tool'];$actor=$step['actor_id'];$entity=$this->sql('SELECT kind,owner_uid FROM '.$this->table('entities').' WHERE session_id=? AND id=?',[$row['session_id'],$actor])['rows'][0]??throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            if($entity['kind']!=='character'||(int)$entity['owner_uid']!==$uid||$tool['owner_uid']!==$uid||$tool['inventory_version']!==$current['inventory_version'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $item=null;foreach($current['items'] as $owned)if((int)$owned['id']===$tool['item_id'])$item=$owned;
            if(!$item||AF_ACS_Equipment::fingerprint(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json'])))!==$tool['item_fingerprint'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $available=(int)$item['qty'];$liveReservation=false;$ref=$tool['reservation']['reservation']??null;
            foreach($current['reservations'] as $r)if((int)$r['item_id']===$tool['item_id']){$available-=(int)$r['remaining_qty'];if($ref&&(string)$r['id']===$ref['id']&&(int)$r['reservation_version']===$ref['version']&&(int)$r['remaining_qty']===1&&$r['context_system']==='acs'&&$r['context_key']===$row['session_id'])$liveReservation=true;}
            if($tool['mode']==='requires'){if($available<1)throw new RuntimeException('ACS_INTERACTION_TOOL_UNAVAILABLE');continue;}
            if(!$liveReservation)throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $seal=$frozen['seals'][$index];$pin=$this->itemSealDefinition($row['session_id'],['definition_snapshot_id'=>$seal['payload']['definition_snapshot_id']])['object_interaction']['tool']??null;
            if(!$pin||$pin['item_id']!==$tool['item_id']||$pin['item_fingerprint']!==$tool['item_fingerprint']||$pin['reservation']!==$tool['reservation'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $adapter=AF_ACS_InventoryAdapter::forCombat($db,$uid);$reserve=$tool['reservation'];$ref=$reserve['reservation'];$lookup=AF_ACS_ItemActionService::request('lookup','acs:tool:lookup:'.$seal['id'],$row['session_id'],$actor,$uid,['target_operation_key'=>$reserve['operation_key']]);
            if(AF_ADVINV_OwnerProtocol::canonical($adapter->lookupParticipant($lookup))!==AF_ADVINV_OwnerProtocol::canonical($reserve))throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $key='acs:tool:consume:'.$row['submission_id'].':'.$seal['id'];$lookup['payload']['target_operation_key']=$key;$lookup['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lookup);if($adapter->lookupParticipant($lookup)['status']!=='not_found')throw new RuntimeException('ACS_ITEM_OWNER_RESULT_ORPHAN');
            $request=AF_ACS_ItemActionService::request('consume',$key,$row['session_id'],$actor,$uid,['reservation_id'=>$ref['id'],'qty'=>1,'expected_version'=>$ref['version']]);$receipt=$adapter->participate('consume',$request)->serverReceipt();if($receipt['status']!=='committed')throw new RuntimeException('ACS_ITEM_CONSUME_REJECTED');$receipts[]=$receipt;
        }return $receipts;
    }
}
