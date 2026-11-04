<?php
declare(strict_types=1);
// Focused publication fixture, known native PID; no Gateway or production endpoint.
$GLOBALS['acsPresentationFixture']=static function(array $fixture):void {
    extract($fixture);global $db,$mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php';
    $actor=$active[0]['actor_id'];$victim=$active[0]['targets'][0];
    $link->query('UPDATE fixture_af_acs_entity_states SET hp=1,life_state=\'active\',state_version=state_version+1 WHERE entity_id='.$victim);
    $link->query('UPDATE fixture_af_acs_resources SET current_value=1,resource_version=resource_version+1 WHERE resource_key=\'hp\' AND entity_id='.$victim);
    $link->query("INSERT INTO fixture_af_acs_resources (entity_id,resource_key,current_value,maximum_value,is_integer,resource_version,definition_json) VALUES (".$actor.",'ep',5,5,1,1,'{\"schema_version\":1}')");
    $seed=new class($link) extends AF_ACS_Repository {
        use AF_ACS_StatusPersistence;
        public function add(string $sid,string $eid,string $source,string $target,array $dto):string {
            return $this->transactions->run(function()use($sid,$eid,$source,$target,$dto){
                $this->writable();$this->lockSession($sid);$p=$this->statusProjection($sid);$rows=[];$groups=[];
                foreach(array_unique([$source,$target]) as $id){
                    $r=$this->sql('SELECT e.control_group_id,s.* FROM '.$this->table('entities').' e JOIN '.$this->table('entity_states').' s ON s.entity_id=e.id WHERE e.session_id=? AND e.id=?',[$sid,$id])['rows'][0];$g=(string)$r['control_group_id'];
                    $groups[$g]=['ap'=>['current'=>0,'maximum'=>0],'version'=>1];$rows[$id]=['hp'=>(int)$r['hp'],'max_hp'=>(int)$r['hp_max'],'life_state'=>$r['life_state'],'recovery_blocked'=>false,
                        'group_id'=>$g,'resources'=>[],'source_ref'=>['key'=>'entity-'.$id,'version'=>'1','fingerprint'=>'sha256:'.str_repeat('a',64)],'version'=>(int)$r['state_version']];
                }
                $state=['schema_version'=>1,'entities'=>$rows,'groups'=>$groups,'barriers'=>[],'next_barrier_order'=>0,'status_instances'=>$p['instances'],'next_status_order'=>$p['next_order']];
                $effect=AF_ACS_KnowledgeBaseAdapter::statusEffect($dto,$dto['data']['ability_profile']['effects'][0]);
                $r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[['kind'=>'status_apply','session_id'=>$sid,'encounter_id'=>$eid,'source'=>$source,'target'=>$target,
                    'spec'=>$effect['instance'],'effect_definition_ref'=>$effect['effect_definition_ref'],'status_definition_ref'=>$effect['status_definition_ref'],'action_index'=>0]]]);
                $snapshot=$this->insertSnapshot($sid,['source_system'=>'knowledgebase','source_key'=>$dto['source']['key'],'contract_id'=>'ic-acs.sealed-definition.v1','contract_version'=>'1'],
                    ['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'definition'=>$dto]);
                $saved=$this->persistStatusInstances($sid,$state['status_instances'],$r->serverState()['status_instances'],[0=>$snapshot]);
                return array_values($saved['id_map'])[0];
            });
        }
    };

    $db->tables['af_kb_entries'][]=snapshot_entry(4000,'arpg_mechanics','hp',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'resource_def','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>'hp','version'=>'1','resource_key'=>'hp','unit'=>'points']]]);
    $status=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'commit-sustained','version'=>'1','type'=>'debuff','scope'=>'combat','duration'=>['value'=>10,'unit'=>'target_turn'],
        'phase'=>'on_turn_start','decrement'=>['event'=>'on_turn_end','amount'=>1],'repeat_rule'=>'refresh','max_stacks'=>1,'removal_conditions'=>[],'expiry_conditions'=>[],'immunities'=>[],'resistances'=>[],'formula_refs'=>[],'events'=>[],'mechanical_parameters'=>[],'periodic_effects'=>[]];
    $db->tables['af_kb_entries'][]=snapshot_entry(4001,'arpg_mechanics','commit-sustained',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'status_def','entries'=>[$status]]);
    $ability=af_kb_default_type_profile_payload_arpg('arpg_ability');$ability=array_replace($ability,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>'single_enemy','max_charges'=>0,'duration_unit'=>'own_turn','cooldown_unit'=>'own_turn',
        'costs'=>[['kind'=>'resource','resource_key'=>'hp','value'=>1,'timing'=>'sustain','period'=>['value'=>1,'unit'=>'own_turn']]],
        'effects'=>[['kind'=>'status','status_key'=>'commit-sustained','base_chance'=>100,'polarity'=>'negative','trigger'=>'on_hit']]]);
    $db->tables['af_kb_entries'][]=snapshot_entry(4002,'arpg_ability','commit-maintain',$ability);
    $dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','commit-maintain',7);snapshot_assert($dto['ready'],'Commit owner DTO not ready');
    $effectId=$seed->add($sid,$eid,$victim,$actor,$dto);$external=$db->tables;
    $mechanics=static function()use($link):array{$out=[];foreach(['effects','entity_states','resources','turns','entity_turns'] as $t)$out[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;};
    $before=$mechanics();$v=$seal->status($d['draft_id']);snapshot_assert($v['confirmable']&&!$v['blocked'],'Sustained/resource projection blocked seal: '.json_encode($v));
    $confirmed=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$v['draft_version'],'operation_key'=>'sustained_commit_confirm','confirmation_hash'=>$v['confirmation_hash']]);
    snapshot_assert($mechanics()===$before&&isset($confirmed['summary']['sustained_state_hash']),'Seal paid upkeep or omitted sustained state binding');
    $baseDraft=$repo->draft($d['draft_id'],'7');$id=str_repeat('b',32);
    $r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$baseDraft['id'],'author_uid'=>'7','expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
    $r=$store->validating($id,$r['state_version'],static function(){});
    $r=$store->posting($id,$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
    $r=$store->confirmPost($id,$r['state_version'],'501',static function(){},'1');$before=$capture();
    $link->query("CREATE TRIGGER sustained_commit_fault BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sustained commit rollback'");
    blocked(fn()=>$writer->commit($id,$r['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER sustained_commit_fault');snapshot_assert($capture()===$before,'Failed writer left a stopped sustained or partial resources');
    $receipt=$writer->commit($id,$r['state_version']);$p=$repo->statusProjection($sid)['instances'][$effectId];
    snapshot_assert($receipt['status']==='COMMITTED'&&$repo->entity($sid,$victim)['life_state']==='incapacitated'&&$p['status']==='removed'&&$p['termination']['reason']==='source_inactive','Combat damage did not stop sustained immediately');
    snapshot_assert(!isset($p['last_upkeep'])&&$repo->statusResources($actor,$repo->entity($sid,$actor))['values']['ep']['current']===5,'Commit paid upkeep or corrupted untouched EP');
    $before=$capture();snapshot_assert($writer->commit($id,$r['state_version'])===$receipt&&$capture()===$before,'Committed replay repeated changes');
    snapshot_assert($db->tables===$external,'Publication mutated owner data');
    echo "ACS sustained commit SQL: preview/seal no upkeep, retained EP, pinned state hash, immediate incapacitation interruption, terminal rollback and replay passed\n";
};
require __DIR__.'/acs_combat_commit_regression.php';
