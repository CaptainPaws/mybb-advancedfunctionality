# ACS-4.1.4B-UI — мастер подготовки в ACP

Исходная main: `4857df384e1345cd08ee66733b3f47232f1d81a3` (PR #1009 с CLI EngineMigration действительно merged). Прочитаны четыре нормативных документа ACS, [4.1.4A](ACS_4_1_4A_ENGINE_MIGRATION_PLAN.md), [4.1.4B](ACS_4_1_4B_ENGINE_MIGRATION_TOOL.md), [4.1.4](ACS_4_1_4_POST_HOOK_CONFLICTS.md). Механика не меняется. **Production ALTER не выполнялся; IC-MYBB-1 остаётся BLOCKED.**

## Результат и граница выполнения

ACP → AdvancedCombatSystem → **Инструменты → Миграция БД** содержит русскоязычный мастер: описание/предупреждение, проверку таблиц, шаги backup/restore/staging, выбор exact scope, подготовку/подтверждение, состояние выполнения, журнал и безопасный экспорт. Подготовка работает без ручного JSON, PHP-команд или SQL в интерфейсе.

В текущем репозитории **нет проверенного автономного server worker/broker** или server-side каталога подтверждённых backup/staging артефактов. Поэтому полностью реализован **диагностический режим**; execution/подтверждение недоступны, никакой фиктивный job не создаётся. Мастер не выдаёт «миграция завершена» по одному engine=InnoDB: нужна полная проверка EngineMigration schema/data/index/counter baseline. Уже InnoDB помечается как не требующая повторного ALTER.

Это не второй механизм миграции. Execution Engine остаётся существующий `tools/acs/EngineMigration.php`; CLI entry и команды 4.1.4B сохранены для профессионального обслуживания. PHP CLI-файл class теперь допускает безопасную загрузку определения класса в ACP, но **constructor и run требуют PHP_SAPI=cli**. Новый static `readOnlyReport()` выполняет только ограниченные SELECT/SHOW CREATE: версия, allowlist tables/engines/размеры и предварительные известные schema blockers. Общий schemaUnsupported используется и CLI, и ACP. SHOW CREATE/raw definitions в браузер и экспорт не передаются. Полные coherent schema/trigger/privilege/digest/volume/maintenance gates 4.1.4B по-прежнему выполняет только Engine через автономный CLI.

## Изменённые файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `admin.php`: вкладка «Инструменты», явный route мастера; обычный bootstrap не менялся.
- `modules/Application/AuthorizationService.php`: отдельное `admin.migration.manage` — MyBB ACP + AF index + native MyBB Tools/optimizedb permission (либо штатный super-admin). Право диагностики отдельно; GM/наличие UID не дают grant.
- `modules/Administration/Migration/Wizard.php`: controller/presentation стандартного ACP, формы/CSRF, объяснения, выбор таблиц, disabled execution, экспорт.
- `.../MigrationService.php`: права, CSRF, exact scope, CAS/replay, Engine read-only invocation, safe report projection. Не содержит ALTER и не вызывает CLI constructor/run, shell или task runner.
- `.../MigrationJournal.php`: файловый журнал **подготовки**, revision/receipts/events и наблюдение существующего job; не заменяет authoritative CLI DDL intent/history.
- `.../BackgroundWorker.php`: честный BLOCKED provider; submit всегда отказывает. Никакого fire-and-forget или условного `worker_ready=true`.

Также `tools/acs/EngineMigration.php` (shared read-only observer + CLI guards), `tests/acs_migration_wizard.php`, этот отчёт. Нет JS/CSS, SQL migrations, изменений MyBB/других аддонов, activate/upgrade hooks или нового public API.

## Доступные шаги

1. **Проверить состояние базы** — POST+CSRF сохраняет результат; по БД только SELECT/SHOW. Десять таблиц Engine allowlist, logical names без prefix, размер data+indexes как оценка. Compatibility preliminary, без ложного READY. GET читает сохранённое состояние, не строит snapshot, не запускает jobs.
2. **Резервная копия** — объяснение полного согласованного backup+files и restore на отдельной среде. Picker пуст/disabled: нет проверенного artifact catalog. Checkbox, имя файла, наличие binary или произвольный отчёт не превращаются в PASS. Upload/dump download через HTTP отсутствуют.
3. **Тестовая проверка** — BLOCKED до evidence реальной приватной installed staging-копии. Synthetic fixture не признаётся доказательством production-совместимости.
4. **Подготовка** — exact выбранные таблицы сохраняются; обязательные forums/users/threads/posts исключить нельзя. Остальные шесть не выбираются автоматически. Подтверждение применимости optional scope — только заявление выбора, не evidence безопасного apply. Подготовка требует отдельного `admin.migration.manage`; diagnosis доступна по `admin.diagnostics.view`. Отсутствующие ресурсы/maintenance/engine-intent/worker перечислены явно.
5. **Подтверждение** — объяснение, контрольная фраза и disabled button. Сервер даже при forged POST требует права, CSRF, фразу, scope, текущую revision и заново проверяет диагностику; создаёт только BLOCKED preparation receipt. **Approval/config с secrets не генерируются**, потому что отсутствуют проверенные исходные значения. Это безопасная блокировка, а не требование пользователю писать JSON.
6. **Выполнение/отчёт** — confirmed Engine progress недоступен, это явно показано; нет выдуманного процента/успеха. Существующий job marker запрещает prepare/start, остаётся read-only диагностика/наблюдение. Закрытие/refresh не создаёт повторного запуска. События подготовки имеют timestamp/русскую подпись; export JSON содержит только safe whitelist.

Все отчёты сейчас BLOCKED независимо от числа InnoDB tables: нет подтверждённых backup, restore, staging, local space budgets, exclusive freeze и authoritative Engine journal. Unknown intent не объявляется отсутствующим.

## Долговременное хранение и безопасность

Auxiliary preparation journal хранится в выделенном `cache/acs-migration-<site hash>/` MyBB, directory 0700, journal 0600, PHP-файлы с `404; exit` header. Файл читается как JSON-пayload, **не include/eval/unserialize**. Он содержит только безопасные metadata, choices, revisions, hashed replay receipts и events; **никаких backup dumps, credentials, private artifact paths, raw SHOW CREATE или full Engine state**. Включённые PHP headers защищают HTTP запросы, пока PHP обслуживается согласно обычной модели безопасности MyBB; Unix permissions защищают от других local users. Полный server technical log не сериализуется в export.

Журнал сохраняется после PHP-request/browser restart. Обновляется под flock с CAS, fsync файла и atomic rename. 200 replay receipts/100 последних preparation events — технический предел; при достижении требуется server review, нет кнопки очистки/«повторить всё». Этот auxiliary cache-dir нельзя чистить во время подготовки. Удаление server cache может потерять **подготовку**, но не может разрешить ALTER: BackgroundWorker всегда BLOCKED. Для реального execution нужен authoritative durable private Engine journal **вне webroot**, как в 4.1.4B, а не этот вспомогательный cache.

Export — download data URL уже безопасной проекции, без дополнительного endpoint/внешней передачи. Whitelist исключает receipts, actor IDs, CSRF, config, paths, raw schema и произвольные поля журнала. HTML экранируется. Все ошибки показывают понятный текст, SQL exceptions/stack не возвращаются. Server error_log получает только стабильный category/code; доступ к нему должен быть закрыт средствами хоста. GET не пишет журнал и не запускает ALTER. Никаких MyBB table updates ради диагностики.

## Что нужно для активации автономного исполнения

Отсутствующие интеграции требуют отдельного проверяемого серверного контракта; их нельзя включить флагом ACP:

- Supervisor/system scheduler/managed host job API, запускающий реальный PHP CLI независимо от HTTP. Durable queue, job identity/PID ownership, single active job, heartbeat, cancellation/recovery/unknown-result protocol, credentials из server secret store и stable private artifacts/journal.
- Broker должен обеспечивать доступ ACP к состоянию, не требующий живого web-DB соединения во время exclusive offline Engine gates. Нельзя держать ACP соединение и обещать «отсутствие других connections» одновременно; текущий Engine осознанно это отклонит. Нужен подтверждённый out-of-band orchestration/status channel.
- Проверенный каталог backup/restore/staging evidence: реальные artifact IDs, hash/cutoff/restore binding, site/scope/version provenance. Мастер должен выбирать записи по безопасному ID, а server adapter формировать config/approval из проверенных данных без отправки secrets браузеру.
- Обеспеченная сервером остановка PHP/cron/tasks/mail/external/admin writers, разрешённое владельцем instance-wide maintenance и локальные storage budgets по реальному staging. Shared MySQL без разрешения владельца остаётся BLOCKED.
- Поздняя интеграция должна сохранить неизменённые gates, intent/recovery и single-table explicit apply EngineMigration, срок/хеш approval и запрет повторной миграции после verified completion. Показать финальный текст «Миграция завершена…» можно только по подтверждённому Engine verification, не по факту завершения процесса.

Обычная MyBB task внутри HTTP, proc_open с `&`, увеличение max_execution_time, наличие CLI binary или общий scheduler manifest не доказывают безопасность. В этом PR shell launch отсутствует вообще. CLI 4.1.4B не удалён; обычный пользователь мастера команды/JSON не вводит. Отдельный server adapter/worker ещё PROPOSED/BLOCKED, а не скрытая обязанность пользователя обходить UI.

## Проверки

- `php -l`: все изменённые/new PHP; JS отсутствует, node --check неприменим.
- `tests/acs_migration_wizard.php`: MyBB/AF deny до SQL, native optimizedb permission, CSRF/GET refusal, SELECT-only Engine observer, tables/sizes, persisted reload, CAS/key conflict/replay, отсутствие backup/restore/staging/worker/space evidence → BLOCKED, forged start не создаёт job, active job не перезапускается, safe HTML/export без canary credentials/prefix/paths/raw schema.
- Отдельный ограниченный HTTP fixture: EngineMigration constructor **и** run (даже при обходе constructor через trusted reflection fixture) возвращают `cli_execution_required` до config/DB access. Не браузерный E2E.
- Relative links, `git diff --check`. PHP CLI 8.4.24. MySQL conversions из 4.1.4B не повторялись: execution алгоритм не заменён, в этой задаче ни один ALTER не запускался. Полный suite/нагрузочный тест/E2E не выполнялись.

## Ручная приёмка

1. На warprift.ru открыть ACP → ACS → Инструменты → Миграция БД. Проверить русские объяснения и предупреждение.
2. Нажать «Проверить состояние базы»: увидеть logical tables, MyISAM/InnoDB, размеры и preliminary compatibility; SQL/пути/пароли не должны появиться.
3. Проверить, что optional tables не включены автоматически. Для сохранения подготовки нужны отдельные MyBB DB-tools права; устаревшая вкладка не перезаписывает новую.
4. Проверить причины backup/restore/staging/worker/space/maintenance/intent BLOCKED, disabled phrase/start, отсутствие checkbox «сделать безопасным».
5. Обновить страницу: сохранённые results/timestamp/scope остаются. Повтор POST с прежним key не создаёт новую работу. Никакой job или ALTER не запускается.
6. Скачать отчёт, проверить отсутствие secrets/prefix/raw definitions/receipts/paths. Проверить отказ пользователю без ACP/AF/DB-tools прав согласно типу операции.
7. Открыть обычную тему/ответ/ACS: новых runtime modules/hooks/JS нет. CLI emergency tooling доступен как прежде.

Откат — revert программных файлов/этого отчёта; пользовательские DB tables не менялись. Auxiliary journal не очищать для обхода защиты. Production миграция и ACS-4.1.4C не выполнялись.
