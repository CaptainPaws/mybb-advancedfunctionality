<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/PreparedExecutionSupport.php';
require_once __DIR__.'/EntityDeltaPersistence.php';
require_once __DIR__.'/RandomSource.php';
require_once __DIR__.'/../Domain/CausalGraph.php';

/** Encounter-start coordinator over existing Resolver, CAS projections and causal journal. */
trait AF_ACS_PreparedStartPersistence
{
    use AF_ACS_PreparedExecutionSupport, AF_ACS_EntityDeltaPersistence;
    private ?AF_ACS_SessionAccess $startAccess=null;
    private function preparedRepository():AF_ACS_EncounterRepository {return $this;}
    private function preparedAccess():AF_ACS_SessionAccess {return $this->startAccess??throw new RuntimeException('ACS_COMMAND_REQUIRED');}
    private function startCandidates(string $sid,string $eid):array
    {
        $rows=$this->sql('SELECT p.*,e.actor_uid AS originating_uid,e.session_sequence AS registered_order FROM '.$this->table('prepared_actions').' p JOIN '.$this->table('events')." e ON e.id=p.source_event_id WHERE p.session_id=? AND p.encounter_id=? AND p.status='active' ORDER BY p.id",[$sid,$eid])['rows'];
        foreach($rows as &$r){foreach(['id','session_id','encounter_id','entity_id','control_group_id','source_sealed_action_id','source_entity_turn_id','source_event_id','prepared_version','originating_uid','registered_order'] as $f)$r[$f]=(string)$r[$f];
            if(!hash_equals($r['contract_hash'],hash('sha256',$r['contract_json'])))throw new RuntimeException('ACS_SEAL_PIN_CHANGED');$c=json_decode($r['contract_json'],true,64,JSON_THROW_ON_ERROR);
            if(!array_key_exists('start_execution',$c))throw new RuntimeException('ACS_AMBUSH_EXECUTION_BLOCKED');
            $r['start_policy']=AF_ACS_PreparedAction::startExecution($c['start_execution']);
            if($r['source_entity_turn_id']!=='0'||$r['cost_state']!=='paid'||($c['pre_round_scope']['encounter_id']??null)!==$eid)throw new RuntimeException('ACS_AMBUSH_EXECUTION_BLOCKED');
        }unset($r);
        usort($rows,static fn($a,$b)=>$a['start_policy']['priority']<=>$b['start_policy']['priority']?:(strlen($a['registered_order'])<=>strlen($b['registered_order'])?:strcmp($a['registered_order'],$b['registered_order']))?:(strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id'])));return $rows;
    }
    private static function startDependencyHash(array $inspection):string
    {
        $v=$inspection['dependencies'];unset($v['session_version'],$v['encounter_version']);return hash('sha256',serialize($v));
    }
    private function armStartCausal(array $request,AF_ACS_SessionAccess $access,?AF_ACS_RandomSource $random):array
    {
        $this->startAccess=$access;$sid=$request['session_id'];$eid=$request['encounter_id'];$rows=$this->startCandidates($sid,$eid);
        if(!$rows)return ['schema_version'=>1,'entries'=>[],'trigger_event_id'=>null];
        $this->preRoundCommitments($sid,$eid);$session=$this->session($sid);$enc=$this->encounter($sid,$eid);$key=$request['start_operation_id'].':causal';$entries=[];
        // All descriptors and dependency inspections pass before generating any samples.
        foreach($rows as $row){$row['armed']=['originating_uid'=>$row['originating_uid'],'source_event_id'=>$row['source_event_id'],'policy'=>$row['start_policy'],'start_operation_id'=>$request['start_operation_id']];$inspection=$this->causalInspection($row,[]);
            if($inspection['has_reactions'])throw new RuntimeException('ACS_AMBUSH_REACTIONS_UNSUPPORTED');$entries[]=['row'=>$row,'dependency_hash'=>self::startDependencyHash($inspection)];}
        $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,encounter_id,event_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$sid,$session['next_sequence'],$eid,$enc['next_event_sequence'],$key,'encounter.start_sealed',$request['actor_uid'],AF_ACS_Resolver::VERSION,time()])['id'];$manifest=[];
        foreach($entries as $entry){$r=$entry['row'];$contract=json_decode($r['contract_json'],true,64,JSON_THROW_ON_ERROR);$identity='prepared:'.$r['id'].':trigger:'.$event;$data=['schema_version'=>1,'identity'=>$identity,'start_operation_id'=>$request['start_operation_id'],'trigger_event_id'=>$event,'prepared_action_id'=>$r['id'],'prepared_source_sealed_action_id'=>$r['source_sealed_action_id'],'source_event_id'=>$r['source_event_id'],'entity_id'=>$r['entity_id'],'target_entity_id'=>$contract['target_entity_id'],'ability_pin'=>$contract['ability_pin'],'definition_snapshot_id'=>$contract['definition_snapshot_id'],'source_sealed_hash'=>$contract['source_sealed_hash'],'prepared_version'=>$r['prepared_version'],'contract_hash'=>$r['contract_hash'],'originating_uid'=>$r['originating_uid'],'registered_order'=>$r['registered_order'],'policy'=>$r['start_policy'],'dependency_hash'=>$entry['dependency_hash'],'tape'=>($random??new AF_ACS_RandomSource)->action()];
            foreach(['hit','crit'] as $kind)$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,NULL,?,?,0,?,?,?)',[$sid,$identity,$kind,'uniform53-v1',self::json(['schema_version'=>1]+$data['tape'][$kind]),time()]);
            $execution=$this->sql('INSERT INTO '.$this->table('prepared_executions').' (prepared_action_id,trigger_event_id,operation_key,status,result_json) VALUES (?,?,?,?,?)',[$r['id'],$event,$identity,'armed',self::json($data)])['id'];$manifest[]=$data+['execution_id'=>$execution];
        }
        $payload=self::json(['schema_version'=>1,'mechanics_confirmed'=>false,'event_kind'=>'encounter.start','stage'=>'encounter_start','phase'=>'causal_seal','start_operation_id'=>$request['start_operation_id'],'entries'=>$manifest]);$visibility=self::json(['schema_version'=>1,'scope'=>'encounter_management']);
        $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$event,AF_ACS_Numeric::POLICY,$payload,$visibility,hash('sha256',$payload."\n".$visibility)]);
        $this->sql('UPDATE '.$this->table('sessions').' SET next_sequence=next_sequence+1 WHERE id=?',[$sid]);$this->sql('UPDATE '.$this->table('encounters').' SET next_event_sequence=next_event_sequence+1 WHERE id=?',[$eid]);
        $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$sid,$eid,'prepared.start_causal_seal',hash('sha256',$payload),'committed',$payload,self::json(['schema_version'=>1,'trigger_event_id'=>$event,'mechanics_confirmed'=>false]),time()]);
        return ['schema_version'=>1,'entries'=>$manifest,'trigger_event_id'=>$event];
    }
    /** The already durable intent remains if validation/sealing is interrupted. */
    public function sealAmbushStart(array $request,AF_ACS_SessionAccess $access,?AF_ACS_RandomSource $random=null):void
    {
        $key=$request['start_operation_id'];$old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=?',[$key])['rows'][0]??throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        if($old['kind']!=='encounter.start_intent'||!hash_equals($old['payload_hash'],hash('sha256',self::json($request))))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
        $result=json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);if(isset($result['causal_seal'])||$old['status']==='committed')return;
        $this->transactions->run(function()use($request,$access,$random,$key){
            $this->writable();$this->lockSession($request['session_id']);$access->csrf();$session=(new AF_ACS_EncounterService($this,$access))->context($request['session_id'],$this->session($request['session_id'])['tid']);
            $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0];$result=json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);if(isset($result['causal_seal']))return;
            $enc=$this->encounter($request['session_id'],$request['encounter_id']);
            if($old['status']!=='prepared'||$session['status']!=='active'||$enc['status']!=='ambush_ready'||$enc['start_operation_id']!==$key||$session['session_version']!==$request['expected_version']||$enc['encounter_version']!==$request['encounter_version'])throw new RuntimeException('ACS_RECOVERY_REQUIRED');
            $result['causal_seal']=$this->armStartCausal($request,$access,$random);
            $this->sql('UPDATE '.$this->table('operations').' SET result_json=?,operation_version=operation_version+1,updated_at=? WHERE operation_key=?',[self::json($result),time(),$key]);
        });
    }
    /** Unknown mechanical outcome reconciles the exact terminal key; otherwise retains a barrier. */
    public function recoverAmbushOutcome(array $request,string $terminalKey,string $reason):array
    {
        $lookup=function()use($request,$terminalKey):?array{
            $row=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? AND session_id=?',[$terminalKey,$request['session_id']])['rows'][0]??null;
            if(!$row)return null;if($row['kind']!=='encounter.ambush_start'||!hash_equals($row['payload_hash'],hash('sha256',self::json($request))))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
            return $row['status']==='committed'?json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR):null;
        };
        if($receipt=$lookup())return $receipt;
        $receipt=$this->transactions->run(function()use($request,$lookup,$reason){$this->writable();$this->lockSession($request['session_id']);if($receipt=$lookup())return $receipt;$intent=$this->sql('SELECT result_json FROM '.$this->table('operations').' WHERE operation_key=?',[$request['start_operation_id']])['rows'][0];$result=json_decode($intent['result_json'],true,64,JSON_THROW_ON_ERROR);$result['status']='recovery_required';$result['mechanics_confirmed']=false;$result['recovery_required']=true;$result['recovery_reason']=$reason;$this->sql('UPDATE '.$this->table('operations')." SET status='recovery_required',operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND status='prepared'",[self::json($result),time(),$request['start_operation_id']]);return null;});
        if($receipt)return $receipt;throw new RuntimeException('ACS_RECOVERY_REQUIRED');
    }
    private function executeStartCausal(array $request,array $intent,AF_ACS_SessionAccess $access):array
    {
        $this->commandWrite();$this->startAccess=$access;$sid=$request['session_id'];$eid=$request['encounter_id'];$saved=json_decode($intent['result_json'],true,64,JSON_THROW_ON_ERROR)['causal_seal']??throw new RuntimeException('ACS_PREPARED_CAUSAL_UNSEALED');$entries=$saved['entries'];$rows=$this->startCandidates($sid,$eid);
        if(array_column($rows,'id')!==array_column($entries,'prepared_action_id'))throw new RuntimeException('ACS_PREPARED_CAUSAL_UNSEALED');if(!$rows)return [];
        $event=$this->sql('SELECT e.*,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE e.id=?',[$saved['trigger_event_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
        $sealed=json_decode($event['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if($event['event_type']!=='encounter.start_sealed'||$event['operation_key']!==$request['start_operation_id'].':causal'||(string)$event['session_id']!==$sid||(string)$event['encounter_id']!==$eid||(string)$event['actor_uid']!==$request['actor_uid']||$event['submission_id']!==null||$sealed['event_kind']!=='encounter.start'||$sealed['phase']!=='causal_seal'||$sealed['mechanics_confirmed']!==false||$sealed['start_operation_id']!==$request['start_operation_id']||$sealed['entries']!==$entries||!hash_equals($event['content_hash'],hash('sha256',$event['payload_json']."\n".$event['visibility_json'])))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
        $contexts=[];$state=null;$facts=['entity_states'=>[],'resources'=>[]];$nodes=['event:'.$saved['trigger_event_id']=>['children'=>[]]];
        foreach($rows as $i=>&$r){$a=$entries[$i];$r['armed']=$a;
            $x=$this->sql('SELECT * FROM '.$this->table('prepared_executions').' WHERE id=? AND prepared_action_id=? AND trigger_event_id=?',[$a['execution_id'],$r['id'],$saved['trigger_event_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $raw=$a;unset($raw['execution_id']);if($x['status']!=='armed'||$x['operation_key']!==$a['identity']||json_decode($x['result_json'],true,64,JSON_THROW_ON_ERROR)!==$raw||$a['identity']!=='prepared:'.$r['id'].':trigger:'.$saved['trigger_event_id']||$a['start_operation_id']!==$request['start_operation_id']||$a['trigger_event_id']!==$saved['trigger_event_id']||$a['prepared_source_sealed_action_id']!==$r['source_sealed_action_id']||$a['source_event_id']!==$r['source_event_id']||$a['originating_uid']!==$r['originating_uid']||$a['registered_order']!==$r['registered_order']||$r['prepared_version']!==$a['prepared_version']||$r['contract_hash']!==$a['contract_hash']||$r['start_policy']!==$a['policy'])throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $samples=$this->sql('SELECT check_kind,roll_index,rng_version,value_json FROM '.$this->table('rolls').' WHERE session_id=? AND causal_key=? ORDER BY check_kind',[$sid,$a['identity']])['rows'];$checks=[];foreach($samples as $sample){$v=json_decode($sample['value_json'],true,8,JSON_THROW_ON_ERROR);if($sample['rng_version']!=='uniform53-v1'||(int)$sample['roll_index']!==0||($v['schema_version']??null)!==1)throw new RuntimeException('ACS_SEAL_TAPE_INVALID');$checks[$sample['check_kind']]=['sample_n'=>$v['sample_n'],'u'=>$v['u']];}
            if(count($checks)!==2||($checks['hit']??null)!==$a['tape']['hit']||($checks['crit']??null)!==$a['tape']['crit'])throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $inspection=$this->causalInspection($r,[]);if($a['entity_id']!==$r['entity_id']||$inspection['ordered'][0]['targets']!==[$a['target_entity_id']]||$a['ability_pin']!==$inspection['contract']['ability_pin']||$a['definition_snapshot_id']!==$inspection['contract']['definition_snapshot_id']||$a['source_sealed_hash']!==$inspection['contract']['source_sealed_hash'])throw new RuntimeException('ACS_SEAL_TAPE_INVALID');if(self::startDependencyHash($inspection)!==$a['dependency_hash'])throw new RuntimeException('ACS_AMBUSH_DEPENDENCY_CONFLICT');if($inspection['has_reactions'])throw new RuntimeException('ACS_AMBUSH_REACTIONS_UNSUPPORTED');$contexts[]=$inspection;
            if($state===null)$state=$inspection['context']['state'];else foreach(['entities','groups'] as $field)$state[$field]+=$inspection['context']['state'][$field];
            $nodes['event:'.$saved['trigger_event_id']]['children'][]=$a['identity'];$nodes[$a['identity']]=['children'=>[]];
        }unset($r);
        $initial=$state;foreach($state['entities'] as $id=>$unused){$facts['entity_states'][]=$this->sql('SELECT * FROM '.$this->table('entity_states').' WHERE entity_id=?',[(string)$id])['rows'][0];array_push($facts['resources'],...$this->entityResources((string)$id));}
        $traces=[];
        foreach($rows as $i=>$r){$a=$entries[$i];$graph=AF_ACS_CausalGraph::order($nodes,['event:'.$saved['trigger_event_id']],$a['policy']['limits']['max_depth']);$child=$contexts[$i]['context'];$child['state']=$state;
            $target=$contexts[$i]['ordered'][0]['targets'][0];if(($state['entities'][$target]['life_state']??'dead')!=='active')throw new RuntimeException('ACS_PREPARED_TARGET_UNAVAILABLE');
            $resolved=AF_ACS_Resolver::resolve($child,$contexts[$i]['ordered'],[$a['tape']],'seal');if($resolved->serverState()['groups']!==$initial['groups'])throw new RuntimeException('ACS_PREPARED_AP_CONFLICT');
            $receipts=(new AF_ACS_PreparedItemService($this,$access))->consumeTrigger($r,$a['trigger_event_id']);$this->causalInspection($r,[]);
            $state=$resolved->serverState();$reveal=$this->revealPrepared($r,['attacker'=>$r['entity_id']],$state);
            $traces[]=$a+['outcome'=>'executed','terminal_state'=>'executed','mechanics_confirmed'=>true,'costs'=>$resolved->serverPlan()['actions'][0]['costs'],'owner_receipts'=>$receipts,'causal_trace'=>$graph,'resolver_plan'=>$resolved->serverPlan(),'reveal'=>$reveal];
        }
        // One final projection for the shared virtual state, before the first real roster.
        $deltas=$this->projectEntityStateDeltas($facts,$initial,$state);
        $this->persistStatusInstances($sid,$initial['status_instances'],$state['status_instances'],[]);$this->persistBarriers($sid,$eid,$initial['barriers'],$state['barriers'],[]);
        $this->persistSummons($sid,$eid,$initial['summons'],$state['summons'],$request['actor_uid']);$this->persistObjects($sid,$eid,$initial['objects'],$state['objects']);$this->persistSpatial($sid,$eid,$initial['spatial'],$state['spatial']);$this->persistCover($sid,$eid,$initial,$state);
        $this->persistAwareness($sid,$eid,$initial['awareness'],$state['awareness'],$initial['awareness_attempts'],$state['awareness_attempts']);
        foreach($traces as &$trace){$trace['mechanical_deltas']=$deltas;
            if($this->sql('UPDATE '.$this->table('prepared_actions')." SET status='executed',prepared_version=prepared_version+1,terminal_boundary=? WHERE id=? AND prepared_version=? AND status='active'",['event:'.$trace['trigger_event_id'],$trace['prepared_action_id'],$trace['prepared_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $terminal=$this->sql('SELECT prepared_version,cost_state FROM '.$this->table('prepared_actions').' WHERE id=?',[$trace['prepared_action_id']])['rows'][0];$trace['terminal_prepared_version']=(string)$terminal['prepared_version'];$trace['terminal_cost_state']=$terminal['cost_state'];
            if($this->sql('UPDATE '.$this->table('prepared_executions')." SET status='executed',result_json=? WHERE id=? AND status='armed'",[self::json($trace),$trace['execution_id']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        }unset($trace);return $traces;
    }
    public function linkStartExecutions(array $session,string $event):array
    {
        $traces=$session['_command_result']['prepared_executions']??[];
        foreach($traces as &$trace){$trace['parent_event_id']=$event;$this->sql('UPDATE '.$this->table('prepared_executions').' SET result_json=? WHERE id=? AND status=?',[self::json($trace),$trace['execution_id'],'executed']);}unset($trace);$session['_command_result']['prepared_executions']=$traces;return $session;
    }
}
