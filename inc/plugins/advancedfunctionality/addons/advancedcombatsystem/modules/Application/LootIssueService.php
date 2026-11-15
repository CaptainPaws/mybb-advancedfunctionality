<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/LootAllocationService.php';
require_once __DIR__.'/InventoryOperationService.php';
/** Two durable ACS phases around a standalone owner grant. No shared owner/MyBB transaction. */
final class AF_ACS_LootIssueService
{
    private AF_ACS_OperationStore $store;
    public function __construct(private object $database,private AF_ACS_SessionAccess $access,private ?Closure $adapterFactory=null)
    {$this->repo=new AF_ACS_EncounterRepository($database);$this->store=new AF_ACS_OperationStore($database);}
    private AF_ACS_EncounterRepository $repo;
    private function authority(string $sid):array
    {
        $session=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$this->access->thread($session['tid']);
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        if(!AF_ACS_AuthorizationService::sessionGm($session,$this->repo->roles($sid,$this->access->uid()),$this->access->uid()))throw new RuntimeException('ACS_GM_REQUIRED');return $session;
    }
    private function intent(array $facts):array
    {
        if(!af_is_addon_enabled('knowledgebase'))throw new RuntimeException('ACS_LOOT_KB_UNAVAILABLE');require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
        $e=$facts['entry'];$pinned=$this->repo->lootIssueSnapshot($facts['session_id'],$e['item_snapshot_id']);$row=$this->repo->lootIssueRawItem($facts['generated']['item_ref']['key']);$issues=[];$source=af_kb_arpg_acs_source($row,$issues);
        if($issues||($source['fingerprint']??null)!==($pinned['definition_version']['fingerprint']??null)||($source['rules']['version']??null)!==($pinned['definition_version']['declared_version']??null))throw new RuntimeException('ACS_LOOT_OWNER_DEFINITION_CHANGED');
        $metadata=json_decode((string)($row['meta_json']??''),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($metadata)||array_keys($metadata)!==['rules']||!is_array($metadata['rules'])||af_kb_arpg_acs_fingerprint($metadata['rules'])!==$pinned['definition_version']['fingerprint'])throw new RuntimeException('ACS_LOOT_OWNER_METADATA_UNSUPPORTED');
        $profile=af_kb_get_normalized_item_profile($row,'inventory');
        if(empty($profile['supported'])||!empty($profile['skip'])||!in_array($profile['item_kind']??null,['consumable','ammo'],true))throw new RuntimeException('ACS_LOOT_GRANT_KIND_UNSUPPORTED');
        $meta=(string)($row['meta_json']??'');$hash=hash('sha256',$meta);
        $normalized=['qty'=>$facts['generated']['qty'],'definition_ref'=>['type'=>'arpg_item','key'=>$facts['generated']['item_ref']['key'],'version'=>'sha256:'.$hash,'hash'=>$hash],'snapshot_json'=>$meta,'entity'=>'equipment','subtype'=>$profile['item_kind']];
        $intent=['schema_version'=>1,'operation'=>'grant','operation_key'=>AF_ACS_LootIssue::key($e),'actor'=>['uid'=>(int)$this->access->uid(),'authorization_ref'=>AF_ACS_LootIssue::ref($facts['encounter_id'],$e)],'scope'=>['system'=>'acs','context_key'=>$facts['session_id']],'payload'=>['recipient_uid'=>(int)$e['recipient_uid'],'normalized_item'=>$normalized,'provenance'=>['system'=>'acs','source_key'=>'loot-entry:'.$e['id']]]];$intent['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($intent);return $intent;
    }
    public function issue(array $input):array
    {
        $this->access->csrf();$fields=['session_id','encounter_id','entry_id','expected_version','expected_allocation_version','operation_key','reason'];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        $r=$input;foreach(['session_id','encounter_id','entry_id','expected_version','expected_allocation_version'] as $k)$r[$k]=AF_ACS_Repository::integer($r[$k],'18446744073709551614');
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key'])||trim($r['reason'])===''||mb_strlen($r['reason'])>1000)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');$r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        $this->authority($r['session_id']);
        $this->repo->command('session:'.$r['actor_uid'].':'.$r['operation_key'],'loot.issue_prepare',$r,fn()=>$this->authority($r['session_id']),
            function($session)use($r){$facts=$this->repo->lootIssueFacts($session['id'],$r['encounter_id'],$r['entry_id']);return $this->repo->prepareLootIssue($session,$r['encounter_id'],$r['entry_id'],$r['expected_allocation_version'],$this->intent($facts));},null,
            fn()=>$this->repo->lootIssueReplay($r['session_id'],$r['encounter_id'],$r['entry_id'],$r['expected_allocation_version'],$r['actor_uid']));
        return $this->drive($r['session_id'],$r['encounter_id'],$r['entry_id'],false);
    }
    public function inspect(string $sid,string $eid,string $entry):array
    {
        foreach(['sid','eid','entry'] as $k)$$k=AF_ACS_Repository::integer($$k);$this->authority($sid);$f=$this->repo->lootIssueFacts($sid,$eid,$entry);$e=$f['entry'];$row=$e['issue_operation_key']===null?null:$this->repo->lootIssueIntent($sid,$eid,$entry)['operation'];
        $result=['session_id'=>$sid,'encounter_id'=>$eid,'entry_id'=>$entry,'allocation_version'=>$e['allocation_version'],'recipient_uid'=>$e['recipient_uid'],'status'=>$e['status'],'operation_key'=>$e['issue_operation_key'],'operation_version'=>$row['operation_version']??null,'owner_status'=>$row['status']??null];
        if($row&&in_array($row['status'],['committed','rejected'],true)){$intent=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);$receipt=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_LootIssue::receipt($intent,$receipt);$result['owner_receipt']=$receipt;}
        return $result;
    }
    public function recover(string $sid,string $eid,string $entry,string $expected):array
    {
        $this->access->csrf();foreach(['sid','eid','entry','expected'] as $k)$$k=AF_ACS_Repository::integer($$k);$this->authority($sid);return $this->drive($sid,$eid,$entry,true,$expected);
    }
    private function drive(string $sid,string $eid,string $entry,bool $lookupOnly,?string $expected=null):array
    {
        $this->authority($sid);$f=$this->repo->lootIssueIntent($sid,$eid,$entry);
        if((string)$f['intent']['actor']['uid']!==$this->access->uid())throw new RuntimeException('ACS_LOOT_ISSUER_REQUIRED');
        if(in_array($f['entry']['status'],['issued','issue_rejected'],true))return $this->inspect($sid,$eid,$entry);
        if($expected!==null&&$f['operation']['operation_version']!==$expected&&!in_array($f['operation']['status'],['committed','rejected'],true))throw new RuntimeException('ACS_VERSION_CONFLICT');
        $code=null;$projection=null;
        if(!in_array($f['operation']['status'],['committed','rejected'],true))try{
            $this->repo->lootIssueExternalBoundary();
            $adapter=$this->adapterFactory?($this->adapterFactory)($sid,$eid,$entry,$this->access):AF_ACS_InventoryAdapter::forLootIssue($this->database,$sid,$eid,$entry,$this->access,!$lookupOnly&&$f['operation']['status']==='prepared');
            $inventory=new AF_ACS_InventoryOperationService($this->database,$this->access,$adapter);
            if(!$lookupOnly&&$f['operation']['status']==='prepared')$projection=$inventory->execute($sid,$f['intent']);
            else $projection=$inventory->recover($sid,$f['intent']['operation_key'],$f['operation']['operation_version']);
        }catch(Throwable $error){$code=preg_match('/\A(?:ACS|INV)_[A-Z_]+\z/D',$error->getMessage())?$error->getMessage():'ACS_LOOT_ISSUE_UNAVAILABLE';}
        $f=$this->repo->lootIssueIntent($sid,$eid,$entry);
        if(in_array($f['operation']['status'],['committed','rejected'],true)&&$f['entry']['status']==='pending_issue'){
            $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'encounter_id'=>$eid,'entry_id'=>$entry,'expected_version'=>$this->repo->session($sid)['session_version'],'reason'=>'Reconcile retained owner grant receipt'];
            try{$this->repo->command('loot:issue:ack:'.$entry.':'.$f['entry']['allocation_version'],'loot.issue_ack',$request,fn()=>$this->authority($sid),fn($s)=>$this->repo->acknowledgeLootIssue($s,$eid,$entry));}
            catch(Throwable $error){$code=preg_match('/\A(?:ACS|INV)_[A-Z_]+\z/D',$error->getMessage())?$error->getMessage():'ACS_LOOT_ISSUE_UNAVAILABLE';}
        }
        $result=$this->inspect($sid,$eid,$entry);if(isset($projection['lookup_state']))$result['lookup_state']=$projection['lookup_state'];if($code!==null&&$result['status']==='pending_issue')$result['recovery_code']=$code;return $result;
    }
}
