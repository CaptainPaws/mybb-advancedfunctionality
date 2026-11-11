<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';

/** Server composition, own-property only. Never acquires ACS locks from an owner callback. */
final class AF_ACS_CombatInventoryAccess implements AF_ADVINV_OwnerAccess
{
    public function __construct(private mysqli $db,private int $actor) {}
    public function uid(): int
    {
        global $mybb;
        if ($this->actor<1 || $this->actor!==(int)($mybb->user['uid']??0) || empty($mybb->usergroup['canview']) || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('INV_ACCESS_DENIED');
        return $this->actor;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation): void
    {$this->decision($operation,$ownerUid,$scope,$ref,$mutation,true);}
    /** Read-only current grant check, also valid for GET preview/status. Never authorizes a mutation. */
    public function verifySelection(int $ownerUid,string $sid,string $entity): void
    {$this->decision('reserve',$ownerUid,['system'=>'acs','context_key'=>$sid],'acs-item:'.$sid.':'.$entity,true,false);}
    private function decision(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation,bool $checkPost): void
    {
        global $mybb;
        if ($ownerUid!==$this->uid() || ($scope['system']??null)!=='acs' || !preg_match('/\Aacs-item:([1-9][0-9]*):([1-9][0-9]*)\z/D',$ref,$m) || $scope['context_key']!==$m[1] || !in_array($operation,['lookup','reserve','consume','release'],true)) throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        // Current READ COMMITTED reads only: no owner→ACS lock inversion, no native callbacks.
        $sql='SELECT e.owner_uid,s.status,st.life_state,c.permissions_json,c.mode,c.valid_from,c.valid_until,c.controller_uid FROM `'.TABLE_PREFIX.'af_acs_entities` e JOIN `'.TABLE_PREFIX.'af_acs_sessions` s ON s.id=e.session_id JOIN `'.TABLE_PREFIX.'af_acs_entity_states` st ON st.entity_id=e.id LEFT JOIN `'.TABLE_PREFIX."af_acs_controllers` c ON c.entity_id=e.id AND c.status='active' WHERE e.session_id=? AND e.id=? AND EXISTS (SELECT 1 FROM `".TABLE_PREFIX.'af_acs_session_roles` r WHERE r.session_id=s.id AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?))';
        $stmt=$this->db->prepare($sql);$now=time();$stmt->bind_param('ssss',$m[1],$m[2],$ownerUid,$now);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (count($rows)!==1 || (int)$rows[0]['owner_uid']!==$ownerUid) throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        if (!$mutation) return;
        $row=$rows[0];$grant=json_decode($row['permissions_json']??'{}',true,16,JSON_THROW_ON_ERROR);
        if ($checkPost && ($mybb->request_method??'')!=='post' || $row['status']!=='active' || $row['mode']!=='exclusive' || (int)$row['controller_uid']!==$ownerUid || (int)$row['valid_from']>$now || $row['valid_until']!==null && (int)$row['valid_until']<=$now
            || ($grant['schema_version']??null)!==1 || ($grant['combat_operations_enabled']??null)!==true || ($grant['inventory_spending_enabled']??null)!==true || $operation!=='release' && $row['life_state']!=='active') throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
    }
}
