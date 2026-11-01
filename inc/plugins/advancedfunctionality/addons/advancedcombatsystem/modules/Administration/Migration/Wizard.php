<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP)die('No direct access');
require_once __DIR__.'/MigrationService.php';
final class AF_ACS_MigrationWizard
{
    private static function esc(mixed $v):string {return htmlspecialchars_uni((string)$v);}
    private static function form(array $s,string $op):void
    {
        global $mybb;
        echo '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=tools">';
        $fields=['my_post_key'=>$mybb->post_code,'migration_key'=>bin2hex(random_bytes(16)),'migration_revision'=>$s['revision'],'migration_op'=>$op,'conditional_confirmed'=>'1'];
        foreach($fields as $key=>$value)echo '<input type="hidden" name="'.$key.'" value="'.self::esc($value).'">';
        if($op!=='prepare')foreach($s['selected'] as $t)echo '<input type="hidden" name="migration_tables[]" value="'.self::esc($t).'">';
    }
    private static function evidenceForm(array $s,string $kind,bool $manage):void
    {
        $backup=$kind==='backup';self::form($s,$backup?'register_backup':'register_staging');
        echo '<fieldset'.($manage?'':' disabled').'><legend>'.($backup?'Зарегистрировать копию и проверку восстановления':'Зарегистрировать испытание на тестовой копии форума').'</legend>';
        echo '<p>Это заявление администратора о выполненной проверке, а не автоматическое доказательство исправности копии или безопасности миграции. Запись не разрешает ALTER.</p>';
        echo '<p><label>Номер копии или отчёта в панели хостинга (без путей, URL и секретов) <input required name="evidence_reference" maxlength="100"></label></p>';
        echo '<p><label>'.($backup?'Дата создания согласованной копии':'Дата испытания').', UTC <input required type="datetime-local" name="evidence_date"></label> '
            .'<label>Дата завершения проверки, UTC <input required type="datetime-local" name="evidence_verified"></label></p>';
        if($backup)echo '<p><label>Контрольная сумма SHA-256 копии (64 шестнадцатеричных символа) <input required name="evidence_checksum" minlength="64" maxlength="64" size="68" pattern="[a-fA-F0-9]{64}"></label><br>Получите сумму в панели хостинга или у провайдера. Сам SQL dump сюда не загружайте.</p>';
        echo '<p><label>Результат <select required name="evidence_result"><option value="">Выберите результат</option><option value="passed">Проверка завершилась успешно</option><option value="failed">Проверка выявила ошибку</option></select></label></p>';
        echo '<p><label>'.($backup?'Как проверяли восстановление на отдельной базе и связанные файлы':'Какая копия установленного форума использована, что проверено и какие ресурсы/время измерены').'<br><textarea required name="evidence_method" rows="3" cols="80" minlength="20" maxlength="800"></textarea></label><br>Минимум 20 символов. Не указывайте пароли, персональные данные или содержимое SQL.</p>';
        echo '<button type="submit">Сохранить результат проверки</button></fieldset></form>';
    }
    public static function render():void
    {
        global $db,$mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        $journal=new AF_ACS_MigrationJournal(MYBB_ROOT.'cache/acs-migration-'.substr(hash('sha256',MYBB_ROOT),0,16));
        $service=new AF_ACS_MigrationService($db,$journal,new AF_ACS_MigrationBackgroundWorker);$error=null;$saved=false;
        try{
            $state=$service->state();
            if($mybb->request_method==='post'){
                $revision=$mybb->get_input('migration_revision');if(!preg_match('/\A[0-9]{1,9}\z/D',$revision))throw new RuntimeException('invalid_request');
                $e=[];foreach(['reference','date','verified','checksum','result','method'] as $k)$e[$k]=$mybb->get_input('evidence_'.$k);
                $state=$service->command(['op'=>$mybb->get_input('migration_op'),'csrf'=>$mybb->get_input('my_post_key'),'key'=>$mybb->get_input('migration_key'),'revision'=>(int)$revision,
                    'tables'=>$mybb->input['migration_tables']??[],'phrase'=>$mybb->get_input('migration_phrase'),'conditional_confirmed'=>$mybb->get_input('conditional_confirmed'),'evidence'=>$e]);$saved=true;
            }
        }catch(Throwable $e){$error=AF_ACS_MigrationService::messages()[$e->getMessage()]??'Проверка не завершена. Состояние не заменено; обновите страницу или передайте отчёт администратору хостинга.';
            error_log('[ACS migration wizard] '.(preg_match('/\A[A-Za-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'internal_failure'));
            try{$state=$service->state();}catch(Throwable $ignored){$state=['revision'=>0,'selected'=>['forums','users','threads','posts'],'report'=>null,'events'=>[],'job'=>null];}}
        $safe=$service->export($state);$r=$safe['report'];$runtime=$r['runtime']??[];
        $manage=(new AF_ACS_AuthorizationService)->decision('admin.migration.manage')['allowed'];
        echo '<h2>Инструменты → Миграция БД</h2><h3>Подготовка базы данных для боевой системы</h3><p>MyISAM не поддерживает общий откат записи поста и ACS. Подготовка перевода утверждённых таблиц на InnoDB не меняет характеристики, способности или броски.</p>';
        echo '<div class="error">Миграция выполняется только при остановленных записях и проверенной резервной копии. В этой версии мастер не запускает ALTER: возможность безопасного ограниченного HTTP-выполнения не доказана.</div>';
        if($error)echo '<p class="error">'.self::esc($error).'</p>';
        elseif($saved)echo '<p>Результат сохранён. Обновление страницы не запускает работу повторно.</p>';
        echo '<p><a href="#system">1. База</a> · <a href="#backup">2. Копия</a> · <a href="#staging">3. Тест</a> · <a href="#maintenance">4. Обслуживание</a> · <a href="#execution">5. Выполнение</a> · <a href="#history">Отчёт</a></p>';
        echo '<h3 id="system">1. Какие таблицы нужно подготовить</h3>';
        if($r)echo '<p>MySQL '.self::esc($r['database']['version']??'не определён').'; PHP '.self::esc($runtime['php_version']??'не определён').'. Проверка: '.self::esc(gmdate('Y-m-d H:i:s',$r['checked_at'])).' UTC.</p>';
        self::form($state,'check');echo '<button>Проверить состояние базы и доступные ресурсы</button></form>';
        self::form($state,'prepare');echo '<table class="general"><tr><th>Таблица</th><th>Движок / оценочный размер</th><th>Необходимость и выбор</th></tr>';
        foreach(AcsEngineMigration::ORDER as $t){$mandatory=in_array($t,['forums','users','threads','posts'],true);$v=$r['database']['tables'][$t]??[];
            echo '<tr><td>'.self::esc(AF_ACS_MigrationService::LABELS[$t]).'<br><code>'.$t.'</code></td><td>'.self::esc($v['engine']??'не проверен').' / '.(isset($v['bytes'])?self::esc(number_format($v['bytes']/1048576,2,'.',' ')).' МиБ':'нет данных').'</td><td>';
            if($mandatory)echo '<input type="hidden" name="migration_tables[]" value="'.$t.'">Обязательная';
            else echo '<label><input type="checkbox" name="migration_tables[]" value="'.$t.'"'.(in_array($t,$safe['selected'],true)?' checked':'').($manage?'':' disabled').'> Только для подтверждённой ветки публикации</label>';
            if(($v['engine']??null)==='InnoDB')echo '<br>Уже InnoDB: повторный ALTER не нужен.';
            if(($v['schema']??null)==='BLOCKED')echo '<br>Структура требует отдельной проверки; автоматическое изменение запрещено.';
            echo '</td></tr>';
        }
        echo '</table><p>Дополнительные таблицы не выбираются автоматически. Сохраняя их, подтвердите применимость к фактической публикации; это не подтверждает backup или безопасность ALTER.</p><button'.($manage?'':' disabled').'>Сохранить выбор таблиц</button></form>';
        if(!$manage)echo '<p>Для регистрации подтверждений и подготовки нужно отдельное право MyBB «Инструменты → Оптимизация базы данных» и доступ AF ACP.</p>';
        echo '<h4>Дисковое пространство</h4><table class="general"><tr><th>Что проверяет форум</th><th>Наблюдение</th></tr>';
        foreach(['data'=>'Размещение данных MySQL','temporary'=>'Временные файлы MySQL','innodb_temporary'=>'Временные файлы InnoDB','php_temporary'=>'Временные файлы PHP'] as $key=>$label){$v=$runtime['space'][$key]??[];
            echo '<tr><td>'.$label.'</td><td>'.(isset($v['free_bytes'])?self::esc(number_format($v['free_bytes']/1073741824,2,'.',' ')).' ГиБ доступно PHP; принадлежность тому же диску БД не доказана':'Файловая система недоступна для достоверной оценки из PHP').'</td></tr>';
        }
        echo '</table><p>Размер таблицы не равен размеру временных файлов. Нужны место для копии/индексов/FULLTEXT, сортировки контрольных сумм, журналов и backup. Попросите хостинг проверить реальные data/temp/backup volumes и запас по замерам тестовой миграции. Наблюдение PHP не разрешает ALTER.</p>';
        echo '<h3 id="backup">2. Создать копию и проверить восстановление</h3><ol><li>Откройте свою панель управления хостингом и найдите резервное копирование MySQL.</li><li>Согласуйте окно обслуживания и остановку всех записей, включая фоновые задания. Одна отметка «Форум закрыт» не обеспечивает согласованную копию MyISAM.</li><li>Создайте полную согласованную копию БД и связанных файлов форума. Для смешанной MyISAM/InnoDB копии уточните у хостинга механизм согласования; один single-transaction недостаточен.</li><li>Сохраните копию вне публичного webroot и получите SHA-256. Если панель не показывает сумму — запросите её у провайдера.</li><li>Восстановите копию в отдельную закрытую базу/среду, без отправки реальных писем; проверьте данные и связанные файлы. Не импортируйте тестовую копию поверх рабочего форума.</li><li>Вернитесь сюда и зарегистрируйте дату, сумму и результат.</li></ol>';
        self::evidenceSummary($safe,'backup');self::evidenceForm($state,'backup',$manage);
        echo '<h3 id="staging">3. Проверить на копии установленного форума</h3><p>Через хостинг подготовьте изолированную копию именно вашего форума с MySQL 8.0.46. Попросите проверить выбранные таблицы, данные, индексы/FULLTEXT, AUTO_INCREMENT и кодировки, измерить время и пиковое место, испытать восстановление неизвестного исхода. Синтетический тест разработчика не заменяет эту работу.</p>';
        self::evidenceSummary($safe,'staging');self::evidenceForm($state,'staging',$manage);
        echo '<h3 id="maintenance">4. Остановить записи и проверить фоновые задачи</h3><p>Форум закрыт средствами MyBB: '.(!empty($runtime['board_closed'])?'да':'не подтверждено').'. Других видимых DB-подключений: '.self::esc($runtime['visible_other_connections']??'не удалось измерить').'. Включённых MyBB tasks: '.self::esc($runtime['enabled_tasks']??'не удалось измерить').'. Планировщик MySQL: '.self::esc($runtime['event_scheduler']??'не определён').'.</p>';
        echo '<p>Это наблюдения, не гарантия остановки writers. Нулевой счётчик без доказанной полноты прав ничего не доказывает. Группы с правом просмотра закрытого форума обходят это ограничение; PHP-запросы, cron, MyBB tasks, mail/jobs и внешние клиенты могут продолжать записи.</p><p><a href="index.php?module=config-settings">Открыть настройки MyBB</a> · <a href="index.php?module=tools-tasks">Открыть список MyBB tasks</a></p><p>Запретите публикации, uploads, редактирование профилей и административные изменения; согласуйте остановку jobs и внешних writers с хостингом. Не меняйте глобальные режимы общего MySQL без разрешения владельца. Мастер ничего не отключает автоматически.</p>';
        echo '<h3 id="execution">5. Можно ли выполнить одну таблицу из ACP?</h3><p><strong>На этой архитектуре безопасное ACP-only выполнение пока не доказано.</strong> Worker не является обязательным условием сам по себе. Требуются следующие доказательства:</p><ul><li>Измеренная верхняя граница времени одного ALTER и полной сверки данных. Размер таблицы такой границы не даёт.</li><li>Надёжные пределы PHP/FPM/веб-сервера/прокси и протокол исхода при разрыве HTTP. Текущий PHP max_execution_time: '.self::esc($runtime['php_limit_seconds']??'не измерен').' секунд; это не гарантированный wall-clock лимит MySQL.</li><li>MySQL max_execution_time ограничивает SELECT, а lock_wait_timeout — ожидание metadata lock, не копирование таблицы. Увеличение этих значений не делает HTTP безопасным.</li><li>Стабильное private хранилище EngineMigration intent и completion receipt, идентичность операции, полная проверка данных/индексов/счётчиков после потери ответа.</li><li>Доказанная остановка всех writers. ACP-соединение и проверки самого веб-запроса нельзя автоматически считать совместимыми с CLI exclusive-режимом.</li></ul>';
        echo '<p>Что делать: передайте хостингу безопасный отчёт ниже, попросите обеспечить эти условия для одной таблицы или предоставить управляемую автономную операцию с устойчивым номером задания и журналом. Это конкретная альтернатива, а не требование вручную писать JSON или пользоваться SSH.</p>';
        echo '<p>Выбрано таблиц: '.count($safe['selected']).'. '.(isset($safe['evidence']['backup'])?'Свидетельство копии зарегистрировано.':'Сначала зарегистрируйте копию.').' '.(isset($safe['evidence']['staging'])?'Свидетельство теста зарегистрировано.':'Сначала зарегистрируйте тест.').'</p><label>Контрольная фраза <input disabled placeholder="ПОДТВЕРЖДАЮ МИГРАЦИЮ"></label> <button disabled>Запустить миграцию</button>';
        echo '<details><summary>Как должна проходить операция после отдельного допуска</summary><ol><li>Новая проверка условий и явное подтверждение одной конкретной таблицы.</li><li>Устойчивая запись намерения до DDL.</li><li>Один разрешённый ALTER без автоматического цикла.</li><li>Сверка engine, полного набора данных, индексов, кодировки и AUTO_INCREMENT.</li><li>Устойчивая запись результата. Только после неё — отдельное подтверждение следующей таблицы.</li><li>Если ответ потерян — сначала сверка журнала и фактического результата, а не повтор ALTER.</li></ol><p>Это требование к будущему проверенному исполнению; сейчас оно не запускается.</p></details>';
        echo '<p>До доказательства условий запуск заблокирован на сервере, даже при поддельном POST. Нет AJAX-цикла, shell_exec или автоматического перехода к следующей таблице.</p>';
        echo '<h3 id="history">Состояние и восстановление</h3><p>Новая операция не запущена. '.($state['job']!==null?'Обнаружена незавершённая операция; повторный запуск запрещён.':'Состояние внешнего EngineMigration journal не подтверждено.').'</p><p>Если запрос/ответ потерян или ранее запускалась конверсия через хостинг: не повторяйте ALTER. Попросите провайдера сверить текущий engine, intent и receipt и выполнить read-only recovery; один InnoDB не подтверждает сохранность данных. При расхождении сохраните остановку записей и восстановите полную согласованную копию на отдельном экземпляре с проверкой перед переключением. Обратный ALTER не является rollback.</p>';
        echo '<details><summary>История сохранённой подготовки</summary><ul>';
        $labels=['check'=>'Проверка системы','prepare'=>'Выбор таблиц','start'=>'Запуск отклонён','register_backup'=>'Регистрация копии','register_staging'=>'Регистрация теста'];
        foreach($safe['events'] as $event)echo '<li>'.self::esc(gmdate('Y-m-d H:i:s',$event['at'])).' UTC — '.self::esc($labels[$event['operation']]??'Подготовка').'</li>';
        echo '</ul></details><p>IC-MYBB-1 остаётся BLOCKED: переход на InnoDB не подтверждает готовность ACS Publication Coordinator и post hooks.</p>';
        $json=json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        echo '<p><a class="button" download="acs-migration-preparation.json" href="data:application/json;base64,'.base64_encode($json).'">Скачать безопасный отчёт для администратора или хостинга</a></p>';
    }
    private static function evidenceSummary(array $safe,string $kind):void
    {
        $r=$safe['evidence'][$kind]??null;
        if(!$r){echo '<p>Результат ещё не зарегистрирован. Доступное действие — заполнить форму ниже.</p>';return;}
        if(isset($r['sha256']))echo '<p>Зарегистрированный SHA-256: <code>'.self::esc($r['sha256']).'</code>. Файл автоматически не проверялся.</p>';
        echo '<p>Заявление администратора сохранено: '.self::esc(gmdate('Y-m-d H:i',$r['created_at'])).' UTC, проверено '.self::esc(gmdate('Y-m-d H:i',$r['verified_at'])).' UTC. Результат: '.($r['result']==='passed'?'успешно':'обнаружена ошибка').'. '.(!$r['scope_current']?'Выбор таблиц изменён — тест требуется подтвердить для нового списка.':'').' Это не автоматический PASS.</p>';
    }
}
