<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP)die('No direct access');
require_once __DIR__.'/MigrationService.php';
/** Narrow authorized ACP orchestration. All DDL/integrity belongs to EngineMigration. */
final class AF_ACS_MigrationAcpExecution
{
    public function __construct(private object $db,private AF_ACS_MigrationJournal $journal,private string $directory){}
    public static function messages():array
    {
        $messages=[
            'private_state_directory_required'=>'PHP не может создать защищённый служебный журнал плагина. Попросите хостинг предоставить каталогу плагина возможность создать приватный подкаталог; состояния из формы не принимаются.',
            'source_identity_changed'=>'Соединение не соответствует исходной базе форума. Миграция запрещена до проверки конфигурации хостингом.',
            'unexpected_engine_or_missing_table'=>'Разрешённая таблица отсутствует или имеет неожиданный движок. Исправьте установку, не создавайте таблицу автоматически ради миграции.',
            'unsupported_schema_feature'=>'Схема содержит неподдержанный столбец, индекс или ограничение; требуется отдельная проверка хостинга, исходная структура не изменяется.',
            'partitioned_table_unsupported'=>'Секционированная таблица не поддерживается ограниченным ACP-режимом.',
            'engine_drift'=>'Движок изменился вне ожидаемой операции. Миграция остановлена; сначала проверьте фактический исход и журнал, не конвертируйте таблицу повторно.',
            'auto_increment_drift'=>'После операции счётчик AUTO_INCREMENT отличается от исходного. Последовательность остановлена; повторный ALTER запрещён до проверки.',
            'state_integrity'=>'Служебный журнал повреждён или недоступен. Не удаляйте его для обхода защиты; требуется восстановление и проверка администратором.',
            'database_tool_mutex_busy'=>'Другой CLI/ACP процесс уже держит блокировку миграции этой базы. Дождитесь его исхода.',
            'journal_too_large'=>'Структура базы слишком велика для ограниченного ACP-журнала (8 МиБ). Последовательность остановлена; требуется профессиональное обслуживание хостинга.',
            'journal_directory_sync_failed'=>'Файловая система не подтвердила долговременную запись журнала. ALTER не должен продолжаться без надёжного хранилища.',
            'durable_journal_unavailable'=>'PHP не предоставляет fsync для долговременной записи журнала. Без него безопасный ACP-запуск недоступен.',
            'http_memory_too_small'=>'Для ограниченного ACP-шага требуется не менее 64 МиБ свободного бюджета памяти PHP. Используйте поддерживаемое обслуживание хостинга, не обходите проверку.',
            'async_mysqlnd_required'=>'В PHP нет необходимого асинхронного MySQLnd API. Без него нельзя контролировать длительность ACP-шага; требуется поддерживаемая сборка PHP или управляемая операция хостинга.',
            'database_read_only'=>'MySQL запретил DDL из-за read_only. Мастер глобальные режимы не меняет: согласуйте служебное окно с владельцем сервера.',
            'forum_not_closed'=>'Сначала закройте форум через штатные настройки MyBB и остановите фоновые задания. Мастер дополнительно блокирует записи в таблицы БД на время каждого шага.',
            'database_connection_failed'=>'Не удалось создать или настроить служебное соединение MySQL. Проверьте доступ учётной записи форума и числовой код ошибки; это не доказательство отсутствия LOCK TABLES.',
            'database_permissions'=>'Старый журнал не сохранил конкретный запрос. Нажмите «Проверить готовность», затем «Проверить результат»: новый отказ будет содержать точную операцию. MySQL не разрешил необходимую служебную проверку или блокировку таблиц. Нужны LOCK TABLES в схеме и чтение сведений о репликации; обратитесь к хостингу. Права приложения автоматически не расширяются.',
            'schema_catalog_changed'=>'Набор таблиц БД изменился. Не продолжайте автоматическую последовательность; требуется проверка администратора.',
            'schema_too_large_for_http'=>'В базе больше 512 таблиц; этот ограниченный ACP-режим не поддерживает такой объём.',
            'backup_statement_required'=>'Подтвердите, что создали резервную копию базы данных.',
            'maintenance_server_gates'=>'MySQL не находится в исключительном обслуживании: нужны offline/read_only, остановленный event scheduler и разрешённая служебная запись. Закрытие MyBB этого не обеспечивает. Попросите владельца хостинга организовать окно; мастер глобальные настройки не меняет.',
            'parallel_database_connections'=>'Обнаружены другие подключения к MySQL. Остановите PHP/cron/внешние записи и другие административные сессии через хостинг. Не продолжайте при активных writers.',
            'database_not_local'=>'PHP и MySQL не подтверждены как один локальный сервер. Без этого нельзя достоверно проверить файловые системы и ограниченный ACP-запуск; требуется управляемая операция хостинга.',
            'local_connection_required'=>'Для ACP-исполнения требуется подтверждённое одно локальное MySQLi-соединение через Unix socket. Раздельные/удалённые подключения не поддерживаются.',
            'storage_unavailable_or_low'=>'Каталог данных/временных файлов недоступен PHP либо на нём менее 256 МиБ свободного места. Попросите хостинг проверить реальные диски; мастер не считает PHP temp диском БД.',
            'table_exceeds_http_limit'=>'Таблица превышает ограничение ACP: 16 МиБ данных/индексов или 50 000 строк. Используйте контролируемую операцию хостинга с тем же EngineMigration; автоматического обхода нет.',
            'http_budget_too_small'=>'Лимит PHP слишком мал для служебного ACP-шага (требуется не менее 15 секунд). Увеличение лимита само по себе не разрешает миграцию: остальные проверки обязательны.',
            'http_budget_exhausted'=>'Истёк бюджет ACP-проверки. Последовательность остановлена. Если есть намерение DDL, сначала нажмите «Проверить результат», не повторяйте ALTER.',
            'ddl_outcome_unknown'=>'Время шага истекло, запрошено прекращение запроса MySQL. Исход неизвестен: операция/откат могли продолжаться. Не снимайте обслуживание; сначала проверьте результат.',
            'recovery_required'=>'Есть незавершённое намерение. Сначала проверьте фактический результат; повтор ALTER запрещён.',
            'tool_already_running'=>'Служебная операция ещё выполняется. Дождитесь завершения и обновите страницу; второй запуск запрещён.',
            'trigger_privilege_evidence_required'=>'Недостаточно прав, чтобы доказать полноту проверки triggers. Нужен согласованный служебный доступ хостинга; права веб-приложения автоматически не расширяются.',
            'cross_schema_dependency'=>'Найдена связь с другой базой данных. Чтобы не затрагивать чужую схему блокировками, нужен отдельный план хостинга.',
            'trigger_requires_separate_audit'=>'Обнаружен trigger выбранной таблицы. До отдельной проверки его записей автоматическая миграция запрещена.',
            'thread_inventory_incomplete'=>'Нельзя доказать отсутствие других writers: системный список подключений неполон.',
            'replica_applier_not_supported'=>'Обнаружено применение репликации. Нужен отдельный план владельца MySQL.',
            'requires_mysql_8_0_46'=>'Этот режим проверен для MySQL 8.0.46; другая версия требует отдельной проверки.',
            'state_identity_or_scope'=>'Сервер или список таблиц изменился относительно журнала. Не создавайте новый журнал для обхода; требуется проверка администратора.',
            'database_operation_failed'=>'MySQL отказал в операции. Последовательность остановлена; проверьте результат перед продолжением. Код ошибки доступен в закрытом серверном журнале.',
        ];
        foreach(self::accessRequirements() as $op=>$r){
            $messages['database_'.$op.'_denied']='MySQL отказал в доступе: '.$r['operation'].'. '.$r['requirement'].' Попросите хостинг проверить это разрешение для учётной записи форума; мастер права не меняет.';
            $messages['database_'.$op.'_failed']='Не выполнена SQL-операция: '.$r['operation'].'. Это не обязательно ошибка прав; проверьте числовой код MySQL в технических подробностях.';
        }
        $messages['trigger_privilege_evidence_required']='Не доказана полнота списка triggers. Проверки: SHOW GRANTS и SELECT из information_schema.TRIGGERS. Нужен прямой TRIGGER на схему форума (либо подтверждённый глобальный grant); роль/права на отдельные таблицы этот строгий режим не подтверждает. Попросите хостинг проверить доступ; права автоматически не меняются.';
        return $messages;
    }
    public static function accessRequirements():array
    {
        return [
            'connection'=>['operation'=>'Открытие служебного соединения MySQLi','requirement'=>'Проверьте доступ учётной записи форума к той же базе через локальный Unix socket и числовой код отказа.'],
            'replication_status'=>['operation'=>'SHOW REPLICA STATUS','requirement'=>'На MySQL 8.0.46 требуется REPLICATION CLIENT ON *.*. SUPER не требуется.'],
            'lock_tables'=>['operation'=>'LOCK TABLES … WRITE','requirement'=>'Нужны LOCK TABLES на схему форума и SELECT на все её базовые таблицы. Read-only диагностика не берёт блокировки; окончательная проверка выполняется при запуске.'],
            'grants'=>['operation'=>'SHOW GRANTS (собственные права)','requirement'=>'Проверка собственных прав не требует SELECT на mysql.*; проверьте ограничения прокси/хостинга.'],
            'triggers'=>['operation'=>'SELECT из information_schema.TRIGGERS','requirement'=>'Для полного списка нужен прямой TRIGGER на схему форума; пустой список без права не доказывает отсутствие triggers.'],
            'foreign_keys'=>['operation'=>'SELECT из information_schema.REFERENTIAL_CONSTRAINTS','requirement'=>'Нужна видимость metadata ограничений схемы; хостинг должен проверить доступ и связи с другими схемами.'],
            'table_catalog'=>['operation'=>'SELECT из information_schema.TABLES','requirement'=>'Нужна видимость всех базовых таблиц схемы; проверяется доступ собственной учётной записи.'],
            'table_definition'=>['operation'=>'SHOW CREATE TABLE','requirement'=>'Нужен доступ к определению выбранной таблицы; проверьте SELECT и ограничения metadata.'],
            'table_columns'=>['operation'=>'SHOW FULL COLUMNS','requirement'=>'Нужна видимость всех столбцов выбранной таблицы, включая SELECT.'],
            'table_indexes'=>['operation'=>'SHOW INDEX','requirement'=>'Нужен доступ к индексам выбранной таблицы, включая SELECT.'],
            'table_data'=>['operation'=>'SELECT данных выбранной таблицы','requirement'=>'Нужен SELECT на преобразуемую таблицу для проверки целостности.'],
            'alter'=>['operation'=>'ALTER TABLE … ENGINE=InnoDB','requirement'=>'На MySQL 8.0.46 нужны ALTER, CREATE и INSERT на преобразуемую таблицу. Если intent уже записан, сначала выполните «Проверить результат».'],
            'migration_mutex'=>['operation'=>'SELECT GET_LOCK(…, 0)','requirement'=>'Нужна доступность штатной именованной блокировки; дополнительные глобальные права обычно не требуются.'],
            'session_setup'=>['operation'=>'SET SESSION lock_wait_timeout / innodb_lock_wait_timeout','requirement'=>'Проверьте ограничения изменения этих параметров собственной сессии; глобальные настройки мастер не меняет.'],
            'server_metadata'=>['operation'=>'SELECT DATABASE(), @@server_uuid и служебных переменных','requirement'=>'Нужно чтение identity и служебных переменных собственного сервера; SQL credentials не экспортируются.'],
        ];
    }
    private function connector():array
    {
        global $config;
        if(!isset($this->db->read_link,$this->db->write_link)||!($this->db->write_link instanceof mysqli)||$this->db->read_link!==$this->db->write_link
            ||!str_contains($this->db->write_link->host_info,'UNIX socket')||($config['database']['hostname']??null)!=='localhost')throw new RuntimeException('local_connection_required');
        $c=$config['database'];$web=$this->db->write_link;$source=$web->query('SELECT @@server_uuid AS uuid,DATABASE() AS db')->fetch_assoc();if(($c['database']??null)!==$source['db'])throw new RuntimeException('source_identity_changed');$uuid=$source['uuid'];
        $connect=static function()use($c){
            mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$m=mysqli_init();$m->options(MYSQLI_OPT_CONNECT_TIMEOUT,2);$m->options(MYSQLI_OPT_READ_TIMEOUT,2);
            $m->real_connect('localhost',$c['username'],$c['password'],$c['database'],0,ini_get('mysqli.default_socket')?:null);$m->set_charset('utf8mb4');return $m;
        };
        return [$connect,$uuid];
    }
    public function status():?array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $s=AcsEngineMigration::readAcpState($this->directory);if(!$s)return null;
        if(!isset($this->db->write_link)||!($this->db->write_link instanceof mysqli))throw new RuntimeException('local_connection_required');
        $source=$this->db->write_link->query('SELECT @@server_uuid AS uuid,DATABASE() AS db')->fetch_assoc();
        if(($s['identity']['uuid']??null)!==$source['uuid']||($s['identity']['db']??null)!==$source['db']||($s['identity']['prefix']??null)!==$this->db->table_prefix)throw new RuntimeException('state_identity_or_scope');
        $tables=[];$done=0;$total=0;
        foreach(AcsEngineMigration::ORDER as $t){$b=$s['baseline'][$t]??null;if(!is_array($b))continue;$needs=$b['engine']==='MyISAM';if($needs)$total++;
            $verified=isset($s['completed'][$t]);if($needs&&$verified)$done++;
            $pending=($s['intent']['table']??null)===$t;
            $tables[$t]=['engine'=>$pending?null:($verified?'InnoDB':$b['engine']),'verified'=>!$pending&&($verified||!$needs),'pending'=>$pending];}
        $history=[];foreach($s['history']??[] as $entry)if(in_array($entry['table']??null,AcsEngineMigration::ORDER,true)&&in_array($entry['outcome']??null,['converted','recovered_InnoDB','recovered_MyISAM'],true))$history[]=['table'=>$entry['table'],'outcome'=>$entry['outcome'],'at'=>isset($entry['at'])?(int)$entry['at']:null];
        return ['history'=>$history,'done'=>$done,'total'=>$total,'tables'=>$tables,'current'=>$s['intent']['table']??null,'unknown'=>$s['intent']!==null,'complete'=>$s['intent']===null&&$done===$total];
    }
    public function command(array $in):array
    {
        global $mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.migration.manage');
        if($mybb->request_method!=='post'||!is_string($in['csrf']??null)||!verify_post_check($in['csrf']??'',true))throw new RuntimeException('csrf_required');
        if(empty($mybb->settings['boardclosed']))throw new RuntimeException('forum_not_closed');
        $op=$in['op']??'';if(!in_array($op,['begin','next','recover'],true))throw new RuntimeException('unknown_command');
        if($op==='begin'&&($in['backup']??'')!=='1')throw new RuntimeException('backup_statement_required');
        $key=$in['key']??'';$revision=$in['revision']??null;if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!is_int($revision))throw new RuntimeException('invalid_request');
        $claimed=false;$hash=hash('sha256',json_encode([$op,(int)$mybb->user['uid'],$in['backup']??'']));
        $state=$this->journal->command($key,$hash,$revision,function($s)use($op,$key,$in,&$claimed){
            $progress=$this->status();
            if(($progress&&$progress['complete'])||($s['job']['status']??null)==='completed')throw new RuntimeException('migration_already_complete');
            if($op!=='begin'&&empty($s['job']['backup_statement'])&&!($op==='recover'&&($in['backup']??'')==='1'))throw new RuntimeException('backup_statement_required');
            if($op!=='recover'&&(($progress['unknown']??false)||in_array($s['job']['status']??null,['running','recovery_required'],true)))throw new RuntimeException('recovery_required');
            if($op==='begin'&&$s['job']!==null)throw new RuntimeException('unfinished_operation');
            if($op==='begin'){
                $data=AcsEngineMigration::readOnlyReport(function($sql){$q=$this->db->query($sql,1);if(!$q)throw new RuntimeException('database_operation_failed');$rows=[];while($r=$this->db->fetch_array($q))$rows[]=$r;return $rows;},(string)$this->db->table_prefix);
                if(count(array_filter($data['tables'],fn($r)=>$r['engine']==='InnoDB'))===count(AcsEngineMigration::ORDER)){$s['job']=['id'=>bin2hex(random_bytes(16)),'status'=>'completed','no_change'=>true,'updated_at'=>time()];$s['report']=['checked_at'=>time(),'database'=>$data];return $s;}
            }
            $claimed=true;$s['job']=['id'=>$s['job']['id']??bin2hex(random_bytes(16)),'status'=>'running','backup_statement'=>true,'statement_by'=>(int)$GLOBALS['mybb']->user['uid'],'request'=>$key,'updated_at'=>time()];return $s;
        });
        if(!$claimed)return $state;
        $engine=null;$failure=null;$errno=null;$operation=null;$beforeDdl=false;
        try{
            [$connect,$uuid]=$this->connector();
            if(!is_dir($this->directory)&&!@mkdir($this->directory,0700,true))throw new RuntimeException('private_state_directory_required');
            $engine=AcsEngineMigration::forAcp($connect,(string)$this->db->table_prefix,$this->directory,$uuid);
            $engine->acpStep($op==='recover');
        }catch(Throwable $e){$errno=(int)$e->getCode()?:null;$operation=$e instanceof AcsEngineMigrationSqlException?$e->operation:($e instanceof mysqli_sql_exception?'connection':null);
            $beforeDdl=$engine!==null&&$engine->acpFailureIsBeforeDdl();
            $failure=$e instanceof mysqli_sql_exception?'database_connection_failed':$e->getMessage();
            if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$failure))$failure='database_operation_failed';
            error_log('[ACS ACP migration] '.$failure.' errno='.(int)$e->getCode());
        }finally{if($engine)$engine->closeAcp();}
        $latest=$this->journal->read();$jobId=$state['job']['id'];
        return $this->journal->command(bin2hex(random_bytes(16)),hash('sha256',$key.'receipt'),$latest['revision'],function($s)use($failure,$jobId,$key,$beforeDdl,$errno,$operation){
            if(($s['job']['id']??null)!==$jobId)throw new RuntimeException('unfinished_operation');
            if(($s['job']['request']??null)!==$key)return $s;
            $progress=$this->status();$s['job']['status']=$failure!==null?($beforeDdl?'ready':'recovery_required'):(($progress['complete']??false)?'completed':'ready');
            $s['job']['error']=$failure;$s['job']['error_operation']=$operation;$s['job']['mysql_errno']=$errno;$s['job']['failure_phase']=$failure===null?null:($beforeDdl?'before_ddl':'recovery_required');$s['job']['updated_at']=time();$s['events'][]=['at'=>time(),'operation'=>'migration_step','status'=>$s['job']['status'],'error'=>$failure,'sql_operation'=>$operation,'mysql_errno'=>$errno,'failure_phase'=>$s['job']['failure_phase']];return $s;
        });
    }
}
