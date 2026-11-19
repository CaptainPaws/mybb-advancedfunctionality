<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/Repository.php';

final class AF_ACS_Migrations extends AF_ACS_Repository
{
    public const VERSION = '029_statistics';

    // Ordered SHA-256 of the LF-normalized source hashes for the reviewed 001–029 DDL-only files.
    private const REDEPLOY_BASELINE = '38feb6b08527d64c118eadf63c3e3616bde9d73f1b2986b7df926866499e90ad';
    private const REDEPLOY_VERSION = '029_statistics';
    private const REDEPLOY_OPERATION = 'migration_reconciliation';

    /** Explicit ordered registry; never execute a filename supplied by a client. */
    private function registry(?string &$version = null): array
    {
        $registry = [];
        foreach (['001_foundation','002_entities','003_encounters','004_round_roster','005_clock_resources','006_drafts','007_sealed_actions','008_publication_journal','009_combat_commit','010_status_instances','011_reaction_registry','012_barrier_pools','013_equipment','014_custody','015_delegations','016_environment_objects','017_awareness','018_cooperation','019_spatial','020_cover','021_prepared_actions','022_pre_round_commitments','023_encounter_resolutions','024_loot_pools','025_loot_claims','026_rewards','027_achievements_reviews','028_archive_policies','029_statistics'] as $version) {
            $file = __DIR__.'/../../migrations/'.$version.'.php';
            clearstatcache(true, $file);
            $source = @file_get_contents($file);
            if ($source === false) { throw new RuntimeException('ACS_MIGRATION_FILE_UNAVAILABLE'); }
            if (!preg_match('/\A<\?php\r?\n/', $source)) { throw new RuntimeException('ACS_MIGRATION_FILE_INVALID'); }
            try { $migration = require $file; }
            catch (Throwable $error) { throw new RuntimeException('ACS_MIGRATION_FILE_INVALID', 0, $error); }
            if (!is_array($migration) || ($migration['version'] ?? null) !== $version || !is_array($migration['tables'] ?? null) || !$migration['tables']) {
                throw new RuntimeException('ACS_MIGRATION_FILE_INVALID');
            }
            $migration['checksum'] = hash('sha256', $source);
            if ($migration['checksum'] !== @hash_file('sha256', $file)) {
                throw new RuntimeException('ACS_MIGRATION_FILE_CHANGED');
            }
            $lf = str_replace("\r\n", "\n", $source);
            $migration['lf'] = hash('sha256', $lf);
            $migration['crlf'] = hash('sha256', str_replace("\n", "\r\n", $lf));
            $registry[] = $migration;
        }
        return $registry;
    }

    public function environment(): array
    {
        $row = $this->sql('SELECT VERSION() AS version,@@innodb_page_size AS page_size,@@character_set_connection AS charset,@@session.sql_mode AS sql_mode')['rows'][0];
        $maria = $this->isMariaDb();
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

    private function isMariaDb(): bool
    {
        return stripos($this->connection->server_info, 'MariaDB') !== false;
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

    private function expectedSchema(string $name, string $ddl): array
    {
        $expected = [];
        $indexes = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $ddl));
        $header = array_shift($lines);
        $footer = array_pop($lines);
        if ($header !== 'CREATE TABLE `{prefix}af_acs_'.$name.'` (' ||
            $footer !== ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;') {
            throw new RuntimeException('ACS_SCHEMA_DDL_UNSUPPORTED');
        }
        foreach ($lines as $line) {
            $line = trim($line, " \t,");
            if (preg_match('/\A(PRIMARY KEY|UNIQUE KEY (\w+)|KEY (\w+)) \((\w+(?:,\w+)*)\)\z/D', $line, $match)) {
                $indexName = $match[1] === 'PRIMARY KEY' ? 'PRIMARY' : ($match[2] ?: $match[3]);
                $indexes[$indexName] = ['unique' => !str_starts_with($match[1], 'KEY '), 'columns' => explode(',', $match[4])];
            } elseif (preg_match('/\A(\w+) ((?:bigint|int|tinyint)(?: unsigned)?|double|(?:var)?char\(\d+\)|longtext) (NOT NULL|NULL)(?: (AUTO_INCREMENT|DEFAULT (\d+)))?\z/D', $line, $match)) {
                $expected[] = ['name' => $match[1], 'type' => $match[2], 'nullable' => $match[3] === 'NULL',
                    'default' => $match[5] ?? null, 'extra' => ($match[4] ?? '') === 'AUTO_INCREMENT' ? 'auto_increment' : '',
                    'collation' => preg_match('/char|text/', $match[2]) ? 'utf8mb4_unicode_ci' : null];
            } else {
                throw new RuntimeException('ACS_SCHEMA_DDL_UNSUPPORTED');
            }
        }
        return [$expected, $indexes];
    }

    /** Exact readback: never repair a mismatching table, column or index silently. */
    private function verify(string $name, string $ddl): void
    {
        $table = $this->prefix.'af_acs_'.$name;
        $info = $this->sql('SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])['rows'][0] ?? [];
        if (($info['ENGINE'] ?? '') !== 'InnoDB' || ($info['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci'
            || strtolower($info['ROW_FORMAT'] ?? '') !== 'dynamic' || ($info['TABLE_TYPE'] ?? '') !== 'BASE TABLE') { throw new RuntimeException('ACS_SCHEMA_TABLE_MISMATCH'); }
        $actual = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])['rows'];
        [$expected, $indexes] = $this->expectedSchema($name, $ddl);
        if (count($actual) !== count($expected)) { throw new RuntimeException('ACS_SCHEMA_COLUMNS_MISMATCH'); }
        foreach ($expected as $i => $column) {
            $found = $actual[$i];
            $default = $found['COLUMN_DEFAULT'];
            // MariaDB returns the SQL token NULL; MySQL returns SQL NULL.
            if ($default === 'NULL') { $default = null; }
            if ($found['COLUMN_NAME'] !== $column['name'] || self::type($found['COLUMN_TYPE']) !== $column['type']
                || ($found['IS_NULLABLE'] === 'YES') !== $column['nullable'] || ($default === null ? null : (string)$default) !== $column['default'] || $found['EXTRA'] !== $column['extra']
                || $found['COLLATION_NAME'] !== $column['collation']) { throw new RuntimeException('ACS_SCHEMA_COLUMN_MISMATCH'); }
        }
        $foundIndexes = [];
        $visibility = $this->isMariaDb() ? "IGNORED='NO'" : "IS_VISIBLE='YES'";
        foreach ($this->sql('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION,'.$visibility.' AS usable FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])['rows'] as $index) {
            if ($index['SUB_PART'] !== null || $index['INDEX_TYPE'] !== 'BTREE' || $index['COLLATION'] !== 'A' || (int)$index['usable'] !== 1) { throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH'); }
            $foundIndexes[$index['INDEX_NAME']]['unique'] = (string)$index['NON_UNIQUE'] === '0';
            $foundIndexes[$index['INDEX_NAME']]['columns'][] = $index['COLUMN_NAME'];
        }
        ksort($indexes); ksort($foundIndexes);
        if ($indexes !== $foundIndexes) { throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH'); }
        if ($this->sql("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE NOT IN ('PRIMARY KEY','UNIQUE')", [$table])['rows']) {
            throw new RuntimeException('ACS_SCHEMA_CONSTRAINT_MISMATCH');
        }
    }

    private function rows(): array
    {
        return $this->sql('SELECT * FROM '.$this->table('schema_migrations').' ORDER BY version')['rows'];
    }

    private function validateJournal(array $registry, array $rows, ?string &$version = null): void
    {
        $known = array_column($registry, null, 'version');
        foreach ($rows as $row) {
            $version = $row['version'];
            if (!isset($known[$version])) { throw new RuntimeException('ACS_UNKNOWN_MIGRATION'); }
            $steps = count($known[$version]['tables']);
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $row['checksum'])
                || !in_array($row['status'], ['running', 'failed', 'completed'], true)
                || (int)$row['step'] < 0 || (int)$row['step'] > $steps
                || (int)$row['updated_at'] <= 0
                || ($row['status'] === 'completed' && (int)$row['step'] !== $steps)
                || ($row['status'] === 'failed' ? !preg_match('/\A[a-f0-9]{32}\z/D', $row['error_id'] ?? '') : $row['error_id'] !== null)) {
                throw new RuntimeException('ACS_MIGRATION_JOURNAL_INVALID');
            }
        }
        $prefix = array_slice($registry, 0, count($rows));
        foreach ($prefix as $i => $definition) {
            $version = $definition['version'];
            if ($rows[$i]['version'] !== $version || ($i < count($rows)-1 && $rows[$i]['status'] !== 'completed')) {
                throw new RuntimeException('ACS_MIGRATION_JOURNAL_ORDER');
            }
        }
        $version = null;
    }

    private function checksumDifferences(array $known, array $rows): array
    {
        $differences = [];
        foreach ($rows as $version => $row) {
            if (!hash_equals($known[$version]['checksum'], $row['checksum'])) {
                $differences[$version] = ['recorded' => $row['checksum'], 'deployed' => $known[$version]['checksum']];
            }
        }
        return $differences;
    }

    /** A matching SHA of an alternate byte representation is evidence; matching DDL alone isn't. */
    private function reconciliationEvidence(array $known, array $rows, array $differences): ?array
    {
        $baseline = [];
        foreach ($known as $version => $definition) {
            if (($rows[$version]['status'] ?? '') !== 'completed') { return null; }
            $baseline[$version] = $definition['lf'];
            if ($version === self::REDEPLOY_VERSION) { break; }
        }
        if (!isset($baseline[self::REDEPLOY_VERSION]) ||
            !hash_equals(self::REDEPLOY_BASELINE, hash('sha256', implode('', $baseline)))) { return null; }
        foreach ($differences as $version => $hashes) {
            $definition = $known[$version];
            $variants = [$definition['lf'], $definition['crlf']];
            if (!isset($baseline[$version]) || !in_array($hashes['recorded'], $variants, true)
                || !in_array($hashes['deployed'], $variants, true)) { return null; }
        }
        return ['schema_version' => 1, 'code' => 'ACS_MIGRATION_REDEPLOY_CONFIRMED',
            'baseline' => self::REDEPLOY_BASELINE, 'differences' => $differences];
    }

    private function receiptId(array $evidence): string
    {
        return hash('sha256', self::json($evidence));
    }

    private function hasReceipt(array $evidence): bool
    {
        $id = $this->receiptId($evidence);
        $row = $this->sql('SELECT operation,phase,details_json FROM '.$this->table('diagnostics').' WHERE error_id=?', [$id])['rows'][0] ?? null;
        if ($row === null) { return false; }
        $stored = json_decode($row['details_json'], true);
        $actor = $stored['admin_uid'] ?? null;
        if (!is_array($stored) || !is_int($actor) || $actor <= 0 || $row['operation'] !== self::REDEPLOY_OPERATION || $row['phase'] !== 'confirmed') {
            throw new RuntimeException('ACS_MIGRATION_RECEIPT_INVALID');
        }
        unset($stored['admin_uid']);
        if ($stored !== $evidence) { throw new RuntimeException('ACS_MIGRATION_RECEIPT_INVALID'); }
        return true;
    }

    private function checkRecoveryScope(array $registry): void
    {
        $expected = [];
        $prefix = $this->prefix.'af_acs_';
        foreach ($registry as $migration) {
            foreach (array_keys($migration['tables']) as $name) { $expected[$prefix.$name] = true; }
        }
        foreach ($this->sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,?)=?', [strlen($prefix), $prefix])['rows'] as $row) {
            if (!isset($expected[$row['TABLE_NAME']])) { throw new RuntimeException('ACS_MIGRATION_UNKNOWN_OBJECT'); }
        }
        $this->checkRecoveryObligations();
    }

    private function checkRecoveryObligations(): void
    {
        if ($this->sql('SELECT 1 FROM '.$this->table('operations')." WHERE status='recovery_required'
            OR (external_system IN ('advancedinventory','balance') AND (status='prepared' OR BINARY payload_hash<>SHA2(request_json,256))) LIMIT 1")['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('submissions')." WHERE status NOT IN ('committed','rejected') LIMIT 1")['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('rewards')." WHERE status='recovery_required' OR BINARY payload_hash<>SHA2(payload_json,256) LIMIT 1")['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('prepared_actions')." WHERE cost_state='recovery_required' OR BINARY contract_hash<>SHA2(contract_json,256) LIMIT 1")['rows']
            || $this->sql('SELECT 1 FROM '.$this->table('custody')." WHERE status NOT IN ('held','returned') OR NOT JSON_VALID(payload_json) LIMIT 1")['rows']) {
            throw new RuntimeException('ACS_MIGRATION_OBLIGATIONS_UNRESOLVED');
        }
    }

    /** No persistent writes on ACP GET; scoped session settings are restored. */
    public function status(): array
    {
        return AF_ACS_TransactionManager::databaseSettings($this->connection, fn() => $this->inspectStatus());
    }

    private function inspectStatus(?array $registry = null, ?array &$evidence = null): array
    {
        $result = ['schema_version' => null, 'migrations' => [], 'error' => null, 'database' => null,
            'status' => 'RECOVERY_REQUIRED', 'structure' => 'NOT_CHECKED', 'version' => null,
            'object' => null, 'checksum_versions' => [], 'confirmation' => null, 'confirmed' => false];
        try {
            $result['database'] = $this->environment();
            $registry ??= $this->registry($result['version']);
            $result['version'] = null;
            if (!$this->exists('schema_migrations')) {
                $prefix = $this->prefix.'af_acs_';
                if ($this->sql('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,?)=? LIMIT 1', [strlen($prefix), $prefix])['rows']) {
                    throw new RuntimeException('ACS_MIGRATION_JOURNAL_MISSING');
                }
                $result['error'] = 'ACS_NOT_INSTALLED';
                return $result;
            }
            $known = array_column($registry, null, 'version');
            $result['object'] = 'schema_migrations';
            $result['version'] = $registry[0]['version'];
            $this->verify('schema_migrations', $registry[0]['tables']['schema_migrations']);
            $result['object'] = null;
            $result['migrations'] = $journal = $this->rows();
            $rows = array_column($journal, null, 'version');
            foreach ($known as $version => $migration) {
                if (($rows[$version]['status'] ?? '') !== 'completed') { break; }
                $result['schema_version'] = $version;
            }
            $this->validateJournal($registry, $journal, $result['version']);
            $differences = $this->checksumDifferences($known, $rows);
            $result['checksum_versions'] = array_keys($differences);
            $pending = null;
            foreach ($known as $version => $migration) {
                $row = $rows[$version] ?? null;
                if (($row['status'] ?? '') !== 'completed') { $pending ??= $version; }
                $result['version'] = $version;
                foreach (array_slice($migration['tables'], 0, (int)($row['step'] ?? 0), true) as $name => $ddl) {
                    $result['object'] = $name;
                    if ($name !== 'schema_migrations') { $this->verify($name, $ddl); }
                }
            }
            $result['object'] = null;
            $result['version'] = $pending;
            $result['structure'] = $pending === null ? 'VERIFIED' : 'PARTIAL';
            if ($differences) {
                $result['version'] = array_key_first($differences);
                $evidence = $this->reconciliationEvidence($known, $rows, $differences);
                if ($evidence === null) {
                    throw new RuntimeException($pending === null ? 'ACS_MIGRATION_CHECKSUM_UNVERIFIED' : 'ACS_MIGRATION_CHECKSUM_WITH_PENDING');
                }
                $result['confirmed'] = $this->hasReceipt($evidence);
                if (!$result['confirmed']) {
                    if ($pending !== null) { throw new RuntimeException('ACS_MIGRATION_CHECKSUM_WITH_PENDING'); }
                    $this->checkRecoveryScope($registry);
                    $result['confirmation'] = $this->receiptId($evidence);
                    throw new RuntimeException('ACS_MIGRATION_CHECKSUM_CONFIRMATION_REQUIRED');
                }
            }
            $result['version'] = $pending;
            if ($pending !== null) { throw new RuntimeException('ACS_MIGRATION_INCOMPLETE'); }
            $result['status'] = 'READY';
        } catch (Throwable $error) {
            $result['error'] = self::safeCode($error);
            if (str_starts_with($result['error'], 'ACS_SCHEMA_')) {
                $result['status'] = 'SCHEMA_DRIFT';
                $result['structure'] = 'FAILED';
            } else {
                $result['status'] = match ($result['error']) {
                    'ACS_UNKNOWN_MIGRATION' => 'UNKNOWN_MIGRATION',
                    'ACS_MIGRATION_INCOMPLETE', 'ACS_MIGRATION_CHECKSUM_WITH_PENDING' => 'MIGRATION_INCOMPLETE',
                    'ACS_MIGRATION_CHECKSUM_CONFIRMATION_REQUIRED', 'ACS_MIGRATION_CHECKSUM_UNVERIFIED' => 'FILE_CHECKSUM_MISMATCH',
                    default => 'RECOVERY_REQUIRED',
                };
            }
        }
        return $result;
    }

    public function confirmRedeploy(string $confirmation, int $adminUid): void
    {
        if ($adminUid <= 0 || !preg_match('/\A[a-f0-9]{64}\z/D', $confirmation)) {
            throw new RuntimeException('ACS_MIGRATION_CONFIRMATION_INVALID');
        }
        $this->transactions->run(function () use ($confirmation, $adminUid) {
            $state = $this->inspectStatus(null, $evidence);
            if ($state['confirmation'] === null && !($state['confirmed'] && $state['status'] === 'READY')) {
                throw new RuntimeException($state['error'] ?? 'ACS_MIGRATION_CONFIRMATION_STALE');
            }
            $id = $this->receiptId($evidence);
            if (!hash_equals($id, $confirmation)) { throw new RuntimeException('ACS_MIGRATION_CONFIRMATION_STALE'); }
            if ($state['confirmed']) { return; }
            $this->sql('INSERT INTO '.$this->table('diagnostics').' (error_id,request_id,category,operation,phase,created_at,details_json) VALUES (?,?,?,?,?,?,?)',
                [$id, 'redeploy:'.$id, 'MAINTENANCE', self::REDEPLOY_OPERATION, 'confirmed', time(),
                    self::json($evidence + ['admin_uid' => $adminUid])]);
            if (!$this->hasReceipt($evidence)) { throw new RuntimeException('ACS_MIGRATION_RECEIPT_INVALID'); }
        });
    }

    /** Preserve the per-write table property guard; activation and confirmation do full DDL readback. */
    public function assertWritableSchema(): void
    {
        $registry = $this->registry();
        $journal = $this->rows();
        $this->validateJournal($registry, $journal);
        if (count($journal) !== count($registry) || end($journal)['status'] !== 'completed') {
            throw new RuntimeException('ACS_SCHEMA_NOT_READY');
        }
        $known = array_column($registry, null, 'version');
        $rows = array_column($journal, null, 'version');
        $differences = $this->checksumDifferences($known, $rows);
        if ($differences) {
            $evidence = $this->reconciliationEvidence($known, $rows, $differences);
            if ($evidence === null || !$this->hasReceipt($evidence)) { throw new RuntimeException('ACS_SCHEMA_NOT_READY'); }
        }
        $names = [];
        foreach ($registry as $migration) {
            foreach (array_keys($migration['tables']) as $name) { $names[] = $this->prefix.'af_acs_'.$name; }
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $count = $this->sql('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.') AND ENGINE=? AND TABLE_COLLATION=? AND ROW_FORMAT=?', [...$names, 'InnoDB', 'utf8mb4_unicode_ci', 'Dynamic'])['rows'][0]['n'];
        if ((int)$count !== count($names)) { throw new RuntimeException('ACS_SCHEMA_NOT_TRANSACTIONAL'); }
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
            $registry = $this->registry();
            $state = $this->inspectStatus($registry);
            if ($state['status'] === 'READY') { return; }
            if (!in_array($state['error'], ['ACS_NOT_INSTALLED', 'ACS_MIGRATION_INCOMPLETE'], true)) {
                throw new RuntimeException($state['error']);
            }
            $this->probe();
            $first = $registry[0];
            // Preflight ALL existing objects before creating even the journal.
            foreach ($registry as $migration) {
                foreach ($migration['tables'] as $name => $ddl) {
                    if ($this->exists($name)) { $this->verify($name, $ddl); }
                    else { $this->expectedSchema($name, $ddl); }
                }
            }
            if (!$this->exists('schema_migrations')) {
                $this->sql(str_replace('{prefix}', $this->prefix, $first['tables']['schema_migrations']));
                $this->verify('schema_migrations', $first['tables']['schema_migrations']);
            }
            $rows = array_column($state['migrations'], null, 'version');
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
                    $readback = $this->rows();
                    $this->validateJournal($registry, $readback);
                    $recorded = end($readback);
                    if (!$recorded || $recorded['version'] !== $version || $recorded['status'] !== 'completed'
                        || !hash_equals($migration['checksum'], $recorded['checksum'])) { throw new RuntimeException('ACS_MIGRATION_READBACK_FAILED'); }
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
                $journal = $this->rows();
                $this->validateJournal([$migration], $journal);
                if ($this->checksumDifferences([$migration['version'] => $migration], array_column($journal, null, 'version'))) {
                    throw new RuntimeException('ACS_MIGRATION_CHECKSUM_UNVERIFIED');
                }
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
