<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/TransactionManager.php';

/** Internal persistence API. No endpoint; authorization belongs to callers. */
class AF_ACS_Repository
{
    protected mysqli $connection;
    protected AF_ACS_TransactionManager $transactions;
    protected string $prefix;

    public function __construct(object $database)
    {
        $link = $database instanceof mysqli ? $database : ($database->write_link ?? null);
        if (!$link instanceof mysqli || PHP_INT_SIZE !== 8 || !function_exists('mysqli_stmt_get_result')) {
            throw new RuntimeException('ACS_MYSQLI_64BIT_REQUIRED');
        }
        $this->connection = $link;
        $this->prefix = (string)TABLE_PREFIX;
        if (!preg_match('/\A[A-Za-z0-9_]*\z/D', $this->prefix) || strlen($this->prefix.'af_acs_schema_migrations') > 64) {
            throw new RuntimeException('ACS_INVALID_TABLE_PREFIX');
        }
        $databaseName = $this->sql('SELECT DATABASE() AS db')['rows'][0]['db'];
        $this->transactions = new AF_ACS_TransactionManager($link, 'acs:'.substr(hash('sha256', $databaseName.':'.$this->prefix), 0, 56));
    }

    protected function table(string $name): string
    {
        if (!in_array($name, ['schema_migrations', 'sessions', 'session_roles', 'snapshots', 'operations', 'events', 'event_details', 'diagnostics', 'control_groups', 'entities', 'entity_states', 'resources', 'controllers'], true)) {
            throw new RuntimeException('ACS_UNKNOWN_TABLE');
        }
        return '`'.$this->prefix.'af_acs_'.$name.'`';
    }

    /** SQL templates are developer-owned; every data value is bound as text. */
    protected function sql(string $sql, array $parameters = []): array
    {
        return AF_ACS_TransactionManager::databaseSettings($this->connection, fn() => $this->statement($sql, $parameters));
    }

    private function statement(string $sql, array $parameters): array
    {
        $statement = null;
        try {
            $statement = $this->connection->prepare($sql);
            if (!$statement) { throw new RuntimeException('ACS_DATABASE_ERROR'); }
            if ($parameters) {
                $parameters = array_map(static fn($v) => $v === null ? null : (string)$v, $parameters);
                $statement->bind_param(str_repeat('s', count($parameters)), ...$parameters);
            }
            if (!$statement->execute()) { throw new RuntimeException('ACS_DATABASE_ERROR'); }
            $result = $statement->get_result();
            return ['rows' => $result ? $result->fetch_all(MYSQLI_ASSOC) : [], 'affected' => $statement->affected_rows, 'id' => (string)$statement->insert_id];
        } catch (mysqli_sql_exception $error) {
            // No query, connection information or data in public exceptions.
            throw new RuntimeException('ACS_DATABASE_ERROR', (int)$error->getCode());
        } finally {
            if ($statement) { $statement->close(); }
        }
    }

    protected function writable(): void
    {
        if (!af_is_addon_enabled('advancedcombatsystem') || !empty($GLOBALS['af_acs_deactivated'])) {
            throw new RuntimeException('ACS_DISABLED');
        }
        $rows = $this->sql('SELECT version,status,step,checksum FROM '.$this->table('schema_migrations'))['rows'];
        $known = [];
        $names = [];
        foreach (['001_foundation','002_entities'] as $version) {
            $file = __DIR__.'/../../migrations/'.$version.'.php';
            $migration = require $file;
            $known[$version] = ['checksum'=>hash_file('sha256',$file),'steps'=>count($migration['tables'])];
            foreach (array_keys($migration['tables']) as $name) $names[]=$this->prefix.'af_acs_'.$name;
        }
        if (count($rows)!==count($known)) throw new RuntimeException('ACS_SCHEMA_NOT_READY');
        foreach ($rows as $row) {
            $expected=$known[$row['version']]??null;
            if (!$expected || $row['status']!=='completed' || (int)$row['step']!==$expected['steps']
                || !hash_equals($expected['checksum'],$row['checksum'])) throw new RuntimeException('ACS_SCHEMA_NOT_READY');
        }
        $placeholders=implode(',',array_fill(0,count($names),'?'));
        $count=$this->sql('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.') AND ENGINE=? AND TABLE_COLLATION=? AND ROW_FORMAT=?',[...$names,'InnoDB','utf8mb4_unicode_ci','Dynamic'])['rows'][0]['n'];
        if ((int)$count!==count($names)) throw new RuntimeException('ACS_SCHEMA_NOT_TRANSACTIONAL');
    }

    /** Exact decimal IDs/versions; callers never pass floats or overflowing ints. */
    public static function integer(mixed $value, string $maximum = '18446744073709551615', bool $zero = false): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string)$value)) {
            throw new InvalidArgumentException('ACS_INVALID_INTEGER');
        }
        $text = (string)$value;
        if ((!$zero && $text === '0') || strlen($text) > strlen($maximum) || (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
            throw new InvalidArgumentException('ACS_INTEGER_RANGE');
        }
        return $text;
    }

    protected static function text(string $value, int $limit): string
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit || str_contains($value, "\0")) {
            throw new InvalidArgumentException('ACS_INVALID_TEXT');
        }
        return $value;
    }

    protected static function key(string $value, int $limit = 191): string
    {
        // Binary-safe identity despite the schema's case-insensitive collation.
        if (!preg_match('/\A[a-z0-9][a-z0-9:_.-]*\z/D', $value) || strlen($value) > $limit) {
            throw new InvalidArgumentException('ACS_INVALID_KEY');
        }
        return $value;
    }

    public static function json(array $payload): string
    {
        self::integer($payload['schema_version'] ?? null, '2147483647');
        $visit = static function ($v, int $depth = 0) use (&$visit): void {
            if ($depth > 32 || is_object($v) || is_resource($v) || (is_float($v) && !is_finite($v))
                || (is_int($v) && ($v > 9007199254740991 || $v < -9007199254740991))) {
                throw new InvalidArgumentException('ACS_INVALID_JSON');
            }
            if (is_array($v)) { foreach ($v as $child) { $visit($child, $depth + 1); } }
        };
        $visit($payload);
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        } finally { ini_set('serialize_precision', (string)$precision); }
        if (strlen($json) > 262144) { throw new InvalidArgumentException('ACS_JSON_TOO_LARGE'); }
        return $json;
    }


    /** Caller must own the session transaction; insert-only, no historical mutator. */
    protected function insertSnapshot(string $sessionId, array $source, array $payload): string
    {
        $json = self::json($payload);
        // Arithmetic/engine identifiers are explicit, never inferred on reload.
        self::text($payload['numeric_policy'] ?? '', 64);
        self::text($payload['engine_version'] ?? '', 64);
        $values = [self::integer($sessionId), self::key($source['source_system'] ?? '', 32), self::text($source['source_key'] ?? '', 191), self::key($source['contract_id'] ?? '', 96), self::text($source['contract_version'] ?? '', 64), $payload['schema_version'], hash('sha256', $json), $json, time()];
        return $this->sql('INSERT INTO '.$this->table('snapshots').' (session_id,source_system,source_key,contract_id,contract_version,schema_version,content_hash,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)', $values)['id'];
    }

    protected function lockSession(string $sessionId): array
    {
        $sessionId = self::integer($sessionId);
        $row = $this->sql('SELECT * FROM '.$this->table('sessions').' WHERE id=? FOR UPDATE', [$sessionId])['rows'][0] ?? null;
        if (!$row) { throw new RuntimeException('ACS_SESSION_NOT_FOUND'); }
        self::integer($row['session_version']);
        self::integer($row['next_sequence']);
        return $row;
    }

    public function session(string $sessionId): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('sessions').' WHERE id=?', [self::integer($sessionId)])['rows'][0] ?? null;
    }

    /** Stage 1 accepts session-only audit events; future non-NULL links fail closed. */
    public function appendEvent(array $event, array $details, array $visibility): string
    {
        foreach (['encounter_id', 'event_sequence', 'source_entity_id', 'target_entity_id', 'submission_id'] as $future) {
            if (($event[$future] ?? null) !== null) { throw new RuntimeException('ACS_FUTURE_REFERENCE_UNAVAILABLE'); }
        }
        $sessionId = self::integer($event['session_id'] ?? null);
        $operation = self::key($event['operation_key'] ?? '');
        $type = self::key($event['event_type'] ?? '', 64);
        $engine = self::text($event['engine_version'] ?? '', 64);
        $actor = self::integer($event['actor_uid'] ?? null, '4294967295', true);
        $policy = self::text($details['numeric_policy'] ?? '', 64);
        $payload = self::json($details);
        $visibilityJson = self::json($visibility);
        if (isset($details['snapshot_refs']) && !is_array($details['snapshot_refs'])) { throw new InvalidArgumentException('ACS_INVALID_SNAPSHOT_REFS'); }
        $hash = hash('sha256', $payload."\n".$visibilityJson);
        return $this->transactions->run(function () use ($event, $sessionId, $operation, $type, $engine, $actor, $policy, $payload, $visibilityJson, $hash, $details) {
            $this->writable();
            $session = $this->lockSession($sessionId);
            foreach ($details['snapshot_refs'] ?? [] as $snapshotId) {
                $snapshot = $this->sql('SELECT session_id FROM '.$this->table('snapshots').' WHERE id=?', [self::integer($snapshotId)])['rows'][0] ?? null;
                if (!$snapshot || (string)$snapshot['session_id'] !== $sessionId) { throw new RuntimeException('ACS_SNAPSHOT_SCOPE'); }
            }
            $op = $this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE', [$operation])['rows'][0] ?? null;
            if (!$op || (string)$op['session_id'] !== $sessionId || $op['encounter_id'] !== null) { throw new RuntimeException('ACS_OPERATION_SCOPE'); }
            // A confirmed operation is read via lookup; never applied again.
            if ($op['status'] !== 'prepared' || $op['external_system'] !== null) { throw new RuntimeException('ACS_OPERATION_ALREADY_RESOLVED'); }
            $refs = [];
            foreach (['parent_event_id', 'cause_event_id', 'correction_of_event_id'] as $field) {
                $id = $event[$field] ?? null;
                if ($id !== null) {
                    $id = self::integer($id);
                    $parent = $this->sql('SELECT session_id FROM '.$this->table('events').' WHERE id=?', [$id])['rows'][0] ?? null;
                    if (!$parent || (string)$parent['session_id'] !== $sessionId) { throw new RuntimeException('ACS_EVENT_SCOPE'); }
                }
                $refs[] = $id;
            }
            if ($actor !== '0' && !$this->sql('SELECT uid FROM `'.$this->prefix.'users` WHERE uid=?', [$actor])['rows']) { throw new RuntimeException('ACS_ACTOR_NOT_FOUND'); }
            $version = self::integer($session['session_version'], '18446744073709551614');
            $sequence = self::integer($session['next_sequence'], '18446744073709551614');
            self::integer($op['operation_version'], '18446744073709551614');
            $id = $this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,operation_key,event_type,actor_uid,parent_event_id,cause_event_id,correction_of_event_id,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$sessionId, $sequence, $operation, $type, $actor, ...$refs, $engine, time()])['id'];
            $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,?,?,?,?,?)', [$id, $details['schema_version'], $policy, $payload, $visibilityJson, $hash]);
            $updated = $this->sql('UPDATE '.$this->table('sessions').' SET next_sequence=next_sequence+1,session_version=session_version+1,updated_at=? WHERE id=? AND session_version=?', [time(), $sessionId, $version]);
            if ($updated['affected'] !== 1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            $this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=?', ['committed', self::json(['schema_version' => 1, 'event_id' => $id]), time(), $operation]);
            return $id;
        });
    }

    public function event(string $id): ?array
    {
        return $this->sql('SELECT e.*,d.schema_version,d.numeric_policy,d.payload_json,d.visibility_json,d.content_hash FROM '.$this->table('events').' e JOIN '.$this->table('event_details').' d ON d.event_id=e.id WHERE e.id=?', [self::integer($id)])['rows'][0] ?? null;
    }

    /** Diagnostics deliberately accept no arbitrary SQL, stack trace or payload. */
    public function recordDiagnostic(string $requestId, string $category, string $operation, string $phase): string
    {
        if (!in_array($category, ['VALIDATION_ERROR', 'STATE_CONFLICT', 'PERMISSION_DENIED', 'DEPENDENCY_ERROR', 'INTERNAL_ERROR', 'RECOVERY_REQUIRED'], true)) { throw new InvalidArgumentException('ACS_INVALID_CATEGORY'); }
        $id = bin2hex(random_bytes(16));
        $this->transactions->run(function () use ($id, $requestId, $category, $operation, $phase) {
            $this->sql('INSERT INTO '.$this->table('diagnostics').' (error_id,request_id,category,operation,phase,created_at,details_json) VALUES (?,?,?,?,?,?,?)', [$id, self::key($requestId, 96), $category, self::key($operation, 64), self::key($phase, 64), time(), self::json(['schema_version' => 1, 'code' => $category])]);
        });
        return $id;
    }

    /** ACP-only, bounded metadata projection: never select details_json. */
    public function recentDiagnostics(): array
    {
        require_once __DIR__.'/../Application/AuthorizationService.php';
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $rows = $this->sql('SELECT error_id,category,operation,phase,created_at FROM '.$this->table('diagnostics').' ORDER BY created_at DESC,error_id DESC LIMIT 20')['rows'];
        foreach ($rows as &$row) {
            foreach (['error_id', 'operation', 'phase'] as $field) {
                if (!preg_match('/\A[a-z0-9:_.-]{1,64}\z/D', $row[$field])) { $row[$field] = 'redacted'; }
            }
            if (!in_array($row['category'], ['VALIDATION_ERROR', 'STATE_CONFLICT', 'PERMISSION_DENIED', 'DEPENDENCY_ERROR', 'INTERNAL_ERROR', 'RECOVERY_REQUIRED'], true)) { $row['category'] = 'INTERNAL_ERROR'; }
        }
        return $rows;
    }
}
