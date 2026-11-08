<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/OwnerProtocol.php';

/** Server-only complete read. No quantities, reservations or equipment are written. */
final class AF_ADVINV_InventoryRead
{
    private mysqli $link;
    private AF_ADVINV_OwnerAccess $access;
    public function __construct(private object $database, ?AF_ADVINV_OwnerAccess $access=null)
    {
        $this->link=$database instanceof mysqli?$database:($database->write_link??throw new RuntimeException('INV_MYSQLI_REQUIRED'));
        if (isset($database->read_link) && $database->read_link!==$this->link) throw new RuntimeException('INV_AUTHORITATIVE_READ_REQUIRED');
        if ($this->link->character_set_name()!=='utf8mb4') throw new RuntimeException('INV_UTF8MB4_REQUIRED');
        $this->access=$access??new AF_ADVINV_MybbOwnerAccess();
    }
    private function authorize(int $uid,int $actor): void
    {
        if ($uid<1 || $actor!==$this->access->uid() || !function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedinventory')) throw new RuntimeException('INV_ACCESS_DENIED');
        $this->access->authorize('lookup',$uid,['system'=>'inventory','context_key'=>'owner:'.$uid],'inventory-owner-v1',false);
    }
    /** Registration-only metadata fence; caller owns an existing SQL write transaction. */
    public function fence(int $uid,int $actor): void
    {
        $this->authorize($uid,$actor);
        if ((int)$this->rows('SELECT @@autocommit AS a')[0]['a']!==1) throw new RuntimeException('INV_TRANSACTION_REQUIRED');
        try { $this->rows('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode()!==1568) throw $e;
            // Only owner revision metadata is initialized; never alter item qty or bindings.
            $this->rows('INSERT INTO `'.TABLE_PREFIX.'af_advinv_owner_state` (uid,inventory_version) VALUES (?,1) ON DUPLICATE KEY UPDATE uid=uid',[$uid]);
            $this->rows('SELECT uid FROM `'.TABLE_PREFIX.'af_advinv_owner_state` WHERE uid=? FOR UPDATE',[$uid]);
            return;
        }
        throw new RuntimeException('INV_TRANSACTION_REQUIRED');
    }
    public function version(int $uid,int $actor): int
    {
        $this->authorize($uid,$actor);
        $row=$this->rows('SELECT inventory_version FROM `'.TABLE_PREFIX.'af_advinv_owner_state` WHERE uid=? LOCK IN SHARE MODE',[$uid])[0]??null;
        $version=$row===null?1:(int)$row['inventory_version'];
        if ($version<1 || $version>9007199254740991) throw new RuntimeException('INV_VERSION_INVALID');
        return $version;
    }
    public function read(int $uid,int $actor,?int $expectedVersion=null): AF_ADVINV_InventoryReadResult
    {
        global $db;
        $authoritative=$db instanceof mysqli?$db:($db->write_link??null);
        if ($authoritative!==$this->link) throw new RuntimeException('INV_AUTHORITATIVE_READ_REQUIRED');
        $this->authorize($uid,$actor);
        (new AF_ADVINV_OwnerProtocol($this->link))->assertStorage();
        if (!function_exists('af_inv_get_items')) throw new RuntimeException('INV_READER_UNAVAILABLE');
        // At most two complete read attempts. Missing/duplicate pages are never accepted/repaired.
        for ($attempt=0;$attempt<2;$attempt++) {
            $version=$this->version($uid,$actor);
            if ($expectedVersion!==null && $version!==$expectedVersion) throw new RuntimeException('INV_VERSION_CONFLICT');
            $items=[];$total=null;$last=0;$page=1;$pages=1;$size=100;
            do {
                $dto=af_inv_get_items($uid,['page'=>$page,'perPage'=>$size,'_snapshot_current'=>true,'enrich'=>false]);
                if (!is_int($dto['total']??null) || $dto['total']<0 || ($dto['page']??null)!==$page || ($dto['perPage']??null)!==$size || !is_array($dto['items']??null)) throw new RuntimeException('INV_INCOMPLETE_SNAPSHOT');
                if ($total===null) { $total=$dto['total'];$pages=max(1,(int)ceil($total/$size)); }
                if ($dto['total']!==$total || count($dto['items'])!==min($size,max(0,$total-($page-1)*$size))) throw new RuntimeException('INV_INCOMPLETE_SNAPSHOT');
                foreach ($dto['items'] as $row) {
                    $id=(int)($row['id']??0);
                    if ($id<=$last || (int)($row['uid']??0)!==$uid || (int)($row['qty']??0)<1) throw new RuntimeException('INV_INCOMPLETE_SNAPSHOT');
                    $last=$id;$items[]=$row;
                }
                $page++;
            } while ($page<=$pages);
            $equipped=$this->rawRows('SELECT * FROM `'.TABLE_PREFIX.'af_advinv_equipped` WHERE uid=? ORDER BY equip_slot LOCK IN SHARE MODE',[$uid]);
            $support=$this->rawRows('SELECT * FROM `'.TABLE_PREFIX.'af_advinv_support_slots` WHERE uid=? ORDER BY slot_code LOCK IN SHARE MODE',[$uid]);
            $reservations=$this->rawRows('SELECT * FROM `'.TABLE_PREFIX."af_advinv_reservations` WHERE owner_uid=? AND status='active' ORDER BY id LOCK IN SHARE MODE",[$uid]);
            if (count($items)!==$total) throw new RuntimeException('INV_INCOMPLETE_SNAPSHOT');
            if ($this->version($uid,$actor)!==$version) {
                if ($expectedVersion!==null || $attempt===1) throw new RuntimeException('INV_SOURCE_CHANGED');
                continue;
            }
            $this->authorize($uid,$actor);
            return new AF_ADVINV_InventoryReadResult(['owner_uid'=>$uid,'inventory_version'=>$version,'total'=>$total,'items'=>$items,'equipped'=>$equipped,'support'=>$support,'reservations'=>$reservations]);
        }
        throw new RuntimeException('INV_SOURCE_CHANGED');
    }
    private function rawRows(string $sql,array $params): array
    {
        return array_map(static fn($r)=>array_map(static fn($v)=>$v===null?null:(string)$v,$r),$this->rows($sql,$params));
    }
    private function rows(string $sql,array $params=[]): array
    {
        $stmt=$this->link->prepare($sql);if (!$stmt) throw new mysqli_sql_exception('INV_READ_FAILED',$this->link->errno);
        try {
            if ($params) { $values=array_map('strval',$params);if (!$stmt->bind_param(str_repeat('s',count($values)),...$values)) throw new RuntimeException('INV_READ_FAILED'); }
            if (!$stmt->execute()) throw new mysqli_sql_exception('INV_READ_FAILED',$stmt->errno);
            $result=$stmt->get_result();return $result?$result->fetch_all(MYSQLI_ASSOC):[];
        } finally { $stmt->close(); }
    }
}
final class AF_ADVINV_InventoryReadResult implements JsonSerializable
{
    public function __construct(private readonly array $data) {}
    public function serverData(): array { return $this->data; }
    public function jsonSerialize(): array { return ['contract_id'=>'IC-INV.read.v1','inventory_version'=>$this->data['inventory_version'],'total'=>$this->data['total']]; }
}
