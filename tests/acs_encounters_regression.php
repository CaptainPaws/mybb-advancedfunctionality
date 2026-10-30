<?php
declare(strict_types=1);
// Reuse the tiny source fixture and isolated database; no extra framework or production DB.
$acsEncounterFixture=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/EncounterService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/Encounters.php';
    $repo=new AF_ACS_EncounterRepository($link);$service=new AF_ACS_EncounterService($repo,$access);
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    // Simulate interruption of 003 after encounters/sides only, in this disposable DB.
    $beforeSnapshots=$link->query('SELECT COUNT(*) FROM fixture_af_acs_snapshots')->fetch_row();
    foreach(['side_relationships','participants'] as $table)$link->query('DROP TABLE fixture_af_acs_'.$table);
    $link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=2 WHERE version='003_encounters'");
    $migrations->run();$migrations->run();snapshot_assert($migrations->status()['schema_version']==='005_clock_resources','003 resume failed');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_snapshots')->fetch_row()===$beforeSnapshots,'003 changed existing snapshots');
    $version=$sessions->view($sid)['session']['session_version'];
    $create=['session_id'=>$sid,'expected_version'=>$version,'name'=>'Test <encounter>','reason'=>'Prepare fixture','operation_key'=>'encounter_fixture_01','tid'=>'51'];
    $created=$service->command('create',$create);$eid=$created['encounter_id'];
    snapshot_assert($service->command('create',$create)===$created,'Duplicate encounter');
    snapshot_assert($service->view($sid,$eid)['encounter']['status']==='preparation','Encounter pretends to be active');
    $command=static function(string $op,array $extra=[])use($service,$sessions,$sid,$eid):array {
        $view=$service->view($sid,$eid);
        return $service->command($op,$extra+['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$view['session']['session_version'],'encounter_version'=>$view['encounter']['encounter_version'],'reason'=>'Fixture command','operation_key'=>bin2hex(random_bytes(16))]);
    };
    $a=$command('side_create',['name'=>'Игроки']);$b=$command('side_create',['name'=>'Противники']);
    $command('relation_set',['side_a'=>$a['side_id'],'side_b'=>$b['side_id'],'relationship'=>'hostile']);
    foreach([[$result,$a],[$registeredPet,$a],[$first,$b],[$second,$b]] as [$entity,$side])$command('participant_add',['entity_id'=>$entity['entity_id'],'side_id'=>$side['side_id']]);
    $view=$service->view($sid,$eid);snapshot_assert(count($view['sides'])===2 && count($view['participants'])===4,'Sides/participants lost');
    $groups=array_column($view['participants'],'control_group_id','entity_id');
    snapshot_assert($groups[$result['entity_id']]===$groups[$registeredPet['entity_id']],'Pet group split');
    snapshot_assert($groups[$first['entity_id']]!==$groups[$second['entity_id']],'Monsters merged by GM uid');
    $ready=$service->readiness($sid,$eid);snapshot_assert($ready['preparation_ready'] && $ready['can_start'],'Readiness failed or start faked: '.json_encode($ready));
    snapshot_assert(!$ready['blocked_capabilities'],'Valid roster remains blocked');
    $before=$service->view($sid,$eid);
    blocked(fn()=>$service->command('start',$create),'ACS_ENCOUNTER_TRANSITION_BLOCKED');
    blocked(fn()=>$command('controller_assign',['entity_id'=>$result['entity_id'],'uid'=>'8']),'ACS_CONTROL_INVALID');
    blocked(fn()=>$command('controller_assign',['entity_id'=>$registeredPet['entity_id'],'uid'=>'8']),'ACS_CONTROL_INVALID');
    $assignment=$command('controller_assign',['entity_id'=>$first['entity_id'],'uid'=>'8']);
    snapshot_assert($repo->controllers($sid,$first['entity_id'])[0]['controller_uid']==='8','NPC controller not assigned');
    snapshot_assert($repo->entity($sid,$first['entity_id'])['control_group_id']===$first['control_group_id'],'NPC reassignment merged group');
    $last=$service->view($sid,$eid);$stale=['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$last['session']['session_version'],'encounter_version'=>$before['encounter']['encounter_version'],'name'=>'Stale','reason'=>'Test CAS','operation_key'=>'stale_encounter_001'];
    blocked(fn()=>$service->command('side_create',$stale),'ACS_VERSION_CONFLICT');
    $mybb->user['uid']=8;$mybb->request_method='get';snapshot_assert(count($service->list($sid)['items'])===1,'Appointed GM cannot view');
    blocked(fn()=>$service->command('side_create',$stale),'ACS_POST_REQUIRED');
    $mybb->user['uid']=9;blocked(fn()=>$service->view($sid,$eid),'ACS_SESSION_UNAVAILABLE');$mybb->user['uid']=7;$mybb->request_method='post';
    $state=$sessions->view($sid)['session'];$sessions->command('set_role',['session_id'=>$sid,'expected_version'=>$state['session_version'],'uid'=>'9','role'=>'player','reason'=>'Join player','operation_key'=>'encounter_player_01']);
    $mybb->user['uid']=9;blocked(fn()=>$service->view($sid,$eid),'ACS_GM_REQUIRED');blocked(fn()=>$service->command('create',array_replace($create,['expected_version'=>$sessions->view($sid)['session']['session_version'],'operation_key'=>'player_encounter_01'])),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    // Cross-session and cross-thread links never authorize access or references.
    blocked(fn()=>$service->view($sid,$eid,'999'),'ACS_ENCOUNTER_UNAVAILABLE');
    $other=$sessions->command('create',['tid'=>'51','owner_uid'=>'7','reason'=>'Other scope','operation_key'=>'other_session_0001']);
    $otherCreate=$service->command('create',['session_id'=>$other['session_id'],'expected_version'=>$other['session_version'],'name'=>'Other','reason'=>'Other','operation_key'=>'other_encounter_001']);
    $otherSide=$service->command('side_create',['session_id'=>$other['session_id'],'encounter_id'=>$otherCreate['encounter_id'],'expected_version'=>$otherCreate['session_version'],'encounter_version'=>'1','name'=>'Other side','reason'=>'Other','operation_key'=>'other_side_0000001']);
    blocked(fn()=>$command('participant_side',['entity_id'=>$first['entity_id'],'side_id'=>$otherSide['side_id']]),'ACS_SIDE_UNAVAILABLE');
    blocked(fn()=>$service->view($other['session_id'],$eid),'ACS_ENCOUNTER_UNAVAILABLE');
    // A malformed immutable snapshot is BLOCKED; do not rebuild it from current KB.
    $link->query("UPDATE fixture_af_acs_snapshots SET content_hash=REPEAT('0',64) WHERE id=".$first['snapshot_id']);
    snapshot_assert(!$service->readiness($sid,$eid)['preparation_ready'],'Invalid pinned hash accepted');
    $link->query('UPDATE fixture_af_acs_snapshots SET content_hash=SHA2(payload_json,256) WHERE id='.$first['snapshot_id']);
    $sourceBefore=$db->tables;$opsBefore=$link->query('SELECT COUNT(*) FROM fixture_af_acs_operations')->fetch_row();
    foreach(['overview'=>'Обзор','participants'=>'Участники','rounds'=>'Первый раунд не запущен','events'=>'Подтвержд','management'=>'Проверить готовность'] as $tab=>$label) {
        $mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>$tab];$html=AF_ACS_EncountersPage::render($sid,'51',$service);
        snapshot_assert(str_contains($html,$tab==='events'?'Создано столкновение':$label),'GM tab missing: '.$tab);
        snapshot_assert(!str_contains($html,'Test <encounter>') && !str_contains($html,'sha256:') && !str_contains($html,'payload_json'),'GM view leaks unescaped/raw snapshot');
    }
    snapshot_assert($db->tables===$sourceBefore && $link->query('SELECT COUNT(*) FROM fixture_af_acs_operations')->fetch_row()===$opsBefore,'GET GM panel changed data');
    $events=$service->events($sid,$eid);snapshot_assert(count($events)===9,'Confirmed encounter audit count wrong: '.count($events));
    foreach($events as $index=>$event)snapshot_assert($event['event_sequence']===(string)($index+1),'Encounter sequence gap/duplicate');
    $mybb->request_method='post';$mybb->input=['my_post_key'=>'bad'];blocked(fn()=>$command('side_create',['name'=>'No CSRF']),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];
    $persisted=$service->view($sid,$eid);$GLOBALS['af_acs_deactivated']=true;blocked(fn()=>$command('side_create',['name'=>'Disabled']),'ACS_DISABLED');unset($GLOBALS['af_acs_deactivated']);$migrations->assertInstalled();snapshot_assert($service->view($sid,$eid)===$persisted,'Reactivation changed encounter');
    if(isset($GLOBALS['acsRoundFixture']))$GLOBALS['acsRoundFixture'](compact('link','sid','eid','service','repo','access','sessions','entities','result','first','second','registeredPet','command','migrations'));
    echo "ACS encounters: create/retry/CAS, two sides/hostile, character+pet group, independent monsters, NPC-only assignment, readiness/roster gate, scopes/permissions/CSRF, 5 GM tabs/read-only audit and 003 resume passed\n";
};
require __DIR__.'/acs_entities_regression.php';
