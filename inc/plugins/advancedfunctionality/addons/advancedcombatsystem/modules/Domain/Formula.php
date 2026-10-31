<?php
declare(strict_types=1);
require_once __DIR__.'/Numeric.php';

/** Safe diagnostic: never embeds a definition, operand value or exception text. */
final class AF_ACS_FormulaError extends DomainException
{
    public function __construct(public readonly string $diagnosticCode, public readonly string $path = 'formula')
    { parent::__construct($diagnosticCode); }
    public function diagnostic(): array
    { return ['severity'=>'error','category'=>'VALIDATION_ERROR','code'=>$this->diagnosticCode,'path'=>$this->path]; }
}

/** Server-only result/trace are explicit opt-in; ordinary JSON exposes readiness only. */
final class AF_ACS_FormulaEvaluation implements JsonSerializable
{
    public function __construct(private readonly AF_ACS_NumericResult $result, private readonly array $trace) {}
    public function serverResult(): AF_ACS_NumericResult { return $this->result; }
    public function serverTrace(): array { return $this->trace; }
    public function jsonSerialize(): array
    { return ['ready'=>true,'compiler_version'=>AF_ACS_Formula::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'diagnostics'=>[]]; }
}

/** Pure compiler over a retained KB snapshot. No I/O, live lookup, optimizer or execution at compile time. */
final class AF_ACS_Formula
{
    public const VERSION = 'acs-formula-1';
    public const SCHEMA = 'af_kb.arpg.formula.v1';
    private const ARITY = ['add'=>[2,2],'subtract'=>[2,2],'multiply'=>[2,2],'divide'=>[2,2],'negate'=>[1,1],'min'=>[2,8],'max'=>[2,8]];
    private function __construct(private readonly array $definition, private readonly array $pin, private readonly array $instructions) {}
    private static function fail(string $code, string $path = 'formula'): never { throw new AF_ACS_FormulaError($code,$path); }
    private static function token(mixed $unit): bool
    { return is_string($unit) && preg_match('/\A[a-zA-Z][a-zA-Z0-9_.\/-]{0,63}\z/D',$unit) === 1; }
    private static function numeric(mixed $value, string $path): int|float
    {
        try { return AF_ACS_Numeric::number($value); }
        catch (DomainException $error) { self::fail($error->getMessage(),$path); }
    }
    /** Same B1/B2 canonicalization: object keys sorted, lists and scalar types retained. */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) { ksort($value,SORT_STRING); }
        foreach ($value as &$child) { if (is_array($child)) { $child = self::canonical($child); } }
        return $value;
    }
    private static function node(mixed $node, array $inputs, array &$instructions, int &$count, int $depth, string $path): int
    {
        if (++$count > 256 || $depth > 16) { self::fail('formula_ast_limit',$path); }
        if (!is_array($node) || !is_string($node['type'] ?? null)) { self::fail('invalid_formula_contract',$path); }
        $fields = match ($node['type']) { 'literal'=>['type','value'], 'input'=>['type','name'], 'operation'=>['type','operator','args'], default=>null };
        if ($fields === null || array_diff(array_keys($node),$fields) || array_diff($fields,array_keys($node))) { self::fail('invalid_formula_contract',$path); }
        $instruction = ['type'=>$node['type'],'path'=>$path];
        if ($node['type'] === 'literal') { $instruction['value'] = self::numeric($node['value'],$path); }
        elseif ($node['type'] === 'input') {
            if (!is_string($node['name']) || !array_key_exists($node['name'],$inputs)) { self::fail('formula_input_undeclared',$path); }
            $instruction['name'] = $node['name'];
        } else {
            $op = $node['operator'];
            if (!is_string($op) || !isset(self::ARITY[$op])) { self::fail('unsupported_operation',$path); }
            $args = $node['args']; [$min,$max] = self::ARITY[$op];
            if (!is_array($args) || !array_is_list($args) || count($args)<$min || count($args)>$max) { self::fail('formula_arity_invalid',$path); }
            $instruction['operator'] = $op; $instruction['args'] = [];
            foreach ($args as $i=>$arg) { $instruction['args'][] = self::node($arg,$inputs,$instructions,$count,$depth+1,$path.'.args.'.$i); }
        }
        $index = count($instructions); $instructions[] = $instruction; return $index;
    }
    public static function compile(array $snapshot, array $pin, string $expectedUnit): self
    {
        if (($snapshot['schema'] ?? null) !== self::SCHEMA) { self::fail('unsupported_formula_schema'); }
        foreach (['key','version','fingerprint'] as $field) {
            if (!is_string($pin[$field] ?? null) || !is_string($snapshot[$field] ?? null)) { self::fail('invalid_formula_contract'); }
        }
        if (!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$pin['key']) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D',$pin['version'])
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D',$pin['fingerprint'])) { self::fail('invalid_formula_contract'); }
        if ($snapshot['key'] !== $pin['key'] || $snapshot['version'] !== $pin['version']) { self::fail('formula_version_mismatch'); }
        if (!hash_equals($pin['fingerprint'],$snapshot['fingerprint'])) { self::fail('formula_fingerprint_mismatch'); }
        $d = $snapshot['definition'] ?? null;
        $required = ['key','version','inputs','expression','output_type','output_unit','rounding','notes'];
        $allowed = array_merge($required,['min_value','max_value','active']);
        if (!is_array($d) || array_diff(array_keys($d),$allowed) || array_diff($required,array_keys($d)) || !is_string($d['notes'])) { self::fail('invalid_formula_contract'); }
        if ($d['key'] !== $pin['key'] || $d['version'] !== $pin['version']) { self::fail('formula_version_mismatch'); }
        if (array_key_exists('active',$d) && !in_array($d['active'],[true,false,0,1],true)) { self::fail('invalid_formula_contract'); }
        if (array_key_exists('active',$d) && !$d['active']) { self::fail('inactive_definition'); }
        if (!self::token($d['output_unit']) || !self::token($expectedUnit) || $d['output_unit'] !== $expectedUnit) { self::fail('formula_unit_mismatch','output_unit'); }
        if (!in_array($d['output_type'],['number','integer'],true)) { self::fail('invalid_formula_contract','output_type'); }
        if (!in_array($d['rounding'],AF_ACS_Numeric::ROUNDING,true)) { self::fail('ACS_NUMERIC_ROUNDING_UNSUPPORTED','rounding'); }
        foreach (['min_value','max_value'] as $field) {
            if (!array_key_exists($field,$d)) { continue; }
            $bound = self::numeric($d[$field],$field);
            if ($d['output_type'] === 'integer' && floor((float)$bound) !== (float)$bound) { self::fail('ACS_NUMERIC_BOUNDS_INVALID',$field); }
        }
        if (isset($d['min_value'],$d['max_value']) && $d['min_value'] > $d['max_value']) { self::fail('ACS_NUMERIC_BOUNDS_INVALID'); }
        $inputs = $d['inputs'];
        if (!is_array($inputs) || ($inputs !== [] && array_is_list($inputs)) || count($inputs)>64) { self::fail('invalid_formula_contract','inputs'); }
        foreach ($inputs as $name=>$type) {
            if (!is_string($name) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D',$name) || !in_array($type,['number','integer'],true)) { self::fail('invalid_formula_contract','inputs'); }
        }
        $instructions = []; $count = 0;
        self::node($d['expression'],$inputs,$instructions,$count,0,'expression');
        // The bounded, strictly validated definition contains no PHP objects/references to external code.
        try {
            $canonical = self::canonical($d);
            $json = json_encode($canonical,JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (json_decode($json,true,64,JSON_THROW_ON_ERROR) !== $canonical) { self::fail('ACS_NUMERIC_SERIALIZATION_PRECISION'); }
        } catch (JsonException $error) { self::fail('invalid_formula_contract'); }
        if (!hash_equals($pin['fingerprint'],'sha256:'.hash('sha256',$json))) { self::fail('formula_fingerprint_mismatch'); }
        // JSON copy severs PHP array references supplied by a caller; compiled content is immutable.
        $retained = json_decode($json,true,64,JSON_THROW_ON_ERROR);
        $cleanInstructions=[];$count=0;self::node($retained['expression'],$retained['inputs'],$cleanInstructions,$count,0,'expression');
        return new self($retained,['key'=>$retained['key'],'version'=>$retained['version'],'fingerprint'=>(string)$pin['fingerprint']],$cleanInstructions);
    }
    public function evaluate(array $dynamicInputs, array $parameters = []): AF_ACS_FormulaEvaluation
    {
        $inputs = $this->definition['inputs']; $values = [];
        if (array_intersect_key($dynamicInputs,$parameters)) { self::fail('formula_input_collision','inputs'); }
        foreach ([$parameters,$dynamicInputs] as $source) {
            foreach ($source as $name=>$value) {
                if (!array_key_exists($name,$inputs)) { self::fail('formula_parameter_mismatch','inputs'); }
                $value = self::numeric($value,'inputs');
                if ($inputs[$name] === 'integer' && !is_int($value)) { self::fail('formula_parameter_mismatch','inputs'); }
                $values[$name] = $value;
            }
        }
        if (array_diff_key($inputs,$values)) { self::fail('formula_input_missing','inputs'); }
        $ordered=[]; foreach ($inputs as $name=>$type) { $ordered[$name]=$values[$name]; } $values=$ordered;
        $retainedParameters=[]; foreach ($parameters as $name=>$value) { $retainedParameters[$name]=$values[$name]; } ksort($retainedParameters,SORT_STRING);
        $results = []; $steps = [];
        foreach ($this->instructions as $index=>$instruction) {
            $args = [];
            if ($instruction['type'] === 'literal') { $raw = $instruction['value']; }
            elseif ($instruction['type'] === 'input') { $raw = $values[$instruction['name']]; }
            else {
                foreach ($instruction['args'] as $id) { $args[] = $results[$id]; }
                try {
                    if ($instruction['operator'] === 'negate') { $raw=AF_ACS_Numeric::operation('negate',$args[0]); $args=[]; }
                    else { $raw=array_shift($args); }
                    foreach ($args as $arg) { $raw = AF_ACS_Numeric::operation($instruction['operator'],$raw,$arg); }
                } catch (DomainException $error) { self::fail($error->getMessage(),$instruction['path']); }
            }
            $results[$index] = $raw;
            $steps[] = ['index'=>$index,'path'=>$instruction['path'],'type'=>$instruction['type'],'operator'=>$instruction['operator'] ?? null,'argument_indexes'=>$instruction['args'] ?? [],'raw'=>$raw];
        }
        try { $result = AF_ACS_Numeric::terminal($raw,$this->definition['rounding'],$this->definition['output_type'],$this->definition['min_value'] ?? null,$this->definition['max_value'] ?? null); }
        catch (DomainException $error) { self::fail($error->getMessage(),'output'); }
        return new AF_ACS_FormulaEvaluation($result,['compiler_version'=>self::VERSION,'schema'=>self::SCHEMA,'numeric_policy'=>AF_ACS_Numeric::POLICY,
            'formula_ref'=>$this->pin,'inputs'=>$values,'parameters'=>$retainedParameters,'output_unit'=>$this->definition['output_unit'],'steps'=>$steps,'terminal'=>$result->jsonSerialize()]);
    }
    /** Structured boundary for trusted callers; evaluation's ordinary JSON never reveals trace/values. */
    public static function attempt(array $snapshot, array $pin, string $unit, array $inputs, array $parameters = []): array
    {
        try { return ['ready'=>true,'evaluation'=>self::compile($snapshot,$pin,$unit)->evaluate($inputs,$parameters),'diagnostics'=>[]]; }
        catch (AF_ACS_FormulaError $error) { return ['ready'=>false,'evaluation'=>null,'diagnostics'=>[$error->diagnostic()]]; }
    }
}
