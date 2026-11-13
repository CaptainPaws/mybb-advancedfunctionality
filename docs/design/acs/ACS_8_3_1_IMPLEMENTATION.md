# ACS-8.3.1 — Zones, engagement, range и movement

База main: b2d37d9d (merge ACS-8.2.2, PR #1047). Требования A1.57,
A1.79, A1.81–A1.82. Прочитаны четыре нормативных ACS-документа,
KB mechanics contract и отчёты 8.1.2, 8.2.1, 7.3.3.
`docs/ROADMAP.md` не изменён. Переход к 8.3.2 не выполнялся.

## Реализация и KB subset

Domain/Spatial.php — чистая типизированная часть существующего Resolver,
без второго processor, RNG, координат и pathfinding. Полная грамматика
добавлена в ACS_KB_MECHANICS_CONTRACT.md, раздел ACS-8.3.1 spatial subset.
Карта опциональна: прежние действия без spatial range работают без карты.
Single-zone template явно задаёт implicit default zone, но не engagement.
В zones mode GM назначает позиции; чтение не создаёт строки.

Дистанции: engaged только для явного контакта, иначе near в одной зоне;
между зонами near/medium/far из прямой открытой направленной связи, иначе
unreachable. Обратный переход не подразумевается. Range, target policy,
life/status/control/delegation/native permissions и awareness проверяются
раздельно. LoE поддерживается только явной boolean capability карты и
зоны/связи; отсутствующая capability блокируется как UNSUPPORTED.

Move, engage и leave — обычные отдельно запечатываемые действия. Default
move AP=1, явная стоимость связи имеет приоритет. Последующие действия
проверяют новую виртуальную позицию и общий AP группы. Preview/seal не
пишут позиции. Registered pinned ability spatial_effect реализует явный
forced_move/teleport к KB destination, оплачиваемый источником; AP цели не
списывается. Перемещение разрывает контакты согласно policy, без новой
универсальной opportunity attack. Неопределённые направления и неизвестные
поля не исполняются.

## Схема, identity и event bindings

Следующая свободная resumable migration — 019_spatial. Добавлены только
af_acs_zones, af_acs_zone_connections, af_acs_positions, af_acs_engagements
с UNIQUE encounter/zone_key, directed edge, entity/encounter position и
канонической парой engagement. Старые checksums 001–018 не менялись.
Используется прежний migrator exact schema/index readback, InnoDB.

Entity identity остаётся существующей entities.id, encounter — encounters.id;
SQL projection проверяет session/encounter scope, connections принадлежат
зонам encounter. Не вводятся отдельные actor/object IDs. Полный KB read DTO
карты закрепляется immutable snapshots.id, source_system=knowledgebase,
contract_id=ic-acs.spatial.v1; snapshot hash и retained definition сверяются
при чтении. Encounter config хранит snapshot ID и pin.

Combat изменения позиции/контакта пишутся SpatialPersistence из прежнего
CombatCommitPersistence внутри ACS transaction. Реальный source event —
combat.resolved, changes.spatial содержит before/after соответствующих строк;
порядок sealed actions остаётся порядком Resolver. GM изменения используют
прежний encounter.spatial_* command/audit event с reason, operation key,
session/encounter CAS и row CAS. Ни новых MyBB posts, ни отдельного журнала,
ни записи Inventory/CharacterSheets нет. Publication R2 и RecoveryService
сохранены; повтор submission возвращает прежний terminal receipt.

## Конфликты и видимость

Seal summary содержит hash только прочитанных spatial dependencies и
изменённых позиций/контактов. Writer выводит тот же read set на прежнем tape,
проверяет и блокирует только выбранные позиции, зоны, edges и контакты,
включая отсутствие строки. Изменение несвязанной связи не инвалидирует seal.
Затронутое изменение требует прежнего conflict/reconfirm, frozen конфликт
остаётся в R2 recovery protocol; rolls не заменяются. Существующие общие
transaction/session fences сохранены.

Player DTO не содержит full projection, pin, snapshot или GM-only позиции.
Audience policy до selectors/JSON исключает undisclosed source/target;
object/cooperative choices и draft restore также проверяют текущую позиционную
видимость. Публичные zone names выдаются только для public zones. Нет общего
GM/player DTO cache. Builder показывает минимальные movement/contact choices
и разрешённые позиции; GM-панель — configure по KB, placement и смену статуса
направленных связей. Без тактической карты, нового редактора и изменений темы.

## Проверки и ограничения

Disposable MySQL 8.0.46 / PHP 8.5.12, текущие authoritative Stage-6/7 fixtures.
Новый tests/acs_spatial_sql.php проверяет migration repeat, one-zone near,
explicit enter/leave, directed far/medium/unreachable, adjacent move 1 AP,
move then attack, два последовательных перехода с KB AP override, blocked
connection, insufficient AP, forced move и teleport без AP цели, разрыв
контакта без атаки, hidden position DTO, stale edge с сохранённым tape,
несвязанную изменённую связь, duplicate commit и late rollback с повтором.

Целевые регрессии: acs_object_interaction_sql.php, acs_cooperation_sql.php,
acs_awareness_sql.php. PHP lint, node --check builder.js,
node tests/acs_builder_state.js и git diff --check. Полный suite не запускался.
SQL fixtures используют отдельные случайные БД и удаляют их в finally.

Поддержаны только прямые связи, явные диапазоны и deterministic on-cast
forced effects. Нет транзитивной дистанции, grid, геометрического LoE,
динамического добавления зон, произвольных on_hit movement или opportunity
attack. Установочный browser/native MyBB acceptance не подменяется fixture
PASS; production storage не подключалась.
