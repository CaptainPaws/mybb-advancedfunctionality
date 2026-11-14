<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Pre-round admission and paid AP ledger; never materializes a clock/turn placeholder. */
trait AF_ACS_PreRoundPersistence
{
    public function preRoundEncounter(string $sid,string $eid): array
    {
        $session=$this->session($sid);$encounter=$this->encounter($sid,$eid);
        if(!$session||$session['status']!=='active'||!$encounter||$encounter['status']!=='preparation'||$encounter['round_number']!=='0'||$encounter['start_operation_id']!==null||$this->firstRound($eid))throw new RuntimeException('ACS_PRE_ROUND_UNAVAILABLE');
        return $encounter;
    }
    public function preRoundMembers(string $sid,string $eid): array
    {
        $this->preRoundEncounter($sid,$eid);$out=[];
        $after='0';do{$rows=$this->participants($eid,$after);foreach($rows as $r){$after=$r['id'];if($r['participation']==='engaged')$out[$r['entity_id']]=$r;}}while(count($rows)===100);
        if(!$out||count($out)>200)throw new RuntimeException('ACS_PRE_ROUND_UNAVAILABLE');return $out;
    }
    /** Reject tampered/unconfirmed provenance; cancelled costs remain paid, never refunded. */
    public function preRoundCommitments(string $sid,string $eid): array
    {
        $rows=$this->sql('SELECT * FROM '.$this->table('pre_round_commitments').' WHERE session_id=? AND encounter_id=? ORDER BY id',[$sid,$eid])['rows'];$totals=[];
        foreach($rows as $r){
            $p=$this->sql('SELECT * FROM '.$this->table('prepared_actions').' WHERE id=? AND session_id=? AND encounter_id=?',[$r['prepared_action_id'],$sid,$eid])['rows'][0]??null;
            $event=$this->sql('SELECT e.operation_key,e.source_entity_id,o.status FROM '.$this->table('events').' e JOIN '.$this->table('operations').' o ON o.operation_key=e.operation_key WHERE e.id=? AND e.session_id=? AND e.encounter_id=? AND e.event_type=?',[$r['source_event_id'],$sid,$eid,'encounter.prepared_register'])['rows'][0]??null;
            if(!$p||!$event||(string)$event['source_entity_id']!==(string)$r['entity_id']||!in_array($p['status'],['active','cancelled','expired','executed'],true)||!in_array($p['cost_state'],['paid','settled','recovery_required'],true)||$p['status']==='active'&&$p['cost_state']!=='paid'||$event['status']!=='committed'||$event['operation_key']!==$r['operation_key']||!hash_equals($p['contract_hash'],hash('sha256',$p['contract_json'])))throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
            $c=json_decode($p['contract_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_PreparedAction::contract(array_intersect_key($c,array_flip(['schema','trigger','target','duration','ap_cost','additional_costs','requires_active','execution','hidden','pre_round'])));
            // Persisted contracts also contain immutable references; validate their typed KB subset.
            if(($c['pre_round']['allowed']??false)!==true||($c['pre_round_scope']??null)!==['schema'=>'ic-acs.pre-round-scope.v1','encounter_id'=>$eid]||(string)$p['source_entity_turn_id']!=='0'||(string)$p['entity_id']!==(string)$r['entity_id']||(string)$p['control_group_id']!==(string)$r['control_group_id']||(string)$p['source_sealed_action_id']!==(string)$r['source_sealed_action_id']||(string)$p['source_event_id']!==(string)$r['source_event_id']||(int)$r['paid_ap']!==$c['ap_cost']||$c['paid_costs'][0]!==['kind'=>'ap','amount'=>(int)$r['paid_ap'],'timing'=>'on_prepare','state'=>'paid']||self::json(['schema_version'=>1,'pin'=>$c['ability_pin']])!==$r['ability_pin_json'])throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
            $seal=$this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE id=?',[$r['source_sealed_action_id']])['rows'][0]??throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
            $payload=$this->sealPayload($seal);$this->verifySealDefinition($sid,$seal,$payload);
            if($seal['status']!=='published'||$payload['pin']!==$c['ability_pin']||$payload['action']['actor_id']!==(string)$r['entity_id']||(int)$seal['ap_cost']!==(int)$r['paid_ap']||$this->sealTape((string)$seal['id'])!==AF_ACS_PreparedAction::TAPE)throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
            $group=(string)$r['control_group_id'];$totals[$group]=($totals[$group]??0)+(int)$r['paid_ap'];if($totals[$group]>3)throw new RuntimeException('ACS_PRE_ROUND_OVERCOMMIT');
        }
        $prepared=$this->sql('SELECT id FROM '.$this->table('prepared_actions').' WHERE session_id=? AND encounter_id=? AND source_entity_turn_id=0',[$sid,$eid])['rows'];
        $recorded=array_map('strval',array_column($rows,'prepared_action_id'));
        if(count($prepared)!==count($rows))throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
        foreach($prepared as $p)if(!in_array((string)$p['id'],$recorded,true))throw new RuntimeException('ACS_PRE_ROUND_INTEGRITY');
        return ['rows'=>$rows,'totals'=>$totals];
    }
    public function preRoundBudget(string $sid,string $group): int
    {
        $g=$this->clockGroup($sid,$group);if(!$g||json_decode($g['config_json'],true)!==['schema_version'=>1])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');return 3;
    }
    public function preRoundQuota(array $draft): int
    {
        $a=json_decode($draft['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions'][0]??null;
        if(!$a)throw new RuntimeException('ACS_PRE_ROUND_UNAVAILABLE');$e=$this->entity($draft['session_id'],$a['actor_id']);
        $row=$this->sql('SELECT COALESCE(SUM(s.ap_cost),0) AS qty FROM '.$this->table('sealed_actions').' s JOIN '.$this->table('drafts').' d ON d.id=s.draft_id JOIN '.$this->table('entities').' e ON e.id=s.actor_entity_id WHERE d.encounter_id=? AND d.turn_id=0 AND e.control_group_id=?',[$draft['encounter_id'],$e['control_group_id']])['rows'][0];return (int)$row['qty'];
    }
    private function addPreRoundCommitment(array $session,array $encounter,array $entity,array $seal,string $prepared,int $ap,array $pin): void
    {
        $this->commandWrite();
        // registerPrepared already checked the locked group total before inserting its prepared row.
        if($ap<1||$ap>$this->preRoundBudget($session['id'],$entity['control_group_id']))throw new RuntimeException('ACS_PRE_ROUND_OVERCOMMIT');
        $this->sql('INSERT INTO '.$this->table('pre_round_commitments').' (session_id,encounter_id,entity_id,control_group_id,prepared_action_id,source_sealed_action_id,operation_key,paid_ap,ability_pin_json) VALUES (?,?,?,?,?,?,?,?,?)',[$session['id'],$encounter['id'],$entity['id'],$entity['control_group_id'],$prepared,$seal['id'],'pre-round:seal:'.$seal['id'],$ap,self::json(['schema_version'=>1,'pin'=>$pin])]);
    }
    /** Existing RoundManagement closing protocol; no fake resolved state or turn reopen. */
    public function closeFirstRoundExhausted(array $session,string $actor): array
    {
        $this->commandWrite();$result=$session['_command_result'];$eid=$result['encounter_id'];$closed=[];
        $encounter=$this->encounter($session['id'],$eid);$round=$this->currentRound($encounter);
        foreach($this->allRoundTurns($round['id']) as $turn)if($turn['status']==='pending'&&(int)$turn['pre_round_ap_commitment']>0&&AF_ACS_Clock::availableAP((int)$turn['ap_budget'],(int)$turn['ap_spent'],(int)$turn['pre_round_ap_commitment'])===0){
            $session=$this->closeTurn($session,$encounter,$this->clockTurn($session['id'],$turn['id']),'passed',$actor,true);
            $closed[]=['turn_id'=>$turn['id'],'boundary'=>$session['_command_result']['boundary_key'],'individual_boundaries'=>$session['_command_result']['individual_boundaries']];
            $session['_command_result']=$result;
        }
        $session['_command_result']=$result+['exhausted_groups'=>$closed];return $session;
    }
}
