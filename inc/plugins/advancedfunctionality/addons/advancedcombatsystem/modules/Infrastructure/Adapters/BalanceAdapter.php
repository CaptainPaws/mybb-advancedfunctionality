<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../balance/modules/RewardCredit.php';
require_once __DIR__.'/../EncounterRepository.php';
require_once __DIR__.'/SessionAccess.php';

/** Exact durable ACS source proof. A GM role by itself cannot mint anything. */
final class AF_ACS_BalanceRewardAuthority implements AF_BALANCE_RewardAuthority
{
    private AF_ACS_EncounterRepository $repo;
    public function __construct(mysqli $db,private AF_ACS_SessionAccess $access,private string $sid,private string $id)
    {
        $this->repo=new AF_ACS_EncounterRepository($db);
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$access->thread($s['tid']);
    }
    public function authorize(array $intent):array
    {
        global $mybb;
        $uid=$this->access->uid();$s=$this->repo->session($this->sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        if(!af_is_addon_enabled('advancedcombatsystem')||!af_is_addon_enabled('balance')||!empty($GLOBALS['af_acs_deactivated'])||empty($mybb->usergroup['canview'])||!empty($mybb->usergroup['isbannedgroup'])||!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($this->sid,$uid),$uid))throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        $r=$this->repo->rewardIntentEvidence($this->sid,$this->id);
        if(AF_ACS_Repository::json(['schema_version'=>1,'intent'=>$intent])!==AF_ACS_Repository::json(['schema_version'=>1,'intent'=>$r['intent']])||!in_array($r['status'],['prepared','recovery_required','issued'],true)||!in_array($r['operation']['status'],['prepared','recovery_required','committed'],true))throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        return $r['authority'];
    }
}
/** Standalone owner calls only. There is no fallback to af_balance_add[_exp]. */
class AF_ACS_BalanceAdapter
{
    protected mysqli $link;
    public function __construct(object $database,protected AF_ACS_SessionAccess $access)
    {
        $this->link=$database instanceof mysqli?$database:($database->write_link??throw new RuntimeException('ACS_BALANCE_UNAVAILABLE'));
        if(!($database instanceof mysqli)&&isset($database->read_link)&&$database->read_link!==$this->link)throw new RuntimeException('ACS_AUTHORITATIVE_CONNECTION_REQUIRED');
    }
    private function owner(string $sid,string $id):AF_BALANCE_RewardCredit
    {
        (new AF_ACS_EncounterRepository($this->link))->rewardExternalBoundary();
        if(!af_is_addon_enabled('balance'))throw new RuntimeException('ACS_BALANCE_UNAVAILABLE');
        return new AF_BALANCE_RewardCredit($this->link,new AF_ACS_BalanceRewardAuthority($this->link,$this->access,$sid,$id));
    }
    public function version(int $uid):int
    {
        (new AF_ACS_EncounterRepository($this->link))->rewardExternalBoundary();
        if(!af_is_addon_enabled('balance'))throw new RuntimeException('ACS_BALANCE_UNAVAILABLE');
        (new AF_BALANCE_RewardCredit($this->link))->assertStorage();
        $s=$this->link->prepare('SELECT owner_version FROM `'.TABLE_PREFIX.'af_balance` WHERE uid=?');$s->bind_param('i',$uid);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        $v=AF_ACS_Repository::integer($r['owner_version']??'1','9007199254740990');return (int)$v;
    }
    public function credit(string $sid,string $id):array
    {
        $r=(new AF_ACS_EncounterRepository($this->link))->rewardIntentEvidence($sid,$id);$i=$r['intent'];
        return $this->owner($sid,$id)->credit($i['operation_key'],$i['uid'],$i['kind'],$i['amount_minor'],$i['source_ref'],$i['expected_version']);
    }
    public function lookup(string $sid,string $id):array
    {
        $r=(new AF_ACS_EncounterRepository($this->link))->rewardIntentEvidence($sid,$id);
        return $this->owner($sid,$id)->lookup($r['operation_key']);
    }
}
