<?php

class AtfResult { public array $rows; public int $position = 0; public function __construct(array $rows) { $this->rows = array_values($rows); } }
class AtfDatabase {
    public array $tables = [];
    public array $columns = [];
    public array $indexes = [];
    public int $nextId = 100;
    public array $writeLog = [];
    public function __construct(bool $legacy = false, array $legacyRows = []) {
        $previous = "<title>{\$mybb->settings['bbname']}</title>\n<script>\nvar x = '{\$lang->some_value}';\nvar path = \"C:\\\\themes\\\"atf\\\"\";\n</script>";
        $this->tables['templates'] = [
            ['tid'=>1, 'title'=>'index', 'template'=>'MASTER', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>2, 'title'=>'index', 'template'=>$previous, 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>3, 'title'=>'forumbit_depth2_forum', 'template'=>'MASTER FORUM', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>4, 'title'=>'forumbit_depth2_forum', 'template'=>'PREVIOUS FORUM', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>5, 'title'=>'forumbit_depth2_forum_lastpost', 'template'=>'MASTER LASTPOST', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>6, 'title'=>'forumbit_depth2_forum_lastpost', 'template'=>'PREVIOUS LASTPOST', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>7, 'title'=>'forumdisplay_thread', 'template'=>'MASTER THREAD ROW', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>8, 'title'=>'forumdisplay_thread', 'template'=>'PREVIOUS THREAD ROW', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>9, 'title'=>'showthread', 'template'=>'MASTER SHOWTHREAD', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>10, 'title'=>'showthread', 'template'=>'APUI SHOWTHREAD BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>11, 'title'=>'postbit_classic', 'template'=>'MASTER CLASSIC POST', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>12, 'title'=>'postbit_classic', 'template'=>'APUI CLASSIC POST BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>13, 'title'=>'member_profile', 'template'=>'MASTER MEMBER PROFILE', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>14, 'title'=>'member_profile', 'template'=>'APUI MEMBER PROFILE BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
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
    public function escape_string($value) {
        return strtr((string)$value, ["\\"=>"\\\\", "\0"=>"\\0", "\n"=>"\\n", "\r"=>"\\r", "'"=>"\\'", '"'=>'\\"']);
    }
    private function decodeWrite(array $row): array {
        foreach ($row as $key => $value) {
            if (!is_string($value)) continue;
            // MyBB quotes values without escaping them. Model the resulting SQL
            // literal, rejecting raw quotes and decoding what MySQL stores.
            for ($i = 0, $length = strlen($value); $i < $length; $i++) {
                if ($value[$i] === "'") throw new RuntimeException("SQL syntax error: unescaped quote in {$key}");
                if ($value[$i] !== "\\") continue;
                if (++$i >= $length) throw new RuntimeException("SQL syntax error: dangling backslash in {$key}");
            }
            $row[$key] = preg_replace_callback('/\\\\([0nr\\\\\'\"])/', static fn($match) => match ($match[1]) {
                '0' => "\0", 'n' => "\n", 'r' => "\r", default => $match[1],
            }, $value);
        }
        return $row;
    }
    public function insert_query($table, $row) {
        $this->writeLog[] = ['insert', $table, $row];
        $row = $this->decodeWrite($row);
        if ($table === 'templates') $row['tid'] = $this->nextId++;
        else $row['id'] = $this->nextId++;
        $this->tables[$table][] = $row;
        return $row[$table === 'templates' ? 'tid' : 'id'];
    }
    public function update_query($table, $values, $where) {
        $this->writeLog[] = ['update', $table, $values];
        $values = $this->decodeWrite($values);
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
require dirname($addon) . '/advancedposteravatar/advancedposteravatar.php';
af_apa_register_atf_compatibility_normalizer();
$seed = af_adaptivethemeframework_index_seed();

// A: no ledger -> current schema -> acquire.
$db = new AtfDatabase();
$previous = $db->tables['templates'][1]['template'];
$originalTemplates = array_column(array_filter($db->tables['templates'], static fn($row) => (int)$row['sid'] === 1), 'template', 'title');
if (!af_adaptivethemeframework_activate() || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 7 || $db->tables['templates'][1]['template'] !== $seed) throw new RuntimeException('fresh acquisition failed');
$lease = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if ($lease['previous_content'] !== $previous || $lease['atf_seed_content'] !== $seed) throw new RuntimeException('SQL-safe lease did not round-trip raw template content');
$master = $db->tables['templates'][0];
// activate -> deactivate -> activate must restore and reuse the one natural-key row.
af_adaptivethemeframework_deactivate();
foreach ($originalTemplates as $title => $content) {
    $restored = array_values(array_filter($db->tables['templates'], static fn($row) => (int)$row['sid'] === 1 && $row['title'] === $title));
    if (count($restored) !== 1 || $restored[0]['template'] !== $content) throw new RuntimeException("deactivation did not restore {$title}");
}
af_adaptivethemeframework_activate();
if (count($db->tables['af_adaptivethemeframework_template_ownership']) !== 7 || $db->tables['templates'][0] !== $master) throw new RuntimeException('repeat lifecycle made a backup chain or changed master');
foreach ($db->tables['af_adaptivethemeframework_template_ownership'] as $row) {
    if ($row['previous_content'] !== $originalTemplates[$row['template_name']] || $row['ownership_state'] !== 'owned') throw new RuntimeException("repeat lifecycle lost backup for {$row['template_name']}");
}

// B/C: previous schema is ALTERed in place; legacy bytes survive and fail closed.
$legacy = ['id'=>7, 'template_name'=>'index', 'template_sid'=>1, 'previous_content'=>'IRREPLACEABLE BACKUP'];
$db = new AtfDatabase(true, [$legacy]);
$failedClosed = af_adaptivethemeframework_activate() === false
    && str_starts_with((string)($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? ''), 'update_lease');
$row = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if (!$failedClosed || $row['previous_content'] !== 'IRREPLACEABLE BACKUP' || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 1 || $db->tables['templates'][1]['template'] !== $previous) throw new RuntimeException('legacy migration did not preserve/fail closed');
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
if (($GLOBALS['af_adaptivethemeframework_activation_stage'] ?? '') !== 'update_lease[template=index,sid=1]') throw new RuntimeException('conflict stage omits template identity');

// A diagnostic state is recoverable when checksum evidence says the original
// pre-ATF value is live again. The original backup must remain unchanged.
$db->tables['templates'][1]['template'] = $previous;
if (!af_adaptivethemeframework_activate()) throw new RuntimeException('manual_override with previous content did not recover');
$lease = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if ($lease['ownership_state'] !== 'owned' || $lease['previous_content'] !== $previous || $db->tables['templates'][1]['template'] !== $seed) throw new RuntimeException('recovery replaced original backup');

// APA markers are attributable compatibility noise, not a user override. Only
// an exact marker-only difference may be recovered and the backup is immutable.
$lastpostSeed = file_get_contents($addon . '/templates/forumbit_depth2_forum_lastpost.html');
$lastpostTemplate =& $db->tables['templates'][5];
$lastpostLeaseIndex = array_search('forumbit_depth2_forum_lastpost', array_column($db->tables['af_adaptivethemeframework_template_ownership'], 'template_name'), true);
$lastpostLease =& $db->tables['af_adaptivethemeframework_template_ownership'][$lastpostLeaseIndex];
$lastpostBackup = $lastpostLease['previous_content'];
$lastpostTemplate['template'] = '<apa_uid_[{$lastpost_data[\'lastposteruid\']}]>' . $lastpostSeed . '<apa_end>';
if (!af_adaptivethemeframework_activate()
    || $lastpostTemplate['template'] !== $lastpostSeed
    || $lastpostLease['ownership_state'] !== 'owned'
    || $lastpostLease['previous_content'] !== $lastpostBackup) {
    throw new RuntimeException('APA-only template pollution was not safely recovered');
}

$pollutedWithEdit = '<apa_uid_[{$lastpost_data[\'lastposteruid\']}]>' . $lastpostSeed . '<!-- user edit --><apa_end>';
$lastpostTemplate['template'] = $pollutedWithEdit;
if (af_adaptivethemeframework_activate() !== false
    || $lastpostTemplate['template'] !== $pollutedWithEdit
    || $lastpostLease['ownership_state'] !== 'manual_override') {
    throw new RuntimeException('APA normalization concealed a real user edit');
}
$lastpostTemplate['template'] = $lastpostSeed;
if (!af_adaptivethemeframework_activate()) throw new RuntimeException('lastpost fixture did not recover after conflict assertion');

// An installed old seed is proof of ownership and may be upgraded in place.
$newSeedPath = tempnam(sys_get_temp_dir(), 'atf-seed-');
$newSeed = $seed . "\n<!-- regression seed upgrade -->";
file_put_contents($newSeedPath, $newSeed);
if (!af_adaptivethemeframework_acquire_template('index', $newSeedPath) || $db->tables['templates'][1]['template'] !== $newSeed) throw new RuntimeException('old installed seed was not upgraded');
$lease = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if ($lease['previous_content'] !== $previous || $lease['atf_installed_checksum'] !== hash('sha256', $newSeed)) throw new RuntimeException('seed upgrade damaged lease');

// If deployment put the new seed live first, reconcile it using the valid
// original backup rather than creating a backup chain.
$deployedSeed = $newSeed . "\n<!-- deployed ahead of lease -->";
file_put_contents($newSeedPath, $deployedSeed);
$db->tables['templates'][1]['template'] = $deployedSeed;
$db->tables['af_adaptivethemeframework_template_ownership'][0]['ownership_state'] = 'migration_review';
if (!af_adaptivethemeframework_acquire_template('index', $newSeedPath)) throw new RuntimeException('live current seed did not reconcile');
$lease = $db->tables['af_adaptivethemeframework_template_ownership'][0];
if ($lease['ownership_state'] !== 'owned' || $lease['previous_content'] !== $previous || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 7) throw new RuntimeException('current-seed reconciliation damaged backup');
unlink($newSeedPath);

$diagnostic = af_adaptivethemeframework_ownership_conflict('index', 1, 'manual_override', 'current-hash', 'previous-hash', 'installed-hash', 'seed-hash')->getMessage();
foreach (['template=index', 'sid=1', 'state=manual_override', 'current=current-hash', 'previous=previous-hash', 'installed=installed-hash', 'seed=seed-hash'] as $field) {
    if (!str_contains($diagnostic, $field)) throw new RuntimeException("ownership diagnostic omits {$field}");
}

// E: a set inheriting the master receives an escaped override, then release
// removes it and reacquisition reuses the same lease instead of chaining backups.
$db = new AtfDatabase();
array_splice($db->tables['templates'], 1, 1);
if (!af_adaptivethemeframework_activate()) throw new RuntimeException('index override acquisition failed');
$override = array_values(array_filter($db->tables['templates'], static fn($row) => (int)$row['sid'] === 1 && $row['title'] === 'index'));
$templateInsert = array_values(array_filter($db->writeLog, static fn($write) => $write[0] === 'insert' && $write[1] === 'templates'));
if (count($override) !== 1 || $override[0]['template'] !== $seed || count($templateInsert) !== 1) throw new RuntimeException('escaped index override was not inserted');
if (!af_adaptivethemeframework_deactivate() || count(array_filter($db->tables['templates'], static fn($row) => (int)$row['sid'] === 1 && $row['title'] === 'index')) !== 0) throw new RuntimeException('inherited index override was not released');
if (!af_adaptivethemeframework_activate() || count($db->tables['af_adaptivethemeframework_template_ownership']) !== 7) throw new RuntimeException('override reacquisition made a backup chain');

echo "ATF ownership fresh/upgrade/conflict lifecycle passed.\n";
