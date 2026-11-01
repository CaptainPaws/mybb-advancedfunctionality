<?php
if(!defined('IN_MYBB')){die('No direct access');}

/** Immutable seal payloads/rolls; only cancellation status and draft projections are mutable. */
trait AF_ACS_SealPersistence
{
    public function sealRows(string $draft,bool $active=false):array
    {
        return $this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE draft_id=?'.($active?" AND status='sealed'":'').' ORDER BY action_index',[$draft])['rows'];
    }
    public function sealTape(string $id):array
    {
        $rows=$this->sql('SELECT check_kind,rng_version,value_json FROM '.$this->table('rolls').' WHERE sealed_action_id=? ORDER BY check_kind',[$id])['rows'];$t=['rng_version'=>'uniform53-v1'];
        foreach($rows as $r){if(!in_array($r['check_kind'],['hit','crit'],true)||$r['rng_version']!==$t['rng_version'])throw new RuntimeException('ACS_SEAL_TAPE_INVALID');$v=json_decode($r['value_json'],true,8,JSON_THROW_ON_ERROR);if(($v['schema_version']??null)!==1)throw new RuntimeException('ACS_SEAL_TAPE_INVALID');$t[$r['check_kind']]=['sample_n'=>$v['sample_n'],'u'=>$v['u']];}
        if(count($rows)!==2||!isset($t['hit'],$t['crit']))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');return ['rng_version'=>$t['rng_version'],'hit'=>$t['hit'],'crit'=>$t['crit']];
    }
    public function sealPayload(array $row):array
    {
        require_once __DIR__.'/../Domain/Resolver.php';
        if(!hash_equals($row['sealed_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_SEAL_HASH_INVALID');
        $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($p['schema_version']??null)!==1||($p['engine_version']??null)!==AF_ACS_Resolver::VERSION||($p['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||($p['rng_version']??null)!=='uniform53-v1')throw new RuntimeException('ACS_SEAL_VERSION_UNSUPPORTED');
        if((string)$row['actor_entity_id']!==($p['action']['actor_id']??null)||(int)$row['ap_cost']!==($p['ap_cost']??null)||(int)$row['action_index']!==($p['history_index']??null))throw new RuntimeException('ACS_SEAL_HASH_INVALID');return $p;
    }
    public function verifySealDefinition(string $sid,array $row,array $payload):void
    {
        $s=$this->sql('SELECT payload_json,content_hash FROM '.$this->table('snapshots').' WHERE session_id=? AND id=?',[$sid,$row['definition_snapshot_id']])['rows'][0]??null;
        if(!$s||!hash_equals($s['content_hash'],hash('sha256',$s['payload_json']))||(string)$row['definition_snapshot_id']!==$payload['definition_snapshot_id'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $d=json_decode($s['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition']??[];
        if(($d['definition_version']['fingerprint']??null)!==$payload['pin']['fingerprint']||($d['definition_version']['declared_version']??null)!==$payload['pin']['version'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
    }
    public function sealQuota(string $turn):int
    {
        $n=$this->sql('SELECT COALESCE(SUM(a.ap_cost),0) AS n FROM '.$this->table('sealed_actions').' a JOIN '.$this->table('drafts').' d ON d.id=a.draft_id WHERE d.turn_id=?',[$turn])['rows'][0]['n'];return (int)self::integer((string)$n,'2147483647',true);
    }
    /** All mutations are enclosed in the existing ACS transaction/lock, without combat events. */
    public function sealCommand(array $request,callable $authorize,callable $apply):array
    {
        $json=self::json($request);$hash=hash('sha256',$json);$key='seal:'.$request['author_uid'].':'.self::key($request['operation_key'],96);
        return $this->transactions->run(function()use($request,$authorize,$apply,$json,$hash,$key){
            $this->writable();$this->lockSession($request['session_id']);$draft=$this->draft($request['draft_id'],$request['author_uid']);
            if(!$draft||$draft['session_id']!==$request['session_id'])throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            $authorize($draft);
            $old=$this->sql('SELECT kind,payload_hash,status,result_json FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
            if($old){if($old['kind']!=='seal.'.$request['op']||!hash_equals($hash,$old['payload_hash']))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');if($old['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');return json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);}
            if((int)$this->sql('SELECT COUNT(*) AS n FROM '.$this->table('operations').' WHERE operation_key LIKE ? AND updated_at>?',['seal:'.$request['author_uid'].':%',time()-60])['rows'][0]['n']>=60)throw new RuntimeException('ACS_RATE_LIMIT');
            if($draft['draft_version']!==$request['expected_draft_version'])throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
            if(!in_array($draft['status'],['draft','sealed'],true)||$draft['pid']!==null)throw new RuntimeException('ACS_DRAFT_READ_ONLY');
            $this->sealWriting=true;
            try{$result=$apply($draft,$key);}finally{$this->sealWriting=false;}
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$request['session_id'],$draft['encounter_id'],'seal.'.$request['op'],$hash,'committed',$json,self::json($result),time()]);
            return $result;
        });
    }
    private bool $sealWriting=false;
    public function appendSeal(array $draft,array $action,array $definition,array $pin,int $cost,string $key,int $historyIndex,array $tape):string
    {
        if(!$this->sealWriting)throw new RuntimeException('ACS_COMMAND_REQUIRED');
        $snapshot=$this->insertSnapshot($draft['session_id'],['source_system'=>'knowledgebase','source_key'=>$action['ability_key'],'contract_id'=>'ic-acs.sealed-definition.v1','contract_version'=>'1'],['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'definition'=>$definition]);
        $payload=['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'rng_version'=>'uniform53-v1','tape_hash'=>hash('sha256',self::json(['schema_version'=>1,'tape'=>$tape])),'history_index'=>$historyIndex,'ap_cost'=>$cost,'action'=>$action,'pin'=>$pin,'definition_snapshot_id'=>$snapshot];$json=self::json($payload);
        $id=$this->sql('INSERT INTO '.$this->table('sealed_actions').' (draft_id,action_index,actor_entity_id,definition_snapshot_id,seal_operation_key,ap_cost,zero_ap_limit_key,status,sealed_hash,payload_json,created_at) VALUES (?,?,?,?,?,?,NULL,?,?,?,?)',[$draft['id'],$historyIndex,$action['actor_id'],$snapshot,$key.':action:'.$historyIndex,$cost,'sealed',hash('sha256',$json),$json,time()])['id'];
        foreach(['hit','crit'] as $kind)$this->sql('INSERT INTO '.$this->table('rolls').' (session_id,sealed_action_id,causal_key,check_kind,roll_index,rng_version,value_json,created_at) VALUES (?,?,?,?,0,?,?,?)',[$draft['session_id'],$id,'sealed-action:'.$id,$kind,$tape['rng_version'],self::json(['schema_version'=>1]+$tape[$kind]),time()]);return $id;
    }
    public function cancelSeal(string $id):void
    {
        if(!$this->sealWriting)throw new RuntimeException('ACS_COMMAND_REQUIRED');
        if($this->sql('UPDATE '.$this->table('sealed_actions')." SET status='cancelled' WHERE id=? AND status='sealed'",[$id])['affected']!==1)throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
    }
    public function projectSeal(array $draft,array $actions,array $hashes,?string $confirmation):array
    {
        if(!$this->sealWriting)throw new RuntimeException('ACS_COMMAND_REQUIRED');self::integer($draft['draft_version'],'18446744073709551614');
        $dep=json_decode($draft['dependency_json'],true,32,JSON_THROW_ON_ERROR);$dep['seal']=$hashes;
        $active=count($this->sealRows($draft['id'],true));$status=$active>0&&$active===count($actions)?'sealed':'draft';
        if($this->sql('UPDATE '.$this->table('drafts').' SET mechanics_json=?,dependency_json=?,status=?,confirmation_hash=?,draft_version=draft_version+1,updated_at=? WHERE id=? AND draft_version=?',[self::json(['schema_version'=>1,'actions'=>$actions]),self::json($dep),$status,$confirmation,time(),$draft['id'],$draft['draft_version']])['affected']!==1)throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
        return $this->draft($draft['id'],$draft['author_uid']);
    }
}
