<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Shared existing transaction/append-only ledger, not a second database or clock scheduler. */
trait AF_ACS_ClockPersistence
{
    public function boundary(string $key,string $sid): ?array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? AND session_id=?',[self::key($key),$sid])['rows'][0]??null;
        if(!$row)return null;
        if($row['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        return json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
    }
    private function recordBoundary(string $key,string $kind,string $sid,?string $eid,string $actor,array $request,array $result): void
    {
        $this->commandWrite();$request=['schema_version'=>1,'actor_uid'=>$actor]+$request;$result=['schema_version'=>1,'session_id'=>$sid,'boundary_key'=>$key]+$result;$json=self::json($request);
        $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$sid,$eid,$kind,hash('sha256',$json),'committed',$json,self::json($result),time()]);
    }
    public function clockTurn(string $sid,string $id): ?array
    {
        $r=$this->sql('SELECT t.*,r.encounter_id,r.round_number,r.status AS round_status,e.status AS encounter_status,e.round_number AS current_round,g.owner_uid,g.kind AS group_kind FROM '.$this->table('turns').' t JOIN '.$this->table('rounds').' r ON r.id=t.round_id JOIN '.$this->table('encounters').' e ON e.id=r.encounter_id AND e.session_id=? JOIN '.$this->table('control_groups').' g ON g.id=t.control_group_id AND g.session_id=e.session_id WHERE t.id=?',[$sid,$id])['rows'][0]??null;
        if($r)foreach(['id','round_id','encounter_id','round_number','current_round','control_group_id','owner_uid','turn_version'] as $field)if($r[$field]!==null)$r[$field]=(string)$r[$field];return $r;
    }
    public function clockEntityTurn(string $sid,string $id): ?array
    {
        $r=$this->sql('SELECT x.*,t.round_id,t.control_group_id,t.status AS turn_status,t.roster_json,r.encounter_id,r.round_number,r.status AS round_status,e.status AS encounter_status,e.round_number AS current_round FROM '.$this->table('entity_turns').' x JOIN '.$this->table('turns').' t ON t.id=x.turn_id JOIN '.$this->table('rounds').' r ON r.id=t.round_id JOIN '.$this->table('encounters').' e ON e.id=r.encounter_id AND e.session_id=? WHERE x.id=?',[$sid,$id])['rows'][0]??null;
        if($r)foreach(['id','turn_id','entity_id','entity_turn_version','round_id','control_group_id','encounter_id','round_number','current_round'] as $field)$r[$field]=(string)$r[$field];return $r;
    }
    public function cooldowns(string $sid,?string $entity=null,string $after='0'): array
    {
        $args=[$sid,self::integer($after,zero:true)];$where='e.session_id=? AND c.id>?';if($entity!==null){$where.=' AND c.entity_id=?';$args[]=$entity;}
        $rows=$this->sql('SELECT c.*,e.name FROM '.$this->table('cooldowns').' c JOIN '.$this->table('entities').' e ON e.id=c.entity_id WHERE '.$where.' ORDER BY c.id LIMIT 100',$args)['rows'];
        foreach($rows as &$r)foreach(['id','entity_id','cooldown_version','remaining_turns'] as $f)$r[$f]=(string)$r[$f];unset($r);return $rows;
    }
    public function allCooldowns(string $sid,?string $entity=null): iterable
    {
        $after='0';do{$rows=$this->cooldowns($sid,$entity,$after);foreach($rows as $row){$after=$row['id'];yield $row;}}while(count($rows)===100);
    }
    public function clockRound(string $sid,string $id): ?array
    {
        return $this->sql('SELECT r.* FROM '.$this->table('rounds').' r JOIN '.$this->table('encounters').' e ON e.id=r.encounter_id WHERE e.session_id=? AND r.id=?',[$sid,$id])['rows'][0]??null;
    }
    /** Immutable provenance prepared by the future confirmed-action producer, never by the UI. */
    public function cooldownSource(string $sid,array $cooldown): array
    {
        $key='cooldown:'.$cooldown['id'];
        $row=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND source_system=? AND source_key=? AND contract_id=? AND contract_version=? AND schema_version=1 ORDER BY id DESC LIMIT 1',[$sid,'acs',$key,'ic-acs.cooldown-source.v1','1'])['rows'][0]??null;
        if(!$row || !hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');
        $p=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($p))throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');
        foreach(['initial_turns','applied_entity_turn_id','origin_encounter_id','confirmation_event_id'] as $required)if(!array_key_exists($required,$p))throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');
        foreach(['applied_entity_turn_id','origin_encounter_id','confirmation_event_id'] as $idField)self::integer($p[$idField]);
        if(($p['engine_version']??null)!=='acs-clock-resources-1' || ($p['numeric_policy']??null)!=='binary64-half_up-v1' || ($p['schema_version']??null)!==1 || (string)($p['entity_id']??'')!==$cooldown['entity_id'] || (string)($p['cooldown_id']??'')!==$cooldown['id'] || ($p['scope']??null)!==$cooldown['scope'] || !in_array($cooldown['scope'],['combat','session'],true) || ($p['ability_key']??null)!==$cooldown['ability_key'] || ($p['ability_ref']['definition_version']['fingerprint']??null)!==($p['ability_ref']['fingerprint']??null) || ($p['group_key']??null)!==$cooldown['group_key'] || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',(string)($p['ability_ref']['fingerprint']??'')) || !is_array($p['ability_ref']['definition_version']??null))throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');
        $proof=$this->sql('SELECT e.source_entity_id,e.encounter_id,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key AND o.session_id=e.session_id AND o.encounter_id=e.encounter_id AND o.status='committed' WHERE e.id=? AND e.session_id=?",[self::integer($p['confirmation_event_id']??null),$sid])['rows'][0]??null;
        if($proof && !hash_equals($proof['content_hash'],hash('sha256',$proof['payload_json']."\n".$proof['visibility_json'])))throw new RuntimeException('ACS_COOLDOWN_UNCONFIRMED');
        $confirmed=$proof?json_decode($proof['payload_json'],true,32,JSON_THROW_ON_ERROR)['confirmed_action']??null:null;
        if(!$proof || (string)$proof['source_entity_id']!==$cooldown['entity_id'] || (string)$proof['encounter_id']!==(string)($p['origin_encounter_id']??'') || !is_array($confirmed) || ($confirmed['schema_version']??null)!==1 || ($confirmed['ability_ref']??null)!==$p['ability_ref'] || (string)($confirmed['entity_turn_id']??'')!==(string)($p['applied_entity_turn_id']??'') || ($confirmed['cooldown']??null)!==['ability_key'=>$p['ability_key'],'group_key'=>$p['group_key'],'scope'=>$p['scope'],'turns'=>$p['initial_turns']])throw new RuntimeException('ACS_COOLDOWN_UNCONFIRMED');
        self::integer($p['initial_turns'],'2147483647',true);self::integer($cooldown['remaining_turns'],'2147483647',true);
        if((int)$cooldown['remaining_turns']>(int)$p['initial_turns'])throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');return $p;
    }
    public function processEntityStart(array $session,array $encounter,array $individual,string $actor): array
    {
        $this->commandWrite();$key=AF_ACS_Clock::entityStart($individual['id']);$changed=[];
        if((string)$individual['start_done']!=='0' || (string)$individual['end_done']!=='0')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        foreach($this->allCooldowns($session['id'],$individual['entity_id']) as $cooldown) {
            $source=$this->cooldownSource($session['id'],$cooldown);
            if((string)$source['applied_entity_turn_id']===$individual['id'] || $cooldown['last_boundary_key']===$key)continue;
            $applied=$this->clockEntityTurn($session['id'],self::integer($source['applied_entity_turn_id']));
            if(!$applied || strlen($applied['id'])>strlen($individual['id']) || strlen($applied['id'])===strlen($individual['id']) && strcmp($applied['id'],$individual['id'])>=0 || $applied['entity_id']!==$individual['entity_id'] || (int)$applied['round_number']>=(int)$individual['round_number'] && $applied['encounter_id']===$individual['encounter_id'])throw new RuntimeException('ACS_COOLDOWN_SOURCE_UNAVAILABLE');
            if($cooldown['scope']==='combat' && (string)$source['origin_encounter_id']!==$encounter['id'])throw new RuntimeException('ACS_COMBAT_RESET_REQUIRED');
            $next=max(0,(int)$cooldown['remaining_turns']-1);self::integer($cooldown['cooldown_version'],'18446744073709551614');
            $this->sql('UPDATE '.$this->table('cooldowns').' SET remaining_turns=?,last_boundary_key=?,cooldown_version=cooldown_version+1 WHERE id=? AND cooldown_version=?',[$next,$key,$cooldown['id'],$cooldown['cooldown_version']]);
            $changed[]=['cooldown_id'=>$cooldown['id'],'before'=>$cooldown['remaining_turns'],'after'=>(string)$next,'ability_ref'=>$source['ability_ref']];
        }
        self::integer($individual['entity_turn_version'],'18446744073709551614');
        if($this->sql('UPDATE '.$this->table('entity_turns').' SET start_done=1,entity_turn_version=entity_turn_version+1 WHERE id=? AND entity_turn_version=? AND start_done=0',[$individual['id'],$individual['entity_turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $this->recordBoundary($key,'clock.entity_start',$session['id'],$encounter['id'],$actor,['entity_turn_id'=>$individual['id']],['entity_turn_id'=>$individual['id'],'cooldowns'=>$changed,'parent_encounter_sequence'=>$encounter['next_event_sequence']]);
        return ['boundary_key'=>$key,'entity_turn_id'=>$individual['id'],'cooldowns'=>$changed];
    }
    public function applyEntityStart(array $session,array $encounter,array $individual,string $actor): array
    {
        $result=$this->processEntityStart($session,$encounter,$individual,$actor);
        $session=$this->change($session,$encounter,'clock_boundary',[]);$session['_command_result']+=$result;return $session;
    }
    public function restWindow(string $sid): ?array
    {
        if($this->sql('SELECT id FROM '.$this->table('encounters')." WHERE session_id=? AND status IN ('active','paused','ambush_ready','pending_resolution') LIMIT 1",[$sid])['rows'])throw new RuntimeException('ACS_REST_COMBAT_ACTIVE');
        $row=$this->sql('SELECT id,completed_at FROM '.$this->table('encounters')." WHERE session_id=? AND status='completed' AND completed_at IS NOT NULL ORDER BY completed_at DESC,id DESC LIMIT 1",[$sid])['rows'][0]??null;
        return $row?['encounter_id'=>(string)$row['id'],'completed_at'=>(string)$row['completed_at']]:null;
    }
    public function restGroup(string $sid,string $group): array
    {
        $g=$this->sql('SELECT * FROM '.$this->table('control_groups').' WHERE session_id=? AND id=?',[$sid,$group])['rows'][0]??null;
        if(!$g || !in_array($g['kind'],['player','independent'],true) || ($g['kind']==='player')!==($g['owner_uid']!==null) || json_decode($g['config_json'],true)!==['schema_version'=>1])throw new RuntimeException('ACS_REST_POLICY_UNAVAILABLE');
        return $g;
    }
    public function restEntities(string $sid,string $group): array
    {
        return $this->sql('SELECT e.*,s.life_state,s.hp,s.hp_max,s.state_version,s.layers_json,(SELECT p.participation FROM '.$this->table('participants').' p JOIN '.$this->table('encounters').' c ON c.id=p.encounter_id WHERE p.entity_id=e.id ORDER BY c.created_at DESC,c.id DESC LIMIT 1) AS last_participation FROM '.$this->table('entities').' e JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id WHERE e.session_id=? AND e.control_group_id=? ORDER BY e.id',[$sid,$group])['rows'];
    }
    public function entityResources(string $id): array
    {
        return $this->sql('SELECT * FROM '.$this->table('resources').' WHERE entity_id=? ORDER BY id',[$id])['rows'];
    }
    public function applyRest(array $session,array $request): array
    {
        $this->commandWrite();$sid=$session['id'];$window=$this->restWindow($sid);
        $full=$request['rest_kind']==='full';
        if(!$window && !$full)throw new RuntimeException('ACS_REST_WINDOW_UNAVAILABLE');
        $window??=['encounter_id'=>'0'];
        $group=$this->restGroup($sid,$request['control_group_id']);
        $marker='rest:'.$sid.':'.$group['id'].':'.$window['encounter_id'].':short';
        if(!$full && $this->boundary($marker,$sid))throw new RuntimeException('ACS_REST_LIMIT');
        $config=json_decode($session['config_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($config) || ($config['schema_version']??null)!==1)throw new RuntimeException('ACS_REST_POLICY_UNAVAILABLE');
        foreach($config as $k=>$v)if(in_array($k,['rest','rest_policy','rest_rules','rest_defaults'],true))throw new RuntimeException('ACS_REST_POLICY_UNAVAILABLE');
        $changed=[];$skipped=[];
        $members=$this->restEntities($sid,$request['control_group_id']);
        if($group['kind']==='independent' && count($members)!==1)throw new RuntimeException('ACS_REST_POLICY_UNAVAILABLE');
        foreach($members as $entity) {
            $id=(string)$entity['id'];
            if(!in_array($entity['kind'],$group['kind']==='player'?['character','pet']:['npc','monster'],true) || (string)$entity['owner_uid']!==(string)$group['owner_uid'])throw new RuntimeException('ACS_REST_POLICY_UNAVAILABLE');
            if($entity['life_state']==='dead' || in_array($entity['last_participation'],['escaped','withdrawn','captured'],true)){$skipped[]=['entity_id'=>$id,'reason'=>'dead_or_presence_unverified'];continue;}
            if(json_decode($entity['layers_json'],true)!==['schema_version'=>1,'layers'=>[]])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
            $hp=self::integer((string)$entity['hp'],'9007199254740991',true);$max=self::integer((string)$entity['hp_max'],'9007199254740991',true);
            $next=AF_ACS_Clock::restHP((int)$hp,(int)$max,$full);$hpResource=null;
            foreach($this->entityResources($id) as $resource) {
                if($resource['resource_key']!=='hp'){$skipped[]=['entity_id'=>$id,'resource_key'=>$resource['resource_key'],'reason'=>'resource_rest_contract_unavailable'];continue;}
                if($hpResource!==null || (float)$resource['current_value']!==(float)$hp || (float)$resource['maximum_value']!==(float)$max || (int)$resource['is_integer']!==1)throw new RuntimeException('ACS_RESOURCE_STATE_CONFLICT');$hpResource=$resource;
            }
            if(!$hpResource)throw new RuntimeException('ACS_RESOURCE_STATE_CONFLICT');
            self::integer((string)$entity['state_version'],'18446744073709551614');self::integer((string)$hpResource['resource_version'],'18446744073709551614');
            $life=$entity['life_state']==='incapacitated' && $next>0?'active':$entity['life_state'];
            if($this->sql('UPDATE '.$this->table('entity_states').' SET hp=?,life_state=?,state_version=state_version+1 WHERE entity_id=? AND state_version=?',[$next,$life,$id,$entity['state_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',[$next,$hpResource['id'],$hpResource['resource_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $changed[]=['entity_id'=>$id,'before'=>$hp,'maximum'=>$max,'after'=>(string)$next,'resource_id'=>(string)$hpResource['id'],'source_snapshot_id'=>(string)$entity['template_snapshot_id']];
        }
        if(!$changed)throw new RuntimeException('ACS_REST_NO_TARGETS');
        $result=['control_group_id'=>$request['control_group_id'],'rest_kind'=>$request['rest_kind'],'window_encounter_id'=>$window['encounter_id'],'policy'=>'baseline-a1.7-a1.9-v1','resources'=>$changed,'skipped'=>$skipped];
        if(!$full)$this->recordBoundary($marker,'session.rest_limit',$sid,null,$request['actor_uid'],['control_group_id'=>$request['control_group_id'],'window'=>$window['encounter_id']],$result);
        $session['_command_result']=$result;return $session;
    }
    public function restHistory(string $sid): array
    {
        return $this->sql('SELECT e.id,e.event_type,e.actor_uid,e.created_at,o.result_json FROM '.$this->table('events').' e JOIN '.$this->table('operations')." o ON o.operation_key=e.operation_key AND o.status='committed' WHERE e.session_id=? AND e.event_type IN ('session.rest_short','session.rest_full') ORDER BY e.id DESC LIMIT 20",[$sid])['rows'];
    }    public function clockGroup(string $sid,string $id): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('control_groups').' WHERE session_id=? AND id=?',[$sid,$id])['rows'][0]??null;
    }
    public function restGroups(string $sid): array
    {
        return $this->sql('SELECT id,owner_uid,kind FROM '.$this->table('control_groups')." WHERE session_id=? AND kind IN ('player','independent') ORDER BY id LIMIT 100",[$sid])['rows'];
    }
    /** Internal round-open boundary, before any start/upkeep. No public reset/spend endpoint. */
    public function initializeRoundAP(string $sid,string $eid,string $roundId,string $actor,string $sequence): array
    {
        $this->commandWrite();$key=AF_ACS_Clock::apRefresh($roundId);
        if($old=$this->boundary($key,$sid))return $old;
        $round=$this->clockRound($sid,$roundId);
        if(!$round || (string)$round['encounter_id']!==$eid || $round['status']!=='open')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $after='0';$count=0;
        do {
            $turns=$this->roundTurns($roundId,$after);
            foreach($turns as $turn) {
                if($turn['status']!=='pending' || $turn['ap_budget']!=='0' || $turn['ap_spent']!=='0' || $turn['seal_quota_spent']!=='0' || $turn['pre_round_ap_commitment']!=='0')throw new RuntimeException('ACS_AP_STATE_INVALID');
                $group=$this->clockGroup($sid,$turn['control_group_id']);
                if(!$group || json_decode($group['config_json'],true)!==['schema_version'=>1])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
                foreach($this->individualTurns([$turn['id']]) as $individual) {
                    if($individual['start_done']!=='0' || $individual['end_done']!=='0')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
                    $entity=$this->entity($sid,$individual['entity_id']);
                    if(!$entity || json_decode($entity['layers_json'],true)!==['schema_version'=>1,'layers'=>[]])throw new RuntimeException('ACS_CLOCK_DEPENDENCY_BLOCKED');
                }
                self::integer($turn['turn_version'],'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('turns').' SET ap_budget=3,turn_version=turn_version+1 WHERE id=? AND turn_version=?',[$turn['id'],$turn['turn_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
                $after=$turn['id'];++$count;
            }
        }while(count($turns)===100);
        if(!$count)throw new RuntimeException('ACS_ROSTER_INVALID');
        $this->recordBoundary($key,'clock.ap_refresh',$sid,$eid,$actor,['round_id'=>$roundId],['round_id'=>$roundId,'initial_budget'=>3,'turn_count'=>(string)$count,'parent_encounter_sequence'=>$sequence]);
        return $this->boundary($key,$sid);
    }
    /** A pre-005 roster already initialized AP: acknowledge its confirmed event, never refill. */
    public function acknowledgeAPRefresh(array $session,array $encounter,string $roundId,string $actor): array
    {
        $this->commandWrite();$round=$this->firstRound($encounter['id']);$key=AF_ACS_Clock::apRefresh($roundId);
        if(!$round || $round['id']!==$roundId || $encounter['status']!=='active')throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $events=$this->sql('SELECT o.result_json FROM '.$this->table('operations')." o WHERE o.session_id=? AND o.encounter_id=? AND o.kind='encounter.round_start' AND o.status='committed'",[$session['id'],$encounter['id']])['rows'];$proof=false;
        foreach($events as $event){$p=json_decode($event['result_json'],true);if(($p['round_id']??null)===$roundId && ($p['ap_refresh_boundary']??null)===$key)$proof=true;}
        if(!$proof)throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $this->recordBoundary($key,'clock.ap_refresh',$session['id'],$encounter['id'],$actor,['round_id'=>$roundId],['round_id'=>$roundId,'already_applied'=>true]);
        $session=$this->change($session,$encounter,'clock_boundary',[]);$session['_command_result']+=['boundary_key'=>$key,'already_applied'=>true];return $session;
    }
    public function resetCombatCooldowns(array $session,array $encounter,string $actor): array
    {
        $this->commandWrite();if($encounter['status']!=='completed' || $encounter['completed_at']===null)throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $key='encounter:'.$encounter['id'].':cooldowns_reset';$changed=[];
        foreach($this->allCooldowns($session['id']) as $row) {
            if($row['scope']!=='combat')continue;$source=$this->cooldownSource($session['id'],$row);if((string)$source['origin_encounter_id']!==$encounter['id'])continue;
            if($this->occupied($row['entity_id'],$encounter['id']))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
            self::integer($row['cooldown_version'],'18446744073709551614');
            $this->sql('UPDATE '.$this->table('cooldowns').' SET remaining_turns=0,last_boundary_key=?,cooldown_version=cooldown_version+1 WHERE id=? AND cooldown_version=?',[$key,$row['id'],$row['cooldown_version']]);$changed[]=$row['id'];
        }
        $this->recordBoundary($key,'clock.combat_reset',$session['id'],$encounter['id'],$actor,[],['cooldown_ids'=>$changed]);
        $session=$this->change($session,$encounter,'clock_boundary',[]);$session['_command_result']+=['boundary_key'=>$key,'cooldown_ids'=>$changed];return $session;
    }

}
