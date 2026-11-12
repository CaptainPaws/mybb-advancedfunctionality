<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Only invoked by EnvironmentService inside SessionRepository::command. */
trait AF_ACS_ObjectCommands
{
    public function changeObject(array $session,array $encounter,array $r):array
    {
        $this->commandWrite();$objects=$this->objectProjection($session['id'],$encounter['id']);$id=$r['entity_id'];
        if(!isset($objects[$id]))throw new RuntimeException('ACS_OBJECT_UNAVAILABLE');
        $state=['schema_version'=>1,'entities'=>[],'groups'=>[],'barriers'=>[],'next_barrier_order'=>1,'objects'=>$objects];
        $result=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'object_operation','entity_id'=>$id,'operation'=>$r['operation'],'expected_version'=>(int)$r['expected_object_version'],'amount'=>$r['amount'],'damage_type'=>$r['damage_type']]]]);
        $changes=$this->persistObjects($session['id'],$encounter['id'],$objects,$result->serverState()['objects']);
        $session=$this->change($session,$encounter,'environment_change',[]);$session['_command_result']+=['objects'=>$changes,'trace'=>$result->serverTrace(),'reason'=>$r['reason']];return $session;
    }
    /** Source and ability must be retained server pins; never accept a client JSON definition. */
    public function objectCreationPlan(string $sid,string $eid,string $source,string $abilityKey):array
    {
        $row=$this->entity($sid,$source);
        if(!$row||!$this->participantExists($eid,$source)||$row['life_state']!=='active'||!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_OBJECT_SOURCE_INVALID');
        $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$dto=$p['pinned_abilities'][$abilityKey]??throw new RuntimeException('ACS_OBJECT_UNSUPPORTED');
        $ability=AF_ACS_Spawn::compile($dto);
        foreach($ability['spawns'] as $binding){
            if($binding['spec']['kind']!=='spawn_object')throw new RuntimeException('ACS_OBJECT_UNSUPPORTED');
            $m=AF_ACS_Environment::definition(AF_ACS_Spawn::template($binding['spec'],$binding['template'])['properties']);
            if(!in_array('create',$m['gm_operations'],true))throw new RuntimeException('ACS_OBJECT_UNSUPPORTED');
        }
        $participant=$this->sql('SELECT side_id,participation FROM '.$this->table('participants').' WHERE encounter_id=? AND entity_id=?',[$eid,$source])['rows'][0];
        if($participant['participation']!=='engaged')throw new RuntimeException('ACS_OBJECT_SOURCE_INVALID');
        $s=['kind'=>$row['kind'],'control_group_id'=>$row['control_group_id'],'side_id'=>(string)$participant['side_id']];$counts=$this->spawnCounts($sid,$eid);
        $plan=AF_ACS_Spawn::plan($ability,$source,0,$s,$counts);
        return ['prepared'=>['ordered'=>[['ability_key'=>$abilityKey]],'context'=>['abilities'=>[$source=>[$abilityKey=>$ability]],'spawn_sources'=>[$source=>$s]]],'plan'=>['spawns'=>$plan]];
    }
    public function createObjectsAtEvent(array $session,string $event,array $encounter,array $r):array
    {
        $this->transactions->assertInternalBoundary();
        if(!$this->sql('SELECT id FROM '.$this->table('events').' WHERE id=? AND session_id=? AND encounter_id=? AND event_type=? AND actor_uid=?',[$event,$session['id'],$encounter['id'],'encounter.environment_create',$r['actor_uid']])['rows'])throw new RuntimeException('ACS_OBJECT_IDENTITY_INVALID');
        $plan=$this->objectCreationPlan($session['id'],$encounter['id'],$r['source_entity_id'],$r['ability_key']);
        $spawns=$this->commitSpawns(['session_id'=>$session['id'],'encounter_id'=>$encounter['id'],'turn_id'=>null],$event,$plan['prepared'],$plan['plan']);
        $session['_command_result']+=['spawns'=>$spawns,'reason'=>$r['reason']];return $session;
    }
}
