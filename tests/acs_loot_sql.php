<?php
/** Real response/GM outcome harness, no Inventory issuance or forum writer. Disposable DB only. */
$GLOBALS['acsEndFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/LootService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/LootController.php';
    // Resume with the first new table already present and the second still missing.
    $link->query('DROP TABLE t_af_acs_loot_entries');$link->query("DELETE FROM t_af_acs_schema_migrations WHERE version='024_loot_pools'");$migrations->run();$migrations->assertInstalled();
    $encounters=new AF_ACS_EncounterService($repo,$access);$drawsLoot=0;$samples=[0,9007199254740991,0,9007199254740991];
    $loot=new AF_ACS_LootService($repo,$access,new AF_ACS_RandomSource(static function()use(&$drawsLoot,$samples){return $samples[($drawsLoot++)%count($samples)];}));
    $request=static fn(string $key)=>['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],'operation_key'=>$key,'reason'=>'Disposable immutable loot evidence'];
    $adapter=new AF_ACS_KnowledgeBaseAdapter;
    $item=$adapter->readDefinition('arpg_item','potion',7);snapshot_assert($item['ready'],'Item not ready '.json_encode($item['diagnostics']));
    $ref=['type'=>'arpg_item','key'=>'potion','version'=>$item['definition_version']['declared_version'],'fingerprint'=>$item['definition_version']['fingerprint']];
    // Legacy items need an explicit declared version for a new generation contract.
    if(!is_string($ref['version'])||$ref['version']===''){
        $row=$link->query("SELECT * FROM t_af_kb_entries WHERE type='arpg_item' AND `key`='potion'")->fetch_assoc();
        $definition=$item['data']['definition'];$definition['version']='1';$json=json_encode($definition);$meta=json_encode(['rules'=>$definition]);$st=$link->prepare('UPDATE t_af_kb_entries SET data_json=?,meta_json=? WHERE id=?');$st->bind_param('sss',$json,$meta,$row['id']);$st->execute();
        $item=$adapter->readDefinition('arpg_item','potion',7);$ref['version']=$item['definition_version']['declared_version'];$ref['fingerprint']=$item['definition_version']['fingerprint'];
    }
    itemInsert($link,'af_acs_sides',['id'=>2,'encounter_id'=>1,'name'=>'Opposition','side_version'=>1,'policy_json'=>'{"schema_version":1}']);
    itemInsert($link,'af_acs_side_relationships',['encounter_id'=>1,'side_a'=>1,'side_b'=>2,'relationship'=>'hostile','relation_version'=>1]);$link->query('UPDATE t_af_acs_participants SET side_id=2 WHERE entity_id='.$victim);
    $link->query("UPDATE t_af_acs_encounters SET status='preparation',round_number=0 WHERE id=1");
    $encounters->command('end_conditions_set',$request('loot_end_policy01')+['end_conditions'=>['schema'=>AF_ACS_EndCondition::SCHEMA,'conditions'=>['last_active_side','no_active_sides'],'allow_continuation'=>true,'mutual_outcomes'=>['draw']]]);
    if(isset($GLOBALS['acsLootParticipantsFixture']))($GLOBALS['acsLootParticipantsFixture'])(get_defined_vars());
    $definitions=[];$pins=[];
    foreach(['guaranteed','independent_roll','weighted_pool'] as $index=>$mode){
        $kind=['monster','encounter','kb'][$index];$key='loot-'.$mode;
        $definition=['schema'=>AF_ACS_Rewards::SCHEMA,'source_kind'=>$kind,'mode'=>$mode,'draws'=>$mode==='weighted_pool'?2:0,'replacement'=>false,'luck_modifier'=>null,
            'conditions'=>['outcomes'=>['victory'],'source_life_states'=>$kind==='monster'?['incapacitated']:[],'source_participation'=>$kind==='monster'?['engaged']:[],'source_side'=>$kind==='monster'?'loser':'any'],
            'eligibility'=>['side'=>'winner','life_states'=>['active','incapacitated','dead'],'participation'=>['engaged','surrendered','escaped','withdrawn','captured']],
            'entries'=>[['key'=>'gem-a','item'=>$ref,'qty'=>2,'chance'=>$mode==='independent_roll'?50:100,'weight'=>$mode==='weighted_pool'?1:0],['key'=>'gem-b','item'=>$ref,'qty'=>1,'chance'=>$mode==='independent_roll'?25:100,'weight'=>$mode==='weighted_pool'?3:0]]];
        if(isset($GLOBALS['acsLootDefinitionFixture']))$definition=($GLOBALS['acsLootDefinitionFixture'])($definition,$mode);
        $rules=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','version'=>'1','service_kind'=>'combat_template','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>$key,'version'=>'1','phases'=>[],'limits'=>[],'formula_refs'=>[],'loot_source'=>$definition]]];
        itemInsert($link,'af_kb_entries',snapshot_entry(1900+$index,'arpg_mechanics',$key,$rules));$definitions[$mode]=$definition;
        $r=$request('loot_configure_'.$mode)+['source_kind'=>$kind,'source_entity_id'=>$kind==='monster'?$victim:'','kb_key'=>$key];$configured=$loot->command('loot_configure',$r);$pins[$mode]=$configured['definition_snapshot_id'];
        $before=$captureAll();snapshot_assert($loot->command('loot_configure',$r)===$configured&&$captureAll()===$before,'Configure replay');
        $dto=$adapter->lootDefinition($key,7);snapshot_assert($dto['definition']===$definition,'Wrong pinned graph');
    }
    $weighted=$definitions['weighted_pool'];$weighted['draws']=1;$weighted['replacement']=true;
    $picked=AF_ACS_Rewards::generate($weighted,[['sample_n'=>2251799813685248,'u'=>0.25]]);snapshot_assert($picked[0]['key']==='gem-b','Weighted cumulative boundary ignored');
    $independent=$definitions['independent_roll'];$independent['entries'][0]['chance']=0;$independent['entries'][1]['chance']=100;
    $picked=AF_ACS_Rewards::generate($independent,[['sample_n'=>0,'u'=>0.0],['sample_n'=>9007199254740991,'u'=>9007199254740991/9007199254740992.0]]);snapshot_assert(count($picked)===1&&$picked[0]['key']==='gem-b','P0/P100 boundary failed');
    $bad=$definitions['weighted_pool'];$bad['luck_modifier']=['attribute'=>'luck'];itemFail(fn()=>AF_ACS_Rewards::definition($bad),'ACS_LOOT_UNSUPPORTED');
    $bad=$definitions['guaranteed'];$bad['entries'][0]['existing_inventory_id']='20';itemFail(fn()=>AF_ACS_Rewards::definition($bad),'ACS_LOOT_UNSUPPORTED');
    itemFail(fn()=>$loot->command('loot_generate',$request('loot_unconfirmed01')+['source_key'=>'encounter']),'ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');snapshot_assert($drawsLoot===0,'Unconfirmed sampled');
    $link->query("UPDATE t_af_acs_encounters SET status='active',round_number=2 WHERE id=1");$link->query('UPDATE t_af_acs_entity_states SET hp=1 WHERE entity_id='.$victim);$link->query("UPDATE t_af_acs_resources SET current_value=1 WHERE resource_key='hp' AND entity_id=".$victim);
    $publish(true);$resolution=$link->query("SELECT id FROM t_af_acs_encounter_resolutions WHERE status='pending'")->fetch_row()[0];
    itemFail(fn()=>$loot->command('loot_generate',$request('loot_pending_outcome01')+['source_key'=>'encounter']),'ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');
    $confirm=$request('loot_outcome_confirm01')+['resolution_id'=>(string)$resolution,'outcome'=>'victory'];$confirmed=$encounters->command('outcome_confirm',$confirm);$outcome=$repo->confirmedOutcome($sid,'1');
    // Unrelated current Luck cannot modify pinned loot chances.
    $stats=$link->query('SELECT stats_json FROM t_af_acs_entity_states WHERE entity_id='.$actor)->fetch_row()[0];$s=json_decode($stats,true);$s['values']['luck']=999999;$json=json_encode($s);$st=$link->prepare('UPDATE t_af_acs_entity_states SET stats_json=? WHERE entity_id=?');$st->bind_param('ss',$json,$actor);$st->execute();
    $frozen=$link->query("SELECT candidate_json FROM t_af_acs_encounter_resolutions WHERE status='confirmed'")->fetch_row()[0];$frozen=json_decode($frozen,true)['loot_eligibility'];
    $group=$repo->entity($sid,$actor)['control_group_id'];$eligible=AF_ACS_Rewards::eligibility($definitions['independent_roll'],$frozen,$outcome['outcome']);snapshot_assert(count($eligible)===($GLOBALS['acsLootExpectedGroups']??1)&&$eligible[0]['control_group_id']===$group,'Eligibility requires damage/duplicates');
    foreach(['pet','summon'] as $kind){$pet=$frozen[0];$pet['entity_id']='999'.$kind;$pet['control_group_id']='999';$pet['kind']=$kind;$pet['source_entity_id']=$actor;$frozen[]=$pet;}
    snapshot_assert(AF_ACS_Rewards::eligibility($definitions['independent_roll'],$frozen,$outcome['outcome'])===$eligible,'Pet/summon added base share');
    $sessions=new AF_ACS_SessionService($repo,$access);$sr=static fn($key)=>['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Loot lifecycle fixture'];
    itemFail(fn()=>$sessions->command('request_close',$sr('loot_before_generate_close01')),'ACS_SESSION_OBLIGATIONS_BLOCKED');
    // Change latest KB after pin, including all chances and item definition. Generation must not consult it.
    $link->query("UPDATE t_af_kb_entries SET active=0 WHERE id IN (1900,1901,1902) OR (type='arpg_item' AND `key`='potion')");
    $link->query("UPDATE t_af_acs_sessions SET status='closed' WHERE id=".$sid);itemFail(fn()=>$loot->command('loot_generate',$request('loot_inactive_session01')+['source_key'=>'encounter']),'ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED');$link->query("UPDATE t_af_acs_sessions SET status='active' WHERE id=".$sid);
    $mechanical=$captureAll();$receipts=[];
    foreach(['guaranteed'=>'monster:'.$victim,'independent_roll'=>'encounter','weighted_pool'=>'kb:loot-weighted_pool'] as $mode=>$source){
        $r=$request('loot_generate_'.$mode)+['source_key'=>$source];$before=$captureAll();
        if($mode==='guaranteed'){
            $mybb->request_method='get';itemFail(fn()=>$loot->command('loot_generate',$r),'ACS_POST_REQUIRED');$mybb->request_method='post';
            $mybb->input['my_post_key']='bad';itemFail(fn()=>$loot->command('loot_generate',$r),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
            $mybb->user['uid']=9;itemFail(fn()=>$loot->command('loot_generate',$r),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
            $link->query("CREATE TRIGGER loot_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='loot rollback'");itemFail(fn()=>$loot->command('loot_generate',$r),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER loot_late');snapshot_assert($captureAll()===$before,'Loot rollback leaked');
        }
        if($mode==='independent_roll'){
            $link->query("CREATE TRIGGER loot_random_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='random loot rollback'");itemFail(fn()=>$loot->command('loot_generate',$r),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER loot_random_late');snapshot_assert($captureAll()===$before,'Random rollback leaked pool/rolls/items');
        }
        $runner=$loot;$rival=null;$contended=false;
        if($mode==='guaranteed'){
            $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');
            $competing=new AF_ACS_LootService(new AF_ACS_EncounterRepository($rival),$access,new AF_ACS_RandomSource(static function(){throw new RuntimeException('Competing generation sampled');}));
            $contending=new class($access,$competing,$r,$contended) extends AF_ACS_SessionAccess {
                private int $calls=0;public function __construct(private $native,private $rival,private array $input,public bool &$contended){}
                public function thread(string $tid,?string $uid=null):array {if(++$this->calls===2){$r=$this->input;$r['operation_key']='loot_concurrent_other01';itemFail(fn()=>$this->rival->command('loot_generate',$r),'ACS_MAINTENANCE_BUSY');$this->contended=true;}return $this->native->thread($tid,$uid);}
            };
            $runner=new AF_ACS_LootService($repo,$contending,new AF_ACS_RandomSource(static function(){throw new RuntimeException('Guaranteed generation sampled');}));
        }
        $beforeDraws=$drawsLoot;$link->loseAck=true;itemFail(fn()=>$runner->command('loot_generate',$r),'ACS_TRANSACTION_ERROR');
        if($rival){snapshot_assert($contended,'Second generation writer not attempted');$rival->close();}
$result=$loot->command('loot_generate',$r);$receipts[$mode]=$result;$pool=$result['pool'];$g=$pool['generation'];
        snapshot_assert($g['definition_snapshot_id']===$pins[$mode]&&$g['outcome']['outcome_id']===$outcome['outcome_id']&&$g['eligibility']===$eligible,'Pin/outcome/group changed');
        $expected=$mode==='independent_roll'?1:2;snapshot_assert(count($g['entries'])===$expected,'Wrong generated count '.$mode.' '.json_encode($g));
        snapshot_assert($drawsLoot-$beforeDraws===($mode==='guaranteed'?0:2),'Unexpected count of loot rolls');
        $snap=$captureAll();$drawCount=$drawsLoot;snapshot_assert($loot->command('loot_generate',$r)===$result&&$captureAll()===$snap&&$drawsLoot===$drawCount,'Repeated key rerolled');
        $other=$request('loot_new_key_'.$mode)+['source_key'=>$source];$other['expected_version']='1';snapshot_assert($loot->command('loot_generate',$other)===$result&&$captureAll()===$snap&&$drawsLoot===$drawCount,'Same source new key rerolled');
        snapshot_assert(count(AF_ACS_Rewards::generate($definitions[$mode],$g['tape']))===$expected,'Saved tape replay changed');
    }
    $done=$captureAll();snapshot_assert($encounters->command('outcome_confirm',$confirm)===$confirmed&&$captureAll()===$done,'Repeated outcome generated more loot');
    foreach(['inv_items','inv_owner_state','inv_reservations','inv_external_operations','combat_results','combat_receipts','sealed_actions','entity_states','resources','turns','entity_turns','cooldowns'] as $table)snapshot_assert($done[$table]===$mechanical[$table],'Loot mutated '.$table);
    $retainedRolls=array_column($done['rolls'],null,'id');foreach($mechanical['rolls'] as $roll)snapshot_assert(($retainedRolls[$roll['id']]??null)===$roll,'Loot rewrote combat roll history');
    if(isset($GLOBALS['acsLootAllocationFixture'])){($GLOBALS['acsLootAllocationFixture'])(get_defined_vars());return;}
    $summary=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert(count(array_filter($summary['pending'],fn($r)=>$r['source']==='loot_pool'))===3&&!$summary['explicitly_blocked'],'Pools lost lifecycle scope');
    $sessions->command('request_close',$sr('loot_pending_close01'));$sessions->command('close',$sr('loot_session_close01'));$sessions->command('reopen_session',$sr('loot_session_reopen01'));
    $snap=$captureAll();snapshot_assert($loot->command('loot_generate',$request('loot_after_reopen01')+['source_key'=>'encounter'])===$receipts['independent_roll']&&$captureAll()===$snap&&$drawsLoot===6,'Reopen regenerated');
    $sessions->command('request_close',$sr('loot_pending_again01'));$sessions->command('close',$sr('loot_close_again01'));$sessions->command('archive',$sr('loot_session_archive01'));
    $mybb->request_method='get';$read=AF_ACS_LootController::handle($loot,'loot_status',['session_id'=>$sid,'encounter_id'=>'1','source_key'=>'encounter'],'get');$mybb->request_method='post';snapshot_assert($read['pool']===$receipts['independent_roll']['pool'],'Archive pool lost');
    $populated=$captureAll();$migrations->run();$migrations->run();snapshot_assert($captureAll()===$populated,'Resumable migration changed populated pool');
    echo "ACS loot SQL: guaranteed/independent/weighted, frozen pins/eligibility, pet group dedupe, sealed rolls, unknown ACK/source replay, no Luck/Inventory, GM/CSRF, rollback, close/archive/reopen and migration repeat passed\n";
};
require __DIR__.'/acs_effect_responses_sql.php';
