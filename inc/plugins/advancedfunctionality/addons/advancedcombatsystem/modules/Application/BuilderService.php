<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/QueryService.php';
require_once __DIR__.'/DraftService.php';
require_once __DIR__.'/ItemActionService.php';

/** Explicit UI queries and a single ordered virtual simulation. No mechanics commands. */
final class AF_ACS_BuilderService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    public static function message(Throwable $e):string
    {
        $code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
        return match($code){
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
            if(!$members||$t['owner_uid']!==null&&$t['owner_uid']!==$this->access->uid())continue;
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
            try {$choices=array_merge($choices,(new AF_ACS_ItemActionService($this->repo,$this->access))->catalog($sid,$actor['id']));} catch (Throwable $e) {$notice=$notice?:self::message($e);}
            foreach ($retained[$actor['id']]??[] as $choice) if (!in_array($choice['key'],array_column($choices,'key'),true)) $choices[]=$choice;
            $actors[]=['id'=>$actor['id'],'name'=>$actor['name'],'choices'=>$choices,'catalog_notice'=>$notice];
        }
        $targets=[];$hostile=[];foreach($this->repo->allRelations($eid) as $r)if($r['relationship']==='hostile'){$hostile[$r['side_a']][$r['side_b']]=true;$hostile[$r['side_b']][$r['side_a']]=true;}
        foreach($scope['targets'] as $target){$allowed=[];
            foreach($chosen['members'] as $actor){if($actor['id']===$target['id'])continue;
                if(isset($hostile[$actor['side_id']][$target['side_id']]))$allowed[]=$actor['id'];
            }
            if($allowed)$targets[]=['id'=>$target['id'],'name'=>$target['name'],'actors'=>$allowed];
        }
        foreach ($chosen['members'] as $member) if (array_filter($actors,fn($a)=>$a['id']===$member['id'] && array_filter($a['choices'],fn($c)=>!empty($c['item_action'])))) $targets[]=['id'=>$member['id'],'name'=>$member['name'].' (себя)','actors'=>[$member['id']],'item_only'=>true];
        $token=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'session'=>$scope['session']['session_version'],'encounter'=>$scope['encounter']['encounter_version'],'round'=>$scope['round']['round_version'],'turns'=>$versions,'selected'=>$selected]));
        return ['session_id'=>$sid,'encounter_id'=>$eid,'tid'=>$scope['session']['tid'],'author_uid'=>$this->access->uid(),'round'=>(string)$scope['round']['round_number'],'encounter_status'=>$scope['encounter']['status'],'turns'=>$turns,'turn'=>$chosen,'actors'=>$actors,'targets'=>$targets,'scope_token'=>$token,'draft_id'=>$draft,'preview_scope'=>$scope['gm']?'gm':'own_draft_only','publication_enabled'=>false];
    }
    public function prepare(array $input,?string $sealedDraft=null):array
    {

        if(array_diff(array_keys($input),['session_id','encounter_id','turn_id','scope_token','actions','scenario'])||!is_array($input['actions']??null)||!array_is_list($input['actions'])||!count($input['actions'])||count($input['actions'])>20||!in_array($input['scenario']??null,['hit','crit','miss'],true))throw new RuntimeException('builder_input_invalid');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$turn=AF_ACS_Repository::integer($input['turn_id']??null);
        $scope=$this->context($sid,$eid,$turn);
        if(!is_string($input['scope_token']??null)||!hash_equals($scope['scope_token'],$input['scope_token']))throw new RuntimeException('builder_version_conflict');
        $q=new AF_ACS_QueryService($this->repo,$this->access);$prepared=[];$ordered=[];$context=null;$definitions=[];$itemSpecs=[];$itemAbilities=[];$itemVirtual=['items'=>[],'equipment'=>[]];
        foreach($input['actions'] as $actionIndex=>$action){
            $isItem=AF_ACS_ItemActionService::isItem((string)($action['ability_key']??''));$item=null;
            if(!is_array($action)||array_diff(array_keys($action),['actor_id','ability_key','targets','parameters'])||!is_string($action['ability_key']??null)||!is_array($action['targets']??null)||!array_is_list($action['targets'])||!$action['targets']||count($action['targets'])>100||count(array_unique($action['targets']))!==count($action['targets']))throw new RuntimeException('builder_input_invalid');
            if(($action['parameters']??[])!==[])throw new RuntimeException('builder_parameters_unsupported');
            $actor=AF_ACS_Repository::integer($action['actor_id']??null);
            if ($isItem) {
                if (str_starts_with($action['ability_key'],'item:pass:')) throw new RuntimeException('ACS_ITEM_PASS_SPATIAL_UNSUPPORTED');
                if (!in_array($actor,array_column($scope['actors'],'id'),true) || $action['targets']!==[$actor]) throw new RuntimeException('ACS_ITEM_TARGET_UNSUPPORTED');
                $item=(new AF_ACS_ItemActionService($this->repo,$this->access))->prepare($sid,$actor,$action['ability_key'],$sealedDraft,$actionIndex);
                $itemAbilities[$actor][$action['ability_key']]=$item['ability'];
                $definitions[$actionIndex]=$item['definition'];$itemSpecs[$actionIndex]=$item['spec'];
                $itemVirtual['items']=array_replace($itemVirtual['items'],$item['virtual']['items']);
                $itemVirtual['equipment']=array_replace($itemVirtual['equipment'],$item['virtual']['equipment']);
            }
            $targetVersions=[];$orderedTargets=[];foreach($action['targets'] as $id){$target=AF_ACS_Repository::integer($id);$orderedTargets[]=$target;
            if(!in_array($actor,array_column($scope['actors'],'id'),true))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            $visible=$isItem&&$target===$actor;foreach($scope['targets'] as $t)if($t['id']===$target&&in_array($actor,$t['actors'],true))$visible=true;
            if(!$visible)throw new RuntimeException('preview_target_forbidden');
            $p=$q->inspect($sid,$eid,$actor,$target,$sealedDraft,$isItem);
            if ($item) {
                $p['context']['abilities'][$actor][$action['ability_key']]=$item['ability'];
                $p['choices'][$action['ability_key']]=['supported'=>true];
                $p['versions']['item_dependencies']=$item['versions'];
                $p['context']['stats'][$actor]=$item['stats'];
            }
            foreach($p['context']['abilities'][$actor][$action['ability_key']]['components']??[] as $component)if($component['damage_type']==='elemental'&&!empty($p['context']['stats'][$actor]['element_damage_bonus']))throw new RuntimeException('preview_element_bonus_contract_unavailable');
            if(($p['choices'][$action['ability_key']]['supported']??false)!==true)throw new RuntimeException('builder_parameters_unsupported');
            if($context===null)$context=$p['context'];else foreach(['entities','groups'] as $f)$context['state'][$f]=array_replace($context['state'][$f],$p['context']['state'][$f]);
            foreach(['stats','defenses','turns','abilities','status_immunities','reaction_turns','reaction_kinds'] as $f)$context[$f]=array_replace($context[$f],$p['context'][$f]);
            foreach($p['context']['relationships'] as $id=>$rels)$context['relationships'][$id]=array_replace($context['relationships'][$id]??[],$rels);
            foreach($p['context']['redirect_targets'] as $id=>$ids)$context['redirect_targets'][$id]=array_values(array_unique([...($context['redirect_targets'][$id]??[]),...$ids]));
            $context['allowed_targets'][$actor]=array_values(array_unique([...($context['allowed_targets'][$actor]??[]),$target]));
            $targetVersions[]=$p['versions'];}
            $versions=$targetVersions[0];if(count($orderedTargets)>1)$versions['selected_targets_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'versions'=>$targetVersions]));
            $ordered[]=['actor_id'=>$actor,'ability_key'=>$action['ability_key'],'targets'=>$orderedTargets,'scenario'=>$input['scenario']];$prepared[]=[$actor,$orderedTargets[0],$versions];
        }
        foreach ($itemAbilities as $actor=>$abilities) $context['abilities'][$actor]=array_replace($context['abilities'][$actor]??[],$abilities);
        if ($itemSpecs) {$context['item_specs']=$itemSpecs;$context['item_state']=$itemVirtual;}
        return ['context'=>$context,'ordered'=>$ordered,'prepared'=>$prepared,'scope'=>$scope,'definitions'=>$definitions];
    }
    public function preview(array $input):array
    {
        $this->access->csrf();$p=$this->prepare($input);$context=$p['context'];$ordered=$p['ordered'];$prepared=$p['prepared'];$scope=$p['scope'];
        $sid=$scope['session_id'];$eid=$scope['encounter_id'];$turn=$scope['turn']['id'];$q=new AF_ACS_QueryService($this->repo,$this->access);
        $result=AF_ACS_Resolver::resolve($context,$ordered);$rows=[];
        if($this->prepare($input)['prepared']!==$prepared)throw new RuntimeException('builder_version_conflict');
        if($this->context($sid,$eid,$turn)['scope_token']!==$scope['scope_token'])throw new RuntimeException('builder_version_conflict');
        foreach($result->serverPlan()['actions'] as $p)$rows[]=['actor_id'=>$p['actor_id'],'target_id'=>$p['targets'][0],'costs'=>$p['costs'],'probabilities'=>$p['probabilities'],'components'=>$p['allocation']['components'],'damage'=>$p['allocation']['integer_budget']]+AF_ACS_Resolver::targetSummary($p);
        // GM-only numeric scope; no raw KB definitions, formula AST or private source snapshots.
        $states=[];foreach($result->serverState()['entities'] as $id=>$state)$states[]=['entity_id'=>(string)$id,'hp'=>$state['hp'],'max_hp'=>$state['max_hp'],'life_state'=>$state['life_state']];
        return ['conditional'=>true,'label'=>'Условный сценарий — не результат броска','scenario'=>$input['scenario'],'persistent'=>false,'actions'=>$rows,'virtual_entities'=>$states,'virtual_groups'=>$result->serverState()['groups'],'scope_token'=>$scope['scope_token']];
    }
}
