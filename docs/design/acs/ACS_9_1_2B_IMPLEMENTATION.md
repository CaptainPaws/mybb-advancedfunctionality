# ACS-9.1.2B — Prepared owner-authorized trigger consumption

База main: `c8d2024a` (merge PR #1054 / ACS-9.1.2A).
Один ограниченный Security/Inventory Integration PR. `docs/ROADMAP.md`,
ACS migrations 001–021, MyBB Core, CharacterSheets, AdvancedAppearance,
исторические implementation reports и publication flags не изменены.
ACS-9.2.1 не начат.

## Источники

Прочитаны [Master](ACS_MASTER_SPECIFICATION.md),
[Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics](../../contracts/ACS_KB_MECHANICS_CONTRACT.md),
[IC-INV-1](../../contracts/ACS_INVENTORY_OWNER_PROTOCOL.md), отчёты
[6.1.2](ACS_6_1_2_IMPLEMENTATION.md), [6.2.2](ACS_6_2_2_IMPLEMENTATION.md),
[6.3.2](ACS_6_3_2_IMPLEMENTATION.md), [9.1.1B](ACS_9_1_1B_IMPLEMENTATION.md),
[9.1.2](ACS_9_1_2_IMPLEMENTATION.md), [9.1.2A](ACS_9_1_2A_IMPLEMENTATION.md).

## Implemented — точный authorization contract

Новый internal subset `prepared_reserved_consume.v1` расширяет прежний
AdvancedInventory OwnerProtocol. Quantity engine, existing owner receipts и
reservation identities остаются прежними; второго Inventory/owner ledger нет.

При confirmed prepared publication, после проверки source seal и on_prepare
оплаты, создаётся prepared ID. **Сам authenticated owner** с POST/CSRF, текущим
owner controller/session membership и независимым inventory_spending_enabled
выдаёт binding оставшегося on_trigger reservation. Выдача происходит в той же
transaction, что AP/resources/prepared registration; не при GET/preview и не
самостоятельным COMMIT. У source event после commit остаётся UID владельца.

Owner хранит immutable canonical binding/hash прямо в существующей reservation:
owner_uid, originating_uid, session/encounter/entity/prepared/seal IDs,
cost index/key, qty, item ID/fingerprint, exact KB item definition и ability pin,
owner inventory version, reservation ID/version, исходный reserve receipt,
base prepared contract hash и независимо вычисленный owner item digest.
Receipt с binding_hash/grant_generation сохраняется в
prepared contract.owner_obligations[cost_index]. Это ссылка на owner-owned
SQL record, не bearer token. Несколько costs имеют независимые bindings.
Повторное связывание с другим содержимым запрещено; нового reserve нет.

На trigger PreparedItemService строит только server intent по прежнему ключу
`acs:prepared:trigger-consume:PREPARED:TRIGGER:COST_INDEX` и точному reservation.
`authorization_ref=acs-prepared:PREPARED:TRIGGER:COST_INDEX`. Клиент не передаёт
эти envelopes/owner claims/capability. Нового HTTP endpoint нет.

OwnerProtocol проверяет собственный binding/hash, reserve receipt, remaining qty,
reservation version/scope, current owner inventory version и реальный item
owner/kind/digest. Текущая Stage-6 composition реализует отдельный
AF_ADVINV_PreparedOwnerPolicy: owner engine вызывает её для SQL revalidation
prepared/cost/pins, original owner registration event, armed execution/version,
trigger event/hash и frozen parent seal/targets. Authenticated runtime UID
должен совпасть с author этой конкретной parent publication; одного GM role
или знания ID недостаточно. Проверяются текущие membership и owner grant.

Фактические phase/conditions/virtual state по-прежнему проверяет trusted
9.1.2A pipeline перед owner participation. Metadata-only trigger_sealed не
выдаётся за самостоятельное mechanical confirmation; owner API не является
публичным способом исполнить trigger. Legacy ordinary item endpoints не
открывают этот private prepared scope даже с правильным authorization_ref.

## Отзыв, generation и expiry

Owner policy повторно читает **живые** SQL данные, а не доверяет ACS-флагу
«раньше было разрешено» или старому receipt. grant_generation — hash:

- owner/source identity;
- owner controller ID, assignment_version и permissions;
- действующих session roles с role_version и expires_at.

Current grant должен иметь combat_operations_enabled и отдельный
inventory_spending_enabled, действующий controller без temporary overlay и
активный source. Выключение spending, reassignment/revocation, смена version,
role revision/expiry или изменение generation запрещают будущий consume.
Существующие versioned controller/role commands остаются способом отзыва;
нового публичного grant/revoke API для trigger actor нет. Старый binding не
перевыдаётся и не обновляется автоматически при новой generation. Прямые SQL
ACL изменения вне versioned owner/session protocol не являются новым API.

| Проверяемое состояние | Доказательство / поведение |
|---|---|
| active | Подтверждённая active/paid preparation, armed execution, тот же live grant generation и active exact reservation |
| revoked | Owner grant выключен/не действует либо generation не совпала; INV_PREPARED_REVOKED, без consume |
| expired | Prepared terminal expired/cancelled или больше не active/paid; dispatch/owner mutation запрещены |
| consumed | Exact owner consume receipt и terminal reservation; повтор не создаёт новую оплату |
| recovery_required | Unknown/orphan owner outcome или ACS parent barrier; прежняя reservation/history сохраняются, автоматического release/retry нет |

Consumed owner state и ACS recovery state различаются: подтверждённый orphan
owner receipt не является подтверждением ACS mechanics. Он сохраняет parent
quarantine до разрешённого recovery. Последующий revoke не отменяет прежний
successful consume. Exact historical lookup возвращает старый owner receipt
тому же trigger author при membership даже после revoke / parent recovery;
он не выдаёт новых spending rights.

## Отсутствие impersonation и atomic participation

Current MyBB UID всегда остаётся Б. Обычный AF_ADVINV_OwnerAccess object и его
uid() не заменяются; session owner/control assignments не меняются. Owner
consume receipt содержит реальный actor_uid Б, а binding отдельно содержит
owner/originating UID А. У Б не появляется обычного reserve/consume/lookup,
owner spending grant или права переиспользовать capability для другой операции.

После проверки owner evidence OwnerProtocol открывает **private scope одного
exact intent**, вызывает existing participate('consume', intent), затем очищает
scope в finally. Identity check сравнивает полный canonical intent; операция
не может стать release/grant/transfer/другим consume. Только существующая
same-writer real InnoDB transaction; standalone prepared COMMIT API нет.
Ни UID swapping, ни привилегированный OwnerAccess для передачи наружу не созданы.

ACS не делает UPDATE qty и не вызывает remove_item. Все qty/resource/AP/owner
receipt/prepared execution/event/terminal receipt writes входят в прежнюю
ACS transaction. Child savepoint и outer rollback сохраняют прежние гарантии.
Для нескольких costs сначала проверяются все bindings; затем owner version
продвигается только по проверенной непрерывной цепочке собственных provisional
receipts того же prepared/trigger/actor. Посторонняя Inventory mutation не
маскируется таким продвижением.

Lookup того же owner key предшествует новой mutation. Existing receipt без
подтверждённого parent result, неверный binding/reservation/ownership/schema
или outcome integrity — fatal для parent, не ordinary optional skip.
Known revoked/expired/version-conflict используют лишь прежнюю pinned KB
retain/cancel policy. Release остаётся прежним owner-authorized 9.1.1B path;
чужому trigger actor не даётся право автоматически освобождать имущество.

## Owner schema upgrade

Необходимы только две nullable колонки существующей
`af_advinv_reservations`: prepared_binding_json LONGTEXT и
prepared_binding_hash CHAR(64) ascii_bin. Новых таблиц/ledger нет.
OwnerSchema::statements включает их для новой установки. Для существующей
owner schema добавлен **явный operator-only** helper
`AF_ADVINV_OwnerSchema::upgradePrepared($authoritativeMysqli, TABLE_PREFIX)`.

Helper проверяет отсутствие ambient transaction до DDL и добавляет только
отсутствующие колонки, поэтому повтор/partial resume сохраняет данные.
Request/activate не вызывают DDL. Missing extension fail closed при новой
item preparation; обычные owner operations продолжают поддерживать старую
schema. Старые ACS migrations/checksums не переписываются. Автоматического
backfill capabilities для старых reservations нет.

## Locally verified

PHP **8.5.12** / disposable MySQL **8.0.46**, существующий authoritative owner/ACS
fixture harness и `/tmp/acs222-socket/mysql8.sock`. Real writer connections,
два authenticated request contexts А=7 / Б=8; no production database/Gateway.
Native PID 501 заранее задан fixture; SQL databases удаляются в finally.

Основной fixture: `tests/acs_prepared_owner_sql.php SOCKET MODE`.

| Проверка | Локальное доказательство |
|---|---|
| А подготовил, Б вызвал trigger | normal: owner-issued binding А, consume receipt actor_uid Б, один расход, исходный UID не подменён — PASS |
| Нет новых прав Б | Обычные lookup/consume, item_consume category без grant и попытка bind от Б запрещены; narrow ref не работает через ordinary consume — PASS |
| Подмена item/qty/key | Extra item_id, другая reservation, qty+1 и другой operation key отвергаются без writes — PASS |
| Unauthorized GM / delegate | GM9 не author parent; реальная combat-only delegation Б не даёт ordinary item rights — PASS |
| Revoke / generation | revoked и generation: owner-side deny при active preparation, unused reservation остаётся — PASS |
| Expiry / changed ownership | expired не dispatch; ownership integrity failure откатывает parent целиком — PASS |
| Duplicate / distinct causes | normal и multiple: durable receipt повторён; второй отдельный trigger не расходует item снова — PASS |
| Два concurrent request | race: два независимых PHP/request/SQL contexts Б, owner fence; один commit/consume/execution, второй получает receipt либо штатный BUSY/version conflict и reconciles через durable terminal lookup — PASS |
| Lost ACK / late rollback | Реальный outer COMMIT + потеря ответа; RecoveryService возвращает terminal receipt. Outbox fault после owner consume откатывает owner и ACS, сохраняет armed rolls — PASS |
| Orphan owner receipt | orphan: parent recovery_required, exact historical owner lookup сохранён; нет blind consume/release — PASS |
| Несколько item costs | multi: две независимые on_trigger reservations/receipts, непрерывная owner-version chain — PASS |
| Inventory unavailable | Composition fail closed; нет подтверждённого consume/потери item — PASS |
| Normal same-actor | acs_prepared_execution_sql.php: прежний AP/resource/item once и ACK recovery — PASS |
| Schema repeat/resume | schema: missing hash column блокирует binding; upgrade повторён после partial schema без изменения собственности — PASS |
| Совместимость | acs_prepared_items_sql.php, acs_combat_items_sql.php и acs_prepared_phases_sql.php multitarget before_hit — PASS |

PHP lint изменённых/новых PHP и git diff --check — PASS. JS не изменён,
node --check не требовался. Полный suite и старый baseline-broken intercept
harness не запускались; его прежний сбой описан в отчёте 9.1.2A, не исправлялся.

## Remaining BLOCKED / UNSUPPORTED

Нет owner schema extension / specialised current owner policy — BLOCKED.
Legacy contracts без owner_obligations не получают новых полномочий: прежний
same-actor путь сохраняется, cross-author trigger cost остаётся BLOCKED.
Issuance за другого owner, делегатом без owner item rights, GM impersonation,
временный custody user, remote/multi-DB owner и automatic item replacement
не поддержаны. Неподдержанные KB item kinds/operations остаются UNSUPPORTED.

Этот PR не расширяет targeting, KB phases, recursive prepared chains,
derived-event triggers, автоматический reveal или другие механики ACS-9.2.1.
Нового интерфейса редактора/Inventory grants для пользователя нет.

## Installed NOT_VERIFIED

warprift.ru / MyBB 1.8.41: фактический owner schema upgrade, native permissions,
все production grant/revoke routes, engines/hooks, темы/ACP и acceptance
Stage 4 R2/Stage 6 не проверены. Local SQL PASS не означает installed READY.
До явного owner upgrade/приёмки новая ветка fail closed. Publication flags не
включались; общей MyBB+ACS transaction нет.
