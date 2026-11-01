<?php
if(!defined('IN_MYBB'))die('No direct access');
require_once __DIR__.'/../../Application/AuthorizationService.php';
require_once __DIR__.'/MigrationJournal.php';
require_once __DIR__.'/BackgroundWorker.php';
require_once __DIR__.'/RuntimeAssessment.php';
// Shared EngineMigration implementation; DDL is restricted to the trusted ACP adapter.
require_once dirname(__DIR__,8).'/tools/acs/EngineMigration.php';
final class AF_ACS_MigrationService
{
    public const LABELS=['forums'=>'Форумы','users'=>'Пользователи','threads'=>'Темы','posts'=>'Сообщения','threadsubscriptions'=>'Подписки на темы','mailqueue'=>'Очередь писем','moderatorlog'=>'Журнал модерации','datacache'=>'Кэш форума','privatemessages'=>'Личные сообщения','attachments'=>'Вложения'];
    public function __construct(private object $db,private AF_ACS_MigrationJournal $journal,private AF_ACS_MigrationBackgroundWorker $worker){}
    private function select(string $sql):array
    {
        try{$q=$this->db->query($sql,1);if(!$q)throw new RuntimeException('database_unavailable');$r=[];while($v=$this->db->fetch_array($q))$r[]=$v;return $r;}
        catch(Throwable $e){throw new RuntimeException('database_unavailable');}
    }
    public function state():array {(new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');return $this->journal->read();}
    public function diagnose(array $selected,array $evidence=[]):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $access=AcsEngineMigration::readOnlyAccess(function($sql){
            // Preserve numeric SQL errors, never raw error text or SHOW GRANTS output.
            if(isset($this->db->write_link)&&$this->db->write_link instanceof mysqli){
                $q=$this->db->write_link->query($sql);if(!$q)throw new RuntimeException('access_probe_failed',$this->db->write_link->errno);return $q->fetch_all(MYSQLI_ASSOC);
            }
            return $this->select($sql);
        },(string)$this->db->table_prefix);
        try{$data=AcsEngineMigration::readOnlyReport(fn($q)=>$this->select($q),(string)$this->db->table_prefix);}
        catch(Throwable $e){$data=['version'=>null,'tables'=>array_fill_keys(AcsEngineMigration::ORDER,['engine'=>null,'bytes'=>null,'schema'=>'NOT_VERIFIED'])];}
        $issues=[];
        if(!is_string($data['version'])||!preg_match('/\A8\.0\.46(?:[-.]|$)/',$data['version']))$issues[]='mysql_version';
        foreach($selected as $t){$r=$data['tables'][$t];if(!$r['engine']||!in_array($r['engine'],['MyISAM','InnoDB'],true))$issues[]='table_unavailable';if($r['schema']!=='PRELIMINARY')$issues[]='schema_unverified';}
        if(!isset($evidence['backup']))$issues[]='backup_missing';
        if(($evidence['backup']['result']??null)!=='passed')$issues[]='restore_unverified';
        if(($evidence['staging']['result']??null)!=='passed'||($evidence['staging']['scope']??null)!==$selected)$issues[]='staging_missing';
        foreach(['maintenance_unverified','space_unverified','engine_journal_unverified','http_not_proven'] as $c)$issues[]=$c;
        $runtime=AF_ACS_MigrationRuntimeAssessment::collect(fn($q)=>$this->select($q),$this->db);
        if(($runtime['visible_other_connections']??0)>0)$issues[]='active_connections';
        if(($runtime['enabled_tasks']??0)>0)$issues[]='tasks_need_review';
        return ['checked_at'=>time(),'database'=>$data,'access'=>$access,'selected'=>$selected,'status'=>'BLOCKED','issues'=>array_values(array_unique($issues)),'worker'=>$this->worker->status(),
            'backup'=>isset($evidence['backup'])?'RECORDED':'MISSING','restore'=>$evidence['backup']['result']??'NOT_VERIFIED','staging'=>$evidence['staging']['result']??'MISSING','runtime'=>$runtime,'space'=>'NOT_VERIFIED','maintenance'=>'NOT_VERIFIED','engine_journal'=>'NOT_VERIFIED','publication'=>'BLOCKED'];
    }
    public function command(array $in):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        global $mybb;
        if($mybb->request_method!=='post'||!is_string($in['csrf']??null)||!verify_post_check($in['csrf'],true))throw new RuntimeException('csrf_required');
        $op=$in['op']??'';if(!in_array($op,['check','prepare','start','register_backup','register_staging'],true))throw new RuntimeException('unknown_command');
        if($op!=='check')(new AF_ACS_AuthorizationService)->requirePermission('admin.migration.manage');
        $selected=$in['tables']??[];
        if(!is_array($selected)||array_filter($selected,fn($t)=>!is_string($t))||count($selected)!==count(array_unique($selected))||array_diff($selected,AcsEngineMigration::ORDER))throw new RuntimeException('table_scope');
        $selected=array_values(array_intersect(AcsEngineMigration::ORDER,$selected));foreach(['forums','users','threads','posts'] as $t)if(!in_array($t,$selected,true))throw new RuntimeException('table_scope');
        if(($in['conditional_confirmed']??'')!=='1'&&array_diff($selected,['forums','users','threads','posts']))throw new RuntimeException('conditional_scope_unverified');
        $key=$in['key']??'';$version=$in['revision']??null;
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!is_int($version)||$version<0)throw new RuntimeException('invalid_request');
        if($op==='start'&&($in['phrase']??'')!=='ПОДТВЕРЖДАЮ МИГРАЦИЮ')throw new RuntimeException('confirmation_required');
        $record=null;
        if(in_array($op,['register_backup','register_staging'],true)){
            $v=$in['evidence']??[];
            $date=static function($value){if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$value))throw new RuntimeException('evidence_invalid');$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d\TH:i')!==$value||$d->getTimestamp()<=0||$d->getTimestamp()>time())throw new RuntimeException('evidence_invalid');return $d->getTimestamp();};
            $at=$date($v['date']??null);
            $result=$v['result']??'';if($op==='register_backup'&&($result===''||$result===null))$result='not_checked';
            $unchecked=$op==='register_backup'&&$result==='not_checked';
            $verified=$unchecked?null:$date($v['verified']??null);
            $method=$unchecked?'':($v['method']??null);
            if((!$unchecked&&$at>$verified)||!in_array($result,$op==='register_backup'?['not_checked','passed','failed']:['passed','failed'],true)||!is_string($v['reference']??null)||!preg_match('/\A[\p{L}\p{N} _.-]{3,100}\z/uD',$v['reference'])||(!$unchecked&&(!is_string($method)||mb_strlen(trim($method))<20||mb_strlen($method)>800||preg_match('/[<>\x00-\x1f]/u',$method))))throw new RuntimeException('evidence_invalid');
            $record=['created_at'=>$at,'verified_at'=>$verified,'result'=>$result,'reference'=>$v['reference'],'method'=>trim($method),'source'=>'administrator_statement','registered_at'=>time(),'registered_by'=>(int)$mybb->user['uid']];
            if($op==='register_backup'){if(!is_string($v['checksum']??null)||!preg_match('/\A[a-fA-F0-9]{64}\z/D',$v['checksum']))throw new RuntimeException('evidence_invalid');$record['sha256']=strtolower($v['checksum']);}
            else $record['scope']=$selected;
        }
        $identityRecord=$record;if($identityRecord!==null)unset($identityRecord['registered_at']);
        $hash=hash('sha256',json_encode([$op,$selected,$in['phrase']??'',$identityRecord,(int)$mybb->user['uid']],JSON_THROW_ON_ERROR));
        return $this->journal->command($key,$hash,$version,function($s)use($op,$selected,$record){
            if($s['job']!==null&&$op!=='check')throw new RuntimeException('unfinished_operation');
            // An unavailable broker cannot create an executable job or approval/config secrets.
            $s['evidence']=$s['evidence']??[];
            if($record!==null){$kind=$op==='register_backup'?'backup':'staging';$s['evidence_history']=$s['evidence_history']??[];if(count($s['evidence_history'])>=30)throw new RuntimeException('journal_review_required');$s['evidence_history'][]=['kind'=>$kind,'record'=>$record];$s['evidence'][$kind]=$record;}
            $s['selected']=$selected;$s['report']=$this->diagnose($selected,$s['evidence']);
            if($s['job']!==null)$s['report']['issues'][]='unfinished_operation';
            $s['events'][]=['at'=>time(),'operation'=>$op,'status'=>$record!==null?'RECORDED':'BLOCKED'];return $s;
        });
    }
    public static function messages():array
    {
        return ['active_connections'=>'Обнаружены другие пользовательские DB-подключения. Они могут писать; требуется исключительное окно обслуживания и проверка хостингом.',
        'tasks_need_review'=>'Есть включённые MyBB tasks. Их число не доказывает исполнение, но расписание/cron и внешние задания нужно проверить перед миграцией.',
        'http_not_proven'=>'Выполнение ALTER из ACP не доказано безопасным: нет верхней границы времени DDL, проверок данных и восстановления после разрыва HTTP.',
        'evidence_invalid'=>'Проверьте дату архива UTC и SHA-256. Для выполненной проверки обязательны её дата и описание (от 20 символов). Если восстановление ещё не выполнялось, выберите «Не проверено».',
        'backup_missing'=>'Зарегистрируйте полную резервную копию и результат её восстановления через форму ниже.',
        'restore_unverified'=>'Успешное тестовое восстановление не зарегистрировано. Восстановите копию в отдельную базу через хостинг и укажите результат.',
        'staging_missing'=>'Нет подтверждения теста на изолированной копии установленного форума. Synthetic fixture его не заменяет.',
        'maintenance_unverified'=>'Не удалось доказать безопасную остановку записи: PHP, cron, tasks, mail/jobs и привилегированные DB connections требуют общего maintenance window.',
        'space_unverified'=>'Свободное место на серверных data/temp/backup volumes не подтверждено. Нужны локальные измерения и бюджет по staging.',
        'worker_unavailable'=>'Альтернативный автономный исполнитель не подключён; он потребуется, если нельзя доказать безопасную длительность одной операции в ACP.',
        'engine_journal_unverified'=>'Наличие незавершённого EngineMigration intent не проверено: нет защищённого соединения с серверным журналом. Не запускайте повторную миграцию.',
        'schema_unverified'=>'Структура таблицы недоступна или требует отдельного аудита.',
        'mysql_version'=>'Требуется проверка совместимости с целевым MySQL 8.0.46.',
        'table_unavailable'=>'Необходимая таблица отсутствует либо использует неожиданный движок.',
        'database_unavailable'=>'База данных недоступна для безопасной диагностики.',
        'journal_unavailable'=>'Не удалось сохранить защищённый журнал подготовки. Запуск недоступен.',
        'version_conflict'=>'Состояние изменилось в другой вкладке. Обновите страницу.',
        'request_conflict'=>'Повтор запроса отличается от исходного. Обновите страницу.',
        'journal_review_required'=>'Требуется проверка журнала администратором; очистка через мастер запрещена.',
        'unfinished_operation'=>'Обнаружена незавершённая операция. Требуется диагностика администратора, повторный запуск запрещён.',
        'csrf_required'=>'Срок защиты формы истёк. Обновите страницу.',
        'table_scope'=>'Список таблиц недопустим. Обязательное ядро нельзя исключать.',
        'conditional_scope_unverified'=>'Дополнительные таблицы разрешено включать только по подтверждённой ветке публикации. Это подтверждение выбора не доказывает backup или готовность worker.',
        'confirmation_required'=>'Введите контрольную фразу и подтвердите список таблиц.',
        'ACS_ADMIN_PERMISSION_REQUIRED'=>'Недостаточно административных прав.',
        ];
    }
    private function safeRuntime(array $r):array
    {
        $out=['php_version'=>is_string($r['php_version']??null)&&preg_match('/\A[0-9][a-zA-Z0-9. +_\-]{1,50}\z/D',$r['php_version'])?$r['php_version']:null];
        foreach(['php_limit_seconds','visible_other_connections','enabled_tasks'] as $k)$out[$k]=is_int($r[$k]??null)&&$r[$k]>=0?$r[$k]:null;
        foreach(['board_closed','offline_mode'] as $k)$out[$k]=is_bool($r[$k]??null)?$r[$k]:null;
        $out['event_scheduler']=in_array($r['event_scheduler']??null,['ON','OFF','DISABLED'],true)?$r['event_scheduler']:null;
        $out['space']=[];foreach(['data','temporary','innodb_temporary','php_temporary'] as $k){$v=$r['space'][$k]??[];$out['space'][$k]=['free_bytes'=>is_int($v['free_bytes']??null)?$v['free_bytes']:null,'status'=>in_array($v['status']??null,['OBSERVED_NOT_PROVEN','UNAVAILABLE','PHP_FILESYSTEM_ONLY'],true)?$v['status']:'UNAVAILABLE'];}
        return $out;
    }
    public static function safeAccess(array $raw):array
    {
        $keys=['replication_status','triggers','foreign_keys','table_catalog','grants','server_metadata','grant_lock_tables','grant_select','grant_alter','grant_create','grant_insert','grant_trigger'];
        foreach(AcsEngineMigration::ORDER as $t){$keys[]='metadata_'.$t;$keys[]='select_'.$t;}
        $out=[];foreach($keys as $k)if(isset($raw[$k])&&is_array($raw[$k]))$out[$k]=['status'=>in_array($raw[$k]['status']??null,['PASS','DIRECT_GRANT','DENIED','NOT_VERIFIED'],true)?$raw[$k]['status']:'NOT_VERIFIED','errno'=>is_int($raw[$k]['errno']??null)?$raw[$k]['errno']:null];return $out;
    }
    public function export(array $state):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $report=null;$raw=$state['report']??null;
        if(is_array($raw)){
            $version=$raw['database']['version']??null;
            if(!is_string($version)||!preg_match('/\A[0-9a-zA-Z. +_\-]{1,100}\z/D',$version))$version=null;
            $tables=[];foreach(AcsEngineMigration::ORDER as $t){$r=$raw['database']['tables'][$t]??[];
                $tables[$t]=['engine'=>in_array($r['engine']??null,['MyISAM','InnoDB'],true)?$r['engine']:null,
                    'bytes'=>is_int($r['bytes']??null)&&$r['bytes']>=0?$r['bytes']:null,
                    'schema'=>in_array($r['schema']??null,['PRELIMINARY','BLOCKED'],true)?$r['schema']:'NOT_VERIFIED'];}
            $report=['checked_at'=>max(0,(int)($raw['checked_at']??0)),'status'=>'BLOCKED','database'=>['version'=>$version,'tables'=>$tables],
                'issues'=>array_values(array_intersect(array_keys(self::messages()),$raw['issues']??[])),
                'access'=>self::safeAccess($raw['access']??[]),'runtime'=>$this->safeRuntime(is_array($raw['runtime']??null)?$raw['runtime']:[]),'space'=>'NOT_VERIFIED','worker'=>$this->worker->status(),'maintenance'=>'NOT_VERIFIED','engine_journal'=>'NOT_VERIFIED','publication'=>'BLOCKED'];
        }
        $events=[];foreach($state['events']??[] as $e){if(in_array($e['operation']??null,['check','prepare','start','register_backup','register_staging'],true))$events[]=['at'=>max(0,(int)($e['at']??0)),'operation'=>$e['operation'],'status'=>($e['status']??null)==='RECORDED'?'RECORDED':'BLOCKED'];}
        $evidence=[];foreach(['backup','staging'] as $kind){$r=$state['evidence'][$kind]??null;if(is_array($r))$evidence[$kind]=['source'=>'administrator_statement','result'=>in_array($r['result']??null,['not_checked','passed','failed'],true)?$r['result']:'unknown','created_at'=>(int)($r['created_at']??0),'verified_at'=>isset($r['verified_at'])?(int)$r['verified_at']:null,'registered_at'=>(int)($r['registered_at']??0),'scope_current'=>$kind==='backup'||($r['scope']??null)===$state['selected']];}
        if(isset($evidence['backup'])){$h=$state['evidence']['backup']['sha256']??null;if(is_string($h)&&preg_match('/\A[a-f0-9]{64}\z/D',$h))$evidence['backup']['sha256']=$h;}
        return ['version'=>2,'evidence'=>$evidence,'revision'=>(int)$state['revision'],'selected'=>array_values(array_intersect(AcsEngineMigration::ORDER,$state['selected'])),'report'=>$report,'events'=>$events,'publication'=>'BLOCKED'];
    }
}
