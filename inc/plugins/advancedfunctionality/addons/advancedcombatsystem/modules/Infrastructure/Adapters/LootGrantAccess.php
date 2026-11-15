<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';
require_once __DIR__.'/../EncounterRepository.php';
require_once __DIR__.'/SessionAccess.php';
/** Exact retained intent only. Native preflight outside owner locks; subsequent checks are non-locking reads. */
final class AF_ACS_LootGrantAccess implements AF_ADVINV_ExactPropertyAccess
{
    private AF_ACS_EncounterRepository $repo;
    private bool $checked=false;
    public function __construct(private mysqli $db,private string $sid,private string $eid,private string $entry,private bool $allowGrant,AF_ACS_SessionAccess $access)
    {
        $this->repo=new AF_ACS_EncounterRepository($db);$session=$this->repo->session($sid)??throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');$access->thread($session['tid']);$this->facts();
    }
    public function uid():int
    {
        global $mybb;$uid=(int)($mybb->user['uid']??0);
        if($uid<1||empty($mybb->usergroup['canview'])||!empty($mybb->usergroup['isbannedgroup']))throw new RuntimeException('INV_ACCESS_DENIED');return $uid;
    }
    private function facts():array
    {
        $session=$this->repo->session($this->sid)??throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');$uid=(string)$this->uid();
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated'])||!AF_ACS_AuthorizationService::sessionGm($session,$this->repo->roles($this->sid,$uid),$uid))throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');
        $f=$this->repo->lootIssueIntent($this->sid,$this->eid,$this->entry);
        if($f['intent']['actor']['uid']!==$this->uid())throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');return $f;
    }
    public function authorizePropertyIntent(array $request):void
    {
        $this->checked=false;$saved=$this->facts();$intent=$saved['intent'];
        if(($request['operation']??null)==='grant'){
            if(!$this->allowGrant||$saved['entry']['status']!=='pending_issue'||!in_array($saved['operation']['status'],['prepared','recovery_required'],true)||AF_ADVINV_OwnerProtocol::canonical($request)!==AF_ADVINV_OwnerProtocol::canonical($intent))throw new RuntimeException('INV_LOOT_INTENT_CONFLICT');
        }elseif(($request['operation']??null)==='lookup'){
            if(($request['actor']??null)!=$intent['actor']||($request['scope']??null)!=$intent['scope']||($request['payload']['target_operation_key']??null)!==$intent['operation_key'])throw new RuntimeException('INV_LOOT_INTENT_CONFLICT');
        }else throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');$this->checked=true;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $authorizationRef,bool $mutation):void
    {
        $f=$this->facts();$intent=$f['intent'];
        if(!$this->checked||$scope!=$intent['scope']||$authorizationRef!==$intent['actor']['authorization_ref']||$mutation&&($operation!=='grant'||!$this->allowGrant)||!$mutation&&$operation!=='lookup'||$ownerUid!==$intent['payload']['recipient_uid']&&($mutation||$ownerUid!==$this->uid()))throw new RuntimeException('INV_LOOT_GRANT_NOT_AUTHORIZED');
    }
}
