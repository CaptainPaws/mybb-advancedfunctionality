# ACS-4.1.4B — контролируемая подготовка MyISAM → InnoDB

Исходная main: `dc07a33014baabe1d6da8c0bccaab62e57bf7ee8` (merge PR #1008, утверждённый [план 4.1.4A](ACS_4_1_4A_ENGINE_MIGRATION_PLAN.md)). **Production миграция не выполнялась. IC-MYBB-1 остаётся BLOCKED.** Переход движков не устраняет отдельно известные hooks/locks/receipts/outbox/cache/shutdown gates.

Добавлены только standalone CLI `tools/acs-engine-migration.php`, библиотека `tools/acs/EngineMigration.php`, focused fixture `tests/acs_engine_migration.php` и этот runbook. Нет ACS migrations, activate/upgrade/ACP dispatch, Gateway, MyBB Core changes или изменения runtime аддонов. Скрипт отказывает вне PHP CLI.

## Область и ограничения

Строгий allowlist в порядке 4.1.4A: `threadsubscriptions`, `mailqueue`, `moderatorlog`, `datacache`, `privatemessages`, `attachments`, `forums`, `users`, `threads`, `posts`. Четыре последние обязательны; первые шесть выбираются **явно** по подтверждённым веткам. Конфигурация и отдельное разрешение перечисляют точный одинаковый список в этом порядке. Любые другие таблицы, повторения, другой порядок или отсутствие обязательного ядра — отказ. `threadsread/forumsread`, captcha и ACS tables не включены: для расширения требуется отдельное согласование/PR.

Обычный reply с включёнными subscriptions/PM/email/attachments/modoptions может требовать условные таблицы. Выбор только четырёх не разрешает молча отключить уведомления/награды или объявить полную публикацию безопасной.

Поддержка apply намеренно ограничена **MySQL 8.0.46**, локальным Unix socket, Linux local filesystem, PHP CLI с mysqli/JSON/fsync/proc_open, `/usr/bin/sort`. Нет MariaDB fallback, remote-storage guesses, online-copy fallback, игнорирования SQL errors, автоматического исправления данных или пересоздания tables. Неподдержанная installed схема остаётся BLOCKED; тестовая синтетическая схема не заменяет staging-копию форума.

## Что проверяется

- Идентичность server UUID/schema/prefix/version, точный scope и hash конфигурации. Credentials читаются из файла, не передаются в аргументах CLI и не печатаются в ошибках.
- Backup: доступный непустой private файл, полный SHA256; три private JSON evidence-файла также проверяются по SHA256. Restore report обязан ссылаться **на этот** backup hash; его backup cutoff не раньше начала freeze, verified time не раньше backup и не в будущем. Staging report: pass, MySQL 8.0.46 и точный table scope.
- Это **проверка артефактов и заявления ответственного DBA**, а не программное доказательство, что backup полный/восстанавливаемый или что restore действительно выполнялся. Ложное evidence нельзя считать безопасностью. Ответственный обязан приложить правдивые результаты rehearsal и полный backup по 4.1.4A.
- Свободное место: явно заданные положительные бюджеты для actual datadir/tmpdir/innodb_tmpdir (если задан), state dir и backup dir. Пути должны быть видимы на том же сервере; для одного filesystem суммы бюджетов складываются, а не проверяются независимо. Пороги выбираются по staging peak copy/FTS/sort/temp/binlog/redo и запасу, не по вымышленному коэффициенту. Check не резервирует место: превышение бюджета во время DDL всё ещё возможно.
- Серверная maintenance-граница: `offline_mode=ON`, `read_only=ON`, `super_read_only=OFF`, event scheduler OFF/DISABLED, autocommit=1; отсутствие других foreground **пользовательских** соединений и replica applier. Внутренний GTID daemon без PROCESSLIST_USER не считается клиентским writer. Performance_schema должен быть включён, счётчик потерянных thread instances — 0. Требуются настоящие performance_schema/replica metadata privileges; нет обхода недоступных проверок.
- DBA запускает CLI привилегированным maintenance-пользователем, которому разрешён DDL при read_only, но блокирует обычных writers. Сам инструмент **не переключает** серверные modes и не отключает hooks. На shared host такое окно требует согласования владельца MySQL; при невозможности — BLOCKED, не упрощение gates.
- Привилегированного суперпользователя, подключившегося после проверки, инструмент физически запретить не может. Обязательны эксклюзивное DBA-окно, остановленные PHP/cron/mail/jobs/external writers и отдельная attestation. offline_mode не заменяет контроль всех административных каналов.
- Current engine только MyISAM/InnoDB; таблица существует. SHOW CREATE, все columns/index definitions (без оценочной Cardinality), collation. Partitioned/generated/spatial/FK и несовместимые ROW_FORMAT требуют отдельного аудита и отклоняются. Triggers выбранных таблиц отклоняются; для достоверности пустого inventory требуется явный direct **global TRIGGER/ALL grant**. Role-only/table-level grants не интерпретируются как доказательство: этот консервативный инструмент откажет; не расширять права приложения ради него.
- Данные: точное число и multiset SHA256 строк, включая duplicates, NULL, binary bytes, zero ID. Каждое поле читается как BINARY, length-prefixed, строки хешируются и сортируются в private scratch ограниченной памятью. Содержимое строк не пишется в state/repo. Scratch содержит только hashes; он всё равно приватный и требует места примерно для двух наборов 65-byte row hashes плюс sort temp.
- AUTO_INCREMENT high-water сохраняется явной option того же ALTER; после DDL проверяется точное равенство baseline, не MAX+1. Данные/индексы/charset/collation/schema сверяются после **каждой** таблицы; остальные выбранные таблицы тоже проверяются на drift. No-PK moderatorlog поддерживается как multiset, без выдуманного ID.

SHOW CREATE допускает только смену ENGINE и заранее сохранённый AUTO_INCREMENT. Неожиданное изменение прочих options/indexes/columns не принимается. FULLTEXT сохраняется/проверяется; реальная семантика поиска и пиковые ресурсы FTS остаются частью staging acceptance.

## Команды и журнал

Все пути — private, **вне webroot/repo**, directory mode 0700, файлы 0600. Local state содержит schema/DDL и логические hashes, его нельзя публиковать. Не редактировать state.json/approval для обхода отказов.

```sh
php tools/acs-engine-migration.php inspect /secure/acs/config.json /secure/acs/state
php tools/acs-engine-migration.php capture /secure/acs/config.json /secure/acs/state
php tools/acs-engine-migration.php verify /secure/acs/config.json /secure/acs/state
php tools/acs-engine-migration.php apply-next /secure/acs/config.json /secure/acs/state /secure/acs/approval.json
php tools/acs-engine-migration.php recover /secure/acs/config.json /secure/acs/state /secure/acs/approval.json
```

- `inspect`: read-only schema observations, не coherent data baseline и не approval. Сохранять stdout только приватно.
- `capture`: после backup/freeze/evidence/gates строит первоначальный baseline и выдаёт baseline hash. Отказывает, если state уже существует. DDL не выполняет.
- `apply-next`: **не более одного ALTER за процесс**, строго следующая выбранная MyISAM table. Отдельный scoped approval обязателен. Если выбранная таблица изначально InnoDB — проверяется, не конвертируется снова. Перед каждым новым запуском администратор смотрит итог предыдущего; циклический auto-run не предусмотрен.
- `verify`: read-only по БД, без ALTER/receipts/journal updates; local lock/scratch нужны для чтения. Повторяемая проверка baseline и ожидаемых engines. Выполнять при сохранённом freeze до открытия writes: после реальных новых reply логические digests закономерно изменятся. Для live metadata использовать inspect; mismatch не даёт команды удалять новые данные.
- `recover`: **без ALTER**, только при unresolved durable intent, после тех же backup/maintenance/approval gates. Если actual table целая MyISAM с исходными schema/data/auto — записывает not-applied outcome. Если actual InnoDB с точно теми же invariants — восстанавливает missing completion receipt. Любое иное состояние — остановка/backup recovery, не повторный ALTER. Не разрешает продолжение, пока прежний SQL thread ещё активен.

Local flock сериализует один state directory, server GET_LOCK сериализует tool по server/schema/prefix. Advisory lock **не** служит защитой от обычных writers; это только mutex инструмента. Нет START/COMMIT/ROLLBACK как фиктивной защиты DDL.

Перед SQL атомарно записывается intent: flush/fsync файла, rename и fsync directory. После проверок — completion/history. Power loss/kill до, во время или после DDL оставляет неопределённый outcome: apply/verify требуют recover, если intent остался. Если завершённый state успел надёжно сохраниться — повторный apply лишь проверяет его и не повторяет эту таблицу. Требуется Linux filesystem с рабочим directory fsync; иное окружение откажет до ALTER.

Конкретный statement строится только из allowlist, валидированного prefix и baseline decimal counter:

```sql
ALTER TABLE `VERIFIED_PREFIXposts`
  ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE, AUTO_INCREMENT=VERIFIED_SAVED_NEXT;
```

AUTO_INCREMENT option отсутствует для таблиц без этого поля. DDL auto-commit не позволяет вернуть весь набор SQL ROLLBACK; после частичного завершения сохраняется смешанный set. Нет автоматического DROP, REPAIR, обратного ENGINE=MyISAM или retry другим алгоритмом.

## Конфигурация и разрешение

Это **не готовая production конфигурация**. Подставлять реальные private значения после staging и подтверждения exact conditional scope. Ниже только mandatory core; conditional таблицы вставляются в установленном allowlist порядке, если approved pipeline их использует.

```json
{
  "socket": "/verified/local/mysql.sock",
  "database": "VERIFIED_DATABASE",
  "prefix": "VERIFIED_PREFIX",
  "user": "MAINTENANCE_DBA",
  "password": "PRIVATE_VALUE",
  "tables": ["forums", "users", "threads", "posts"],
  "backup": {"path": "/secure/backup/forum.sql", "sha256": "REAL_64_HEX_HASH"},
  "restore_evidence": {"path": "/secure/acs/restore.json", "sha256": "REAL_64_HEX_HASH"},
  "maintenance_evidence": {"path": "/secure/acs/freeze.json", "sha256": "REAL_64_HEX_HASH"},
  "staging_evidence": {"path": "/secure/acs/staging.json", "sha256": "REAL_64_HEX_HASH"},
  "space": [
    {"path": "/verified/datadir", "minimum_free_bytes": 123456789},
    {"path": "/verified/tmpdir", "minimum_free_bytes": 123456789},
    {"path": "/secure/acs/state", "minimum_free_bytes": 123456789},
    {"path": "/secure/backup", "minimum_free_bytes": 123456789}
  ]
}
```

Значения bytes — **placeholders, не оценка достаточного места**. Если innodb_tmpdir отдельный — добавить. Если директории совпадают, разрешается одна запись с суммарным бюджетом; инструмент требует минимум три budget entries для полной явной конфигурации, при совпадении путей можно повторить роли с раздельными компонентами, которые будут суммированы на filesystem. Для BLOB/digest/temp больших таблиц учесть полный scan/sort и повторное чтение backup SHA256 в каждом apply/recover. Это увеличивает downtime относительно одного только ALTER; измерить полный workflow, не обещать мгновенную конверсию.

Evidence JSON обязательные поля (дополнительные описательные результаты DBA можно хранить рядом):

- Restore: `backup_sha256`, `result:"pass"`, `backup_captured_at` и `verified_at` — UTC epoch integers; `freeze_started_at ≤ backup_captured_at ≤ verified_at ≤ now`.
- Maintenance: `freeze_started_at` — положительный UTC epoch integer, `writers_stopped:true`.
- Staging: `result:"pass"`, `mysql_version:"8.0.46"`, `tables` — exact selected list. Ответственный обязан подтвердить schema/данные/indexes/FTS, фактические дисковые budgets и сроки, не только создать JSON.

После capture вручную создать **отдельный** private approval, подставив полученный hash и реальный срок окна:

```json
{
  "baseline_hash": "HASH_FROM_CAPTURE",
  "tables": ["forums", "users", "threads", "posts"],
  "backup_sha256": "REAL_64_HEX_HASH",
  "approved_by": "RESPONSIBLE_DBA",
  "expires_at": 0,
  "all_writers_stopped": true,
  "same_host_storage_verified": true,
  "backup_restore_tested": true,
  "staging_passed": true
}
```

`expires_at:0` намеренно **невалиден**: нужен будущий epoch в согласованном окне. Approval не выдаётся инструментом автоматически. После capture менять config/scope/evidence/budgets нельзя — state привязан к hash config. При истечении окна допускается новый явно одобренный approval для того же baseline, после повторной проверки backup/freeze; scope не расширяется. Credential/config rotation в незавершённой операции потребует отдельного DBA recovery, не редактирования baseline для обхода проверки.

## Порядок администратора

1. Получить actual SHOW CREATE/indexes, sizes/counts/AUTO_INCREMENT, TRIGGER privilege evidence, FULLTEXT parser/token settings, storage budget и actual frontend write set по 4.1.4A. До получения нет допуска к production apply.
2. На изолированной приватной **копии установленной схемы/данных** MySQL 8.0.46 пройти весь workflow, включая restore и interrupted outcome. Сохранить закрытые evidence и measured downtime/RTO. Synthetic fixture ниже проверяет программу, а не заменяет этот шаг.
3. Назначить окно, остановить приём writes/активные PHP/cron/jobs/mail/external writers и прочие DBA sessions, выставить согласованные серверные modes. CLI этого не делает; не включать instance-wide offline/read_only на shared сервере без допуска владельца.
4. Снять согласованный полный backup **смешанной БД**, включая ACS и owner files; `--single-transaction` не даёт consistent MyISAM snapshot. Проверить restore/хеши и оформить evidence с cutoff. Сам инструмент не читает пользовательские строки из backup и не проверяет семантику restore.
5. Создать private state dir, config, evidence. Inspect, затем capture; проверить результаты, сформировать approval. Сохранить baseline и контрольные показатели на отдельном носителе.
6. Выполнить один apply-next. Сверить JSON и state, диск и оставшееся окно. Затем следующая явная команда. При любой ошибке остановиться; unresolved intent — recover, без нового capture или удаления state.
7. После последней таблицы дважды verify при freeze. Дополнительно approved orphan/links/counters/search проверки 4.1.4A: они не подменены row digest. Инструмент не меняет/пересчитывает данные для устранения старых расхождений.
8. Согласованно восстановить **прежние**, заранее записанные server modes и ordinary forum/worker доступ после приёмки. Не забыть, что пользователи с CONNECTION_ADMIN могут писать даже в read_only. Проверить ordinary reply/маску/notifications/Balance/EXP/Quick Edit. После открытия writes новый post не обязан совпадать с предмиграционным digest.
9. Боевая публикация всё ещё отключена. Отдельно закрыть coordinator/locks/receipts/outbox/cache/shutdown gates и итоговую проверку IC-MYBB-1.

## Recovery из backup

Сохранить maintenance и журнал. Сначала read-only recover выясняет outcome **одной** намеренной операции; он ничего не чинит. При schema/data/counter drift или недостаточном доказательстве не продолжать sequence. Не удалять intent, не делать повторный capture «чтобы прошло», не выполнять обратный ALTER как rollback.

Восстановить полный согласованный backup DB + files на чистый изолированный MySQL 8.0.46 с отключённой внешней доставкой/event scheduler/jobs; проверить destination, exit status импорта, hashes/schema/IDs/counters/links и прежние engines. Не импортировать backup posts отдельно поверх новых ACS/Balance rows. Проверенный порядок dump/restore и PITR изложен в [4.1.4A §7, §10](ACS_4_1_4A_ENGINE_MIGRATION_PLAN.md). Только после верификации переключать production endpoint; неуспешную копию сохранить для анализа. Если после cutoff были новые writes — отдельное решение о reconciliation/PITR, не молчаливое удаление новых данных. SQL restore не отзывает уже доставленные письма.

## Выполненные проверки

- Изолированный MySQL **8.0.46** (официальный образ, контейнер без сети), PHP CLI **8.4.24**. Только схема `acs414b_test`, prefix `fixture_`, синтетические данные; production недоступен.
- `tests/acs_engine_migration.php`: десять allowlisted таблиц MyISAM→InnoDB, duplicates/no-PK, explicit zero IDs, NULL/BLOB/русский текст, PREFIX и FULLTEXT indexes, AUTO_INCREMENT high-water 1000; после каждого шага schema/index/charset/count/digest/counter сверяются с baseline.
- Refusal gates: maintenance выключен, другое подключение, недостаточный budget, повреждённый backup, expired approval; drift данных и неизвестный индекс отклоняются.
- Два моделируемых persisted crash состояния: intent до SQL и completed DDL без receipt. Проверены explicit recover без ALTER, запрет обычного продолжения при intent, повтор verify, отсутствие повторного ALTER для завершённых таблиц. Это моделирование границ журнала, **не kill/power-loss stress test**.
- `php -l` трёх новых PHP, `git diff --check`. Полный suite, нагрузочный/E2E не запускались.

Синтетические evidence/backup fixtures используются только для проверки validation gates. Production-compatible private restore evidence, actual table schema, MySQL host budgets и PHP 8.5.12 требуют ручной staging-приёмки. Сам переход форума и следующие задачи не выполнялись.
