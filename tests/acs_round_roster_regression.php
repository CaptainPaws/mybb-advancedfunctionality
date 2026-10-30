<?php
declare(strict_types=1);
// Isolated MariaDB + real ACS repositories/services; reuse the existing small owner fixture.
$GLOBALS['acsRoundFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // A fixture may run many checks in one second. Do not change the production rate limit.
    $link->query('UPDATE fixture_af_acs_operations SET updated_at=0');
    foreach(['turns','entity_turns'] as $table)$link->query('DROP TABLE fixture_af_acs_'.$table);
    $link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=1 WHERE version='004_round_roster'");
    $migrations->run();$migrations->run();snapshot_assert($migrations->status()['schema_version']==='004_round_roster','004 resume/repeat failed');
    $view=$service->view($sid,$eid);
    $input=['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$view['session']['session_version'],'encounter_version'=>$view['encounter']['encounter_version'],'operation_key'=>'first_round_fixture1','reason'=>'Open first round','tid'=>'51'];
    // A pre-existing active layer must never be ignored merely to open a roster.
    $link->query('UPDATE fixture_af_acs_entity_states SET layers_json=\'{"schema_version":1,"layers":[{"type":"sustained"}]}\' WHERE entity_id='.$first['entity_id']);
    snapshot_assert(!$service->readiness($sid,$eid)['can_start'],'Unsupported tick/upkeep state accepted');
    blocked(fn()=>$service->command('round_start',$input),'ACS_ROSTER_BLOCKED');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_rounds')->fetch_row()[0]==='0','Failed start left a partial round');
    $link->query('UPDATE fixture_af_acs_entity_states SET layers_json=\'{"schema_version":1,"layers":[]}\' WHERE entity_id='.$first['entity_id']);
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/Encounters.php';
    $mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>'rounds'];
    $startHtml=AF_ACS_EncountersPage::render($sid,'51',$service);
    snapshot_assert(str_contains($startHtml,'value="round_start"') && str_contains($startHtml,'Запустить первый раунд'),'GM first-round button missing');
    $mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    $sourceBefore=$db->tables;
    $resultStart=$service->command('round_start',$input);
    snapshot_assert($service->command('round_start',$input)===$resultStart,'Same operation did not return exact round result');
    snapshot_assert($resultStart['turn_count']==='3' && $resultStart['entity_turn_count']==='4','Wrong group/entity count');
    snapshot_assert($db->tables===$sourceBefore,'Round start wrote KB/CS sources');
    $round=$service->roundView($sid,$eid);snapshot_assert($round['round']['round_number']==='1','Round number wrong');
    snapshot_assert($service->view($sid,$eid)['encounter']['status']==='active','Roster commit did not activate encounter');
    $groups=[];$entityTurns=[];
    foreach($round['turns'] as $turn) {
        snapshot_assert($turn['status']==='pending' && $turn['ap_budget']==='3' && $turn['ap_spent']==='0' && $turn['deadline_at']===null,'Initial turn state/budget wrong');
        foreach($turn['roster']['members'] as $member){$groups[$member['entity_id']]=$turn['id'];$entityTurns[$member['entity_id']]=$turn['individual_turns'][$member['entity_id']];}
    }
    snapshot_assert($groups[$result['entity_id']]===$groups[$registeredPet['entity_id']],'Character/pet received two turns');
    snapshot_assert($groups[$first['entity_id']]!==$groups[$second['entity_id']],'Monsters share a turn');
    foreach($entityTurns as $individual) {
        snapshot_assert($individual['start_done']==='0' && $individual['end_done']==='0' && $individual['reaction_used']==='0','Boundary effects were simulated');
        snapshot_assert($individual['start_boundary']===AF_ACS_Clock::entityStart($individual['id']) && $individual['end_boundary']===AF_ACS_Clock::entityEnd($individual['id']),'Clock identities not stable');
    }
    $rowsBefore=$link->query('SELECT * FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $individualBefore=$link->query('SELECT * FROM fixture_af_acs_entity_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $eventBefore=$link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row();
    $current=$service->view($sid,$eid);
    blocked(fn()=>$service->command('round_start',array_replace($input,['operation_key'=>'second_round_key_01','expected_version'=>$current['session']['session_version'],'encounter_version'=>$current['encounter']['encounter_version']])),'ACS_FIRST_ROUND_EXISTS');
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/Encounters.php';
    $mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>'rounds'];
    $html=AF_ACS_EncountersPage::render($sid,'51',$service);
    snapshot_assert(str_contains($html,'Раунд 1') && str_contains($html,'Ожидает') && !str_contains($html,'value="round_start"'),'Round tab missing or still offers a second start');
    snapshot_assert($service->roundView($sid,$eid)===$round,'Read-only roster changed');
    snapshot_assert($link->query('SELECT * FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$rowsBefore && $link->query('SELECT * FROM fixture_af_acs_entity_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$individualBefore,'GET consumed/refreshed a boundary');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()===$eventBefore,'GET wrote a clock event');
    $mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // Add a real independently registered late monster from a complete inline KB source.
    $template=null;foreach($db->tables['af_kb_entries'] as $entry)if(($entry['type']??'')==='arpg_bestiary')$template=$entry;
    $lateRules=json_decode($template['data_json'],true);$lateRules['rules']['ability_keys']=[];
    $db->tables['af_kb_entries'][]=['id'=>100,'type'=>'arpg_bestiary','key'=>'late-monster','active'=>1,'data_json'=>json_encode($lateRules,JSON_THROW_ON_ERROR),'meta_json'=>'{}'];
    $late=$entities->register(['session_id'=>$sid,'expected_version'=>$service->view($sid,$eid)['session']['session_version'],'kind'=>'monster','source_key'=>'late-monster','initial_hp'=>'20','reason'=>'Late monster','operation_key'=>'late_monster_000001']);
    $side=$service->view($sid,$eid)['sides'][0]['id'];
    $admitted=$command('participant_add',['entity_id'=>$late['entity_id'],'side_id'=>$side]);snapshot_assert($admitted['admission']==='next_round','Late join lacks next-round policy');
    snapshot_assert($link->query('SELECT * FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$rowsBefore && $link->query('SELECT * FROM fixture_af_acs_entity_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$individualBefore,'Late group got a free current turn/EntityTurn');
    snapshot_assert($service->roundView($sid,$eid)===$round,'Late registration changed immutable roster');
    // A stored terminal turn is never reopened by a healed entity or another view.
    $closed=$groups[$first['entity_id']];$link->query("UPDATE fixture_af_acs_turns SET status='resolved',turn_version=turn_version+1 WHERE id=".$closed);
    $link->query("UPDATE fixture_af_acs_entity_states SET life_state='incapacitated',hp=0 WHERE entity_id=".$first['entity_id']);
    $link->query("UPDATE fixture_af_acs_entity_states SET life_state='active',hp=30 WHERE entity_id=".$first['entity_id']);
    $read=$service->roundView($sid,$eid);foreach($read['turns'] as $turn)if($turn['id']===$closed)snapshot_assert($turn['status']==='resolved','Healing reopened a closed turn');
    foreach([AF_ACS_TurnState::Resolved,AF_ACS_TurnState::Passed,AF_ACS_TurnState::Forfeited] as $state)snapshot_assert(!AF_ACS_Clock::transitionExists($state,AF_ACS_TurnState::Pending),'Clock graph allows reopen');
    blocked(fn()=>$service->command('turn_resolve',$input),'ACS_ENCOUNTER_TRANSITION_BLOCKED');
    $mybb->user['uid']=9;blocked(fn()=>$service->roundView($sid,$eid),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $GLOBALS['af_acs_deactivated']=true;blocked(fn()=>$service->command('round_start',$input),'ACS_DISABLED');unset($GLOBALS['af_acs_deactivated']);$migrations->assertInstalled();
    snapshot_assert($service->roundView($sid,$eid)===$read,'Reactivation changed roster');
    echo "ACS round roster: one atomic first round/replay, character+pet one turn, independent monsters, stable per-entity boundaries, unsupported layer gate, GET no ticks, late-group deferral, closed-turn persistence and 004 resume passed\n";
};
require __DIR__.'/acs_encounters_regression.php';
