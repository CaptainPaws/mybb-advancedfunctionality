<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/QueryService.php';
require_once __DIR__.'/DraftService.php';
require_once __DIR__.'/ItemActionService.php';
require_once __DIR__.'/ObjectInteractionService.php';
require_once __DIR__.'/SpatialService.php';
require_once __DIR__.'/CoverService.php';

require_once __DIR__.'/../Domain/Spawn.php';

/** Explicit UI queries and a single ordered virtual simulation. No mechanics commands. */

final class AF_ACS_BuilderService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    public static function message(Throwable $e):string
    {
        $code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
        return match($code){
            'ACS_COVER_BLOCKED'=>'Цель или действие сейчас недоступны.',
            'ACS_COVER_UNSUPPORTED'=>'UNSUPPORTED: контракт окружения недоступен.',
            'ACS_COVER_VERSION_CONFLICT'=>'Зависимости окружения изменились. Броски сохранены.',
            'ACS_SPATIAL_UNSUPPORTED'=>'UNSUPPORTED: пространственный контракт или capability недоступны.',
            'ACS_SPATIAL_TARGET_BLOCKED','ACS_SPATIAL_CONTACT_BLOCKED'=>'Цель или переход сейчас недоступны.',
            'ACS_SPATIAL_VERSION_CONFLICT'=>'Пространственные зависимости изменились. Броски сохранены.',
            'ACS_COOPERATION_UNSUPPORTED'=>'UNSUPPORTED: контракт совместной задачи не поддерживается.',
            'ACS_OBJECTIVE_BLOCKED','ACS_OBJECTIVE_VERSION_CONFLICT'=>'Условия или версия общей задачи изменились. Подтверждённые вклады сохранены.',
            'ACS_ASSIST_STACK_BLOCKED'=>'Идентичная помощь уже действует или достигнут лимит KB.',
            'ACS_INTERACTION_UNSUPPORTED'=>'UNSUPPORTED: KB-контракт взаимодействия пока не поддерживается.',
            'ACS_INTERACTION_BLOCKED'=>'Условия взаимодействия не выполнены.',
            'ACS_INTERACTION_RETRY_DENIED'=>'KB запрещает следующую попытку взаимодействия.',
            'ACS_INTERACTION_TOOL_UNAVAILABLE'=>'Требуемый инструмент недоступен владельцу исполнителя.',
            'ACS_INVENTORY_RECOVERY_REQUIRED'=>'Предметная операция ожидает lookup прежнего исхода. Черновик и sealed tape сохранены; не создавайте новый ключ.',
            'ACS_CUSTODY_ITEM_UNAVAILABLE','ACS_CUSTODY_SLOT_CONFLICT'=>'Предмет или слот недоступен из-за сессионного custody.',
            'ACS_ITEM_PASS_SPATIAL_UNSUPPORTED'=>'UNSUPPORTED: передача требует утверждённых правил дистанции и пользования предметом.',
            'ACS_ITEM_EQUIP_DENIED','ACS_ITEM_EQUIP_CONFLICT'=>'Этот предмет или слот нельзя изменить обычным боевым действием. Броня и неподтверждённые аксессуары недоступны.',
            'ACS_ITEM_DEPENDENCY_CONFLICT','ACS_ITEM_INSUFFICIENT_QTY','ACS_ITEM_RESERVATION_REQUIRED','ACS_ITEM_RESERVATION_REJECTED'=>'Недостаточно свободного количества или резерв предмета изменился.',
            'ACS_ITEM_MODIFIER_PROVENANCE_BLOCKED','ACS_ITEM_PROVENANCE_REQUIRED'=>'Не подтверждено происхождение бонусов боевого комплекта.',
            'ACS_ITEM_EFFECT_UNSUPPORTED','ACS_ITEM_RULE_UNSUPPORTED'=>'UNSUPPORTED: эффекты или ограничения KB этого предмета пока не поддерживаются.',
            'INV_SCHEMA_NOT_READY','ACS_INVENTORY_UNAVAILABLE'=>'BLOCKED: owner-side Inventory protocol или схема недоступны.',
            'INV_SPENDING_NOT_AUTHORIZED'=>'Нет действующего серверного разрешения на расход собственного имущества в этой сессии.',
            'preview_turn_unavailable'=>'Предпросмотр недоступен: нужен текущий открытый ход и обработанное начало хода существ.',
            'preview_encounter_not_active'=>'Предпросмотр недоступен: столкновение не активно или поставлено на паузу.',
            'effects_cost_unaffordable'=>'Недостаточно общего AP или ресурсов для всей последовательности.',
            'builder_parameters_unsupported'=>'Параметры этого действия пока не поддерживаются. Они сохранены в черновике, но расчёт заблокирован.',
            'builder_version_conflict','preview_version_conflict'=>'Состояние изменилось. Обновите сведения конструктора и повторите предпросмотр.',
            'resolver_scenario_impossible'=>'Этот условный сценарий невозможен при текущих вероятностях. Выберите другой сценарий.',
            'preview_target_forbidden','ACS_DRAFT_UNAVAILABLE','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE'=>'Нет разрешённого доступа к выбранной группе или цели.',
            'builder_input_invalid'=>'Некорректная последовательность действий.',
            'ACS_FRIENDLY_FIRE_CONFIRMATION_REQUIRED'=>'Перед seal подтвердите предупреждение об уроне союзнику.',
            'ACS_BEHAVIOR_ACTION_BLOCKED'=>'Активное ограничение поведения запрещает это действие.',
            'ACS_BEHAVIOR_TARGET_BLOCKED'=>'Активное ограничение поведения запрещает выбранную цель.',
            'ACS_BEHAVIOR_NO_LEGAL_TARGET'=>'Для действия нет допустимой цели. Автоматическая замена не выполняется.',
            'ACS_BEHAVIOR_UNSUPPORTED'=>'KB behavior constraint или его совместимость не поддерживаются; действие заблокировано.',
            'ACS_TARGET_POLICY_DENIED'=>'Выбранная цель не разрешена текущей KB target policy.',
            'ACS_SEAL_SUMMARY_CHANGED'=>'Зависимости изменились. Проверьте пересчитанную сводку на прежних бросках и подтвердите её заново.',
            'ACS_SEAL_PIN_CHANGED','ACS_SEAL_VERSION_UNSUPPORTED'=>'Закреплённое определение или версия движка не совпадает. Броски сохранены; новый roll не выполняется.',
            'ACS_SEAL_IMMUTABLE'=>'Запечатанный префикс неизменяем. Отмена разрешена только с конца.',
            'preview_action_cost_unsupported'=>'Для способности за 0 AP или иной неподдержанной стоимости нужен проверенный лимит KB. Запечатывание не выполняется.',
            'ACS_SEAL_QUOTA_EXHAUSTED'=>'Квота подготовки группы исчерпана. Отмена действий не возвращает попытки.',
            'preview_dependency_unavailable'=>'Нужная интеграция отключена. Проверьте состояние Knowledge Base и CharacterSheets.',
            'preview_ability_pin_mismatch','preview_source_version_unavailable'=>'Закреплённая версия способности недоступна или не совпадает с источником регистрации.',
            'preview_source_invalid','preview_stats_layer_unavailable','preview_stats_missing'=>'Снимок существа неполон или несовместим с текущим read-only контрактом.',
            'preview_effect_contract_unavailable','preview_effect_unsupported','preview_ability_contract_unavailable','preview_ability_unsupported'=>'Эффекты, условия или затраты этой способности пока не поддерживаются базовым предпросмотром.',
            'preview_cooldown_contract_unavailable'=>'У существа активен кулдаун; его применение в этом предпросмотре пока не подключено.',
            'preview_formula_dynamic_binding_unavailable','preview_formula_binding_unavailable'=>'Для формулы требуется неподдерживаемая привязка параметров. Предположительный расчёт не используется.',
            'preview_damage_value_missing'=>'В закреплённом определении нет поддержанного значения или формулы урона.',
            'preview_element_bonus_contract_unavailable'=>'Стихийный бонус источника пока не поддерживается текущим предпросмотром.',
            default=>'Данные или механика пока не поддерживаются. Проверьте источник способности, начало хода и доступность интеграций.'
        };
    }
    public function context(string $sid,string $eid,?string $selected=null):array
    {
        $sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);$q=new AF_ACS_QueryService($this->repo,$this->access);$scope=$q->builderScope($sid,$eid);
        $this->access->draftThread($scope['session']['tid']);$turns=[];$actors=[];$versions=[];
        foreach($this->repo->allRoundTurns($scope['round']['id']) as $turn){
            $members=[];foreach($scope['actors'] as $actor)if($actor['group_id']===$turn['control_group_id'])$members[]=$actor;
            if(!$members)continue;
            $t=$this->repo->clockTurn($sid,$turn['id']);$roster=json_decode($t['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$roster['fingerprint']??null;unset($roster['fingerprint']);
            if(!is_string($fp)||!hash_equals($fp,hash('sha256',AF_ACS_Repository::json($roster))))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            $memberIds=array_column($roster['members']??[],'entity_id');$members=array_values(array_filter($members,static fn($a)=>in_array($a['id'],$memberIds,true)));
            if(!$members)continue;
            $ap=AF_ACS_Clock::availableAP((int)$t['ap_budget'],(int)$t['ap_spent'],(int)$t['pre_round_ap_commitment']);
            $turns[]=['id'=>$t['id'],'group_id'=>$t['control_group_id'],'status'=>$t['status'],'members'=>$members,'ap'=>$ap,'maximum_ap'=>(int)$t['ap_budget']];
            $versions[$t['id']]=[$t['turn_version'],$fp];
        }
        if(!$turns)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        if($selected===null||$selected===''){$selected=$turns[0]['id'];foreach($turns as $t)if($t['status']==='pending'){$selected=$t['id'];break;}}
        $chosen=null;foreach($turns as $t)if($t['id']===$selected)$chosen=$t;
        if(!$chosen)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $draft=$this->repo->builderDraft($sid,$selected,$this->access->uid());$retained=[];
        if ($draft) foreach ($this->repo->sealRows($draft,true) as $seal) {
            $payload=$this->repo->sealPayload($seal);$definition=$this->repo->itemSealDefinition($sid,$seal);$spec=$definition['item_action']??null;
            if (!$spec) continue;
            $retained[$payload['action']['actor_id']][]=['key'=>$payload['action']['ability_key'],'supported'=>true,'label'=>'Предмет #'.$spec['item_id'].' · '.($spec['operation']==='consume'?'Использовать':'Сменить оружие'),'ap_cost'=>(int)$seal['ap_cost'],'item_action'=>true,'target'=>'self','sealed_only'=>true];
        }
        foreach($chosen['members'] as $actor){
            try{$choices=$q->builderCatalog($sid,$actor['id']);$notice='';}catch(Throwable $e){$choices=[];$notice=self::message($e);}
            foreach($choices as &$c){if(!$c['supported'])$c['reason']=self::message(new RuntimeException($c['reason']??'preview_ability_unsupported'));}unset($c);
            try {if(!isset($this->repo->controllerFor($sid,$actor['id'],$this->access->uid())['control_overlay']))$choices=array_merge($choices,(new AF_ACS_ItemActionService($this->repo,$this->access))->catalog($sid,$actor['id']));} catch (Throwable $e) {$notice=$notice?:self::message($e);}
            $choices=array_merge($choices,(new AF_ACS_CoverService($this->repo,$this->access))->catalog($sid,$eid,$actor['id']));
            $choices=array_merge($choices,(new AF_ACS_SpatialService($this->repo,$this->access))->catalog($sid,$eid,$actor['id']));
            $choices=array_merge($choices,(new AF_ACS_ObjectInteractionService($this->repo,$this->access))->catalog($sid,$eid,$actor['id'],$draft));
            foreach ($retained[$actor['id']]??[] as $choice) if (!in_array($choice['key'],array_column($choices,'key'),true)) $choices[]=$choice;
            $actors[]=['id'=>$actor['id'],'name'=>$actor['name'],'choices'=>$choices,'catalog_notice'=>$notice];
        }
        $targets=[];$relations=[];foreach($this->repo->allRelations($eid) as $r){$relations[$r['side_a']][$r['side_b']]=$r['relationship'];$relations[$r['side_b']][$r['side_a']]=$r['relationship'];}
        $behavior=$this->repo->statusProjection($sid)['instances'];$world=['session_id'=>$sid,'encounter_id'=>$eid];
        foreach($actors as &$a){$rels=[];$member=$scope['actors'][$a['id']];foreach($scope['targets'] as $t)$rels[$a['id']][$t['id']]=$a['id']===$t['id']?'self':($member['side_id']===$t['side_id']?'allied':($relations[$member['side_id']][$t['side_id']]??'neutral'));
            $candidates=AF_ACS_QueryService::behaviorCandidates(array_filter($scope['targets'],static fn($t)=>in_array($a['id'],$t['known_to']??[],true)),$a['id'],$rels);
            foreach($a['choices'] as &$choice){if(($choice['supported']??false)!==true)continue;$category=!empty($choice['item_action'])?(str_starts_with($choice['key'],'item:consume:')?'item_consume':'equipment_change'):(!empty($choice['spawn_action'])?'spawn':'ordinary');
                $choiceCandidates=!empty($choice['object_interaction'])?[$choice['object_id']=>['kind'=>'object','relationship'=>'neutral','active'=>true]]:$candidates;
                $r=AF_ACS_Behavior::evaluate($behavior,$a['id'],$choice['key'],$category,$choiceCandidates,$choice['target_policy']??['kind'=>$category==='ordinary'?'hostile_only':'self_only'],$world+['minimum_targets'=>$choice['target_count']['min']??1]);
                $choice['legal_targets']=$r['legal_targets'];$choice['behavior_status']=$r['status'];if($r['status']!=='READY'){$choice['supported']=false;$choice['reason']=self::message(new RuntimeException($r['code']));}
            }unset($choice);
        }unset($a);
        foreach($scope['targets'] as $target){$rels=[];foreach($chosen['members'] as $member)$rels[$member['id']]=$member['id']===$target['id']?'self':($member['side_id']===$target['side_id']?'allied':($relations[$member['side_id']][$target['side_id']]??'neutral'));
            $allowed=[];foreach($actors as $a)foreach($a['choices'] as $choice){if(($choice['supported']??false)!==true)continue;$policy=$choice['target_policy']??['kind'=>(!empty($choice['item_action'])||!empty($choice['spawn_action']))?'self_only':'hostile_only'];if(in_array($a['id'],$target['known_to']??[],true)&&in_array($target['id'],$choice['legal_targets']??[],true)&&AF_ACS_Control::allowed($policy,$rels[$a['id']],$target['kind'])){$allowed[]=$a['id'];break;}}
            if($allowed)$targets[]=['id'=>$target['id'],'name'=>$target['name'],'kind'=>$target['kind'],'actors'=>$allowed,'relationships'=>array_intersect_key($rels,array_fill_keys($allowed,true))];
        }
        $objectTargets=[];foreach($actors as $a)foreach($a['choices'] as $choice)if(!empty($choice['object_interaction'])&&$choice['supported']){$id=$choice['object_id'];$objectTargets[$id]??=['id'=>$id,'name'=>$choice['object_label'],'kind'=>'object','actors'=>[],'relationships'=>[]];$objectTargets[$id]['actors']=array_values(array_unique([...$objectTargets[$id]['actors'],$a['id']]));$objectTargets[$id]['relationships'][$a['id']]='neutral';}
        $targets=array_merge($targets,array_values($objectTargets));
        // Public metadata only for the already authorized group; no raw assignments or status sources.
        $individuals=array_column($this->repo->individualTurns([$selected]),null,'entity_id');$summons=$this->repo->summonProjection($sid,$eid);$summonRows=[];
        foreach($actors as &$a){$member=$scope['actors'][$a['id']];$individual=$individuals[$a['id']]??null;$grant=$this->repo->controllerFor($sid,$a['id'],$this->access->uid());
            $ready=$grant!==null&&$scope['encounter']['status']==='active'&&$chosen['status']==='pending'&&($member['active']??false)&&$individual&&$individual['start_done']==='1'&&$individual['end_done']==='0'&&AF_ACS_Effects::ordinaryActionAllowed(['summons'=>$summons,'status_instances'=>$behavior],$a['id']);
            $a['kind']=$member['kind'];$a['supported']=(bool)$ready;$a['availability']=$scope['encounter']['status']==='paused'?'encounter_paused':($ready?'ready':($grant===null?'permission_changed':($chosen['status']!=='pending'?'turn_closed':(!($member['active']??false)?'entity_inactive':(($individual['start_done']??'0')!=='1'?'start_required':'status_blocked')))));
            $a['reason']=match($a['availability']){'ready'=>'','encounter_paused'=>'Столкновение приостановлено.','permission_changed'=>'Разрешение управления изменилось. Обновите панель.','turn_closed'=>'Групповой ход закрыт. Новый ход не создаётся.','entity_inactive'=>'Существо не может выполнять обычное действие.','start_required'=>'Не выполнена граница начала индивидуального хода.',default=>'Обычные действия существа ограничены текущим состоянием.'};
            $a['control']=$grant?['mode'=>$grant['mode'],'via'=>isset($grant['control_overlay'])?'temporary_control':(isset($grant['delegation_id'])?'delegation':(isset($grant['source_control_proof'])?'source':'assignment')),'assignment_version'=>(string)$grant['assignment_version']]+(isset($grant['delegation_version'])?['delegation_version'=>(string)$grant['delegation_version']]:[]):['via'=>'unavailable'];
        }unset($a);
        foreach($scope['actors'] as $id=>$member){$id=(string)$id;if($member['group_id']!==$chosen['group_id']||$member['kind']!=='summon'||!isset($summons[$id]))continue;
            $v=$summons[$id];$summonRows[]=['entity_id'=>$id,'name'=>$member['name'],'status'=>$v['pending_independent']?'pending_independent':$v['status'],'remaining'=>$v['remaining'],'duration_unit'=>$v['unit'],'admission'=>isset($individuals[$id])?'current_roster':'next_round'];
        }
        $token=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'session'=>$scope['session']['session_version'],'encounter'=>$scope['encounter']['encounter_version'],'round'=>$scope['round']['round_version'],'turns'=>$versions,'selected'=>$selected,'audience'=>$scope['audience_stamp']]));
        return ['session_id'=>$sid,'encounter_id'=>$eid,'tid'=>$scope['session']['tid'],'author_uid'=>$this->access->uid(),'round'=>(string)$scope['round']['round_number'],'encounter_status'=>$scope['encounter']['status'],'turns'=>$turns,'turn'=>$chosen,'actors'=>$actors,'targets'=>$targets,'cover'=>(new AF_ACS_CoverService($this->repo,$this->access))->view($sid,$eid,array_column($actors,'id')),'spatial'=>(new AF_ACS_SpatialService($this->repo,$this->access))->view($sid,$eid),'known_subjects'=>$scope['known_subjects'],'objectives'=>(new AF_ACS_CooperationService($this->repo,$this->access))->progress($sid,$eid,array_column($actors,'id')),'summons'=>$summonRows,'can_manage'=>$scope['gm'],'scope_token'=>$token,'draft_id'=>$draft,'preview_scope'=>$scope['gm']?'gm':'own_draft_only','publication_enabled'=>false];
    }
    public function prepare(array $input,?string $sealedDraft=null):array
    {

        if(array_diff(array_keys($input),['session_id','encounter_id','turn_id','scope_token','actions','scenario'])||!is_array($input['actions']??null)||!array_is_list($input['actions'])||!count($input['actions'])||count($input['actions'])>20||!in_array($input['scenario']??null,['hit','crit','miss'],true))throw new RuntimeException('builder_input_invalid');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$turn=AF_ACS_Repository::integer($input['turn_id']??null);
        $scope=$this->context($sid,$eid,$turn);
        if(!is_string($input['scope_token']??null)||!hash_equals($scope['scope_token'],$input['scope_token']))throw new RuntimeException('builder_version_conflict');
        $q=new AF_ACS_QueryService($this->repo,$this->access);$prepared=[];$ordered=[];$context=null;$definitions=[];$itemSpecs=[];$itemAbilities=[];$objectAbilities=[];$actionAbilities=[];$objectBehavior=[];$spatialAbilities=[];$itemVirtual=['items'=>[],'equipment'=>[]];$warnings=[];
        $rawScope=$q->builderScope($sid,$eid);$behavior=$this->repo->statusProjection($sid)['instances'];$pairs=[];foreach($this->repo->allRelations($eid) as $r){$pairs[$r['side_a']][$r['side_b']]=$r['relationship'];$pairs[$r['side_b']][$r['side_a']]=$r['relationship'];}
        foreach($input['actions'] as $actionIndex=>$action){
            $spatial=null;$isCover=str_starts_with((string)($action['ability_key']??''),'cover:');$isSpatial=$isCover||str_starts_with((string)($action['ability_key']??''),'spatial:');$object=null;$isObject=AF_ACS_ObjectInteraction::isAction((string)($action['ability_key']??''));$isSelfAction=false;$isSpawn=false;$isItem=AF_ACS_ItemActionService::isItem((string)($action['ability_key']??''));$item=null;
            if(!is_array($action)||array_diff(array_keys($action),['actor_id','ability_key','targets','parameters'])||!is_string($action['ability_key']??null)||!is_array($action['targets']??null)||!array_is_list($action['targets'])||!$action['targets']||count($action['targets'])>100||count(array_unique($action['targets']))!==count($action['targets']))throw new RuntimeException('builder_input_invalid');
            if(($action['parameters']??[])!==[])throw new RuntimeException('builder_parameters_unsupported');
            $actor=AF_ACS_Repository::integer($action['actor_id']??null);
            $category=$isItem?(str_starts_with($action['ability_key'],'item:consume:')?'item_consume':'equipment_change'):'ordinary';
            $grant=$this->repo->controllerFor($sid,$actor,$this->access->uid(),$category);AF_ACS_Control::action($grant,$action['ability_key']);if(!$grant)throw new RuntimeException('ACS_DELEGATION_ACTION_DENIED');
            if ($isItem) {
                if (str_starts_with($action['ability_key'],'item:pass:')) throw new RuntimeException('ACS_ITEM_PASS_SPATIAL_UNSUPPORTED');
                if (!in_array($actor,array_column($scope['actors'],'id'),true) || $action['targets']!==[$actor]) throw new RuntimeException('ACS_ITEM_TARGET_UNSUPPORTED');
                $item=(new AF_ACS_ItemActionService($this->repo,$this->access))->prepare($sid,$actor,$action['ability_key'],$sealedDraft,$actionIndex);
                $itemAbilities[$actor][$action['ability_key']]=$item['ability'];
                $definitions[$actionIndex]=$item['definition'];$itemSpecs[$actionIndex]=$item['spec'];
                $itemVirtual['items']=array_replace($itemVirtual['items'],$item['virtual']['items']);
                $itemVirtual['equipment']=array_replace($itemVirtual['equipment'],$item['virtual']['equipment']);
            }
            if($isSpatial){$spatial=($isCover?new AF_ACS_CoverService($this->repo,$this->access):new AF_ACS_SpatialService($this->repo,$this->access))->prepare($sid,$eid,$actor,$action['ability_key'],$action['targets'],$sealedDraft);$definitions[$actionIndex]=$spatial['definition'];$spatialAbilities[$actor][$action['ability_key']]=$spatial['ability'];}
            if($isObject){$object=(new AF_ACS_ObjectInteractionService($this->repo,$this->access))->prepare($sid,$eid,$actor,$action['ability_key'],$sealedDraft,$actionIndex);if($action['targets']!==[$object['definition']['object_interaction']['entity_id']])throw new RuntimeException('preview_target_forbidden');$definitions[$actionIndex]=$object['definition'];$objectAbilities[$actor][$action['ability_key']]=$object['inspection']['context']['abilities'][$actor][$action['ability_key']];$actionAbilities[$actionIndex]=$objectAbilities[$actor][$action['ability_key']];$objectBehavior[$actor][$action['targets'][0]]=['kind'=>'object','relationship'=>'neutral','active'=>true];}
            if(!$isItem&&!$isObject&&!$isSpatial){$dto=$q->sealDefinition($sid,$actor,$action['ability_key']);$isSpawn=AF_ACS_Spawn::hasAbility($dto);$isSelfAction=AF_ACS_Awareness::hasAbility($dto)||AF_ACS_Environment::hasMassAbility($dto);}
            if(($isSpawn||$isSelfAction) && $action['targets']!==[$actor])throw new RuntimeException('ACS_SPAWN_TARGET_UNSUPPORTED');
            $behaviorPolicy=$isSpatial?$spatial['ability']['target_policy']:($isObject?['kind'=>'object_only']:($isItem?['kind'=>'self_only']:AF_ACS_Control::targetPolicy($dto['data']['definition'])));
            $relationMap=[];$side=$rawScope['actors'][$actor]['side_id']??null;
            foreach($rawScope['targets'] as $t)$relationMap[$actor][$t['id']]=$actor===$t['id']?'self':($side===$t['side_id']?'allied':($pairs[$side][$t['side_id']]??'neutral'));
            $behaviorCandidates=AF_ACS_QueryService::behaviorCandidates($rawScope['targets'],$actor,$relationMap);if($isObject)$behaviorCandidates[$action['targets'][0]]=['kind'=>'object','relationship'=>'neutral','active'=>true];
            AF_ACS_Behavior::check($behavior,$actor,$action['ability_key'],$isSpawn?'spawn':$category,$action['targets'],$behaviorCandidates,$behaviorPolicy,['session_id'=>$sid,'encounter_id'=>$eid,'minimum_targets'=>($isItem||$isObject||$isSpatial)?1:($dto['data']['definition']['target_count']['min']??1)]);
            $targetVersions=[];$orderedTargets=[];foreach($action['targets'] as $id){$target=AF_ACS_Repository::integer($id);$orderedTargets[]=$target;
            if(!in_array($actor,array_column($scope['actors'],'id'),true))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            $visible=($isItem||$isSpawn||$isSelfAction||$isCover)&&$target===$actor;foreach($scope['targets'] as $t)if($t['id']===$target&&in_array($actor,$t['actors'],true))$visible=true;
            if(!$visible)throw new RuntimeException('preview_target_forbidden');
            $p=$isSpatial?$spatial['inspection']:($isObject?$object['inspection']:$q->inspect($sid,$eid,$actor,$target,$sealedDraft,true));
            $compiled=$p['context']['abilities'][$actor][$action['ability_key']]??null;
            $policy=$compiled['target_policy']??['kind'=>($isItem||$isSpawn||$isSelfAction)?'self_only':'hostile_only'];$relationship=$p['context']['relationships'][$actor][$target]??'';
            if(!AF_ACS_Control::allowed($policy,$relationship,$p['context']['reaction_kinds'][$target]??''))throw new RuntimeException('ACS_TARGET_POLICY_DENIED');
            $damaging=!empty($compiled['components']);foreach($compiled['statuses']??[] as $status)foreach($status['instance']['periodic']??[] as $tick)if($tick['kind']==='damage')$damaging=true;
            if($damaging&&in_array($relationship,['ally','self'],true))$warnings[]=['action_index'=>$actionIndex,'target_id'=>$target,'message'=>'Урон союзнику или себе: требуется явное подтверждение.'];
            if($damaging&&!in_array($relationship,['ally','self'],true))foreach($p['context']['reactions']??[] as $reaction){
                $redirect=$reaction['entity_id'];$redirectRelationship=$p['context']['relationships'][$actor][$redirect]??'';
                if($reaction['status']==='active'&&$reaction['enabled']&&($reaction['spec']['effect']['kind']??null)==='intercept'&&in_array($target,$reaction['protected_targets']??[$redirect],true)&&in_array($redirectRelationship,['ally','self'],true)&&in_array($redirect,$p['context']['redirect_targets'][$actor]??[],true)&&AF_ACS_Control::allowed($policy,$redirectRelationship,$p['context']['reaction_kinds'][$redirect]??'')){$warnings[]=['action_index'=>$actionIndex,'target_id'=>$target,'message'=>'Возможен урон союзнику при перенаправлении: требуется подтверждение.'];break;}
            }
            if($isSpawn){
                $counts=$this->repo->spawnCounts($sid,$eid);$p['versions']['spawn_counts_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'counts'=>$counts]));
                $p['context']['spawn_counts']=$counts;
                $entity=$this->repo->entity($sid,$actor);$participant=null;
                foreach($this->repo->participants($eid,'0',201) as $member)if($member['entity_id']===$actor)$participant=$member;
                if(!$participant)throw new RuntimeException('ACS_SPAWN_SCOPE_INVALID');
                $p['context']['spawn_sources'][$actor]=['kind'=>$entity['kind'],'control_group_id'=>$entity['control_group_id'],'side_id'=>$participant['side_id']];
            }
            if ($item) {
                $p['context']['abilities'][$actor][$action['ability_key']]=$item['ability'];
                $p['choices'][$action['ability_key']]=['supported'=>true];
                $p['versions']['item_dependencies']=$item['versions'];
                $p['context']['stats'][$actor]=$item['stats'];
            }
            foreach($p['context']['abilities'][$actor][$action['ability_key']]['components']??[] as $component)if($component['damage_type']==='elemental'&&!empty($p['context']['stats'][$actor]['element_damage_bonus']))throw new RuntimeException('preview_element_bonus_contract_unavailable');
            if(($p['choices'][$action['ability_key']]['supported']??false)!==true)throw new RuntimeException('builder_parameters_unsupported');
            if($context===null)$context=$p['context'];else foreach(['entities','groups'] as $f)$context['state'][$f]=array_replace($context['state'][$f],$p['context']['state'][$f]);
            foreach(['stats','defenses','turns','abilities','status_immunities','reaction_turns','reaction_kinds','behavior_targets','perception_actors'] as $f)$context[$f]=array_replace($context[$f],$p['context'][$f]);
            foreach($p['context']['relationships'] as $id=>$rels)$context['relationships'][$id]=array_replace($context['relationships'][$id]??[],$rels);
            foreach($p['context']['redirect_targets'] as $id=>$ids)$context['redirect_targets'][$id]=array_values(array_unique([...($context['redirect_targets'][$id]??[]),...$ids]));
            $context['allowed_targets'][$actor]=array_values(array_unique([...($context['allowed_targets'][$actor]??[]),$target]));
            if($isSpawn){$context['spawn_counts']=$p['context']['spawn_counts'];$context['spawn_sources'][$actor]=$p['context']['spawn_sources'][$actor];}
            $context['control_context']['assignments']=array_replace($context['control_context']['assignments']??[],$p['context']['control_context']['assignments']);
            $targetVersions[]=$p['versions'];}
            $versions=$targetVersions[0];if(count($orderedTargets)>1)$versions['selected_targets_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'versions'=>$targetVersions]));
            $ordered[]=['actor_id'=>$actor,'ability_key'=>$action['ability_key'],'targets'=>$orderedTargets,'scenario'=>$input['scenario']];$prepared[]=[$actor,$orderedTargets[0],$versions];
        }
        // Repeated object keys can have different sealed owner reservations at each action index.
        $context['action_abilities']=$actionAbilities;
        foreach($spatialAbilities as $actor=>$abilities)$context['abilities'][$actor]=array_replace($context['abilities'][$actor]??[],$abilities);
        foreach($objectAbilities as $actor=>$abilities){$context['abilities'][$actor]=array_replace($context['abilities'][$actor]??[],$abilities);$context['behavior_targets'][$actor]=array_replace($context['behavior_targets'][$actor]??[],$objectBehavior[$actor]);}
        foreach ($itemAbilities as $actor=>$abilities) $context['abilities'][$actor]=array_replace($context['abilities'][$actor]??[],$abilities);
        if ($itemSpecs) {$context['item_specs']=$itemSpecs;$context['item_state']=$itemVirtual;}
        $actionCosts=[];if(($context['state']['spatial']['pin']??null)!==null){$spatialSequence=(bool)$spatialAbilities;foreach($ordered as $a)if(isset($context['abilities'][$a['actor_id']][$a['ability_key']]['spatial_effect']))$spatialSequence=true;
            if($spatialSequence){$costPreview=AF_ACS_Resolver::resolve($context,$ordered);foreach($costPreview->serverPlan()['actions'] as $step)$actionCosts[]=$step['costs'];}
            else{$spatialCheck=$context['state']['spatial'];foreach($ordered as $a)foreach($a['targets'] as $target)AF_ACS_Spatial::check($spatialCheck,$a['actor_id'],$target,$context['abilities'][$a['actor_id']][$a['ability_key']]['range']??null);}
        }
        return ['action_costs'=>$actionCosts,'context'=>$context,'ordered'=>$ordered,'prepared'=>$prepared,'scope'=>$scope,'definitions'=>$definitions,'friendly_fire'=>AF_ACS_Control::friendlyFire($warnings,$prepared)];
    }
    public static function visibleState(array $state,array $scope):array
    {
        $visible=array_unique([...array_column($scope['actors'],'id'),...array_column($scope['targets'],'id')]);$groups=[];$entities=[];
        foreach($state['entities'] as $id=>$e)if(in_array((string)$id,$visible,true)){$groups[$e['group_id']]=true;$entities[]=['entity_id'=>(string)$id,'hp'=>$e['hp'],'max_hp'=>$e['max_hp'],'life_state'=>$e['life_state']];}
        return ['virtual_entities'=>$entities,'virtual_groups'=>array_intersect_key($state['groups'],$groups)];
    }
    public function preview(array $input):array
    {
        $this->access->csrf();$p=$this->prepare($input);$context=$p['context'];$ordered=$p['ordered'];$prepared=$p['prepared'];$scope=$p['scope'];
        $sid=$scope['session_id'];$eid=$scope['encounter_id'];$turn=$scope['turn']['id'];$q=new AF_ACS_QueryService($this->repo,$this->access);
        $friendlyFire=$p['friendly_fire'];$result=AF_ACS_Resolver::resolve($context,$ordered);$rows=[];
        if($this->prepare($input)['prepared']!==$prepared)throw new RuntimeException('builder_version_conflict');
        if($this->context($sid,$eid,$turn)['scope_token']!==$scope['scope_token'])throw new RuntimeException('builder_version_conflict');
        foreach($result->serverPlan()['actions'] as $p)$rows[]=['actor_id'=>$p['actor_id'],'target_id'=>$p['targets'][0],'costs'=>$p['costs'],'probabilities'=>$p['probabilities'],'components'=>$p['allocation']['components'],'damage'=>$p['allocation']['integer_budget']]+AF_ACS_Resolver::targetSummary($p);
        // GM-only numeric scope; no raw KB definitions, formula AST or private source snapshots.
        return ['conditional'=>true,'label'=>'Условный сценарий — не результат броска','scenario'=>$input['scenario'],'persistent'=>false,'actions'=>$rows,'friendly_fire'=>$friendlyFire,'scope_token'=>$scope['scope_token']]+self::visibleState($result->serverState(),$scope);
    }
}
