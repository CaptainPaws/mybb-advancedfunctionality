<?php
declare(strict_types=1);

/** Sanitized operation identity only; never raw SQL/server error text. */
final class AcsEngineMigrationSqlException extends RuntimeException
{
    public function __construct(public readonly string $operation, int $errno)
    {
        parent::__construct('database_'.$operation.(in_array($errno,[1044,1045,1142,1143,1227],true)?'_denied':'_failed'),$errno);
    }
}

/** Standalone DBA tool. No MyBB bootstrap, lifecycle hooks or web entry point. */
final class AcsEngineMigration
{
    public const ORDER=['threadsubscriptions','mailqueue','moderatorlog','datacache','privatemessages','attachments','forums','users','threads','posts'];
    /** Bounded HTTP-safe observations only. Never constructs a migration or reads row data. */
    public static function readOnlyReport(callable $select,string $prefix):array
    {
        self::ensure(preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)===1,'configuration_identifier');
        $version=$select('SELECT VERSION() AS version')[0]['version']??null;
        $names=array_map(fn($t)=>"'".$prefix.$t."'",self::ORDER);
        $rows=$select('SELECT TABLE_NAME,ENGINE,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',$names).')');
        $by=[];foreach($rows as $r)$by[$r['TABLE_NAME']]=$r;
        $tables=[];
        foreach(self::ORDER as $t){
            $r=$by[$prefix.$t]??null;$schema='NOT_VERIFIED';
            if($r){$ddl=$select('SHOW CREATE TABLE `'.$prefix.$t.'`');$values=array_values($ddl[0]??[]);
                if(isset($values[1]))$schema=self::schemaUnsupported($values[1])?'BLOCKED':'PRELIMINARY';}
            $tables[$t]=['engine'=>$r['ENGINE']??null,'bytes'=>$r?(int)$r['DATA_LENGTH']+(int)$r['INDEX_LENGTH']:null,'schema'=>$schema];
        }
        return ['version'=>is_string($version)&&preg_match('/\A[0-9a-zA-Z. +_\-]{1,100}\z/D',$version)?$version:null,'tables'=>$tables];
    }
    /** Independent read-only probes; failures do not suppress the other observations. */
    public static function readOnlyAccess(callable $select,string $prefix):array
    {
        self::ensure(preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)===1,'configuration_identifier');
        $out=[];
        $probe=static function(string $name,string $sql)use($select,&$out):?array{
            try{$r=$select($sql);$out[$name]=['status'=>'PASS','errno'=>null];return $r;}
            catch(Throwable $e){$n=(int)$e->getCode();$out[$name]=['status'=>in_array($n,[1044,1045,1142,1143,1227],true)?'DENIED':'NOT_VERIFIED','errno'=>$n?:null];return null;}
        };
        $probe('replication_status','SHOW REPLICA STATUS');
        $probe('triggers','SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        $probe('foreign_keys','SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA<>DATABASE()');
        $probe('table_catalog',"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $grants=$probe('grants','SHOW GRANTS');
        $db=$probe('server_metadata','SELECT DATABASE() AS db');
        $scope=isset($db[0]['db'])?'`'.str_replace('`','``',$db[0]['db']).'`.*':null;
        $text=$grants===null?'':implode("\n",array_map(fn($r)=>implode(' ',$r),$grants));
        foreach(['LOCK TABLES','SELECT','ALTER','CREATE','INSERT','TRIGGER'] as $priv){
            $ok=$scope!==null&&preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\b'.preg_quote($priv,'/').'\b[^\n]*) ON (?:\*\.\*|'.preg_quote($scope,'/').') TO /',$text)===1;
            $out['grant_'.strtolower(str_replace(' ','_',$priv))]=['status'=>$ok?'DIRECT_GRANT':'NOT_VERIFIED','errno'=>null];
        }
        // Schema/table/role grants can differ. NOT_VERIFIED is not a claim that access is denied.
        foreach(self::ORDER as $t){
            $probe('metadata_'.$t,'SHOW INDEX FROM `'.$prefix.$t.'`');
            $probe('select_'.$t,'SELECT * FROM `'.$prefix.$t.'` LIMIT 0');
        }
        return $out;
    }
    private static function schemaUnsupported(string $ddl):bool
    {
        return preg_match('/\b(?:FOREIGN KEY|GENERATED ALWAYS|SPATIAL KEY|PARTITION BY|ROW_FORMAT=(?:FIXED|COMPRESSED|REDUNDANT))\b/i',$ddl)===1;
    }
    private bool $acp=false;
    // Conservative until this process owns both mutexes and has checked the durable state.
    private bool $acpDdlMayHaveStarted=true;
    public function acpFailureIsBeforeDdl():bool {return $this->acp&&!$this->acpDdlMayHaveStarted;}
    private static function sqlOperation(string $sql):string
    {
        if(str_starts_with($sql,'SHOW REPLICA STATUS'))return 'replication_status';
        if(str_starts_with($sql,'SHOW GRANTS'))return 'grants';
        if(str_starts_with($sql,'LOCK TABLES'))return 'lock_tables';
        if(str_starts_with($sql,'ALTER TABLE'))return 'alter';
        if(str_contains($sql,'information_schema.TRIGGERS'))return 'triggers';
        if(str_contains($sql,'information_schema.REFERENTIAL_CONSTRAINTS'))return 'foreign_keys';
        if(str_contains($sql,'information_schema.TABLES'))return 'table_catalog';
        if(str_starts_with($sql,'SHOW CREATE TABLE'))return 'table_definition';
        if(str_starts_with($sql,'SHOW FULL COLUMNS'))return 'table_columns';
        if(str_starts_with($sql,'SHOW INDEX'))return 'table_indexes';
        if(str_starts_with($sql,'SELECT CAST('))return 'table_data';
        if(str_contains($sql,'GET_LOCK('))return 'migration_mutex';
        if(str_starts_with($sql,'SET SESSION'))return 'session_setup';
        return 'server_metadata';
    }
    private function query(string $sql,int $mode=MYSQLI_STORE_RESULT):mysqli_result|bool
    {
        try{return $this->db->query($sql,$mode);}
        catch(mysqli_sql_exception $e){if(!$this->acp)throw $e;throw new AcsEngineMigrationSqlException(self::sqlOperation($sql),(int)$e->getCode());}
    }
    private float $deadline=0;
    private ?Closure $acpConnect=null;
    private array $acpCatalog=[];
    private bool $acpLocksHeld=false;
    private const ACP_HEADER="<?php http_response_code(404); exit; ?>\n";
    /** Trusted ACP adapter only; no arbitrary SQL, paths or connection IDs from HTTP. */
    public static function forAcp(Closure $connect,string $prefix,string $dir,string $serverUuid):self
    {
        self::ensure(defined('IN_MYBB')&&defined('IN_ADMINCP')&&IN_ADMINCP,'acp_context_required');
        self::ensure(function_exists('fsync'),'durable_journal_unavailable');
        $memory=ini_parse_quantity(ini_get('memory_limit'));self::ensure($memory===-1||$memory-memory_get_usage(true)>=67108864,'http_memory_too_small');
        self::ensure(function_exists('mysqli_poll')&&defined('MYSQLI_ASYNC')&&str_contains(mysqli_get_client_info(),'mysqlnd'),'async_mysqlnd_required');
        self::ensure(preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)===1,'configuration_identifier');
        $o=(new ReflectionClass(self::class))->newInstanceWithoutConstructor();$o->acp=true;$o->deadline=microtime(true)+10;$o->acpConnect=$connect;
        $o->db=$connect();$id=$o->rows('SELECT DATABASE() AS db,@@server_uuid AS uuid')[0];
        self::ensure($id['uuid']===$serverUuid&&preg_match('/\A[a-zA-Z0-9_]+\z/D',$id['db'])===1,'source_identity_changed');
        $o->config=['database'=>$id['db'],'prefix'=>$prefix,'tables'=>self::ORDER];
        self::ensure(is_dir($dir)&&!is_link($dir)&&(fileperms($dir)&0077)===0,'private_state_directory_required');$o->dir=realpath($dir);
        $o->lock=fopen($dir.'/engine-lock.php','c+');chmod($dir.'/engine-lock.php',0600);self::ensure($o->lock!==false&&flock($o->lock,LOCK_EX|LOCK_NB),'tool_already_running');
        if(fstat($o->lock)['size']===0){fwrite($o->lock,self::ACP_HEADER);fflush($o->lock);}
        $limit=(int)ini_get('max_execution_time');self::ensure($limit===0||$limit>=15,'http_budget_too_small');$o->deadline=microtime(true)+10;
        $o->query('SET SESSION lock_wait_timeout=2');$o->query('SET SESSION innodb_lock_wait_timeout=2');return $o;
    }
    private function budget():void {if($this->acp)self::ensure(microtime(true)<$this->deadline,'http_budget_exhausted');}
    private static function rowHash(array $row):string
    {
        $h=hash_init('sha256');foreach($row as $v)hash_update($h,$v===null?'N;':'V'.strlen($v).':'.$v.';');return hash_final($h);
    }
    private mysqli $db;
    private array $config;
    private string $dir;
    private $lock;
    public function __construct(string $configFile,string $stateDir)
    {
        self::ensure(PHP_SAPI==='cli','cli_execution_required');
        umask(0077);
        $this->config=self::privateJson($configFile);
        $c=$this->config;
        foreach(['database','prefix'] as $key)self::ensure(is_string($c[$key]??null)&&preg_match('/\A[a-zA-Z0-9_]+\z/D',$c[$key])===1,'configuration_identifier');
        self::ensure(is_string($c['socket']??null)&&is_file($configFile),'configuration');
        $tables=$c['tables']??[];
        self::ensure(is_array($tables)&&array_is_list($tables)&&count($tables)===count(array_unique($tables))&&array_values(array_intersect(self::ORDER,$tables))===$tables,'table_allowlist_or_order');
        foreach(['forums','users','threads','posts'] as $required)self::ensure(in_array($required,$tables,true),'mandatory_table_missing');
        self::ensure(is_dir($stateDir)&&!is_link($stateDir)&&(fileperms($stateDir)&0077)===0,'private_state_directory_required');
        $this->dir=realpath($stateDir);
        $this->lock=fopen($this->dir.'/tool.lock','c');self::ensure($this->lock!==false&&flock($this->lock,LOCK_EX|LOCK_NB),'tool_already_running');
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $this->db=new mysqli('localhost',$c['user'],$c['password'],$c['database'],0,$c['socket']);
        $this->db->set_charset('utf8mb4');
        $this->db->query('SET SESSION lock_wait_timeout=10');
    }
    public static function ensure(bool $ok,string $code):void {if(!$ok)throw new RuntimeException($code);}
    public static function privateJson(string $path):array
    {
        self::ensure(is_file($path)&&!is_link($path)&&(fileperms($path)&0077)===0,'private_json_required');
        $v=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);self::ensure(is_array($v),'json_object_required');return $v;
    }
    private static function json(array $a):string {return json_encode($a,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function hash(array $a):string {return hash('sha256',self::json($a));}
    private function rows(string $sql):array {$this->budget();$q=$this->query($sql);return $q->fetch_all(MYSQLI_ASSOC);}
    private function q(string $v):string {return "'".$this->db->real_escape_string($v)."'";}
    private function table(string $logical):string {return '`'.$this->config['prefix'].$logical.'`';}
    private function identity():array
    {
        $r=$this->rows('SELECT VERSION() AS version,@@server_uuid AS uuid,DATABASE() AS db')[0];
        self::ensure(preg_match('/\A8\.0\.46(?:[-.]|$)/',$r['version'])===1,'requires_mysql_8_0_46');
        return $r+['prefix'=>$this->config['prefix']];
    }
    private function artifact(array $a):void
    {
        self::ensure(is_string($a['path']??null)&&is_file($a['path'])&&!is_link($a['path'])&&(fileperms($a['path'])&0077)===0&&filesize($a['path'])>0,'backup_artifact_unavailable');
        self::ensure(is_string($a['sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$a['sha256'])===1&&hash_equals($a['sha256'],hash_file('sha256',$a['path'])),'backup_artifact_hash');
    }
    private function maintenance():array
    {
        $this->identity();
        $r=$this->rows('SELECT @@performance_schema AS perf,@@global.offline_mode AS offline,@@global.read_only AS ro,@@global.super_read_only AS sro,@@global.event_scheduler AS scheduler,@@session.autocommit AS ac,@@datadir AS data_dir,@@tmpdir AS tmp_dir,@@innodb_tmpdir AS innodb_tmp')[0];
        self::ensure($r['perf']==='1'&&$r['offline']==='1'&&$r['ro']==='1'&&$r['sro']==='0'&&$r['scheduler']!=='ON'&&$r['ac']==='1','maintenance_server_gates');
        // Requires actual performance_schema access; unavailable evidence is a refusal.
        $others=$this->rows("SELECT THREAD_ID FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_USER IS NOT NULL AND PROCESSLIST_ID IS NOT NULL AND PROCESSLIST_ID<>CONNECTION_ID()");
        self::ensure($others===[],'parallel_database_connections');
        $lost=$this->rows("SHOW GLOBAL STATUS LIKE 'Performance_schema_thread_instances_lost'");
        self::ensure(count($lost)===1&&$lost[0]['Value']==='0','thread_inventory_incomplete');
        self::ensure($this->rows('SHOW REPLICA STATUS')===[],'replica_applier_not_supported');
        return $r;
    }
    private function preconditions():void
    {
        $r=$this->maintenance();
        foreach(['backup','restore_evidence','maintenance_evidence','staging_evidence'] as $key){self::ensure(isset($this->config[$key]),'evidence_required');$this->artifact($this->config[$key]);}
        $restore=self::privateJson($this->config['restore_evidence']['path']);
        $maintenance=self::privateJson($this->config['maintenance_evidence']['path']);
        $staging=self::privateJson($this->config['staging_evidence']['path']);
        self::ensure(($restore['backup_sha256']??null)===$this->config['backup']['sha256']&&($restore['result']??null)==='pass','restore_evidence_backup_binding');
        self::ensure(is_int($maintenance['freeze_started_at']??null)&&is_int($restore['backup_captured_at']??null)&&is_int($restore['verified_at']??null)
            &&$maintenance['freeze_started_at']>0&&$maintenance['freeze_started_at']<=$restore['backup_captured_at']&&$restore['backup_captured_at']<=$restore['verified_at']&&$restore['verified_at']<=time()
            &&($maintenance['writers_stopped']??null)===true,'evidence_cutoff_or_freeze');
        self::ensure(($staging['result']??null)==='pass'&&($staging['mysql_version']??null)==='8.0.46'&&($staging['tables']??null)===$this->config['tables'],'staging_evidence_scope');
        $spaces=$this->config['space']??[];self::ensure(is_array($spaces)&&count($spaces)>=3,'space_budget_required');$seen=[];$devices=[];
        foreach($spaces as $s){
            $path=realpath($s['path']??'');$min=$s['minimum_free_bytes']??null;
            self::ensure($path!==false&&is_int($min)&&$min>0,'space_budget_invalid');
            $stat=stat($path);$dev=(string)$stat['dev'];$devices[$dev]=($devices[$dev]??0)+$min;
            $free=disk_free_space($path);self::ensure($free!==false&&$free>=$devices[$dev],'insufficient_free_space');$seen[]=$path;
        }
        // Local socket and locally visible daemon paths required; no remote free-space guesses.
        foreach(array_filter([$r['data_dir'],$r['tmp_dir'],$r['innodb_tmp'],$this->dir,dirname($this->config['backup']['path'])]) as $path)self::ensure(realpath($path)!==false&&in_array(realpath($path),$seen,true),'unverified_server_storage_volume');
        $this->maintenance(); // Recheck after potentially long backup hashing/disk checks.
    }
    private static function normalizedDdl(string $ddl):string
    {
        // Normalize only SQL options outside quoted identifiers/literals.
        $out='';$plain='';$flush=static function()use(&$out,&$plain){
            $out.=preg_replace(['/ENGINE=(?:MyISAM|InnoDB)\\b/','/ AUTO_INCREMENT=\\d+\\b/'],['ENGINE=<approved>',''],$plain);$plain='';
        };
        for($i=0,$n=strlen($ddl);$i<$n;$i++){
            $ch=$ddl[$i];
            if(in_array($ch,["'",'"','`'],true)){
                $flush();$quote=$ch;$out.=$ch;
                while(++$i<$n){$out.=$ddl[$i];if($ddl[$i]==='\\'&&$i+1<$n){$out.=$ddl[++$i];continue;}
                    if($ddl[$i]===$quote){if($i+1<$n&&$ddl[$i+1]===$quote){$out.=$ddl[++$i];continue;}break;}}
            }else $plain.=$ch;
        }
        $flush();return $out;
    }
    private function metadata(string $name):array
    {
        $t=$this->config['prefix'].$name;$lit=$this->q($t);
        $r=$this->rows('SELECT ENGINE,AUTO_INCREMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.$lit);
        self::ensure(count($r)===1&&in_array($r[0]['ENGINE'],['MyISAM','InnoDB'],true),'unexpected_engine_or_missing_table');
        self::ensure(!str_contains(strtolower($r[0]['CREATE_OPTIONS']),'partition'),'partitioned_table_unsupported');
        $ddl=array_values($this->rows('SHOW CREATE TABLE '.$this->table($name))[0])[1];
        self::ensure(!self::schemaUnsupported($ddl),'unsupported_schema_feature');
        self::ensure($this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='.$lit)===[],'trigger_requires_separate_audit');
        // TRIGGER privilege is required even for an empty inventory; SHOW CREATE privilege check.
        $privs=$this->rows('SHOW GRANTS');$grantText=implode("\n",array_map(fn($r)=>implode(' ',$r),$privs));
        $schema='`'.str_replace('`','``',$this->config['database']).'`';$table='`'.str_replace('`','``',$t).'`';
        $scope='(?:\*\.\*|'.preg_quote($schema.'.*','/').'|'.preg_quote($schema.'.'.$table,'/').')';
        self::ensure(preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bTRIGGER\b[^\n]*) ON '.$scope.' TO /',$grantText)===1,'trigger_privilege_evidence_required');
        $cols=$this->rows('SHOW FULL COLUMNS FROM '.$this->table($name));
        $idx=$this->rows('SHOW INDEX FROM '.$this->table($name));
        foreach($idx as &$i){unset($i['Cardinality']);}unset($i);
        usort($idx,fn($a,$b)=>[$a['Key_name'],(int)$a['Seq_in_index']]<=>[$b['Key_name'],(int)$b['Seq_in_index']]);
        $normalized=self::normalizedDdl($ddl);
        return ['engine'=>$r[0]['ENGINE'],'auto'=>$r[0]['AUTO_INCREMENT'],'ddl'=>$normalized,'columns'=>$cols,'indexes'=>$idx,'collation'=>$r[0]['TABLE_COLLATION']];
    }
    /** Exact multiset digest, including duplicates/NULL; bounded RAM, private sorted row hashes. */
    private function digest(string $name,array $meta):array
    {
        if($this->acp){
            $hashes=[];$bytes=0;$fields=array_map(fn($c)=>'CAST(`'.str_replace('`','``',$c['Field']).'` AS BINARY)',$meta['columns']);
            $q=$this->query('SELECT '.implode(',',$fields).' FROM '.$this->table($name),MYSQLI_USE_RESULT);
            try{while($row=$q->fetch_row()){$this->budget();foreach($row as $v)$bytes+=strlen($v??'');self::ensure($bytes<=16777216&&count($hashes)<50000,'table_exceeds_http_limit');$hashes[]=self::rowHash($row);}}finally{$q->free();}
            sort($hashes,SORT_STRING);$h=hash_init('sha256');foreach($hashes as $v)hash_update($h,$v."\n");return ['count'=>count($hashes),'sha256'=>hash_final($h)];
        }
        self::ensure(is_executable('/usr/bin/sort'),'gnu_sort_required');
        $dir=$this->dir.'/scratch-'.bin2hex(random_bytes(8));mkdir($dir,0700);
        $raw=$dir.'/hashes';$sorted=$dir.'/sorted';$out=fopen($raw,'xb');$count=0;
        try {
            $fields=array_map(fn($c)=>'CAST(`'.str_replace('`','``',$c['Field']).'` AS BINARY)',$meta['columns']);
            $q=$this->query('SELECT '.implode(',',$fields).' FROM '.$this->table($name),MYSQLI_USE_RESULT);
            try {while($row=$q->fetch_row()){self::ensure(fwrite($out,self::rowHash($row)."\n")===65,'scratch_write_failed');$count++;}}
            finally {$q->free();fclose($out);$out=null;}
            $proc=proc_open(['/usr/bin/sort','--buffer-size=16M','--temporary-directory='.$dir,'-o',$sorted,$raw],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,['LC_ALL'=>'C']);
            self::ensure(is_resource($proc)&&proc_close($proc)===0,'digest_sort_failed');
            return ['count'=>$count,'sha256'=>hash_file('sha256',$sorted)];
        } finally {if(is_resource($out))fclose($out);foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);}
    }
    private function snapshot(string $name):array {$m=$this->metadata($name);return $m+['data'=>$this->digest($name,$m)];}
    private function file():string {return $this->dir.($this->acp?'/engine-state.php':'/state.json');}
    private function save(array $state):void
    {
        $tmp=$this->acp?$this->dir.'/engine-state-new.php':$this->file().'.tmp';$f=fopen($tmp,'wb');self::ensure($f!==false,'journal_unavailable');chmod($tmp,0600);
        try {if($this->acp){unset($state['journal_hash']);$state['journal_hash']=self::hash($state);}$v=($this->acp?self::ACP_HEADER:'').self::json($state);if($this->acp)self::ensure(strlen($v)<=8388608,'journal_too_large');self::ensure(fwrite($f,$v)===strlen($v)&&fflush($f)&&fsync($f),'journal_flush_failed');}finally{fclose($f);}
        self::ensure(rename($tmp,$this->file()),'journal_rename_failed');
        // Require durable directory entry before issuing DDL. Linux local filesystem only.
        $dir=fopen($this->dir,'r');try{self::ensure($dir!==false&&fsync($dir),'journal_directory_sync_failed');}finally{if(is_resource($dir))fclose($dir);}
    }
    private function state():array
    {
        $s=$this->acp?self::readAcpState($this->dir):self::privateJson($this->file());self::ensure(($s['format']??null)===1&&$s['baseline_hash']===self::hash($s['baseline']),'state_integrity');
        self::ensure($s['identity']===$this->identity()&&$s['tables']===$this->config['tables']&&$s['config_hash']===self::hash($this->config),'state_identity_or_scope');return $s;
    }
    private function compareStructure(array $before,array $after,string $engine):void
    {
        self::ensure($after['engine']===$engine,'engine_drift');
        foreach(['ddl','columns','indexes','collation'] as $k)self::ensure($before[$k]===$after[$k],'schema_or_data_drift_'.$k);
    }
    private function compare(array $before,array $after,string $engine):void
    {
        $this->compareStructure($before,$after,$engine);
        self::ensure($before['data']===$after['data'],'schema_or_data_drift_data');
        self::ensure(($before['auto']===null)===($after['auto']===null),'auto_increment_shape');
        if($before['auto']!==null)self::ensure($after['auto']===$before['auto'],'auto_increment_drift');
    }
    private function validate(array $s,?string $recovering=null):void
    {
        foreach($s['tables'] as $t){if($t===$recovering)continue;$expected=isset($s['completed'][$t])?'InnoDB':$s['baseline'][$t]['engine'];$this->compare($s['baseline'][$t],$this->snapshot($t),$expected);}
    }
    private function approval(string $file,array $s):void
    {
        $a=self::privateJson($file);
        self::ensure(($a['baseline_hash']??null)===$s['baseline_hash']&&($a['tables']??null)===$s['tables']&&($a['backup_sha256']??null)===$this->config['backup']['sha256'],'approval_scope_or_backup');
        self::ensure(is_int($a['expires_at']??null)&&$a['expires_at']>time()&&is_string($a['approved_by']??null)&&$a['approved_by']!=='','approval_expired_or_missing');
        foreach(['all_writers_stopped','same_host_storage_verified','backup_restore_tested','staging_passed'] as $k)self::ensure(($a[$k]??null)===true,'operator_attestation_required');
    }
    public function run(string $mode,?string $approval=null):array
    {
        self::ensure(PHP_SAPI==='cli','cli_execution_required');
        if($mode==='inspect'){$out=[];foreach($this->config['tables'] as $t)$out[$t]=$this->metadata($t);return ['identity'=>$this->identity(),'tables'=>$out,'notice'=>'Read-only schema observations, not approval or consistent baseline'];}
        if($mode==='capture'){
            self::ensure(!file_exists($this->file()),'state_exists');$this->preconditions();$b=[];foreach($this->config['tables'] as $t)$b[$t]=$this->snapshot($t);$this->preconditions();
            $s=['format'=>1,'config_hash'=>self::hash($this->config),'identity'=>$this->identity(),'tables'=>$this->config['tables'],'baseline'=>$b,'baseline_hash'=>self::hash($b),'backup_sha256'=>$this->config['backup']['sha256'],'completed'=>[],'intent'=>null,'history'=>[]];$this->save($s);return ['baseline_hash'=>$s['baseline_hash'],'tables'=>$s['tables'],'notice'=>'Create separate scoped approval; no DDL executed'];
        }
        $s=$this->state();
        if($mode==='verify'){self::ensure($s['intent']===null,'recovery_required');$this->validate($s);return ['verified'=>true,'completed'=>array_keys($s['completed']),'all_innodb'=>count(array_filter($s['baseline'],fn($b)=>$b['engine']==='MyISAM'))===count($s['completed']),'publication'=>'BLOCKED'];}
        self::ensure(in_array($mode,['apply-next','recover'],true)&&$approval!==null,'command_or_approval_required');
        $this->preconditions();$this->approval($approval,$s);self::ensure($s['backup_sha256']===$this->config['backup']['sha256'],'baseline_backup_changed');
        $key='acs_engine_'.substr(hash('sha256',self::json($s['identity'])),0,40);
        self::ensure($this->rows('SELECT GET_LOCK('.$this->q($key).',0) AS acquired')[0]['acquired']==='1','database_tool_mutex_busy');
        try {
            if($mode==='recover'){
                $t=$s['intent']['table']??null;self::ensure(in_array($t,$s['tables'],true),'no_recovery_intent');$this->validate($s,$t);$after=$this->snapshot($t);$this->compare($s['baseline'][$t],$after,$after['engine']);
                if($after['engine']==='InnoDB')$s['completed'][$t]=['recovered'=>true];
                $s['history'][]=['table'=>$t,'outcome'=>'recovered_'.$after['engine']];$s['intent']=null;$this->save($s);return ['recovered'=>$t,'engine'=>$after['engine'],'ddl_executed'=>false];
            }
            self::ensure($s['intent']===null,'recovery_required');$this->validate($s);$next=null;
            foreach($s['tables'] as $t)if($s['baseline'][$t]['engine']==='MyISAM'&&!isset($s['completed'][$t])){$next=$t;break;}
            if($next===null)return ['complete'=>true,'publication'=>'BLOCKED'];
            $this->preconditions();$this->approval($approval,$s);$s['intent']=['table'=>$next,'time'=>time()];$this->save($s);
            $auto=$s['baseline'][$next]['auto'];self::ensure($auto===null||preg_match('/\A[0-9]+\z/D',$auto)===1,'auto_increment_invalid');
            $sql='ALTER TABLE '.$this->table($next).' ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE'.($auto===null?'':', AUTO_INCREMENT='.$auto);
            // One statement only. No BEGIN/COMMIT/ROLLBACK, no automatic retry/fallback.
            $this->db->query($sql);
            $this->preconditions();$this->compare($s['baseline'][$next],$this->snapshot($next),'InnoDB');$this->validate($s,$next);
            $s['completed'][$next]=['recovered'=>false];$s['history'][]=['table'=>$next,'outcome'=>'converted'];$s['intent']=null;$this->save($s);
            return ['converted'=>$next,'notice'=>'Review result, then invoke apply-next explicitly for next table','publication'=>'BLOCKED'];
        }finally{$this->db->query('SELECT RELEASE_LOCK('.$this->q($key).')');}
    }
    public static function readAcpState(string $dir):?array
    {
        $p=$dir.'/engine-state.php';if(!file_exists($p))return null;
        self::ensure(!is_link($p)&&(fileperms($p)&0077)===0&&filesize($p)<=8388608,'state_integrity');
        $raw=file_get_contents($p);self::ensure(str_starts_with($raw,self::ACP_HEADER),'state_integrity');
        $s=json_decode(substr($raw,strlen(self::ACP_HEADER)),true,64,JSON_THROW_ON_ERROR);self::ensure(is_array($s),'state_integrity');$hash=$s['journal_hash']??null;unset($s['journal_hash']);self::ensure(is_string($hash)&&hash_equals($hash,self::hash($s)),'state_integrity');return $s;
    }
    public function closeAcp():void {if($this->acp){try{$this->db->close();}catch(Throwable $ignored){}if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);}}}
    private function acpCatalog():array
    {
        return array_column($this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"),'TABLE_NAME');
    }
    private function acpAcquire():void
    {
        $this->identity();
        $schema='`'.str_replace('`','``',$this->config['database']).'`';
        $grants=implode("\n",array_map(fn($r)=>implode(' ',$r),$this->rows('SHOW GRANTS')));
        self::ensure(preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bTRIGGER\b[^\n]*) ON (?:\*\.\*|'.preg_quote($schema.'.*','/').') TO /',$grants)===1,'trigger_privilege_evidence_required');
        self::ensure($this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')===[],'trigger_requires_separate_audit');
        self::ensure($this->rows('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA<>DATABASE()')===[],'cross_schema_dependency');
        $this->acpCatalog=$this->acpCatalog();self::ensure(count($this->acpCatalog)>0&&count($this->acpCatalog)<=512,'schema_too_large_for_http');
        self::ensure($this->rows('SHOW REPLICA STATUS')===[],'replica_applier_not_supported');
        $parts=array_map(fn($t)=>'`'.str_replace('`','``',$t).'` WRITE',$this->acpCatalog);
        // Physical DB-scoped write barrier; no global mode change or fictitious transaction.
        $this->query('LOCK TABLES '.implode(',',$parts));$this->acpLocksHeld=true;
        $this->acpPreconditions();
    }
    private function acpPreconditions():void
    {
        self::ensure($this->acpLocksHeld,'write_barrier_missing');
        self::ensure($this->acpCatalog()===$this->acpCatalog,'schema_catalog_changed');
        $v=$this->rows('SELECT @@hostname AS host,@@datadir AS datadir,@@tmpdir AS tmpdir')[0];
        self::ensure($v['host']===gethostname(),'database_not_local');
        foreach([$v['datadir'],$v['tmpdir'],$this->dir] as $path){$real=@realpath($path);$free=$real===false?false:@disk_free_space($real);self::ensure($free!==false&&$free>=268435456,'storage_unavailable_or_low');}
    }
    private function acpValidateSchemas(array $s,?string $except=null):void
    {
        foreach($s['tables'] as $t){if($t===$except)continue;$engine=isset($s['completed'][$t])?'InnoDB':$s['baseline'][$t]['engine'];$this->compareStructure($s['baseline'][$t],$this->metadata($t),$engine);}
    }
    /** One bounded ACP operation. Checkbox statement is recorded by the service, not a backup proof. */
    public function acpStep(bool $recover=false):array
    {
        self::ensure($this->acp,'acp_context_required');
        $key='acs_engine_'.substr(hash('sha256',self::json($this->identity())),0,40);
        self::ensure($this->rows('SELECT GET_LOCK('.$this->q($key).',0) AS acquired')[0]['acquired']==='1','database_tool_mutex_busy');
        try{
            // Read under the file lock + DB mutex BEFORE any permission preflight.
            // A pending or unreadable state always retains the recovery gate.
            $previous=file_exists($this->file())?$this->state():null;
            $this->acpDdlMayHaveStarted=$previous!==null&&$previous['intent']!==null;
            $this->acpAcquire();
            if(!file_exists($this->file())){
                if($recover)return ['no_ddl'=>true];$b=[];
                foreach(self::ORDER as $t){$size=$this->rows('SELECT DATA_LENGTH+INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.$this->q($this->config['prefix'].$t));self::ensure(isset($size[0]['bytes'])&&(float)$size[0]['bytes']<=16777216,'table_exceeds_http_limit');$b[$t]=$this->snapshot($t);}
                $s=['format'=>1,'config_hash'=>self::hash($this->config),'identity'=>$this->identity(),'tables'=>self::ORDER,'baseline'=>$b,'baseline_hash'=>self::hash($b),'completed'=>[],'intent'=>null,'history'=>[],'backup'=>'administrator_statement_only','catalog_hash'=>self::hash($this->acpCatalog)];$this->acpPreconditions();$this->save($s);
            }
            $s=$this->state();self::ensure(($s['catalog_hash']??null)===self::hash($this->acpCatalog),'schema_catalog_changed');
            if($recover){
                if($s['intent']!==null){$t=$s['intent']['table'];self::ensure(in_array($t,self::ORDER,true),'state_integrity');$this->acpValidateSchemas($s,$t);$before=$s['before'][$t]??$s['baseline'][$t];self::ensure(($s['intent']['before_hash']??self::hash($before))===self::hash($before),'state_integrity');$after=$this->snapshot($t);$this->compare($before,$after,$after['engine']);if($after['engine']==='InnoDB')$s['completed'][$t]=['recovered'=>true];$s['history'][]=['table'=>$t,'outcome'=>'recovered_'.$after['engine'],'at'=>time()];$s['intent']=null;$this->save($s);}
                else $this->acpValidateSchemas($s);
                return $s;
            }
            self::ensure($s['intent']===null,'recovery_required');$this->acpValidateSchemas($s);$next=null;
            foreach(self::ORDER as $t)if($s['baseline'][$t]['engine']==='MyISAM'&&!isset($s['completed'][$t])){$next=$t;break;}
            if($next===null)return $s;
            $this->acpPreconditions();$this->budget();$s['before'][$next]=$this->snapshot($next);$this->acpDdlMayHaveStarted=true;$s['intent']=['table'=>$next,'before_hash'=>self::hash($s['before'][$next]),'time'=>time(),'connection_id'=>$this->db->thread_id];$this->save($s);
            $auto=$s['before'][$next]['auto'];self::ensure($auto===null||preg_match('/\A[0-9]+\z/D',$auto)===1,'auto_increment_invalid');
            $sql='ALTER TABLE '.$this->table($next).' ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE'.($auto===null?'':', AUTO_INCREMENT='.$auto);
            $this->budget();$this->query($sql,MYSQLI_ASYNC);
            while(true){$read=[$this->db];$error=[];$reject=[];
                if(mysqli_poll($read,$error,$reject,0,20000)>0){try{$this->db->reap_async_query();}catch(mysqli_sql_exception $e){throw new AcsEngineMigrationSqlException('alter',(int)$e->getCode());}break;}
                if(microtime(true)>=$this->deadline){
                    try{$cancel=($this->acpConnect)();$cancel->query('KILL QUERY '.(int)$this->db->thread_id);$cancel->close();}catch(Throwable $ignored){}
                    throw new RuntimeException('ddl_outcome_unknown');
                }
            }
            $this->acpPreconditions();$this->compare($s['before'][$next],$this->snapshot($next),'InnoDB');$this->acpValidateSchemas($s,$next);
            $s['completed'][$next]=['recovered'=>false];$s['history'][]=['table'=>$next,'outcome'=>'converted','at'=>time()];$s['intent']=null;$this->save($s);return $s;
        }finally{try{if($this->acpLocksHeld){$this->db->query('UNLOCK TABLES');$this->acpLocksHeld=false;}$this->db->query('SELECT RELEASE_LOCK('.$this->q($key).')');}catch(Throwable $ignored){}}
    }

}
