<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Formula.php';
if (defined('IN_MYBB')) { throw new RuntimeException('Domain unexpectedly bootstrapped MyBB'); }
define('IN_MYBB',true); define('MYBB_ROOT',dirname(__DIR__).'/'); define('AF_ADDONS',MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/');
function af_is_addon_enabled(string $id): bool { return !in_array($id,$GLOBALS['disabled'] ?? [],true); }
$mybb=(object)['user'=>['uid'=>7,'usergroup'=>2],'usergroup'=>['cancp'=>0],'settings'=>['af_kb_public_catalog'=>1]];
require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php';
function formula_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function formula_literal(int|float $n): array { return ['type'=>'literal','value'=>$n]; }
function formula_op(string $op,array ...$args): array { return ['type'=>'operation','operator'=>$op,'args'=>$args]; }
function formula_pin(array $s): array { return array_intersect_key($s,array_flip(['key','version','fingerprint'])); }
function formula_run(array $d,array $inputs=[],array $parameters=[]): array {
    $s=af_kb_arpg_formula_snapshot($d); return AF_ACS_Formula::attempt($s,formula_pin($s),'stat_points',$inputs,$parameters);
}
function formula_error(array $r,string $code): void {
    formula_check(!$r['ready'] && $r['evaluation']===null && $r['diagnostics'][0]['code']===$code,'Expected '.$code.', got '.json_encode($r));
}
$base=['key'=>'fixture_formula','version'=>'1','inputs'=>[], 'expression'=>formula_op('add',formula_literal(2),formula_literal(3)),
    'output_type'=>'integer','output_unit'=>'stat_points','rounding'=>'half_up','notes'=>'Private fixture notes'];
formula_check(!af_kb_arpg_formula_definition_errors($base),'KB contract rejected fixture');
$s=af_kb_arpg_formula_snapshot($base);$pin=formula_pin($s);
$r=AF_ACS_Formula::attempt($s,$pin,'stat_points',[]);
formula_check($r['ready'] && $r['evaluation']->serverResult()->clamped===5,'Simple arithmetic failed');
$nested=$base;$nested['inputs']=['amount'=>'integer','factor'=>'number'];
$nested['expression']=formula_op('add',['type'=>'input','name'=>'amount'],formula_op('multiply',formula_literal(2),['type'=>'input','name'=>'factor']));
$r=formula_run($nested,['amount'=>10],['factor'=>0.25]);
formula_check($r['ready'] && $r['evaluation']->serverResult()->raw===10.5 && $r['evaluation']->serverResult()->clamped===11,'Nested/profile output failed');
formula_check($r['evaluation']->serverTrace()===formula_run($nested,['amount'=>10],['factor'=>0.25])['evaluation']->serverTrace(),'Nonreproducible trace');
formula_check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'10.5') && !str_contains(json_encode($r),'amount'),'Ordinary JSON leaked private trace');
formula_error(formula_run($nested,['amount'=>10,'factor'=>0.25],['factor'=>0.25]),'formula_input_collision');
formula_error(formula_run($nested,['amount'=>10]),'formula_input_missing');
formula_error(formula_run($nested,['amount'=>10.0,'factor'=>0.25]),'formula_parameter_mismatch');
formula_error(formula_run($nested,['amount'=>10,'factor'=>0.25,'extra'=>0]),'formula_parameter_mismatch');
formula_error(formula_run($nested,['amount'=>10,'factor'=>INF]),'ACS_NUMERIC_INFINITY');
formula_error(formula_run($nested,['amount'=>true,'factor'=>0]),'ACS_NUMERIC_TYPE_INVALID');
$d=$base;$d['expression']=formula_op('max',formula_op('negate',formula_literal(-3)),formula_op('min',formula_literal(8),formula_op('subtract',formula_literal(10),formula_op('divide',formula_literal(6),formula_literal(2)))));
formula_check(formula_run($d)['evaluation']->serverResult()->clamped===7,'All operations failed');
$d['expression']=formula_op('min',...array_map('formula_literal',range(1,8)));
formula_check(formula_run($d)['evaluation']->serverResult()->clamped===1,'8-arity min failed');
$d['expression']=formula_op('divide',formula_literal(1),formula_literal(0));
// Compile is structural: even a constant div0 must not execute until evaluate.
$compiled=AF_ACS_Formula::compile(af_kb_arpg_formula_snapshot($d),formula_pin(af_kb_arpg_formula_snapshot($d)),'stat_points');
formula_error(formula_run($d),'ACS_NUMERIC_DIVISION_BY_ZERO');
$d['expression']=formula_op('divide',formula_literal(1e15),formula_literal(1e-300));formula_error(formula_run($d),'ACS_NUMERIC_OVERFLOW');
$d['expression']=formula_op('php_eval',formula_literal(1));formula_error(formula_run($d),'unsupported_operation');
$d['expression']=formula_op('add',formula_literal(1));formula_error(formula_run($d),'formula_arity_invalid');
$d['expression']=['type'=>'literal','value'=>'system()'];formula_error(formula_run($d),'ACS_NUMERIC_TYPE_INVALID');
$d['expression']=['type'=>'input','name'=>'missing'];formula_error(formula_run($d),'formula_input_undeclared');
$d=$base;$d['expression']['url']='https://example.invalid';formula_error(formula_run($d),'invalid_formula_contract');
$d=$base;$d['rounding']='automatic';formula_error(formula_run($d),'ACS_NUMERIC_ROUNDING_UNSUPPORTED');
foreach (['floor'=>2,'ceil'=>3,'truncate'=>2,'half_up'=>3,'half_even'=>2] as $mode=>$expected) {
    $d=$base;$d['expression']=formula_literal(2.5);$d['rounding']=$mode;
    formula_check(formula_run($d)['evaluation']->serverResult()->clamped===$expected,'Output policy replaced: '.$mode);
}
$d=$base;$d['expression']=formula_op('divide',formula_literal(1),formula_literal(3));$d['rounding']='none';$d['output_type']='number';
formula_check(formula_run($d)['evaluation']->serverResult()->clamped===1/3,'Intermediate fraction rounded');
$d['output_type']='integer';formula_error(formula_run($d),'ACS_NUMERIC_INTEGER_REQUIRED');
$d=$base;$d['output_type']='number';$d['rounding']='none';
$d['expression']=formula_op('add',formula_op('add',formula_literal(1e15),formula_literal(-1e15)),formula_literal(0.1));
formula_check(formula_run($d)['evaluation']->serverResult()->clamped===0.1,'AST order reassociated');
$d['expression']=formula_op('add',formula_literal(1e15),formula_op('add',formula_literal(-1e15),formula_literal(0.1)));
formula_check(formula_run($d)['evaluation']->serverResult()->clamped===0.125,'AST grouping ignored');
$d=$base;$d['max_value']=2.4;formula_error(formula_run($d),'ACS_NUMERIC_BOUNDS_INVALID');
$d=$base;$d['min_value']=null;formula_error(formula_run($d),'ACS_NUMERIC_TYPE_INVALID');
$bad=$s;$bad['schema']='af_kb.arpg.formula.v2';formula_error(AF_ACS_Formula::attempt($bad,$pin,'stat_points',[]),'unsupported_formula_schema');
$bad=$s;$bad['version']='2';formula_error(AF_ACS_Formula::attempt($bad,$pin,'stat_points',[]),'formula_version_mismatch');
$bad=$s;$bad['definition']['expression']=formula_literal(0);formula_error(AF_ACS_Formula::attempt($bad,$pin,'stat_points',[]),'formula_fingerprint_mismatch');
$bad=$pin;$bad['fingerprint']='sha256:'.str_repeat('0',64);formula_error(AF_ACS_Formula::attempt($s,$bad,'stat_points',[]),'formula_fingerprint_mismatch');
formula_error(AF_ACS_Formula::attempt($s,$pin,'HP',[]),'formula_unit_mismatch');
$d=$base;$d['expression']=formula_literal(1);for($i=0;$i<16;$i++)$d['expression']=formula_op('negate',$d['expression']);
formula_check(formula_run($d)['ready'],'KB depth 16 rejected');
$d['expression']=formula_op('negate',$d['expression']);formula_error(formula_run($d),'formula_ast_limit');
$tree=formula_literal(1);for($i=0;$i<7;$i++)$tree=formula_op('add',$tree,$tree);
$d=$base;$d['expression']=formula_op('negate',$tree);formula_check(formula_run($d)['ready'],'KB 256 nodes rejected');
$d['expression']=formula_op('add',$tree,$tree);formula_error(formula_run($d),'formula_ast_limit');
$d=$base;$d['inputs']=array_fill_keys(array_map(fn($i)=>'i'.$i,range(1,65)),'number');formula_error(formula_run($d),'invalid_formula_contract');
// Retained compiled snapshot must not follow PHP references or subsequent live KB edits.
$literal=4;$d=$base;$d['expression']=['type'=>'literal','value'=>&$literal];$snapshot=af_kb_arpg_formula_snapshot($d);
$compiled=AF_ACS_Formula::compile($snapshot,formula_pin($snapshot),'stat_points');$literal=100;
formula_check($compiled->evaluate([])->serverResult()->clamped===4,'Compiled snapshot reference mutated');

final class FormulaReadRows { public int $offset=0; public function __construct(public array $rows) {} }
final class FormulaReadOnlyDb {
    public array $entries=[];public int $reads=0;
    public function table_exists(string $table): bool { return $table==='af_kb_entries'; }
    public function escape_string(string $v): string { return addslashes($v); }
    public function simple_select(string $table,string $fields,string $where,array $options=[]): FormulaReadRows {
        $this->reads++;preg_match("/`key`='([^']+)'/",$where,$m);return new FormulaReadRows(isset($this->entries[$m[1]??''])?[$this->entries[$m[1]]]:[]);
    }
    public function fetch_array(FormulaReadRows $r): array|false { return $r->rows[$r->offset++]??false; }
    public function __call(string $name,array $args): never { throw new RuntimeException('Write/unsupported DB call forbidden'); }
}
$db=new FormulaReadOnlyDb;
$envelope=['schema'=>AF_KB_ARPG_RULES_SCHEMA,'type_profile'=>'service_mechanics','service_kind'=>'formula_def','entries'=>[$base]];
$db->entries['formula_def']=['id'=>91,'type'=>'arpg_mechanics','key'=>'formula_def','active'=>1,'data_json'=>json_encode($envelope,JSON_THROW_ON_ERROR),'meta_json'=>'{}'];
$before=$db->entries;$kb=new AF_ACS_KnowledgeBaseAdapter;$read=$kb->readFormula($pin,7,'stat_points');
formula_check($read['ready'],'Owner reader blocked: '.json_encode($read['diagnostics']));
$retained=$read['data']['formula'];formula_check(AF_ACS_Formula::attempt($retained,$pin,'stat_points',[])['ready'],'Adapter -> compiler failed');
formula_check($db->entries===$before,'Read modified owner data');
formula_check(!$kb->readFormula($pin,8,'stat_points')['ready'],'Spoofed actor read accepted');
$private=$envelope;$private['visibility']=['internal'=>true];$db->entries['formula_def']['data_json']=json_encode($private);
formula_check($kb->readFormula($pin,7,'stat_points')['diagnostics'][0]['code']==='definition_access_denied','Private formula leaked');
$db->entries=$before;$changed=$envelope;$changed['entries'][0]['expression']=formula_literal(100);$db->entries['formula_def']['data_json']=json_encode($changed);
formula_check($kb->readFormula($pin,7,'stat_points')['diagnostics'][0]['code']==='formula_fingerprint_mismatch','Same-version drift accepted');
formula_check(AF_ACS_Formula::attempt($retained,$pin,'stat_points',[])['evaluation']->serverResult()->clamped===5,'Pinned snapshot replaced by live KB');
$changed['entries'][0]['version']='2';$db->entries['formula_def']['data_json']=json_encode($changed);
formula_check($kb->readFormula($pin,7,'stat_points')['diagnostics'][0]['code']==='formula_version_mismatch','Live latest version selected');
$db->entries=[];formula_check(!$kb->readFormula($pin,7,'stat_points')['ready'],'Missing formula accepted');
$db->entries=$before;$unsupported=$envelope;$unsupported['schema']='af_kb.arpg.rules.v99';$db->entries['formula_def']['data_json']=json_encode($unsupported);
formula_check($kb->readFormula($pin,7,'stat_points')['diagnostics'][0]['code']==='unsupported_rules_schema','Unknown source schema accepted');
$db->entries=$before;$GLOBALS['disabled']=['knowledgebase'];
formula_check(!$kb->readFormula($pin,7,'stat_points')['ready'],'Disabled owner accepted');$GLOBALS['disabled']=[];
echo "ACS Formula: real KB snapshot/adapter, all AST operations/limits, exact version/hash/unit, typed inputs/profile collision, fixed order/output policy, safe trace, pinned replay and read-only owner passed\n";
