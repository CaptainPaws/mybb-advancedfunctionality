<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/Achievements.php';
/** Read-only source/pin proofs shared by earning, issuer and review. */
trait AF_ACS_AchievementSources
{
    public function achievementSource(string $sid,string $eid,string $id):array
    {
        $event=$this->event($id);
        if(!$event||(string)$event['session_id']!==$sid||(string)$event['encounter_id']!==$eid||!hash_equals($event['content_hash'],hash('sha256',$event['payload_json']."\n".$event['visibility_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
        $body=json_decode($event['payload_json'],true,64,JSON_THROW_ON_ERROR);$actors=[];$outcome=null;$damage=0;
        if($event['event_type']==='combat.resolved'){
            $row=$this->sql('SELECT c.submission_id,c.result_hash,c.payload_json,s.status,r.receipt_json,r.receipt_hash,o.result_json AS operation_result,o.request_json,o.payload_hash AS operation_hash FROM '.$this->table('combat_results').' c JOIN '.$this->table('submissions').' s ON s.submission_id=c.submission_id JOIN '.$this->table('combat_receipts').' r ON r.submission_id=c.submission_id JOIN '.$this->table('operations')." o ON o.operation_key=? AND o.kind='combat.commit' AND o.status='committed' WHERE c.event_id=? AND s.session_id=? AND s.encounter_id=?",[$event['operation_key'],$id,$sid,$eid])['rows'][0]??null;
            if(!$row||!hash_equals($row['operation_hash'],hash('sha256',$row['request_json']))||($body['submission_id']??null)!==$row['submission_id']||$row['status']!=='committed'||$row['payload_json']!==$event['payload_json']||!hash_equals($row['result_hash'],hash('sha256',$row['payload_json']))||!hash_equals($row['receipt_hash'],hash('sha256',$row['receipt_json']))||($body['persistent']??false)!==true)throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
            $receipt=json_decode($row['receipt_json'],true,64,JSON_THROW_ON_ERROR);$operation=json_decode($row['operation_result'],true,64,JSON_THROW_ON_ERROR);
            if(($operation['boundary_key']??null)!==$event['operation_key'])throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');unset($operation['boundary_key']);
            if(AF_ACS_Achievements::canonical($operation)!==AF_ACS_Achievements::canonical($receipt))throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
            if(($receipt['submission_id']??null)!==$row['submission_id']||$event['submission_id']!==$row['submission_id']||($receipt['event_id']??null)!==$id||($receipt['result_hash']??null)!==$row['result_hash']||($receipt['status']??null)!=='COMMITTED')throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
            foreach($body['plan']['actions'] as $a){$actors[]=(string)$a['actor_id'];$n=$a['allocation']['integer_budget']??0;if(!is_int($n)||$n<0||$n>9007199254740991-$damage)throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');$damage+=$n;}
            $ctx=$body['achievement_context']??null;$facts=$ctx['facts']??null;$pin=$ctx['config_snapshot_id']??null;$timing='on_confirmed_event';
        }elseif($event['event_type']==='encounter.outcome_confirm'){
            $outcome=$this->confirmedOutcome($sid,$eid)??throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');if($outcome['terminal_event_id']!==$id)throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
            $r=$this->sql('SELECT candidate_json,candidate_hash FROM '.$this->table('encounter_resolutions')." WHERE session_id=? AND encounter_id=? AND status='confirmed'",[$sid,$eid])['rows'][0];
            if(!hash_equals($r['candidate_hash'],hash('sha256',$r['candidate_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
            $facts=json_decode($r['candidate_json'],true,64,JSON_THROW_ON_ERROR)['loot_eligibility']??null;$pin=$outcome['config_snapshot_id'];$outcome=$outcome['outcome'];$timing='on_encounter_completed';
        }else throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_INVALID');
        // Review may inspect legacy sources, but earning requires frozen facts/pin.
        return ['source_event_id'=>$id,'source_hash'=>hash('sha256',$event['payload_json']),'timing'=>$timing,'facts'=>$facts,'config_snapshot_id'=>$pin,'actors'=>array_values(array_unique($actors)),'outcome'=>$outcome,'primary_allocated_damage'=>$damage,'event_type'=>$event['event_type']];
    }
    public function achievementPin(string $sid,string $configId,string $key):array
    {
        $c=$this->configSnapshot($sid,$configId);
        if(!$c||!hash_equals($c['content_hash'],hash('sha256',$c['payload_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_PIN_INVALID');
        $ref=json_decode($c['payload_json'],true,64,JSON_THROW_ON_ERROR)['config']['achievement_sources'][$key]??throw new RuntimeException('ACS_ACHIEVEMENT_PIN_INVALID');
        $pin=$this->achievementDefinitionPin($sid,$ref['snapshot_id'],$ref['hash']);if($pin['definition']['achievement_key']!==$key)throw new RuntimeException('ACS_ACHIEVEMENT_PIN_INVALID');return $pin;
    }
    public function achievementDefinitionPin(string $sid,string $id,?string $hash=null):array
    {
        $s=$this->configSnapshot($sid,$id);
        if(!$s||$hash!==null&&$s['content_hash']!==$hash||!hash_equals($s['content_hash'],hash('sha256',$s['payload_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_PIN_INVALID');
        $graph=json_decode($s['payload_json'],true,64,JSON_THROW_ON_ERROR)['graph']??throw new RuntimeException('ACS_ACHIEVEMENT_PIN_INVALID');$d=AF_ACS_Achievements::definition($graph['definition']);
        return ['definition'=>$d,'snapshot_id'=>$id,'hash'=>$s['content_hash'],'semantics'=>AF_ACS_Achievements::semantics($d),'version'=>$graph['table']['definition_version']['declared_version']];
    }
    public function configureAchievement(array $s,array $e,array $graph):array
    {
        $this->commandWrite();if($s['status']!=='active'||$e['status']!=='preparation'||$e['round_number']!=='0'||$e['start_operation_id']!==null)throw new RuntimeException('ACS_ENCOUNTER_TRANSITION_BLOCKED');
        $key=$graph['definition']['achievement_key'];$c=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        if(isset($c['achievement_sources'][$key])||count($c['achievement_sources']??[])>=16)throw new RuntimeException('ACS_ACHIEVEMENT_ALREADY_PINNED');
        $id=$this->insertSnapshot($s['id'],['source_system'=>'knowledgebase','source_key'=>$key,'contract_id'=>AF_ACS_Achievements::SCHEMA,'contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-achievements-1','graph'=>$graph]);
        $c['achievement_sources'][$key]=['snapshot_id'=>$id,'hash'=>$this->configSnapshot($s['id'],$id)['content_hash']];
        $configId=$this->insertSnapshot($s['id'],['source_system'=>'acs','source_key'=>'achievement-config','contract_id'=>'ic-acs.encounter-config.v1','contract_version'=>'1'],['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-achievements-1','config'=>$c]);
        $this->sql('UPDATE '.$this->table('encounters').' SET config_json=?,config_snapshot_id=?,encounter_version=encounter_version+1 WHERE id=?',[self::json($c),$configId,$e['id']]);
        $s['_command_result']=['encounter_id'=>$e['id'],'achievement_key'=>$key,'definition_snapshot_id'=>$id];return $s;
    }
    public function achievementProgress(string $id):array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('achievement_progress').' WHERE id=?',[self::integer($id)])['rows'][0]??throw new RuntimeException('ACS_ACHIEVEMENT_UNAVAILABLE');
        foreach(['id','definition_snapshot_id','progress_version'] as $k)$r[$k]=(string)$r[$k];
        if(!hash_equals($r['progress_hash'],hash('sha256',$r['progress_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        $r['progress']=json_decode($r['progress_json'],true,64,JSON_THROW_ON_ERROR);return $r;
    }
    public function achievementContribution(string $sid,string $id):array
    {
        $r=$this->sql('SELECT * FROM '.$this->table('achievement_events').' WHERE session_id=? AND id=?',[$sid,$id])['rows'][0]??throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        if(!hash_equals($r['result_hash'],hash('sha256',$r['result_json'])))throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        $b=json_decode($r['result_json'],true,64,JSON_THROW_ON_ERROR);$command=$this->rewardCommandReceipt($sid,(string)$r['encounter_id'],'achievement.evaluate','$.evaluation_key',$b['evaluation_key'])??throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        if((string)$r['event_id']!==$command['result']['event_id']||($command['result']['source_event_id']??null)!==(string)$r['source_event_id']||!in_array(['contribution_id'=>(string)$r['id'],'result'=>$b],$command['result']['contributions'],true))throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        if((string)($this->event((string)$r['event_id'])['cause_event_id']??'')!==(string)$r['source_event_id'])throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        $source=$this->achievementSource($sid,(string)$r['encounter_id'],(string)$r['source_event_id']);$pin=$this->achievementPin($sid,$source['config_snapshot_id'],$b['achievement_key']);
        if($source['source_hash']!==$b['source_hash']||$pin['snapshot_id']!==(string)$r['definition_snapshot_id']||$pin['hash']!==$b['definition_hash']||!in_array($b['subject'],AF_ACS_Achievements::subjects($pin['definition'],$source),true))throw new RuntimeException('ACS_ACHIEVEMENT_INTEGRITY');
        return ['row'=>$r,'result'=>$b,'pin'=>$pin,'source'=>$source];
    }
}
