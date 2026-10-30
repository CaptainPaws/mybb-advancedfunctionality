<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/Repository.php';

final class AF_ACS_Migrations extends AF_ACS_Repository
{
    public const VERSION = '003_encounters';

    /** Explicit ordered registry; never execute a filename supplied by a client. */
    private function registry(): array
    {
        $registry = [];
        foreach (['001_foundation','002_entities','003_encounters'] as $version) {
            $file = __DIR__.'/../../migrations/'.$version.'.php';
            $migration = require $file;
            $migration['checksum'] = hash_file('sha256', $file);
            $registry[] = $migration;
        }
        return $registry;
    }

    public function environment(): array
    {
        $row = $this->sql('SELECT VERSION() AS version,@@innodb_page_size AS page_size,@@character_set_connection AS charset,@@session.sql_mode AS sql_mode')['rows'][0];
        $maria = stripos($row['version'], 'MariaDB') !== false;
        preg_match('/(\d+\.\d+\.\d+)/', $row['version'], $parts);
        if (!isset($parts[1]) || version_compare($parts[1], $maria ? '10.6.0' : '8.0.0', '<')) { throw new RuntimeException('ACS_DB_VERSION_UNSUPPORTED'); }
        if ((int)$row['page_size'] < 16384) { throw new RuntimeException('ACS_DB_PAGE_SIZE_UNSUPPORTED'); }
        if ($row['charset'] !== 'utf8mb4') { throw new RuntimeException('ACS_DB_UTF8MB4_UNAVAILABLE'); }
        if (!preg_match('/(?:^|,)STRICT_(?:TRANS|ALL)_TABLES(?:,|$)/', $row['sql_mode'])) { throw new RuntimeException('ACS_DB_STRICT_MODE_UNAVAILABLE'); }
        $engines = $this->sql('SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE=?', ['InnoDB'])['rows'];
        $collation = $this->sql('SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME=?', ['utf8mb4_unicode_ci'])['rows'];
        if (!$engines || !in_array($engines[0]['SUPPORT'], ['YES', 'DEFAULT'], true)) { throw new RuntimeException('ACS_DB_INNODB_UNAVAILABLE'); }
        if (!$collation) { throw new RuntimeException('ACS_DB_COLLATION_UNAVAILABLE'); }
        return ['server' => $row['version'], 'engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
    }

    private function probe(): void
    {
        // Verify full 3072-byte keys and ROW_FORMAT on this actual connection.
        $name = '`acs_probe_'.substr(hash('sha256', $this->prefix), 0, 20).'`';
        try {
            $this->sql('CREATE TEMPORARY TABLE '.$name.' (probe varchar(768) NOT NULL, UNIQUE KEY probe_key(probe)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->sql('INSERT INTO '.$name.' VALUES (?)', [str_repeat('x', 768)]);
            if (strlen($this->sql('SELECT probe FROM '.$name)['rows'][0]['probe'] ?? '') !== 768) { throw new RuntimeException('ACS_DB_KEY_LIMIT'); }
            $indexes = $this->sql('SHOW INDEX FROM '.$name)['rows'];
            if (count($indexes) !== 1 || $indexes[0]['Sub_part'] !== null) { throw new RuntimeException('ACS_DB_KEY_LIMIT'); }
        } finally { $this->sql('DROP TEMPORARY TABLE IF EXISTS '.$name); }
    }

    private function exists(string $name): bool
    {
        return (bool)$this->sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->prefix.'af_acs_'.$name])['rows'];
    }

    private static function type(string $type): string
    {
        return preg_replace('/\b(bigint|int|tinyint)\(\d+\)/', '$1', strtolower($type));
    }

    /** Exact readback: never repair a mismatching table, column or index silently. */
    private function verify(string $name, string $ddl): void
    {
        $table = $this->prefix.'af_acs_'.$name;
        $info = $this->sql('SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])['rows'][0] ?? [];
        if (($info['ENGINE'] ?? '') !== 'InnoDB' || ($info['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci'
            || strtolower($info['ROW_FORMAT'] ?? '') !== 'dynamic' || ($info['TABLE_TYPE'] ?? '') !== 'BASE TABLE') { throw new RuntimeException('ACS_SCHEMA_TABLE_MISMATCH'); }
        $actual = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])['rows'];
        $expected = [];
        $indexes = [];
        foreach (explode("\n", $ddl) as $line) {
            $line = trim($line, " \t\r,");
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY (\w+)|KEY (\w+)) \(([^)]+)\)$/', $line, $match)) {
                $indexName = $match[1] === 'PRIMARY KEY' ? 'PRIMARY' : ($match[2] ?: $match[3]);
                $indexes[$indexName] = ['unique' => !str_starts_with($match[1], 'KEY '), 'columns' => explode(',', $match[4])];
            } elseif (preg_match('/^(\w+) ((?:bigint|int|tinyint)(?: unsigned)?|double|(?:var)?char\(\d+\)|longtext) (.+)$/', $line, $match)) {
                preg_match('/DEFAULT (\d+)/', $match[3], $default);
                $expected[] = ['name' => $match[1], 'type' => $match[2], 'nullable' => !str_contains($match[3], 'NOT NULL'), 'default' => $default[1] ?? null, 'extra' => str_contains($match[3], 'AUTO_INCREMENT') ? 'auto_increment' : ''];
            }
        }
        if (count($actual) !== count($expected)) { throw new RuntimeException('ACS_SCHEMA_COLUMNS_MISMATCH'); }
        foreach ($expected as $i => $column) {
            $found = $actual[$i];
            $default = $found['COLUMN_DEFAULT'];
            // MariaDB returns the SQL token NULL; MySQL returns SQL NULL.
            if ($default === 'NULL') { $default = null; }
            if ($found['COLUMN_NAME'] !== $column['name'] || self::type($found['COLUMN_TYPE']) !== $column['type']
                || ($found['IS_NULLABLE'] === 'YES') !== $column['nullable'] || $default !== $column['default'] || $found['EXTRA'] !== $column['extra']
                || ($found['COLLATION_NAME'] !== null && $found['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('ACS_SCHEMA_COLUMN_MISMATCH'); }
        }
        $foundIndexes = [];
        foreach ($this->sql('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])['rows'] as $index) {
            if ($index['SUB_PART'] !== null || $index['INDEX_TYPE'] !== 'BTREE') { throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH'); }
            $foundIndexes[$index['INDEX_NAME']]['unique'] = (string)$index['NON_UNIQUE'] === '0';
            $foundIndexes[$index['INDEX_NAME']]['columns'][] = $index['COLUMN_NAME'];
        }
        ksort($indexes); ksort($foundIndexes);
        if ($indexes !== $foundIndexes) { throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH'); }
        if ($this->sql('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$table])['rows']) { throw new RuntimeException('ACS_UNEXPECTED_FOREIGN_KEY'); }
    }

    private function rows(): array
    {
        return $this->sql('SELECT * FROM '.$this->table('schema_migrations').' ORDER BY version')['rows'];
    }

    private function validateJournal(array $registry): array
    {
        $rows = $this->rows();
        $known = array_column($registry, null, 'version');
        foreach ($rows as $row) {
            $definition = $known[$row['version']] ?? null;
            if (!$definition || !hash_equals($definition['checksum'], $row['checksum'])
                || !in_array($row['status'], ['running', 'failed', 'completed'], true)
                || (int)$row['step'] < 0 || (int)$row['step'] > count($definition['tables'])
                || ($row['status'] === 'completed' && (int)$row['step'] !== count($definition['tables']))) { throw new RuntimeException('ACS_MIGRATION_JOURNAL_MISMATCH'); }
        }
        return array_column($rows, null, 'version');
    }

    /** No persistent writes on ACP GET; scoped session settings are restored. */
    public function status(): array
    {
        return AF_ACS_TransactionManager::databaseSettings($this->connection, fn() => $this->inspectStatus());
    }

    private function inspectStatus(): array
    {
        $result = ['schema_version' => null, 'migrations' => [], 'error' => null, 'database' => null];
        try {
            $result['database'] = $this->environment();
            $registry = $this->registry();
            $first = $registry[0];
            if (!$this->exists('schema_migrations')) {
                $result['error'] = 'ACS_NOT_INSTALLED';
                return $result;
            }
            $this->verify('schema_migrations', $first['tables']['schema_migrations']);
            $rows = $this->validateJournal($registry);
            $result['migrations'] = array_values($rows);
            foreach ($registry as $migration) {
                $row = $rows[$migration['version']] ?? null;
                if (!$row || $row['status'] !== 'completed') { $result['error'] = 'ACS_MIGRATION_INCOMPLETE'; continue; }
                foreach ($migration['tables'] as $name => $ddl) { $this->verify($name, $ddl); }
                $result['schema_version'] = $migration['version'];
            }
        } catch (Throwable $error) { $result['error'] = self::safeCode($error); }
        return $result;
    }

    private static function safeCode(Throwable $error): string
    {
        return preg_match('/\AACS_[A-Z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
    }

    public function assertInstalled(): void
    {
        $status = $this->status();
        if ($status['error'] !== null || $status['schema_version'] !== self::VERSION) { throw new RuntimeException($status['error'] ?? 'ACS_SCHEMA_NOT_READY'); }
    }

    public function run(): void
    {
        $this->transactions->exclusive(function () {
            $this->environment();
            $this->probe();
            $registry = $this->registry();
            $first = $registry[0];
            // Preflight ALL existing objects before creating even the journal.
            foreach ($registry as $migration) {
                foreach ($migration['tables'] as $name => $ddl) { if ($this->exists($name)) { $this->verify($name, $ddl); } }
            }
            if (!$this->exists('schema_migrations')) {
                $this->sql(str_replace('{prefix}', $this->prefix, $first['tables']['schema_migrations']));
                $this->verify('schema_migrations', $first['tables']['schema_migrations']);
            }
            $rows = $this->validateJournal($registry);
            foreach ($registry as $migration) {
                $version = $migration['version'];
                $old = $rows[$version] ?? null;
                if ($old && $old['status'] === 'completed') {
                    // A deleted object in a completed migration is drift, not a resume.
                    foreach ($migration['tables'] as $name => $ddl) { $this->verify($name, $ddl); }
                    continue;
                }
                $step = 0;
                try {
                    if (!$old) {
                        $this->sql('INSERT INTO '.$this->table('schema_migrations').' (version,status,step,checksum,updated_at) VALUES (?,?,?,?,?)', [$version, 'running', '0', $migration['checksum'], time()]);
                    } else {
                        $this->sql('UPDATE '.$this->table('schema_migrations').' SET status=?,error_id=NULL,updated_at=? WHERE version=?', ['running', time(), $version]);
                    }
                    foreach ($migration['tables'] as $name => $ddl) {
                        if (!$this->exists($name)) {
                            // A recorded completed step must never lose its table.
                            if ($old && $step < (int)$old['step']) { throw new RuntimeException('ACS_MIGRATION_OBJECT_LOST'); }
                            $this->sql(str_replace('{prefix}', $this->prefix, $ddl));
                        }
                        $this->verify($name, $ddl);
                        ++$step;
                        $this->sql('UPDATE '.$this->table('schema_migrations').' SET step=?,updated_at=? WHERE version=?', [$step, time(), $version]);
                    }
                    $this->sql('UPDATE '.$this->table('schema_migrations').' SET status=?,error_id=NULL,updated_at=? WHERE version=?', ['completed', time(), $version]);
                    $readback = $this->validateJournal($registry);
                    if (($readback[$version]['status'] ?? '') !== 'completed') { throw new RuntimeException('ACS_MIGRATION_READBACK_FAILED'); }
                } catch (Throwable $error) {
                    $errorId = bin2hex(random_bytes(16));
                    $this->sql('UPDATE '.$this->table('schema_migrations').' SET status=?,error_id=?,updated_at=? WHERE version=?', ['failed', $errorId, time(), $version]);
                    if ($this->exists('diagnostics')) {
                        $this->verify('diagnostics', $first['tables']['diagnostics']);
                        $this->sql('INSERT INTO '.$this->table('diagnostics').' (error_id,request_id,category,operation,phase,created_at,details_json) VALUES (?,?,?,?,?,?,?)', [$errorId, 'migration:'.$errorId, 'DEPENDENCY_ERROR', 'migration', 'ddl_readback', time(), self::json(['schema_version' => 1, 'code' => self::safeCode($error)])]);
                    }
                    // Keep all created objects/data. Never pretend DDL rolled back.
                    throw new RuntimeException(self::safeCode($error).' [error_id='.$errorId.']');
                }
            }
        });
    }

    /** Purge only an empty foundation; future schemas/any history block deletion. */
    public function uninstall(string $confirmation): void
    {
        if ($confirmation !== 'DELETE EMPTY ACS FOUNDATION' || af_is_addon_enabled('advancedcombatsystem')) { throw new RuntimeException('ACS_UNINSTALL_CONFIRMATION_REQUIRED'); }
        $this->transactions->exclusive(function () {
            $this->environment();
            $migration = $this->registry()[0];
            $known = array_keys($migration['tables']);
            $all = $this->sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')['rows'];
            foreach ($all as $table) {
                $prefix = $this->prefix.'af_acs_';
                if (str_starts_with($table['TABLE_NAME'], $prefix) && !in_array(substr($table['TABLE_NAME'], strlen($prefix)), $known, true)) { throw new RuntimeException('ACS_UNINSTALL_UNKNOWN_OBLIGATIONS'); }
            }
            if ($this->exists('schema_migrations')) {
                $this->verify('schema_migrations', $migration['tables']['schema_migrations']);
                $this->validateJournal([$migration]);
            }
            // Conservative: even completed operations/history require a later
            // retention/obligations protocol, not an automatic destructive purge.
            foreach ($migration['tables'] as $name => $ddl) {
                if (!$this->exists($name)) { continue; }
                $this->verify($name, $ddl);
                if ($name !== 'schema_migrations' && $this->sql('SELECT 1 FROM '.$this->table($name).' LIMIT 1')['rows']) { throw new RuntimeException('ACS_UNINSTALL_DATA_RETAINED'); }
            }
            foreach (array_reverse($known) as $name) { $this->sql('DROP TABLE IF EXISTS '.$this->table($name)); if ($this->exists($name)) { throw new RuntimeException('ACS_UNINSTALL_READBACK_FAILED'); } }
        });
    }
}
