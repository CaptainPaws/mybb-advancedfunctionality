# AdvancedCombatSystem 1.0 — Technical Architecture

## 1. Статус и границы

Основание: [Master Specification](ACS_MASTER_SPECIFICATION.md), включая N1/N2/M1/H1/C1/D1/S1 и A1.1–A1.95. Реальные внешние интерфейсы и ревизия main зафиксированы в [Integration Contracts](ACS_INTEGRATION_CONTRACTS.md). План — [12 этапов](ACS_IMPLEMENTATION_ROADMAP.md).

**Все новые PHP-имена, SQL DDL, DTO, API operations и алгоритмы координации этого документа — PROPOSED, технический проект для дальнейшей реализации.** Они не существуют в репозитории и не меняют утверждённые игровые правила. Код ACS в рамках текущей задачи не создаётся. DDL приведён для ревью и будущих миграций, не выполнялся. Цель — MyBB 1.8.40 / PHP 8.5.0; версии сервера MySQL/MariaDB, фактические engine таблиц MyBB и набор production plugins необходимо проверить при этапе 1/4.

## 2. Структура аддона и ответственность

Корень PROPOSED: `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`.

```text
manifest.php                      AF discovery, contextual assets, lang, ACP
advancedcombatsystem.php           лёгкий procedural bootstrap и hook registration
admin.php                         вход ACP через существующий AF router
modules/Bootstrap/Context.php      inactive/view/interactive и enabled/dependencies
modules/Domain/Numeric.php         binary64 policy, terminal rounding, units
modules/Domain/Formula.php         валидированный AST, без eval
modules/Domain/Modifiers.php       фазы, проценты, set/conflicts, clamps
modules/Domain/Resolver.php        единственный action/effect pipeline
modules/Domain/Clock.php           round/turn границы, ticks, upkeep, cooldowns
modules/Domain/Effects.php         statuses/barriers/reactions/reflection/lifesteal
modules/Domain/Control.php         группы, delegation, controller/control_effect
modules/Domain/Environment.php     objects, interaction, cooperation, awareness
modules/Domain/Spatial.php         zones/engagement/cover/line_of_effect
modules/Domain/AdvancedEffects.php prepared/ambush/spawn/transform/copy/steal
modules/Domain/Rewards.php         outcomes, loot, rewards, achievements
modules/Application/SessionService.php
modules/Application/EncounterService.php
modules/Application/DraftService.php
modules/Application/SealService.php
modules/Application/PublishService.php
modules/Application/RecoveryService.php
modules/Application/CorrectionService.php
modules/Application/RewardService.php
modules/Application/AuthorizationService.php
modules/Application/QueryService.php
modules/Infrastructure/Repository.php
modules/Infrastructure/TransactionManager.php
modules/Infrastructure/SnapshotStore.php
modules/Infrastructure/OperationStore.php
modules/Infrastructure/Outbox.php
modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php
modules/Infrastructure/Adapters/CharacterSheetAdapter.php
modules/Infrastructure/Adapters/InventoryAdapter.php
modules/Infrastructure/Adapters/MybbPostGateway.php
modules/Infrastructure/Adapters/AlertsAdapter.php
modules/Infrastructure/Adapters/StatisticsAdapter.php
modules/Infrastructure/Adapters/BalanceAdapter.php
modules/Presentation/ApiController.php
modules/Presentation/ThreadComponents.php
modules/Presentation/BuilderController.php
modules/Presentation/ProfileController.php
modules/Administration/Diagnostics.php
modules/Administration/Migrations.php
migrations/                       последовательные versioned migrations
assets/acs-view.js, acs-builder.js, acs.css
templates/                        собственные версии ACS templates
```

Имя класса соответствует `AF_ACS_<Module>` либо отдельному классу в указанном файле; процедурные публичные функции `af_advancedcombatsystem_init/xmlhttp/install/activate/deactivate/uninstall` согласуются с AF. Не требуется новый framework/composer container или изменение ядра. Domain не знает SQL/HTML/MyBB globals; Application координирует разрешения/транзакции; Infrastructure владеет I/O; Presentation выводит только отфильтрованные DTO. Число физических файлов может уточняться ограниченными PR без смены границ ответственности.

Bootstrap не подключает Resolver/KB/CS/Inventory на обычном showthread. Inactive определяет только наличие контекста; view читает материализованное состояние/карточки; interactive лениво грузит builder и command stack. ACP не исполняет произвольный SQL/PHP. Совместимость с Theme/Editor определяется существующими provider API, см. IC-THEME/IC-EDITOR. A1.67, A1.90–A1.93.

## 3. Сущности, состояния, события

### 3.1. Агрегаты

Session хранит связанный с форумной темой долговременный контекст, роли, существ и обязательства. Encounter — отдельное столкновение; в сессии могут быть последовательные столкновения без автоматического восстановления ресурсов. ControlGroup не равна uid: питомец и временный контроллер не создают новый бюджет. Entity сохраняет identity, snapshot и актуальное сессионное состояние; EncounterParticipant хранит сторону, участие и регистрацию в конкретном бою. Независимый монстр имеет свою control_group/turn budget; объект окружения не получает ход без KB.

Round содержит неизменяемый roster в turns. Turn относится к группе, EntityTurn фиксирует индивидуальные границы существ, чтобы poison питомца не тикал от открытия редактора владельцем. Life state (`active/incapacitated/dead`) независим от participation (`engaged/surrendered/escaped/withdrawn/captured`). Paused — состояние encounter, не статус существа. A1.1, A1.28–A1.31, A1.42–A1.49, A1.69, A1.71.

### 3.2. Переходы

| Машина | Переходы и условия |
|---|---|
| Session | active → pending_close → closed → archived; closed → active только reopen_session; archived допускает разрешение обязательств, но не обычную игру |
| Encounter | preparation → ambush_ready (опционально) → active; active ↔ paused; active → pending_resolution → completed; GM может разрешить продолжение из pending_resolution по KB |
| Turn | pending → resolved успешным combat_post; pending → passed разрешённым pass/timeout; pending → forfeited разрешённым исключением; закрытый ход не открывается лечением/контролем/rejoin |
| Draft | draft → sealed/частично sealed → published; отмена только хвоста; published связан с pid; recovery_required относится к неопределённой операции, не стирает draft |
| Submission | registered → validating → committing → published; rejected до commit; неопределённость → recovery_required; повтор возвращает подтверждённый результат |
| Integration operation | prepared → committed или rejected/released; неопределённый внешний результат → recovery_required; retry только после lookup |
| Loot/reward | generated/open → allocated/reserved → issuing → issued; ошибка не освобождает победителя и не запускает новый roll |

`preparation/ambush_ready` закреплены A1.77. Прочие внутренние staging-статусы PROPOSED, не дополнительный игровой ход. Если инфраструктура не может обеспечить переход безопасно, он blocked по A1.89.

### 3.3. Envelope события

```json
{
  "schema_version":1,"event_id":100,"session_id":1,"session_sequence":50,
  "encounter_id":4,"event_sequence":37,"type":"damage_applied",
  "operation_key":"submission:...:action:0:hit:0:target:12",
  "actor_uid":8,"source_entity_id":11,"target_entity_id":12,
  "round_id":6,"turn_id":21,"submission_id":"...",
  "parent_event_id":99,"cause_event_id":98,
  "engine_version":"acs-resolver-1","numeric_policy":"binary64-half_up-v1",
  "snapshot_refs":[3,4],"roll_refs":[7],"before_versions":{},"after_versions":{},
  "payload":{},"visibility_policy":{},"created_at":0
}
```

Идентификаторы и версии передаются без потери точности JavaScript: SQL BIGINT в HTTP DTO — decimal string; примеры малых чисел иллюстративны. Event log append-only по договору Repository: update/delete подтверждённых событий запрещены. Event details содержит входы/выходы/причины, не проза. Correction — новое связанное событие с причиной, правом и compensating effects, без переписывания бросков. Session-only события (rest/close/custody) имеют session_sequence, encounter_id/event_sequence=NULL. Для encounter уникален event_sequence. Projection updates и event insert выполняются в одной транзакции.

## 4. Резолвер и математический pipeline

`AF_ACS_Resolver::resolve(context, ordered_actions, fixed_random_tape, mode)` возвращает plan/events/deltas/dependency_set/trace. Он не публикует пост и не выполняет внешние операции сам. Режимы:

- preview: только допустимость, стоимость, разрешённые вероятности, **без RNG**;
- seal: одна серверная запись random tape и квоты в транзакции seal, симуляция на snapshot актуальных зависимостей;
- commit: тот же исполнитель с сохранённым tape, повторной проверкой и применением plan через PublishService.

Если случайная проверка возникает позже (prepared trigger/loot), создаётся отдельный сохранённый roll key по causal event; она не подменяет броски исходной атаки. Условно неиспользованный crit/status roll не превращается в новый бесплатный roll. RNG PROPOSED: криптографический серверный источник, результат нормализуется в u∈[0,1); успех `100*u < P`. Шкала RNG и алгоритм сравнения версионируются; P=0 всегда false, P=100 всегда true. Это техническая реализация вероятности, не требование d100 с целым порогом, которое потеряло бы дробные шансы H1. Клиент не присылает seed/roll. Исторический tape воспроизводится, а не генерируется заново.

Порядок одного действия (N1/N2/M1/H1/C1/D1/S1/A1.13/A1.18–A1.27):

1. Проверить право исполнителя, открытый ход, форму/статусы/constraints, cooldown/лимиты, KB capabilities и допустимые original targets/порядок. По virtual state проверить полную стоимость.
2. Запланировать стоимость AP/ресурсов/предметов в порядке действия; HP-cost не damage, может жертвенно вызвать incapacitated, но не отменяет разрешённое действие.
3. on_cast и разрешённые эффекты способности обрабатываются согласно порядку KB; для каждого воздействия фазы on_targeted, before_hit, hit, after_hit до урона, damage, after_damage. Условия реакций перепроверяются; неоплачиваемая пропускается без лимита. Пассивные срабатывания привязаны к cause_event, защищены от циклов.
4. Перехват сохраняет original/resolved, допускает одно успешное перенаправление. Используются Speed/DEF/RES/иммунитеты/барьеры фактической цели, исходные roll values сохраняются. Targets/components/hits обходятся по зафиксированному порядку.
5. Hit H1; при успехе одна crit-проверка удара. Только can_crit компоненты умножаются на `1+crit_dmg/100` до защиты.
6. Modifiers: flat → аддитивные relative percentages → explicit multiply; окончательный winning set; проверка bounds. Ни legacy CS percent, ни уже включённый equipment bonus не применяются повторно.
7. Для physical/elemental: `effective=max(0,base*(1-pct_pen/100)-flat_pen)`, `raw*100/(100+effective)`, затем для elemental аддитивные уязвимости; иммунитет даёт 0. True минует защиту.
8. Сумма post-defense компонентов → один integer half_up budget → largest remainder с KB index tie break → components в порядке KB → подходящие барьеры barrier_priority/FIFO/id → HP без повторного округления. Shared pools расходуются между целями последовательно, атомарно.
9. Реальная HP loss ограничена текущим HP. Фиксируются incapacitated и прекращение обычных sustained. Условие on_damage требует фактической HP loss; on_hit достаточно попадания; отдельный status roll S1.3, duration/stack/source scope из KB. Статусы исполняются в своей объявленной trigger phase: on_cast при применении, on_hit после подтверждения попадания, on_damage после фактического HP loss; этот пункт не переносит все статусы в конец урона.
10. После основного урона и немедленных состояний — отражение, затем вампиризм по фактическому HP loss, затем завершение предусмотренных эффектов/реакций действия. Конкретный hook эффекта KB не перемещается произвольно между фазами. Все child effects идут через общий pipeline с causal links и ограничениями повторов.
11. После полного действия/реакций/отражения/вампиризма проверить end conditions. При pending_resolution следующие обычные действия не выполняются в обход A1.27: plan помечает конфликт для всего неподходящего хвоста до commit; исправление подготовки без перебросов. Новые действия запрещены до разрешения GM.
12. Следующее действие читает изменённое virtual state. После всех действий создаётся единый commit plan; отсутствие валидности одного не допускает частичной публикации.

Formula compiler проверяет существующую AST-грамматику KB, типы inputs, units, точную pinned version, collisions parameters/dynamic inputs. Evaluator фиксирует порядок операций, NaN/Infinity/div0/overflow/unsafe integer diagnostics. Не оптимизирует ассоциативность binary64. Внутри суммы/произведения входы обходятся по сохранённым phase/type, priority, source snapshot ID и modifier index; этот порядок сохраняется в trace и не зависит от SQL без ORDER BY. Выходная политика formula_def исполняется на её объявленной границе; ударный terminal budget не получает двойного округления. Raw, rounded, clamped, allocated, absorbed, HP loss и overkill/overheal различаются в trace. Нельзя перезапустить CharacterSheets previews как боевую формулу.

Clock отдельно обрабатывает однократные round/turn events. Ключи `round:<id>:ap_refresh`, `entity_turn:<id>:start`, `effect:<id>:tick:<boundary>`, `cooldown:<id>:boundary:<id>` уникальны. На новом раунде AP обновляются до поддержания; индивидуальный start: ticks по applied order/id, состояния, sustained в установленном игроком порядке/FIFO, доступ к действиям. Контроль истекает в конце затронутого хода, cooldown decrement — на следующих own starts. Посещение страницы не является clock event. Во время paused ни clocks, ни deadlines не продвигаются. A1.4–A1.6, A1.44–A1.48, S1.4/S1.13–S1.16.

## 5. Хранилище и полный проект DDL

### 5.1. SQL-условия

Ниже **PROPOSED DDL**, таблицы не существуют и не создавались. `{prefix}` заменяется на доверенный MyBB TABLE_PREFIX при миграции; это шаблон идентификатора, не bind-параметр и не вход пользователя. Все ACS-таблицы InnoDB/utf8mb4. Составные индексы предполагают современный InnoDB с лимитом ключа 3072 байта; этап 1 проверяет это и поддержку выбранных collations/ROW_FORMAT. Нельзя молча обрезать UNIQUE до небезопасного префикса на старой БД. Для совместимости SQL семейства MySQL/MariaDB сложные структуры — LONGTEXT с обязательной серверной JSON/schema validation, не индексируемые serialized PHP. BIGINT timestamps — UTC epoch, row versions — целые монотонные. Параметры binary64 сериализуются JSON с сохранением round-trip precision; исполнитель проверяет ограничения точности и диапазонов. AP/count/HP/barrier storage — signed integers с проверками >=0/верхних границ в Domain. Нельзя полагаться только на unsigned или на CHECK, поддержка которого отличается между серверами.

PK/UNIQUE/KEY приведены в каждом CREATE. Идентификаторы FK логически обязательны, но **физические FOREIGN KEY намеренно не вводятся**: MyBB/AF обычно управляют жизненным циклом программно, внешние posts/users могут удаляться штатно, а история ACS сохраняется. Repository обязан проверять существование родителя, same session/encounter и неизменность ownership под блокировкой; purge идёт только после проверки обязательств в обратном порядке зависимостей. Запрет каскадного удаления истории — явный. Это техническое предложение, не утверждение о наличии FK в AF. В §5.3 перечислены все логические связи.

Неуказанные optional FK — NULL, не 0. JSON-поля хранят собственный schema_version в envelope. JSON не заменяет отдельные индексируемые status/version/id/recipient/owner/due поля. Для tables без state_version (events, rolls, snapshots) строки неизменяемы; версии содержимого лежат в payload/snapshot. Начальное создание индексов и изменение DDL миграциями последовательно, с readback information_schema, без предположения об откате DDL.

```sql
CREATE TABLE `{prefix}af_acs_schema_migrations` (
  version varchar(64) NOT NULL,
  status varchar(24) NOT NULL,
  step int NOT NULL,
  checksum char(64) NOT NULL,
  updated_at bigint NOT NULL,
  error_id varchar(64) NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_sessions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tid int unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  session_version bigint unsigned NOT NULL DEFAULT 1,
  next_sequence bigint unsigned NOT NULL DEFAULT 1,
  schema_version int NOT NULL,
  config_json longtext NOT NULL,
  created_at bigint NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY thread_status (tid,status),
  KEY owner_status (owner_uid,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_session_roles` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  uid int unsigned NOT NULL,
  role varchar(24) NOT NULL,
  permissions_json longtext NOT NULL,
  role_version bigint unsigned NOT NULL,
  expires_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY member_role (session_id,uid,role),
  KEY user_sessions (uid,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_control_groups` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  owner_uid int unsigned NULL,
  kind varchar(24) NOT NULL,
  group_version bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY session_owner (session_id,owner_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_snapshots` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  source_system varchar(32) NOT NULL,
  source_key varchar(191) NOT NULL,
  contract_id varchar(96) NOT NULL,
  contract_version varchar(64) NOT NULL,
  schema_version int NOT NULL,
  content_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY source_version (source_system,contract_id,contract_version),
  KEY session_hash (session_id,content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_entities` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  control_group_id bigint unsigned NULL,
  kind varchar(24) NOT NULL,
  owner_uid int unsigned NULL,
  sheet_id bigint unsigned NULL,
  template_snapshot_id bigint unsigned NOT NULL,
  source_entity_id bigint unsigned NULL,
  spawn_key varchar(191) NULL,
  name varchar(191) NOT NULL,
  entity_version bigint unsigned NOT NULL,
  definition_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY spawn_once (spawn_key),
  KEY session_group (session_id,control_group_id),
  KEY owner_sheet (owner_uid,sheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_entity_states` (
  entity_id bigint unsigned NOT NULL,
  life_state varchar(24) NOT NULL,
  hp bigint NOT NULL,
  hp_max bigint NOT NULL,
  state_version bigint unsigned NOT NULL,
  stats_json longtext NOT NULL,
  layers_json longtext NOT NULL,
  PRIMARY KEY (entity_id),
  KEY life (life_state,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_resources` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  resource_key varchar(64) NOT NULL,
  current_value double NOT NULL,
  maximum_value double NOT NULL,
  is_integer tinyint NOT NULL,
  resource_version bigint unsigned NOT NULL,
  definition_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY entity_resource (entity_id,resource_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_encounters` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  encounter_version bigint unsigned NOT NULL,
  next_event_sequence bigint unsigned NOT NULL DEFAULT 1,
  round_number int NOT NULL DEFAULT 0,
  start_operation_id varchar(96) NULL,
  visibility varchar(24) NOT NULL,
  config_snapshot_id bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  paused_from varchar(24) NULL,
  created_at bigint NOT NULL,
  completed_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY start_once (start_operation_id),
  KEY session_state (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_sides` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  name varchar(191) NOT NULL,
  side_version bigint unsigned NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY encounter_side (encounter_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_side_relationships` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  side_a bigint unsigned NOT NULL,
  side_b bigint unsigned NOT NULL,
  relationship varchar(24) NOT NULL,
  relation_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY side_pair (encounter_id,side_a,side_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_participants` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  side_id bigint unsigned NULL,
  participation varchar(24) NOT NULL,
  registered_sequence bigint unsigned NOT NULL,
  participant_version bigint unsigned NOT NULL,
  visibility_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_entity (encounter_id,entity_id),
  KEY encounter_side_state (encounter_id,side_id,participation),
  KEY entity_history (entity_id,encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_controllers` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  controller_uid int unsigned NULL,
  mode varchar(24) NOT NULL,
  status varchar(24) NOT NULL,
  assignment_version bigint unsigned NOT NULL,
  valid_from bigint NOT NULL,
  valid_until bigint NULL,
  permissions_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY entity_active (entity_id,status),
  KEY user_control (controller_uid,status,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_delegations` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  delegate_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  delegation_version bigint unsigned NOT NULL,
  valid_until bigint NULL,
  scope_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY delegate_scope (session_id,delegate_uid,status),
  KEY owner_scope (owner_uid,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_rounds` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  round_number int NOT NULL,
  status varchar(24) NOT NULL,
  round_version bigint unsigned NOT NULL,
  opened_at bigint NOT NULL,
  closed_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_round (encounter_id,round_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_turns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  round_id bigint unsigned NOT NULL,
  control_group_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  ap_budget int NOT NULL,
  ap_spent int NOT NULL,
  seal_quota_spent int NOT NULL,
  pre_round_ap_commitment int NOT NULL,
  turn_version bigint unsigned NOT NULL,
  deadline_at bigint NULL,
  remaining_deadline_seconds bigint NULL,
  closed_by_submission varchar(96) NULL,
  roster_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY group_round (round_id,control_group_id),
  KEY due_turn (status,deadline_at),
  KEY group_turns (control_group_id,round_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_entity_turns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  turn_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  start_done tinyint NOT NULL DEFAULT 0,
  end_done tinyint NOT NULL DEFAULT 0,
  reaction_used int NOT NULL DEFAULT 0,
  entity_turn_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY individual_boundary (turn_id,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_effects` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  source_entity_id bigint unsigned NULL,
  target_entity_id bigint unsigned NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  effect_key varchar(96) NOT NULL,
  scope varchar(24) NOT NULL,
  status varchar(24) NOT NULL,
  stacks int NOT NULL,
  remaining_duration int NULL,
  duration_unit varchar(24) NULL,
  applied_order bigint unsigned NOT NULL,
  effect_version bigint unsigned NOT NULL,
  parent_effect_id bigint unsigned NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY target_status (target_entity_id,status),
  KEY encounter_order (encounter_id,status,applied_order),
  KEY source_scope (source_entity_id,effect_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_barrier_pools` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  effect_id bigint unsigned NOT NULL,
  pool_kind varchar(24) NOT NULL,
  strength bigint NOT NULL,
  maximum_strength bigint NOT NULL,
  barrier_priority int NOT NULL,
  applied_order bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  pool_version bigint unsigned NOT NULL,
  absorbs_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY effect_pool (effect_id),
  KEY absorb_order (status,barrier_priority,applied_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_barrier_targets` (
  pool_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  PRIMARY KEY (pool_id,entity_id),
  KEY entity_pools (entity_id,pool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_cooldowns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  ability_key varchar(96) NOT NULL,
  group_key varchar(96) NOT NULL,
  scope varchar(24) NOT NULL,
  remaining_turns int NOT NULL,
  last_boundary_key varchar(191) NULL,
  cooldown_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY entity_ability_group (entity_id,ability_key,group_key),
  KEY entity_scope (entity_id,scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_reactions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  phase varchar(24) NOT NULL,
  priority int NOT NULL,
  registered_order bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  reaction_version bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY phase_queue (encounter_id,phase,status,priority,registered_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_drafts` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  author_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  draft_version bigint unsigned NOT NULL,
  mechanics_json longtext NOT NULL,
  prose longtext NOT NULL,
  dependency_json longtext NOT NULL,
  confirmation_hash char(64) NULL,
  pid int unsigned NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY turn_drafts (turn_id,status),
  KEY author_drafts (author_uid,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_sealed_actions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  draft_id bigint unsigned NOT NULL,
  action_index int NOT NULL,
  actor_entity_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  seal_operation_key varchar(191) NOT NULL,
  ap_cost int NOT NULL,
  zero_ap_limit_key varchar(96) NULL,
  status varchar(24) NOT NULL,
  sealed_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY seal_once (seal_operation_key),
  UNIQUE KEY immutable_position (draft_id,action_index),
  KEY actor_actions (actor_entity_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_rolls` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  sealed_action_id bigint unsigned NULL,
  causal_key varchar(191) NOT NULL,
  check_kind varchar(32) NOT NULL,
  roll_index int NOT NULL,
  rng_version varchar(64) NOT NULL,
  value_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY check_once (causal_key,check_kind,roll_index),
  KEY action_rolls (sealed_action_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_submissions` (
  submission_id varchar(96) NOT NULL,
  draft_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  actor_uid int unsigned NOT NULL,
  payload_hash char(64) NOT NULL,
  status varchar(32) NOT NULL,
  pid int unsigned NULL,
  lease_until bigint NULL,
  request_id varchar(96) NOT NULL,
  error_id varchar(64) NULL,
  result_json longtext NOT NULL,
  created_at bigint NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (submission_id),
  UNIQUE KEY linked_pid (pid),
  KEY draft_submissions (draft_id,status),
  KEY recovery_queue (status,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_post_links` (
  pid int unsigned NOT NULL,
  submission_id varchar(96) NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  result_snapshot_id bigint unsigned NOT NULL,
  PRIMARY KEY (pid),
  UNIQUE KEY submission_post (submission_id),
  UNIQUE KEY one_post_per_turn (turn_id),
  KEY encounter_posts (encounter_id,pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_events` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  session_sequence bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  event_sequence bigint unsigned NULL,
  operation_key varchar(191) NOT NULL,
  event_type varchar(64) NOT NULL,
  source_entity_id bigint unsigned NULL,
  target_entity_id bigint unsigned NULL,
  actor_uid int unsigned NOT NULL,
  submission_id varchar(96) NULL,
  parent_event_id bigint unsigned NULL,
  cause_event_id bigint unsigned NULL,
  correction_of_event_id bigint unsigned NULL,
  engine_version varchar(64) NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_order (session_id,session_sequence),
  UNIQUE KEY encounter_order (encounter_id,event_sequence),
  UNIQUE KEY operation_once (operation_key),
  KEY entity_events (target_entity_id,id),
  KEY submission_events (submission_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_event_details` (
  event_id bigint unsigned NOT NULL,
  schema_version int NOT NULL,
  numeric_policy varchar(64) NOT NULL,
  payload_json longtext NOT NULL,
  visibility_json longtext NOT NULL,
  content_hash char(64) NOT NULL,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_operations` (
  operation_key varchar(191) NOT NULL,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  kind varchar(64) NOT NULL,
  external_system varchar(32) NULL,
  external_ref varchar(191) NULL,
  payload_hash char(64) NOT NULL,
  status varchar(32) NOT NULL,
  operation_version bigint unsigned NOT NULL,
  request_json longtext NOT NULL,
  result_json longtext NOT NULL,
  retry_at bigint NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (operation_key),
  KEY pending_operations (status,retry_at),
  KEY external_lookup (external_system,external_ref),
  KEY session_operations (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_outbox` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint unsigned NOT NULL,
  channel varchar(32) NOT NULL,
  recipient_key varchar(96) NOT NULL,
  dedupe_key varchar(191) NOT NULL,
  status varchar(24) NOT NULL,
  attempts int NOT NULL DEFAULT 0,
  retry_at bigint NULL,
  lease_until bigint NULL,
  payload_json longtext NOT NULL,
  ack_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY delivery_once (dedupe_key),
  KEY due_delivery (status,retry_at,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_item_reservations` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  inventory_item_id bigint unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  qty bigint NOT NULL,
  operation_key varchar(191) NOT NULL,
  external_reservation_id varchar(191) NOT NULL,
  status varchar(24) NOT NULL,
  reservation_version bigint unsigned NOT NULL,
  context_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY reservation_once (operation_key),
  KEY item_active (inventory_item_id,status),
  KEY session_reservations (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_equipment` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  slot_key varchar(64) NOT NULL,
  inventory_item_id bigint unsigned NULL,
  item_snapshot_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  equipment_version bigint unsigned NOT NULL,
  source_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY combat_slot (session_id,entity_id,slot_key),
  KEY item_usage (inventory_item_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_custody` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  inventory_item_id bigint unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  custodian_entity_id bigint unsigned NULL,
  active_user_entity_id bigint unsigned NULL,
  status varchar(24) NOT NULL,
  custody_version bigint unsigned NOT NULL,
  operation_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_item (session_id,inventory_item_id),
  UNIQUE KEY custody_once (operation_key),
  KEY custody_obligations (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_objects` (
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  object_version bigint unsigned NOT NULL,
  durability bigint NULL,
  interaction_json longtext NOT NULL,
  progress_json longtext NOT NULL,
  PRIMARY KEY (entity_id),
  KEY encounter_objects (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_zones` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  zone_version bigint unsigned NOT NULL,
  name varchar(191) NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY encounter_zones (encounter_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_zone_connections` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  from_zone_id bigint unsigned NOT NULL,
  to_zone_id bigint unsigned NOT NULL,
  connection_version bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY directed_edge (from_zone_id,to_zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_positions` (
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  zone_id bigint unsigned NULL,
  position_version bigint unsigned NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (entity_id,encounter_id),
  KEY zone_entities (zone_id,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_engagements` (
  encounter_id bigint unsigned NOT NULL,
  entity_a bigint unsigned NOT NULL,
  entity_b bigint unsigned NOT NULL,
  engagement_version bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  PRIMARY KEY (encounter_id,entity_a,entity_b),
  KEY target_contacts (entity_b,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_cover_assignments` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  cover_entity_id bigint unsigned NOT NULL,
  protected_entity_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  assignment_version bigint unsigned NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY cover_target (encounter_id,cover_entity_id,protected_entity_id),
  KEY entity_cover (protected_entity_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_awareness` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  subject_kind varchar(24) NOT NULL,
  subject_id bigint unsigned NOT NULL,
  audience_kind varchar(24) NOT NULL,
  audience_id bigint unsigned NOT NULL,
  level varchar(24) NOT NULL,
  awareness_version bigint unsigned NOT NULL,
  details_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY audience_subject (encounter_id,subject_kind,subject_id,audience_kind,audience_id),
  KEY audience_view (encounter_id,audience_kind,audience_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_objectives` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  object_entity_id bigint unsigned NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  objective_version bigint unsigned NOT NULL,
  progress_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY encounter_objectives (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_contributions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  objective_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  source_event_id bigint unsigned NOT NULL,
  contribution_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY contribution_once (contribution_key),
  KEY objective_history (objective_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_prepared_actions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  source_sealed_action_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  prepared_version bigint unsigned NOT NULL,
  expires_boundary varchar(191) NULL,
  pre_round_ap_commitment int NOT NULL,
  contract_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY preparation_once (source_sealed_action_id),
  KEY encounter_triggers (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_prepared_executions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  prepared_action_id bigint unsigned NOT NULL,
  trigger_event_id bigint unsigned NOT NULL,
  operation_key varchar(191) NOT NULL,
  status varchar(24) NOT NULL,
  result_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY trigger_once (prepared_action_id,trigger_event_id),
  UNIQUE KEY prepared_operation (operation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_loot_pools` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  generation_key varchar(191) NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  pool_version bigint unsigned NOT NULL,
  mode varchar(24) NOT NULL,
  result_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY generation_once (generation_key),
  KEY session_open_loot (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_loot_entries` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  pool_id bigint unsigned NOT NULL,
  item_snapshot_id bigint unsigned NOT NULL,
  qty bigint NOT NULL,
  status varchar(24) NOT NULL,
  recipient_group_id bigint unsigned NULL,
  recipient_uid int unsigned NULL,
  allocation_version bigint unsigned NOT NULL,
  issue_operation_key varchar(191) NULL,
  result_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY loot_issue_once (issue_operation_key),
  KEY pool_entries (pool_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_loot_claims` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  loot_entry_id bigint unsigned NOT NULL,
  control_group_id bigint unsigned NOT NULL,
  claim varchar(24) NOT NULL,
  claim_version bigint unsigned NOT NULL,
  closed_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY group_claim (loot_entry_id,control_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_rewards` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  reward_key varchar(191) NOT NULL,
  recipient_key varchar(96) NOT NULL,
  reward_type varchar(32) NOT NULL,
  status varchar(24) NOT NULL,
  reward_version bigint unsigned NOT NULL,
  operation_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY recipient_reward (reward_key,recipient_key,reward_type),
  UNIQUE KEY reward_operation (operation_key),
  KEY pending_rewards (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_achievement_progress` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  achievement_key varchar(96) NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  subject_kind varchar(24) NOT NULL,
  subject_key varchar(96) NOT NULL,
  progress_version bigint unsigned NOT NULL,
  visibility varchar(32) NOT NULL,
  progress_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY achievement_subject (achievement_key,subject_kind,subject_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_achievement_events` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  progress_id bigint unsigned NOT NULL,
  source_event_id bigint unsigned NOT NULL,
  progress_key varchar(191) NOT NULL,
  result_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY achievement_once (progress_key),
  KEY progress_events (progress_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_statistics` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  subject_kind varchar(24) NOT NULL,
  subject_key varchar(96) NOT NULL,
  aggregate_version bigint unsigned NOT NULL,
  visibility varchar(24) NOT NULL,
  source_sequence bigint unsigned NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_subject (encounter_id,subject_kind,subject_key),
  KEY subject_history (subject_kind,subject_key,encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_acs_diagnostics` (
  error_id varchar(64) NOT NULL,
  request_id varchar(96) NOT NULL,
  submission_id varchar(96) NULL,
  encounter_id bigint unsigned NULL,
  category varchar(32) NOT NULL,
  operation varchar(64) NOT NULL,
  phase varchar(64) NOT NULL,
  created_at bigint NOT NULL,
  details_json longtext NOT NULL,
  PRIMARY KEY (error_id),
  KEY request_errors (request_id),
  KEY encounter_errors (encounter_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

```

### 5.2. Данные в JSON и ограничения целостности

- effects.payload: status definition, stack_max/per_apply, expiration boundary, sustained cost/order, constraints, transform layers, control_effect base assignment ref, granted abilities и переносы parent_effect_id. Вид эффекта и typed payload schema обязателен; это не произвольный исполняемый JSON.
- sealed_actions.payload: actor, exact ability/item snapshot, ordered targets/components/hits, original targets, parameters, source fingerprints, dependency versions и costs. Отменённая строка сохраняется; новое action_index монотонно, старый index не переиспользуется.
- resources: current/maximum с is_integer по контракту. Для AP/qty/HP не используется DOUBLE. Resource integer проверяется в допустимом точном диапазоне binary64 либо сериализуется как exact integer в специализированном payload; AP хранится отдельно в turns. Для существующих денежных scaled integers используется IC-BALANCE, не resources DOUBLE.
- turns.roster_json: зафиксированные участники и правила ожидания, initial AP и pre_round commitments; новые summon checks не создают вторую turn-строку группы. Квота AP seal не возвращается отменой. Лимиты 0 AP представлены счётчиками в operations с уникальным scoped key и лимитами KB, не выводятся из ap_spent.
- effect duration/scope, reaction limits, start/end и cooldown boundaries всегда меняются одним command под lock, не GET-запросом.
- config_json: versioned encounter/session defaults, timeout mode, visibility policies, rest counters, reward rules; отдельное изменение создаёт event и увеличивает version. Нет скрытого изменения глобальной ACP-настройкой активной сессии.
- item_reservations — **зеркало** внешнего Inventory reservation ref, не глобальная защита предмета без IC-INV-1. Custody хранит одну актуальную запись session/item; вся история в events. Финальная передача собственности только external operation.
- achievement_progress.achievement_key — стабильная identity достижения независимо от сессии/snapshot; UNIQUE вместе с subject предотвращает повторную строку прогресса при новой версии. definition_snapshot_id фиксирует версию определения. Для непрерывного прогресса при новой версии требуется явная migration/definition identity policy с сохранением achievement_key, не создание бесплатного повторного one_time. Reward key содержит стабильную identity достижения/периода/subject, не только новый snapshot id.
- awareness разделяет subject_kind entity/object/prepared_action и audience actor/control_group/side/encounter. Без записи — unknown. Audience IDs нормализуются; encounter-wide audience_id=encounter_id. Механическое состояние не раскрывается уровнем осведомлённости автоматически.
- loot/reward получатели — группы по умолчанию, отдельные существа только KB. Need/greed tie roll фиксируется в rolls causal_key loot_entry/closed_allocation_version; ошибка выдачи не изменяет result.

Domain/repositories проверяют целочисленные диапазоны, min≤max, qty>0, один primary_form, отсутствие дублированного активного пользователя custody, same-session FK, допустимые state transitions. Семантические unique invariants (например, один успешный spawn и один post на turn) дополнительно защищены SQL UNIQUE. Relationship pairs/engagement pairs нормализуются перед записью; для направленной связи зон обратное ребро отдельное.

### 5.3. Полная карта логических FK и владельцев

| Дочерние строки | Родители / проверка |
|---|---|
| session_roles, control_groups, snapshots, encounters | sessions.id |
| entities | session, nullable group, template snapshot, optional source entity; sheet_id/owner_uid — внешние ссылки CS/MyBB |
| entity_states, resources | entities.id |
| sides, rounds, loot_pools | encounter; pool дополнительно session/definition snapshot |
| side_relationships | encounter и обе sides того же encounter |
| participants | encounter, entity той же session, optional side того же encounter |
| controllers, delegations | session; controller entity; uid внешние; entity scope delegation хранится typed JSON и проверяется |
| turns | round и control_group той же session; closed submission по итогам commit |
| entity_turns | turn и entity из roster |
| effects | session, optional encounter/source/target; snapshot, optional parent effect |
| barrier_pools, barrier_targets | effect → pool; entity в области действия того же session/encounter |
| cooldowns, reactions | entity; reaction encounter/snapshot |
| drafts, sealed_actions, rolls | draft→turn/encounter; action→draft/entity/snapshot; roll→session/optional action либо causal operation |
| submissions, post_links | draft/turn/encounter; pid внешний MyBB; result snapshot; один pid/submission/turn |
| events, event_details | event→session/optional encounter/entity/submission/parent/cause/correction; details→event |
| operations, outbox | operation→session/optional encounter; outbox→event; external refs только owner adapter |
| equipment, reservations, custody | session/entity/snapshot; inventory_item_id/reservation_id — внешние Inventory |
| objects, positions, zones, zone_connections | object→entity/encounter; position→entity/encounter/optional zone; connections→две zones одного encounter |
| engagements, cover_assignments, awareness | encounter, соответствующие entities/subject/audience в том же scope |
| objectives, contributions | encounter/optional object/snapshot; contribution→objective/entity/source event |
| prepared_actions, prepared_executions | entity/encounter/sealed action; execution→prepared/trigger event |
| loot_entries, loot_claims, rewards | pool/item snapshot/group; claim→entry/group; reward→session/optional encounter/operation |
| achievement_progress, achievement_events, statistics | definition snapshot; event→progress/source event; stats→completed encounter/subject |
| diagnostics | optional submission/encounter, request correlation; diagnostic retention не удаляет event history |

### 5.4. Покрытие данных по этапам

Этап 1: migrations/sessions/roles/operations/diagnostics/snapshots/events/event_details (фундамент аудита), затем добавления по мере функциональности. Этап 2: groups/entities/states/resources/encounters/sides/participants/controllers/delegations/rounds/turns/entity_turns/cooldowns. Этап 3: numeric trace и barrier_pools/barrier_targets для базового резолвера, расширение event details. Этап 4: drafts/actions/rolls/submissions/post_links/outbox foundation. Этап 5: полный lifecycle effects/reactions и расширение барьеров; минимальные effects для barrier pools создаются на этапе 3. Этап 6: equipment/reservations/custody. Этап 7: слои control/spawn в entities/effects/controllers. Этап 8: objects/zones/connections/positions/engagements/cover/awareness/objectives/contributions. Этап 9: prepared_actions/executions и effects layers. Этап 10: loot pools/entries/claims/rewards/achievements/statistics. Этап 11: consumers outbox и presentation. Этап 12: migration/recovery/lifecycle verification. Ни одна таблица не устанавливается отдельным незапрошенным SQL текущей задачей.

### 5.5. Предлагаемые изменения хранения у владельцев интеграций

Следующие таблицы и колонка — **PROPOSED**, отсутствуют в проверенной main. Это часть будущих ограниченных интеграционных PR, а не ACS, уже установленный в чужом аддоне. Их lifecycle и миграции принадлежат соответствующему владельцу (Inventory, balance, AAM). Имена должны быть закреплены в интеграционном PR; функциям ACS запрещено создавать их при обычном запросе. Допускается эквивалентный owner-протокол с теми же гарантиями, если он отдельно доказан и документация обновлена.

```sql
ALTER TABLE `{prefix}af_advinv_items`
  ADD COLUMN inventory_version bigint unsigned NOT NULL DEFAULT 1;

CREATE TABLE `{prefix}af_advinv_external_operations` (
  operation_key varchar(191) NOT NULL,
  payload_hash char(64) NOT NULL,
  operation_type varchar(32) NOT NULL,
  owner_uid int unsigned NOT NULL,
  item_id bigint unsigned NULL,
  status varchar(32) NOT NULL,
  external_ref varchar(191) NULL,
  result_json longtext NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (operation_key),
  KEY owner_operations (owner_uid,status),
  KEY item_operations (item_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_advinv_reservations` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  operation_key varchar(191) NOT NULL,
  owner_uid int unsigned NOT NULL,
  item_id bigint unsigned NOT NULL,
  qty bigint NOT NULL,
  context_system varchar(32) NOT NULL,
  context_key varchar(191) NOT NULL,
  status varchar(24) NOT NULL,
  reservation_version bigint unsigned NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY reservation_once (operation_key),
  KEY item_active (item_id,status),
  KEY owner_context (owner_uid,context_system,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_balance_external_operations` (
  operation_key varchar(191) NOT NULL,
  payload_hash char(64) NOT NULL,
  uid int unsigned NOT NULL,
  kind varchar(32) NOT NULL,
  amount_scaled bigint NOT NULL,
  status varchar(32) NOT NULL,
  result_json longtext NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (operation_key),
  KEY recipient_operations (uid,kind,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}af_aam_external_operations` (
  operation_key varchar(191) NOT NULL,
  payload_hash char(64) NOT NULL,
  recipient_uid int unsigned NOT NULL,
  alert_id bigint unsigned NULL,
  status varchar(32) NOT NULL,
  result_json longtext NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (operation_key),
  KEY recipient_delivery (recipient_uid,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Inventory reserve берёт lock самой строки item, затем читает активные reservations: `available=qty-sum(active reservation qty)`. Все reserve/consume/remove/sell/delete/transfer writers должны брать тот же item lock и уважать суммарную reservation, повышая inventory_version. Операция consume разрешает только зарезервированное целое количество, подтверждение owner operation и изменение qty атомарны. Logical FK reservations→Inventory item/operation; owner operations сохраняют provenance после удаления item. Release — явная идемпотентная операция, не автоматическое истечение из-за деактивации ACS.

Balance gateway под lock постоянной строки баланса в одной транзакции проверяет/вставляет receipt operation_key, применяет scaled delta и сохраняет результат. Повтор возвращает receipt, другой payload_hash отклоняется. Unique ref_id в необязательном legacy ledger не подменяет это правило. AAM gateway в одной транзакции вставляет alert и receipt либо фиксирует осознанный skipped по preferences; после потери ack возвращает старый alert_id/status. Если фактическая таблица owner не InnoDB или вызываемый writer выполняет независимый commit, это DDL само не обеспечивает атомарности: dependency gate остаётся blocked. AdvancedStatistic может читать ACS aggregates как read model; если consumer сохраняет отдельную копию, он обязан добавить эквивалентный receipt/versioned upsert до допуска outbox.

## 6. Серверный API и DTO

### 6.1. Маршрут и общие требования

PROPOSED route использует **EXISTING** AF transport: `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=<operation>`, dispatch `af_advancedcombatsystem_xmlhttp()`. GET — только разрешённые query без мутаций; POST — commands с `my_post_key`, авторизацией и rate/body limits. Query о submitted status сам не повторяет внешнюю операцию. Все IDs как строки. Nullable сохраняется как null, boolean не превращается в числовую стоимость.

```json
{
  "request_id":"uuid","operation_key":"uuid",
  "session_id":"1","encounter_id":"4",
  "expected":{"session_version":"2","encounter_version":"7","round_version":"3","entity_versions":{"11":"5"},"draft_version":"8"},
  "payload":{}
}
```

Успех:

```json
{"ok":true,"request_id":"uuid","data":{},"versions":{"encounter_version":"8","event_sequence":"38"},"warnings":[]}
```

Ошибка:

```json
{"ok":false,"request_id":"uuid","error":{"category":"STATE_CONFLICT","code":"DEPENDENCY_CHANGED","error_id":"uuid","message":"Состояние изменилось. Проверьте сводку."},"recovery":{"submission_id":"uuid","status":"registered"}}
```

HTTP mapping PROPOSED: 400 validation, 403 permission, 409 state conflict, 422 unsupported dependency contract, 429 limit, 503 unavailable dependency, 500 internal. recovery_required возвращает подтверждённое состояние операции с безопасным описанием, а не маскируется успешной публикацией. Никаких SQL/PHP stack/скрытых numeric inputs в player errors. Неавторизованный lookup не раскрывает существование чужого draft/submission.

Технические лимиты PROPOSED: не более 100 строк на страницу query, max body 256 KiB для механического JSON отдельно от допустимой длины прозы MyBB; конфигурируемый per-user/per-operation rate limit. Максимум действий/целей/триггерной глубины следует валидировать по KB и профилированию, не объявлять новые игровые caps. Запросы не создают броски на preview или autosave. Changes API может фильтровать sequence gaps без раскрытия количества скрытых entities/events.

### 6.2. Полный каталог операций

| op / метод | payload → data | Право / зависимость |
|---|---|---|
| context / GET | tid → session, encounter, safe panel, capabilities, versions | forum view + session visibility |
| catalog / GET | encounter_id, actor_entity_id, search, cursor → allowed action DTOs/cost/cooldown/safe unavailable reason | current control/delegation; KB+Inventory read |
| targets / GET | draft_id, action descriptor → safe target candidates/modes | серверная visibility/relations/range/constraints |
| draft_save / POST | draft_id?, prose, ordered unsealed actions, expected draft_version → draft_id,new version,saved_at | controller + user draft; запечатанный prefix immutable |
| draft_restore / GET | draft_id → own prose/actions/sealed refs, current conflicts | author/current permitted controller, без RNG |
| preview / POST | draft_id, expected versions → costs, legal flags, allowed probabilities, dependency_hash | dry run, no mutation/RNG |
| seal / POST | draft_id, action indexes append-only, operation_key, expected → sealed action ids, saved rolls, safe simulation, quota, confirmation_hash | открытый turn, quotas, no pause; atomic seal |
| seal_cancel_tail / POST | draft_id, from_action_id, reason → active prefix, history refs,new draft version | только хвост; quota не возвращается |
| confirm / POST | draft_id, simulation_hash, expected dependency set → confirmation_hash | актуальная safe simulation; не новый roll |
| publish / POST | draft_id, draft_version, submission_id, confirmation_hash, message → pid,result snapshot,event cursor | один разрешённый боевой пост; MyBB+ACS+Inventory coordinator |
| submission_status / GET | submission_id → registered/published/rejected/recovery_required,pid? | только уполномоченный; без повторного применения |
| changes / GET | encounter_id, after_sequence, versions,cursor → filtered deltas, safe cursor/versions | серверная visibility; fallback A1.88 |
| log / GET | encounter_id, round/entity/category/search/cursor → safe event groups, next cursor | session/forum rights на каждый элемент |
| export / GET | encounter_id, format=json/csv, filters → filtered file/data | GM export либо разрешённое представление |
| gm_command / POST | command из §6.3, target refs, reason, expected, operation_key → event refs/new versions | granular GM capability, KB contracts |
| reaction_register / POST | entity, definition_snapshot, priority config, expected → reaction_id/version | controller; effect contract; не расход реакционного лимита |
| loot_claim / POST | entry_id, need/greed/pass, allocation_version → claim version | зарегистрированная eligible group, pool open |
| loot_allocate / POST | pool_id, mode/assignments, expected → immutable winners/roll refs | GM по умолчанию либо KB free_claim/need_greed |
| reward_issue / POST | reward/loot refs, operation_key → issued/pending/recovery ref | authorized allocation; safe external owner gateway |
| profile_stats / GET | subject, filters,cursor → completed aggregate/history | private/participants/public + encounter secrecy |
| recovery_inspect / GET | operation/submission → safe diagnostic state | отдельное admin/GM recovery read |
| recovery_execute / POST | operation, proven strategy, reason, expected → previous pid/compensation/event refs | отдельное admin recovery write, no blind retry |
| acp_command / POST | settings/migration/dependency inspect/recover command → status | ACP permission; не произвольные formulas/code |

Каждый action descriptor имеет `actor_entity_id`, `kind`, `definition_ref {snapshot_id/key/version}`, `ordered_target_ids`, `parameters` по pinned KB schema. Item action дополнительно inventory_item_id/reservation_ref, spawn/object/prepared параметры только разрешённых типов. Сервер запрещает unknown fields по версии schema, клиентские cost/roll/permissions не доверяет. Parameters с entity references проходят same scope и visibility validation. Проза сохраняется как message и валидируется MyBB отдельно.

### 6.3. Typed GM commands и системные команды

Ниже имена PROPOSED command DTO. Их handlers используют тот же Domain, а не прямое изменение SQL:

| command | Обязательная предметная часть payload | Результат |
|---|---|---|
| session_create | tid, owner, initial config | session_id/roles/version |
| encounter_create | session_id, config, KB presets | encounter_id/config snapshot |
| register_entities | source refs, controllers, group refs, requested side | entity/participant IDs + snapshots |
| round_open / turn_pass / gm_skip | encounter/round/turn refs, allowed reason | boundary event/roster/closed turn |
| pause / resume / extend_deadline | encounter_id, duration/remaining change | status/deadline versions |
| session_event / rest | rest type или exact KB event ref, recipients | per-entity deltas, used rest allowance |
| gm_entity_adjustment | entity_id, permitted KB delta/max change, reason | versioned state, absolute HP preserved/clamped |
| controller_assignment / control_delegation | owner/delegate/entity scope/permissions/expiry | assignment version; без нового AP |
| rejoin_encounter / capture / release | participant refs, KB conditions | participation events, unchanged closed turn |
| custody_change | item/reservation/owner/custodian/temporary-user refs | custody event + owner gateway refs |
| encounter_environment_change / object_interaction | exact definition/instances/parameters | object/environment versions and effects |
| side_change_event / relationship_change | side/entity/relationship and source effect | changed relations/constraints, no refill |
| confirm_outcome | encounter, candidate outcome/version | completed, immutable generation refs |
| close_session / reopen_session / archive | session_id, outstanding operation policy, reason | status, retained obligations |
| correction_event | original_event_id, typed correction plan, recipient adapter capability, reason | linked compensation events; unchanged original |
| visibility_change | target encounter/event, keep_hidden/reveal_results/reveal_all или safe policy | new view policy event, unchanged history |

Automatic timeout, tick, trigger, start_operation и outbox dispatch идут внутренними commands с уникальными causal keys. Их нельзя вызывать произвольным authenticated user как GET. Отдельные global ACP capabilities не означают разрешение играть за чужого персонажа. Подтверждение UI для critical GM operations не заменяет CSRF/версионность/причину.

## 7. Транзакции, блокировки и публикация

### 7.1. Граница безопасности

Для ACS предлагается сериализация механических команд на строке session, затем encounter. Это сознательный простой порядок для ACS 1.0: меньше гонок shared_pool/targets/clocks, свободный порядок игроков определяется успешным commit. Это не обязательная игровая инициатива. Query не берут exclusive locks и не меняют clocks. Более тонкая блокировка возможна позднее только с доказательством сохранения инвариантов.

Глобальный lock order PROPOSED: session → encounter → round → turns по id → entities/states/resources по id → effects/shared pools по id → draft/sealed/submission → внешние Inventory/balance строки по `(system,item/uid,id)`. В адаптерах порядок должен быть совместим с их writers; иначе coordinator блокируется до scoped исправления. Не удерживать SQL locks при HTTP/внешнем сетевом I/O. Запись предварительного submission registration происходит отдельно до основной tx; повтор не меняет payload_hash. Deadlocks обрабатываются ограниченным повтором только доказанно rolled-back transaction с тем же tape/key, не новым RNG.

Любое изменение увеличивает соответствующие versions. Encounter_version используется для уведомления, но несвязанное событие не обязано конфликтовать с sealed action: commit сравнивает реальный dependency_set (state target, shared pools, relationships, zone/cover, source/cost/cooldowns, control, KB pinned refs). Изменение summary требует нового пользовательского confirmation_hash с прежними rolls. Клиентское expected не является достаточной проверкой: сервер сам строит множество зависимостей.

### 7.2. Успешная публикация

1. Валидировать форму/CSRF/forum/контроль и operation key; registered submission с hash payload, без расходов. Если key уже есть: другой hash → conflict, published → существующий pid, recovery_required → запрещён blind retry.
2. Выполнить штатную MyBB validation до необратимых эффектов. Проверить доступность безопасных IC-MYBB/IC-INV контрактов и транзакционные engines всех затронутых таблиц/handlers.
3. Начать общую транзакцию на том же DB connection, захватить locks в согласованном порядке. Перечитать права, turn pending, versions, availability, quota и confirmation. Не сдвигать таймеры от повторного запроса.
4. Resolve сохранённую последовательность в virtual state с прежним random tape. Получить единый validated plan. При конфликте/ошибке rollback, draft/rolls/quota сохраняются из seal; подтвердить безопасный rejected/conflict outside transaction.
5. Через MybbPostGateway создать один штатный пост с prose; получить pid. В рамках этой же tx применить AP/resources/effects/Inventory safe operations, event log, projections, post_links, turn resolved, draft published, submission published и outbox. Никаких внешних уведомлений до commit.
6. Commit. Вернуть pid/result snapshot/versions. Обрыв ответа после commit восстанавливается submission_status, не новым постом.

Отдельно проверить post hooks, автоматически начисляющие баланс/EXP/уведомления: они не должны совершать необратимое I/O до общего commit или расходиться при rollback. Без подтверждённого control над их побочными эффектами общий протокол считается **не готовым**, даже если ACS tables InnoDB. Нет правок ядра MyBB: scoped addon hooks/gateway и разрешённые адаптеры. При отсутствии безопасной границы этап 4 blocked с конкретным отчётом, не обход через прямой INSERT posts.

### 7.3. Внешние операции без общей транзакции

Для наград/outbox и иных явно координируемых owner systems: operation_key+payload_hash → prepare/reserve → lookup → commit/ack; ответ и внешняя ссылка сохраняются. Дубликат key не повторяет действие. Компенсация возможна только при соответствующем owner API: обратное начисление/возврат имущества — новое событие, не удаление старого. Необратимые внешние side effects не допускаются посреди атомарной публикации без доказанного протокола.

Статус `prepared` не означает механическое применение. ACS не выставляет completed/issued пока нет подтверждения owner. При timeout после external call — recovery_required; оператор сначала lookup, затем reconcile либо разрешённая compensation. Не освобождать резерв или loot winner автоматически только из-за сетевой ошибки. Некритичные AAM/statistics отправляются после commit через outbox и не блокируют бой.

## 8. Recovery, версии, воспроизводимость и rollback

| Точка сбоя | Доказанное состояние | Действие |
|---|---|---|
| preview/autosave | механики нет | сохранить draft; повтор expected version |
| seal до commit tx | rolls/quota не подтверждены | rollback; retry тем же key возвращает единственный сохранённый итог либо выполняет один seal |
| seal после commit до HTTP response | tape/quota уже есть | lookup seal_operation_key, возврат прежних значений |
| publish validation/rolled back tx | нет pid/events/deltas | та же подготовка и rolls, исправить причину; quota не обнуляется |
| publish committed, ответ потерян | submission published + post_links | вернуть существующий pid |
| неизвестная внешняя стадия | side effect нельзя доказать | recovery_required, no retry до lookup/reconciliation |
| outbox consumer вставил запись, ack потерян | consumer key lookup | вернуть прежний ack, не двойная доставка |
| crash migration | DDL мог сохраниться | readback checksum/schema/step, продолжить только доказанно незавершённый шаг |

Числовая политика, resolver version, KB schema/definition/contract version, content hash, snapshots, ordered targets/components/actions и tape сохраняются. Старый бой отображается по result snapshots/events, не по текущим KB/CS. Replay исторического плана — диагностический режим без записи, рассылки, выдачи EXP/loot и новых RNG. Отсутствующая старая engine version не оправдывает молчаливый пересчёт новой; архивный сохранённый результат остаётся источником отображения.

Projection rebuild допустим только из подтверждённых events/snapshots с валидной версией алгоритма и последующей сверкой hash/sequence, не «перебросить весь бой». Materialized tables не заменяются недостоверным кэшем. Session reopen не открывает completed encounter и не восстанавливает AP/ресурсы/награды. Исправления с постоянными последствиями сначала проверяют возможности owner adapter; уже выданную награду нельзя автоматически изъять из-за correction achievement. A1.39–A1.40, A1.62–A1.66, A1.89–A1.93.

## 9. Подсистемы сверх базового удара

| Domain | Реализация и сохранение инвариантов | Этап / baseline |
|---|---|---|
| Equipment/Custody | equipment snapshot, reservations mirror, custody current+events; собственник только Inventory; бонусы только активному пользователю; смена через action | 6; A1.2–A1.3, A1.32–A1.33 |
| Control/Pets/Spawn | один group/turn budget, assignment и temporary control effects раздельно, spawn_key source_event+index; независимый NPC со следующего roster | 7; A1.42–A1.43, A1.47, A1.78, A1.84 |
| Outcomes/Retreat/Capture | life/participation отдельно; end condition после полного действия, pending_resolution; partial escape и rejoin без refill | 10; A1.27–A1.31 |
| Environment/Awareness | объекты — entities+objects, обнаружение отдельно audience/subject; tools presence vs consume; progress и permitted retries KB | 8; A1.70–A1.73, A1.81 |
| Cooperation | confirmed contributions unique by event, assist one-use по умолчанию; не joint multi-post transaction | 8; A1.74 |
| Spatial | зоны без геометрии, edges/positions/engagement/cover; LOE не visibility; hidden filtered; forced move не AP цели | 8; A1.82–A1.83 |
| Prepared/Ambush | paid preparation, reserved trigger items, causal execution roll once, no extra post/turn; pre_round commitment deducts first budget | 9; A1.75–A1.77 |
| Relations/Behavior | side/controller/control_effect/awareness отдельно; KB constraints target_lock/priority/restriction/exclusion; конфликт не рандомный retry | 7–9; A1.78–A1.81 |
| Transform | effect layer modifier/override/hybrid, same entity, absolute HP clamp; retain/suppress/adapt equipment; primary_form unique | 9; A1.85 |
| Copy/Steal/Reflect/Absorb | temporary grant; linked old/new effect transfer without repeated ticks; child effect depth/causal guards; no extra AP | 9; A1.86 |
| Rewards/Achievements | loot generation and allocation separate, winner reserved on fail; recipient group default; stable reward keys/consumer acknowledgements | 10; A1.34–A1.40, A1.66 |
| Statistics/Archive | completed source events only, actual amounts, no double counting pet+group, permissions on JSON/CSV/profile and aggregate | 10–11; A1.62–A1.65 |

## 10. Presentation, безопасность и производительность

QueryService отделяет истинное состояние от viewer DTO. Политики full/approximate/hidden и awareness применяются на сервере до JSON/HTML/CSV/AAM/outbox payload. Неизвестный участник не раскрывается счётчиком total/disabled target/причиной ошибки/деталями формулы. Нельзя возвращать raw snapshot игроку и скрывать CSS. Authorize включает MyBB forum permissions, session role, ownership/delegation/controller/control effects, operation-specific grants и актуальные версии; cached permission не обходит свежую проверку commit.

ThreadComponents регистрируются через IC-THEME. Карточки постов читают post_links/result snapshot. Prose хранится отдельно и редактируется штатно. Конструктор использует один server draft и один SCEditor; переход desktop/mobile не меняет state. Списки участников/effects/links грузятся batch без N+1; журнал и каталоги paginated; тяжёлые детали/GM/формы lazy. Keyboard focus, touch targets, отсутствие перекрытия sticky/nav/meta-line — ручная приёмка этапа 11. UI операции только по разрешённым capabilities, скрытие кнопки не авторизация.

Outbox не становится high-frequency state polling. Transport optional; fallback events: visibilitychange/focus/open builder/pre-seal/pre-publish. Сетевой сбой сохраняет draft; unknown submission проверяется по key. Кэш KB ключуется contract_id/version/schema_version/hash, пользовательские DTO ещё и viewer visibility context; materialized state версия проверяется на сервере. Профилирование TTFB/PHP memory/SQL count/JS payload/API latency до выбора количественных thresholds. Обычный showthread не грузит heavy stack. A1.53–A1.59, A1.87–A1.92.

## 11. Lifecycle, миграции и минимальные проверки

Install создаёт необходимые версии schema/settings/templates с readback, activate включает hooks/assets после capability checks, deactivate прекращает механику и сохраняет всё, upgrade выполняет resumable ordered migrations, uninstall требует отдельного административного подтверждения и закрытия/разрешения внешних обязательств. Обычная реактивация не сбрасывает AP/rounds/drafts/tape и не переписывает Theme/Editor. Деактивация не освобождает Inventory reservations без согласованного протокола. Активные snapshots/engine versions сохраняются при upgrade. MySQL DDL может auto-commit: transaction rollback не считается откатом миграции.

Нужные проверки по этапам: php syntax изменённых PHP, JSON/schema contracts, уникальности/idempotency/CAS/lock rollback на точечных fixtures, lifecycle install→activate→deactivate→reactivate/upgrade, публикация duplicate/response-loss, права/секретность, AF template/editor conflicts. Для текущей документации — только ссылки, покрытие ID, структурная согласованность DDL и терминов. Полные тестовые комплексы и новая большая инфраструктура не требуются. Финальная приёмка — заказчиком на форуме; implemented и ready_for_manual_test не равны accepted. A1.93–A1.95.
