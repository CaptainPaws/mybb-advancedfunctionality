<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/EncounterService.php';
require_once __DIR__.'/../Domain/Resolver.php';

/** GM visibility scope only until per-player awareness exists. Control is still checked independently. */
final class AF_ACS_QueryService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access){}
    private static function fail(string $code):never{throw new AF_ACS_FormulaError($code,'preview');}
    private static function int(mixed $n):int{return (int)AF_ACS_Repository::integer((string)$n,'9007199254740991',true);}
    private function source(string $sid,string $id):array
    {
        $e=$this->repo->entity($sid,$id);if(!$e||!hash_equals($e['content_hash'],hash('sha256',$e['payload_json'])))self::fail('preview_source_invalid');
        $p=json_decode($e['payload_json'],true,64,JSON_THROW_ON_ERROR);$s=$p['source_snapshot']??null;
        if(($p['contract_id']??null)!=='IC-ACS.entity-registration.v1'||($p['schema_version']??null)!==1||($p['contract_version']??null)!=='1'||($p['engine_version']??null)!=='acs-registration-1'||($p['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||!is_array($s)||($s['ready']??null)!==true||($p['equipment_policy']??null)!=='already_included_do_not_reapply')self::fail('preview_source_invalid');
        if(!in_array($e['kind'],['character','pet','npc','monster'],true))self::fail('preview_source_invalid');
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
        foreach(['immunities','weaknesses','passive_effects','triggers','reflection','lifesteal','math_modifiers'] as $field)if(!empty($rules[$field]))self::fail('preview_effect_contract_unavailable');
        foreach($this->repo->entityResources($id) as $resource){
            if($resource['resource_key']!=='hp'||(string)$resource['current_value']!==(string)$e['hp']||(string)$resource['maximum_value']!==(string)$e['hp_max'])self::fail('preview_resource_contract_unavailable');
        }
        return ['row'=>$e,'registration'=>$p,'source'=>$s];
    }
    public function select(string $sid,string $eid):array
    {
        if(!af_is_addon_enabled('knowledgebase'))self::fail('preview_dependency_unavailable');
        $view=(new AF_ACS_EncounterService($this->repo,$this->access))->view($sid,$eid);
        if($view['session']['status']!=='active'||$view['encounter']['status']!=='active')self::fail('preview_encounter_not_active');
        $members=$this->repo->participants($eid,'0',201);if(count($members)>100)self::fail('preview_pagination_required');$actors=[];$targets=[];
        foreach($members as $m){if($m['participation']!=='engaged')continue;
            $visibility=json_decode($m['visibility_json'],true,16,JSON_THROW_ON_ERROR);if($visibility!==['schema_version'=>1,'scope'=>'gm'])continue;
            $targets[$m['entity_id']]=['id'=>$m['entity_id'],'name'=>$m['name'],'side_id'=>$m['side_id']];
            if($m['controller_uid']===$this->access->uid()&&($m['owner_uid']===null||$m['owner_uid']===$this->access->uid()))$actors[$m['entity_id']]=$targets[$m['entity_id']];
        }
        return ['view'=>$view,'actors'=>$actors,'targets'=>$targets,'members'=>$members];
    }
    public function inspect(string $sid,string $eid,string $actor,string $target):array
    {
        $selection=$this->select($sid,$eid);$this->repo->assertPreviewStorage();$view=$selection['view'];
        if(!isset($selection['actors'][$actor])||!isset($selection['targets'][$target])||$actor===$target)self::fail('preview_target_forbidden');
        $sideA=$selection['actors'][$actor]['side_id'];$sideB=$selection['targets'][$target]['side_id'];$hostile=false;
        foreach($this->repo->allRelations($eid) as $r)if(($r['side_a']===$sideA&&$r['side_b']===$sideB||$r['side_a']===$sideB&&$r['side_b']===$sideA)&&$r['relationship']==='hostile')$hostile=true;
        if(!$hostile)self::fail('preview_target_forbidden');
        $round=$this->repo->currentRound($view['encounter']);if(!$round||$round['status']!=='open')self::fail('preview_turn_unavailable');
        $sources=[$actor=>$this->source($sid,$actor),$target=>$this->source($sid,$target)];if($sources[$actor]['row']['life_state']!=='active')self::fail('preview_actor_unavailable');$turns=[];$groups=[];$versions=['session_version'=>$view['session']['session_version'],'encounter_version'=>$view['encounter']['encounter_version'],'round_version'=>(string)$round['round_version']];
        foreach($this->repo->allRoundTurns($round['id']) as $turn){
            foreach($sources as $id=>$source){$id=(string)$id;if($source['row']['control_group_id']!==$turn['control_group_id'])continue;
                $r=json_decode($turn['roster_json'],true,32,JSON_THROW_ON_ERROR);$fp=$r['fingerprint']??null;unset($r['fingerprint']);
                if(!is_string($fp)||!hash_equals($fp,hash('sha256',AF_ACS_Repository::json($r))))self::fail('preview_roster_invalid');
                $member=null;foreach($r['members']??[] as $m)if($m['entity_id']===$id)$member=$m;
                if(!$member||$member['content_hash']!==$source['row']['content_hash']||$member['template_snapshot_id']!==$source['row']['template_snapshot_id'])self::fail('preview_roster_invalid');
                $individual=null;foreach($this->repo->individualTurns([$turn['id']]) as $x)if($x['entity_id']===$id)$individual=$x;
                if(!$individual)self::fail('preview_turn_unavailable');
                $turns[$id]=['id'=>$turn['id'],'status'=>$turn['status'],'group_id'=>$turn['control_group_id'],'started'=>(string)$individual['start_done']==='1'&&(string)$individual['end_done']==='0'];
                $available=AF_ACS_Clock::availableAP(self::int($turn['ap_budget']),self::int($turn['ap_spent']),self::int($turn['pre_round_ap_commitment']));
                $group=$this->repo->restGroup($sid,$turn['control_group_id']);
                if((string)$group['group_version']!==(string)$r['group_version'])self::fail('preview_roster_invalid');
                $versions[$id===$actor?'actor_group_version':'target_group_version']=(string)$group['group_version'];
                $groups[$turn['control_group_id']]=['ap'=>['current'=>$available,'maximum'=>self::int($turn['ap_budget'])],'version'=>self::int($turn['turn_version'])];
                foreach(['entity_version','state_version','content_hash'] as $f)$versions[$id===$actor?'actor_'.$f:'target_'.$f]=$source['row'][$f];
                $versions[$id===$actor?'actor_turn_version':'target_turn_version']=$turn['turn_version'];
                $versions[$id===$actor?'actor_entity_turn_version':'target_entity_turn_version']=$individual['entity_turn_version'];
            }
        }
        if(count($turns)!==2||$turns[$actor]['status']!=='pending'||!$turns[$actor]['started'])self::fail('preview_turn_unavailable');
        (new AF_ACS_EncounterService($this->repo,$this->access))->clock()->available($sid,$turns[$actor]['id'],$actor);
        $this->repo->assertNoObligations($sid,$eid,$turns[$actor]['id']);
        $state=['schema_version'=>1,'entities'=>[],'groups'=>$groups,'barriers'=>[],'next_barrier_order'=>0];$stats=[];$defenses=[];$catalog=null;
        foreach($sources as $id=>$source){$id=(string)$id;$row=$source['row'];$s=$source['source'];$stat=$s['characteristics']??null;if(!is_array($stat))self::fail('preview_stats_missing');
            foreach(['accuracy','speed','crit_rate','crit_dmg','def'] as $name)AF_ACS_Numeric::number($stat[$name]??null);$stats[$id]=$stat;
            $ref=['key'=>'entity-'.$id,'version'=>'registration-1','fingerprint'=>'sha256:'.$row['content_hash']];
            $state['entities'][$id]=['hp'=>self::int($row['hp']),'max_hp'=>self::int($row['hp_max']),'life_state'=>$row['life_state'],'recovery_blocked'=>false,'group_id'=>$row['control_group_id'],'resources'=>[],'source_ref'=>$ref,'version'=>self::int($row['state_version'])];
            $res=[];foreach($s['resistances']??[] as $key=>$r)$res[$key]=['scope'=>'elemental','unit'=>'RES','value'=>$r['value']];
            $defenses[$id]=['def'=>$stat['def'],'resistances'=>$res,'immunities'=>[],'vulnerabilities'=>[]];
            if(($s['element_registry_version']??null)==='primary-elements-17-v1'||($s['capabilities']['canonical_elements_17']['readiness']??null)==='READY')$catalog=AF_ACS_Damage::retainedCatalog($row['payload_json'],$row['content_hash']);
            foreach($this->repo->allCooldowns($sid,$id) as $cd)if((int)$cd['remaining_turns']>0)self::fail('preview_cooldown_contract_unavailable');
        }
        if($catalog===null)self::fail('preview_retained_registry_unavailable');
        $abilities=[];$choices=[];
        $grants=$this->grants($sources[$actor]['source']);
        foreach($grants as $key=>$grant){
            $dto=$sources[$actor]['registration']['pinned_abilities'][$key]??null;
            if(!is_array($dto)||($dto['definition_version']['fingerprint']??null)!==$grant['fingerprint'])self::fail('preview_ability_pin_mismatch');
            foreach(['revision','declared_version'] as $field)if(($dto['definition_version'][$field]??null)!==($grant['definition_version'][$field]??null))self::fail('preview_ability_pin_mismatch');
            try{foreach($grant['_profiles']??[] as $inline)$this->inline($inline,$dto['data']['definition']);$abilities[$key]=$this->ability($dto);$choices[$key]=['key'=>$key,'supported'=>true,'label'=>is_string($dto['data']['definition']['name']??null)?mb_substr($dto['data']['definition']['name'],0,191):$key];}
            catch(DomainException $e){$choices[$key]=['key'=>$key,'supported'=>false,'label'=>$key,'reason'=>$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage()];}
        }
        return ['selection'=>$selection,'versions'=>$versions,'choices'=>$choices,'context'=>['schema_version'=>1,'state'=>$state,'stats'=>$stats,'defenses'=>$defenses,'element_catalog'=>$catalog,'turns'=>$turns,
            'encounter_status'=>'active','abilities'=>[$actor=>$abilities],'allowed_targets'=>[$actor=>[$target]]]];
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
        $policy=AF_ACS_HitCritPolicy::fromDefinition($dto,$dto['source'],$dto['definition_version']);$d=$dto['data']['definition'];
        $fields=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','range','cast_time','cooldown','cooldown_value','cooldown_unit','duration','duration_value','duration_unit','max_charges','level_cap','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','action_cost','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','magic_direction_ref','damage_element','element','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),$fields))self::fail('preview_ability_contract_unavailable');
        if(!empty($d['target_count'])&&$d['target_count']!==['min'=>1,'max'=>1])self::fail('preview_ability_unsupported');
        if(($d['type']??'active')!=='active'||($d['targeting']??null)!=='single_enemy')self::fail('preview_ability_unsupported');
        foreach(['resources','costs','timing','usage_limits','application_conditions','summon','math_modifiers','modifiers','base_stats','conditions','stacking','grants','immunities','upgrade_requirements','application_mode','damage_value','heal_value','shield_value','formula_refs','formula_ref','triggers','passive_effects','reflection','lifesteal'] as $f)if(!empty($d[$f]))self::fail('preview_ability_contract_unavailable');
        foreach(['range','cast_time','cooldown','cooldown_value','max_charges','duration','duration_value','cost_value'] as $f)if(!empty($d[$f]))self::fail('preview_ability_contract_unavailable');
        $cost=$d['action_cost']??1;if(!is_int($cost)||$cost<1)self::fail('preview_action_cost_unsupported');
        $parts=[];foreach($dto['data']['ability_profile']['effects']??[] as $effect){$p=$effect['parameters'];
            $fields=['kind','effect_type','target','targeting','value','value_mode','damage_type','damage_element','element','formula_profile','formula_ref','condition_ref','duration','duration_value','duration_unit','status_key','resource_key','stat_key','operation','hit_count','coefficient','can_crit','magic_direction_ref','notes'];
            if(array_diff(array_keys($p),$fields))self::fail('preview_effect_unsupported');
            if(array_key_exists('hit_count',$p)&&!in_array($p['hit_count'],['',null,1],true))self::fail('preview_effect_unsupported');
            if(array_key_exists('coefficient',$p)&&!in_array($p['coefficient'],['',null],true))self::fail('preview_effect_unsupported');
            if(($p['effect_type']??null)!=='damage')self::fail('preview_effect_unsupported');
            foreach(['condition_ref','duration','duration_value','status_key','resource_key','stat_key','operation'] as $f)if(!empty($p[$f]))self::fail('preview_effect_unsupported');
            if(($p['target']??'single_enemy')!=='single_enemy'||!in_array($p['value_mode']??'flat',['flat',''],true))self::fail('preview_effect_unsupported');
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
        if(!$parts)self::fail('preview_damage_value_missing');
        if(!is_string($dto['definition_version']['declared_version']??null))self::fail('preview_source_version_unavailable');
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],
            'components'=>$parts,'costs'=>[['kind'=>'ap','amount'=>$cost]],'hit_policy'=>$policy];
    }
    public function preview(array $input):array
    {
        $this->access->csrf();$allowed=['session_id','encounter_id','actor_id','target_id','ability_key','scenario','versions'];if(array_diff(array_keys($input),$allowed))self::fail('preview_input_invalid');
        if(!is_string($input['ability_key']??null)||!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$input['ability_key']))self::fail('preview_input_invalid');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$eid=AF_ACS_Repository::integer($input['encounter_id']??null);$actor=AF_ACS_Repository::integer($input['actor_id']??null);$target=AF_ACS_Repository::integer($input['target_id']??null);
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
