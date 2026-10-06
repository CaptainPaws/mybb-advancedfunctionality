<?php

class AtfResult { public array $rows; public int $position = 0; public function __construct(array $rows) { $this->rows = array_values($rows); } }
class AtfDatabase {
    public array $tables = [];
    public array $columns = [];
    public array $indexes = [];
    // Stay above the growing master-template fixture so inserted theme rows
    // never collide with a master tid during repeated lifecycle checks.
    public int $nextId = 1000;
    public array $writeLog = [];
    public function __construct(bool $legacy = false, array $legacyRows = []) {
        $previous = "<title>{\$mybb->settings['bbname']}</title>\n<script>\nvar x = '{\$lang->some_value}';\nvar path = \"C:\\\\themes\\\"atf\\\"\";\n</script>";
        $this->tables['templates'] = [
            ['tid'=>1, 'title'=>'index', 'template'=>'MASTER', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>2, 'title'=>'index', 'template'=>$previous, 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>3, 'title'=>'forumbit_depth2_forum', 'template'=>'MASTER FORUM', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>4, 'title'=>'forumbit_depth2_forum', 'template'=>'PREVIOUS FORUM', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>5, 'title'=>'forumbit_depth2_forum_lastpost', 'template'=>'MASTER LASTPOST', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>6, 'title'=>'forumbit_depth2_forum_lastpost', 'template'=>'PREVIOUS LASTPOST', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>7, 'title'=>'forumdisplay_thread', 'template'=>'MASTER THREAD ROW', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>8, 'title'=>'forumdisplay_thread', 'template'=>'PREVIOUS THREAD ROW', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>9, 'title'=>'showthread', 'template'=>'MASTER SHOWTHREAD', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>10, 'title'=>'showthread', 'template'=>'APUI SHOWTHREAD BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>11, 'title'=>'postbit_classic', 'template'=>'MASTER CLASSIC POST', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>12, 'title'=>'postbit_classic', 'template'=>'APUI CLASSIC POST BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
            ['tid'=>13, 'title'=>'member_profile', 'template'=>'MASTER MEMBER PROFILE', 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10],
            ['tid'=>14, 'title'=>'member_profile', 'template'=>'APUI MEMBER PROFILE BYTES', 'sid'=>1, 'version'=>'1840', 'status'=>'', 'dateline'=>20],
        ];
        // Keep the lifecycle fixture aligned with the complete ownership map.
        // Surface-specific regressions add seeds over time; every owned name
        // must have the read-only MyBB master row required by acquisition.
        $known = array_column($this->tables['templates'], null, 'title');
        $nextTid = 15;
        foreach (array_keys(af_adaptivethemeframework_template_seeds()) as $title) {
            if (isset($known[$title])) continue;
            $this->tables['templates'][] = ['tid'=>$nextTid++, 'title'=>$title, 'template'=>'MASTER '.strtoupper($title), 'sid'=>-2, 'version'=>'1840', 'status'=>'', 'dateline'=>10];
        }
        $this->tables['templatesets'] = [['sid'=>1, 'title'=>'Default']];
        if ($legacy) {
            $name = 'af_adaptivethemeframework_template_ownership';
            $this->tables[$name] = $legacyRows;
            $this->columns[$name] = ['id', 'template_name', 'template_sid', 'previous_content'];
            $this->indexes[$name] = [];
        }
    }
    public function table_exists($table) { return array_key_exists($table, $this->tables); }
    public function build_create_table_collation() { return ' DEFAULT CHARSET=utf8mb4'; }
    public function write_query($sql) {
        $name = 'af_adaptivethemeframework_template_ownership';
        if (str_starts_with($sql, 'SELECT template_sid')) {
            $counts = [];
            foreach ($this->tables[$name] as $row) {
                $key = (int)($row['template_sid'] ?? 0).'|'.(string)($row['template_name'] ?? '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            $rows = [];
            foreach ($counts as $key => $count) if ($count > 1) {
                [$sid, $template] = explode('|', $key, 2);
                $rows[] = ['template_sid'=>(int)$sid, 'template_name'=>$template, 'cnt'=>$count];
            }
            return new AtfResult(array_slice($rows, 0, 1));
        } elseif (str_starts_with($sql, 'CREATE TABLE')) {
            $this->tables[$name] = [];
            $this->columns[$name] = array_keys(af_adaptivethemeframework_ownership_columns());
            $this->indexes[$name] = ['template_lease', 'ownership_state', 'template_tid'];
        } elseif (preg_match('/ADD (?:UNIQUE )?KEY ([a-z_]+)/', $sql, $match)) {
            $this->indexes[$name][] = $match[1];
        }
        return true;
    }
    public function field_exists($field, $table) { return in_array($field, $this->columns[$table] ?? [], true); }
    public function add_column($table, $field, $definition) {
        $this->columns[$table][] = $field;
        $default = str_contains($definition, "migration_review") ? 'migration_review' : (str_contains($definition, 'mediumtext') || str_contains($definition, "DEFAULT ''") ? '' : 0);
        foreach ($this->tables[$table] as &$row) { $row[$field] = $default; }
    }
    public function index_exists($table, $name) { return in_array($name, $this->indexes[$table] ?? [], true); }
    private function matches(array $row, string $where): bool {
        if ($where === '' || $where === 'sid>0') return $where === '' || (int)$row['sid'] > 0;
        preg_match_all("/([a-z_]+)='?(-?[a-z0-9_]+)'?/i", $where, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) if ((string)($row[$part[1]] ?? '') !== (string)$part[2]) return false;
        return true;
    }
    public function simple_select($table, $fields='*', $where='', $options=[]) {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row, $where)));
        if (($options['limit'] ?? 0) === 1) $rows = array_slice($rows, 0, 1);
        return new AtfResult($rows);
    }
    public function fetch_array($result) { return $result->rows[$result->position++] ?? false; }
    public function escape_string($value) {
        return strtr((string)$value, ["\\"=>"\\\\", "\0"=>"\\0", "\n"=>"\\n", "\r"=>"\\r", "'"=>"\\'", '"'=>'\\"']);
    }
    private function decodeWrite(array $row): array {
        foreach ($row as $key => $value) {
            if (!is_string($value)) continue;
            // MyBB quotes values without escaping them. Model the resulting SQL
            // literal, rejecting raw quotes and decoding what MySQL stores.
            for ($i = 0, $length = strlen($value); $i < $length; $i++) {
                if ($value[$i] === "'") throw new RuntimeException("SQL syntax error: unescaped quote in {$key}");
                if ($value[$i] !== "\\") continue;
                if (++$i >= $length) throw new RuntimeException("SQL syntax error: dangling backslash in {$key}");
            }
            $row[$key] = preg_replace_callback('/\\\\([0nr\\\\\'\"])/', static fn($match) => match ($match[1]) {
                '0' => "\0", 'n' => "\n", 'r' => "\r", default => $match[1],
            }, $value);
        }
        return $row;
    }
    public function insert_query($table, $row) {
        $this->writeLog[] = ['insert', $table, $row];
        $row = $this->decodeWrite($row);
        if ($table === 'templates') $row['tid'] = $this->nextId++;
        else $row['id'] = $this->nextId++;
        $this->tables[$table][] = $row;
        return $row[$table === 'templates' ? 'tid' : 'id'];
    }
    public function update_query($table, $values, $where) {
        $this->writeLog[] = ['update', $table, $values];
        $values = $this->decodeWrite($values);
        foreach ($this->tables[$table] as &$row) if ($this->matches($row, $where)) $row = array_merge($row, $values);
        return true;
    }
    public function delete_query($table, $where) {
        $this->tables[$table] = array_values(array_filter($this->tables[$table], fn($row) => !$this->matches($row, $where)));
    }
}
