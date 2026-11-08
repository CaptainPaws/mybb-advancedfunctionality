# ACS-6.1.3 — Reservation-aware writers

База A: main `c452717f` (merge ACS-6.1.2). Задача разделена по разрешённой границе: **6.1.3A Inventory/CharacterSheets writers**, затем **6.1.3B Shop/transfer/grant**. Этот раздел фиксирует A; A отдельно не разрешает production reservations/combat. Master Specification, Technical Architecture, ACS Implementation Roadmap, Integration Contracts, ACS-6.1.1/6.1.2 и Inventory/Shop/CS audit использованы как источники. `docs/ROADMAP.md` не меняется.

## 6.1.3A — реализовано

`advancedinventory/modules/WriterBoundary.php` вводит общую standalone write boundary для обычных writers. Авторизация остаётся у существующих HTTP entry points/доверенных server callers. Native writer link, utf8mb4, InnoDB и полная owner schema обязательны. Discovery owner schema выполняется на writer, не replica. При отсутствии **всех трёх** owner tables сохраняется legacy mode: резервов там создать нельзя. Частичная схема блокирует записи. Никакой автоматической установки DDL/флага validated=true нет.

Порядок: Appearance/Shop advisory locks по uid ASC → BEGIN → общие owner guards по uid ASC → внешние Balance locks для sell → items по id → active reservations → bindings → versions → COMMIT. Engine ACS-6.1.2 использует те же owner guards, но не вызывает Appearance/Shop и не ждёт их advisory locks. Только собственная boundary разрешает вложенные Inventory mutations на уже захваченном owner set; чужая ambient tx отвергается без commit/rollback. Это join только Inventory writers, не общий MyBB/ACS transaction.

Reserved item вызывает понятный `INV_ITEM_RESERVED` **внутри** write boundary; staff bypass отсутствует. Для legacy destructive/binding writers выбрана консервативная политика: блокируется весь stack с active reservation, даже если часть qty свободна. Операция touch повышает owner-wide inventory_version; over-request не удаляет строку. Unknown COMMIT возвращается как исключение INV_WRITE_OUTCOME_UNKNOWN без rollback/retry; старые пользовательские endpoints не объявлены durable owner APIs.

### Переведённые writer-функции

Пути относительно `inc/plugins/advancedfunctionality/addons/`.

| Writer | Изменение и подтверждение |
| --- | --- |
| advancedinventory/advancedinventory.php: af_advancedinventory_api_sell | Modern branch вызывает WriterBoundary::sell; fresh item/qty/price под lock, active reserve запрещён до Balance effects. Legacy branch доступна только без owner schema. SQL fixture подтверждает reserve→sell denial, happy sale/Shop проверяются в B |
| af_advinv_delete_owner_item_slot; af_advancedinventory_api_delete | Modern delete проверяет owner + reserve до очистки bindings/удаления; реальный public helper проверен SQL fixture |
| af_advancedinventory_api_bulk_delete | Все найденные IDs сортируются, проверяются и удаляются в одной tx; conflict любого item откатывает batch. Исчезнувший item вызывает conflict вместо выдуманного success |
| af_inv_remove_item | Общая boundary/locks/reserve; excessive и qty<=0 не удаляют item; partial decrement, full cleanup. SQL fixture вызывает настоящий helper |
| af_advancedinventory_api_update | Quantity/entity/slot set через guarded helper, reserved stack блокируется; SQL fixture подтверждает refusal |
| af_advancedinventory_api_equip / api_unequip | Перед записью проверяются current item и **старый item заменяемого slot**, оба с reserve check. Общая boundary; SQL fixture проверяет bind/unbind/replacement refusal |
| af_advancedinventory_api_bind_support_slot / api_unbind_support_slot | Аналогичный shared support binding writer; slot validation повторяется по locked item; SQL fixture refusal и обычный bind/unbind после release |
| af_inv_add_item → af_advinv_add_item_locked | Public helper входит в owner boundary; internal implementation требует held owner guard. Exact meta_json bytes + stable min(id) matcher, overflow refusal. Зарезервированный stack исключается из merge, metadata/title/icon не перезаписываются; grant создаёт отдельную строку. SQL fixture проверяет merge и isolation. Это ещё **не durable grant**, он относится к B |
| charactersheets/modules/ajax.php: af_charactersheets_handle_api_impl equipment branch | equip/unequip делегируют тем же Inventory helpers; вся loadout branch удерживает owner guard, включая set_active_weapon и build_json saves. Current active slot проверяется перед сменой. Named Inventory errors возвращаются как inventory_conflict, не raw SQL. Helper SQL + source review; полный installed CS handler NOT_VERIFIED |

Bind/equip не создаёт ACS equipment/custody, не расходует AP. Cosmetic Appearance assignments и декоративные slots не становятся ACS items. В modern режиме destructive delete visual item пока явно отклоняется `INV_APPEARANCE_DELETE_REQUIRES_PROTOCOL`: независимый Appearance callback с потенциальным собственным COMMIT не считается joinable. В legacy mode прежнее поведение сохранено. Отказ происходит до callback/Inventory mutation. AP за маску не вводится.

### Maintenance и оставшиеся writers

При installed owner schema следующие пути **явно блокируются до любых записей/DDL**, а не объявляются reservation-safe migrations:

- `af_advancedinventory_install`, `activate`, `uninstall`, `upgrade_schema`, `ensure_inventory_storage`;
- `af_advinv_normalize_customization_items_in_db`, `af_advancedinventory_migrate_from_shop`;
- `AF_Admin_Advancedinventory::reset_test_data_tables` (включая variable-table DELETE I/E/shop/orders).

Код INV_MAINTENANCE_REQUIRES_OFFLINE_PROTOCOL не позволяет администратору удалить обязательства/reset receipts или попасть в implicit DDL commit. Auto `af_advinv_run_customization_subtype_migration` пропускается при installed owner schema, без обновления migration marker/normalization: импорт и legacy normalization требуют отдельного offline upgrade/durable import policy. При отсутствии owner schema старый lifecycle сохранён. В A не добавлена обходная force operation.

**Shop checkout/grant всё ещё относятся к B.** В A старый Shop ambient transaction при попытке modern add_item безопасно отвергается; для установленной owner schema требуется B, A нельзя отдельно использовать для production activation. Default ACS adapter gate ACS_INVENTORY_WRITERS_NOT_INTEGRATED остаётся закрытым. Схема не устанавливалась на форуме.

## Проверки A

Disposable Docker MySQL **8.0.46**, PHP **8.4.27**. `tests/acs_inventory_writers_sql.php` использует настоящие Inventory functions и shared writer на реальной БД, тонкий MyBB DB facade для native mysqli и server-policy fixture из 6.1.2. Новая случайная БД удаляется в finally. Full installed MyBB/CS/Appearance не эмулируется.

- php -l изменённых/новых PHP: PASS.
- reserve→sell/delete/decrement/set qty/bind/unbind/replacement denied; сохранность qty: PASS.
- release→обычный decrement/delete/bind/unbind: PASS.
- add/merge free stack; grant isolation от reserved stack; qty overflow; excessive remove без удаления; ambient refusal; maintenance refusal: PASS.
- `tests/acs_inventory_owner_sql.php`: PASS, прежние reserve races/duplicate consume/release/unknown COMMIT/rollback сохранены.
- `python tests/acs_inventory_owner_contract.py`: PASS; legacy scanner обновлён под guarded internal add implementation, не объявляет legacy branches безопасными при installed schema.
- git diff --check: PASS. Полный test suite не запускался.

## BLOCKED

A не реализует transfer/grant receipts или Shop join; это B. Production upgrade/deployment, actual read/write routing и installed MyBB/CS/Appearance acceptance ещё требуют проверки. ACS combat commit/preview/seal/custody/loot_pool/UI не подключаются; ACS-6.2.1 не начинается.
