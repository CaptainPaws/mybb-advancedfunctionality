<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Infrastructure/Adapters/BalanceAdapter.php';
require_once __DIR__.'/../Infrastructure/Adapters/KnowledgeBaseAdapter.php';
/** GM commands prepare immutable obligations; each recipient has its own owner intent. */
final class AF_ACS_RewardService
{
    private AF_ACS_EncounterRepository $repo;
    private AF_ACS_OperationStore $operations;
    private AF_ACS_BalanceAdapter $balance;
    public function __construct(object $database,private AF_ACS_SessionAccess $access,?AF_ACS_BalanceAdapter $balance=null)
    {
        $this->repo=new AF_ACS_EncounterRepository($database);$this->operations=new AF_ACS_OperationStore($database);
        $this->balance=$balance??new AF_ACS_BalanceAdapter($database,$access);
    }
    /** Code-only earning boundary; never accepts a client amount/recipient. */
    public static function recordAchievementObligation(AF_ACS_EncounterRepository $repo,array $session,string $contribution,int $ordinal):string
    {return $repo->recordAchievementReward($session,$contribution,$ordinal);}
    private function authorize(string $sid,bool $native=true):array
    {
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$uid=$this->access->uid();
        if($native)$this->access->thread($s['tid']);
        if(!AF_ACS_AuthorizationService::sessionGm($s,$this->repo->roles($sid,$uid),$uid))throw new RuntimeException('ACS_GM_REQUIRED');return $s;
    }
    private function input(array $r,array $fields):array
    {
        $this->access->csrf();
        if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
        foreach($r as $k=>$v)if($k!=='allocations'&&!is_string($v)||$k==='allocations'&&!is_array($v))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
        foreach(['session_id','encounter_id','expected_version','encounter_version','reward_id','expected_reward_version'] as $k)if(isset($r[$k]))$r[$k]=AF_ACS_Repository::integer($r[$k],'18446744073709551614');
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
        return ['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
    }
    public function command(string $op,array $r):array
    {
        if(!in_array($op,['reward_configure','reward_distribute'],true))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
        $r=$this->input($r,['session_id','encounter_id','expected_version','encounter_version','operation_key','reason','kb_key',...($op==='reward_distribute'?['allocations']:[])]);
        if(!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$r['kb_key']))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
        $this->authorize($r['session_id']);$graph=$op==='reward_configure'?(new AF_ACS_KnowledgeBaseAdapter)->rewardDefinition($r['kb_key'],(int)$r['actor_uid']):null;
        return $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],$op==='reward_configure'?'reward.configure':'reward.distribute',$r,
            fn()=>$this->authorize($r['session_id']),function($s)use($op,$r,$graph){
                $e=$this->repo->encounter($s['id'],$r['encounter_id'],true)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
                if($e['encounter_version']!==$r['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                return $op==='reward_configure'?$this->repo->configureReward($s,$e,$r['kb_key'],$graph):$this->repo->distributeReward($s,$e,$r['kb_key'],$r['allocations']);
            },null,$op==='reward_distribute'?fn()=>$this->repo->rewardPlanReplay($r['session_id'],$r['encounter_id'],$r['kb_key'],$r['allocations']):null);
    }
    public function inspect(string $sid,string $id):array
    {
        $sid=AF_ACS_Repository::integer($sid);$id=AF_ACS_Repository::integer($id);$this->authorize($sid);
        $r=$this->repo->rewardEvidence($sid,$id);$op=$this->operations->get($r['operation_key']);$receipt=null;
        if($op){
            $e=$this->repo->rewardIntentEvidence($sid,$id);
            if(($op['status']==='prepared'&&$r['status']!=='prepared')||($op['status']==='recovery_required'&&$r['status']!=='recovery_required')||($op['status']==='committed'&&$r['status']!=='issued')||!in_array($op['status'],['prepared','recovery_required','committed'],true))throw new RuntimeException('ACS_REWARD_INTEGRITY');
            if($op['status']==='committed'){$receipt=json_decode($op['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_RewardDistribution::receipt($e['intent'],$e['authority'],$receipt);}
        }elseif(!in_array($r['status'],['pending','blocked'],true))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        return ['reward_id'=>$id,'status'=>$r['status'],'reward_version'=>$r['reward_version'],'recipient'=>$r['payload']['recipient'],'reward_type'=>$r['reward_type'],'operation_key'=>$r['operation_key'],'operation_version'=>$op['operation_version']??null,'owner_receipt'=>$receipt,'blocked_reason'=>$r['status']==='blocked'?'charactersheets_durable_sync_api_missing':null];
    }
    public function view(string $sid,string $eid,string $after='0'):array
    {
        $this->authorize(AF_ACS_Repository::integer($sid));$rows=$this->repo->rewardRows($sid,$eid,$after);
        return ['session_id'=>$sid,'encounter_id'=>$eid,'rewards'=>array_map(fn($r)=>$this->inspect($sid,(string)$r['id']),$rows)];
    }
    public function issue(array $r):array
    {
        $r=$this->input($r,['session_id','reward_id','expected_version','expected_reward_version','operation_key','reason']);$sid=$r['session_id'];$id=$r['reward_id'];$this->authorize($sid);
        $state=$this->inspect($sid,$id);
        if(in_array($state['status'],['issued','blocked'],true))return $state;
        if($state['status']==='pending'){
            if($state['reward_version']!==$r['expected_reward_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            try{$ownerVersion=$this->balance->version($state['recipient']['uid']);}
            catch(Throwable){return $state+['owner_state'=>'unavailable'];}
            $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'reward.prepare',$r,
                fn()=>$this->authorize($sid),fn($s)=>$this->repo->prepareReward($s,$id,$r['expected_reward_version'],$ownerVersion,$r['actor_uid']));
            $state=$this->inspect($sid,$id);
        }
        if($state['status']==='recovery_required')return $this->recover($sid,$id,$state['operation_version']);
        // The CAS winner alone dispatches. Persist uncertainty BEFORE external write.
        $op=$this->operations->rewardTransition($sid,$id,$state['operation_version'],'recovery_required',[],fn()=>$this->authorize($sid,false));
        try{$receipt=$this->balance->credit($sid,$id);}catch(Throwable){return $this->inspect($sid,$id)+['owner_state'=>'unavailable_or_unknown'];}
        if(($receipt['status']??null)!=='committed')return $this->inspect($sid,$id)+['owner_state'=>$receipt['status']??'unknown'];
        $this->operations->rewardTransition($sid,$id,$op['operation_version'],'committed',$receipt,fn()=>$this->authorize($sid,false));return $this->inspect($sid,$id);
    }
    /** Recovery never invokes credit, refreshes version, changes UID or releases a winner. */
    public function recover(string $sid,string $id,string $expectedOperationVersion):array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($sid);$id=AF_ACS_Repository::integer($id);$this->authorize($sid);$state=$this->inspect($sid,$id);
        if(in_array($state['status'],['issued','blocked','pending'],true))return $state;
        if($state['operation_version']!==AF_ACS_Repository::integer($expectedOperationVersion))throw new RuntimeException('ACS_VERSION_CONFLICT');
        try{$receipt=$this->balance->lookup($sid,$id);}catch(Throwable){return $state+['lookup_state'=>'unavailable'];}
        if(($receipt['status']??null)!=='committed')return $state+['lookup_state'=>$receipt['status']??'unknown'];
        if($state['status']!=='recovery_required')throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $this->operations->rewardTransition($sid,$id,$state['operation_version'],'committed',$receipt,fn()=>$this->authorize($sid,false));return $this->inspect($sid,$id);
    }
}
