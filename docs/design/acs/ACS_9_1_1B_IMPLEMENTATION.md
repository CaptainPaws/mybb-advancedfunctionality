# ACS-9.1.1B — Prepared item costs и retained reservations

База main: `c8247488` (merge ACS-9.1.1 / PR #1051). Один Integration PR.
Исторический [отчёт 9.1.1](ACS_9_1_1_IMPLEMENTATION.md), документы этапа 6,
миграции 001–021 и `docs/ROADMAP.md` не изменены. ACS-9.1.2 не реализован.

## Источники

A1.75 и связанные положения [Master](ACS_MASTER_SPECIFICATION.md),
[Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics](../../contracts/ACS_KB_MECHANICS_CONTRACT.md),
отчёты [9.1.1](ACS_9_1_1_IMPLEMENTATION.md),
[6.1.2](ACS_6_1_2_IMPLEMENTATION.md), [6.1.3](ACS_6_1_3_IMPLEMENTATION.md),
[6.2.2](ACS_6_2_2_IMPLEMENTATION.md), [6.3.2](ACS_6_3_2_IMPLEMENTATION.md).

## Implemented: kind=item subset

Снят безусловный запрет item costs только для equipment consumable/ammo
с полным KB pin и selection=explicit. amount — положительное целое до 1000000;
on_prepare/on_trigger — явные timing. Предмет расходуется как стоимость;
побочные on_use эффекты не исполняются. Нет нового Inventory protocol/adapter,
таблицы количеств, MyBB Core/CharacterSheets/Appearance изменений или включения
publication_enabled. Новая миграция не требуется.

PreparedItemService читает existing authoritative InventoryRead, проверяет
source character, authenticated owner, current controller/session/native gates,
независимый spending grant, scope/custody/pending operations, item kind,
KB definition/version/fingerprint и owner version. Поддержан только собственный
Inventory. GM/delegate не получают нового права на чужое имущество.

Existing Builder выводит отдельный selector конкретного Inventory ID для каждого
item cost, с qty/timing. Клиент передаёт только cost index и item ID в
parameters.prepared_items; owner/qty/pins/receipts не принимает HTTP. Полный wire
и descriptor описаны в новом разделе KB Mechanics. Название не является identity.

## Retained item binding и seal

По каждому cost index в immutable sealed definition сохраняются:

- cost_key, definition (arpg_item key/declared version/fingerprint),
  immutable definition_payload и подтверждённый item_kind;
- item_id, owner_uid, qty, timing, mechanical item fingerprint;
- inventory_version после полного набора reserves;
- полный reserve_receipt, включая reservation.id/version, actor/scope,
  operation_key, payload_hash/result_hash и owner before/after versions.

Несколько costs на одном stack имеют независимые reserves и проверку суммарного
доступного количества. Нельзя автоматически заменить утраченный экземпляр.

Seal берёт существующий owner fence, затем вызывает ItemActionService::reserve
→ InventoryAdapter::forCombat → OwnerProtocol::participate. reserve обобщён
с прежнего qty=1 на явный положительный qty; обычные callers сохраняют default=1.
Stable key: acs:prepared:reserve:<draft_id>:<history_index>:cost:<cost_index>.
Связь с реальным seal закрепляется его immutable snapshot. Повтор seal key
возвращает прежний ACS receipt без нового reserve. Owned qty не уменьшается;
резерв виден всем сессиям и обычным Inventory writers.

Seal confirmation включает retained bindings и owner version. Изменение quantity,
version, KB или spending grant блокирует publish и сохраняет draft/seal/tape.
Отмена неопубликованного seal использует existing same-writer owner release;
при ошибке весь cancel_tail откатывается, а sealed obligation остаётся.

## on_prepare consumption и atomic participation

Prepared publication повторно читает binding под owner fence на том же mysqli
writer, сравнивает сохранённую owner version/fingerprint/KB pin и reservation,
а исходный reserve receipt подтверждает через owner lookupParticipant.
Consume key: acs:prepared:consume:<sealed_action_id>:<cost_index>.
Orphan owner result не используется как разрешение повторной механики.

В одной реальной InnoDB transaction фиксируются owner consume/receipt,
AP/resource costs, prepared contract, закрытие хода, EntityTurn boundaries,
source event/details и terminal ACS operation receipt. Owner participant
возвращает provisional serverReceipt, не делает своего COMMIT и не создаёт
другого connection. Результат выходит наружу после outer COMMIT.
Late SQL failure откатывает весь набор. После реального COMMIT с потерянным ACK
повтор прежнего operation_key возвращает подтверждённый ACS receipt и сохранённый
owner receipt, без нового consume/post. Обычный af_inv_remove_item не вызывается.

## on_trigger pending reservation

Все on_trigger items резервируются при seal и остаются protected после
регистрации. Prepared contract хранит исходный sealed binding, pending timing,
on_prepare consume receipts и item_inventory_version после собственной оплаты.
Нельзя успешно подтвердить незарезервированную cost.

Internal preparedTriggerItems проверяет active/paid, contract hash, исходный
sealed snapshot/hash и точное соответствие cost/binding. Возвращает только
сохранённые instance/reservation IDs с qty/timing/pins и prepared_inventory_version.
Terminal preparation этот handoff не выдаёт. Trigger executor обязан выполнить
актуальные проверки и оплату в 9.1.2; здесь нет matching, trigger RNG, нового
reserve или on_trigger consume.

## Cancel/expiry и recovery obligations

Cancelled/expired history, paid AP/resources и sealed tape сохраняются.
Обрабатываются только on_trigger reservations: consumed on_prepare qty не
возвращается. Successful release receipt записан в существующий operations
journal с indexed prepared.release identity. Общий cost_state становится settled.

Release разрешён только текущим owner-side permission. GM Clock boundary не
имперсонирует исходного владельца. Если grant/Inventory недоступны, owner отказал
или release не подтверждён, локальный savepoint откатывает попытку release,
глобальный резерв остаётся active, а terminal preparation и scoped operation
получают recovery_required. Сессия сохраняет existing obligations barrier.
При потере самого writer невозможность rollback/savepoint передаётся наружу;
фиктивного durable результата нет.

POST/CSRF prepared_release → RecoveryService::recoverPreparedItems — явное owner
recovery через прежнюю SessionRepository
command boundary. Тот же stable owner key сначала проверяется lookupParticipant;
только подтверждённый not_found допускает тот же release. Новый резерв/key или
перебор предметов не создаётся. Replay cancel/expiry/recovery не повторяет расход.
Builder показывает owner recovery command с сохранением pending request/key;
существующая Inventory obligations panel перечисляет prepared.release barriers
без raw contracts. Это same-writer recovery, не новый внешний owner dispatcher.

## Locally verified

**PHP 8.5.12 / disposable MySQL 8.0.46**, official CLI/container, network=none,
mysqli/mysqlnd, socket /tmp/acs222-socket/mysql8.sock. Случайные DB удаляются в
finally. Production credentials/DB не использовались. Native post fixtures —
MyISAM; Gateway не вызывается.

`tests/acs_prepared_items_sql.php SOCKET` и вариант `SOCKET ammo` — PASS:

| Проверки | Доказательство |
| --- | --- |
| 1–2 | Явные pinned consumable/ammo costs; weapon selection UNSUPPORTED |
| 3–4 | Два indexed reserves; duplicate seal возвращает прежний receipt/qty |
| 5 | Вторая настоящая ACS session/character не резервирует protected qty=1 |
| 6–7 | on_prepare qty=2 consumed один раз; on_trigger qty=1 остаётся active reserve |
| 8–9 | Cancel и настоящая EntityTurn expiry освобождают только unused reserve; consumed qty/AP/resources не возвращаются |
| 10 | Duplicate publish возвращает буквальный сохранённый receipt без второго consume |
| 11–13 | Отзыв spending grant, concurrent before-BEGIN owner version mutation, live KB outage и insufficient qty блокируют прежний seal |
| 14 | Два costs на разных instances и одном stack имеют отдельные reservation IDs |
| 15 | SQL trigger после owner consume, при INSERT ACS event_details: owner+ACS capture полностью совпадает с состоянием до попытки |
| 16 | Реальный успешный COMMIT, затем mysqli ACK exception: publish и cancel replay получают исходные confirmed receipts |
| 17 | Owner release SQL refusal откатывается savepoint, сохраняет active reservation/recovery barrier; explicit owner lookup/not_found/release завершает obligation. Revoked release permission также сохраняет reserve |
| 18 | Existing acs_combat_items_sql.php — PASS |

Дополнительно existing acs_object_interaction_sql.php — PASS: presence/consumes
tools, repeated-key independent reservations, permissions, outage и late rollback.
Existing acs_prepared_actions_sql.php — PASS: прежние resource/AP/lifecycle gates.
PHP lint изменённых/новых PHP, node --check только изменённого builder.js,
git diff --check — PASS. Полный suite и browser E2E не запускались.

## Remaining BLOCKED / UNSUPPORTED

Чужие owner items (включая расход по delegation), equipment/Appearance и прочие
item kinds, неполный KB pin, произвольные selector/callback, external/multi-writer
owner participation и автоматическая замена Inventory ID остаются закрытыми.
При отозванных owner permissions release ожидает восстановления явных прав;
GM не получает обхода. В closed/archived session существующая owner policy
не разрешает mutation; такие обязательства сохраняются до разрешённого lifecycle
восстановления, без автоматического возврата имущества. Полная недоступность DB
не подменяется not_found.

Trigger-time consume/RNG/execution receipts/cycle guards — ACS-9.1.2, не этот PR.
Безопасный on_trigger retained binding реализован; фактическое исполнение ещё нет.

## Installed NOT_VERIFIED

warprift.ru / MyBB 1.8.41: actual schema/engines/routing, hooks, ACL/grants,
ACP lifecycle, другие addons, тема и mobile/browser flows не проверены.
Локальные SQL PASS не являются installed acceptance. Сохраняются gates этапа 6
и Stage 4 Revision 2; production publication flags не изменялись.
