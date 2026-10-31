<?php
declare(strict_types=1);
$GLOBALS['acsResourcesFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    $mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
    $link->query('UPDATE fixture_af_acs_operations SET updated_at=0');
    // Prior fixture's artificial terminal row is reset only here to start this independent scenario.
    $link->query("UPDATE fixture_af_acs_turns SET status='pending' WHERE id=".$groups[$first['entity_id']]);
    $now=time();$rounds=$service->rounds(static function()use(&$now):int{return $now;});
    $make=static function(array $extra=[])use($service,$repo,$sid,$eid):array {
        $v=$service->view($sid,$eid);$input=$extra+['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$v['session']['session_version'],'encounter_version'=>$v['encounter']['encounter_version'],'confirm'=>'1','reason'=>'GM management fixture','operation_key'=>bin2hex(random_bytes(16))];
        if(isset($extra['turn_id']))$input['turn_version']=$repo->clockTurn($sid,$extra['turn_id'])['turn_version'];return $input;
    };
    $external=$db->tables;$initial=$rounds->overview($sid,$eid);snapshot_assert($initial['policy']['mode']==='off','Timeout defaults changed');
    $heroTurn=$groups[$result['entity_id']];$link->query('UPDATE fixture_af_acs_turns SET ap_spent=1 WHERE id='.$heroTurn);
    $rounds->command('deadline_set',$make(['deadline_mode'=>'gm_skip','seconds'=>'100']));
    $beforePause=$link->query('SELECT id,status,ap_budget,ap_spent FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $now+=30;$pause=$make();$paused=$rounds->command('pause',$pause);snapshot_assert($rounds->command('pause',$pause)===$paused,'Pause retry wrote again');
    $frozen=$repo->clockTurn($sid,$heroTurn);snapshot_assert($frozen['deadline_at']===null && (string)$frozen['remaining_deadline_seconds']==='70','Pause did not freeze exact remainder');
    snapshot_assert($link->query('SELECT id,status,ap_budget,ap_spent FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforePause,'Pause changed turns/AP');
    blocked(fn()=>$rounds->command('gm_skip',$make(['turn_id'=>$heroTurn])),'ACS_ROUND_STATE_CONFLICT');
    blocked(fn()=>$rounds->command('round_next',$make(['round_id'=>$round['round']['id']])),'ACS_ROUND_STATE_CONFLICT');
    $now+=1000;$rounds->command('extend_deadline',$make(['turn_id'=>$heroTurn,'seconds'=>'10']));
    $rounds->command('resume',$make());snapshot_assert((int)$repo->clockTurn($sid,$heroTurn)['deadline_at']===$now+80,'Resume/extension lost frozen remainder');
    // Two actual DB connections contend for the same mechanical lock (no sleeps/stress).
    $database=$link->query('SELECT DATABASE()')->fetch_row()[0];$lock='acs:'.substr(hash('sha256',$database.':'.TABLE_PREFIX),0,56);
    $stmt=$link->prepare('SELECT GET_LOCK(?,0)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();
    $other=new mysqli('localhost','root','',$database,0,$GLOBALS['argv'][1]);$other->set_charset('utf8mb4');$otherRepo=new AF_ACS_EncounterRepository($other);$otherService=new AF_ACS_RoundManagementService($otherRepo,$access,static function()use(&$now){return $now;});
    $mybb->user['uid']=8;$raceInput=$make();blocked(fn()=>$otherService->command('pause',$raceInput),'ACS_MAINTENANCE_BUSY');
    $stmt=$link->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();$other->close();
    // Interleaved GMs: the losing stale command cannot overwrite a successful one.
    $stale=$make();$rounds->command('deadline_set',$make(['deadline_mode'=>'notify','seconds'=>'10']));$mybb->user['uid']=7;blocked(fn()=>$rounds->command('pause',$stale),'ACS_VERSION_CONFLICT');
    $now+=11;$notify=$make(['turn_id'=>$heroTurn]);$notice=$rounds->command('deadline_process',$notify);snapshot_assert($notice['notification']==='recorded_delivery_unavailable' && $repo->clockTurn($sid,$heroTurn)['status']==='pending','Notification policy skipped a turn');
    snapshot_assert($rounds->command('deadline_process',$make(['turn_id'=>$heroTurn]))===$notice,'Duplicate notification');
    $rounds->command('deadline_set',$make(['deadline_mode'=>'timeout_pass','seconds'=>'1']));$now+=2;
    $passed=$rounds->command('deadline_process',$make(['turn_id'=>$heroTurn]));snapshot_assert($passed['status']==='passed','Allowed timeout failed');
    snapshot_assert($rounds->command('deadline_process',$notify)===$notice,'Policy changes changed historical notification replay');
    snapshot_assert($rounds->command('gm_skip',$make(['turn_id'=>$heroTurn]))===$passed,'Different-key duplicate skip changed result');
    snapshot_assert($repo->clockTurn($sid,$heroTurn)['ap_spent']===1 || (string)$repo->clockTurn($sid,$heroTurn)['ap_spent']==='1','Skip spent/refunded AP');
    // Pending draft/sealed obligation blocks skip and is never deleted or modified.
    $architecture=file_get_contents(dirname(__DIR__).'/docs/acs/ACS_TECHNICAL_ARCHITECTURE.md');preg_match('/CREATE TABLE `\{prefix\}af_acs_drafts` \(.*?;/s',$architecture,$ddl);$link->query(str_replace('{prefix}',TABLE_PREFIX,$ddl[0]));
    $monsterTurn=$groups[$second['entity_id']];$json=AF_ACS_Repository::json(['schema_version'=>1]);$stmt=$link->prepare("INSERT INTO fixture_af_acs_drafts (encounter_id,turn_id,author_uid,status,draft_version,mechanics_json,prose,dependency_json,updated_at) VALUES (?,?,7,'sealed',1,?,'Keep prose',?,?)");$stamp=time();$stmt->bind_param('ssssi',$eid,$monsterTurn,$json,$json,$stamp);$stmt->execute();$stmt->close();
    $opsBefore=$link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row();
    blocked(fn()=>$rounds->command('gm_skip',$make(['turn_id'=>$monsterTurn])),'ACS_ROUND_OBLIGATIONS_BLOCKED');
    snapshot_assert($link->query('SELECT status,prose FROM fixture_af_acs_drafts')->fetch_row()===['sealed','Keep prose'] && $link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()===$opsBefore,'Skip altered draft or wrote partial event');$link->query('DROP TABLE fixture_af_acs_drafts');
    blocked(fn()=>$rounds->command('gm_forfeit',$make(['turn_id'=>$monsterTurn])),'ACS_FORFEIT_EXCEPTION_UNAVAILABLE');
    blocked(fn()=>$rounds->command('round_next',$make(['round_id'=>$round['round']['id']])),'ACS_PENDING_TURNS_REMAIN');
    // Approved withdrawal projection fixture: only a fully withdrawn group can forfeit.
    $withdrawn=$first['entity_id'];$link->query("UPDATE fixture_af_acs_participants SET participation='withdrawn' WHERE encounter_id=".$eid.' AND entity_id='.$withdrawn);
    $forfeit=$rounds->command('gm_forfeit',$make(['turn_id'=>$groups[$withdrawn]]));snapshot_assert($forfeit['status']==='forfeited','Valid withdrawn exception not handled');
    $skipInput=$make(['turn_id'=>$monsterTurn]);$skip=$rounds->command('gm_skip',$skipInput);snapshot_assert($rounds->command('gm_skip',$skipInput)===$skip,'Same-key skip duplicated');
    $oldTurns=$link->query('SELECT id,status,ap_spent,roster_json FROM fixture_af_acs_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    foreach($repo->individualTurns(array_values(array_unique($groups))) as $individual)snapshot_assert($individual['start_done']==='1' && $individual['end_done']==='1','Skip omitted individual boundary');
    $nextInput=$make(['round_id'=>$round['round']['id']]);$next=$rounds->command('round_next',$nextInput);snapshot_assert($next['round_number']==='2','Next round not created');
    snapshot_assert($rounds->command('round_next',$nextInput)===$next,'Next-round retry duplicated');$mybb->user['uid']=8;snapshot_assert($rounds->command('round_next',array_replace($nextInput,['operation_key'=>'competing_next_0001']))===$next,'Competing GM created second next round');$mybb->user['uid']=7;
    $current=$service->roundView($sid,$eid);snapshot_assert($current['round']['round_number']==='2','UI still reads first round');
    $members=[];foreach($current['turns'] as $turn) {snapshot_assert($turn['status']==='pending' && $turn['ap_budget']==='3' && $turn['ap_spent']==='0','New AP/turn invalid');foreach($turn['roster']['members'] as $member)$members[]=$member['entity_id'];foreach($turn['individual_turns'] as $individual)snapshot_assert($individual['start_done']==='1' && $individual['end_done']==='0','New own start not applied once');}
    snapshot_assert(count($members)===5-1 && !in_array($withdrawn,$members,true),'New roster ignored withdrawal/late admission');
    snapshot_assert($link->query('SELECT id,status,ap_spent,roster_json FROM fixture_af_acs_turns WHERE round_id='.$round['round']['id'].' ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$oldTurns,'Next round changed historical roster/AP');
    $beforeRead=$link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row();$beforeBoundaries=$link->query('SELECT * FROM fixture_af_acs_entity_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC);$mybb->request_method='get';$mybb->input=['acs_encounter'=>$eid,'acs_tab'=>'rounds','acs_encounter_op'=>'manage_gm_skip'];
    $html=AF_ACS_EncountersPage::render($sid,'51',$service);snapshot_assert(str_contains($html,'Раунд 2') && str_contains($html,'Поставить на паузу'),'GM controls/current roster missing');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_events')->fetch_row()===$beforeRead && $link->query('SELECT * FROM fixture_af_acs_entity_turns ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforeBoundaries,'GET mutated clock');
    $mybb->user['uid']=9;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];blocked(fn()=>$rounds->command('pause',$make()),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $rounds->command('deadline_set',$make(['deadline_mode'=>'off']));$turn=$service->roundView($sid,$eid)['turns'][0];blocked(fn()=>$rounds->command('deadline_process',$make(['turn_id'=>$turn['id']])),'ACS_DEADLINE_AUTOMATIC_DISABLED');
    snapshot_assert($db->tables===$external,'Management changed KB/CS/Inventory');
    echo "ACS round management: pause/resume frozen deadlines/AP, stale/locked competing GMs, notify vs timeout, skip/forfeit boundaries, obligations retention, duplicate next roster/AP and read-only GET passed\n";
};
require __DIR__.'/acs_round_roster_regression.php';
