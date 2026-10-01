<?php

class AtfResult { public array $rows; public int $position = 0; public function __construct(array $rows) { $this->rows = array_values($rows); } }
class AtfDatabase {
    public array $tables = [];
    public array $columns = [];
    public array $indexes = [];
    public int $nextId = 100;
    public function __construct(bool $legacy = false, array $legacyRows = []) {
        $this->tables['templates'] = [
            ['tid'=>1, 'title'=>'index', 'template'=>'MASTER', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>2, 'title'=>'index', 'template'=>'CUSTOM', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
        ];
        $this->tables['templatesets'] = [['sid'=>1, 'title'=>'Default']];
        if ($legacy) {
            $name = 'af_adaptivethemeframework_template_ownership';
            $this->tables[$name] = $legacyRows;
            $this->columns[$name] = ['id', 'template_name', 'template_sid', 'previous_content'];
            $this->indexes[$name] = [];
        }
    }
    public function table_exists($table) { return array_key_exists($table, $this->tables); }
    public function build_create_table_collation() { return ' DEFAULT CHARSET=utf8mb4'; }
    public function write_query($sql) {
        $name = 'af_adaptivethemeframework_template_ownership';
        if (str_starts_with($sql, 'SELECT template_sid')) {
            $counts = [];
            foreach ($this->tables[$name] as $row) {
                $key = (int)($row['template_sid'] ?? 0).'|'.(string)($row['template_name'] ?? '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            $rows = [];
            foreach ($counts as $key => $count) if ($count > 1) {
                [$sid, $template] = explode('|', $key, 2);
                $rows[] = ['template_sid'=>(int)$sid, 'template_name'=>$template, 'cnt'=>$count];
            }
            return new AtfResult(array_slice($rows, 0, 1));
        } elseif (str_starts_with($sql, 'CREATE TABLE')) {
            $this->tables[$name] = [];
            $this->columns[$name] = array_keys(af_adaptivethemeframework_ownership_columns());
            $this->indexes[$name] = ['template_lease', 'ownership_state', 'template_tid'];
        } elseif (preg_match('/ADD (?:UNIQUE )?KEY ([a-z_]+)/', $sql, $match)) {
            $this->indexes[$name][] = $match[1];
        }
        return true;
    }
    public function field_exists($field, $table) { return in_array($field, $this->columns[$table] ?? [], true); }
    public function add_column($table, $field, $definition) {
        $this->columns[$table][] = $field;
        $default = str_contains($definition, "migration_review") ? 'migration_review' : (str_contains($definition, 'mediumtext') || str_contains($definition, "DEFAULT ''") ? '' : 0);
        foreach ($this->tables[$table] as &$row) { $row[$field] = $default; }
    }
    public function index_exists($table, $name) { return in_array($name, $this->indexes[$table] ?? [], true); }
    private function matches(array $row, string $where): bool {
        if ($where === '' || $where === 'sid>0') return $where === '' || (int)$row['sid'] > 0;
        preg_match_all("/([a-z_]+)='?(-?[a-z0-9_]+)'?/i", $where, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) if ((string)($row[$part[1]] ?? '') !== (string)$part[2]) return false;
        return true;
    }
    public function simple_select($table, $fields='*', $where='', $options=[]) {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row, $where)));
        if (($options['limit'] ?? 0) === 1) $rows = array_slice($rows, 0, 1);
        return new AtfResult($rows);
    }
    public function fetch_array($result) { return $result->rows[$result->position++] ?? false; }
    public function insert_query($table, $row) {
        if ($table === 'templates') $row['tid'] = $this->nextId++;
        else $row['id'] = $this->nextId++;
        $this->tables[$table][] = $row;
        return $row[$table === 'templates' ? 'tid' : 'id'];
    }
    public function update_query($table, $values, $where) {
        foreach ($this->tables[$table] as &$row) if ($this->matches($row, $where)) $row = array_merge($row, $values);
        return true;
    }
    public function delete_query($table, $where) {
        $this->tables[$table] = array_values(array_filter($this->tables[$table], fn($row) => !$this->matches($row, $where)));
    }
}

define('IN_MYBB', true);
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 12345);
$addon = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
define('AF_ADDONS', dirname($addon) . '/');
require $addon . '/adaptivethemeframework.php';
$seed = af_adaptivethemeframework_index_seed();

// A: no ledger -> current schema -> acquire.
$db = new AtfDatabase();
if (!af_adaptivethemeframework_activate() || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 1 || $db->tables['templates'][1]['template'] !== $seed) throw new RuntimeException('fresh acquisition failed');
$master = $db->tables['templates'][0];
// activate -> deactivate -> activate must restore and reuse the one natural-key row.
af_adaptivethemeframework_deactivate();
if ($db->tables['templates'][1]['template'] !== 'CUSTOM') throw new RuntimeException('deactivation did not restore previous content');
af_adaptivethemeframework_activate();
if (count($db->tables['af_adaptivethemeframework_template_ownership']) !== 1 || $db->tables['templates'][0] !== $master) throw new RuntimeException('repeat lifecycle made a backup chain or changed master');

// B/C: previous schema is ALTERed in place; legacy bytes survive and fail closed.
$legacy = ['id'=>7, 'template_name'=>'index', 'template_sid'=>1, 'previous_content'=>'IRREPLACEABLE BACKUP'];
$db = new AtfDatabase(true, [$legacy]);
$failedClosed = af_adaptivethemeframework_activate() === false
    && str_starts_with((string)($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? ''), 'update_lease');
$row = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if (!$failedClosed || $row['previous_content'] !== 'IRREPLACEABLE BACKUP' || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 1 || $db->tables['templates'][1]['template'] !== 'CUSTOM') throw new RuntimeException('legacy migration did not preserve/fail closed');
foreach (array_keys(af_adaptivethemeframework_ownership_columns()) as $column) if (!$db->field_exists($column, 'af_adaptivethemeframework_template_ownership')) throw new RuntimeException("missing migrated column {$column}");

// Duplicate natural keys are reported before UNIQUE DDL; no lease or template is deleted/changed.
$duplicateRows = [$legacy, array_merge($legacy, ['id'=>8, 'previous_content'=>'SECOND BACKUP'])];
$db = new AtfDatabase(true, $duplicateRows);
$templatesBeforeDuplicateCheck = $db->tables['templates'];
if (af_adaptivethemeframework_schema_readiness() !== false
    || ($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? '') !== 'check_duplicate_leases'
    || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 2
    || $db->tables['templates'] !== $templatesBeforeDuplicateCheck) {
    throw new RuntimeException('duplicate lease preflight was not safe');
}

// D: proven lease plus changed live index becomes conflict and is never overwritten.
$db = new AtfDatabase();
af_adaptivethemeframework_activate();
$db->tables['templates'][1]['template'] = 'MANUAL EDIT';
$conflict = af_adaptivethemeframework_activate() === false;
if (!$conflict || $db->tables['templates'][1]['template'] !== 'MANUAL EDIT' || $db->tables['af_adaptivethemeframework_template_ownership'][0]['ownership_state'] !== 'manual_override') throw new RuntimeException('manual conflict was overwritten');

echo "ATF ownership fresh/upgrade/conflict lifecycle passed.\n";
