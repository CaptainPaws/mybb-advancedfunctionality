<?php
/** Environment foundation through the existing authoritative stage-7 fixture. */
$GLOBALS['acsObjectDefinition']=static function(array $dto):array {
    $o=&$dto['entries'][0]['object_definition'];$o['schema']='ic-acs.object-template.v2';
    $o['mechanics']=['categories'=>['passive','triggered','interactive','destructible'],'initial_status'=>'active','durability'=>['maximum'=>12,'damage_types'=>['physical']],
        'passive'=>[['key'=>'renew','event'=>'source_turn_start','effect'=>['kind'=>'heal','target'=>'source','amount'=>2]]],
        'triggers'=>[['key'=>'bless','event'=>'source_turn_end','once'=>true,'effect'=>['kind'=>'heal','target'=>'source','amount'=>7]],['key'=>'wear','event'=>'source_turn_end','once'=>true,'effect'=>['kind'=>'damage_object','target'=>'self','amount'=>1,'damage_type'=>'physical']]],
        'gm_operations'=>['create','activate','deactivate','damage']];return $dto;
};
$GLOBALS['acsStage7Fixture']=static function(array $fixture):void {
    extract($fixture);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/EnvironmentService.php';
    $service=new AF_ACS_EnvironmentService($repo,$access);$commandSeq=0;
    $request=static function(string $op,array $extra=[])use($repo,$sid,$actor,&$commandSeq):array {
        return array_replace(['session_id'=>$sid,'encounter_id'=>'1','expected_version'=>$repo->session($sid)['session_version'],'expected_encounter_version'=>$repo->encounter($sid,'1')['encounter_version'],
            'expected_object_version'=>$op==='create'?'0':'1','operation_key'=>'environment_fixture_'.++$commandSeq,'reason'=>'Explicit environment fixture'], $op==='create'?['source_entity_id'=>$actor,'ability_key'=>'one-banner']:[],$extra);
    };
    $read=static fn(string $id)=>$repo->objectProjection($sid,'1')[$id];
    $migrations=new AF_ACS_Migrations($db);$before=$capture();$migrations->run();snapshot_assert($capture()===$before,'Repeat migration changed data');
    // Simulate interruption after CREATE TABLE, before ledger completion.
    $link->query("DELETE FROM t_af_acs_schema_migrations WHERE version='016_environment_objects'");$migrations->run();snapshot_assert($repo->objectProjection($sid,'1')===[],'Migration created gameplay state');
    $req=$request('create');$before=$capture();$mybb->input=['my_post_key'=>'wrong'];itemFail(fn()=>$service->command('create',$req),'ACS_CSRF_REQUIRED');$mybb->input=['my_post_key'=>'token'];snapshot_assert($capture()===$before,'CSRF changed state');
    $mybb->request_method='get';itemFail(fn()=>$service->command('create',$req),'ACS_POST_REQUIRED');$mybb->request_method='post';
    $link->query("UPDATE t_af_acs_session_roles SET role='player' WHERE uid=7");itemFail(fn()=>$service->command('create',$req),'ACS_GM_REQUIRED');$link->query("UPDATE t_af_acs_session_roles SET role='owner' WHERE uid=7");
    $nativeDenied=new AF_ACS_EnvironmentService($repo,new class extends AF_ACS_SessionAccess {public function thread(string $tid,?string $uid=null):array {throw new RuntimeException('ACS_NATIVE_DENIED');}});itemFail(fn()=>$nativeDenied->command('create',$req),'ACS_NATIVE_DENIED');
    $created=$service->command('create',$req);$id=$created['spawns'][0]['entity_id'];$v=$read($id);snapshot_assert($v['object_version']===1&&$v['durability']===12&&$v['entity_id']===$id,'Object initial state/identity');
    $before=$capture();snapshot_assert($service->command('create',$req)===$created&&$capture()===$before,'Duplicate GM creation');
    foreach(['entity_states','resources','controllers','entity_turns','participants'] as $table)snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_acs_'.$table.' WHERE entity_id='.$id)->fetch_row()[0]==0,'Object got ordinary '.$table);
    $bad=$request('damage',['entity_id'=>$id,'amount'=>'2','damage_type'=>'elemental']);itemFail(fn()=>$service->command('damage',$bad),'ACS_OBJECT_DAMAGE_UNSUPPORTED');snapshot_assert($capture()===$before,'Unsupported damage wrote');
    $bad=$request('damage',['entity_id'=>$id,'amount'=>'2','damage_type'=>'physical','expected_object_version'=>'2']);itemFail(fn()=>$service->command('damage',$bad),'ACS_OBJECT_VERSION_CONFLICT');
    $bad=$request('deactivate',['entity_id'=>$id]);$bad['state_json']='{}';itemFail(fn()=>$service->command('deactivate',$bad),'ACS_OBJECT_INPUT_INVALID');
    $deactivate=$service->command('deactivate',$request('deactivate',['entity_id'=>$id]));snapshot_assert($read($id)['status']==='inactive'&&$read($id)['object_version']===2,'Deactivation');
    $service->command('activate',$request('activate',['entity_id'=>$id,'expected_object_version'=>'2']));snapshot_assert($read($id)['status']==='active','Activation');
    // Preview, seal and confirmation never execute a durable boundary.
    $beforeObject=$read($id);$beforeHp=$repo->entity($sid,$actor)['hp'];$scope=$builder->context($sid,'1','1');$builder->preview(['session_id'=>$sid,'encounter_id'=>'1','turn_id'=>'1','scope_token'=>$scope['scope_token'],'actions'=>$actions('one-wolf'),'scenario'=>'hit']);
    snapshot_assert($read($id)===$beforeObject&&$repo->entity($sid,$actor)['hp']===$beforeHp,'Preview changed object/source');
    $sealed=$prepare('one-wolf');snapshot_assert($read($id)===$beforeObject,'Seal changed object');$r=$issue($sealed);
    $all=$capture();$link->query("CREATE TRIGGER object_late_failure BEFORE INSERT ON t_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late object failure'");
    itemFail(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER object_late_failure');snapshot_assert($capture()===$all,'Late commit left object/source/event writes');
    $receipt=$writer->commit($r['submission_id'],$r['state_version']);$v=$read($id);snapshot_assert(isset($v['fired']['bless'],$v['fired']['wear'])&&$v['durability']===11&&$v['object_version']===4,'Trigger did not persist once');snapshot_assert((int)$repo->entity($sid,$actor)['hp']===(int)$beforeHp+7,'Trigger effect did not use Effects');
    $all=$capture();snapshot_assert($writer->commit($r['submission_id'],$r['state_version'])===$receipt&&$capture()===$all,'Duplicate combat fired trigger twice');
    $event=$link->query('SELECT payload_json FROM t_af_acs_event_details WHERE event_id='.$receipt['event_id'])->fetch_row()[0];snapshot_assert(str_contains($event,'object.triggered')&&str_contains($event,'object:'.$id.':bless:entity_turn:'),'Missing causal event binding');
    $next();$v=$read($id);snapshot_assert($v['remaining']===2&&isset($v['passive_boundaries']['renew']),'Clock lifetime/passive');snapshot_assert((int)$repo->entity($sid,$actor)['hp']===(int)$beforeHp+9,'Passive did not use source Clock');
    $all=$capture();$roundCommand('round_next',['round_id'=>'1']);snapshot_assert($capture()===$all,'Duplicate lifetime boundary');
    $pass();$next();$v=$read($id);snapshot_assert($v['remaining']===1&&count($v['fired'])===2,'Trigger replayed on new source event');$pass();$next();snapshot_assert($read($id)['status']==='expired'&&$read($id)['remaining']===0,'Object did not expire');
    snapshot_assert($link->query('SELECT COUNT(*) FROM t_af_acs_events WHERE id='.$created['event_id'])->fetch_row()[0]==1,'Expiration deleted spawn history');
    // A changed object invalidates the existing confirmation, without resampling the tape.
    $reset();$created=$service->command('create',$request('create'));$id=$created['spawns'][0]['entity_id'];$sealed=$prepare('one-wolf');
    $tape=$capture()['rolls'];$service->command('deactivate',$request('deactivate',['entity_id'=>$id]));itemFail(fn()=>$issue($sealed),'ACS_COMBAT_CONFIRMATION_CONFLICT');
    snapshot_assert($capture()['rolls']===$tape&&$read($id)['status']==='inactive'&&!$capture()['combat_receipts'],'Stale confirmation changed tape/object');
    // Destruction once, typed damage admission, audit and operation replay.
    $reset();$created=$service->command('create',$request('create'));$id=$created['spawns'][0]['entity_id'];
    $damage=$request('damage',['entity_id'=>$id,'amount'=>'5','damage_type'=>'physical']);$service->command('damage',$damage);snapshot_assert($read($id)['durability']===7&&$read($id)['object_version']===2,'Durability damage');
    $destroy=$request('damage',['entity_id'=>$id,'amount'=>'20','damage_type'=>'physical','expected_object_version'=>'2']);$receipt=$service->command('damage',$destroy);snapshot_assert($read($id)['durability']===0&&$read($id)['status']==='destroyed'&&$read($id)['object_version']===3,'Destruction');
    $all=$capture();snapshot_assert($service->command('damage',$destroy)===$receipt&&$capture()===$all,'Destruction replay changed history');itemFail(fn()=>$service->command('damage',$request('damage',['entity_id'=>$id,'amount'=>'1','damage_type'=>'physical','expected_object_version'=>'3'])),'ACS_OBJECT_TERMINAL');snapshot_assert($capture()===$all,'Terminal damage wrote');
    $reset();$req=$request('create');$all=$capture();$link->query("CREATE TRIGGER object_audit_failure BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='late object audit failure'");itemFail(fn()=>$service->command('create',$req),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER object_audit_failure');snapshot_assert($capture()===$all,'GM late audit rollback');$service->command('create',$req);
    $bad=$object['entries'][0]['object_definition'];$bad['mechanics']['triggers'][0]['effect']['kind']='php_callback';itemFail(fn()=>AF_ACS_Environment::definition($bad),'ACS_OBJECT_EFFECT_UNSUPPORTED');
    $bad=$object['entries'][0]['object_definition'];$bad['mechanics']['triggers'][0]['event']='enter_zone';itemFail(fn()=>AF_ACS_Environment::definition($bad),'ACS_OBJECT_UNSUPPORTED');
    echo "ACS environment: repeat/resumed migration, identity/no AP, GM CSRF/CAS/idempotency, activation, fixed trigger once, passive, durability/destruction, preview/seal isolation, lifetime duplicate boundary, history, unsupported effects/spatial and late rollback passed\n";
};
require __DIR__.'/acs_summon_lifecycle_sql.php';
