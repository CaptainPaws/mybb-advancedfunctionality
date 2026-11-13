<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** All mutations participate in SessionRepository::command or existing Clock boundaries. */
trait AF_ACS_PreparedActionPersistence
{
    public function preparedRows(string $sid,string $eid,string $entity): array
    {
        return array_map(fn($r)=>$this->ids($r,['id','session_id','encounter_id','entity_id','control_group_id','owner_uid','source_sealed_action_id','source_entity_turn_id','source_event_id','prepared_version']),$this->sql('SELECT * FROM '.$this->table('prepared_actions').' WHERE session_id=? AND encounter_id=? AND entity_id=? ORDER BY id DESC',[$sid,$eid,$entity])['rows']);
    }
    public function registerPrepared(array $session,array $encounter,array $draft,array $seal,array $ability,array $dependencies): array
    {
        $this->commandWrite();
        $turn=$this->clockTurn($session['id'],$draft['turn_id']);
        if (!$turn || $turn['status']!=='pending' || $turn['encounter_id']!==$encounter['id'] || $encounter['status']!=='active') throw new RuntimeException('ACS_SEAL_TURN_UNAVAILABLE');
        $this->assertNoObligations($session['id'],$encounter['id'],$turn['id'],false,$draft['id']);
        $payload=$this->sealPayload($seal);$action=$payload['action'];$entity=$this->entity($session['id'],$action['actor_id']);
        $individual=null;foreach($this->individualTurns([$turn['id']]) as $row)if($row['entity_id']===$action['actor_id'])$individual=$row;
        if (!$individual || $individual['start_done']!=='1' || $individual['end_done']!=='0' || $entity['control_group_id']!==$turn['control_group_id']) throw new RuntimeException('ACS_SEAL_TURN_UNAVAILABLE');
        $contract=AF_ACS_PreparedAction::contract($ability['prepared_action']);$cost=$contract['ap_cost'];
        if ($seal['status']!=='sealed' || (int)$seal['ap_cost']!==$cost || $payload['pin']!==$ability['source_ref'] || $this->sealTape((string)$seal['id'])!==AF_ACS_PreparedAction::TAPE) throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $this->verifySealDefinition($session['id'],$seal,$payload);
        if (AF_ACS_Clock::availableAP((int)$turn['ap_budget'],(int)$turn['ap_spent'],(int)$turn['pre_round_ap_commitment'])<$cost) throw new RuntimeException('effects_cost_unaffordable');
        $fixed=['schema_version'=>1]+$contract+['ability_pin'=>$payload['pin'],'definition_snapshot_id'=>(string)$seal['definition_snapshot_id'],'target_policy'=>$ability['target_policy'],'target_entity_id'=>$action['targets'][0],
            'dependency_references'=>$dependencies,'source_sealed_hash'=>$seal['sealed_hash'],'paid_costs'=>[['kind'=>'ap','amount'=>$cost,'timing'=>'on_prepare','state'=>'paid']],
            'additional_cost_states'=>array_map(static fn($c)=>$c+['state'=>$c['timing']==='on_prepare'?'paid':'pending'],$contract['additional_costs'])];
        $json=self::json($fixed);
        $id=$this->sql('INSERT INTO '.$this->table('prepared_actions').' (session_id,encounter_id,entity_id,control_group_id,owner_uid,source_sealed_action_id,source_entity_turn_id,status,prepared_version,expires_boundary,cost_state,contract_hash,contract_json) VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?)',
            [$session['id'],$encounter['id'],$entity['id'],$turn['control_group_id'],$entity['owner_uid'],$seal['id'],$individual['id'],'active','until_next_turn','paid',hash('sha256',$json),$json])['id'];
        if ($this->sql('UPDATE '.$this->table('turns').' SET ap_spent=ap_spent+?,turn_version=turn_version+1 WHERE id=? AND turn_version=? AND status=?',[$cost,$turn['id'],$turn['turn_version'],'pending'])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
        foreach($contract['additional_costs'] as $extra)if($extra['timing']==='on_prepare') {
            $resource=null;foreach($this->entityResources($entity['id']) as $r)if($r['resource_key']===$extra['key'])$resource=$r;
            if(!$resource || (float)$resource['current_value']<$extra['amount'])throw new RuntimeException('effects_cost_unaffordable');
            self::integer((string)$resource['resource_version'],'18446744073709551614');
            if($this->sql('UPDATE '.$this->table('resources').' SET current_value=current_value-?,resource_version=resource_version+1 WHERE id=? AND resource_version=? AND current_value>=?',[$extra['amount'],$resource['id'],$resource['resource_version'],$extra['amount']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($this->sql('UPDATE '.$this->table('entity_states').' SET state_version=state_version+1 WHERE entity_id=?',[$entity['id']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        }
        // Existing EntityTurn end mechanics for every member of the shared group.
        foreach($this->checkTurnBoundaries($session['id'],$turn) as $row) {
            if ($row['start_done']==='0') $this->processEntityStart($session,$encounter,$row,$draft['author_uid']);
            $this->processEntityEnd($session,$encounter,$this->clockEntityTurn($session['id'],$row['id']),$draft['author_uid']);
        }
        $turn=$this->clockTurn($session['id'],$turn['id']);
        if ($this->sql('UPDATE '.$this->table('turns')." SET status='resolved',turn_version=turn_version+1,deadline_at=NULL,remaining_deadline_seconds=NULL WHERE id=? AND turn_version=? AND status='pending'",[$turn['id'],$turn['turn_version']])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
        if ($this->sql('UPDATE '.$this->table('drafts')." SET status='published',draft_version=draft_version+1,updated_at=? WHERE id=? AND draft_version=? AND status='sealed' AND pid IS NULL",[time(),$draft['id'],$draft['draft_version']])['affected']!==1) throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
        if ($this->sql('UPDATE '.$this->table('sealed_actions')." SET status='published' WHERE id=? AND status='sealed'",[$seal['id']])['affected']!==1) throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
        $session=$this->change($session,$encounter,'prepared',[]);
        $session['_command_result']+=['prepared_action_id'=>$id,'prepared_version'=>'1','status'=>'active','cost_state'=>'paid','turn_id'=>$turn['id'],'source_entity_id'=>$entity['id'],'post_created'=>false];
        return $session;
    }
    public function linkPreparedEvent(array $session,string $event): array
    {
        $id=$session['_command_result']['prepared_action_id'];
        if ($this->sql('UPDATE '.$this->table('prepared_actions').' SET source_event_id=? WHERE id=? AND source_event_id IS NULL',[$event,$id])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
        $this->sql('UPDATE '.$this->table('events').' SET source_entity_id=? WHERE id=?',[$session['_command_result']['source_entity_id'],$event]);return $session;
    }
    public function cancelPrepared(array $session,array $encounter,string $id,string $version,string $entity,string $reason):array
    {
        $this->commandWrite();
        if($this->sql('UPDATE '.$this->table('prepared_actions')." SET status='cancelled',prepared_version=prepared_version+1,terminal_boundary=? WHERE id=? AND session_id=? AND encounter_id=? AND entity_id=? AND prepared_version=? AND status='active'",['author_cancel:'.$reason,$id,$session['id'],$encounter['id'],$entity,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session=$this->change($session,$encounter,'prepared',[]);$session['_command_result']+=['prepared_action_id'=>$id,'status'=>'cancelled','cost_state'=>'paid'];return $session;
    }
    public function expirePrepared(array $session,array $encounter,array $individual,string $boundary): array
    {
        $this->commandWrite();$changed=[];
        foreach($this->preparedRows($session['id'],$encounter['id'],$individual['entity_id']) as $row) {
            if ($row['status']!=='active' || $row['source_entity_turn_id']===$individual['id']) continue;
            if ($this->sql('UPDATE '.$this->table('prepared_actions')." SET status='expired',prepared_version=prepared_version+1,terminal_boundary=? WHERE id=? AND prepared_version=? AND status='active'",[$boundary,$row['id'],$row['prepared_version']])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
            $changed[]=(string)$row['id'];
        }
        return $changed;
    }
}
