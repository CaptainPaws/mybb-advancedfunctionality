<?php
/** ACS-10.4.3: extend existing authoritative Stage 10 setup, never native MyBB publish.
 * Actual completion -> allocation/owner issue -> rewards/achievements -> correction/stats
 * -> archived issue recovery. Second statistics encounter remains explicitly seeded. */
function af_clear_addon_lifecycle_diagnostic($addon):void {}
function is_super_admin($uid):bool {return (int)$uid===7;}
$GLOBALS['acsLootDefinitionFixture']=static function(array $d,string $mode):array {
    if($mode==='weighted_pool')$d['allocation']=['schema'=>'ic-acs.loot-allocation.v1','mode'=>'need_greed','allow_pass'=>true,'visibility'=>'eligible','winner_visibility'=>'eligible'];return $d;
};
$GLOBALS['acsStage10LootFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/LootAllocationService.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/LootIssueService.php';
    $candidate=json_decode($link->query("SELECT candidate_json FROM t_af_acs_encounter_resolutions WHERE encounter_id=1 AND status='confirmed'")->fetch_row()[0],true);
    $facts=$candidate['loot_eligibility'];$rule=$GLOBALS['rewardDefinitions']['reward-each-exp'];$character=null;
    foreach($facts as $fact)if($fact['kind']==='character'&&(string)$fact['group_owner_uid']==='7'){$character=$fact;break;}
    snapshot_assert($character!==null,'Missing frozen registration fact');
    foreach(['engaged','escaped','surrendered','captured','withdrawn'] as $participation){$fact=$character;$fact['participation']=$participation;
        $eligible=AF_ACS_RewardDistribution::eligible($rule,[$fact],$repo->confirmedOutcome($sid,'1')['outcome']);
        snapshot_assert((count($eligible)===1)===in_array($participation,$rule['eligibility']['participation'],true),'Reward ignored pinned KB participation '.$participation);
    }
    $members=[$character];foreach(['pet','summon'] as $kind){$member=$character;$member['kind']=$kind;$member['entity_id']='fixture-'.$kind;$member['source_entity_id']=$character['entity_id'];$members[]=$member;}
    $npc=$character;$npc['kind']='npc';$npc['entity_id']='fixture-independent';$npc['control_group_id']='99999';$npc['group_owner_uid']=null;$members[]=$npc;
    snapshot_assert(count(AF_ACS_RewardDistribution::eligible($rule,$members,$repo->confirmedOutcome($sid,'1')['outcome']))===1,'Pet/summon duplicated base reward or unowned NPC minted to GM');
    $allocation=new AF_ACS_LootAllocationService($repo,$access,new AF_ACS_RandomSource(static fn()=>0));
    $entries=[];foreach($repo->allocationEntries($sid,'1') as $entry)$entries[(string)$entry['id']]=$entry;
    $groups=$GLOBALS['rewardGroups'];$g7=substr($groups[0],6);$g8=substr($groups[1],6);$weighted=[];$guaranteed=[];
    foreach($entries as $id=>$entry){$pool=$link->query('SELECT mode FROM t_af_acs_loot_pools WHERE id='.$entry['pool_id'])->fetch_row()[0];if($pool==='weighted_pool')$weighted[]=(string)$id;else $guaranteed[]=(string)$id;}
    snapshot_assert(count($weighted)>=1&&count($guaranteed)>=2,'Expected generated immutable loot sources');
    $request=static fn($key,$id,$version='0')=>['session_id'=>$sid,'encounter_id'=>'1','entry_id'=>$id,'expected_version'=>$repo->session($sid)['session_version'],'expected_allocation_version'=>$version,'operation_key'=>$key,'reason'=>'Stage 10 integration owner boundary'];
    $mybb->user['uid']=7;
    $r=$request('stage10_assign_lostack01',$guaranteed[0])+['control_group_id'=>$g7];$assigned=$allocation->command('gm_assign',$r);$again=$captureAll();snapshot_assert($allocation->command('gm_assign',$r)===$assigned&&$captureAll()===$again,'Allocation replay changed winner');
    $allocation->command('gm_assign',$request('stage10_assign_outage01',$guaranteed[1])+['control_group_id'=>$g8]);
    $tie=$weighted[0];foreach([7=>$g7,8=>$g8] as $uid=>$group){$mybb->user['uid']=$uid;$allocation->command('claim',$request('stage10_need_claim_'.$uid.'01',$tie)+['control_group_id'=>$group,'expected_claim_version'=>'0','claim'=>'need']);}
    $mybb->user['uid']=7;$r=$request('stage10_need_close_once01',$tie);$closed=$allocation->command('close',$r);$snap=$captureAll();snapshot_assert($allocation->command('close',$r)===$closed&&$captureAll()===$snap,'Need/greed close rerolled');
    $link->query('ALTER TABLE t_af_kb_types ADD mechanic_key VARCHAR(32),ADD active INT DEFAULT 1');$link->query("INSERT INTO t_af_kb_types VALUES(1,'arpg_item','arpg_item','arpg',1)");
    $ownerLink=new ItemAckConnection('localhost','root','',$database,0,$socket);$ownerLink->set_charset('utf8mb4');
    $factory=static fn($s,$e,$id,$a)=>AF_ACS_InventoryAdapter::forLootIssue($ownerLink,$s,$e,$id,$a);
    $issuer=new AF_ACS_LootIssueService($db,$access,$factory);
    $issueRequest=static fn($id,$key)=>['session_id'=>$sid,'encounter_id'=>'1','entry_id'=>$id,'expected_version'=>$repo->session($sid)['session_version'],'expected_allocation_version'=>'1','operation_key'=>$key,'reason'=>'Stage 10 retained grant'];
    $ownerLink->loseAck=true;$lost=$issuer->issue($issueRequest($guaranteed[0],'stage10_owner_lost_ack01'));
    snapshot_assert($lost['status']==='pending_issue'&&$lost['owner_status']==='recovery_required','Owner COMMIT/ACK loss guessed success');
    $GLOBALS['disabled']=['advancedinventory'];$offline=$issuer->issue($issueRequest($guaranteed[1],'stage10_owner_unavailable01'));$GLOBALS['disabled']=[];
    snapshot_assert($offline['status']==='pending_issue'&&$offline['owner_status']==='prepared','Unavailable owner lost durable intent');
    $GLOBALS['stage10Issue']=['lost'=>$guaranteed[0],'offline'=>$guaranteed[1],'lost_key'=>$lost['operation_key'],'offline_key'=>$offline['operation_key'],'tie'=>$tie,'owner_link'=>$ownerLink,'entries'=>$link->query('SELECT id,recipient_group_id,recipient_uid,allocation_version FROM t_af_acs_loot_entries ORDER BY id')->fetch_all(MYSQLI_ASSOC)];
    $summary=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert(array_filter($summary['recovery_required'],fn($x)=>$x['source']==='operation')&&array_filter($summary['pending'],fn($x)=>$x['source']==='statistics'),'Stage 10 pending scopes missing before reward distribution');
};
$GLOBALS['acsStage10PaidFixture']=static function(array $f):void {
    extract($f);$receipts=[];
    foreach(['af_balance_external_operations','af_advinv_external_operations'] as $table)$receipts[$table]=array_column($link->query('SELECT * FROM t_'.$table.' ORDER BY operation_key')->fetch_all(MYSQLI_ASSOC),null,'operation_key');
    $GLOBALS['stage10PaidReceipts']=$receipts;
};
$GLOBALS['acsStage10ArchiveFixture']=static function(array $f):void {
    extract($f);global $mybb,$db;
    $mybb->user['uid']=7;$state=$GLOBALS['stage10Issue'];$recovery=new AF_ACS_RecoveryService($db,$access);
    snapshot_assert($repo->session($sid)['status']==='archived','Expected actual close/archive from reward harness');
    $summary=$sessions->view($sid,true)['outstanding_obligations'];snapshot_assert((bool)array_filter($summary['pending'],fn($x)=>$x['source']==='loot_pool'),'Archived loot obligation lost');
    // Restore the current group owner deliberately changed by the reward fixture; source eligibility
    // is still the frozen pre-completion owner. No reassignment/late eligibility is invented.
    $group=substr($GLOBALS['rewardGroups'][1],6);$link->query('UPDATE t_af_acs_control_groups SET owner_uid=8,group_version=group_version+1 WHERE id='.$group);
    $inventoryBefore=$link->query('SELECT id,uid,qty FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $lost=$recovery->recoverLoot($sid,'1',$state['lost'],'2');snapshot_assert($lost['status']==='issued'&&$lost['operation_key']===$state['lost_key'],'Archived grant lookup changed intent');
    snapshot_assert($inventoryBefore===$link->query('SELECT id,uid,qty FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Lost ACK recovery minted twice');
    $issue=new AF_ACS_LootIssueService($db,$access);$r=['session_id'=>$sid,'encounter_id'=>'1','entry_id'=>$state['offline'],'expected_version'=>$repo->session($sid)['session_version'],'expected_allocation_version'=>'1','operation_key'=>'stage10_archived_pending01','reason'=>'Resume prepared intent only'];
    $issued=$issue->issue($r);snapshot_assert($issued['status']==='issued'&&$issued['operation_key']===$state['offline_key'],'Prepared archived grant changed winner/key');
    $after=$link->query('SELECT id,recipient_group_id,recipient_uid,allocation_version FROM t_af_acs_loot_entries ORDER BY id')->fetch_all(MYSQLI_ASSOC);snapshot_assert($after===$state['entries'],'Outage/correction changed allocated recipients');
    $snap=$captureAll();snapshot_assert($issue->issue($r)===$issued&&$captureAll()===$snap,'Archived issue replay repeated owner delta');
    // Explicitly create pending statistics while archived, then use the shared recovery facade.
    $current=$repo->latestCorrection($sid,'1',$source);$link->query('UPDATE t_af_acs_operations SET updated_at=updated_at-120');
    (new AF_ACS_CorrectionService($db,$access))->command(['session_id'=>$sid,'encounter_id'=>'1','original_event_id'=>$source,'expected_version'=>$repo->session($sid)['session_version'],'expected_review_version'=>$current['review_version'],'operation_key'=>'stage10_pending_stats01','reason'=>'Retained statistics recovery','plan'=>['schema'=>'ic-acs.correction-review.v1','disposition'=>'include','recipient_contract'=>'acs.derived-review.v1','compensation'=>'none']]);
    $GLOBALS['disabled']=['advancedstatistic'];$unavailable=(new AF_ACS_StatisticsService($db,$access))->recover($sid,'1');$GLOBALS['disabled']=[];
    snapshot_assert($unavailable['publication']==='pending'&&array_filter($sessions->view($sid,true)['outstanding_obligations']['pending'],fn($x)=>$x['source']==='statistics'),'Archived statistics outage disappeared');
    $readBefore=$captureAll();$status=$recovery->inspectStatistics($sid,'1');snapshot_assert($status['pending']&&$captureAll()===$readBefore,'Statistics recovery inspection wrote state');
    snapshot_assert($recovery->recoverStatistics($sid,'1')['publication']==='delivered'&&!$recovery->inspectStatistics($sid,'1')['pending'],'Statistics facade did not settle retained outbox');
    $before=$captureAll();$recovery->recoverStatistics($sid,'1');snapshot_assert($captureAll()===$before,'Statistics recovery replay mutated projection');
    foreach([8,9,10] as $viewer){$mybb->user['uid']=$viewer;itemFail(fn()=>$recovery->inspectStatistics($sid,'1'),'ACS_GM_REQUIRED');}$mybb->user['uid']=7;
    // Full populated fingerprint includes owner ledgers, balances and optional tables absent from
    // the old combat capture helper. Activation must not execute owners, refill or clear queues.
    $fingerprint=static function()use($link){$all=[];$q=$link->query("SHOW TABLES LIKE 't_%'");while($r=$q->fetch_row())$all[$r[0]]=hash('sha256',json_encode($link->query('SELECT * FROM `'.$r[0].'`')->fetch_all(MYSQLI_ASSOC)));return $all;};
    require_once AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';if(!defined('IN_ADMINCP'))define('IN_ADMINCP',true);
    $native=new class extends stdClass {public function get_input($k){return $this->input[$k]??'';}};foreach(get_object_vars($mybb) as $k=>$value)$native->$k=$value;$mybb=$native;$mybb->version_code=1841;$mybb->usergroup['cancp']=1;
    $before=$fingerprint();$migrations->run();$migrations->run();snapshot_assert(af_advancedcombatsystem_deactivate()&&af_advancedcombatsystem_activate()&&af_advancedcombatsystem_activate(),'Stage10 populated reactivation failed');snapshot_assert($fingerprint()===$before,'Stage10 activation changed owner/loot/reward/achievement/statistics/archive');
    foreach($GLOBALS['stage10PaidReceipts'] as $table=>$retained){$now=array_column($link->query('SELECT * FROM t_'.$table.' ORDER BY operation_key')->fetch_all(MYSQLI_ASSOC),null,'operation_key');foreach($retained as $key=>$receipt)snapshot_assert(($now[$key]??null)===$receipt,'Correction/lifecycle removed or rewrote paid owner receipt '.$table);}
    $state['owner_link']->close();echo "ACS Stage 10 integration: allocation+grant before rewards, real owner ACK/outage retained through close/archive/correction, archived lookup/sync, immutable winners, GM-only diagnostics and populated reactivation passed\n";
};
require __DIR__.'/acs_statistics_sql.php';
