# ACS-4.1.4B-UI-4 — компактный ACP и фактическое выполнение

Исходная main: `f3d267ab29daa55f3e56dd01f9e6c29c2689e585`. Сохранена одна вкладка ACS → Инструменты → Миграция БД. Этот PR реализует последнее задание UI-4; промежуточные пожелания UI-3/исправления backup учтены без создания параллельных страниц/PR. **На warprift.ru никаких SQL/ALTER не выполнялось. IC-MYBB-1 не READY.**

Источники: четыре нормативных документа ACS, [4.1.4A](ACS_4_1_4A_ENGINE_MIGRATION_PLAN.md), [4.1.4B](ACS_4_1_4B_ENGINE_MIGRATION_TOOL.md), отчёты [UI](ACS_4_1_4B_UI_MIGRATION_WIZARD.md), [UI-2](ACS_4_1_4B_UI_2_USABILITY.md). Последнее явное разрешение заказчика заменяет требование ручных backup/staging документов для **ACP admission** одним заявлением о backup. Это не доказательство восстановления; прежние CLI gates сохранены для CLI. Публикационные и игровые правила не изменены.

## Что видит администратор

- Назначение инструмента, десять разрешённых таблиц с engines/размерами, количество MyISAM и суммарную оценку.
- Кнопку «Проверить готовность», указанное заказчиком предупреждение, **один** checkbox о наличии backup и «Выполнить миграцию».
- После подтверждённого шага — «Продолжить миграцию: следующая таблица», без повторного backup checkbox/контрольной фразы. Каждый запрос преобразует не более одной таблицы, автоматического AJAX-цикла нет.
- При неизвестном исходе — «Проверить результат», а не повтор ALTER. Обновление страницы только читает journal. Если шаг ещё выполняется, проверка не запускает SHOW CREATE на заблокированных таблицах.
- Число N из M, текущую таблицу/неподтверждённый исход, проверенные результаты, ошибки. Нет выдуманного процента копирования.
- Завершение: «Миграция завершена. Все выбранные таблицы переведены на InnoDB». Если при проверке всё уже InnoDB — «Миграция не требуется». Повторный start запрещён.
- Под раскрываемыми «Техническими подробностями» — ограничения, история и восстановление. Export v3 — безопасные metadata/progress/error codes, без credentials, путей, raw schema, actor IDs или прежнего свободного текста evidence.

Нет форм SHA-256/дат/восстановления/staging, ввода JSON, CLI state или требования supervisor. Старые backup/staging записи **не удаляются**. Для совместимости старый parser понимает `not_checked` с `verified_at:null` и пустым методом; успешное восстановление никогда не присваивается автоматически. Эти записи не являются разрешением новой ACP-команды.

## Как обеспечено исполнение без worker

Прежний отказ «HTTP не доказан» заменён ограниченным работающим режимом **на том же EngineMigration**. Задание не исполняет DDL через MyBB task, shell или второй SQL-converter.

1. `AcpExecution` проверяет `admin.migration.manage`: MyBB ACP, AF index и native Tools/optimizedb (либо штатный super-admin), POST/CSRF, backup statement, закрытый MyBB, operation key и CAS. Все SQL identifiers и список преобразования — server allowlist, не параметры клиента.
2. В preparation journal до исполнения фиксируется job/request/backup statement. Повтор того же ключа возвращает существующее состояние. Остальные операции при running/unknown не стартуют новый ALTER.
3. Отдельный MySQLi connection создаётся из **серверной** конфигурации MyBB с connect/read timeout 2 s, без передачи secrets браузеру. Сверяются DB/server UUID/prefix с текущим форумом; split/remote соединения не принимаются. Web connection не используется как async DDL connection и не попадает в «commands out of sync» при потере результата.
4. Общий server GET_LOCK mutex Engine защищает от одновременных ACP/CLI apply. Отдельный file flock защищает ACP journal. Нет фиктивного START/ROLLBACK вокруг DDL.
5. **Физическая граница записи:** отдельное соединение берёт `LOCK TABLES … WRITE` на все существующие BASE TABLES **только текущей схемы**, включая не конвертируемые. Это временная блокировка, **не изменение** дополнительных/ACS tables. Не нужны глобальные offline/read_only режимы. На изолированном MySQL 8.0.46 подтверждено, что блокировка других MyISAM и InnoDB таблиц сохраняется после ALTER выбранной таблицы.
6. До lock проверены полный inventory triggers с direct schema/global TRIGGER grant, отсутствие triggers/связей с другими DB, replica applier, предел каталога ≤512 tables. Неизвестные transitive dependencies не получают слепое разрешение. Смена каталога приводит к отказу.
7. Начальный schema baseline и **fresh before snapshot конкретной таблицы под write barrier** используют прежние metadata/digest/compare алгоритмы. Выполняется один `ALTER … ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE, AUTO_INCREMENT=<current saved high-water>`; для таблиц без auto option отсутствует.
8. После него под теми же locks сверяются schema/columns/indexes/collation, полный multiset count+digest и AUTO_INCREMENT этой таблицы. Схема остальных выбранных таблиц проверяется на неожиданные изменения. Только после проверок durable completion receipt записывается, затем locks освобождаются.

MyBB/cron/external clients **не могут писать в эти таблицы, пока держится DB barrier**: конфликтующий запрос ждёт/получает ошибку. Между отдельными HTTP-запросами locks не удерживаются, поэтому форум надо закрыть и jobs остановить. Нормальные обновления данных между шагами не уничтожаются: следующий шаг захватывает свежий before snapshot под новым lock. Уже проверенные операции не переисполняются. Неожиданная **схема/engine** между шагами отклоняется. Это не обещание глобальной тишины всех приложений/схем MySQL и не атомарная миграция десяти таблиц.

## Общий механизм целостности

Изменён `tools/acs/EngineMigration.php`, а не создан независимый validator. Оба режима используют `metadata`, общий `compareStructure/compare`, `snapshot`, единое row hash encoding и multiset digest, проверку counters, source identity и fsync/rename.

- CLI сохраняет прежние команды/gates, полный frozen baseline, evidence hashes/approval и внешний bounded-memory sort. Существующий CLI regression проверен отдельно.
- ACP использует bounded in-memory sort того же набора 64-hex row hashes: тот же hash policy/дубликаты/NULL/BINARY, без shell/process запуска из HTTP. Лимит: **16 МиБ и 50 000 строк на таблицу**, 8 МиБ state, не менее 64 МиБ свободного PHP memory budget.
- Перед **каждым** DDL ACP durable intent содержит таблицу, connection ID и hash fresh before snapshot. File+directory fsync обязательны. Completion/history появляются только после верификации. Частично выполненный набор — нормальное наблюдаемое состояние, не SQL rollback.
- ACP authoritative journal — `.maintenance-<site/prefix hash>/engine-state.php` внутри каталога ACS, dir 0700/files 0600 и PHP `404; exit` header. Temp/lock также `.php`, не публичный `.tmp` с raw JSON. Читается как данные, не include/eval. Whole-journal hash обнаруживает повреждения; baseline hash и identity дополнительно проверяются. Каталог добавлен в `.gitignore`.
- Auxiliary preparation journal в MyBB cache остаётся отдельным UI-журналом; его нельзя считать completion receipt. Потерянное UI-подтверждение восстанавливается из authoritative Engine state. При потере UI cache можно повторно заявить backup для read-only recovery; Engine intent/history не обнуляются.

Нельзя удалять/менять state для обхода ошибки или смешивать независимые CLI/ACP directories. Если до первого ACP запуска уже использовался CLI, провайдер должен проверить его private journal/unknown outcome. Общий mutex не заменяет знание отдельного старого файлового журнала. В этой истории production CLI/ACP не запускались.

## Время и неизвестный исход

ACP step имеет мягкий wall-clock budget 10 s, session metadata/row lock waits 2 s, connect/read timeouts 2 s. SQL ALTER подаётся через MYSQLI_ASYNC и контролируется mysqli_poll. При превышении budget отдельное служебное соединение запрашивает **KILL QUERY только своего текущего DDL connection ID**, после чего **не объявляет rollback/успех**, а оставляет durable intent и unknown outcome. Отмена/откат на сервере может продолжаться. При раннем FPM/proxy/process abort intent тоже остаётся.

Это не обещание абсолютного завершения SQL за 10 s: PHP/FPM/network/rollback могут прерваться или занять больше времени. Безопасность обеспечивается тем, что неизвестный результат **не** допускает новый ALTER. После разрыва:

- GET читает journal/progress, не запускает работу.
- Recover POST берёт те же mutex/barrier; если прежний SQL ещё активен/держит locks, получает отказ, не запускает новую конверсию.
- При подтверждённом InnoDB и полном совпадении с сохранённым **pending before** восстанавливает missing completion; при подтверждённом MyISAM и совпадении фиксирует не выполненный/откатившийся шаг. Recover не содержит ALTER.
- Если данные pending таблицы изменились после обрыва или проверка не завершилась — **не** строится новый baseline для обхода. Нужен разбор администратором/хостингом. Не удалять новые данные ради совпадения; восстановление из backup — отдельное контролируемое решение.

## Реальные ограничения сервера

Режим выполняет DDL только при успешном прохождении технических gates. Он не включает global modes и не расширяет привилегии.

- MySQL **8.0.46**, PHP MySQLnd async API/fsync, согласованный локальный MySQLi Unix socket, та же DB identity; hostname PHP/MySQL должен совпадать. Удалённый DB/jail/несовпавший namespace — конкретный отказ, а не требование staging-формы.
- Доступные локальные datadir/tmpdir и journal filesystem: минимум 256 МиБ свободно на проверяемых путях. Это консервативный floor для ограниченного режима, не гарантия любого storage/quota/FTS peak; фактический ENOSPC/SQL error всё равно останавливает последовательность с intent.
- Права LOCK TABLES/SELECT/ALTER в схеме, достоверное TRIGGER visibility и доступ к metadata репликации. Если shared hosting запрещает эти действия, нужен **управляемый запуск владельцем хостинга**, а не выдача приложению SUPER или обход проверки.
- Triggers, cross-DB FK dependencies, partitioning/unsupported schema, >512 tables, >16 МиБ/50k rows, большой state, недостаток памяти/места, pending operation, drift — отказ с понятным сообщением. Большие таблицы требуют прежнего профессионального CLI/управляемой операции хостинга с тем же ядром.
- MyBB boardclosed обязателен для ACP-команды, но не считается доказательством полной остановки cron/external writers. Во время реального шага их записи блокируются DB locks; после выхода из шага внешний writer может продолжить. Сохраняйте maintenance и не делайте административные DDL между шагами.

Таким образом **без консоли можно выполнить ограниченную миграцию** в поддерживаемом локальном окружении с нужными правами. Соответствие установленного warprift.ru этим gates заранее из repo не утверждается: UI показывает реальную ошибку сервера. Проверка backup остаётся честным заявлением по последнему ТЗ; сам инструмент не создаёт dump, не вычисляет вымышленные SHA и не обещает восстановление без реальной копии.

## Файлы

- ACS `modules/Administration/Migration/Wizard.php`: компактный UI, progress/recovery/manual next, без evidence форм.
- Новый `.../AcpExecution.php`: авторизация, idempotent job/CAS, trusted connection factory, интеграция Engine.
- `.../MigrationService.php`: совместимость старых evidence records, `not_checked`/null дата, безопасный legacy export; старые записи не мигрируются/не удаляются.
- `tools/acs/EngineMigration.php`: общий kernel, явный ACP entry, bounded async step, barrier/journal/recovery; CLI сохранён.
- ACS `.gitignore`: private runtime journal.
- `tests/acs_migration_acp_execution.php`, обновлён `tests/acs_migration_wizard.php`, этот отчёт.

## Проверки

Только локальная изолированная MySQL **8.0.46**, сеть контейнера отключена, схема `acs414b_test`, prefix `fixture_`, синтетические данные; PHP **8.4.24**. Настройки/DDL fixture никогда не адресованы production.

- Actual LOCK+ALTER probe: параллельный INSERT в другую MyISAM **и** InnoDB таблицу остаётся заблокированным до UNLOCK после преобразования целевой таблицы.
- Actual ACP kernel/service: один ALTER за запрос, десять последовательных таблиц, права/CSRF/backup/boardclosed, повтор key без DDL, already-InnoDB/no-change, отказ повторного completed start.
- FULLTEXT/prefix indexes, no-PK дубликаты, NULL/BLOB/русский текст/zero IDs, AUTO_INCREMENT high-water и row count/digest проверены после каждой таблицы.
- Изменение схемы после одного успешного шага останавливает sequence; ранее успешный результат сохраняется. Легитимное изменение данных между шагами сохраняется fresh before/after сравнением, не теряется и не заменяется старым snapshot.
- Missing completion симулирован persisted intent при уже выполненном DDL: recover подтверждает исход без повторного ALTER.
- Test-only native driver задерживает SQL через SLEEP вместо ALTER: soft deadline → KILL/unknown intent → normal recover MyISAM. Это проверка cancellation branch, **не** фиктивная production готовность или latency benchmark реального ALTER.
- Реальный local HTTP fixture: клиент закрыл соединение без чтения результата; GET вернул выполненный шаг, replay POST не повторил ALTER, explicit next сделал одну следующую таблицу. Это ограниченный integration check, не браузерный E2E.
- Существующий targeted CLI regression и ACP UI fixture; php -l всех PHP, ссылки, git diff --check. Полный suite/нагрузочные тесты не запускались. PHP 8.5.12/FPM/реальная installed schema требуют ручной приёмки, не объявлены проверенными.

## Администратору — несколько действий

1. Создать backup через панель хостинга; закрыть MyBB и остановить jobs/external changes. Backup checkbox — заявление, не гарантия SQL rollback.
2. Открыть существующий мастер, нажать «Проверить готовность», просмотреть список/размеры. Отметить backup и нажать «Выполнить миграцию».
3. После каждого подтверждённого шага нажимать «Продолжить». При потере ответа — обновить страницу; если исход неизвестен, только «Проверить результат». Не перезапускать вслепую и не очищать journal.
4. При конкретном отказе среды передать безопасный отчёт хостингу. При drift сохранить maintenance и разбирать исход/восстановление полной согласованной копии на отдельном экземпляре перед переключением; обратный ALTER не rollback.
5. После подтверждённого завершения проверить ordinary reply/маску/награды/уведомления и открыть форум. IC-MYBB-1 всё ещё требует отдельной проверки post hooks/coordinator.

Деактивация/обновление ACS не запускает миграцию. Откат программного PR не откатывает уже выполненный DDL: runtime journals нужно сохранить, а восстановление данных — только из проверенной копии по отдельному решению. Следующие задачи автоматически не выполнялись.
