<?php
/** Isolated appearance_test only; same explicit destructive fixture as mask regression. */
require __DIR__.'/appearance_post_masks_integration.php';
// The real MyBB adapter exposes these identical reader/writer handles.
$db=new class extends AppearanceFixtureDB {
    public mysqli $read_link; public mysqli $write_link;
    public function __construct(){parent::__construct();$this->read_link=$this->write_link=$this->connection;}
};
/** Test harness, NOT a shipped ACS coordinator. MariaDB-only transaction observation. */
class MaskTestUnit implements AF_AA_PostMaskUnitOfWork {
    public array $callbacks=[];public bool $rollbackOnly=false;
    public function __construct(public object $db,public string $key){}
    public function assertActiveWriterAndOwnerLock(object $database,int $uid):void {
        appearance_check($database===$this->db&&$uid===7,'Wrong participant writer/owner');
        $r=$database->fetch_array($database->write_query("SELECT @@in_transaction AS active,IS_USED_LOCK('{$this->key}')=CONNECTION_ID() AS owned"));
        appearance_check((int)$r['active']===1&&(int)$r['owned']===1,'Missing outer transaction/owner lock');
    }
    public function afterCompletion(callable $invalidate):void {$this->callbacks[]=$invalidate;}
    public function markRollbackOnly():void {$this->rollbackOnly=true;}
    public function finish(bool $commit):void {
        appearance_check(!$commit||!$this->rollbackOnly,'Cannot commit rollback-only');
        $this->db->write_query($commit?'COMMIT':'ROLLBACK');
        foreach($this->callbacks as $callback)$callback();
        $this->callbacks=[];$this->db->write_query("SELECT RELEASE_LOCK('{$this->key}')");
    }
}
function mask414_pending(int $pid,array $prepared):MaskFixtureHandler {
    $h=new MaskFixtureHandler;$h->pid=$pid;
    $GLOBALS['af_aa_mask_pending'][spl_object_id($h)]=['uid'=>7,'mask'=>$prepared];return $h;
}
function mask414_no_boundaries(array $sql):void {
    foreach($sql as $q)appearance_check(!preg_match('/\A(?:START TRANSACTION|BEGIN|COMMIT|ROLLBACK|SELECT (?:GET_LOCK|RELEASE_LOCK))/i',$q),'Nested transaction/lock boundary: '.$q);
}
$key=af_aa_personal_owner_lock_key(7);
$prepared=['mode'=>'set','source_preset_id'=>0,'snapshot'=>['text_color'=>'#123456'],'topbar_avatar_media_id'=>null,'sidebar_avatar_media_id'=>null];
$none=['mode'=>'clear'];
// An ordinary reply without pending mask performs no Appearance transaction.
$before=count($db->querylog);$plain=new MaskFixtureHandler;$plain->pid=120;af_aa_mask_after_handler($plain);
appearance_check(count($db->querylog)===$before,'Unmasked reply changed Appearance');
// Normal masked hook still owns one transaction; failure remains a warning after post success.
$h=mask414_pending(120,$prepared);af_aa_mask_after_handler($h);
appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id=120")===1,'Standalone masked reply');
$bad=$prepared;$bad['snapshot']=['text_color'=>'invalid'];$h=mask414_pending(120,$bad);af_aa_mask_after_handler($h);
appearance_check(isset($h->return_values['af_aa_mask_warning']),'Ordinary mask error no warning');
appearance_check(af_aa_mask_row(120)['snapshot']['text_color']==='#123456','Failed standalone mask changed assignment');
// Unjoined ambient call cannot COMMIT or ROLLBACK the caller's work, even via hook.
$db->write_query('START TRANSACTION');$db->write_query("UPDATE ".TABLE_PREFIX."posts SET message='outer-change' WHERE pid=120");
$before=count($db->querylog);$h=mask414_pending(120,$none);
try{af_aa_mask_after_handler($h);throw new RuntimeException('Ambient nesting accepted');}catch(AF_AA_TransactionContextException $e){}
foreach(array_slice($db->querylog,$before) as $q)appearance_check(!in_array($q,['START TRANSACTION','COMMIT','ROLLBACK'],true),'Standalone affected outer transaction');
$db->write_query('ROLLBACK');appearance_check(get_post(120)['message']==='Post 120','Ambient work committed');
// Join on pre-owned lock and existing transaction. No nested boundaries; rollback all.
$db->write_query("SELECT GET_LOCK('{$key}',0)");$db->write_query('START TRANSACTION');
$unit=new MaskTestUnit($db,$key);$h=mask414_pending(121,$prepared);$before=count($db->querylog);
AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($h));
mask414_no_boundaries(array_slice($db->querylog,$before));
appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id=121")===1,'Joined write absent');
af_aa_mask_row(121);$unit->finish(false);
appearance_check(!isset($GLOBALS['af_aa_mask_rows'][121]),'Rolled-back cache retained');
appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id=121")===0,'Joined rollback failed');
// Joined mask failure must escape hook and mark outer UoW rollback-only.
$db->write_query("SELECT GET_LOCK('{$key}',0)");$db->write_query('START TRANSACTION');$unit=new MaskTestUnit($db,$key);
$db->write_query("UPDATE ".TABLE_PREFIX."posts SET message='must-rollback' WHERE pid=122");
$h=mask414_pending(122,$bad);$before=count($db->querylog);
try{AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($h));throw new RuntimeException('Joined error swallowed');}catch(AF_AA_PersonalException $e){}
appearance_check($unit->rollbackOnly&&!isset($h->return_values['af_aa_mask_warning']),'Joined error became warning');mask414_no_boundaries(array_slice($db->querylog,$before));
$unit->finish(false);appearance_check(get_post(122)['message']==='Post 122','Joined error committed post');
// Missing pre-owned lock and a second handler cannot silently join.
$db->write_query('START TRANSACTION');$unit=new MaskTestUnit($db,$key);$h=mask414_pending(124,$prepared);
try{AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($h));throw new LogicException('Unowned lock accepted');}catch(RuntimeException $e){appearance_check($e->getMessage()==='Missing outer transaction/owner lock','Unexpected lock error');}
appearance_check($unit->rollbackOnly,'Missing owner lock not rollback-only');$db->write_query('ROLLBACK');
$unit=new MaskTestUnit($db,$key);$h=mask414_pending(125,$prepared);$other=mask414_pending(126,$prepared);
try{AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($other));throw new LogicException('Different handler accepted');}catch(RuntimeException $e){appearance_check($e->getMessage()==='AA_MASK_HANDLER_SCOPE_MISMATCH','Unexpected scope error');}
appearance_check($unit->rollbackOnly,'Wrong handler not rollback-only');
// Split read/write is unsupported; no fallback standalone transaction is permitted.
$db->write_query("SELECT GET_LOCK('{$key}',0)");$db->write_query('START TRANSACTION');$unit=new MaskTestUnit($db,$key);$h=mask414_pending(127,$prepared);
$db->read_link=new mysqli('localhost','root','','appearance_test');$before=count($db->querylog);
try{AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($h));throw new LogicException('Split writer accepted');}catch(AF_AA_PersonalException $e){appearance_check($e->getCode()===503,'Unexpected split error');}
appearance_check($unit->rollbackOnly,'Split writer not rollback-only');mask414_no_boundaries(array_slice($db->querylog,$before));
$db->read_link->close();$db->read_link=$db->write_link;$unit->finish(false);
// Successful outer commit retains the mask; later standalone cosmetic change is independent.
$db->write_query("SELECT GET_LOCK('{$key}',0)");$db->write_query('START TRANSACTION');$unit=new MaskTestUnit($db,$key);$h=mask414_pending(123,$prepared);
AF_AA_PostMaskScope::within($unit,$h,fn()=>af_aa_mask_after_handler($h));$unit->finish(true);
appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id=123")===1,'Outer commit lost mask');
af_aa_mask_commit(123,7,$none);appearance_check(appearance_count(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='post' AND entity_id=123")===0,'Standalone cosmetic change failed');
appearance_check(AF_AA_PostMaskScope::forHandler(new stdClass)===null,'Scope leaked after completion');
foreach(['AF_ACS_Resolver','AF_ACS_SealService'] as $class)appearance_check(!class_exists($class,false),'Appearance loaded ACS mechanics');
echo "Mask UoW: ordinary reply/mask/failure, ambient rejection without outer rollback/commit, joined boundaries/error propagation/cache invalidation, outer rollback/commit and cosmetic independence passed.\n";
