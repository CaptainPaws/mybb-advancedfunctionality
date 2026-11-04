# ACS-4.0-R2 — аудит этапа 4 и остаток работ

> Последующая поставка ACS-4.1-R2 добавила bounded MyBB Post Gateway и fixtures; текущие возможности и ограничения описаны в [отчёте Gateway](ACS_4_1_R2_GATEWAY_IMPLEMENTATION.md). Карта ниже фиксирует исходную поставку 4.0-R2 и не является заявлением installed READY.

## Текущая карта Stage 4 R2 после ACS-4.3.1B-R2

База новой задачи: main `2c4a216d`. [Отчёт writer](ACS_4_3_1B_R2_COMBAT_WRITER.md) отличает backend fixtures от installed приёмки.

| Компонент | Фактический прогресс / остаток |
|---|---|
| Draft/Seal/Builder/Resolver | Сохранены; исходные snapshot/tape/formulas неизменны; publication_enabled=false |
| Gateway / 4.1-R2 | Bounded backend реализован, PR #1014 в main; installed entry binding/addon/AJAX integration BLOCKED |
| Submission journal / 4.3.1 | Durable CAS/claims/audit/freeze/quarantine есть; COMMITTING/COMMITTED теперь пишет transactional writer; recovery/manual evidence ещё отсутствуют |
| Combat writer / **задача 1, 4.3.1B-R2** | Реализован: migration 009, HP/AP и текущий HP-resource scope, state/clock closure/events/results/post_link/terminal receipt/outbox в одной tx. Rollback/replay/concurrency fixtures пройдены. Подготовительные controller grants=false не обходятся. Неподдержанные эффекты/resources остаются заблокированы |
| PublishService / задача 2 | **Не начата**; Gateway не вызывается writer и не связан с ним coordinator |
| RecoveryService / задача 3 | **Не начата**; writer receipt lookup — инфраструктура, не полный recovery протокол |
| Moderation/lifecycle / задача 4 | **Не начата**; writer лишь отказывает pending/invalid post; native observers не подключены |
| Editor/Postbit/GM UI / задачи 5–6 | **Не начаты**; второй editor не создан, публикация не включена |
| Outbox consumers / installed 4.5 / задача 7 | Есть только pending storage, потребители не запускаются. Installed MySQL/MyBB/manual acceptance BLOCKED; локальная MariaDB её не заменяет |

После PR этой задачи автоматического перехода к задаче 2 нет. Карта ниже сохранена как история исходной поставки 4.0-R2.

## Основание и область доказательств

Проверенная локальная исходная `main`: `781f5ab6` (merge PR #1013). Перед отправкой поставка согласована с `main` = `3c588f0f`: сохранено удаление ACP migration wizard, CLI и migration library коммитами `7570c43f`/`3c588f0f`. Нормативная поставка — Revision 2 в [Master](ACS_MASTER_SPECIFICATION.md), [Architecture](ACS_TECHNICAL_ARCHITECTURE.md#publication-r2), [Contracts](ACS_INTEGRATION_CONTRACTS.md#publication-r2), [Roadmap](ACS_IMPLEMENTATION_ROADMAP.md#stage-4). Цель: warprift.ru, MyBB 1.8.41, PHP 8.5.12, MySQL 8.0.46.

Установленного ядра MyBB, production БД и runtime inventory активных hooks в checkout нет. Версии целевой установки предоставлены заказчиком, а не измерены этим аудитом. Upstream-проверка ниже не выдаётся за installed-проверку. Публикация на форуме не включена, тестовые посты туда не отправлялись, ядро не менялось.

R2 отменяет обязательную общую MyBB/ACS транзакцию и миграцию форумных таблиц. Старые [4.1.1](ACS_4_1_1_PUBLICATION_AUDIT.md), [4.1.2](ACS_4_1_2_GATEWAY_CONTRACT.md), [4.1.3](ACS_4_1_3_ENVIRONMENT_DIAGNOSTICS.md), документы migration tool и [4.2.1](ACS_4_2_1_IMPLEMENTATION.md)/[4.2.2](ACS_4_2_2_IMPLEMENTATION.md)/[4.2.3](ACS_4_2_3_IMPLEMENTATION.md) оставлены без переписывания как исторические свидетельства. Прежние gates «все MyBB InnoDB» и «Appearance joinable writer внутри ACS tx» больше не требования включения R2. Installed Gateway, права, outcomes и side effects всё ещё требуют проверки.

## Карта исходной реализации и изменений R2

Пути модулей ниже относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`.

| Компонент | Что действительно есть | Решение R2 / оставшиеся работы |
|---|---|---|
| `Infrastructure/TransactionManager.php` | Короткие собственные ACS tx, GET_LOCK, ambient/nested guard, восстановление session charset/sql_mode | Сохранён. Никогда не оборачивать им PostDataHandler/обычные MyBB hooks |
| `Administration/Migrations.php`, `migrations/001`–`007` | Версионированные собственные InnoDB-таблицы, checksum/readback/resume; это не доказывает публикацию | Старые миграции неизменны; добавлена только `008_publication_journal` |
| `Application/DraftService.php`, `Infrastructure/DraftPersistence.php` | Серверная проза/план, CAS, restore, rate limit, права, idempotent receipt | Сохранены. R2 добавляет frozen `publishing`, предотвращает создание второго черновика вместо отправляемого. HTTP publication lifecycle ещё не подключён |
| `Application/BuilderService.php`, `Presentation/BuilderController.php`, `assets/builder.*` | Существующий scoped конструктор, условный read-only preview, один editor; `publication_enabled=false` | Сохранены. Нет новых кнопок отправки, editor instance или ложной активации публикации |
| `Application/SealService.php`, `Infrastructure/SealPersistence.php`, `RandomSource.php`, `migrations/007` | Append-only attempts/rolls, общий AP quota, только cancel tail без refund, pinned definition, simulation/dependency/confirmation hashes; повтор receipt не бросает снова | Сохранены. Submission snapshot копирует исходные tape/payload/hashes; Resolver не переписан |
| `Domain/Resolver.php` и числовое ядро | Реальный серверный resolver существующего ограниченного scope, не production combat writer | Сохранены без изменений. Наличие resolver не означает атомарное применение полного результата |
| `Infrastructure/Adapters/MybbPostGateway.php` | **Отсутствует** в исходной main; старый 4.1.2 — документ, не класс | Installed binding BLOCKED. Нельзя «адаптировать существующий класс» или объявить обычную публикацию проверенной |
| `Application/PublishService.php` | **Отсутствует** | BLOCKED: native Gateway, серверный plan/control verifier, единый transactional combat writer, UNIQUE post_link/results/turn и outbox |
| `Application/RecoveryService.php` | **Отсутствует** | BLOCKED: evidence protocol, GM permissions/CSRF/CAS/audit, known-pid reconciliation, moderation/deletion/move observers |
| `Infrastructure/OperationStore.php` | Generic prepared/committed/recovery receipts для прежних операций | Сохранён; его универсальный status updater не используется для publication state machine |
| R2 `Domain/Publication.php` | Новый единый публикационный словарь/граф и безопасная status projection | Не заменяет исполняемые writers. COMMITTED projection разрешает confirmed mechanics; остальные статусы — pending/refused |
| R2 `Infrastructure/SubmissionStore.php` | Новый внутренний ACS-only journal: register/replay, CAS, immutable sealed snapshot, freeze, VALIDATING, durable POSTING, known-pid readback, quarantine/last_phase, доказанный отказ **до** POSTING, append-only audit | Проверяется fixture. Не endpoint, не Gateway, не RecoveryService. Recovery→resolved и COMMITTING→COMMITTED не реализованы произвольной сменой статуса |
| R2 `submissions`/`publication_audit` | PK submission_id, UNIQUE active_turn_id и известный pid, журнал версий/переходов, сохранённые request/seals/prose/evidence | Это journal foundation, не готовые post_links/results/outbox. UNIQUE pid не доказывает identity потерянного поста |
| `Infrastructure/SessionRepository.php` | Existing state-changing commands и replay receipts | Добавлен консервативный session-wide barrier для новых команд при unresolved submission. Безопасное сужение до dependency_set — последующая работа |
| `Administration/PublicationDiagnostics.php` | Read-only metadata, hashes/hooks, permissions/export | Цель 1.8.41, upstream SHA; MyISAM MyBB — информация (PASS), ACS non-InnoDB — BLOCKED. Диагностика не включает публикацию |
| Прежние `Administration/Migration/*`, `tools/acs-engine-migration.php`, `tools/acs/EngineMigration.php` | Удалены актуальной main (`7570c43f`/`3c588f0f`) | Удаление сохранено при согласовании R2; инструмент не восстанавливается. Исторические документы сохранены с upstream retirement notes |
| `Presentation/ThreadComponents.php`, Theme slots | Существующие ссылки/session UI, подготовка | Combat card/status/confirmed post_link rendering и GM recovery UI ещё не реализованы |
| AdvancedEditor/SCEditor, Quick Edit | Штатный редактор в других аддонах | Не менялись; нет доказанного combat submit wiring. Обычная проза не становится механикой от ACS hidden fields |

Ни одна задача 4.1–4.5 не объявлена выполненной только из-за наличия модели/DDL. 4.0 документирует текущие факты; journal foundation — частичная поставка 4.3.1.

## Проверка upstream MyBB 1.8.41

Получены файлы из тега [`mybb_1841`](https://github.com/mybb/mybb/tree/mybb_1841), без изменения checkout ядра:

| Файл | SHA256 |
|---|---|
| `inc/datahandlers/post.php` | `9d7a5aee7cf100bff0cbd918934b16524f3d556e94ced4719bef97622c6a5d13` |
| `newreply.php` | `affb30736516ac15f7597424b27df39c074093bbed8922e54e121bda9fdc4f43` |
| `inc/db_mysqli.php` | `99f7077ef3dd5c2032e9a690dfd50f4b707147d7ec43621ed5bc875cb8edde4a` |
| `inc/class_core.php` | `6fe9f32d470218e4fc0b15e9631007d9108c1e2cae53f8abf1862ec771488d7b` |

`post.php`: `validate_post()` вызывает `datahandler_post_validate_post` (878). `insert_post()` (897) требует validated/error-free, но это не замена проверкам `newreply.php`. Есть merge (1040–1093), конвертация existing draft (1137–1140) и новый INSERT (1161–1163). Один `datahandler_post_insert_post` вызывается **до** update/insert и не означает наличие нового pid. В новой ветке pid получен от штатного `$db->insert_query` на 1163; поздний `datahandler_post_insert_post_end` — 1381, после подписок, mail/counters/attachments. Merge имеет свой `datahandler_post_insert_merge` (1093) и ранний return.

`newreply.php`: обычные права/состояние темы и вложения обрабатываются entry script; `newreply_do_newreply_start` (314), native `validate_post()` (449), `insert_post()` (520), `newreply_do_newreply_end` (602). Late hook недостаточен для обнаружения смерти процесса после INSERT, до записи ACS evidence. Ошибка позднего hook не доказывает отсутствие поста. Стандартный posthash/uid/tid/time не заменяет проверенный submission identity.

**Вывод:** можно сохранять обычную MyBB публикацию вне ACS tx. Из проверенных extension points не доказан атомарный marker для exactly-once INSERT/автоматического поиска потерянного pid при MyISAM. Поддерживаемый fallback — RECOVERY_REQUIRED без повторного INSERT; candidates только для review. Installed diff/bootstrap/hook order ещё надо проверить на изолированной копии warprift.ru.

## Побочные действия аддонов

Статически подтверждены регистрации: `advancedappearance/post_masks.php` — insert post/thread end и update end priority 80, отдельный merge hook; `balance/balance.php` — insert post/thread end, дальнейшая связь с CS; `advancedalertsandmentions/advancedalertsandmentions.php` — post/thread end. Ранее описанные подписки, уведомления, статистика и shutdown остаются собственностью MyBB/аддонов. Фактический список активных callbacks и их порядок на установленном форуме неизвестны.

R2 не оборачивает их в ACS tx, не отключает и не повторяет их через recovery. Cosmetic mask не включается в dependency_set/snapshot/sealed/hash/tape. При известном pid и сбое позднего handler возможны частичные штатные side effects MyBB: это отдельная диагностика владельца, не повод повторять весь PostDataHandler или объявлять отсутствие поста. ACS EXP/награды/уведомления требуют своих idempotent receipts и не дублируют обычные post rewards.

## План оставшихся реализационных работ

1. Проверить установленный core 1.8.41 и полный native reply/Quick Reply lifecycle. Выбрать конкретный Gateway binding, проверять новый pid, merge/draft conversion, moderation и attachments; persist POSTING до опасного вызова. Для неподдержанных веток — доказанный отказ заранее или quarantine при неизвестном исходе.
2. Добавить серверную выдачу/повтор submission_id и PublishService. Авторизация/CSRF не делегируются hidden fields. Отдельный свежий plan verifier связывает immutable tape, dependency/simulation/confirmation hashes и state versions; mask refs не участвуют.
3. Реализовать ACS transactional combat writer, post_links/results/outbox следующей миграцией. UNIQUE turn/submission/pid и весь HP/AP/entities/events/turn/receipt/outbox — одна tx. Проверка свежести, известной допустимой post_link и прав на writer; никаких произвольных MyBB hooks внутри tx.
4. Реализовать RecoveryService: safe lookup terminal receipt, известный pid после ACS rollback, POSTING неизвестного исхода, двойной submit, конкурентный ход, moderation, удаление/скрытие/перенос. История бросков неизменна. Неизвестный COMMIT сначала writer lookup, без повторного механического применения.
5. Добавить GM recovery decisions с session authorization, CSRF, CAS, доказательствами и отдельным audit event. Кандидаты не становятся привязкой по uid/tid/time. Нет автоматического RECOVERY_REQUIRED→REGISTERED и очистки pending operations.
6. Подключить существующий Editor/Theme/Postbit: narrative mode, processing/recovery/moderation/confirmed, только committed damage, неизменяемая механика при edits, сохранённые prose/seals после ошибок. Обычная литература может быть видна раньше ACS; скрытая атомарная публикация не обещается.
7. Добавить idempotent ACS outbox consumers с подтверждёнными owner APIs. Проверить отсутствие повторных EXP/rewards/notifications отдельно от native post counters.
8. Выполнить [ручную матрицу 4.5-R2](ACS_IMPLEMENTATION_ROADMAP.md#stage-4) на установленной изолированной копии. MyISAM форумные таблицы остаются MyISAM; no core changes. Без этих доказательств этап 4 остаётся не принят.

## Проверки этой поставки

Локальная среда проверки: **PHP 8.5.12**, **MariaDB 11.8.8**, одноразовая БД через Unix socket с отключённым TCP. Это не MySQL 8.0.46 и не установленный MyBB. Системная/production БД не использовалась.

Пройдено:

- `php -l` для PHP аддона и нового fixture; `git diff --check`.
- `tests/acs_publication_journal_regression.php /tmp/acs222-socket/r2.sock`: реальный ACS persistence и существующие draft/seal/preview/builder/roster/encounter fixtures. Forced failure audit откатывает registration/freeze; проверены CAS, повтор с другого соединения, несовпадающий payload/actor, pre-POSTING reject/release без reroll, durable POSTING, lost-response quarantine и запрет restart, session dependency barrier, UNIQUE turn/pid, сохранение tape/HP/AP/events. Для известных pid проверены writer uid/tid, moderation=0, visible=1, удалённый/чужой pid и quarantine с сохранённым pid. Это тест readback, а не исполнения реального Gateway.
- В том же fixture создана **только тестовая** `posts ENGINE=MyISAM`; обычная ACS migration replay не меняет движок, прозу и visibility. Прямой SQL INSERT posts используется только для setup fixture; production ACS такого writer не содержит.
- `tests/acs_drafts_regression.php /tmp/acs222-socket/r2.sock`: create/replay/update, CAS, restore, CSRF/control, no RNG/mechanical mutations, migration resume/repeat.
- `tests/acs_publication_diagnostics.php`: ACP permissions, read-only metadata/export, MyISAM MyBB не является engine BLOCKED, собственные таблицы ACS требуют InnoDB.
- `tests/acs_builder_asset_scope.php`: scope/permissions сохранены.
- До согласования с новой main побайтово проверено отсутствие изменений реестра **A1.1–A1.95**, исторических документов `ACS_4_*` и миграций **001–007**. Retirement notes исторических документов из новой main сохранены. Локальные ссылки новых разделов разрешаются.

**Не проверено / BLOCKED:** installed native publication/Quick Reply/Quick Edit и active-addon outcomes, MySQL 8.0.46, реальный HP/AP combat commit/rollback после MyBB INSERT, outbox/EXP consumers, recovery/GM UI и deployment. Образ MySQL не поместился в лимит локального Docker; MariaDB fixture не подменяет целевую MySQL-приёмку. Полные suites и production E2E не запускались.

Поставка подготовлена в ветке `work` на основе `781f5ab6` и перенесена поверх `main` = `3c588f0f` перед отправкой по указанию заказчика. Чтобы использовать journal на staging, выполнить обычный явный ACS schema upgrade до 008 через штатный lifecycle с backup; эта поставка не запускала миграцию на warprift.ru. Даже после upgrade `publication_enabled=false`: native submit ещё не подключён. Этап 4 в целом **не завершён**.
