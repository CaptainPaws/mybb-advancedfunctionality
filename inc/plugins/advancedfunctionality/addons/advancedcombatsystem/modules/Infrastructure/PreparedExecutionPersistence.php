<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/PreparedExecutionAccess.php';
require_once __DIR__.'/../Domain/CausalGraph.php';
require_once __DIR__.'/RandomSource.php';

/** Durable causal seal first, mechanical execution only in the existing combat transaction. */
trait AF_ACS_PreparedExecutionPersistence
{
    private function preparedTriggerKey(array $parent,array $seal,array $row):string
    {
        $contract=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);$phase=$contract['execution']['phase']??'unsupported';
        // Retain identities of already armed 9.1.2 after_damage commitments across deployment/recovery.
        $suffix=in_array($phase,['after_damage','unsupported'],true)?'':':phase:'.$phase;
        return 'prepared-trigger:'.hash('sha256',$parent['submission_id'].':'.$seal['id'].':'.$row['entity_id'].$suffix);
    }
    private function armPreparedCausal(array $parent,array $frozen):void
    {
        $candidates=$this->sql('SELECT id,entity_id,contract_json FROM '.$this->table('prepared_actions')." WHERE session_id=? AND encounter_id=? AND status='active'",[$parent['session_id'],$parent['encounter_id']])['rows'];$needs=false;
        foreach($frozen['seals'] as $seal)foreach($candidates as $candidate)if(in_array((string)$candidate['entity_id'],$seal['payload']['action']['targets'],true)){
            $key=$this->preparedTriggerKey($parent,$seal,$candidate);
            if(!$this->sql('SELECT x.id FROM '.$this->table('prepared_executions').' x JOIN '.$this->table('events').' e ON e.id=x.trigger_event_id WHERE x.prepared_action_id=? AND e.operation_key=?',[$candidate['id'],$key])['rows'])$needs=true;
        }
        if(!$needs)return;
        $this->transactions->run(function()use($parent,$frozen){
            $this->writable();$session=$this->lockSession($parent['session_id']);
            $current=$this->submissions->submission($parent['submission_id']);if($this->terminal($current))return;
            if($current!==$parent||$current['status']!=='post_confirmed'||$current['author_uid']!==$this->access->uid())throw new RuntimeException('ACS_COMBAT_VERSION_CONFLICT');
            $enc=$this->preparation->encounter($parent['session_id'],$parent['encounter_id'],true);
            $rows=$this->sql('SELECT p.*,e.actor_uid AS originating_uid,e.session_sequence AS registered_order FROM '.$this->table('prepared_actions').' p JOIN '.$this->table('events')." e ON e.id=p.source_event_id WHERE p.session_id=? AND p.encounter_id=? AND p.status='active' ORDER BY p.id FOR UPDATE",[$parent['session_id'],$parent['encounter_id']])['rows'];
            foreach($frozen['seals'] as $index=>$seal)foreach($rows as $row){
                if(!in_array((string)$row['entity_id'],$seal['payload']['action']['targets'],true))continue;
                if(!hash_equals($row['contract_hash'],hash('sha256',$row['contract_json'])))throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
                $contract=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);$policy=null;$blocked=null;
                try{$policy=AF_ACS_PreparedAction::execution($contract['execution']??null);}catch(DomainException $e){$blocked='ACS_PREPARED_UNSUPPORTED';}
                $key=$this->preparedTriggerKey($parent,$seal,$row);
                $event=$this->sql('SELECT id FROM '.$this->table('events').' WHERE session_id=? AND operation_key=?',[$parent['session_id'],$key])['rows'][0]??null;
                if(!$event){
                    $session=$this->preparation->session($parent['session_id']);$enc=$this->preparation->encounter($parent['session_id'],$parent['encounter_id']);
                    $eventId=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,encounter_id,event_sequence,operation_key,event_type,source_entity_id,target_entity_id,actor_uid,submission_id,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$parent['session_id'],$session['next_sequence'],$parent['encounter_id'],$enc['next_event_sequence'],$key,'prepared.trigger_sealed',$seal['payload']['action']['actor_id'],$row['entity_id'],$parent['author_uid'],$parent['submission_id'],AF_ACS_Resolver::VERSION,time()])['id'];
                    $payload=self::json(['schema_version'=>1,'phase'=>'causal_seal','trigger_phase'=>$policy['phase']??null,'mechanics_confirmed'=>false,'submission_id'=>$parent['submission_id'],'source_sealed_action_id'=>$seal['id'],'source_sealed_hash'=>$seal['sealed_hash'],'action_index'=>$index,'target_entity_id'=>(string)$row['entity_id']]);$visibility=self::json(['schema_version'=>1,'scope'=>'encounter_management']);
                    $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$eventId,AF_ACS_Numeric::POLICY,$payload,$visibility,hash('sha256',$payload."\n".$visibility)]);
                    // Metadata-only causal sealing, like SealCommand: no mechanical version change.
                    $this->sql('UPDATE '.$this->table('sessions').' SET next_sequence=next_sequence+1 WHERE id=?',[$parent['session_id']]);$this->sql('UPDATE '.$this->table('encounters').' SET next_event_sequence=next_event_sequence+1 WHERE id=?',[$parent['encounter_id']]);
                    $request=self::json(['schema_version'=>1,'submission_id'=>$parent['submission_id'],'source_sealed_action_id'=>$seal['id'],'target_entity_id'=>(string)$row['entity_id']]);
                    $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$parent['session_id'],$parent['encounter_id'],'prepared.causal_seal',hash('sha256',$request),'committed',$request,self::json(['schema_version'=>1,'event_id'=>$eventId,'mechanics_confirmed'=>false]),time()]);
                    $event=['id'=>$eventId];
                }
                $eventId=(string)$event['id'];
                if($this->sql('SELECT id FROM '.$this->table('prepared_executions').' WHERE prepared_action_id=? AND trigger_event_id=?',[$row['id'],$eventId])['rows'])continue;
                $identity='prepared:'.$row['id'].':trigger:'.$eventId;$tape=$blocked?null:(new AF_ACS_RandomSource)->action();
                $data=['schema_version'=>1,'identity'=>$identity,'submission_id'=>$parent['submission_id'],'action_index'=>$index,'source_sealed_action_id'=>$seal['id'],'prepared_source_sealed_action_id'=>(string)$row['source_sealed_action_id'],'trigger_event_id'=>$eventId,'prepared_action_id'=>(string)$row['id'],'prepared_version'=>(string)$row['prepared_version'],'contract_hash'=>$row['contract_hash'],'originating_uid'=>(string)$row['originating_uid'],'registered_order'=>(string)$row['registered_order'],'source_event_id'=>(string)$row['source_event_id'],'policy'=>$policy,'blocked'=>$blocked,'tape'=>$tape];
                if($tape)foreach(['hit','crit'] as $kind)$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,NULL,?,?,0,?,?,?)',[$parent['session_id'],$identity,$kind,'uniform53-v1',self::json(['schema_version'=>1]+$tape[$kind]),time()]);
                $this->sql('INSERT INTO '.$this->table('prepared_executions').' (prepared_action_id,trigger_event_id,operation_key,status,result_json) VALUES (?,?,?,?,?)',[$row['id'],$eventId,$identity,'armed',self::json($data)]);
            }
        });
    }
    private function causalCandidates(array $parent):array
    {
        $rows=$this->sql('SELECT x.*,p.session_id,p.encounter_id,p.entity_id,p.owner_uid,p.control_group_id,p.source_sealed_action_id,p.source_entity_turn_id,p.source_event_id,p.status AS prepared_status,p.prepared_version,p.expires_boundary,p.cost_state,p.contract_hash,p.contract_json FROM '.$this->table('prepared_executions').' x JOIN '.$this->table('prepared_actions').' p ON p.id=x.prepared_action_id WHERE p.session_id=? AND p.encounter_id=? AND JSON_UNQUOTE(JSON_EXTRACT(x.result_json,\'$.submission_id\'))=? ORDER BY x.id FOR UPDATE',[$parent['session_id'],$parent['encounter_id'],$parent['submission_id']])['rows'];
        foreach($rows as &$r){$r['armed']=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);$r['execution_id']=(string)$r['id'];$r['id']=(string)$r['prepared_action_id'];foreach(['session_id','encounter_id','entity_id','source_sealed_action_id','source_entity_turn_id','source_event_id','prepared_version'] as $f)$r[$f]=(string)$r[$f];}unset($r);
        usort($rows,static fn($a,$b)=>($a['armed']['policy']['priority']??0)<=>($b['armed']['policy']['priority']??0)?:(strlen($a['armed']['registered_order'])<=>strlen($b['armed']['registered_order'])?:strcmp($a['armed']['registered_order'],$b['armed']['registered_order']))?:(strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id'])));return $rows;
    }
    private function causalInspection(array $candidate,array $parent):array
    {
        $uid=$candidate['armed']['originating_uid'];$sid=$candidate['session_id'];$actor=$candidate['entity_id'];$p=json_decode($candidate['contract_json'],true,64,JSON_THROW_ON_ERROR);
        $origin=$this->sql('SELECT actor_uid FROM '.$this->table('events').' WHERE id=? AND session_id=? AND encounter_id=? AND event_type=?',[$candidate['source_event_id'],$sid,$candidate['encounter_id'],'encounter.prepared_register'])['rows'][0]??null;if(!$origin||(string)$origin['actor_uid']!==$uid||$candidate['source_event_id']!==$candidate['armed']['source_event_id'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $seal=$this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE id=?',[$candidate['source_sealed_action_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');$source=$this->preparation->sealPayload($seal);$this->preparation->verifySealDefinition($sid,$seal,$source);
        if($seal['sealed_hash']!==$p['source_sealed_hash']||(string)$seal['definition_snapshot_id']!==$p['definition_snapshot_id']||$source['action']['actor_id']!==$actor||$source['action']['ability_key']!==$p['ability_pin']['key']||$source['action']['targets']!==[$p['target_entity_id']])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $entity=$this->preparation->entity($sid,$actor);if(!$entity||$entity['control_group_id']!==(string)$candidate['control_group_id'])throw new RuntimeException('ACS_CONTROL_INVALID');
        $readAccess=new AF_ACS_PreparedExecutionAccess($this->access,$uid);$readAccess->draftThread($this->preparation->session($sid)['tid']);
        if(!$this->preparation->roles($sid,$uid)||!($grant=$this->preparation->controllerFor($sid,$actor,$uid)))throw new RuntimeException('ACS_CONTROL_INVALID');
        $key=$p['ability_pin']['key'];AF_ACS_Control::action($grant,$key);
        $q=new AF_ACS_QueryService($this->preparation,$readAccess);$inspection=$q->inspect($sid,$candidate['encounter_id'],$actor,$p['target_entity_id'],null,false,true);
        $ability=$inspection['context']['abilities'][$actor][$key]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        if($ability['source_ref']!==$p['ability_pin']||($ability['prepared_action']['execution']??null)!==$candidate['armed']['policy'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        unset($ability['prepared_action']);$ability['costs']=[];
        foreach($p['additional_costs'] as $cost)if($cost['kind']==='resource'&&$cost['timing']==='on_trigger')$ability['costs'][]=['kind'=>'resource','resource'=>$cost['key'],'amount'=>$cost['amount']];
        $context=$inspection['context'];$context['abilities']=[$actor=>[$key=>$ability]];$context['reactions']=[];$context['entity_end_boundaries']=[];
        $context['prepared_execution']=['actor_id'=>$actor,'ability_key'=>$key,'targets'=>[$p['target_entity_id']],'policy'=>$candidate['armed']['policy']];
        $ordered=[['actor_id'=>$actor,'ability_key'=>$key,'targets'=>[$p['target_entity_id']]]];
        return ['context'=>$context,'ordered'=>$ordered,'contract'=>$p];
    }
    /** Expand the existing virtual state before resolving; no second projection or battle store. */
    private function preparedPhase(array $parent,array $frozen,array &$context):?AF_ACS_PreparedPhase
    {
        $candidates=$this->causalCandidates($parent);$entries=[];$diagnostics=[];
        $pending=$this->sql('SELECT id,entity_id FROM '.$this->table('prepared_actions')." WHERE session_id=? AND encounter_id=? AND status='active'",[$parent['session_id'],$parent['encounter_id']])['rows'];
        foreach($pending as $p)foreach($frozen['seals'] as $i=>$seal)if(in_array((string)$p['entity_id'],$seal['payload']['action']['targets'],true)){
            $found=false;foreach($candidates as $c)if($c['id']===(string)$p['id']&&$c['armed']['action_index']===$i)$found=true;
            if(!$found)throw new RuntimeException('ACS_PREPARED_CAUSAL_UNSEALED');
        }
        foreach($candidates as $candidate){
            $armed=$candidate['armed'];$policy=$armed['policy'];
            if(!$policy){
                if($candidate['status']!=='armed'||!hash_equals($candidate['contract_hash'],hash('sha256',$candidate['contract_json'])))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
                $diagnostics[]=$armed+['execution_id'=>$candidate['execution_id'],'phase_event'=>null,'terminal_prepared_version'=>$candidate['prepared_version'],'terminal_cost_state'=>$candidate['cost_state'],'outcome'=>'skipped','reason'=>'ACS_PREPARED_UNSUPPORTED','costs'=>[],'owner_receipts'=>[],'mechanical_deltas'=>[],'causal_trace'=>[],'resolver_plan'=>null,'final_state'=>null,'terminal_state'=>$candidate['prepared_status']];continue;
            }
            AF_ACS_PreparedAction::execution($policy);
            $seal=$frozen['seals'][$armed['action_index']]??throw new RuntimeException('ACS_SEAL_TAPE_INVALID');$primary=$seal['payload']['action'];
            $contract=json_decode($candidate['contract_json'],true,64,JSON_THROW_ON_ERROR);
            if($armed['source_sealed_action_id']!==$seal['id']||$armed['prepared_source_sealed_action_id']!==$candidate['source_sealed_action_id']||$armed['prepared_action_id']!==$candidate['id']||$armed['trigger_event_id']!==(string)$candidate['trigger_event_id']||$armed['contract_hash']!==$candidate['contract_hash']||$policy!==($contract['execution']??null))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $trigger=$this->sql('SELECT e.*,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE e.id=?',[$candidate['trigger_event_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            if($trigger['event_type']!=='prepared.trigger_sealed'||(string)$trigger['submission_id']!==$parent['submission_id']||(string)$trigger['source_entity_id']!==$primary['actor_id']||(string)$trigger['target_entity_id']!==$candidate['entity_id']||$trigger['operation_key']!==$this->preparedTriggerKey($parent,$seal,$candidate)||!hash_equals($trigger['content_hash'],hash('sha256',$trigger['payload_json']."\n".$trigger['visibility_json'])))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $ability=$context['action_abilities'][$armed['action_index']]??($context['abilities'][$primary['actor_id']][$primary['ability_key']]??[]);
            if(empty($ability['components'])&&empty($ability['statuses'])&&empty($ability['removals'])||array_intersect(array_keys($ability),['prepared_action','item_action','spatial_action','spatial_effect','cover_action','spawns','awareness_action','object_interaction','cooperation']))throw new RuntimeException('ACS_PREPARED_TRIGGER_UNSUPPORTED');

            $inspection=null;$admission=null;
            try{$inspection=$this->causalInspection($candidate,$parent);}catch(Throwable $e){$admission=$this->causalCondition($e);}
            if($inspection){
                // QueryService already authenticated all retained definitions. Add only missing entities/groups;
                // existing virtual values will subsequently be supplied by the one Resolver pipeline.
                foreach(['entities','groups'] as $field)$context['state'][$field]+=$inspection['context']['state'][$field];
            }
            $entries[]=['queue_kind'=>'prepared','id'=>$candidate['id'],'priority'=>$policy['priority'],'registered_order'=>$armed['registered_order'],
                'identity'=>$armed['identity'],'phase'=>$policy['phase'],'action_index'=>$armed['action_index'],'subject'=>$candidate['entity_id'],
                'candidate'=>$candidate,'admission'=>$admission];
        }
        if(!$entries&&!$diagnostics)return null;
        return new AF_ACS_PreparedPhase($entries,function(array $entry,array $event,array $state)use($parent):array{return $this->executePreparedAtPhase($parent,$entry,$event,$state);},$diagnostics);
    }
    /** Integrity/unknown outcomes abort the entire parent transaction, never a mechanical skip. */
    private function causalCondition(Throwable $e):string
    {
        $code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
        if((!$e instanceof RuntimeException&&!$e instanceof DomainException)||$e instanceof mysqli_sql_exception
            ||in_array($code,['ACS_DATABASE_ERROR','ACS_ITEM_OWNER_RESULT_ORPHAN','ACS_SEAL_TAPE_INVALID','ACS_SEAL_PIN_CHANGED','ACS_SNAPSHOT_HASH_MISMATCH'],true)
            ||str_starts_with($code,'ACS_CAUSAL_')||str_starts_with($code,'INV_PREPARED_')&&!in_array($code,['INV_PREPARED_REVOKED','INV_PREPARED_EXPIRED','INV_PREPARED_VERSION_CONFLICT'],true))throw $e;
        return preg_match('/\A(?:ACS_[A-Z_]+|[a-z][a-z0-9_]+|INV_[A-Z_]+)\z/D',$code)?$code:'ACS_PREPARED_CONDITION_FAILED';
    }
    private function executePreparedAtPhase(array $parent,array $entry,array $event,array $state):array
    {
        $candidate=$entry['candidate'];$armed=$candidate['armed'];$policy=$armed['policy'];
        if(!hash_equals($candidate['contract_hash'],hash('sha256',$candidate['contract_json']))||$candidate['operation_key']!=='prepared:'.$candidate['id'].':trigger:'.$candidate['trigger_event_id']||$candidate['status']!=='armed')throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
        $live=$this->sql('SELECT status,prepared_version FROM '.$this->table('prepared_actions').' WHERE id=?',[$candidate['id']])['rows'][0];
        $candidate['prepared_status']=$live['status'];$candidate['prepared_version']=(string)$live['prepared_version'];
        $reason=$entry['admission'];$result=null;$receipts=[];$deltas=[];$graph=[];$finalState=null;$next=$state;
        if($reason===null&&($live['status']!=='active'||(string)$live['prepared_version']!==$armed['prepared_version']))$reason='preparation_unavailable';
        if($reason===null&&$event['resolved_target']!==$candidate['entity_id'])$reason='trigger_subject_changed';
        if($reason===null&&$policy['requires_hit']&&!$event['hit_success'])$reason='hit_required';
        if($reason===null){
            $identity=$armed['identity'];$graph=AF_ACS_CausalGraph::order(['event:'.$armed['trigger_event_id']=>['children'=>[$identity]],$identity=>['children'=>[]]],['event:'.$armed['trigger_event_id']],$policy['limits']['max_depth']);
            $this->causalSavepoint('start');
            try{
                $inspection=$this->causalInspection($candidate,$parent);$child=$inspection['context'];
                // Pure virtual effects from previous actions/reactions/prepared executions are authoritative.
                foreach(array_keys($child['state']['entities']) as $id)if(!isset($state['entities'][$id]))throw new RuntimeException('ACS_CAUSAL_ENTITY_UNSEALED');
                $child['state']=$state;
                if(($state['entities'][$inspection['ordered'][0]['targets'][0]]['life_state']??'dead')!=='active')throw new RuntimeException('ACS_PREPARED_TARGET_UNAVAILABLE');
                if(AF_ACS_Control::stateHash($state['status_instances']??[])!==AF_ACS_Control::stateHash($inspection['context']['state']['status_instances']??[]))throw new RuntimeException('ACS_PREPARED_VIRTUAL_CONTROL_UNSUPPORTED');
                $samples=$this->sql('SELECT check_kind,value_json FROM '.$this->table('rolls').' WHERE session_id=? AND causal_key=? ORDER BY check_kind',[$parent['session_id'],$identity])['rows'];$tape=[];
                foreach($samples as $sample){$v=json_decode($sample['value_json'],true,8,JSON_THROW_ON_ERROR);$tape[$sample['check_kind']]=['sample_n'=>$v['sample_n'],'u'=>$v['u']];}
                if(!isset($tape['hit'],$tape['crit'])||$tape['hit']!==$armed['tape']['hit']||$tape['crit']!==$armed['tape']['crit'])throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
                $resolved=AF_ACS_Resolver::resolve($child,$inspection['ordered'],[$armed['tape']],'seal');
                if($resolved->serverInitial()['groups']!==$resolved->serverState()['groups'])throw new RuntimeException('ACS_PREPARED_AP_CONFLICT');
                $candidate['status']=$candidate['prepared_status'];$receipts=(new AF_ACS_PreparedItemService($this->preparation,$this->access))->consumeTrigger($candidate,$armed['trigger_event_id']);
                // Re-read time-sensitive authorization after owner participation too.
                $this->causalInspection($candidate,$parent);
                $result=$resolved->serverPlan();$next=$resolved->serverState();$finalState=$next;
                foreach($next['entities'] as $id=>$entity)if($entity!==$state['entities'][$id])$deltas['entities'][(string)$id]=['before'=>$state['entities'][$id],'after'=>$entity];
            }catch(Throwable $e){$this->causalSavepoint('rollback');$reason=$this->causalCondition($e);$receipts=[];$deltas=[];$next=$state;}
            $this->causalSavepoint('release');
        }
        $outcome=$reason===null?'executed':'skipped';
        $terminal=$outcome==='executed'?'executed':($candidate['prepared_status']==='active'&&!str_contains($reason??'','UNSUPPORTED')&&$policy['on_unavailable']==='cancel'?'cancelled':$candidate['prepared_status']);
        if($candidate['prepared_status']==='active'&&$terminal!=='active'){
            if($this->sql('UPDATE '.$this->table('prepared_actions').' SET status=?,prepared_version=prepared_version+1,terminal_boundary=? WHERE id=? AND prepared_version=? AND status=?',[$terminal,'event:'.$armed['trigger_event_id'],$candidate['id'],$candidate['prepared_version'],'active'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($terminal==='cancelled'){$row=$candidate;$row['status']='cancelled';$receipts=array_merge($receipts,$this->preparation->settlePreparedItemsInCombat($row));}
        }
        $facts=$this->sql('SELECT prepared_version,cost_state FROM '.$this->table('prepared_actions').' WHERE id=?',[$candidate['id']])['rows'][0];
        $trace=$armed+['execution_id'=>$candidate['execution_id'],'phase_event'=>$event,'terminal_prepared_version'=>(string)$facts['prepared_version'],'terminal_cost_state'=>$facts['cost_state'],
            'outcome'=>$outcome,'reason'=>$reason,'costs'=>$result['actions'][0]['costs']??[],'owner_receipts'=>$receipts,'mechanical_deltas'=>$deltas,'causal_trace'=>$graph,'resolver_plan'=>$result,'final_state'=>$finalState,'terminal_state'=>$terminal];
        return ['state'=>$next,'trace'=>$trace];
    }
    private function finishPreparedPhases(?AF_ACS_PreparedPhase $phase,string $parentEvent):array
    {
        $trace=$phase?->traces()??[];
        foreach($trace as &$execution){$execution['parent_event_id']=$parentEvent;
            if($this->sql('UPDATE '.$this->table('prepared_executions').' SET status=?,result_json=? WHERE id=? AND status=?',[$execution['outcome'],self::json($execution),$execution['execution_id'],'armed'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        }unset($execution);return $trace;
    }
    private function causalSavepoint(string $phase):void
    {
        $query=match($phase){'start'=>'SAVEPOINT prepared_causal','rollback'=>'ROLLBACK TO SAVEPOINT prepared_causal','release'=>'RELEASE SAVEPOINT prepared_causal'};
        try{if(!$this->connection->query($query))throw new RuntimeException('ACS_DATABASE_ERROR');}catch(mysqli_sql_exception $e){throw new RuntimeException('ACS_DATABASE_ERROR',(int)$e->getCode());}
    }
}
