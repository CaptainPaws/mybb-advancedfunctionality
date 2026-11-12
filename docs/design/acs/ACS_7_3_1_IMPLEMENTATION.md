# ACS-7.3.1 — Side relationships, control effects и safe restoration

База main `32a84f23` (merge ACS-7.2.2, PR #1040). Основание: A1.78–A1.79,
A1.41–A1.43; четыре нормативных документа, KB mechanics и отчёты 7.1.2,
7.2.2, 5.1.2, 5.3.2. Один ограниченный PR; `docs/ROADMAP.md` не изменён.
ACS-7.3.2 не реализован.

## Данные и разделение систем

Новых таблиц/миграций/колонок нет. Registry остаётся 015_delegations.
Используются реальные participant.side_id, side_relationships с relation_version,
controllers с assignment_version и существующие status effects с effect_version.
Нового реестра персонажей, групп или AP нет.

Domain/Control — чистая проверка target policies и эффективного overlay;
прежние Effects/Resolver/Clock исполняют и завершают status instance. Базовые
controllers никогда не переписываются применением или истечением overlay.
Runtime `instance.control_overlay` хранит caster UID и application base version;
история/полный pinned spec остаются в прежних effects/snapshots/events.

## Стороны и отношения

GM backend-команда `side_change` принимает session/encounter/participant CAS,
entity_id, side_id, operation_key и reason, с прежними POST/CSRF и native/GM
проверками. Только engaged участник active/paused encounter. Результат/audit
содержит старую/новую сторону, previous/current participant version, scope,
server source=gm_decision/actor UID и durable event identity. Повтор ключа
идёт через существующий command journal. CharacterSheets/faction/owner UID
не изменяются. Legacy participant_side в preparation сохранён.

`relation_set` теперь доступен также active/paused с обязательным
relation_version (0 для отсутствующей пары). Allied/neutral/hostile симметричны
в прежней canonical pair table. Audit хранит before/after/version/source/scope.
Alliance не меняет control_group, turn, ресурсы или controller assignment.
В существующей GM management панели — небольшие CAS-формы стороны/отношений
и отдельный read-only список overlays поверх прежнего списка базовых NPC
назначений. GET не исполняет механику.

Эти команды и NPC controller_set могут законно менять соответствующие
зависимости при незавершённой публикации. Они не подтверждают её сами:
preflight/locked fact fence/повторная authorization блокируют stale commit,
сохраняя известный PID, tape и прежние recovery obligations.

## KB target policy и friendly fire

Поддержаны hostile_only, allied_only (включая self), self_only, any_entity и
ограниченный custom по точным relationship/entity-kind lists. Binding и
примеры находятся в ACS_KB_MECHANICS_CONTRACT.md. Нет faction fallback,
геометрии, автоматического поиска скрытых целей или arbitrary predicates.
Существующие cardinality, chance, polarity/immunity и numeric rules сохраняются.

Selector фильтруется сервером по visibility и хотя бы одной доступной KB policy;
конкретная способность повторно проверяется в prepare и Resolver. Eligible
interceptors также проверяются по текущей policy; реакции/protection используют
актуальный relationship map и control proof. Aura producer в текущей модели
отсутствует; новый aura/behavior engine и неописанные aura fields не включаются.

Перед Seal известный direct/periodic friendly damage и возможный видимый
allied redirect дают предупреждение. Клиент требует отдельное явное согласие;
сервер проверяет opaque hash до выделения RNG/quota; ACK сохраняется
в existing seal metadata в той же транзакции. Скрытые участники/definitions в warning не выводятся.
Автоматического уменьшения damage союзникам нет.

Изменённые relation/participant/assignment versions, status state и control
result hash инвалидируют старое подтверждение. До issue пересчитывается
сводка с прежними samples; при новом friendly risk нужен новый ACK. Fixed
selected targets не меняются. Если frozen submission уже имеет native PID,
stale commit блокируется в прежнем R2 recovery protocol; второй post не создаётся.

## Временный контроль и безопасное снятие

Контракт — typed control_effect внутри pinned KB status definition, только
combat, independent/source instance, target_turn или round duration. Controller
равен authenticated caster, включая действительного делегата, а не permanent
owner UID источника. Приоритет слоёв: больший priority, затем новый applied_order.

Только перечисленные ordinary ability keys цели; временный контроллер не
получает новые способности. Builder не показывает остальные private keys,
labels/descriptions; full snapshot остаётся под прежней owner/GM policy.
Отдельно закрыты item/equipment operations, reactions и upkeep ordering.
Hidden identity не становится доступной через controllerFor/AP/builder API;
GM/native privileges, существующие независимо, сохраняют отдельные gates.
Overlay не распространяется автоматически на питомцев и summon источника.

Control proof содержит effect ID/version, application base reference и
**текущую** assignment generation. Новое законное назначение можно сохранить
во время overlay; оно не отменяется expiry. Clock/cleanse/dispel меняют только
соответствующий effect row, после чего authorization видит нижний слой либо
актуальную базу. Stale cached controller UID не записывается обратно.

Применение/снятие не создаёт turn/post, не возвращает AP/seal quota, HP/resources,
cooldowns или reaction_used. Expiry на существующей end/round boundary входит
в тот же atomic commit и durable receipts. Схема Clock/engine и RNG не заменены.

## Проверки

PHP **8.5.12**, disposable MySQL **8.0.46**, существующий authoritative
Inventory/ACS fixture; native users/posts в MyISAM. Production не подключалась.

- `acs_control_policies.php`: relationship matrix, priority/multiple layers,
  снятие верхнего/последнего слоя, актуальная base generation, deny item/property
  categories и malformed grant.
- `acs_side_control_sql.php`: real KB DTO/pins; hostile/allied/neutral/custom;
  friendly fire warning, отказ до RNG/quota, полный damage; reconfirm на старой
  tape после отношения; post-confirmed stale relationship refusal; side audit и
  неизменные бюджеты; caster UID через actual delegation; overlay/private/hidden/
  ability/category gates; late outbox rollback/retry; два SQL handles, contention
  assignment под lock; новое base назначение при активном overlay; expiry без
  stale restore; один прежний group budget/post, duplicate commit; GM renderer
  GET с version fields/overlay metadata без writes.
- Точечные SQL регрессии: delegation, NPC controllers, summon lifecycle,
  combat items. Domain upkeep/status/Resolver chain — через existing
  `acs_sustained_upkeep.php`.
- `php -l` изменённых/новых PHP, `node --check` builder.js, `git diff --check`.

Полный suite, browser E2E и installed MyBB acceptance не запускались.

## Границы

Control subset — caster + ordinary explicit keys; без arbitrary UID delegation,
refresh/shared control instance, source-state scripts, behavior/charm/taunt,
ownership/Inventory grants или CharacterSheets writes. Unknown policies и
неподдержанные control contracts отклоняются. Objects/spatial/hidden automatic
selection относятся к последующим этапам. Старые untyped control metadata
не превращаются в права. Existing sealed plans после обновления могут требовать
обычного reconfirm на исходной tape; publication flags не включаются.
PHP/JS consumer должен обновляться вместе. Следующий этап не начат.
