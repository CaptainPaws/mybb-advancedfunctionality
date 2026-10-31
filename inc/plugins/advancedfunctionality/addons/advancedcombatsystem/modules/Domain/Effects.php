<?php
declare(strict_types=1);
require_once __DIR__.'/Damage.php';

/** A single action on a detached virtual state. No globals, time, RNG, SQL or user callbacks. */
final class AF_ACS_Effects
{
    public const VERSION='acs-effects-1';
    private static function fail(string $code): never {throw new AF_ACS_FormulaError($code,'effects');}
    private static function id(mixed $id): string
    {
        if ((!is_string($id)&&!is_int($id)) || !preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$id)
            || (strlen((string)$id)===20 && strcmp((string)$id,'18446744073709551615')>0)) self::fail('effects_identity_invalid');
        return (string)$id;
    }
    private static function order(string|int $a,string|int $b): int {return strlen((string)$a)<=>strlen((string)$b) ?: strcmp((string)$a,(string)$b);}
    private static function fields(array $row,array $allowed): void {if(array_diff(array_keys($row),$allowed))self::fail('effects_schema_invalid');}
    private static function integer(mixed $value): int {return AF_ACS_Numeric::integer($value);}
    private static function ref(mixed $ref): void
    {
        if (!is_array($ref) || !is_string($ref['key']??null) || !preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$ref['key']) || !is_string($ref['version']??null) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D',$ref['version'])
            || !is_string($ref['fingerprint']??null) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$ref['fingerprint'])) self::fail('effects_source_invalid');
        self::fields($ref,['key','version','fingerprint']);
    }
    private static function resource(mixed $r): void
    {
        if(!is_array($r))self::fail('effects_resource_invalid');self::fields($r,['current','maximum']);
        if(self::integer($r['current']??null)>self::integer($r['maximum']??null))self::fail('effects_resource_invalid');
    }
    private static function pool(array $p,array $entities): void
    {
        self::fields($p,['source_entity_id','source_ref','scope','scope_key','targets','strength','maximum_strength','barrier_priority','applied_order','status','version','absorbs','duration']);
        if(!isset($entities[self::id($p['source_entity_id']??null)]))self::fail('effects_entity_missing');self::ref($p['source_ref']??null);
        if(!in_array($p['scope']??null,['per_target','shared_pool'],true) || !is_string($p['scope_key']??null) || $p['scope_key']===''
            || !is_array($p['targets']??null) || !array_is_list($p['targets']) || !$p['targets'])self::fail('effects_barrier_invalid');
        $seen=[];foreach($p['targets'] as $id){$id=self::id($id);if(!isset($entities[$id])||isset($seen[$id]))self::fail('effects_barrier_invalid');$seen[$id]=true;}
        if($p['scope']==='per_target' && (count($seen)!==1 || $p['scope_key']!==(string)$p['targets'][0]))self::fail('effects_barrier_invalid');
        $strength=self::integer($p['strength']??null);$maximum=self::integer($p['maximum_strength']??null);
        if($strength>$maximum || !in_array($p['status']??null,['active','destroyed','replaced'],true)
            || ($p['status']==='active' && $strength===0) || ($p['status']==='destroyed' && $strength!==0))self::fail('effects_barrier_invalid');
        AF_ACS_Numeric::integer($p['barrier_priority']??null,-AF_ACS_Numeric::MAX_INTEGER);
        if(self::integer($p['version']??null)<1)self::fail('effects_version_invalid');self::integer($p['applied_order']??null);
        if(!is_array($p['absorbs']??null)||!array_is_list($p['absorbs']))self::fail('effects_filter_unsupported');
        foreach($p['absorbs'] as $filter)if(!is_string($filter)||!in_array($filter,['physical','elemental'],true))self::fail('effects_filter_unsupported');
        if(count(array_unique($p['absorbs']))!==count($p['absorbs']))self::fail('effects_filter_unsupported');
        $d=$p['duration']??null;
        if(!is_array($d)||!in_array($d['unit']??null,['own_turn','target_turn','round'],true)||AF_ACS_Numeric::number($d['value']??null)<0)self::fail('effects_duration_invalid');
        self::fields($d,['value','unit']);
    }
    private static function state(array $state): array
    {
        self::fields($state,['schema_version','entities','groups','barriers','next_barrier_order']);
        if(($state['schema_version']??null)!==1)self::fail('effects_schema_invalid');
        foreach(['entities','groups','barriers'] as $field)if(!is_array($state[$field]??null))self::fail('effects_schema_invalid');
        foreach($state['groups'] as $id=>$group){self::id($id);if(!is_array($group))self::fail('effects_schema_invalid');self::fields($group,['ap','version']);self::resource($group['ap']??null);if(self::integer($group['version']??null)<1)self::fail('effects_version_invalid');}
        foreach($state['entities'] as $id=>$e){
            self::id($id);if(!is_array($e))self::fail('effects_schema_invalid');
            self::fields($e,['hp','max_hp','life_state','recovery_blocked','group_id','resources','source_ref','version']);self::ref($e['source_ref']??null);
            $hp=self::integer($e['hp']??null);$max=self::integer($e['max_hp']??null);
            if($hp>$max || !is_bool($e['recovery_blocked']??null)||!in_array($e['life_state']??null,['active','incapacitated','dead'],true)
                || ($e['life_state']==='active'&&($hp===0||$e['recovery_blocked']))||($e['life_state']==='dead'&&$hp!==0)
                || ($e['life_state']==='incapacitated'&&$hp>0&&!$e['recovery_blocked']))self::fail('effects_hp_state_invalid');
            if(!isset($state['groups'][self::id($e['group_id']??null)]))self::fail('effects_group_missing');
            if(self::integer($e['version']??null)<1||!is_array($e['resources']??null))self::fail('effects_resource_invalid');
            foreach($e['resources'] as $key=>$resource){if(!is_string($key)||!preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D',$key)||in_array(strtolower($key),['hp','ap'],true))self::fail('effects_resource_invalid');self::resource($resource);}
        }
        $next=self::integer($state['next_barrier_order']??null);
        foreach($state['barriers'] as $id=>$p){self::id($id);if(!is_array($p))self::fail('effects_barrier_invalid');self::pool($p,$state['entities']);if($p['applied_order']>=$next)self::fail('effects_barrier_order_invalid');}
        try{$json=json_encode($state,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);$copy=json_decode($json,true,64,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){self::fail('effects_schema_invalid');}
        if($copy!==$state)self::fail('ACS_NUMERIC_SERIALIZATION_PRECISION');
        foreach(['entities','groups','barriers'] as $field)uksort($copy[$field],self::order(...));
        return $copy;
    }
    private static function bump(array &$row): void {$row['version']=self::integer($row['version']+1);}
    private static function hp(array &$e,int $hp,string $id,array &$signals): void
    {
        $before=$e['life_state'];$changed=$e['hp']!==$hp;$e['hp']=$hp;
        if($e['life_state']!=='dead')$e['life_state']=$hp===0?'incapacitated':($e['recovery_blocked']?'incapacitated':'active');
        if($before!==$e['life_state'])$signals[]=['type'=>'life_transition','entity_id'=>$id,'from'=>$before,'to'=>$e['life_state']];
        if($hp===0&&$before==='active')$signals[]=['type'=>'stop_sustained_required','entity_id'=>$id];
        if($changed||$before!==$e['life_state'])self::bump($e);
    }
    private static function allocation(AF_ACS_DamageResult $allocation): array
    {
        $d=$allocation->serverData();
        if(($d['trace_version']??null)!==AF_ACS_Damage::VERSION||($d['numeric_policy']??null)!==AF_ACS_Numeric::POLICY||!is_array($d['components']??null)||!array_is_list($d['components']))self::fail('effects_allocation_invalid');
        $budget=self::integer($d['integer_budget']??null);$sum=0;$previous=-1;
        foreach($d['components'] as $c){
            if(!is_array($c)||!is_int($c['source_index']??null)||$c['source_index']<=$previous||!in_array($c['damage_type']??null,['physical','elemental','true'],true))self::fail('effects_allocation_invalid');
            $previous=$c['source_index'];$sum=self::integer($sum+self::integer($c['allocated']??null));
        }
        if($budget!==$sum||($d['allocation_sum']??null)!==$sum)self::fail('effects_allocation_invalid');return $d;
    }
    /** Costs first, then effects in the retained action/target/component order. Failure returns no state. */
    public static function simulate(array $state,array $action): AF_ACS_EffectsResult
    {
        $v=self::state($state);$dependencies=$v;$events=[];$signals=[];
        self::fields($action,['actor_id','costs','hp_cost_policy','effects']);
        foreach(['costs','effects'] as $field)if(!is_array($action[$field]??null)||!array_is_list($action[$field]))self::fail('effects_action_invalid');
        $actor=isset($action['actor_id'])?self::id($action['actor_id']):null;
        if(($actor!==null&&(!isset($v['entities'][$actor])||$v['entities'][$actor]['life_state']!=='active'))||($action['costs']&&$actor===null))self::fail('effects_actor_unavailable');
        $sacrifice=false;
        if(array_key_exists('hp_cost_policy',$action)){
            $p=$action['hp_cost_policy'];if(!is_array($p)||!is_bool($p['allow_zero']??null))self::fail('effects_cost_policy_invalid');
            self::fields($p,['allow_zero','source_ref']);self::ref($p['source_ref']??null);$sacrifice=$p['allow_zero'];
        }
        foreach($action['costs'] as $cost){
            if(!is_array($cost))self::fail('effects_cost_invalid');self::fields($cost,['kind','resource','amount']);$amount=self::integer($cost['amount']??null);
            $e=&$v['entities'][$actor];$kind=$cost['kind']??null;
            if($kind==='hp'){
                if(array_key_exists('resource',$cost))self::fail('effects_cost_invalid');
                if($amount>$e['hp']||(!$sacrifice&&$e['hp']-$amount<1))self::fail('effects_cost_unaffordable');
                self::hp($e,$e['hp']-$amount,$actor,$signals);
            } elseif($kind==='ap'){
                if(array_key_exists('resource',$cost))self::fail('effects_cost_invalid');$g=&$v['groups'][$e['group_id']];
                if($amount>$g['ap']['current'])self::fail('effects_cost_unaffordable');$g['ap']['current']-=$amount;if($amount)self::bump($g);unset($g);
            } elseif($kind==='resource'){
                $key=$cost['resource']??null;if(!is_string($key)||!isset($e['resources'][$key]))self::fail('effects_resource_invalid');
                if($amount>$e['resources'][$key]['current'])self::fail('effects_cost_unaffordable');$e['resources'][$key]['current']-=$amount;if($amount)self::bump($e);
            } else self::fail('effects_cost_invalid');
            $events[]=['type'=>'cost_paid','entity_id'=>$actor,'kind'=>$kind,'resource'=>$cost['resource']??null,'amount'=>$amount,'is_damage'=>false];unset($e);
        }
        foreach($action['effects'] as $op){
            if(!is_array($op)||!is_string($op['kind']??null))self::fail('effects_operation_invalid');$kind=$op['kind'];
            if($kind==='damage'){
                self::fields($op,['kind','targets']);if(!is_array($op['targets']??null)||!array_is_list($op['targets']))self::fail('effects_operation_invalid');$seen=[];
                foreach($op['targets'] as $target){
                    if(!is_array($target))self::fail('effects_operation_invalid');self::fields($target,['entity_id','allocation']);$id=self::id($target['entity_id']??null);
                    if(isset($seen[$id]))self::fail('effects_target_duplicate');$seen[$id]=true;
                    if(!isset($v['entities'][$id])||$v['entities'][$id]['life_state']==='dead')self::fail('effects_target_unavailable');
                    if(!(($target['allocation']??null) instanceof AF_ACS_DamageResult))self::fail('effects_allocation_invalid');$d=self::allocation($target['allocation']);
                    foreach($d['components'] as $c){
                        $left=$c['allocated'];$absorbed=0;$queue=array_keys($v['barriers']);
                        usort($queue,static fn($a,$b)=>$v['barriers'][$a]['barrier_priority']<=>$v['barriers'][$b]['barrier_priority'] ?: $v['barriers'][$a]['applied_order']<=>$v['barriers'][$b]['applied_order'] ?: self::order($a,$b));
                        if($c['damage_type']!=='true')foreach($queue as $poolId){
                            $p=&$v['barriers'][$poolId];
                            if(!$left||$p['status']!=='active'||!in_array($id,array_map('strval',$p['targets']),true)||!in_array($c['damage_type'],$p['absorbs'],true)){unset($p);continue;}
                            $take=min($left,$p['strength']);$p['strength']-=$take;$left-=$take;$absorbed+=$take;if($take)self::bump($p);
                            if($p['strength']===0)$p['status']='destroyed';
                            $events[]=['type'=>'barrier_absorb','pool_id'=>(string)$poolId,'entity_id'=>$id,'source_index'=>$c['source_index'],'amount'=>$take];unset($p);
                        }
                        $e=&$v['entities'][$id];$loss=min($left,$e['hp']);self::hp($e,$e['hp']-$loss,$id,$signals);unset($e);
                        $events[]=['type'=>'damage','entity_id'=>$id,'source_index'=>$c['source_index'],'damage_type'=>$c['damage_type'],'allocated'=>$c['allocated'],'absorbed'=>$absorbed,'hp_loss'=>$loss,'overkill'=>$left-$loss];
                    }
                    $events[]=['type'=>'strike_budget','entity_id'=>$id,'integer_budget'=>$d['integer_budget']];
                }
            } elseif($kind==='heal'||$kind==='set_max_hp'){
                self::fields($op,['kind','entity_id','amount']);$id=self::id($op['entity_id']??null);$amount=self::integer($op['amount']??null);
                if(!isset($v['entities'][$id]))self::fail('effects_entity_missing');$e=&$v['entities'][$id];
                if($kind==='heal'){
                    if($e['life_state']==='dead')self::fail('effects_dead_heal_forbidden');$recovery=min($amount,$e['max_hp']-$e['hp']);self::hp($e,$e['hp']+$recovery,$id,$signals);
                    $events[]=['type'=>'heal','entity_id'=>$id,'calculated'=>$amount,'effective_recovery'=>$recovery,'overheal'=>$amount-$recovery];
                } else {
                    $old=$e['max_hp'];$e['max_hp']=$amount;$hpVersion=$e['version'];self::hp($e,min($e['hp'],$amount),$id,$signals);if($old!==$amount&&$hpVersion===$e['version'])self::bump($e);
                    $events[]=['type'=>'max_hp_changed','entity_id'=>$id,'before'=>$old,'after'=>$amount,'current_hp'=>$e['hp']];
                }unset($e);
            } elseif($kind==='barrier_apply'){
                self::fields($op,['kind','pool_id','pool']);$id=self::id($op['pool_id']??null);if(isset($v['barriers'][$id])||!is_array($op['pool']??null))self::fail('effects_barrier_identity_conflict');
                $p=$op['pool'];self::fields($p,['source_entity_id','source_ref','scope','scope_key','targets','strength','maximum_strength','barrier_priority','absorbs','duration']);
                $p['barrier_priority']=array_key_exists('barrier_priority',$p)?$p['barrier_priority']:0;$p['status']=($p['strength']??null)===0?'destroyed':'active';$p['version']=1;$p['applied_order']=$v['next_barrier_order'];self::pool($p,$v['entities']);
                try{$p=json_decode(json_encode($p,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){self::fail('effects_barrier_invalid');}
                $matches=[];foreach($v['barriers'] as $oldId=>$old)if($old['status']==='active'&&(string)$old['source_entity_id']===(string)$p['source_entity_id']&&$old['source_ref']['key']===$p['source_ref']['key']&&$old['scope']===$p['scope']&&$old['scope_key']===$p['scope_key'])$matches[]=$oldId;
                if(count($matches)>1)self::fail('effects_barrier_stack_unsupported');foreach($matches as $oldId){$v['barriers'][$oldId]['status']='replaced';self::bump($v['barriers'][$oldId]);}
                $v['next_barrier_order']=self::integer($v['next_barrier_order']+1);$v['barriers'][$id]=$p;$events[]=['type'=>'barrier_applied','pool_id'=>$id,'replaced_ids'=>array_map('strval',$matches),'applied_order'=>$p['applied_order']];
            } elseif($kind==='barrier_restore'){
                self::fields($op,['kind','pool_id','amount']);$id=self::id($op['pool_id']??null);
                if(!isset($v['barriers'][$id])||$v['barriers'][$id]['status']!=='active')self::fail('effects_barrier_restore_forbidden');$p=&$v['barriers'][$id];
                $room=$p['maximum_strength']-$p['strength'];$amount=array_key_exists('amount',$op)?self::integer($op['amount']):$room;$restore=min($amount,$room);$p['strength']+=$restore;if($restore)self::bump($p);unset($p);
                $events[]=['type'=>'barrier_restored','pool_id'=>$id,'restored'=>$restore,'excess'=>$amount-$restore];
            } else self::fail('effects_operation_unsupported');
        }
        $v=self::state($v);
        return new AF_ACS_EffectsResult($v,['engine_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'events'=>$events,'signals'=>$signals,
            'ordinary_actions_require_recheck'=>array_values(array_unique(array_column(array_filter($signals,static fn($s)=>$s['type']==='stop_sustained_required'),'entity_id'))),'end_condition_check_required'=>true,'encounter_transition'=>null,'current_action_may_complete'=>true],$dependencies);
    }
    public static function attempt(array $state,array $action): array
    {
        try{return ['ready'=>true,'evaluation'=>self::simulate($state,$action),'diagnostics'=>[]];}
        catch(AF_ACS_FormulaError $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$e->diagnostic()]];}
        catch(DomainException $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$e->getMessage(),'path'=>'effects']]];}
    }
}
final class AF_ACS_EffectsResult implements JsonSerializable
{
    public function __construct(private readonly array $state,private readonly array $trace,private readonly array $dependencies) {}
    public function serverState(): array{return $this->state;}
    public function serverTrace(): array{return $this->trace;}
    public function serverDependencies(): array{return $this->dependencies;}
    public function jsonSerialize(): array{return ['ready'=>true,'engine_version'=>AF_ACS_Effects::VERSION,'persistent'=>false];}
}
