# ACS-2.3.2 — AP, cooldown boundaries и отдых

Исходная main: `84e4c27aa6bc53fe0ca22f492a170e427dbf70cb` (merge PR #990).
Аддон `0.8.0`, схема `005_clock_resources`. Четыре проектных документа, Design Baseline и миграции 001–004 не изменены.

## Файлы

Новые внутри `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `migrations/005_clock_resources.php`: только проектная af_acs_cooldowns.
- `modules/Application/ClockService.php`: авторизация, AP availability, явные clock commands и отдых.
- `modules/Infrastructure/ClockPersistence.php`: trait существующего repository/transaction, ledger, CAS и resource projections; не вторая БД/архитектура хранилищ.
- `modules/Presentation/Clock.php`: AP/cooldown metadata, явные формы границ/отдыха и confirmed history.

Расширены `modules/Domain/Clock.php`, `modules/Application/EncounterService.php`, `modules/Infrastructure/EncounterRepository.php`, `modules/Infrastructure/Repository.php`, `modules/Administration/Migrations.php`, `modules/Presentation/Encounters.php`, `advancedcombatsystem.php`, `manifest.php`. Новая точечная проверка — `tests/acs_clock_resources_regression.php`; предыдущие shared fixtures entities/encounters/round_roster обновлены на версию схемы и небольшой callback. Этот отчёт — единственное изменение документации.

Миграция 005 сохраняет DDL Architecture §5: id PK; entity_id, ability_key, group_key, scope, remaining_turns, last_boundary_key, cooldown_version; UNIQUE entity_ability_group(entity_id,ability_key,group_key), KEY entity_scope(entity_id,scope). InnoDB/utf8mb4_unicode_ci/ROW_FORMAT=DYNAMIC, настоящий TABLE_PREFIX, без FK. Источники/версии не добавлены вымышленными SQL-колонками: используются существующие immutable snapshots и confirmed audit.

## AP

Начало нового раунда фиксирует boundary `round:<id>:ap_refresh` в существующем operations ledger. Внутренний `initializeRoundAP` вызывается **в той же транзакции открытия roster**, после создания нулевых projections и до любого начала хода/поддержания. Он устанавливает базовые **3 AP**, увеличивает turn_version и сохраняет committed boundary вместе с parent round event. Остатки прошлого turn не переносятся: каждый новый turn начинает с нулевых counters.

Повтор boundary возвращает сохранённый результат и не выполняет UPDATE. Для первого раунда, созданного старым кодом 004 до появления marker, `ap_refresh` проверяет уже подтверждённый `encounter.round_start` с тем же boundary и только регистрирует факт: потраченные AP не возвращаются. Неподтверждённый/произвольный round не получает AP через этот путь.

Внутренний writer требует pending/нулевые spent/seal/commitment, ещё не обработанные индивидуальные границы, пустые поддерживаемые group config/layers. Неизвестные modifiers/AP policies/ticks/upkeep блокируются, а не игнорируются. Никаких пересчётов по GET или смене controller/owner. Статусы turn не открываются повторно.

`ClockService::available(session_id, turn_id, entity_id, cost=0)` — единый read-only AP check будущих команд: actual actor, membership/native topic access, текущий pending turn, участие entity в frozen roster, соответствие группы и текущего контроллера/владельца. GM не получает этот check для чужого персонажа. Возвращает maximum/available/affordable и turn_version. Это **AP check**, а не полная авторизация способности/публикации: последующий coordinator также обязан проверить life/эффекты/границы/targets/costs под lock. Нет spend/reserve/reset endpoint для произвольных действий. pre_round commitments пока не исполняются; неподдержанный ненулевой commitment блокируется.

Игрок/питомцы делят один turn budget; самостоятельные NPC/монстры сохраняют отдельные groups. UI GM показывает оставшиеся/максимальные AP, но не позволяет вручную тратить их. Открытие следующего раунда/закрытие pending ходов здесь не добавлено: внутренний round-open writer готов к следующему разрешённому открытию, а не к обходу обязательств.

## Clock и cooldown

`entity_start` — явный POST/CSRF command владельца сессии/GM (или штатного AF ACP администратора в ACP), только current open round, active encounter, pending turn, entity из зафиксированного roster. Обрабатывается отдельный EntityTurn; boundary — `entity_turn:<id>:start`. После проверки пустого поддерживаемого состояния выполняются decrement и CAS start_done/entity_turn_version, затем marker и audit в одной транзакции. Повтор с тем же **или другим** operation_key возвращает тот же boundary result без второго decrement. Во время paused новая граница запрещена; уже применённая граница может быть прочитана без изменения состояния.

Cooldown уменьшается только на **следующих** собственных началах: applied EntityTurn никогда не уменьшает свой новый cooldown. cooldown=2 проходит 2 → 1 → 0 на двух следующих собственных starts. Чужой EntityTurn, повтор HTTP, просмотр, редактор или литературный пост не являются границей. remaining_turns неотрицателен, cooldown_version монотонна, last_boundary_key хранится. Сервис проверяет scope, исходную величину, source/owner scope и порядок границ.

`combat_reset` доступен только для подтверждённого completed encounter; обнуляет только combat cooldowns этого origin, не session. Новые combat cooldowns другого encounter не трогает; при конфликтующем живом столкновении существа отказывает. Это отдельная явная boundary `encounter:<id>:cooldowns_reset`, идемпотентная и журналируемая. Само завершение столкновения пока не реализовано этой задачей. Будущий lifecycle должен вызывать reset при завершении, до нового боя.

### Источник и граница с этапом 4

Публичного создания/редактирования cooldown нет. Без подтверждённого действия такой state не принимается. Текущий producer настоящего combat_post ещё отсутствует; его безопасность/атомарность **не объявляется READY** этим PR.

Reader подготовлен к серверному контракту **IC-ACS.cooldown-source.v1**: immutable snapshot source_system=acs, source_key=`cooldown:<row_id>`, contract_id=`ic-acs.cooldown-source.v1`, version=1, schema_version=1, numeric_policy=binary64-half_up-v1, engine_version=acs-clock-resources-1. Payload фиксирует cooldown/entity IDs, ability_key/group_key/scope, initial_turns, applied_entity_turn_id, origin_encounter_id, confirmation_event_id, ability_ref с pinned definition_version/fingerprint. Новое применение позднее должно создавать новый source snapshot, не переписывать старый.

Reader сверяет hash/version snapshot и committed event/operation той же сессии/encounter с source_entity_id и типизированным `confirmed_action {schema_version:1, entity_turn_id, ability_ref, cooldown:{ability_key,group_key,scope,turns}}` в hashed event_details. Definition fingerprint в ref/version должен совпадать. Сам **producer** этого envelope — контракт будущего подтверждённого исполнения; он обязан проверить KB definition, владение способностью и atomic publish. Наличие произвольного PHP caller или MyBB post не является доказательством такого исполнения. Ни существующие формы, ни generic API не позволяют записать confirmed_action/source JSON.

Неполный/неподтверждённый source блокирует readiness/start boundary конкретного существа до любых частичных изменений. UI показывает причину, не ремонтирует state. Проверка не подменяет KB версии текущими и не пересчитывает ранее сохранённый cooldown по live KB.

## Отдых и ресурсы

Только явный мастерский POST, native CSRF, reason, operation_key, session expected_version. Короткий отдых: **25% максимального HP**, один на группу между завершённым и следующим столкновением. Window определяется последним completed encounter; marker включает session/group/window. Новый ключ в том же интервале не даёт второй короткий отдых, исходный key возвращает прежний результат. Начальный интервал без завершённого боя не выдаёт короткий отдых по догадке.

Полноценный отдых: до максимального HP, отдельное явное разрешение GM/owner и checkbox подтверждения. Не расходует короткий лимит без отдельного KB правила. Полный отдых разрешён также в мирной подготовке до первого боя; short всё равно требует completed window. Во время active/paused/ambush_ready/pending_resolution отдых запрещён. Мирные литературные посты ничего не восстанавливают.

Одно событие обрабатывает персонажа и присутствующих постоянных питомцев player group; independent group NPC/монстра также может получить разрешённый мастерский отдых по baseline. Не создаёт питомцев/призывов. Dead не воскрешается; captured/escaped/withdrawn без подтверждённого присутствия не восстанавливаются. Неподдержанные активные layers требуют настоящего обработчика, а не молчаливой очистки статусов.

HP восстанавливается отдельно по текущему максимуму каждого существа, с одной terminal half_up границей и clamp. Integer quarter вычисляется точно (например 25.5 → 26); не используется формула лечения этапа 3. HP/state/resource versions меняются CAS, entity_states.hp и resource hp согласованы; исходный maximum/definition_json/source_snapshot_id сохраняются. Отдых **не меняет максимум HP** и не делает процентного пересчёта текущего HP при смене максимума. Общего редактора max HP/критических постоянных прав нет.

Только HP имеет сейчас доказанный rest contract. Остальные resources без утверждённого owner/KB признака rest eligibility не меняются и получают `resource_rest_contract_unavailable` в результате. Не выдаются mana/stamina из названия. Сессионные статусы/cooldowns отдыхом по умолчанию не сбрасываются. Существующие поддерживаемые session/group rest overrides не угадываются: неизвестная политика — BLOCKED. Полноценный KB rest-policy/resource-eligibility producer ещё не реализован; baseline A1.7–A1.9 применяется как явная версионированная политика, не как выполнение произвольного JSON.

Все изменения только в ACS. CharacterSheets/build_json/опыт/Inventory/KB не обновляются. Parent rest operation/event содержит before/after/max, source refs, skipped targets/resources и policy version. Source snapshots/events неизменяемы. Rest limit marker, ресурсы, versions и event коммитятся атомарно.

## UI/API и защита

Существующий AF API: `clock_overview`, `clock_ap_available`; POST `clock_entity_start`, `clock_ap_refresh`, `clock_combat_reset`, `clock_rest_short`, `clock_rest_full`. Любая другая clock operation запрещена. Commands используют существующий transaction/advisory lock и session CAS, encounter CAS для границ, resource/entity/cooldown CAS. Actor/owner не принимаются из клиента. Возвращаемый canonical boundary result одинаков при первом вызове и повторе; актуальные versions для следующей команды читаются через context/overview, а не повторно применяют старый POST.

В существующей ATF GM-панели показаны AP, cooldown state/source readiness, явное начало EntityTurn, доступный отдых и последние confirmed восстановления. После short use соответствующая кнопка группы исчезает; API также проверяет лимит сервером. GM не вводит сырой JSON/отпечатки. Отдельных global hooks/assets/таймеров, независимого ACP, нового SCEditor нет. GM clock не даёт права играть за персонажа/тратить его имущество; AP action check отдельно проверяет владельца/контроллера.

## Проверки

- `php -l` затронутых PHP, сверка DDL/индексов с Architecture, `git diff --check`, неизменность 001–004 и Design Baseline.
- Новый focused `tests/acs_clock_resources_regression.php` на disposable MariaDB переиспользует прежние небольшие fixtures: общий/независимый AP, duplicate refresh после spent=1 без возврата AP; pinned confirmed cooldown=2, 2→1→0, same/different-key duplicate, отказ unconfirmed proof; combat reset без изменения session row; short 25%/half_up/owner+pet/limit/retry, full confirmation/dead, active-combat запрет, CSRF, GET и настоящий server-rendered POST формы/истории без внешних записей; 005 resume/repeat.
- Связанные focused snapshot/entities/encounters/roster fixtures и sessions regression проходят. Последняя проверяет настоящую deactivate/activate и восстановление общей ATF connection charset/sql_mode.

Fixtures следующих round boundaries, completed encounter и confirmed action намеренно создаются **только в собственной изолированной БД**: настоящие publishers/encounter completion/next-round lifecycle ещё не реализованы. Это тест входного контракта, не игровые endpoints или утверждение о готовности этапов 4/10. Producer постоянных питомцев всё ещё будущий: используется fake owner provider предыдущей задачи.

Локально PHP **8.4.24**, MariaDB **10.11**. Установленный MyBB 1.8.40 / PHP 8.5.0 — ручная приёмка. Full suite, E2E и стресс не запускались, production SQL не выполнялся.

## Установка, приёмка и восстановление

1. Резервная копия; установить файлы; **ACP ACS → Основные → Обновить / продолжить миграции** до `005_clock_resources`, completed. Затем включение ACS. AF Core/ATF реактивировать не нужно.
2. Открыть первый раунд: общий AP 3 owner/pet, отдельные 3 монстров. Повторные обновления/refresh не возвращают потраченный AP. Произвольной кнопки AP spend/reset нет.
3. Для cooldown=2 использовать изолированный confirmed-source fixture либо будущий настоящий producer. Обычный KB editor/UI не должен создавать cooldown. Проверить два следующих собственных starts, duplicate, scopes и pinned refs; не подделывать production records.
4. В мирной подготовке мастер может явно разрешить полный отдых. Short проверить после подтверждённого завершённого столкновения; пока завершение не поставлено, этот сценарий доступен в fixture, а не через фиктивную кнопку close. Active/paused encounter должен блокировать отдых.
5. Проверить HP группы, лимит short и повтор исходного operation_key; maximum/source refs и постоянный CharacterSheets прежние. Проверить dead/presence и неизменность cooldown от отдыха по умолчанию.
6. Проверить GET/редактор/обычную тему, права игрока/чужого GM, reload и deactivate/activate после upgrade. Данные/история сохраняются.

При прерывании 005 устранить причину и повторить штатный upgrade с теми же файлами: runner проверяет DDL/readback и продолжает. Не менять checksum/journal и не удалять state. Потерянный ответ команды восстанавливать тем же key/payload/lookup, не новым запросом вслепую.

Операционный откат: отключить ACS, сохранить таблицы и историю. Down migration нет. Старый код 004 нельзя включать поверх journal 005; полный возврат только из согласованной совместимой копии с сохранением новых данных. Uninstall не является откатом.

ACS-2.3.3 не выполнялась. Атаки, способности, публикация, periodic damage/heal, расход AP/предметов и полный battle simulator не добавлены.
