<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/RecoveryQueueQuery.php';
require_once __DIR__.'/EncounterRepository.php';
require_once __DIR__.'/Adapters/CustodyInventoryAccess.php';
require_once __DIR__.'/../Domain/InventoryReceipt.php';
/** Explicit GM panel only. Owner DTOs are bounded; no raw inventory or hidden character snapshot. */
final class AF_ACS_InventoryObligationsQuery extends AF_ACS_Repository
{
    public function page(string $sid,AF_ACS_SessionAccess $access,string $after='0',string $opAfter='',string $resAfter='0'): array
    {
        $sid=self::integer($sid);$context=(new AF_ACS_RecoveryQueueQuery($this->connection))->context($sid,$access);$after=self::integer($after,zero:true);$resAfter=self::integer($resAfter,zero:true);
        if ($opAfter!=='') self::key($opAfter);
        $session=$this->session($sid);$roles=$this->sql('SELECT role FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?)',[$sid,$access->uid(),time()])['rows'];
        if (!in_array('gm',array_column($roles,'role'),true)&&((string)$session['owner_uid']!==$access->uid()||!in_array('owner',array_column($roles,'role'),true))) throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
        $rows=$this->sql('SELECT * FROM '.$this->table('custody').' WHERE session_id=? AND id>? ORDER BY id LIMIT 21',[$sid,$after])['rows'];$more=count($rows)>20;if($more)array_pop($rows);$items=[];
        $owner=null;$inventory='unavailable';$reservations=['items'=>[],'next_after'=>null];
        try {
            if (!af_is_addon_enabled('advancedinventory')) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
            $owner=new AF_ADVINV_OwnerProtocol($this->connection,new AF_ACS_CustodyInventoryAccess($this->connection,$sid,(int)$access->uid()));
            $reservations=$owner->contextReservations(['system'=>'acs','context_key'=>$sid],'acs-custody:'.$sid,$resAfter);$inventory='available';
        } catch (Throwable $error) {$owner=null;}
        $pending=$this->sql('SELECT operation_key FROM '.$this->table('operations')." WHERE session_id=? AND external_system='advancedinventory' AND status IN ('prepared','recovery_required') LIMIT 1",[$sid])['rows'];
        $publication=$this->sql('SELECT submission_id FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') LIMIT 1",[$sid])['rows'];
        $sealed=$this->sql('SELECT a.id FROM '.$this->table('sealed_actions').' a JOIN '.$this->table('drafts').' d ON d.id=a.draft_id JOIN '.$this->table('encounters')." e ON e.id=d.encounter_id WHERE e.session_id=? AND a.status='sealed' LIMIT 1",[$sid])['rows'];
        foreach ($rows as $row) {
            $p=AF_ACS_Custody::payload($row);$reservation=['reservation_id'=>$p['reservation']['reservation']['id'],'status'=>'unverified'];
            if ($owner) try {$reservation=$owner->contextReservation(['system'=>'acs','context_key'=>$sid],'acs-custody:'.$sid,$reservation['reservation_id']);} catch (Throwable $error) {$reservation['status']='recovery_required';}
            $conflict=false;try {$this->assertInventorySettled($sid,[(string)$row['inventory_item_id']]);} catch (RuntimeException $error) {if ($error->getMessage()!=='ACS_INVENTORY_RECOVERY_REQUIRED') throw $error;$conflict=true;}
            $commands=[];if (!$conflict&&!$pending&&!$publication&&!$sealed&&$reservation['status']==='active'&&$row['status']==='held') {
                $commands=['return'];if ($session['status']==='active') {$commands[]='assign';if ($row['custodian_entity_id']!==null)$commands[]='temporary_use';}
            }
            $items[]=['custody_id'=>(string)$row['id'],'item_id'=>(string)$row['inventory_item_id'],'owner_uid'=>(string)$row['owner_uid'],'custodian_entity_id'=>$row['custodian_entity_id']===null?null:(string)$row['custodian_entity_id'],'active_user_entity_id'=>$row['active_user_entity_id']===null?null:(string)$row['active_user_entity_id'],'status'=>$row['status'],'custody_version'=>(string)$row['custody_version'],'reservation'=>$reservation,'obligations'=>$p['obligations'],'available_commands'=>$commands];
        }
        $ops=$this->sql('SELECT operation_key,kind,status,operation_version,request_json,payload_hash,result_json FROM '.$this->table('operations')." WHERE session_id=? AND external_system='advancedinventory' AND operation_key>? ORDER BY operation_key LIMIT 21",[$sid,$opAfter])['rows'];$opMore=count($ops)>20;if($opMore)array_pop($ops);$operations=[];
        foreach ($ops as $op) {
            if (!hash_equals($op['payload_hash'],hash('sha256',$op['request_json']))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
            $intent=json_decode($op['request_json'],true,64,JSON_THROW_ON_ERROR);
            if (!hash_equals($intent['payload_hash'],AF_ADVINV_OwnerProtocol::payloadHash($intent))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
            $receipt=null;if(in_array($op['status'],['committed','rejected','released'],true)) {$receipt=json_decode($op['result_json'],true,64,JSON_THROW_ON_ERROR)['owner_receipt']??[];AF_ACS_InventoryReceipt::verify($intent,$receipt);if(AF_ACS_InventoryReceipt::status($receipt)!==$op['status'])throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');}
            $operations[]=['owner_uid'=>(string)($intent['payload']['from_uid']??$intent['payload']['owner_uid']??$intent['payload']['recipient_uid']??''),'item_id'=>isset($intent['payload']['item_id'])?(string)$intent['payload']['item_id']:(isset($receipt['item_id'])?(string)$receipt['item_id']:null),'operation_key'=>$op['operation_key'],'kind'=>$op['kind'],'status'=>$op['status'],'operation_version'=>(string)$op['operation_version'],'payload_hash'=>$intent['payload_hash'],'actor_uid'=>(string)$intent['actor']['uid'],'can_lookup'=>(string)$intent['actor']['uid']===$access->uid()&&in_array($op['status'],['prepared','recovery_required'],true)];
        }
        $preparedReleases=$this->sql('SELECT operation_key,status,operation_version,request_json FROM '.$this->table('operations')." WHERE session_id=? AND kind='prepared.release' AND status='recovery_required' ORDER BY operation_key LIMIT 100",[$sid])['rows'];$prepared=[];foreach($preparedReleases as $op){$p=json_decode($op['request_json'],true,64,JSON_THROW_ON_ERROR);$prepared[]=['prepared_action_id'=>$p['prepared_action_id'],'cost_index'=>$p['cost_index'],'operation_key'=>$op['operation_key'],'status'=>$op['status']];}
        return $context+['prepared_releases'=>$prepared]+['session_status'=>$session['status'],'session_version'=>(string)$session['session_version'],'inventory'=>$inventory,'items'=>$items,'operations'=>$operations,'reservations'=>$reservations['items'],'next_after'=>$more?(string)end($rows)['id']:null,'next_operation_after'=>$opMore?end($ops)['operation_key']:null,'next_reservation_after'=>$reservations['next_after'],'can_confiscate'=>$session['status']==='active'&&$inventory==='available'&&!$pending&&!$publication&&!$sealed];
    }
}
