<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../../../../advancedinventory/modules/OwnerProtocol.php';
require_once __DIR__.'/../EncounterRepository.php';

/** Owner-side IC-INV-1 composition. Current consent AND the owner's spending gate are required.
 * Reads only: no owner→ACS lock inversion, native callbacks, transfer or grant authority.
 */
final class AF_ACS_CombatInventoryAccess implements AF_ADVINV_PreparedOwnerPolicy
{
    public function __construct(private mysqli $db,private int $actor,private ?array $readContext=null) {}
    public function uid(): int
    {
        global $mybb;
        if ($this->actor<1 || $this->actor!==(int)($mybb->user['uid']??0) || empty($mybb->usergroup['canview']) || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('INV_ACCESS_DENIED');
        return $this->actor;
    }
    public function authorize(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation): void
    {
        if($this->readContext!==null && $operation==='lookup' && !$mutation && $scope===['system'=>'inventory','context_key'=>'owner:'.$ownerUid] && $ref==='inventory-owner-v1'){
            [$sid,$entity,$category]=$this->readContext;$this->verifySelection($ownerUid,$sid,$entity,$category);return;
        }
        $this->decision($operation,$ownerUid,$scope,$ref,$mutation,true);
    }
    public function verifySelection(int $ownerUid,string $sid,string $entity,string $category='ordinary'): void
    {
        $this->decision('reserve',$ownerUid,['system'=>'acs','context_key'=>$sid],'acs-item:'.$sid.':'.$entity.($category==='ordinary'?'':':'.$category),true,false);
    }
    private function decision(string $operation,int $ownerUid,array $scope,string $ref,bool $mutation,bool $checkPost): void
    {
        global $mybb;$uid=$this->uid();
        if (($scope['system']??null)!=='acs' || !preg_match('/\Aacs-item:([1-9][0-9]*):([1-9][0-9]*)(?::(item_consume|equipment_change))?\z/D',$ref,$m) || $scope['context_key']!==$m[1] || !in_array($operation,['lookup','reserve','consume','release'],true)) throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $repo=new AF_ACS_EncounterRepository($this->db);$entity=$repo->entity($m[1],$m[2]);$session=$repo->session($m[1]);
        // IC-INV-1 uses the authenticated actor as the owner placeholder for a missing lookup.
        // Existing receipts still pass samePrincipal(actor/context) in OwnerProtocol.
        if($entity && $operation==='lookup' && !$mutation && $ownerUid===$uid && isset($m[3]))$ownerUid=(int)$entity['owner_uid'];
        if(!$entity || !$session || $entity['kind']!=='character' || (int)$entity['owner_uid']!==$ownerUid || !$repo->roles($m[1],(string)$uid) || !$repo->roles($m[1],(string)$ownerUid))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $category=$m[3]??'ordinary';
        if($ownerUid!==$uid && ($category==='ordinary' || $operation==='consume' && $category!=='item_consume' || !$repo->controllerFor($m[1],$m[2],(string)$uid,$category)))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        if(!$mutation)return;
        // The grant does not replace the owner's independent inventory_spending_enabled permission.
        $controller=$repo->controllerFor($m[1],$m[2],(string)$ownerUid);$permission=json_decode($controller['permissions_json']??'{}',true,16,JSON_THROW_ON_ERROR);
        if($checkPost && ($mybb->request_method??'')!=='post' || $session['status']!=='active' || !$controller
            || ($permission['schema_version']??null)!==1 || ($permission['combat_operations_enabled']??null)!==true || ($permission['inventory_spending_enabled']??null)!==true || $operation!=='release' && $entity['life_state']!=='active')throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
    }
    /** Owner's independent live grant; never evaluates the trigger actor as that owner. */
    public function preparedGrantGeneration(array $b):string
    {
        $repo=new AF_ACS_EncounterRepository($this->db);$e=$repo->entity($b['session_id'],$b['entity_id']);$session=$repo->session($b['session_id']);
        $roles=$repo->roles($b['session_id'],(string)$b['owner_uid']);$c=$repo->controllerFor($b['session_id'],$b['entity_id'],(string)$b['owner_uid']);$p=json_decode($c['permissions_json']??'{}',true,16,JSON_THROW_ON_ERROR);
        if(!$e||$e['kind']!=='character'||$e['life_state']!=='active'||(int)$e['owner_uid']!==$b['owner_uid']||$b['originating_uid']!==$b['owner_uid']||!$session||$session['status']!=='active'||!$roles||!$c||isset($c['control_overlay'])||($p['schema_version']??null)!==1||($p['combat_operations_enabled']??false)!==true||($p['inventory_spending_enabled']??false)!==true)throw new RuntimeException('INV_PREPARED_REVOKED');
        $st=$this->db->prepare('SELECT uid,role,role_version,expires_at FROM `'.TABLE_PREFIX.'af_acs_session_roles` WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?) ORDER BY role');$now=time();$st->bind_param('sii',$b['session_id'],$b['owner_uid'],$now);$st->execute();$roles=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        return hash('sha256',AF_ADVINV_OwnerProtocol::canonical(['owner_uid'=>$b['owner_uid'],'entity_id'=>$b['entity_id'],'controller_id'=>$c['id'],'assignment_version'=>$c['assignment_version'],'permissions'=>$p,'roles'=>$roles]));
    }
    public function authorizePreparedObligation(array $b,array $intent,bool $history):void
    {
        global $mybb;$uid=$this->uid();
        if(($mybb->request_method??'')!=='post'||$intent['actor']['uid']!==$uid||!preg_match('/\Aacs-prepared:([1-9][0-9]*):([1-9][0-9]*):([0-9]+)\z/D',$intent['actor']['authorization_ref'],$m))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $one=function(string $sql,array $args):?array{$s=$this->db->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r;};$prefix=TABLE_PREFIX.'af_acs_';
        $row=$one('SELECT p.*,e.actor_uid AS originating_uid,e.event_type AS source_event_type,e.session_id AS source_session,e.encounter_id AS source_encounter,e.source_entity_id AS source_event_entity FROM `'.$prefix.'prepared_actions` p JOIN `'.$prefix.'events` e ON e.id=p.source_event_id WHERE p.id=?',[$m[1]]);
        $execution=$one('SELECT x.*,e.submission_id,e.operation_key AS trigger_key,e.target_entity_id,e.source_entity_id,e.event_type,e.session_id AS trigger_session,e.encounter_id AS trigger_encounter,d.payload_json AS trigger_payload,d.visibility_json AS trigger_visibility,d.content_hash AS trigger_hash,s.sealed_json,s.author_uid,s.status AS publication_status FROM `'.$prefix.'prepared_executions` x JOIN `'.$prefix.'events` e ON e.id=x.trigger_event_id JOIN `'.$prefix.'event_details` d ON d.event_id=e.id JOIN `'.$prefix.'submissions` s ON s.submission_id=e.submission_id WHERE x.prepared_action_id=? AND x.trigger_event_id=?',[$m[1],$m[2]]);
        if(!$row||!$execution||(int)$execution['author_uid']!==$uid||!in_array($execution['publication_status'],$history?['post_confirmed','committing','committed','recovery_required']:['post_confirmed','committing'],true)||!in_array($execution['status'],$history?['armed','executed','skipped']:['armed'],true))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        $repo=new AF_ACS_EncounterRepository($this->db);if(!$repo->roles($b['session_id'],(string)$uid))throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        if($row['source_event_type']!=='encounter.prepared_register'||(string)$row['source_session']!==$b['session_id']||(string)$row['source_encounter']!==$b['encounter_id']||(string)$row['source_event_entity']!==$b['entity_id']||$execution['event_type']!=='prepared.trigger_sealed'||(string)$execution['trigger_session']!==$b['session_id']||(string)$execution['trigger_encounter']!==$b['encounter_id']||!hash_equals($execution['trigger_hash'],hash('sha256',$execution['trigger_payload']."\n".$execution['trigger_visibility']))||(int)$row['originating_uid']!==$b['originating_uid']||(int)$row['owner_uid']!==$b['owner_uid']||(string)$row['id']!==$b['prepared_action_id']||(string)$row['session_id']!==$b['session_id']||(string)$row['encounter_id']!==$b['encounter_id']||(string)$row['entity_id']!==$b['entity_id']||(string)$row['source_sealed_action_id']!==$b['source_sealed_action_id']||(string)$execution['target_entity_id']!==$b['entity_id']||!hash_equals($row['contract_hash'],hash('sha256',$row['contract_json'])))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $contract=json_decode($row['contract_json'],true,64,JSON_THROW_ON_ERROR);$permit=$contract['owner_obligations'][$b['cost_index']]??null;unset($contract['owner_obligations']);
        if(!$permit||$m[1]!==$b['prepared_action_id']||$m[3]!==((string)$b['cost_index'])||AF_ADVINV_OwnerProtocol::canonical($intent['scope'])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','context_key'=>$b['session_id']])||!hash_equals($permit['binding_hash'],hash('sha256',AF_ADVINV_OwnerProtocol::canonical($b)))||AF_ADVINV_OwnerProtocol::canonical($intent['payload'])!==AF_ADVINV_OwnerProtocol::canonical(['reservation_id'=>$b['reservation_id'],'qty'=>$b['qty'],'expected_version'=>$b['reservation_version']])||$intent['operation_key']!=='acs:prepared:trigger-consume:'.$m[1].':'.$m[2].':'.$m[3]||$permit['grant_generation']!==$b['grant_generation']||$permit['reservation_id']!==$b['reservation_id']||!hash_equals($b['obligation_hash'],hash('sha256',AF_ACS_Repository::json($contract))))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $cost=$contract['additional_costs'][$b['cost_index']]??[];$match=null;foreach($contract['item_bindings']??[] as $binding)if($binding['cost_index']===$b['cost_index'])$match=$binding;
        if(!$match||$cost['kind']!=='item'||$cost['timing']!=='on_trigger'||$cost['key']!==$b['cost_key']||$cost['amount']!==$b['qty']||AF_ADVINV_OwnerProtocol::canonical($cost['definition'])!==AF_ADVINV_OwnerProtocol::canonical($b['definition'])||AF_ADVINV_OwnerProtocol::canonical($contract['ability_pin'])!==AF_ADVINV_OwnerProtocol::canonical($b['ability_pin'])||$match['item_id']!==$b['item_id']||$match['qty']!==$b['qty']||AF_ADVINV_OwnerProtocol::canonical($match['reserve_receipt'])!==AF_ADVINV_OwnerProtocol::canonical($b['reserve_receipt'])||$match['item_fingerprint']!==$b['item_fingerprint'])throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $armed=json_decode($execution['result_json'],true,64,JSON_THROW_ON_ERROR);
        if($armed['prepared_action_id']!==$b['prepared_action_id']||$armed['trigger_event_id']!==$m[2]||$armed['source_event_id']!==(string)$row['source_event_id']||$armed['prepared_source_sealed_action_id']!==$b['source_sealed_action_id']||$armed['identity']!==$execution['operation_key'])throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $frozen=json_decode($execution['sealed_json'],true,64,JSON_THROW_ON_ERROR);$seal=$frozen['seals'][$armed['action_index']]??null;
        $suffix=($armed['policy']['phase']??null)==='after_damage'?'':':phase:'.($armed['policy']['phase']??'unsupported');
        $triggerKey='prepared-trigger:'.hash('sha256',$execution['submission_id'].':'.$armed['source_sealed_action_id'].':'.$b['entity_id'].$suffix);
        if(!$seal||(string)$seal['id']!==$armed['source_sealed_action_id']||($seal['payload']['action']['actor_id']??null)!==(string)$execution['source_entity_id']||!in_array($b['entity_id'],$seal['payload']['action']['targets']??[],true)||$execution['trigger_key']!==$triggerKey||(!$history&&($armed['prepared_version']!==(string)$row['prepared_version']||$armed['contract_hash']!==$row['contract_hash'])))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if(!$history&&($row['status']!=='active'||$row['cost_state']!=='paid'))throw new RuntimeException('INV_PREPARED_EXPIRED');
    }

}
