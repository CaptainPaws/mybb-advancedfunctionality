<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/ItemPersistence.php';
require_once __DIR__.'/Adapters/InventoryAdapter.php';
trait AF_ACS_ItemCommitPersistence
{
    use AF_ACS_ItemPersistence;
    /** Same writer/InnoDB only: provisional owner results become durable with the terminal ACS result. */
    private function commitItems(array $row,array $frozen,AF_ACS_ResolverResult $result,array $prepared): array
    {
        $steps=array_filter($result->serverPlan()['actions'],fn($step)=>isset($step['item_action']));
        if (!$steps) return [];
        global $db;
        $this->assertReadConnection($db);
        $uid=(int)$row['author_uid'];$reader=new AF_ADVINV_InventoryRead($db);$reader->fence($uid,$uid);$current=$reader->read($uid,$uid)->serverData();
        foreach ($steps as $index=>$step) {
            if (($prepared['prepared'][$index][2]['item_dependencies']['inventory_version']??null)!==$current['inventory_version']) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $item=null;foreach ($current['items'] as $owned) if ((int)$owned['id']===$step['item_action']['item_id']) $item=$owned;
            if (!$item || AF_ACS_Equipment::fingerprint(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json'])))!==$step['item_action']['item_fingerprint']) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        }
        $adapter=AF_ACS_InventoryAdapter::forCombat($db,(int)$row['author_uid']);$receipts=[];$protected=[];
        $final=$result->serverPlan()['item_state']['equipment'];$keep=[];
        foreach ($final as $slots) foreach ($slots as $slot) if (isset($slot['spec']['reservation'])) $keep[$slot['spec']['reservation']['reservation']['id']]=true;
        foreach ($steps as $index=>$step) {
            $spec=$step['item_action'];$actor=$step['actor_id'];$seal=$frozen['seals'][$index];
            $pinned=$this->itemSealDefinition($row['session_id'],['definition_snapshot_id'=>$seal['payload']['definition_snapshot_id']])['item_action']??null;
            if (AF_ACS_Equipment::fingerprint($pinned??[])!==AF_ACS_Equipment::fingerprint($spec) || !$spec['reservation']) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $reserve=$spec['reservation'];$ref=$reserve['reservation'];
            $lookup=AF_ACS_ItemActionService::request('lookup','acs:item:lookup:'.$seal['id'],$row['session_id'],$actor,(int)$row['author_uid'],['target_operation_key'=>$reserve['operation_key']]);
            if (AF_ADVINV_OwnerProtocol::canonical($adapter->lookupParticipant($lookup))!==AF_ADVINV_OwnerProtocol::canonical($reserve)) throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            if ($spec['operation']==='consume') {
                $key='acs:item:consume:'.$row['submission_id'].':'.$seal['id'];
                $lookup['payload']['target_operation_key']=$key;$lookup['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lookup);
                if ($adapter->lookupParticipant($lookup)['status']!=='not_found') throw new RuntimeException('ACS_ITEM_OWNER_RESULT_ORPHAN');
                $request=AF_ACS_ItemActionService::request('consume',$key,$row['session_id'],$actor,(int)$row['author_uid'],['reservation_id'=>$ref['id'],'qty'=>1,'expected_version'=>$ref['version']]);
                $receipt=$adapter->participate('consume',$request)->serverReceipt();
                if ($receipt['status']!=='committed') throw new RuntimeException('ACS_ITEM_CONSUME_REJECTED');
                $receipts[]=$receipt;
            } else $protected[$ref['id']]=[$reserve,$actor];
            if (isset($spec['outgoing_reservation'])) $protected[$spec['outgoing_reservation']['reservation']['id']]=[$spec['outgoing_reservation'],$actor];
        }
        foreach ($protected as $id=>[$reserve,$actor]) {
            if (isset($keep[$id])) continue;
            $request=AF_ACS_ItemActionService::request('release','acs:item:release:'.$row['submission_id'].':'.$id,$row['session_id'],$actor,(int)$row['author_uid'],['reservation_id'=>(string)$id,'reason'=>'combat_loadout_replaced']);
            $receipt=$adapter->participate('release',$request)->serverReceipt();
            if ($receipt['status']!=='released') throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');
            $receipts[]=$receipt;
        }
        $this->projectItemEquipment($row['session_id'],$final);
        return $receipts;
    }
}
