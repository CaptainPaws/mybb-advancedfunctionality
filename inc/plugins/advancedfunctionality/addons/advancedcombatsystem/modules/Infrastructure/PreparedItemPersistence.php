<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/PreparedItemService.php';

trait AF_ACS_PreparedItemPersistence
{
    private function preparedReleaseSavepoint(string $phase):void
    {
        $sql=match($phase){'start'=>'SAVEPOINT prepared_release_cost','rollback'=>'ROLLBACK TO SAVEPOINT prepared_release_cost','release'=>'RELEASE SAVEPOINT prepared_release_cost'};
        try{if(!$this->connection->query($sql))throw new RuntimeException('ACS_DATABASE_ERROR');}catch(mysqli_sql_exception $e){throw new RuntimeException('ACS_DATABASE_ERROR',(int)$e->getCode());}
    }
    public function settlePreparedItemsInCombat(array $row):array
    {
        return $this->internalCommand(function()use($row){foreach($this->preparedRows($row['session_id'],$row['encounter_id'],$row['entity_id']) as $stored)if($stored['id']===$row['id']&&in_array($stored['status'],['expired','cancelled'],true))return $this->settlePreparedItems($stored);throw new RuntimeException('ACS_VERSION_CONFLICT');});
    }
    public function preparedTriggerItems(array $row):array
    {
        if($row['status']!=='active'||$row['cost_state']!=='paid')throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
        $contract=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);
        return array_map(fn($b)=>$b+['prepared_action_id'=>$row['id'],'source_sealed_action_id'=>$row['source_sealed_action_id'],'prepared_inventory_version'=>$contract['item_inventory_version']],$this->pendingPreparedItems($row));
    }
    /** Strict internal handoff: original instance/reservation only, never a new selector/reserve. */
    public function pendingPreparedItems(array $row):array
    {
        if(!hash_equals($row['contract_hash'],hash('sha256',$row['contract_json'])))throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        $p=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);$bindings=$p['item_bindings']??[];if(!$bindings)return [];
        $seal=$this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE id=?',[$row['source_sealed_action_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $payload=$this->sealPayload($seal);$this->verifySealDefinition($row['session_id'],$seal,$payload);
        if($bindings!==($this->itemSealDefinition($row['session_id'],$seal)['prepared_items']??[])||$payload['action']['actor_id']!==$row['entity_id'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
        $out=[];foreach($bindings as $b){$cost=$p['additional_costs'][$b['cost_index']]??[];
            if(($cost['kind']??null)!=='item'||$cost['key']!==$b['cost_key']||$cost['amount']!==$b['qty']||$cost['timing']!==$b['timing']||$cost['definition']!==$b['definition']||$b['owner_uid']!==(int)$row['owner_uid']||($b['reserve_receipt']['status']??null)!=='prepared'||$b['reserve_receipt']['item_id']!==$b['item_id']||$b['reserve_receipt']['qty']!==$b['qty'])throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            if($b['timing']==='on_trigger')$out[]=$b;
        }return $out;
    }
    /** Same-writer release; failed/unauthorized release retains the global reservation and barrier. */
    public function settlePreparedItems(array $row):array
    {
        $this->commandWrite();if(!in_array($row['status'],['cancelled','expired'],true))throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
        $pending=$this->pendingPreparedItems($row);if(!$pending)return [];
        global $db,$mybb;$this->assertReadConnection($db);$receipts=[];$unresolved=false;
        foreach($pending as $b){
            $key='acs:prepared:release:'.$row['id'].':'.$b['cost_index'];$op='prepared-release:'.$row['id'].':'.$b['cost_index'];
            $request=AF_ACS_ItemActionService::request('release',$key,$row['session_id'],$row['entity_id'],$b['owner_uid'],['reservation_id'=>$b['reserve_receipt']['reservation']['id'],'reason'=>'prepared_terminal_unused']);
            $envelope=['schema_version'=>1,'session_id'=>$row['session_id'],'prepared_action_id'=>(string)$row['id'],'cost_index'=>$b['cost_index'],'intent'=>$request];$json=self::json($envelope);$hash=hash('sha256',$json);
            $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$op])['rows'][0]??null;
            if($old&&(!hash_equals($old['payload_hash'],$hash)||$old['kind']!=='prepared.release'))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
            if($old&&$old['status']==='released'){$receipts[]=json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt'];continue;}
            $this->preparedReleaseSavepoint('start');$receipt=null;$failure=null;
            try {
                // A GM Clock boundary cannot impersonate the owner recorded in the intent.
                if((int)($mybb->user['uid']??0)!==$b['owner_uid'])throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
                $adapter=AF_ACS_InventoryAdapter::forCombat($db,$b['owner_uid']);
                $lookup=AF_ACS_ItemActionService::request('lookup',$key.':lookup',$row['session_id'],$row['entity_id'],$b['owner_uid'],['target_operation_key'=>$key]);
                $known=$adapter->lookupParticipant($lookup);
                if($known['status']==='not_found')$receipt=$adapter->participate('release',$request)->serverReceipt();
                elseif($known['status']==='released'&&hash_equals($known['payload_hash'],$request['payload_hash']))$receipt=$known;
                else throw new RuntimeException('ACS_RECOVERY_REQUIRED');
                if($receipt['status']!=='released')throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');
            } catch(Throwable $e) {
                // If the writer itself is lost this rollback fails too; no fictitious durable outcome.
                $this->preparedReleaseSavepoint('rollback');$receipt=null;$failure='ACS_RECOVERY_REQUIRED';$unresolved=true;
            }
            $this->preparedReleaseSavepoint('release');
            $result=self::json(['schema_version'=>1,'owner_receipt'=>$receipt,'code'=>$failure]);$status=$receipt?'released':'recovery_required';
            if($old)$this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=?',[$status,$result,time(),$op]);
            else $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$op,$row['session_id'],$row['encounter_id'],'prepared.release',$hash,$status,$json,$result,time()]);
            if($receipt)$receipts[]=$receipt;
        }
        $this->sql('UPDATE '.$this->table('prepared_actions').' SET cost_state=? WHERE id=?',[$unresolved?'recovery_required':'settled',$row['id']]);return $receipts;
    }
    public function recoverPreparedItems(array $session,array $encounter,string $entity,string $id,string $version):array
    {
        $this->commandWrite();$row=null;foreach($this->preparedRows($session['id'],$encounter['id'],$entity) as $r)if($r['id']===$id)$row=$r;
        if(!$row||$row['cost_state']!=='recovery_required'||$row['prepared_version']!==$version||!in_array($row['status'],['expired','cancelled'],true))throw new RuntimeException('ACS_VERSION_CONFLICT');
        $receipts=$this->settlePreparedItems($row);$this->sql('UPDATE '.$this->table('prepared_actions').' SET prepared_version=prepared_version+1 WHERE id=? AND prepared_version=?',[$id,$version]);
        $session=$this->change($session,$encounter,'prepared_release_recovery',[]);$session['_command_result']+=['prepared_action_id'=>$id,'owner_receipts'=>$receipts,'cost_state'=>$this->sql('SELECT cost_state FROM '.$this->table('prepared_actions').' WHERE id=?',[$id])['rows'][0]['cost_state']];return $session;
    }
}
