<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/PreparedExecutionAccess.php';
/** Shared source authorization and explicit reveal; mechanics stay in Resolver. */
trait AF_ACS_PreparedExecutionSupport
{
    private function causalInspection(array $candidate,array $parent):array
    {
        $uid=$candidate['armed']['originating_uid'];$sid=$candidate['session_id'];$actor=$candidate['entity_id'];$p=json_decode($candidate['contract_json'],true,64,JSON_THROW_ON_ERROR);
        $origin=$this->sql('SELECT actor_uid FROM '.$this->table('events').' WHERE id=? AND session_id=? AND encounter_id=? AND event_type=?',[$candidate['source_event_id'],$sid,$candidate['encounter_id'],'encounter.prepared_register'])['rows'][0]??null;if(!$origin||(string)$origin['actor_uid']!==$uid||$candidate['source_event_id']!==$candidate['armed']['source_event_id'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        $seal=$this->sql('SELECT * FROM '.$this->table('sealed_actions').' WHERE id=?',[$candidate['source_sealed_action_id']])['rows'][0]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');$source=$this->preparedRepository()->sealPayload($seal);$this->preparedRepository()->verifySealDefinition($sid,$seal,$source);
        if($seal['sealed_hash']!==$p['source_sealed_hash']||(string)$seal['definition_snapshot_id']!==$p['definition_snapshot_id']||$source['action']['actor_id']!==$actor||$source['action']['ability_key']!==$p['ability_pin']['key']||$source['action']['targets']!==[$p['target_entity_id']])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        if(!AF_ACS_FormLayers::ability($this->preparedRepository()->statusProjection($sid)['instances'],$actor,$p['ability_pin']['key'],json_decode($this->sql('SELECT payload_json FROM '.$this->table('snapshots').' WHERE id=? AND session_id=?',[$seal['definition_snapshot_id'],$sid])['rows'][0]['payload_json'],true,64,JSON_THROW_ON_ERROR)['definition']['data']['definition']['form_requirement']??null))throw new RuntimeException('ACS_FORM_ABILITY_UNAVAILABLE');
        $entity=$this->preparedRepository()->entity($sid,$actor);if(!$entity||$entity['control_group_id']!==(string)$candidate['control_group_id'])throw new RuntimeException('ACS_CONTROL_INVALID');
        $readAccess=new AF_ACS_PreparedExecutionAccess($this->preparedAccess(),$uid);$readAccess->draftThread($this->preparedRepository()->session($sid)['tid']);
        if(!$this->preparedRepository()->roles($sid,$uid)||!($grant=$this->preparedRepository()->controllerFor($sid,$actor,$uid)))throw new RuntimeException('ACS_CONTROL_INVALID');
        $key=$p['ability_pin']['key'];AF_ACS_Control::action($grant,$key);
        $q=new AF_ACS_QueryService($this->preparedRepository(),$readAccess);$inspection=$q->inspect($sid,$candidate['encounter_id'],$actor,$p['target_entity_id'],null,false,true,isset($candidate['armed']['start_operation_id']));
        $ability=$inspection['context']['abilities'][$actor][$key]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        if($ability['source_ref']!==$p['ability_pin']||($ability['prepared_action'][isset($candidate['armed']['start_operation_id'])?'start_execution':'execution']??null)!==$candidate['armed']['policy'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
        unset($ability['prepared_action']);$ability['costs']=[];
        foreach($p['additional_costs'] as $cost)if($cost['kind']==='resource'&&$cost['timing']==='on_trigger')$ability['costs'][]=['kind'=>'resource','resource'=>$cost['key'],'amount'=>$cost['amount']];
        $context=$inspection['context'];$context['abilities']=[$actor=>[$key=>$ability]];$context['reactions']=[];$context['entity_end_boundaries']=[];
        $context['prepared_execution']=['actor_id'=>$actor,'ability_key'=>$key,'targets'=>[$p['target_entity_id']],'policy'=>$candidate['armed']['policy']];
        $ordered=[['actor_id'=>$actor,'ability_key'=>$key,'targets'=>[$p['target_entity_id']]]];
        return ['context'=>$context,'ordered'=>$ordered,'contract'=>$p,'dependencies'=>$inspection['versions'],'has_reactions'=>(bool)$inspection['context']['reactions']];
    }

    private function causalCondition(Throwable $e):string
    {
        $code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
        if((!$e instanceof RuntimeException&&!$e instanceof DomainException)||$e instanceof mysqli_sql_exception
            ||in_array($code,['ACS_DATABASE_ERROR','ACS_ITEM_OWNER_RESULT_ORPHAN','ACS_SEAL_TAPE_INVALID','ACS_SEAL_PIN_CHANGED','ACS_SNAPSHOT_HASH_MISMATCH'],true)
            ||str_starts_with($code,'ACS_FORM_')&&$code!=='ACS_FORM_ABILITY_UNAVAILABLE'
            ||str_starts_with($code,'ACS_CAUSAL_')||str_starts_with($code,'INV_PREPARED_')&&!in_array($code,['INV_PREPARED_REVOKED','INV_PREPARED_EXPIRED','INV_PREPARED_VERSION_CONFLICT'],true))throw $e;
        return preg_match('/\A(?:ACS_[A-Z_]+|[a-z][a-z0-9_]+|INV_[A-Z_]+)\z/D',$code)?$code:'ACS_PREPARED_CONDITION_FAILED';
    }

    private function revealPrepared(array $candidate,array $event,array &$state):?array
    {
        $contract=json_decode($candidate['contract_json'],true,64,JSON_THROW_ON_ERROR);$policy=$contract['hidden']['reveal_on_trigger']??null;if($policy===null)return null;
        AF_ACS_PreparedAction::hidden($contract['hidden']);$subject=$state['perception_subjects']['prepared_action:'.$candidate['id']]??throw new RuntimeException('ACS_CAUSAL_PREPARED_SUBJECT_MISSING');
        $actorId=$policy['relative_to']==='source'?$candidate['entity_id']:$event['attacker'];$actor=$state['perception_subjects']['entity:'.$actorId]['source']??throw new RuntimeException('ACS_CAUSAL_PREPARED_AUDIENCE_MISSING');
        AF_ACS_Awareness::disclose($state,$subject,$actor,$policy['audience'],$policy['level'],$subject['perception']['disclosure'][$policy['level']]);
        return ['subject_kind'=>'prepared_action','subject_id'=>$candidate['id'],'audience_kind'=>$policy['audience'],'audience_id'=>AF_ACS_Awareness::audiences($actor)[$policy['audience']][0],'level'=>$policy['level'],'source_identity_revealed'=>false];
    }

    private function causalSavepoint(string $phase):void
    {
        $query=match($phase){'start'=>'SAVEPOINT prepared_causal','rollback'=>'ROLLBACK TO SAVEPOINT prepared_causal','release'=>'RELEASE SAVEPOINT prepared_causal'};
        try{if(!$this->connection->query($query))throw new RuntimeException('ACS_DATABASE_ERROR');}catch(mysqli_sql_exception $e){throw new RuntimeException('ACS_DATABASE_ERROR',(int)$e->getCode());}
    }
}
