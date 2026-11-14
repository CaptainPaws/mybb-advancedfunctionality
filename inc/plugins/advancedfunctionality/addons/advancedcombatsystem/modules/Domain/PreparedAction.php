<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Awareness.php';

/** Typed data only. No KB expressions, callbacks, selectors or trigger execution. */
final class AF_ACS_PreparedAction
{
    public const TAPE = ['rng_version'=>'uniform53-v1','deferred'=>true];
    private static function fail(): never { throw new DomainException('ACS_PREPARED_UNSUPPORTED'); }
    public static function contract(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value),['schema','trigger','target','duration','ap_cost','additional_costs','requires_active','execution','hidden','pre_round'])
            || ($value['schema']??null)!=='ic-acs.prepared.v1'
            || (!is_array($value['trigger']??null)||array_diff(array_keys($value['trigger']),['kind','subject'])||($value['trigger']['kind']??null)!=='on_targeted'||($value['trigger']['subject']??null)!=='source')
            || ($value['target']??null)!==['kind'=>'fixed']
            || (array_key_exists('duration',$value)&&$value['duration']!=='until_next_turn')
            || (array_key_exists('requires_active',$value)&&$value['requires_active']!==true)
            || !is_int($value['ap_cost']??null) || $value['ap_cost']<1 || $value['ap_cost']>1000000) self::fail();
        $costs=array_key_exists('additional_costs',$value)?$value['additional_costs']:[];
        if (!is_array($costs) || !array_is_list($costs) || count($costs)>16) self::fail();
        foreach ($costs as &$cost) {
            if (!is_array($cost) || array_diff(array_keys($cost),['kind','key','amount','timing','definition','selection'])
                || !in_array($cost['kind']??null,['resource','item'],true)
                || !is_string($cost['key']??null) || !preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$cost['key'])
                || !is_int($cost['amount']??null) || $cost['amount']<1 || $cost['amount']>1000000
                || !in_array($cost['timing']??null,['on_prepare','on_trigger'],true)) self::fail();
            if($cost['kind']==='item') {
                $pin=$cost['definition']??null;
                if(($cost['selection']??null)!=='explicit'||!is_array($pin)||array_diff(array_keys($pin),['type','key','version','fingerprint'])||($pin['type']??null)!=='arpg_item'||!is_string($pin['key']??null)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{0,95}\z/D',$pin['key'])||!is_string($pin['version']??null)||$pin['version']===''||!is_string($pin['fingerprint']??null)||!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$pin['fingerprint']))self::fail();
                $cost['definition']=['type'=>$pin['type'],'key'=>$pin['key'],'version'=>$pin['version'],'fingerprint'=>$pin['fingerprint']];
            }elseif(array_diff(array_keys($cost),['kind','key','amount','timing'])||in_array($cost['key'],['hp','ap'],true))self::fail();
        }
        unset($cost);
        if(array_key_exists('pre_round',$value)&&(!is_array($value['pre_round'])||count($value['pre_round'])!==2||array_diff(array_keys($value['pre_round']),['schema','allowed'])||($value['pre_round']['schema']??null)!=='ic-acs.pre-round-preparation.v1'||($value['pre_round']['allowed']??null)!==true))self::fail();
        $preRound=isset($value['pre_round'])?['pre_round'=>['schema'=>'ic-acs.pre-round-preparation.v1','allowed'=>true]]:[];
        $hidden=array_key_exists('hidden',$value)?['hidden'=>self::hidden($value['hidden'])]:[];
        $execution=isset($value['execution'])?['execution'=>self::execution($value['execution'])]:[];
        return $preRound+$hidden+$execution+['schema'=>'ic-acs.prepared.v1','trigger'=>['kind'=>'on_targeted','subject'=>'source'],'target'=>$value['target'],
            'duration'=>'until_next_turn','requires_active'=>true,'ap_cost'=>$value['ap_cost'],'additional_costs'=>$costs];
    }
    public static function hidden(mixed $h):array
    {
        if(!is_array($h)||array_diff(array_keys($h),['schema','source_stealth','perception','on_detected','reveal_on_trigger'])||count($h)!==5||($h['schema']??null)!=='ic-acs.hidden-prepared.v1'||!is_array($h['source_stealth']??null)||!array_is_list($h['source_stealth'])||array_diff($h['source_stealth'],['hidden','concealed','invisible'])||count(array_unique($h['source_stealth']))!==count($h['source_stealth'])||($h['on_detected']??null)!=='retain')self::fail();
        $p=AF_ACS_Awareness::perception($h['perception']??null);if($p===null||$p['stealth']!==null||$p['passive']!==null)self::fail();
        $reveal=$h['reveal_on_trigger'];if($reveal!==null&&(!is_array($reveal)||array_diff(array_keys($reveal),['relative_to','audience','level','reveal_source'])||count($reveal)!==4||!in_array($reveal['relative_to']??null,['source','trigger_actor'],true)||!in_array($reveal['audience']??null,['actor','control_group','side','encounter'],true)||!in_array($reveal['level']??null,['suspected','detected','identified'],true)||($reveal['reveal_source']??null)!==false))self::fail();
        return $h;
    }
    public static function concealment(array $contract,array $state,string $actor):void
    {
        if(!isset($contract['hidden']))return;$h=self::hidden($contract['hidden']);$available=$state['perception_subjects']['entity:'.$actor]['perception']['stealth']['states']??[];
        foreach($state['status_instances']??[] as $row)if($row['status']==='active'&&$row['target_entity_id']===$actor){$stealth=$row['spec']['mechanical_parameters']['stealth']??null;if($stealth!==null){AF_ACS_Awareness::stealth($stealth);array_push($available,...$stealth['states']);}}
        if(array_diff($h['source_stealth'],$available))throw new RuntimeException('ACS_PREPARED_CONCEALMENT_REQUIRED');
    }
    public static function execution(mixed $e):array
    {
        $fields=['schema','event_kind','phase','priority','requires_hit','on_unavailable','emit_prepared','emit_active_reactions','limits'];
        if(!is_array($e)||array_diff(array_keys($e),$fields)||array_diff($fields,array_keys($e))||$e['schema']!=='ic-acs.prepared-execution.v1'||$e['event_kind']!=='combat.primary'||!in_array($e['phase'],['on_targeted','before_hit','after_hit','after_damage'],true)||!is_int($e['priority'])||$e['priority'] < -1000000||$e['priority']>1000000||!is_bool($e['requires_hit'])||in_array($e['phase'],['on_targeted','before_hit'],true)&&$e['requires_hit']!==false||!in_array($e['on_unavailable'],['retain','cancel'],true)||$e['emit_prepared']!==false||$e['emit_active_reactions']!==false||!is_array($e['limits'])||array_diff(array_keys($e['limits']),['max_depth','per_cause'])||!is_int($e['limits']['max_depth']??null)||$e['limits']['max_depth']<2||($e['limits']['per_cause']??null)!==1)self::fail();
        return $e;
    }
    public static function compile(array $ability,mixed $contract): array
    {
        if (($ability['targeting']??'single_target')!=='single_target' || empty($ability['components'])
            || !empty($ability['statuses']) || !empty($ability['removals'])) self::fail();
        $contract=self::contract($contract);$ability['costs']=[['kind'=>'ap','amount'=>$contract['ap_cost']]];
        foreach($contract['additional_costs'] as $cost)if($cost['kind']==='resource'&&$cost['timing']==='on_prepare')$ability['costs'][]=['kind'=>'resource','resource'=>$cost['key'],'amount'=>$cost['amount']];
        return $ability+['prepared_action'=>$contract];
    }
    public static function terminal(string $state): bool { return in_array($state,['expired','cancelled','executed'],true); }
}
