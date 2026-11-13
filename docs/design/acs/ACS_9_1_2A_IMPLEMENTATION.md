# ACS-9.1.2A — Prepared phase interleaving и causal execution order

База main: `377bea83` (merge PR #1053 / ACS-9.1.2).
Один ограниченный Integration PR; ACS-9.1.2B не начат.
`docs/ROADMAP.md`, historical implementation reports, migrations 001–021,
Inventory owner modules и publication_enabled не изменены.

## Источники

[Master](ACS_MASTER_SPECIFICATION.md),
[Technical Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md).
Нормативные требования A1.17–A1.26, A1.75–A1.76.
Основания существующих pipelines:
[9.1.1](ACS_9_1_1_IMPLEMENTATION.md),
[9.1.1B](ACS_9_1_1B_IMPLEMENTATION.md),
[9.1.2](ACS_9_1_2_IMPLEMENTATION.md),
[5.3.2](ACS_5_3_2_IMPLEMENTATION.md),
[8.3.3](ACS_8_3_3_IMPLEMENTATION.md).

## Implemented — фактическая матрица

Execution descriptor остаётся typed `ic-acs.prepared-execution.v1` с explicit
KB phase/priority, fixed target и per-cause limits. Никакой клиентский callback
или выбор нового target не добавлен.

| Trigger kind / phase | Gate | Interleaving |
|---|---|---|
| combat.primary / on_targeted | requires_hit=false | После on_cast; одна очередь с on_targeted reactions, до before_hit |
| combat.primary / before_hit | requires_hit=false | Одна очередь с before_hit reactions, до hit; samples не меняются |
| combat.primary / after_hit | requires_hit boolean | После hit/on_hit, до primary damage; одна очередь с after_hit reactions |
| combat.primary / after_damage | requires_hit boolean | После damage/on_damage; одна очередь с after_damage reactions, до reflection/lifesteal |
| Existing reaction passive children | Прежний pinned Stage-5 contract | Синхронно в точке родительской реакции; до следующего участника очереди |
| Existing reflection → lifesteal | Прежние правила A1.25–A1.26 | После after_damage, без новой prepared dispatch |
| Derived-event prepared triggers / recursive chains | UNSUPPORTED | Не переименовываются в combat.primary и не переносятся в after_damage |

Early requires_hit=true отвергается: будущий результат не используется до hit.
Late requires_hit=true требует hit/crit по фактическому source-subject;
false допускает miss согласно явному KB contract. Redirect away делает original
subject недоступным для соответствующего исполнения; sealed fixed target
самого prepared никогда не заменяется.

## Единственный Resolver и общий virtual state

Новый Domain/PreparedPhase — только internal typed bridge и metadata traces.
У него нет своего battle state, persistence, RNG или KB interpreter. Writer
передаёт server closure, связанный с проверенной publication; KB/HTTP не могут
передать исполняемую функцию. Resolver объединяет descriptors с прежней фазовой
reaction queue и передаёт текущий state разрешённому child Resolver/Effects.
Child не входит снова в active/prepared queues.

Сохранён порядок phases независимо от priority. Внутри фазы: KB priority →
immutable registration event session_sequence → stable ID. Для prepared —
исходный prepared registration event. Для ordinary reaction causal_order читается
из существующего encounter.reaction_register event/result с hash verification.
Локальный registry registered_order и старые строки не переписываются. Оба
порядка обычных реакций монотонны по исходной регистрации; игровые default
priorities/лимиты не меняются. BIGINT сравниваются по длине decimal string,
затем лексически, без float/int conversion. Полный cross-registry tie имеет
stable kind discriminator; недоказанный registration order блокирует resolution.

Последовательные primary actions и multiple targets идут в sealed order.
Каждое воздействие получает собственную causal identity; terminal prepared CAS
не допускает второго исполнения на следующем ударе. HP/resources/barriers/status
и sustained interruption уже выполненного child доступны следующей реакции,
цели и действию. End boundaries обрабатываются прежним Clock после действий,
а не до prepared. До hit с теми же samples перепроверяются current target,
range/cover; прежние intercept/HitCrit/Damage/Effects не заменены.

Writer больше не исполняет prepared после primary SQL projection. Полученный
совместный ResolverResult один раз проходит прежний CombatCommitPersistence:
entity/resource/group/reaction/status/barrier/summon CAS. Нет второго SQL writer,
отдельной боевой очереди с состоянием, таблицы quantities или нового журнала.
Нового поста, группового хода, оплаты AP или reaction_used для prepared нет.

## Causal tape, identity и atomic recovery

До mechanical transaction прежний 9.1.2 causal seal сохраняет armed executions
и server hit/crit по prepared_action_id + trigger_event_id. У разных actions и
original targets разные events. Для новых фаз key включает phase. Прежний
ключ after_damage сохранён для recovery ранее armed 9.1.2. Все потребные checks
уже durable до child/owner writes; replay не вызывает новый RNG.

Проверяются prepared contract hash/version/status, исходный seal/ability pin,
source event/originating principal и frozen primary seal. Дополнительно сверяются
armed source IDs, trigger event type/submission/actor/subject/key и content hash.
Повреждённая tape/provenance, CausalGraph cycle, orphan/unknown owner outcome
не превращаются в optional skip: весь parent rollback/recovery barrier. Не
посещённый обязательный phase event также блокирует проекцию primary result.

Перед child повторены current control/delegation/membership/native rights,
status/behavior, awareness, fixed target, range/cover/LoE, expiry и costs; child
получает текущий общий virtual state. После owner participation права читаются
ещё раз. Смена исходных sealed dependencies сохраняет прежний reconfirm/conflict
path; source tape/draft не переписываются. Unsupported virtual control-overlay
transition не исполняется со старыми правами.

Owner consume использует тот же 9.1.1B reservation/receipt/operation identity и
Stage-6 same-writer participant. Child savepoint откатывает optional condition
failure вместе с расходами; integrity failure откатывает parent полностью.
On_prepare costs/AP не оплачиваются повторно. On_trigger item/resource costs
применяются в фактической фазе; unknown consume не освобождается как unused.
Runtime Inventory actor/grants не расширены: соответствующая foreign-owner
ветка остаётся BLOCKED. Cancel/expiry settlement сохраняет прежний protocol.

В том же ACS commit executions получают outcome/reason/costs/owner receipts,
mechanical deltas, phase event, causal trace, terminal state и parent event ID.
Result plan/final содержат реально применённый порядок. Исходное доказательство
frozen confirmation хранится отдельно в confirmation_summary; summary для
карточки обновляется по фактическому результату и не выдаёт старые summary hashes
за новые. Internal prepared markers не отображаются как ordinary reactions и
не расширяют player доступ к hidden contracts. Чтение receipt/card SQL-only.

## Locally verified

PHP **8.5.12** / disposable MySQL **8.0.46**, существующий authoritative harness,
socket `/tmp/acs222-socket/mysql8.sock`; случайные БД удаляются fixture finally.
Native PID 501 уже задан fixture; Gateway и production forum не вызываются.

| Проверка | Доказательство / результат |
|---|---|
| on_targeted, before_hit, after_hit, after_damage | acs_prepared_phases_sql.php: phase trace, HP before/after damage, один owner consume — PASS |
| Multiple targets | Тот же fixture, multitarget before_hit: исходные target order/tapes, child до primary damage — PASS |
| Последовательные actions, HP между событиями | acs_prepared_execution_sql.php multiple: child первого удара меняет HP до второго, второй cause skipped/unavailable, различные event IDs — PASS |
| Ordinary reaction / prepared priorities | acs_prepared_phase_order_sql.php: actual owner-pinned reaction, priorities 99/100/101 относительно prepared=100 — PASS |
| Phase order выше priority | Reaction before_hit priority=101 исполняется раньше prepared after_damage priority=100 — PASS |
| Equal priority / deterministic BIGINT ties | Prepared registration раньше reaction при priority=100; comparator с IDs >2^53 и registration orders около BIGINT max — PASS |
| Duplicate / fixed rolls / late rollback | Каждая phase fixture: outbox fault откатывает HP/AP/resources/items/turns, оставляет armed tape; commit и duplicate её не меняют — PASS |
| ACK recovery | Existing prepared execution fixture: реальный COMMIT, потеря ACK, durable receipt через RecoveryService без consume/reroll — PASS |
| Stale original target | Phase fixture stale: confirmation conflict без изменения drafts/armed tape/механики — PASS |
| Corrupt causal provenance | Phase fixture corrupt: неверный source seal ID блокирует parent до механики — PASS |
| Unsupported recursive emission / unknown phase | Typed descriptor rejects emit_prepared=true и неизвестную phase; прежний CausalGraph cycle guard — PASS |
| Revoked delegation / hidden target | Existing prepared execution conditions delegation/hidden: skipped без расхода, повтор стабильный — PASS |
| Owner version / unknown outcome | Existing execution conflict/orphan: skip с сохранённой tape либо recovery barrier, без blind consume/release — PASS |
| Legacy no execution descriptor | Phase fixture legacy: diagnostic UNSUPPORTED, без implicit phase/RNG/cost и без блокировки primary — PASS |
| Совместимость | acs_combat_items_sql.php, acs_prepared_items_sql.php, acs_prepared_actions_sql.php — PASS |

Команда: `php tests/acs_prepared_phases_sql.php SOCKET single on_targeted`;
аналогично `single after_hit`, `single after_damage`, `multitarget before_hit`,
`stale before_hit`, `corrupt before_hit`. Для порядка:
`php tests/acs_prepared_phase_order_sql.php SOCKET 100 after_damage` и
`... SOCKET 101 after_damage before_hit`.

`php -l` изменённых/новых PHP, `git diff --check` — PASS.
JS не менялся, node --check не требовался. Полный suite не запускался.

### Отдельный baseline failure

acs_intercept_mass_defense.php не доходит до intercept assertions: зависимый
acs_status_evaluation.php:136 падает с Wrong persistent causal key / отсутствующим
mock argument 2. Тот же запуск из чистого git archive origin/main `377bea83`
даёт идентичный сбой. Старый harness не изменялся и не объявляется PASS.
Предшествующие pure Damage/Effects/Resolver checks в этой цепочке проходят.

## Remaining BLOCKED / UNSUPPORTED

Generic prepared chains, emission active reactions от prepared child,
derived-event prepared triggers, arbitrary effect callbacks/target selectors
не реализованы. Несколько primary actions не означают поддержку рекурсивных
цепочек. Уже разрешённые passive/derived эффекты сохраняют прежние causal guards.

Prepared consumer остаётся single fixed-target damage ability без status/removal
components, как 9.1.1/9.1.2. Primary trigger может быть single/multiple strike;
item/spatial/object/search/spawn actions не становятся новыми prepared event
kinds. Если такой action пытается породить недоказанную ветку, commit блокируется
до mechanical projection. Нет автоматического выбора нового hidden target,
порождаемого на лету нового entity ID или замены сохранённого item.

Virtual control-overlay transition, для которого owner/controller projection
не подтверждает новые полномочия, остаётся UNSUPPORTED. Foreign runtime owner
Inventory authority остаётся BLOCKED ровно прежней Stage-6 policy. Дополнительных
Inventory grants, impersonation, reserve-on-trigger или нового owner protocol нет.

## Installed NOT_VERIFIED

warprift.ru / MyBB 1.8.41: actual hooks/routing/native permissions, установленная
schema/engines, grants, themes/editor, ACP lifecycle и publication acceptance
не проверены. Local MySQL PASS не включает publication_enabled и не заменяет
Stage-4 R2/Stage-6 installed acceptance. MyBB Core, CharacterSheets,
AdvancedAppearance и Historical Stage-6 reports не менялись.
