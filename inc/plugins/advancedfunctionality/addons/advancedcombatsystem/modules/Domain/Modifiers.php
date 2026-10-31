<?php
declare(strict_types=1);
require_once __DIR__.'/Formula.php';

/** One explicitly declared modifier stage. Gameplay phase orchestration belongs to the caller. */
final class AF_ACS_Modifiers
{
    public const VERSION='acs-modifiers-1';
    public const SCHEMA='af_kb.arpg.math-modifier.v1';
    private const TYPES=['add_flat'=>0,'add_relative_percent'=>1,'multiply'=>2,'set'=>3,'clamp_min'=>4,'clamp_max'=>5];
    private static function fail(string $code): never { throw new AF_ACS_FormulaError($code,'modifiers'); }
    private static function token(mixed $value): bool
    { return is_string($value) && preg_match('/\A[a-zA-Z][a-zA-Z0-9_.\/-]{0,63}\z/D',$value)===1; }
    private static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)
            || (strlen($value)===20 && strcmp($value,'18446744073709551615')>0)) self::fail('modifier_source_invalid');
        return $value;
    }
    private static function hash(mixed $value): bool
    { return is_string($value) && preg_match('/\Asha256:[a-f0-9]{64}\z/D',$value)===1; }
    private static function compareId(string $a,string $b): int { return strlen($a)<=>strlen($b) ?: strcmp($a,$b); }
    private static function bounds(array $row,?float &$min,?float &$max): void
    {
        foreach (['min_value','max_value'] as $field) {
            if (!array_key_exists($field,$row)) continue;
            $value=(float)AF_ACS_Numeric::number($row[$field]);
            if ($field==='min_value') $min=$min===null?$value:max($min,$value);
            else $max=$max===null?$value:min($max,$value);
        }
        if ($min!==null && $max!==null && $min>$max) self::fail('modifier_bounds_conflict');
    }
    /** Evidence for an already calculated CS base; no legacy modifier is executed here. */
    private static function included(array $source,array $m,int $index,?array $snapshot,int|float $base,string $stat): bool
    {
        $isEquipment=$source['source_type']==='equipment' || str_starts_with($source['source_id'],'inventory:');
        if (!$isEquipment) {
            if ($snapshot===null) return false;
            $known=array_filter($snapshot['source_breakdown']??[],static fn($r)=>is_array($r) && ($r['source_id']??null)===$source['source_id']);
            if (!$known) return false;
            // Aggregated base/growth provenance cannot prove an individual opt-in delta.
            self::fail('source_breakdown_unavailable');
        }
        if ($source['source_type']!=='equipment' || $snapshot===null || ($snapshot['ready']??false)!==true
            || ($snapshot['contract_id']??null)!=='IC-CS.combat-preparation.v1'
            || ($snapshot['equipment_policy']??null)!=='already_included_do_not_reapply'
            || !self::hash($snapshot['fingerprint']??null) || !is_array($snapshot['source_breakdown']??null)
            || !is_array($snapshot['equipment']??null) || !array_key_exists($stat,$snapshot['characteristics']??[])
            || $snapshot['characteristics'][$stat]!=$base) self::fail('equipment_provenance_incomplete');
        if (!preg_match('/\Ainventory:([1-9][0-9]*)\z/D',$source['source_id'],$match)) self::fail('equipment_provenance_incomplete');
        $items=array_values(array_filter($snapshot['equipment'],static fn($i)=>is_array($i) && (string)($i['item_id']??'')===$match[1]));
        if (count($items)!==1 || ($items[0]['included_in_total']??null)!==true
            || !is_array($items[0]['kb_ref']??null) || !is_array($items[0]['kb_ref']['version']??null)
            || ($items[0]['kb_ref']['version']['fingerprint']??null)!==$source['source_version']['fingerprint']) self::fail('equipment_provenance_incomplete');
        $path=($source['source_path']??'math_modifiers').'.'.$index;
        $matches=array_values(array_filter($snapshot['source_breakdown'],static fn($r)=>is_array($r)
            && ($r['source_id']??null)===$source['source_id'] && ($r['stat_key']??null)===$stat && ($r['source_path']??null)===$path));
        if (count($matches)!==1) self::fail('equipment_provenance_incomplete');
        $r=$matches[0];
        // flat/percent are checked solely as evidence of prior inclusion; never converted/executed.
        $compatible=['add_flat'=>['add_flat','flat'],'add_relative_percent'=>['add_relative_percent','percent']];
        if (($r['source_type']??null)!=='equipment' || ($r['included_in_total']??null)!==true || !is_array($r['source_version']??null)
            || ($r['source_version']['fingerprint']??null)!==$source['source_version']['fingerprint']
            || !in_array($r['operation']??null,$compatible[$m['operation']]??[$m['operation']],true)
            || !is_int($r['value']??null) && !is_float($r['value']??null)
            || $r['value']!=$m['value'] || (!is_int($r['before']??null) && !is_float($r['before']??null)) || (!is_int($r['after']??null) && !is_float($r['after']??null))
            || !is_finite((float)$r['before']) || !is_finite((float)$r['after'])) self::fail('equipment_provenance_incomplete');
        return true;
    }
    /**
     * Sources are trusted retained snapshots: snapshot_id, source_id/type/version, modifiers[];
     * source_path identifies the original bucket solely for exclusion evidence.
     * Policy supplies KB stat_keys, ordered phases and explicit stat/percent/factor units.
     */
    public static function stage(mixed $base,string $stat,string $phase,array $sources,array $policy,?array $combatSnapshot=null): AF_ACS_ModifierResult
    {
        $base=AF_ACS_Numeric::number($base);
        $phases=$policy['phases']??null;
        if (!is_array($phases) || !array_is_list($phases) || !$phases || !is_array($policy['stat_keys']??null) || !array_is_list($policy['stat_keys']) || !in_array($stat,$policy['stat_keys'],true)) self::fail('modifier_policy_invalid');
        foreach ($phases as $p) if (!self::token($p)) self::fail('modifier_policy_invalid');
        if (count(array_unique($phases))!==count($phases)) self::fail('modifier_policy_invalid');
        foreach ($policy['stat_keys'] as $key) if (!self::token($key)) self::fail('modifier_policy_invalid');
        if (!in_array($phase,$phases,true)) self::fail('modifier_phase_unsupported');
        foreach (['unit','percent_unit','factor_unit'] as $field) if (!self::token($policy[$field]??null)) self::fail('modifier_unit_mismatch');
        if (!in_array($policy['rounding']??null,AF_ACS_Numeric::ROUNDING,true) || !in_array($policy['output_type']??null,['number','integer'],true)) self::fail('modifier_policy_invalid');
        if ($combatSnapshot!==null && (($combatSnapshot['ready']??null)!==true || ($combatSnapshot['schema_version']??null)!==1
            || ($combatSnapshot['contract_id']??null)!=='IC-CS.combat-preparation.v1' || ($combatSnapshot['numeric_policy']??null)!==AF_ACS_Numeric::POLICY || !self::hash($combatSnapshot['fingerprint']??null)
            || ($combatSnapshot['equipment_policy']??null)!=='already_included_do_not_reapply' || !is_array($combatSnapshot['source_breakdown']??null)
            || !is_array($combatSnapshot['equipment']??null) || !is_array($combatSnapshot['characteristics']??null)
            || !array_key_exists($stat,$combatSnapshot['characteristics']) || $combatSnapshot['characteristics'][$stat]!=$base)) self::fail('equipment_provenance_incomplete');
        if ($combatSnapshot!==null) AF_ACS_Numeric::number($combatSnapshot['characteristics'][$stat]);
        $phases=array_map(static fn($v)=>(string)$v,$phases);
        foreach (['min_value','max_value'] as $bound) if (array_key_exists($bound,$policy)) {
            $v=AF_ACS_Numeric::number($policy[$bound]);
            if ($policy['output_type']==='integer' && floor((float)$v)!=(float)$v) self::fail('ACS_NUMERIC_BOUNDS_INVALID');
        }
        $rows=[];$seen=[];
        foreach ($sources as $source) {
            if (!is_array($source)) self::fail('modifier_source_invalid');
            $id=self::id($source['snapshot_id']??null);
            if (isset($seen[$id]) || !self::token($source['source_type']??null) || !is_string($source['source_id']??null)
                || $source['source_id']==='' || !is_array($source['source_version']??null) || !self::hash($source['source_version']['fingerprint']??null)
                || !is_array($source['modifiers']??null) || !array_is_list($source['modifiers'])) self::fail('modifier_source_invalid');
            $bucket=$source['source_path']??'math_modifiers';
            if (!in_array($bucket,['math_modifiers','base_stats','modifiers'],true)) self::fail('modifier_source_invalid');
            if ($bucket!=='math_modifiers' && $source['source_type']!=='equipment') self::fail('invalid_math_modifier');
            $seen[$id]=true;
            foreach ($source['modifiers'] as $index=>$m) {
                $required=['schema','stat_key','operation','value','unit','phase','priority','condition_ref'];
                if (!is_array($m) || array_diff($required,array_keys($m)) || array_diff(array_keys($m),array_merge($required,['min_value','max_value']))
                    || $m['schema']!==self::SCHEMA || !in_array($m['stat_key'],$policy['stat_keys'],true)
                    || !is_string($m['operation']) || !isset(self::TYPES[$m['operation']]) || !in_array($m['phase'],$phases,true)
                    || !is_int($m['priority']) || !self::token($m['unit'])
                    || ($m['condition_ref']!==null && (!is_string($m['condition_ref']) || !preg_match('/\Acondition_template:[a-z][a-z0-9_-]{1,63}\z/D',$m['condition_ref'])))) self::fail('invalid_math_modifier');
                AF_ACS_Numeric::number($m['priority']);AF_ACS_Numeric::number($m['value']);
                $lo=null;$hi=null;self::bounds($m,$lo,$hi);
                if ($m['stat_key']!==$stat || $m['phase']!==$phase) continue; // Deliberate stage selection, no execution of other phases/stats.
                if ($policy['output_type']==='integer') {
                    foreach (['min_value','max_value'] as $bound) if (array_key_exists($bound,$m) && floor((float)$m[$bound])!=(float)$m[$bound]) self::fail('ACS_NUMERIC_BOUNDS_INVALID');
                    if (in_array($m['operation'],['clamp_min','clamp_max'],true) && floor((float)$m['value'])!=(float)$m['value']) self::fail('ACS_NUMERIC_BOUNDS_INVALID');
                }
                $unit=match($m['operation']) {'add_relative_percent'=>$policy['percent_unit'],'multiply'=>$policy['factor_unit'],default=>$policy['unit']};
                if ($m['unit']!==$unit) self::fail('modifier_unit_mismatch');
                // No condition evaluator exists yet: never assume an unresolved condition is true.
                if ($m['condition_ref']!==null) self::fail('modifier_condition_unresolved');
                if (($m['operation']==='add_relative_percent' || $m['operation']==='multiply') && ($lo!==null || $hi!==null)) self::fail('modifier_bound_unit_unsupported');
                $skip=self::included($source,$m,$index,$combatSnapshot,$base,$stat);
                $rows[]=['phase'=>$m['phase'],'operation'=>$m['operation'],'priority'=>$m['priority'],'source_snapshot_id'=>$id,
                    'modifier_index'=>$index,'source_id'=>$source['source_id'],'source_version'=>$source['source_version']['fingerprint'],
                    'definition'=>$m,'included_in_total'=>$skip];
            }
        }
        usort($rows,static fn($a,$b)=>array_search($a['phase'],$phases,true)<=>array_search($b['phase'],$phases,true)
            ?: self::TYPES[$a['operation']]<=>self::TYPES[$b['operation']] ?: $a['priority']<=>$b['priority']
            ?: self::compareId($a['source_snapshot_id'],$b['source_snapshot_id']) ?: $a['modifier_index']<=>$b['modifier_index']);
        $flat=0;$percent=0;$product=1;$sets=[];$min=null;$max=null;$trace=[];
        self::bounds($policy,$min,$max);
        foreach ($rows as $row) {
            $m=$row['definition'];
            $trace[]=array_diff_key($row,['definition'=>true]);
            if ($row['included_in_total']) continue;
            self::bounds($m,$min,$max);
            switch ($m['operation']) {
                case 'add_flat': $flat=AF_ACS_Numeric::operation('add',$flat,$m['value']);break;
                case 'add_relative_percent': $percent=AF_ACS_Numeric::operation('add',$percent,$m['value']);break;
                case 'multiply': $product=AF_ACS_Numeric::operation('multiply',$product,$m['value']);break;
                case 'set': $sets[]=$row;break;
                case 'clamp_min': $min=$min===null?(float)$m['value']:max($min,$m['value']);break;
                case 'clamp_max': $max=$max===null?(float)$m['value']:min($max,$m['value']);break;
            }
        }
        if ($min!==null && $max!==null && $min>$max) self::fail('modifier_bounds_conflict');
        $winner=null;
        if ($sets) {
            $priority=max(array_column($sets,'priority'));
            foreach ($sets as $set) {
                if ($set['priority']!==$priority) continue;
                if ($winner!==null && $winner['definition']['value']!=$set['definition']['value']) self::fail('modifier_set_conflict');
                if ($winner===null) $winner=$set;
            }
        }
        $flatValue=AF_ACS_Numeric::operation('add',$base,$flat);
        $percentValue=AF_ACS_Numeric::operation('multiply',$flatValue,AF_ACS_Numeric::operation('add',1,AF_ACS_Numeric::operation('divide',$percent,100)));
        $multValue=AF_ACS_Numeric::operation('multiply',$percentValue,$product);
        $raw=$winner===null?$multValue:$winner['definition']['value'];
        $result=AF_ACS_Numeric::terminal($raw,$policy['rounding'],$policy['output_type'],$min,$max);
        return new AF_ACS_ModifierResult($result,['modifier_policy'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,
            'stat_key'=>$stat,'phase'=>$phase,'phase_order'=>$phases,'base'=>$base,'ordered_sources'=>$trace,
            'flat_sum'=>$flat,'relative_percent_sum'=>$percent,'multiplier_product'=>$product,
            'v_flat'=>$flatValue,'v_percent'=>$percentValue,'v_mult'=>$multValue,'set_source'=>$winner===null?null:[$winner['source_snapshot_id'],$winner['modifier_index']],
            'terminal'=>$result->jsonSerialize()]);
    }
    public static function attempt(mixed $base,string $stat,string $phase,array $sources,array $policy,?array $snapshot=null): array
    {
        try {return ['ready'=>true,'evaluation'=>self::stage($base,$stat,$phase,$sources,$policy,$snapshot),'diagnostics'=>[]];}
        catch (AF_ACS_FormulaError $e) {return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$e->diagnostic()]];}
        catch (DomainException $e) {return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$e->getMessage(),'path'=>'modifiers']]];}
    }
}
final class AF_ACS_ModifierResult implements JsonSerializable
{
    public function __construct(private readonly AF_ACS_NumericResult $result,private readonly array $trace) {}
    public function serverResult(): AF_ACS_NumericResult {return $this->result;}
    public function serverTrace(): array {return $this->trace;}
    public function jsonSerialize(): array {return ['ready'=>true,'modifier_policy'=>AF_ACS_Modifiers::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY];}
}
