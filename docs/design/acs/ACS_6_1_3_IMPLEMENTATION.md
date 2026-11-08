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

## 6.1.3B — Shop, transfer и grant

B основан на commit A `2c1845b9` и оформлен отдельным stacked PR поверх A. После merge A целевая ветка B — main. Это вторая часть того же разрешённого scope, не переход к ACS-6.2.1.

### Исправленные Shop writers

- `advancedshop/appearance_slots.php::af_advancedshop_order_transaction`: при installed owner schema использует общую WriterBoundary. Owner/advisory locks берутся **до** cart/order/Balance. При отсутствии owner schema сохраняется существующий legacy transaction wrapper. Nested Inventory add_item присоединяется только к управляемой boundary, не делает независимый BEGIN/COMMIT.
- `af_advancedshop_checkout_order`: при enabled Inventory runtime загружается до BEGIN; required tables теперь включают owner grant source ledger для обычных items. Проверка InnoDB выполняется до debit. Без Inventory обычные items запрещены по прежним правилам; cosmetic appearance_slot не требует включения Inventory и не получает боевой механики.
- `advancedshop.php::af_advancedshop_grant_inventory_item`: ordinary modern delivery требует текущего покупателя, paid order, точной line из durable items_json. Использует `advancedinventory/modules/ShopDelivery.php::af_advinv_deliver_shop_line`; повтор возвращает прежний receipt, не перечитывает KB/не вызывает add_item второй раз. `af_advancedshop_grant_inventory_item_locked` — внутренняя прежняя классификация/выдача с обязательным held owner lock при installed schema. Guarded add_item сохраняет active stack и создаёт отдельную строку. Legacy bypass остаётся no-op, старые import paths закрыты maintenance gate A.
- `af_advancedshop_grant_slot_order_line`, replay_slot_order и refund_slot_order используют общий transaction wrapper при installed schema; их существующий Appearance entitlement protocol не превращён в Inventory/ACS grant. Ordinary item refund по-прежнему не придуман. Cosmetic apply/unapply не получает AP или ACS equipment identity.

Новый `af_advinv_lookup_shop_delivery(operation_key)` — авторизованный read-only writer lookup paid delivery по `shop:delivery:ORDER:LINE`. Проверяет текущего владельца paid order, source recipient и result hash; ambient tx запрещена. Потеря checkout ack восстанавливается прежним **checkout_key → paid order → delivery receipt**, а не повторным списанием валюты. Это отдельный обычный Shop контракт; paid checkout не считается ACS reward/grant.

### Transfer

`AF_ADVINV_OwnerProtocol::transfer(v1_envelope)` делегирует owner implementation `PropertyOperations.php`. Вход соответствует IC-INV-1 transfer(key,item_id,qty,from_uid,to_uid,expected_versions). Default MyBB policy требует реального отправителя-собственника, собственный Inventory scope, POST/CSRF; temporary ACS controller/GM view не заменяют право собственности. Отдельная серверная policy может предоставить явно проверенное право, не request flag.

Оба uid сортируются до advisory/SQL owner locks. Current source и candidate destination обнаруживаются под guards, item locks берутся по id ASC. Проверяются существование владельцев, обе owner versions, qty и active reserve. Выбрана консервативная политика: любой active reserve source запрещает transfer всего stack, включая свободную часть. Self transfer/over-request/stale versions возвращают durable rejected без изменения qty.

Частичный transfer уменьшает source qty и создаёт/увеличивает exact-byte matching free destination stack. Полный transfer удаляет source и его E/S bindings, а recipient получает destination row; receipt сохраняет source item_id и destination_item_id. Qty суммарно не меняется, uid означает **постоянного** собственника, custody не создаётся. Appearance/customization transfer закрыт до отдельного протокола косметических assignments. Replay проверяет старый receipt до устаревших versions и не требует существования уже удалённого source.

### Grant и KB

`AF_ADVINV_OwnerProtocol::grant(v1_envelope)` — отдельная owner операция. **Default owner-only MyBB policy запрещает grant:** владение своим Inventory не даёт права создавать имущество. Нужна доверенная server-side policy с явным grant authorization, вызываемая до tx и под owner guard. ACS spending/delegation/loot allocation provider в этом PR не вводится.

Поддержан доказанный узкий normalized_item: KB `arpg_item`, owner-normalized kind consumable/ammo, entity=equipment. Определение читается с authoritative writer `af_kb_entries` по точным type/key (ровно одна строка) под FOR SHARE; требуется InnoDB и действующий KB dependency/нормализатор `af_kb_get_normalized_item_profile(...,'inventory')`. `snapshot_json` должен совпасть с точными сохранёнными meta_json bytes, definition_ref.hash=SHA256 этих bytes, version=`sha256:HASH`. Неподтверждённый snapshot/type/kind → durable DEFINITION_CONFLICT, не fallback на произвольный клиентский payload. Inventory key/type bounds соблюдены без обрезания KB identity. Это проверка owner definition, не исполнение combat effects. Остальные grant kinds безопасно не поддержаны; обычные Shop talent/appearance доставляются их прежним отдельным paid-order путём.

`OwnerSchema::statements` дополнен **af_advinv_grant_sources**: PK `(source_system,source_key)` с binary identity, recipient_uid, unique operation_key, payload_hash, result_json/result_hash. Source key глобальна в подсистеме, не per-recipient: повтор той же provenance под другой key/recipient не выдаёт предмет второму победителю. Provenance system обязана совпадать с authenticated scope system. Полные normalized payload/provenance сохраняются также в immutable external_operations.intent_json; item не становится хранилищем ledger. Источники/receipts переживают удаление item.

Новая таблица — явный owner DDL plan, **не автоматическая миграция**. Для установки старого плана 6.1.2 требуется отдельно выполнить только новый CREATE в maintenance window с проверкой actual schema; повторять весь plan на заполненной БД нельзя. Без source ledger modern Shop/grant отказывают до оплаты/выдачи. Имена I/E/S и qty source of truth не меняются.

Дополнительно set_active_weapon читает текущий old slot из owner CS row под общей boundary и проверяет **оба** old/new bindings в id order. Переключение на свободный slot не обходит резерв прежнего активного item; helper fixture подтверждает refusal.

### Atomicity, retry и оставшиеся gates

Mutation qty, owner versions, grant source и external receipt фиксируются одной owner transaction. Public transfer/grant отказывают при любой ambient tx, включая управляемую WriterBoundary: committed receipt нельзя вернуть до outer COMMIT. Только внутренний paid-delivery primitive присоединяется к checkout, чей результат возвращается после outer COMMIT. Одинаковая key/hash/canonical intent возвращает immutable receipt, другая payload — конфликт. Transfer/grant используют прежний `OwnerProtocol::lookup`; Shop — paid source lookup выше. Успешный COMMIT с потерей ack возвращает recovery_required/INV_WRITE_OUTCOME_UNKNOWN, новый grant/transfer key не генерируется автоматически. Unknown после ordinary checkout сначала ищется прежним checkout_key. Поздний source/receipt SQL failure откатывает Inventory/Balance/order; ошибки не выдаются за success.

ACS adapter дополнен transfer/grant delegates, без SQL. Default gate теперь **ACS_INVENTORY_COMPOSITION_REQUIRED**, а не сообщение о якобы неучтённых writers: source paths либо переведены, либо явно закрыты. Production ACS authorization provider, combat coordinator и installed acceptance всё ещё не подключены. **Боевой расход остаётся BLOCKED**, preview/seal/Resolver/commit/UI не вызывают новые APIs.

Неготовые, но закрытые пути: unsafe maintenance/reset/import/DDL из A; cosmetic destructive callbacks и transfer assignments; произвольные grant kinds/legacy KB schemas; installed upgrade/rollback и сторонние scripts. Эти пути не объявлены safe и не обходят reserve. Legacy compatibility branch существует только без owner foundation tables; partial schema fail-closed. Direct af_inv_add_item остаётся legacy trusted server helper, не idempotent grant API; его обнаруженные runtime callers теперь paid Shop delivery, а import закрыт. Новые rewards обязаны использовать grant с key/provenance, не этот helper.

### Подтверждённые проверки B

`tests/acs_inventory_property_sql.php`, disposable MySQL **8.0.46**, PHP **8.4.27**, реальные Inventory/Shop/Balance/KB functions и native SQL, без forum DB:

- reserve→transfer rejected, partial transfer, retry и lookup; два реальных DB handles конкурируют за source (SQL wait подтверждён) → один transfer и неизменная суммарная qty;
- verified KB grant; duplicate и concurrent duplicate grant → один результат/одна выдача; same provenance/new key rejected; changed payload conflict; stale KB snapshot rejected;
- successful owner COMMIT + потеря ack → recovery_required → прежний grant receipt по lookup, без повторной выдачи;
- настоящий checkout при active reserve; повторная покупка exact Shop stack не merge-ит зарезервированную строку; checkout retry и direct paid-line retry не списывают валюту/не выдают второй раз;
- ошибка source receipt **после** debit и Inventory insert → rollback Balance/order/items; successful checkout COMMIT + потеря ack → retry прежней checkout_key находит order/delivery и одну оплату;
- обычная продажа после release повышает баланс на точную цену; default policy не даёт владельцу self-grant;
- php -l новых/изменённых PHP, старые focused owner/writers SQL fixtures, payload/writer inventory и git diff --check: PASS. Полный suite не запускался.

Installed MyBB/real permissions/Appearance theme/custom addons и PHP 8.5.12 не сертифицированы этими fixtures. Cosmetic appearance_slot сохраняет прежний отдельный protocol; код AP/masks не менялся. `docs/ROADMAP.md` не изменён. ACS-6.2.1 не начат.
