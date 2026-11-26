# INV-ACS-01 — Подготовка Owner Schema AdvancedInventory

## Причина и контракт

Обращение `824eefd649a9b2b7f349f72298e45736` дошло до `InventoryRead::read()` и остановилось на `OwnerProtocol::assertStorage()` с `INV_SCHEMA_NOT_READY` и компонентом `table.owner_state`. Этот компонент означает, что таблица с префиксом форума отсутствует либо её движок отличается от InnoDB. Предварительная проверка ACP показывает, какой из вариантов обнаружен на текущей базе.

ACS требует шесть таблиц InnoDB. Суффиксы ниже дополняются действующим `TABLE_PREFIX`.

| Таблица | Требования проверки ACS |
| --- | --- |
| `af_advinv_items` | `id`, `uid` — unsigned INT, `qty` — INT, PK `id` |
| `af_advinv_equipped` | PK `uid, equip_slot` |
| `af_advinv_support_slots` | PK `uid, slot_code` |
| `af_advinv_owner_state` | `uid` — unsigned INT, `inventory_version` — unsigned BIGINT, PK `uid` |
| `af_advinv_external_operations` | `operation_key` — VARCHAR(191) с `ascii_bin`, `intent_json` и `result_json` — LONGTEXT, `result_hash` — CHAR(64), PK `operation_key` |
| `af_advinv_reservations` | `id`, `reservation_version` — unsigned BIGINT, `item_id`, `initial_qty`, `remaining_qty` — unsigned INT, PK `id`, UNIQUE `reservation_once(operation_key)`, индекс `item_active(item_id,status)` |

План ACP проверяет тот же контракт и дополнительные колонки слотов, журнала операций и резервов согласно утверждённому DDL. Он включает `af_advinv_grant_sources`, поскольку эта таблица входит в действующую Owner Schema и нужна операциям выдачи Inventory. Для неё проверяются InnoDB, обязательные колонки, составной PK `source_system, source_key` и UNIQUE `source_operation(operation_key)`.

## Развёртывание

1. После установки файлов PR откройте **ACP → AdvancedInventory → Подготовка схемы для ACS** с правом доступа к ACP AdvancedFunctionality. Просмотр плана читает метаданные.
2. Проверьте движок, колонки, индексы и действие по каждой таблице. Если таблица существует, но несовместима, сохраните резервную копию, проверьте расхождения с DDL на копии и согласуйте отдельную миграцию в обслуживании. Для отсутствующих `prepared_binding_json` и `prepared_binding_hash` в существующих резервах предусмотрен штатный `AF_ADVINV_OwnerSchema::upgradePrepared()`.
3. Если план содержит только отсутствующие таблицы, подтвердите его и нажмите **Проверить и подготовить схему**. ACP проверяет CSRF и актуальность плана под блокировкой развёртывания. Команда выполняет только утверждённые `CREATE TABLE` из `AF_ADVINV_OwnerSchema`. Базовые таблицы используют DDL обычной установки Inventory. Каждая попытка и результат фиксируются стандартным журналом действий ACP. При прерывании перечитайте план и подтвердите оставшиеся действия.
4. Дождитесь **READY**. После создания команда заново вызывает `AF_ADVINV_OwnerProtocol::assertStorage()` и пересчитывает полный план. **BLOCKED** показывает оставшиеся несоответствия. Изменения существующих таблиц и пользовательских данных в этой операции не выполняются.
5. Повторите проверку листа #34 в GM-панели, затем при готовности отдельно подтвердите регистрацию. Проверьте экипировку в снимке и исходный Inventory. Если проверка укажет на следующий блокер, сопоставьте код обращения с диагностикой ACP.

Панель GM показывает отдельное сообщение при `INV_SCHEMA_NOT_READY`. Администраторам с правами ACP доступны ссылки на запись диагностики и подготовку схемы. Чтение Inventory остаётся обязательной частью регистрации.

## Проверки

Выполнены точечные `php -l` и `git diff --check`. Автоматические тесты не создавались и не запускались. Фактическое развёртывание на форуме, получение READY и повторная проверка листа #34 требуют ручной приёмки.
