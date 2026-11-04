<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Domain/Clock.php';

/** Explicit state boundaries/rest only. Includes mandatory upkeep; no arbitrary spending or healing. */
final class AF_ACS_ClockService
{
    public function __construct(private AF_ACS_EncounterRepository $repository,private AF_ACS_SessionAccess $access) {}
    private function context(string $sid): array
    {
        $view=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);$s=$view['session'];
        $roles=array_column($this->repository->roles($sid,$this->access->uid()),'role');
        if(!$this->access->administrator() && !(in_array('gm',$roles,true) || in_array('owner',$roles,true) && $s['owner_uid']===$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');return $s;
    }
    /** Future commands call this rather than trusting UI/caller AP. No deduction is performed. */
    public function available(string $sid,string $turnId,string $entityId,int $cost=0): array
    {
        $view=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);
        if(!$view['can_register_own'] || $cost<0)throw new RuntimeException('ACS_AP_ACCESS_DENIED');
        $turn=$this->repository->clockTurn($sid,AF_ACS_Repository::integer($turnId));
        if(!$turn || $turn['encounter_status']!=='active' || $turn['round_status']!=='open' || $turn['round_number']!==$turn['current_round'] || $turn['status']!=='pending')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);$member=null;
        foreach($roster['members']??[] as $candidate)if((string)$candidate['entity_id']===$entityId)$member=$candidate;
        $entity=$this->repository->entity($sid,AF_ACS_Repository::integer($entityId));
        $controllers=$this->repository->controllers($sid,$entityId);
        if(!$member || !$entity || $entity['control_group_id']!==$turn['control_group_id'] || count($controllers)!==1 || $controllers[0]['controller_uid']!==$this->access->uid() || $entity['owner_uid']!==null && $entity['owner_uid']!==$this->access->uid())throw new RuntimeException('ACS_AP_ACCESS_DENIED');
        $individual=null;foreach($this->repository->individualTurns([$turnId]) as $candidate)if($candidate['entity_id']===$entityId)$individual=$candidate;
        $ready=$individual&&$individual['start_done']==='1'&&$individual['end_done']==='0'&&$this->repository->boundary(AF_ACS_Clock::entityStart($individual['id']),$sid)&&$entity['life_state']==='active'&&AF_ACS_Effects::ordinaryActionAllowed(['status_instances'=>$this->repository->statusProjection($sid)['instances']],$entityId);
        $budget=(int)AF_ACS_Repository::integer((string)$turn['ap_budget'],'2147483647',true);$spent=(int)AF_ACS_Repository::integer((string)$turn['ap_spent'],'2147483647',true);$commit=(int)AF_ACS_Repository::integer((string)$turn['pre_round_ap_commitment'],'2147483647',true);
        $remaining=AF_ACS_Clock::availableAP($budget,$spent,$commit);
        return ['turn_id'=>$turnId,'turn_version'=>$turn['turn_version'],'control_group_id'=>$turn['control_group_id'],'maximum'=>$budget,'available'=>$remaining,'affordable'=>$ready&&$cost<=$remaining,'actions_ready'=>$ready];
    }
    public function overview(string $sid,?string $eid=null): array
    {
        $session=$this->context($sid);
        $cooldowns=[];
        foreach($this->repository->cooldowns($sid) as $row) {
            try{$source=$this->repository->cooldownSource($sid,$row);$row['source']=$source['ability_ref'];$row['ready']=true;}
            catch(Throwable $e){$row['ready']=false;$row['reason']='cooldown_source_unavailable';}
            $cooldowns[]=$row;
        }
        try{$window=$this->repository->restWindow($sid);$fullRest=true;$rest=$window!==null;$reason=$rest?null:'Нет завершённого столкновения, открывающего интервал отдыха.';}
        catch(Throwable $e){$window=null;$rest=false;$fullRest=false;$reason='Отдых недоступен во время столкновения/паузы/засады.';}
        if($session['status']!=='active'){$rest=false;$fullRest=false;$reason='Сессия не активна.';}
        $groups=$this->repository->restGroups($sid);
        foreach($groups as &$g)$g['short_available']=$rest && !$this->repository->boundary('rest:'.$sid.':'.$g['id'].':'.$window['encounter_id'].':short',$sid);unset($g);
        return ['session_version'=>$session['session_version'],'cooldowns'=>$cooldowns,'rest_allowed'=>$rest,'full_rest_allowed'=>$fullRest,'rest_window'=>$window,'rest_reason'=>$reason,'groups'=>$groups,'history'=>$this->repository->restHistory($sid)];
    }
    /** Own controlled sources or the existing GM management scope only; no target/definition traces. */
    private function upkeepAccess(string $sid,string $source):array
    {
        $v=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);$e=$this->repository->entity($sid,$source);
        if(!$e)throw new RuntimeException('ACS_UPKEEP_ACCESS_DENIED');
        if(!$v['can_manage']){
            $c=$this->repository->controllers($sid,$source);
            if(!$v['can_register_own']||count($c)!==1||$c[0]['controller_uid']!==$this->access->uid()||$e['owner_uid']!==null&&$e['owner_uid']!==$this->access->uid())throw new RuntimeException('ACS_UPKEEP_ACCESS_DENIED');
        }return $v;
    }
    public function sustained(string $sid,string $eid):array
    {
        $v=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);$encounter=$this->repository->encounter($sid,$eid);if(!$encounter)throw new RuntimeException('ACS_UPKEEP_ACCESS_DENIED');
        $groups=[];$turns=[];$round=$this->repository->currentRound($encounter);
        if($round)foreach($this->repository->allRoundTurns($round['id']) as $turn)foreach($this->repository->individualTurns([$turn['id']]) as $i)$turns[$i['entity_id']]=['id'=>$i['id'],'editable'=>$encounter['status']==='active','applies_next'=>$i['start_done']==='1'];
        foreach($this->repository->statusProjection($sid)['instances'] as $row){
            if(!isset($row['spec']['upkeep'])||$row['spec']['scope']==='combat'&&$row['encounter_id']!==$eid)continue;
            try{$this->upkeepAccess($sid,$row['source_entity_id']);}catch(RuntimeException $e){if($e->getMessage()!=='ACS_UPKEEP_ACCESS_DENIED')throw $e;continue;}
            $source=$row['source_entity_id'];$groups[$source]['source_entity_id']=$source;$groups[$source]['turn']=$turns[$source]??null;
            $groups[$source]['effects'][]=['id'=>$row['id'],'effect_key'=>$row['spec']['effect_key'],'status'=>$row['status'],'priority'=>$row['upkeep_priority']??null,'applied_order'=>$row['applied_order'],'costs'=>$row['spec']['upkeep']['costs'],'last_payment'=>$row['last_upkeep']??null,'termination_reason'=>$row['termination']['reason']??null];
        }
        foreach($groups as &$group)usort($group['effects'],static fn($a,$b)=>($a['priority']??PHP_INT_MAX)<=>($b['priority']??PHP_INT_MAX)?:$a['applied_order']<=>$b['applied_order']);unset($group);
        return ['session_version'=>$v['session']['session_version'],'encounter_version'=>$encounter['encounter_version'],'sources'=>array_values($groups)];
    }
    private function order(array $input):array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$id=AF_ACS_Repository::integer($input['entity_turn_id']??null);
        $individual=$this->repository->clockEntityTurn($sid,$id);if(!$individual)throw new RuntimeException('ACS_UPKEEP_ACCESS_DENIED');$this->upkeepAccess($sid,$individual['entity_id']);
        $raw=$input['effect_order']??null;
        if(is_string($raw))$raw=$raw===''?[]:explode(',',trim($raw));
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>128)throw new RuntimeException('ACS_UPKEEP_ORDER_INVALID');
        $ids=[];foreach($raw as $value)$ids[]=AF_ACS_Repository::integer(is_string($value)?trim($value):$value);
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason===''||mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'session_id'=>$sid,'actor_uid'=>$this->access->uid(),'encounter_id'=>$eid,'entity_turn_id'=>$id,'order'=>$ids,'reason'=>$reason,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'encounter_version'=>AF_ACS_Repository::integer($input['encounter_version']??null)];
        return $this->repository->command('session:'.$this->access->uid().':'.$key,'encounter.upkeep_order',$request,
            function($s)use($sid,$individual){$this->upkeepAccess($sid,$individual['entity_id']);if($s['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');},
            function($s)use($request){$e=$this->repository->encounter($s['id'],$request['encounter_id'],true);if(!$e||$e['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                return $this->repository->setUpkeepOrder($s,$e,$this->repository->clockEntityTurn($s['id'],$request['entity_turn_id']),$request['order']);});
    }
    public function command(string $op,array $input): array
    {
        if($op==='upkeep_order')return $this->order($input);
        $this->access->csrf();$sid=AF_ACS_Repository::integer($input['session_id']??null);$session=$this->context($sid);
        if($session['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
        if(!in_array($op,['entity_start','ap_refresh','combat_reset','rest_short','rest_full'],true))throw new RuntimeException('ACS_CLOCK_OPERATION_UNAVAILABLE');
        if($op==='entity_start') {
            $individual=$this->repository->clockEntityTurn($sid,AF_ACS_Repository::integer($input['entity_turn_id']??null));
            if(!$individual || isset($input['encounter_id']) && (string)$input['encounter_id']!==$individual['encounter_id'])throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');$key=AF_ACS_Clock::entityStart($individual['id']);
        } elseif($op==='ap_refresh') {
            $roundId=AF_ACS_Repository::integer($input['round_id']??null);$scope=$this->repository->clockRound($sid,$roundId);if(!$scope || (string)$scope['encounter_id']!==(string)($input['encounter_id']??''))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');$key=AF_ACS_Clock::apRefresh($roundId);
        } elseif($op==='combat_reset')$key='encounter:'.AF_ACS_Repository::integer($input['encounter_id']??null).':cooldowns_reset';
        if(isset($key) && ($old=$this->repository->boundary($key,$sid)))return $old;
        $operationKey=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$operationKey))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason==='' || mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'reason'=>$reason];
        if(str_starts_with($op,'rest_')) {
            $request['control_group_id']=AF_ACS_Repository::integer($input['control_group_id']??null);$request['rest_kind']=$op==='rest_full'?'full':'short';
            if($op==='rest_full' && ($input['confirm_full']??'')!=='1')throw new RuntimeException('ACS_REST_CONFIRMATION_REQUIRED');
        } else {
            $request['encounter_id']=$op==='entity_start'?$individual['encounter_id']:AF_ACS_Repository::integer($input['encounter_id']??null);
            $request['encounter_version']=AF_ACS_Repository::integer($input['encounter_version']??null,'18446744073709551614');
            if($op==='entity_start')$request['entity_turn_id']=$individual['id'];
            if($op==='ap_refresh')$request['round_id']=$roundId;
        }
        $kind=str_starts_with($op,'rest_')?'session.'.$op:'encounter.clock_'.$op;
        $result=$this->repository->command('session:'.$request['actor_uid'].':'.$operationKey,$kind,$request,
            function($s)use($sid){$this->context($sid);if($s['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');},
            function($s)use($op,$request,$sid) {
                if(str_starts_with($op,'rest_'))return $this->repository->applyRest($s,$request);
                $encounter=$this->repository->encounter($sid,$request['encounter_id'],true);
                if(!$encounter || $encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($op==='combat_reset')return $this->repository->resetCombatCooldowns($s,$encounter,$request['actor_uid']);
                if($op==='ap_refresh')return $this->repository->acknowledgeAPRefresh($s,$encounter,$request['round_id'],$request['actor_uid']);
                $row=$this->repository->clockEntityTurn($sid,$request['entity_turn_id']);
                if(!$row || $row['encounter_status']!=='active' || $row['round_status']!=='open' || $row['round_number']!==$row['current_round'] || $row['turn_status']!=='pending')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
                $roster=json_decode($row['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$roster['fingerprint']??'';unset($roster['fingerprint']);
                if(!is_string($fp) || !hash_equals($fp,hash('sha256',AF_ACS_Repository::json($roster))) || !in_array($row['entity_id'],array_column($roster['members']??[],'entity_id'),true))throw new RuntimeException('ACS_ROSTER_INVALID');
                $entity=$this->repository->entity($sid,$row['entity_id']);$group=$this->repository->clockGroup($sid,$row['control_group_id']);
                if(!$entity || !$group || json_decode($entity['layers_json'],true)!==['schema_version'=>1,'layers'=>[]] || json_decode($group['config_json'],true)!==['schema_version'=>1])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
                return $this->repository->applyEntityStart($s,$encounter,$row,$request['actor_uid']);
            });
        return isset($key)?($this->repository->boundary($key,$sid)??throw new RuntimeException('ACS_RECOVERY_REQUIRED')):$result;
    }
}
