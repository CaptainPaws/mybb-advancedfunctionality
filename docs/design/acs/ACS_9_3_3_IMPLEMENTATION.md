# ACS-9.3.3 — Reflect effect, absorb effect and causal chain safety

База main: `b869e3c5` (merge ACS-9.3.2, PR #1062). A1.86 и A1.17–A1.26;
прочитаны четыре нормативных ACS документа, KB Mechanics Contract и отчёты
9.1.2, 9.3.2, 5.3.2, 8.3.2, 8.3.3. Один Backend PR. `docs/ROADMAP.md`,
исторические отчёты, MyBB Core, CharacterSheets, Inventory и Appearance не
изменены. publication_enabled не включён. ACS-9.3.4 не начинается.

## Implemented

Типизированные `mechanical_parameters.reflect_effect` и `absorb_effect` —
временные pinned self-statuses в прежнем Effects/StatusPersistence. Наложение
использует обычный Builder/Seal/status ability с действующим controller и AP.
`EffectResponses` проверяет typed data и строит только структурный causal plan;
Damage/Effects/Resolver остаются единственным путём механических изменений.
Нового damage/status engine, RNG, журнала, очереди боя или таблиц нет.
Полная схема descriptors добавлена в KB Mechanics Contract.

| Контракт | Фактическая поддержка |
| --- | --- |
| reflect root | ordinary combat.primary, actual resolved recipient, after_damage active/prepared queue → reflection → прежний lifesteal |
| База | reflect_absorbed, reflect_hp_loss, post-defense integer reflect_incoming; явный literal factor |
| Child damage | physical/elemental/true через прежний Damage, DEF/RES, rounding, immunity и absorption получателя |
| Default chain | null: не принимает и не порождает reflect-of-reflect |
| Explicit chain | emit/receive, max_depth с primary root, per_cause=1; детерминированный root→response→leaf |
| Child check | независимая chance 0..100; 0/100 без sample, остальные — отдельная сохранённая uniform53 causal row |
| absorb barrier | прежняя общая pool queue; typed audit фактически поглощённого, additional_absorption=0 |
| absorb resource | после pools, перед HP; существующий integer resource, explicit one-to-one spending conversion и KB limit |

Отражение не вызывает HitCrit заново, on_hit, обычный vampirism, active reaction
или новый ход. Первоначальные AP/resources не оплачиваются повторно. Отдельный
chance-check не является броском попадания. Само passive response не расходует
reaction_used; обычная shield reaction продолжает расходовать свой прежний slot.

Сохраняются parent causal identity и actual combat event ID, original source и
original/resolved target, reflected source/target, status instance и KB pin,
policy, roll reference/sample, allocation и применённый Effects trace. Causal
seal не считается уроном или успешной публикацией. Событие и результат входят
в прежний combat journal/terminal receipt; нового MyBB post/submission нет.

## Causal identity, order и recovery

Identity: sealed-action + original target + resolved target + reflect status ID.
Приоритет — explicit KB priority → applied_order → decimal ID без float.
В supported first-selection subset одна подходящая response на входящее событие;
неподходящий выбранный child завершает ветку, а не создаёт неявный fallback.
Для reflected input нужны explicit parent emit и child receive.

CausalGraph до принятия результата проверяет reference/depth/cycle и visited
identities. Глобального игрового depth limit нет: берутся явные KB ограничения.
Потенциальный цикл блокируется до нового seal RNG. Ошибка целостности внутри
цепочки откатывает весь parent, включая primary damage и расходы.

Explicit seal/prepare_reactions сохраняет только отсутствующие потенциальные
child checks в существующей UNIQUE rolls ledger, check_kind=reaction. В identity
учитывается возможный зарегистрированный intercept recipient. Чтение, preview,
confirm, commit и recovery не генерируют RNG. Missing check блокируется; прежний
hit/crit и child samples сохраняются. Duplicate terminal commit/lost ACK используют
прежний durable receipt. Неизвестный исход не означает новую публикацию.

## Target, authority и видимость

Наложить supported response можно только себе с действующим ACS control; ongoing
passive не выдаёт HTTP actor права на чужой Inventory. Проверяются current
active policy, source/target existence, sides/target_policy, awareness, текущий
form/control overlay и behavior scope reaction. Исходный form_requirement
сохраняется в status runtime, проверяется против immutable definition и остаётся
immutable; смена формы не восстанавливает недоступную способность.

В unmapped encounter допускается только явно unmapped range/LoE policy. В mapped
encounter нужны typed range и requires_line_of_effect=true; child заново проверяет
геометрию reflector → recipient. Cover bonuses идут в прежний Damage. Complete
cover и wall не обходятся через ignores_cover. Obstacle penetration не поддержан.
Availability/geometry отказ не списывает child cost и не меняет исходный hit/crit.

Нового reveal нет; знания reflector о цели проверяются независимо от знания
инициатора HTTP и GM-роли. Публичные summaries получают hashes, не contracts,
child source/target, samples или private KB. Сырой journal остаётся под прежними
CombatCard/Audience permissions; литературный MyBB текст не переписывается.

## Absorption и atomic resources

Порядок компонента: штатный defense/integer allocation → существующая unified
barrier queue → resource absorption → HP/overkill. Каждый следующий механизм
получает только оставшийся budget. Barrier mode не создаёт pool и не вычитает
поглощённое повторно. True damage исключён из supported absorbs filters.

Resource conversion использует прежние Effects::payCosts и Numeric integer/
half_up. Оплата ограничена remaining damage, явным KB limit и current resource.
Проверяются существующая entity-owned resource row, integer semantics, retained
source definition/maximum и exact definition_hash. Schema-only/untyped row
возвращает RESOURCE_UNPROVEN. Нового ресурса, definition, Inventory grant или
внешней owner transaction не создаётся. Общий gate EntityService на регистрацию
extra resources сохраняется; production extra-resource contracts не объявляются
готовыми по одному наличию fixture EP.

HP/resource versions, pool versions, CAS, event, reaction slot и terminal receipt
фиксируются одной прежней InnoDB transaction. Response integrity/roll/provenance
ошибки не маскируются обычным prepared skip. Для существующего pool отделена
проверка immutable reaction origin от проверки права на **новую** активацию:
barrierProjection читает reactionProjection(authorize=false), остальные callers
сохраняют full authorization. Это устраняет цикл
controlContext → statusProjection → barrierProjection → reactionProjection →
controlContext при реальных pool rows, не активирует reaction и не ослабляет
проверку pin/targets/versions пула. Старый pool не требует нового owner grant.

## Locally verified

PHP 8.5.12 CLI/mysqli, disposable MySQL 8.0.46. Только focused fixtures;
полный suite и browser E2E не запускались. Fixtures используют заранее известный
native PID; код не вызывает posting gateway.

`tests/acs_effect_responses_sql.php`:

- Две реально зарегистрированные сущности, authentic controller context, pinned
  response statuses и штатные Draft → Seal → Publish → CombatCommitWriter.
- Один reflect; default не вызывает второй reflect; explicit root/leaf даёт два
  child events; original hit/crit неизменны, отдельные child checks сохранены.
- Cycle не оставляет механических изменений; cyclic seal не создаёт RNG/quota.
  Missing child check не вызывает новый RNG или partial damage.
- Resource absorb с source definition/hash, resource_version CAS и одним списанием;
  неизвестный definition отклоняется.
- Настоящая зарегистрированная shield reaction, SQL pool, resource sink и typed
  barrier audit в одном commit; pool 3 + resource 7, без double absorption.
  Отзыв combat activation permission оставляет новую reaction disabled, а
  immutable historical pool по-прежнему читается без повторной активации.
- На SQL-hydrated virtual state: partial cover DEF идёт через Damage, complete
  cover блокирует child, hidden/unidentified recipient не раскрывается;
  original tape и awareness остаются прежними.
- Duplicate seal/commit, late outbox failure, полный mechanical rollback,
  lost ACK и recovery с теми же samples; AP/slot/post/round не дублируются.

Точечные regressions: `acs_ability_operations_sql.php` и
`acs_prepared_execution_sql.php`, `acs_combat_items_sql.php` и
`acs_transform_sql.php ... writer`. Дополнительно проверены executable JSON
пример из KB contract, strict range normalization и resource-definition guards. PHP lint изменённых файлов и
`git diff --check`; JS не менялся, node --check не требуется.

Отдельный baseline failure: `acs_intercept_mass_sql.php ... mass` завершается
`ACS_AUTHORITATIVE_CONNECTION_REQUIRED` в `acs_entities_regression.php:44`
(mock SnapshotReadOnlyDb), до новой reflection/absorption механики. Воспроизведено
на чистом main `b869e3c5` из git archive в том же PHP/MySQL окружении. Старые
fixtures не переписаны; реальный pool/ReactionService проверяется новым
self-contained authoritative harness.

## BLOCKED / UNSUPPORTED

- Generic effects/callbacks, active-reaction reflect, отражение произвольных
  status/control эффектов, автоматические prepared/start/environment/tick triggers.
  Root v1 объявляет только combat.primary; другие event kinds не переименовываются.
- New virtual reflect instance раньше в той же публикации: нет durable status ID;
  исполнение блокируется, не генерирует ad-hoc RNG.
- Произвольные recursive chains, повтор того же instance в cause, неявный fallback,
  смешение legacy reflection и typed response на одном входящем событии.
- Fractional/custom conversion, resource gain/creation, HP/AP/item conversion,
  чужой Inventory, новые pool kinds и obstacle penetration.

## Installed NOT_VERIFIED

На warprift.ru не устанавливалось. Installed MyBB 1.8.41, production KB data,
реальные extra-resource owner definitions, player/observer rendering и native
post/recovery lifecycle требуют отдельной приёмки. Локальная SQL verification
не означает поддержку всех KB combinations или включение publication_enabled.
