# ACS-7.3.3 — Stage 7 integration

База `main`: `093d87d5` (merge ACS-7.3.2, PR #1042). Один ограниченный
Integration PR. Источники: ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE,
ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS; отчёты 4.5-R2, 5.3.2,
6.3.2 и все шесть отчётов 7.1.1–7.3.2 в `docs/design/acs/`.
`docs/ROADMAP.md` не изменён. Этап 8 не начат.

## Implemented

Поддержанный серверный subset этапа 7 использует существующие
Builder → Draft/Seal → PublishService.issue → submission journal →
CombatCommitWriter/Resolver/Effects и Clock/RoundManagement. Ни один из этих
движков не заменён. Повторная проверка controller/delegation/overlay/category,
source capabilities, life/turn state, visibility, targets и dependencies остаётся
на прежних prepare/locked facts/commit gates. Персонаж, питомцы и source-group
summons сохраняют одну группу, AP, quota, turn и post; собственные EntityTurn,
HP/status/cooldowns остаются индивидуальными.

### Реальные изменения этого PR

Пути относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

| Компонент | Изменение |
|---|---|
| `modules/Application/BuilderService.php` | Безопасные actor metadata: kind, текущий путь управления (assignment/delegation/source/temporary_control), assignment/delegation version; ready/paused/closed/inactive/start-required/status-blocked причины. Отдельные metadata управляемых призывов выбранной группы: оставшийся срок, единица и current/next roster |
| `modules/Presentation/BuilderController.php` | Место для этих metadata в существующем Builder, подпись «Исполнитель группы», cache version JS 0733 |
| `assets/builder.js` | Показ общего управления и срока призыва, причины недоступности; невозможность добавить действие недоступного исполнителя; ссылка GM на прежнюю management-панель |
| `tests/acs_summon_lifecycle_sql.php` | Только callback hook для переиспользования authoritative initial setup; обычный запуск fixture сохранён |
| `tests/acs_stage7_integration_sql.php` | Новый сквозной fixture: permanent pet provider → смешанная последовательность → spawn → rollback → lost-ACK recovery → roster; реальный independent NPC spawn |

Этот отчёт — единственное изменение документации. Механические формулы, KB
contracts, authorization policies и permanent owner не менялись.

### Проверенные существующие соединения

- AuthorizationService, EntityService и PermanentPetSource: подтверждённый
  owner/source pin для регистрации; exact permanent spawn identity; source-group
  и explicit delegation без выдачи item rights/GM powers.
- Resolver/Effects/Clock: текущие target policies/отношения, status/control/behavior,
  реакции, pinned spawn intents и индивидуальные end boundaries. Нет retarget
  запечатанной цели или нового RNG при изменении зависимостей.
- CombatCommitWriter/Persistence: единственный confirmed mechanical commit,
  locks/CAS/fact fence, durable `(source_event_id, spawn_index)` identity через
  UNIQUE spawn_key, snapshots, admission и event в той же ACS transaction.
- PublishService и RecoveryService: прежний R2 journal/receipt lookup и
  post-confirmed quarantine. Независимый Inventory transfer/grant recovery не
  считается завершением ACS combat и не переисполняет расход.
- RoundManagementService: summon admission/обычный independent turn только в
  разрешённом roster; closed group turn не открывается появлением существа.
- BuilderController/ThreadComponents: существующая контекстная панель и
  native/session guards. ThreadComponents проверен, не изменён. Нового SCEditor,
  глобального postbit, ATF или Appearance изменения нет.

## Locally verified

PHP **8.5.12**, MySQL **8.0.46**, disposable Unix socket
`/tmp/acs222-socket/mysql8.sock`, случайные БД existing fixtures с удалением в
finally. MyBB fixture posts/users/threads остаются MyISAM. Production не
подключалась. Ниже PASS относится к локальным серверным fixtures, не к
установленному forum/native lifecycle.

### Краткая матрица этапа 7

| Требования / механика | Implemented subset | Локальное доказательство |
|---|---|---|
| A1.41–A1.43, A1.69: NPC controllers | exclusive/shared/gm_pool, session/native/assignment gates, одна generation/budget | `acs_npc_controllers_sql.php`: scope/CAS/audit, два GM/SQL handles, один claim/result, immutable pin/identity |
| A1.41–A1.43, A1.49: delegation | exact entity/shared-group, granular categories, expiry/revoke/version | `acs_delegation_sql.php`: owner/native/category gates, отзыв после post confirmation, common quota/turn, contention и real owner-authorized consume |
| A1.1, A1.47–A1.49: pet/shared group | отдельная identity, owner adapter, общий 3 AP, один post | Новый integration fixture: character + permanent pet в одной реальной последовательности; lifecycle fixture — partial withdrawal/rejoin без refill |
| A1.84: spawn_entity/spawn_object | retained KB, limits, unique event/index, passive object | `acs_spawn_sql.php`: single/multiple, durable UNIQUE, pinned template, rollback/retry; object без state/AP/group/turn |
| A1.30, A1.47, A1.84: roster/lifetime | source-group, next-round independent, Clock duration/upkeep/despawn | `acs_summon_lifecycle_sql.php` и новый fixture: следующий roster, shared AP, shortage, duplicate boundary, history, pending owner/custody barriers |
| A1.78–A1.79: sides/control | sides/relationships отдельно от assignment и overlay | `acs_side_control_sql.php`: side change/friendly fire/stale confirmation, layered control, новое base assignment во время overlay, expiry без stale restore |
| A1.80: behavior | typed lock/priority/exclusion/action restriction, explicit compatibility/fallback | `acs_behavior_constraints_sql.php`: pinned status, no legal target, hidden/manual target, compulsion/reaction gates, stale commit/tape |
| A1.32–A1.33, A1.60: Stage 6 isolation | owner authority/receipts, global reservations, scoped dependencies и lookup-only recovery | `acs_combat_items_sql.php`, `acs_inventory_outage_sql.php`, `acs_inventory_recovery_sql.php` |
| R2/visibility | прежние post_link/journal/tape/terminal receipt; filtered DTO, без raw pins | Новый fixture + side/delegation/behavior fixtures; installed gates сохраняются |

### Результаты 15 минимальных проверок

| № | Сценарий | Результат / fixture |
|---|---|---|
| 1 | Персонаж + питомец: общий AP=3 | PASS, новый fixture: 2 исполнителя, один group turn и максимум 3 AP |
| 2 | Призыв не выдаёт новый бюджет; после расхода 2 AP остаётся 1 | PASS, pet attack 1 AP + summon ability 1 AP → spent=2/3, один бюджет; lifecycle fixture сохраняет ранее spent AP/quota. Обычная стоимость способности призыва оплачивается, не отменяется |
| 3 | Повтор spawn | PASS, UNIQUE event/index и повтор submission/recovery дают прежнюю identity |
| 4 | Независимый NPC: следующий раунд | PASS, реальный NPC с pinned independent/gm_pool summon; текущий раунд без turn новой группы, следующий — ровно один |
| 5 | Два GM shared | PASS, NPC fixture: один допустимый claim/result, competing планы не дают второй post/AP |
| 6 | Отзыв delegation между seal и commit | PASS, delegation fixture: также post-confirmed revoke; denied commit, сохранённые tape/prose |
| 7 | Чужой consumable | PASS, default category/owner spending deny; отдельный item grant не даёт transfer/grant |
| 8 | Смена стороны после seal | PASS, side fixture: старое confirmation инвалидируется, новая проверка на прежних samples; поздний commit блокируется |
| 9 | Control не создаёт AP | PASS, side fixture: применение/expiry/reassignment не сбрасывают AP/quota/resources и не открывают turn |
| 10 | Behavior без допустимой цели | PASS, behavior fixture: BLOCKED до RNG/quota; нет случайной подмены/раскрытия hidden source |
| 11 | Duplicate submission/recovery | PASS, новый fixture: реальная потеря ACK SQL COMMIT, receipt lookup и повтор recovery/commit без второго spawn/post_link |
| 12 | Rollback после spawn/entity write | PASS, поздний outbox fault откатывает entity/snapshot/resources/events/receipt целиком; native PID остаётся прежним |
| 13 | Pending Inventory operation | PASS, lifecycle/Inventory recovery: зависимые переходы/kit/item commands заблокированы, holds/receipts не освобождаются; grant/transfer recovery остаётся независимым |
| 14 | Inventory unavailable, independent non-item | PASS, outage fixture: ordinary preview/seal/commit при отключённом Inventory и несвязанном pending grant, без обхода связанных item barriers |
| 15 | Декоративная маска | PASS, новый fixture: af_aa_mask меняется, entity IDs/pins/confirmation/tape неизменны. Это не installed Appearance acceptance |

Точный запуск SQL: `docker exec acs711-php php tests/NAME.php
/tmp/acs222-socket/mysql8.sock`. Выполнены девять перечисленных SQL fixtures:
новый stage7 integration, NPC controllers, delegation, spawn, summon lifecycle,
side/control, behavior constraints, combat items, Inventory outage, а также
узкий Inventory recovery fixture (всего **10**). Все завершились с exit 0.
Также PASS: `node tests/acs_builder_state.js`, `acs_builder_asset_scope.php`,
`php -l` всех изменённых/новых PHP, `node --check` изменённого builder.js и
`git diff --check`. Отдельно проверены pause/resume readiness metadata. Новая renderer проверка — только authorized read-only HTML/
JSON, не визуальная/UI приёмка. Полный suite, browser E2E и общее тестирование
аддонов не запускались.

Старые stage-2–5 fixtures с SnapshotReadOnlyDb и известной несовместимостью
с authoritative reader из 6.3.2/7.3.2 не использованы как доказательство Stage 7.
Новый fixture использует существующий InventoryFixtureDatabase и explicit
owner-normalized initial registration/roster setup. Начальный roster seeding
не является разрешением runtime открывать закрытый ход.

## Installed NOT_VERIFIED

Не проверены installed MyBB source/hook registry, routing/engines и фактическая
конфигурация warprift.ru, installed native publication, moderation/edit/move
branches и owner side effects Balance/CS/AAM/Appearance. Fixtures начинают с
известного native PID; новый MyBB INSERT/Gateway acceptance ими не заявляется.
Нет installed permanent pet owner plugin: проверен явный provider fixture через
существующий интерфейс. Его реальную интеграцию/ownership нужно подтвердить
при наличии владельца источника.

`publication_enabled=false` и прежний test-only gate сохранены. R2 Gateway,
native lifecycle, SubmissionStore/post_link/random tape/terminal receipts и
RecoveryService не переписывались; общей транзакции MyBB+ACS нет. Outbox
consumer/loot/reward dispatcher не включены. Локальный PASS не означает
installed publication READY. Ручная UI-приёмка отложена до этапа 11.

## Explicitly BLOCKED / UNSUPPORTED

- Неописанные KB fields/шаблоны, arbitrary conditions/callbacks, spatial/zone
  mechanics, on_spawn_effect и полноценное взаимодействие с объектами не
  эмулируются. spawn_object остаётся passive identity с pinned definition;
  object lifetime/upkeep executor не добавлен. Stage-7 summon lifecycle относится
  к существам, не к неподдержанной механике окружения этапа 8.
- Behavior/control поддерживают только typed subset предыдущих отчётов.
  Prepared-action executor, arbitrary compulsion scripts и forced flee не
  реализованы; нет автоматической замены fixed sealed target или новых бросков.
- Истечение прав/новые зависимости требуют прежнего reconfirm, а frozen
  post-confirmed конфликт остаётся в существующем quarantine/recovery protocol.
  Одновременно unresolved publication на сессию не размножается.
- Full/approximate/hidden gameplay projections будущих этапов не выдумываются:
  сохранены существующие server audience/visibility gates, unknown policies
  fail closed. Новый controller получает только разрешённые metadata/действия,
  не raw snapshot или скрытые abilities/relations/GM reasons по самому факту
  назначения. Панель призывов показывает только уже авторизованные существа
  выбранной группы, не чужую или скрытую историю despawn.
- Stage 6 remote/multi-writer critical costs, blind property retry, auto-release,
  Inventory-derived pet registry и новые ownership/CS grants не добавлены.

## Схема и поставка

DDL/миграций нет; ledger остаётся `015_delegations`. Поля metadata получаются
из существующих controllers/delegations/status/lifecycle/roster, а не хранят
дублирующее состояние. PHP и JS следует обновлять вместе; cache key Builder
изменён на 0733. Reactivation/GET не создают сущностей и не сбрасывают бюджет.
Installed enablement требует отдельной прежней R2 acceptance. Следующий этап
не запускается автоматически.
