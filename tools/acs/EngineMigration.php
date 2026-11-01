<?php
declare(strict_types=1);

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
    private static function schemaUnsupported(string $ddl):bool
    {
        return preg_match('/\b(?:FOREIGN KEY|GENERATED ALWAYS|SPATIAL KEY|PARTITION BY|ROW_FORMAT=(?:FIXED|COMPRESSED|REDUNDANT))\b/i',$ddl)===1;
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
    private function rows(string $sql):array {$q=$this->db->query($sql);return $q->fetch_all(MYSQLI_ASSOC);}
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
        self::ensure(preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bTRIGGER\b[^\n]*) ON \*\.\* TO /',$grantText)===1,'trigger_privilege_evidence_required');
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
        self::ensure(is_executable('/usr/bin/sort'),'gnu_sort_required');
        $dir=$this->dir.'/scratch-'.bin2hex(random_bytes(8));mkdir($dir,0700);
        $raw=$dir.'/hashes';$sorted=$dir.'/sorted';$out=fopen($raw,'xb');$count=0;
        try {
            $fields=array_map(fn($c)=>'CAST(`'.str_replace('`','``',$c['Field']).'` AS BINARY)',$meta['columns']);
            $q=$this->db->query('SELECT '.implode(',',$fields).' FROM '.$this->table($name),MYSQLI_USE_RESULT);
            try {while($row=$q->fetch_row()){$h=hash_init('sha256');foreach($row as $v)hash_update($h,$v===null?'N;':'V'.strlen($v).':'.$v.';');self::ensure(fwrite($out,hash_final($h)."\n")===65,'scratch_write_failed');$count++;}}
            finally {$q->free();fclose($out);$out=null;}
            $proc=proc_open(['/usr/bin/sort','--buffer-size=16M','--temporary-directory='.$dir,'-o',$sorted,$raw],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,['LC_ALL'=>'C']);
            self::ensure(is_resource($proc)&&proc_close($proc)===0,'digest_sort_failed');
            return ['count'=>$count,'sha256'=>hash_file('sha256',$sorted)];
        } finally {if(is_resource($out))fclose($out);foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);}
    }
    private function snapshot(string $name):array {$m=$this->metadata($name);return $m+['data'=>$this->digest($name,$m)];}
    private function file():string {return $this->dir.'/state.json';}
    private function save(array $state):void
    {
        $tmp=$this->file().'.tmp';$f=fopen($tmp,'wb');self::ensure($f!==false,'journal_unavailable');
        try {$v=self::json($state);self::ensure(fwrite($f,$v)===strlen($v)&&fflush($f)&&fsync($f),'journal_flush_failed');}finally{fclose($f);}
        self::ensure(rename($tmp,$this->file()),'journal_rename_failed');
        // Require durable directory entry before issuing DDL. Linux local filesystem only.
        $dir=fopen($this->dir,'r');try{self::ensure($dir!==false&&fsync($dir),'journal_directory_sync_failed');}finally{if(is_resource($dir))fclose($dir);}
    }
    private function state():array
    {
        $s=self::privateJson($this->file());self::ensure(($s['format']??null)===1&&$s['baseline_hash']===self::hash($s['baseline']),'state_integrity');
        self::ensure($s['identity']===$this->identity()&&$s['tables']===$this->config['tables']&&$s['config_hash']===self::hash($this->config),'state_identity_or_scope');return $s;
    }
    private function compare(array $before,array $after,string $engine):void
    {
        self::ensure($after['engine']===$engine,'engine_drift');
        foreach(['ddl','columns','indexes','collation','data'] as $k)self::ensure($before[$k]===$after[$k],'schema_or_data_drift_'.$k);
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
}
