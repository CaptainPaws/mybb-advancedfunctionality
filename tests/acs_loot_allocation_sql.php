<?php
/** Existing real combat/generation harness. Extra registered groups are explicit SQL fixture seeds. */
$GLOBALS['acsLootExpectedGroups']=3;
$GLOBALS['acsLootParticipantsFixture']=static function(array $f):void {
    extract($f);
    $actorRow=$link->query('SELECT * FROM t_af_acs_entities WHERE id='.$actor)->fetch_assoc();$groupRow=$link->query('SELECT * FROM t_af_acs_control_groups WHERE id='.$actorRow['control_group_id'])->fetch_assoc();
    $state=$link->query('SELECT * FROM t_af_acs_entity_states WHERE entity_id='.$actor)->fetch_assoc();$snapshot=$link->query('SELECT * FROM t_af_acs_snapshots WHERE id='.$actorRow['template_snapshot_id'])->fetch_assoc();
    $groups=['7'=>(string)$actorRow['control_group_id']];
    foreach([8,9] as $uid){
        itemInsert($link,'users',['uid'=>$uid]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>$uid,'role'=>'player','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
        $g=$groupRow;unset($g['id']);$g['owner_uid']=$uid;itemInsert($link,'af_acs_control_groups',$g);$gid=(string)$link->insert_id;$groups[(string)$uid]=$gid;
        $p=json_decode($snapshot['payload_json'],true,64,JSON_THROW_ON_ERROR);$p['source_snapshot']['identity']['owner_uid']=$uid;$p['source_snapshot']['identity']['sheet_id']=100+$uid;
        $snap=$snapshot;unset($snap['id']);$snap['payload_json']=AF_ACS_Repository::json($p);$snap['content_hash']=hash('sha256',$snap['payload_json']);itemInsert($link,'af_acs_snapshots',$snap);$snapId=(string)$link->insert_id;
        $e=$actorRow;unset($e['id']);$e['owner_uid']=$uid;$e['sheet_id']=100+$uid;$e['control_group_id']=$gid;$e['template_snapshot_id']=$snapId;$e['name']='Registered allocation group '.$uid;itemInsert($link,'af_acs_entities',$e);$entity=(string)$link->insert_id;
        $s=$state;$s['entity_id']=$entity;itemInsert($link,'af_acs_entity_states',$s);
        $controller=$link->query('SELECT * FROM t_af_acs_controllers WHERE entity_id='.$actor.' LIMIT 1')->fetch_assoc();unset($controller['id']);$controller['entity_id']=$entity;$controller['controller_uid']=$uid;itemInsert($link,'af_acs_controllers',$controller);
        // Explicit KB eligibility includes withdrawal. No action or current roster turn is required.
        itemInsert($link,'af_acs_participants',['encounter_id'=>1,'entity_id'=>$entity,'side_id'=>1,'participation'=>'withdrawn','registered_sequence'=>1,'participant_version'=>1,'visibility_json'=>'{"schema_version":1,"scope":"gm"}']);
    }$GLOBALS['acsAllocationGroups']=$groups;
};
$GLOBALS['acsLootDefinitionFixture']=static function(array $d,string $mode):array {
    if($mode!=='guaranteed')$d['allocation']=['schema'=>'ic-acs.loot-allocation.v1','mode'=>$mode==='independent_roll'?'free_claim':'need_greed','allow_pass'=>$mode==='weighted_pool','visibility'=>'eligible','winner_visibility'=>$mode==='weighted_pool'?'eligible':'recipient'];return $d;
};
$GLOBALS['acsLootAllocationFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/LootAllocationPage.php';
    $groups=$GLOBALS['acsAllocationGroups'];$draws=0;$allocation=new AF_ACS_LootAllocationService($repo,$access,new AF_ACS_RandomSource(static function()use(&$draws){++$draws;return 0;}));
    $entryIds=[];foreach($receipts as $mode=>$receipt)$entryIds[$mode]=array_column($receipt['pool']['generation']['entries'],'entry_id');
    // Separate fixture phases by aging throttle metadata; no sleeps or production gate changes.
    $link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    $base=$captureAll();$r=static function(string $key,string $entry,array $extra=[])use($repo,$sid):array {return ['session_id'=>$sid,'encounter_id'=>'1','entry_id'=>$entry,'expected_version'=>$repo->session($sid)['session_version'],'expected_allocation_version'=>'0','operation_key'=>$key,'reason'=>'Disposable allocation evidence']+$extra;};
    $read=static fn()=> $allocation->view($sid,'1');
    $gm=$read();snapshot_assert(count($gm['entries'])===5,'GM missing generated entries');
    $mybb->user['uid']=8;$player=$read();$visible=array_column($player['entries'],'entry_id');snapshot_assert(count($visible)===3&&!array_intersect($entryIds['guaranteed'],$visible),'Hidden loot leaked');
    $json=json_encode($player);foreach(['definition_snapshot_id','item_snapshot_id','fingerprint','tape','eligible_groups','contenders','recipient_uid','receipt'] as $secret)snapshot_assert(!str_contains($json,'"'.$secret.'"'),'DTO leaked '.$secret);
    $html=AF_ACS_LootAllocationPage::renderView($player,'token','misc.php?action=acs_loot');snapshot_assert(str_contains($html,'name="claim"')&&str_contains($html,'expected_claim_version')&&!str_contains($html,'fingerprint'),'Minimal player view');
    itemFail(fn()=>$allocation->command('claim',$r('allocation_unknown_entry01','999999',['control_group_id'=>$groups['8'],'expected_claim_version'=>'0','claim'=>'need'])),'ACS_LOOT_NOT_ELIGIBLE');
    $mybb->user['uid']=7;$assign=$r('allocation_gm_assign01',$entryIds['guaranteed'][0],['control_group_id'=>$groups['8']]);
    $mybb->request_method='get';itemFail(fn()=>$allocation->command('gm_assign',$assign),'ACS_POST_REQUIRED');$mybb->request_method='post';$mybb->input['my_post_key']='bad';itemFail(fn()=>$allocation->command('gm_assign',$assign),'ACS_CSRF_REQUIRED');$mybb->input['my_post_key']='token';
    $wrong=$assign;$wrong['control_group_id']='9999';itemFail(fn()=>$allocation->command('gm_assign',$wrong),'ACS_LOOT_NOT_ELIGIBLE');
    $before=$captureAll();$link->query("CREATE TRIGGER allocation_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='allocation rollback'");itemFail(fn()=>$allocation->command('gm_assign',$assign),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER allocation_late');snapshot_assert($captureAll()===$before,'Assign rollback leaked');
    $assigned=$allocation->command('gm_assign',$assign);snapshot_assert($assigned['allocation']['recipient_uid']==='8'&&$assigned['allocation']['status']==='awaiting_issue','GM assign did not reserve');$snap=$captureAll();snapshot_assert($allocation->command('gm_assign',$assign)===$assigned&&$captureAll()===$snap,'Assign duplicate');
    $again=$r('allocation_assign_again01',$assign['entry_id'],['control_group_id'=>$groups['8']]);$again['expected_version']='1';snapshot_assert($allocation->command('gm_assign',$again)===$assigned,'Assign source replay');
    $again['control_group_id']=$groups['7'];itemFail(fn()=>$allocation->command('gm_assign',$again),'ACS_LOOT_ALLOCATION_CLOSED');
    $stale=$r('allocation_wrong_version01',$entryIds['guaranteed'][1],['control_group_id'=>$groups['7']]);$stale['expected_allocation_version']='1';itemFail(fn()=>$allocation->command('gm_assign',$stale),'ACS_VERSION_CONFLICT');
    // Competing free claims: actual second SQL writer while first holds ACS session lock.
    $free=$r('allocation_free_claim01',$entryIds['independent_roll'][0],['control_group_id'=>$groups['7'],'expected_claim_version'=>'0']);
    $rival=new mysqli('localhost','root','',$database,0,$socket);$rival->set_charset('utf8mb4');$other=new AF_ACS_LootAllocationService(new AF_ACS_EncounterRepository($rival),$access);$contended=false;
    $contending=new class($access,$other,$free,$groups['8'],$contended) extends AF_ACS_SessionAccess {
        private int $calls=0;public function __construct(private $native,private $rival,private array $input,private string $group,public bool &$contended){}
        public function thread(string $tid,?string $uid=null):array {global $mybb;if(++$this->calls===2){$old=$mybb->user['uid'];$mybb->user['uid']=8;try{$r=$this->input;$r['control_group_id']=$this->group;$r['operation_key']='allocation_free_rival01';itemFail(fn()=>$this->rival->command('free_claim',$r),'ACS_MAINTENANCE_BUSY');$this->contended=true;}finally{$mybb->user['uid']=$old;}}return $this->native->thread($tid,$uid);}
    };
    $won=(new AF_ACS_LootAllocationService($repo,$contending))->command('free_claim',$free);snapshot_assert($contended&&$won['allocation']['recipient_uid']==='7','Free race winner');$rival->close();
    $mybb->user['uid']=8;$lost=$r('allocation_free_loser01',$free['entry_id'],['control_group_id'=>$groups['8'],'expected_claim_version'=>'0']);itemFail(fn()=>$allocation->command('free_claim',$lost),'ACS_LOOT_ALLOCATION_CLOSED');
    $freeDto=array_values(array_filter($read()['entries'],fn($e)=>$e['entry_id']===$free['entry_id']))[0];snapshot_assert(!isset($freeDto['winner']),'Recipient-only winner leaked to loser');
    $claim=static function(string $key,string $entry,string $uid,string $choice,string $v='0')use($allocation,$r,$groups,$mybb){$mybb->user['uid']=(int)$uid;return $allocation->command('claim',$r($key,$entry,['control_group_id'=>$groups[$uid],'expected_claim_version'=>$v,'claim'=>$choice]));};
    $ng1=$entryIds['weighted_pool'][0];$ng2=$entryIds['weighted_pool'][1];
    $claim('allocation_greed_eight01',$ng1,'8','greed');$claim('allocation_pass_nine01',$ng1,'9','pass');$claim('allocation_first_greed01',$ng1,'7','greed');$edited=$claim('allocation_edit_need01',$ng1,'7','need','1');snapshot_assert($edited['allocation']['claim']['claim_version']==='2','Claim edit lost CAS');
    $mybb->user['uid']=8;itemFail(fn()=>$allocation->command('close',$r('allocation_player_close01',$ng1)),'ACS_GM_REQUIRED');$mybb->user['uid']=7;
    $beforeClose=$r('allocation_close_need01',$ng1);$closed=$allocation->command('close',$beforeClose);snapshot_assert($closed['allocation']['recipient_uid']==='7'&&$closed['allocation']['roll']===null&&$draws===0,'Need did not beat greed');
    $mybb->user['uid']=8;itemFail(fn()=>$allocation->command('claim',$r('allocation_late_edit01',$ng1,['control_group_id'=>$groups['8'],'expected_claim_version'=>'1','claim'=>'need'])),'ACS_LOOT_ALLOCATION_CLOSED');
    $claim('allocation_tie_need_seven01',$ng2,'7','need');$claim('allocation_tie_need_eight01',$ng2,'8','need');$claim('allocation_tie_greed_nine01',$ng2,'9','greed');$mybb->user['uid']=7;
    $tie=$r('allocation_tie_close01',$ng2);$before=$captureAll();$link->query("CREATE TRIGGER allocation_tie_late BEFORE INSERT ON t_af_acs_event_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='tie rollback'");itemFail(fn()=>$allocation->command('close',$tie),'ACS_DATABASE_ERROR');$link->query('DROP TRIGGER allocation_tie_late');snapshot_assert($captureAll()===$before&&$draws===1,'Tie rollback lost atomicity');
    $link->loseAck=true;itemFail(fn()=>$allocation->command('close',$tie),'ACS_TRANSACTION_ERROR');$sealed=$allocation->command('close',$tie);snapshot_assert($sealed['allocation']['recipient_uid']==='7'&&$draws===2,'Tie ACK rerolled');
    $roll=$sealed['allocation']['roll'];$rollRow=$link->query('SELECT causal_key FROM t_af_acs_rolls WHERE id='.$roll['roll_id'])->fetch_row()[0];snapshot_assert($rollRow==='loot-allocation:'.$ng2.':1','Wrong closed-version roll key');
    $snap=$captureAll();snapshot_assert($allocation->command('close',$r('allocation_tie_new_key01',$ng2))===$sealed&&$captureAll()===$snap&&$draws===2,'New close key rerolled');
    $mybb->user['uid']=8;$player=$read();$ngDto=array_values(array_filter($player['entries'],fn($e)=>$e['entry_id']===$ng2))[0];snapshot_assert(isset($ngDto['winner'])&&count($ngDto['own_claims'])===1&&!isset($ngDto['claims'])&&!isset($ngDto['receipt']),'Player NG redaction');
    $link->query('UPDATE t_af_acs_session_roles SET expires_at='.(time()-1).' WHERE session_id='.$sid.' AND uid=8');itemFail(fn()=>$read(),'ACS_SESSION_UNAVAILABLE');$link->query('UPDATE t_af_acs_session_roles SET expires_at=NULL WHERE session_id='.$sid.' AND uid=8');
    $denied=new class extends AF_ACS_SessionAccess {public function thread(string $tid,?string $uid=null):array{throw new RuntimeException('ACS_NATIVE_DENIED');}};itemFail(fn()=>(new AF_ACS_LootAllocationService($repo,$denied))->view($sid,'1'),'ACS_NATIVE_DENIED');
    $mybb->user['uid']=7;
    // Seed future issuer failure metadata only: the allocation receipt/winner must remain authoritative.
    $link->query("UPDATE t_af_acs_loot_entries SET status='issue_recovery_required' WHERE id=".$ng2);$failed=array_values(array_filter($read()['entries'],fn($e)=>$e['entry_id']===$ng2))[0];snapshot_assert($failed['winner']['uid']==='7'&&!$failed['can_claim']&&$allocation->command('close',$tie)===$sealed&&$draws===2,'Issue failure released/rerolled winner');$link->query("UPDATE t_af_acs_loot_entries SET status='awaiting_issue' WHERE id=".$ng2);
    // New member/group after completion cannot enter prior eligibility, even with native access.
    itemInsert($link,'users',['uid'=>10]);itemInsert($link,'af_acs_session_roles',['session_id'=>$sid,'uid'=>10,'role'=>'player','permissions_json'=>'{"schema_version":1}','role_version'=>1,'expires_at'=>null]);
    itemInsert($link,'af_acs_control_groups',['session_id'=>$sid,'owner_uid'=>10,'kind'=>'player','group_version'=>1,'config_json'=>'{"schema_version":1}']);$lateGroup=(string)$link->insert_id;
    $mybb->user['uid']=10;snapshot_assert($read()['entries']===[],'Late member saw old loot');itemFail(fn()=>$allocation->command('claim',$r('allocation_late_member01',$ng2,['control_group_id'=>$lateGroup,'expected_claim_version'=>'1','claim'=>'need'])),'ACS_LOOT_NOT_ELIGIBLE');
    $mybb->user['uid']=8;itemFail(fn()=>$allocation->command('claim',$r('allocation_pet_alias01',$ng2,['control_group_id'=>$groups['7'],'expected_claim_version'=>'1','claim'=>'need'])),'ACS_LOOT_NOT_ELIGIBLE');
    $mybb->user['uid']=7;$sr=static fn($key)=>['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>$key,'reason'=>'Retained allocation obligation'];
    $sessions->command('request_close',$sr('allocation_pending_close01'));$sessions->command('close',$sr('allocation_session_close01'));$sessions->command('archive',$sr('allocation_session_archive01'));
    $archivedAssign=$allocation->command('gm_assign',$r('allocation_archived_assign01',$entryIds['guaranteed'][1],['control_group_id'=>$groups['7']]));snapshot_assert($archivedAssign['allocation']['status']==='awaiting_issue'&&$repo->session($sid)['status']==='archived','Archive recovery resumed combat');
    $snap=$captureAll();snapshot_assert($allocation->command('close',$tie)===$sealed&&$captureAll()===$snap&&$draws===2,'Archive repeat lost winner');
    foreach(['loot_pools','loot_entries'] as $table){$old=array_column($base[$table],null,'id');foreach($snap[$table] as $row){snapshot_assert($old[$row['id']]['result_json']===$row['result_json'],'Allocation rewrote generation '.$table);if($table==='loot_pools')snapshot_assert($old[$row['id']]['result_hash']===$row['result_hash'],'Generation hash changed');}}
    foreach(['inv_items','inv_owner_state','inv_reservations','inv_external_operations','snapshots','sealed_actions','combat_results','combat_receipts','entity_states','resources','rounds','turns','entity_turns','cooldowns'] as $table)snapshot_assert($snap[$table]===$base[$table],'Allocation changed '.$table);
    $oldRolls=array_column($snap['rolls'],null,'id');foreach($base['rolls'] as $row)snapshot_assert(($oldRolls[$row['id']]??null)===$row,'Allocation rewrote prior roll');
    $pending=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert(count(array_filter($pending['pending'],fn($o)=>$o['source']==='loot_pool'))===3,'Awaiting issue disappeared');
    snapshot_assert((int)$link->query('SELECT COUNT(*) FROM t_af_acs_loot_claims WHERE loot_entry_id='.$ng1.' AND control_group_id='.$groups['7'])->fetch_row()[0]===1,'Group/pet claim multiplied');
    $migrations->run();snapshot_assert($captureAll()===$snap,'Activation/migration lost reservation');
    echo "ACS loot allocation SQL: GM assign, free claim race, need/greed priority+edit+pass, single sealed tie, ACK/replay, wrong/late groups, redacted DTO/HTML, rollback, awaiting-issue preservation and archive scope passed\n";
};
require __DIR__.'/acs_loot_sql.php';
