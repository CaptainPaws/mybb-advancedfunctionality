<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../advancedinventory/modules/OwnerProtocol.php';
/** An owner acknowledgement resolves that operation, never an ACS combat submission. */
final class AF_ACS_InventoryReceipt
{
    public static function verify(array $intent,array $receipt): void
    {
        $allowed=match($intent['operation']) {'reserve'=>['prepared','rejected'],'release'=>['released','rejected'],'consume','transfer','grant'=>['committed','rejected'],default=>[]};
        $hash=$receipt['result_hash']??'';$copy=$receipt;unset($copy['result_hash']);
        if (!is_string($hash) || !hash_equals($hash,hash('sha256',AF_ADVINV_OwnerProtocol::canonical($copy))) || ($receipt['schema_version']??null)!==1 || ($receipt['operation_key']??null)!==$intent['operation_key'] || ($receipt['operation_type']??null)!==$intent['operation'] || ($receipt['payload_hash']??null)!==$intent['payload_hash'] || ($receipt['actor_uid']??null)!==$intent['actor']['uid'] || AF_ADVINV_OwnerProtocol::canonical($receipt['scope']??[])!==AF_ADVINV_OwnerProtocol::canonical($intent['scope']) || !in_array($receipt['status']??null,$allowed,true)) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
    }
    public static function status(array $receipt): string
    {return $receipt['status']==='prepared'?'committed':$receipt['status'];}
}
