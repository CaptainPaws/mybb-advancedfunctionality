<?php
final class InventoryFixtureDatabase
{
    public mysqli $read_link;
    public function __construct(public mysqli $write_link) { $this->read_link=$write_link; }
    public function table_exists($table): bool { return (bool)$this->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t_".$this->escape_string($table)."'")->num_rows; }
    public function field_exists($field,$table): bool { return (bool)$this->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t_".$this->escape_string($table)."' AND COLUMN_NAME='".$this->escape_string($field)."'")->num_rows; }
    public function affected_rows(): int { return $this->write_link->affected_rows; }
    public function insert_id(): int { return $this->write_link->insert_id; }
    public function num_rows($r): int { return $r->num_rows; }
    public function escape_string($s): string { return $this->write_link->real_escape_string((string)$s); }
    public function query($sql) { try { return $this->write_link->query($sql); } catch (mysqli_sql_exception $e) { fwrite(STDERR,'Fixture SQL error: '.$e->getMessage().PHP_EOL);throw $e; } }
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
