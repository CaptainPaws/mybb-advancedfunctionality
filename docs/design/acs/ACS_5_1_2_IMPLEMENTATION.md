# ACS-5.1.2 — Status Instances, Stacking, Duration, Cleanse и Dispel

База: `main` = `5c912af248d0209ddd1b4fddc41f2d0e55c9d1d1`, после merge ACS-5.1.1. Основание: S1.4–S1.12, A1.17–A1.19; [Master §4.1](ACS_MASTER_SPECIFICATION.md), [Architecture](ACS_TECHNICAL_ARCHITECTURE.md), [Roadmap, этап 5](ACS_IMPLEMENTATION_ROADMAP.md#stage-5), [Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md), [KB mechanics](../../contracts/ACS_KB_MECHANICS_CONTRACT.md), [5.1.1](ACS_5_1_1_IMPLEMENTATION.md), [R2 writer](ACS_4_3_1B_R2_COMBAT_WRITER.md) и [PublishService](ACS_4_3_2_R2_PUBLISH_SERVICE.md).

Реализованы жизненный цикл экземпляров и confirmed persistence. Временные тики, decrement/expiry на turn/round boundaries и поддержание не исполняются: это ACS-5.2.1. Publication guards Stage 4 R2 не включаются автоматически.

## Фактическая схема и миграция

До изменения в исходниках зарегистрированы миграции **001–009**, последняя — `009_combat_commit`. Таблицы `af_acs_effects` нет ни в одной из них; её DDL в Architecture был проектом, а прежний preparer блокировал само наличие таблицы effects. К installed БД warprift.ru доступа нет: её фактическая последняя применённая версия **не проверена**.

Добавлена **`010_status_instances`** в существующий ordered registry и writable gate. Она создаёт InnoDB `af_acs_effects` по Architecture: ID, session/encounter, source/target entity IDs, definition_snapshot_id, effect_key, scope/status, stacks, remaining_duration/duration_unit, applied_order, effect_version, parent_effect_id и payload_json. Для поддержанного статусного subset source/target/duration обязательны; parent_effect_id=NULL. UNIQUE `(session_id,applied_order)` защищает порядок. Category, правила повторения/снятия, mechanical_parameters и точные refs лежат в versioned typed payload.

001–009 не изменены. Actual migration resume/repeat и точный schema readback выполнены на disposable MariaDB; прежние migration rows/checksums совпали. Нестандартную существующую effects-таблицу migration runner не чинит и не пересоздаёт молча. Перед записью по-прежнему обязательны completed migration records с правильными checksum/step и transactional table checks. Миграция запускается штатно, не из preview/seal/GET. Добавление status dependency hash может потребовать явного reconfirm существующего pending seal с прежним tape; старые COMMITTED receipts не пересчитываются.

## Изменённые файлы

Пути относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

| Файл | Назначение |
|---|---|
| `migrations/010_status_instances.php` | Первая фактическая status storage table |
| `modules/Administration/Migrations.php` | Registry/version 010 |
| `modules/Infrastructure/Repository.php` | Allowlist effects и обязательный migration gate 010 |
| `modules/Domain/Effects.php` | В существующем Effects: typed instance/spec validation, apply/reapply, stack, removal и combat-scope cleanup |
| `modules/Domain/Resolver.php` | Successful 5.1.1 evaluation → тот же Effects; cleanse/dispel; root-index order внутри trigger phase; виртуальные состояния между действиями |
| `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php` | Hydration lifecycle из pinned typed status_def; строгий removal effect contract |
| `modules/Application/QueryService.php` | Read-only status projection, hash в dependencies, status_scope; подготовленные cleanse/dispel |
| `modules/Infrastructure/EncounterRepository.php` | Подключает status projection; сохраняет запрет неизвестного persistent barrier storage |
| `modules/Infrastructure/StatusPersistence.php` | Общий SQL trait для preparer/writer/combat-reset: hydration с pins, CAS writes, mapping виртуальных ID; собственной tx/engine нет |
| `modules/Infrastructure/CombatCommitPersistence.php` | Effects и их snapshots входят в dependency fence; status writes — часть существующего project() |
| `modules/Infrastructure/CombatCommitWriter.php` | Использует общий SQL trait; передаёт frozen definition snapshot IDs в project() |
| `modules/Infrastructure/ClockPersistence.php` | Удаление combat-статусов в существующем confirmed `combat_reset`, в том же command journal/tx |
| `modules/Infrastructure/SealPersistence.php` | Преобразует invalid sample diagnostic в прежний `ACS_SEAL_TAPE_INVALID` на persistence boundary; это обнаружила SQL-регрессия writer |

Fixtures: новые `tests/acs_status_instances.php`, `tests/acs_status_instances_sql.php`; в `acs_status_evaluation.php` тестовый status_def получил явный scope, а `acs_combat_commit_regression.php` проверяет актуальную migration VERSION вместо константы 009. Также этот отчёт. `docs/ROADMAP.md`, KB schema/editor, MyBB core, внешние owner writers и publication routes не изменены. Второй Effects Engine, status endpoint и новая транзакционная система не созданы.

## Идентичность и повторное наложение

Instance payload имеет schema_version=1, source/target IDs, origin encounter/session, typed spec, parent effect definition ref `(type,key,version,fingerprint,effect_index)`, отдельный status definition ref `(key,version,fingerprint)`, stacks, remaining_duration/unit, applied_order, version, status и origin_action_index. SQL column definition_snapshot_id ссылается на сохранённый **sealed ability DTO**, содержащий точный status definition; read projection проверяет hash snapshot, оба refs и совпадение spec. Live KB lookup/пересборки из latest нет. Маски/имена не входят в identity.

Default identity: session + target + effect_key + scope + source; для combat также origin encounter. Разные источники независимы. Явный `source_scope=shared` снимает разделение по source; первый source/provenance сохраняется. Переход от уже раздельных экземпляров к неоднозначному shared bucket закрыт, а не объединяет их произвольно.

- `refresh` (default prepared mode): срок `max(existing,new)`, исходные spec/сила/provenance/applied_order сохраняются; версия увеличивается.
- `independent`: новый ID/order даже от того же источника.
- `reject`: без изменения существующего экземпляра.
- `extend`: прибавляет новый срок, сохраняя исходную силу; integer overflow запрещён.
- `stack`: требует cap и increment; `min(cap,existing+increment)`, общий срок `max(existing,new)`, в том числе на cap. Изменение pinned stacking definition не подменяет прежний cap/spec.

В virtual state новые IDs имеют вид `new:<session applied_order>`. Только confirmed writer получает глобальный SQL AUTO_INCREMENT ID, сохраняет payload с реальным ID и пишет `changes.statuses.id_map`/before/after в общий combat result/event. Origin snapshot для нового экземпляра выбирается из frozen seal по origin_action_index; refresh не меняет его. История хранит логически удалённые rows: `status=removed, stacks=0`, не выполняя DELETE/audit erasure.

## Поддержанный KB subset

Исходный B3 schema остаётся `af_kb.arpg.mechanic.v1`. Lifecycle читает явные `duration.value`/unit и scope (`combat/session/persistent`), `repeat_rule`, category (по умолчанию definition.type), source_scope (по умолчанию source), cleansable/dispellable (по умолчанию true) и mechanical_parameters. Отсутствующий prepared repeat mode означает refresh, а не stack. Владелец B3 по-прежнему требует repeat_rule в typed definition.

Для stack используется существующий B3 `max_stacks` как canonical cap (`stack_max` в prepared spec). Если KB явно содержит также `stack_max`, значения должны совпасть. `stack_per_apply` обязателен; отсутствие/невалидный increment не становится 1. Для остальных modes начальное число стаков — 1, накопления нет. Длительности — целые 0..2147483647 в `own_turn/target_turn/round`; дробная длительность не округляется в integer column. Явный `duration`/`duration_value` конкретного effect переопределяет срок только вместе с явной duration_unit; alias conflict или неполная пара BLOCKED. При повторе другая единица не конвертируется. Stack на cap также допускает более длинный concrete-effect duration, сохраняя исходную силу/cap/provenance.

Как и в 5.1.1, конкретный effect задаёт B/polarity/trigger; instance polarity обязана совпасть с проверенной probability definition. Для lifecycle scope требуется явно: legacy definition без него BLOCKED. Неподдержанные schemas/fields, condition/application overrides, status callbacks/events/periodic effects и root resistance/formula overrides закрыты до соответствующего исполнителя. Mechanical parameters сохраняются как данные, не становятся автоматически модификаторами характеристик или control restrictions. Existing immutable snapshots не переписываются для добавления полей.

## Cleanse и dispel

Поддержан конкретный pinned KB effect `kind=cleanse|dispel`. Default: `trigger=on_cast`, `chance=100`, один допустимый самый старый экземпляр, полное снятие. Cleanse выбирает negative, dispel — positive. Проверяются typed categories и per-instance cleansable/dispellable; иммунитет не участвует в выборе уже существующих экземпляров и не удаляет их автоматически.

Явные effect parameters:

- `categories:[key,...]` — фильтр, пустой список означает все категории;
- `limit:N` — положительное число экземпляров либо `mass:true` — все допустимые; конфликтующие настройки отвергаются;
- `remove_stacks:N` — частичное снятие с каждого выбранного экземпляра; отсутствие/null — полностью;
- `chance:0..100` — явная вероятность removal operation; для вероятностной массовой операции требуется `chance_scope:operation`. Неизвестный/per-instance scope не угадывается.

Порядок: выбрать разрешённые экземпляры по applied_order/ID, ограничить количество, выполнить сохранённую вероятность, затем удалить/уменьшить stacks. Для частичного снятия срок/order/spec не обновляются; при 0 stacks экземпляр становится removed. Уже выполненные тики не откатываются; callbacks/реакции не запускаются. Для гарантированного снятия отдельного status roll нет. Для chance<100 используется существующий uniform53 server tape: `check_kind=status`, `roll_index=root effect index`, с тем же sealed-action causal key. Нет нового RNG в commit/recovery/GET. Эффекты application/removal одной фазы объединяются по root KB index.

## Publication, preview и scope boundary

Preview/seal изменяют только detached state. Probabilistic preview сохраняет success=null, не выдумывая создание/снятие; детерминированный positive/guaranteed case может изменить только виртуальную копию. SQL rows создаются/меняются исключительно через existing CombatCommitWriter project() или authorized combat-reset command.

Effects rows и их definition snapshots включены в консервативный writer fence до/после preparation и под lock. Dependency versions содержат status_state_hash, поэтому добавление/refresh/removal между seal и commit требует прежней повторной проверки/подтверждения, а не нового броска. CAS эффекта проверяет effect_version; spec, source, scope, refs и applied_order immutable. Status transition, HP/AP, turn close, event/result/receipt/outbox фиксируются одной **существующей** ACS transaction Stage 4 R2. Replay COMMITTED возвращает прежний receipt. Неподтверждённая/неизвестная native publication не применяет реальные статусы.

Scope cleanup подключён к существующему `ClockService combat_reset` → `resetCombatCooldowns`: только после persisted encounter.status=completed и completed_at. В том же command transaction/journal combat effects становятся removed; session/persistent сохраняются. Повтор возвращает прежний boundary receipt и не увеличивает effect_version. Незавершённый старый combat scope другого encounter блокирует preparer как `ACS_COMBAT_RESET_REQUIRED`. Новое завершение encounter/сессии, victory/rewards и межквестовый перенос здесь не добавлены.

Старые public visibility/target restrictions сохранены: QueryService работает с разрешёнными GM-visible single_enemy targets. Ally/self lifecycle и положительные эффекты есть в доверенном Domain context; новый публичный allied/self selector не реализован. Raw instances, pins/сила и private target data не добавлены в публичные result DTO. Combat result хранит server-only full plan и ID mapping; карточка его не пересчитывает.

## Проверки и фактическое evidence

Локальная среда: **PHP 8.4.24 CLI с mysqli**, **MariaDB 11.8.6**, отдельный Unix socket, сервер с `--skip-networking`. Целевые PHP 8.5.12/MySQL и installed MyBB/warprift.ru не проверены. Пакеты извлечены во временный workspace; системная конфигурация и production БД не использовались. Fixtures создают/удаляют собственные random `acs512_*`/`acs222_*` databases.

Выполнены с exit 0 (php — CLI с mysqli/mbstring/ctype):

```sh
php tests/acs_status_instances.php
php tests/acs_status_instances_sql.php /tmp/acs512-socket/isolated.sock
php tests/acs_combat_commit_regression.php /tmp/acs222-socket/status512.sock
php -l <каждый изменённый/новый PHP>
git diff --check
```

Domain/KB fixtures покрывают source separation/shared, max refresh без усиления, independent/reject/extend, stack cap с refresh, partial cleanse без изменения срока, oldest eligible/categories, mass/prohibitions/chance, dispel polarity, scope/units, unknown KB и instance schemas, atomic virtual failure, actual Resolver lifecycle и owner KB→Query projection. Переиспользованы узкие 5.1.1/Effects/Damage/source fixtures; нового framework нет.

SQL fixture проверяет 010 readback/resume/repeat и неизменность 001–009, actual IDs и pinned projection без live KB reads; отсутствие SQL от virtual application; **настоящий TransactionManager rollback** после записи экземпляра/версии сессии и при SQL fault на втором INSERT; stale effect CAS; unknown payload schema без read-repair; реальный existing command/combat-reset journal и replay.

Существующий combat writer regression проходит late terminal/outbox rollback, dependency/CAS conflict, control/permission checks, contention/replay/receipt и неизменный tape; его узкая цепочка включает Preview/Builder/Seal/round fixtures. Он не является installed newreply E2E и не доказывает весь статусный production flow end-to-end. Непосредственное соединение Resolver→status SQL trait и transaction rollback проверено отдельными targeted fixtures. Полный suite и браузерные E2E не запускались.

## Ограничения и ручная проверка

Не добавлены ticks, upkeep, boundary duration decrement/expiry, callbacks, реакции, stat/control execution, cleanse-triggered callbacks, Session close и persistent transfer. Unknown gameplay contracts остаются BLOCKED. JSON/state size limits существующего Repository сохраняются: чрезмерная retained history блокирует переход, а не обрезается. PHP 8.5.12/MySQL 8.0.46 и installed source/active hooks требуют отдельной проверки. ACS-5.2.1 не начинается автоматически.

На **изолированной копии** целевого форума:

1. Сверить applied migration rows/checksums и фактические columns/indexes/engines. Выполнить 010 штатным ACP flow; старые записи 001–009 не изменяются. При неизвестной существующей effects schema остановиться с диагностикой, без ручного destructive repair.
2. Подготовить новые versioned KB definitions со scope/целочисленной duration и явным application contract; для stack — cap/increment. Закрепить snapshots штатным registration/seal flow. Legacy incomplete snapshots не редактировать ради прохождения gate.
3. Снять baseline effects/resources/events/versions. Preview и seal не создают реальных экземпляров. Только permitted test-only publication после подтверждения меняет effects вместе с HP/AP/event/result/receipt. Сверить реальные IDs, virtual→SQL map, pins и version.
4. Два источника → два экземпляра. Повтор источника → max duration без новой силы. Проверить independent/reject/extend; stack достигает cap и всё ещё обновляет разрешённый срок. Default cleanse выбирает один oldest eligible, partial removal не меняет duration, dispel не снимает negative. Проверить category/prohibition/mass/chance по fixtures.
5. На disposable DB инъецировать отказ status INSERT/UPDATE и поздний outbox failure. Не остаётся частичных effects/HP/AP/versions/receipts. Повтор того же submission/tape даёт один подтверждённый результат. При version/dependency drift требуется прежний конфликт/подтверждение, без переброса.
6. После подтверждённого завершения encounter исполнить существующий combat_reset. Если установленный completion flow ещё отсутствует, installed boundary acceptance остаётся BLOCKED: не подменять её ручным SQL; disposable SQL fixture проверяет только сам cleanup command на явно подготовленной completed-записи. Combat removed; session/persistent остаются в той же сессии. Повтор boundary не меняет версии. Открытие страницы/литературный пост не уменьшает remaining_duration.
7. Проверить обычного пользователя/outsider/GM: скрытые refs/параметры не появляются в DTO/карточке. Unknown schema/override/ref возвращает явный BLOCKED; никакого latest fallback, read-repair или автоматического включения publication.
