<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Registry/roll projections use the existing command and combat writer transactions. */
trait AF_ACS_ReactionPersistence
{
    public function reactionParticipant(string $sid,string $eid,string $entity):bool
    {
        return (bool)$this->sql('SELECT p.id FROM '.$this->table('participants').' p JOIN '.$this->table('encounters').' e ON e.id=p.encounter_id WHERE e.session_id=? AND e.id=? AND p.entity_id=? AND p.participation=?',[$sid,$eid,$entity,'engaged'])['rows'];
    }
    public function reactionRows(string $sid,string $eid,bool $lock=false):array
    {
        return $this->sql('SELECT r.* FROM '.$this->table('reactions').' r JOIN '.$this->table('encounters').' e ON e.id=r.encounter_id WHERE e.session_id=? AND e.id=? ORDER BY r.registered_order,r.id'.($lock?' FOR UPDATE':''),[$sid,$eid])['rows'];
    }
    public function reactionProjection(string $sid,string $eid):array
    {
        require_once __DIR__.'/../Domain/Effects.php';$rows=$this->reactionRows($sid,$eid);$entries=[];$fingerprint=hash_init('sha256');
        foreach($rows as $row){$c=json_decode($row['config_json'],true,64,JSON_THROW_ON_ERROR);
            $snap=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND id=?',[$sid,$row['definition_snapshot_id']])['rows'][0]??null;
            if(!$snap||!hash_equals($snap['content_hash'],hash('sha256',$snap['payload_json'])))throw new RuntimeException('ACS_REACTION_PIN_INVALID');
            $payload=json_decode($snap['payload_json'],true,64,JSON_THROW_ON_ERROR);$spec=AF_ACS_Effects::reactionSpec($payload['definition']??[]);
            if(($payload['schema_version']??null)!==1||($payload['engine_version']??null)!==AF_ACS_Effects::VERSION||($payload['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||($c['schema_version']??null)!==1||($c['spec']??null)!==$spec||$row['phase']!==$spec['phase']||(int)$row['priority']!==$spec['priority']||!in_array($row['status'],['active','cancelled'],true))throw new RuntimeException('ACS_REACTION_SCHEMA_INVALID');
            foreach(['id','entity_id','encounter_id','definition_snapshot_id','registered_order','reaction_version'] as $field)$row[$field]=self::integer((string)$row[$field]);
            self::integer($row['registered_order'],'9007199254740991');self::integer($row['reaction_version'],'9007199254740991');
            $entity=$this->entity($sid,$row['entity_id']);$controllers=$this->controllers($sid,$row['entity_id']);$controller=$controllers[0]??[];$permission=json_decode($controller['permissions_json']??'{}',true);
            $enabled=$entity&&count($controllers)===1&&($c['controller_uid']??null)===$controller['controller_uid']&&($c['assignment_version']??null)===$controller['assignment_version']
                &&($permission['combat_operations_enabled']??false)===true&&($c['template_snapshot_id']??null)===$entity['template_snapshot_id']&&($c['content_hash']??null)===$entity['content_hash']&&$this->roles($sid,$controller['controller_uid'])&&$this->reactionParticipant($sid,$eid,$row['entity_id']);
            $entries[$row['id']]=['id'=>$row['id'],'entity_id'=>$row['entity_id'],'encounter_id'=>$eid,'phase'=>$row['phase'],'priority'=>$spec['priority'],'registered_order'=>(int)$row['registered_order'],
                'status'=>$row['status'],'reaction_version'=>(int)$row['reaction_version'],'spec'=>$spec,'enabled'=>(bool)$enabled,'last_execution'=>$c['last_execution']??null];
            hash_update($fingerprint,self::json(['schema_version'=>1,'row'=>$row,'entry'=>$entries[$row['id']]]));
        }
        return ['entries'=>$entries,'fingerprint'=>hash_final($fingerprint)];
    }
    public function registerReaction(array $session,array $encounter,array $source,array $controller,array $graph):array
    {
        $this->commandWrite();$this->reactionRows($session['id'],$encounter['id'],true);$p=$this->reactionProjection($session['id'],$encounter['id']);$order=1;
        foreach($p['entries'] as $row){$order=max($order,$row['registered_order']+1);if($row['status']==='active'&&$row['entity_id']===$source['id']&&$row['spec']===$graph['spec'])throw new RuntimeException('ACS_REACTION_ALREADY_REGISTERED');}
        if(count($p['entries'])>=256)throw new RuntimeException('ACS_REACTION_REGISTRY_LIMIT');
        $snapshot=$this->insertSnapshot($session['id'],['source_system'=>'knowledgebase','source_key'=>$graph['spec']['trigger_ref']['key'],'contract_id'=>'ic-acs.reaction-definition.v1','contract_version'=>'1'],['schema_version'=>1,'engine_version'=>AF_ACS_Effects::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'definition'=>$graph['snapshot']]);
        $config=['schema_version'=>1,'spec'=>$graph['spec'],'controller_uid'=>$controller['controller_uid'],'assignment_version'=>$controller['assignment_version'],'template_snapshot_id'=>$source['template_snapshot_id'],'content_hash'=>$source['content_hash'],'last_execution'=>null];
        $id=$this->sql('INSERT INTO '.$this->table('reactions').' (entity_id,encounter_id,definition_snapshot_id,phase,priority,registered_order,status,reaction_version,config_json) VALUES (?,?,?,?,?,?,?,1,?)',[$source['id'],$encounter['id'],$snapshot,$graph['spec']['phase'],$graph['spec']['priority'],$order,'active',self::json($config)])['id'];
        $session=$this->change($session,$encounter,'reaction_registered',[]);$session['_command_result']+=['reaction_id'=>$id,'reaction_version'=>'1','entity_id'=>$source['id'],'phase'=>$graph['spec']['phase'],'priority'=>$graph['spec']['priority']];return $session;
    }
    public function cancelReaction(array $session,array $encounter,string $id,string $version):array
    {
        $this->commandWrite();if($this->sql('UPDATE '.$this->table('reactions')." SET status='cancelled',reaction_version=reaction_version+1 WHERE id=? AND encounter_id=? AND reaction_version=? AND status='active'",[$id,$encounter['id'],$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session=$this->change($session,$encounter,'reaction_cancelled',[]);$session['_command_result']+=['reaction_id'=>$id,'reaction_version'=>(string)((int)$version+1)];return $session;
    }
    public static function reactionRollKey(string $reaction,string $seal,string $phase):string
    {
        if(!in_array($phase,AF_ACS_Effects::REACTION_PHASES,true))throw new RuntimeException('ACS_REACTION_PHASE_INVALID');
        return 'reaction:'.self::integer($reaction).':sealed-action:'.self::integer($seal).':'.$phase;
    }
    /** Read only: missing causal checks cannot be generated by status/preview/commit/recovery. */
    public function reactionContext(array $context,array $seals):array
    {
        $context['reaction_causes']=[];$context['reaction_rolls']=[];
        foreach($seals as $index=>$descriptor){$payload=$descriptor['payload']??$this->sealPayload($descriptor);$target=$payload['action']['targets'][0];$seal=(string)$descriptor['id'];$context['reaction_causes'][$index]='sealed-action:'.self::integer($seal);
            foreach($context['reactions']??[] as $entry){if($entry['status']!=='active'||$entry['entity_id']!==$target||$entry['spec']['probability']>=100)continue;
                $key=self::reactionRollKey($entry['id'],$seal,$entry['phase']);$row=$this->sql('SELECT * FROM '.$this->table('rolls')." WHERE session_id=? AND causal_key=? AND check_kind='reaction' AND roll_index=0",[$context['status_scope']['session_id'],$key])['rows'][0]??null;
                if(!$row)continue;if($row['sealed_action_id']!==null||$row['rng_version']!=='uniform53-v1')throw new RuntimeException('ACS_REACTION_ROLL_INVALID');
                $v=json_decode($row['value_json'],true,8,JSON_THROW_ON_ERROR);if(($v['schema_version']??null)!==1||array_diff(array_keys($v),['schema_version','sample_n','u']))throw new RuntimeException('ACS_REACTION_ROLL_INVALID');
                $sample=['sample_n'=>$v['sample_n']??null,'u'=>$v['u']??null];AF_ACS_Effects::statusSample($sample);$context['reaction_rolls'][$key]=['id'=>(string)$row['id'],'sample'=>$sample];
            }
        }return $context;
    }
    /** Only an explicit seal command may reserve a new prepared causal check. */
    public function prepareReactionRolls(array $draft,array $context,AF_ACS_RandomSource $random):void
    {
        if(empty($this->sealWriting))throw new RuntimeException('ACS_COMMAND_REQUIRED');
        foreach($this->sealRows($draft['id'],true) as $seal){$target=$this->sealPayload($seal)['action']['targets'][0];foreach($context['reactions']??[] as $entry){
            if($entry['status']!=='active'||$entry['entity_id']!==$target||!$entry['enabled']||$entry['spec']['probability']>=100)continue;$key=self::reactionRollKey($entry['id'],(string)$seal['id'],$entry['phase']);
            if($this->sql('SELECT id FROM '.$this->table('rolls').' WHERE session_id=? AND causal_key=? AND check_kind=? AND roll_index=0',[$draft['session_id'],$key,'reaction'])['rows'])continue;
            $sample=$random->reaction();$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,NULL,?,?,0,?,?,?)',[$draft['session_id'],$key,'reaction','uniform53-v1',self::json(['schema_version'=>1]+$sample),time()]);
        }}
    }
}
