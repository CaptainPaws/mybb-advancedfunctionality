<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';
require_once __DIR__.'/../EncounterRepository.php';

/** Owner-side IC-INV-1 composition. Current consent AND the owner's spending gate are required.
 * Reads only: no owner→ACS lock inversion, native callbacks, transfer or grant authority.
 */
final class AF_ACS_CombatInventoryAccess implements AF_ADVINV_OwnerAccess
{
    public function __construct(private mysqli $db,private int $actor,private ?array $readContext=null) {}
    public function uid(): int
    {
        global $mybb;
        if ($this->actor<1 || $this->actor!==(int)($mybb->user['uid']??0) || empty($mybb->usergroup['canview']) || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('INV_ACCESS_DENIED');
        return $this->actor;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation): void
    {
        if($this->readContext!==null && $operation==='lookup' && !$mutation && $scope===['system'=>'inventory','context_key'=>'owner:'.$ownerUid] && $ref==='inventory-owner-v1'){
            [$sid,$entity,$category]=$this->readContext;$this->verifySelection($ownerUid,$sid,$entity,$category);return;
        }
        $this->decision($operation,$ownerUid,$scope,$ref,$mutation,true);
    }
    public function verifySelection(int $ownerUid,string $sid,string $entity,string $category='ordinary'): void
    {
        $this->decision('reserve',$ownerUid,['system'=>'acs','context_key'=>$sid],'acs-item:'.$sid.':'.$entity.($category==='ordinary'?'':':'.$category),true,false);
    }
    private function decision(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation,bool $checkPost): void
    {
        global $mybb;$uid=$this->uid();
        if (($scope['system']??null)!=='acs' || !preg_match('/\Aacs-item:([1-9][0-9]*):([1-9][0-9]*)(?::(item_consume|equipment_change))?\z/D',$ref,$m) || $scope['context_key']!==$m[1] || !in_array($operation,['lookup','reserve','consume','release'],true)) throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $repo=new AF_ACS_EncounterRepository($this->db);$entity=$repo->entity($m[1],$m[2]);$session=$repo->session($m[1]);
        // IC-INV-1 uses the authenticated actor as the owner placeholder for a missing lookup.
        // Existing receipts still pass samePrincipal(actor/context) in OwnerProtocol.
        if($entity && $operation==='lookup' && !$mutation && $ownerUid===$uid && isset($m[3]))$ownerUid=(int)$entity['owner_uid'];
        if(!$entity || !$session || $entity['kind']!=='character' || (int)$entity['owner_uid']!==$ownerUid || !$repo->roles($m[1],(string)$uid) || !$repo->roles($m[1],(string)$ownerUid))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $category=$m[3]??'ordinary';
        if($ownerUid!==$uid && ($category==='ordinary' || $operation==='consume' && $category!=='item_consume' || !$repo->controllerFor($m[1],$m[2],(string)$uid,$category)))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        if(!$mutation)return;
        // The grant does not replace the owner's independent inventory_spending_enabled permission.
        $controller=$repo->controllerFor($m[1],$m[2],(string)$ownerUid);$permission=json_decode($controller['permissions_json']??'{}',true,16,JSON_THROW_ON_ERROR);
        if($checkPost && ($mybb->request_method??'')!=='post' || $session['status']!=='active' || !$controller
            || ($permission['schema_version']??null)!==1 || ($permission['combat_operations_enabled']??null)!==true || ($permission['inventory_spending_enabled']??null)!==true || $operation!=='release' && $entity['life_state']!=='active')throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
    }
}
