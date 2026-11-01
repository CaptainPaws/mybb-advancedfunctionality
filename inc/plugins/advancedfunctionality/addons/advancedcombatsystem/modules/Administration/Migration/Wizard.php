<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP)die('No direct access');
require_once __DIR__.'/AcpExecution.php';
final class AF_ACS_MigrationWizard
{
    private static function esc(mixed $v):string {return htmlspecialchars_uni((string)$v);}
    private static function form(array $s,string $op):void
    {
        global $mybb;
        echo '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=tools">';
        foreach(['my_post_key'=>$mybb->post_code,'migration_key'=>bin2hex(random_bytes(16)),'migration_revision'=>$s['revision'],'migration_op'=>$op,'conditional_confirmed'=>'1'] as $k=>$v)echo '<input type="hidden" name="'.$k.'" value="'.self::esc($v).'">';
        foreach(AcsEngineMigration::ORDER as $t)echo '<input type="hidden" name="migration_tables[]" value="'.$t.'">';
    }
    public static function render():void
    {
        global $db,$mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $journal=new AF_ACS_MigrationJournal(MYBB_ROOT.'cache/acs-migration-'.substr(hash('sha256',MYBB_ROOT),0,16));
        $service=new AF_ACS_MigrationService($db,$journal,new AF_ACS_MigrationBackgroundWorker);
        $dir=MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedcombatsystem/.maintenance-'.substr(hash('sha256',MYBB_ROOT.(string)$db->table_prefix),0,16);
        $execution=new AF_ACS_MigrationAcpExecution($db,$journal,$dir);$error=null;$notice=null;
        try{
            $state=$service->state();
            if($mybb->request_method==='post'){
                $revision=$mybb->get_input('migration_revision');if(!preg_match('/\A[0-9]{1,9}\z/D',$revision))throw new RuntimeException('invalid_request');
                $op=$mybb->get_input('migration_op');$in=['op'=>$op,'csrf'=>$mybb->get_input('my_post_key'),'key'=>$mybb->get_input('migration_key'),'revision'=>(int)$revision];
                if($op==='check'){
                    $before=$execution->status();
                    if(($state['job']['status']??null)==='running'||($before['unknown']??false)){if(!verify_post_check($in['csrf'],true))throw new RuntimeException('csrf_required');$notice='Показано последнее подтверждённое состояние из журнала. Пока ALTER выполняется или исход неизвестен, запросы к заблокированным таблицам не запускаются.';}
                    else $state=$service->command($in+['tables'=>AcsEngineMigration::ORDER,'conditional_confirmed'=>'1']);
                }
                else $state=$execution->command($in+['backup'=>$mybb->get_input('backup_statement')]);
            }
            $progress=$execution->status();
        }catch(Throwable $e){$error=self::error($e->getMessage());try{$state=$service->state();$progress=$execution->status();}catch(Throwable $ignored){$state=['revision'=>0,'selected'=>[],'report'=>null,'events'=>[],'job'=>null];$progress=null;}}
        $safe=$service->export($state);$report=$safe['report'];$tables=$report['database']['tables']??[];
        $missing=[];$size=0;foreach(AcsEngineMigration::ORDER as $t)if(($progress['tables'][$t]['engine']??$tables[$t]['engine']??null)==='MyISAM'){$missing[]=$t;$size+=$tables[$t]['bytes']??0;}
        $allKnown=count(array_filter($tables,fn($r)=>in_array($r['engine'],['MyISAM','InnoDB'],true)))===count(AcsEngineMigration::ORDER);
        $complete=$progress['complete']??false;$notNeeded=$allKnown&&$missing===[]&&$progress===null;
        echo '<h2>Миграция базы данных</h2><p>AdvancedCombatSystem → Инструменты</p><p>Переход утверждённых таблиц на InnoDB позволит использовать транзакции при публикации боевых постов ACS. Игровые характеристики и результаты бросков не меняются.</p>';
        if($error)echo '<div class="error">'.self::esc($error).'</div>';if($notice)echo '<p>'.self::esc($notice).'</p>';
        if($complete)echo '<p><strong>Миграция завершена. Все выбранные таблицы переведены на InnoDB.</strong> Структура, данные, индексы и AUTO_INCREMENT проверены.</p>';
        elseif($notNeeded)echo '<p><strong>Миграция не требуется.</strong></p>';
        echo '<h3>Таблицы MyISAM</h3>';
        if(!$report)echo '<p>Сначала проверьте состояние базы.</p>';
        else echo '<p>Обнаружено таблиц: '.count($missing).'. Суммарный оценочный размер: '.self::esc(number_format($size/1048576,2,'.',' ')).' МиБ.</p>';
        echo '<table class="general"><tr><th>Таблица</th><th>Движок</th><th>Размер</th><th>Результат</th></tr>';
        foreach(AcsEngineMigration::ORDER as $t){$p=$progress['tables'][$t]??null;$r=$tables[$t]??[];$engine=!empty($p['pending'])?'исход не подтверждён':($p['engine']??$r['engine']??'не проверен');
            echo '<tr><td>'.self::esc(AF_ACS_MigrationService::LABELS[$t]).' <code>'.$t.'</code></td><td>'.self::esc($engine).'</td><td>'.(isset($r['bytes'])?self::esc(number_format($r['bytes']/1048576,2,'.',' ')).' МиБ':'—').'</td><td>'.(!empty($p['verified'])?'Проверено':($engine==='InnoDB'?'Преобразование не требуется':'Ожидает обработки')).'</td></tr>';
        }
        echo '</table>';
        if(empty($mybb->settings['boardclosed']))echo '<p>Перед запуском закройте форум через <a href="index.php?module=config-settings">штатные настройки MyBB</a>. Сам мастер настройки форума не меняет.</p>';
        self::form($state,'check');echo '<button>Проверить готовность</button></form>';
        echo '<div class="error"><strong>Перед миграцией создайте резервную копию базы данных через панель хостинга. При возникновении ошибки она понадобится для восстановления. Рекомендуется временно закрыть форум на обслуживание и остановить фоновые задания, способные изменять базу данных.</strong></div>';
        echo '<p>Во время шага записи в таблицы этой базы будут заблокированы. Не выполняйте другие административные изменения. Один запрос обрабатывает не более одной таблицы; после подтверждённого результата можно продолжить.</p>';
        $job=$state['job']??null;$manage=(new AF_ACS_AuthorizationService)->decision('admin.migration.manage')['allowed'];
        if(!$complete&&!$notNeeded&&$job&&($job['error']??null)){
            echo '<p class="error">'.self::esc(self::error($job['error'])).'</p>';
            if(($job['failure_phase']??null)==='before_ddl')echo '<p>Отказ произошёл до ALTER: под блокировкой проверено отсутствие незавершённого намерения. Журнал и ранее обработанные таблицы сохранены. После исправления доступа можно продолжить.</p>';
            else echo '<p>Отсутствие незавершённого ALTER ещё не доказано. Сначала нажмите «Проверить результат»; повторное преобразование не запускается.</p>';
        }
        foreach($report['access']??[] as $name=>$probe)if(!$complete&&!$notNeeded&&(!$job||!empty($job['error']))&&$probe['status']==='DENIED'&&isset(AF_ACS_MigrationAcpExecution::accessRequirements()[$name]))echo '<p class="error">'.self::esc(self::error('database_'.$name.'_denied')).'</p>';
        if($progress)echo '<h3>Прогресс</h3><p>Обработано '.$progress['done'].' из '.$progress['total'].'. Текущая таблица: '.self::esc($progress['current']??'нет незавершённого ALTER').'.</p>';
        elseif($job)echo '<p>Операция зарегистрирована. Подтверждённое преобразование ещё не зафиксировано.</p>';
        if(!$complete&&!$notNeeded&&($job['status']??null)!=='completed'){
            $unknown=($progress['unknown']??false)||in_array($job['status']??null,['running','recovery_required'],true);
            $op=$unknown?'recover':($job?'next':'begin');self::form($state,$op);
            if(!$job)echo '<p><label><input type="checkbox" required name="backup_statement" value="1"> Подтверждаю, что создал резервную копию базы данных.</label></p><p>Это заявление администратора, а не доказательство возможности отката или восстановления.</p>';
            echo '<button'.(!$manage||!$report?' disabled':'').'>'.($unknown?'Проверить результат':($job?'Продолжить миграцию: следующая таблица':'Выполнить миграцию')).'</button></form>';
        }
        if(!$complete&&!$notNeeded&&($job['status']??null)==='completed')echo '<p>После предыдущего завершения появились изменения. Повторный запуск требует отдельной проверки администратора; журнал не очищается автоматически.</p>';
        if(!$manage)echo '<p>Для запуска нужны права MyBB ACP, AF и отдельное право «Инструменты → Оптимизация базы данных».</p>';
        echo '<details><summary>Технические подробности</summary><p>ACP-режим: MySQL 8.0.46, локальный MySQLi Unix socket, доступные локальные data/temp volumes, до 16 МиБ/50 000 строк на таблицу. Бюджет шага — 10 секунд; при превышении запрашивается KILL QUERY и фиксируется неизвестный исход, а не успех. PHP/FPM может оборвать запрос раньше: тогда сначала проверяется журнал, повторный ALTER автоматически не выполняется.</p><p>LOCK TABLES WRITE блокирует все существующие базовые таблицы только текущей схемы на время шага, без глобального read_only/offline. Если права, блокировки, место, целостность или безопасный режим не подтверждены, последовательность останавливается. Перед каждым шагом схема проверяется заново, а текущее содержимое выбранной таблицы фиксируется под блокировкой. При неизвестном исходе данные сверяются со снимком до незавершённой операции; расхождение останавливает продолжение.</p><p>Перед первым шагом закройте форум через <a href="index.php?module=config-settings">штатные настройки MyBB</a> и остановите задания через хостинг/<a href="index.php?module=tools-tasks">MyBB tasks</a>. Сам мастер ничего не отключает. Большие таблицы и неподдержанное окружение требуют управляемой операции хостинга с тем же EngineMigration, не обхода проверок.</p><p>При неизвестном исходе сохраняйте обслуживание, нажмите «Проверить результат»; пока прежний SQL/другая операция ещё держит блокировки, продолжение запрещено. При расхождении данных не повторяйте ALTER: восстановление выполняется из полной согласованной копии на отдельном экземпляре с проверкой перед переключением. Обратный ALTER не является rollback.</p>';
        echo '<h4>Проверки доступа MySQL (без изменения таблиц)</h4><p>Прямое разрешение в SHOW GRANTS не доказывает успешность ALTER. «Не подтверждено» может означать права через роли или на отдельные таблицы. LOCK TABLES проверяется только при запуске, не диагностическим захватом блокировок.</p>';
        if(isset($report['checked_at']))echo '<p>Результаты последней проверки готовности: '.self::esc(gmdate('Y-m-d H:i:s',$report['checked_at'])).' UTC. После изменения прав нажмите «Проверить готовность» ещё раз.</p>';
        foreach($report['access']??[] as $name=>$probe){
            $requirements=AF_ACS_MigrationAcpExecution::accessRequirements();
            if(str_starts_with($name,'grant_')){$label='Прямое право '.strtoupper(str_replace('_',' ',substr($name,6)));$hint='SHOW GRANTS: собственные права на схему/глобально. Это не автоматическая выдача прав.';}
            elseif(str_starts_with($name,'metadata_')){$label='Индексы: '.substr($name,9);$hint='SHOW INDEX FROM выбранная таблица; требуется доступ к metadata.';}
            elseif(str_starts_with($name,'select_')){$label='Чтение: '.substr($name,7);$hint='SELECT … LIMIT 0: проверяется SELECT без чтения пользовательских строк.';}
            else {$label=$requirements[$name]['operation']??$name;$hint=$requirements[$name]['requirement']??'';}
            $result=['PASS'=>'Запрос выполнен','DIRECT_GRANT'=>'Прямое разрешение найдено','DENIED'=>'Доступ запрещён','NOT_VERIFIED'=>'Не подтверждено'][$probe['status']];
            echo '<p><strong>'.self::esc($label).': '.self::esc($result).'</strong>'.(isset($probe['errno'])?' (код MySQL '.(int)$probe['errno'].')':'').'. '.self::esc($hint).'</p>';
        }
        $operation=$job['error_operation']??null;
        if(is_string($operation)&&isset(AF_ACS_MigrationAcpExecution::accessRequirements()[$operation]))echo '<p>Последняя операция: '.self::esc(AF_ACS_MigrationAcpExecution::accessRequirements()[$operation]['operation']).'. Код MySQL: '.(int)($job['mysql_errno']??0).'.</p>';
        if($progress)foreach($progress['history'] as $event)echo '<p>'.self::esc($event['table']).' — '.($event['outcome']==='converted'?'преобразована и проверена':'исход сверен без повторного ALTER').(isset($event['at'])?' · '.self::esc(gmdate('Y-m-d H:i:s',$event['at'])).' UTC':'').'</p>';
        echo '<p>Старые записи backup/restore и staging сохранены в журнале; их не требуется повторно вводить. IC-MYBB-1 остаётся BLOCKED: совместимость post hooks и публикационный координатор проверяются отдельно.</p><ul>';
        foreach($safe['events'] as $e)echo '<li>'.self::esc(gmdate('Y-m-d H:i:s',$e['at'])).' UTC — '.self::esc($e['status']).'</li>';
        echo '</ul></details>';
        $export=['version'=>3,'database'=>$report['database']??null,'checked_at'=>$report['checked_at']??null,'migration'=>$progress,'migration_status'=>$complete?'completed':($notNeeded?'not_required':($job['status']??'not_started')),'error_code'=>$job['error']??null,'access'=>$report['access']??[],'error_operation'=>isset(AF_ACS_MigrationAcpExecution::accessRequirements()[$job['error_operation']??''])?$job['error_operation']:null,'mysql_errno'=>is_int($job['mysql_errno']??null)?$job['mysql_errno']:null,'failure_phase'=>in_array($job['failure_phase']??null,['before_ddl','recovery_required'],true)?$job['failure_phase']:null,'backup_statement_only'=>!empty($job['backup_statement']),'publication'=>'BLOCKED'];$json=json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        echo '<p><a download="acs-migration-report.json" href="data:application/json;base64,'.base64_encode($json).'">Скачать отчёт</a></p>';
    }
    private static function error(string $code):string
    {
        if(str_starts_with($code,'schema_or_data_drift_'))return 'Проверка структуры, индексов, данных или кодировки выявила расхождение. Миграция остановлена; требуется проверка администратора и резервной копии.';
        return AF_ACS_MigrationAcpExecution::messages()[$code]??AF_ACS_MigrationService::messages()[$code]??($code==='migration_already_complete'?'Миграция уже завершена; повторный запуск запрещён.':'Операция остановлена: необходимая серверная проверка не пройдена. Передайте безопасный отчёт хостингу; исход SQL сначала нужно проверить, а не повторять.');
    }
}
