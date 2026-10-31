<?php
declare(strict_types=1);

/** Pure N1/N2 arithmetic. No bootstrap, storage, formula execution or global configuration. */
final class AF_ACS_Numeric
{
    public const POLICY = 'binary64-half_up-v1';
    public const MAX_INTEGER = 9007199254740991;
    public const ROUNDING = ['none', 'floor', 'ceil', 'truncate', 'half_up', 'half_even'];

    public static function platform(): void
    {
        if (PHP_INT_SIZE !== 8 || PHP_FLOAT_DIG !== 15 || PHP_FLOAT_EPSILON !== 2.220446049250313e-16 || PHP_FLOAT_MAX !== 1.7976931348623157e308) {
            throw new DomainException('ACS_NUMERIC_PLATFORM_UNSUPPORTED');
        }
    }

    /** Accept only actual numbers; never coerce strings, booleans or finalized results. */
    public static function number(mixed $value): int|float
    {
        self::platform();
        if ($value instanceof AF_ACS_NumericResult) {
            throw new DomainException('ACS_NUMERIC_ALREADY_FINALIZED');
        }
        if (!is_int($value) && !is_float($value)) {
            throw new DomainException('ACS_NUMERIC_TYPE_INVALID');
        }
        if (is_nan((float)$value)) { throw new DomainException('ACS_NUMERIC_NAN'); }
        if (!is_finite((float)$value)) { throw new DomainException('ACS_NUMERIC_INFINITY'); }
        // Integral binary64 outside this contiguous range cannot prove unit precision.
        if (abs((float)$value) > self::MAX_INTEGER) {
            throw new DomainException('ACS_NUMERIC_UNSAFE_INTEGER');
        }
        return $value;
    }

    /** Exact storage integers, with explicit game bounds; no truncation/coercion. */
    public static function integer(mixed $value, mixed $min = 0, mixed $max = self::MAX_INTEGER): int
    {
        self::bounds($min, $max, true);
        if ($min === null || $max === null) { throw new DomainException('ACS_NUMERIC_BOUNDS_INVALID'); }
        $value = self::number($value);
        if (!is_int($value)) { throw new DomainException('ACS_NUMERIC_INTEGER_REQUIRED'); }
        if ($value < $min || $value > $max) { throw new DomainException('ACS_NUMERIC_INTEGER_RANGE'); }
        return $value;
    }

    /** Exactly one operation in the caller's declared order; no associative rewrites. */
    public static function operation(string $op, mixed $left, mixed $right = null): float
    {
        $a = (float)self::number($left);
        if ($op === 'negate') {
            if ($right !== null) { throw new DomainException('ACS_NUMERIC_ARITY_INVALID'); }
            return -$a;
        }
        $b = (float)self::number($right);
        if ($op === 'divide' && $b == 0.0) { throw new DomainException('ACS_NUMERIC_DIVISION_BY_ZERO'); }
        $result = match ($op) {
            'add' => $a + $b, 'subtract' => $a - $b,
            'multiply' => $a * $b, 'divide' => $a / $b,
            'min' => min($a, $b), 'max' => max($a, $b),
            default => throw new DomainException('ACS_NUMERIC_OPERATION_UNSUPPORTED'),
        };
        if (!is_finite($result)) { throw new DomainException('ACS_NUMERIC_OVERFLOW'); }
        if (($op === 'multiply' || $op === 'divide') && $a != 0.0 && $b != 0.0 && $result == 0.0) {
            throw new DomainException('ACS_NUMERIC_UNDERFLOW');
        }
        // Complete absorption is an observable loss of an operand, not an epsilon-based correction.
        if (($op === 'add' && (($b != 0.0 && $result === $a) || ($a != 0.0 && $result === $b)))
            || ($op === 'subtract' && (($b != 0.0 && $result === $a) || ($a != 0.0 && $result === -$b)))) {
            throw new DomainException('ACS_NUMERIC_PRECISION_LOSS');
        }
        return (float)self::number($result);
    }

    private static function bounds(mixed $min, mixed $max, bool $integer): void
    {
        foreach ([$min, $max] as $bound) {
            if ($bound === null) { continue; }
            self::number($bound);
            if ($integer && (!is_int($bound) && floor($bound) !== $bound)) {
                throw new DomainException('ACS_NUMERIC_BOUNDS_INVALID');
            }
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new DomainException('ACS_NUMERIC_BOUNDS_INVALID');
        }
    }

    /** One declared output boundary: round, validate integer compatibility, then clamp. */
    public static function terminal(
        mixed $raw, string $rounding = 'half_up', string $outputType = 'integer',
        mixed $min = null, mixed $max = null
    ): AF_ACS_NumericResult {
        $raw = self::number($raw);
        if (!in_array($rounding, self::ROUNDING, true)) { throw new DomainException('ACS_NUMERIC_ROUNDING_UNSUPPORTED'); }
        if (!in_array($outputType, ['number', 'integer'], true)) { throw new DomainException('ACS_NUMERIC_OUTPUT_INVALID'); }
        self::bounds($min, $max, $outputType === 'integer');
        $rounded = match ($rounding) {
            'none' => $raw, 'floor' => floor($raw), 'ceil' => ceil($raw),
            'truncate' => $raw < 0 ? ceil($raw) : floor($raw),
            'half_up' => round($raw, 0, PHP_ROUND_HALF_UP),
            'half_even' => round($raw, 0, PHP_ROUND_HALF_EVEN),
        };
        self::number($rounded);
        if ($outputType === 'integer') {
            if (floor((float)$rounded) !== (float)$rounded) { throw new DomainException('ACS_NUMERIC_INTEGER_REQUIRED'); }
            $rounded = (int)$rounded;
        }
        $clamped = $rounded;
        if ($min !== null && $clamped < $min) { $clamped = $min; }
        if ($max !== null && $clamped > $max) { $clamped = $max; }
        if ($outputType === 'integer') { $clamped = (int)$clamped; }
        return new AF_ACS_NumericResult($raw, $rounded, $clamped, $rounding, $outputType);
    }

    /** Detect unsuitable host serialize_precision instead of silently losing source fractions. */
    public static function encode(mixed $value): string
    {
        $payload = $value instanceof AF_ACS_NumericResult ? $value->jsonSerialize() : self::number($value);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if ($decoded !== $payload) { throw new DomainException('ACS_NUMERIC_SERIALIZATION_PRECISION'); }
        return $json;
    }
}

/** Terminal trace; intentionally not accepted as a new raw arithmetic operand. */
final class AF_ACS_NumericResult implements JsonSerializable
{
    public function __construct(
        public readonly int|float $raw,
        public readonly int|float $rounded,
        public readonly int|float $clamped,
        public readonly string $rounding,
        public readonly string $outputType
    ) {}

    public function jsonSerialize(): array
    {
        return ['numeric_policy' => AF_ACS_Numeric::POLICY, 'raw' => $this->raw,
            'rounded' => $this->rounded, 'clamped' => $this->clamped,
            'rounding' => $this->rounding, 'output_type' => $this->outputType];
    }
}
