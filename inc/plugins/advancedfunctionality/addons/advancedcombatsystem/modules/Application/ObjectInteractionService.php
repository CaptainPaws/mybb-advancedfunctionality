<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/ItemActionService.php';
require_once __DIR__.'/CooperationService.php';
require_once __DIR__.'/../Domain/ObjectInteraction.php';

/** Server selected object actions and owner tools; preview performs reads only. */
final class AF_ACS_ObjectInteractionService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    public function catalog(string $sid,string $eid,string $actor,?string $draft=null):array
    {
        $audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);$spatial=$this->repo->spatialProjection($sid,$eid);$gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];$out=[];
        $objects=array_filter($this->repo->objectProjection($sid,$eid),static fn($v)=>(!empty($v['definition']['interactions'])||!empty($v['definition']['cooperation']))&&$audience->knowledge('object',$v['entity_id'],$actor)['level']==='identified'&&AF_ACS_Spatial::disclosed($spatial,$v['entity_id'],$gm)&&AF_ACS_Spatial::disclosed($spatial,$actor,$gm));
        if(!$objects)return [];
        try{$inspection=(new AF_ACS_QueryService($this->repo,$this->access))->inspect($sid,$eid,$actor,$actor,$draft,true);}catch(Throwable $e){return [];}
        foreach($objects as $id=>$v){$id=(string)$id;$knowledge=$audience->knowledge('object',$id,$actor);if($knowledge['level']!=='identified')continue;$label=$knowledge['details']['name']??('Известный объект #'.$id);
            foreach($v['definition']['interactions']['actions']??[] as $a){$key='object:'.$id.':'.$a['key'];$choice=['key'=>$key,'label'=>$label.' · '.$a['label'],'object_label'=>$label,'category'=>'objects','object_interaction'=>true,'object_id'=>$id,'costs'=>$a['costs'],'supported'=>true,'target_policy'=>['kind'=>'object_only'],'legal_targets'=>[$id],'ap_cost'=>array_values(array_filter($a['costs'],fn($c)=>$c['kind']==='ap'))[0]['amount']];
                $index=-1;if($draft)foreach($this->repo->sealRows($draft,true) as $position=>$seal){$wire=$this->repo->sealPayload($seal)['action'];if($wire['actor_id']===$actor&&$wire['ability_key']===$key){$index=$position;break;}}
                try{AF_ACS_ObjectInteraction::eligible($v,$a,$actor,$inspection['context']['reaction_kinds'][$actor],AF_ACS_Resolver::interactionActorStats($inspection['context'],$actor),$inspection['context']['abilities'][$actor]);if($a['tool']!==null)$this->tool($sid,$actor,$a['tool'],$draft,$index);}catch(Throwable $e){$choice['supported']=false;$choice['reason']=AF_ACS_BuilderService::message($e);}$out[]=$choice;
            }
        }return array_merge($out,(new AF_ACS_CooperationService($this->repo,$this->access))->catalog($sid,$eid,$actor,$inspection));
    }
    public function prepare(string $sid,string $eid,string $actor,string $key,?string $draft=null,int $index=0):array
    {
        [$id,$actionKey]=AF_ACS_ObjectInteraction::selection($key);$audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        $spatial=$this->repo->spatialProjection($sid,$eid);$gm=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'];if(!AF_ACS_Spatial::disclosed($spatial,$id,$gm)||!AF_ACS_Spatial::disclosed($spatial,$actor,$gm)||$audience->knowledge('object',$id,$actor)['level']!=='identified')throw new RuntimeException('preview_target_forbidden');
        $v=$this->repo->objectProjection($sid,$eid)[$id]??throw new RuntimeException('preview_target_forbidden');if(isset($v['definition']['cooperation'])&&str_starts_with($actionKey,'coop-'))return (new AF_ACS_CooperationService($this->repo,$this->access))->prepare($sid,$eid,$actor,$key,$draft);$a=AF_ACS_ObjectInteraction::action($v,$actionKey);
        $p=(new AF_ACS_QueryService($this->repo,$this->access))->inspect($sid,$eid,$actor,$actor,$draft,true);$entity=$this->repo->entity($sid,$actor);
        AF_ACS_ObjectInteraction::eligible($v,$a,$actor,$entity['kind'],AF_ACS_Resolver::interactionActorStats($p['context'],$actor),$p['context']['abilities'][$actor]);
        $dto=$this->repo->objectDefinitionSnapshot($sid,$id);$pin=['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']];
        $tool=$a['tool']===null?null:$this->tool($sid,$actor,$a['tool'],$draft,$index);
        $binding=['entity_id'=>$id,'key'=>$actionKey,'object_version'=>$v['object_version'],'action'=>$a,'tool'=>$tool];
        $definition=['definition_version'=>$dto['definition_version'],'object_interaction'=>$binding];
        $p['context']['abilities'][$actor][$key]=['supported'=>true,'source_ref'=>$pin,'components'=>[],'costs'=>$a['costs'],'target_policy'=>['kind'=>'object_only'],'object_interaction'=>$binding];
        $p['choices'][$key]=['supported'=>true];$p['versions']['object_interaction']=['entity_id'=>$id,'object_version'=>$v['object_version'],'tool'=>$tool];
        $p['context']['behavior_targets'][$actor][$id]=['relationship'=>'neutral','kind'=>'object','active'=>true];$p['context']['reaction_kinds'][$id]='object';$p['context']['relationships'][$actor][$id]='neutral';
        return ['inspection'=>$p,'definition'=>$definition];
    }
    private function tool(string $sid,string $actor,array $required,?string $draft,int $index):array
    {
        global $db;
        if(!function_exists('af_is_addon_enabled')||!af_is_addon_enabled('advancedinventory'))throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        $this->repo->assertReadConnection($db);$this->repo->assertInventorySettled($sid);$entity=$this->repo->entity($sid,$actor);$uid=(int)$this->access->uid();
        // Bounded owner-only subset. A controller grant never grants another owner's tools.
        if($entity['kind']!=='character'||(int)$entity['owner_uid']!==$uid)throw new RuntimeException('ACS_INTERACTION_TOOL_UNAVAILABLE');
        if($required['mode']==='consumes')(new AF_ACS_CombatInventoryAccess($db instanceof mysqli?$db:$db->write_link,$uid))->verifySelection($uid,$sid,$actor,'ordinary');
        $read=(new AF_ADVINV_InventoryRead($db))->read($uid,$uid)->serverData();$saved=null;
        if($draft){$seals=$this->repo->sealRows($draft,true);if(isset($seals[$index]))$saved=$this->repo->itemSealDefinition($sid,$seals[$index])['object_interaction']['tool']??null;}
        $held=AF_ACS_Custody::held($this->repo->custodyRows($sid));
        foreach($read['items'] as $item){$id=(int)$item['id'];if($saved&&$saved['item_id']!==$id)continue;if($item['entity']!=='equipment'||!empty(af_advinv_resolve_appearance_item($item)['is_visual_item'])||$item['kb_type']!=='arpg_item'||$item['kb_key']!==$required['kb_key']||isset($held[(string)$id]))continue;
            if($required['mode']==='consumes'&&!in_array($item['subtype'],['consumable','ammo'],true))throw new RuntimeException('ACS_INTERACTION_UNSUPPORTED');
            $available=(int)$item['qty'];$own=false;foreach($read['reservations'] as $r)if((int)$r['item_id']===$id){$available-=(int)$r['remaining_qty'];$ref=$saved['reservation']['reservation']??null;if($ref&&(string)$r['id']===$ref['id']&&(int)$r['reservation_version']===$ref['version']&&(int)$r['remaining_qty']===1&&$r['context_system']==='acs'&&$r['context_key']===$sid)$own=true;}
            if($available+($own?1:0)<1)continue;$fingerprint=AF_ACS_Equipment::fingerprint(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json'])));
            if($saved&&($fingerprint!==$saved['item_fingerprint']||$required['mode']==='consumes'&&!$own))throw new RuntimeException('ACS_ITEM_DEPENDENCY_CONFLICT');
            return ['mode'=>$required['mode'],'operation'=>'consume','item_id'=>$id,'owner_uid'=>$uid,'qty'=>1,'item_fingerprint'=>$fingerprint,'inventory_version'=>$read['inventory_version'],'available'=>$available,'reservation'=>$saved['reservation']??null];
        }throw new RuntimeException('ACS_INTERACTION_TOOL_UNAVAILABLE');
    }
    public function cancel(array $draft,array $seal):void
    {
        global $db;$tool=$this->repo->itemSealDefinition($draft['session_id'],$seal)['object_interaction']['tool']??null;if(!$tool||$tool['mode']!=='consumes')return;
        $request=AF_ACS_ItemActionService::request('release','acs:tool:cancel:'.$seal['id'],$draft['session_id'],$seal['actor_entity_id'],(int)$this->access->uid(),['reservation_id'=>$tool['reservation']['reservation']['id'],'reason'=>'author_cancel_tail']);
        if(AF_ACS_InventoryAdapter::forCombat($db,(int)$this->access->uid())->participate('release',$request)->serverReceipt()['status']!=='released')throw new RuntimeException('ACS_ITEM_RELEASE_REJECTED');
    }
}
