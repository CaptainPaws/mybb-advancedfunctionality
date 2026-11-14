<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/EncounterService.php';

/** GM-only start orchestration with durable typed causal sealing. */
final class AF_ACS_AmbushStartService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,private ?AF_ACS_RandomSource $random=null) {}
    private function authorize(string $sid): void
    {
        // Encounter context checks persisted GM/owner roles and native thread permission.
        $row=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $session=(new AF_ACS_EncounterService($this->repo,$this->access))->context($sid,$row['tid']);
        if($session['status']!=='active')throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
    }
    public function view(string $sid,string $eid): array
    {
        $this->authorize($sid);return $this->repo->ambushStartState($sid,$eid);
    }
    public function command(string $op,array $input): array
    {
        $this->access->csrf();if(array_diff(array_keys($input),['session_id','encounter_id','expected_version','encounter_version','reason','operation_key']))throw new RuntimeException('ACS_AMBUSH_INPUT_INVALID');
        if(!in_array($op,['ready','start'],true))throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$this->authorize($sid);
        $key=$input['operation_key']??null;if(!is_string($key)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason===''||mb_strlen($reason,'UTF-8')>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'encounter_id'=>AF_ACS_Repository::integer($input['encounter_id']??null),'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'encounter_version'=>AF_ACS_Repository::integer($input['encounter_version']??null),'reason'=>$reason];
        $authorize=fn()=>$this->authorize($sid);
        if($op==='start'){$request['start_operation_id']='ambush:'.$sid.':'.$key;$this->repo->armAmbushStart($request,$authorize);$this->repo->sealAmbushStart($request,$this->access,$this->random);}
        try{return $this->repo->command('session:'.$request['actor_uid'].':ambush-'.$op.':'.$key,'encounter.ambush_'.$op,$request,$authorize,function($session)use($request,$op){
            $eid=$request['encounter_id'];$encounter=$this->repo->encounter($session['id'],$eid,true)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
            if($encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            $ready=(new AF_ACS_EncounterService($this->repo,$this->access))->readiness($session['id'],$eid,false,$op==='start');if(!$ready['can_start'])throw new RuntimeException('ACS_ROSTER_BLOCKED');
            return $op==='ready'?$this->repo->markAmbushReady($session,$encounter):$this->repo->finishAmbushStart($session,$encounter,$this->repo->rosterSources($session['id'],$eid),$request,$this->access);
        },$op==='start'?fn($s,$event)=>$this->repo->linkStartExecutions($s,$event):null);
        }catch(Throwable $e){
            if($op==='start'&&($e->getMessage()==='ACS_TRANSACTION_ERROR'||$e->getMessage()==='ACS_ITEM_OWNER_RESULT_ORPHAN'||$e->getMessage()==='INV_PREPARED_OUTCOME_INTEGRITY'))return $this->repo->recoverAmbushOutcome($request,'session:'.$request['actor_uid'].':ambush-start:'.$key,$e->getMessage());
            throw $e;
        }
    }
}
