<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** SQL projection only. Mutations are called inside the existing command/combat transaction. */
trait AF_ACS_StatusPersistence
{
    /** Existing combat resources, exact integer projection; never invent a missing balance. */
    public function statusResources(string $entity,array $row):array
    {
        $all=[];$values=[];$rows=$this->entityResources($entity);
        foreach($rows as $r){$key=$r['resource_key'];
            if(isset($all[$key])||(string)$r['is_integer']!=='1')throw new RuntimeException('ACS_RESOURCE_STATE_CONFLICT');
            $current=(int)self::integer((string)$r['current_value'],'9007199254740991',true);$max=(int)self::integer((string)$r['maximum_value'],'9007199254740991',true);
            if($current>$max||strtolower($key)==='ap')throw new RuntimeException('ACS_RESOURCE_STATE_CONFLICT');$all[$key]=$r;
            if($key!=='hp')$values[$key]=['current'=>$current,'maximum'=>$max];
        }
        if(!isset($all['hp'])||(string)$all['hp']['current_value']!==(string)$row['hp']||(string)$all['hp']['maximum_value']!==(string)$row['hp_max'])throw new RuntimeException('ACS_RESOURCE_STATE_CONFLICT');
        return ['values'=>$values,'rows'=>$all,'fingerprint'=>hash('sha256',self::json(['schema_version'=>1,'rows'=>$rows]))];
    }
    /** Only the existing Clock command transaction may advance time. */
    protected function processStatusBoundary(array $session,array $encounter,string $phase,string $id,string $actor,?string $entity=null,array $entities=[]):array
    {
        $this->commandWrite();require_once __DIR__.'/../Domain/Clock.php';
        if($session['status']!=='active'||$encounter['status']!=='active')throw new RuntimeException('ACS_STATUS_CLOCK_PAUSED');
        $this->statusRows($session['id'],true);$projection=$this->statusProjection($session['id']);$barriers=$this->barrierProjection($session['id'],$encounter['id']);
        $summons=$this->summonProjection($session['id'],$encounter['id']);
        $objects=$this->objectProjection($session['id'],$encounter['id']);
        $state=['spatial'=>$this->spatialProjection($session['id'],$encounter['id']),'cover'=>AF_ACS_Cover::initial($this->coverProjection($session['id'],$encounter['id']),$objects),'objectives'=>$this->objectiveProjection($session['id'],$encounter['id']),'awareness'=>$this->awarenessProjection($session['id'],$encounter['id']),'awareness_attempts'=>$this->awarenessAttempts($session['id'],$encounter['id']),'perception_subjects'=>$this->perceptionSubjects($session['id'],$encounter['id']),'objects'=>$objects,'summons'=>$summons,'schema_version'=>1,'entities'=>[],'groups'=>[],'barriers'=>$barriers['pools'],'next_barrier_order'=>$barriers['next_order'],'status_instances'=>$projection['instances'],'next_status_order'=>$projection['next_order']];
        $defenses=[];$catalog=['by_target'=>[]];$resources=[];$needed=[];$groupTurns=[];
        foreach($barriers['pools'] as $pool)foreach(array_unique([$pool['source_entity_id'],...$pool['targets']]) as $poolEntity)$needed[$poolEntity]=true;
        foreach($projection['instances'] as $effect)if($effect['status']==='active'&&isset($effect['spec']['upkeep'])&&($effect['spec']['scope']!=='combat'||$effect['encounter_id']===$encounter['id']))$needed[$effect['source_entity_id']]=true;
        if(in_array($phase,['entity_start','round_start'],true))foreach($projection['instances'] as $effect){
            if($effect['status']!=='active'||empty($effect['spec']['periodic'])||($effect['spec']['scope']==='combat'&&$effect['encounter_id']!==$encounter['id']))continue;
            $round=$effect['duration_unit']==='round';
            if($round?($phase==='round_start'&&in_array($effect['target_entity_id'],$entities,true)):($phase==='entity_start'&&($effect['duration_unit']==='own_turn'?$effect['source_entity_id']:$effect['target_entity_id'])===$entity))$needed[$effect['target_entity_id']]=true;
        }
        foreach($summons as $summon)if($summon['status']==='active')foreach([$summon['entity_id'],$summon['source_entity_id']] as $target)$needed[$target]=true;
        foreach($objects as $object)if($object['status']==='active'&&(($phase==='entity_start'||$phase==='entity_end')&&$entity===$object['source_entity_id'])){ $m=AF_ACS_Environment::definition($object['definition']);if($m['passive']||$m['triggers'])$needed[$object['source_entity_id']]=true;}
        if($needed)$this->assertPreviewStorage();
        foreach(array_keys($needed) as $target){$target=(string)$target;$row=$this->entity($session['id'],$target);
            if(!$row||!hash_equals($row['content_hash'],hash('sha256',$row['payload_json']))||json_decode($row['layers_json'],true)!==['schema_version'=>1,'layers'=>[]])throw new RuntimeException('ACS_STATUS_TARGET_UNSUPPORTED');
            $source=self::registrationView(json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR))['source_snapshot']??[];$stats=json_decode($row['stats_json'],true,32,JSON_THROW_ON_ERROR);
            if(($source['ready']??null)!==true||$stats!==['schema_version'=>1,'values'=>$source['characteristics']??null])throw new RuntimeException('ACS_STATUS_TARGET_UNSUPPORTED');
            $hp=(int)self::integer($row['hp'],'9007199254740991',true);$max=(int)self::integer($row['hp_max'],'9007199254740991',true);$gid=$row['control_group_id'];
            if(!isset($state['groups'][$gid])){
                $turn=null;$round=$this->currentRound($encounter);
                if($round)foreach($this->allRoundTurns($round['id']) as $t)if($t['control_group_id']===$gid)$turn=$t;
                if($phase==='entity_start'&&$target===$entity&&$turn===null)throw new RuntimeException('ACS_UPKEEP_TURN_UNAVAILABLE');
                $groupTurns[$gid]=$turn;
                $state['groups'][$gid]=$turn?['ap'=>['current'=>AF_ACS_Clock::availableAP((int)$turn['ap_budget'],(int)$turn['ap_spent'],(int)$turn['pre_round_ap_commitment']),'maximum'=>(int)$turn['ap_budget']],'version'=>(int)$turn['turn_version']]:['ap'=>['current'=>0,'maximum'=>0],'version'=>1];
            }
            $state['entities'][$target]=['hp'=>$hp,'max_hp'=>$max,'life_state'=>$row['life_state'],'recovery_blocked'=>false,'group_id'=>$gid,'resources'=>[],
                'source_ref'=>['key'=>'entity-'.$target,'version'=>'registration-1','fingerprint'=>'sha256:'.$row['content_hash']],'version'=>(int)self::integer($row['state_version'],'9007199254740991')];
            $loaded=$this->statusResources($target,$row);$resources[$target]=$loaded['rows'];$state['entities'][$target]['resources']=$loaded['values'];
            $res=[];foreach($source['resistances']??[] as $key=>$r)$res[$key]=['scope'=>'elemental','unit'=>'RES','value'=>$r['value']];
            foreach(['weaknesses','math_modifiers'] as $field)if(!empty($source['data']['definition'][$field]))throw new RuntimeException('ACS_STATUS_TARGET_UNSUPPORTED');
            foreach($source['data']['definition']['immunities']??[] as $scope)if(!is_array($scope)||!in_array($scope['scope']??null,['status_key','status_type'],true)||!is_string($scope['key']??null))throw new RuntimeException('ACS_STATUS_TARGET_UNSUPPORTED');
            $defenses[$target]=['def'=>$source['characteristics']['def']??null,'resistances'=>$res,'immunities'=>[],'vulnerabilities'=>[]];
            $catalog['by_target'][$target]=AF_ACS_Damage::retainedCatalog($row['payload_json'],$row['content_hash']);
        }
        $result=AF_ACS_Clock::advance($state,['phase'=>$phase,'id'=>$id,'session_id'=>$session['id'],'encounter_id'=>$encounter['id'],
            'encounter_status'=>$encounter['status'],'entity_id'=>$entity,'entity_ids'=>$entities],$defenses,$catalog);
        $after=$result->serverState();$changes=[];
        foreach($after['entities'] as $target=>$e){$old=$state['entities'][$target];if($old===$e)continue;$r=$resources[$target]['hp'];
            self::integer((string)$r['resource_version'],'18446744073709551614');
            if($this->sql('UPDATE '.$this->table('entity_states').' SET hp=?,life_state=?,state_version=? WHERE entity_id=? AND state_version=?',[$e['hp'],$e['life_state'],$e['version'],(string)$target,$old['version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            foreach($resources[$target] as $key=>$resource){
                $beforeValue=$key==='hp'?$old['hp']:$old['resources'][$key]['current'];$value=$key==='hp'?$e['hp']:$e['resources'][$key]['current'];if($beforeValue===$value)continue;
                self::integer((string)$resource['resource_version'],'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('resources').' SET current_value=?,resource_version=resource_version+1 WHERE id=? AND resource_version=?',[$value,$resource['id'],$resource['resource_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            }
            $changes[]=['entity_id'=>(string)$target,'before'=>$old,'after'=>$e];
        }
        $groupChanges=[];
        foreach($after['groups'] as $gid=>$g){$old=$state['groups'][$gid];if($old===$g)continue;$turn=$groupTurns[$gid];
            $spent=$old['ap']['current']-$g['ap']['current'];if(!$turn||$spent<0||$phase!=='entity_start'||$turn['status']!=='pending')throw new RuntimeException('ACS_AP_STATE_INVALID');
            self::integer((string)$turn['turn_version'],'18446744073709551614');
            if($this->sql('UPDATE '.$this->table('turns').' SET ap_spent=ap_spent+?,turn_version=? WHERE id=? AND turn_version=? AND status=?',[$spent,$g['version'],$turn['id'],$turn['turn_version'],'pending'])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $groupChanges[]=['turn_id'=>$turn['id'],'before'=>$old,'after'=>$g];
        }
        $statuses=$this->persistStatusInstances($session['id'],$state['status_instances'],$after['status_instances'],[]);$trace=$result->serverTrace();
        foreach($trace['tick_keys'] as $key){
            if($this->boundary($key,$session['id']))throw new RuntimeException('ACS_STATUS_BOUNDARY_RECEIPT_CONFLICT');
            $this->recordBoundary($key,'clock.status_tick',$session['id'],$encounter['id'],$actor,['parent_boundary'=>$trace['boundary_key']],['parent_boundary'=>$trace['boundary_key'],'trace'=>$trace]);
        }
        $poolChanges=$this->persistBarriers($session['id'],$encounter['id'],$state['barriers'],$after['barriers'],[]);
        $summonChanges=$this->persistSummons($session['id'],$encounter['id'],$state['summons'],$after['summons'],$actor);
        $coverChanges=$this->persistCover($session['id'],$encounter['id'],$state,$after);$spatialChanges=$this->persistSpatial($session['id'],$encounter['id'],$state['spatial'],$after['spatial']);
        $objectChanges=$this->persistObjects($session['id'],$encounter['id'],$state['objects'],$after['objects']);
        $objectiveChanges=$this->persistObjectives($session['id'],$encounter['id'],$state['objectives'],$after['objectives']);
        $knowledgeChanges=$this->persistAwareness($session['id'],$encounter['id'],$state['awareness'],$after['awareness'],$state['awareness_attempts'],$after['awareness_attempts']);
        return ['cover'=>$coverChanges,'spatial'=>$spatialChanges,'objectives'=>$objectiveChanges,'awareness'=>$knowledgeChanges,'objects'=>$objectChanges,'summons'=>$summonChanges,'trace'=>$trace,'entities'=>$changes,'groups'=>$groupChanges,'statuses'=>$statuses,'barriers'=>$poolChanges];
    }
    /** Server-persisted source priority; expected session/encounter CAS is owned by command(). */
    public function setUpkeepOrder(array $session,array $encounter,array $individual,array $ids):array
    {
        $this->commandWrite();
        if($encounter['status']!=='active'||$individual['encounter_id']!==$encounter['id']||$individual['round_status']!=='open'||$individual['round_number']!==$individual['current_round'])throw new RuntimeException('ACS_UPKEEP_ORDER_CLOSED');
        $started=(string)$individual['start_done']==='1';$start=AF_ACS_Clock::entityStart($individual['id']);
        if($started!==($this->boundary($start,$session['id'])!==null)||!$started&&((string)$individual['end_done']!=='0'||$individual['turn_status']!=='pending'))throw new RuntimeException('ACS_CLOCK_BOUNDARY_STATE');
        $this->statusRows($session['id'],true);$p=$this->statusProjection($session['id']);$eligible=[];
        foreach($p['instances'] as $id=>$row)if($row['status']==='active'&&isset($row['spec']['upkeep'])&&$row['source_entity_id']===$individual['entity_id']&&($row['spec']['scope']!=='combat'||$row['encounter_id']===$encounter['id']))$eligible[]=(string)$id;
        $sorted=$ids;$expected=$eligible;sort($sorted,SORT_STRING);sort($expected,SORT_STRING);
        if(!$ids||!array_is_list($ids)||count(array_unique($ids))!==count($ids)||$sorted!==$expected)throw new RuntimeException('ACS_UPKEEP_ORDER_INVALID');
        $after=$p['instances'];foreach($ids as $rank=>$id){$after[$id]['upkeep_priority']=$rank+1;$after[$id]['version']++;}
        $saved=$this->persistStatusInstances($session['id'],$p['instances'],$after,[]);
        $session=$this->change($session,$encounter,'upkeep_order',[]);$session['_command_result']+=['entity_turn_id'=>$individual['id'],'source_entity_id'=>$individual['entity_id'],'order'=>$ids,'effective_from'=>$started?'next_source_start':'pending_source_start','effect_versions'=>array_map(static fn($c)=>['id'=>$c['id'],'before_version'=>$c['before']['version'],'after_version'=>$c['after']['version']],$saved['changes'])];return $session;
    }
    public function statusRows(string $sid,bool $lock=false):array
    {
        return $this->sql('SELECT * FROM '.$this->table('effects').' WHERE session_id=? ORDER BY applied_order,id'.($lock?' FOR UPDATE':''),[self::integer($sid)])['rows'];
    }
    public function statusProjection(string $sid):array
    {
        require_once __DIR__.'/../Domain/Effects.php';
        require_once __DIR__.'/Adapters/KnowledgeBaseAdapter.php';
        $rows=$this->statusRows($sid);$instances=[];$next=1;$barrierEncounters=[];
        foreach($rows as $row){
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$next=max($next,(int)$row['applied_order']+1);if(($p['kind']??null)==='barrier'){$barrierEncounters[(string)$row['encounter_id']]=true;continue;}$instance=$p['instance']??null;
            if(($p['schema_version']??null)!==1||!is_array($instance))throw new RuntimeException('ACS_STATUS_SCHEMA_UNSUPPORTED');
            $id=self::integer((string)$row['id']);
            foreach(['id','session_id','encounter_id','source_entity_id','target_entity_id'] as $field)if(($instance[$field]??null)!==(string)$row[$field])throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            foreach(['stacks','remaining_duration','applied_order'] as $field)if(($instance[$field]??null)!==(int)self::integer((string)$row[$field],'9007199254740991',true))throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            if(($instance['version']??null)!==(int)self::integer((string)$row['effect_version'],'9007199254740991')||$instance['duration_unit']!==$row['duration_unit']||$instance['status']!==$row['status']
                ||($instance['spec']['scope']??null)!==$row['scope']||($instance['spec']['effect_key']??null)!==$row['effect_key']||$row['parent_effect_id']!==null)throw new RuntimeException('ACS_STATUS_PROJECTION_INVALID');
            $snapshot=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$row['definition_snapshot_id'],$sid])['rows'][0]??null;
            if(!$snapshot||!hash_equals($snapshot['content_hash'],hash('sha256',$snapshot['payload_json'])))throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $dto=json_decode($snapshot['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition']??[];$effect=null;
            foreach($dto['data']['ability_profile']['effects']??[] as $candidate)if(($candidate['source_index']??null)===$instance['effect_definition_ref']['effect_index'])$effect=$candidate;
            if($effect===null)throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $status=$effect['references']['status_key']??[];
            $expectedRef=['type'=>$dto['source']['type']??null,'key'=>$dto['source']['key']??null,'version'=>$dto['definition_version']['declared_version']??null,
                'fingerprint'=>$dto['definition_version']['fingerprint']??null,'effect_index'=>$effect['source_index']];
            $expectedStatus=['key'=>$status['key']??null,'version'=>$status['definition_version']['version']??null,'fingerprint'=>$status['definition_version']['fingerprint']??null];
            if(($status['resolved']??null)!==true||AF_ACS_KnowledgeBaseAdapter::statusInstanceSpec($dto,$effect)!==$instance['spec']
                ||$expectedRef!==$instance['effect_definition_ref']||$expectedStatus!==$instance['status_definition_ref'])throw new RuntimeException('ACS_STATUS_PIN_INVALID');
            $instances[$id]=$instance;$next=max($next,$instance['applied_order']+1);
        }
        foreach(array_keys($barrierEncounters) as $encounter)$this->barrierProjection($sid,(string)$encounter);
        return ['instances'=>$instances,'next_order'=>$next,'fingerprint'=>hash('sha256',self::json(['schema_version'=>1,'rows'=>$rows]))];
    }
    /** Existing combat-reset command only, after persisted encounter completion. */
    protected function expireCombatStatuses(array $session,array $encounter):array
    {
        $this->commandWrite();
        if($encounter['status']!=='completed'||$encounter['completed_at']===null||$encounter['session_id']!==$session['id'])throw new RuntimeException('ACS_STATUS_BOUNDARY_INVALID');
        $this->statusRows($session['id'],true);$projection=$this->statusProjection($session['id']);
        $state=['schema_version'=>1,'entities'=>[],'groups'=>[],'barriers'=>[],'next_barrier_order'=>0,
            'status_instances'=>$projection['instances'],'next_status_order'=>$projection['next_order']];
        $result=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_combat_end','encounter_id'=>$encounter['id']]]]);
        return $this->persistStatusInstances($session['id'],$state['status_instances'],$result->serverState()['status_instances'],[]);
    }
    /** Caller owns the existing ACS lock/transaction and event journal. Never starts or commits a transaction. */
    protected function persistStatusInstances(string $sid,array $before,array $after,array $snapshotIds):array
    {
        require_once __DIR__.'/../Domain/Effects.php';$mapping=[];$changed=[];
        if(array_diff_key($before,$after))throw new RuntimeException('ACS_STATUS_HISTORY_REQUIRED');
        foreach($after as $virtualId=>$instance){
            $virtualId=(string)$virtualId;$old=$before[$virtualId]??null;if($old===$instance)continue;
            if(($instance['session_id']??null)!==$sid)throw new RuntimeException('ACS_STATUS_SCOPE_INVALID');
            if($old===null){
                if(!preg_match('/\Anew:[1-9][0-9]*\z/D',$virtualId))throw new RuntimeException('ACS_STATUS_ID_INVALID');
                $snapshot=$snapshotIds[$instance['origin_action_index']]??null;if($snapshot===null)throw new RuntimeException('ACS_STATUS_PIN_INVALID');
                $id=$this->sql('INSERT INTO '.$this->table('effects').' (session_id,encounter_id,source_entity_id,target_entity_id,definition_snapshot_id,effect_key,scope,status,stacks,remaining_duration,duration_unit,applied_order,effect_version,parent_effect_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)',
                    [$sid,$instance['encounter_id'],$instance['source_entity_id'],$instance['target_entity_id'],$snapshot,$instance['spec']['effect_key'],$instance['spec']['scope'],$instance['status'],$instance['stacks'],$instance['remaining_duration'],$instance['duration_unit'],$instance['applied_order'],$instance['version'],self::json(['schema_version'=>1,'instance'=>$instance])])['id'];
                $instance['id']=$id;$mapping[$virtualId]=$id;
                if($this->sql('UPDATE '.$this->table('effects').' SET payload_json=? WHERE id=? AND session_id=? AND effect_version=?',[self::json(['schema_version'=>1,'instance'=>$instance]),$id,$sid,$instance['version']])['affected']!==1)throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
            }else{
                $id=self::integer($virtualId);$immutable=['id','session_id','encounter_id','source_entity_id','target_entity_id','spec','effect_definition_ref','status_definition_ref','applied_order','duration_unit','origin_action_index'];
                foreach($immutable as $field)if($old[$field]!==$instance[$field])throw new RuntimeException('ACS_STATUS_IMMUTABLE');
                if($instance['version']<=$old['version'])throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
                if($this->sql('UPDATE '.$this->table('effects').' SET status=?,stacks=?,remaining_duration=?,effect_version=?,payload_json=? WHERE session_id=? AND id=? AND effect_version=?',
                    [$instance['status'],$instance['stacks'],$instance['remaining_duration'],$instance['version'],self::json(['schema_version'=>1,'instance'=>$instance]),$sid,$id,$old['version']])['affected']!==1)throw new RuntimeException('ACS_STATUS_VERSION_CONFLICT');
            }
            $changed[]=['id'=>$id,'virtual_id'=>$virtualId,'before'=>$old,'after'=>$instance];
        }
        return ['id_map'=>$mapping,'changes'=>$changed];
    }
}
