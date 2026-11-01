<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP)die('No direct access');
require_once __DIR__.'/MigrationService.php';
final class AF_ACS_MigrationWizard
{
    public static function render():void
    {
        global $db,$mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $journal=new AF_ACS_MigrationJournal(MYBB_ROOT.'cache/acs-migration-'.substr(hash('sha256',MYBB_ROOT),0,16));
        $service=new AF_ACS_MigrationService($db,$journal,new AF_ACS_MigrationBackgroundWorker);
        $esc=fn($s)=>htmlspecialchars_uni((string)$s);$error=null;
        try{
            $state=$service->state();
            if($mybb->request_method==='post'){
                $revision=$mybb->get_input('migration_revision');
                if(!preg_match('/\A[0-9]{1,9}\z/D',$revision))throw new RuntimeException('invalid_request');
                $tables=$mybb->input['migration_tables']??[];
                $state=$service->command(['op'=>$mybb->get_input('migration_op'),'csrf'=>$mybb->get_input('my_post_key'),'key'=>$mybb->get_input('migration_key'),
                    'revision'=>(int)$revision,'tables'=>$tables,'phrase'=>$mybb->get_input('migration_phrase'),'conditional_confirmed'=>$mybb->get_input('conditional_confirmed')]);
            }
        }catch(Throwable $e){$error=AF_ACS_MigrationService::messages()[$e->getMessage()]??'Требуется проверка администратора перед продолжением. Подготовка недоступна.';
            // Do not expose SQL/path/stack; existing server log gets only a stable category/code.
            error_log('[ACS migration wizard] '.(preg_match('/\A[A-Za-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'internal_failure'));
            try{$state=$service->state();}catch(Throwable $ignored){$state=['revision'=>0,'selected'=>['forums','users','threads','posts'],'report'=>null,'events'=>[],'job'=>null];}}
        $safe=$service->export($state);$report=$safe['report'];
        echo '<h2>Инструменты → Миграция БД</h2><h3>Подготовка базы данных для боевой системы</h3>';
        echo '<p>Некоторые таблицы форума используют MyISAM. Этот движок не поддерживает откат изменений в общей SQL-транзакции. Для атомарной публикации боевых постов ACS необходимо перевести утверждённые таблицы на InnoDB.</p>';
        echo '<p>Операция не изменяет игровые характеристики, способности и результаты бросков. Она изменяет способ хранения таблиц MySQL.</p>';
        echo '<div class="error"><strong>Эта операция выполняется при остановленных записях на форум.</strong> Не запускайте её без проверенной резервной копии. Во время выполнения пользователи не должны публиковать сообщения, загружать файлы или изменять профиль.</div>';
        if($error)echo '<p class="error">'.$esc($error).'</p>';
        echo '<h3>1. Проверить систему</h3><p>Состояние: <strong>BLOCKED — выполнение заблокировано</strong>. IC-MYBB-1 не меняется на READY. Версия сохранённой подготовки: '.$esc($state['revision']).'.</p>';
        if($report)echo '<p>MySQL: '.$esc($report['database']['version']??'не определён').'. Последняя сохранённая проверка (UTC): '.$esc(gmdate('Y-m-d H:i:s',$report['checked_at'])).'.</p>';
        echo '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=tools">'
            .'<input type="hidden" name="my_post_key" value="'.$esc($mybb->post_code).'">'
            .'<input type="hidden" name="migration_key" value="'.bin2hex(random_bytes(16)).'">'
            .'<input type="hidden" name="migration_revision" value="'.$esc($state['revision']).'">';
        echo '<table class="general"><tr><th>Таблица</th><th>Движок / размер</th><th>Выбор и применимость</th><th>Предварительная проверка</th></tr>';
        foreach(AcsEngineMigration::ORDER as $t){$mandatory=in_array($t,['forums','users','threads','posts'],true);$r=$report['database']['tables'][$t]??null;$checked=in_array($t,$safe['selected'],true);
            echo '<tr><td>'.$esc(AF_ACS_MigrationService::LABELS[$t]).'<br><code>'.$esc($t).'</code></td><td>'.$esc($r['engine']??'не проверен').' / '.($r&&$r['bytes']!==null?$esc(number_format($r['bytes']/1048576,2,'.',' ')).' МиБ (оценка)':'нет данных').'</td><td>';
            if($mandatory)echo '<input type="hidden" name="migration_tables[]" value="'.$t.'">Обязательная';
            else echo '<label><input type="checkbox" name="migration_tables[]" value="'.$t.'"'.($checked?' checked':'').'> Только по подтверждённой ветке публикации</label>';
            echo '</td><td>'.$esc(!$r?'Не проверена':($r['engine']==='InnoDB'?'Уже InnoDB — повторный ALTER не требуется':($r['schema']==='PRELIMINARY'?'Предварительно допустима; полная Engine-проверка требует worker':'Требуется проверка администратора'))).'</td></tr>';
        }
        echo '</table><p><label><input type="checkbox" name="conditional_confirmed" value="1"> Подтверждаю применимость выбранных дополнительных таблиц по списку фактически затрагиваемых таблиц. Это не подтверждение backup или безопасности запуска.</label></p>';
        echo '<button type="submit" name="migration_op" value="check">Проверить состояние базы</button> ';
        $manage=(new AF_ACS_AuthorizationService)->decision('admin.migration.manage')['allowed'];
        echo '<button type="submit" name="migration_op" value="prepare"'.($manage?'':' disabled').'>Сохранить выбранные таблицы и подготовку</button></form>';
        if(!$manage)echo '<p>Для изменения подготовки нужно отдельное право MyBB «Инструменты → Оптимизация базы данных» вместе с доступом AF ACP.</p>';
        echo '<h3>2. Резервная копия и восстановление</h3><p>Перед миграцией нужна полная резервная копия форума, включая базу данных и связанные файлы. Копия должна быть проверена восстановлением на отдельной среде.</p>';
        echo '<label>Проверенная резервная копия <select disabled><option>Нет доступных подтверждённых записей</option></select></label>';
        echo '<p>BLOCKED: защищённый серверный каталог backup/restore-артефактов не подключён. Наличие файла или checkbox не доказывает восстановление. Автоматическое согласованное резервное копирование отдельным worker также не подключено. SQL dump не загружается и не скачивается через эту страницу.</p>';
        echo '<h3>3. Тестовая проверка</h3><p>BLOCKED: отчёт испытаний на изолированной копии установленного форума не получен через проверенный серверный канал. Синтетические тесты инструмента не заменяют staging.</p>';
        echo '<h3>4. Подготовка</h3><p>Выбрано таблиц: '.count($safe['selected']).'. Ожидаемое изменение: MyISAM → InnoDB только для подтверждённых выбранных таблиц; уже InnoDB не преобразуются повторно. Оценка времени и ресурсов отсутствует до подтверждённого staging.</p>';
        echo '<p>Свободное место, исключительное maintenance window и EngineMigration intent: НЕ ПОДТВЕРЖДЕНЫ. HTTP не может доказать локальные data/temp/backup volumes или отсутствие всех writers.</p><ul>';
        foreach($report['issues']??['worker_unavailable','backup_missing','staging_missing'] as $code)echo '<li>'.$esc(AF_ACS_MigrationService::messages()[$code]??'Требуется проверка администратора').'</li>';
        echo '</ul><p>Потребуется автономный supervisor/scheduler с job identity, heartbeat, защищённым журналом EngineMigration и каталогом доказательств. MyBB task в пользовательском запросе не подходит. JSON и секреты пользователю вводить не требуется: их формирует будущая проверенная серверная интеграция, сейчас она BLOCKED.</p>';
        echo '<h3>5. Подтверждение</h3><p>Вы собираетесь изменить движок хранения основных таблиц форума. Это служебная операция, которую не нужно повторять после успешного завершения.</p>';
        echo '<label>Контрольная фраза <input type="text" disabled placeholder="ПОДТВЕРЖДАЮ МИГРАЦИЮ"></label> <button disabled>Запустить миграцию</button><p>Подтверждение и запуск недоступны, пока не доказаны все условия. Контрольная фраза не заменяет backup, staging и безопасное выполнение.</p>';
        echo '<h3>6. Выполнение и журнал</h3><p>Автономный исполнитель: BLOCKED. '.($state['job']!==null?'Обнаружена незавершённая операция. Требуется проверка администратора перед продолжением.':'Новая операция не запущена.').'</p><p>Текущая таблица и число подтверждённых Engine-этапов не получены. Процент текущего ALTER не вычисляется. Обновление или закрытие страницы не запускает работу.</p><ul>';
        foreach($safe['events'] as $e)echo '<li>'.$esc(gmdate('Y-m-d H:i:s',$e['at'])).' UTC — '.$esc(['check'=>'Проверка системы','prepare'=>'Подготовка','start'=>'Запуск отклонён'][$e['operation']]).': '.$esc($e['status']).'</li>';
        echo '</ul><p>Миграция движков не означает готовности обработчиков публикации или ACS Publication Coordinator.</p>';
        $json=json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        echo '<p><a class="button" download="acs-migration-preparation.json" href="data:application/json;base64,'.base64_encode($json).'">Экспорт безопасного отчёта</a></p>';
    }
}
