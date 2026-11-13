<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/ItemActionService.php';
require_once __DIR__.'/../Domain/PreparedAction.php';

/** Explicit owner-only cost selection; quantity and mechanics come exclusively from pinned KB. */
final class AF_ACS_PreparedItemService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    private function read(string $sid,string $actor,bool $lock=false):array
    {
        global $db;$uid=(int)$this->access->uid();$e=$this->repo->entity($sid,$actor);
        $this->repo->assertReadConnection($db);
        if(!$e || $e['kind']!=='character' || (int)$e['owner_uid']!==$uid)throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $this->repo->assertInventorySettled($sid);
        $reader=AF_ACS_ItemActionService::reader($db,$uid,$sid,$actor,'ordinary');
        (new AF_ACS_CombatInventoryAccess($db instanceof mysqli?$db:$db->write_link,$uid))->verifySelection($uid,$sid,$actor);
        if($lock)$reader->fence($uid,$uid);
        return $reader->read($uid,$uid)->serverData();
    }
    private function item(array $item,array $cost):array
    {
        if($item['entity']!=='equipment'||$item['kb_type']!=='arpg_item'||!in_array($item['subtype'],['consumable','ammo'],true)||!empty(af_advinv_resolve_appearance_item($item)['is_visual_item']))throw new DomainException('ACS_PREPARED_UNSUPPORTED');
        if($item['kb_key']!==$cost['definition']['key'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        require_once __DIR__.'/../../../charactersheets/modules/combat_snapshot.php';
        $issues=[];$reads=[];$d=af_cs_snapshot_definition('arpg_item',$item['kb_key'],$reads,$issues);
        if(!$d||array_filter($issues,fn($e)=>($e['severity']??'error')==='error'))throw new RuntimeException('ACS_ITEM_DEFINITION_UNAVAILABLE');
        $pin=['type'=>'arpg_item','key'=>$item['kb_key'],'version'=>$d['version']['declared_version'],'fingerprint'=>$d['version']['fingerprint']];
        if($pin!==$cost['definition']||($d['rules']['item']['item_kind']??null)!==$item['subtype'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        return ['definition'=>$pin,'definition_payload'=>$d['rules'],'item_kind'=>$item['subtype'],'item_fingerprint'=>AF_ACS_Equipment::fingerprint(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json'])))];
    }
    public function catalog(string $sid,string $actor,array $contract):array
    {
        $costs=array_filter($contract['additional_costs'],fn($c)=>$c['kind']==='item');if(!$costs)return [];
        $read=$this->read($sid,$actor);$out=[];$held=AF_ACS_Custody::held($this->repo->custodyRows($sid));
        foreach($costs as $index=>$cost){$options=[];foreach($read['items'] as $item){if(isset($held[(string)$item['id']]))continue;
            try{$this->item($item,$cost);}catch(DomainException|RuntimeException $e){continue;}
            $available=(int)$item['qty'];foreach($read['reservations'] as $r)if((int)$r['item_id']===(int)$item['id'])$available-=(int)$r['remaining_qty'];
            if($available>=$cost['amount'])$options[]=['inventory_item_id'=>(string)$item['id'],'label'=>$item['kb_key'].' #'.$item['id'],'available'=>$available];
        }$out[]=['cost_index'=>$index,'key'=>$cost['key'],'qty'=>$cost['amount'],'timing'=>$cost['timing'],'options'=>$options];}
        return $out;
    }
    public function bindings(string $sid,string $actor,array $contract,array $parameters,?string $draft,int $index,bool $lock=false):array
    {
        $costs=array_filter($contract['additional_costs'],fn($c)=>$c['kind']==='item');
        if(!$costs){if($parameters!==[])throw new RuntimeException('builder_parameters_unsupported');return [];}
        if(array_keys($parameters)!==['prepared_items']||!is_array($parameters['prepared_items'])||!array_is_list($parameters['prepared_items'])||count($parameters['prepared_items'])!==count($costs))throw new DomainException('ACS_PREPARED_UNSUPPORTED');
        $selected=[];foreach($parameters['prepared_items'] as $s){if(!is_array($s)||array_diff(array_keys($s),['cost_index','inventory_item_id'])||!is_int($s['cost_index']??null)||!isset($costs[$s['cost_index']])||isset($selected[$s['cost_index']]))throw new DomainException('ACS_PREPARED_UNSUPPORTED');$selected[$s['cost_index']]=AF_ACS_Repository::integer($s['inventory_item_id']??null,'4294967295');}
        $read=$this->read($sid,$actor,$lock);$saved=[];
        if($draft){$seals=$this->repo->sealRows($draft,true);if(isset($seals[$index]))$saved=$this->repo->itemSealDefinition($sid,$seals[$index])['prepared_items']??[];}
        $available=[];$items=[];foreach($read['items'] as $item){$id=(string)$item['id'];$available[$id]=(int)$item['qty'];$items[$id]=$item;}
        foreach($read['reservations'] as $r)$available[(string)$r['item_id']]-=(int)$r['remaining_qty'];
        foreach($saved as $s){$ref=$s['reserve_receipt']['reservation'];$found=false;foreach($read['reservations'] as $r)if((string)$r['id']===$ref['id']&&$r['context_system']==='acs'&&$r['context_key']===$sid&&(int)$r['owner_uid']===(int)$this->access->uid()&&(int)$r['item_id']===$s['item_id']&&(int)$r['remaining_qty']===$s['qty']&&(int)$r['reservation_version']===$ref['version'])$found=true;
            if(!$found||$s['inventory_version']!==$read['inventory_version'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');$available[(string)$s['item_id']]+=$s['qty'];
        }
        $held=AF_ACS_Custody::held($this->repo->custodyRows($sid));$out=[];
        foreach($costs as $i=>$cost){$id=$selected[$i];$item=$items[$id]??throw new RuntimeException('ACS_ITEM_INSUFFICIENT_QTY');if(isset($held[$id]))throw new RuntimeException('ACS_CUSTODY_ITEM_UNAVAILABLE');
            $this->repo->assertInventorySettled($sid,[$id]);$pin=$this->item($item,$cost);
            $binding=['cost_index'=>$i,'cost_key'=>$cost['key'],'item_id'=>(int)$id,'owner_uid'=>(int)$this->access->uid(),'qty'=>$cost['amount'],'timing'=>$cost['timing'],'inventory_version'=>$read['inventory_version']]+$pin;
            $old=null;foreach($saved as $s)if($s['cost_index']===$i)$old=$s;
            if($saved&&(!$old||array_diff_key($old,['reserve_receipt'=>true])!==$binding))throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            if(($available[$id]??0)<$binding['qty'])throw new RuntimeException('ACS_ITEM_INSUFFICIENT_QTY');$available[$id]-=$binding['qty'];
            $binding['reserve_receipt']=$old['reserve_receipt']??null;$out[]=$binding;
        }
        return $out;
    }
    public function reserve(array $draft,array $action,array $bindings,string $identity):array
    {
        $items=new AF_ACS_ItemActionService($this->repo,$this->access);
        foreach($bindings as &$b)$b['reserve_receipt']=$items->reserve(['item_id'=>$b['item_id'],'qty'=>$b['qty'],'operation'=>'consume'],$draft['session_id'],$action['actor_id'],$identity.':cost:'.$b['cost_index']);unset($b);
        // All costs retain the post-reserve owner version, including several costs on one stack.
        if($bindings){$read=$this->read($draft['session_id'],$action['actor_id'],true);foreach($bindings as &$b)$b['inventory_version']=$read['inventory_version'];unset($b);}
        return $bindings;
    }
    public function consume(array $draft,array $seal):array
    {
        global $db;$payload=$this->repo->sealPayload($seal);$definition=$this->repo->itemSealDefinition($draft['session_id'],$seal);$saved=$definition['prepared_items']??[];
        $contract=AF_ACS_PreparedAction::contract($definition['data']['definition']['prepared_action']);
        if(count($saved)!==count(array_filter($contract['additional_costs'],fn($c)=>$c['kind']==='item')))throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
        if(!$saved)return ['bindings'=>[],'receipts'=>[],'inventory_version'=>null];
        $a=$payload['action'];
        $current=$this->bindings($draft['session_id'],$a['actor_id'],$contract,$a['parameters'],$draft['id'],0,true);
        if($current!==$saved)throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        $adapter=AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid());$receipts=[];
        foreach($saved as $b){$reserve=$b['reserve_receipt'];$lookup=AF_ACS_ItemActionService::request('lookup','acs:prepared:lookup:'.$seal['id'].':'.$b['cost_index'],$draft['session_id'],$a['actor_id'],(int)$this->access->uid(),['target_operation_key'=>$reserve['operation_key']]);
            if(AF_ADVINV_OwnerProtocol::canonical($adapter->lookupParticipant($lookup))!==AF_ADVINV_OwnerProtocol::canonical($reserve))throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            if($b['timing']!=='on_prepare')continue;$key='acs:prepared:consume:'.$seal['id'].':'.$b['cost_index'];$lookup['payload']['target_operation_key']=$key;$lookup['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lookup);
            if($adapter->lookupParticipant($lookup)['status']!=='not_found')throw new RuntimeException('ACS_ITEM_OWNER_RESULT_ORPHAN');
            $request=AF_ACS_ItemActionService::request('consume',$key,$draft['session_id'],$a['actor_id'],(int)$this->access->uid(),['reservation_id'=>$reserve['reservation']['id'],'qty'=>$b['qty'],'expected_version'=>$reserve['reservation']['version']]);
            $receipt=$adapter->participate('consume',$request)->serverReceipt();if($receipt['status']!=='committed')throw new RuntimeException('ACS_ITEM_CONSUME_REJECTED');$receipts[]=$receipt;
        }
        return ['bindings'=>$saved,'receipts'=>$receipts,'inventory_version'=>$this->read($draft['session_id'],$a['actor_id'],true)['inventory_version']];
    }
    public function consumeTrigger(array $row,string $triggerEvent):array
    {
        global $db;$bindings=$this->repo->preparedTriggerItems($row);if(!$bindings)return [];
        $contract=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);$actor=$row['entity_id'];$sid=$row['session_id'];
        $read=$this->read($sid,$actor,true);
        $adapter=AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid());$receipts=[];
        foreach($bindings as $b){$key='acs:prepared:trigger-consume:'.$row['id'].':'.$triggerEvent.':'.$b['cost_index'];$lookup=AF_ACS_ItemActionService::request('lookup',$key.':preflight',$sid,$actor,(int)$this->access->uid(),['target_operation_key'=>$key]);if($adapter->lookupParticipant($lookup)['status']!=='not_found')throw new RuntimeException('ACS_ITEM_OWNER_RESULT_ORPHAN');}
        if($read['inventory_version']!==$contract['item_inventory_version'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        foreach($bindings as $b){
            if($b['owner_uid']!==(int)$this->access->uid())throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
            $cost=$contract['additional_costs'][$b['cost_index']];$item=null;foreach($read['items'] as $i)if((int)$i['id']===$b['item_id'])$item=$i;
            if(!$item||$this->item($item,$cost)['item_fingerprint']!==$b['item_fingerprint'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $reserve=$b['reserve_receipt'];$ref=$reserve['reservation'];$valid=false;foreach($read['reservations'] as $r)if((string)$r['id']===$ref['id']&&(int)$r['item_id']===$b['item_id']&&(int)$r['owner_uid']===$b['owner_uid']&&$r['context_system']==='acs'&&$r['context_key']===$sid&&(int)$r['remaining_qty']===$b['qty']&&(int)$r['reservation_version']===$ref['version'])$valid=true;
            if(!$valid)throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $key='acs:prepared:trigger-consume:'.$row['id'].':'.$triggerEvent.':'.$b['cost_index'];
            $lookup=AF_ACS_ItemActionService::request('lookup',$key.':lookup',$sid,$actor,(int)$this->access->uid(),['target_operation_key'=>$reserve['operation_key']]);
            if(AF_ADVINV_OwnerProtocol::canonical($adapter->lookupParticipant($lookup))!==AF_ADVINV_OwnerProtocol::canonical($reserve))throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $lookup['payload']['target_operation_key']=$key;$lookup['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lookup);
            if($adapter->lookupParticipant($lookup)['status']!=='not_found')throw new RuntimeException('ACS_ITEM_OWNER_RESULT_ORPHAN');
            $request=AF_ACS_ItemActionService::request('consume',$key,$sid,$actor,(int)$this->access->uid(),['reservation_id'=>$ref['id'],'qty'=>$b['qty'],'expected_version'=>$ref['version']]);
            $receipt=$adapter->participate('consume',$request)->serverReceipt();if($receipt['status']!=='committed')throw new RuntimeException('ACS_ITEM_CONSUME_REJECTED');$receipts[]=$receipt;
        }return $receipts;
    }
    public function cancelSeal(array $draft,array $seal):void
    {
        global $db;$saved=$this->repo->itemSealDefinition($draft['session_id'],$seal)['prepared_items']??[];if(!$saved)return;
        $adapter=AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid());foreach($saved as $b){$request=AF_ACS_ItemActionService::request('release','acs:prepared:seal-cancel:'.$seal['id'].':'.$b['cost_index'],$draft['session_id'],(string)$seal['actor_entity_id'],(int)$this->access->uid(),['reservation_id'=>$b['reserve_receipt']['reservation']['id'],'reason'=>'prepared_seal_cancel']);if($adapter->participate('release',$request)->serverReceipt()['status']!=='released')throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');}
    }
}
