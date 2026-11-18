<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/EncounterRepository.php';
require_once __DIR__.'/../Application/SessionService.php';

/** Read original native issue arguments, never an owner envelope or a replacement operation. */
final class AF_ACS_CompletionQuery extends AF_ACS_Repository
{
    public function retainedIssue(string $sid,string $eid,string $id,string $kind,AF_ACS_SessionAccess $access):array
    {
        $scope=(new AF_ACS_SessionService(new AF_ACS_SessionRepository($this->connection),$access))->view($sid);
        if(!$scope['can_audit'])throw new RuntimeException('ACS_GM_REQUIRED');
        $repo=new AF_ACS_EncounterRepository($this->connection);
        if($kind==='loot'){
            $proof=$repo->lootIssueIntent($sid,$eid,$id);$issuer=(string)$proof['intent']['actor']['uid'];
            $command='loot.issue_prepare';$path='$.issue.entry_id';
            $fields=['session_id','encounter_id','entry_id','expected_version','expected_allocation_version','operation_key','reason'];
        }elseif($kind==='reward'){
            $proof=$repo->rewardIntentEvidence($sid,$id);
            if($proof['encounter_id']!==$eid)throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            $issuer=$proof['envelope']['issuer_uid'];$command='reward.prepare';$path='$.reward_prepare.reward_id';
            $fields=['session_id','reward_id','expected_version','expected_reward_version','operation_key','reason'];
        }else throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        if($kind==='loot'&&$issuer!==$access->uid())throw new RuntimeException('ACS_LOOT_ISSUER_REQUIRED');
        $rows=$this->sql('SELECT operation_key,request_json,payload_hash FROM '.$this->table('operations')." WHERE session_id=? AND encounter_id=? AND kind=? AND status='committed' AND JSON_UNQUOTE(JSON_EXTRACT(result_json,?))=? LIMIT 2",[$sid,$eid,$command,$path,$id])['rows'];
        if(count($rows)!==1||!hash_equals($rows[0]['payload_hash'],hash('sha256',$rows[0]['request_json'])))throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        $original=json_decode($rows[0]['request_json'],true,64,JSON_THROW_ON_ERROR);
        if(($original['actor_uid']??null)!==$issuer||($original['session_id']??null)!==$sid||$rows[0]['operation_key']!=='session:'.$issuer.':'.($original['operation_key']??''))throw new RuntimeException('ACS_RECOVERY_REQUIRED');
        $out=[];foreach($fields as $field){if(!isset($original[$field])||!is_string($original[$field]))throw new RuntimeException('ACS_RECOVERY_REQUIRED');$out[$field]=$original[$field];}
        return $out;
    }
    /** Server queue intersected with this encounter's stored event ids; no client event catalogue. */
    public function achievementSources(string $sid,string $eid,AF_ACS_SessionAccess $access):array
    {
        $scope=(new AF_ACS_SessionService(new AF_ACS_SessionRepository($this->connection),$access))->view($sid);
        if(!$scope['can_audit'])throw new RuntimeException('ACS_GM_REQUIRED');
        $repo=new AF_ACS_EncounterRepository($this->connection);
        if(!$repo->encounter($sid,$eid))throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $events=$this->sql('SELECT id,event_type FROM '.$this->table('events')." WHERE session_id=? AND encounter_id=? AND event_type IN ('combat.resolved','encounter.outcome_confirm') ORDER BY id LIMIT 201",[$sid,$eid])['rows'];
        if(count($events)>200)throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_LIMIT');
        $types=[];foreach($events as $event)$types[(string)$event['id']]=$event['event_type'];$out=[];
        foreach($repo->achievementObligations($sid) as $obligation){
            if($obligation['state']==='explicitly_blocked')throw new RuntimeException('ACS_ACHIEVEMENT_SOURCE_LIMIT');
            if(preg_match('/\Aachievement:([a-z][a-z0-9_.-]{2,95}):source:([1-9][0-9]*)\z/D',$obligation['reference'],$m)&&isset($types[$m[2]]))$out[]=['achievement_key'=>$m[1],'source_event_id'=>$m[2],'event_type'=>$types[$m[2]]];
        }
        return $out;
    }

}
