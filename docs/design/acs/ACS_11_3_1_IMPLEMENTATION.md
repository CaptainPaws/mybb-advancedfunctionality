# ACS-11.3.1 — AdvancedAlertsMentions: Idempotent Outbox Consumer

База: `main` f3817d3e, merged ACS-11.2.1 (#1085). Требования A1.61,
A1.88–A1.89. Один ограниченный PR. Изучены ACS Master Specification,
Technical Architecture, Integration Contracts, Stage 4 Revision 2 acceptance
ACS-4.5-R2 и ACS-10.4.3, AAM writer/types/preferences/formatters/repository и
фактические ACS command/commit/reward/loot/control boundaries.
`docs/ROADMAP.md` не изменён, следующая задача не начата.

## Найденная граница и owner extension

Реальный addon ID — `advancedalertsandmentions`. Обычный
`af_aam_add_alert(...): void` проверяет enabled/type/preferences и вставляет
alert, но не возвращает подтверждение пропуска/вставки и не принимает stable key.
`af_aam_has_recent_alert` не защищает concurrent delivery или crash после INSERT.
Эти API не используются как идемпотентный ACS consumer.

Добавлено ограниченное code-only owner API в `combat_delivery.php`:

- `AF_AAM_CombatDelivery::deliver(message): receipt`;
- `AF_AAM_CombatDelivery::lookup(message): ?receipt`;
- `AF_AAM_DeliveryAuthority::authorize(message): bool` связывает exact envelope
  с сохранённой ACS записью и актуальной политикой получателя.

Message содержит stable operation_key, payload_hash, recipient_uid,
event_identity, type и whitelist payload `{session_id, tid, gm_only}`.
Ключ — `acs:alert:sha256(event_identity:recipient:type)`. Payload hash покрывает
канонический recursively sorted JSON всех полей message кроме самого hash.
Изменённый payload с прежним key отвергается. Recipient/TID проверяются как
канонические положительные uint32, source identity и keys ограничены по формату.
Произвольные message/URL/HTML/количество/имена/target/source IDs не принимаются.

Controlled AAM install/activate создаёт только owner-таблицу
`aam_delivery_receipts`: PRIMARY KEY(operation_key), payload_hash, uid,
event_identity, type_code, receipt_json/hash, created_at. AAM alerts/types и эта
таблица должны быть InnoDB; проверяются engines и receipt PK. Нет автоматического
ALTER/engine conversion/schema repair при delivery/lookup/GET. AAM без нужной
таблицы/типов остаётся недоступен для ACS, существующие native alerts не меняются.

Alert INSERT и terminal delivered/skipped receipt фиксируются **в одной owner
транзакции** на authoritative MyBB write_link. Unique receipt row и FOR UPDATE
сериализуют одинаковый key. SQL/BEGIN/COMMIT результаты проверяются; ambient
transaction отвергается через autocommit + SET TRANSACTION до BEGIN, чтобы не
сделать неявный COMMIT чужой работы. Lookup проверяет retained receipt hash и
recipient/event/type/key/hash bindings. Неизвестный COMMIT не объявляется успехом.
Concurrent retry того же envelope не выполняет второй alert INSERT.

Skipped — durable результат отсутствующего recipient/потерянного session scope,
отключённого типа или user preference. Preferences соблюдаются, forced=0;
critical-copy policy не обходит пользовательские настройки AAM. Выключенный AAM,
неустановленная схема/тип, непроверенный native/password access, SQL/owner error
дают pending/recovery_required, не фиктивный skipped/delivered.

Receipts не участвуют в native cleanup/delete/max-alerts. Удаление или прочтение
alert не разрешает повторную вставку по прежнему key. Receipt table намеренно не
удаляется обычной очисткой или существующим AAM uninstall: сброс dedupe history
требует отдельного контролируемого решения владельца. Автоматический purge этой
истории не добавлен.

## ACS outbox и источники

Используется существующий `af_acs_outbox`, без новой ACS migration/store/ledger.
Confirmed combat commit, выбранные immutable SessionRepository commands,
подтверждённые Balance reward ACK и Inventory loot issue ACK записывают короткий
`alerts.source` pointer в своей прежней ACS транзакции. Содержимое механики и
random tape не меняются. Same-command replay возвращается до создания второго
pointer. AAM API, hooks или owner transaction внутри этих writers не вызываются.
Отказ/отключение AAM не участвует в combat/publication capability gate.

Отдельный NotificationOutbox разворачивает pointers в `alerts.delivery` по
recipient/type. Исходные event/details hashes и committed operation проверяются
через существующий ArchiveQuery::verify; combat дополнительно проходит terminal
receipt proof. Outcome читается через verified confirmedOutcome. Reward и loot
подтверждают точный retained owner intent и receipt; pending/rejected награда
не порождает уведомление «подтверждена». Source помечается обработанным вместе с
постановкой delivery rows в ACS transaction. Старые `combat.committed` и statistics
outbox не захватываются и не помечаются delivered этим consumer.

| Тип AAM | Источник и recipient policy |
|---|---|
| acs_turn | Текущий open round/active encounter, pending turn, start_done=1/end_done=0; действующие контроллеры по существующей AuthorizationService::controller |
| acs_deadline | Тот же допустимый ход, deadline в следующих 300 секундах; identity включает сохранённый deadline, учитываются pause/extend |
| acs_missed | Подтверждённый gm_skip/gm_forfeit/deadline_process с passed/forfeited; текущие контроллеры и владельцы группы, не предположение по часам |
| acs_combat | Один confirmed combat post/event; primary actions, разрешённые targets и executed reactions агрегируются по аккаунту |
| acs_round | Текущий round identity; один alert на аккаунт, когда у контроллера наблюдается доступный ход этого раунда |
| acs_completed | Confirmed outcome; владельцы зарегистрированных участников именно этого encounter и текущие GM |
| acs_reward | Verified issued Balance obligation либо issued Inventory loot entry; только точный recipient UID |
| acs_publication | Durable recovery_required submission; автор, владелец control group и текущие GM; один identity на submission |
| acs_gm | Разрешённые pause/resume/deadline/session close/archive/reopen — связанные владельцы и GM; private role/delegation audit — только текущие GM |

Turn/deadline не адресуются прежнему владельцу вместо действующего делегата.
Candidate roles/delegations/control context кэшируются только на время seed одной
партии; используется существующая авторизация, включая temporary control и
summon policy, а не SQL-флаг «он владелец». Перед delivery повторно вызывается
controllerFor, проверяются current turn/round/encounter/deadline и native access.
Смена контроллера создаёт отдельный key нового recipient; старый envelope не
переписывается. Важные итоги/пропуск/награда/publication problem копируются владельцу
по приведённой account policy. Пет/призыв не становится отдельным account;
несколько существ одной группы не создают alert на каждую строку trace.

Для атак по targets дополнительно применяется существующий AudienceService
идентифицированного/targetable source в контексте recipient. Неизвестный источник
не раскрывается через уведомление о его атаке. Игроки/observer/outsider не
получают private GM audit. Completion не рассылается владельцам постороннего
encounter той же сессии. В owner payload нет скрытых identities/HP/AP/rolls/qty/
чисел результатов или количества участников. Текст generic, типизированный;
точное актуальное состояние всегда читается из ACS с правами.

AAM formatter обрабатывает только `acs_*` ветку отдельно. Он повторно проверяет
текущий UID, enabled ACS, native thread/password, session roles и gm_only scope;
при утрате доступа возвращает нейтральный текст без URL и session details.
Старый custom extra URL не может переопределить эту ветку. Литературный текст
вообще не читается. Обычные AAM formatters и writers сохранены.

## Consumer, retry и эксплуатация

`NotificationService::drain(sid)` обрабатывает до 20 source pointers и до 20 due
delivery rows. Seed также ограничен 100 актуальными turns/100 entities одного
turn/100 primary actions/200 role rows или owners; overflow оставляет source
pending и diagnostic, а не выдаёт частичную выдуманную сводку. Pending publication
scan ограничен 100. Повторные controller/audience reads переиспользуются в рамках
seed, но не заменяют свежую проверку перед owner dispatch.

Owner вызывается после выхода из ACS transaction/lock с assertExternalBoundary.
Сначала lookup прежнего envelope. Только отсутствие receipt допускает deliver
**того же** stable key/payload. Отдельный ACS ACK transaction сохраняет verified
owner receipt в `alerts.ack` и помечает delivery delivered. Crash после owner COMMIT
до ACS ACK разрешается lookup, без новой вставки. Alert cleanup не меняет этот
результат. Любой потерянный/неверный owner результат оставляет recovery_required.
Retries используют available_at с backoff 60–3600 секунд; source failure получает
5-минутную отсрочку. На партию создаётся не более одного безопасного error_id
через существующую Diagnostics, с operation/phase и без SQL/secret payload.

Controlled ACS install/upgrade копирует task source в
`inc/tasks/af_acs_notifications.php` и регистрирует MyBB task раз в минуту.
Существующее enabled/schedule владельца при upgrade сохраняется. Task выбирает
не более 20 active/pending sessions с durable round-robin checkpoint в том же
outbox; повтор конкурентного запуска безопасен на уровне source/key/receipt.
AAM/ACS disabled — worker ничего не отправляет. Это отдельный backend task,
не showthread polling, браузерный retry или execution Clock boundary.
MyBB traffic task не гарантирует точный момент предупреждения: для регулярного
исполнения нужен рабочий scheduler/cron. Истёкший/paused/stale deadline не
обозначается текущим; worker не выполняет timeout_pass вместо RoundManagement.

Добавлены GM-only AF `alerts_status` GET и `alerts_retry` POST (только sid,
current native/session GM, CSRF на retry). Никакие client operation keys, recipient,
alert payload или механические команды не принимаются. Status — одна due партия,
не total всех долгов. В RecoveryPage добавлены ссылки/форма этой ограниченной
maintenance, без нового GM backend. Installed schema/type failure показывается
как неподтверждённая доставка. Alerts не добавлены в блокирующие combat/session
obligations и не запрещают подтверждённую публикацию.

ACP capability `aam.delivery` теперь указывает реальные owner deliver/lookup и
ACS consumer/ACK implementations. Старая NOT_IMPLEMENTED заглушка удалена;
implementation отделена от installed readiness. Installed acceptance остаётся
NOT_VERIFIED / INSTALLED_AAM_ACCEPTANCE_REQUIRED. Никакого автоматического READY,
publication_enabled или изменения R2 acceptance нет.

## Файлы и установка

AAM: новый `combat_delivery.php`; controlled install в
`advancedalertsandmentions.php`; узкая early-return ветка в
`advancedalertsandmentionsformatters.php` с сохранением прежних строк файла.

ACS: новые `Infrastructure/NotificationOutbox.php`,
`Application/NotificationService.php`, `Presentation/NotificationController.php`,
`Administration/NotificationTask.php`, `tasks/task_af_acs_notifications.php`;
короткие source inserts в CombatCommitWriter, SessionRepository,
RewardOperations, LootIssuePersistence; routes/lifecycle в advancedcombatsystem.php;
RecoveryPage и актуальная AAM capability в Administration/Diagnostics.php.
Обновлена только IC-AAM секция `docs/contracts/ACS_INTEGRATION_CONTRACTS.md` и
создан этот отчёт. `docs/ROADMAP.md`, исторические migrations/ledger, MyBB core,
ATF/postbit, Resolver, Inventory/Balance writers и publication gates не меняются.

Перед включением установленной доставки владелец должен штатно обновить AAM
(owner receipt table/types), затем ACS (task deployment/registration), проверить
writer routing/InnoDB/PK, права scheduler, preferences и вручную принять сценарии
ниже. Не устанавливать таблицу SQL-вставками из ACS GET. В Codex production DDL,
установка аддонов, task execution и отправка реальных уведомлений не выполнялись.

## Проверки и ограничения доказательств

- **PASS:** `git diff --check`.
- **Статически проверено:** соответствие реальным AAM schema/preferences/writer,
  controller/delegation/Audience signatures, event/terminal/reward/loot proofs,
  stable key/hash binding, standalone owner transaction/PK serialization,
  separate ACK/lookup, bounded retry, source pointers/routes/task и отсутствие
  owner calls в combat transaction. Это не результат исполнения сценариев.
- **NOT_VERIFIED:** `php -l` — PHP CLI отсутствует, runtime не устанавливался.
- Согласно AGENTS.md автоматические/regression/integration/SQL/browser tests и
  fixtures не создавались и не запускались. Запрошенные role/hidden/duplicate/
  lost ACK/outage/replay/aggregation сценарии прослежены статически; их installed
  результат **NOT_VERIFIED**. Нового JS нет.
- Installed MyBB/AAM/ACS/owner schema/scheduler/permissions и actual delivery
  NOT_VERIFIED. Условия owner API реализованы в коде, но подтверждённая installed
  приёмка/production exactly-once без проверки writer/storage не заявляется.

Ручная приёмка на изолированной установленной копии:

1. Все девять типов, preferences/type disabled, отсутствующий recipient,
   обычные native alerts неизменны. Только реальные подтверждённые reward receipts
   дают acs_reward; pending/rejected не объявляются начисленными.
2. GM/player/owner/delegate/observer/outsider, expired grant, shared/gm_pool,
   pets/summons, control overlay. Turn/deadline получает current controller;
   критические факты — надлежащий owner, без копии на каждое существо.
3. Hidden source/target, gm_only actor/audit, пароль/чужой форум, revoke до dispatch
   и после доставки: AAM extra/list/formatter не раскрывают имён, IDs и сумм;
   access не подменяется текущим GM аккаунтом scheduler.
4. Один пост с multiple actions/targets/reactions — одна сводка на recipient;
   duplicate source/повтор task/два workers не создают два alert по одному key.
5. Crash/lost ACK после AAM COMMIT, до ACS ACK; повтор lookup возвращает прежний
   alert ID. Read/delete/autocleanup alert и retry не создают новую запись.
   Изменённый hash/recipient/type с прежним key отвергается.
6. AAM off/schema/type missing/MyISAM storage/owner outage, SQL failure и неизвестный
   COMMIT: бой/публикация подтверждаются независимо, pending/recovery_required
   сохраняются. После восстановления доставки — прежние keys, без нового боя.
7. Pause/resume/extend/истёкший deadline, timeout notification без boundary execution,
   пропущенный ход только после confirmed pass/forfeit; stale alert не командует ACS.
8. Reward/loot partial recipients, outcome из другого encounter, archived session
   recovery/publication problem, GM-only status/retry/CSRF/подмена sid.
9. Scheduler round-robin/backoff/лимиты/diagnostics, неработающий traffic cron,
   upgrade task с сохранением owner preferences. PHP lint до установки обязателен.

Матрица Codex не исполнялась. PR предназначен для ручной installed приёмки.
