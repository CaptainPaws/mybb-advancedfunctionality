<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Domain/Participation.php';
trait AF_ACS_ParticipationPersistence
{
    public function participationPolicy(array $enc):array
    {
        $s=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND id=?',[$enc['session_id'],$enc['config_snapshot_id']])['rows'][0]??null;
        $config=json_decode($enc['config_json'],true,64,JSON_THROW_ON_ERROR);
        if(!$s||!hash_equals($s['content_hash'],hash('sha256',$s['payload_json']))||(json_decode($s['payload_json'],true,64,JSON_THROW_ON_ERROR)['config']??null)!==$config)throw new RuntimeException('ACS_PARTICIPATION_PIN_INVALID');
        return AF_ACS_Participation::policy($config['participation_policy']??null);
    }
    public function configureParticipation(array $session,array $enc,array $policy):array
    {
        $this->commandWrite();AF_ACS_Participation::policy($policy);
        if($enc['status']!=='preparation'||$enc['round_number']!=='0'||$enc['start_operation_id']!==null)throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        foreach(['encounter_version','next_event_sequence'] as $field)self::integer($enc[$field],'18446744073709551614');
        $config=json_decode($enc['config_json'],true,64,JSON_THROW_ON_ERROR);$config['participation_policy']=$policy;
        $pin=$this->insertSnapshot($session['id'],['source_system'=>'acs','source_key'=>'encounter-participation','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-participation-1','config'=>$config]);
        if($this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=?,encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[self::json($config),$pin,$enc['id'],$enc['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$enc['id'],'encounter_sequence'=>$enc['next_event_sequence'],'encounter_version'=>$this->encounter($session['id'],$enc['id'])['encounter_version'],'snapshot_id'=>$pin];return $session;
    }
    public function returnParticipation(array $session,array $enc,string $op,array $request):array
    {
        $this->commandWrite();$policy=$this->participationPolicy($enc);
        $p=$this->sql('SELECT p.*,s.life_state,e.kind FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id AND e.session_id=? JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id WHERE p.encounter_id=? AND p.entity_id=? FOR UPDATE',[$session['id'],$enc['id'],$request['entity_id']])['rows'][0]??null;
        if(!$p||(string)$p['participant_version']!==$request['participant_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($p['life_state']==='dead'||($op==='rejoin_encounter'&&($p['life_state']!=='active'||!in_array($p['participation'],$policy['rejoin_from'],true)))||($op==='release'&&$p['participation']!=='captured'))throw new RuntimeException('ACS_PARTICIPATION_STATE_CONFLICT');
        if($p['kind']==='summon'&&($this->summonProjection($session['id'],$enc['id'])[$request['entity_id']]['status']??null)!=='active')throw new RuntimeException('ACS_PARTICIPATION_STATE_CONFLICT');
        foreach(['encounter_version','next_event_sequence'] as $field)self::integer($enc[$field],'18446744073709551614');self::integer($p['participant_version'],'18446744073709551614');
        if($op==='rejoin_encounter'){$spatialState=['spatial'=>$this->spatialProjection($session['id'],$enc['id']),'objects'=>$this->objectProjection($session['id'],$enc['id'])];AF_ACS_Participation::spatial($spatialState,$policy,$request['entity_id']);}
        $this->assertNoObligations($session['id'],$enc['id']);$to=$op==='release'?$policy['release_to']:'engaged';
        if($this->sql('UPDATE '.$this->table('participants').' SET participation=?,participant_version=participant_version+1 WHERE id=? AND participant_version=?',[$to,$p['id'],$p['participant_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE id=? AND encounter_version=?',[$enc['id'],$enc['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['encounter_id'=>$enc['id'],'encounter_sequence'=>$enc['next_event_sequence'],'entity_id'=>$request['entity_id'],'before'=>$p['participation'],'participation'=>$to,'policy_snapshot_id'=>$enc['config_snapshot_id'],'no_refill'=>true];return $session;
    }
    private function persistParticipation(array $before,array $after,string $eid):array
    {
        $out=[];foreach($after as $id=>$p){$old=$before[$id]??null;if($p===$old)continue;
            if(!$old||$p['version']!==$old['version']+1||$p['side_id']!==$old['side_id']||$p['group_id']!==$old['group_id'])throw new RuntimeException('ACS_PARTICIPATION_STATE_CONFLICT');
            if($this->sql('UPDATE '.$this->table('participants').' SET participation=?,participant_version=participant_version+1 WHERE encounter_id=? AND entity_id=? AND participant_version=? AND participation=?',[$p['participation'],$eid,(string)$id,$old['version'],$old['participation']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $out[(string)$id]=['before'=>$old,'after'=>$p];
        }return $out;
    }
}
