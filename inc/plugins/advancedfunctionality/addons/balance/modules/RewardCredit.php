<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Code-only trust boundary. Implementations MUST re-read a durable confirmed reward,
 * recipient and issuer permissions, including for historical lookup. Never compose
 * this from request fields, account ownership, GM status alone or a boolean hook.
 * No production reward provider is registered by this owner-only stage. */
interface AF_BALANCE_RewardAuthority
{
    /** Return a stable, persisted authority identity for this exact intent, or throw.
     * Keys: scope (acs.reward), source_ref, uid, kind, amount_minor,
     * authority_ref, confirmation_ref, issuer_ref. */
    public function authorize(array $intent): array;
}

final class AF_BALANCE_RewardCredit
{
    public function __construct(private mysqli $link, private ?AF_BALANCE_RewardAuthority $authority = null)
    {
        if (PHP_INT_SIZE !== 8 || !preg_match('/\A[A-Za-z0-9_]{0,30}\z/D', TABLE_PREFIX)) {
            throw new RuntimeException('BALANCE_UNSUPPORTED_RUNTIME');
        }
    }

    private function table(string $name): string { return '`'.TABLE_PREFIX.'af_balance'.$name.'`'; }
    private function query(string $sql, array $args = []): mysqli_result|bool
    {
        $s = $this->link->prepare($sql);
        if (!$s) throw new RuntimeException('BALANCE_DATABASE_ERROR');
        try {
            if ($args) { $types = str_repeat('s', count($args)); $s->bind_param($types, ...$args); }
            if (!$s->execute()) throw new mysqli_sql_exception('BALANCE_DATABASE_ERROR', $s->errno);
            return $s->get_result() ?: true;
        } finally { $s->close(); }
    }
    private function row(string $sql, array $args = []): ?array
    {
        $r = $this->query($sql, $args);
        return $r instanceof mysqli_result ? ($r->fetch_assoc() ?: null) : null;
    }
    public static function json(array $value): string
    {
        ksort($value, SORT_STRING);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private function standalone(): void
    {
        if ($this->link->character_set_name() !== 'utf8mb4' || (int)$this->row('SELECT @@autocommit AS a')['a'] !== 1) {
            throw new RuntimeException('BALANCE_AMBIENT_TRANSACTION');
        }
        // MySQL rejects this inside an active transaction; unlike BEGIN it cannot
        // implicitly commit a caller's transaction. No owner work precedes it.
        try { $this->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (Throwable $e) { throw new RuntimeException('BALANCE_AMBIENT_TRANSACTION', 0, $e); }
    }
    public function installed(): bool
    {
        $r = $this->row('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [TABLE_PREFIX.'af_balance', 'owner_version']);
        return (int)$r['n'] === 1 || (int)$this->row('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[TABLE_PREFIX.'af_balance_external_operations'])['n'] === 1;
    }
    /** Read-only readiness: no repair/DDL/row creation. No MyISAM conversion. */
    public function assertStorage(): void
    {
        $mode=(string)$this->row('SELECT @@SESSION.sql_mode AS mode')['mode'];
        if ($this->link->character_set_name() !== 'utf8mb4' || !preg_match('/(?:^|,)STRICT_(?:TRANS|ALL)_TABLES(?:,|$)/', $mode)) throw new RuntimeException('BALANCE_UNSAFE_CONNECTION');
        $names=[TABLE_PREFIX.'af_balance',TABLE_PREFIX.'af_balance_tx',TABLE_PREFIX.'af_balance_external_operations'];
        $tables=$this->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)',$names);
        $engines=[]; while ($r=$tables->fetch_assoc()) $engines[$r['TABLE_NAME']]=strtoupper($r['ENGINE']);
        foreach ($names as $name) if (($engines[$name] ?? '') !== 'INNODB') throw new RuntimeException('BALANCE_SCHEMA_NOT_READY');
        $rows=$this->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLLATION_NAME,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)',$names);
        $schema=[];while ($r=$rows->fetch_assoc()) $schema[$r['TABLE_NAME']][$r['COLUMN_NAME']]=$r;
        $required = [
            '' => ['uid'=>'int unsigned','exp'=>'bigint','credits'=>'bigint','ability_tokens'=>'bigint','updated_at'=>'int unsigned','owner_version'=>'bigint unsigned'],
            '_external_operations' => ['operation_key'=>'varchar(191)','payload_hash'=>'char(64)','source_hash'=>'char(64)','uid'=>'int unsigned','kind'=>'varchar(32)','amount_scaled'=>'bigint','expected_version'=>'bigint unsigned','source_ref'=>'varchar(191)','authority_json'=>'longtext','status'=>'varchar(32)','result_json'=>'longtext','result_hash'=>'char(64)','updated_at'=>'bigint'],
            '_tx' => ['id'=>'bigint unsigned','uid'=>'int unsigned','kind'=>'varchar(32)','amount'=>'bigint','balance_after'=>'bigint','reason'=>'varchar(64)','source'=>'varchar(64)','ref_type'=>'varchar(32)','ref_id'=>'bigint unsigned','meta_json'=>'mediumtext','actor_uid'=>'int unsigned','created_at'=>'int unsigned'],
        ];
        foreach ($required as $suffix => $columns) {
            foreach ($columns as $column => $type) {
                $r = $schema[TABLE_PREFIX.'af_balance'.$suffix][$column] ?? null;
                $actual = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower((string)($r['COLUMN_TYPE'] ?? '')));
                if ($actual !== $type || ($column !== 'meta_json' && ($r['IS_NULLABLE'] ?? '') !== 'NO')) throw new RuntimeException('BALANCE_SCHEMA_NOT_READY');
            }
        }
        if (($schema[$names[2]]['operation_key']['COLLATION_NAME'] ?? '') !== 'ascii_bin' || ($schema[$names[0]]['owner_version']['COLUMN_DEFAULT'] ?? '') !== '1') throw new RuntimeException('BALANCE_SCHEMA_NOT_READY');
        $rows=$this->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols,SUM(SUB_PART IS NOT NULL) AS partial FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?) GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE',$names);
        $keys=[];while ($r=$rows->fetch_assoc()) $keys[$r['TABLE_NAME']][$r['INDEX_NAME']]=$r;
        foreach ([''=>['PRIMARY'=>'uid'], '_tx'=>['PRIMARY'=>'id'], '_external_operations'=>['PRIMARY'=>'operation_key','source_once'=>'source_hash']] as $suffix=>$indexes) {
            foreach ($indexes as $name=>$columns) {
                $r = $keys[TABLE_PREFIX.'af_balance'.$suffix][$name] ?? null;
                if (!$r || (int)$r['NON_UNIQUE'] !== 0 || $r['cols'] !== $columns || (int)$r['partial'] !== 0) throw new RuntimeException('BALANCE_SCHEMA_NOT_READY');
            }
        }
    }
    /** Operator-only, explicit maintenance upgrade. Not invoked by install, activate,
     * hooks or HTTP. Stop writers, back up DB and finish legacy imports first. */
    public function upgrade(): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('BALANCE_OPERATOR_CLI_REQUIRED');
        $this->standalone();
        foreach (['','_tx'] as $suffix) {
            $r=$this->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[TABLE_PREFIX.'af_balance'.$suffix]);
            if (!$r || strtoupper($r['ENGINE']) !== 'INNODB') throw new RuntimeException('BALANCE_SCHEMA_NOT_READY');
        }
        $scale = $this->row("SELECT value FROM `".TABLE_PREFIX."settings` WHERE name='af_balance_credits_scale_migrated'");
        if (($scale['value'] ?? '') !== '1') throw new RuntimeException('BALANCE_LEGACY_SCALE_NOT_MIGRATED');
        if (!(int)$this->row('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[TABLE_PREFIX.'af_balance','owner_version'])['n']) $this->query('ALTER TABLE '.$this->table('').' ADD owner_version BIGINT UNSIGNED NOT NULL DEFAULT 1');
        $this->query('CREATE TABLE IF NOT EXISTS '.$this->table('_external_operations')." (
            operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            uid INT UNSIGNED NOT NULL, kind VARCHAR(32) NOT NULL, amount_scaled BIGINT NOT NULL,
            expected_version BIGINT UNSIGNED NOT NULL, source_ref VARCHAR(191) NOT NULL,
            authority_json LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL,
            result_json LONGTEXT NOT NULL, result_hash CHAR(64) NOT NULL, updated_at BIGINT NOT NULL,
            PRIMARY KEY(operation_key), UNIQUE KEY source_once(source_hash), KEY recipient_operations(uid,kind,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->assertStorage();
    }
    private function authorized(array $intent): array
    {
        if (!$this->authority) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        $a = $this->authority->authorize($intent);
        foreach (['source_ref','uid','kind','amount_minor'] as $k) {
            if (($a[$k] ?? null) !== $intent[$k]) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        }
        if (($a['scope'] ?? null) !== 'acs.reward') throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        foreach (['authority_ref','confirmation_ref','issuer_ref'] as $k) {
            if (!is_string($a[$k] ?? null) || $a[$k] === '' || strlen($a[$k]) > 191) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        }
        if (count($a) !== 8) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        return $a;
    }
    private function key(string $key): void
    {
        if (!preg_match('/\A[A-Za-z0-9:._-]{1,191}\z/D', $key)) throw new InvalidArgumentException('BALANCE_INVALID_KEY');
    }
    private function receipt(array $row): array
    {
        if (!hash_equals($row['result_hash'], hash('sha256',$row['result_json']))) throw new RuntimeException('BALANCE_RECEIPT_INTEGRITY');
        $r=json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR);
        foreach (['operation_key','payload_hash','uid','kind','source_ref','status'] as $k) {
            if ((string)($r[$k] ?? '') !== (string)$row[$k]) throw new RuntimeException('BALANCE_RECEIPT_INTEGRITY');
        }
        if (($r['amount_minor'] ?? null) !== (int)$row['amount_scaled']) throw new RuntimeException('BALANCE_RECEIPT_INTEGRITY');
        $intent=['operation_key'=>$row['operation_key'],'uid'=>(int)$row['uid'],'kind'=>$row['kind'],'amount_minor'=>(int)$row['amount_scaled'],'source_ref'=>$row['source_ref'],'expected_version'=>(int)$row['expected_version']];
        $hash=hash('sha256',self::json($intent + ['authority_hash'=>hash('sha256',$row['authority_json'])]));
        if (!hash_equals($row['payload_hash'],$hash) || ($r['expected_version'] ?? null) !== $intent['expected_version'] || ($r['authority_hash'] ?? '') !== hash('sha256',$row['authority_json']) || ($r['status'] ?? '') !== 'committed' || ($r['owner_version'] ?? null) !== $intent['expected_version']+1 || !is_int($r['balance_before'] ?? null) || !is_int($r['balance_after'] ?? null) || $r['balance_before'] > PHP_INT_MAX-$intent['amount_minor'] || $r['balance_after'] !== $r['balance_before']+$intent['amount_minor']) throw new RuntimeException('BALANCE_RECEIPT_INTEGRITY');
        return $r + ['result_hash'=>$row['result_hash']];
    }
    public function lookup(string $operation_key): array
    {
        $this->key($operation_key); $this->assertStorage(); $this->standalone();
        $row=$this->row('SELECT * FROM '.$this->table('_external_operations').' WHERE operation_key=?',[$operation_key]);
        // No unauthenticated existence oracle, even for absent keys.
        if (!$this->authority) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        if (!$row) return ['status'=>'not_found','operation_key'=>$operation_key];
        $intent=['operation_key'=>$row['operation_key'],'uid'=>(int)$row['uid'],'kind'=>$row['kind'],'amount_minor'=>(int)$row['amount_scaled'],'source_ref'=>$row['source_ref'],'expected_version'=>(int)$row['expected_version']];
        $a=$this->authorized($intent);
        if (!hash_equals($row['authority_json'],self::json($a))) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
        return $this->receipt($row);
    }
    public function credit(string $operation_key, mixed $uid, string $kind, mixed $amount_minor, string $source_ref, mixed $expected_version): array
    {
        $this->key($operation_key);
        if (!is_int($uid) || !is_int($amount_minor) || !is_int($expected_version) || $uid < 1 || $uid > 4294967295 || !in_array($kind,['exp','credits'],true) || $amount_minor < 1 || $expected_version < 1 || $source_ref === '' || strlen($source_ref)>191) throw new InvalidArgumentException('BALANCE_INVALID_INTENT');
        $intent=compact('operation_key','uid','kind','amount_minor','source_ref','expected_version');
        $authority=$this->authorized($intent);
        $authorityJson=self::json($authority);
        $hash=hash('sha256',self::json($intent + ['authority_hash'=>hash('sha256',$authorityJson)]));
        // Source identity deliberately excludes key and recipient: changing either
        // cannot duplicate one reward-recipient source. A provider supplies one
        // durable source_ref per reward recipient/kind, never a caller nonce.
        $sourceHash=hash('sha256','acs.reward:'.$source_ref);
        $this->assertStorage(); $this->standalone();
        $started=$committing=false;
        try {
            if (!$this->link->begin_transaction()) throw new RuntimeException('BALANCE_BEGIN_FAILED');
            $started=true;
            // Immutable operation claim precedes the delta. Duplicate INSERT waits
            // for the original COMMIT/ROLLBACK, including different recipients.
            $exists=$this->row('SELECT * FROM '.$this->table('_external_operations').' WHERE operation_key=?',[$operation_key]);
            if (!$exists) {
                try {
                    $this->query('INSERT INTO '.$this->table('_external_operations')." (operation_key,payload_hash,source_hash,uid,kind,amount_scaled,expected_version,source_ref,authority_json,status,result_json,result_hash,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'pending','','',?)",[$operation_key,$hash,$sourceHash,$uid,$kind,$amount_minor,$expected_version,$source_ref,$authorityJson,time()]);
                } catch (mysqli_sql_exception $e) { if ($e->getCode() !== 1062) throw $e; }
            }
            $existing=$this->row('SELECT * FROM '.$this->table('_external_operations').' WHERE operation_key=? FOR UPDATE',[$operation_key]);
            if (!$existing || !hash_equals($existing['payload_hash'],$hash)) throw new RuntimeException('BALANCE_INTENT_CONFLICT');
            if ($existing['status'] !== 'pending') {
                $result=$this->receipt($existing);
                $this->link->rollback(); $started=false;
                return $result;
            }
            $this->query('INSERT INTO '.$this->table('')." (uid,exp,credits,ability_tokens,updated_at) VALUES (?,0,0,0,?) ON DUPLICATE KEY UPDATE uid=uid",[$uid,time()]);
            $balance=$this->row('SELECT * FROM '.$this->table(''). ' WHERE uid=? FOR UPDATE',[$uid]);
            // Re-read durable authorization under the owner lock. The source must
            // be immutable, and provider reads must use this authoritative writer.
            if (!hash_equals($authorityJson,self::json($this->authorized($intent)))) throw new RuntimeException('BALANCE_REWARD_UNAUTHORIZED');
            if ((int)$balance['owner_version'] !== $expected_version) throw new RuntimeException('BALANCE_VERSION_CONFLICT');
            $old=(int)$balance[$kind];
            if ($old > PHP_INT_MAX-$amount_minor || $expected_version === PHP_INT_MAX) throw new RuntimeException('BALANCE_OVERFLOW');
            $new=$old+$amount_minor; $version=$expected_version+1;
            $this->query('UPDATE '.$this->table('')." SET `$kind`=?,owner_version=?,updated_at=? WHERE uid=? AND owner_version=?",[$new,$version,time(),$uid,$expected_version]);
            $meta=self::json(['operation_key'=>$operation_key,'source_ref'=>$source_ref,'payload_hash'=>$hash]);
            $this->query('INSERT INTO '.$this->table('_tx')." (uid,kind,amount,balance_after,reason,source,ref_type,ref_id,meta_json,actor_uid,created_at) VALUES (?,?,?,?,'acs_reward','acs','reward',0,?,0,?)",[$uid,$kind,$amount_minor,$new,$meta,time()]);
            $tx=(string)$this->link->insert_id;
            $result=$intent + ['payload_hash'=>$hash,'status'=>'committed','balance_before'=>$old,'balance_after'=>$new,'owner_version'=>$version,'tx_id'=>$tx,'authority_hash'=>hash('sha256',$authorityJson)];
            $json=self::json($result); $resultHash=hash('sha256',$json);
            $this->query('UPDATE '.$this->table('_external_operations')." SET status='committed',result_json=?,result_hash=?,updated_at=? WHERE operation_key=? AND status='pending'",[$json,$resultHash,time(),$operation_key]);
            $committing=true;
            if (!$this->link->commit()) throw new RuntimeException('BALANCE_COMMIT_UNKNOWN');
            return json_decode($json,true,512,JSON_THROW_ON_ERROR) + ['result_hash'=>$resultHash];
        } catch (Throwable $e) {
            if ($committing) return ['status'=>'recovery_required','operation_key'=>$operation_key,'payload_hash'=>$hash,'reason'=>'commit_unknown'];
            if ($started) {
                try { if (!$this->link->rollback()) throw new RuntimeException('rollback'); }
                catch (Throwable $rollback) { return ['status'=>'recovery_required','operation_key'=>$operation_key,'payload_hash'=>$hash,'reason'=>'rollback_unknown']; }
            }
            throw $e;
        }
    }
}
