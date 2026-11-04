# ACS-4.1.4B-UI-2 — практический сценарий ACP и границы HTTP-выполнения

> Исторический отчёт. По ACS Stage 4 Revision 2 мигратор полностью удалён из репозитория; приведённые пути и команды больше не действуют. Актуальное состояние: [ACS-4.0-CLEANUP](ACS_4_0_CLEANUP.md).

Исходная main: `2ef31e4540833a19c1972051959e20851f03261d`, включает merged PR #1010. Переработана **существующая** вкладка ACS → Инструменты → Миграция БД, не создана параллельная страница. Основание: [план 4.1.4A](ACS_4_1_4A_ENGINE_MIGRATION_PLAN.md), [CLI 4.1.4B](ACS_4_1_4B_ENGINE_MIGRATION_TOOL.md), [предыдущий UI](ACS_4_1_4B_UI_MIGRATION_WIZARD.md), четыре нормативных документа [Master](ACS_MASTER_SPECIFICATION.md), [Architecture](ACS_TECHNICAL_ARCHITECTURE.md), [Contracts](ACS_INTEGRATION_CONTRACTS.md), [Roadmap](ACS_IMPLEMENTATION_ROADMAP.md). Последующее уточнение UX ниже заменяет прежнее универсальное требование «обязательно worker/catalog для любой подготовки», но **не отменяет инварианты выполнения CLI**.

## Что теперь реально доступно

- Переходы по шагам внутри существующей страницы, кнопка read-only проверки БД/ресурсов, сохранение выбранных таблиц. Обязательное ядро и optional allowlist сохранены; повторный ALTER для InnoDB не предлагается.
- Конкретная инструкция панели хостинга: остановка writes → согласованная полная копия смешанной MyISAM/InnoDB БД и связанных файлов → хранение вне webroot → SHA-256 → restore в отдельную закрытую базу без доставки писем → регистрация результата здесь. Нет требования вводить JSON/SQL/команды.
- **Рабочая форма backup/restore evidence:** номер копии/отчёта, даты создания и проверки UTC, 64-hex SHA-256, результат успешно/ошибка и описание не короче 20 символов. Пустой checkbox, будущие/перепутанные даты, пустой hash/метод не принимаются. Копия не загружается в webroot, SQL dump не выдаётся из ACP.
- **Рабочая форма staging-свидетельства:** дата, дата проверки, ссылка-идентификатор отчёта хостинга без URL/пути, результат, описание установленной копии/проверок/замеров. Точный выбранный scope фиксирует сервер, не произвольное клиентское поле. После смены scope прежнее заявление показывается неактуальным для нового набора; требуется повторная регистрация.
- Заявления хранятся в прежнем защищённом preparation journal, с временем и автором, историей без удаления, CAS и idempotency. Это **RECORDED / administrator_statement**, не автоматическое доказательство корректности backup/restore и не PASS для apply. Неудачная проверка тоже сохраняется честно. Никакого fake evidence или создания CLI approval из этих полей.
- Read-only runtime observations: версия PHP/MySQL, PHP max_execution_time, MyBB boardclosed, видимые другие пользовательские подключения, число enabled MyBB tasks, MySQL event scheduler/offline_mode, доступное PHP место на видимых каталогах. Неизвестное отдельно от наблюдаемого; интерфейс не блокируется целиком.
- Export v2 включает безопасные технические наблюдения и метаданные записанных заявлений/контрольную сумму. Свободный текст, backup reference, private paths, actor UID, raw schema, CSRF и receipts не экспортируются. Исходные secrets/config не читаются и не отправляются браузеру.

У каждого препятствия есть действие: заполнить форму, перейти в штатные настройки/список tasks, получить конкретные measurements или запросить контролируемую операцию у хостинга, передав безопасный отчёт. Убраны пустой disabled backup picker и длинный одинаковый список BLOCKED. Регистрации и диагностика доступны **без** worker и каталога артефактов.

## Можно ли безопасно выполнить один ALTER из ACP?

**На доступной архитектуре — не доказано, поэтому HTTP apply не реализован и не запускается.** Причина не абстрактное отсутствие worker. Для конкретной таблицы нет доказанной верхней границы времени ALTER + полной сверки, независимого от HTTP durable execution/outcome канала и подтверждённого exclusive maintenance. Даже «маленькая» таблица по DATA_LENGTH не гарантирует короткий запрос.

| Свойство | Что выяснено | Практический вывод |
|---|---|---|
| MySQL 8.0.46 engine conversion | Existing Engine делает COPY/EXCLUSIVE, проверяет schema/data/index/counter и все выбранные таблицы | Нельзя подменить это одним ALTER+engine readback |
| `lock_wait_timeout` | Ограничивает ожидание metadata lock, **не** время копирования/FTS/index build | Не является execution deadline DDL |
| MySQL `max_execution_time` | Применяется к SELECT, не произвольному ALTER | Не обеспечивает короткое HTTP DDL |
| PHP execution limit | Мастер показывает фактическое значение текущего SAPI. На Unix PHP-время не равно надёжному wall-clock пределу DB operation | Нулевое/большое значение не делает операцию безопасной |
| FPM/веб-сервер/прокси/разрыв клиента | Их пределы/поведение не выводятся из PHP ini. Закрытие HTTP не является согласованным SQL cancel+receipt | Возможен неизвестный результат; нельзя повторять вслепую |
| Engine verification | Полные scans/hashes/sort, backup hash и проверки остальных таблиц могут занять больше ALTER | Даже быстрый ALTER не означает завершение всех проверок в HTTP budget |
| Durable identity/outcome | Прежний UI journal хранит **подготовку**, authoritative Engine state расположен отдельно | Нельзя объявлять его completion receipt или принимать только engine=InnoDB как успех |
| Exclusive window | Shared hosting/привилегированные подключения/cron/внешние writers не контролируются обычным ACP | Нужны проверяемые полномочия и подтверждение хостинга; никакого глобального mode switch здесь |

Ссылки для технической проверки: MySQL 8.0 [max_execution_time](https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_max_execution_time), [lock_wait_timeout](https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_lock_wait_timeout), PHP [set_time_limit](https://www.php.net/manual/en/function.set-time-limit.php). Установленные FPM/proxy limits, schema sizes и actual upper bounds недоступны из репозитория и не выдумываются.

Для будущего ACP-only допуска требуются измеренная и обеспеченная граница **всей** операции, серверный механизм проверки активного SQL thread/исхода после разрыва, private durable intent/receipt и доказанная сверка. Изменить CLI SAPI guard или увеличить max_execution_time недостаточно. Если эта цепочка не обеспечивается, **управляемая автономная операция хостинга** с job identity и журналом — альтернатива. Supervisor не считается обязательным только из-за своего названия. Shell/fire-and-forget и AJAX-цикл не добавлены.

## Что может измерить форум и чего он не доказывает

`RuntimeAssessment.php` выполняет только SHOW VARIABLES и SELECT агрегатов. Возвращаются counts/flags/числа, без query text, username, hostnames или private paths. Ошибки доступа/SHOW/SELECT превращаются в неизвестное значение, не PASS и не полный отказ остального UI.

Для DB storage используется осторожная эвристика: только при видимом MySQLi Unix socket и существующем локальном reported path выполняется realpath/disk_free_space. Даже тогда это **OBSERVED_NOT_PROVEN**: namespaces, mount и реальные DB volumes не доказаны. Для TCP/недоступных каталогов свободное место БД неизвестно. Свободное место PHP temp измеряется отдельно, не принимается за MySQL storage. DATA_LENGTH+INDEX_LENGTH — оценка таблицы, не гарантированный размер copy/temp/FTS/logs. Нет коэффициента, автоматически разрешающего ALTER. Провайдер должен проверить реальные volume/quota/inodes и measured peak budget.

Положительное число чужих соединений означает потенциальных writers и мешает запуску; 0 без доказанной полноты inventory не означает тишину. Enabled tasks — возможные операции, а не доказательство, что сейчас каждая исполняется. MySQL scheduler и PHP cron независимы. Сведения относятся к текущему диагностическому DB route, не гарантируют routing всех nested handlers.

## Maintenance: сверка с MyBB 1.8.41

Официальный tag 1.8.41, commit `cacad9f352043a1e36f21871c32beba46852f93e`: `global.php` ~1099 проверяет boardclosed с исключением групп `canviewboardclosed` и bypass страниц; это не DB write barrier. В `global.php` ~966 task image формируется отдельно. `task.php` подключает `inc/init.php`, вызывает `run_task()` напрямую или через shutdown; в `inc/functions_task.php` task locking пишет tasks и исполняет файл задания. Boardclosed не останавливает этот scheduler/cron, внешние процессы и уже активные requests.

Мастер показывает boardclosed и даёт ссылки на штатные MyBB settings/tasks, **не** меняет их автоматически, не отключает аддоны/награды и не запускает jobs. Нужны согласованные остановка PHP requests/cron/MyBB tasks/mail/jobs/external writers и контроль admin DB sessions. На shared MySQL глобальные offline/read_only режимы разрешает владелец сервера; UI их не включает. Из ACP нельзя обещать отключение всех writers.

## Сохранённые инварианты и unknown outcome

`tools/acs-engine-migration.php` и `tools/acs/EngineMigration.php` **в этом PR не изменены**. Сохраняются allowlist, exact source identity, strict schema/index/counter/data checks, local storage budgets, backup/evidence hash/cutoff, maintenance gates, CLI-only execution, single explicit ALTER, durable intent/file+directory fsync, no automatic retries/fallback/обратный ALTER.

Новые записи ACP **не** являются CLI config/approval/evidence PASS, не снимают его gates. Нет второго SQL-пути изменения таблиц. Формы не вызывают constructor/run Engine. Наличие двух записанных успешных заявлений оставляет HTTP apply недоступным по конкретным техническим причинам выше.

Повтор POST возвращает существующее состояние, устаревшая вкладка получает CAS conflict, смена optional scope требует явного выбора. Незавершённый job marker запрещает изменения/повторный start; read-only check/refresh разрешены. Состояние неизвестного внешнего Engine intent не объявляется отсутствующим. В UI даны действия: не повторять ALTER, попросить хостинг сверить actual engine/intent/receipt и выполнить read-only recovery, при расхождениях сохранять freeze и восстанавливать **полную согласованную** DB+files на отдельном экземпляре перед переключением. Обратная конверсия не является rollback.

## Файлы и проверки

Изменены существующие `modules/Administration/Migration/{Wizard,MigrationService,BackgroundWorker}.php`; добавлен `RuntimeAssessment.php` (пути относительно ACS). Расширен `tests/acs_migration_wizard.php`; добавлен этот отчёт. Нет JS, новой вкладки, SQL migrations, MyBB core/ACS game/runtime changes.

- `php -l` всех затронутых PHP; `node --check` не требуется — JS нет.
- Focused fixture: ACP/AF/native DB-tools права и CSRF; SELECT/SHOW-only observations; registration с dates/hash/method/result, invalid/future input refusal; replay и reload; scope invalidation staging; оба заявления не открывают apply; активные DB connections/unknown job оставляют start без job/DDL; отсутствие free-text evidence/UID/paths/secrets в export/HTML; controller POST→GET сохраняет report и checksum.
- `git diff --check`, ссылки. CLI execution не менялся, ALTER нигде не запускался, полного suite/нагрузочного/E2E нет. Runtime SQL проверен fake adapter-ом; live warprift.ru observations появятся при нажатии проверки в ACP. Локальный PHP 8.4.24 не выдаётся за installed FPM 8.5.12.

## Ручная приёмка без SSH/JSON

1. Развернуть PR, открыть прежний мастер, нажать «Проверить состояние базы и доступные ресурсы». Увидеть engines/размеры и раздельные доступные/неизвестные measurements.
2. Открыть раздел копии, пройти инструкцию хостинга. Зарегистрировать **реально выполненную** проверку с датами UTC, SHA-256, результатом и описанием. Проверить видимость checksum и подписи «заявление, не автоматическое доказательство».
3. После действительного теста установленной копии зарегистрировать staging-свидетельство. Ошибочный результат тоже можно сохранить; synthetic test разработчика не подставляется.
4. Обновить страницу и повторить POST: записи остаются, новой работы нет. Изменить выбранные optional tables — прежний staging не должен считаться актуальным для нового scope.
5. Проверить ссылки на MyBB settings/tasks и объяснения, почему boardclosed/0 visible connections/свободный PHP temp не являются разрешением.
6. Проверить конкретные причины невозможности безопасного HTTP ALTER и понятное задание хостингу. Кнопка запуска disabled; forged POST также не создаёт executable job. Не проводить production конверсию этой задачей.
7. Скачать безопасный отчёт; в нём нет введённого свободного текста/номера отчёта, private paths, UID, CSRF, raw SHOW CREATE или credentials. Проверить отказы без прав/CSRF.

Откат — revert файлов, без DDL/удаления пользовательских таблиц. Старые preparation journals читаются с отсутствующим evidence по умолчанию; сведения удалять для обхода защиты нельзя. После обновления старую открытую форму лучше обновить: request hash schema расширена. IC-MYBB-1 остаётся BLOCKED; к 4.1.4C не переходили.
