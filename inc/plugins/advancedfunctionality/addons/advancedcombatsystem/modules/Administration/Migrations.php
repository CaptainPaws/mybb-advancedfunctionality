<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/Repository.php';

final class AF_ACS_Migrations extends AF_ACS_Repository
{
    public const VERSION = '029_statistics';

    /** Stable migration versions and step identities, in execution order. */
    private const STEPS = [
        '001_foundation' => ['schema_migrations', 'sessions', 'session_roles', 'snapshots', 'operations', 'events', 'event_details', 'diagnostics'],
        '002_entities' => ['control_groups', 'entities', 'entity_states', 'resources', 'controllers'],
        '003_encounters' => ['encounters', 'sides', 'side_relationships', 'participants'],
        '004_round_roster' => ['rounds', 'turns', 'entity_turns'],
        '005_clock_resources' => ['cooldowns'],
        '006_drafts' => ['drafts'],
        '007_sealed_actions' => ['sealed_actions', 'rolls'],
        '008_publication_journal' => ['submissions', 'publication_audit'],
        '009_combat_commit' => ['post_links', 'combat_results', 'combat_receipts', 'outbox'],
        '010_status_instances' => ['effects'],
        '011_reaction_registry' => ['reactions'],
        '012_barrier_pools' => ['barrier_pools', 'barrier_targets'],
        '013_equipment' => ['equipment'],
        '014_custody' => ['custody'],
        '015_delegations' => ['delegations'],
        '016_environment_objects' => ['objects'],
        '017_awareness' => ['awareness'],
        '018_cooperation' => ['objectives', 'contributions'],
        '019_spatial' => ['zones', 'zone_connections', 'positions', 'engagements'],
        '020_cover' => ['cover_assignments'],
        '021_prepared_actions' => ['prepared_actions', 'prepared_executions'],
        '022_pre_round_commitments' => ['pre_round_commitments'],
        '023_encounter_resolutions' => ['encounter_resolutions'],
        '024_loot_pools' => ['loot_pools', 'loot_entries'],
        '025_loot_claims' => ['loot_claims'],
        '026_rewards' => ['rewards'],
        '027_achievements_reviews' => ['achievement_progress', 'achievement_events', 'correction_reviews'],
        '028_archive_policies' => ['archive_policies'],
        '029_statistics' => ['statistics', 'statistics_consumers', 'statistics_receipts'],
    ];

    private function registry(?string &$version = null, ?string &$object = null): array
    {
        if (array_key_last(self::STEPS) !== self::VERSION) { throw new RuntimeException('ACS_MIGRATION_REGISTRY_VERSION_MISMATCH'); }
        $registry = [];
        foreach (self::STEPS as $version => $steps) {
            $source = @file_get_contents(__DIR__.'/../../migrations/'.$version.'.php');
            if ($source === false) { throw new RuntimeException('ACS_MIGRATION_FILE_UNAVAILABLE'); }
            $tables = $this->declarations($source, $version);
            if (array_keys($tables) !== $steps) { throw new RuntimeException('ACS_MIGRATION_STEP_CONTRACT_MISMATCH'); }
            $schema = [];
            foreach ($tables as $object => $ddl) {
                $schema[$object] = $this->expectedSchema($object, $ddl);
            }
            $object = null;
            $registry[] = ['version' => $version, 'tables' => $tables, 'schema' => $schema, 'checksum' => hash('sha256', $source)];
        }
        $version = null;
        return $registry;
    }

    /** Read the supported literal migration format without executing the PHP file. */
    private function declarations(string $source, string $version): array
    {
        // A UTF-8 BOM is packaging metadata. Other inline HTML and closing tags are rejected.
        if (str_starts_with($source, "\xef\xbb\xbf")) { $source = substr($source, 3); }
        try { $tokens = PhpToken::tokenize(ltrim($source, " \t\r\n"), TOKEN_PARSE); }
        catch (ParseError $error) { throw new RuntimeException('ACS_MIGRATION_FILE_INVALID', 0, $error); }
        $tokens = array_values(array_filter($tokens, static fn(PhpToken $token) => !$token->isIgnorable() || $token->is(T_OPEN_TAG)));
        $i = 0;
        $unsupported = static fn() => new RuntimeException('ACS_MIGRATION_DECLARATION_UNSUPPORTED');
        $expect = static function (int|string $token) use ($tokens, &$i, $unsupported): string {
            $found = $tokens[$i++] ?? null;
            if (!$found?->is($token)) { throw $unsupported(); }
            return $found->text;
        };
        $literal = static function () use ($expect, $unsupported): string {
            $text = $expect(T_CONSTANT_ENCAPSED_STRING);
            if (!preg_match('/\A([\'"])([A-Za-z0-9_ ]+)\1\z/D', $text, $match)) { throw $unsupported(); }
            return $match[2];
        };
        $expect(T_OPEN_TAG);
        // The conventional direct-access guard may precede the single returned array.
        if (($tokens[$i]->id ?? null) === T_IF) {
            foreach ([T_IF, '(', '!', 'defined', '('] as $token) { $expect($token); }
            if ($literal() !== 'IN_MYBB') { throw $unsupported(); }
            foreach ([')', ')', '{'] as $token) { $expect($token); }
            if (!in_array($tokens[$i]->text ?? null, ['die', 'exit'], true)) { throw $unsupported(); }
            ++$i;
            $expect('(');
            if ($literal() !== 'No direct access') { throw $unsupported(); }
            foreach ([')', ';', '}'] as $token) { $expect($token); }
        }
        $expect(T_RETURN);
        $expect('[');
        if ($literal() !== 'version') { throw $unsupported(); }
        $expect(T_DOUBLE_ARROW);
        if ($literal() !== $version) { throw new RuntimeException('ACS_MIGRATION_VERSION_MISMATCH'); }
        $expect(',');
        if ($literal() !== 'tables') { throw $unsupported(); }
        $expect(T_DOUBLE_ARROW);
        $expect('[');

        $tables = [];
        while (($tokens[$i]->text ?? null) !== ']') {
            $name = $literal();
            if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) || isset($tables[$name])) { throw $unsupported(); }
            $expect(T_DOUBLE_ARROW);
            if (!preg_match("/\A<<<[ \t]*'[A-Za-z_][A-Za-z0-9_]*'\r?\n\z/D", $expect(T_START_HEREDOC))) { throw $unsupported(); }
            $tables[$name] = rtrim($expect(T_ENCAPSED_AND_WHITESPACE), "\r\n");
            $expect(T_END_HEREDOC);
            if (($tokens[$i]->text ?? null) !== ']') { $expect(','); }
        }
        $expect(']');
        if (($tokens[$i]->text ?? null) === ',') { $expect(','); }
        $expect(']');
        $expect(';');
        if ($i !== count($tokens)) { throw $unsupported(); }
        return $tables;
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
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $ddl)) { throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED'); }
        $expected = [];
        $indexes = [];
        $lines = array_values(array_filter(array_map(
            static fn(string $line) => preg_replace('/[ \t]+/', ' ', trim($line)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $ddl))
        ), static fn(string $line) => $line !== ''));
        $header = array_shift($lines);
        $footer = array_pop($lines);
        if ($header !== 'CREATE TABLE `{prefix}af_acs_'.$name.'` (' ||
            $footer !== ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;') {
            throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED');
        }
        $last = count($lines)-1;
        foreach ($lines as $i => $line) {
            if (str_ends_with($line, ',') !== ($i < $last)) { throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED'); }
            $line = rtrim(substr($line, 0, $i < $last ? -1 : strlen($line)));
            if (preg_match('/\A(PRIMARY KEY|UNIQUE KEY (\w+)|KEY (\w+)) \((\w+(?: *, *\w+)*)\)\z/D', $line, $match)) {
                $indexName = $match[1] === 'PRIMARY KEY' ? 'PRIMARY' : ($match[2] ?: $match[3]);
                if (isset($indexes[$indexName])) { throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED'); }
                $indexes[$indexName] = ['unique' => !str_starts_with($match[1], 'KEY '), 'columns' => array_map('trim', explode(',', $match[4]))];
            } elseif (preg_match('/\A(\w+) ((?:bigint|int|tinyint)(?: unsigned)?|double|(?:var)?char\(\d+\)|longtext) (NOT NULL|NULL)(?: (AUTO_INCREMENT|DEFAULT (\d+)))?\z/D', $line, $match)
                && !isset($expected[$match[1]])) {
                $expected[$match[1]] = ['name' => $match[1], 'type' => $match[2], 'nullable' => $match[3] === 'NULL',
                    'default' => $match[5] ?? null, 'extra' => ($match[4] ?? '') === 'AUTO_INCREMENT' ? 'auto_increment' : '',
                    'collation' => preg_match('/char|text/', $match[2]) ? 'utf8mb4_unicode_ci' : null];
            } else {
                throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED');
            }
        }
        if (!$expected || !isset($indexes['PRIMARY'])) { throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED'); }
        foreach ($indexes as $index) {
            if (array_diff($index['columns'], array_keys($expected))) { throw new RuntimeException('ACS_MIGRATION_DDL_UNSUPPORTED'); }
        }
        ksort($indexes);
        return [array_values($expected), $indexes];
    }

    /** Batch metadata readback so lifecycle and gameplay can use the same strict check. */
    private function metadata(?string $name = null): array
    {
        $prefix = $this->prefix.'af_acs_';
        $scope = ' WHERE TABLE_SCHEMA=DATABASE() AND '.($name === null ? 'LEFT(TABLE_NAME,?)=?' : 'TABLE_NAME=?');
        $args = $name === null ? [strlen($prefix), $prefix] : [$prefix.$name];
        $visibility = $this->isMariaDb() ? "IGNORED='NO'" : "IS_VISIBLE='YES'";
        $queries = [
            'table' => ['TABLE_NAME,ENGINE,TABLE_COLLATION,ROW_FORMAT,TABLE_TYPE', 'TABLES', ''],
            'columns' => ['TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME', 'COLUMNS', ' ORDER BY TABLE_NAME,ORDINAL_POSITION'],
            'indexes' => ['TABLE_NAME,INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION,'.$visibility.' AS usable', 'STATISTICS', ' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX'],
            'constraints' => ['TABLE_NAME,CONSTRAINT_NAME', 'TABLE_CONSTRAINTS', " AND CONSTRAINT_TYPE NOT IN ('PRIMARY KEY','UNIQUE')"],
        ];
        $metadata = [];
        foreach ($queries as $kind => [$fields, $source, $suffix]) {
            foreach ($this->sql('SELECT '.$fields.' FROM information_schema.'.$source.$scope.$suffix, $args)['rows'] as $row) {
                $metadata[substr($row['TABLE_NAME'], strlen($prefix))][$kind][] = $row;
            }
        }
        return $metadata;
    }

    private function verify(string $name, array $schema, ?array $metadata = null): void
    {
        $metadata ??= $this->metadata($name);
        $actual = $metadata[$name] ?? [];
        $info = $actual['table'][0] ?? null;
        if ($info === null) { throw new RuntimeException('ACS_SCHEMA_TABLE_MISSING'); }
        if (($info['ENGINE'] ?? '') !== 'InnoDB' || ($info['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci'
            || strtolower($info['ROW_FORMAT'] ?? '') !== 'dynamic' || ($info['TABLE_TYPE'] ?? '') !== 'BASE TABLE') {
            throw new RuntimeException('ACS_SCHEMA_TABLE_MISMATCH');
        }
        [$columns, $indexes] = $schema;
        if (count($actual['columns'] ?? []) !== count($columns)) { throw new RuntimeException('ACS_SCHEMA_COLUMNS_MISMATCH'); }
        foreach ($columns as $i => $column) {
            $found = $actual['columns'][$i];
            $default = $found['COLUMN_DEFAULT'];
            if ($default === 'NULL') { $default = null; }
            if ($found['COLUMN_NAME'] !== $column['name'] || self::type($found['COLUMN_TYPE']) !== $column['type']
                || ($found['IS_NULLABLE'] === 'YES') !== $column['nullable'] || ($default === null ? null : (string)$default) !== $column['default']
                || $found['EXTRA'] !== $column['extra'] || $found['COLLATION_NAME'] !== $column['collation']) {
                throw new RuntimeException('ACS_SCHEMA_COLUMN_MISMATCH');
            }
        }
        $foundIndexes = [];
        foreach ($actual['indexes'] ?? [] as $index) {
            if ($index['SUB_PART'] !== null || $index['INDEX_TYPE'] !== 'BTREE' || $index['COLLATION'] !== 'A' || (int)$index['usable'] !== 1) {
                throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH');
            }
            $foundIndexes[$index['INDEX_NAME']]['unique'] = (string)$index['NON_UNIQUE'] === '0';
            $foundIndexes[$index['INDEX_NAME']]['columns'][] = $index['COLUMN_NAME'];
        }
        ksort($foundIndexes);
        if ($indexes !== $foundIndexes) { throw new RuntimeException('ACS_SCHEMA_INDEX_MISMATCH'); }
        if (!empty($actual['constraints'])) { throw new RuntimeException('ACS_SCHEMA_CONSTRAINT_MISMATCH'); }
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

    /** Read-only for ACP, installed checks and gameplay. */
    public function status(): array
    {
        return AF_ACS_TransactionManager::databaseSettings($this->connection, fn() => $this->inspectStatus());
    }

    private function inspectStatus(?array $registry = null): array
    {
        $result = ['schema_version' => null, 'migrations' => [], 'error' => null, 'database' => null,
            'status' => 'RECOVERY_REQUIRED', 'structure' => 'NOT_CHECKED', 'version' => null,
            'object' => null, 'checksum_differences' => []];
        try {
            $result['database'] = $this->environment();
            $registry ??= $this->registry($result['version'], $result['object']);
            $metadata = $this->metadata();
            if (!isset($metadata['schema_migrations']['table'])) {
                if ($metadata) {
                    $result['version'] = $registry[0]['version'];
                    $result['object'] = 'schema_migrations';
                    throw new RuntimeException('ACS_MIGRATION_JOURNAL_MISSING');
                }
                $result['error'] = 'ACS_NOT_INSTALLED';
                return $result;
            }
            $result['object'] = 'schema_migrations';
            $result['version'] = $registry[0]['version'];
            $this->verify('schema_migrations', $registry[0]['schema']['schema_migrations'], $metadata);
            $result['object'] = null;
            $result['migrations'] = $journal = $this->rows();
            $known = array_column($registry, null, 'version');
            $rows = array_column($journal, null, 'version');
            foreach (array_keys($known) as $version) {
                if (($rows[$version]['status'] ?? '') !== 'completed') { break; }
                $result['schema_version'] = $version;
            }
            $this->validateJournal($registry, $journal, $result['version']);
            $result['checksum_differences'] = $this->checksumDifferences($known, $rows);
            $pending = null;
            foreach ($known as $version => $migration) {
                $row = $rows[$version] ?? null;
                if (($row['status'] ?? '') !== 'completed') { $pending ??= $version; }
                $result['version'] = $version;
                $step = 0;
                foreach ($migration['schema'] as $name => $schema) {
                    $result['object'] = $name;
                    if ($name !== 'schema_migrations' && isset($metadata[$name]) && $row === null) {
                        throw new RuntimeException('ACS_MIGRATION_OBJECT_UNJOURNALED');
                    }
                    if ($name !== 'schema_migrations' && (isset($metadata[$name]) || $step < (int)($row['step'] ?? 0))) {
                        $this->verify($name, $schema, $metadata);
                    }
                    ++$step;
                    unset($metadata[$name]);
                }
            }
            $result['version'] = $pending;
            $result['object'] = null;
            if ($metadata) {
                $result['object'] = array_key_first($metadata);
                throw new RuntimeException('ACS_SCHEMA_UNKNOWN_OBJECT');
            }
            $result['structure'] = $pending === null ? 'VERIFIED' : 'PARTIAL';
            // Only a recorded, unfinished migration still depends on the exact source used to begin it.
            if ($pending !== null) {
                if (isset($result['checksum_differences'][$pending])) {
                    throw new RuntimeException('ACS_MIGRATION_PENDING_CHECKSUM_MISMATCH');
                }
                throw new RuntimeException('ACS_MIGRATION_INCOMPLETE');
            }
            $result['status'] = 'READY';
        } catch (Throwable $error) {
            $result['error'] = self::safeCode($error);
            if (str_starts_with($result['error'], 'ACS_SCHEMA_')) {
                $result['status'] = 'SCHEMA_DRIFT';
                $result['structure'] = 'FAILED';
            } else {
                $result['status'] = match ($result['error']) {
                    'ACS_UNKNOWN_MIGRATION' => 'UNKNOWN_MIGRATION',
                    'ACS_MIGRATION_INCOMPLETE', 'ACS_MIGRATION_PENDING_CHECKSUM_MISMATCH' => 'MIGRATION_INCOMPLETE',
                    default => 'RECOVERY_REQUIRED',
                };
            }
        }
        return $result;
    }

    /** Called only by ordinary lifecycle operations under the maintenance lock. */
    private function recordChecksumDifferences(array $differences): void
    {
        if (!$differences) { return; }
        $details = self::json(['schema_version' => 1, 'code' => 'ACS_MIGRATION_CHECKSUM_DRIFT', 'differences' => $differences]);
        $id = hash('sha256', $details);
        $table = $this->table('diagnostics');
        if ($this->sql('SELECT 1 FROM '.$table.' WHERE error_id=?', [$id])['rows']) { return; }
        $this->sql('INSERT INTO '.$table.' (error_id,request_id,category,operation,phase,created_at,details_json) VALUES (?,?,?,?,?,?,?)',
            [$id, 'redeploy:'.$id, 'MAINTENANCE', 'migration_redeploy', 'schema_verified', time(), $details]);
    }

    public function assertWritableSchema(): void
    {
        $this->assertInstalled();
    }

    private static function safeCode(Throwable $error): string
    {
        return preg_match('/\AACS_[A-Z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
    }

    public function assertInstalled(): void
    {
        $this->requireReady($this->status());
    }

    private function requireReady(array $status): void
    {
        if ($status['status'] !== 'READY') { throw new RuntimeException($status['error'] ?? 'ACS_SCHEMA_NOT_READY'); }
    }

    public function activate(): void
    {
        $this->transactions->exclusive(function () {
            $state = $this->status();
            $this->requireReady($state);
            $this->recordChecksumDifferences($state['checksum_differences']);
        });
    }

    public function run(): void
    {
        $this->transactions->exclusive(function () {
            $registry = $this->registry();
            $state = $this->inspectStatus($registry);
            if ($state['status'] === 'READY') {
                $this->recordChecksumDifferences($state['checksum_differences']);
                return;
            }
            if (!in_array($state['error'], ['ACS_NOT_INSTALLED', 'ACS_MIGRATION_INCOMPLETE'], true)) {
                throw new RuntimeException($state['error']);
            }
            if ($state['error'] === 'ACS_NOT_INSTALLED') { $this->probe(); }
            $first = $registry[0];
            if (!$this->exists('schema_migrations')) {
                $this->sql(str_replace('{prefix}', $this->prefix, $first['tables']['schema_migrations']));
                $this->verify('schema_migrations', $first['schema']['schema_migrations']);
            }
            $rows = array_column($state['migrations'], null, 'version');
            foreach ($registry as $migration) {
                $version = $migration['version'];
                $old = $rows[$version] ?? null;
                if ($old && $old['status'] === 'completed') {
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
                        $this->verify($name, $migration['schema'][$name]);
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
                        $this->verify('diagnostics', $first['schema']['diagnostics']);
                        $this->sql('INSERT INTO '.$this->table('diagnostics').' (error_id,request_id,category,operation,phase,created_at,details_json) VALUES (?,?,?,?,?,?,?)', [$errorId, 'migration:'.$errorId, 'DEPENDENCY_ERROR', 'migration', 'ddl_readback', time(), self::json(['schema_version' => 1, 'code' => self::safeCode($error)])]);
                    }
                    // Keep all created objects/data. Never pretend DDL rolled back.
                    throw new RuntimeException(self::safeCode($error).' [error_id='.$errorId.']');
                }
            }
            $state = $this->inspectStatus($registry);
            $this->requireReady($state);
            $this->recordChecksumDifferences($state['checksum_differences']);
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
                $this->verify('schema_migrations', $migration['schema']['schema_migrations']);
                $journal = $this->rows();
                $this->validateJournal([$migration], $journal);
                if ($this->checksumDifferences([$migration['version'] => $migration], array_column($journal, null, 'version'))) {
                    throw new RuntimeException('ACS_UNINSTALL_CHECKSUM_MISMATCH');
                }
            }
            // Conservative: even completed operations/history require a later
            // retention/obligations protocol, not an automatic destructive purge.
            foreach ($migration['schema'] as $name => $schema) {
                if (!$this->exists($name)) { continue; }
                $this->verify($name, $schema);
                if ($name !== 'schema_migrations' && $this->sql('SELECT 1 FROM '.$this->table($name).' LIMIT 1')['rows']) { throw new RuntimeException('ACS_UNINSTALL_DATA_RETAINED'); }
            }
            foreach (array_reverse($known) as $name) { $this->sql('DROP TABLE IF EXISTS '.$this->table($name)); if ($this->exists($name)) { throw new RuntimeException('ACS_UNINSTALL_READBACK_FAILED'); } }
        });
    }
}
