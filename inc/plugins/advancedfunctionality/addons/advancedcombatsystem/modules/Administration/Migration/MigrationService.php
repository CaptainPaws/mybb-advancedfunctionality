<?php
if(!defined('IN_MYBB'))die('No direct access');
require_once __DIR__.'/../../Application/AuthorizationService.php';
require_once __DIR__.'/MigrationJournal.php';
require_once __DIR__.'/BackgroundWorker.php';
// Shared EngineMigration implementation: HTTP may call ONLY its static read-only observer.
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
    public function diagnose(array $selected):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $data=AcsEngineMigration::readOnlyReport(fn($q)=>$this->select($q),(string)$this->db->table_prefix);
        $issues=[];
        if(!is_string($data['version'])||!preg_match('/\A8\.0\.46(?:[-.]|$)/',$data['version']))$issues[]='mysql_version';
        foreach($selected as $t){$r=$data['tables'][$t];if(!$r['engine']||!in_array($r['engine'],['MyISAM','InnoDB'],true))$issues[]='table_unavailable';if($r['schema']!=='PRELIMINARY')$issues[]='schema_unverified';}
        foreach(['backup_missing','restore_unverified','staging_missing','maintenance_unverified','space_unverified','engine_journal_unverified','worker_unavailable'] as $c)$issues[]=$c;
        return ['checked_at'=>time(),'database'=>$data,'selected'=>$selected,'status'=>'BLOCKED','issues'=>array_values(array_unique($issues)),'worker'=>$this->worker->status(),
            'backup'=>'BLOCKED','restore'=>'NOT_VERIFIED','staging'=>'BLOCKED','space'=>'NOT_VERIFIED','maintenance'=>'NOT_VERIFIED','engine_journal'=>'NOT_VERIFIED','publication'=>'BLOCKED'];
    }
    public function command(array $in):array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        global $mybb;
        if($mybb->request_method!=='post'||!is_string($in['csrf']??null)||!verify_post_check($in['csrf'],true))throw new RuntimeException('csrf_required');
        $op=$in['op']??'';if(!in_array($op,['check','prepare','start'],true))throw new RuntimeException('unknown_command');
        if($op!=='check')(new AF_ACS_AuthorizationService)->requirePermission('admin.migration.manage');
        $selected=$in['tables']??[];
        if(!is_array($selected)||array_filter($selected,fn($t)=>!is_string($t))||count($selected)!==count(array_unique($selected))||array_diff($selected,AcsEngineMigration::ORDER))throw new RuntimeException('table_scope');
        $selected=array_values(array_intersect(AcsEngineMigration::ORDER,$selected));foreach(['forums','users','threads','posts'] as $t)if(!in_array($t,$selected,true))throw new RuntimeException('table_scope');
        if(($in['conditional_confirmed']??'')!=='1'&&array_diff($selected,['forums','users','threads','posts']))throw new RuntimeException('conditional_scope_unverified');
        $key=$in['key']??'';$version=$in['revision']??null;
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!is_int($version)||$version<0)throw new RuntimeException('invalid_request');
        if($op==='start'&&($in['phrase']??'')!=='ПОДТВЕРЖДАЮ МИГРАЦИЮ')throw new RuntimeException('confirmation_required');
        $hash=hash('sha256',json_encode([$op,$selected,$in['phrase']??'',(int)$mybb->user['uid']],JSON_THROW_ON_ERROR));
        return $this->journal->command($key,$hash,$version,function($s)use($op,$selected){
            if($s['job']!==null&&$op!=='check')throw new RuntimeException('unfinished_operation');
            // An unavailable broker cannot create an executable job or approval/config secrets.
            $s['selected']=$selected;$s['report']=$this->diagnose($selected);
            if($s['job']!==null)$s['report']['issues'][]='unfinished_operation';
            $s['events'][]=['at'=>time(),'operation'=>$op,'status'=>'BLOCKED'];return $s;
        });
    }
    public static function messages():array
    {
        return ['backup_missing'=>'Не найдена проверенная резервная копия. Нужен серверный каталог backup-артефактов вне webroot.',
        'restore_unverified'=>'Тестовое восстановление не подтверждено. Нужна проверяемая связь отчёта восстановления с конкретным backup.',
        'staging_missing'=>'Нет подтверждения теста на изолированной копии установленного форума. Synthetic fixture его не заменяет.',
        'maintenance_unverified'=>'Не удалось доказать безопасную остановку записи: PHP, cron, tasks, mail/jobs и привилегированные DB connections требуют общего maintenance window.',
        'space_unverified'=>'Свободное место на серверных data/temp/backup volumes не подтверждено. Нужны локальные измерения и бюджет по staging.',
        'worker_unavailable'=>'Автономный серверный исполнитель недоступен.',
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
                'backup'=>'BLOCKED','restore'=>'NOT_VERIFIED','staging'=>'BLOCKED','space'=>'NOT_VERIFIED','worker'=>$this->worker->status(),'maintenance'=>'NOT_VERIFIED','engine_journal'=>'NOT_VERIFIED','publication'=>'BLOCKED'];
        }
        $events=[];foreach($state['events']??[] as $e){if(in_array($e['operation']??null,['check','prepare','start'],true))$events[]=['at'=>max(0,(int)($e['at']??0)),'operation'=>$e['operation'],'status'=>'BLOCKED'];}
        return ['version'=>1,'revision'=>(int)$state['revision'],'selected'=>array_values(array_intersect(AcsEngineMigration::ORDER,$state['selected'])),'report'=>$report,'events'=>$events,'publication'=>'BLOCKED'];
    }
}
