# ACS-8.3.2 — Cover, obstacles, line of effect и destruction

База main: ce1e9972 (merge ACS-8.3.1, PR #1048). Один ограниченный PR.
Источники: ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE,
ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS, KB mechanics contract;
отчёты 8.1.1, 8.3.1, 5.3.2, 7.3.3. Требования A1.20, A1.57, A1.79,
A1.81–A1.83. docs/ROADMAP.md не изменён; ACS-8.3.3 не начат.

## Реализация и подтверждённый KB subset

Domain/Cover.php — чистая часть прежнего Resolver/Effects, без нового damage
processor, RNG, SQL или callbacks. Типизированные descriptors cover/obstacle
разрешены в pinned object-template.v2 категории passive. Их строгая грамматика
добавлена в ACS_KB_MECHANICS_CONTRACT.md, раздел ACS-8.3.2 cover and obstacle
subset. Неизвестные поля, направления и отсутствующая capability блокируются.

Partial/substantial/complete не задают универсальных процентов. KB явно
определяет additive Speed/DEF/elemental RES, блокировку LoE, capacity,
occupy/leave AP, same-zone admission и leave-on-move/remove-on-destroy policy.
Speed применяется к прежнему HitCrit, DEF/RES — к прежнему Damage до обычного
penetration/rounding/barrier/HP pipeline. Нет второго shield/barrier pool.
У существа один действующий cover; один объект может защищать несколько
существ до KB capacity. Нахождение в зоне не создаёт assignment автоматически.

Optional environment_policy на ordinary/multiple abilities и прежнем hidden
object mass раздельно задаёт ignores_cover, requires_line_of_effect,
affects_zone и penetrates_obstacles. Игнорирование bonus не проходит сквозь
wall/complete cover с blocks_line_of_effect. Penetration проходит только
объектные blockers и не отменяет range, awareness или базовую LoE capability
карты. Multiple проверяет выбранные известные цели; hidden-object mass
серверно выбирает прежний perception tag scope, затем ограничивает его зоной.
Раскрытие объекта/его identities в результате mass не добавлено.

Obstacle содержит точные directed edges по KB zone keys, блокировку движения
и LoE и optional on_destroy для существующей connection. Никакой геометрии,
координатной сетки или pathfinding нет. Неизвестная связь при destruction
откатывает всю операцию. Другие действующие blockers остаются отдельным gate.
Видимость, awareness, range, obstacle и cover checks не заменяют друг друга.

## Persistence, версии и реальные event bindings

Следующая свободная resumable migration — 020_cover. Добавлена только
предусмотренная Architecture af_acs_cover_assignments: encounter, существующие
cover/protected entity IDs, status, assignment_version, pinned policy_json,
UNIQUE cover_target и entity_cover index. Старые migrations 001–019/checksums
не менялись. InnoDB, прежний exact-schema resumable runner. Новых физических
FK, отдельной object identity и параллельного журнала нет. Reader проверяет
object snapshot/definition, encounter/session, protected participant и точное
соответствие policy исходному pinned object definition.

cover:enter:<entity_id> / cover:leave:<entity_id>, targets=[actor], parameters=[]
идут через Builder → Draft/Seal → Publication R2 → CombatCommitWriter. AP
берётся из pinned policy и общей группы; preview/seal лишь симулируют. Native,
controller/delegation, life/status/behavior и awareness gates сохранены.
Assignments CAS-пишутся CoverPersistence в прежней ACS transaction. Event —
реальный combat.resolved, changes.cover содержит before/after. Покинувший
зону участник теряет assignment по явной leave policy, включая GM relocation.

Разрушение вызывается прежним Environment::damage через Effects object_damage
или object_operation; durability/types/object_version/terminal guards остаются
этапом 8.1.1. Единственный переход в destroyed выполняет typed cover removal
и connection status/LoE/version changes. Combat, GM correction и Clock trigger
используют один helper; Clock boundary identity остаётся прежней. Изменения
назначений и графа сохраняются вместе с объектом, AP/HP, journal, receipt и
outbox. Replay не выполняет destruction event второй раз. Spawn history и
immutable snapshots сохраняются; rewards/loot/new MyBB posts не создаются.
Inventory/CharacterSheets не изменяются, RecoveryService не обходится.

GM assignment API/панель использует encounter.cover_assignment с актуальными
native/session role, POST/CSRF, session/encounter/assignment CAS, reason и
operation key. Политика берётся только из retained object snapshot; arbitrary
state/policy JSON не принимается. Existing EnvironmentService управляет
созданием/разрушением разрешённых объектов, SpatialService — их размещением.
Builder добавляет минимальные занятия/выходы и filtered cover category; GM
panel — назначения на разрешённых существ. Без карты, drag/drop или редактора.

## Intercept, dependencies и видимость

Прежний intercept проверяет позицию/range/LoE/obstacles потенциального source
до расхода реакции. Недоступный source пропускается. После redirect заново
получаются его Speed, собственное cover, DEF/RES и barrier targets. Original
cover не переносится. Те же persisted hit/crit samples определяют новый hit,
crit и damage; нового RNG нет. История original/resolved target сохранена.

Scoped cover read set фиксирует assignments цели, capacity при занятии,
прочитанные object versions и blocker set конкретного directed edge, включая
отсутствие записи. Он входит в seal simulation hash и locked writer facts.
Для cover/obstacle objects глобальный object hash заменён relevant reads и
фактическими virtual changes; прежние non-cover dependencies сохранены.
Добавление/разрушение посторонней преграды другой связи не инвалидирует seal.
Relevant assignment/destruction меняет confirmation на прежнем tape; до freeze
требуется reconfirm, после freeze действует прежний R2 quarantine/recovery.
Старые события не переписываются, цель не подменяется.

Player DTO не содержит raw cover policies, hidden assignments, obstacle source,
KB pin или GM-only причины. Choices, assignment list и draft restore проверяют
awareness и позиционную видимость объекта/существа. Ошибка блокировки общая,
без object ID/name/count. Hidden obstacle остаётся механически действующим.
Карточка сохраняет GM-only mechanical details, hashes убираются из player
seal summary. Общий GM/player cache не добавлен.

## Проверки

PHP 8.5.12 / disposable MySQL 8.0.46, текущая authoritative Stage-6/7 fixture
(тот же socket /tmp/acs222-socket/mysql8.sock). tests/acs_cover_sql.php:

- install/repeat/resume 020 без изменения прежнего ledger;
- partial/substantial/complete с разными явными bonuses и LoE gates;
- два защищаемых существа, durable capacity denial третьему;
- POST/CSRF/role, stale assignment CAS и idempotent GM replay;
- voluntary enter/leave, movement и GM relocation прекращают assignment;
- ignores_cover остаётся blocked, explicit penetration проходит;
- destruction снимает modifier/assignments и увеличивает connection version;
- relevant conflict/reconfirm сохраняет прежний hit/crit tape;
- unrelated cover и blocker другой связи сохраняют confirmation;
- affects_zone исключает объект вне зоны, hidden wall отсутствует в DTO,
  но блокирует известную цель;
- поздний outbox fault откатывает object/graph/assignments/AP/journal;
  повтор commit не повторяет destruction.

Tests/acs_cover_intercept.php расширяет прежнюю pure intercept/mass fixture:
собственный DEF cover, отсутствие inherited cover, blocked interceptor без
расхода, RES через прежний Damage, прежние hit/crit samples, unsupported
missing-map contract. Целевые SQL регрессии acs_spatial_sql.php,
acs_environment_sql.php и acs_awareness_sql.php — PASS. PHP lint, node --check
builder.js, node tests/acs_builder_state.js, git diff --check.

Старый acs_intercept_mass_sql.php дополнительно пробовался, но его legacy
SnapshotReadOnlyDb harness останавливается на unchanged authoritative-connection
guard до выполнения intercept fixture (ACS_AUTHORITATIVE_CONNECTION_REQUIRED).
Он не заявляется как SQL PASS. Intercept integration проверена pure existing
Resolver fixture, SQL atomicity — текущей authoritative cover fixture.
Полный suite не запускался. Каждый SQL fixture использует случайную disposable
БД и удаляет её в finally. Production storage не подключалась.

## Ограничения

Поддержаны прямые directed blockers, один cover на цель, flat KB bonuses для
ordinary direct attacks, explicit fixed durability damage прежнего mass/GM/
trigger contract. Не добавлены обычные attacks-object, геометрический LoE,
stacking нескольких covers, conditional formulas/novel defense statuses,
автоматические hidden creature zone targets или cover modifiers для derived
reflection/passive damage. Unsupported contracts явно блокируются. Installed
MyBB/browser/native publication acceptance этим fixture PASS не подменяется;
прежние publication_enabled/test-only gates сохранены.
