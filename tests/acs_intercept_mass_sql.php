<?php
declare(strict_types=1);
function get_thread($tid):array{return ['tid'=>(int)$tid,'fid'=>1,'uid'=>7,'visible'=>1,'closed'=>0];}
function is_moderator(...$args):bool{return false;}
// Known native PID + existing disposable MariaDB fixture; no new post/Gateway.
$GLOBALS['acsPresentationFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/ReactionService.php';
    $service=new AF_ACS_EncounterService($repo,$access);
    $actor=$active[0]['actor_id'];$protected=$active[0]['targets'][0];$victim='3';$mode=$GLOBALS['argv'][2]??'mass';$reactions=$service->reactions();$entry=5000;
    $old=$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'012_barrier_pools' ORDER BY version")->fetch_all(MYSQLI_ASSOC);
    $link->query('DROP TABLE fixture_af_acs_barrier_targets');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=1 WHERE version='012_barrier_pools'");$migrations->run();$migrations->run();
    snapshot_assert($migrations->status()['schema_version']===AF_ACS_Migrations::VERSION&&$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'012_barrier_pools' ORDER BY version")->fetch_all(MYSQLI_ASSOC)===$old,'012 resume modified previous ledger');
    $db->tables['af_kb_entries'][]=snapshot_entry(++$entry,'arpg_mechanics','ep',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'resource_def','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>'ep','version'=>'1','resource_key'=>'ep','unit'=>'points']]]);
    $grants=[];$definitions=[];
    foreach(['expensive'=>99,'purify'=>1,'healing'=>1] as $key=>$cost){
        $rules=af_kb_default_type_profile_payload_arpg('arpg_ability');$rules=array_replace($rules,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>$mode==='mass'?'multiple':'single_ally','duration_unit'=>'own_turn','cooldown_unit'=>'own_turn','max_charges'=>0,
            'costs'=>[['kind'=>'resource','resource_key'=>'ep','value'=>$cost,'timing'=>'use']],
            'effects'=>[$mode==='mass'?['kind'=>'shield','mass'=>true,'value'=>30,'scope'=>'shared_pool','scope_key'=>'party','absorbs'=>['physical','elemental'],'duration_value'=>2,'duration_unit'=>'own_turn']:['kind'=>'control','control_type'=>'intercept']]]);
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entry,'arpg_ability','react-'.$key,$rules);
        $definitions['react-'.$key]=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','react-'.$key,7);snapshot_assert($definitions['react-'.$key]['ready'],'Reaction owner ability blocked: '.json_encode($definitions['react-'.$key]['diagnostics']));
        $issues=[];$grants[]=af_kb_arpg_acs_reference('arpg_ability','react-'.$key,'fixture',$issues);snapshot_assert(!$issues,'Reaction grant normalization failed');
        $trigger=['schema'=>'af_kb.arpg.mechanic.v1','key'=>'trigger-'.$key,'version'=>'1','event'=>'before_hit','priority'=>$key==='expensive'?-1:($key==='purify'?0:1),'condition_ref'=>null,
            'effect_ref'=>['type'=>'arpg_ability','key'=>'react-'.$key,'effect_index'=>0],'limits'=>['per_round'=>1],'formula_refs'=>[]];
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entry,'arpg_mechanics','trigger-'.$key,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'trigger_template','entries'=>[$trigger]]);
    }
    // Attach owner-resolved direct grants to this isolated registration snapshot, update roster proof.
    $entity=$repo->entity($sid,$victim);$payload=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);$payload['pinned_abilities']=array_replace($payload['pinned_abilities'],$definitions);
    foreach($grants as $grant)$payload['source_snapshot']['data']['ability_keys'][]=['ability'=>['parameters'=>[],'references'=>['ability_key'=>$grant]]];
    $json=AF_ACS_Repository::json($payload);$hash=hash('sha256',$json);$st=$link->prepare('UPDATE fixture_af_acs_snapshots SET payload_json=?,content_hash=? WHERE id=?');$st->bind_param('sss',$json,$hash,$entity['template_snapshot_id']);$st->execute();
    $round=$repo->currentRound($service->view($sid,$eid)['encounter']);$victimTurn=null;$individual=null;
    foreach($repo->allRoundTurns($round['id']) as $t)foreach($repo->individualTurns([$t['id']]) as $i)if($i['entity_id']===$victim){$victimTurn=$t;$individual=$i;}
    $link->query('UPDATE fixture_af_acs_controllers SET controller_uid=7,assignment_version=assignment_version+1 WHERE status=\'active\' AND entity_id='.$victim);$controller=$repo->controllers($sid,$victim)[0];
    $roster=json_decode($victimTurn['roster_json'],true,32,JSON_THROW_ON_ERROR);foreach($roster['members'] as &$m)if($m['entity_id']===$victim){$m['content_hash']=$hash;$m['controller_uid']='7';$m['assignment_version']=$controller['assignment_version'];}unset($m);unset($roster['fingerprint']);$roster['fingerprint']=hash('sha256',AF_ACS_Repository::json($roster));$json=AF_ACS_Repository::json($roster);
    $st=$link->prepare("UPDATE fixture_af_acs_turns SET roster_json=?,status='resolved' WHERE id=?");$st->bind_param('ss',$json,$victimTurn['id']);$st->execute();
    $permissions=AF_ACS_Repository::json(['schema_version'=>1,'scope'=>'fixture_combat','combat_operations_enabled'=>true]);$st=$link->prepare('UPDATE fixture_af_acs_controllers SET permissions_json=? WHERE entity_id=?');$st->bind_param('ss',$permissions,$victim);$st->execute();
    $link->query("INSERT INTO fixture_af_acs_resources (entity_id,resource_key,current_value,maximum_value,is_integer,resource_version,definition_json) VALUES (".$victim.",'ep',2,2,1,1,'{\"schema_version\":1}')");
    $make=static function(string $trigger,string $key)use($service,$sid,$eid,$victim,$protected,$mode,$link):array{$link->query('UPDATE fixture_af_acs_operations SET updated_at=0');$v=$service->view($sid,$eid);return ['session_id'=>$sid,'encounter_id'=>$eid,'entity_id'=>$victim,'trigger_key'=>$trigger,'targets'=>$mode==='mass'?[$protected,$victim]:[$protected],'expected_version'=>$v['session']['session_version'],'encounter_version'=>$v['encounter']['encounter_version'],'operation_key'=>$key,'reason'=>'Reaction fixture'];};
    $request=$make('trigger-expensive','reaction_register_fixture_01');$mybb->user['uid']=9;blocked(fn()=>$reactions->command('register',$request),'ACS_REACTION_ACCESS_DENIED');blocked(fn()=>$reactions->view($sid,$eid,$victim),'ACS_REACTION_ACCESS_DENIED');$mybb->user['uid']=7;
    // Registration is controlled by the source controller, not by GM status or pending ordinary turn.
    $link->query("UPDATE fixture_af_acs_session_roles SET role='player' WHERE session_id=".$sid." AND uid=7 AND role='owner'");blocked(fn()=>$reactions->command('register',$request),'ACS_REACTION_TARGETS_INVALID');
    $link->query("UPDATE fixture_af_acs_session_roles SET role='owner' WHERE session_id=".$sid." AND uid=7 AND role='player'");$first=$reactions->command('register',$request);
    snapshot_assert($reactions->command('register',$request)===$first,'Duplicate registration replay changed identity');blocked(fn()=>$reactions->command('register',$make('trigger-expensive','reaction_duplicate_fixture1')),'ACS_REACTION_ALREADY_REGISTERED');
    $second=$reactions->command('register',$make('trigger-purify','reaction_register_fixture_02'));$third=$reactions->command('register',$make('trigger-healing','reaction_register_fixture_03'));
    $safe=$reactions->view($sid,$eid,$victim);snapshot_assert(count($safe['reactions'])===3&&$safe['reaction_used']===0&&!str_contains(json_encode($safe),'fingerprint'),'Registration consumed limit or exposed pinned graph');
    snapshot_assert($repo->clockTurn($sid,$victimTurn['id'])['status']==='resolved','Registration reopened ordinary turn');
    $ownerState=$db->tables;$ownerReads=count($db->queries);$tapeBefore=array_map(fn($s)=>$repo->sealTape((string)$s['id']),$repo->sealRows($d['draft_id'],true));
    $draws=0;$reactionSeal=new AF_ACS_SealService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){$draws++;return 0;}));
    $before=$capture();$prepared=$reactionSeal->status($d['draft_id']);snapshot_assert(!$prepared['blocked']&&$capture()===$before,'Read-only reaction view changed state or blocked: '.json_encode($prepared));
    snapshot_assert(array_map(fn($s)=>$repo->sealTape((string)$s['id']),$repo->sealRows($d['draft_id'],true))===$tapeBefore,'Reaction replaced original tape');
    $confirmed=$reactionSeal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$prepared['draft_version'],'operation_key'=>'reaction_confirm_fixture','confirmation_hash'=>$prepared['confirmation_hash']]);
    snapshot_assert(isset($confirmed['summary']['reaction_hash'])&&$repo->clockEntityTurn($sid,$individual['id'])['reaction_used']==0,'Seal spent reaction slot');
    $baseDraft=$repo->draft($d['draft_id'],'7');$id=str_repeat('c',32);$r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$baseDraft['id'],'author_uid'=>'7','expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
    $r=$store->validating($id,$r['state_version'],static function(){});$r=$store->posting($id,$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});$r=$store->confirmPost($id,$r['state_version'],'501',static function(){},'1');
    // Simulate a retained causal-graph defect after sealing: commit must diagnose it without a draw or any writes.
    $reactionRow=$link->query('SELECT * FROM fixture_af_acs_reactions WHERE id='.$second['reaction_id'])->fetch_assoc();$snapshot=$link->query('SELECT * FROM fixture_af_acs_snapshots WHERE id='.$reactionRow['definition_snapshot_id'])->fetch_assoc();
    $retained=json_decode($snapshot['payload_json'],true);$graph=$retained['definition'];
    $node=static function(array $definition,string $key):array{$json=json_encode(af_kb_arpg_acs_canonical($definition),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return ['json'=>$json,'ref'=>['key'=>$key,'version'=>$definition['version'],'fingerprint'=>'sha256:'.hash('sha256',$json)]];};
    $wrapper=['kind'=>'control','control_type'=>'passive','effect_ref'=>['type'=>'arpg_ability','key'=>'cyclic-child','effect_index'=>0]];
    $ability=json_decode($graph['ability']['json'],true);$ability['passive_effects']=[$wrapper];$graph['ability']=$node($ability,$graph['ability']['ref']['key']);
    $trigger=json_decode($graph['trigger']['json'],true);$trigger['limits']+=['max_depth'=>4,'per_cause'=>1];$graph['trigger']=$node($trigger,$graph['trigger']['ref']['key']);
    $graph['passives']=[['source'=>$node(['schema'=>'af_kb.arpg.rules.v1','version'=>'1','targeting'=>'self','effects'=>[['kind'=>'heal','target'=>'self','value'=>1]],'passive_effects'=>[$wrapper]],'cyclic-child'),'effect_index'=>0]];
    $retained['definition']=$graph;$json=AF_ACS_Repository::json($retained);$hash=hash('sha256',$json);$st=$link->prepare('UPDATE fixture_af_acs_snapshots SET payload_json=?,content_hash=? WHERE id=?');$st->bind_param('sss',$json,$hash,$snapshot['id']);$st->execute();
    $config=json_decode($reactionRow['config_json'],true);$config['spec']=AF_ACS_Effects::reactionSpec($graph);$json=AF_ACS_Repository::json($config);$st=$link->prepare('UPDATE fixture_af_acs_reactions SET config_json=? WHERE id=?');$st->bind_param('ss',$json,$second['reaction_id']);$st->execute();
    $cycleBefore=$capture();blocked(fn()=>$writer->commit($id,$r['state_version']),'ACS_CAUSAL_CYCLE');snapshot_assert($capture()===$cycleBefore&&$draws===0,'Causal failure changed mechanics, draft or tape');
    $st=$link->prepare('UPDATE fixture_af_acs_snapshots SET payload_json=?,content_hash=? WHERE id=?');$st->bind_param('sss',$snapshot['payload_json'],$snapshot['content_hash'],$snapshot['id']);$st->execute();$st=$link->prepare('UPDATE fixture_af_acs_reactions SET config_json=? WHERE id=?');$st->bind_param('ss',$reactionRow['config_json'],$second['reaction_id']);$st->execute();
    $before=$capture();$link->query("CREATE TRIGGER reaction_commit_fault BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='reaction rollback'");
    blocked(fn()=>$writer->commit($id,$r['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER reaction_commit_fault');snapshot_assert($capture()===$before,'Reaction rollback left cost/slot/version changes');
    $receipt=$writer->commit($id,$r['state_version']);$p=$repo->reactionProjection($sid,$eid)['entries'];
    snapshot_assert($receipt['status']==='COMMITTED'&&$p[$second['reaction_id']]['reaction_version']===2&&$p[$first['reaction_id']]['reaction_version']===1&&$p[$third['reaction_id']]['reaction_version']===1,'Wrong reaction executed or shortage aborted foreign attack');
    snapshot_assert((int)$repo->clockEntityTurn($sid,$individual['id'])['reaction_used']===1&&$repo->statusResources($victim,$repo->entity($sid,$victim))['values']['ep']['current']===1,'Reaction slot/resource not committed atomically');
    $before=$capture();snapshot_assert($writer->commit($id,$r['state_version'])===$receipt&&$capture()===$before&&$draws===0,'Duplicate commit executed/rerolled reaction');
    $event=$link->query('SELECT payload_json FROM fixture_af_acs_combat_results WHERE submission_id=\''.$id.'\'')->fetch_assoc();$plan=json_decode($event['payload_json'],true)['plan'];$trace=$plan['actions'][0]['reactions'];
    snapshot_assert($trace[0]['reason']==='effects_cost_unaffordable'&&$trace[1]['executed'],'Recorded queue/skip/limit order wrong');
    snapshot_assert($db->tables===$ownerState&&count($db->queries)===$ownerReads,'Execution read/wrote live KB or permanent owner state');
    $result=json_decode($event['payload_json'],true);$step=$result['plan']['actions'][0];
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCardQuery.php';require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/CombatCard.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
    $cards=new AF_ACS_CombatCardQuery($link);$before=$capture();$card=$cards->forPosts('51',['501'],$access)['501'];$html=AF_ACS_CombatCard::render($card);
    snapshot_assert(str_contains($html,'Реакция')&&str_contains($html,'Цель:')&&!str_contains(json_encode($card),'fingerprint'),'Stored card omitted protection or leaked definition');
    $mybb->input['af_aa_mask']='changed-decoration';snapshot_assert($cards->forPosts('51',['501'],$access)['501']===$card,'Mask changed protection result');
    $link->query("UPDATE fixture_af_acs_session_roles SET role='player' WHERE session_id=".$sid." AND uid=7 AND role='owner'");$limited=$cards->forPosts('51',['501'],$access)['501'];
    snapshot_assert($limited['details']===null&&!str_contains(json_encode($limited),'original_target')&&!str_contains(AF_ACS_CombatCard::render($limited),'Реакция'),'Hidden reactions leaked to player DOM/JSON');
    $link->query("UPDATE fixture_af_acs_session_roles SET role='owner' WHERE session_id=".$sid." AND uid=7 AND role='player'");snapshot_assert($capture()===$before,'Card GET mutated state');
    $recovery=new AF_ACS_RecoveryService((object)['read_link'=>$link,'write_link'=>$link],$access);$out=$recovery->recover($id,'1');snapshot_assert($out['receipt']===$receipt&&$capture()===$before&&$draws===0,'Recovery rerolled or repeated protection');

    if($mode==='mass'){
        $pools=$repo->barrierProjection($sid,$eid);snapshot_assert(count($pools['pools'])===1&&count($repo->statusProjection($sid)['instances'])===0,'Pool persisted as invalid status or disappeared');
        snapshot_assert(count($link->query('SELECT * FROM fixture_af_acs_barrier_targets')->fetch_all(MYSQLI_ASSOC))===2,'Protected target membership lost');
        $pool=array_values($pools['pools'])[0];snapshot_assert($pool['source_entity_id']===$victim&&$pool['source_ref']['key']==='react-purify','Pool provenance lost');
        $poolId=(string)array_key_first($pools['pools']);$link->query("UPDATE fixture_af_acs_barrier_pools SET pool_version=pool_version+1 WHERE id=".$poolId);blocked(fn()=>$repo->barrierProjection($sid,$eid),'ACS_BARRIER_PROJECTION_INVALID');
    }else{
        snapshot_assert($step['original_target']===$protected&&$step['resolved_target']===$victim,'Writer ignored resolved target');
        $original=$tapeBefore[0];snapshot_assert($step['rolls']['hit']===$original['hit']&&$step['rolls']['crit']===$original['crit'],'Writer replaced immutable samples');
    }
    echo "ACS intercept/mass SQL ($mode): 012 resume, third-party controller/grant/targets/roster, pinned definitions, resolved ordinary turn, no seal/RNG mutations, causal-cycle preservation, late rollback, resource+slot+pool CAS, replay/recovery/visibility/mask and provenance passed\n";
};
require __DIR__.'/acs_combat_commit_regression.php';
