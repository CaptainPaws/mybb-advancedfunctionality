<?php
function get_thread($tid):array {return ['tid'=>(int)$tid,'fid'=>2,'uid'=>7,'visible'=>1,'closed'=>'0'];}
function af_clear_addon_lifecycle_diagnostic($addon):void {}
function af_store_addon_lifecycle_diagnostic(...$args):void {throw new RuntimeException('Unexpected lifecycle error');}
$GLOBALS['acsCustodyFixture']=static function(array $fixture):void {
 extract($fixture);global $mybb,$db;
 require_once AF_ADDONS.'advancedcombatsystem/modules/Application/CustodyService.php';
 require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/InventoryObligationsQuery.php';
 require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/InventoryObligations.php';
 $s2=$entities->command('fixture:isolation2','session.create',['schema_version'=>1,'actor_uid'=>'7'],fn()=>null,fn()=>$entities->insertSession('51','7',['schema_version'=>1]));$sid2=$s2['session_id'];
 $e2=$entityService->register(['session_id'=>$sid2,'expected_version'=>$s2['session_version'],'kind'=>'character','source_key'=>'3','initial_hp'=>'90','operation_key'=>'isolation_character2','reason'=>'Second session']);$actor2=$e2['entity_id'];
 $service=new AF_ACS_CustodyService($db,$access);$sessions=new AF_ACS_SessionService($repo,$access);
 $request=static function(string $session,string $entity,string $item,string $key,string $version='0',?string $custodian=null)use($repo):array {return ['session_id'=>$session,'expected_version'=>$repo->session($session)['session_version'],'expected_custody_version'=>$version,'operation_key'=>$key,'reason'=>'Cross-session fixture','inventory_item_id'=>$item,'owner_entity_id'=>$entity,'custodian_entity_id'=>$custodian??''];};
 $a=$service->command('confiscate',$request($sid,$actor,'20','isolation_confiscate1'));
 itemFail(fn()=>$service->command('confiscate',$request($sid2,$actor2,'20','isolation_same_item2')),'INV_RESERVATION_CHANGED');
 snapshot_assert(!$repo->custodyRows($sid2),'Other session acquired held item');
 $b=$service->command('confiscate',$request($sid2,$actor2,'9','isolation_confiscate2'));
 $aBefore=$repo->custodyRows($sid);$aVersion=$repo->session($sid)['session_version'];
 itemFail(fn()=>$service->command('assign',$request($sid2,$actor2,'9','isolation_bad_target','1',$actor)),'ACS_CUSTODY_ENTITY_UNAVAILABLE');
 $returned=$service->command('return',$request($sid2,$actor2,'9','isolation_return_002','1'));
 snapshot_assert($returned['status']==='returned'&&$repo->custodyRows($sid)===$aBefore&&$repo->session($sid)['session_version']===$aVersion,'Cross-session custody changed');
 snapshot_assert($link->query('SELECT status FROM t_af_advinv_reservations WHERE id='.$a['reservation_id'])->fetch_row()[0]==='active','Foreign return released reservation');
 $page=(new AF_ACS_InventoryObligationsQuery($db))->page($sid,$access);
 snapshot_assert(count($page['items'])===1&&$page['items'][0]['reservation']['status']==='active'&&count($page['reservations'])===1,'Scoped obligations not shown');
 require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/RecoveryPage.php';$mybb->request_method='get';$mybb->input=['session_id'=>$sid];$nativeMybb=$mybb;$mybb=new class($mybb) extends stdClass {public function __construct($old){foreach(get_object_vars($old) as $k=>$v)$this->$k=$v;$this->post_code='token';}public function get_input($key){return $this->input[$key]??'';}};
 $full=AF_ACS_RecoveryPage::render($sid);snapshot_assert(str_contains($full,'Имущество и обязательства')&&str_contains($full,'собственник UID 7'),'Recovery panel integration missing');$mybb=$nativeMybb;$mybb->request_method='post';$mybb->input=['my_post_key'=>'token'];
 $evil=$page;$evil['items'][0]['owner_uid']='<script>bad()</script>';$html=AF_ACS_InventoryObligations::render($evil,'token');snapshot_assert(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Inventory panel XSS');
 $GLOBALS['disabled']=['advancedinventory'];$down=(new AF_ACS_InventoryObligationsQuery($db))->page($sid,$access);snapshot_assert($down['items'][0]['reservation']['status']==='unverified'&&$down['items'][0]['available_commands']===[],'Unavailable owner fabricated active reserve');
 itemFail(fn()=>$service->command('return',$request($sid,$actor,'20','isolation_off_return','1')),'INV_ACCESS_DENIED');$GLOBALS['disabled']=[];
 $beforeItems=$link->query('SELECT * FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);$beforeRes=$link->query('SELECT * FROM t_af_advinv_reservations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 foreach([[$sid2,'isolation_close_002'],[$sid,'isolation_close_001']] as [$id,$key])$sessions->command('close',['session_id'=>$id,'expected_version'=>$repo->session($id)['session_version'],'operation_key'=>$key,'reason'=>'Preserve custody']);
 $sessions->command('archive',['session_id'=>$sid,'expected_version'=>$repo->session($sid)['session_version'],'operation_key'=>'isolation_archive01','reason'=>'Preserve return obligation']);
 $migrations->run();$migrations->assertInstalled();
 require_once AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';
 define('IN_ADMINCP',true);
 $originalMybb=$mybb;$mybb=new class($mybb) extends stdClass {public function __construct($old){foreach(get_object_vars($old) as $k=>$v)$this->$k=$v;$this->version_code=1841;$this->usergroup['cancp']=1;$this->admin=['permissions'=>['advancedfunctionality'=>['index'=>1]]];}public function get_input($key){return $this->input[$key]??'';}};
 snapshot_assert(af_advancedcombatsystem_deactivate()&&af_advancedcombatsystem_activate()&&!isset($GLOBALS['af_acs_deactivated']),'Lifecycle reactivation failed');$mybb=$originalMybb;
 snapshot_assert($repo->custodyRows($sid)===$aBefore&&$link->query('SELECT * FROM t_af_advinv_reservations ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforeRes&&$link->query('SELECT * FROM t_af_advinv_items ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforeItems,'Lifecycle/activation lost obligations or ownership');
 $end=$service->command('return',$request($sid,$actor,'20','isolation_return_end','1'));snapshot_assert($end['status']==='returned','Explicit archived return blocked');
 $mybb->user['uid']=9;itemFail(fn()=>(new AF_ACS_InventoryObligationsQuery($db))->page($sid,$access),'ACS_RECOVERY_PERMISSION_DENIED');$mybb->user['uid']=7;
 echo "ACS cross-session isolation: separate custody, same item denied, scoped return, owner unchanged, explicit close/archive retain obligations, outage panel, reactivation schema gate and archived return passed\n";
};
require __DIR__.'/acs_combat_items_sql.php';
