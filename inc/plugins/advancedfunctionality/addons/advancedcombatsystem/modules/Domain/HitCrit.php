<?php
declare(strict_types=1);
require_once __DIR__.'/Modifiers.php';

/** Trusted owner DTO only; source authorization/hash readback remain the adapter's responsibility. */
final class AF_ACS_HitCritPolicy
{
    private function __construct(private readonly bool $autoHit,private readonly ?bool $canCrit,private readonly array $components,private readonly array $ref) {}
    public static function fromDefinition(array $dto,array $expectedSource,array $expectedVersion): self
    {
        if (!is_array($dto['source']??null) || !is_array($dto['definition_version']??null) || !is_array($dto['data']??null)
            || ($dto['contract_id']??null)!=='IC-KB.read.v1' || ($dto['schema_version']??null)!==1 || ($dto['ready']??null)!==true
            || ($dto['source']['type']??null)!=='arpg_ability' || ($dto['definition_version']['schema']??null)!=='af_kb.arpg.rules.v1') throw new AF_ACS_FormulaError('hit_crit_policy_unavailable');
        foreach (['id','type','key'] as $key) if (!array_key_exists($key,$expectedSource) || ($dto['source'][$key]??null)!==$expectedSource[$key]) throw new AF_ACS_FormulaError('hit_crit_source_mismatch');
        $version=$dto['definition_version'];ksort($version);ksort($expectedVersion);
        if ($version!==$expectedVersion || !is_string($version['fingerprint']??null) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$version['fingerprint'])) throw new AF_ACS_FormulaError('hit_crit_source_mismatch');
        $d=$dto['data']['definition']??null;
        if (!is_array($d) || ($d['schema']??null)!=='af_kb.arpg.rules.v1') throw new AF_ACS_FormulaError('hit_crit_policy_unavailable');
        $read=static function(array $row,string $field): ?bool {
            if (!array_key_exists($field,$row)) return null;
            if (!is_bool($row[$field])) throw new AF_ACS_FormulaError('hit_crit_policy_invalid');
            return $row[$field];
        };
        $auto=$read($d,'auto_hit')??false;$crit=$read($d,'can_crit');$components=[];
        if (array_key_exists('effects',$d) && (!is_array($d['effects']) || !array_is_list($d['effects']))) throw new AF_ACS_FormulaError('hit_crit_policy_invalid');
        foreach ($d['effects']??[] as $i=>$effect) {
            if (!is_array($effect)) throw new AF_ACS_FormulaError('hit_crit_policy_invalid');
            $components[$i]=$read($effect,'can_crit');
        }
        try { $ref=json_decode(json_encode(['source'=>array_intersect_key($dto['source'],array_flip(['id','type','key'])),'definition_version'=>$version],JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),true,16,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new AF_ACS_FormulaError('hit_crit_policy_invalid'); }
        return new self($auto,$crit,$components,$ref);
    }
    public function autoHit(): bool {return $this->autoHit;}
    public function permission(?int $index): ?bool {
        if ($index!==null && !array_key_exists($index,$this->components)) throw new AF_ACS_FormulaError('crit_component_reference_missing');
        return $index===null?$this->canCrit:($this->components[$index]??$this->canCrit);
    }
    public function reference(): array {return $this->ref;}
}

/** H1/C1 pure, pre-defense math. One externally supplied critical outcome per strike; no RNG. */
final class AF_ACS_HitCrit
{
    public const VERSION='acs-hit-crit-1';
    private const DEFAULT_CRIT=['ordinary_attack'=>true,'direct_attack'=>true,'dot'=>false,'reflected'=>false,'natural'=>false,'healing'=>false,'shield'=>false];
    private static function nonnegative(mixed $value): int|float
    {
        $value=AF_ACS_Numeric::number($value);
        if ($value<0) throw new AF_ACS_FormulaError('hit_crit_negative_stat');
        return $value;
    }
    public static function hit(mixed $effectiveAccuracy,mixed $effectiveSpeed,?AF_ACS_HitCritPolicy $policy=null): array
    {
        $a=self::nonnegative($effectiveAccuracy);$s=self::nonnegative($effectiveSpeed);
        $difference=null;$sum=null;$scaled=null;$ratio=null;
        if ($a==0 && $s==0) $raw=75;
        else {
            $difference=AF_ACS_Numeric::operation('subtract',$a,$s);
            $scaled=AF_ACS_Numeric::operation('multiply',30,$difference);
            $sum=AF_ACS_Numeric::operation('add',$a,$s);
            $ratio=AF_ACS_Numeric::operation('divide',$scaled,$sum);
            $raw=AF_ACS_Numeric::operation('add',75,$ratio);
        }
        $normal=AF_ACS_Numeric::terminal($raw,'none','number',5,95);
        $hit=$policy?->autoHit()?100:$normal->clamped;
        $evade=AF_ACS_Numeric::operation('subtract',100,$hit);
        return ['effective_accuracy'=>$a,'effective_speed'=>$s,'P_hit'=>$hit,'P_evade'=>$evade,
            'trace'=>['policy'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'difference'=>$difference,'scaled_difference'=>$scaled,
                'sum'=>$sum,'correction'=>$ratio,'ordinary'=>$normal->jsonSerialize(),'auto_hit'=>$policy?->autoHit()??false,'kb_ref'=>$policy?->reference()]];
    }
    public static function strike(mixed $accuracy,mixed $speed,mixed $critRate,mixed $critDamage,array $components,mixed $criticalOutcome,?AF_ACS_HitCritPolicy $policy=null): AF_ACS_HitCritResult
    {
        if ($criticalOutcome!==null && !is_bool($criticalOutcome)) throw new AF_ACS_FormulaError('crit_outcome_invalid');
        $hit=self::hit($accuracy,$speed,$policy);$rate=self::nonnegative($critRate);$damage=self::nonnegative($critDamage);
        if ($rate>100) throw new AF_ACS_FormulaError('crit_rate_out_of_range');
        if (!array_is_list($components) || !$components) throw new AF_ACS_FormulaError('crit_components_invalid');
        $factor=AF_ACS_Numeric::operation('add',1,AF_ACS_Numeric::operation('divide',$damage,100));
        $rows=[];$eligible=false;
        foreach ($components as $i=>$component) {
            if (!is_array($component) || array_diff(array_keys($component),['kind','raw','kb_effect_index']) || !is_string($component['kind']??null)
                || !array_key_exists($component['kind'],self::DEFAULT_CRIT)) throw new AF_ACS_FormulaError('crit_component_unsupported');
            $raw=self::nonnegative($component['raw']??null);
            $index=$component['kb_effect_index']??null;
            if (array_key_exists('kb_effect_index',$component) && (!is_int($index) || $index<0)) throw new AF_ACS_FormulaError('crit_component_unsupported');
            if ($index!==null && $policy===null) throw new AF_ACS_FormulaError('crit_component_reference_missing');
            $permission=$policy?->permission($index);$can=$permission??self::DEFAULT_CRIT[$component['kind']];$eligible=$eligible||$can;
            // Null outcome is preview: do not invent a successful or failed critical check.
            $after=$criticalOutcome===null?null:($criticalOutcome && $can?AF_ACS_Numeric::operation('multiply',$raw,$factor):$raw);
            $rows[]=['source_index'=>$i,'kb_effect_index'=>$index,'kind'=>$component['kind'],'can_crit'=>$can,
                'permission_source'=>$permission===null?'baseline':'kb_explicit','raw_component'=>$raw,'raw_after_crit'=>$after,'crit_applied'=>$criticalOutcome===null?null:($criticalOutcome&&$can)];
        }
        if ($eligible && (($rate==0 && $criticalOutcome===true)||($rate==100 && $criticalOutcome===false))) throw new AF_ACS_FormulaError('crit_outcome_conflict');
        if ($criticalOutcome===true && !$eligible) throw new AF_ACS_FormulaError('crit_not_allowed');
        return new AF_ACS_HitCritResult(array_merge(array_diff_key($hit,['trace'=>true]),['crit_rate'=>$rate,'crit_dmg'=>$damage,
            'can_crit'=>$eligible,'critical_outcome'=>$criticalOutcome,'components'=>$rows]),
            ['engine_version'=>self::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'hit'=>$hit['trace'],'critical_multiplier'=>$factor,
                'crit_check_scope'=>'one_per_strike','critical_outcome'=>$criticalOutcome,'components'=>$rows,'phase'=>'before_def_res','kb_ref'=>$policy?->reference()]);
    }
    public static function attempt(mixed $a,mixed $s,mixed $rate,mixed $damage,array $components,mixed $outcome,?AF_ACS_HitCritPolicy $policy=null): array
    {
        try {return ['ready'=>true,'evaluation'=>self::strike($a,$s,$rate,$damage,$components,$outcome,$policy),'diagnostics'=>[]];}
        catch (AF_ACS_FormulaError $e) {return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$e->diagnostic()]];}
        catch (DomainException $e) {return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$e->getMessage(),'path'=>'hit_crit']]];}
    }
}
final class AF_ACS_HitCritResult implements JsonSerializable
{
    public function __construct(private readonly array $data,private readonly array $trace) {}
    public function serverData(): array {return $this->data;}
    public function serverTrace(): array {return $this->trace;}
    public function jsonSerialize(): array {return ['ready'=>true,'engine_version'=>AF_ACS_HitCrit::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY];}
}
