<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/EndCondition.php';

/** Uses the caller's existing writer transaction and session lock. No owner cleanup. */
trait AF_ACS_EndConditionPersistence
{
    private function endPolicy(array $encounter): ?array
    {
        $config=json_decode($encounter['config_json'],true,64,JSON_THROW_ON_ERROR);
        if(!array_key_exists('end_conditions',$config))return null;
        $pin=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$encounter['config_snapshot_id'],$encounter['session_id']])['rows'][0]??null;
        if(!$pin||!hash_equals($pin['content_hash'],hash('sha256',$pin['payload_json']))||(json_decode($pin['payload_json'],true,64,JSON_THROW_ON_ERROR)['config']??null)!==$config)throw new RuntimeException('ACS_END_POLICY_PIN_INVALID');
        return AF_ACS_EndCondition::policy($config['end_conditions']);
    }
    private function endCandidate(array $encounter,array $policy):?array
    {
        $participants=$this->sql('SELECT p.entity_id,p.side_id,p.participation,s.life_state,s.state_version,p.participant_version FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id AND e.session_id=? JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id WHERE p.encounter_id=? ORDER BY p.id FOR UPDATE',[$encounter['session_id'],$encounter['id']])['rows'];
        $relations=$this->sql('SELECT side_a,side_b,relationship,relation_version FROM '.$this->table('side_relationships').' WHERE encounter_id=? ORDER BY id FOR UPDATE',[$encounter['id']])['rows'];
        $config=json_decode($encounter['config_json'],true);$departed=isset($config['participation_policy']);if($departed)AF_ACS_Participation::policy($config['participation_policy']);
        $candidate=AF_ACS_EndCondition::evaluate($policy,$participants,$relations,$departed);
        return $candidate===null?null:['schema_version'=>1]+$candidate+['participants'=>$participants,'relationships'=>$relations,'policy'=>$policy];
    }
    /** Only after the complete Resolver projection, causal children and linked spawn persistence. */
    public function checkEndConditions(string $sid,string $eid,string $trigger,string $actor):?array
    {
        $this->transactions->assertInternalBoundary();
        $encounter=$this->sql('SELECT * FROM '.$this->table('encounters').' WHERE session_id=? AND id=? FOR UPDATE',[$sid,$eid])['rows'][0]??throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        if($encounter['status']!=='active'||!($policy=$this->endPolicy($encounter)))return null;
        $source=$this->sql('SELECT e.event_type FROM '.$this->table('events')." e JOIN ".$this->table('operations')." o ON o.operation_key=e.operation_key AND o.status='committed' WHERE e.id=? AND e.session_id=? AND e.encounter_id=?",[$trigger,$sid,$eid])['rows'][0]??null;
        if(!$source||!in_array($source['event_type'],['combat.resolved','encounter.ambush_start'],true))throw new RuntimeException('ACS_END_RESULT_REQUIRED');
        if(!($candidate=$this->endCandidate($encounter,$policy)))return null;
        $session=$this->lockSession($sid);
        foreach(['session_version','next_sequence'] as $field)self::integer($session[$field],'18446744073709551614');
        foreach(['encounter_version','next_event_sequence'] as $field)self::integer($encounter[$field],'18446744073709551614');
        $key='encounter:end:'.$trigger;$now=time();
        $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,encounter_id,event_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$sid,$session['next_sequence'],$eid,$encounter['next_event_sequence'],$key,'encounter.pending_resolution',$actor,'acs-end-1',$now])['id'];
        $data=self::json(['schema_version'=>1,'trigger_event_id'=>$trigger,'candidate'=>$candidate]);$visibility=self::json(['schema_version'=>1,'scope'=>'encounter_management']);
        $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$event,'binary64-half_up-v1',$data,$visibility,hash('sha256',$data."\n".$visibility)]);
        $id=$this->sql('INSERT INTO '.$this->table('encounter_resolutions').' (session_id,encounter_id,trigger_event_id,pending_event_id,config_snapshot_id,candidate_json,candidate_hash,status) VALUES (?,?,?,?,?,?,?,?)',[$sid,$eid,$trigger,$event,$encounter['config_snapshot_id'],self::json($candidate),hash('sha256',self::json($candidate)),'pending'])['id'];
        if($this->sql('UPDATE '.$this->table('encounters')." SET status='pending_resolution',encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=? AND status='active'",[$eid,$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',[$now,$sid,$session['session_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $receipt=['schema_version'=>1,'resolution_id'=>$id,'event_id'=>$event,'status'=>'pending_resolution','trigger_event_id'=>$trigger];
        $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$sid,$eid,'encounter.pending_resolution',hash('sha256',$data),'committed',$data,self::json($receipt),$now]);
        return $receipt;
    }
    public function configureEndConditions(array $session,array $encounter,array $policy):array
    {
        $this->commandWrite();AF_ACS_EndCondition::policy($policy);
        if($encounter['status']!=='preparation'||$encounter['round_number']!=='0'||$encounter['start_operation_id']!==null)throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        self::integer($encounter['encounter_version'],'18446744073709551614');self::integer($encounter['next_event_sequence'],'18446744073709551614');
        $config=json_decode($encounter['config_json'],true,64,JSON_THROW_ON_ERROR);$config['end_conditions']=$policy;
        $pin=$this->insertSnapshot($session['id'],['source_system'=>'acs','source_key'=>'encounter-end-conditions','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-end-1','config'=>$config]);
        if($this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=?,encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[self::json($config),$pin,$encounter['id'],$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_sequence'=>$encounter['next_event_sequence'],'encounter_version'=>$this->encounter($session['id'],$encounter['id'])['encounter_version'],'snapshot_id'=>$pin];return $session;
    }
    public function decideEndConditions(array $session,array $encounter,string $op,array $request):array
    {
        $this->commandWrite();
        if($encounter['status']!=='pending_resolution')throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        $pending=$this->sql('SELECT * FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='pending' ORDER BY id FOR UPDATE",[$session['id'],$encounter['id']])['rows'];
        if(count($pending)!==1||(string)$pending[0]['id']!==$request['resolution_id'])throw new RuntimeException('ACS_VERSION_CONFLICT');$pending=$pending[0];
        if(!hash_equals($pending['candidate_hash'],hash('sha256',$pending['candidate_json']))||(string)$pending['config_snapshot_id']!==$encounter['config_snapshot_id'])throw new RuntimeException('ACS_END_POLICY_PIN_INVALID');
        $candidate=json_decode($pending['candidate_json'],true,64,JSON_THROW_ON_ERROR);$policy=$this->endPolicy($encounter);
        // GM choice must still be supported by authoritative mechanics; no client winner input.
        if($candidate!==$this->endCandidate($encounter,$policy))throw new RuntimeException('ACS_END_STATE_CONFLICT');
        if($op==='outcome_continue'&&!$policy['allow_continuation'])throw new RuntimeException('ACS_END_CONTINUATION_DENIED');
        if($op==='outcome_confirm'&&!in_array($request['outcome'],$candidate['outcomes'],true))throw new RuntimeException('ACS_END_OUTCOME_DENIED');
        self::integer($encounter['encounter_version'],'18446744073709551614');self::integer($encounter['next_event_sequence'],'18446744073709551614');
        $status=$op==='outcome_confirm'?'completed':'active';
        if($this->sql('UPDATE '.$this->table('encounters').' SET status=?,completed_at=?,encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=? AND status=?',[$status,$status==='completed'?time():null,$encounter['id'],$encounter['encounter_version'],'pending_resolution'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_sequence'=>$encounter['next_event_sequence'],'encounter_version'=>$this->encounter($session['id'],$encounter['id'])['encounter_version'],'resolution_id'=>(string)$pending['id'],'pending_event_id'=>(string)$pending['pending_event_id'],'config_snapshot_id'=>(string)$pending['config_snapshot_id'],'candidate_hash'=>$pending['candidate_hash'],'trigger_event_id'=>(string)$pending['trigger_event_id'],'status'=>$status,'outcome'=>$op==='outcome_confirm'?['kind'=>$request['outcome'],'winner_side_id'=>$candidate['winner_side_id']]:null,'obligations_preserved'=>true];return $session;
    }
    public function recordEndDecision(array $session,string $event):array
    {
        $r=&$session['_command_result'];$r['terminal_event_id']=$event;
        if($r['status']==='completed')$r['outcome_id']='acs-outcome:'.$r['encounter_id'].':'.$event;
        $json=self::json(['schema_version'=>1,'session_id'=>$session['id'],'actor_uid'=>$session['_end_actor'],'event_id'=>$event]+$r);
        if($this->sql('UPDATE '.$this->table('encounter_resolutions').' SET status=?,terminal_event_id=?,completed_encounter_id=?,receipt_json=?,receipt_hash=? WHERE id=? AND status=?',[$r['status']==='completed'?'confirmed':'continued',$event,$r['status']==='completed'?$r['encounter_id']:null,$json,hash('sha256',$json),$r['resolution_id'],'pending'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        return $session;
    }
    public function pendingResolution(string $sid,string $eid):?array
    {
        $r=$this->sql('SELECT id,pending_event_id,trigger_event_id,candidate_json,candidate_hash FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='pending'",[$sid,$eid])['rows'][0]??null;
        if(!$r)return null;if(!hash_equals($r['candidate_hash'],hash('sha256',$r['candidate_json'])))throw new RuntimeException('ACS_END_RECEIPT_INVALID');
        $c=json_decode($r['candidate_json'],true,64,JSON_THROW_ON_ERROR);
        return ['resolution_id'=>(string)$r['id'],'pending_event_id'=>(string)$r['pending_event_id'],'trigger_event_id'=>(string)$r['trigger_event_id'],'condition'=>$c['condition'],'outcomes'=>$c['outcomes'],'winner_side_id'=>$c['winner_side_id'],'allow_continuation'=>$c['policy']['allow_continuation']];
    }
    /** Downstream systems read only a confirmed outcome with its retained event identity. */
    public function confirmedOutcome(string $sid,string $eid):?array
    {
        $r=$this->sql('SELECT id,terminal_event_id,receipt_json,receipt_hash FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='confirmed' AND completed_encounter_id=encounter_id",[$sid,$eid])['rows'][0]??null;
        if(!$r)return null;if(!hash_equals($r['receipt_hash'],hash('sha256',$r['receipt_json'])))throw new RuntimeException('ACS_END_RECEIPT_INVALID');
        $receipt=json_decode($r['receipt_json'],true,64,JSON_THROW_ON_ERROR);
        if(($receipt['session_id']??null)!==$sid || ($receipt['encounter_id']??null)!==$eid || ($receipt['status']??null)!=='completed'
            || ($receipt['resolution_id']??null)!==(string)$r['id'] || ($receipt['terminal_event_id']??null)!==(string)$r['terminal_event_id']
            || ($receipt['outcome_id']??null)!=='acs-outcome:'.$eid.':'.$r['terminal_event_id'])throw new RuntimeException('ACS_END_RECEIPT_INVALID');
        $event=$this->sql('SELECT e.actor_uid,d.payload_json,d.visibility_json,d.content_hash,o.status FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key WHERE e.id=? AND e.session_id=? AND e.encounter_id=? AND e.event_type='encounter.outcome_confirm'",[$receipt['terminal_event_id'],$sid,$eid])['rows'][0]??null;
        if(!$event || $event['status']!=='committed' || (string)$event['actor_uid']!==$receipt['actor_uid']
            || !hash_equals($event['content_hash'],hash('sha256',$event['payload_json']."\n".$event['visibility_json']))
            || (json_decode($event['payload_json'],true,64,JSON_THROW_ON_ERROR)['result']??null)!==array_diff_key($receipt,array_flip(['schema_version','session_id','actor_uid','event_id'])))throw new RuntimeException('ACS_END_RECEIPT_INVALID');
        return $receipt;
    }
}
