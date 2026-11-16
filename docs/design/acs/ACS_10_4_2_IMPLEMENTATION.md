# ACS-10.4.2 — Combat Statistics, AdvancedStatistic Consumer и Profile Contract

База: main `bb47c2e7`, ACS-10.4.1 (#1074). Один Statistics Integration PR,
A1.64–A1.65. Использованы Master Specification, Technical Architecture,
Implementation Roadmap, Integration Contracts и отчёты 10.4.1, 10.3.3, 9.3.4.
`docs/ROADMAP.md` не изменён. ACS-10.4.3 не начат.

## Источник и метрики

`StatisticsRepository` принимает только completed encounter с verified
`confirmedOutcome`. Combat sources проходят прежний `ArchiveQuery::verify` и
`TerminalReceiptPersistence`: committed submission, request/sealed/receipt hashes,
result hash, event/post-link/turn identity. Preview, условные планы и causal seal
без применённого результата не становятся статистикой. Новый Resolver, RNG,
пересчёт CharacterSheets и чтение литературного posts.message не добавлены.

Чистый `Domain/Statistics` редуцирует сохранённые applied traces:

- actual_hp_damage и received_damage — `damage.hp_loss`, без allocated/raw/overkill;
- effective_healing — `heal.effective_recovery`, без calculated/overheal;
- barrier_absorption — `barrier_absorb.amount` у получателя защиты, без повторного
  суммирования damage.absorbed или absorb_effect audit;
- hits/critical_hits — сохранённые успешные strike checks;
- reactions — executed reactions; intercepts — успешный redirect;
- applied_statuses — status_created/status_refreshed, без rejected/removal;
- ap_spent — cost_paid/AP и отдельно оплаченный prepared contract;
- items_consumed — применённый consume/item/tool, on_prepare bindings и committed
  owner consume receipts выполненных prepared children. Requires/equip/release не расход.

Multi-target container не добавляется поверх собственных strikes. Legacy reflection,
пассивные reaction children и effect responses учитывают отдельный источник.
Обычные prepared children читаются из terminal combat payload, ambush children —
только из committed encounter.ambush_start; start causal seal исключён.
Clock ticks/upkeep/object effects читаются из committed Clock boundary receipts; повтор одного
boundary в разных receipts/terminal history дедуплицируется по boundary_key.
On-prepare AP не начисляется повторно при trigger; сам preparation event без
завершения encounter не создаёт постоянную статистику.

Отдельные subject kinds: entity, character, pet, summon, control_group. Kind aliases
являются отдельными представлениями, не слагаемыми единого total. Группа строится
из уникальных entity contributions ровно один раз. Метрики также разбиты по типу
источника: combat.resolved, clock.status_tick, encounter.prepared_register,
encounter.ambush_start. Нет score, MVP, рейтинга или вызова reward APIs.

## Persistence и обработка очереди

Фактический ledger до задачи заканчивался на **028_archive_policies**. Новая
resumable migration **029_statistics** создаёт проектную `af_acs_statistics` по
архитектурному контракту: encounter, subject kind/key, aggregate_version,
source_sequence, visibility, payload_json; unique encounter+subject.
Дополнительно `af_acs_statistics_consumers` хранит одну текущую derived версию
на encounter, `af_acs_statistics_receipts` — operation/hash receipts. Все таблицы
InnoDB/utf8mb4/Dynamic, включены в тот же runner/transactional readiness ledger.
Старые migrations/checksums не изменены. Production DDL не выполнялся.

Outcome confirmation и correction.review сохраняют в существующем outbox только
`statistics.refresh` pointer. Никакой aggregation/consumer call внутри подтверждения
боя нет. Повреждение consumer не влияет на подтверждённую механику. Projection
строится отдельной bounded maintenance операцией, с session lock и атомарной
заменой subject rows + `statistics.aggregate` outbox.

AF endpoint `statistics_sync` — POST/CSRF, current native/session GM; обязательны
session_id, encounter_id, visibility (private/participants/public). Это явная
операция обработки/повтора для конкретного encounter, также после close/archive.
Фоновый scheduler или бесконечный worker не добавлен: durable refresh pointers
ждут этой операции. До неё permanent/profile projection может отсутствовать;
GET никогда не достраивает её. Первичная публикация выбирает visibility;
последующие sync/correction сохраняют её. Изменение visibility через тот же source
version отвергается, отдельный редактор privacy в этот ограниченный PR не входит.

aggregate_version/source_sequence — session_sequence последнего confirmed outcome
или correction.review этого encounter. Иные session/visibility/reward events не
увеличивают версию. Same source replay не переписывает rows. Correction применяет
latest verified disposition include/exclude к исходному combat либо outcome;
exclude outcome исключает механические contributions encounter. Source events,
rolls, receipts, HP/AP/Inventory и уже выданные награды не меняются. Полная замена
derived версии исключает двойное накопление; новые боевые величины correction
не выдумывает — прежний correction contract поддерживает include/exclude.

## IC-STATS v1

`AF_ACS_StatisticsAdapter::publishCompletedAggregate(operation_key, encounter_id,
aggregate_version, visibility, payload)` вызывает отдельный AdvancedStatistic
consumer в `advancedstatistic/modules/combat_statistics.php`, подключённый из
существующего addon entrypoint. Board stats/post counters не изменены.

Stable key: `statistics:<encounter>:<source_sequence>`. SHA-256 покрывает versioned
canonical envelope, включая visibility и payload. Consumer проверяет соответствие
сохранённому producer outbox, поэтому произвольный PHP/HTTP payload не считается
confirmed aggregate. Consumer upsert и receipt атомарны на существующем writer.
Same key/hash возвращает прежний receipt; changed envelope конфликтует; old version
без receipt получает superseded; old acknowledged replay возвращает прежний receipt,
не заменяя новую projection. Adapter::lookup и
`af_advancedstatistic_completed_aggregate_receipt` восстанавливают lost ACK.

Outbox ACK ставится только после проверки operation key/hash/status receipt.
Ошибка/отключение AdvancedStatistic, rollback consumer или неизвестный ACK оставляют
aggregate pending; повтор statistics_sync безопасен. Никаких raw journal, owner
contracts, private definitions, литературных постов или snapshots в consumer нет.

## IC-PROFILE v1 и видимость

`StatisticsService::profile(owner_uid, filters)` — read-only серверный view model;
AF `statistics_profile` — GET с native canview/canviewprofiles. Фильтры: entity,
control_group, kind, category (метрика), event_type (тип исходного события).

`advancedprofileui/modules/combat_statistics.php` предоставляет отдельный
`af_apui_combat_statistics_contract(ownerUid, filters)` и минимальный lazy renderer.
APUI lazy tab `combat` показывает итоги по kind, историю completed encounters,
фильтры и разрешённые archive links. Добавлены отдельные shells в APUI и реальный
ATF member_profile template, navigation label в APUI. JS использует прежний generic
lazy/tab handler; нового runtime/CSS нет. Installed template refresh требуется
через штатное обновление владельцев templates. Полная стилизация остаётся этапу 11.
Postbit и механика CharacterSheets не изменены; ошибка lazy tab локальна.

Каждый encounter сначала проходит обычную member archive projection: current
native thread/forum/password origins, session role и current archive policy.
Даже GM profile не обходит member secrecy. Только reveal_all допускает точные
числа; keep_hidden/reveal_results исключают encounter целиком, включая membership
в истории, totals и filter choices. Public здесь означает аудиторию, которая уже
имеет native/session archive access; существующий запрет archive для outsider
не расширяется. Participants дополнительно требует owner entity в данном encounter
либо session GM; private — владельца профиля либо session GM.

Дополнительный conservative boundary: encounter с gm-only participant, скрытой
подготовкой или сохранённой stealth-механикой не появляется в профильных агрегатах.
Частичные totals с вычитанием скрытого противника не формируются. Это ограничение
предпочтено попытке восстановить секретные contributions через доступные числа.
Фильтры/choices строятся только после всех проверок. CSS не используется как защита.
После correction профиль не показывает старый consumer: source, consumer и latest
source sequence должны совпадать. Profile reads не меняют очередь/источники/награды.

Bounded limits: 1,000 participants/preparations, 10,000 combat/Clock records на
пересборку; 20 pending deliveries за sync; 1,000 candidate encounters на profile
read. При превышении read/rebuild блокируется, частичные итоги не выдаются.
Это не unlimited history exporter; очередной sync обрабатывает оставшиеся deliveries.

## Проверки и пределы

Точечные проверки запрошены задачей; полный test suite и browser E2E не запускались.
PHP 8.5.12 CLI/mysqli/mbstring и disposable MySQL 8.0.46, отдельный Unix socket.
`tests/acs_statistics.php` проверяет actual/raw, healing, barrier, все метрики,
entity/pet/group, multi-strike dedupe, deterministic reduction, preview/seal guards
и Clock boundary dedupe. SQL fixture продолжает прежний completion/reward/review
harness; первое завершение реально, второе явно seeded с согласованными
terminal receipts/post-link/outcome IDs. Это не второй native MyBB publish прогон.

Проверки PASS:

```text
php tests/acs_statistics.php
php tests/acs_statistics_sql.php /tmp/acs222-socket/mysql.sock
ACS_STATISTICS_VISIBILITY=participants php tests/acs_statistics_sql.php /tmp/acs222-socket/mysql.sock
php -l <только изменённые PHP-файлы>
git diff --check
```

SQL: completed/active exclusion, два encounter, same source replay, correction new
version, old receipt replay и unacknowledged stale version/superseded, lost ACK,
AdvancedStatistic disabled, private/participants/public с native archive access,
hidden participant/encounter exclusion, read-only profile и immutable sources.
Повтор migration runner на populated projections/receipts/outbox сохраняет rows.
JS не изменён, `node --check` для изменённого JS неприменим. Installed MyBB/APUI/ATF
hooks/templates, production PHP/settings/permissions и warprift.ru не проверялись.
Для staging остаётся browser acceptance lazy tab/filters, обновление templates,
проверка реальных owner grants/hidden encounters и обработка pending outbox через
GM POST statistics_sync. Боевая механика не ожидает доступности профиля/consumer.
