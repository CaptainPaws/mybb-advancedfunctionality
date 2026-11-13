# ACS-9.2.1 — Hidden prepared actions и independent awareness

База main: `bb20b4a8` (merge PR #1055 / ACS-9.1.2B).
Один ограниченный Integration PR. `docs/ROADMAP.md`, исторические отчёты,
миграции 001–021, MyBB Core, CharacterSheets, AdvancedAppearance и publication
flags не изменены. ACS-9.2.2 не начат.

## Источники

[Master](ACS_MASTER_SPECIFICATION.md), [Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics](../../contracts/ACS_KB_MECHANICS_CONTRACT.md).
A1.72, A1.75–A1.76 и A1.81; основания:
[9.1.1](ACS_9_1_1_IMPLEMENTATION.md), [9.1.2](ACS_9_1_2_IMPLEMENTATION.md),
[8.1.2](ACS_8_1_2_IMPLEMENTATION.md), [8.3.3](ACS_8_3_3_IMPLEMENTATION.md),
[7.3.3](ACS_7_3_3_IMPLEMENTATION.md).
Текущие interleaving и owner boundaries сохранены из
[9.1.2A](ACS_9_1_2A_IMPLEMENTATION.md) и [9.1.2B](ACS_9_1_2B_IMPLEMENTATION.md).

## Implemented — hidden mode того же prepared_action

Pinned prepared_action.hidden с schema=ic-acs.hidden-prepared.v1 задаёт право
скрытой подготовки, source_stealth requirements, retained perception/tag/disclosure,
on_detected=retain и optional reveal_on_trigger. Полный JSON descriptor описан
в KB Mechanics. Клиент выбирает уже разрешённую способность, не передаёт
hidden/capability flags или права владельца.

source_stealth — необходимые состояния invisible/hidden/concealed из retained
base perception либо active status stealth. Builder проверяет их до seal;
единственный Resolver повторяет проверку при cost-only registration/confirmation.
Пустой список явно допускает видимого исполнителя со скрытым действием.
Нет новой универсальной stealth formula, второго Resolver или бесплатного RNG.
Состояние hidden action не накладывает stealth status на существо.

Сохранены current authority/delegation/native/session/encounter gates, ability
pins, AP/resource/item timing, global owner reservations и until_next_turn.
Подготовка оплачивается прежней publication без MyBB post. Detection не меняет
prepared status/version, оплату, sealed source action или rolls.

## Awareness и causal detection

Existing awareness subjects расширены kind=prepared_action. ID — prepared ID,
а не entity/object ID. perceptionSubjects собирает только pinned hidden contract
из durable prepared row с проверкой contract hash; source/owner/creator refs
остаются внутренними. Исторические terminal subjects сохраняют знания; поиск требует active/engaged
source. Active search не обнаруживает их как действующие подготовки. Новых tables/migrations
не требуется: schema 017 уже содержит varchar(24) и UNIQUE по kind/ID/audience.

Уровни unknown/suspected/detected/identified, actor/control_group/side/encounter,
GM CAS/audit и awareness.check receipts остаются прежними. Entity/object knowledge
не переносится на prepared и обратно. Видимый противник может иметь неизвестную
подготовку; suspected/detected action не раскрывает исполнителя.

Существующая self search/share ability может явно выбрать subject_kind=prepared_action,
KB tag/chance/level/audience. Проверка — прежний sealed hit sample отдельного
paid action на весь tag scope независимо от числа hidden subjects, без отправки
их IDs клиентом. Stored causal attempt включает prepared namespace. Новый check
требует другого разрешённого sealed cause; read/duplicate/recovery не делают RNG.
GM disclosure — отдельный авторизованный event с CAS и durable idempotency.

## Reveal on trigger

Только explicit policy: relative_to=source либо trigger_actor; audience=
actor/control_group/side/encounter; level=suspected/detected/identified.
null не делает reveal. reveal_source=false обязательно для этого subset.
После успешного admission/execution общий virtual state получает knowledge
только данного prepared subject. Shared tag не раскрывает другие commitments.
Entity awareness, stealth statuses и source identity не меняются.

Audience IDs вычисляются на сервере из текущего encounter source/attacker.
Execution trace сохраняет subject/audience/level и source_identity_revealed=false.
Knowledge проецируется прежним persistAwareness в той же combat transaction,
что HP/resources/owner consume/execution/event/terminal receipt. Late rollback
откатывает reveal вместе с механикой, оставляя durable causal tape; committed
parent replay читает старый receipt и не повторяет раскрытие.

## DTO, UI, GM и права

AudienceService остаётся единым policy reader:

- unknown — ни entries, ни prepared IDs/контрактов/счётчиков;
- suspected/detected — approved hint без subject/source/owner identity;
- identified — prepared ID и разрешённые KB details, без source entity, ability,
  trigger, fixed target, item bindings или owner grants;
- owner/creator management view и разрешённый session GM — доступ к собственной
  либо управляемой hidden preparation по прежнему native/session scope.

Combat delegation сама по себе не раскрывает чужой hidden commitment. Builder
prepared_actions фильтруется до JSON; знание о теле исполнителя не заменяет знание
о действии. В capabilities собственного контролируемого источника может быть
доступна сама KB ability — это не признак наличия конкретной подготовки.
Owner/creator implicit management access не создаёт actor/group knowledge rows
и не является автоматическим share grant.

Используется прежний ability selector (метка «Скрыто подготовить действие»),
prepared state и generic knowledge notices. Нового editor/JS runtime нет.
Player/delegate/observer combat cards по-прежнему получают status-only projection,
internal prepared markers не становятся обычными reactions. Raw event feed
остаётся session-management-only; новые raw player export/notification consumers
не подключены. Existing combat outbox содержит лишь parent metadata, без hidden
prepared refs/counts. Native MyBB литературный текст не редактируется.

Audience stamp учитывает subject registry; current UID/native/role/awareness
partitions существующего cache сохранены. Общего GM/player cache нет.

GM может читать/cancel **hidden** preparation с session/native management rights,
CSRF, CAS и event audit, не получая права её исполнить или потратить чужой item.
Обычные prepared contracts сохраняют прежний controller-only read/cancel scope.
GM cancellation с чужим unused item не делает release от имени владельца:
сохраняются reservation и recovery_required obligation по 9.1.1B.
Known ID не даёт делегату cancel/contract access.

## Locally verified

PHP **8.5.12** / disposable MySQL **8.0.46**, существующий authoritative
owner/prepared fixture harness, socket `/tmp/acs222-socket/mysql8.sock`.
Все БД случайные, удаляются finally; production storage/Gateway не вызываются.
Native PID 501 заранее задан fixture, preparation не создаёт post.

`php tests/acs_hidden_prepared_sql.php SOCKET MODE`:

| Проверка | Результат / доказательство |
|---|---|
| Hidden registration | Два независимо оплаченных hidden commitments, retained pins/costs и deferred source seals — PASS |
| Concealment requirement | requirements: отсутствующий invisible блокирует Builder до seal/cost/RNG; данные до/после совпадают — PASS |
| Independent awareness | Entity identified не раскрывает action; action suspected/identified не раскрывает entity. Typed object/prepared ID namespace assertion; existing object SQL awareness regression — PASS |
| Уровни и privacy | suspected/detected без ID/name/performer; identified с prepared ID, без source/owner refs — PASS |
| GM/player/delegate/observer/outsider | Unknown notices отсутствуют, delegate Builder prepared_actions=[], known-ID cancel denied; GM management read; outsider denied — PASS |
| Detection retain / duplicate | Все уровни через реальные GM awareness commands, повтор exact request возвращает прежний результат; preparation active/version и sealed_actions/rolls не меняются — PASS |
| Sealed SQL search | search: pinned paid search перед attack в той же publication; durable prepared_action awareness.check receipt, без reroll на повторе — PASS |
| Reveal/null/scopes | search, no-reveal, group-reveal: explicit encounter/group scope; несоответствующий audience остаётся unknown — PASS |
| Reveal только одного action | same-tag: другой active hidden commitment с тем же tag остаётся unknown — PASS |
| Rollback / ACK recovery | Outbox fault после execution/reveal откатывает awareness/HP/items; реальный COMMIT с lost ACK возвращает старый receipt; duplicate сохраняет tape/costs/knowledge — PASS |
| Cards/feed/outbox | Player/delegate/observer details=null, GM-only event feed denied, notification payload без hidden refs; current audience filtering — PASS |
| GM cancel без owner rights | gm-cancel: actor audit/CAS, cancelled + recovery_required, чужой item не списан/не освобождён — PASS |

Регрессии: acs_awareness_sql.php (objects/entities/search/share/cache/receipts),
acs_prepared_execution_sql.php (обычное execution/rolls/owner consume),
acs_prepared_owner_sql.php normal (A/B authority/rollback/recovery) и
acs_prepared_actions_sql.php (обычный paid prepare/privacy/expiry) — PASS.
PHP lint изменённых/новых PHP и git diff --check — PASS. JS не менялся;
node --check не требовался. Полный suite не запускался. Старый baseline-broken
intercept harness не переписывался и в этом PR не запускался.

## Remaining BLOCKED / UNSUPPORTED

Поддержаны declarative source stealth requirements, existing pinned search/share
chance и on_detected=retain. Generic stealth formulas, случайный on_prepare check,
on_detected=cancel, reveal_source=true, passive preparation detection и произвольные
callbacks/selectors пока UNSUPPORTED, а не подменены успешной проверкой.
Source identity можно раскрыть отдельным явным разрешённым entity-awareness event;
action reveal не делает этого неявно. Literal KB disclosure text остаётся явно
разрешённым текстом, actor name/ID сервером в него не интерполируется.

Нет pre-round ambush start, ambush_resolution, новых surprise modifiers, форм,
recursive chains или новых Inventory прав. Legacy prepared/owner contracts и
их ограничения не обновляются автоматически. Полный старт засады — ACS-9.2.2.

## Installed NOT_VERIFIED

warprift.ru / MyBB 1.8.41: реальные native hooks/ACL, theme/editor/GM UI, caches,
exports/notification consumers, owner schema/grants и R2/Stage-6 acceptance не
проверены. Local MySQL PASS не означает installed READY и не включает publication.
Новых схем/миграций и общего MyBB+ACS COMMIT нет.
