# ACS-9.1.2 — Prepared trigger execution и fixed causal rolls

База main: `31ec531d` (merge ACS-9.1.1B / PR #1052). Один Integration PR.
`docs/ROADMAP.md`, исторические отчёты и миграции 001–021 не изменены.
ACS-9.2.1 не начат; скрытая засада и автоматический reveal не добавлены.

## Источники

A1.75–A1.76 и A1.17–A1.26: [Master](ACS_MASTER_SPECIFICATION.md),
[Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics](../../contracts/ACS_KB_MECHANICS_CONTRACT.md).
Основания существующих pipelines: [9.1.1](ACS_9_1_1_IMPLEMENTATION.md),
[9.1.1B](ACS_9_1_1B_IMPLEMENTATION.md), [8.3.3](ACS_8_3_3_IMPLEMENTATION.md),
[6.3.2](ACS_6_3_2_IMPLEMENTATION.md), [5.3.2](ACS_5_3_2_IMPLEMENTATION.md).

## Implemented

CombatCommitWriter находит active prepared commitments, адресованные source,
выбранному целью frozen primary action. Срабатывание — internal server path:
нет HTTP endpoint для произвольного trigger ID, samples, source UID или target.
Writer не создаёт дополнительного MyBB post/submission/turn.

Поддержан явный KB execution subset, описанный в KB Mechanics: combat.primary,
after_damage, requires_hit=true, фиксированная sealed target, explicit priority,
retain/cancel и CausalGraph limits. Один primary single-target action, без
EntityTurn-end work и derived reflection/lifesteal. Приоритет prepared позже
существующих active after_damage reactions. Другой порядок/interleaving остаётся
UNSUPPORTED, а не переносится молча в конец публикации. Старые contracts без
execution descriptor остаются зарегистрированными, но не исполняются неявно.

Prepared queue использует priority → source event registration order → prepared
ID; BIGINT identities сравниваются как decimal strings. Проверяются prepared
version/hash/status, исходный immutable seal/snapshot/pin, source event и
originating principal. Current source control/delegation/membership/native
posting rights проверяются повторно; GM не получает управление чужим source.
После расчёта и owner participation снова проверяются текущие права/expiry grants.

PreparedExecutionAccess — только read-only originating context для QueryService,
Audience и owned ability checks. Он не меняет global authenticated UID, не
разрешает csrf/write commands и не подменяет owner policy. Поэтому ресурсное
обязательство делегата исполняется при чужой публикации только при сохранении
реальных прав этого делегата. Парольные/native ограничения не обходятся.

QueryService causal inspection сохраняет retained stats, current status/behavior,
side/target policy, awareness, range/cover/LoE и cooldown/dependency gates, но
не требует нового pending ordinary turn и не производит EntityTurn-end ticks.
Тот же Resolver/Effects рассчитывает strike, hit/crit, DEF/RES, barriers, integer
damage и on_trigger resources. В costs отсутствует AP; prepared registration
и on_prepare resources/items повторно не оплачиваются. Reaction budget не
расходуется. Фиксированная цель не заменяется при недоступности.

## Causal seal, identity и RNG

Перед mechanical transaction writer в отдельной короткой ACS transaction
создаёт prepared.trigger_sealed event/operation и armed prepared_execution.
Это **только causal seal**, с mechanics_confirmed=false, source submission,
sealed-action ID/hash, subject и стабильной operation identity. Он не является
подтверждением урона/исполнения. Journal sequences продвигаются под session lock;
mechanical versions не меняются, как и при обычном seal preparation.

Trigger identity — реальный durable event ID + prepared_action_id. Existing
UNIQUE preparation/trigger, execution operation key и rolls.check_once уже
созданы в 007/021, поэтому новая миграция не нужна. Hit/crit сохраняются один раз
в rolls с causal_key=prepared:ID:trigger:EVENT и в armed payload. На исполнении
оба представления сверяются. Оригинальная attack tape не меняется.

Armed tape переживает поздний mechanical rollback. Replay не генерирует новые
samples и не делает даже пустой дополнительный arm COMMIT, если все identities
уже сохранены. Committed parent возвращает прежний terminal receipt до RNG.
Новая подготовка, появившаяся в preparation→commit gap, не пропускается молча:
нехватка causal seal блокирует commit до повторной проверенной подготовки.

В той же фактической transaction, что parent combat result, фиксируются
executed/skipped execution, prepared CAS/terminal state, on_trigger costs,
mechanical deltas, source/trigger/parent event refs и causal trace. Parent result
final state учитывает child deltas; исходная confirmation summary остаётся
доказательством подтверждённого primary plan. Causal trace/details не добавлены
в player DTO; existing GM/native journal gates сохранены.

## Inventory reservations и recovery

PreparedItemService::consumeTrigger использует только сохранённые 9.1.1B
bindings. Проверяются owner version после on_prepare, live KB pin/fingerprint,
исходный reserve receipt, reservation owner/scope/version/remaining и current
spending grant. Stable key:
acs:prepared:trigger-consume:PREPARED:TRIGGER:COST_INDEX.

Новый reserve не создаётся. Owner participate работает на том же mysqli writer
в существующей ACS transaction и возвращает только provisional serverReceipt.
Mechanical child вычисляется виртуально; owner costs и проекция записываются
под локальным savepoint. Недопустимые условия откатывают child costs целиком,
после чего применяется pinned retain/cancel. Неизвестный DB/owner outcome,
orphan owner receipt, tape integrity или causal graph error не выдаётся за skip:
весь parent commit откатывается/уходит в существующий R2 recovery barrier.

После реального COMMIT с потерянным ACK RecoveryService сначала читает terminal
receipt и не расходует предмет повторно. При неизвестном/orphan consume
автоматический retry/release запрещён. Для cancel используется прежний 9.1.1B
unused-reservation settlement; отсутствие прав оставляет recovery obligation.

**Runtime owner principal не подменяется.** Если trigger инициирован другим
пользователем и Stage-6 policy не подтверждает spending authority этого runtime
actor, конкретное item execution блокируется. Ресурсное originating-principal
исполнение не расширяет Inventory права. Это remaining capability boundary,
а не фиктивный расход от лица владельца.

## Causal safety и совместимость

Используется существующий CausalGraph с explicit max_depth/per_cause из KB.
Поддержанный граф — root event → prepared child. Emit prepared/active reactions
для child явно false в KB; recursive chains и неизвестные emission rules
UNSUPPORTED. Никакого нового глобального игрового depth/количества реакций нет.
Existing passive/active reaction guards, source/target HitCrit и Damage не заменены.

Проекция HP/resources выделена из прежнего CombatCommitPersistence в общий
projectEntityDeltas; primary и child используют один CAS writer. Status/barrier/
summon projections также существующие. Child не закрывает и не открывает ход.
Новых Resolver/Effects/InventoryAdapter или таблицы количеств нет.

## Locally verified

**PHP 8.5.12 / disposable MySQL 8.0.46**, official CLI/container, network=none,
mysqli/mysqlnd, socket /tmp/acs222-socket/mysql8.sock. Random DB удаляются в finally;
production DB/credentials не используются. Native MyISAM posts — fixture PID 501,
Gateway не вызывается.

- acs_prepared_execution_sql.php: допустимый trigger, durable causal event/tape,
  executed terminal state, on_prepare AP без повторной оплаты, on_trigger EP и
  reserved item consume once, неизменный reaction_used/turn count/native posts;
  duplicate parent возвращает прежний receipt без RNG/расхода.
- Тот же fixture: late outbox failure после child/owner writes полностью
  откатывает HP/AP/resources/items/execution state, оставляя armed tape.
  Реальный COMMIT + потеря ACK восстанавливается через RecoveryService.
- Вариант conflict: owner version меняется после causal seal отдельным безопасным
  owner reserve; child отклонён без подмены item, оплаты или нового roll.
- Вариант orphan: искусственно созданный standalone owner receipt прежнего
  consume key не превращается в повторную механику; parent recovery_required,
  armed tape и отсутствие blind retry/release подтверждены.
- Вариант multiple: два competing causes имеют разные durable identities, но
  unsupported interleaving не исполняется в неверной фазе и не расходует costs.
- acs_prepared_execution_conditions_sql.php: insufficient resource, настоящая
  отозванная delegation, скрытая fixed target, expired prepared row и
  originating delegated principal при публикации другого пользователя.
  Retain/cancel и duplicate stability проверены отдельно. Expiry transition
  самого Clock ранее покрыт existing 9.1.1/9.1.1B fixtures.
- CausalGraph cycle fixture возвращает ACS_CAUSAL_CYCLE; recursive emission
  не принимается как разрешённый contract.
- Existing acs_combat_items_sql.php, acs_prepared_items_sql.php и
  acs_prepared_actions_sql.php — PASS; обычные owner reservations/consume,
  late rollback, AP/resources, cancel/expiry и recovery сохранены.

Дополнительный acs_intercept_mass_defense.php не дошёл до intercept assertions:
старый dependent acs_status_evaluation.php:136 падает с Wrong persistent causal
key. Тот же запуск из чистого git archive origin/main (31ec531d) даёт идентичный
сбой. Это baseline harness failure, не PASS; старый fixture не переписывался.

PHP lint изменённых/новых PHP и git diff --check — PASS. JavaScript не менялся;
node --check не запускался. Полный test suite и browser E2E не запускались.

## Remaining BLOCKED / UNSUPPORTED

Другие фазы, generic multi-action/multi-target interleaving, prepared chains,
active reactions от child, derived-event triggers и начальные засады не реализованы.
Фазы/порядок не эмулируются другой фазой. On_trigger item execution от чужого
runtime owner principal закрыт существующим Stage-6 policy. Новые grants, global
UID impersonation, remote owner transaction, automatic item replacement,
callbacks KB и автоматический reveal не добавлены. Будущие form mechanics
не угадываются; unsupported source layers блокируют текущий consumer.

## Installed NOT_VERIFIED

warprift.ru / MyBB 1.8.41: actual schema/engines/routing, native permissions,
owner grants, hooks, ACP lifecycle, themes и installed acceptance не проверены.
Publication flags не менялись. Local SQL PASS не заменяет installed R2/Stage-6
acceptance и не включает боевую публикацию на форуме.
