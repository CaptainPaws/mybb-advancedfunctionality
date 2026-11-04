<?php
declare(strict_types=1);
function get_thread($tid):array{return ['tid'=>(int)$tid,'fid'=>1,'uid'=>7,'visible'=>1,'closed'=>0];}
function is_moderator(...$args):bool{return false;}
$GLOBALS['acsPresentationFixture']=static function(array $f):void {
    extract($f);global $mybb,$db,$pids;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCardQuery.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/CombatCard.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/RecoveryPage.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/ThreadComponents.php';
    require_once AF_ADDONS.'advancedcombatsystem/modules/Application/RecoveryService.php';
    $native=(object)['read_link'=>$link,'write_link'=>$link];$oldDb=$db;$query=new AF_ACS_CombatCardQuery($link);
    $r=$intent();$receipt=$writer->commit($r['submission_id'],$r['state_version']);$before=$capture();
    $cards=$query->forPosts('51',['501','999'],$access);$card=$cards['501'];
    snapshot_assert($card['mechanics_confirmed']&&$card['details']&&$card['result_hash']===$receipt['result_hash'],'Verified result not projected');
    snapshot_assert($card['details'][0]['damage']===$confirmed['summary']['actions'][0]['damage'],'Display recalculated damage');
    $html=AF_ACS_CombatCard::render($card);snapshot_assert(str_contains($html,'Урон:')&&str_contains($html,'эффективная защита')&&str_contains($html,'Попадание:'),'Stored Resolver fields missing');
    $evil=$card;$evil['details'][0]['actor_name']='<script>leak()</script>';$escaped=AF_ACS_CombatCard::render($evil);snapshot_assert(!str_contains($escaped,'<script>')&&str_contains($escaped,'&lt;script&gt;'),'Entity name XSS');
    $mybb->input['af_aa_mask']='another-cosmetic';snapshot_assert($query->forPosts('51',['501'],$access)['501']===$card,'Mask changed mechanics card');
    // A real ATF component registry/renderer, no template or APUI changes.
    require_once AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
    $db=$native;$pids='501,999';af_advancedcombatsystem_register_thread_component();
    $slot=af_adaptivethemeframework_render_slot('post.before_body',['pid'=>501,'tid'=>51,'uid'=>999,'appearance'=>['mask'=>'cosmetic']]);
    snapshot_assert(str_contains($slot,'Результат подтверждён')&&str_contains($slot,'Урон:'),'ATF result slot missing or trusts cosmetic uid');
    $again=af_adaptivethemeframework_render_slot('post.before_body',['pid'=>501,'tid'=>51,'uid'=>7]);snapshot_assert($again===$slot,'Appearance context changed slot result');
    snapshot_assert($capture()===$before,'Card GET mutated ACS');
    // Existing gm-only participant visibility must not leak details to players/observers.
    $link->query("INSERT INTO fixture_af_acs_session_roles(session_id,uid,role,permissions_json,role_version) VALUES(".$sid.",9,'observer','{\"schema_version\":1}',1) ON DUPLICATE KEY UPDATE expires_at=NULL");
    $mybb->user['uid']=9;$limited=$query->forPosts('51',['501'],$access)['501'];$public=AF_ACS_CombatCard::render($limited);
    snapshot_assert($limited['details']===null&&!isset($limited['result_hash'])&&!str_contains($public,'Урон:')&&!str_contains($public,'Попадание:'),'GM values leaked to observer');
    $mybb->user['uid']=10;snapshot_assert($query->forPosts('51',['501'],$access)===[],'Foreign viewer learned combat card');$mybb->user['uid']=7;
    $r=$intent(true);$before=$capture();$pending=$query->forPosts('51',['501'],$access)['501'];
    snapshot_assert(!$pending['mechanics_confirmed']&&$pending['details']===null&&!str_contains(AF_ACS_CombatCard::render($pending),'Урон:'),'Pending damage shown as applied');
    snapshot_assert($capture()===$before,'Pending card wrote state');
    $r=$intent();$writer->commit($r['submission_id'],$r['state_version']);$link->query("UPDATE fixture_af_acs_combat_results SET payload_json=CONCAT(payload_json,' ')");
    blocked(fn()=>$query->forPosts('51',['501'],$access),'ACS_COMBAT_RECEIPT_INVALID');
    // Read-only queue followed by explicit, audited GM commands. No writer commit from this UI.
    $r=$intent();$id=$r['submission_id'];$mybb->user['uid']=8;$mybb->request_method='get';$mybb->post_code='token';$mybb->input=['session_id'=>$sid,'submission_id'=>$id];
    $before=$capture();$view=AF_ACS_RecoveryPage::render($sid);
    snapshot_assert(str_contains($view,'Передать на ручную проверку')&&str_contains($view,'expected_state_version')&&!str_contains($view,'name="pid"'),'GM queue unsafe or no CAS');snapshot_assert($capture()===$before,'GM GET changed state');$gmHtml=$view;
    $mybb->request_method='post';$mybb->input=['session_id'=>$sid,'submission_id'=>$id,'tid'=>'51','expected_state_version'=>$r['state_version'],'my_post_key'=>'token','strategy'=>'quarantine','reason'=>'Review <script>alert(1)</script>'];
    $beforeMechanics=array_intersect_key($capture(),array_flip(['entity_states','resources','turns','events','combat_results','combat_receipts','outbox','rolls','sealed_actions']));
    $view=AF_ACS_RecoveryPage::render($sid);$updated=$store->submission($id);
    snapshot_assert($updated['status']==='recovery_required'&&str_contains($view,'Решение сохранено')&&!str_contains($view,'<script>')&&str_contains($view,'&lt;script&gt;'),'GM quarantine/audit escaping failed');
    $reviewHtml=$view;
    snapshot_assert((string)$link->query("SELECT actor_uid FROM fixture_af_acs_publication_audit WHERE reason='gm_recovery_quarantine'")->fetch_row()[0]==='8','Wrong audit actor');
    snapshot_assert(array_intersect_key($capture(),$beforeMechanics)===$beforeMechanics,'GM UI applied mechanics');
    $after=$capture();$view=AF_ACS_RecoveryPage::render($sid);snapshot_assert(str_contains($view,'Решение не подтверждено')&&$capture()===$after,'Stale POST repeated decision');
    $mybb->input['expected_state_version']=$updated['state_version'];$mybb->input['my_post_key']='bad';$view=AF_ACS_RecoveryPage::render($sid);snapshot_assert($capture()===$after,'CSRF changed GM decision');$mybb->input['my_post_key']='token';
    $mybb->user['uid']=9;blocked(fn()=>AF_ACS_RecoveryPage::render($sid),'ACS_RECOVERY_PERMISSION_DENIED');$mybb->user['uid']=8;
    $mybb->input['strategy']='resume_known_post';$mybb->input['reason']='Known native evidence checked';$view=AF_ACS_RecoveryPage::render($sid);
    snapshot_assert($store->submission($id)['status']==='post_confirmed'&&str_contains($view,'Решение сохранено'),'GM known-pid resume failed');
    snapshot_assert(array_intersect_key($capture(),$beforeMechanics)===$beforeMechanics,'Resume impersonated author');
    $r=$intent();$mybb->user['uid']=7;$mybb->request_method='post';$mybb->input=['session_id'=>$sid,'submission_id'=>$r['submission_id'],'tid'=>'51','expected_state_version'=>$r['state_version'],'my_post_key'=>'token','strategy'=>'quarantine','reason'=>'Author GM explicitly requests review'];
    $ownBefore=array_intersect_key($capture(),$beforeMechanics);AF_ACS_RecoveryPage::render($sid);
    snapshot_assert(array_intersect_key($capture(),$beforeMechanics)===$ownBefore&&$store->submission($r['submission_id'])['status']==='recovery_required','Author GM quarantine invoked combat writer');
    $mybb->user['uid']=8;
    // Unknown pid can be held, but not selected from candidates or rejected after POSTING.
    $r=$intent();$link->query('UPDATE fixture_af_acs_submissions SET pid=NULL');$r=$store->submission($r['submission_id']);
    $recovery=new AF_ACS_RecoveryService($native,$access);$recovery->requestReview($r['submission_id'],$r['state_version'],'Unknown native identity');$r=$store->submission($r['submission_id']);
    $mybb->request_method='get';$mybb->input=['session_id'=>$sid,'submission_id'=>$r['submission_id']];$view=AF_ACS_RecoveryPage::render($sid);
    snapshot_assert(str_contains($view,'Оставить заблокированной')&&!str_contains($view,'value="resume_known_post"')&&!str_contains($view,'value="reject_unsent"'),'Unknown identity offered unsafe command');
    $mybb->input['submission_id']=str_repeat('f',32);blocked(fn()=>AF_ACS_RecoveryPage::render($sid),'ACS_SUBMISSION_NOT_FOUND');
    if(getenv('ACS_PRESENTATION_DOM_FIXTURE')==='1')file_put_contents('/tmp/acs-stage6-presentation.json',json_encode(['confirmed'=>$escaped,'limited'=>$public,'pending'=>AF_ACS_CombatCard::render($pending),'queue'=>$gmHtml,'review'=>$reviewHtml],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $mybb->user['uid']=7;$db=$oldDb;$reset();echo "ACS result/GM UI: verified stored card, ATF slot, visibility/mask/XSS, no GET mutations, GM quarantine/resume/CAS/CSRF/audit and immutable mechanics passed\n";
};
require __DIR__.'/acs_combat_commit_regression.php';
