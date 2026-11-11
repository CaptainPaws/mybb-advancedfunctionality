<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/OwnerAccess.php';

/** Inventory-owned quantity engine. Standalone APIs and explicit provisional participation; no bootstrap/route. */
final class AF_ADVINV_OwnerProtocol
{
    private mysqli $db;
    private string $prefix;
    private AF_ADVINV_OwnerAccess $access;
    private bool $running = false;
    private const MAX_VERSION = 9007199254740991;

    public function __construct(object $database, ?AF_ADVINV_OwnerAccess $access = null)
    {
        $link = $database instanceof mysqli ? $database : ($database->write_link ?? null);
        if (!$link instanceof mysqli || PHP_INT_SIZE !== 8) throw new RuntimeException('INV_MYSQLI_REQUIRED');
        $this->db = $link; // Authoritative write_link only; no replica and no new connection.
        $this->prefix = (string)TABLE_PREFIX;
        if (!preg_match('/\A[A-Za-z0-9_]{0,30}\z/D', $this->prefix)) throw new RuntimeException('INV_INVALID_PREFIX');
        $this->access = $access ?? new AF_ADVINV_MybbOwnerAccess();
    }

    /** Canonical contract JSON; typed PHP arrays, not raw client JSON. */
    public static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $v, int $depth = 0) use (&$normalize): mixed {
            if ($depth > 24 || is_float($v) || is_object($v) || is_resource($v)) throw new InvalidArgumentException('INV_INVALID_JSON');
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    foreach (array_keys($v) as $key) if (!is_string($key) || !preg_match('/\A[\x20-\x7e]+\z/D', $key)) throw new InvalidArgumentException('INV_INVALID_JSON_KEY');
                    ksort($v, SORT_STRING);
                }
                foreach ($v as &$child) $child = $normalize($child, $depth + 1);
            }
            return $v;
        };
        $json = json_encode($normalize($value), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);
        if (strlen($json) > 65536) throw new InvalidArgumentException('INV_PAYLOAD_TOO_LARGE');
        return $json;
    }

    public static function payloadHash(array $request): string
    {
        unset($request['operation_key'], $request['payload_hash']);
        return hash('sha256', self::canonical($request));
    }

    public function transfer(array $request): array { return $this->propertyOperation('transfer',$request); }
    public function grant(array $request): array { return $this->propertyOperation('grant',$request); }
    private function propertyOperation(string $operation, array $request): array
    {
        $this->validate($operation,$request);
        require_once __DIR__.'/PropertyOperations.php';
        return (new AF_ADVINV_PropertyOperations($this->db,$this->access))->execute($operation,$request);
    }
    public function reserve(array $request): array { return $this->execute('reserve', $request); }
    public function consume(array $request): array { return $this->execute('consume', $request); }
    public function release(array $request): array { return $this->execute('release', $request); }

    /** No writes, including no guard creation; absence on a failed read is never not_found. */
    public function lookup(array $request): array
    {
        $this->validate('lookup', $request);
        $this->identity($request);
        try {
            $this->boundary();
            $this->schema();
            $row = $this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?', [$request['payload']['target_operation_key']]);
            $this->authorize($request, $row ? (int)$row['owner_uid'] : $this->access->uid(), false);
            if (!$row) return ['status'=>'not_found', 'operation_key'=>$request['payload']['target_operation_key']];
            $this->samePrincipal($row, $request);
            return $this->receipt($row);
        } catch (mysqli_sql_exception|Error $error) {
            return $this->unknown($request['payload']['target_operation_key']);
        }
    }

    /** Internal same-connection InnoDB participant. Result is provisional until caller COMMIT.
     * Never starts, commits or rolls back caller work. No remote/multi-database support.
     */
    public function participate(string $operation,array $request): AF_ADVINV_ProvisionalOwnerResult
    {
        if (!in_array($operation,['reserve','consume','release'],true)) throw new InvalidArgumentException('INV_INVALID_OPERATION');
        $this->requireTransaction();
        return new AF_ADVINV_ProvisionalOwnerResult($this->execute($operation,$request,true));
    }
    public function lookupParticipant(array $request): array
    {
        $this->requireTransaction();$this->validate('lookup',$request);$this->identity($request);$this->schema();
        $row=$this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?',[$request['payload']['target_operation_key']]);
        $this->authorize($request,$row?(int)$row['owner_uid']:$this->access->uid(),false);
        if (!$row) return ['status'=>'not_found','operation_key'=>$request['payload']['target_operation_key']];
        $this->samePrincipal($row,$request);return $this->receipt($row);
    }
    private function requireTransaction(): void
    {
        if ($this->running || $this->db->character_set_name()!=='utf8mb4' || (int)$this->one('SELECT @@session.autocommit AS ac')['ac']!==1) throw new RuntimeException('INV_OWNER_TRANSACTION_REQUIRED');
        try { $this->sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (mysqli_sql_exception $e) { if ((int)$e->getCode()===1568) return;throw $e; }
        throw new RuntimeException('INV_OWNER_TRANSACTION_REQUIRED');
    }

    private function execute(string $operation, array $r,bool $joined=false): array
    {
        $this->validate($operation, $r);
        $this->identity($r);
        $begun = false;
        $committing = false;
        $entered = false;
        try {
            if (!$joined) $this->boundary(); // Standalone never touches caller tx.
            $this->schema();
            $p = $r['payload'];
            $hint = $this->one('SELECT owner_uid,item_id FROM '.$this->table('external_operations').' WHERE operation_key=?', [$r['operation_key']]);
            $discovered = ($operation === 'reserve' || $hint) ? null : $this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=?', [$p['reservation_id']]);
            $owner = $hint ? (int)$hint['owner_uid'] : ($operation === 'reserve' ? $p['owner_uid'] : (int)($discovered['owner_uid'] ?? $this->access->uid()));
            $itemId = $hint ? ($hint['item_id'] === null ? null : (int)$hint['item_id']) : ($operation === 'reserve' ? $p['item_id'] : (isset($discovered['item_id']) ? (int)$discovered['item_id'] : null));
            $this->authorize($r, $owner, true);
            if (!$this->one('SELECT uid FROM `'.$this->prefix.'users` WHERE uid=?', [$owner])) throw new RuntimeException('INV_OWNER_UNAVAILABLE');
            $this->running = true;
            $entered = true;
            if (!$joined) {
                if (!$this->db->begin_transaction()) throw new RuntimeException('INV_BEGIN_FAILED');
                $begun = true;
            }
            $this->sql('INSERT INTO '.$this->table('owner_state').' (uid,inventory_version) VALUES (?,1) ON DUPLICATE KEY UPDATE uid=uid', [$owner]);
            $state = $this->one('SELECT inventory_version FROM '.$this->table('owner_state').' WHERE uid=? FOR UPDATE', [$owner]);
            $before = (int)$state['inventory_version'];
            if ($before < 1 || $before > self::MAX_VERSION) throw new RuntimeException('INV_INVENTORY_INTEGRITY');
            // One immutable result per key. The guard serializes normal replays;
            // the journal unique key arbitrates cross-owner races at the final insert.
            $prior = $this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=? FOR UPDATE', [$r['operation_key']]);
            if ($prior) {
                $this->authorize($r, (int)$prior['owner_uid'], true);
                $this->samePrincipal($prior, $r);
                if (!hash_equals($prior['payload_hash'], $r['payload_hash']) || $prior['intent_json'] !== self::canonical($r)) throw new RuntimeException('INV_IDEMPOTENCY_CONFLICT');
                $result = $this->receipt($prior);
                if (!$joined && !$this->db->rollback()) return $this->unknown($r['operation_key']); // No mutation on replay.
                $begun = false;
                return $result;
            }
            if ($hint) throw new RuntimeException('INV_RECEIPT_INTEGRITY'); // Receipts are never deleted.
            $this->authorize($r, $owner, true); // Fresh spending decision under owner lock.
            if ($discovered && ($discovered['context_system'] !== $r['scope']['system'] || $discovered['context_key'] !== $r['scope']['context_key'])) throw new RuntimeException('INV_SCOPE_DENIED');
            $item = $itemId === null ? null : $this->one('SELECT * FROM '.$this->table('items').' WHERE id=? FOR UPDATE', [$itemId]);
            $active = $itemId === null ? [] : $this->sql('SELECT * FROM '.$this->table('reservations')." WHERE item_id=? AND status='active' ORDER BY id FOR UPDATE", [$itemId])['rows'];
            $reservation = $operation === 'reserve' ? null : $this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=? FOR UPDATE', [$p['reservation_id']]);
            if ($reservation) $this->confirmed($reservation);
            if ($reservation && ((int)$reservation['owner_uid'] !== $owner || (int)$reservation['item_id'] !== $itemId)) throw new RuntimeException('INV_RESERVATION_CHANGED');
            if ($reservation && ($reservation['context_system'] !== $r['scope']['system'] || $reservation['context_key'] !== $r['scope']['context_key'])) throw new RuntimeException('INV_SCOPE_DENIED');
            $reserved = 0;
            foreach ($active as $other) {
                if ((int)$other['owner_uid'] !== $owner || (int)$other['remaining_qty'] <= 0) throw new RuntimeException('INV_RESERVATION_INTEGRITY');
                $this->confirmed($other);
                $reserved += (int)$other['remaining_qty'];
            }
            if (!$item && $reserved > 0) throw new RuntimeException('INV_RESERVATION_INTEGRITY');
            if ($item && ((int)$item['qty'] < 1 || $reserved > (int)$item['qty'])) throw new RuntimeException('INV_INVENTORY_INTEGRITY');
            $code = 'OK';
            $status = 'rejected';
            $qty = $p['qty'] ?? 0;
            $changed = false;
            if ($operation === 'reserve') {
                if (!$item || (int)$item['uid'] !== $owner) $code = 'ITEM_NOT_OWNED';
                elseif ($item['entity'] !== 'equipment' || !in_array($item['subtype'], ['consumable','ammo','weapon'], true) || $item['kb_type'] === 'appearance' || ($item['subtype']==='weapon' && ($item['kb_type']!=='arpg_item' || $qty!==1))) $code = 'ITEM_KIND_UNSUPPORTED';
                elseif ($before !== $p['expected_inventory_version']) $code = 'VERSION_CONFLICT';
                elseif ($qty > (int)$item['qty'] - $reserved) $code = 'INSUFFICIENT_AVAILABLE';
                elseif ($before >= self::MAX_VERSION) $code = 'VERSION_EXHAUSTED';
                else {
                    // A key is not a reservation: no qty is removed here.
                    $id = $this->sql('INSERT INTO '.$this->table('reservations').' (operation_key,owner_uid,item_id,initial_qty,remaining_qty,context_system,context_key,status,reservation_version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,\'active\',1,?,?)', [$r['operation_key'],$owner,$itemId,$qty,$qty,$r['scope']['system'],$r['scope']['context_key'],time(),time()])['id'];
                    $reservation = $this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=?', [$id]);
                    $status = 'prepared';
                    $changed = true;
                }
            } elseif (!$reservation) $code = 'RESERVATION_NOT_FOUND';
            elseif ($operation === 'release' && $reservation['status'] !== 'active') {
                $status = 'released';
                $code = 'ALREADY_TERMINAL';
                $qty = 0;
            } elseif ($reservation['status'] !== 'active') $code = 'RESERVATION_NOT_ACTIVE';
            elseif (!$item || (int)$item['uid'] !== $owner) throw new RuntimeException('INV_RESERVATION_INTEGRITY');
            elseif ($operation === 'consume' && ($item['entity'] !== 'equipment' || !in_array($item['subtype'], ['consumable','ammo'], true) || $item['kb_type'] === 'appearance')) $code = 'ITEM_KIND_UNSUPPORTED';
            elseif ($operation === 'consume' && (int)$reservation['reservation_version'] !== $p['expected_version']) $code = 'VERSION_CONFLICT';
            elseif ($operation === 'consume' && $qty > (int)$reservation['remaining_qty']) $code = 'INSUFFICIENT_RESERVED';
            elseif ($before >= self::MAX_VERSION || (int)$reservation['reservation_version'] >= self::MAX_VERSION) $code = 'VERSION_EXHAUSTED';
            else {
                $qty = $operation === 'release' ? (int)$reservation['remaining_qty'] : $qty;
                $remaining = (int)$reservation['remaining_qty'] - $qty;
                $rs = $remaining > 0 ? 'active' : ($operation === 'consume' ? 'consumed' : 'released');
                $updated = $this->sql('UPDATE '.$this->table('reservations').' SET remaining_qty=?,status=?,reservation_version=reservation_version+1,updated_at=? WHERE id=? AND reservation_version=? AND status=\'active\'', [$remaining,$rs,time(),$reservation['id'],$reservation['reservation_version']]);
                if ($updated['affected'] !== 1) throw new RuntimeException('INV_CAS_FAILED');
                if ($operation === 'consume') {
                    $left = (int)$item['qty'] - $qty;
                    if ($left < 0 || $left < $reserved - $qty) throw new RuntimeException('INV_INVENTORY_INTEGRITY');
                    if ($left === 0) {
                        // Only supported owner item kinds, never cosmetic assignment callbacks.
                        foreach (['equipped'=>'equip_slot','support_slots'=>'slot_code'] as $binding=>$slot) {
                            $this->sql('SELECT '.$slot.' FROM '.$this->table($binding).' WHERE uid=? AND item_id=? ORDER BY '.$slot.' FOR UPDATE', [$owner,$itemId]);
                            $this->sql('DELETE FROM '.$this->table($binding).' WHERE uid=? AND item_id=?', [$owner,$itemId]);
                        }
                        $updated = $this->sql('DELETE FROM '.$this->table('items').' WHERE id=? AND uid=? AND qty=?', [$itemId,$owner,$item['qty']]);
                    } else $updated = $this->sql('UPDATE '.$this->table('items').' SET qty=?,updated_at=? WHERE id=? AND uid=? AND qty=?', [$left,time(),$itemId,$owner,$item['qty']]);
                    if ($updated['affected'] !== 1) throw new RuntimeException('INV_CAS_FAILED');
                }
                $reservation = $this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=?', [$reservation['id']]);
                $status = $operation === 'consume' ? 'committed' : 'released';
                $changed = true;
            }
            if ($changed) {
                if ($this->sql('UPDATE '.$this->table('owner_state').' SET inventory_version=inventory_version+1 WHERE uid=? AND inventory_version=?', [$owner,$before])['affected'] !== 1) throw new RuntimeException('INV_CAS_FAILED');
            }
            $result = ['schema_version'=>1,'operation_key'=>$r['operation_key'],'operation_type'=>$operation,'payload_hash'=>$r['payload_hash'],'actor_uid'=>$r['actor']['uid'],'scope'=>$r['scope'],'status'=>$status,'code'=>$code,'external_ref'=>$reservation ? (string)$reservation['id'] : null,'item_id'=>$itemId,'qty'=>$qty,'version'=>$reservation ? (int)$reservation['reservation_version'] : $before,'inventory_versions'=>[['uid'=>$owner,'before'=>$before,'after'=>$before + ($changed ? 1 : 0)]],'reservation'=>$reservation ? ['id'=>(string)$reservation['id'],'remaining_qty'=>(int)$reservation['remaining_qty'],'version'=>(int)$reservation['reservation_version'],'status'=>$reservation['status']] : null];
            $result['result_hash'] = hash('sha256', self::canonical($result));
            $this->sql('INSERT INTO '.$this->table('external_operations').' (operation_key,payload_hash,operation_type,actor_uid,context_system,context_key,owner_uid,item_id,status,external_ref,intent_json,result_json,result_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$r['operation_key'],$r['payload_hash'],$operation,$r['actor']['uid'],$r['scope']['system'],$r['scope']['context_key'],$owner,$itemId,$status,$result['external_ref'],self::canonical($r),self::canonical($result),$result['result_hash'],time(),time()]);
            if ($joined) return $result;
            $committing = true;
            if (!$this->db->commit()) return $this->unknown($r['operation_key']);
            $begun = false;
            return $result;
        } catch (mysqli_sql_exception $error) {
            if ($joined) throw $error; // Outer participant owns the entire rollback.
            if ($committing) return $this->unknown($r['operation_key']); // Never infer rollback after COMMIT dispatch.
            if ($begun) {
                try { if (!$this->db->rollback()) return $this->unknown($r['operation_key']); }
                catch (Throwable $ignored) { return $this->unknown($r['operation_key']); }
            }
            if ((int)$error->getCode() === 1062) {
                $prior = $this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?', [$r['operation_key']]);
                if ($prior) {
                    $this->authorize($r, (int)$prior['owner_uid'], true);
                    $this->samePrincipal($prior, $r);
                    if (!hash_equals($prior['payload_hash'], $r['payload_hash']) || $prior['intent_json'] !== self::canonical($r)) throw new RuntimeException('INV_IDEMPOTENCY_CONFLICT');
                    return $this->receipt($prior);
                }
            }
            return $this->unknown($r['operation_key']);
        } catch (Throwable $error) {
            if ($begun && !$committing) {
                try { if (!$this->db->rollback()) return $this->unknown($r['operation_key']); }
                catch (Throwable $ignored) { return $this->unknown($r['operation_key']); }
            }
            throw $error;
        } finally {
            if ($entered) $this->running = false;
        }
    }

    public function validateCommand(string $operation,array $request): void { $this->validate($operation,$request); }
    private function validate(string $operation, array $r): void
    {
        self::fields($r, ['schema_version','operation','operation_key','actor','scope','payload_hash','payload']);
        if ($r['schema_version'] !== 1 || $r['operation'] !== $operation) throw new InvalidArgumentException('INV_INVALID_OPERATION');
        self::key($r['operation_key']);
        self::fields($r['actor'], ['uid','authorization_ref']);
        self::positive($r['actor']['uid'], 4294967295);
        self::text($r['actor']['authorization_ref'], 191);
        self::fields($r['scope'], ['system','context_key']);
        if (!in_array($r['scope']['system'], ['inventory','acs','shop'], true) || !str_starts_with($r['operation_key'], $r['scope']['system'].':')) throw new InvalidArgumentException('INV_INVALID_SCOPE');
        self::text($r['scope']['context_key'], 191);
        $fields = match ($operation) {
            'reserve'=>['owner_uid','item_id','qty','session_id','expected_inventory_version'],
            'consume'=>['reservation_id','qty','expected_version'],
            'release'=>['reservation_id','reason'],
            'lookup'=>['target_operation_key'],
            'transfer'=>['item_id','qty','from_uid','to_uid','expected_versions'],
            'grant'=>['recipient_uid','normalized_item','provenance'],
        };
        self::fields($r['payload'], $fields);
        foreach ($r['payload'] as $key=>$value) {
            if (in_array($key, ['owner_uid','item_id','from_uid','to_uid','recipient_uid'], true)) self::positive($value, 4294967295);
            elseif ($key === 'qty') self::positive($value, 2147483647);
            elseif ($key==='expected_versions') {
                self::fields($value,['from','to']);foreach ($value as $v) self::positive($v,self::MAX_VERSION);
            } elseif ($key==='normalized_item') {
                self::fields($value,['qty','definition_ref','snapshot_json','entity','subtype']);
                self::positive($value['qty'],2147483647);self::text($value['snapshot_json'],49152);
                self::text($value['entity'],32);self::text($value['subtype'],32);
                self::fields($value['definition_ref'],['type','key','version','hash']);
                foreach (['type','key','version'] as $f) self::text($value['definition_ref'][$f],$f==='type'?32:($f==='key'?64:191));
                if (!is_string($value['definition_ref']['hash']) || !preg_match('/\A[a-f0-9]{64}\z/D',$value['definition_ref']['hash'])) throw new InvalidArgumentException('INV_INVALID_DEFINITION');
            } elseif ($key==='provenance') {
                self::fields($value,['system','source_key']);
                if (!in_array($value['system'],['acs','shop','inventory'],true)) throw new InvalidArgumentException('INV_INVALID_PROVENANCE');
                self::text($value['source_key'],191);
            }
            elseif (str_starts_with($key, 'expected_')) self::positive($value, self::MAX_VERSION);
            elseif ($key === 'reservation_id') {
                if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) throw new InvalidArgumentException('INV_INVALID_RESERVATION');
            } elseif ($key === 'target_operation_key') self::key($value);
            else self::text($value, $key === 'reason' ? 512 : 191);
        }
        if ($operation==='grant' && $r['payload']['provenance']['system']!==$r['scope']['system']) throw new InvalidArgumentException('INV_INVALID_PROVENANCE');
        if ($operation === 'reserve' && $r['payload']['session_id'] !== $r['scope']['context_key']) throw new InvalidArgumentException('INV_INVALID_SCOPE');
        if (!is_string($r['payload_hash']) || !preg_match('/\A[a-f0-9]{64}\z/D', $r['payload_hash']) || !hash_equals(self::payloadHash($r), $r['payload_hash'])) throw new InvalidArgumentException('INV_PAYLOAD_HASH_MISMATCH');
    }

    private function identity(array $r): void
    {
        if ($this->access->uid() !== $r['actor']['uid']) throw new RuntimeException('INV_ACCESS_DENIED');
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedinventory')) throw new RuntimeException('INV_DEPENDENCY_UNAVAILABLE');
    }

    private function authorize(array $r, int $owner, bool $mutation): void
    {
        $this->identity($r);
        $this->access->authorize($r['operation'], $owner, ['system'=>$r['scope']['system'],'context_key'=>$r['scope']['context_key']], $r['actor']['authorization_ref'], $mutation);
    }

    private function samePrincipal(array $row, array $r): void
    {
        if ((int)$row['actor_uid'] !== $r['actor']['uid'] || $row['context_system'] !== $r['scope']['system'] || $row['context_key'] !== $r['scope']['context_key']) throw new RuntimeException('INV_ACCESS_DENIED');
    }

    /** A reservation is usable only with its original durable prepared receipt. */
    private function confirmed(array $reservation): void
    {
        $remaining = (int)$reservation['remaining_qty'];
        $initial = (int)$reservation['initial_qty'];
        $version = (int)$reservation['reservation_version'];
        if ($initial < 1 || $initial > 2147483647 || $remaining < 0 || $remaining > $initial || $version < 1 || $version > self::MAX_VERSION
            || !in_array($reservation['status'], ['active','consumed','released'], true)
            || (($reservation['status'] === 'active') !== ($remaining > 0))) throw new RuntimeException('INV_RESERVATION_INTEGRITY');
        $origin = $this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?', [$reservation['operation_key']]);
        if (!$origin) throw new RuntimeException('INV_RESERVATION_UNCONFIRMED');
        $receipt = $this->receipt($origin);
        if ($origin['operation_type'] !== 'reserve' || $origin['status'] !== 'prepared'
            || (int)$origin['owner_uid'] !== (int)$reservation['owner_uid']
            || (int)$origin['item_id'] !== (int)$reservation['item_id']
            || $origin['external_ref'] !== (string)$reservation['id']
            || $origin['context_system'] !== $reservation['context_system'] || $origin['context_key'] !== $reservation['context_key']
            || ($receipt['qty'] ?? null) !== (int)$reservation['initial_qty']) throw new RuntimeException('INV_RESERVATION_UNCONFIRMED');
    }

    private function receipt(array $row): array
    {
        $receipt = json_decode($row['result_json'], true, 32, JSON_THROW_ON_ERROR);
        $hash = $receipt['result_hash'] ?? '';
        unset($receipt['result_hash']);
        if (!is_string($hash) || !hash_equals($row['result_hash'], $hash) || !hash_equals($hash, hash('sha256', self::canonical($receipt)))
            || ($receipt['operation_key'] ?? null) !== $row['operation_key'] || ($receipt['payload_hash'] ?? null) !== $row['payload_hash'] || ($receipt['status'] ?? null) !== $row['status']
            || ($receipt['operation_type'] ?? null) !== $row['operation_type'] || ($receipt['actor_uid'] ?? null) !== (int)$row['actor_uid']
            || ($receipt['scope']['system'] ?? null) !== $row['context_system'] || ($receipt['scope']['context_key'] ?? null) !== $row['context_key']
            || ($receipt['item_id'] ?? null) !== ($row['item_id'] === null ? null : (int)$row['item_id'])
            || ($receipt['external_ref'] ?? null) !== $row['external_ref']) throw new RuntimeException('INV_RECEIPT_INTEGRITY');
        $receipt['result_hash'] = $hash;
        return $receipt;
    }

    private function boundary(): void
    {
        if ($this->running) throw new RuntimeException('INV_AMBIENT_TRANSACTION');
        if ($this->db->character_set_name() !== 'utf8mb4') throw new RuntimeException('INV_UTF8MB4_REQUIRED');
        if ((int)$this->one('SELECT @@session.autocommit AS ac')['ac'] !== 1) throw new RuntimeException('INV_AMBIENT_TRANSACTION');
        // Unlike BEGIN, SET TRANSACTION fails inside an ambient transaction and
        // never commits or rolls back the caller's work.
        try { $this->sql('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (mysqli_sql_exception $error) { throw new RuntimeException('INV_AMBIENT_TRANSACTION'); }
    }

    public function assertExternalBoundary(): void { $this->boundary(); }
    public function assertStorage(): void { $this->schema(); }

    private function schema(): void
    {
        foreach (['items','equipped','support_slots','owner_state','external_operations','reservations'] as $table) {
            $row = $this->one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->prefix.'af_advinv_'.$table]);
            if (!$row || $row['ENGINE'] !== 'InnoDB') throw new RuntimeException('INV_SCHEMA_NOT_READY');
        }
        $critical = [
            'items'=>['id'=>'int unsigned','uid'=>'int unsigned','qty'=>'int'],
            'owner_state'=>['uid'=>'int unsigned','inventory_version'=>'bigint unsigned'],
            'external_operations'=>['operation_key'=>'varchar(191)','intent_json'=>'longtext','result_json'=>'longtext','result_hash'=>'char(64)'],
            'reservations'=>['id'=>'bigint unsigned','item_id'=>'int unsigned','initial_qty'=>'int unsigned','remaining_qty'=>'int unsigned','reservation_version'=>'bigint unsigned'],
        ];
        foreach ($critical as $table=>$columns) {
            foreach ($columns as $column=>$type) {
                $meta = $this->one('SELECT COLUMN_TYPE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->prefix.'af_advinv_'.$table,$column]);
                if (!$meta || $meta['COLUMN_TYPE'] !== $type || ($column === 'operation_key' && $meta['COLLATION_NAME'] !== 'ascii_bin')) throw new RuntimeException('INV_SCHEMA_NOT_READY');
            }
        }
        foreach (['items'=>['PRIMARY'=>['id']], 'equipped'=>['PRIMARY'=>['uid','equip_slot']], 'support_slots'=>['PRIMARY'=>['uid','slot_code']], 'owner_state'=>['PRIMARY'=>['uid']], 'external_operations'=>['PRIMARY'=>['operation_key']], 'reservations'=>['PRIMARY'=>['id'],'reservation_once'=>['operation_key'],'item_active'=>['item_id','status']]] as $table=>$required) {
            $indexes = [];
            $unique = [];
            foreach ($this->sql('SHOW INDEX FROM '.$this->table($table))['rows'] as $index) {
                $indexes[$index['Key_name']][] = $index['Column_name'];
                $unique[$index['Key_name']] = (int)$index['Non_unique'] === 0;
            }
            foreach ($required as $name=>$columns) if (($indexes[$name] ?? null) !== $columns || ($name !== 'item_active' && !$unique[$name])) throw new RuntimeException('INV_SCHEMA_NOT_READY');
        }
    }

    private function table(string $name): string { return '`'.$this->prefix.'af_advinv_'.$name.'`'; }
    private function unknown(string $key): array { return ['status'=>'recovery_required','code'=>'INV_OUTCOME_UNKNOWN','operation_key'=>$key]; }
    private function one(string $sql, array $parameters = []): ?array { return $this->sql($sql, $parameters)['rows'][0] ?? null; }
    private function sql(string $sql, array $parameters = []): array
    {
        $statement = $this->db->prepare($sql);
        if (!$statement) throw new mysqli_sql_exception('INV_DATABASE_ERROR', $this->db->errno);
        try {
            if ($parameters) {
                $values = array_map(static fn($v)=>$v === null ? null : (string)$v, $parameters);
                if (!$statement->bind_param(str_repeat('s', count($values)), ...$values)) throw new mysqli_sql_exception('INV_DATABASE_ERROR', $statement->errno);
            }
            if (!$statement->execute()) throw new mysqli_sql_exception('INV_DATABASE_ERROR', $statement->errno);
            $result = $statement->get_result();
            return ['rows'=>$result ? $result->fetch_all(MYSQLI_ASSOC) : [],'affected'=>$statement->affected_rows,'id'=>(string)$statement->insert_id];
        } finally { $statement->close(); }
    }
    private static function fields(mixed $value, array $fields): void
    {
        if (!is_array($value) || count($value) !== count($fields) || array_diff($fields, array_keys($value))) throw new InvalidArgumentException('INV_INVALID_FIELDS');
    }
    private static function positive(mixed $value, int $max): void
    {
        if (!is_int($value) || $value < 1 || $value > $max) throw new InvalidArgumentException('INV_INVALID_INTEGER');
    }
    private static function key(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/\A[a-zA-Z0-9:._\/-]{1,191}\z/D', $value)) throw new InvalidArgumentException('INV_INVALID_KEY');
    }
    private static function text(mixed $value, int $max): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || str_contains($value, "\0") || !preg_match('//u', $value)) throw new InvalidArgumentException('INV_INVALID_TEXT');
    }
}

/** A participant cannot publish a durable success before the outer owner transaction commits. */
final class AF_ADVINV_ProvisionalOwnerResult implements JsonSerializable
{
    public function __construct(private readonly array $receipt) {}
    public function serverReceipt(): array {return $this->receipt;}
    public function jsonSerialize(): never {throw new RuntimeException('INV_RECEIPT_NOT_DURABLE_YET');}
}
