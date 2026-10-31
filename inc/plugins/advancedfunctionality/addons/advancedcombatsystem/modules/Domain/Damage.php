<?php
declare(strict_types=1);
require_once __DIR__.'/HitCrit.php';

/** D1.4/D1.13 pre-barrier calculation over trusted normalized/pinned sources. No I/O. */
final class AF_ACS_Damage
{
    public const VERSION='acs-damage-1';
    private static function fail(string $code): never {throw new AF_ACS_FormulaError($code,'damage');}
    private static function nonnegative(mixed $n): int|float
    {$n=AF_ACS_Numeric::number($n);if($n<0)self::fail('damage_negative_value');return $n;}
    private static function sum(mixed $values,bool $nonnegative): int|float
    {
        if (!is_array($values) || !array_is_list($values)) self::fail('damage_contributions_invalid');
        $sum=0;
        foreach ($values as $value) $sum=AF_ACS_Numeric::operation('add',$sum,$nonnegative?self::nonnegative($value):AF_ACS_Numeric::number($value));
        return $sum;
    }
    private static function catalog(array $catalog): array
    {
        if (($catalog['contract_id']??null)!=='IC-KB-2' || ($catalog['schema_version']??null)!==1 || ($catalog['ready']??null)!==true
            || !is_array($catalog['data']??null) || count($catalog['data'])!==17) self::fail('damage_element_contract_unavailable');
        foreach ($catalog['data'] as $key=>$node) {
            if (!is_string($key) || !is_array($node) || ($node['schema']??null)!=='af_kb.element.v1'
                || ($node['registry_version']??null)!=='primary-elements-17-v1' || ($node['alias_policy']??null)!=='wind-air-v1'
                || !is_string($node['source_key']??null) || ($node['supported']??null)!==true || ($node['key']??null)!==$key || ($node['root_element']??null)!==$key
                || !is_array($node['definition']??null) || ($node['definition']['kind']??null)!=='primary_element' || ($node['definition']['key']??null)!==$key
                || !is_int($node['definition']['definition_version']??null) || $node['definition']['definition_version']<1
                || !is_string($node['definition']['fingerprint']??null) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$node['definition']['fingerprint'])) self::fail('damage_element_contract_unavailable');
        }
        $data=$catalog['data'];ksort($data,SORT_STRING);return $data;
    }
    private static function penetration(mixed $row): array
    {
        if (!is_array($row) || array_diff(array_keys($row),['percent','flat'])) self::fail('damage_penetration_invalid');
        $percent=self::sum((array_key_exists('percent',$row)?$row['percent']:[]),false);$flat=self::sum((array_key_exists('flat',$row)?$row['flat']:[]),true);
        $clamped=AF_ACS_Numeric::terminal($percent,'none','number',0,100);
        return ['percent_sources'=>array_map(static fn($n)=>AF_ACS_Numeric::number($n),(array_key_exists('percent',$row)?$row['percent']:[])),'flat_sources'=>array_map(static fn($n)=>AF_ACS_Numeric::number($n),(array_key_exists('flat',$row)?$row['flat']:[])),'percent'=>$clamped->clamped,'percent_raw'=>$percent,'flat'=>$flat];
    }
    private static function defense(int|float $base,array $pen): float
    {
        $fraction=AF_ACS_Numeric::operation('subtract',1,AF_ACS_Numeric::operation('divide',$pen['percent'],100));
        $scaled=AF_ACS_Numeric::operation('multiply',$base,$fraction);
        $raw=AF_ACS_Numeric::operation('subtract',$scaled,$pen['flat']);
        return AF_ACS_Numeric::operation('max',0,$raw);
    }
    public static function calculate(array $components,array $target,array $penetration,array $elementCatalog): AF_ACS_DamageResult
    {
        $elements=self::catalog($elementCatalog);
        if (!array_is_list($components) || !$components) self::fail('damage_components_invalid');
        if (array_diff(array_keys($target),['def','resistances','vulnerabilities','immunities'])
            || array_diff(array_keys($penetration),['physical','elemental'])) self::fail('damage_context_invalid');
        foreach (['resistances','vulnerabilities','immunities'] as $field) {
            $map=array_key_exists($field,$target)?$target[$field]:[];
            if (!is_array($map)) self::fail('damage_context_invalid');
            foreach ($map as $key=>$value) {
                if (!isset($elements[$key])) self::fail('unknown_element');
                if ($field==='resistances') {
                    if (!is_array($value) || ($value['scope']??null)!=='elemental' || ($value['unit']??null)!=='RES') self::fail('element_resistance_invalid');
                    self::nonnegative($value['value']??null);
                } elseif ($field==='vulnerabilities') self::sum($value,true);
                elseif (!is_bool($value)) self::fail('damage_immunity_invalid');
            }
        }
        if (array_key_exists('def',$target)) self::nonnegative($target['def']);
        $physicalPen=self::penetration(array_key_exists('physical',$penetration)?$penetration['physical']:[]);$elementPen=array_key_exists('elemental',$penetration)?$penetration['elemental']:[];
        if (!is_array($elementPen)) self::fail('damage_penetration_invalid');
        foreach ($elementPen as $key=>$value) {if(!isset($elements[$key]))self::fail('unknown_element');self::penetration($value);}
        $ordered=[];$seen=[];
        foreach ($components as $component) {
            if (!is_array($component) || array_diff(array_keys($component),['source_index','damage_type','damage_element','raw','after_crit'])
                || !is_int($component['source_index']??null) || $component['source_index']<0 || isset($seen[$component['source_index']])
                || !in_array($component['damage_type']??null,['physical','elemental','true'],true)) self::fail('damage_component_invalid');
            AF_ACS_Numeric::integer($component['source_index']);$seen[$component['source_index']]=true;
            self::nonnegative($component['raw']??null);self::nonnegative($component['after_crit']??null);
            $element=$component['damage_element']??null;
            if ($component['damage_type']==='elemental') {
                if (!is_string($element) || $element==='') self::fail('damage_element_missing');
                if (!isset($elements[$element])) self::fail('unknown_element');
                if (!array_key_exists($element,$target['resistances']??[])) self::fail('required_resistance_missing');
            } elseif ($element!==null) self::fail('damage_component_invalid');
            $ordered[]=$component;
        }
        usort($ordered,static fn($a,$b)=>$a['source_index']<=>$b['source_index']);
        $rows=[];$totalRaw=0;$totalAfter=0;$totalPost=0;$floorSum=0;
        foreach ($ordered as $component) {
            $type=$component['damage_type'];$element=$component['damage_element']??null;$effective=null;$pen=null;$vulnerability=0;$immune=false;
            $raw=$component['raw'];$after=$component['after_crit'];$post=$after;
            if ($type!=='true') {
                if ($type==='physical') {
                    if (!array_key_exists('def',$target)) self::fail('damage_def_missing');
                    $base=$target['def'];$pen=$physicalPen;
                } else {
                    $base=$target['resistances'][$element]['value'];$pen=self::penetration($elementPen[$element]??[]);
                    $vulnerability=self::sum($target['vulnerabilities'][$element]??[],true);$immune=$target['immunities'][$element]??false;
                }
                $effective=self::defense($base,$pen);
                // Immunity is validated before skipping numeric damage arithmetic; it never manufactures missing RES.
                if ($immune) $post=0;
                else {
                    $post=AF_ACS_Numeric::operation('divide',AF_ACS_Numeric::operation('multiply',$after,100),AF_ACS_Numeric::operation('add',100,$effective));
                    if ($type==='elemental') $post=AF_ACS_Numeric::operation('multiply',$post,AF_ACS_Numeric::operation('add',1,AF_ACS_Numeric::operation('divide',$vulnerability,100)));
                }
            }
            $totalRaw=AF_ACS_Numeric::operation('add',$totalRaw,$raw);$totalAfter=AF_ACS_Numeric::operation('add',$totalAfter,$after);$totalPost=AF_ACS_Numeric::operation('add',$totalPost,$post);
            $floor=(int)floor($post);$floorSum=AF_ACS_Numeric::integer($floorSum+$floor);
            $rows[]=['source_index'=>$component['source_index'],'damage_type'=>$type,'element'=>$element,'raw'=>$raw,'after_crit'=>$after,
                'effective_defense'=>$effective,'penetration'=>$pen,'vulnerability'=>$vulnerability,'immunity'=>$immune,'post_defense'=>$post,
                'floor'=>$floor,'remainder'=>AF_ACS_Numeric::operation('subtract',$post,$floor),'allocated'=>$floor,'bypasses_ordinary_barriers'=>$type==='true'];
        }
        $budget=AF_ACS_Numeric::terminal($totalPost,'half_up','integer',0,AF_ACS_Numeric::MAX_INTEGER);
        $remaining=$budget->clamped-$floorSum;
        if ($remaining<0 || $remaining>count($rows)) self::fail('damage_allocation_precision');
        $ranks=array_keys($rows);
        usort($ranks,static fn($a,$b)=>$rows[$b]['remainder']<=>$rows[$a]['remainder'] ?: $rows[$a]['source_index']<=>$rows[$b]['source_index']);
        for($i=0;$i<$remaining;$i++) {
            $index=$ranks[$i];if($rows[$index]['remainder']<=0 || $rows[$index]['immunity'])self::fail('damage_allocation_precision');
            $rows[$index]['allocated']=AF_ACS_Numeric::integer($rows[$index]['allocated']+1);
        }
        $sum=0;foreach($rows as $row)$sum=AF_ACS_Numeric::integer($sum+$row['allocated']);
        if ($sum!==$budget->clamped) self::fail('damage_allocation_precision');
        $provenance=[];foreach($elements as $key=>$node)$provenance[$key]=['source_key'=>$node['source_key'],'definition_version'=>$node['definition']['definition_version'],'fingerprint'=>$node['definition']['fingerprint']];
        return new AF_ACS_DamageResult(['trace_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'components'=>$rows,
            'total_raw'=>$totalRaw,'total_after_crit'=>$totalAfter,'total_post_defense'=>$totalPost,'integer_budget'=>$budget->clamped,'allocation_sum'=>$sum],
            ['trace_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'registry_version'=>'primary-elements-17-v1',
                'alias_policy'=>'wind-air-v1','element_provenance'=>$provenance,'allocation_order'=>array_map(static fn($i)=>$rows[$i]['source_index'],$ranks),'terminal'=>$budget->jsonSerialize(),'stage'=>'before_barriers_hp']);
    }
    public static function attempt(array $components,array $target,array $penetration,array $catalog): array
    {
        try {return ['ready'=>true,'evaluation'=>self::calculate($components,$target,$penetration,$catalog),'diagnostics'=>[]];}
        catch(AF_ACS_FormulaError $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$e->diagnostic()]];}
        catch(DomainException $e){return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$e->getMessage(),'path'=>'damage']]];}
    }
}
final class AF_ACS_DamageResult implements JsonSerializable
{
    public function __construct(private readonly array $data,private readonly array $trace) {}
    public function serverData(): array {return $this->data;}
    public function serverTrace(): array {return $this->trace;}
    public function jsonSerialize(): array {return ['ready'=>true,'trace_version'=>AF_ACS_Damage::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY];}
}
