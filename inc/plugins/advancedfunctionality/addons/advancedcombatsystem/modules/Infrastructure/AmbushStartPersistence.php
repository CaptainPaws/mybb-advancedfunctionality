<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Start metadata and terminal outcome share the existing operation journal. */
trait AF_ACS_AmbushStartPersistence
{
    public function assertAmbushExecutionSupported(string $sid,string $eid): void
    {
        // combat.primary is not encounter.start. Never pretend a post exists or skip a cost.
        if($this->sql('SELECT id FROM '.$this->table('prepared_actions')." WHERE session_id=? AND encounter_id=? AND (status='active' OR cost_state='recovery_required') LIMIT 1",[$sid,$eid])['rows'])throw new RuntimeException('ACS_AMBUSH_EXECUTION_BLOCKED');
    }
    public function markAmbushReady(array $session,array $encounter): array
    {
        $this->commandWrite();$this->assertNoObligations($session['id'],$encounter['id']);
        $commitments=$this->preRoundCommitments($session['id'],$encounter['id']);$confirmed=array_column($commitments['rows'],'prepared_action_id');
        $pending=$this->sql('SELECT id,cost_state FROM '.$this->table('prepared_actions')." WHERE session_id=? AND encounter_id=? AND (status='active' OR cost_state='recovery_required')",[$session['id'],$encounter['id']])['rows'];
        foreach($pending as $row)if($row['cost_state']==='recovery_required'||!in_array((string)$row['id'],array_map('strval',$confirmed),true))throw new RuntimeException('ACS_AMBUSH_EXECUTION_BLOCKED');
        if($encounter['status']!=='preparation'||$encounter['round_number']!=='0'||$encounter['start_operation_id']!==null||$this->firstRound($encounter['id']))throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        if($this->sql('UPDATE '.$this->table('encounters')." SET status='ambush_ready',encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND session_id=? AND encounter_version=? AND status='preparation'",[$encounter['id'],$session['id'],$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_version'=>$this->encounter($session['id'],$encounter['id'])['encounter_version'],'encounter_sequence'=>$encounter['next_event_sequence'],'status'=>'ambush_ready','start_execution'=>$pending?'typed_start_required':'empty_only','pre_round_ap_commitments'=>$commitments['totals']];return $session;
    }
    /** Separate metadata commit survives a later mechanical rollback. No AP, RNG or clocks. */
    public function armAmbushStart(array $request,callable $authorize): array
    {
        $key=$request['start_operation_id'];$json=self::json($request);$hash=hash('sha256',$json);
        // Read-only exact lookup avoids a new metadata COMMIT on replay.
        $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=?',[$key])['rows'][0]??null;
        if($old){$authorize();if($old['kind']!=='encounter.start_intent'||!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');if(!in_array($old['status'],['prepared','committed'],true))throw new RuntimeException('ACS_RECOVERY_REQUIRED');return json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);}
        return $this->transactions->run(function()use($request,$authorize,$key,$json,$hash){
            $this->writable();$this->lockSession($request['session_id']);$authorize();
            $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
            if($old){
                if($old['kind']!=='encounter.start_intent'||!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
                if(!in_array($old['status'],['prepared','committed'],true))throw new RuntimeException('ACS_RECOVERY_REQUIRED');
                return json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);
            }
            $session=$this->session($request['session_id']);$encounter=$this->encounter($request['session_id'],$request['encounter_id'],true);
            if(!$encounter||$session['session_version']!==$request['expected_version']||$encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($encounter['status']!=='ambush_ready'||$encounter['round_number']!=='0'||$encounter['start_operation_id']!==null||$this->firstRound($encounter['id']))throw new RuntimeException('ACS_FIRST_ROUND_EXISTS');
            $this->assertNoObligations($session['id'],$encounter['id']);$this->startCandidates($session['id'],$encounter['id']);
            $result=['schema_version'=>1,'start_operation_id'=>$key,'status'=>'encounter_start','mechanics_confirmed'=>false];
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$session['id'],$encounter['id'],'encounter.start_intent',$hash,'prepared',$json,self::json($result),time()]);
            // This is an immutable start identity, not a mechanical/version transition.
            if($this->sql('UPDATE '.$this->table('encounters').' SET start_operation_id=? WHERE id=? AND session_id=? AND start_operation_id IS NULL AND encounter_version=?',[$key,$encounter['id'],$session['id'],$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            return $result;
        });
    }
    public function finishAmbushStart(array $session,array $encounter,array $sources,array $request,AF_ACS_SessionAccess $access): array
    {
        $this->commandWrite();$key=$request['start_operation_id'];
        $intent=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
        if(!$intent||$intent['status']!=='prepared'||$intent['kind']!=='encounter.start_intent'||!hash_equals($intent['payload_hash'],hash('sha256',self::json($request))))throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        if($encounter['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
        $this->assertNoObligations($session['id'],$encounter['id'],null,false,null,$key);
        $executions=$this->executeStartCausal($request,$intent,$access);
        $encounter=$this->encounter($session['id'],$encounter['id']);
        $session=$this->openFirstRound($session,$encounter,$this->rosterSources($session['id'],$encounter['id']),$request['actor_uid'],$key);
        $result=&$session['_command_result'];
        $result+=['schema_version'=>1,'start_operation_id'=>$key,'status'=>'active','mechanics_confirmed'=>true,'stages'=>['encounter_start','ambush_resolution','active'],'causal_seal'=>json_decode($intent['result_json'],true,64,JSON_THROW_ON_ERROR)['causal_seal'],'prepared_executions'=>$executions, 'pre_round_ap_commitments'=>$this->preRoundCommitments($session['id'],$encounter['id'])['totals'], 'post_created'=>false];
        // The ordinary first roster is opened only after every sealed start obligation is terminal.
        $this->sql('UPDATE '.$this->table('operations')." SET status='committed',operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND status='prepared'",[self::json($result),time(),$key]);
        $session=$this->closeFirstRoundExhausted($session,$request['actor_uid']);
        $this->sql('UPDATE '.$this->table('operations').' SET result_json=? WHERE operation_key=?',[self::json($session['_command_result']),$key]);
        return $session;
    }
    /** Read-only proof of the existing command terminal event, not merely a metadata intent. */
    public function ambushStartConfirmed(string $sid,string $eid):bool
    {
        $enc=$this->encounter($sid,$eid);if(($enc['start_operation_id']??null)===null)return false;
        $intent=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? AND session_id=? AND encounter_id=?',[$enc['start_operation_id'],$sid,$eid])['rows'][0]??null;
        if(!$intent||$intent['kind']!=='encounter.start_intent'||$intent['status']!=='committed'||!hash_equals($intent['payload_hash'],hash('sha256',$intent['request_json'])))return false;
        $request=json_decode($intent['request_json'],true,64,JSON_THROW_ON_ERROR);$start=json_decode($intent['result_json'],true,64,JSON_THROW_ON_ERROR);
        $prefix='ambush:'.$sid.':';if(!str_starts_with($enc['start_operation_id'],$prefix)||($request['start_operation_id']??null)!==$enc['start_operation_id'])return false;
        $key='session:'.self::integer($request['actor_uid']).':ambush-start:'.substr($enc['start_operation_id'],strlen($prefix));
        $row=$this->sql('SELECT o.status,o.kind,o.payload_hash,o.result_json,e.id AS event_id,e.actor_uid,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('operations').' o JOIN '.$this->table('events').' e ON e.operation_key=o.operation_key AND e.session_id=o.session_id AND e.encounter_id=o.encounter_id JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE o.operation_key=? AND o.session_id=? AND o.encounter_id=? AND e.event_type=?',[$key,$sid,$eid,'encounter.ambush_start'])['rows'][0]??null;
        if(!$row||$row['status']!=='committed'||$row['kind']!=='encounter.ambush_start'||$row['payload_hash']!==$intent['payload_hash']||(string)$row['actor_uid']!==$request['actor_uid']||!hash_equals($row['content_hash'],hash('sha256',$row['payload_json']."\n".$row['visibility_json'])))return false;
        $result=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);$event=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        return ($result['mechanics_confirmed']??false)===true&&($start['mechanics_confirmed']??false)===true&&($event['request']??null)===$request
            &&($event['result']['mechanics_confirmed']??false)===true&&($result['start_operation_id']??null)===$enc['start_operation_id']
            &&($result['round_id']??null)===($start['round_id']??null)&&($event['result']['round_id']??null)===$result['round_id']
            &&($result['event_id']??null)===(string)$row['event_id'];
    }
    public function ambushStartState(string $sid,string $eid): array
    {
        $encounter=$this->encounter($sid,$eid)??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $state=['status'=>$encounter['status'],'start_operation_id'=>$encounter['start_operation_id'],'execution_support'=>'ic-acs.prepared-start.v1','prepared_start'=>'CONTRACT_GATED'];
        if($encounter['start_operation_id']!==null){$row=$this->sql('SELECT status,result_json FROM '.$this->table('operations')." WHERE session_id=? AND encounter_id=? AND operation_key=? AND kind='encounter.start_intent'",[$sid,$eid,$encounter['start_operation_id']])['rows'][0]??null;if($row)$state+=['operation_status'=>$row['status'],'outcome'=>json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR)];}
        return $state;
    }
}
