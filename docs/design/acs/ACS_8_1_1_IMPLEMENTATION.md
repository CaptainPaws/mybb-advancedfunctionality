# ACS-8.1.1 — Environment objects: state, durability, triggers, lifecycle

База main `35d982c1`, завершённый ACS-7.3.3. Один backend PR; A1.70–A1.71,
A1.84. Этап 7 использован как основание и не реализован повторно.
`docs/ROADMAP.md` не изменён. ACS-8.1.2 не начат.

## Реализованный subset

Существующий spawn_object создаёт полноценную проекцию на том же entity_id.
Сохраняются encounter/source_event_id/spawn_index/source_entity_id, pinned KB
snapshot/hash, сторона и unplaced zone policy. Object не получает group,
entity_states/HP/resource balance, controller, participant, EntityTurn, AP или
post. Object version независима от immutable entity identity.

Domain/Environment проверяет v1 passive marker и явный v2 typed subset из
ACS_KB_MECHANICS_CONTRACT: любые комбинации passive/triggered/interactive/
destructible; active/inactive/expired/destroyed; typed activation state; optional
проверенная durability; round/source-own_turn duration. Нет выдуманной прочности
у legacy/v1 объектов. Новые object definitions закрепляются прежним
KnowledgeBaseAdapter::pinSpawnTemplates на registration boundary; commit их не
перечитывает из KB. EntityRepository и SnapshotStore сохраняют прежние правила
identity и immutable snapshots.

Механические passive effects этого subset — фиксированный source heal на
source_turn_start/end. Triggered — один раз за жизнь экземпляра на такой же
явной causal boundary: source heal либо damage_object/self с фиксированным
конечным damage amount/type. Необходимая category и damage admission проверяются
сервером. Self-damage может завершить объект; дальнейшие срабатывания terminal
объекта запрещены. Нулевой durability всегда destroyed; destruction не создаёт
награды, loot, новый эффект или новый event при replay.

## Реальные source/event bindings

- Spawn в combat: прежний combat.resolved event, UNIQUE entities.spawn_key =
  spawn:event:index. Snapshot и definition_json содержат одну и ту же immutable
  spawn provenance; ObjectPersistence проверяет их, source identity и наличие
  parent event именно в session/encounter. Второй object_id не введён.
- GM create: encounter.environment_create, его реальный ID передаётся в тот же
  commitSpawns после INSERT события и до event_details/receipt в существующей
  SessionRepository::command transaction. Контролируемый afterEvent callback —
  внутренний серверный hook, не KB/user callback. Его отказ откатывает весь command.
- Effects: прежний Resolver→Clock→Effects. Source_turn_start/end — реальные
  entity_turn:<id>:start/end исходного source_entity_id; round lifetime —
  round:<id>:next текущего encounter. Cause ID:
  object:<entity_id>:<entry_key>:<boundary_key>. Root/effect граф проверяется
  CausalGraph с depth=2; fixed effects не порождают новые object causes.
- Clock boundary receipt и combat receipt защищают повтор commit. Fired keys
  сохраняются в object state на всю жизнь; passive/boundary stamps фиксируют
  обработанную границу. Нет page-view clock или отдельного scheduler.
- Before/after, версия, source и эффект входят в parent event_details/trace.
  Истечение не удаляет исторический spawn event/snapshot/entity.

## Схема и транзакции

Новая resumable migration `016_environment_objects` добавляет предусмотренную
Architecture af_acs_objects: entity_id PK, encounter_id/status index,
object_version, nullable durability, interaction_json/progress_json. Migration
registry/readiness обновлены; checksums 001–015 не менялись. Применён прежний
табличный resumable DDL runner. Как и у существующих ACS projections, физических
FK не добавлено: reader проверяет entity/snapshot/event/session/encounter scope,
все writers используют общий session lock и InnoDB transaction.

Отдельный ObjectPersistence делает CAS и сверяет индексируемые колонки с JSON,
проверяет pinned definition и монотонность состояния. Старые stage-7 objects
получают initial view при чтении без INSERT; первая авторизованная перемена
материализует строку. Новый spawn сразу создаёт objects row атомарно с identity.

Query/Seal/Writer связывают object state через dependency/summary hash; locked
facts fence включает objects. Preview/seal только симулируют. Изменение object
после подтверждения требует reconfirm на прежней tape. Combat project сохраняет
object changes вместе с source HP, AP, событиями и receipt; self-damage trigger
проверен именно через подтверждённый combat commit. Поздний outbox fault
откатывает object version/durability/fired, heal, spawn и журнал целиком.
R2 gates, MyBB Gateway и RecoveryService не заменялись.

## GM API и границы

`EncounterService::environment()` предоставляет EnvironmentService:
create/activate/deactivate/damage. Нужны POST/CSRF, native permissions, текущая
session GM/owner role, expected session/encounter/object versions, reason и
operation key. Create использует только retained object-only spawn ability
engaged source и явный gm_operations/create. Runtime JSON не принимается.
Идемпотентный повтор возвращает исходный command receipt, даже если expected
versions уже устарели. Payload с тем же ключом не может изменить операцию.
Неопределённая/незавершённая publication блокирует GM command прежним барьером.

Это backend API без нового UI/маршрута. Не добавлены ordinary targeting объектов,
проверки object_interaction/progress/tools/repair (ACS-8.1.2), awareness, зоны,
укрытия, stat auras, random triggers, arbitrary on_destroy/loot/rewards или
callbacks. Пространственные/неизвестные условия и unsupported KB effects
отклоняются. Damage в этом subset — разрешённая GM correction или фиксированный
self-damage причинного trigger; обычные attack targets-object не заявляются.
Нет изменений Inventory/CharacterSheets и новых MyBB posts.

## Проверки

PHP **8.5.12**, disposable MySQL **8.0.46**, существующие authoritative
InventoryFixtureDatabase/Stage-7 fixtures; MyBB users/posts остаются MyISAM.
Новая tests/acs_environment_sql.php переиспользует initial stage-7 setup и
проверяет реальный Draft/Seal/Publish/Writer/Clock путь:

- install, repeat и resume после CREATE TABLE до записи migration ledger;
- passive identity без AP/turn/resources, GM creation replay;
- POST/CSRF, native/session role и unknown-JSON guards;
- typed activation/deactivation, stale object_version и wrong damage type;
- preview/seal isolation, source heal + self-damage в confirmed combat commit;
- trigger once, duplicate combat receipt, стабильный causal binding;
- round lifetime, passive на source Clock, duplicate round boundary, expiration;
- destruction once, terminal damage deny и replay без добавочных событий;
- late combat/outbox и GM/audit rollback/retry;
- stale confirmation без новых rolls, unsupported effect и spatial event.

Целевые регрессии: acs_spawn_sql.php, acs_summon_lifecycle_sql.php,
acs_stage7_integration_sql.php. Выполнены lint изменённых/новых PHP и
`git diff --check`. Полный test suite не запускался. Installed warprift.ru,
production DB, ручная UI-приёмка и native publication acceptance не проверялись;
прежние publication_enabled/test-only gates сохранены.
