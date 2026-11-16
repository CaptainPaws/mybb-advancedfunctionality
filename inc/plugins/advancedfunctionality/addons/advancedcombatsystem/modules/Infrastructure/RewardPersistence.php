<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/RewardDistribution.php';
require_once __DIR__.'/OperationStore.php';

trait AF_ACS_RewardPersistence
{
    public function rewardExternalBoundary():void {$this->transactions->assertExternalBoundary();}
    public function configureReward(array $s,array $e,string $key,array $graph):array
    {
        $this->commandWrite();
        if($s['status']!=='active'||$e['status']!=='preparation'||$e['round_number']!=='0'||$e['start_operation_id']!==null)throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        $config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        if(isset($config['reward_sources'][$key])||count($config['reward_sources']??[])>=16)throw new RuntimeException('ACS_REWARD_ALREADY_PINNED');
        $snapshot=$this->insertSnapshot($s['id'],['source_system'=>'knowledgebase','source_key'=>$key,'contract_id'=>AF_ACS_RewardDistribution::SCHEMA,'contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-rewards-1','graph'=>$graph]);
        $config['reward_sources'][$key]=['snapshot_id'=>$snapshot,'hash'=>$this->sql('SELECT content_hash FROM '.$this->table('snapshots').' WHERE id=?',[$snapshot])['rows'][0]['content_hash']];
        $pin=$this->insertSnapshot($s['id'],['source_system'=>'acs','source_key'=>'encounter-reward-config','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-rewards-1','config'=>$config]);
        $this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=?,encounter_version=encounter_version+1 WHERE id=?',[self::json($config),$pin,$e['id']]);
        $s['_command_result']=['encounter_id'=>$e['id'],'kb_key'=>$key,'definition_snapshot_id'=>$snapshot];return $s;
    }
    public function rewardRow(string $sid,string $id):array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('rewards').' WHERE session_id=? AND id=?',[self::integer($sid),self::integer($id)])['rows'][0]??throw new RuntimeException('ACS_REWARD_UNAVAILABLE');
        foreach(['id','session_id','encounter_id','reward_version'] as $k)$r[$k]=(string)$r[$k];
        if(!hash_equals($r['payload_hash'],hash('sha256',$r['payload_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $p=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($p['session_id']??null)!==$sid||($p['encounter_id']??null)!==$r['encounter_id']||($p['reward_key']??null)!==$r['reward_key']||($p['reward_type']??null)!==$r['reward_type']||($p['recipient']['recipient_key']??null)!==$r['recipient_key'])throw new RuntimeException('ACS_REWARD_INTEGRITY');
        return $r+['payload'=>$p];
    }
    public function rewardRows(string $sid,string $eid,string $after='0'):array
    {
        return $this->sql('SELECT id,status,reward_version,reward_key,recipient_key,reward_type,operation_key FROM '.$this->table('rewards').' WHERE session_id=? AND encounter_id=? AND id>? ORDER BY id LIMIT 100',[self::integer($sid),self::integer($eid),self::integer($after,zero:true)])['rows'];
    }
    /** Immutable command receipt + audit, not a mutable projection used as authority. */
    public function rewardCommandReceipt(string $sid,string $eid,string $kind,string $path,string $value):?array
    {
        $rows=$this->sql('SELECT o.*,e.id AS event_id,e.actor_uid,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('operations').' o JOIN '.$this->table('events').' e ON e.operation_key=o.operation_key AND e.session_id=o.session_id AND e.encounter_id=o.encounter_id AND e.event_type=o.kind JOIN '.$this->table('event_details')." d ON d.event_id=e.id WHERE o.session_id=? AND o.encounter_id=? AND o.kind=? AND o.status='committed' AND JSON_UNQUOTE(JSON_EXTRACT(o.result_json,?))=? LIMIT 2",[$sid,$eid,$kind,$path,$value])['rows'];
        if(!$rows)return null;if(count($rows)!==1)throw new RuntimeException('ACS_REWARD_INTEGRITY');$r=$rows[0];
        if(!hash_equals($r['payload_hash'],hash('sha256',$r['request_json']))||!hash_equals($r['content_hash'],hash('sha256',$r['payload_json']."\n".$r['visibility_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $result=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);$request=json_decode($r['request_json'],true,64,JSON_THROW_ON_ERROR);$audit=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(($result['event_id']??null)!==(string)$r['event_id']||($result['session_id']??null)!==$sid||($result['encounter_id']??null)!==$eid||($request['actor_uid']??null)!==(string)$r['actor_uid']||($audit['request']??null)!==$request)throw new RuntimeException('ACS_REWARD_INTEGRITY');
        foreach($audit['result'] as $k=>$v)if(($result[$k]??null)!==$v)throw new RuntimeException('ACS_REWARD_INTEGRITY');
        return ['request'=>$request,'result'=>$result];
    }
    public function rewardPlanFacts(string $sid,string $eid,string $key):array
    {
        $outcome=$this->confirmedOutcome($sid,$eid)??throw new RuntimeException('ACS_REWARD_CONFIRMED_OUTCOME_REQUIRED');
        $pin=$this->configSnapshot($sid,$outcome['config_snapshot_id']);
        if(!$pin||!hash_equals($pin['content_hash'],hash('sha256',$pin['payload_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $config=json_decode($pin['payload_json'],true,64,JSON_THROW_ON_ERROR)['config'];$ref=$config['reward_sources'][$key]??throw new RuntimeException('ACS_REWARD_SOURCE_UNAVAILABLE');
        $snap=$this->configSnapshot($sid,$ref['snapshot_id']);
        if(!$snap||$snap['content_hash']!==$ref['hash']||!hash_equals($snap['content_hash'],hash('sha256',$snap['payload_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $graph=json_decode($snap['payload_json'],true,64,JSON_THROW_ON_ERROR)['graph'];$d=AF_ACS_RewardDistribution::definition($graph['definition']);
        $res=$this->sql('SELECT candidate_json,candidate_hash FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='confirmed'",[$sid,$eid])['rows'][0];
        if(!hash_equals($res['candidate_hash'],hash('sha256',$res['candidate_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $facts=json_decode($res['candidate_json'],true,64,JSON_THROW_ON_ERROR)['loot_eligibility']??throw new RuntimeException('ACS_REWARD_ELIGIBILITY_UNAVAILABLE');
        return ['outcome'=>$outcome,'definition'=>$d,'definition_snapshot_id'=>$ref['snapshot_id'],'definition_hash'=>$ref['hash'],'eligibility_hash'=>hash('sha256',self::json(['schema_version'=>1,'facts'=>$facts])),'eligible'=>AF_ACS_RewardDistribution::eligible($d,$facts,$outcome['outcome'])];
    }
    public function rewardPlanReplay(string $sid,string $eid,string $key,array $manual):?array
    {
        $saved=$this->rewardCommandReceipt($sid,$eid,'reward.distribute','$.reward_plan.kb_key',$key);if(!$saved)return null;
        if($saved['request']['allocations']!==$manual)throw new RuntimeException('ACS_REWARD_ALLOCATION_CLOSED');return $saved['result'];
    }
    public function distributeReward(array $s,array $e,string $key,array $manual):array
    {
        $this->commandWrite();if($s['status']!=='active'||$e['status']!=='completed')throw new RuntimeException('ACS_REWARD_CONFIRMED_OUTCOME_REQUIRED');
        $f=$this->rewardPlanFacts($s['id'],$e['id'],$key);$alloc=AF_ACS_RewardDistribution::allocate($f['definition'],$f['eligible'],$manual);
        $rewardKey='reward:'.hash('sha256',$s['id'].':'.$e['id'].':'.$f['outcome']['outcome_id'].':'.$key);$rows=[];
        foreach($alloc as $recipient){
            $payload=['schema_version'=>1,'session_id'=>$s['id'],'encounter_id'=>$e['id'],'kb_key'=>$key,'reward_key'=>$rewardKey,'reward_type'=>$f['definition']['reward_type'],'mode'=>$f['definition']['mode'],'recipient'=>$recipient,'outcome_id'=>$f['outcome']['outcome_id'],'source_event_id'=>$f['outcome']['terminal_event_id'],'definition_snapshot_id'=>$f['definition_snapshot_id'],'definition_hash'=>$f['definition_hash'],'eligibility_hash'=>$f['eligibility_hash']];
            $json=self::json($payload);$ownerKey='acs:reward:'.hash('sha256',$rewardKey.':'.$recipient['recipient_key'].':'.$payload['reward_type']);
            $status=$payload['reward_type']==='permanent_sync'?'blocked':'pending';
            $id=$this->sql('INSERT INTO '.$this->table('rewards').' (session_id,encounter_id,reward_key,recipient_key,reward_type,status,reward_version,operation_key,payload_json,payload_hash) VALUES (?,?,?,?,?,?,1,?,?,?)',[$s['id'],$e['id'],$rewardKey,$recipient['recipient_key'],$payload['reward_type'],$status,$ownerKey,$json,hash('sha256',$json)])['id'];
            $rows[]=['reward_id'=>$id,'payload'=>$payload,'initial_status'=>$status];
        }
        $s['_command_result']=['encounter_id'=>$e['id'],'reward_plan'=>['kb_key'=>$key,'reward_key'=>$rewardKey,'eligible'=>$f['eligible'],'allocations'=>$alloc,'rewards'=>$rows,'blocked_reason'=>$f['definition']['reward_type']==='permanent_sync'?'charactersheets_durable_sync_api_missing':null]];return $s;
    }
    public function rewardEvidence(string $sid,string $id):array
    {
        $r=$this->rewardRow($sid,$id);$p=$r['payload'];if(($p['source_kind']??null)==='achievement')return $this->achievementRewardEvidence($r);$f=$this->rewardPlanFacts($sid,$r['encounter_id'],$p['kb_key']);
        $saved=$this->rewardCommandReceipt($sid,$r['encounter_id'],'reward.distribute','$.reward_plan.kb_key',$p['kb_key'])??throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $plan=$saved['result']['reward_plan'];$alloc=AF_ACS_RewardDistribution::allocate($f['definition'],$f['eligible'],$saved['request']['allocations']);
        if($plan['eligible']!==$f['eligible']||$plan['allocations']!==$alloc||$p['definition_hash']!==$f['definition_hash']||$p['definition_snapshot_id']!==$f['definition_snapshot_id']||$p['eligibility_hash']!==$f['eligibility_hash']||$p['outcome_id']!==$f['outcome']['outcome_id']||$p['source_event_id']!==$f['outcome']['terminal_event_id']||$p['reward_type']!==$f['definition']['reward_type']||$p['mode']!==$f['definition']['mode'])throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $matched=false;foreach($plan['rewards'] as $entry)if($entry['reward_id']===$id&&$entry['payload']===$p)$matched=true;
        if(!$matched||!in_array($p['recipient'],$alloc,true)||$r['operation_key']!=='acs:reward:'.hash('sha256',$r['reward_key'].':'.$r['recipient_key'].':'.$r['reward_type']))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        return $r;
    }
    private function achievementAwardPayload(array $row,array $body,array $pin,int $ordinal):array
    {
        $d=$pin['definition'];$reward=$d['reward'];
        if($reward===null||$ordinal<=$body['before']['awards']||$ordinal>$body['after']['awards'])throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $key='achievement:'.hash('sha256',$body['achievement_key'].':'.$body['subject_kind'].':'.$body['subject']['subject_key'].':'.$ordinal);
        return ['schema_version'=>1,'source_kind'=>'achievement','session_id'=>(string)$row['session_id'],'encounter_id'=>(string)$row['encounter_id'],'reward_key'=>$key,'reward_type'=>$reward['reward_type'],'mode'=>'each',
            'recipient'=>['recipient_key'=>$body['subject']['subject_key'],'uid'=>$body['subject']['recipient_uid'],'control_group_id'=>null,'entity_ids'=>[],'amount_minor'=>$reward['amount_minor']],
            'achievement_event_id'=>(string)$row['id'],'award_ordinal'=>$ordinal,'source_event_id'=>(string)$row['source_event_id'],'confirmation_ref'=>'acs-achievement:'.$row['id'].':'.$ordinal,'definition_snapshot_id'=>$pin['snapshot_id'],'definition_hash'=>$pin['hash']];
    }
    /** Invoked only by RewardService inside the existing audited achievement command. */
    public function recordAchievementReward(array $session,string $contribution,int $ordinal):string
    {
        $this->commandWrite();$row=$this->sql('SELECT * FROM '.$this->table('achievement_events').' WHERE id=? AND session_id=?',[$contribution,$session['id']])['rows'][0]??throw new RuntimeException('ACS_REWARD_INTEGRITY');
        if(!hash_equals($row['result_hash'],hash('sha256',$row['result_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $body=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);$pin=$this->achievementDefinitionPin($session['id'],(string)$row['definition_snapshot_id'],$body['definition_hash']);
        $p=$this->achievementAwardPayload($row,$body,$pin,$ordinal);$json=self::json($p);$ownerKey='acs:reward:'.hash('sha256',$p['reward_key'].':'.$p['recipient']['recipient_key'].':'.$p['reward_type']);
        return $this->sql('INSERT INTO '.$this->table('rewards')." (session_id,encounter_id,reward_key,recipient_key,reward_type,status,reward_version,operation_key,payload_json,payload_hash) VALUES (?,?,?,?,?,'pending',1,?,?,?)",[$session['id'],$p['encounter_id'],$p['reward_key'],$p['recipient']['recipient_key'],$p['reward_type'],$ownerKey,$json,hash('sha256',$json)])['id'];
    }
    public function achievementRewardEvidence(array $r):array
    {
        $p=$r['payload'];$proof=$this->achievementContribution($r['session_id'],$p['achievement_event_id']);
        $expected=$this->achievementAwardPayload($proof['row'],$proof['result'],$proof['pin'],$p['award_ordinal']);
        if(self::json($expected)!==self::json($p)||$r['operation_key']!=='acs:reward:'.hash('sha256',$r['reward_key'].':'.$r['recipient_key'].':'.$r['reward_type']))throw new RuntimeException('ACS_REWARD_INTEGRITY');return $r;
    }
    public function prepareReward(array $s,string $id,string $version,int $ownerVersion,string $issuer):array
    {
        $this->commandWrite();$r=$this->rewardEvidence($s['id'],$id);
        if($r['reward_type']==='permanent_sync')throw new RuntimeException('ACS_PERMANENT_SYNC_BLOCKED');
        if($r['status']!=='pending'||$r['reward_version']!==$version)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $p=$r['payload'];$intent=['operation_key'=>$r['operation_key'],'uid'=>$p['recipient']['uid'],'kind'=>AF_ACS_RewardDistribution::kind($p['reward_type']),'amount_minor'=>$p['recipient']['amount_minor'],'source_ref'=>'acs-reward:'.$id,'expected_version'=>$ownerVersion];
        $envelope=['schema_version'=>1,'reward_id'=>$id,'issuer_uid'=>$issuer,'intent'=>$intent];
        (new AF_ACS_OperationStore($this->connection))->rewardRegister($s,$envelope);
        if($this->sql('UPDATE '.$this->table('rewards')." SET status='prepared',reward_version=reward_version+1 WHERE id=? AND reward_version=? AND status='pending'",[$id,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $s['_command_result']=['encounter_id'=>$r['encounter_id'],'reward_prepare'=>$envelope];return $s;
    }
    public function rewardIntentEvidence(string $sid,string $id):array
    {
        $r=$this->rewardEvidence($sid,$id);$op=(new AF_ACS_OperationStore($this->connection))->get($r['operation_key'])??throw new RuntimeException('ACS_REWARD_INTEGRITY');
        if($op['session_id']!==$sid||$op['kind']!=='balance.credit'||$op['external_system']!=='balance'||$op['external_ref']!==$r['operation_key']||!hash_equals($op['payload_hash'],hash('sha256',$op['request_json'])))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $env=json_decode($op['request_json'],true,64,JSON_THROW_ON_ERROR);$saved=$this->rewardCommandReceipt($sid,$r['encounter_id'],'reward.prepare','$.reward_prepare.reward_id',$id);
        if(!$saved||($saved['result']['reward_prepare']??null)!==$env||$env['reward_id']!==$id||$env['issuer_uid']!==$saved['request']['actor_uid'])throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $intent=$env['intent'];$p=$r['payload'];$expected=['operation_key'=>$r['operation_key'],'uid'=>$p['recipient']['uid'],'kind'=>AF_ACS_RewardDistribution::kind($p['reward_type']),'amount_minor'=>$p['recipient']['amount_minor'],'source_ref'=>'acs-reward:'.$id,'expected_version'=>$intent['expected_version']??null];
        if(self::json(['schema_version'=>1,'intent'=>$expected])!==self::json(['schema_version'=>1,'intent'=>$intent])||!is_int($intent['expected_version'])||$intent['expected_version']<1||$intent['expected_version']>=PHP_INT_MAX)throw new RuntimeException('ACS_REWARD_INTEGRITY');
        return $r+['operation'=>$op,'envelope'=>$env,'intent'=>$intent,'authority'=>AF_ACS_RewardDistribution::authority($p,$id,$env['issuer_uid'])];
    }
}
