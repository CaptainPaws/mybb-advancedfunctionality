<?php
declare(strict_types=1);
// Uses the existing disposable acs222_* roster fixture/socket. Never a forum database.
$GLOBALS['acsResourcesFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Domain/Resolver.php';
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
    $entryId=2000;
    $definition=static function(string $key,string $kind,int $duration,string $unit,bool $control=false)use(&$entryId,$db):array {
        $tickKey=$key.'-tick';$statusKey=$key.'-status';$abilityKey=$key.'-action';
        $tick=af_kb_default_type_profile_payload_arpg('arpg_ability');$tick=array_replace($tick,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','max_charges'=>0,'duration_unit'=>'own_turn','cooldown_unit'=>'own_turn',
            'effects'=>[$kind==='heal'?['kind'=>'heal','value'=>3,'can_crit'=>false]:['kind'=>'damage','value'=>4,'damage_type'=>'true','can_crit'=>false]]]);
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_ability',$tickKey,$tick);
        $status=['schema'=>'af_kb.arpg.mechanic.v1','key'=>$statusKey,'version'=>'1','type'=>$control?'control':'debuff','scope'=>'combat','duration'=>['value'=>$duration,'unit'=>$unit],
            'phase'=>$unit==='round'?'on_round_start':'on_turn_start','decrement'=>['event'=>$control?'on_turn_end':($unit==='round'?'on_round_end':'on_turn_start'),'amount'=>1],'repeat_rule'=>'refresh','max_stacks'=>1,'removal_conditions'=>[],'expiry_conditions'=>[],
            'immunities'=>[],'resistances'=>[],'formula_refs'=>[],'events'=>[],'mechanical_parameters'=>$control?['blocks_ordinary_actions'=>true]:[],
            'periodic_effects'=>$control?[]:[['effect'=>['type'=>'arpg_ability','key'=>$tickKey,'effect_index'=>0],'period'=>['value'=>1,'unit'=>$unit],'phase'=>$unit==='round'?'on_round_start':'on_turn_start']]];
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_mechanics',$statusKey,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'status_def','entries'=>[$status]]);
        $ability=af_kb_default_type_profile_payload_arpg('arpg_ability');$ability=array_replace($ability,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','max_charges'=>0,'duration_unit'=>'own_turn','cooldown_unit'=>'own_turn',
            'effects'=>[['kind'=>'status','status_key'=>$statusKey,'base_chance'=>100,'polarity'=>$kind==='heal'?'positive':'negative','trigger'=>'on_hit']]]);
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entryId,'arpg_ability',$abilityKey,$ability);
        $dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability',$abilityKey,7);snapshot_assert($dto['ready'],'Periodic owner DTO failed: '.json_encode($dto['diagnostics']));return $dto;
    };
    $poison=$seed->add($sid,$eid,$source,$target,$definition('poison','damage',3,'target_turn'));
    $control=$seed->add($sid,$eid,$source,$target,$definition('control','damage',1,'target_turn',true));
    $hot=$seed->add($sid,$eid,$source,$target,$definition('hot','heal',1,'own_turn'));
    $external=$db->tables;$ownerReads=count($db->queries);$baseHP=(int)$repo->entity($sid,$target)['hp'];$before=$capture();
    $service->roundView($sid,$eid);$clock->overview($sid,$eid);snapshot_assert($capture()===$before,'GET/view consumed ticks');
    $targetStart=$entityTurns[$target]['id'];$request=$make(['entity_turn_id'=>$targetStart]);$before=$capture();
    $link->query("CREATE TRIGGER status_tick_fault BEFORE INSERT ON fixture_af_acs_operations FOR EACH ROW BEGIN IF NEW.kind='clock.status_tick' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status tick fixture failure'; END IF; END");
    blocked(fn()=>$clock->command('entity_start',$request),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER status_tick_fault');
    snapshot_assert($capture()===$before,'Tick SQL fault left HP/effect/receipt mutation');
    $firstTick=$clock->command('entity_start',$request);$before=$capture();
    snapshot_assert($clock->command('entity_start',$make(['entity_turn_id'=>$targetStart]))===$firstTick,'Duplicate boundary changed result');
    // make() only resets fixture rate timestamps, so compare mechanical tables rather than timestamps.
    snapshot_assert((int)$repo->entity($sid,$target)['hp']===$baseHP-4&&$repo->statusProjection($sid)['instances'][$poison]['remaining_duration']===2,'First poison tick missing');
    snapshot_assert(!$clock->available($sid,$targetTurn,$target)['actions_ready'],'Control allowed ordinary action before end');
    $rounds->command('pause',$make());$paused=$capture();
    blocked(fn()=>$clock->command('entity_start',$make(['entity_turn_id'=>$entityTurns[$source]['id']])),'ACS_CLOCK_BOUNDARY_STATE');
    snapshot_assert((int)$repo->entity($sid,$target)['hp']===$baseHP-4&&$repo->statusProjection($sid)['instances'][$poison]['remaining_duration']===2,'Pause advanced effects');
    $rounds->command('resume',$make());
    $rounds->command('gm_skip',$make(['turn_id'=>$targetTurn]));snapshot_assert($repo->statusProjection($sid)['instances'][$control]['status']==='removed','Control not removed at end');
    $clock->command('entity_start',$make(['entity_turn_id'=>$entityTurns[$source]['id']]));
    snapshot_assert((int)$repo->entity($sid,$target)['hp']===$baseHP-1&&$repo->clockTurn($sid,$targetTurn)['status']==='passed','HoT reopened a closed turn or missed own-turn tick');
    $passAll=static function()use($repo,$service,$rounds,$sid,$eid,$make):void {
        $round=$repo->currentRound($service->view($sid,$eid)['encounter']);foreach($repo->allRoundTurns($round['id']) as $turn)if($turn['status']==='pending')$rounds->command('gm_skip',$make(['turn_id'=>$turn['id']]));
    };
    for($n=2;$n<=4;$n++){
        $passAll();$round=$repo->currentRound($service->view($sid,$eid)['encounter']);$next=$rounds->command('round_next',$make(['round_id'=>$round['id']]));
        $expected=$baseHP+3-4*min(3,$n);snapshot_assert((int)$repo->entity($sid,$target)['hp']===$expected,'Poison not exactly three starts/last tick');
    }
    snapshot_assert($repo->statusProjection($sid)['instances'][$poison]['status']==='removed','Poison remains after last tick');
    // Forfeit still runs both boundaries once for a properly withdrawn group.
    $current=$repo->currentRound($service->view($sid,$eid)['encounter']);$victimTurn=null;
    foreach($repo->allRoundTurns($current['id']) as $turn)if($turn['control_group_id']===$repo->entity($sid,$target)['control_group_id'])$victimTurn=$turn;
    $link->query("UPDATE fixture_af_acs_participants SET participation='withdrawn' WHERE encounter_id=".$eid.' AND entity_id='.$target);
    $r=$rounds->command('gm_forfeit',$make(['turn_id'=>$victimTurn['id']]));snapshot_assert($r['status']==='forfeited','Forfeit failed');
    snapshot_assert($db->tables===$external&&count($db->queries)===$ownerReads,'Boundary read/wrote live KB/CS');
    echo "ACS status tick SQL: owner pinned graph, real start/last tick/HoT/control, tick receipt rollback, duplicate, pause/resume, pass/forfeit, next-round AP, closed-turn preservation and read-only GET passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
