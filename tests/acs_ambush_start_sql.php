<?php
/** Limited start coordinator, authoritative disposable MySQL; no fabricated preparation. */
function get_user($uid):array { global $db;return $db->write_link->query('SELECT * FROM t_users WHERE uid='.(int)$uid)->fetch_assoc()?:[]; }
function blocked(callable $call,string $code):void {itemFail($call,$code);}
$GLOBALS['acsCustodyFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb;
    // Use the current same-writer Stage-6 SQL harness, not the old mocked source harness.
    $link->query("UPDATE t_af_acs_encounters SET status='completed' WHERE id=1");
    $service=new AF_ACS_EncounterService($repo,$access);
    $created=$service->command('create',['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'ambush_create_fixture_01','reason'=>'Disposable start','name'=>'Start fixture']);$eid=$created['encounter_id'];
    $change=static function(string $op,array $extra=[])use($service,$repo,$sid,$eid):array{return $service->command($op,$extra+['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$repo->encounter($sid,$eid)['encounter_version'],'operation_key'=>bin2hex(random_bytes(16)),'reason'=>'Start setup']);};
    $side=$change('side_create',['name'=>'One side']);$change('participant_add',['entity_id'=>$actor,'side_id'=>$side['side_id']]);

    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/AmbushStartService.php';
    $ambush=new AF_ACS_AmbushStartService($repo,$access);
    $capture=static function()use($link):array{$out=[];foreach(glob(AF_ADDONS.'advancedcombatsystem/migrations/[0-9][0-9][0-9]_*.php') as $f)foreach(array_keys((require $f)['tables']) as $t)$out[$t]=$link->query('SELECT * FROM t_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;};
    $request=static function(string $key)use($repo,$sid,$eid):array{$e=$repo->encounter($sid,$eid);return ['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$repo->session($sid)['session_version'],'encounter_version'=>$e['encounter_version'],'reason'=>'Start coordinator fixture','operation_key'=>$key];};
    $link->query('UPDATE t_af_acs_operations SET updated_at=0');$r=$request('ambush_ready_fixture_001');$before=$capture();
    $mybb->request_method='get';blocked(fn()=>$ambush->command('ready',$r),'ACS_POST_REQUIRED');$mybb->request_method='post';$mybb->input=['my_post_key'=>'bad'];blocked(fn()=>$ambush->command('ready',$r),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];
    $mybb->user['uid']=9;blocked(fn()=>$ambush->command('ready',$r),'ACS_SESSION_UNAVAILABLE');$mybb->user['uid']=7;snapshot_assert($capture()===$before,'Denied ready mutated');
    blocked(fn()=>$ambush->command('ready',$r+['pre_round_ap_commitment'=>0]),'ACS_AMBUSH_INPUT_INVALID');
    itemInsert($link,'af_acs_prepared_actions',['session_id'=>$sid,'encounter_id'=>$eid,'entity_id'=>$actor,'control_group_id'=>$group,'owner_uid'=>7,'source_sealed_action_id'=>999,'source_entity_turn_id'=>1,'status'=>'active','prepared_version'=>1,'expires_boundary'=>'until_next_turn','cost_state'=>'paid','contract_hash'=>hash('sha256','unsupported fixture'),'contract_json'=>'{"schema_version":1}']);
    blocked(fn()=>$ambush->command('ready',$r),'ACS_AMBUSH_EXECUTION_BLOCKED');
    $link->query('DELETE FROM t_af_acs_prepared_actions WHERE source_sealed_action_id=999');
    $ready=$ambush->command('ready',$r);snapshot_assert($ready['status']==='ambush_ready'&&$ambush->command('ready',$r)===$ready,'Ready/replay');
    snapshot_assert(!$repo->firstRound($eid),'Ready created a free round');
    $r=$request('ambush_start_fixture_001');$before=$capture();
    $link->query("CREATE TRIGGER ambush_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late start rollback'");
    blocked(fn()=>$ambush->command('start',$r),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER ambush_late');
    $armed=$capture();snapshot_assert(!$repo->firstRound($eid)&&$armed['turns']===$before['turns']&&$armed['entity_turns']===$before['entity_turns'],'Partial round after rollback');
    $state=$ambush->view($sid,$eid);snapshot_assert($state['operation_status']==='prepared'&&!$state['outcome']['mechanics_confirmed'],'Intent lost after rollback');
    $rival=$r;$rival['operation_key']='ambush_start_fixture_002';blocked(fn()=>$ambush->command('start',$rival),'ACS_FIRST_ROUND_EXISTS');
    snapshot_assert($capture()===$armed,'Alternate start changed armed intent');
    $changed=$r;$changed['reason']='Different intent';blocked(fn()=>$ambush->command('start',$changed),'ACS_OPERATION_KEY_CONFLICT');
    itemInsert($link,'af_acs_operations',['operation_key'=>'ambush_foreign_obligation','session_id'=>$sid,'encounter_id'=>$eid,'kind'=>'inventory.fixture','payload_hash'=>hash('sha256','pending fixture'),'status'=>'prepared','operation_version'=>1,'request_json'=>'{"schema_version":1}','result_json'=>'{"schema_version":1}','updated_at'=>0]);
    blocked(fn()=>$ambush->command('start',$r),'ACS_ROUND_OBLIGATIONS_BLOCKED');snapshot_assert(!$repo->firstRound($eid),'Pending owner obligation bypassed');
    $link->query("DELETE FROM t_af_acs_operations WHERE operation_key='ambush_foreign_obligation'");snapshot_assert($capture()===$armed,'Foreign obligation rollback changed intent');
    $link->loseAck=true;$result=$ambush->command('start',$r);snapshot_assert(!$link->loseAck&&$result['mechanics_confirmed'],'Lost terminal ACK did not reconcile prior outcome');$after=$capture();snapshot_assert($result['mechanics_confirmed']&&$result['turn_count']==='1'&&$result['entity_turn_count']==='1','Start roster shape');
    snapshot_assert($ambush->command('start',$r)===$result&&$capture()===$after,'Duplicate start repeated mechanics');
    snapshot_assert(count($after['rounds'])===count($before['rounds'])+1&&count($after['control_groups'])===count($before['control_groups']),'Extra round/group');
    $turns=iterator_to_array($repo->allRoundTurns($result['round_id']));foreach($turns as $turn)snapshot_assert($turn['ap_budget']==='3'&&$turn['ap_spent']==='0'&&$turn['pre_round_ap_commitment']==='0'&&$turn['status']==='pending','Free surprise bonus/lost victim turn');
    snapshot_assert($after['rolls']===$before['rolls']&&$after['submissions']===$before['submissions']&&$after['post_links']===$before['post_links'],'Start sampled or made post');
    snapshot_assert(AF_ACS_Clock::availableAP(3,0,2)===1&&AF_ACS_Clock::availableAP(3,0,3)===0&&AF_ACS_Clock::availableAP(3,1,2)===0,'AP commitment arithmetic');
    foreach([[3,0,4],[3,1,3],[3,0,-1]] as $args)blocked(fn()=>AF_ACS_Clock::availableAP(...$args),'ACS_AP_STATE_INVALID');
    echo "ACS ambush start foundation: ready, exact replay, durable interrupted intent, late rollback, alternate ID rejection, one roster, no RNG/post/free surprise and AP arithmetic passed; paid pre-round integration BLOCKED\n";
};
require __DIR__.'/acs_combat_items_sql.php';
