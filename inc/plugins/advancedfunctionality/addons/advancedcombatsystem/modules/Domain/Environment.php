<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';
require_once __DIR__.'/Control.php';
require_once __DIR__.'/CausalGraph.php';
require_once __DIR__.'/ObjectInteraction.php';

/** Detached object projection. No clock, callbacks, SQL, live KB or random sampling. */
final class AF_ACS_Environment
{
    private static function fail(string $code='ACS_OBJECT_UNSUPPORTED'):never {throw new DomainException($code);}
    private static function fields(array $v,array $keys):void
    {if(array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::fail();}
    private static function integer(mixed $n,int $minimum=0):int
    {if(!is_int($n)||$n<$minimum||$n>9007199254740991)self::fail('ACS_OBJECT_VALUE_INVALID');return $n;}
    public static function definition(array $o):array
    {
        $interactions=AF_ACS_ObjectInteraction::definition($o['interactions']??null);unset($o['interactions']);
        require_once __DIR__.'/Awareness.php';AF_ACS_Awareness::perception($o['perception']??null);unset($o['perception']);
        if(($o['schema']??null)==='ic-acs.object-template.v1'){
            self::fields($o,['schema','name','properties']);$mechanics=['categories'=>['passive'],'initial_status'=>'active','durability'=>null,'passive'=>[],'triggers'=>[],'gm_operations'=>[]];
        }else{
            self::fields($o,['schema','name','properties','mechanics']);if($o['schema']!=='ic-acs.object-template.v2')self::fail();$mechanics=$o['mechanics'];
        }
        self::fields($o['properties'],['description','tags']);
        if(!is_string($o['name'])||trim($o['name'])===''||mb_strlen($o['name'])>191||!is_string($o['properties']['description'])||strlen($o['properties']['description'])>4096
            ||!is_array($o['properties']['tags'])||!array_is_list($o['properties']['tags'])||count($o['properties']['tags'])>32)self::fail();
        foreach($o['properties']['tags'] as $tag)if(!is_string($tag)||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$tag))self::fail();
        self::fields($mechanics,['categories','initial_status','durability','passive','triggers','gm_operations']);$c=$mechanics['categories'];
        if(!is_array($c)||!array_is_list($c)||!$c||count(array_unique($c))!==count($c)||array_diff($c,['passive','triggered','interactive','destructible'])||!in_array($mechanics['initial_status'],['active','inactive'],true))self::fail();
        foreach(['passive','triggers','gm_operations'] as $key)if(!is_array($mechanics[$key])||!array_is_list($mechanics[$key])||count($mechanics[$key])>16)self::fail();
        if($mechanics['passive']&&!in_array('passive',$c,true)||$mechanics['triggers']&&!in_array('triggered',$c,true))self::fail();
        if(in_array('destructible',$c,true)){
            $d=$mechanics['durability'];if(!is_array($d))self::fail();self::fields($d,['maximum','damage_types']);self::integer($d['maximum'],1);
            if(!is_array($d['damage_types'])||!array_is_list($d['damage_types'])||!$d['damage_types']||array_diff($d['damage_types'],['physical','elemental','true'])||count(array_unique($d['damage_types']))!==count($d['damage_types']))self::fail();
        }elseif($mechanics['durability']!==null)self::fail();
        if(array_diff($mechanics['gm_operations'],['create','activate','deactivate','damage'])||count(array_unique($mechanics['gm_operations']))!==count($mechanics['gm_operations']))self::fail();
        if(array_intersect($mechanics['gm_operations'],['activate','deactivate'])&&!in_array('interactive',$c,true)||in_array('damage',$mechanics['gm_operations'],true)&&!in_array('destructible',$c,true))self::fail();
        if($interactions!==null&&(($o['schema']??null)!=='ic-acs.object-template.v2'||!in_array('interactive',$c,true)))self::fail();
        foreach($interactions['actions']??[] as $action)foreach($action['outcomes'] as $outcome)if($outcome['repair']>0&&$mechanics['durability']===null)self::fail();
        $keys=[];
        foreach(['passive','triggers'] as $kind)foreach($mechanics[$kind] as $entry){
            if(!is_array($entry))self::fail();self::fields($entry,$kind==='triggers'?['key','event','once','effect']:['key','event','effect']);
            if(!is_string($entry['key'])||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$entry['key'])||isset($keys[$entry['key']])||!in_array($entry['event'],['source_turn_start','source_turn_end'],true))self::fail();$keys[$entry['key']]=true;
            if($kind==='triggers'&&$entry['once']!==true)self::fail();
            $effect=$entry['effect'];
            // Explicit fixed-roll subset. No implicit proc samples or arbitrary Effects operations.
            if(($effect['kind']??null)==='heal'){
                self::fields($effect,['kind','target','amount']);if($effect['target']!=='source')self::fail('ACS_OBJECT_EFFECT_UNSUPPORTED');
            }elseif(($effect['kind']??null)==='damage_object'&&$kind==='triggers'){
                self::fields($effect,['kind','target','amount','damage_type']);
                if($effect['target']!=='self'||!in_array($effect['damage_type'],$mechanics['durability']['damage_types']??[],true))self::fail('ACS_OBJECT_EFFECT_UNSUPPORTED');
            }else self::fail('ACS_OBJECT_EFFECT_UNSUPPORTED');
            self::integer($effect['amount'],1);
        }
        return $mechanics;
    }
    public static function hasMassAbility(array $dto):bool {return array_key_exists('hidden_mass_effect',$dto['data']['definition']??[]);}
    /** Explicit non-spatial object pulse. Hidden recipients are server-selected and never become action targets. */
    public static function compileMass(array $dto):array
    {
        $d=$dto['data']['definition'];$m=$d['hidden_mass_effect'];self::fields($m,['schema','tag','amount','damage_type']);
        if($m['schema']!=='ic-acs.hidden-object-mass.v1'||!is_string($m['tag'])||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$m['tag'])||!in_array($m['damage_type'],['physical','elemental','true'],true))self::fail();self::integer($m['amount'],1);
        $metadata=['schema','type_profile','version','revision','name','title','description','notes','icon_url','icon_class','sortorder','active','type','subtype','slot','damage_type','targeting','cooldown_unit','duration_unit','level_cap','magic_direction_ref','damage_element','element','target_policy','action_cost','hidden_mass_effect'];
        $empty=['range','cast_time','cooldown','cooldown_value','duration','duration_value','max_charges','resources','effects','modifiers','base_stats','triggers','conditions','stacking','upgrade_requirements','cost_value','cost_resource','costs','formula_profile','formula_ref','formula_refs','target_count','application_conditions','application_mode','summon','usage_limits','timing','math_modifiers','passive_effects','reflection','lifesteal','grants','immunities','can_crit','auto_hit','damage_value','heal_value','shield_value'];
        if(array_diff(array_keys($d),[...$metadata,...$empty])||($d['type']??null)!=='active'||($d['targeting']??null)!=='self'||AF_ACS_Control::targetPolicy($d)!==['kind'=>'self_only']||!is_int($d['action_cost']??null)||$d['action_cost']<1||$d['action_cost']>2147483647||($dto['ready']??null)!==true||!is_string($dto['definition_version']['declared_version']??null)||$dto['definition_version']['declared_version']==='')self::fail();foreach($empty as $key)if(!empty($d[$key]))self::fail();
        return ['supported'=>true,'source_ref'=>['key'=>$dto['source']['key'],'version'=>$dto['definition_version']['declared_version'],'fingerprint'=>$dto['definition_version']['fingerprint']],'target_policy'=>['kind'=>'self_only'],'components'=>[],'costs'=>[['kind'=>'ap','amount'=>$d['action_cost']]],'targeting'=>'self','hidden_mass_effect'=>$m];
    }
    public static function mass(array $state,array $spec):array
    {
        $effects=[];foreach($state['perception_subjects']??[] as $subject){if($subject['kind']!=='object'||!in_array($spec['tag'],$subject['perception']['tags']??[],true))continue;$v=$state['objects'][$subject['id']];$m=self::definition($v['definition']);
            if(in_array($v['status'],['expired','destroyed'],true)||!in_array($spec['damage_type'],$m['durability']['damage_types']??[],true))continue;
            $effects[]=['kind'=>'object_damage','entity_id'=>$subject['id'],'expected_version'=>$v['object_version'],'amount'=>$spec['amount'],'damage_type'=>$spec['damage_type']];
        }
        return AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>$effects])->serverState();
    }
    public static function initial(string $entity,string $encounter,string $source,array $definition,array $duration):array
    {
        $m=self::definition($definition);
        if(($definition['interactions']['progress']['decay_per_round']??0)>0&&($duration['unit']??null)!=='round')self::fail('ACS_INTERACTION_UNSUPPORTED');
        if(!in_array($duration['unit']??null,['round','own_turn'],true))self::fail();self::integer($duration['value']??null,1);
        return ['schema_version'=>1,'entity_id'=>$entity,'encounter_id'=>$encounter,'source_entity_id'=>$source,'definition'=>$definition,'status'=>$m['initial_status'],'object_version'=>1,
            'durability'=>$m['durability']['maximum']??null,'interaction'=>['schema_version'=>1,'activation'=>$m['initial_status']]+(isset($definition['interactions'])?['typed'=>AF_ACS_ObjectInteraction::initial($definition['interactions'])]:[]),'remaining'=>$duration['value'],'unit'=>$duration['unit'],
            'last_boundary'=>null,'fired'=>[],'passive_boundaries'=>[]];
    }
    public static function instance(array $v):void
    {
        self::fields($v,['schema_version','entity_id','encounter_id','source_entity_id','definition','status','object_version','durability','interaction','remaining','unit','last_boundary','fired','passive_boundaries']);
        foreach(['entity_id','encounter_id','source_entity_id'] as $key)if(!is_string($v[$key])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v[$key]))self::fail('ACS_OBJECT_IDENTITY_INVALID');
        if($v['schema_version']!==1)self::fail();
        $m=self::definition($v['definition']);self::integer($v['object_version'],1);self::integer($v['remaining']);
        if(!in_array($v['status'],['active','inactive','expired','destroyed'],true)||!in_array($v['unit'],['round','own_turn'],true)||!in_array(array_diff_key($v['interaction'],['typed'=>true]),[['schema_version'=>1,'activation'=>'active'],['schema_version'=>1,'activation'=>'inactive']],true))self::fail('ACS_OBJECT_STATE_INVALID');
        if(isset($v['definition']['interactions']))AF_ACS_ObjectInteraction::validate($v['interaction']['typed']??[],$v['definition']['interactions']);elseif(isset($v['interaction']['typed']))self::fail();
        if($m['durability']===null?$v['durability']!==null:(!is_int($v['durability'])||$v['durability']<0||$v['durability']>$m['durability']['maximum']))self::fail('ACS_OBJECT_STATE_INVALID');
        if(($v['status']==='destroyed')!==($v['durability']===0)||($v['status']==='expired'&&$v['remaining']!==0)||($v['remaining']===0&&!in_array($v['status'],['expired','destroyed'],true)))self::fail('ACS_OBJECT_STATE_INVALID');
        if(!is_array($v['fired'])||!is_array($v['passive_boundaries']))self::fail('ACS_OBJECT_STATE_INVALID');
        $triggerKeys=array_column($m['triggers'],'key');$passiveKeys=array_column($m['passive'],'key');
        foreach(['fired'=>$triggerKeys,'passive_boundaries'=>$passiveKeys] as $field=>$allowed)foreach($v[$field] as $key=>$stamp)if(!in_array($key,$allowed,true)||!is_string($stamp)||!preg_match('/\Aentity_turn:[1-9][0-9]*:(start|end)\z/D',$stamp))self::fail('ACS_OBJECT_STATE_INVALID');
        if(in_array($v['status'],['active','inactive'],true)&&$v['interaction']['activation']!==$v['status'])self::fail('ACS_OBJECT_STATE_INVALID');
        if($v['last_boundary']!==null&&(!is_string($v['last_boundary'])||!preg_match($v['unit']==='round'?'/\Around:[1-9][0-9]*:next\z/D':'/\Aentity_turn:[1-9][0-9]*:end\z/D',$v['last_boundary'])))self::fail('ACS_OBJECT_STATE_INVALID');
    }
    public static function operation(array $v,string $operation,int $expected,?int $amount=null,?string $damageType=null):array
    {
        self::instance($v);if($v['object_version']!==$expected)self::fail('ACS_OBJECT_VERSION_CONFLICT');$m=self::definition($v['definition']);
        if(!in_array($operation,['activate','deactivate','damage'],true)||!in_array($operation,$m['gm_operations'],true))self::fail();
        if(in_array($v['status'],['expired','destroyed'],true))self::fail('ACS_OBJECT_TERMINAL');
        if($operation==='damage')return self::damage($v,$expected,$amount,$damageType);
        if($amount!==null||$damageType!==null)self::fail();$status=$operation==='activate'?'active':'inactive';if($v['status']===$status)self::fail('ACS_OBJECT_STATE_CONFLICT');
        $v['status']=$status;$v['interaction']['activation']=$status;
        ++$v['object_version'];return $v;
    }
    /** Already allocated fixed durability damage; never invent HP or resistance statistics. */
    public static function damage(array $v,int $expected,?int $amount,?string $damageType):array
    {
        self::instance($v);if($v['object_version']!==$expected)self::fail('ACS_OBJECT_VERSION_CONFLICT');
        if(in_array($v['status'],['expired','destroyed'],true))self::fail('ACS_OBJECT_TERMINAL');$m=self::definition($v['definition']);
        self::integer($amount,1);if(!in_array($damageType,$m['durability']['damage_types']??[],true))self::fail('ACS_OBJECT_DAMAGE_UNSUPPORTED');
        $v['durability']=max(0,$v['durability']-$amount);if($v['durability']===0)$v['status']='destroyed';++$v['object_version'];return $v;
    }
    /** Called by Clock only. Fixed effects pass through Effects, and never emit another object cause. */
    public static function boundary(array $state,array $boundary,string $key,array &$events):array
    {
        foreach($state['objects']??[] as $id=>$v){
            self::instance($v);if($v['encounter_id']!==$boundary['encounter_id']||in_array($v['status'],['expired','destroyed'],true))continue;$before=$v;$m=self::definition($v['definition']);
            if($v['status']==='active'&&($boundary['entity_id']??null)===$v['source_entity_id'])foreach(['passive','triggers'] as $kind)foreach($m[$kind] as $entry){
                $phase=$entry['event']==='source_turn_start'?'entity_start':'entity_end';$field=$kind==='triggers'?'fired':'passive_boundaries';
                if($v['status']!=='active'||$boundary['phase']!==$phase||($kind==='triggers'?isset($v[$field][$entry['key']]):($v[$field][$entry['key']]??null)===$key))continue;
                $target=$v['source_entity_id'];if(!isset($state['entities'][$target])||$state['entities'][$target]['life_state']==='dead')continue;
                $cause='object:'.$id.':'.$entry['key'].':'.$key;
                AF_ACS_CausalGraph::order([$key=>['children'=>[$cause]],$cause=>['children'=>[]]],[$key],2);
                $effect=$entry['effect'];
                $op=$effect['kind']==='heal'?['kind'=>'heal','entity_id'=>$target,'amount'=>$effect['amount']]:['kind'=>'object_damage','entity_id'=>(string)$id,'expected_version'=>$v['object_version'],'amount'=>$effect['amount'],'damage_type'=>$effect['damage_type']];
                $state['objects'][$id]=$v;$r=AF_ACS_Effects::simulate($state,['costs'=>[],'effects'=>[$op]]);$state=$r->serverState();$v=$state['objects'][$id];
                $v[$field][$entry['key']]=$key;$events[]=['type'=>$kind==='triggers'?'object.triggered':'object.passive','entity_id'=>(string)$id,'cause_id'=>$cause,'source_event'=>$key,'effects'=>$r->serverTrace()];
            }
            $eligible=$v['unit']==='round'?$boundary['phase']==='round_end':($boundary['phase']==='entity_end'&&($boundary['entity_id']??null)===$v['source_entity_id']);
            if($v['status']!=='destroyed'&&$eligible&&$v['last_boundary']!==$key){$v['last_boundary']=$key;$v['remaining']=max(0,$v['remaining']-1);if($v['unit']==='round'&&isset($v['interaction']['typed'])&&($decay=$v['definition']['interactions']['progress']['decay_per_round']??0)>0)$v['interaction']['typed']['progress']=max(0,$v['interaction']['typed']['progress']-$decay);if($v['remaining']===0)$v['status']='expired';}
            if($v!==$before){$v['object_version']=$before['object_version']+1;$state['objects'][$id]=$v;$events[]=['type'=>'object.boundary','entity_id'=>(string)$id,'before'=>$before,'after'=>$v];}
        }
        return $state;
    }
}
