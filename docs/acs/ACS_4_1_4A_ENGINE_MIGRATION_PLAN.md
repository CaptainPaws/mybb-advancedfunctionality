# ACS-4.1.4A — аудит MyISAM и план транзакционного write set MyBB

> Исторический отчёт. По ACS Stage 4 Revision 2 мигратор полностью удалён из репозитория; приведённые пути и команды больше не действуют. Актуальное состояние: [ACS-4.0-CLEANUP](ACS_4_0_CLEANUP.md).

## 1. Результат и доказательства

**Это план, не выполненная миграция. IC-MYBB-1 остаётся BLOCKED.** Никакие production-запросы, ALTER TABLE, преобразования движков, изменения ядра/ACS или копирование пользовательских данных в репозиторий не выполнялись.

Проверенная main: `b90a0f93e0ab245472b73d5202561207a77afb63`. PR **#1007** действительно merged в main (`b90a0f93`, содержит `fd71c0ae`); [4.1.4](ACS_4_1_4_POST_HOOK_CONFLICTS.md) сверен также с commit `fd71c0ae` ветки `codex/acs-4-1-4-appearance-uow`. Наличие owner-side Appearance port не означает наличие работающего ACS publication coordinator.

Прочитаны [Master](ACS_MASTER_SPECIFICATION.md), [Architecture §7–8](ACS_TECHNICAL_ARCHITECTURE.md), [Integration Contracts](ACS_INTEGRATION_CONTRACTS.md), [Roadmap этап 4](ACS_IMPLEMENTATION_ROADMAP.md), [аудит 4.1.1](ACS_4_1_1_PUBLICATION_AUDIT.md), [контракт 4.1.2](ACS_4_1_2_GATEWAY_CONTRACT.md), [диагностика 4.1.3](ACS_4_1_3_ENVIRONMENT_DIAGNOSTICS.md), [конфликты 4.1.4](ACS_4_1_4_POST_HOOK_CONFLICTS.md). Механика и нормативный baseline не меняются.

Источники различаются:

- **Сообщённые заказчиком наблюдения ACP:** MyBB 1.8.41, PHP 8.5.12, MySQL 8.0.46-0ubuntu0.22.04.4, DB_MySQLi; engines ниже, один read/write handle в текущем ACP запросе; enabled AF integrations. Самостоятельного доступа к установленной БД/файлам нет.
- **Проверенный официальный MyBB 1.8.41:** tag `mybb_1841`, commit [`cacad9f352043a1e36f21871c32beba46852f93e`](https://github.com/mybb/mybb/tree/cacad9f352043a1e36f21871c32beba46852f93e). Исходники скачаны только во временный каталог вне repo; прочитаны reply/Post/PM handlers, functions, indicators/subscriptions, cache, driver, install schema и создание FULLTEXT.
- **Текущий AF:** source-аудит известных прямых/вложенных writers, не трассировка установленного frontend.

Все четыре хеша из ACP **совпадают с официальной 1.8.41**:

| Файл | SHA256 1.8.41 / переданный ACP |
|---|---|
| `inc/datahandlers/post.php` | `9d7a5aee7cf100bff0cbd918934b16524f3d556e94ced4719bef97622c6a5d13` |
| `newreply.php` | `affb30736516ac15f7597424b27df39c074093bbed8922e54e121bda9fdc4f43` |
| `inc/db_mysqli.php` | `99f7077ef3dd5c2032e9a690dfd50f4b707147d7ec43621ed5bc875cb8edde4a` |
| `inc/class_core.php` | `6fe9f32d470218e4fc0b15e9631007d9108c1e2cae53f8abf1862ec771488d7b` |

Предыдущие WARNING относительно 1.8.40 для newreply/core не являются доказательством локальной модификации. Совпадение четырёх файлов не подтверждает остальные functions, PM, plugins, triggers, настройки и ветки исполнения.

## 2. Сценарий и граница write set

Базовый сценарий **R0**: авторизованный пользователь, новый видимый reply в существующую тему, не native draft/merge/новая тема; без modoptions и новых attachments. Подписки/настройки уведомлений не предполагаются выключенными. Полный ordinary reply дополнительно включает перечисленные ниже условные ветки. Это классификация аудита, не предложение отключить уведомления, начисления или модерацию.

В 1.8.41 [`newreply.php`](https://github.com/mybb/mybb/blob/cacad9f352043a1e36f21871c32beba46852f93e/newreply.php#L314) выполняет entry validation/hooks, затем `mark_thread_read()` (460), PostDataHandler `insert_post()` (520), captcha invalidation, `newreply_do_newreply_end` (602). Preflight entry script не является чистой частью будущей общей транзакции.

[`insert_post()`](https://github.com/mybb/mybb/blob/cacad9f352043a1e36f21871c32beba46852f93e/inc/datahandlers/post.php#L897): подписка автора и modoptions **до PID**; merge/draft special branches; users counters; `datahandler_post_insert_post` (1161), INSERT posts/PID (1163), attachment assign, SQL email/PM, cache mailqueue, counters/lastpost, `datahandler_post_insert_post_end` (1381). `PostDataHandler` сам по себе не владеет общим commit. Закрытый форум/частичная блокировка, merge, unapproved и draft должны проверяться отдельными сценариями.

Имена далее логические, **без реального prefix**. Ни `mybb_`, ни другое имя из примера нельзя считать prefix warprift.ru.

| Таблица | Engine по ACP | Класс / причина записи | Решение планирования |
|---|---|---|---|
| `posts` | MyISAM | Обязательная R0: новый PID/message; merge/draft/update имеют другую семантику | В обязательное ядро migration set |
| `users` | MyISAM | Обязательная R0 registered visible reply: lastpost/postnum по разрешениям; дополнительно PM counters, APC | В обязательное ядро; аудит конкурентных writers остаётся |
| `threads` | MyISAM | Обязательная R0: replies/lastpost; условно attachmentcount, modoptions | В обязательное ядро |
| `forums` | MyISAM | Обязательная R0: counters/lastpost, включая иерархию форумов | В обязательное ядро |
| `threadsubscriptions` | MyISAM | Условная по состоянию/опциям автора; add/remove подписки до PID. Чтение получателей само по себе не запись | Для обычного reply с поддержкой всех штатных опций включить; нельзя исключить из общего протокола без branch proof |
| `attachments` | MyISAM | Условная: pre-upload/remove и assign pid по posthash; даже без новых файлов возможны ранее загруженные unattached rows | Включить при поддержке attachment branch. Файловая система отдельно от SQL |
| `mailqueue` | MyISAM | Условная: email subscribers, PM email notification | Включить для соответствующих штатных уведомлений; worker запускается только после outcome |
| `privatemessages` | MyISAM | Условная: `send_pm()` подписчикам → PMDataHandler, включая sender copy/recipient state | Включить при PM branch, вместе с users/mailqueue и PM hooks |
| `moderatorlog` | MyISAM | Условная: выбранные modoptions в insert_post; не обязательная запись обычного немодераторского R0 | Включить при поддержке modoptions; не объявлять всегда обязательной для R0 |
| `datacache` | MyISAM | Условная в handler: update_mailqueue; также deferred stats/cache после counters | Для полного pipeline включить, если DB cache пишет эту таблицу. Внешний cache/outcome этим не исправляется |
| `threadsread`, `forumsread` | **Нет данных** | Условные entry/deferred read indicators: DB tracking, `mark_thread_read`/`mark_forum_read`; иначе cookies | Получить engine и зафиксировать границу: preflight/после outcome либо внутри атомарного set. Не утверждать их конверсию без фактической ветки |
| `captcha` | **Нет данных** | Guest captcha creation/invalidation; R0 зарегистрированного автора не использует | За пределами R0. Отдельный guest-путь, не требование конвертировать ради ACS |
| `sessions`, `tasks`, `adminlog`, `maillogs`, `mailerrors` и др. | **Нет данных** | Bootstrap/session tracking, scheduler/delivery/ACP; не доказаны как непосредственный write set R0 | Не конвертировать автоматически; отделить инфраструктурные записи от publication transaction и проверить вложенные hooks |

Десять известных MyISAM таблиц — **кандидаты полного поддерживаемого ordinary pipeline**, а не десять безусловных writes каждой атаки/ответа. Минимум R0 не разрешает игнорировать реально выбранные notifications/attachments/modoptions. До установления фактического branch set нельзя публиковать «частично атомарно».

## 3. AF hooks и вложенные записи

Включение аддонов установлено из отчёта; точные frontend registrations/порядок ещё не получены. ACP показал лишь `af_xmlhttp_router`/`af_core_xmlhttp_bootstrap_addons`, что не является полным post inventory.

| Вход / вложенный writer в AF | Таблицы, engine по ACP | Граница и оставшийся риск |
|---|---|---|
| APC `af_advancedpostcounter_increment_post()` / `af_advancedpostcounter_update_user_count()` | `users` MyISAM | Pre-PID counter; после InnoDB нужен общий writer/lock/outcome. Deferred rebuild не должен исполнять последствия rollback |
| Balance `af_balance_datahandler_post_insert()` → `af_balance_recalc_post_awards()` → `af_balance_apply_scaled_delta()` | `af_post_rewards`, `af_balance`, `af_balance_tx` InnoDB | Read→absolute balance update, tx prune, post-hash и shutdown recalc. Engine не даёт submission dedupe или предотвращения lost updates |
| Balance EXP → CS `af_charactersheets_on_exp_changed()` → `af_charactersheets_update_sheet_json()` | `af_cs_sheets` InnoDB | Вложенный owner write base/build/progress; нужен согласованный lock/CAS/freshness. ACS immutable snapshots не должны меняться |
| AAM `af_aam_post_insert_end()` → `af_aam_add_alert()` | `aam_alerts` InnoDB; другие setup/cache tables уточнить | SQL insert/autoclean, request/cache state и доставка; нужен outcome-aware outbox/ack. Lazy setup/DDL внутри общей tx запрещён |
| Appearance `af_aa_mask_after_handler()` → `af_aa_mask_commit()` / Gallery retention | `af_aa_assignments`, `af_gallery_media` InnoDB | Условно при выбранной маске. 4.1.4 merged: standalone guard + explicit owner UoW port; production coordinator отсутствует. Owner lock/engines/routing надо доказать |
| SmartURLTitles `af_sut_insert_post()` → `af_sut_fetch_title()` | Нет доказанного SQL write set самого title fetch | HTTP/prose transformation до tx; конверсия движков не решает network side effects |
| ATF fields / KB sync | Для ordinary reply ATF validation возвращается; новая тема/first-post edit — другая ветка | Не добавлять KB tables в R0 без actual callback trace. Не запускать анкетную синхронизацию ради миграции |
| MyBB `update_stats`, datacache, PM/parser/shutdown и внешние plugins/triggers | Известные core выше + неизвестные расширения | Внешние caches, static deltas и queue delivery не rollback-safe лишь от InnoDB. Нужен отдельный outcome протокол |

ACS tables по ACP уже InnoDB; **не менять их**. Backup/restore всей БД должен всё же включать их для единой точки времени. Проектные будущие submissions/post_links/outbox не создавать этой задачей.

## 4. Индексы и совместимость: что проверено и чего нет

Сверена **официальная install schema 1.8.41**, не `SHOW CREATE` установленного сайта:

| Таблицы | Upstream ключи, существенные для InnoDB |
|---|---|
| posts/threads/forums/users | PK соответственно pid/tid/fid/uid, AUTO_INCREMENT — ведущий PK; users также UNIQUE(username) |
| threadsubscriptions/attachments/mailqueue/privatemessages | PK sid/aid/mid/pmid, AUTO_INCREMENT — ведущий PK |
| datacache | PK(title); AUTO_INCREMENT нет |
| moderatorlog | **Нет явного PK/UNIQUE и AUTO_INCREMENT** в install schema. InnoDB создаст скрытый clustered row ID; это не повод автоматически придумывать публичный log ID или удалять похожие записи |
| threadsread/forumsread | UNIQUE(tid,uid) / UNIQUE(fid,uid), NOT NULL; без отдельного PK. Не добавлять PK в рамках engine conversion без отдельного решения |

Install schema использует MyISAM, поэтому наличие MyISAM на давней установке ожидаемо и не доказывает повреждение. Логические связи MyBB не становятся FK после ENGINE conversion. Не добавлять новые FK/UNIQUE/CHECK и не менять collation/charset/типы одновременно с engine migration.

**FULLTEXT нельзя пропустить:** install/index.php 2423–2430 создаёт threads(subject) и posts(message) отдельно от базового CREATE TABLE. DB_MySQLi 1.8.41 `supports_fulltext()` признаёт InnoDB при версии ≥5.6. MySQL 8.0 поддерживает InnoDB FULLTEXT, но фактические индексы/parser/stopwords, размеры токенов и настройки поиска надо получить с сервера. MyISAM `ft_min_word_len` и InnoDB `innodb_ft_min_token_size` могут отличаться; результаты, особенно коротких/русских терминов, изменятся без настройки и проверки. FTS строится заново, возможны внутренние DOC_ID структуры и существенное время/место. Не удалять FULLTEXT ради успешного ALTER и не обещать побайтно одинаковые search results.

Обязательная проверка installed DDL перед допуском:

- SHOW CREATE/SHOW INDEX: все кастомные поля, FULLTEXT/SPATIAL/parser/prefix/expression/invisible indexes, generated columns, CHECK/FK, partitioning и table options; наличие/ведущее положение AUTO_INCREMENT индекса. Если существующая MyISAM использует составной per-group AUTO_INCREMENT, это несовместимая семантика InnoDB — отдельный разбор, не автоматическая конверсия.
- Размер индексного ключа в байтах и row size при **сохранении** charset: `innodb_page_size`/row format, длинные VARCHAR/BLOB/TEXT, локальные расширения. Типичный предел ключа 3072 байт при 16 KiB страницах нельзя применять к неизвестной конфигурации как гарантию; при меньших страницах предел меньше.
- Невалидные legacy dates/encoding, SQL mode, потенциальное новое применение ограничений при rebuild; не использовать IGNORE, отключение строгой проверки или усечение данных как «исправление».
- Отсутствие corruption на исходной копии. MyISAM repair — отдельная операция на backup/staging, не автоматический этап этого плана.
- Текущие AUTO_INCREMENT high-water и MAX(id), нулевые/отрицательные/предельные ID, holes и действительные дубликаты. Сохранить `NO_AUTO_VALUE_ON_ZERO`, если нужны literal zero IDs при restore. После миграции next counter должен быть **не ниже исходного** и выше MAX(id), а не просто MAX+1: старые удалённые ID могли поднять counter. В отдельном утверждённом runbook для AUTO_INCREMENT-таблицы заранее предусмотреть сохранение captured next value (при необходимости явная table option AUTO_INCREMENT), а не принимать сброс счётчика как норму. Не перенумеровывать и не заполнять gaps.

Все эти installed свойства пока **NOT_VERIFIED**. ACP engines не содержит индексов, размеров, row formats, counts, constraints или свободного места.

## 5. Что администратор должен собрать (read-only)

Выполнять на выбранной БД форума с разрешением администратора. `mybb_` ниже — **пример**, заменить на реальный проверенный prefix только в приватной рабочей копии. SQL выдаёт metadata/агрегаты; не публиковать config.php, credentials, hostname, пользовательские строки или trigger bodies в GitHub.

```sql
SELECT VERSION() AS server_version, DATABASE() AS selected_schema;
SHOW VARIABLES WHERE Variable_name IN
 ('innodb_page_size','innodb_file_per_table','innodb_default_row_format',
  'innodb_ft_min_token_size','innodb_ft_max_token_size','innodb_ft_enable_stopword',
  'innodb_ft_server_stopword_table','ft_min_word_len','ft_max_word_len','ft_stopword_file',
  'sql_mode','log_bin','binlog_format','gtid_mode','tmpdir','datadir',
  'innodb_tmpdir','innodb_temp_data_file_path','event_scheduler');
SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION, TABLE_ROWS,
       DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, CREATE_OPTIONS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME;
```

TABLE_ROWS для InnoDB — оценка, не контрольное число строк; DATA_FREE не равно свободному месту файловой системы. `datadir/tmpdir` и имена схем — приватная operational информация, не включать в публичный обезличенный отчёт.

```sql
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
       COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
SELECT EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=DATABASE();
```

Проверить полноту TRIGGER privileges; 0 строк не доказывает отсутствие triggers. Privately снять `SHOW CREATE TABLE`/`SHOW INDEX` **каждой утверждённой** таблицы и bodies dependencies triggers/routines. Проверить metadata locks/длинные транзакции, replica topology/lag, DB backup privileges и отсутствие внешних writers. Проверка sessions/objects не должна раскрывать SQL text и персональные данные публично.

Дополнительно вне SQL: свободные bytes/inodes и quota на datadir/temp/backup/binlog volume, filesystem snapshot capability, storage latency/IOPS, DBRAM/buffer pool, текущие размеры backup и измеренная скорость restore; действительные scheduler/cron/mail/AAM workers, upstream frontend hook inventory и cache backend.

## 6. Downtime и резерв диска

Выбран **offline write-quiesced подход**, а не недоказанный online ALTER с живыми writers. MyBB «форум закрыт» недостаточен: выключить приём изменяющих запросов на уровне maintenance/приложения, завершить активные PHP requests, остановить jobs/workers/cron/админские операции, импорты и внешних DB writers. Зафиксировать отсутствие оставшихся writes. Чтение, удерживающее metadata locks, тоже может задержать DDL; на время окна предпочтительна статическая maintenance page.

Заранее померить на приватной restored staging-копии **той же MySQL 8.0.46/config/DDL и сопоставимого storage**, отдельно для posts+FULLTEXT, threads+FULLTEXT и users:

`T_window = T_drain + T_consistent_backup + Σ(T_table_copy_and_indexes) + T_validation + T_smoke + reserve`.

Установить отдельный `T_restore` и предельное время решения о восстановлении. Без размеров/скоростей численная оценка минут/часов была бы выдумкой: **downtime пока не оценён**, запуск production запрещён до замеров. Большие posts/FTS могут доминировать; не рассчитывать на INSTANT/LOCK=NONE при смене engine.

Планируемый offline copy/rebuild держит metadata/table locks, может ждать старый SELECT/transaction и блокировать новые обращения. DDL делает implicit commit и не становится откатываемым от внешнего START TRANSACTION. При отказе нескольких последовательных DDL может остаться смешанный набор engines. Engine conversion не обнуляет конкуренцию: InnoDB row/gap locks, deadlocks и изменения порядка обработки нужно тестировать отдельно, включая ordinary counter read→write writers.

Проверять **по каждому volume**: свободно для новой InnoDB копии и всех индексов крупнейшей текущей таблицы, возможных FTS/sort/temp файлов, redo/binlog и эксплуатационного резерва; прежние файлы остаются до успешной замены. Плюс отдельное место полного backup и его тестового restore, если они на том же диске. InnoDB secondary indexes включают clustered key; MyISAM→InnoDB размер не обязан уменьшиться. Универсальное «достаточно 2×» недостоверно. После каждой таблицы мерить actual free space, не запускать следующую при выходе за утверждённый порог.

## 7. Backup до изменений

1. Назначить ответственного, окно, точку отката, RTO/RPO, проверенный список таблиц/DDL и контрольные показатели. Подготовить maintenance и закрыть outbound delivery на staging, не на работающем production произвольным отключением hooks.
2. Сначала rehearsal: полный restore на изолированный MySQL 8.0.46, без production credentials/network delivery, с остановленными event scheduler/jobs. Подтвердить запускаемость MyBB и читаемость всех tables.
3. В production-окно добиться quiescence **всех** writers. Зафиксировать версии, prefix приватно, SHOW CREATE/indexes/triggers/routines/events, engines, AUTO_INCREMENT, точные counts, PK ranges и приватные логические checksums по таблицам. Сохранить release/config privately, uploads/attachments/avatars и прочие owner files в той же точке покоя.
4. Сделать **полный согласованный backup БД форума**, включая ACS/InnoDB и MyISAM, не только десять tables. `--single-transaction` в смешанной БД **не обеспечивает** консистентность MyISAM. Если выбран logical dump — глобальная read lock на весь dump и остановленные writers; на shared сервере заранее согласовать влияние lock на другие БД/привилегии.
5. Пример команды будущего утверждённого runbook (здесь **не выполнялась**):

   ```sh
   mysqldump --defaults-extra-file=/secure/backup.cnf \
     --lock-all-tables --routines --events --triggers --hex-blob \
     --default-character-set=utf8mb4 --no-tablespaces --set-gtid-purged=OFF \
     --databases FORUM_DATABASE > /secure/backup/forum-before-engine.sql
   ```

   Реальное имя БД и права проверяются приватно. Credentials file с mode 0600; пароль не передавать в command line. `--lock-all-tables` не сочетать с `--single-transaction`. Проверить exit status/stderr, полноту, size/hash, шифрование и отдельное хранение backup. Пустой/частичный dump не считается backup. Команда с `--databases` сохраняет исходное имя схемы: restore разрешён только на проверенный изолированный destination.
6. При наличии DBA-managed согласованного physical backup/snapshot допустим его **проверенный** протокол с MyISAM и точкой остановки, не копирование live `.MYD/.MYI/.ibd` файлов вручную. Binary logs/GTID coordinates хранить для согласованного PITR; политика применения и исключения повторных side effects отдельна.
7. До первого conversion иметь подтверждённый restore из этой точки и доступные backup файлов, не только обещание хостера. Не оставлять единственную копию на volume миграции.

## 8. Предлагаемый порядок будущей миграции — не выполнять этим PR

Допуск: backup проверен, DDL/indexes совместимы, disk/time budget измерены, все writers остановлены, engine set утверждён по actual branches. ACS publication всё ещё disabled.

1. Зафиксировать приватный manifest таблиц и контрольные значения. Репетировать полный порядок на restored staging. Не включать таблицу только потому, что engine=MyISAM.
2. Сначала небольшие подтверждённые условные dependencies — например threadsubscriptions, mailqueue, moderatorlog, datacache, privatemessages и attachments, **только если включены в утверждённый pipeline**. Порядок внутри этой группы выбирает DBA по замерам размера/ограничений.
3. Затем обязательные forums, users, threads и posts. Большие threads/posts с FULLTEXT идут по измеренному времени, по одной таблице. Это operational порядок для контроля окна, **не SQL lock order будущего coordinator**. При наличии custom dependencies/constraints DBA уточняет порядок до запуска.
4. Условные threadsread/forumsread или иные обнаруженные targets добавить в manifest только после решения о границе их writes; их engines сейчас неизвестны. Если фактическая ветка должна быть атомарной и её таблица остаётся нетранзакционной — эта ветка BLOCKED.
5. В будущем runbook для одной проверенной таблицы может использоваться такой **не выполненный шаблон**, без изменения charset/index definitions:

   ```sql
   -- Только после отдельного допуска и подстановки проверенного identifier.
   ALTER TABLE `VERIFIED_PREFIXposts`
     ENGINE=InnoDB, ALGORITHM=COPY, LOCK=EXCLUSIVE;
   ```

   Этот PR не исполнял даже тестовый ALTER. На конкретной installed schema допустимость алгоритма/options подтверждается отдельной staging-репетицией. Если DDL требует иной алгоритм/изменения constraints — остановка и пересмотр, не автоматический fallback.
6. После каждой таблицы readback engine, DDL/index definitions и AUTO_INCREMENT, counts/digests, оценка диска/времени. При неуспехе не продолжать оставшиеся таблицы вслепую. Без write-quiescence нельзя считать исходный backup достаточной точкой отката.
7. Не изменять ACS/InnoDB tables, не сбрасывать caches/counters/rewards автоматически для маскировки расхождений. Не запускать MyBB installer/upgrader как способ конверсии.
8. Проверить весь approved set и выполнить в изолированной staging-копии acceptance ниже. В production до снятия maintenance выполнить read-only checks, затем контролируемый ordinary smoke после согласованной точки открытия; тестовая запись после открытия уже означает новую точку данных и отдельную recovery policy.

## 9. Целостность и критерии приёмки

До и после при остановленных writers: точные row counts, min/max PK, сохранение logical IDs/ссылок и next AUTO_INCREMENT; отсутствие преобразования payload/blob/text. Рекомендуется приватный chunked logical digest по **всем** столбцам в детерминированном порядке с однозначным различением NULL/bytes/типов и учётом повторяющихся строк. Для moderatorlog без PK — multiset всех колонок/дубликатов, не предположенный порядок скрытого row ID. Один SUM/CRC XOR не является достаточной защитой от потери/дублирования.

`CHECKSUM TABLE` не гарантирует сопоставимый checksum после смены engine/row format и не заменяет logical digest. `CHECK TABLE`/diagnostic проверка структуры не доказывает semantic references и может быть тяжёлой; сначала на staging. Не использовать REPAIR TABLE для InnoDB как recovery.

Сверить before/after SHOW CREATE: допустима ожидаемая смена engine/физических options и внутренних FTS деталей; неожиданное исчезновение индекса, изменение collation, типа/default/constraint — отказ приёмки. Не добавлять уникальность подписки `(uid,tid)` только из соображений будущей идемпотентности: upstream хранит PK sid, возможные legacy duplicates требуют отдельного решения.

Примеры будущих **read-only агрегатов** после подстановки prefix (не выгружать message/PII):

```sql
SELECT COUNT(*) AS n, MIN(pid) AS lo, MAX(pid) AS hi FROM mybb_posts;
SELECT COUNT(*) AS orphan_posts
FROM mybb_posts p LEFT JOIN mybb_threads t ON t.tid=p.tid WHERE t.tid IS NULL;
SELECT COUNT(*) AS orphan_threads
FROM mybb_threads t LEFT JOIN mybb_forums f ON f.fid=t.fid WHERE f.fid IS NULL;
SELECT COUNT(*) AS linked_attachments_without_post
FROM mybb_attachments a LEFT JOIN mybb_posts p ON p.pid=a.pid
WHERE a.pid<>0 AND p.pid IS NULL;
```

Сравнивать с pre-migration baseline: старые orphan rows не удалять автоматически, `attachments.pid=0` — допустимые незакреплённые uploads; `posts.uid=0` — guest, не автоматический orphan. Аналогично проверить firstpost/lastpost/counters с учётом видимости и deleted/unapproved, авторские/PM links, Balance/EXP/CS и неизменность pinned ACS snapshots/tape. Counts логически связанных таблиц не обязаны быть одинаковыми; проверяются approved invariants и отсутствие **новых** расхождений.

На staging: ordinary reply, маска/её контролируемый отказ, подписки email/PM с доставкой в изолированный sink, attachments, moderation/unapproved, native merge/draft и Quick Edit, поиск FULLTEXT (русские/короткие слова), Balance/EXP/APC и параллельные ordinary replies. Это **план будущей приёмки**, не запущенный suite. Затем отдельно публикационный протокол: forced failure после PID/owner writes, отсутствие неоткатившихся counters/queues/cache/shutdown, duplicate submission/response loss. До реализации coordinator последний набор ещё недоступен.

Engine migration принимается отдельно от IC-MYBB-1. Одних InnoDB недостаточно: core counter read→absolute-update, owner locks/receipts, Appearance binding, AAM/outbox, cache/shutdown, внешняя доставка и single-writer semantics остаются gates. Не менять MyBB core и не вводить Gateway этой задачей.

## 10. Отдельный план восстановления

1. При ошибке/нехватке диска/новых расхождениях оставить все writes и delivery остановленными; записать completed table manifest, DDL error и время. Не продолжать по автоматическому retry и не удалять единственный backup ради места.
2. DDL не откатывается общей SQL-транзакцией. Обратное ENGINE=MyISAM — **не backup restore**, не исправляет возможные изменения данных и возвращает non-transactional risk. Не рассчитывать на ROLLBACK ALTER.
3. Предпочтительно восстановить **полную БД и согласованные owner files** из проверенной точки покоя на чистый изолированный MySQL 8.0.46 instance с совместимой конфигурацией; остановленные event scheduler/cron/mail, запрет внешней доставки. Сверить destination перед импортом: dump с `--databases` сам содержит исходное имя БД. Нельзя выполнять import по production DSN «для проверки».
4. Проверить backup hashes, завершение import без игнорирования ошибок, DDL/indexes/engines/counters/IDs, logical digests и привязки uploads. Восстановить прежний release/config privately. Безопасно очистить/пересобрать только подтверждённые кэши после outcome; не пересчитать боевую историю/награды из догадок.
5. Если восстанавливается production endpoint — только после верификации переключить его на восстановленный instance, сохранить старую неуспешную копию для анализа. Порядок освобождения storage/переключения определяет DBA. Не делать выборочный restore posts поверх новых ACS/Balance/reward rows.
6. Если после контрольной точки были разрешены новые writes, остановка и отдельное решение о data reconciliation/PITR. Не отбрасывать их молча; replay binlog может снова применить failed DDL/side effects и требует отбора. Уже отправленные внешние уведомления SQL restore не отзывает.
7. По утверждённому recovery acceptance вновь открыть ordinary forum, jobs и delivery согласованно, наблюдать lag/errors/queues. ACS combat publication оставить BLOCKED. Сохранять backup до окончания согласованного retention и наблюдения.

## 11. Оставшиеся сведения и выполненные проверки

Для допуска администратору нужны: actual installed SHOW CREATE/indexes/constraints и engines дополнительных веток; размеры/counts/AUTO_INCREMENT; FULLTEXT parser/token settings и search baseline; disk/quota/inodes/temp/binlog budget; backup privileges/restore time; table corruption/legacy compatibility; replica/cache/workers topology; полноценный frontend hook inventory и triggers privileges/bodies; результат staging rehearsal и измеренный downtime/RTO. Два ACP xmlhttp hooks и один handle этих доказательств не заменяют.

Выполнено: проверка actual merge 4.1.4, статическое сравнение upstream 1.8.41 и четырёх reported hashes, классификация source write set и install indexes, документационные ссылки/ограничение одного Markdown, `git diff --check`. Пользовательские данные в repo не добавлялись; PHP/JS/SQL migrations не менялись. Metadata-блоки SELECT/SHOW из §5 успешно выполнены на пустой одноразовой схеме MariaDB 10.11.19 (контейнер без сети). Это ограниченная синтаксическая проверка общего metadata subset, **не** проверка installed MySQL 8.0.46: специфичные переменные могут отсутствовать, installed таблицы/данные не загружались. Агрегаты §9 требуют существующих таблиц и не выполнялись; будущий ALTER-шаблон §8 не исполнялся и не проверялся конверсией; production SQL и ALTER нигде не выполнялись. Проверка installed DDL/FULLTEXT conversion и численная оценка времени остаются NOT_VERIFIED. Полный suite, PHP tests, браузерные тесты и сама миграция не запускались.
