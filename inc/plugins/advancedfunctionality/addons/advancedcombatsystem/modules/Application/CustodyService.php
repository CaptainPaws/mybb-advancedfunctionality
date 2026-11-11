<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';
require_once __DIR__.'/../Infrastructure/Adapters/SessionAccess.php';
require_once __DIR__.'/../Infrastructure/Adapters/CustodyInventoryAccess.php';
require_once __DIR__.'/../../../advancedinventory/modules/InventoryRead.php';

/** GM-administrative custody. No AP, controller transfer, permanent equip, consume or ownership transfer. */
final class AF_ACS_CustodyService
{
    private AF_ACS_EncounterRepository $repo;
    public function __construct(private object $database,private AF_ACS_SessionAccess $access)
    {if (!($database instanceof mysqli) && isset($database->read_link) && $database->read_link!==$database->write_link) throw new RuntimeException('ACS_AUTHORITATIVE_CONNECTION_REQUIRED');$this->repo=new AF_ACS_EncounterRepository($database);$this->repo->assertReadConnection($database);}
    private function authority(string $sid,bool $native=true): array
    {
        if (!af_is_addon_enabled('advancedcombatsystem')) throw new RuntimeException('ACS_DISABLED');
        $uid=$this->access->uid();$s=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_NOT_FOUND');
        $roles=array_column($this->repo->roles($sid,$uid),'role');
        if (!in_array('gm',$roles,true) && !((string)$s['owner_uid']===$uid&&in_array('owner',$roles,true))) throw new RuntimeException('ACS_CUSTODY_PERMISSION_DENIED');
        if ($native) $this->access->thread($s['tid']);
        return $s;
    }
    private function key(string $key): string
    {if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key)) throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');return $key;}
    public function lookup(string $sid,string $key): array
    {
        $sid=AF_ACS_Repository::integer($sid);$this->authority($sid);$key=$this->key($key);
        $r=$this->repo->custodyOperation($sid,'session:'.$this->access->uid().':'.$key,$this->access->uid());
        return $r??['status'=>'not_found','operation_key'=>$key];
    }
    public function view(string $sid): array
    {
        $sid=AF_ACS_Repository::integer($sid);$s=$this->authority($sid);$out=[];
        foreach ($this->repo->custodyRows($sid) as $row) {$p=AF_ACS_Custody::payload($row);$out[]=['id'=>(string)$row['id'],'item_id'=>(string)$row['inventory_item_id'],'owner_uid'=>(string)$row['owner_uid'],'custodian_entity_id'=>$row['custodian_entity_id']===null?null:(string)$row['custodian_entity_id'],'active_user_entity_id'=>$row['active_user_entity_id']===null?null:(string)$row['active_user_entity_id'],'status'=>$row['status'],'custody_version'=>(string)$row['custody_version'],'obligations'=>$p['obligations'],'return_restrictions'=>$p['return_restrictions']];}
        return ['session_id'=>$sid,'session_version'=>$s['session_version'],'items'=>$out];
    }
    private function recipient(string $sid,?string $id): void
    {
        if ($id===null) return;
        $e=$this->repo->entity($sid,$id);
        if (!$e || $e['life_state']!=='active') throw new RuntimeException('ACS_CUSTODY_ENTITY_UNAVAILABLE');
    }
    private function request(string $operation,string $key,string $sid,array $payload): array
    {
        $r=['schema_version'=>1,'operation'=>$operation,'operation_key'=>$key,'actor'=>['uid'=>(int)$this->access->uid(),'authorization_ref'=>'acs-custody:'.$sid],'scope'=>['system'=>'acs','context_key'=>$sid],'payload'=>$payload];
        $r['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($r);return $r;
    }
    private function capture(string $sid,array $item,array $rows,int $owner): array
    {
        if ((int)$item['qty']!==1) throw new RuntimeException('ACS_CUSTODY_STACK_UNSUPPORTED');
        if ($item['entity']!=='equipment' || $item['kb_type']!=='arpg_item' || !function_exists('af_advinv_resolve_appearance_item') || !empty(af_advinv_resolve_appearance_item($item)['is_visual_item'])) throw new RuntimeException('ACS_CUSTODY_ITEM_UNSUPPORTED');
        $chosen=null;
        foreach ($rows as $row) if ($row['status']==='active') {
            if (!hash_equals($row['content_hash'],hash('sha256',$row['payload_json']))) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');
            $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$spec=$p['item']??null;
            if ($spec) {
                if ($spec['owner_uid']!==$owner || $spec['item_id']!==(int)$item['id'] || $spec['item_fingerprint']!==AF_ACS_Equipment::fingerprint(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json'])))) throw new RuntimeException('ACS_CUSTODY_SOURCE_CHANGED');
                $pin=$spec['pin'];
            } else {
                if (($p['owner_uid']??null)!==$owner || ($p['inventory_item_id']??null)!==(int)$item['id'] || ($p['instance_fingerprint']??null)!==AF_ACS_Equipment::fingerprint($item)) throw new RuntimeException('ACS_CUSTODY_SOURCE_CHANGED');
                $pin=$p['kb_ref'];
            }
            $candidate=['item_id'=>(int)$item['id'],'owner_uid'=>$owner,'kind'=>$item['subtype'],'slot'=>$row['slot_key'],'snapshot_id'=>(string)$row['item_snapshot_id'],'snapshot_hash'=>$row['content_hash'],'pin'=>$pin,'bonuses'=>AF_ACS_Custody::bonuses($row)];
            if ($chosen && ($candidate['slot']!==$chosen['slot'] || $candidate['bonuses']!==$chosen['bonuses'])) throw new RuntimeException('ACS_CUSTODY_SOURCE_CHANGED');
            $chosen=$candidate;
        }
        return $chosen??throw new RuntimeException('ACS_CUSTODY_PINNED_EQUIPMENT_REQUIRED');
    }
    public function command(string $operation,array $input): array
    {
        $this->access->csrf();
        if (!in_array($operation,['confiscate','assign','temporary_use','return'],true) || array_diff(array_keys($input),['session_id','expected_version','expected_custody_version','operation_key','reason','inventory_item_id','owner_entity_id','custodian_entity_id'])) throw new RuntimeException('ACS_CUSTODY_INPUT_INVALID');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$this->authority($sid);$key=$this->key((string)($input['operation_key']??''));
        $reason=trim((string)($input['reason']??''));if ($reason==='' || strlen($reason)>512 || str_contains($reason,"\0")) throw new RuntimeException('ACS_REASON_REQUIRED');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'operation'=>$operation,'session_id'=>$sid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'expected_custody_version'=>AF_ACS_Repository::integer($input['expected_custody_version']??null,'9007199254740990',true),'inventory_item_id'=>AF_ACS_Repository::integer($input['inventory_item_id']??null,'4294967295'),'owner_entity_id'=>$operation==='confiscate'?AF_ACS_Repository::integer($input['owner_entity_id']??null):null,'custodian_entity_id'=>in_array($operation,['confiscate','assign','temporary_use'],true)&&($input['custodian_entity_id']??'')!==''?AF_ACS_Repository::integer($input['custodian_entity_id']):null,'reason'=>$reason];
        try {return $this->repo->command('session:'.$this->access->uid().':'.$key,'custody.'.$operation,$r,
            fn()=>$this->authority($sid,false),function($session)use($r,$key,$sid,$operation) {
                if ($operation!=='return'&&$session['status']!=='active') throw new RuntimeException('ACS_CUSTODY_SESSION_UNAVAILABLE');
                $this->repo->assertInventorySettled($sid);$this->repo->assertInventorySettled($sid,[$r['inventory_item_id']]);$this->repo->custodyNoPending($sid);$this->repo->custodyRows($sid,true);
                $before=$this->repo->custodyItem($sid,$r['inventory_item_id']);
                if ((string)($before['custody_version']??0)!==$r['expected_custody_version']) throw new RuntimeException('ACS_CUSTODY_VERSION_CONFLICT');
                if ($before) AF_ACS_Custody::payload($before);
                $this->recipient($sid,$r['custodian_entity_id']);$affected=[];
                $link=$this->database instanceof mysqli?$this->database:$this->database->write_link;
                $policy=new AF_ACS_CustodyInventoryAccess($link,$sid,(int)$this->access->uid());$reader=new AF_ADVINV_InventoryRead($this->database,$policy);$ownerApi=new AF_ADVINV_OwnerProtocol($link,$policy);
                if ($operation==='confiscate') {
                    if ($before && $before['status']!=='returned') throw new RuntimeException('ACS_CUSTODY_ALREADY_HELD');
                    $entity=$this->repo->itemEntity($sid,$r['owner_entity_id']);$owner=(int)$entity['owner_uid'];
                    if ($before && (int)$before['owner_uid']!==$owner) throw new RuntimeException('ACS_CUSTODY_SOURCE_CHANGED');
                    if ($owner<1 || $entity['kind']!=='character') throw new RuntimeException('ACS_CUSTODY_OWNER_UNAVAILABLE');
                    $reader->fence($owner,(int)$this->access->uid());$data=$reader->read($owner,(int)$this->access->uid())->serverData();$item=null;
                    foreach ($data['items'] as $candidate) if ((string)$candidate['id']===$r['inventory_item_id']) $item=$candidate;
                    if (!$item) throw new RuntimeException('ACS_CUSTODY_ITEM_UNAVAILABLE');
                    $rows=$this->repo->custodyEquipment($sid,$r['inventory_item_id']);$captured=$this->capture($sid,$item,$rows,$owner);
                    $active=array_values(array_filter($data['reservations'],fn($v)=>(string)$v['item_id']===$r['inventory_item_id']));
                    if (count($active)>1) throw new RuntimeException('ACS_CUSTODY_RESERVATION_CONFLICT');
                    if ($active) {
                        $e=$ownerApi->reservationEvidence((string)$active[0]['id'],$owner,['system'=>'acs','context_key'=>$sid],'acs-custody:'.$sid);$bound=false;
                        foreach ($rows as $row) { $p=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);if ($row['status']==='active'&&($p['item']['reservation']['reservation']['id']??null)===$e['reservation']['id']) $bound=true; }
                        if (!$bound || $e['reservation']['remaining_qty']!==1 || $e['reservation']['version']!==1) throw new RuntimeException('ACS_CUSTODY_RESERVATION_CONFLICT');
                        $reservation=$e['receipt'];$adopted=true;
                    } else {
                        $request=$this->request('reserve','acs:custody:reserve:'.$r['actor_uid'].':'.$key,$sid,['owner_uid'=>$owner,'item_id'=>(int)$r['inventory_item_id'],'qty'=>1,'session_id'=>$sid,'expected_inventory_version'=>$data['inventory_version']]);
                        $reservation=$ownerApi->participate('reserve',$request)->serverReceipt();$adopted=false;
                        if ($reservation['status']!=='prepared') throw new RuntimeException('ACS_CUSTODY_RESERVATION_CONFLICT');
                    }
                    $original=[];foreach ($rows as $row) if ($row['status']==='active') $original[]=array_intersect_key($row,array_flip(['id','entity_id','slot_key','item_snapshot_id','equipment_version','status']));
                    $p=['schema_version'=>1,'engine_version'=>'acs-custody-1','session_id'=>$sid,'inventory_item_id'=>(int)$r['inventory_item_id'],'owner_uid'=>$owner,'owner_entity_id'=>$r['owner_entity_id'],'custodian_entity_id'=>$r['custodian_entity_id'],'active_user_entity_id'=>null,'status'=>'held','initial_operation_key'=>'session:'.$r['actor_uid'].':'.$key,'item'=>$captured,'original_equipment'=>$original,'reservation'=>$reservation,'adopted_reservation'=>$adopted,'return_restrictions'=>['auto_restore_equipment'=>false,'reason_required'=>true],'obligations'=>['reservation_id'=>$reservation['reservation']['id'],'state'=>'held','pending_external_operations'=>[]],'permission'=>null];
                } else {
                    if (!$before || $before['status']!=='held') throw new RuntimeException('ACS_CUSTODY_NOT_HELD');
                    $p=AF_ACS_Custody::payload($before);$owner=$p['owner_uid'];$reader->fence($owner,(int)$this->access->uid());
                    $ownerApi->reservationEvidence($p['reservation']['reservation']['id'],$owner,['system'=>'acs','context_key'=>$sid],'acs-custody:'.$sid);
                    $affected[]=$p['active_user_entity_id'];
                    if ($operation==='assign') {$p['custodian_entity_id']=$r['custodian_entity_id'];$p['active_user_entity_id']=null;$p['permission']=null;}
                    elseif ($operation==='temporary_use') {
                        if ($p['custodian_entity_id']===null || $r['custodian_entity_id']!==$p['custodian_entity_id'] || !in_array($p['item']['kind'],['weapon','armor','gear','artifact'],true)) throw new RuntimeException('ACS_CUSTODY_TEMPORARY_USE_UNSUPPORTED');
                        foreach ($this->repo->itemEquipment($sid,$p['custodian_entity_id']) as $equipment) if ($equipment['slot_key']===$p['item']['slot']&&$equipment['status']==='active') throw new RuntimeException('ACS_CUSTODY_SLOT_CONFLICT');
                        foreach ($this->repo->custodyRows($sid) as $other) if ($other['status']==='held'&&(string)$other['id']!==(string)$before['id']&&(string)$other['active_user_entity_id']===$p['custodian_entity_id']&&AF_ACS_Custody::payload($other)['item']['slot']===$p['item']['slot']) throw new RuntimeException('ACS_CUSTODY_SLOT_CONFLICT');
                        $p['active_user_entity_id']=$p['custodian_entity_id'];$p['permission']=['kind'=>'gm','actor_uid'=>$r['actor_uid'],'operation_key'=>$key,'reason'=>$r['reason']];$affected[]=$p['active_user_entity_id'];
                    } else {
                        $request=$this->request('release','acs:custody:return:'.$r['actor_uid'].':'.$key,$sid,['reservation_id'=>$p['reservation']['reservation']['id'],'reason'=>$r['reason']]);
                        $release=$ownerApi->participate('release',$request)->serverReceipt();if ($release['status']!=='released') throw new RuntimeException('ACS_CUSTODY_RELEASE_FAILED');
                        $p['release_receipt']=$release;$p['active_user_entity_id']=null;$p['custodian_entity_id']=null;$p['status']='returned';$p['permission']=null;$p['obligations']['state']='released';
                    }
                }
                $saved=$this->repo->custodySave($session,$r,$before,$p,$affected);
                foreach ($this->repo->custodyEquipment($sid,$r['inventory_item_id']) as $eq) $affected[]=(string)$eq['entity_id'];
                foreach (array_unique(array_filter($affected)) as $actor) {
                    $entity=$this->repo->itemEntity($sid,$actor);if (!hash_equals($entity['content_hash'],hash('sha256',$entity['payload_json']))) throw new RuntimeException('ACS_CUSTODY_INTEGRITY');$registration=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);
                    AF_ACS_Custody::stats($registration,$this->repo->itemEquipment($sid,$actor),$this->repo->custodyRows($sid),$actor,$registration['source_snapshot']['characteristics']);
                }
                return $saved;
            });
        } catch (RuntimeException $error) {
            if (in_array($error->getMessage(),['ACS_TRANSACTION_ERROR','ACS_COMMIT_UNKNOWN','ACS_ROLLBACK_UNKNOWN','ACS_LOCK_RELEASE_UNKNOWN'],true)) throw new RuntimeException('ACS_RECOVERY_REQUIRED',0,$error);
            throw $error;
        }
    }
}
