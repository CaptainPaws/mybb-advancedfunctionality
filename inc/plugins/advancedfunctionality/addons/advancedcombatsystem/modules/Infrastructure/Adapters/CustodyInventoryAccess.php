<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';

/** GM custody authority, never a temporary user's permission to spend someone else's property. */
final class AF_ACS_CustodyInventoryAccess implements AF_ADVINV_OwnerAccess
{
    public function __construct(private mysqli $db,private string $sid,private int $actor) {}
    public function uid(): int
    {
        global $mybb;
        if ($this->actor<1 || $this->actor!==(int)($mybb->user['uid']??0) || empty($mybb->usergroup['canview']) || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('INV_ACCESS_DENIED');
        return $this->actor;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation): void
    {
        global $mybb;
        $this->uid();
        $read=$operation==='lookup'&&$scope===['system'=>'inventory','context_key'=>'owner:'.$ownerUid]&&$ref==='inventory-owner-v1';
        if (!$read && ($scope!==['system'=>'acs','context_key'=>$this->sid] || $ref!=='acs-custody:'.$this->sid || !in_array($operation,['reserve','release','lookup'],true))) throw new RuntimeException('INV_CUSTODY_PERMISSION_DENIED');
        if ($mutation && ($mybb->request_method??'')!=='post') throw new RuntimeException('INV_CUSTODY_PERMISSION_DENIED');
        $sql='SELECT s.status FROM `'.TABLE_PREFIX.'af_acs_sessions` s WHERE s.id=? AND EXISTS (SELECT 1 FROM `'.TABLE_PREFIX."af_acs_session_roles` r WHERE r.session_id=s.id AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?) AND (r.role='gm' OR (r.role='owner' AND s.owner_uid=r.uid))) AND EXISTS (SELECT 1 FROM `".TABLE_PREFIX.'af_acs_entities` e WHERE e.session_id=s.id AND e.owner_uid=?)';
        $stmt=$this->db->prepare($sql);$now=time();$stmt->bind_param('ssss',$this->sid,$this->actor,$now,$ownerUid);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$row || $mutation&&$operation==='reserve'&&$row['status']!=='active') throw new RuntimeException('INV_CUSTODY_PERMISSION_DENIED');
    }
}
