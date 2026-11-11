<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';
/** Historical acknowledgement lookup by its original actor. Never a spending/grant permission. */
final class AF_ACS_InventoryRecoveryAccess implements AF_ADVINV_OwnerAccess
{
    public function __construct(private mysqli $db,private array $intent) {}
    public function uid(): int
    {
        global $mybb;$uid=(int)($mybb->user['uid']??0);
        if ($uid<1 || $uid!==$this->intent['actor']['uid'] || empty($mybb->usergroup['canview']) || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('INV_ACCESS_DENIED');return $uid;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation): void
    {
        $uid=$this->uid();
        if ($mutation || $operation!=='lookup' || AF_ADVINV_OwnerProtocol::canonical($scope)!==AF_ADVINV_OwnerProtocol::canonical($this->intent['scope']) || $ref!==$this->intent['actor']['authorization_ref']) throw new RuntimeException('INV_ACCESS_DENIED');
        $stmt=$this->db->prepare('SELECT o.request_json,o.payload_hash FROM `'.TABLE_PREFIX.'af_acs_operations` o WHERE o.operation_key=? AND o.session_id=? AND o.external_system=? AND EXISTS (SELECT 1 FROM `'.TABLE_PREFIX.'af_acs_session_roles` r WHERE r.session_id=o.session_id AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?))');
        $system='advancedinventory';$sid=$scope['context_key'];$now=time();$key=$this->intent['operation_key'];$stmt->bind_param('sssss',$key,$sid,$system,$uid,$now);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$row || !hash_equals($row['payload_hash'],hash('sha256',$row['request_json'])) || AF_ADVINV_OwnerProtocol::canonical(json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR))!==AF_ADVINV_OwnerProtocol::canonical($this->intent)) throw new RuntimeException('INV_ACCESS_DENIED');
    }
}
