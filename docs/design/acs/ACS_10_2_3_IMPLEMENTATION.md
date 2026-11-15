# ACS-10.2.3 — Safe Loot Issue through AdvancedInventory Grant

## Реализовано и ограничения профиля

База — `main` 6d9ac4a9, merge ACS-10.2.2 (#1069). Требования A1.34–A1.36.
Прочитаны нормативные Master, Architecture, Roadmap, Integration Contracts,
ACS_INVENTORY_OWNER_PROTOCOL и отчёты ACS-6.1.3, ACS-6.3.2, ACS-10.2.1,
ACS-10.2.2. `docs/ROADMAP.md` не изменён. ACS-10.3.1 не начат.

Выдача closed allocation реализована через прежний standalone owner grant.
**Поддержан действующий owner profile consumable/ammo.** Остальные kinds не
создаются обходным способом. Нет прямого ACS INSERT в Inventory items,
af_inv_add_item, Shop checkout, EXP/currency, consumer или изменения владельца
другого предмета. Новой миграции нет: актуальный ledger остаётся 025_loot_claims.
Используются существующие issue_operation_key и UNIQUE loot_issue_once,
OperationStore, InventoryOperationService, InventoryAdapter и RecoveryService.

## Сохранённая identity

Сервер, не HTTP caller, закрепляет:

- owner operation key `acs:loot:issue:<entry>:<allocation_version>`;
- authorization ref `acs-loot-issue:<encounter>:<entry>:<allocation_version>`;
- provenance `acs / loot-entry:<entry>`, независимую от recipient/new client key;
- session/pool/entry, verified outcome/generation/allocation refs, winner UID;
- item snapshot/qty, точный owner normalized_item, его meta bytes/version/hash;
- canonical envelope/hash, issuer UID, owner receipt и ACS acknowledgement events.

Generated JSON, item snapshots, allocation receipt/closed claims, recipient и
allocation_version не переписываются. Inventory получает ровно qty entry.
Retry не выбирает нового recipient, не создаёт новый owner key или новый roll.

## Строгая authorization policy

AF_ADVINV_ExactPropertyAccess — optional code-only дополнение owner contract.
PropertyOperations вызывает full-envelope check до BEGIN и под owner guard;
OwnerProtocol использует его также для preflight и lookup. Default MyBB owner
policy остаётся grant-denied. Никакого client validated=true/admin bypass нет.

AF_ACS_LootGrantAccess принимает только canonical envelope из сохранённой операции,
связанный hash с immutable loot.issue_prepare audit. Проверяются:

1. Verified confirmed outcome и сохранённый generation result, item pins/rolls.
2. Closed allocation receipt, winner UID/group, allocation version, claims и tie.
3. Exact key/ref/scope/actor/provenance/qty/normalized item и отсутствие issued.
4. Native thread access перед owner boundary, current authenticated issuer и
   сохранённый session GM/owner; роли повторно читаются under owner guard.
5. Pending entry и prepared/recovery_required dispatch intent для grant;
   recovery adapter отдельно запрещает mutations.

Боевые controller/delegation, владение Inventory или известный operation key не
дают mint authority. Только первоначальный issuer с текущими GM/native правами
может продолжить intent. Другой GM не подставляется как actor старого owner request.

Нормализация использует существующий KB normalizer. Текущая exact KB row должна
совпасть semantic fingerprint/declared version с retained item snapshot; metadata
должна быть только `{rules:...}` с тем же fingerprint. Сохраняются точные meta_json
bytes и owner hash/version `sha256:<metadata hash>`. Это отдельная owner identity,
не замена исходного KB pin. Latest KB не перепинивается на retry.

Неподдержанные kinds/metadata или изменённая definition блокируются с
ACS_LOOT_GRANT_KIND_UNSUPPORTED / ACS_LOOT_OWNER_METADATA_UNSUPPORTED /
ACS_LOOT_OWNER_DEFINITION_CHANGED. KB unavailable блокируется отдельно.
Старый winner остаётся awaiting_issue, если intent ещё нельзя подготовить;
после prepare остаётся pending_issue. Owner дополнительно проверяет exact bytes
под своим KB read lock и может вернуть durable DEFINITION_CONFLICT.

## Транзакции и acknowledgement

Общая ACS + Inventory + MyBB transaction **не заявляется**, в том числе когда
используется один физический mysqli handle. Границы последовательные:

1. ACS command/session lock/CAS проверяет allocation, фиксирует pending_issue,
   issue key, prepared inventory.grant envelope и immutable preparation audit.
   OperationStore::inventoryRegisterLoot допускает только этот typed internal scope
   внутри уже существующей ACS transaction.
2. После ACS COMMIT проверяется external boundary. InventoryOperationService
   CAS-ит prepared → recovery_required до owner dispatch. Конкурентная попытка
   не получает второе право dispatch. Вновь создаваемый generic grant вне active
   session по-прежнему запрещён; scoped loot intent уже durable.
3. OwnerProtocol/PropertyOperations выполняют собственный grant transaction:
   owner guards, source provenance dedupe, item matcher/qty/version и receipt.
   Owner policy читает ACS proofs без row/advisory locks; native callbacks
   under owner lock не вызываются. MyBB post transaction отсутствует.
4. Owner receipt проверяется существующим validator и дополнительными loot
   проверками provenance/qty/item identity/inventory version именно winner UID.
   InventoryOperationService/OperationStore сохраняют acknowledgement через CAS.
5. Отдельный loot.issue_ack сверяет прежний intent/receipt и CAS entry с тем же
   allocation/winner/key. Только committed grant даёт issued; owner rejection
   даёт issue_rejected. Event/entry/pool version и session CAS атомарны в ACS.

Ошибка позднего ACS acknowledgement не откатывает уже совершённый owner COMMIT.
При rollback owner acknowledgement остаётся recovery_required; при rollback
entry acknowledgement сохранён committed owner receipt, но entry ещё pending.
Lookup/reconcile завершает прежний intent, не вызывает grant повторно.

Generic Inventory recovery может сохранить owner acknowledgement, не объявляя
loot issued. RecoveryService::recoverLoot выполняет scoped entry reconciliation.
Полностью issued pool получает status=issued; unissued/rejected entry сохраняет
pool outstanding. Старые allocation receipts/winner reservation остаются неизменными.

## Replay, отказ и lifecycle

Повтор client request либо новый client key для того же entry/allocated version
возвращает прежний issue intent. Full envelope и первоначальный issuer сохраняются.
Исходный client key с другой нагрузкой — conflict. При unknown owner outcome
возможен только lookup прежнего owner key. `not_found`/unavailable сохраняют barrier,
не означают успешную выдачу или разрешение нового grant key.

Если owner ещё не вызывался (prepared marker), восстановившаяся зависимость может
выполнить первый dispatch того же intent. После dispatch marker blind retry нет.
Durable rejected не переотправляется, даже если KB восстановлена; нужен отдельный
разрешённый correction protocol. Он не добавлен этим PR.

Owner grant_sources независимо блокирует ту же provenance под новым key/recipient.
Нельзя автоматически снять winner reservation при ошибке или дать предмет другой
группе. Удаление/расход выданного item не отменяет исторический owner receipt.

Closed/archived scope работает по тем же explicit GM/native gates, без reset
session или completed encounter. Проверено реальное owner write из обоих состояний.
Owner schema/addon unavailable, revoked права или original issuer mismatch оставляют
unfulfilled obligation; error code возвращается без выдуманного issued. Схема owner
автоматически не устанавливается/не обновляется, maintenance bypass не добавлен.

## API

Через прежний AF router:

| op | Вход |
| --- | --- |
| loot_issue (POST) | session_id, encounter_id, entry_id, expected_version, expected_allocation_version, operation_key, reason |
| loot_issue_status (GET) | session_id, encounter_id, entry_id |
| loot_issue_recover (POST) | session_id, encounter_id, entry_id, expected_operation_version |

Все mutations проверяют native CSRF. Recover использует уже закреплённый owner key,
а не новый client envelope. Неизвестные actor/recipient/qty/definition/provenance
поля отклоняются. Status доступен текущему session GM и не создаёт intent/owner
state. Issued/issue_rejected, operation version, owner receipt и recovery code/state
возвращаются отдельно от результата allocation. UI полного этапа 11 не добавлен.

## Проверки

PHP 8.4.27, MySQL 8.0.46, 64-bit mysqli/mysqlnd + mbstring; disposable databases,
Unix socket, удаление в finally. Production DB не использовалась. PHP 8.5.12,
installed MyBB/Inventory lifecycle и browser routing отдельно NOT_VERIFIED.
Полный test suite не запускался.

```text
php -l <все новые/изменённые PHP-файлы>
git diff --check
php tests/acs_loot_issue_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_inventory_recovery_sql.php /tmp/acs222-socket/mysql8.sock
ACS_INV_FIXTURE_SOCKET=/tmp/acs222-socket/mysql8.sock php tests/acs_inventory_property_sql.php
```

Новый fixture продолжает реальный Resolver → GM outcome → generation → allocation.
Имеется только optional continuation hook прежнего allocation fixture. Стартовый
closed state и KB type profile seeded SQL; переход в archive — настоящий lifecycle
command с pending issue. Старые throttle timestamps aged между фазами fixture,
runtime rate limit не изменён.

PASS: allocated→issued; duplicate issue и новый client key; два SQL requests в
окне после intent COMMIT; changed payload/recipient/key; default owner grant deny;
recovery-only adapter grant deny; другой GM не заменяет issuer; unavailable
Inventory; closed pending issue → archive → первый dispatch; real owner rejection;
same provenance/new key; reserved winner/rolls/claims/snapshots unchanged;
lost owner ACK; forged self-consistent receipt qty rejected; generic Inventory
recovery + typed loot reconcile; rollback обоих ACS acknowledgement phases;
реальный RecoveryService и сохранность после повторной migration.

Regression PASS: существующий Inventory recovery и owner property fixture,
включая transfer/grant/provenance, lost outcome lookup и обычный Shop checkout.
Shop не используется для новой loot issue. В ACS нет прямого Inventory INSERT.
