<?php
declare(strict_types=1);
// Known native PID + existing disposable MariaDB fixture; no new post/Gateway.
$GLOBALS['acsPresentationFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/ReactionService.php';
    $service=new AF_ACS_EncounterService($repo,$access);
    $actor=$active[0]['actor_id'];$victim=$active[0]['targets'][0];$reactions=$service->reactions();$entry=5000;
    $old=$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'011_reaction_registry' ORDER BY version")->fetch_all(MYSQLI_ASSOC);
    $link->query('DROP TABLE fixture_af_acs_reactions');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=0 WHERE version='011_reaction_registry'");$migrations->run();$migrations->run();
    snapshot_assert($migrations->status()['schema_version']==='011_reaction_registry'&&$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<'011_reaction_registry' ORDER BY version")->fetch_all(MYSQLI_ASSOC)===$old,'011 resume modified earlier ledger');
    $db->tables['af_kb_entries'][]=snapshot_entry(++$entry,'arpg_mechanics','ep',['schema'=>AF_KB_ARPG_RULES_SCHEMA,'service_kind'=>'resource_def','entries'=>[['schema'=>'af_kb.arpg.mechanic.v1','key'=>'ep','version'=>'1','resource_key'=>'ep','unit'=>'points']]]);
    $grants=[];$definitions=[];
    foreach(['expensive'=>99,'purify'=>1,'healing'=>1] as $key=>$cost){
        $rules=af_kb_default_type_profile_payload_arpg('arpg_ability');$rules=array_replace($rules,['schema'=>AF_KB_ARPG_RULES_SCHEMA,'version'=>'1','targeting'=>'self','duration_unit'=>'own_turn','cooldown_unit'=>'own_turn','max_charges'=>0,
            'costs'=>[['kind'=>'resource','resource_key'=>'ep','value'=>$cost,'timing'=>'use']],
            'effects'=>[$key==='purify'?['kind'=>'cleanse','chance'=>25]:['kind'=>'heal','value'=>3,'can_crit'=>false]]]);
        $db->tables['af_kb_entries'][]=snapshot_entry(++$entry,'arpg_ability','react-'.$key,$rules);
        $definitions['react-'.$key]=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability','react-'.$key,7);snapshot_assert($definitions['react-'.$key]['ready'],'Reaction owner ability blocked');
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
    $make=static function(string $trigger,string $key)use($service,$sid,$eid,$victim,$link):array{$link->query('UPDATE fixture_af_acs_operations SET updated_at=0');$v=$service->view($sid,$eid);return ['session_id'=>$sid,'encounter_id'=>$eid,'entity_id'=>$victim,'trigger_key'=>$trigger,'expected_version'=>$v['session']['session_version'],'encounter_version'=>$v['encounter']['encounter_version'],'operation_key'=>$key,'reason'=>'Reaction fixture'];};
    $request=$make('trigger-expensive','reaction_register_fixture_01');$mybb->user['uid']=9;blocked(fn()=>$reactions->command('register',$request),'ACS_REACTION_ACCESS_DENIED');blocked(fn()=>$reactions->view($sid,$eid,$victim),'ACS_REACTION_ACCESS_DENIED');$mybb->user['uid']=7;
    // Registration is controlled by the source controller, not by GM status or pending ordinary turn.
    $link->query("UPDATE fixture_af_acs_session_roles SET role='player' WHERE session_id=".$sid." AND uid=7 AND role='owner'");$first=$reactions->command('register',$request);
    $link->query("UPDATE fixture_af_acs_session_roles SET role='owner' WHERE session_id=".$sid." AND uid=7 AND role='player'");
    snapshot_assert($reactions->command('register',$request)===$first,'Duplicate registration replay changed identity');blocked(fn()=>$reactions->command('register',$make('trigger-expensive','reaction_duplicate_fixture1')),'ACS_REACTION_ALREADY_REGISTERED');
    $second=$reactions->command('register',$make('trigger-purify','reaction_register_fixture_02'));$third=$reactions->command('register',$make('trigger-healing','reaction_register_fixture_03'));
    $safe=$reactions->view($sid,$eid,$victim);snapshot_assert(count($safe['reactions'])===3&&$safe['reaction_used']===0&&!str_contains(json_encode($safe),'fingerprint'),'Registration consumed limit or exposed pinned graph');
    snapshot_assert($repo->clockTurn($sid,$victimTurn['id'])['status']==='resolved','Registration reopened ordinary turn');
    // Live KB changes do not alter registered graphs or already retained actor definitions.
    foreach($db->tables['af_kb_entries'] as &$live)if($live['key']==='react-purify'){$changed=json_decode($live['data_json'],true);$changed['version']='2';$changed['effects'][0]['chance']=100;$live['data_json']=json_encode($changed);}unset($live);
    $ownerState=$db->tables;$ownerReads=count($db->queries);
    $before=$capture();$tapeBefore=array_map(fn($s)=>$repo->sealTape((string)$s['id']),$repo->sealRows($d['draft_id'],true));$view=$seal->status($d['draft_id']);
    snapshot_assert($view['blocked']&&$capture()===$before,'Read-only view allocated reaction roll or changed state');
    $draws=0;$reactionSeal=new AF_ACS_SealService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){$draws++;return 9007199254740991;}));
    $req=['draft_id'=>$d['draft_id'],'expected_draft_version'=>$view['draft_version'],'operation_key'=>'prepare_reaction_checks01'];$prepared=$reactionSeal->command('prepare_reactions',$req);
    snapshot_assert(!$prepared['blocked']&&$draws===count($repo->sealRows($d['draft_id'],true)),'Prepared causal reaction roll failed: '.json_encode($prepared));
    snapshot_assert($reactionSeal->command('prepare_reactions',$req)===$prepared&&$draws===1,'Repeat request rerolled reaction');
    snapshot_assert(array_map(fn($s)=>$repo->sealTape((string)$s['id']),$repo->sealRows($d['draft_id'],true))===$tapeBefore,'Reaction replaced original hit/crit tape');
    $confirmed=$reactionSeal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$prepared['draft_version'],'operation_key'=>'reaction_confirm_fixture','confirmation_hash'=>$prepared['confirmation_hash']]);
    snapshot_assert(isset($confirmed['summary']['reaction_hash'])&&$repo->clockEntityTurn($sid,$individual['id'])['reaction_used']==0,'Seal spent reaction slot');
    $baseDraft=$repo->draft($d['draft_id'],'7');$id=str_repeat('c',32);$r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$baseDraft['id'],'author_uid'=>'7','expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
    $r=$store->validating($id,$r['state_version'],static function(){});$r=$store->posting($id,$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});$r=$store->confirmPost($id,$r['state_version'],'501',static function(){},'1');
    $before=$capture();$link->query("CREATE TRIGGER reaction_commit_fault BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='reaction rollback'");
    blocked(fn()=>$writer->commit($id,$r['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER reaction_commit_fault');snapshot_assert($capture()===$before,'Reaction rollback left cost/slot/version changes');
    $receipt=$writer->commit($id,$r['state_version']);$p=$repo->reactionProjection($sid,$eid)['entries'];
    snapshot_assert($receipt['status']==='COMMITTED'&&$p[$second['reaction_id']]['reaction_version']===2&&$p[$first['reaction_id']]['reaction_version']===1&&$p[$third['reaction_id']]['reaction_version']===1,'Wrong reaction executed or shortage aborted foreign attack');
    snapshot_assert((int)$repo->clockEntityTurn($sid,$individual['id'])['reaction_used']===1&&$repo->statusResources($victim,$repo->entity($sid,$victim))['values']['ep']['current']===1,'Reaction slot/resource not committed atomically');
    $before=$capture();snapshot_assert($writer->commit($id,$r['state_version'])===$receipt&&$capture()===$before&&$draws===1,'Duplicate commit executed/rerolled reaction');
    $event=$link->query('SELECT payload_json FROM fixture_af_acs_combat_results WHERE submission_id=\''.$id.'\'')->fetch_assoc();$plan=json_decode($event['payload_json'],true)['plan'];$trace=$plan['actions'][0]['reactions'];
    snapshot_assert($trace[0]['reason']==='effects_cost_unaffordable'&&$trace[1]['executed']&&$trace[2]['reason']==='limit_used','Recorded queue/skip/limit order wrong');
    snapshot_assert($db->tables===$ownerState&&count($db->queries)===$ownerReads,'Execution read/wrote live KB or permanent owner state');
    echo "ACS reaction SQL: 011 resume/schema, pinned controller grant, unauthorized/duplicate/after-resolved registration, hidden read-only DTO, separate causal tape, affordability skip, cost+slot+CAS rollback and commit replay passed\n";
};
require __DIR__.'/acs_combat_commit_regression.php';
