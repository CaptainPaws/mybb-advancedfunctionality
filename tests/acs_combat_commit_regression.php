<?php
declare(strict_types=1);
// Disposable local ACS SQL fixture only. Gateway is never loaded/called; a known native PID is seeded.
$GLOBALS['acsPublicationFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCommitWriter.php';
    snapshot_assert(!class_exists('AF_ACS_MybbPostGateway',false),'Writer loaded Gateway');
    // Upgrade from preserved 001–008, then resume an interrupted 009 without changing old checksums/data.
    $legacy=$link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<>'009_combat_commit' ORDER BY version")->fetch_all(MYSQLI_ASSOC);
    foreach(['post_links','combat_results','combat_receipts','outbox'] as $t)$link->query('DROP TABLE fixture_af_acs_'.$t);
    $link->query("DELETE FROM fixture_af_acs_schema_migrations WHERE version='009_combat_commit'");$migrations->run();
    $link->query('DROP TABLE fixture_af_acs_outbox');$link->query("UPDATE fixture_af_acs_schema_migrations SET status='failed',step=3 WHERE version='009_combat_commit'");
    $migrations->run();$migrations->run();snapshot_assert($migrations->status()['schema_version']==='009_combat_commit','009 upgrade/resume failed');
    snapshot_assert($link->query("SELECT * FROM fixture_af_acs_schema_migrations WHERE version<>'009_combat_commit' ORDER BY version")->fetch_all(MYSQLI_ASSOC)===$legacy,'Prior migration records changed');
    $store=new AF_ACS_SubmissionStore($link);$writer=new AF_ACS_CombatCommitWriter($link,$access);
    $d=$drafts->restore($saved['draft_id']);$active=[];
    foreach($repo->sealRows($d['draft_id'],true) as $a) $active[]=$repo->sealPayload($a)['action'];
    $drafts->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],
        'operation_key'=>'combat_prepare_fixture','prose'=>'Writer narrative','actions'=>$active]);
    $v=$seal->status($d['draft_id']);$confirmed=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$v['draft_version'],
        'operation_key'=>'combat_confirm_fixture','confirmation_hash'=>$v['confirmation_hash']]);
    $baseDraft=$repo->draft($d['draft_id'],'7');$ownerBefore=$db->tables;
    // Existing preparation assignments explicitly forbid combat. Fixture opts in the acting controller only.
    foreach($active as $a) {
        $p=AF_ACS_Repository::json(['schema_version'=>1,'scope'=>'fixture_combat','combat_operations_enabled'=>true]);
        $st=$link->prepare('UPDATE fixture_af_acs_controllers SET permissions_json=? WHERE entity_id=? AND status=\'active\'');$st->bind_param('ss',$p,$a['actor_id']);$st->execute();
    }
    $link->query('CREATE TABLE fixture_posts (pid int unsigned PRIMARY KEY,uid int unsigned,tid int unsigned,fid int unsigned,visible int,message text) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_threads (tid int unsigned PRIMARY KEY,fid int unsigned,visible int,closed varchar(30)) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_forums (fid int unsigned PRIMARY KEY,open int,type varchar(10)) ENGINE=MyISAM');
    $link->query("INSERT INTO fixture_threads VALUES(51,1,1,'0')");$link->query("INSERT INTO fixture_forums VALUES(1,1,'f')");
    $link->query("INSERT INTO fixture_posts VALUES(501,7,51,1,1,'unchanged native prose')");
    $tables=[];
    foreach(glob(AF_ADDONS.'advancedcombatsystem/migrations/[0-9][0-9][0-9]_*.php') as $file) foreach(array_keys((require $file)['tables']) as $t) if($t!=='schema_migrations')$tables[]=$t;
    $capture=static function()use($link,$tables):array {
        $out=[];foreach($tables as $t)$out[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;
    };
    $baseline=$capture();$next=0;
    $reset=static function()use($link,$baseline):void {
        foreach($baseline as $t=>$rows) {
            $link->query('DELETE FROM fixture_af_acs_'.$t);
            foreach($rows as $r) {
                $columns='`'.implode('`,`',array_keys($r)).'`';$values=array_values($r);
                $st=$link->prepare('INSERT INTO fixture_af_acs_'.$t.' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
                $st->bind_param(str_repeat('s',count($values)),...$values);$st->execute();$st->close();
            }
        }
        $link->query("UPDATE fixture_posts SET uid=7,tid=51,fid=1,visible=1,message='unchanged native prose' WHERE pid=501");
    };
    $intent=static function(bool $moderation=false)use(&$next,$reset,$link,$store,$baseDraft,$confirmed):array {
        $reset();$id=str_pad(dechex(++$next),32,'0',STR_PAD_LEFT);
        $r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$baseDraft['id'],'author_uid'=>'7',
            'expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        $r=$store->validating($id,$r['state_version'],static function(){});
        $r=$store->posting($id,$r['state_version'],['dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],
            'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        if($moderation)$link->query('UPDATE fixture_posts SET visible=0 WHERE pid=501');
        return $store->confirmPost($id,$r['state_version'],'501',static function(){},'1');
    };
    $unchanged=static function(array $before,string $label)use($capture):void { snapshot_assert($capture()===$before,'Partial combat mutation: '.$label); };
    // Failure late enough to include HP/AP, clocks, event/result/link/COMMITTED/receipt writes.
    $r=$intent();$mybb->input['af_aa_mask']='fixture-cosmetic-only';$before=$capture();
    $link->query("CREATE TRIGGER fixture_fail_outbox BEFORE INSERT ON fixture_af_acs_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_DATABASE_ERROR');
    $link->query('DROP TRIGGER fixture_fail_outbox');$unchanged($before,'outbox failure rollback');
    snapshot_assert($writer->receipt($r['submission_id'])===null,'Rolled back receipt survived');
    $receipt=$writer->commit($r['submission_id'],$r['state_version']);
    snapshot_assert($receipt['status']==='COMMITTED'&&$receipt['pid']==='501','Commit receipt missing');
    $expected=[];foreach($confirmed['summary']['virtual_entities'] as $e)$expected[$e['entity_id']]=$e;
    foreach($expected as $id=>$e) {
        $state=$repo->entity($sid,(string)$id);snapshot_assert((int)$state['hp']===$e['hp']&&$state['life_state']===$e['life_state'],'Resolver projection differs');
        foreach($repo->entityResources((string)$id) as $resource) snapshot_assert((int)$resource['current_value']===$e['hp'],'HP resource diverged');
    }
    snapshot_assert($repo->clockTurn($sid,$turnId)['status']==='resolved'&&(int)$repo->clockTurn($sid,$turnId)['ap_spent']===1,'AP or turn not committed');
    snapshot_assert($repo->draft($baseDraft['id'],'7')['status']==='published','Draft was not finalized');
    $repo->assertNoObligations($sid,$eid,$turnId);
    $link->query('UPDATE fixture_posts SET visible=-1 WHERE pid=501');
    snapshot_assert($writer->receipt($r['submission_id'])===$receipt,'Hidden committed post rewrote combat history');
    $link->query('UPDATE fixture_posts SET visible=1 WHERE pid=501');
    $after=$capture();snapshot_assert($writer->commit($r['submission_id'],$r['state_version'])===$receipt,'Stale response replay differed');$unchanged($after,'replay');
    $database=$link->query('SELECT DATABASE()')->fetch_row()[0];$socket=$GLOBALS['argv'][1];
    $other=new mysqli('localhost','root','',$database,0,$socket);$otherWriter=new AF_ACS_CombatCommitWriter($other,$access);
    snapshot_assert($otherWriter->receipt($r['submission_id'])===$receipt,'Independent receipt lookup after response loss failed');
    snapshot_assert($otherWriter->commit($r['submission_id'],$r['state_version'])===$receipt,'Independent retry reapplied combat');$unchanged($after,'lost response');
    snapshot_assert(count($after['post_links'])===1&&count($after['combat_results'])===1&&count($after['combat_receipts'])===1&&count($after['outbox'])===1,'Missing/duplicate terminal storage');
    snapshot_assert($after['outbox'][0]['status']==='pending','Outbox executed an external consumer');
    // SQL-level alternative submissions cannot claim the same turn/post.
    try {
        $link->query("INSERT INTO fixture_af_acs_post_links (submission_id,session_id,encounter_id,turn_id,pid,tid,fid,author_uid,created_at) SELECT REPEAT('f',32),session_id,encounter_id,turn_id,502,tid,fid,author_uid,created_at FROM fixture_af_acs_post_links");
        throw new LogicException('Duplicate result turn accepted');
    } catch(mysqli_sql_exception $e) {snapshot_assert($e->getCode()===1062,'Expected UNIQUE turn guard');}
    // No stale plan after changed dependencies; no new tape or confirmation is generated.
    $r=$intent();$link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1 WHERE entity_id='.$first['entity_id']);$before=$capture();
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_CONFIRMATION_CONFLICT');$unchanged($before,'seal version conflict');
    $r=$intent();$before=$capture();blocked(fn()=>$writer->commit($r['submission_id'],'1'),'ACS_COMBAT_POST_NOT_CONFIRMED');$unchanged($before,'stale journal CAS');
    $r=$intent(true);$before=$capture();blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_POST_NOT_CONFIRMED');$unchanged($before,'moderation');
    foreach(['uid=8','tid=52','fid=2','visible=0'] as $bad) {
        $r=$intent();$link->query('UPDATE fixture_posts SET '.$bad.' WHERE pid=501');$before=$capture();
        blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_POST_INVALID');$unchanged($before,'invalid post '.$bad);
    }
    $r=$intent();$link->query("UPDATE fixture_af_acs_controllers SET permissions_json='{\"schema_version\":1,\"combat_operations_enabled\":false}' WHERE entity_id=".$active[0]['actor_id']);$before=$capture();
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_CONTROL_DENIED');$unchanged($before,'preparation-only controller');
    $r=$intent();$tape=$link->query('SELECT * FROM fixture_af_acs_rolls ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $link->query("UPDATE fixture_af_acs_rolls SET value_json='{\"schema_version\":1,\"sample_n\":1,\"u\":0.25}' WHERE id=".$tape[0]['id']);$before=$capture();
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_SEAL_TAPE_INVALID');$unchanged($before,'tape corruption');
    $r=$intent();$before=$capture();$mybb->input['my_post_key']='invalid';
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';$unchanged($before,'CSRF');
    $mybb->user['uid']=8;blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_PERMISSION_DENIED');$mybb->user['uid']=7;$unchanged($before,'foreign actor');
    $r=$intent();$before=$capture();$link->begin_transaction();$link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1 WHERE entity_id='.$active[0]['actor_id']);
    blocked(fn()=>$writer->commit($r['submission_id'],$r['state_version']),'ACS_EXTERNAL_BOUNDARY_HELD');$link->rollback();$unchanged($before,'ambient transaction');
    // Change state in the preparation/transaction gap and exercise two actual writer connections.
    $race=new class('localhost','root','',$database,0,$socket) extends mysqli {
        public ?Closure $beforeBegin=null;
        public function begin_transaction(int $flags=0,?string $name=null):bool {
            if($cb=$this->beforeBegin){$this->beforeBegin=null;$cb();}return parent::begin_transaction($flags,$name);
        }
    };
    $r=$intent();$raceWriter=new AF_ACS_CombatCommitWriter($race,$access);
    $race->beforeBegin=static function()use($other,$first):void {$other->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1 WHERE entity_id='.$first['entity_id']);};
    blocked(fn()=>$raceWriter->commit($r['submission_id'],$r['state_version']),'ACS_COMBAT_DEPENDENCY_CONFLICT');
    snapshot_assert($store->submission($r['submission_id'])['status']==='post_confirmed'&&$link->query('SELECT COUNT(*) FROM fixture_af_acs_combat_receipts')->fetch_row()[0]==='0','Gap conflict partially committed');
    $r=$intent();$contended=false;
    $race->beforeBegin=static function()use($otherWriter,$r,&$contended):void {
        try {$otherWriter->commit($r['submission_id'],$r['state_version']);throw new LogicException('Concurrent writer acquired ACS lock');}
        catch(RuntimeException $e){snapshot_assert($e->getMessage()==='ACS_MAINTENANCE_BUSY','Unexpected contender failure '.$e->getMessage());$contended=true;}
    };
    $winner=$raceWriter->commit($r['submission_id'],$r['state_version']);
    snapshot_assert($contended&&$otherWriter->commit($r['submission_id'],$r['state_version'])===$winner,'Concurrent retry did not find winner receipt');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_af_acs_outbox')->fetch_row()[0]==='1','Concurrent apply duplicated outbox');
    $immutable=$capture()['sealed_actions'];foreach($immutable as &$a)unset($a['status']);unset($a);$original=$baseline['sealed_actions'];foreach($original as &$a)unset($a['status']);unset($a);
    snapshot_assert($immutable===$original,'Sealed payload changed');
    foreach(['rolls','snapshots'] as $t) snapshot_assert($capture()[$t]===$baseline[$t],'Immutable history changed '.$t);
    snapshot_assert($draws===6&&$db->tables===$ownerBefore,'Writer touched RNG or external owners');
    snapshot_assert($link->query('SELECT message FROM fixture_posts WHERE pid=501')->fetch_row()[0]==='unchanged native prose','Writer changed MyBB prose');
    snapshot_assert($link->query("SHOW TABLE STATUS LIKE 'fixture_posts'")->fetch_assoc()['Engine']==='MyISAM','MyBB engine changed');
    snapshot_assert(!class_exists('AF_ACS_MybbPostGateway',false),'Commit invoked Gateway');
    $reset();
    echo "ACS combat commit: Resolver projections, atomic terminal/outbox rollback, CAS/dependencies, authorization, same-turn contention/replay, receipt lookup and immutable tape passed\n";
};
require __DIR__.'/acs_seal_regression.php';
