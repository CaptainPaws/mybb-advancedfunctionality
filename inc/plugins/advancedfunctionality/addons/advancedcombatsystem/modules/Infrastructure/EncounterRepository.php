<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionRepository.php';
require_once __DIR__.'/ClockPersistence.php';
require_once __DIR__.'/RoundManagementPersistence.php';

/** Prepared, scoped SQL. All mutators require the existing audited command transaction. */
final class AF_ACS_EncounterRepository extends AF_ACS_SessionRepository
{
    use AF_ACS_ClockPersistence;
    use AF_ACS_RoundManagementPersistence;
    private function ids(array $row,array $fields): array
    {
        foreach($fields as $field)if(isset($row[$field]))$row[$field]=(string)$row[$field];return $row;
    }
    public function encounter(string $sid,string $id,bool $lock=false): ?array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('encounters').' WHERE session_id=? AND id=?'.($lock?' FOR UPDATE':''),[self::integer($sid),self::integer($id)])['rows'][0]??null;
        return $row?$this->ids($row,['id','session_id','encounter_version','next_event_sequence','round_number','config_snapshot_id','created_at']):null;
    }
    public function encounters(string $sid,string $after='0'): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','session_id','encounter_version','round_number','config_snapshot_id']),$this->sql('SELECT * FROM '.$this->table('encounters').' WHERE session_id=? AND id>? ORDER BY id LIMIT 100',[self::integer($sid),self::integer($after,zero:true)])['rows']);
    }
    public function sides(string $id,string $after='0'): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','encounter_id','side_version']),$this->sql('SELECT * FROM '.$this->table('sides').' WHERE encounter_id=? AND id>? ORDER BY id LIMIT 100',[$id,self::integer($after,zero:true)])['rows']);
    }
    public function relations(string $id,string $after='0'): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','encounter_id','side_a','side_b','relation_version']),$this->sql('SELECT * FROM '.$this->table('side_relationships').' WHERE encounter_id=? AND id>? ORDER BY id LIMIT 100',[$id,self::integer($after,zero:true)])['rows']);
    }
    public function sideExists(string $eid,string $side): bool
    {
        return (bool)$this->sql('SELECT id FROM '.$this->table('sides').' WHERE encounter_id=? AND id=?',[$eid,$side])['rows'];
    }
    public function participantExists(string $eid,string $entity): bool
    {
        return (bool)$this->sql('SELECT id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$entity])['rows'];
    }
    public function allSides(string $eid): iterable
    {
        $after='0';do {$rows=$this->sides($eid,$after);foreach($rows as $row){$after=$row['id'];yield $row;}}while(count($rows)===100);
    }
    public function allRelations(string $eid): iterable
    {
        $after='0';do {$rows=$this->relations($eid,$after);foreach($rows as $row){$after=$row['id'];yield $row;}}while(count($rows)===100);
    }
    public function participants(string $id,string $after='0',int $limit=100): array
    {
        if(!in_array($limit,[100,201],true))throw new RuntimeException('ACS_QUERY_LIMIT');
        return array_map(fn($r)=>$this->ids($r,['id','encounter_id','entity_id','side_id','participant_version','control_group_id','owner_uid','sheet_id','controller_uid','assignment_version']),$this->sql('SELECT p.*,e.name,e.kind,e.control_group_id,e.owner_uid,e.sheet_id,c.controller_uid,c.assignment_version FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id LEFT JOIN '.$this->table('controllers')." c ON c.entity_id=e.id AND c.session_id=e.session_id AND c.status='active' AND (c.valid_until IS NULL OR c.valid_until>?) WHERE p.encounter_id=? AND p.id>? ORDER BY p.id LIMIT ".$limit,[time(),$id,self::integer($after,zero:true)])['rows']);
    }
    public function entity(string $sid,string $id): ?array
    {
        $row=$this->sql('SELECT e.*,s.life_state,s.hp,s.hp_max,s.state_version,s.layers_json,g.session_id AS group_session,g.owner_uid AS group_owner,g.kind AS group_kind,x.payload_json,x.content_hash FROM '.$this->table('entities').' e JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id JOIN '.$this->table('control_groups').' g ON g.id=e.control_group_id JOIN '.$this->table('snapshots').' x ON x.id=e.template_snapshot_id AND x.session_id=e.session_id WHERE e.session_id=? AND e.id=?',[$sid,$id])['rows'][0]??null;
        return $row?$this->ids($row,['id','session_id','control_group_id','owner_uid','group_owner','group_session','template_snapshot_id','entity_version','hp','hp_max','state_version']):null;
    }
    public function controllers(string $sid,string $entity): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','entity_id','controller_uid','assignment_version']),$this->sql('SELECT * FROM '.$this->table('controllers').' WHERE session_id=? AND entity_id=? AND status=? AND (valid_until IS NULL OR valid_until>?) ORDER BY id',[$sid,$entity,'active',time()])['rows']);
    }
    public function occupied(string $id,string $except): bool
    {
        return (bool)$this->sql('SELECT p.id FROM '.$this->table('participants').' p JOIN '.$this->table('encounters')." c ON c.id=p.encounter_id WHERE p.entity_id=? AND c.id<>? AND c.status IN ('active','paused','ambush_ready','pending_resolution') AND p.participation='engaged' LIMIT 1",[$id,$except])['rows'];
    }
    public function configSnapshot(string $sid,string $id): ?array
    {
        return $this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND id=?',[$sid,$id])['rows'][0]??null;
    }
    public function masters(string $sid): array
    {
        return array_map(fn($r)=>['uid'=>(string)$r['uid'],'role'=>$r['role']],$this->sql('SELECT uid,role FROM '.$this->table('session_roles')." WHERE session_id=? AND role IN ('owner','gm') AND (expires_at IS NULL OR expires_at>?) ORDER BY uid LIMIT 201",[$sid,time()])['rows']);
    }
    public function candidatesForEncounter(string $sid,string $after): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','control_group_id']),$this->sql('SELECT id,name,kind,control_group_id FROM '.$this->table('entities').' WHERE session_id=? AND id>? ORDER BY id LIMIT 100',[$sid,self::integer($after,zero:true)])['rows']);
    }
    public function events(string $sid,string $eid,string $after='0'): array
    {
        // Only confirmed operations, metadata only. Raw event_details/snapshots are not a viewer DTO.
        return array_map(fn($r)=>$this->ids($r,['id','event_sequence','actor_uid','created_at']),$this->sql('SELECT e.id,e.event_sequence,e.event_type,e.actor_uid,e.created_at FROM '.$this->table('events').' e JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key AND o.session_id=e.session_id AND o.encounter_id=e.encounter_id AND o.status='committed' WHERE e.session_id=? AND e.encounter_id=? AND e.id>? ORDER BY e.id LIMIT 100",[$sid,$eid,self::integer($after,zero:true)])['rows']);
    }
    public function createEncounter(array $session,string $title,array $defaults): array
    {
        $this->commandWrite();$sid=$session['id'];
        $config=$defaults+['title'=>$title];
        $snapshot=$this->insertSnapshot($sid,['source_system'=>'acs','source_key'=>'encounter-defaults','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-encounters-1','config'=>$config]);
        $id=$this->sql('INSERT INTO '.$this->table('encounters').' (session_id,status,encounter_version,next_event_sequence,round_number,start_operation_id,visibility,config_snapshot_id,config_json,paused_from,created_at,completed_at) VALUES (?,?,?,?,0,NULL,?,?,?,NULL,?,NULL)',[$sid,'preparation',1,1,$defaults['visibility'],$snapshot,self::json($config),time()])['id'];
        $session['_command_result']=['encounter_id'=>$id,'encounter_version'=>'1','encounter_sequence'=>'1','snapshot_id'=>$snapshot];
        $this->sql('UPDATE '.$this->table('encounters').' SET next_event_sequence=next_event_sequence+1 WHERE id=?',[$id]);
        return $session;
    }
    public function change(array $session,array $encounter,string $op,array $request): array
    {
        $this->commandWrite();$id=$encounter['id'];$result=[];
        if($op==='side_create') {
            $result['side_id']=$this->sql('INSERT INTO '.$this->table('sides').' (encounter_id,name,side_version,policy_json) VALUES (?,?,1,?)',[$id,self::text($request['name'],191),self::json(['schema_version'=>1])])['id'];
        } elseif($op==='relation_set') {
            $a=$request['side_a'];$b=$request['side_b'];
            if(strlen($a)>strlen($b) || (strlen($a)===strlen($b) && strcmp($a,$b)>0))[$a,$b]=[$b,$a];
            $old=$this->sql('SELECT id,relation_version,relationship FROM '.$this->table('side_relationships').' WHERE encounter_id=? AND side_a=? AND side_b=? FOR UPDATE',[$id,$a,$b])['rows'][0]??null;
            $result['previous_relationship']=$old['relationship']??null;
            if($old){self::integer($old['relation_version'],'18446744073709551614');$this->sql('UPDATE '.$this->table('side_relationships').' SET relationship=?,relation_version=relation_version+1 WHERE id=?',[$request['relationship'],$old['id']]);}
            else $this->sql('INSERT INTO '.$this->table('side_relationships').' (encounter_id,side_a,side_b,relationship,relation_version) VALUES (?,?,?,?,1)',[$id,$a,$b,$request['relationship']]);
        } elseif($op==='participant_add') {
            if($this->sql('SELECT id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$id,$request['entity_id']])['rows'])throw new RuntimeException('ACS_PARTICIPANT_EXISTS');
            $result['participant_id']=$this->sql('INSERT INTO '.$this->table('participants').' (encounter_id,entity_id,side_id,participation,registered_sequence,participant_version,visibility_json) VALUES (?,?,?,?,?,1,?)',[$id,$request['entity_id'],$request['side_id'],'engaged',$encounter['next_event_sequence'],self::json(['schema_version'=>1,'scope'=>'gm'])])['id'];
        } elseif($op==='participant_side') {
            $row=$this->sql('SELECT participant_version,side_id FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=? FOR UPDATE',[$id,$request['entity_id']])['rows'][0]??null;
            if(!$row)throw new RuntimeException('ACS_PARTICIPANT_UNAVAILABLE');self::integer($row['participant_version'],'18446744073709551614');
            $result['previous_side_id']=$row['side_id']===null?null:(string)$row['side_id'];
            $this->sql('UPDATE '.$this->table('participants').' SET side_id=?,participant_version=participant_version+1 WHERE encounter_id=? AND entity_id=?',[$request['side_id'],$id,$request['entity_id']]);
        } elseif($op==='controller_assign') {
            if($this->sql('UPDATE '.$this->table('controllers').' SET status=?,valid_until=? WHERE id=? AND assignment_version=? AND status=?',['superseded',time(),$request['_assignment_id'],$request['_previous_assignment_version'],'active'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $this->sql('INSERT INTO '.$this->table('controllers').' (session_id,entity_id,controller_uid,mode,status,assignment_version,valid_from,valid_until,permissions_json) VALUES (?,?,?,?,?,CAST(? AS UNSIGNED)+1,?,NULL,?)',[$session['id'],$request['entity_id'],$request['uid'],'exclusive','active',$request['_previous_assignment_version'],time(),self::json(['schema_version'=>1,'scope'=>'preparation','combat_operations_enabled'=>false])]);
            $result=['previous_controller_uid'=>$request['_previous_controller_uid'],'controller_uid'=>$request['uid'],'assignment_version'=>$this->controllers($session['id'],$request['entity_id'])[0]['assignment_version']];
        }
        self::integer($encounter['encounter_version'],'18446744073709551614');self::integer($encounter['next_event_sequence'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('encounters').' SET encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE session_id=? AND id=? AND encounter_version=?',[$session['id'],$id,$encounter['encounter_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        if($op==='participant_add' && $encounter['status']==='active')$result['admission']='next_round';
        if(isset($request['_snapshot_id']))$result+=['entity_id'=>$request['entity_id'],'snapshot_id'=>$request['_snapshot_id'],'control_group_id'=>$request['_group_id']];
        $session['_command_result']=['encounter_id'=>$id,'encounter_version'=>$this->encounter($session['id'],$id)['encounter_version'],'encounter_sequence'=>$encounter['next_event_sequence']]+$result;
        return $session;
    }
    /** Snapshot input read under the existing session/encounter command lock. */
    public function rosterSources(string $sid,string $eid): array
    {
        $rows=$this->sql('SELECT p.id AS participant_id,p.entity_id,p.side_id,p.participant_version,e.name,e.kind,e.owner_uid,e.control_group_id,e.entity_version,e.template_snapshot_id,g.group_version,g.config_json AS group_config,s.life_state,s.state_version,s.layers_json,x.content_hash,c.controller_uid,c.assignment_version FROM '.$this->table('participants').' p JOIN '.$this->table('entities').' e ON e.id=p.entity_id AND e.session_id=? JOIN '.$this->table('control_groups').' g ON g.id=e.control_group_id AND g.session_id=e.session_id JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id JOIN '.$this->table('snapshots').' x ON x.id=e.template_snapshot_id AND x.session_id=e.session_id JOIN '.$this->table('controllers')." c ON c.entity_id=e.id AND c.session_id=e.session_id AND c.status='active' AND (c.valid_until IS NULL OR c.valid_until>?) WHERE p.encounter_id=? AND p.participation='engaged' ORDER BY e.control_group_id,e.id",[$sid,time(),$eid])['rows'];
        foreach($rows as &$row)foreach(['participant_id','entity_id','side_id','participant_version','owner_uid','control_group_id','entity_version','template_snapshot_id','group_version','state_version','controller_uid','assignment_version'] as $field)if($row[$field]!==null)$row[$field]=(string)$row[$field];unset($row);
        return $rows;
    }
    public function firstRound(string $eid): ?array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('rounds').' WHERE encounter_id=? AND round_number=1',[$eid])['rows'][0]??null;
        return $row?$this->ids($row,['id','encounter_id','round_number','round_version','opened_at','closed_at']):null;
    }
    public function roundTurns(string $roundId,string $after='0'): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','round_id','control_group_id','turn_version','ap_budget','ap_spent','seal_quota_spent','pre_round_ap_commitment']),$this->sql('SELECT * FROM '.$this->table('turns').' WHERE round_id=? AND id>? ORDER BY id LIMIT 100',[$roundId,self::integer($after,zero:true)])['rows']);
    }
    public function individualTurns(array $turnIds): array
    {
        if(!$turnIds)return [];
        if(count($turnIds)>100)throw new RuntimeException('ACS_QUERY_LIMIT');
        $placeholders=implode(',',array_fill(0,count($turnIds),'?'));
        return array_map(fn($r)=>$this->ids($r,['id','turn_id','entity_id','entity_turn_version','start_done','end_done','reaction_used']),$this->sql('SELECT * FROM '.$this->table('entity_turns').' WHERE turn_id IN ('.$placeholders.') ORDER BY turn_id,entity_id',$turnIds)['rows']);
    }
    public function openFirstRound(array $session,array $encounter,array $sources,string $actor): array
    {
        $this->commandWrite();
        if($encounter['status']!=='preparation' || $encounter['round_number']!=='0' || $encounter['start_operation_id']!==null || $this->firstRound($encounter['id']))throw new RuntimeException('ACS_FIRST_ROUND_EXISTS');
        return $this->openRoundProjection($session,$encounter,$sources,$actor,1,true);
    }
    private function openRoundProjection(array $session,array $encounter,array $sources,string $actor,int $number,bool $first,?int $openedAt=null): array
    {
        $this->commandWrite();
        $openedAt??=time();
        $groups=[];foreach($sources as $source)$groups[$source['control_group_id']][]=$source;
        if(!$groups)throw new RuntimeException('ACS_ROSTER_INVALID');
        $round=$this->sql('INSERT INTO '.$this->table('rounds').' (encounter_id,round_number,status,round_version,opened_at,closed_at) VALUES (?,?,?,1,?,NULL)',[$encounter['id'],$number,'open',$openedAt])['id'];
        $rosters=[];$entityCount=0;
        foreach($groups as $group=>$members) {
            $frozen=[];foreach($members as $member) {
                unset($member['group_config'],$member['layers_json']);$frozen[]=$member;++$entityCount;
            }
            $payload=['schema_version'=>1,'contract_id'=>'IC-ACS.round-roster.v1','clock_version'=>AF_ACS_Clock::VERSION,'numeric_policy'=>'binary64-half_up-v1',
                'round_id'=>$round,'round_number'=>$number,'control_group_id'=>(string)$group,'group_version'=>$members[0]['group_version'],
                'initial_ap'=>3,'pre_round_ap_commitment'=>0,'waiting_policy'=>'next_round','members'=>$frozen];
            $payload['fingerprint']=hash('sha256',self::json($payload));$json=self::json($payload);$rosters[$group]=$json;
            $turn=$this->sql('INSERT INTO '.$this->table('turns').' (round_id,control_group_id,status,ap_budget,ap_spent,seal_quota_spent,pre_round_ap_commitment,turn_version,deadline_at,remaining_deadline_seconds,closed_by_submission,roster_json) VALUES (?,?,?,0,0,0,0,1,NULL,NULL,NULL,?)',[$round,(string)$group,'pending',$json])['id'];
            foreach($members as $member)$this->sql('INSERT INTO '.$this->table('entity_turns').' (turn_id,entity_id,start_done,end_done,reaction_used,entity_turn_version) VALUES (?,?,0,0,0,1)',[$turn,$member['entity_id']]);
        }
        self::integer($encounter['next_event_sequence'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('encounters').' SET status=?,round_number=?,start_operation_id=?,encounter_version=encounter_version+1,next_event_sequence=next_event_sequence+1 WHERE session_id=? AND id=? AND encounter_version=? AND status=?', ['active',$number,$first?'encounter:'.$encounter['id'].':first-round':$encounter['start_operation_id'],$session['id'],$encounter['id'],$encounter['encounter_version'],$first?'preparation':'active'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $this->initializeRoundAP($session['id'],$encounter['id'],$round,$actor,$encounter['next_event_sequence']);
        $this->initializeDeadlines($round,$encounter,$openedAt);
        $session['_command_result']=['encounter_id'=>$encounter['id'],'encounter_version'=>$this->encounter($session['id'],$encounter['id'])['encounter_version'],
            'encounter_sequence'=>$encounter['next_event_sequence'],'round_id'=>$round,'round_number'=>(string)$number,'turn_count'=>(string)count($groups),'entity_turn_count'=>(string)$entityCount,
            'round_roster_hash'=>AF_ACS_Clock::rosterHash($rosters),'clock_version'=>AF_ACS_Clock::VERSION,'ap_refresh_boundary'=>AF_ACS_Clock::apRefresh($round)];
        return $session;
    }

}
