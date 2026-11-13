<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Typed data only. No KB expressions, callbacks, selectors or trigger execution. */
final class AF_ACS_PreparedAction
{
    public const TAPE = ['rng_version'=>'uniform53-v1','deferred'=>true];
    private static function fail(): never { throw new DomainException('ACS_PREPARED_UNSUPPORTED'); }
    public static function contract(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value),['schema','trigger','target','duration','ap_cost','additional_costs','requires_active','execution'])
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
        $execution=isset($value['execution'])?['execution'=>self::execution($value['execution'])]:[];
        return $execution+['schema'=>'ic-acs.prepared.v1','trigger'=>['kind'=>'on_targeted','subject'=>'source'],'target'=>$value['target'],
            'duration'=>'until_next_turn','requires_active'=>true,'ap_cost'=>$value['ap_cost'],'additional_costs'=>$costs];
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
