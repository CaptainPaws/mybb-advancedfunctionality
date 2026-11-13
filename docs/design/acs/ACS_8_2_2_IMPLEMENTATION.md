# ACS-8.2.2 — Assist, cooperative checks/objectives и durable contributions

База main: `adf0a6ec` (merge ACS-8.2.1, PR #1046). Один ограниченный PR.
Источники: ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE,
ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS,
ACS_KB_MECHANICS_CONTRACT; отчёты 8.2.1, 7.3.3 и 4.3.1B-R2.
Требования A1.1, A1.49, A1.73–A1.74. `docs/ROADMAP.md` не изменён.

## Реализация

`Domain/Cooperation.php` исполняет один typed KB contract для assist,
cooperative_check и cooperative_objective. Задача привязана к существующему
object entity, одна на object. `cooperation` хранится в pinned object template
v2 категории interactive. Полная грамматика и ограничения subset добавлены в
[KB mechanics contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-822-sequential-cooperation-subset).
Нового per-object PHP-handler и второго combat event processor нет.

Check/contribute используют Formula/outcome/cost/retry contract из 8.2.1.
KB задаёт actor kinds, способности, минимумы характеристик, AP/resources,
вычисление score, диапазоны исходов и неотрицательный вклад. Формула и пороги
не подставляются по умолчанию. Начальный прогресс 0, cap задаёт KB; вклад
ограничен остатком до cap. Каждый record хранит actor, recipient (для assist),
operation kind, score, outcome, фактическую delta и before/after progress.

Assist — отдельный mechanical effect instance внутри projection задачи:
source, recipient, pinned inline effect definition, causal sealed action,
срок в раундах, remaining uses и stacking policy. Это не литературная пометка
и не прямое изменение прогресса. По умолчанию 1 использование и reject
идентичной активной помощи тому же recipient независимо от helper. Явные
replace и bounded sum, uses>1 поддержаны контрактом. Бонус применяется только
через explicit assist input формулы данной задачи; обычная атака не получает
новый неявный модификатор. Проверка расходует использованную помощь один раз.
Завершение задачи прекращает оставшуюся scoped помощь с сохранением истории.
Expiry выполняет существующий Clock round_end с boundary identity; GET,
preview и открытие панели не создают помощь/вклад и не запускают Clock.

## Схема и реальные bindings

Добавлена следующая свободная migration `018_cooperation`: только недостающие
`af_acs_objectives` и `af_acs_contributions` с индексами Architecture.
Objectives имеют UNIQUE object_entity_id, encounter/object/template snapshot,
status, objective_version, completed_event_id и progress_json. Contributions
имеют global UNIQUE contribution_key, objective/entity/source_event и typed
payload. Никаких изменений checksums 001–017, MyBB engines или owner schema.
Использован прежний resumable мигратор с exact table/index readback.

Objective создаётся в той же transaction, что и существующий spawn identity,
через `SpawnPersistence`, включая разрешённое GM creation. GET не материализует
задачи. Definition snapshot ID указывает на реальный immutable object template
snapshot, runtime definition проверяется с исходным pinned object definition.
Это не новая object identity и не inferred bestiary entity.

Каждое `object:<entity_id>:coop-check|coop-contribute|coop-assist-<recipient>`
проходит прежние Builder → Draft/Seal → PublishService → R2 journal →
CombatCommitWriter. parameters=[]; клиентский результат/размер вклада не
принимается. Сохраняются current controller/delegation/native/session gates,
life/roster/status/behavior, awareness, AP/resources и quota. Assist recipient
должен быть активным известным союзником в существующей selectable scope.
Одно действие не даёт право управления recipient и не расходует его AP.

Cause — `sealed-action:<sealed_actions.id>` из прежнего reactionContext.
Contribution key — `objective:<objective.id>:sealed-action:<id>`, source_event_id
— реальный `combat.resolved` event. У группы остаётся один post_link и один
resolved turn; у независимой группы — свой turn/AP и обычная отдельная
последовательная публикация, без совместного dispatch нескольких сообщений.
Условная одновременность — порядок успешных ACS commit/event sequence.

`Infrastructure/CooperationPersistence.php` проверяет projection, принадлежность
entity/event к session/encounter, pin, неизменяемость records и objective CAS.
Запись выполняется в `CombatCommitWriter::finish` после создания настоящего
journal event, до terminal result/receipt/outbox. Completion отмечается
`objective.completed` в changes существующего event_details, completion_cause
и completed_event_id. Отдельного журнала completion/reward нет. Допустимые
последствия текущего subset — object activation/deactivation и heal finisher
через прежние Environment/Effects. Повтор completion невозможен после terminal
status; duplicate commit/recovery возвращает immutable receipt.

## Конфликты, история и атомарность

Objective/contribution rows включены в locking facts/fence, objective projection
— в dependencies и seal/writer summary hash. Изменение objective_version или
object_version инвалидирует прежнее confirmation. До publication freeze можно
пересчитать и подтвердить прежний tape. Уже frozen конфликт остаётся в прежнем
R2 quarantine/recovery; редактирование publishing draft не добавлено.

KB object_changed=reconfirm проверяет новое допустимое состояние; block
запрещает любое отличие от creation object_version, включая lifecycle versions.
Contributor inactive policy текущего subset — retain: подтверждённые records
и помощь не удаляются. Новая попытка incapacitated/dead/unavailable actor
отклоняется прежними gates. Чужой прогресс не сбрасывается произвольно.

Поздняя ошибка откатывает objective/version, contribution UNIQUE rows,
completion_event binding, object effects, HP/AP, journal/result/receipt/outbox
одной существующей ACS InnoDB transaction. Native MyBB post остаётся вне неё.
Gateway, RecoveryService и publication flags не переписаны. Inventory и
CharacterSheets не получают новых операций; shared owner permissions не
выводятся из участия в задаче.

## Видимость и минимальный UI

Builder добавляет группу «Совместные действия»: «Помощь», «Совместная проверка»,
«Внести вклад», стоимость и безопасные причины блокировки. Progress DTO
строится после native/session/actor awareness/KB visibility checks. Known
policy требует identified object; gm policy не раскрывает задачу даже при
известном объекте. Hidden задачи отсутствуют в choices и totals; player DTO
не содержит conditions, contributors, assist definitions, snapshots или GM
reasons. Полные details остаются GM-only. Draft restore проверяет текущую
видимость задачи и recipient. Shared public DTO cache не добавлен.

Preview не вычисляет outcome/вклад и не расходует помощь. Seal фиксирует
стандартный tape на каждое действие; assist samples остаются unused, check
использует hit.u только при явном roll binding. Сводка и GM stored combat card
показывают typed cooperative result без фиктивных P_hit/P_crit. Cache asset
Builder обновлён до 0822. Нового SCEditor и UX этапа 11 нет.

## Ограничения

Только object-bound задачи; standalone tasks, spatial условия, minimum distinct
contributors/произвольные составные условия, отрицательный вклад/reset/decay
общего прогресса, другие inactive policies, tools внутри cooperative action,
ordinary-attack assist, status/loot/Balance/EXP completion — UNSUPPORTED.
Assist duration — только round; сумма ограничена 8 эффектами, история задачи —
16000 records. Предметные действия 8.2.1/Stage 6 сохраняются отдельно в прежнем
pipeline. Installed MyBB/native publication acceptance и production migration
этим PR не заявляются. ACS-8.3.1 не начат.

## Проверки

Disposable MySQL 8.0.46 / PHP 8.5.12, существующий authoritative Stage-6/7
fixture и известные native fixture PID; Gateway INSERT не запускается.
Полный suite не запускался.

PASS:

- `tests/acs_cooperation_sql.php`: install/repeat/resumed 018 без изменения
  прежнего ledger; отдельные contributors и group AP; два actions одной группы
  в одном post_link; actual cooperative_check и cooperative_objective KB pins;
  assist once, no auto-stack, round expiry/duplicate boundary, завершение scope;
  persisted score и retained used rolls; contribution UNIQUE/replay;
  completion once с object/HP consequences; два реальных SQL handles и ACS lock
  contention; concurrent objective reconfirm без новых rolls; object changed;
  incapacitated participant deny и retain чужого подтверждённого вклада;
  prose-only не добавляет вклад; late rollback обычного вклада и completion;
  known public progress без GM details; GM-only task скрыта даже при known object.
- `tests/acs_object_interaction_sql.php`: регрессия 8.2.1, включая tools,
  AP/resources, outcomes/progress, fixed tape/conflict и owner rollback.
- `tests/acs_environment_sql.php`: identity/lifecycle, passive/trigger,
  destruction, migration resume и rollback.
- `tests/acs_awareness_sql.php`: audience/levels, hidden filtering,
  stealth/detection, cached card isolation и rollback.
- `tests/acs_combat_items_sql.php`: прежняя Stage-6 same-writer предметная
  последовательность, post-confirmed commit, duplicate и late rollback.
- `node tests/acs_builder_state.js`, `node --check` Builder JS,
  `php -l` всех изменённых/новых PHP и `git diff --check`.

SQL-команда: `docker exec acs811-php php tests/<fixture>.php
/tmp/acs222-socket/mysql8.sock`. Каждый SQL fixture создаёт отдельную случайную
БД и удаляет её в finally. Production storage не подключалась. Browser/native
installed acceptance этим локальным PASS не подменяется.
