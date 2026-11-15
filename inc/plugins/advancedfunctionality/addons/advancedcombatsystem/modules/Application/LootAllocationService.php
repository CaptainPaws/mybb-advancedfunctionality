<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/LootService.php';

/** Native/member access and frozen group ownership, never combat control/delegation authority. */
final class AF_ACS_LootAllocationService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,private ?AF_ACS_RandomSource $rng=null) {}
    private function audience(string $sid,string $eid):array
    {
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();$this->access->thread($s['tid']);$roles=$this->repo->roles($sid,$uid);
        if(!$roles||!$this->repo->encounter($sid,$eid))throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        return ['session'=>$s,'uid'=>$uid,'gm'=>AF_ACS_AuthorizationService::sessionGm($s,$roles,$uid),'can_claim'=>(bool)array_intersect(array_column($roles,'role'),['owner','gm','assistant_gm','player'])];
    }
    private function context(string $sid,string $eid,array $entry):array
    {
        $pool=$this->repo->lootPool($sid,$eid,$entry['source_key'])??throw new RuntimeException('ACS_LOOT_ALLOCATION_UNAVAILABLE');
        $this->repo->lootReplay($sid,$eid,$entry['source_key']);
        if(($this->repo->confirmedOutcome($sid,$eid)['outcome_id']??null)!==$pool['generation']['outcome']['outcome_id'])throw new RuntimeException('ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');
        return [$pool,$this->repo->allocationPolicy($pool)];
    }
    private function groups(array $pool,string $uid):array
    {
        $out=[];foreach($pool['generation']['eligibility'] as $g)if($g['owner_uid']===$uid){
            try{$this->repo->allocationRecipient($pool['generation']['session_id'],$pool['generation']['eligibility'],$g['control_group_id']);$out[]=$g['control_group_id'];}catch(RuntimeException $e){if($e->getMessage()!=='ACS_LOOT_NOT_ELIGIBLE')throw $e;}
        }return $out;
    }
    private function visible(array $a,array $p,array $groups):bool {return $a['gm']||$p['visibility']==='eligible'&&(bool)$groups;}
    private function winnerVisible(array $a,array $p,?string $recipient):bool
    {return $a['gm']||$p['winner_visibility']==='eligible'||$p['winner_visibility']==='recipient'&&$recipient===$a['uid'];}
    public function view(string $sid,string $eid):array
    {
        $sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);$a=$this->audience($sid,$eid);$items=[];$cache=[];
        foreach($this->repo->allocationEntries($sid,$eid) as $entry){
            if(!isset($cache[$entry['pool_id']])){[$pool,$policy]=$this->context($sid,$eid,$entry);$cache[$entry['pool_id']]=[$pool,$policy,$this->groups($pool,$a['uid'])];}[$pool,$policy,$groups]=$cache[$entry['pool_id']];
            if(!$this->visible($a,$policy,$groups))continue;
            $definition=json_decode($entry['result_json'],true,64,JSON_THROW_ON_ERROR);$claims=$this->repo->allocationClaims($entry['id']);$own=array_values(array_filter($claims,fn($c)=>in_array($c['control_group_id'],$groups,true)));
            $receipt=$this->repo->allocationReceipt($sid,$eid,$entry);
            $dto=['entry_id'=>$entry['id'],'item_key'=>$definition['item_ref']['key'],'qty'=>$definition['qty'],'mode'=>$policy['mode'],'status'=>$entry['status'],'allocation_version'=>$entry['allocation_version'],'own_claims'=>$own,'own_groups'=>$groups,'can_claim'=>$a['can_claim']&&(bool)$groups&&$entry['status']==='generated'&&$policy['mode']!=='gm_assign','can_assign'=>$a['gm']&&$entry['status']==='generated'&&$policy['mode']==='gm_assign','can_close'=>$a['gm']&&$entry['status']==='generated'&&$policy['mode']==='need_greed','allow_pass'=>$policy['allow_pass']];
            if($receipt!==null&&$this->winnerVisible($a,$policy,$entry['recipient_uid']))$dto['winner']=['control_group_id'=>$entry['recipient_group_id'],'uid'=>$entry['recipient_uid']];
            if($a['gm']){$dto['eligible_groups']=$pool['generation']['eligibility'];$dto['claims']=$claims;$dto['receipt']=$receipt;}
            $items[]=$dto;
        }
        return ['schema_version'=>1,'session_id'=>$sid,'encounter_id'=>$eid,'session_version'=>$a['session']['session_version'],'can_manage'=>$a['gm'],'entries'=>$items];
    }
    public function command(string $op,array $input):array
    {
        $this->access->csrf();
        $fields=['session_id','encounter_id','entry_id','expected_version','expected_allocation_version','operation_key','reason',...match($op){'gm_assign'=>['control_group_id'],'free_claim'=>['control_group_id','expected_claim_version'],'claim'=>['control_group_id','expected_claim_version','claim'],'close'=>[],default=>throw new RuntimeException('ACS_LOOT_INPUT_INVALID')}];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        $r=$input;foreach(['session_id','encounter_id','entry_id','expected_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],'18446744073709551614');
        $r['expected_allocation_version']=AF_ACS_Repository::integer($r['expected_allocation_version'],'18446744073709551614',true);
        if(isset($r['control_group_id']))$r['control_group_id']=AF_ACS_Repository::integer($r['control_group_id']);
        if(isset($r['expected_claim_version']))$r['expected_claim_version']=AF_ACS_Repository::integer($r['expected_claim_version'],'18446744073709551614',true);
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        $authorize=function()use($op,$r):void {
            $a=$this->audience($r['session_id'],$r['encounter_id']);try{$entry=$this->repo->allocationEntry($r['session_id'],$r['encounter_id'],$r['entry_id']);}catch(RuntimeException $e){if(!$a['gm']&&$e->getMessage()==='ACS_LOOT_ALLOCATION_UNAVAILABLE')throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');throw $e;}[$pool,$policy]=$this->context($r['session_id'],$r['encounter_id'],$entry);$groups=$this->groups($pool,$a['uid']);
            if(!$this->visible($a,$policy,$groups))throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');
            if(in_array($op,['gm_assign','close'],true)&&!$a['gm'])throw new RuntimeException('ACS_GM_REQUIRED');
            if($policy['mode']!==match($op){'gm_assign'=>'gm_assign','free_claim'=>'free_claim',default=>'need_greed'})throw new RuntimeException('ACS_LOOT_ALLOCATION_UNSUPPORTED');
            if(in_array($op,['claim','free_claim'],true)&&(!$a['can_claim']||!in_array($r['control_group_id'],$groups,true)))throw new RuntimeException('ACS_LOOT_NOT_ELIGIBLE');
            if($op==='claim'&&!in_array($r['claim'],$policy['allow_pass']?['need','greed','pass']:['need','greed'],true))throw new RuntimeException('ACS_LOOT_ALLOCATION_UNSUPPORTED');
        };$authorize();
        $result=$this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'loot.'.$op,$r,$authorize,
            function($s)use($op,$r){$entry=$this->repo->allocationEntry($s['id'],$r['encounter_id'],$r['entry_id']);[$pool,$policy]=$this->context($s['id'],$r['encounter_id'],$entry);
                return $this->repo->applyLootAllocation($s,$this->repo->encounter($s['id'],$r['encounter_id'],true),$op,$r,$pool,$policy,$this->rng??new AF_ACS_RandomSource);},
            fn($s,$event)=>$this->repo->linkAllocationEvent($s,$event),
            fn()=>$this->repo->allocationReplay($r['session_id'],$r['encounter_id'],$op,$r));
        $a=$this->audience($r['session_id'],$r['encounter_id']);if($a['gm'])return $result;
        $entry=$this->repo->allocationEntry($r['session_id'],$r['encounter_id'],$r['entry_id']);[, $policy]=$this->context($r['session_id'],$r['encounter_id'],$entry);
        $allocation=$result['allocation'];unset($allocation['claims'],$allocation['contenders'],$allocation['roll']);
        if(!$this->winnerVisible($a,$policy,$allocation['recipient_uid']??null))unset($allocation['recipient_uid'],$allocation['recipient_group_id']);$result['allocation']=$allocation;return $result;
    }
}
