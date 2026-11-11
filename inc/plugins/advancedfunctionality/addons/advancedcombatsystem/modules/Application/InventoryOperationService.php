<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Infrastructure/OperationStore.php';
require_once __DIR__.'/../Infrastructure/SessionRepository.php';
require_once __DIR__.'/../Infrastructure/Adapters/InventoryAdapter.php';
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
require_once __DIR__.'/../Domain/InventoryReceipt.php';

/** Explicit standalone composition only. Critical combat costs stay in existing same-writer commit. */
final class AF_ACS_InventoryOperationService
{
    private AF_ACS_OperationStore $store;
    private AF_ACS_SessionRepository $sessions;
    public function __construct(private object $database,private AF_ACS_SessionAccess $access,private ?AF_ACS_InventoryAdapter $adapter=null)
    {
        $this->store=new AF_ACS_OperationStore($database);$this->sessions=new AF_ACS_SessionRepository($database);
        if (!($database instanceof mysqli) && isset($database->read_link) && $database->read_link!==$database->write_link) throw new RuntimeException('ACS_AUTHORITATIVE_CONNECTION_REQUIRED');
    }
    private function authority(string $sid,?int $actor=null,bool $native=true): array
    {
        if (!af_is_addon_enabled('advancedcombatsystem')) throw new RuntimeException('ACS_DISABLED');
        $s=$this->sessions->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();
        if (!$this->sessions->roles($sid,$uid) || $actor!==null&&(string)$actor!==$uid) throw new RuntimeException('ACS_INVENTORY_RECOVERY_PERMISSION_DENIED');
        if ($native) $this->access->thread($s['tid']);return $s;
    }
    private function owner(?array $intent=null): AF_ACS_InventoryAdapter
    {return $this->adapter??($intent===null?AF_ACS_InventoryAdapter::forCombat($this->database,(int)$this->access->uid()):AF_ACS_InventoryAdapter::forRecovery($this->database,$intent));}
    private function row(string $sid,string $key): array
    {
        $this->authority($sid);$row=$this->store->get($key)??throw new RuntimeException('ACS_OPERATION_NOT_FOUND');
        if ($row['session_id']!==$sid || $row['external_system']!=='advancedinventory' || $row['external_ref']!==$key || !hash_equals($row['payload_hash'],hash('sha256',$row['request_json']))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
        $intent=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);$this->authority($sid,$intent['actor']['uid']);
        if ($row['kind']!=='inventory.'.$intent['operation'] || $intent['operation_key']!==$key || AF_ADVINV_OwnerProtocol::canonical($intent['scope'])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','context_key'=>$sid]) || !in_array($intent['operation'],['reserve','consume','release','transfer','grant'],true) || !hash_equals($intent['payload_hash'],AF_ADVINV_OwnerProtocol::payloadHash($intent))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
        return [$row,$intent];
    }
    private function projection(array $row): array
    {
        if (in_array($row['status'],['committed','rejected','released'],true)) {
            $result=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);$intent=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_InventoryReceipt::verify($intent,$result['owner_receipt']??[]);
            if (AF_ACS_InventoryReceipt::status($result['owner_receipt'])!==$row['status']) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
        }
        return ['operation_key'=>$row['operation_key'],'payload_hash'=>json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR)['payload_hash'],'status'=>$row['status'],'operation_version'=>$row['operation_version'],'external_ref'=>$row['external_ref'],'result'=>json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR)];
    }
    public function inspect(string $sid,string $key): array
    {[$row]=$this->row(AF_ACS_Repository::integer($sid),$key);return $this->projection($row);}
    /** Internal server entry point, deliberately no HTTP dispatcher, no default grant/transfer authority. */
    public function execute(string $sid,array $intent): array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($sid);$this->authority($sid,$intent['actor']['uid']??0);
        if (!in_array($intent['operation']??null,['transfer','grant'],true) || AF_ADVINV_OwnerProtocol::canonical($intent['scope']??[])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','context_key'=>$sid])) throw new RuntimeException('ACS_INVENTORY_OPERATION_UNSUPPORTED');
        $owner=$this->owner();$owner->validateCommand($intent['operation'],$intent);
        $authorize=fn()=> $this->authority($sid,$intent['actor']['uid'],false);
        $row=$this->store->inventoryRegister($sid,$intent,$authorize);
        if ($row['status']!=='prepared') return $this->recover($sid,$row['operation_key'],$row['operation_version']);
        // Persist before dispatch. Crash/lost acknowledgement can only proceed through lookup.
        $row=$this->store->inventoryTransition($row['operation_key'],$row['operation_version'],'recovery_required',['schema_version'=>1,'phase'=>'owner_dispatch','payload_hash'=>$intent['payload_hash']],$authorize);
        try {$receipt=$owner->{$intent['operation']}($intent);}
        catch (Throwable $error) {return $this->projection($row);}
        if (!in_array($receipt['status']??null,['prepared','committed','released','rejected'],true)) return $this->projection($row);
        AF_ACS_InventoryReceipt::verify($intent,$receipt);
        return $this->projection($this->store->inventoryTransition($row['operation_key'],$row['operation_version'],AF_ACS_InventoryReceipt::status($receipt),['schema_version'=>1,'owner_receipt'=>$receipt],$authorize));
    }
    /** Lookup only, including a proven not_found. No retry, consume, transfer, grant or release here. */
    public function recover(string $sid,string $key,string $expectedVersion): array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($sid);[$row,$intent]=$this->row($sid,$key);
        if (!in_array($row['status'],['prepared','recovery_required'],true)) return $this->projection($row);
        if ($row['operation_version']!==AF_ACS_Repository::integer($expectedVersion)) throw new RuntimeException('ACS_VERSION_CONFLICT');
        $lookup=['schema_version'=>1,'operation'=>'lookup','operation_key'=>'acs:recovery:'.hash('sha256',$key),'actor'=>$intent['actor'],'scope'=>$intent['scope'],'payload'=>['target_operation_key'=>$intent['operation_key']]];$lookup['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lookup);
        try {$receipt=$this->owner($intent)->lookup($lookup);} catch (Throwable $error) {return $this->projection($row)+['lookup_state'=>'unavailable'];}
        if (!in_array($receipt['status']??null,['prepared','committed','released','rejected'],true)) return $this->projection($row)+['lookup_state'=>$receipt['status']??'unavailable'];
        AF_ACS_InventoryReceipt::verify($intent,$receipt);
        $saved=$this->store->inventoryTransition($key,$row['operation_version'],AF_ACS_InventoryReceipt::status($receipt),['schema_version'=>1,'owner_receipt'=>$receipt],fn()=> $this->authority($sid,$intent['actor']['uid'],false));
        return $this->projection($saved);
    }
}
