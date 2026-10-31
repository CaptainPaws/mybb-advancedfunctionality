<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Existing ACS transaction, no scheduler/GET side effects or destructive draft cleanup. */
trait AF_ACS_RoundManagementPersistence
{
    public function replayRoundCommand(string $key,string $kind,array $request): ?array
    {
        $row=$this->sql('SELECT kind,session_id,payload_hash,status,result_json FROM '.$this->table('operations').' WHERE operation_key=?',[$key])['rows'][0]??null;
        if(!$row)return null;
        if($row['kind']!==$kind || (string)$row['session_id']!==$request['session_id'] || !hash_equals($row['payload_hash'],hash('sha256',self::json($request))))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
        if($row['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');return json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
    }
    public function currentRound(array $encounter): ?array
    {
        if((string)$encounter['round_number']==='0')return null;
        $row=$this->sql('SELECT * FROM '.$this->table('rounds').' WHERE encounter_id=? AND round_number=?',[$encounter['id'],$encounter['round_number']])['rows'][0]??null;
        return $row?$this->ids($row,['id','encounter_id','round_number','round_version','opened_at','closed_at']):null;
    }
    public function allRoundTurns(string $round): iterable
    {
        $after='0';do{$rows=$this->roundTurns($round,$after);foreach($rows as $row){$after=$row['id'];yield $row;}}while(count($rows)===100);
    }
    private function futureTable(string $name): ?string
    {
        if(!in_array($name,['drafts','sealed_actions','submissions','effects'],true))throw new RuntimeException('ACS_UNKNOWN_TABLE');
        return $this->sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.'af_acs_'.$name])['rows']?'`'.$this->prefix.'af_acs_'.$name.'`':null;
    }
    public function assertNoObligations(string $sid,string $eid,?string $turn=null): void
    {
        if($this->sql('SELECT operation_key FROM '.$this->table('operations')." WHERE session_id=? AND (encounter_id=? OR encounter_id IS NULL) AND status IN ('prepared','recovery_required') LIMIT 1",[$sid,$eid])['rows'])throw new RuntimeException('ACS_ROUND_OBLIGATIONS_BLOCKED');
        try {
            $drafts=$this->futureTable('drafts');$sealed=$this->futureTable('sealed_actions');$submissions=$this->futureTable('submissions');
            $scope='encounter_id=?'.($turn===null?'':' AND turn_id=?');$args=$turn===null?[$eid]:[$eid,$turn];
            if($drafts && $this->sql('SELECT id FROM '.$drafts.' WHERE '.$scope." AND status NOT IN ('published','cancelled') LIMIT 1",$args)['rows'])throw new RuntimeException('ACS_ROUND_OBLIGATIONS_BLOCKED');
            if($sealed && !$drafts)throw new RuntimeException('ACS_ROUND_OBLIGATIONS_BLOCKED');
            if($sealed && $this->sql('SELECT a.id FROM '.$sealed.' a JOIN '.$drafts.' d ON d.id=a.draft_id WHERE d.encounter_id=?'.($turn===null?'':' AND d.turn_id=?')." AND a.status NOT IN ('published','cancelled','consumed') LIMIT 1",$args)['rows'])throw new RuntimeException('ACS_ROUND_OBLIGATIONS_BLOCKED');
            if($submissions && $this->sql('SELECT submission_id FROM '.$submissions.' WHERE '.$scope." AND status NOT IN ('published','rejected','cancelled') LIMIT 1",$args)['rows'])throw new RuntimeException('ACS_ROUND_OBLIGATIONS_BLOCKED');
            $effects=$this->futureTable('effects');
            if($effects && $this->sql('SELECT id FROM '.$effects." WHERE session_id=? AND (encounter_id=? OR encounter_id IS NULL) AND status NOT IN ('expired','removed','ended') LIMIT 1",[$sid,$eid])['rows'])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
        } catch(Throwable $error) {
            if(in_array($error->getMessage(),['ACS_ROUND_OBLIGATIONS_BLOCKED','ACS_CLOCK_DEPENDENCY_BLOCKED'],true))throw $error;
            throw new RuntimeException('ACS_OBLIGATIONS_SCHEMA_UNVERIFIED');
        }
    }
    private function checkTurnBoundaries(string $sid,array $turn): array
    {
        $roster=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$roster['fingerprint']??null;unset($roster['fingerprint']);
        if(!is_string($fp) || !hash_equals($fp,hash('sha256',self::json($roster))))throw new RuntimeException('ACS_ROSTER_INVALID');
        $rows=$this->individualTurns([$turn['id']]);
        if(count($rows)!==count($roster['members']??[]))throw new RuntimeException('ACS_ROSTER_INVALID');
        foreach($rows as $row) {
            if(!in_array($row['entity_id'],array_column($roster['members'],'entity_id'),true))throw new RuntimeException('ACS_ROSTER_INVALID');
            $member=null;foreach($roster['members'] as $candidate)if($candidate['entity_id']===$row['entity_id'])$member=$candidate;
            $snapshot=$member?$this->configSnapshot($sid,$member['template_snapshot_id']):null;
            if(!$snapshot || !hash_equals($snapshot['content_hash'],hash('sha256',$snapshot['payload_json'])) || $snapshot['content_hash']!==$member['content_hash'])throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');
            $entity=$this->entity($sid,$row['entity_id']);$group=$this->clockGroup($sid,$turn['control_group_id']);
            if(!$entity || !$group || json_decode($entity['layers_json'],true)!==['schema_version'=>1,'layers'=>[]] || json_decode($group['config_json'],true)!==['schema_version'=>1])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
            foreach($this->allCooldowns($sid,$row['entity_id']) as $cooldown)$this->cooldownSource($sid,$cooldown);
        }
        return $rows;
    }
    public function initializeDeadlines(string $round,array $encounter,int $now): void
    {
        $this->commandWrite();$policy=AF_ACS_Clock::deadlinePolicy(json_decode($encounter['config_json'],true,32,JSON_THROW_ON_ERROR));
        if($policy['mode']==='off')return;
        $deadline=AF_ACS_Clock::deadlineAfter($now,$policy['seconds']);
        $this->sql('UPDATE '.$this->table('turns').' SET deadline_at=? WHERE round_id=? AND status=?',[$deadline,$round,'pending']);
    }
    public function pauseResume(array $session,array $encounter,bool $resume,int $now): array
    {
        $this->commandWrite();$round=$this->currentRound($encounter);
        if(!$round || $round['status']!=='open' || ($resume?($encounter['status']!=='paused' || $encounter['paused_from']!=='active'):$encounter['status']!=='active'))throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        $changes=[];
        foreach($this->allRoundTurns($round['id']) as $turn)if($turn['status']==='pending') {
            self::integer($turn['turn_version'],'18446744073709551614');
            if($resume) {$remaining=$turn['remaining_deadline_seconds'];$deadline=$remaining===null?null:AF_ACS_Clock::deadlineAfter($now,(int)$remaining);}
            else {$deadline=null;$remaining=$turn['deadline_at']===null?null:max(0,(int)$turn['deadline_at']-$now);}
            if($this->sql('UPDATE '.$this->table('turns').' SET deadline_at=?,remaining_deadline_seconds=?,turn_version=turn_version+1 WHERE id=? AND turn_version=?',[$deadline,$resume?null:$remaining,$turn['id'],$turn['turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $changes[]=['turn_id'=>$turn['id'],'deadline_before'=>$turn['deadline_at']===null?null:(string)$turn['deadline_at'],'remaining_seconds'=>$remaining===null?null:(string)$remaining,'deadline_after'=>$deadline===null?null:(string)$deadline];
        }
        $this->sql('UPDATE '.$this->table('encounters').' SET status=?,paused_from=? WHERE id=?',[$resume?'active':'paused',$resume?null:'active',$encounter['id']]);
        $session=$this->change($session,$encounter,'round_management',[]);$session['_command_result']+=['status'=>$resume?'active':'paused','deadline_changes'=>$changes];return $session;
    }
    public function setDeadlines(array $session,array $encounter,array $policy,int $now): array
    {
        $this->commandWrite();if(!in_array($encounter['status'],['preparation','active'],true))throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        $config=json_decode($encounter['config_json'],true,32,JSON_THROW_ON_ERROR);$config['deadline_policy']=$policy;
        $snapshot=$this->insertSnapshot($session['id'],['source_system'=>'acs','source_key'=>'encounter:'.$encounter['id'].':deadline-policy','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-round-management-1','config'=>$config]);
        $this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=? WHERE id=?',[self::json($config),$snapshot,$encounter['id']]);
        if($round=$this->currentRound($encounter))foreach($this->allRoundTurns($round['id']) as $turn)if($turn['status']==='pending') {
            self::integer($turn['turn_version'],'18446744073709551614');$deadline=$policy['mode']==='off'?null:AF_ACS_Clock::deadlineAfter($now,$policy['seconds']);
            if($this->sql('UPDATE '.$this->table('turns').' SET deadline_at=?,remaining_deadline_seconds=NULL,turn_version=turn_version+1 WHERE id=? AND turn_version=?',[$deadline,$turn['id'],$turn['turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        }
        $session=$this->change($session,$encounter,'round_management',[]);$session['_command_result']+=['snapshot_id'=>$snapshot,'deadline_policy'=>$policy];return $session;
    }
    public function extendDeadline(array $session,array $encounter,array $turn,int $seconds,int $now): array
    {
        $this->commandWrite();if($turn['status']!=='pending' || !in_array($encounter['status'],['active','paused'],true))throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        $paused=$encounter['status']==='paused';$old=$paused?$turn['remaining_deadline_seconds']:$turn['deadline_at'];if($old===null)throw new RuntimeException('ACS_DEADLINE_DISABLED');
        $next=AF_ACS_Clock::deadlineAfter((int)$old,$seconds);$column=$paused?'remaining_deadline_seconds':'deadline_at';
        self::integer($turn['turn_version'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('turns').' SET '.$column.'=?,turn_version=turn_version+1 WHERE id=? AND turn_version=?',[$next,$turn['id'],$turn['turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session=$this->change($session,$encounter,'round_management',[]);$session['_command_result']+=['turn_id'=>$turn['id'],'before'=>(string)$old,'after'=>(string)$next];return $session;
    }
    public function closeTurn(array $session,array $encounter,array $turn,string $state,string $actor): array
    {
        $this->commandWrite();if($encounter['status']!=='active' || $turn['status']!=='pending' || !in_array($state,['passed','forfeited'],true))throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        $this->assertNoObligations($session['id'],$encounter['id'],$turn['id']);$rows=$this->checkTurnBoundaries($session['id'],$turn);
        if($state==='forfeited')foreach($rows as $individual) {
            $p=$this->sql('SELECT participation FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$encounter['id'],$individual['entity_id']])['rows'][0]??null;
            if(!$p || !in_array($p['participation'],['withdrawn','escaped','captured','surrendered'],true))throw new RuntimeException('ACS_FORFEIT_EXCEPTION_UNAVAILABLE');
        }
        $boundaries=[];
        foreach($rows as $individual) {
            $start=AF_ACS_Clock::entityStart($individual['id']);
            if($individual['start_done']==='0')$this->processEntityStart($session,$encounter,$individual,$actor);
            elseif(!$this->boundary($start,$session['id']))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
            $row=$this->clockEntityTurn($session['id'],$individual['id']);$end=AF_ACS_Clock::entityEnd($row['id']);
            if((string)$row['end_done']==='0') {
                self::integer($row['entity_turn_version'],'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('entity_turns').' SET end_done=1,entity_turn_version=entity_turn_version+1 WHERE id=? AND entity_turn_version=?',[$row['id'],$row['entity_turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
                $this->recordBoundary($end,'clock.entity_end',$session['id'],$encounter['id'],$actor,['entity_turn_id'=>$row['id']],['entity_turn_id'=>$row['id'],'parent_encounter_sequence'=>$encounter['next_event_sequence']]);
            } elseif(!$this->boundary($end,$session['id']))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
            $boundaries[]=['entity_turn_id'=>$row['id'],'start'=>$start,'end'=>$end];
        }
        self::integer($turn['turn_version'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('turns').' SET status=?,deadline_at=NULL,remaining_deadline_seconds=NULL,turn_version=turn_version+1 WHERE id=? AND turn_version=? AND status=?',[$state,$turn['id'],$turn['turn_version'],'pending'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $key='turn:'.$turn['id'].':closed';$session=$this->change($session,$encounter,'round_management',[]);
        $result=$session['_command_result']+['turn_id'=>$turn['id'],'status'=>$state,'boundary_key'=>$key,'individual_boundaries'=>$boundaries];
        $this->recordBoundary($key,'round.turn_closed',$session['id'],$encounter['id'],$actor,['turn_id'=>$turn['id']],$result);$session['_command_result']=$result;return $session;
    }
    public function nextRound(array $session,array $encounter,array $oldRound,array $sources,string $actor,int $now): array
    {
        $this->commandWrite();$this->assertNoObligations($session['id'],$encounter['id']);$count=0;
        foreach($this->allRoundTurns($oldRound['id']) as $turn) {
            ++$count;if(!in_array($turn['status'],['resolved','passed','forfeited'],true))throw new RuntimeException('ACS_PENDING_TURNS_REMAIN');
            foreach($this->checkTurnBoundaries($session['id'],$turn) as $individual)if($individual['start_done']!=='1' || $individual['end_done']!=='1' || !$this->boundary(AF_ACS_Clock::entityStart($individual['id']),$session['id']) || !$this->boundary(AF_ACS_Clock::entityEnd($individual['id']),$session['id']))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        }
        if(!$count || $encounter['status']!=='active' || $oldRound['status']!=='open' || (string)$oldRound['round_number']!==$encounter['round_number'])throw new RuntimeException('ACS_ROUND_STATE_CONFLICT');
        $n=(int)self::integer((string)$oldRound['round_number'],'2147483646')+1;self::integer((string)$oldRound['round_version'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('rounds').' SET status=?,closed_at=?,round_version=round_version+1 WHERE id=? AND round_version=?',['closed',$now,$oldRound['id'],$oldRound['round_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session=$this->openRoundProjection($session,$encounter,$sources,$actor,$n,false,$now);$new=$this->currentRound($this->encounter($session['id'],$encounter['id']));
        $boundaryContext=$encounter;$boundaryContext['status']='active';$boundaryContext['round_number']=(string)$n;
        foreach($this->allRoundTurns($new['id']) as $turn)foreach($this->individualTurns([$turn['id']]) as $individual) {
            $row=$this->clockEntityTurn($session['id'],$individual['id']);$this->processEntityStart($session,$boundaryContext,$row,$actor);
        }
        $key='round:'.$oldRound['id'].':next';$session['_command_result']+=['previous_round_id'=>(string)$oldRound['id'],'boundary_key'=>$key];
        $this->recordBoundary($key,'round.next',$session['id'],$encounter['id'],$actor,['previous_round_id'=>(string)$oldRound['id']],$session['_command_result']);return $session;
    }
    public function recordDeadlineNotice(array $session,array $encounter,array $turn,string $actor): array
    {
        $this->commandWrite();$key='turn:'.$turn['id'].':deadline:'.$turn['deadline_at'].':notify';
        $session=$this->change($session,$encounter,'round_management',[]);$session['_command_result']+=['turn_id'=>$turn['id'],'boundary_key'=>$key,'notification'=>'recorded_delivery_unavailable'];
        $this->recordBoundary($key,'round.deadline_notice',$session['id'],$encounter['id'],$actor,['turn_id'=>$turn['id']],$session['_command_result']);return $session;
    }
}
