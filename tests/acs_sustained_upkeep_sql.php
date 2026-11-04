<?php
declare(strict_types=1);
// Uses the existing disposable acs222_* roster fixture/socket. Never a forum database.
$GLOBALS['acsResourcesFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Domain/Resolver.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/QueryService.php';
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // Undo only the earlier fixture's artificial terminal turn; no gameplay data is reused.
    $link->query("UPDATE fixture_af_acs_turns SET status='pending' WHERE id=".$groups[$first['entity_id']]);
    $source=$result['entity_id'];$target=$second['entity_id'];$heroTurn=$groups[$source];$targetTurn=$groups[$target];
    $clock=$service->clock();$rounds=$service->rounds();
    $make=static function(array $extra=[])use($link,$service,$repo,$sid,$eid):array {
        $link->query('UPDATE fixture_af_acs_operations SET updated_at=0');
        $v=$service->view($sid,$eid);$r=$extra+['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$v['session']['session_version'],'encounter_version'=>$v['encounter']['encounter_version'],
            'operation_key'=>bin2hex(random_bytes(16)),'reason'=>'Status tick fixture','confirm'=>'1'];
        if(isset($extra['turn_id']))$r['turn_version']=$repo->clockTurn($sid,$extra['turn_id'])['turn_version'];return $r;
    };
    $capture=static function()use($link):array{$out=[];foreach(['effects','entity_states','resources','entity_turns','turns','rounds','operations','events','event_details'] as $t)$out[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;};
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
    $entryId=3000;
    foreach(['hp','ep','ap'] as $key)$db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_mechanics',$key,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'resource_def','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>$key,'version'=>'1','resource_key'=>$key,'unit'=>'points']]]);
    $definition=static function(string $key,array $costs)use(&$entryId,$db):array {
        $statusKey=$key.'-status';$abilityKey=$key.'-action';
        $status=['schema'=>'af_kb.arpg.mechanic.v1','key'=>$statusKey,'version'=>'1','type'=>'debuff','scope'=>'combat','duration'=>['value'=>10,'unit'=>'target_turn'],
            'phase'=>'on_turn_start','decrement'=>['event'=>'on_turn_end','amount'=>1],'repeat_rule'=>'independent','max_stacks'=>1,'removal_conditions'=>[],'expiry_conditions'=>[],'immunities'=>[],'resistances'=>[],'formula_refs'=>[],'events'=>[],'mechanical_parameters'=>[],'periodic_effects'=>[]];
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_mechanics',$statusKey,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'status_def','entries'=>[$status]]);
        $ability=af_kb_default_type_profile_payload_arpg('arpg_ability');$ability=array_replace($ability,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>'single_enemy','max_charges'=>0,'duration_unit'=>'own_turn','cooldown_unit'=>'own_turn',
            'costs'=>array_map(static fn($c)=>['kind'=>'resource','resource_key'=>$c[0],'value'=>$c[1],'timing'=>'sustain','period'=>['value'=>1,'unit'=>'own_turn']],$costs),
            'effects'=>[['kind'=>'status','status_key'=>$statusKey,'base_chance'=>100,'polarity'=>'negative','trigger'=>'on_hit']]]);
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_ability',$abilityKey,$ability);
        $dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability',$abilityKey,7);snapshot_assert($dto['ready'],'Sustain owner DTO failed: '.json_encode($dto['diagnostics']));return $dto;
    };
    // A server-owned integer combat balance in the existing resource table, no parallel ledger.
    $link->query("INSERT INTO fixture_af_acs_resources (entity_id,resource_key,current_value,maximum_value,is_integer,resource_version,definition_json) VALUES (".$source.",'ep',5,5,1,1,'{\"schema_version\":1}')");
    $dto=$definition('cost-short',[['hp',2],['ep',6]]);
    $method=new ReflectionMethod(AF_ACS_QueryService::class,'ability');$prepared=$method->invoke(new AF_ACS_QueryService($repo,$access),$dto);
    snapshot_assert(isset($prepared['statuses'][0]['instance']['upkeep'])&&$prepared['costs']===[['kind'=>'ap','amount'=>1]],'Query did not retain upkeep or charged it as use cost');
    $a=$seed->add($sid,$eid,$source,$target,$dto);
    $b=$seed->add($sid,$eid,$source,$target,$definition('fifo-first',[['ep',4],['ap',1]]));
    $c=$seed->add($sid,$eid,$source,$target,$definition('player-first',[['hp',1],['ep',2],['ap',1]]));
    $d=$seed->add($sid,$eid,$source,$target,$definition('after-failure',[['ep',1]]));
    $other=$seed->add($sid,$eid,$target,$source,$definition('other-source',[['hp',1]]));
    $external=$db->tables;$reads=count($db->queries);$before=$capture();$screen=$clock->sustained($sid,$eid);$service->roundView($sid,$eid);
    snapshot_assert($capture()===$before,'Read-only sustained panel mutated state');
    $sourceStart=$entityTurns[$source]['id'];$request=$make(['entity_turn_id'=>$sourceStart,'effect_order'=>implode(',',[$c,$a,$b,$d])]);
    $mybb->user['uid']=9;blocked(fn()=>$clock->command('upkeep_order',$request),'ACS_UPKEEP_ACCESS_DENIED');snapshot_assert($clock->sustained($sid,$eid)['sources']===[],'Player saw another controller effects');$mybb->user['uid']=7;
    // Exercise the owner/controller path without GM or session-owner privileges.
    $link->query("UPDATE fixture_af_acs_session_roles SET role='player' WHERE session_id=".$sid." AND uid=7 AND role='owner'");
    $old=$request;$receipt=$clock->command('upkeep_order',$request);
    $link->query("UPDATE fixture_af_acs_session_roles SET role='owner' WHERE session_id=".$sid." AND uid=7 AND role='player'");
    snapshot_assert($clock->command('upkeep_order',$old)===$receipt,'Priority command replay changed result');
    $p=$repo->statusProjection($sid)['instances'];snapshot_assert($p[$c]['upkeep_priority']===1&&$p[$b]['upkeep_priority']===3,'Order was not persisted');
    snapshot_assert(!isset($receipt['statuses'])&&!str_contains(json_encode($receipt),'tick_definitions'),'Order response leaked definitions');
    $stale=$old;$stale['operation_key']=bin2hex(random_bytes(16));blocked(fn()=>$clock->command('upkeep_order',$stale),'ACS_VERSION_CONFLICT');
    blocked(fn()=>$clock->command('upkeep_order',$make(['entity_turn_id'=>$sourceStart,'effect_order'=>implode(',',[$c,$a,$b,$other])])),'ACS_UPKEEP_ORDER_INVALID');
    $request=$make(['entity_turn_id'=>$sourceStart]);$before=$capture();$baseHP=(int)$repo->entity($sid,$source)['hp'];
    $link->query("CREATE TRIGGER upkeep_fault BEFORE INSERT ON fixture_af_acs_operations FOR EACH ROW BEGIN IF NEW.kind='clock.entity_start' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='upkeep fixture failure'; END IF; END");
    blocked(fn()=>$clock->command('entity_start',$request),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER upkeep_fault');snapshot_assert($capture()===$before,'Failed transaction left partial HP/EP/AP/order/effects/receipts');
    $paid=$clock->command('entity_start',$request);$mechanics=$capture();$again=$clock->command('entity_start',$request);snapshot_assert($again===$paid&&$mechanics===$capture(),'Duplicate boundary paid twice');
    $p=$repo->statusProjection($sid)['instances'];$r=$repo->statusResources($source,$repo->entity($sid,$source));
    snapshot_assert((int)$repo->entity($sid,$source)['hp']===$baseHP-1&&$r['values']['ep']['current']===2,'HP/EP atomic charge wrong');
    snapshot_assert((int)$repo->clockTurn($sid,$heroTurn)['ap_spent']===1,'Explicit upkeep AP not spent');
    snapshot_assert($p[$a]['status']==='removed'&&$p[$b]['status']==='removed'&&$p[$c]['last_upkeep']['paid']&&$p[$d]['last_upkeep']['paid'],'Independent upkeep order/results wrong');
    snapshot_assert(!isset($p[$other]['last_upkeep']),'Target upkeep ran on source boundary');
    $beforeHP=$repo->entity($sid,$source)['hp'];$future=$clock->command('upkeep_order',$make(['entity_turn_id'=>$sourceStart,'effect_order'=>implode(',',[$d,$c])]));
    snapshot_assert($future['effective_from']==='next_source_start'&&$repo->entity($sid,$source)['hp']===$beforeHP&&$clock->command('entity_start',$request)===$paid,'Future order changed the completed payment');
    $panel=$clock->sustained($sid,$eid);snapshot_assert(str_contains(json_encode($panel),'effects_cost_unaffordable')&&str_contains(json_encode($panel),'last_payment'),'Safe panel missed termination/payment');
    // New effects after a completed start are not charged retroactively or on preview/seal.
    $late=$seed->add($sid,$eid,$source,$target,$definition('late-source',[['hp',1]]));
    $external=$db->tables;$reads=count($db->queries);
    $before=$capture();$clock->sustained($sid,$eid);snapshot_assert($capture()===$before&&!isset($repo->statusProjection($sid)['instances'][$late]['last_upkeep']),'View paid late effect');
    $rounds->command('pause',$make());$paused=$capture();blocked(fn()=>$clock->command('entity_start',$make(['entity_turn_id'=>$entityTurns[$target]['id']])),'ACS_CLOCK_BOUNDARY_STATE');
    snapshot_assert(!isset($repo->statusProjection($sid)['instances'][$other]['last_upkeep']),'Pause paid upkeep');$rounds->command('resume',$make());
    $rounds->command('gm_skip',$make(['turn_id'=>$heroTurn]));
    $targetHP=(int)$repo->entity($sid,$target)['hp'];$rounds->command('gm_skip',$make(['turn_id'=>$targetTurn]));
    snapshot_assert((int)$repo->entity($sid,$target)['hp']===$targetHP-1,'Skip bypassed target mandatory upkeep');
    // Incapacitation caused by a combat effect is irreversible by later healing for sustained.
    $remaining=$seed->add($sid,$eid,$target,$source,$definition('zero-hp',[['hp',1]]));
    $external=$db->tables;$reads=count($db->queries);
    // Server fixture changes the initial life-state to exercise the next valid boundary.
    $link->query('UPDATE fixture_af_acs_entity_states SET hp=0,life_state=\'incapacitated\',state_version=state_version+1 WHERE entity_id='.$target);
    $link->query('UPDATE fixture_af_acs_resources SET current_value=0,resource_version=resource_version+1 WHERE resource_key=\'hp\' AND entity_id='.$target);
    $round=$repo->currentRound($service->view($sid,$eid)['encounter']);foreach($repo->allRoundTurns($round['id']) as $turn)if($turn['status']==='pending')$rounds->command('gm_skip',$make(['turn_id'=>$turn['id']]));
    $rounds->command('round_next',$make(['round_id'=>$round['id']]));
    snapshot_assert($repo->statusProjection($sid)['instances'][$remaining]['status']==='removed','0 HP sustained survived next start');
    snapshot_assert($db->tables===$external&&count($db->queries)===$reads,'Upkeep read/wrote live KB/CharacterSheets');
    echo "ACS upkeep SQL: pinned typed costs, CAS order/replay/stale/invalid scope, HP/EP/AP atomic results, independent effects, duplicate/read-only/pause, skip and injected rollback passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
