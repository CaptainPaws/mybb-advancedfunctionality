# ACS-7.3.2 — Behavior Constraints

База `main`: `c1e6a9f0` (merge ACS-7.3.1, PR #1041). Основание: A1.80,
четыре нормативных ACS-документа, KB mechanics contract и отчёты 5.1.2, 5.2.1,
7.3.1. Один ограниченный PR. `docs/ROADMAP.md` не изменён; 7.3.3 не начат.

## Реальная схема и интеграция

Новых таблиц, колонок и миграций нет. Registry остаётся 015_delegations.
Проверены существующие effects payload/spec, pinned snapshots, StatusPersistence
и Clock. Используется `mechanical_parameters.behavior_constraint` внутри прежнего
status instance: source/target/session/encounter, definition refs, duration,
applied_order и effect_version сохраняет тот же StatusPersistence. KB adapter
уже передаёт retained status_def в Effects::instanceSpec; дополнительный live
lookup или отдельный реестр не нужны. Status application/removal/expiry и audit
сохраняются штатным atomic writer/Clock, включая rollback и durable receipts.

Новый `Domain/Behavior.php` — чистая bounded проверка допустимости, не новый
Resolver или status engine. Effects валидирует opted-in descriptor; существующий
Resolver применяет проверку на актуальном detached state перед каждым действием
и каждой активной реакцией. Нет собственного RNG, очереди с retry или таймера.

## Контракт и конфликты

Точный binding и примеры: [ACS_KB_MECHANICS_CONTRACT.md](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-732--behavior_constraint-binding).
Поддержаны target_lock, target_priority, target_exclusion и action_restriction.
Любое KB имя может использовать эти примитивы: taunt/fear/compulsion не являются
специальными ветками или источником правил по локализованному названию.

Descriptor явно задаёт schema, int32 priority, scope ordinary/reaction,
compatibility group/mode, on_no_legal_target и 1..16 правил. Источник цели —
source этого экземпляра либо self. Категории действия и точные ability_keys
только сужают уже предоставленные права. Combat scope, source_scope=source,
положительный срок own_turn/target_turn/round; неизвестные поля/схемы/селекторы
отклоняются. Комбинирование с control_effect, periodic или upkeep в одном
spec не поддерживается; отдельные экземпляры остаются независимыми.

Внутри exclusive группы выбирается больший priority, затем самый старый
applied_order, затем стабильный ID. combine пересекает все правила; разные
группы также пересекаются. Несовпадение mode в одной группе — UNSUPPORTED,
не произвольное переключение правил. Порядок rules — индекс KB. Нет нового RNG
для приоритета. Refresh/stack не переписывают прежнюю pinned силу/spec.

## Выбор, отсутствие цели и видимость

Builder выдаёт per-choice legal_targets и READY/BLOCKED/UNSUPPORTED, фильтрует
способности и общий selector на сервере. Browser только использует выданный
список. Переданный вручную target_id не обходит проверку prepare/Resolver.
Состав candidate universe строится из видимых engaged selectors, текущих sides,
relationship policy способности и life state; это **не** присланный список целей.
Metadata life_state берётся одним JOIN к существующей entity_states, без чтения
полного source snapshot каждого кандидата. Hidden identity не выводится даже
как disabled target или причина блокировки; descriptor/pins/source ID закрыты.

Для source/self lock и priority цель должна быть видима, active и допустима
по KB target policy. block возвращает безопасный код; ignore_rule пропускает
только неосуществимое target-правило и сохраняет прежний набор. Exclusion,
оставляющий пустой набор, может быть пропущен только по explicit ignore_rule.
Ни один fallback не отменяет action_restriction, не выбирает противника и не
исполняет атаку. Набор меньше pinned target_count.min также BLOCKED.

UI показывает причину, оставляет обновление/обычное редактирование доступными,
не создаёт повторную очередь и не меняет запечатанную фиксированную цель.
Seal status и API ошибок передают структурированный безопасный behavior status.
Известный отказ снимает pending UI request; текст и сохранённые действия остаются.

## Seal, commit и реакции

Builder prepare и Resolver проверяют одно правило независимо. QueryService
добавляет hash полного видимого candidate universe; прежний status_state_hash
содержит все экземпляры. Existing confirmation hash/fact fence включает их и
под SQL lock охватывает entities/states/participants/relations/effects/snapshots.
Наложение/истечение или изменение доступности source требует обычной повторной
проверки/подтверждения. Tape и фиксированные targets не заменяются.

Writer по-прежнему вызывает тот же preparer/Resolver на исходных sealed samples;
новой commit API нет. При уже известном native PID устаревший commit блокируется
в прежнем R2 протоколе без второго поста. Единственный unresolved publication
на сессию сохранён, не ослаблен ради параллельного эффекта.

ReactionService проверяет behavior при регистрации; Resolver повторяет проверку
перед исполнением существующей реакции. Отказ не платит costs и не тратит slot.
Redirect candidates также сужаются текущими ограничениями, но taunt не создаёт
intercept, не переносит атаку и не меняет существующие roll values.
Passive consequences не превращаются в новые самостоятельные выборы действий.

## Независимость и duration

Behavior ничего не пишет в sides/controllers/owner UID/groups, не даёт Inventory
transfer/grant или CS права. AP/seal quota/resources/reaction/cooldowns/closed turn
не восстанавливаются. Обычное подтверждённое действие сохраняет прежний group
turn/post и реальные расходы.

Срок уменьшается исключительно существующими EntityTurn/round boundaries;
Clock/cleanse/dispel снимают прежний status row, не стирая history/pins/events.
Duplicate boundary/commit возвращает прежний результат без второй expiry.
Нет view timer, второго Clock, нового turn или нормализации постоянного персонажа.

## Проверки

PHP **8.5.12**, disposable MySQL **8.0.46**, authoritative Stage 6/7 fixture;
production/installed forum не подключались.

- `acs_behavior_constraints.php`: все четыре typed правила, taunt priority,
  fear exclusion, compulsion allow/deny, exclusive/combine/mixed compatibility,
  разные priority и FIFO tie, независимость от storage iteration, пустой/hidden/
  inactive source, explicit fallback, target cardinality, чужой encounter,
  неизвестные policy/forced_flee/prepared, expiry/duplicate Clock boundary,
  manual/sealed Resolver gates, реакция без costs/slot при запрете.
- `acs_behavior_constraints_sql.php`: реальный KB normalize/pin/status commit,
  server selector, manual target, visibility для делегата, отсутствие целей до
  RNG/quota, compulsion, регистрация реакции, новый статус после seal,
  post-confirmed commit после GM side change, неизменные samples/targets,
  expiry на реальном group-end и duplicate commit, отсутствие side/controller/AP reset.
- `acs_side_control_sql.php`: точечная регрессия 7.3.1 (relations/friendly fire,
  overlays, reassignment race, rollback, shared budget, hidden scope).
- `acs_intercept_mass_defense.php`: существующая domain regression intercept,
  mass defense, protection boundaries и target tape. Узкие цепочки первых двух
  domain fixtures также включают Status/Clock/upkeep/reaction regressions.
- `node tests/acs_builder_state.js`, `acs_builder_asset_scope.php`, `node --check`
  builder.js, `php -l` всех изменённых/новых PHP, `git diff --check`.

Старые `acs_builder_regression.php` и `acs_intercept_mass_sql.php` были запущены,
но не достигают своих сценариев: зависимый `acs_entities_regression.php` передаёт
SnapshotReadOnlyDb без того же writer connection и получает
ACS_AUTHORITATIVE_CONNECTION_REQUIRED в EntityService/Repository. Ошибка Builder
воспроизведена отдельно на неизменённом `c1e6a9f0`; guard не ослаблен. Для новых
SQL сценариев использован актуальный authoritative fixture. Полный suite,
browser E2E и installed MyBB acceptance не запускались.

## Ограничения

Поддержан только описанный combat subset, без произвольных predicates, movement,
зон, fleeing/surrender, callbacks и автоматического выполнения fallback-действий.
Prepared-action executor пока отсутствует; scope=prepared отклоняется, будущий
исполнитель должен вызвать этот же gate при срабатывании, сохраняя fixed target
и tape. Новые pinned status defs требуют явного typed descriptor; неизвестная
метаинформация не угадывается. Старые sealed планы после обновления могут
потребовать обычный reconfirm из-за добавленного candidate dependency hash.
Publication flags не включаются. Следующий этап ACS-7.3.3 не начат.
