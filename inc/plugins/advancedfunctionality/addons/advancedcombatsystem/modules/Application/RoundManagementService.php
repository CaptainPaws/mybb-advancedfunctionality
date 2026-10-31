<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/EncounterService.php';

/** Explicit GM commands only. No tick runner, system-actor bypass or mutating GET. */
final class AF_ACS_RoundManagementService
{
    private Closure $now;
    public function __construct(private AF_ACS_EncounterRepository $repository,private AF_ACS_SessionAccess $access,?callable $now=null)
    {
        $this->now=$now?Closure::fromCallable($now):static fn()=>time();
    }
    private function context(string $sid): array
    {
        $view=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);$session=$view['session'];
        $roles=array_column($this->repository->roles($sid,$this->access->uid()),'role');
        if(!$this->access->administrator() && !(in_array('gm',$roles,true) || in_array('owner',$roles,true) && $session['owner_uid']===$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');return $session;
    }
    public function overview(string $sid,string $eid,string $after='0'): array
    {
        $session=$this->context($sid);$encounter=$this->repository->encounter($sid,$eid);if(!$encounter)throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $policy=AF_ACS_Clock::deadlinePolicy(json_decode($encounter['config_json'],true,32,JSON_THROW_ON_ERROR));$now=($this->now)();$turns=[];
        if($round=$this->repository->currentRound($encounter))foreach($this->repository->roundTurns($round['id'],$after) as $turn) {
            $left=$encounter['status']==='paused'?$turn['remaining_deadline_seconds']:($turn['deadline_at']===null?null:max(0,(int)$turn['deadline_at']-$now));
            $turns[]=['turn_id'=>$turn['id'],'turn_version'=>$turn['turn_version'],'status'=>$turn['status'],'remaining_seconds'=>$left===null?null:(string)$left,'deadline_at'=>$turn['deadline_at']===null?null:(string)$turn['deadline_at']];
        }
        return ['session_version'=>$session['session_version'],'encounter_version'=>$encounter['encounter_version'],'status'=>$encounter['status'],'policy'=>$policy,'turns'=>$turns,
            'scheduler'=>['status'=>'BLOCKED','reason'=>'ACS system-authority scheduler не подключён. Обработка только явным авторизованным POST.'],
            'notification_delivery'=>['status'=>'BLOCKED','contract'=>'IC-AAM','stage'=>'11']];
    }
    public function command(string $op,array $input): array
    {
        $this->access->csrf();$sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);
        $session=$this->context($sid);$encounter=$this->repository->encounter($sid,$eid);
        if(!$encounter || $session['status']!=='active')throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        if(!in_array($op,['pause','resume','deadline_set','extend_deadline','gm_skip','gm_forfeit','deadline_process','round_next'],true))throw new RuntimeException('ACS_ROUND_OPERATION_UNAVAILABLE');
        if(($input['confirm']??'')!=='1')throw new RuntimeException('ACS_ROUND_CONFIRMATION_REQUIRED');
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason==='' || mb_strlen($reason)>1000 || !mb_check_encoding($reason,'UTF-8'))throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'encounter_version'=>AF_ACS_Repository::integer($input['encounter_version']??null,'18446744073709551614'),'reason'=>$reason];
        $canonical=null;
        if(in_array($op,['gm_skip','gm_forfeit','deadline_process','extend_deadline'],true)) {
            $request['turn_id']=AF_ACS_Repository::integer($input['turn_id']??null);$request['turn_version']=AF_ACS_Repository::integer($input['turn_version']??null,'18446744073709551614');
            $turn=$this->repository->clockTurn($sid,$request['turn_id']);if(!$turn || $turn['encounter_id']!==$eid)throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
            if(in_array($op,['gm_skip','gm_forfeit'],true))$canonical='turn:'.$turn['id'].':closed';
            if($op==='deadline_process') {
                $p=AF_ACS_Clock::deadlinePolicy(json_decode($encounter['config_json'],true,32,JSON_THROW_ON_ERROR));
                $canonical=$p['mode']==='timeout_pass'?'turn:'.$turn['id'].':closed':($p['mode']==='notify' && $turn['deadline_at']!==null?'turn:'.$turn['id'].':deadline:'.$turn['deadline_at'].':notify':null);
            }
        }
        if($op==='round_next') {
            $request['round_id']=AF_ACS_Repository::integer($input['round_id']??null);$r=$this->repository->clockRound($sid,$request['round_id']);if(!$r || (string)$r['encounter_id']!==$eid)throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');$canonical='round:'.$request['round_id'].':next';
        }
        if(in_array($op,['deadline_set','extend_deadline'],true)) {
            if($op==='deadline_set') {
                $mode=(string)($input['deadline_mode']??'');if(!in_array($mode,['off','notify','timeout_pass','gm_skip'],true))throw new RuntimeException('ACS_DEADLINE_POLICY_INVALID');
                $request['policy']=['schema_version'=>1,'mode'=>$mode,'seconds'=>$mode==='off'?null:(int)AF_ACS_Repository::integer($input['seconds']??null,'9007199254740991')];
            } else $request['seconds']=(int)AF_ACS_Repository::integer($input['seconds']??null,'9007199254740991');
        }
        $prior=$this->repository->replayRoundCommand('session:'.$request['actor_uid'].':'.$key,'encounter.'.$op,$request);
        if($prior)return isset($prior['boundary_key'])?($this->repository->boundary($prior['boundary_key'],$sid)??throw new RuntimeException('ACS_RECOVERY_REQUIRED')):$prior;
        if($canonical && ($old=$this->repository->boundary($canonical,$sid)))return $old;
        $result=$this->repository->command('session:'.$request['actor_uid'].':'.$key,'encounter.'.$op,$request,
            function($s)use($sid){$this->context($sid);if($s['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');},
            function($s)use($op,$request,$sid,$eid) {
                $e=$this->repository->encounter($sid,$eid,true);if(!$e || $e['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');$now=($this->now)();
                if($op==='pause' || $op==='resume')return $this->repository->pauseResume($s,$e,$op==='resume',$now);
                if($op==='deadline_set'){if($request['policy']['mode']!=='off')AF_ACS_Clock::deadlineAfter($now,$request['policy']['seconds']);return $this->repository->setDeadlines($s,$e,$request['policy'],$now);}
                if($op==='round_next') {
                    if($e['status']!=='active')throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');$round=$this->repository->currentRound($e);
                    if(!$round || $round['id']!==$request['round_id'])throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
                    $ready=(new AF_ACS_EncounterService($this->repository,$this->access))->readiness($sid,$eid,true);
                    if(!$ready['preparation_ready'])throw new RuntimeException('ACS_ROSTER_BLOCKED');
                    return $this->repository->nextRound($s,$e,$round,$this->repository->rosterSources($sid,$eid),$request['actor_uid'],$now);
                }
                $t=$this->repository->clockTurn($sid,$request['turn_id']);
                if(!$t || $t['encounter_id']!==$eid || $t['round_number']!==$t['current_round'] || $t['round_status']!=='open' || $t['turn_version']!==$request['turn_version'] || $t['status']!=='pending')throw new RuntimeException('ACS_VERSION_CONFLICT');
                if($op==='extend_deadline')return $this->repository->extendDeadline($s,$e,$t,$request['seconds'],$now);
                if($e['status']!=='active')throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
                if($op==='deadline_process') {
                    $policy=AF_ACS_Clock::deadlinePolicy(json_decode($e['config_json'],true,32,JSON_THROW_ON_ERROR));
                    if(!in_array($policy['mode'],['notify','timeout_pass'],true))throw new RuntimeException('ACS_DEADLINE_AUTOMATIC_DISABLED');
                    if($t['deadline_at']===null || (int)$t['deadline_at']>$now)throw new RuntimeException('ACS_DEADLINE_NOT_DUE');
                    if($policy['mode']==='notify')return $this->repository->recordDeadlineNotice($s,$e,$t,$request['actor_uid']);
                }
                return $this->repository->closeTurn($s,$e,$t,$op==='gm_forfeit'?'forfeited':'passed',$request['actor_uid']);
            });
        $canonical??=$result['boundary_key']??null;
        return $canonical?($this->repository->boundary($canonical,$sid)??throw new RuntimeException('ACS_RECOVERY_REQUIRED')):$result;
    }
}
