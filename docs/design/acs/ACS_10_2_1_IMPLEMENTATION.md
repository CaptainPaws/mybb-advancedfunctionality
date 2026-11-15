# ACS-10.2.1 — Immutable Loot Generation и Loot Pool

## Реализовано

A1.34–A1.35, A1.38 на базе `main` b072b94b (ACS-10.1.3, PR #1067).
Прочитаны Master Specification, Technical Architecture, Implementation Roadmap,
Integration Contracts, KB Mechanics Contract и отчёты ACS-10.1.1, ACS-10.1.3,
ACS-9.3.4, ACS-6.3.2. `docs/ROADMAP.md` не изменён. ACS-10.2.2 не начат.

Добавлены Domain/Rewards, LootService, LootPersistence и небольшой backend
controller существующего AF router. Используются EncounterService, существующий
command/OperationStore ledger, snapshot writer, RandomSource, events и rolls.
Второго Resolver, RNG, журнала операций или outbox consumer нет.

Генерация отделена от подтверждения исхода и выполняется явной серверной командой
по verified confirmed outcome. Повтор GM outcome confirmation возвращает прежний
terminal outcome receipt и не вызывает новую генерацию. Pool/results читаются
отдельным loot_status; повтор loot_generate возвращает исходный generation receipt.

## Схема и миграция

Фактический ledger `main` — **023_encounter_resolutions**, добавленный ACS-10.1.1,
а не 022 из первоначальной формулировки задачи. Loot tables отсутствовали.
Следующая свободная resumable migration — **024_loot_pools**. Исторические файлы
и checksums не изменены. Обновлены ordered migration registry и writable guard.

Создаются только loot_pools и loot_entries. Используется архитектурная форма
с generation_key, definition/item snapshot refs, pool_version, mode, status и
будущими nullable allocation/issue полями. Дополнительно pool явно хранит
outcome_id, source_key, generation event_id и result_hash.

UNIQUE generation_key и UNIQUE(encounter_id,source_key) не допускают вторую
генерацию того же источника; confirmed outcome в текущем ledger единственный
на encounter. Ни FK cascade/delete, claims/rewards tables, ни Inventory writes
в миграции нет. Пустой результат сохраняет pool status=empty; непустой — open.
Pool version при генерации 1, entries status=generated, allocation_version=0,
recipient/issue identities остаются NULL.

## KB и источник

Полный opt-in контракт описан в [KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1021--opt-in-immutable-loot-source-v1).
Используется существующий arpg_mechanics/combat_template с marked loot_source,
без изменения legacy bestiary loot[] или глобальной таблицы вероятностей.
KB adapter получил owner-authorized arpg_item read и exact graph acquisition.

loot_configure разрешён только GM в active session / preparation encounter,
round_number=0, без start intent. Поддержаны:

| Источник | Неизменяемая identity |
| --- | --- |
| Конкретный зарегистрированный побеждённый monster | monster:<entity_id>, с pin исходного entity snapshot |
| Столкновение | encounter |
| Разрешённая самостоятельная KB таблица | kb:<table-key> |

Версии таблицы и всех item definitions проверяются и сохраняются до боя.
Повторное закрепление того же источника не заменяет версию, а блокируется.
После outcome невозможно добавить источник для нового roll.
Legacy/неполные правила, unsupported fields, вложенные таблицы, random qty,
formulas и contribution branches не получают придуманных default mechanics.
Динамически возникший источник без предварительного pin не backfill-ится.

Гарантированный режим возвращает все rows без RNG. Independent использует одну
проверку P на row, включая сохранённые P0/P100 samples. Weighted выполняет ровно
KB draws с KB weights и явной replacement policy. Quantity фиксируется KB.
Luck не читается при генерации; non-null Luck modifier в v1 блокируется.

## Eligibility и исход

Для encounter с loot sources EndConditionPersistence сохраняет eligibility facts
в том же pending-resolution candidate после полного Resolver result, child
executions и before GM confirmation. Этот helper используется также существующим
CombatCommitWriter; старые encounters без loot sources не меняют candidate shape.

Фиксируются зарегистрированные до end boundary participants, control groups,
group owner/version, registration sequence/version, entity kind/source/snapshot,
side, participation и life_state. GM confirmation повторно проверяет candidate
существующим механизмом. При генерации используются candidate/hash и pinned config
из verified terminal outcome; текущий roster/контроллер не является eligibility.

Достаточно официальной регистрации базового участника; damage/action contribution
не требуется. Group dedupe не создаёт дополнительной базовой доли pet/summon или
source-derived entity и не умножает число drops. Dead/incapacitated, surrender,
escape, withdrawal/capture, сторона и outcome учитываются только явными KB lists.
Monster source дополнительно требует подтверждённую противоположную hostile side
и соответствие pinned source identity. Mutual incapacitation не создаёт winner.

## Atomicity, replay и права

loot_configure/loot_generate — POST, CSRF, current actor, native thread permission,
сохранённый session GM/owner, reason, operation_key и session/encounter CAS.
Глобального ACP bypass для loot нет. HTTP не принимает table JSON, seed, samples,
item definition, qty, winner, eligibility или готовые generated entries.

SessionRepository::command получил code-only existing-result read callback после
проверки прав/конфликта operation key и до CAS/RNG. Только LootService использует
его для source-level replay: другой ключ либо stale expected version не создаёт
новую generation identity и audit. Исходный key с другой нагрузкой всё ещё conflict.
Существующий command boundary сохраняет audit, receipt и CAS одним commit.

Generation key строится из session/encounter/confirmed outcome/source. uniform53
samples создаются существующим RandomSource, сохраняются в rolls с отдельными
loot causal keys и rng_version. Sealed attack rolls не используются повторно.
Pool, entries, item snapshots, rolls, generation event/hash, operation receipt и
CAS входят в одну ACS transaction. Ошибка позднего audit insert откатывает всё;
rolled-back попытка не является подтверждённой генерацией. Потерянный commit ACK
восстанавливается прежним receipt без нового RNG.

Saved result содержит source/pinned table version, outcome identity и event refs,
eligibility/hash, samples/roll IDs, entry IDs/qty/item refs и generation event.
Чтение pool и source-level replay проверяют result hash, scope identities, entries, item snapshot hashes,
roll rows и сохранённый operation/event audit. Pure tape replay не пишет в БД.

## Lifecycle и граница выдачи

Подтверждённый configured source без pool блокирует close до явной генерации,
включая пустой результат при невыполненном KB condition. Open pool добавлен в
SessionObligationsQuery как pending с разрешённым loot.pool.inspect scope.
Close/archive сохраняют таблицы, snapshots, events, rolls и receipts. GM может
читать pool после archive; новое исполнение генерации вне active session запрещено,
а уже существующий generation receipt доступен без повторения механики.
Reopen не меняет pool/entries, outcome, recipients или rolls.

Generated entry не является Inventory instance/ownership или custody. Код не
вызывает af_inv_add_item, owner grant, Shop checkout, выдачу EXP/валюты или consumer.
Allocation/claims/issue и урегулирование unallocated pool — следующий отдельный
этап; сейчас он сохраняется и доступен для чтения, без фиктивного issued.

## Проверки

Disposable MySQL 8.0.46 и PHP 8.4.27, 64-bit mysqli/mysqlnd + mbstring.
PHP 8.5.12 отдельно NOT_VERIFIED. Production DB не использовалась.
Полный test suite не запускался.

```text
php -l <все новые/изменённые PHP-файлы>
git diff --check
php tests/acs_loot_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_end_conditions_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_session_lifecycle_sql.php /tmp/acs222-socket/mysql8.sock
```

PASS: guaranteed; independent success/failure и P0/P100; weighted cumulative
boundary, KB draw count и no replacement; pinned versions при отключённой latest
KB; duplicate key/source generation, stale source replay, saved tape replay;
no global Luck; pet/summon group facts не добавляют долю; Inventory tables и
combat/sealed history неизменны; preparation/pending unconfirmed denied;
GM/POST/CSRF; inactive session denied; второй competing SQL writer; rollback включая уже записанные random rolls; lost ACK без нового
roll; повтор GM confirmation; pools после close/archive/reopen; repeat migration.

Fixture использует настоящий бой через Resolver и GM confirmation. Pet/summon
проверяются как frozen eligibility facts в pure contract, не как новый summon
lifecycle. До generation current Luck изменён только в тестовой SQL записи;
generation не читает эту характеристику. Резюмирование 024 проверено с первой
таблицей уже существующей и второй отсутствующей; затем повтор на заполненном
архиве сохраняет полный SQL snapshot. End-condition и session lifecycle fixtures
прошли отдельно, включая прежний concurrent GM/rollback/ACK coverage.
