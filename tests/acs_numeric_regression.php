<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Numeric.php';

function numeric_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
}
function numeric_error(callable $call, string $code): void {
    try { $call(); } catch (DomainException $error) {
        numeric_check($error->getMessage() === $code, $code.' instead got '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Missing '.$code);
}
AF_ACS_Numeric::platform();
foreach ([[-2.5,-3],[-1.5,-2],[-0.5,-1],[0.0,0],[0.5,1],[1.5,2],[2.5,3]] as [$input,$expected]) {
    numeric_check(AF_ACS_Numeric::terminal($input)->clamped === $expected, 'half_up boundary');
}
foreach (['none'=>-2.5,'floor'=>-3,'ceil'=>-2,'truncate'=>-2,'half_up'=>-3,'half_even'=>-2] as $mode=>$expected) {
    numeric_check(AF_ACS_Numeric::terminal(-2.5,$mode,'number')->rounded == $expected, 'explicit '.$mode);
}
$third = AF_ACS_Numeric::operation('divide',1,3);
numeric_check($third === 1/3 && AF_ACS_Numeric::operation('multiply',$third,3) === 1.0, 'Fraction lost at intermediate');
$sum = AF_ACS_Numeric::operation('add',0.4,0.4);
$result = AF_ACS_Numeric::terminal($sum);
numeric_check($result->raw === 0.8 && $result->rounded === 1 && $result->clamped === 1, 'Single terminal budget');
numeric_error(fn()=>AF_ACS_Numeric::terminal($result),'ACS_NUMERIC_ALREADY_FINALIZED');
numeric_error(fn()=>AF_ACS_Numeric::operation('add',$result,1),'ACS_NUMERIC_ALREADY_FINALIZED');
$clamp = AF_ACS_Numeric::terminal(2.6,'half_up','number',null,2.4);
numeric_check($clamp->raw === 2.6 && $clamp->rounded === 3.0 && $clamp->clamped === 2.4, 'Round before clamp');
numeric_error(fn()=>AF_ACS_Numeric::terminal(2.6,'half_up','integer',null,2.4),'ACS_NUMERIC_BOUNDS_INVALID');
numeric_error(fn()=>AF_ACS_Numeric::terminal(2,'none','integer',5,1),'ACS_NUMERIC_BOUNDS_INVALID');
numeric_error(fn()=>AF_ACS_Numeric::terminal(2.5,'none','integer',null,2),'ACS_NUMERIC_INTEGER_REQUIRED');
numeric_error(fn()=>AF_ACS_Numeric::terminal(2.5,'guessed'),'ACS_NUMERIC_ROUNDING_UNSUPPORTED');
numeric_check(AF_ACS_Numeric::terminal($third,'none','number')->raw === $third,'Explicit none replaced');
foreach ([0,0.0,-0.0] as $zero) { numeric_error(fn()=>AF_ACS_Numeric::operation('divide',1,$zero),'ACS_NUMERIC_DIVISION_BY_ZERO'); }
numeric_error(fn()=>AF_ACS_Numeric::number(NAN),'ACS_NUMERIC_NAN');
foreach ([INF,-INF] as $infinity) { numeric_error(fn()=>AF_ACS_Numeric::number($infinity),'ACS_NUMERIC_INFINITY'); }
numeric_error(fn()=>AF_ACS_Numeric::operation('divide',1e15,1e-300),'ACS_NUMERIC_OVERFLOW');
numeric_error(fn()=>AF_ACS_Numeric::operation('multiply',1e-300,1e-300),'ACS_NUMERIC_UNDERFLOW');
numeric_error(fn()=>AF_ACS_Numeric::operation('add',1e15,1e-10),'ACS_NUMERIC_PRECISION_LOSS');
numeric_error(fn()=>AF_ACS_Numeric::operation('subtract',1e-10,1e15),'ACS_NUMERIC_PRECISION_LOSS');
foreach ([9007199254740992,9007199254740992.0,-9007199254740992] as $unsafe) { numeric_error(fn()=>AF_ACS_Numeric::number($unsafe),'ACS_NUMERIC_UNSAFE_INTEGER'); }
numeric_error(fn()=>AF_ACS_Numeric::operation('add',9007199254740991,1),'ACS_NUMERIC_UNSAFE_INTEGER');
numeric_check(AF_ACS_Numeric::integer(9007199254740991) === 9007199254740991,'Last safe storage integer');
numeric_check(AF_ACS_Numeric::terminal(9007199254740991)->clamped === 9007199254740991,'Last safe terminal integer');
numeric_check(AF_ACS_Numeric::integer(0) === 0 && AF_ACS_Numeric::encode(3) === '3','Storage integer became float');
numeric_error(fn()=>AF_ACS_Numeric::integer(3.0),'ACS_NUMERIC_INTEGER_REQUIRED');
numeric_error(fn()=>AF_ACS_Numeric::integer(-1),'ACS_NUMERIC_INTEGER_RANGE');
foreach (['3',true,null,[],new stdClass] as $bad) { numeric_error(fn()=>AF_ACS_Numeric::number($bad),'ACS_NUMERIC_TYPE_INVALID'); }
// Only caller order determines the binary64 path. No sorting/reassociation/epsilon repair.
$a = AF_ACS_Numeric::operation('add',AF_ACS_Numeric::operation('add',1e15,-1e15),0.1);
$b = AF_ACS_Numeric::operation('add',1e15,AF_ACS_Numeric::operation('add',-1e15,0.1));
numeric_check($a === 0.1 && $b === 0.125,'Operation order changed');
$encoded = AF_ACS_Numeric::encode($result);
$decoded = json_decode($encoded,true,16,JSON_THROW_ON_ERROR);
numeric_check($decoded['rounded'] === 1 && $decoded['clamped'] === 1 && $decoded['numeric_policy'] === 'binary64-half_up-v1','Trace/integer JSON changed');
$precision = ini_get('serialize_precision');
try {
    ini_set('serialize_precision','3');
    numeric_error(fn()=>AF_ACS_Numeric::encode($third),'ACS_NUMERIC_SERIALIZATION_PRECISION');
} finally { ini_set('serialize_precision',$precision); }
numeric_check(AF_ACS_Numeric::encode($third) === json_encode($third,JSON_PRESERVE_ZERO_FRACTION),'Round-trip fraction');
echo "ACS Numeric: binary64 order/fractions, all six policies, signed half_up, round-before-clamp, finite/div0/overflow/underflow/unsafe integers, one boundary and exact JSON passed\n";
