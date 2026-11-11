<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Domain/Items.php';
require_once __DIR__.'/../Infrastructure/Adapters/EquipmentAdapter.php';
require_once __DIR__.'/../Infrastructure/Adapters/InventoryAdapter.php';
require_once __DIR__.'/../Infrastructure/Adapters/CombatInventoryAccess.php';
require_once __DIR__.'/../../../advancedinventory/modules/InventoryRead.php';

/** Explicit, authenticated preparation and same-writer owner participation. No HTTP payload grants. */
final class AF_ACS_ItemActionService
{
    private ?array $inventory=null;
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    public function catalog(string $sid,string $actor): array
    {
        global $db;
        $entity=$this->repo->itemEntity($sid,$actor);$uid=(int)$this->access->uid();
        if ((int)$entity['owner_uid']!==$uid || $entity['kind']!=='character') return [];
        $registration=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if (!isset($registration['equipment_snapshot'])) return [];
        (new AF_ACS_CombatInventoryAccess($db instanceof mysqli?$db:$db->write_link,$uid))->verifySelection($uid,$sid,$actor);
        $this->repo->assertReadConnection($db);$this->inventory=(new AF_ADVINV_InventoryRead($db))->read($uid,$uid)->serverData();
        $hasWeapon=false;foreach ($this->repo->itemEquipment($sid,$actor) as $slot) if ($slot['slot_key']==='weapon_mainhand'&&$slot['status']==='active') $hasWeapon=true;
        $choices=[];
        foreach ($this->inventory['items'] as $item) {
            if (!in_array($item['subtype'],['weapon','consumable'],true) || $item['entity']!=='equipment' || $item['kb_type']!=='arpg_item') continue;
            $operation=$item['subtype']==='consumable'?'consume':($hasWeapon?'swap':'equip');$key='item:'.$operation.':'.$item['id'];
            try {$this->prepare($sid,$actor,$key);$choices[]=['key'=>$key,'supported'=>true,'label'=>mb_substr(trim((string)($item['title']??''))?:$item['kb_key'],0,96).' #'.$item['id'].' · '.match($operation){'consume'=>'Использовать','swap'=>'Сменить оружие',default=>'Взять оружие'},'ap_cost'=>1,'item_action'=>true,'target'=>'self'];}
            catch (RuntimeException|DomainException $e) {continue;} // Unsupported sources never become choices.
        }
        return $choices;
    }
    public static function loadoutStats(AF_ACS_EncounterRepository $repo,string $sid,string $actor,array $registration,array $stats): array
    {
        return AF_ACS_Custody::stats($registration,$repo->itemEquipment($sid,$actor),$repo->custodyRows($sid),$actor,$stats);
    }

    public static function isItem(string $key): bool {return str_starts_with($key,'item:');}
    public static function request(string $operation,string $key,string $sid,string $entity,int $uid,array $payload): array
    {
        $r=['schema_version'=>1,'operation'=>$operation,'operation_key'=>$key,'actor'=>['uid'=>$uid,'authorization_ref'=>'acs-item:'.$sid.':'.$entity],'scope'=>['system'=>'acs','context_key'=>$sid],'payload'=>$payload];
        $r['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($r);return $r;
    }
    public function prepare(string $sid,string $actor,string $key,?string $draft=null,int $index=0): array
    {
        global $db;
        foreach (['advancedcombatsystem','advancedinventory','knowledgebase','charactersheets'] as $addon) if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled($addon)) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        require_once __DIR__.'/../../../charactersheets/modules/combat_snapshot.php'; // Read-only definition helpers, never rebuild a sheet.
        foreach (['af_kb_arpg_acs_source','af_inv_get_items','af_inv_candidate_slots_for_item'] as $helper) if (!function_exists($helper)) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        if (!preg_match('/\Aitem:(consume|equip|swap|pass):([1-9][0-9]{0,9})\z/D',$key,$m)) throw new RuntimeException('ACS_ITEM_ACTION_UNSUPPORTED');
        if ($m[1]==='pass') throw new RuntimeException('ACS_ITEM_PASS_SPATIAL_UNSUPPORTED');
        $entity=$this->repo->itemEntity($sid,$actor);$uid=(int)$this->access->uid();
        if ((int)$entity['owner_uid']!==$uid || $entity['kind']!=='character' || !hash_equals($entity['content_hash'],hash('sha256',$entity['payload_json']))) throw new RuntimeException('ACS_ITEM_SOURCE_UNAVAILABLE');
        (new AF_ACS_CombatInventoryAccess($db instanceof mysqli?$db:$db->write_link,$uid))->verifySelection($uid,$sid,$actor);
        $registration=json_decode($entity['payload_json'],true,64,JSON_THROW_ON_ERROR);$cs=$registration['source_snapshot'];$bundle=$registration['equipment_snapshot']??null;
        if (!is_array($bundle)) throw new RuntimeException('ACS_ITEM_PROVENANCE_REQUIRED');
        AF_ACS_Equipment::baseline($bundle,$cs);
        $this->repo->assertReadConnection($db);$read=$this->inventory??=(new AF_ADVINV_InventoryRead($db))->read($uid,$uid)->serverData();
        $id=(int)$m[2];$item=null;foreach ($read['items'] as $row) if ((int)$row['id']===$id) $item=$row;
        if (!$item || $item['entity']!=='equipment' || $item['kb_type']!=='arpg_item' || !empty(af_advinv_resolve_appearance_item($item)['is_visual_item'])) throw new RuntimeException('ACS_ITEM_INSUFFICIENT_QTY');
        $held=AF_ACS_Custody::held($this->repo->custodyRows($sid));
        if (isset($held[(string)$item['id']])) throw new RuntimeException('ACS_CUSTODY_ITEM_UNAVAILABLE');
        if ($m[1]!=='consume') foreach ($held as $row) if ((string)$row['active_user_entity_id']===$actor&&AF_ACS_Custody::payload($row)['item']['slot']==='weapon_mainhand') throw new RuntimeException('ACS_CUSTODY_SLOT_CONFLICT');
        if ($m[1]==='consume' && $item['subtype']!=='consumable') throw new RuntimeException('ACS_ITEM_EFFECT_UNSUPPORTED');
        if ($m[1]!=='consume' && $item['subtype']!=='weapon') throw new RuntimeException('ACS_ITEM_EQUIP_DENIED');
        $issues=[];$reads=[];$definition=af_cs_snapshot_definition('arpg_item',$item['kb_key'],$reads,$issues);
        if (!$definition || array_filter($issues,fn($i)=>($i['severity']??'error')==='error')) throw new RuntimeException('ACS_ITEM_DEFINITION_UNAVAILABLE');
        $rules=$definition['rules'];
        if (($rules['schema']??null)!==AF_KB_ARPG_RULES_SCHEMA || ($rules['type_profile']??null)!=='item' || ($rules['item']['item_kind']??null)!==$item['subtype']) throw new RuntimeException('ACS_ITEM_DEFINITION_UNAVAILABLE');
        if (isset($rules['action_cost']) && $rules['action_cost']!==1) throw new RuntimeException('ACS_ITEM_RULE_UNSUPPORTED');
        foreach (['application_conditions','equip_requirements','costs','consumption_type','use_ability_ref','conditions','triggers','timing','requirements','targeting','target_count','application_mode','usage_limits','summon','formula_refs','formula_ref','formula_profile','range','cast_time','cooldown'] as $field) if (!empty($rules[$field]) || !empty($rules['item'][$field])) throw new RuntimeException('ACS_ITEM_RULE_UNSUPPORTED');
        $pin=['key'=>$item['kb_key'],'version'=>$definition['version']['declared_version'],'fingerprint'=>$definition['version']['fingerprint']];
        if (!is_string($pin['version']) || $pin['version']==='') throw new RuntimeException('ACS_ITEM_DEFINITION_UNAVAILABLE');
        $mechanical=array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json']));
        $fingerprint=AF_ACS_Equipment::fingerprint($mechanical);$reserved=0;
        foreach ($read['reservations'] as $r) if ((int)$r['item_id']===$id) $reserved+=(int)$r['remaining_qty'];
        $available=(int)$item['qty']-$reserved;$binding=null;$own=0;$pinnedSpec=null;
        $active=$draft?$this->repo->sealRows($draft,true):[];
        foreach ($active as $position=>$sealed) {
            $p=$this->repo->sealPayload($sealed);$snapshot=$this->repo->itemSealDefinition($sid,$sealed);$saved=$snapshot['item_action']??null;
            if (!$saved || $saved['item_id']!==$id) continue;
            if ($saved['item_fingerprint']!==$fingerprint || $saved['pin']!==$pin) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            $reservation=$saved['reservation']??null;
            if (!$reservation) throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $r=null;foreach ($read['reservations'] as $candidate) if ((string)$candidate['id']===$reservation['reservation']['id']) $r=$candidate;
            if (!$r || $r['context_system']!=='acs' || $r['context_key']!==$sid || (int)$r['owner_uid']!==$uid || (int)$r['remaining_qty']!==1 || (int)$r['reservation_version']!==$reservation['reservation']['version']) throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            $own++;
            if ($position===$index) {$binding=$reservation;$pinnedSpec=$saved;}
        }
        if ($available<0) throw new RuntimeException('ACS_ITEM_INSUFFICIENT_QTY');
        $virtual=['items'=>[$id=>['fingerprint'=>$fingerprint,'available'=>$available+$own]],'equipment'=>[$actor=>[]]];$versions=[];$initial=[];$current=[];$outgoing=null;$oldReservation=null;
        foreach ($this->repo->itemEquipment($sid,$actor) as $equipment) {
            if (!hash_equals($equipment['content_hash'],hash('sha256',$equipment['payload_json']))) throw new RuntimeException('ACS_ITEM_PROVENANCE_REQUIRED');
            $versions[$equipment['slot_key']]=(int)$equipment['equipment_version'];
            if ($equipment['status']!=='active') continue;
            $record=json_decode($equipment['payload_json'],true,64,JSON_THROW_ON_ERROR);$spec=$record['item']??null;
            $bonuses=$equipment['slot_key']==='weapon_mainhand'?($spec['bonuses']??AF_ACS_Items::bonuses($record['mechanical_properties']??[])):[];
            $virtual['equipment'][$actor][$equipment['slot_key']]=['item_id'=>(int)$equipment['inventory_item_id'],'bonuses'=>$bonuses];
            $versions[$equipment['slot_key']]=(int)$equipment['equipment_version'];
            if ($equipment['slot_key']==='weapon_mainhand' && $spec!==null) {
                $ref=$spec['reservation']['reservation']??null;$valid=false;
                foreach ($read['reservations'] as $r) if ($ref && (string)$r['id']===$ref['id'] && (int)$r['item_id']===(int)$equipment['inventory_item_id'] && (int)$r['owner_uid']===$uid && $r['context_system']==='acs' && $r['context_key']===$sid && (int)$r['remaining_qty']===1 && (int)$r['reservation_version']===$ref['version']) $valid=true;
                if (!$valid) throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            }
            if ($equipment['slot_key']==='weapon_mainhand') {$current=$bonuses;$outgoing=(int)$equipment['inventory_item_id'];$oldReservation=$spec['reservation']??null;}
        }
        $stats=self::loadoutStats($this->repo,$sid,$actor,$registration,$cs['characteristics']);
        $spec=['schema_version'=>1,'operation'=>$m[1],'item_id'=>$id,'owner_uid'=>$uid,'qty'=>1,'item_fingerprint'=>$fingerprint,'pin'=>$pin,'subtype'=>$item['subtype'],'slot'=>'weapon_mainhand','equipment_version'=>$versions['weapon_mainhand']??0,'snapshot_id'=>(string)($pinnedSpec['snapshot_id']??$definition['entry']['id']),'bonuses'=>[],'effects'=>[],'reservation'=>$binding];
        if ($m[1]==='consume') {
            $use=$rules['item']['on_use']??null;
            if (!empty($rules['base_stats']) || !empty($rules['modifiers']) || !empty($rules['math_modifiers']) || $item['subtype']!=='consumable' || !is_array($use) || array_diff(array_keys($use),['cooldown','cost','effects']) || !empty($use['cooldown']) || !empty($use['cost']) || empty($use['effects'])) throw new RuntimeException('ACS_ITEM_EFFECT_UNSUPPORTED');
            foreach ($use['effects'] as $effect) if (($effect['op']??null)!=='add_hp' || !is_int($effect['value']??null) || $effect['value']<1 || array_diff(array_keys($effect),['op','value'])) throw new RuntimeException('ACS_ITEM_EFFECT_UNSUPPORTED');
            $spec['effects']=$use['effects'];
        } else {
            if (!empty($rules['slot_restrictions']) && (!is_array($rules['slot_restrictions']) || !array_is_list($rules['slot_restrictions']) || !in_array('weapon_mainhand',$rules['slot_restrictions'],true))) throw new RuntimeException('ACS_ITEM_EQUIP_DENIED');
            $candidate=$item;$candidate['meta_json']=$definition['entry']['meta_json'];
            if ($item['subtype']!=='weapon' || !in_array('weapon_mainhand',af_inv_candidate_slots_for_item($candidate),true)) throw new RuntimeException('ACS_ITEM_EQUIP_DENIED');
            if ($outgoing===$id || $outgoing!==null && $m[1]==='equip' || $outgoing===null && $m[1]==='swap') throw new RuntimeException('ACS_ITEM_EQUIP_CONFLICT');
            if ($outgoing!==null) {
                $found=false;foreach ($read['items'] as $owned) if ((int)$owned['id']===$outgoing && (int)$owned['uid']===$uid) $found=true;
                if (!$found) throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
                $spec['outgoing_item_id']=$outgoing;$spec['outgoing_reservation']=$oldReservation;
            }
            $spec['bonuses']=AF_ACS_Items::bonuses($rules);
            foreach ($cs['source_breakdown'] as $trace) if (isset($spec['bonuses'][$trace['stat_key']??'']) && isset($trace['before'],$trace['after']) && (($trace['operation']??null)!=='flat' || abs(($trace['after']-$trace['before'])-$trace['value'])>1e-9)) throw new RuntimeException('ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED');
        }
        if ($available+$own<1) throw new RuntimeException('ACS_ITEM_INSUFFICIENT_QTY');
        if ($m[1]!=='consume' && $outgoing!==null && !$oldReservation) foreach ($active as $seal) {
            $previous=$this->repo->itemSealDefinition($sid,$seal)['item_action']??null;
            if (($previous['outgoing_item_id']??null)===$outgoing && isset($previous['outgoing_reservation'])) $spec['outgoing_reservation']=$previous['outgoing_reservation'];
        }
        $dto=['definition_version'=>$definition['version'],'source_definition'=>$rules,'item_action'=>$spec];
        return ['ability'=>['supported'=>true,'source_ref'=>$pin,'costs'=>[['kind'=>'ap','amount'=>1]],'item_action'=>true], 'definition'=>$dto,'spec'=>$spec,'virtual'=>$virtual,'stats'=>$stats,
            'versions'=>['inventory_version'=>$read['inventory_version'],'item_fingerprint'=>$fingerprint,'equipment_versions'=>$versions,'reservation_hash'=>$binding?hash('sha256',AF_ADVINV_OwnerProtocol::canonical($binding)):null]];
    }
    public function equipFree(array $input): array
    {
        global $db;
        $this->access->csrf();$this->inventory=null;
        if (array_diff(array_keys($input),['session_id','entity_id','ability_key','expected_version','operation_key'])) throw new RuntimeException('ACS_ITEM_ACTION_UNSUPPORTED');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$actor=AF_ACS_Repository::integer($input['entity_id']??null);
        $key=(string)($input['operation_key']??'');if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key)) throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$sid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'entity_id'=>$actor,'ability_key'=>(string)($input['ability_key']??'')];
        return $this->repo->command('session:'.$this->access->uid().':'.$key,'item.equip',$request,
            function() use ($sid) { $view=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);if (!$view['can_register_own']) throw new RuntimeException('ACS_ITEM_SOURCE_UNAVAILABLE'); },
            function($session) use ($sid,$actor,$key,$request,$db) {
                $spec=$this->prepare($sid,$actor,$request['ability_key'])['spec'];
                if (!in_array($spec['operation'],['equip','swap'],true)) throw new RuntimeException('ACS_ITEM_FREE_EQUIP_IN_COMBAT');
                if (isset($spec['outgoing_item_id']) && !isset($spec['outgoing_reservation'])) $spec['outgoing_reservation']=$this->reserve(['item_id'=>$spec['outgoing_item_id']],$sid,$actor,'acs:item:free:'.$actor.':'.$key.':old');
                $spec['reservation']=$this->reserve($spec,$sid,$actor,'acs:item:free:'.$actor.':'.$key);
                $saved=$this->repo->freeItemEquipment($session,$actor,$spec);
                if (isset($spec['outgoing_reservation'])) {
                    $r=self::request('release','acs:item:free:'.$actor.':'.$key.':release',$sid,$actor,(int)$this->access->uid(),['reservation_id'=>$spec['outgoing_reservation']['reservation']['id'],'reason'=>'free_loadout_replaced']);
                    if (AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid())->participate('release',$r)->serverReceipt()['status']!=='released') throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');
                }
                return $saved;
            });
    }
    public function cancel(array $draft,array $seal,bool $last): void
    {
        global $db;
        $spec=$this->repo->itemSealDefinition($draft['session_id'],$seal)['item_action']??null;
        if (!$spec) return;
        $requests=[$spec['reservation']];
        $keepOutgoing=false;
        foreach ($this->repo->sealRows($draft['id'],true) as $remaining) {
            $other=$this->repo->itemSealDefinition($draft['session_id'],$remaining)['item_action']??null;
            if (isset($other['outgoing_reservation']) && ($other['outgoing_reservation']['reservation']['id']??null)===($spec['outgoing_reservation']['reservation']['id']??null)) $keepOutgoing=true;
        }
        if (!$keepOutgoing && isset($spec['outgoing_reservation'])) $requests[]=$spec['outgoing_reservation'];
        $actor=(string)$seal['actor_entity_id'];$adapter=AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid());
        foreach ($requests as $reserve) {
            $request=self::request('release','acs:item:cancel:'.$seal['id'].':'.$reserve['reservation']['id'],$draft['session_id'],$actor,(int)$this->access->uid(),['reservation_id'=>$reserve['reservation']['id'],'reason'=>'author_cancel_tail']);
            $r=$adapter->participate('release',$request)->serverReceipt();
            if ($r['status']!=='released') throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');
        }
    }
    public function reserve(array $spec,string $sid,string $actor,string $key): array
    {
        global $db;
        $reader=new AF_ADVINV_InventoryRead($db);$uid=(int)$this->access->uid();$reader->fence($uid,$uid);
        $adapter=AF_ACS_InventoryAdapter::forCombat($db,$uid);
        $known=$adapter->lookupParticipant(self::request('lookup',$key.':lookup',$sid,$actor,$uid,['target_operation_key'=>$key]));
        if ($known['status']!=='not_found') {
            if ($known['status']!=='prepared' || $known['item_id']!==$spec['item_id'] || $known['qty']!==1) throw new RuntimeException('ACS_ITEM_RESERVATION_REQUIRED');
            return $known;
        }
        $request=self::request('reserve',$key,$sid,$actor,$uid,['owner_uid'=>$uid,'item_id'=>$spec['item_id'],'qty'=>1,'session_id'=>$sid,'expected_inventory_version'=>$reader->version($uid,$uid)]);
        $result=AF_ACS_InventoryAdapter::forCombat($db,$uid)->participate('reserve',$request)->serverReceipt();
        if ($result['status']!=='prepared') throw new RuntimeException('ACS_ITEM_RESERVATION_REJECTED');
        return $result;
    }
}
