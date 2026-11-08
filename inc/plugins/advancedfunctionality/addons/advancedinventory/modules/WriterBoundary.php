<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/OwnerProtocol.php';

/** Shared owner lock for the existing writers. Authorization remains at their entry points. */
final class AF_ADVINV_WriterBoundary
{
    private static array $transactions = [];
    private mysqli $link;
    private string $prefix;

    public function __construct(private object $database)
    {
        $this->link = $database instanceof mysqli ? $database : $database->write_link;
        $this->prefix = (string)TABLE_PREFIX;
        if (!preg_match('/\A[A-Za-z0-9_]{0,30}\z/D', $this->prefix)) throw new RuntimeException('INV_INVALID_PREFIX');
    }

    /** No schema means legacy mode, not permission to create a reservation. Partial schema fails closed. */
    public static function enabled(object $database): bool
    {
        $tables = ['af_advinv_owner_state','af_advinv_external_operations','af_advinv_reservations'];
        $link=$database instanceof mysqli?$database:($database->write_link??null);
        $n=0;
        if ($link instanceof mysqli) {
            foreach ($tables as $table) {
                $stmt=$link->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                if (!$stmt) throw new RuntimeException('INV_SCHEMA_UNAVAILABLE');
                try { $name=TABLE_PREFIX.$table;$stmt->bind_param('s',$name);if (!$stmt->execute()) throw new RuntimeException('INV_SCHEMA_UNAVAILABLE');$n+=(int)$stmt->get_result()->fetch_row()[0]; }
                finally { $stmt->close(); }
            }
        } else {
            foreach ($tables as $table) if ($database->table_exists($table)) $n++;
        }
        if ($n !== 0 && $n !== 3) throw new RuntimeException('INV_SCHEMA_NOT_READY');
        return $n === 3;
    }

    public function run(array $owners, callable $work): mixed
    {
        $owners = array_values(array_unique($owners)); sort($owners, SORT_NUMERIC);
        foreach ($owners as $uid) if (!is_int($uid) || $uid < 1) throw new InvalidArgumentException('INV_INVALID_OWNER');
        $id = spl_object_id($this->link);
        if (isset(self::$transactions[$id])) {
            if (array_diff($owners, self::$transactions[$id])) throw new RuntimeException('INV_LOCK_ORDER');
            return $work($this);
        }
        if (isset($this->database->read_link) && $this->database->read_link !== $this->link) throw new RuntimeException('INV_AUTHORITATIVE_READ_REQUIRED');
        if ($this->link->character_set_name() !== 'utf8mb4' || (int)$this->rows('SELECT @@autocommit AS a')[0]['a'] !== 1) throw new RuntimeException('INV_AMBIENT_TRANSACTION');
        try { $this->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (Throwable $e) { throw new RuntimeException('INV_AMBIENT_TRANSACTION'); }
        (new AF_ADVINV_OwnerProtocol($this->link))->assertStorage();
        $locks = [];
        $started = $committing = false;
        try {
            $name = $this->rows('SELECT DATABASE() AS n')[0]['n'];
            foreach ($owners as $uid) {
                // Same advisory namespace used by Shop/Appearance. Always before SQL locks.
                $key = 'af_aa_owner_'.sha1($name.':'.$this->prefix.':'.$uid);
                if ((int)$this->rows('SELECT GET_LOCK(?,10) AS n',[$key])[0]['n'] !== 1) throw new RuntimeException('INV_OWNER_BUSY');
                $locks[] = $key;
            }
            if (!$this->link->begin_transaction()) throw new RuntimeException('INV_BEGIN_FAILED');
            $started = true;
            foreach ($owners as $uid) {
                $this->query('INSERT INTO '.$this->table('owner_state').' (uid,inventory_version) VALUES (?,1) ON DUPLICATE KEY UPDATE uid=uid',[$uid]);
                $this->rows('SELECT uid FROM '.$this->table('owner_state').' WHERE uid=? FOR UPDATE',[$uid]);
            }
            self::$transactions[$id] = $owners;
            $result = $work($this);
            $committing = true;
            if (!$this->link->commit()) throw new RuntimeException('INV_WRITE_OUTCOME_UNKNOWN');
            return $result;
        } catch (Throwable $e) {
            if ($committing) throw new RuntimeException('INV_WRITE_OUTCOME_UNKNOWN');
            if ($started && !$this->link->rollback()) throw new RuntimeException('INV_WRITE_OUTCOME_UNKNOWN');
            throw $e;
        } finally {
            unset(self::$transactions[$id]);
            foreach (array_reverse($locks) as $key) $this->query('SELECT RELEASE_LOCK(?)',[$key]);
        }
    }

    public function item(int $uid, int $itemId): array
    {
        $row = $this->rows('SELECT * FROM '.$this->table('items').' WHERE id=? AND uid=? FOR UPDATE',[$itemId,$uid])[0] ?? null;
        if (!$row) throw new RuntimeException('INV_ITEM_NOT_OWNED');
        if ((int)$row['qty'] <= 0) throw new RuntimeException('INV_INVENTORY_INTEGRITY');
        return $row;
    }
    public function free(int $itemId): void
    {
        if ($this->rows('SELECT id FROM '.$this->table('reservations')." WHERE item_id=? AND status='active' ORDER BY id FOR UPDATE",[$itemId])) {
            throw new RuntimeException('INV_ITEM_RESERVED: предмет зарезервирован; сначала освободите резерв');
        }
    }
    public function touch(int $uid): void
    {
        if ($this->query('UPDATE '.$this->table('owner_state').' SET inventory_version=inventory_version+1 WHERE uid=? AND inventory_version BETWEEN 1 AND 9007199254740990',[$uid]) !== 1) throw new RuntimeException('INV_VERSION_EXHAUSTED');
    }

    public function bind(int $uid, int $itemId, string $slot, bool $support): void
    {
        $this->run([$uid], function () use ($uid,$itemId,$slot,$support) {
            $table = $support ? 'support_slots' : 'equipped'; $column = $support ? 'slot_code' : 'equip_slot';
            $old = $this->rows('SELECT item_id FROM '.$this->table($table).' WHERE uid=? AND '.$column.'=?',[$uid,$slot]);
            $ids = array_unique([$itemId,...array_map(static fn($r)=>(int)$r['item_id'],$old)]); sort($ids,SORT_NUMERIC);
            foreach ($ids as $id) { $row=$this->item($uid,$id); $this->free($id); if ($id===$itemId) $item=$row; }
            if ($support) {
                $configs=af_inv_support_slots();
                if (!af_inv_is_consumable_item($item) || !isset($configs[$slot])) throw new RuntimeException('INV_INVALID_SLOT');
            } elseif (!in_array($slot,af_inv_candidate_slots_for_item($item),true)) throw new RuntimeException('INV_INVALID_SLOT');
            $this->query('DELETE FROM '.$this->table($table).' WHERE uid=? AND item_id=?',[$uid,$itemId]);
            if ($support) $this->query('INSERT INTO '.$this->table($table).' (uid,slot_code,item_id,sortorder,created_at,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),sortorder=VALUES(sortorder),updated_at=VALUES(updated_at)',[$uid,$slot,$itemId,(int)($configs[$slot]['sortorder']??0),time(),time()]);
            else $this->query('INSERT INTO '.$this->table($table).' (uid,equip_slot,item_id,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),updated_at=VALUES(updated_at)',[$uid,$slot,$itemId,time()]);
            $this->touch($uid);
        });
    }
    public function unbind(int $uid, string $slot, int $itemId, bool $support): void
    {
        $this->run([$uid], function () use ($uid,$slot,$itemId,$support) {
            $table=$support?'support_slots':'equipped'; $column=$support?'slot_code':'equip_slot';
            $where=$slot!==''?$column.'=?':'item_id=?'; $value=$slot!==''?$slot:$itemId;
            $rows=$this->rows('SELECT item_id FROM '.$this->table($table).' WHERE uid=? AND '.$where.' ORDER BY item_id',[$uid,$value]);
            foreach ($rows as $row) { $this->item($uid,(int)$row['item_id']); $this->free((int)$row['item_id']); }
            if ($this->query('DELETE FROM '.$this->table($table).' WHERE uid=? AND '.$where,[$uid,$value])) $this->touch($uid);
        });
    }
    public function delete(int $uid, array $ids): array
    {
        sort($ids,SORT_NUMERIC);
        return $this->run([$uid],function () use ($uid,$ids) {
            $items=[];
            foreach (array_unique($ids) as $id) { $item=$this->item($uid,$id); $this->free($id); $items[]=$item; }
            // Appearance cleanup has independent callbacks; do not silently join it to this tx.
            foreach ($items as $item) if (!empty(af_advinv_resolve_appearance_item($item)['is_visual_item'])) throw new RuntimeException('INV_APPEARANCE_DELETE_REQUIRES_PROTOCOL');
            foreach ($items as $item) {
                foreach (['equipped','support_slots'] as $table) $this->query('DELETE FROM '.$this->table($table).' WHERE uid=? AND item_id=?',[$uid,$item['id']]);
                $this->query('DELETE FROM '.$this->table('items').' WHERE uid=? AND id=?',[$uid,$item['id']]);
            }
            if ($items) $this->touch($uid);
            return array_map(static fn($r)=>(int)$r['id'],$items);
        });
    }
    public function decrement(int $uid, int $id, int $qty): bool
    {
        if ($qty < 1) return false;
        return $this->run([$uid],function () use ($uid,$id,$qty) {
            $item=$this->item($uid,$id); $this->free($id);
            if ($qty>(int)$item['qty']) return false;
            if ($qty===(int)$item['qty']) $this->delete($uid,[$id]);
            else { $this->query('UPDATE '.$this->table('items').' SET qty=qty-?,updated_at=? WHERE id=? AND uid=?',[$qty,time(),$id,$uid]); $this->touch($uid); }
            return true;
        });
    }
    public function update(int $uid, int $id, int $qty, string $entity, string $slot): void
    {
        if ($qty<1) throw new RuntimeException('INV_INVALID_QUANTITY');
        $this->run([$uid],function () use ($uid,$id,$qty,$entity,$slot) {
            $this->item($uid,$id); $this->free($id);
            $sql='UPDATE '.$this->table('items').' SET qty=?,updated_at=?';$params=[$qty,time()];
            if ($entity!=='') { $sql.=',entity=?';$params[]=$entity; }
            if ($slot!=='') { $sql.=',slot=?';$params[]=$slot; }
            $this->query($sql.' WHERE uid=? AND id=?',[...$params,$uid,$id]);$this->touch($uid);
        });
    }
    public function held(int $uid): void
    {
        if (!in_array($uid,self::$transactions[spl_object_id($this->link)]??[],true)) throw new RuntimeException('INV_WRITER_BOUNDARY_REQUIRED');
    }
    public function loadout(int $uid, string $slot, callable $work): mixed
    {
        return $this->run([$uid],function () use ($uid,$slot,$work) {
            $rows=$this->rows('SELECT item_id FROM '.$this->table('equipped').' WHERE uid=? AND equip_slot=?',[$uid,$slot]);
            foreach ($rows as $row) { $this->item($uid,(int)$row['item_id']); $this->free((int)$row['item_id']); }
            $result=$work();$this->touch($uid);return $result;
        });
    }
    public function sell(int $uid, int $itemId, int $qty): array
    {
        if ($qty<1) throw new RuntimeException('INV_INVALID_QUANTITY');
        return $this->run([$uid],function () use ($uid,$itemId,$qty) {
            global $mybb;
            // Read-only early denial under owner guard, before any external Balance effect.
            if ($this->rows('SELECT id FROM '.$this->table('reservations')." WHERE item_id=? AND status='active'",[$itemId])) throw new RuntimeException('INV_ITEM_RESERVED: предмет зарезервирован');
            if (!defined('AF_BALANCE_TABLE') || !function_exists('af_balance_get') || !function_exists('af_shop_add_balance')) throw new RuntimeException('INV_BALANCE_UNAVAILABLE');
            $tables=[AF_BALANCE_TABLE];
            if (!empty($mybb->settings['af_balance_tx_enable'])) $tables[]=AF_BALANCE_TX_TABLE;
            foreach ($tables as $table) {
                $engine=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.$table])[0]['ENGINE']??null;
                if ($engine!=='InnoDB') throw new RuntimeException('INV_BALANCE_NOT_TRANSACTIONAL');
            }
            af_balance_get($uid);
            $this->rows('SELECT uid FROM `'.$this->prefix.AF_BALANCE_TABLE.'` WHERE uid=? FOR UPDATE',[$uid]);
            $item=$this->item($uid,$itemId);$this->free($itemId);
            if ($qty>(int)$item['qty']) throw new RuntimeException('INV_QUANTITY_EXCEEDED');
            $sale=af_advinv_item_sale_profile($item,$qty);
            if (empty($sale['can_sell']) || !in_array($sale['currency'],['credits','ability_tokens'],true)) throw new RuntimeException('INV_SELL_PRICE_UNAVAILABLE');
            $binding=$this->rows('SELECT equip_slot FROM '.$this->table('equipped').' WHERE uid=? AND item_id=?',[$uid,$itemId]);
            if ($qty<(int)$item['qty'] && $binding) throw new RuntimeException('INV_EQUIPPED_PARTIAL_SALE');
            $before=af_shop_get_balance($uid,$sale['currency']);
            $this->decrement($uid,$itemId,$qty);
            af_shop_add_balance($uid,$sale['currency'],$sale['sell_total_minor'],'inventory_sell',['item_id'=>$itemId,'qty'=>$qty]);
            if (af_shop_get_balance($uid,$sale['currency'])!==$before+$sale['sell_total_minor']) throw new RuntimeException('INV_BALANCE_WRITE_FAILED');
            return ['ok'=>true,'sold_qty'=>$qty,'sold_minor'=>$sale['sell_total_minor'],'sold_major'=>$sale['sell_total_major'],'item_qty_left'=>(int)$item['qty']-$qty,'currency'=>$sale['currency'],'currency_symbol'=>$sale['currency_symbol']];
        });
    }
    public function table(string $name): string { return '`'.$this->prefix.'af_advinv_'.$name.'`'; }
    public function rows(string $sql, array $params=[]): array { return $this->statement($sql,$params)['rows']; }
    public function query(string $sql, array $params=[]): int { return $this->statement($sql,$params)['affected']; }
    private function statement(string $sql, array $params): array
    {
        $stmt=$this->link->prepare($sql);if (!$stmt) throw new RuntimeException('INV_DATABASE_ERROR');
        try {
            if ($params) { $values=array_map(static fn($v)=>(string)$v,$params); if (!$stmt->bind_param(str_repeat('s',count($values)),...$values)) throw new RuntimeException('INV_DATABASE_ERROR'); }
            if (!$stmt->execute()) throw new RuntimeException('INV_DATABASE_ERROR');
            $result=$stmt->get_result();return ['rows'=>$result?$result->fetch_all(MYSQLI_ASSOC):[],'affected'=>$stmt->affected_rows];
        } finally { $stmt->close(); }
    }
}

function af_advinv_reservation_writers_enabled(): bool
{
    global $db; return is_object($db) && AF_ADVINV_WriterBoundary::enabled($db);
}
function af_advinv_writer(): AF_ADVINV_WriterBoundary
{
    global $db; return new AF_ADVINV_WriterBoundary($db);
}
function af_advinv_writer_http(callable $work): mixed
{
    try { return $work(); }
    catch (Throwable $e) { af_advancedinventory_json(['ok'=>false,'error'=>$e instanceof mysqli_sql_exception?'INV_DATABASE_ERROR':$e->getMessage()],409); }
}
function af_advinv_maintenance_gate(): void
{
    if (af_advinv_reservation_writers_enabled()) throw new RuntimeException('INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL');
}
