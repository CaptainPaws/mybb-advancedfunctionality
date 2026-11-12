<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/EncounterService.php';
require_once __DIR__.'/AudienceService.php';
require_once __DIR__.'/../Domain/Resolver.php';
require_once __DIR__.'/../Infrastructure/Adapters/KnowledgeBaseAdapter.php';

/** Numeric preview remains GM-scoped; own builder catalogs expose no GM-only targets. */
final class AF_ACS_QueryService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private static function fail(string $code):never{throw new AF_ACS_FormulaError($code,'preview');}
    private static function int(mixed $n):int{return (int)AF_ACS_Repository::integer((string)$n,'9007199254740991',true);}
    private function source(string $sid,string $id):array
    {
        $e=$this->repo->entity($sid,$id);if(!$e||!hash_equals($e['content_hash'],hash('sha256',$e['payload_json'])))self::fail('preview_source_invalid');
        $p=AF_ACS_Repository::registrationView(json_decode($e['payload_json'],true,64,JSON_THROW_ON_ERROR));$s=$p['source_snapshot']??null;
        if(($p['contract_id']??null)!=='IC-ACS.entity-registration.v1'||($p['schema_version']??null)!==1||($p['contract_version']??null)!=='1'||($p['engine_version']??null)!=='acs-registration-1'||($p['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||!is_array($s)||($s['ready']??null)!==true||($p['equipment_policy']??null)!=='already_included_do_not_reapply')self::fail('preview_source_invalid');
        if(!in_array($e['kind'],['character','pet','npc','monster','summon'],true))self::fail('preview_source_invalid');
        if($e['kind']==='character'&&(($s['contract_id']??null)!=='IC-CS.combat-preparation.v1'||(string)($s['identity']['sheet_id']??'')!==(string)$e['sheet_id']))self::fail('preview_source_invalid');
        if(in_array($e['kind'],['npc','monster'],true)&&(($s['contract_id']??null)!=='IC-KB.read.v1'||($s['source']['type']??null)!=='arpg_bestiary'))self::fail('preview_source_invalid');
        if($e['group_session']!==$sid)self::fail('preview_source_invalid');
        if(in_array($e['kind'],['character','pet'],true)&&($e['owner_uid']===null||$e['group_owner']!==$e['owner_uid']||$e['group_kind']!=='player'||(string)($s['identity']['owner_uid']??'')!==$e['owner_uid']))self::fail('preview_source_invalid');
        if(in_array($e['kind'],['npc','monster'],true)&&($e['owner_uid']!==null||$e['group_owner']!==null||$e['group_kind']!=='independent'))self::fail('preview_source_invalid');
        if($e['kind']==='character'&&!af_is_addon_enabled('charactersheets'))self::fail('preview_dependency_unavailable');
        $actual=json_decode($e['stats_json'],true,32,JSON_THROW_ON_ERROR);
        if($actual!==['schema_version'=>1,'values'=>$s['characteristics']??null])self::fail('preview_stats_layer_unavailable');
        $layers=json_decode($e['layers_json'],true,32,JSON_THROW_ON_ERROR);
        if($layers!==['schema_version'=>1,'layers'=>[]])self::fail('preview_effect_contract_unavailable');
        $rules=$s['data']['definition']??[];
        $immunities=$rules['immunities']??[];
        if(!is_array($immunities)||!array_is_list($immunities))self::fail('status_immunity_contract_unsupported');
        foreach($immunities as $scope)if(!is_array($scope)||count($scope)!==2||array_diff(array_keys($scope),['scope','key'])
            ||!in_array($scope['scope']??null,['status_key','status_type'],true)||!is_string($scope['key']??null)||$scope['key']==='')self::fail('status_immunity_scope_unsupported');
        foreach(['weaknesses','passive_effects','triggers','reflection','lifesteal','math_modifiers'] as $field)if(!empty($rules[$field]))self::fail('preview_effect_contract_unavailable');
        $resources=$this->repo->statusResources($id,$e);
        return ['row'=>$e,'registration'=>$p,'source'=>$s,'resources'=>$resources];
    }
    public function select(string $sid,string $eid):array
    {
        if(!af_is_addon_enabled('knowledgebase'))self::fail('preview_dependency_unavailable');
        $sessionView=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);
        $encounter=$this->repo->encounter($sid,$eid);if(!$encounter)self::fail('preview_target_forbidden');
        $view=['session'=>$sessionView['session'],'encounter'=>$encounter];$gm=$sessionView['can_manage'];
        if($view['session']['status']!=='active'||$view['encounter']['status']!=='active')self::fail('preview_encounter_not_active');
        $audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        $members=$this->repo->participants($eid,'0',201);$actors=[];$targets=[];$summonScope=$this->repo->summonProjection($sid,$eid);
        foreach($members as $m){if($m['participation']!=='engaged')continue;if($m['kind']==='summon'&&($summonScope[$m['entity_id']]['status']??null)!=='active')continue;
            $control=$this->repo->controllerFor($sid,$m['entity_id'],$this->access->uid());$own=$control!==null;$temporary=isset($control['control_overlay']);
            if($temporary&&$audience->knowledge('entity',$m['entity_id'])['level']!=='identified')continue;
            $entry=['id'=>$m['entity_id'],'name'=>$audience->name($m['entity_id'],$m['name']),'side_id'=>$m['side_id'],'kind'=>$m['kind'],'active'=>($m['life_state']??null)==='active'];
            if($own)$actors[$m['entity_id']]=$entry;
            if($own||$audience->canTarget($m['entity_id']))$targets[$m['entity_id']]=$entry;
        }
        $access=[];foreach($actors as $actor)foreach($targets as $target)if($audience->canTarget($target['id'],$actor['id']))$access[$actor['id']][$target['id']]=true;
        return ['view'=>$view,'actors'=>$actors,'targets'=>$targets,'members'=>$members,'target_access'=>$access,'audience_stamp'=>$audience->stamp()];
    }
    public static function behaviorCandidates(array $targets,string $actor,array $relationships):array
    {
        $out=[];foreach($targets as $t)$out[$t['id']]=['kind'=>$t['kind'],'active'=>$t['active']??true,'relationship'=>$relationships[$actor][$t['id']]??'neutral'];return $out;
    }
    public function inspect(string $sid,string $eid,string $actor,string $target,?string $sealedDraft=null,bool $itemSelf=false):array
    {
        $selection=$this->select($sid,$eid);$this->repo->assertPreviewStorage();$view=$selection['view'];
        if(!isset($selection['actors'][$actor])||!isset($selection['targets'][$target])||empty($selection['target_access'][$actor][$target]))self::fail('preview_target_forbidden');
        $sideA=$selection['actors'][$actor]['side_id'];$sideB=$selection['targets'][$target]['side_id'];$hostile=false;
        foreach($this->repo->allRelations($eid) as $r)if(($r['side_a']===$sideA&&$r['side_b']===$sideB||$r['side_a']===$sideB&&$r['side_b']===$sideA)&&$r['relationship']==='hostile')$hostile=true;

        $round=$this->repo->currentRound($view['encounter']);if(!$round||$round['status']!=='open')self::fail('preview_turn_unavailable');
        $reactions=$this->repo->reactionProjection($sid,$eid);$barriers=$this->repo->barrierProjection($sid,$eid);
        $objects=$this->repo->objectProjection($sid,$eid);
        $summons=$this->repo->summonProjection($sid,$eid);
        $sources=[$actor=>$this->source($sid,$actor),$target=>$this->source($sid,$target)];if($sources[$actor]['row']['life_state']!=='active')self::fail('preview_actor_unavailable');$turns=[];$groups=[];$reactionTurns=[];$reactionKinds=[];$versions=['session_version'=>$view['session']['session_version'],'encounter_version'=>$view['encounter']['encounter_version'],'round_version'=>(string)$round['round_version']];
        if($barriers['pools']||array_filter($reactions['entries'],static fn($e)=>isset($e['spec']['selection']))){
            $needed=[];foreach($barriers['pools'] as $pool)array_push($needed,$pool['source_entity_id'],...$pool['targets']);foreach($reactions['entries'] as $reaction)array_push($needed,$reaction['entity_id'],...$reaction['protected_targets']);
            foreach($selection['members'] as $m){$id=$m['entity_id'];if(isset($sources[$id])||!in_array($id,$needed,true))continue;$sources[$id]=$this->source($sid,$id);}
        }
        foreach($summons as $summon)if($summon['status']==='active'&&(isset($sources[$summon['source_entity_id']])||isset($sources[$summon['entity_id']])))foreach([$summon['entity_id'],$summon['source_entity_id']] as $needed)if(!isset($sources[$needed]))$sources[$needed]=$this->source($sid,$needed);
        foreach($objects as $object)if($object['status']==='active'&&!isset($sources[$object['source_entity_id']])){
            $m=AF_ACS_Environment::definition($object['definition']);$row=$this->repo->entity($sid,$object['source_entity_id']);
            if(($m['passive']||$m['triggers'])&&$row&&$row['control_group_id']===$sources[$actor]['row']['control_group_id'])$sources[$object['source_entity_id']]=$this->source($sid,$object['source_entity_id']);
        }
        foreach($this->repo->allRoundTurns($round['id']) as $turn){
            foreach($sources as $id=>$source){$id=(string)$id;if($source['row']['control_group_id']!==$turn['control_group_id'])continue;
                $r=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$r['fingerprint']??null;unset($r['fingerprint']);
                if(!is_string($fp)||!hash_equals($fp,hash('sha256',AF_ACS_Repository::json($r))))self::fail('preview_roster_invalid');
                $member=null;foreach($r['members']??[] as $m)if($m['entity_id']===$id)$member=$m;
                if(!$member&&!in_array($id,[$actor,$target],true))continue;
                if(!$member||$member['content_hash']!==$source['row']['content_hash']||$member['template_snapshot_id']!==$source['row']['template_snapshot_id'])self::fail('preview_roster_invalid');
                $individual=null;foreach($this->repo->individualTurns([$turn['id']]) as $x)if($x['entity_id']===$id)$individual=$x;
                if(!$individual){if(in_array($id,[$actor,$target],true))self::fail('preview_turn_unavailable');continue;}
                $reactionTurns[$id]=['id'=>$individual['id'],'used'=>(int)$individual['reaction_used'],'version'=>(int)$individual['entity_turn_version']];$reactionKinds[$id]=$source['row']['kind'];
                $turns[$id]=['id'=>$turn['id'],'status'=>$turn['status'],'group_id'=>$turn['control_group_id'],'started'=>(string)$individual['start_done']==='1'&&(string)$individual['end_done']==='0'];
                $available=AF_ACS_Clock::availableAP(self::int($turn['ap_budget']),self::int($turn['ap_spent']),self::int($turn['pre_round_ap_commitment']));
                $group=$this->repo->restGroup($sid,$turn['control_group_id']);
                if((string)$group['group_version']!==(string)$r['group_version'])self::fail('preview_roster_invalid');
                if(in_array($id,[$actor,$target],true))$versions[$id===$actor?'actor_group_version':'target_group_version']=(string)$group['group_version'];
                $groups[$turn['control_group_id']]=['ap'=>['current'=>$available,'maximum'=>self::int($turn['ap_budget'])],'version'=>self::int($turn['turn_version'])];
                if(!in_array($id,[$actor,$target],true))continue;
                foreach(['entity_version','state_version','content_hash'] as $f)$versions[$id===$actor?'actor_'.$f:'target_'.$f]=$source['row'][$f];
                $versions[$id===$actor?'actor_resources_hash':'target_resources_hash']=$source['resources']['fingerprint'];
                if (isset($source['registration']['equipment_snapshot'])) $versions[$id===$actor?'actor_equipment_hash':'target_equipment_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'rows'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['id','slot_key','inventory_item_id','item_snapshot_id','equipment_version','status','content_hash'])),$this->repo->itemEquipment($sid,$id))]));
                $versions[$id===$actor?'actor_turn_version':'target_turn_version']=$turn['turn_version'];
                $versions[$id===$actor?'actor_entity_turn_version':'target_entity_turn_version']=$individual['entity_turn_version'];
            }
        }
        if(!isset($turns[$actor],$turns[$target])||$turns[$actor]['status']!=='pending'||!$turns[$actor]['started'])self::fail('preview_turn_unavailable');
        if(!(new AF_ACS_EncounterService($this->repo,$this->access))->clock()->available($sid,$turns[$actor]['id'],$actor)['actions_ready'])self::fail('ACS_STATUS_ACTION_NOT_READY');
        $this->repo->assertNoObligations($sid,$eid,$turns[$actor]['id'],true,$sealedDraft);
        $control=$this->repo->controllerFor($sid,$actor,$this->access->uid())??throw new RuntimeException('ACS_CONTROL_INVALID');
        $versions['actor_controller_version']=$control['assignment_version'];$versions['actor_control_proof']=AF_ACS_AuthorizationService::controlProof($control);
        $versions['custody_state_hash']=hash('sha256',serialize($this->repo->custodyRows($sid)));$versions['reaction_registry_hash']=$reactions['fingerprint'];$versions['barrier_state_hash']=$barriers['fingerprint'];$protectionProof=[];foreach($sources as $id=>$source)$protectionProof[$id]=[$source['row']['state_version'],$source['row']['content_hash'],$source['resources']['fingerprint']];$versions['protection_state_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'sources'=>$protectionProof,'turns'=>$reactionTurns,'groups'=>$groups]));
        $statusProjection=$this->repo->statusProjection($sid);foreach($this->repo->statusRows($sid) as $effect)if(($effect['scope']??null)==='combat'&&$effect['status']==='active'&&(string)$effect['encounter_id']!==$eid&&(json_decode($effect['payload_json'],true,64,JSON_THROW_ON_ERROR)['kind']??null)==='barrier')self::fail('ACS_COMBAT_RESET_REQUIRED');$versions['status_state_hash']=$statusProjection['fingerprint'];
        foreach($statusProjection['instances'] as $instance)if($instance['status']==='active'&&$instance['spec']['scope']==='combat'&&$instance['encounter_id']!==$eid)self::fail('ACS_COMBAT_RESET_REQUIRED');
        $endBoundaries=[];
        foreach($this->repo->individualTurns([$turns[$actor]['id']]) as $individual){
            if($individual['start_done']!=='1'&&AF_ACS_Clock::hasObjectStartWork($objects,$individual['entity_id']))self::fail('ACS_STATUS_START_REQUIRED');
            if($individual['start_done']!=='1'&&AF_ACS_Clock::hasSummonStartWork($summons,$individual['entity_id']))self::fail('ACS_STATUS_START_REQUIRED');
            if($individual['start_done']!=='1'&&AF_ACS_Clock::hasStartWork($statusProjection['instances'],$individual['entity_id'],$sid,$eid))self::fail('ACS_STATUS_START_REQUIRED');
            $endBoundaries[]=['phase'=>'entity_end','id'=>$individual['id'],'entity_id'=>$individual['entity_id'],'session_id'=>$sid,'encounter_id'=>$eid,'encounter_status'=>'active'];
        }
        $versions['relationships_hash']=hash('sha256',serialize(iterator_to_array($this->repo->allRelations($eid))));
        $versions['actor_side_id']=$sideA;$versions['target_side_id']=$sideB;$membership=array_column($selection['members'],null,'entity_id');
        $versions['actor_participant_version']=$membership[$actor]['participant_version'];$versions['target_participant_version']=$membership[$target]['participant_version'];
        $versions['summon_state_hash']=hash('sha256',serialize($summons));
        $versions['awareness_hash']=hash('sha256',serialize([$this->repo->awarenessProjection($sid,$eid),$this->repo->awarenessAttempts($sid,$eid),$selection['audience_stamp']]));
        $versions['object_state_hash']=hash('sha256',serialize($objects));
        $subjects=$this->repo->perceptionSubjects($sid,$eid);$state=['awareness'=>$this->repo->awarenessProjection($sid,$eid),'awareness_attempts'=>$this->repo->awarenessAttempts($sid,$eid),'perception_subjects'=>$subjects,'objects'=>$objects,'summons'=>$summons,'status_instances'=>$statusProjection['instances'],'next_status_order'=>$statusProjection['next_order'],'schema_version'=>1,'entities'=>[],'groups'=>$groups,'barriers'=>$barriers['pools'],'next_barrier_order'=>$barriers['next_order']];$stats=[];$defenses=[];$statusImmunities=[];$catalog=null;
        foreach($sources as $id=>$source){$id=(string)$id;$row=$source['row'];$s=$source['source'];$stat=$s['characteristics']??null;if(!is_array($stat))self::fail('preview_stats_missing');
            require_once __DIR__.'/ItemActionService.php';$stat=AF_ACS_ItemActionService::loadoutStats($this->repo,$sid,$id,$source['registration'],$stat);
            foreach(['accuracy','speed','crit_rate','crit_dmg','def'] as $name)AF_ACS_Numeric::number($stat[$name]??null);$stats[$id]=$stat;
            $statusImmunities[$id]=$s['data']['definition']['immunities']??[];
            $ref=['key'=>'entity-'.$id,'version'=>'registration-1','fingerprint'=>'sha256:'.$row['content_hash']];
            if(!isset($state['groups'][$row['control_group_id']]))$state['groups'][$row['control_group_id']]=['ap'=>['current'=>0,'maximum'=>0],'version'=>1];
            $state['entities'][$id]=['hp'=>self::int($row['hp']),'max_hp'=>self::int($row['hp_max']),'life_state'=>$row['life_state'],'recovery_blocked'=>false,'group_id'=>$row['control_group_id'],'resources'=>$source['resources']['values'],'source_ref'=>$ref,'version'=>self::int($row['state_version'])];
            $res=[];foreach($s['resistances']??[] as $key=>$r)$res[$key]=['scope'=>'elemental','unit'=>'RES','value'=>$r['value']];
            $defenses[$id]=['def'=>$stat['def'],'resistances'=>$res,'immunities'=>[],'vulnerabilities'=>[]];
            if(($s['element_registry_version']??null)==='primary-elements-17-v1'||($s['capabilities']['canonical_elements_17']['readiness']??null)==='READY')$catalog=AF_ACS_Damage::retainedCatalog($row['payload_json'],$row['content_hash']);
            foreach($this->repo->allCooldowns($sid,$id) as $cd)if((int)$cd['remaining_turns']>0)self::fail('preview_cooldown_contract_unavailable');
        }
        $relationships=[];$redirectTargets=[];$members=array_column($selection['members'],null,'entity_id');$relations=[];foreach($this->repo->allRelations($eid) as $rel){$relations[$rel['side_a'].':'.$rel['side_b']]=$rel['relationship'];$relations[$rel['side_b'].':'.$rel['side_a']]=$rel['relationship'];}
        foreach($sources as $aId=>$_)foreach($sources as $bId=>$_){$aId=(string)$aId;$bId=(string)$bId;$sa=$members[$aId]['side_id']??null;$sb=$members[$bId]['side_id']??null;$rel=$sa!==null&&$sb!==null?($sa===$sb?'allied':($relations[$sa.':'.$sb]??null)):null;$relationships[$aId][$bId]=$aId===$bId?'self':($rel==='allied'?'ally':$rel);if($aId!==$bId&&isset($selection['targets'][$bId],$reactionTurns[$bId]))$redirectTargets[$aId][]=$bId;}
        if($catalog===null)self::fail('preview_retained_registry_unavailable');
        $controlContext=['controller_uid'=>$this->access->uid(),'assignments'=>[]];foreach($sources as $sourceId=>$unused){ $base=$this->repo->controllers($sid,(string)$sourceId);if($base)$controlContext['assignments'][(string)$sourceId]=AF_ACS_AuthorizationService::assignment($base)['assignment_version'];}
        $versions['target_assignment_version']=$controlContext['assignments'][$target]??null;
        // Full visible target universe is independent of fixed submitted targets.
        $behaviorTargets=[];foreach($sources as $aId=>$unused){$aId=(string)$aId;$rels=[];$sa=$members[$aId]['side_id']??null;
            foreach($selection['targets'] as $t){$sb=$t['side_id'];$rels[$aId][$t['id']]=$aId===$t['id']?'self':($sa===$sb?'ally':($relations[$sa.':'.$sb]??'neutral'));}
            $behaviorTargets[$aId]=self::behaviorCandidates($selection['targets'],$aId,$rels);
        }
        $versions['behavior_candidates_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'candidates'=>$behaviorTargets]));
        $perceptionActors=[];foreach($sources as $id=>$source)if(isset($members[$id]))$perceptionActors[$id]=['entity_id'=>(string)$id,'group_id'=>$source['row']['control_group_id'],'side_id'=>$members[$id]['side_id'],'encounter_id'=>$eid];
        [$abilities,$choices]=$this->pinnedAbilities($sources[$actor]);
        return ['selection'=>$selection,'versions'=>$versions,'choices'=>$choices,'context'=>['perception_actors'=>$perceptionActors,'behavior_targets'=>$behaviorTargets,'control_context'=>$controlContext,'schema_version'=>1,'state'=>$state,'stats'=>$stats,'defenses'=>$defenses,'element_catalog'=>$catalog,'turns'=>$turns,
            'encounter_status'=>'active','abilities'=>[$actor=>$abilities],'allowed_targets'=>[$actor=>[$target]],'relationships'=>$relationships,'status_immunities'=>$statusImmunities,'status_scope'=>['session_id'=>$sid,'encounter_id'=>$eid],'entity_end_boundaries'=>$endBoundaries,'reactions'=>$reactions['entries'],'reaction_turns'=>$reactionTurns,'reaction_kinds'=>$reactionKinds,'redirect_targets'=>$redirectTargets]];
    }
    /** Own controls may be listed without exposing GM-only targets or their counts. */
    public function builderScope(string $sid,string $eid):array
    {
        $view=(new AF_ACS_SessionService($this->repo,$this->access))->view($sid);
        if(!$view['can_register_own'])self::fail('preview_target_forbidden');
        $encounter=$this->repo->encounter($sid,$eid);
        if(!$encounter||!in_array($encounter['status'],['active','paused'],true))self::fail('preview_encounter_not_active');
        $round=$this->repo->currentRound($encounter);if(!$round||$round['status']!=='open')self::fail('preview_turn_unavailable');
        $roles=array_column($this->repo->roles($sid,$this->access->uid()),'role');
        $gm=$this->access->administrator()||in_array('gm',$roles,true)||(in_array('owner',$roles,true)&&$view['session']['owner_uid']===$this->access->uid());
        $audience=new AF_ACS_AudienceService($this->repo,$this->access,$sid,$eid);
        $actors=[];$targets=[];$members=$this->repo->participants($eid,'0',201);$summonScope=$this->repo->summonProjection($sid,$eid);
        foreach($members as $m){if($m['participation']!=='engaged')continue;if($m['kind']==='summon'&&($summonScope[$m['entity_id']]['status']??null)!=='active')continue;
            $control=$this->repo->controllerFor($sid,$m['entity_id'],$this->access->uid());$own=$control!==null;$temporary=isset($control['control_overlay']);
            if($temporary&&$audience->knowledge('entity',$m['entity_id'])['level']!=='identified')continue;
            if($own)$actors[$m['entity_id']]=['id'=>$m['entity_id'],'name'=>$audience->name($m['entity_id'],$m['name']),'group_id'=>$m['control_group_id'],'side_id'=>$m['side_id'],'kind'=>$m['kind'],'active'=>($m['life_state']??null)==='active'];
            if($own||$audience->canTarget($m['entity_id']))$targets[$m['entity_id']]=['id'=>$m['entity_id'],'name'=>$audience->name($m['entity_id'],$m['name']),'side_id'=>$m['side_id'],'kind'=>$m['kind'],'active'=>($m['life_state']??null)==='active'];
        }
        foreach($targets as &$target){$target['known_to']=[];foreach($actors as $actor)if($audience->canTarget($target['id'],$actor['id']))$target['known_to'][]=$actor['id'];}unset($target);
        return ['session'=>$view['session'],'encounter'=>$encounter,'round'=>$round,'actors'=>$actors,'targets'=>$targets,'gm'=>$gm,'known_subjects'=>$audience->notices(),'audience_stamp'=>$audience->stamp()];
    }
    /** Internal pinned owner data, only after the coordinator has prepared/authorized selectors. */
    public function sealDefinition(string $sid,string $actor,string $key):array
    {
        $source=$this->source($sid,$actor);return $source['registration']['pinned_abilities'][$key]??throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
    }
    /** Cached dependencies are not grants. Registration requires an exact direct owner ability ref. */
    public function reactionGrant(string $sid,string $entity,array $pin):void
    {
        $source=$this->source($sid,$entity);$grant=$this->grants($source['source'])[$pin['key']]??null;$dto=$source['registration']['pinned_abilities'][$pin['key']]??null;
        if(!$grant||!is_array($dto)||$grant['fingerprint']!==$pin['fingerprint']||($dto['definition_version']['fingerprint']??null)!==$pin['fingerprint']||($dto['definition_version']['declared_version']??null)!==$pin['version'])throw new RuntimeException('ACS_REACTION_GRANT_REQUIRED');
        foreach($grant['_profiles']??[] as $inline)$this->inline($inline,$dto['data']['definition']);
    }
    public function builderCatalog(string $sid,string $actor):array
    {
        if(!af_is_addon_enabled('knowledgebase'))self::fail('preview_dependency_unavailable');
        [, $choices]=$this->pinnedAbilities($this->source($sid,$actor));return array_values($choices);
    }
    private function pinnedAbilities(array $source):array
    {
        $abilities=[];$choices=[];
        $controlGrant=$this->repo->controllerFor((string)$source['row']['session_id'],(string)$source['row']['id'],$this->access->uid());
        $grants=$this->grants($source['source']);
        foreach($grants as $key=>$grant){
            $dto=$source['registration']['pinned_abilities'][$key]??null;
            if(!is_array($dto)||($dto['definition_version']['fingerprint']??null)!==$grant['fingerprint'])self::fail('preview_ability_pin_mismatch');
            foreach(['revision','declared_version'] as $field)if(($dto['definition_version'][$field]??null)!==($grant['definition_version'][$field]??null))self::fail('preview_ability_pin_mismatch');
            if(isset($controlGrant['control_overlay'])&&!in_array($key,$controlGrant['control_overlay']['ability_keys'],true))continue;
            try{foreach($grant['_profiles']??[] as $inline)$this->inline($inline,$dto['data']['definition']);$abilities[$key]=$this->ability($dto);$choices[$key]=['key'=>$key,'supported'=>true,'label'=>is_string($dto['data']['definition']['name']??null)&&$dto['data']['definition']['name']!==''?mb_substr($dto['data']['definition']['name'],0,191):'Способность '.$key,'target_policy'=>$abilities[$key]['target_policy']??['kind'=>'self_only'],'ap_cost'=>$abilities[$key]['costs'][0]['amount']]+(isset($abilities[$key]['target_count'])?['target_count'=>$abilities[$key]['target_count']]:[])+(isset($abilities[$key]['spawns'])?['spawn_action'=>true,'target'=>'self']:[])+(isset($abilities[$key]['awareness_action'])?['awareness_action'=>true,'label'=>$abilities[$key]['awareness_action']['operation']==='search'?'Поиск':'Передача сведений','target'=>'self']:[]);}
            catch(DomainException $e){$choices[$key]=['key'=>$key,'supported'=>false,'label'=>$key,'reason'=>$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage()];}
        }
        if(isset($controlGrant['control_overlay']))foreach($choices as &$choice)$choice['label']='Разрешённое боевое действие';unset($choice);
        return [$abilities,$choices];
    }
    /** Cached dependency definitions alone are not a grant to use an ability. Only direct owner refs qualify. */
    private function grants(array $source):array
    {
        $profiles=[];$owned=$source['abilities']??[];$npc=$source['data']['ability_keys']??[];
        if(!is_array($owned)||!array_is_list($owned)||!is_array($npc)||!array_is_list($npc))self::fail('preview_source_invalid');
        foreach($owned as $a){if(!is_array($a))self::fail('preview_source_invalid');$profiles[]=$a['definition']??[];}
        foreach($npc as $a){if(!is_array($a))self::fail('preview_source_invalid');$profiles[]=$a['ability']??[];}
        $grants=[];foreach($profiles as $profile)foreach(['ability_kb_key','ability_key'] as $field){
            if(!is_array($profile)||!is_array($profile['parameters']??[]))self::fail('preview_source_invalid');
            if(($profile['parameters']['type']??'active')!=='active'||array_key_exists('active',$profile['parameters']??[])&&!$profile['parameters']['active'])continue;
            $ref=$profile['references'][$field]??null;if(!is_array($ref)||empty($ref['resolved'])||($ref['kind']??null)!=='arpg_ability')continue;
            $key=$ref['entry_key']??null;if(!is_string($key)||!is_string($ref['fingerprint']??null)||!is_array($ref['definition_version']??null))self::fail('preview_ability_pin_mismatch');
            if(isset($grants[$key])&&$grants[$key]['fingerprint']!==$ref['fingerprint'])self::fail('preview_ability_pin_mismatch');$previous=$grants[$key]['_profiles']??[];$grants[$key]=$ref;$grants[$key]['_profiles']=array_merge($previous,[$profile['parameters']??[]]);
        }return $grants;
    }
    private function inline(array $inline,array $definition):void
    {
        $metadata=['ability_kb_key','ability_key','ability_fingerprint','ability_definition_version','ability_name','title','name','description','notes','icon_url','icon_class','slot_index','sortorder'];
        foreach($inline as $key=>$value){if(in_array($key,$metadata,true)||$value===null||$value===''||$value===[])continue;
            $field=$key==='target'?'targeting':$key;
            if(!array_key_exists($field,$definition)||$definition[$field]!==$value)self::fail('preview_inline_override_unsupported');
        }
    }
    private function ability(array $dto):array
    {
        require_once __DIR__.'/../Domain/Spawn.php';
        if(AF_ACS_Environment::hasMassAbility($dto))return AF_ACS_Environment::compileMass($dto);
        if(AF_ACS_Awareness::hasAbility($dto))return AF_ACS_Awareness::compile($dto);
        if(AF_ACS_Spawn::hasAbility($dto))return AF_ACS_Spawn::compile($dto);
        $policy=AF_ACS_HitCritPolicy::fromDefinition($dto,$dto['source'],$dto['definition_version']);$d=$dto['data']['definition'];
        $fields=['target_policy','schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),$fields))self::fail('preview_ability_contract_unavailable');
        if(!empty($d['target_count'])&&($d['targeting']??null)!=='multiple'&&$d['target_count']!==['min'=>1,'max'=>1])self::fail('preview_ability_unsupported');
        if(($d['type']??'active')!=='active'||!in_array($d['targeting']??null,['single_enemy','multiple'],true))self::fail('preview_ability_unsupported');
        foreach(['resources','timing','usage_limits','application_conditions','summon','math_modifiers','modifiers','base_stats','conditions','stacking','grants','immunities','upgrade_requirements','application_mode','damage_value','heal_value','shield_value','formula_refs','formula_ref','triggers','passive_effects','reflection','lifesteal'] as $f)if(!empty($d[$f]))self::fail('preview_ability_contract_unavailable');
        foreach(['range','cast_time','cooldown','cooldown_value','max_charges','duration','duration_value','cost_value'] as $f)if(!empty($d[$f]))self::fail('preview_ability_contract_unavailable');
        $cost=$d['action_cost']??1;if(!is_int($cost)||$cost<1)self::fail('preview_action_cost_unsupported');
        $parts=[];$statuses=[];$removals=[];foreach($dto['data']['ability_profile']['effects']??[] as $effect){$p=$effect['parameters'];
            $fields=['kind','effect_type','target','targeting','value','value_mode','damage_type','damage_element','element','formula_profile','formula_ref','condition_ref','duration','duration_value','duration_unit','status_key','resource_key','stat_key','operation','hit_count','coefficient','can_crit','magic_direction_ref','notes','base_chance','polarity','trigger','probability_check','categories','limit','mass','chance','chance_scope','remove_stacks'];
            if(array_diff(array_keys($p),$fields))self::fail('preview_effect_unsupported');
            if(array_key_exists('hit_count',$p)&&!in_array($p['hit_count'],['',null,1],true))self::fail('preview_effect_unsupported');
            if(array_key_exists('coefficient',$p)&&!in_array($p['coefficient'],['',null],true))self::fail('preview_effect_unsupported');
            if(in_array($p['effect_type']??null,['cleanse','dispel'],true)){
                $allowed=['kind','effect_type','target','targeting','damage_type','damage_element','element','magic_direction_ref','hit_count','trigger','categories','limit','mass','chance','chance_scope','remove_stacks','notes'];
                foreach($p as $field=>$value)if(!in_array($field,$allowed,true)&&$value!==null&&$value!==''&&$value!==[]&&$value!==false)self::fail('status_removal_override_unsupported');
                if(($p['target']??$d['targeting'])!==$d['targeting']||($p['targeting']??$d['targeting'])!==$d['targeting'])self::fail('status_target_unsupported');
                $removals[]=AF_ACS_KnowledgeBaseAdapter::removalEffect($dto,$effect);continue;
            }
            if(array_intersect(array_keys($p),['categories','limit','mass','chance','chance_scope','remove_stacks']))self::fail('preview_effect_unsupported');
            if(($p['effect_type']??null)==='status'){
                if(array_key_exists('can_crit',$p)&&$p['can_crit']!==false)self::fail('status_override_unsupported');
                foreach(['condition_ref','formula_ref','formula_profile','coefficient','operation','resource_key','stat_key'] as $field)if(!empty($p[$field]))self::fail('status_override_unsupported');
                if(($p['target']??$d['targeting'])!==$d['targeting']||($p['targeting']??$d['targeting'])!==$d['targeting'])self::fail('status_target_unsupported');
                $statuses[]=AF_ACS_KnowledgeBaseAdapter::statusEffect($dto,$effect);continue;
            }
            if(array_intersect(array_keys($p),['base_chance','polarity','trigger','probability_check']))self::fail('preview_effect_unsupported');
            if(($p['effect_type']??null)!=='damage')self::fail('preview_effect_unsupported');
            foreach(['condition_ref','duration','duration_value','status_key','resource_key','stat_key','operation'] as $f)if(!empty($p[$f]))self::fail('preview_effect_unsupported');
            if(($p['target']??$d['targeting'])!==$d['targeting']||($p['targeting']??$d['targeting'])!==$d['targeting']||!in_array($p['value_mode']??'flat',['flat',''],true))self::fail('preview_effect_unsupported');
            $value=[];
            if(!empty($p['formula_profile'])||!empty($p['formula_ref'])){
                if(!empty($p['formula_profile'])&&!empty($p['formula_ref']))self::fail('preview_formula_binding_unavailable');
                $ref=$effect['references'][!empty($p['formula_ref'])?'formula_ref':'formula_profile']??[];$formula=$ref['formula']??null;$parameters=$ref['parameters']??[];
                if(!is_array($formula)||empty($ref['resolved'])||array_diff_key($formula['definition']['inputs']??[],$parameters))self::fail('preview_formula_dynamic_binding_unavailable');
                if(($formula['definition']['output_unit']??null)!=='damage')self::fail('preview_formula_unit_unsupported');
                if(($formula['definition']['rounding']??null)!=='none'||($formula['definition']['output_type']??null)!=='number')self::fail('preview_formula_rounding_unavailable');
                AF_ACS_Formula::compile($formula,array_intersect_key($formula,array_flip(['key','version','fingerprint'])),'damage');
                $value=['formula'=>$formula,'pin'=>array_intersect_key($formula,array_flip(['key','version','fingerprint'])),'parameters'=>$parameters,'inputs'=>[],'unit'=>'damage'];
            }else{
                if(!array_key_exists('value',$p))self::fail('preview_damage_value_missing');$value=['value'=>AF_ACS_Numeric::number($p['value'])];if($value['value']<0)self::fail('preview_damage_value_invalid');
            }
            $type=$effect['damage']['category'];$element=$effect['damage']['element'];if(!in_array($type,['physical','elemental','true'],true))self::fail('preview_effect_unsupported');
            if($type==='elemental'){$explicit=$effect['parameters']['damage_element']??null;if(!is_string($explicit)||!is_string($element))self::fail('damage_element_missing');}
            $parts[]=['source_index'=>$effect['source_index'],'kb_effect_index'=>$effect['source_index'],'damage_type'=>$type,'damage_element'=>$type==='elemental'?$element:null]+$value;
        }
        if(!empty($d['costs'])&&!$statuses)self::fail('upkeep_status_binding_missing');
        if(!$parts&&!$statuses&&!$removals)self::fail('preview_damage_value_missing');
        if(!is_string($dto['definition_version']['declared_version']??null))self::fail('preview_source_version_unavailable');
        if($d['targeting']==='multiple'){if(!is_array($d['target_count']??null)||array_diff(array_keys($d['target_count']),['min','max'])||!is_int($d['target_count']['min']??null)||!is_int($d['target_count']['max']??null)||$d['target_count']['min']<1||$d['target_count']['max']<$d['target_count']['min'])self::fail('preview_target_count_unsupported');}
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],
            'target_policy'=>AF_ACS_Control::targetPolicy($d),'components'=>$parts,'costs'=>[['kind'=>'ap','amount'=>$cost]],'hit_policy'=>$policy]+(($d['targeting']??null)==='multiple'?['targeting'=>'multiple','target_count'=>$d['target_count']]:[])+($statuses?['statuses'=>$statuses]:[])+($removals?['removals'=>$removals]:[]);
    }
    public function preview(array $input):array
    {
        $this->access->csrf();$allowed=['session_id','encounter_id','actor_id','target_id','ability_key','scenario','versions'];if(array_diff(array_keys($input),$allowed))self::fail('preview_input_invalid');
        if(!is_string($input['ability_key']??null)||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$input['ability_key']))self::fail('preview_input_invalid');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$actor=AF_ACS_Repository::integer($input['actor_id']??null);$target=AF_ACS_Repository::integer($input['target_id']??null);
        if(!(new AF_ACS_SessionService($this->repo,$this->access))->view($sid)['can_manage'])self::fail('preview_target_forbidden');
        $p=$this->inspect($sid,$eid,$actor,$target);$expected=$input['versions']??null;if(!is_array($expected))self::fail('preview_version_conflict');$current=$p['versions'];ksort($expected);ksort($current);if($expected!==$current)self::fail('preview_version_conflict');
        foreach($p['context']['abilities'][$actor][$input['ability_key']]['components']??[] as $component)if($component['damage_type']==='elemental'&& !empty($p['context']['stats'][$actor]['element_damage_bonus']))self::fail('preview_element_bonus_contract_unavailable');
        $result=AF_ACS_Resolver::resolve($p['context'],[['actor_id'=>$actor,'ability_key'=>$input['ability_key']??null,'targets'=>[$target],'scenario'=>$input['scenario']??null]]);
        // Readback: no cross-request result is returned for a concurrently changed state/permission.
        $after=$this->inspect($sid,$eid,$actor,$target);if($after['versions']!==$p['versions'])self::fail('preview_version_conflict');
        $plan=$result->serverPlan()['actions'][0];return ['engine_version'=>AF_ACS_Resolver::VERSION,'conditional'=>true,'label'=>'Условный сценарий, не результат броска','scenario'=>$input['scenario'],'actor_id'=>$actor,'target_id'=>$target,
            'ability_key'=>$input['ability_key'],'costs'=>$plan['costs'],'probabilities'=>$plan['probabilities'],'components'=>$plan['allocation']['components'],
            'integer_budget'=>$plan['allocation']['integer_budget'],'consequences'=>$plan['effects_trace'],'versions'=>$p['versions'],'persistent'=>false,'visibility_scope'=>'gm'];
    }
}
