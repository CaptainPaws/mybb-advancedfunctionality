<?php
if (!defined('IN_MYBB')) {die('No direct access');}
/** Uses af_acs_operations, never a second balance/accounting ledger. */
trait AF_ACS_RewardOperations
{
    public function rewardRegister(array $session,array $envelope):array
    {
        $this->transactions->assertInternalBoundary();$this->writable();
        require_once __DIR__.'/EncounterRepository.php';$repo=new AF_ACS_EncounterRepository($this->connection);
        $r=$repo->rewardEvidence($session['id'],$envelope['reward_id']);$i=$envelope['intent'];
        if($r['status']!=='pending'||$i['operation_key']!==$r['operation_key']||$i['source_ref']!=='acs-reward:'.$r['id']||$i['uid']!==$r['payload']['recipient']['uid']||$i['amount_minor']!==$r['payload']['recipient']['amount_minor']||$i['kind']!==AF_ACS_RewardDistribution::kind($r['reward_type']))throw new RuntimeException('ACS_REWARD_INTEGRITY');
        $json=self::json($envelope);$key=$i['operation_key'];
        if($this->get($key))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
        $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,kind,external_system,external_ref,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?,?)',[$key,$session['id'],'balance.credit','balance',$key,hash('sha256',$json),'prepared',$json,self::json(['schema_version'=>1]),time()]);return $this->get($key);
    }
    public function rewardTransition(string $sid,string $id,string $expected,string $status,array $receipt,callable $authorize):array
    {
        return $this->transactions->run(function()use($sid,$id,$expected,$status,$receipt,$authorize){
            $this->writable();$s=$this->lockSession($sid);$authorize($s);
            require_once __DIR__.'/EncounterRepository.php';$repo=new AF_ACS_EncounterRepository($this->connection);$r=$repo->rewardIntentEvidence($sid,$id);$op=$r['operation'];
            if(!in_array($status,['recovery_required','committed'],true))throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED');
            if($op['operation_version']!==self::integer($expected))throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($status==='recovery_required'){
                if($op['status']!=='prepared'||$r['status']!=='prepared')throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED');
                $result=['schema_version'=>1,'phase'=>'owner_dispatch'];$rewardStatus='recovery_required';
            }else{
                if($op['status']!=='recovery_required'||$r['status']!=='recovery_required')throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED');
                AF_ACS_RewardDistribution::receipt($r['intent'],$r['authority'],$receipt);$result=['schema_version'=>1,'owner_receipt'=>$receipt];$rewardStatus='issued';
            }
            if($this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND operation_version=?',[$status,self::json($result),time(),$op['operation_key'],$expected])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            if($this->sql('UPDATE '.$this->table('rewards').' SET status=?,reward_version=reward_version+1 WHERE id=? AND reward_version=? AND status=?',[$rewardStatus,$id,$r['reward_version'],$r['status']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $this->rewardAudit($s,$op['operation_key'],$id,$status,(string)((int)$expected+1),$result);return $this->get($op['operation_key']);
        });
    }
    private function rewardAudit(array $s,string $key,string $id,string $status,string $version,array $evidence):void
    {
        $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?)',[$s['id'],$s['next_sequence'],'reward:'.hash('sha256',$key.':'.$version),'reward.'.$status,(int)($GLOBALS['mybb']->user['uid']??0),'acs-rewards-1',time()])['id'];
        $json=self::json(['schema_version'=>1,'reward_id'=>$id,'operation_key'=>$key,'operation_version'=>$version,'status'=>$status,'evidence'=>$evidence]);$visibility=self::json(['schema_version'=>1,'scope'=>'session_management']);
        $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,1,?,?,?,?)',[$event,'binary64-half_up-v1',$json,$visibility,hash('sha256',$json."\n".$visibility)]);
        if($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',[time(),$s['id'],$s['session_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
    }
}
