<?php
/** Run on the same isolated MySQL container as acs_inventory_owner_sql.php. */
define('ACS_INV_FIXTURE_LIBRARY',1);
require __DIR__.'/acs_inventory_owner_sql.php';
define('AF_ADDONS',$base);
define('AF_CACHE','/tmp/');
define('TIME_NOW',time());
final class InventoryFixtureDatabase
{
    public mysqli $read_link;
    public function __construct(public mysqli $write_link) { $this->read_link=$write_link; }
    public function table_exists($table): bool { return (bool)$this->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t_".$this->escape_string($table)."'")->num_rows; }
    public function escape_string($s): string { return $this->write_link->real_escape_string((string)$s); }
    public function query($sql) { return $this->write_link->query($sql); }
    public function write_query($sql) { return $this->query($sql); }
    public function fetch_array($r) { return $r->fetch_assoc(); }
    public function fetch_field($r,$field) { return $r->fetch_assoc()[$field]??null; }
    public function simple_select($table,$fields='*',$where='',$opts=[]) {
        $sql='SELECT '.$fields.' FROM t_'.$table.($where!==''?' WHERE '.$where:'');
        if (!empty($opts['order_by'])) $sql.=' ORDER BY '.$opts['order_by'].' '.($opts['order_dir']??'ASC');
        if (isset($opts['limit'])) $sql.=' LIMIT '.(int)$opts['limit'];
        return $this->query($sql);
    }
    public function update_query($table,$data,$where) {
        $set=[];foreach ($data as $k=>$v) $set[]='`'.$k."`='".$v."'";
        return $this->query('UPDATE t_'.$table.' SET '.implode(',',$set).' WHERE '.$where);
    }
    public function insert_query($table,$data) {
        $this->query('INSERT INTO t_'.$table.' (`'.implode('`,`',array_keys($data))."`) VALUES ('".implode("','",array_values($data))."')");return $this->write_link->insert_id;
    }
    public function delete_query($table,$where) { return $this->query('DELETE FROM t_'.$table.' WHERE '.$where); }
}
$database = 'acs613a_fixture_'.bin2hex(random_bytes(8));
$admin = connectFixture();
$admin->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
$db = connectFixture($database);
try {
    // Existing owner tables, matching Inventory's actual schema (not a second Inventory).
    $proposal=file_get_contents(dirname(__DIR__).'/docs/contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql');
    $items=substr($proposal,strpos($proposal,'CREATE TABLE af_advinv_items'));
    $items=substr($items,0,strpos($items,';'));
    $db->query(str_replace('CREATE TABLE af_advinv_items','CREATE TABLE t_af_advinv_items',$items));
    $db->query('CREATE TABLE t_users (uid INT UNSIGNED PRIMARY KEY) ENGINE=MyISAM');
    $db->query('INSERT INTO t_users VALUES (10),(11)');
    $db->query('CREATE TABLE t_af_advinv_equipped (uid INT UNSIGNED NOT NULL,equip_slot VARCHAR(64) NOT NULL,item_id INT UNSIGNED NOT NULL,updated_at INT UNSIGNED NOT NULL,PRIMARY KEY(uid,equip_slot),KEY uid_item(uid,item_id)) ENGINE=InnoDB');
    $db->query('CREATE TABLE t_af_advinv_support_slots (uid INT UNSIGNED NOT NULL,slot_code VARCHAR(64) NOT NULL,item_id BIGINT UNSIGNED NOT NULL,sortorder INT NOT NULL DEFAULT 0,created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(uid,slot_code),KEY uid_item(uid,item_id),KEY uid_sortorder(uid,sortorder)) ENGINE=InnoDB');
    foreach (AF_ADVINV_OwnerSchema::statements(TABLE_PREFIX) as $sql) $db->query($sql);
    $db->query("INSERT INTO t_af_advinv_items(id,uid,entity,subtype,kb_type,qty,created_at,updated_at) VALUES (21,10,'equipment','consumable','arpg_item',3,1,1),(22,10,'equipment','ammo','arpg_item',1,1,1),(23,11,'equipment','consumable','arpg_item',3,1,1)");
    $native=$db;$db=new InventoryFixtureDatabase($native);
    $plugins=new class { public function add_hook(...$args) {} };
    $mybb=(object)['settings'=>['af_advancedinventory_debug_enabled'=>0],'user'=>['uid'=>10]];
    require AF_ADDONS.'advancedinventory/advancedinventory.php';
    $owner=new AF_ADVINV_OwnerProtocol($native,new FixtureAccess());
    $r=$owner->reserve(reserveRequest('writers',21,1,1));
    $writer=af_advinv_writer();
    $denied=function ($fn) { try { $fn(); } catch (RuntimeException $e) { check(str_starts_with($e->getMessage(),'INV_ITEM_RESERVED'),$e->getMessage());return; } throw new RuntimeException('FAIL: reserved writer allowed'); };
    $before=scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id=21');
    $denied(fn()=>$writer->sell(10,21,1));
    $denied(fn()=>af_inv_remove_item(10,21,1));
    $denied(fn()=>af_advinv_delete_owner_item_slot(10,['id'=>21]));
    $denied(fn()=>$writer->update(10,21,1,'equipment','equipment'));
    $denied(fn()=>$writer->bind(10,21,'support_1',true));
    $native->query("INSERT INTO t_af_advinv_support_slots(uid,slot_code,item_id) VALUES (10,'support_1',21)");
    $denied(fn()=>$writer->unbind(10,'support_1',0,true));
    $native->query("UPDATE t_af_advinv_items SET subtype='consumable' WHERE id=22");
    $denied(fn()=>$writer->bind(10,22,'support_1',true));
    check(scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id=21')===$before,'reserved quantity preserved');
    check(!af_inv_remove_item(10,22,2) && scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id=22')==='1','over request no deletion');
    fails(fn()=>af_advinv_normalize_customization_items_in_db(),'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL');
    fails(fn()=>af_advancedinventory_upgrade_schema(),'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL');
    fails(fn()=>af_advancedinventory_uninstall(),'INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL');
    $owner->release(request('release','writer-release',['reservation_id'=>$r['external_ref'],'reason'=>'fixture release']));
    check(af_inv_remove_item(10,21,1),'ordinary decrement after release');
    $writer->bind(10,21,'support_1',true);$writer->unbind(10,'support_1',0,true);
    $writer->delete(10,[22]);check(scalar($native,'SELECT COUNT(*) FROM t_af_advinv_items WHERE id=22')==='0','free item deletion');
    $id=af_inv_add_item(10,['entity'=>'equipment','slot'=>'equipment','subtype'=>'ammo','kb_type'=>'arpg_item','kb_key'=>'test','qty'=>2,'meta_json'=>'{}']);
    $id2=af_inv_add_item(10,['entity'=>'equipment','slot'=>'equipment','subtype'=>'ammo','kb_type'=>'arpg_item','kb_key'=>'test','qty'=>1,'meta_json'=>'{}']);
    check($id===$id2 && scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$id)==='3','ordinary stack grant still works');
    $reserved=$owner->reserve(reserveRequest('merge', $id,1,version($native)));
    $id3=af_inv_add_item(10,['entity'=>'equipment','slot'=>'equipment','subtype'=>'ammo','kb_type'=>'arpg_item','kb_key'=>'test','qty'=>1,'meta_json'=>'{}']);
    check($id3!==$id && scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$id)==='3','grant isolates reserved stack');
    fails(fn()=>af_inv_add_item(10,['entity'=>'equipment','slot'=>'equipment','subtype'=>'ammo','kb_type'=>'arpg_item','kb_key'=>'test','qty'=>2147483647,'meta_json'=>'{}']),'INV_QUANTITY_OVERFLOW');
    $native->begin_transaction();fails(fn()=>$writer->delete(10,[$id3]),'INV_AMBIENT_TRANSACTION');$native->rollback();
    echo "PASS: actual Inventory writers deny reserved sell/delete/decrement/update/equip/unbind/replacement; release restores normal writes; add/merge isolation, overflow boundary, maintenance gate, ambient refusal
";
} finally { $native->close();$admin->query('DROP DATABASE `'.$database.'`');$admin->close(); }
