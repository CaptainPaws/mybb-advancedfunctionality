# ACS-6.1.1 — IC-INV-1: writers и глобальные резервации

База: main `fcf973c8059b8427e91cca007720de066d1b107b` (merge #1028), совпадение HEAD/origin/main проверено после `git fetch origin main`. Предыдущий этап: ACS-5.3.2. Один ограниченный Integration/Contract PR; ACS-6.1.2 не начат.

## Реализовано в этом PR

Подготовлен [owner protocol v1](../../contracts/ACS_INVENTORY_OWNER_PROTOCOL.md): шесть API, проверенный actor/scope, strict qty, canonical payload hash, owner-wide version/CAS, общая доступность по всем сессиям, lock order, durable receipts, replay и authoritative lookup после неизвестного исхода. [JSON Schema](../../contracts/ACS_INVENTORY_OWNER_REQUEST.schema.json) и focused payload fixture делают форму запросов проверяемой; это не authorization API.

[SQL proposal](../../contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql) проверен на отдельном MySQL 8.0.46. Это **эскиз в docs/contracts/fixtures, не migration**. Минимальные owner-side таблицы: owner guard/version, external_operations и reservations; в fixture воспроизведена исходная Inventory items DDL. Guard нужен для отсутствующего stack и transfer/grant, где только lock существующего item недостаточен. Ни Inventory lifecycle, ни ACS migrations не подключают эти таблицы. Production schema пока не доказана всеми writers, поэтому установка не подготовлена и не выполнялась.

Не изменены PHP/JS, пользовательские данные, существующие схемы, настройки публикации, RNG/Resolver/PublishService, обычные пользовательские потоки и `docs/ROADMAP.md`. ACS не получает альтернативный баланс qty. Боевой расход остаётся **BLOCKED**. Текущая диагностика `inv.reserve_consume` в `Administration/Diagnostics.php:68` по-прежнему PROPOSED / IDEMPOTENT_INVENTORY_GATEWAY_NOT_IMPLEMENTED.

Прочитаны обязательные Architecture, Modules, Master Specification, Technical Architecture, ACS Implementation Roadmap, Integration Contracts, Inventory/Shop/CS audit, ACS-5.3.2 и ACS-4.3.2-R2 PublishService. Использованы актуальные пути docs/design/acs; старые ссылки внутри исторических документов не являются путями новых файлов. R2 запрещает трактовать owner receipt как общую атомарную MyBB/ACS/Inventory transaction.

## Метод и фактическая схема

Поиск выполнен по всему PHP репозитория (включая root aliases, assets, admin, tasks, остальные addons) по literal таблицам, `AF_ADVINV_TABLE_ITEMS`, legacy таблицам, add/remove и вызовам delete helper. Для найденных writers прочитаны тела и вызывающие пути. Дополнительно проверены raw write_query/DDL, variable-table ACP reset и CharacterSheets AJAX. В `tests/acs_inventory_owner_contract.py` зафиксировано множество direct writer functions: scanner намеренно ограничен синтаксическим поиском и не заменяет ручной разбор динамического SQL/callbacks. Не заявляется аудит неизвестных installed plugins, сторонних scripts или ручного SQL администратора.

Все пути в таблицах ниже относительно `inc/plugins/advancedfunctionality/addons/`. Строки относятся к исходной main; её PHP этим PR не меняется. I = af_advinv_items, E = af_advinv_equipped, S = af_advinv_support_slots. Prefix всегда TABLE_PREFIX.

- `advancedinventory/advancedinventory.php:810–924`, `af_advancedinventory_upgrade_schema`: I.id INT UNSIGNED PK, I.uid INT UNSIGNED, I.qty **signed INT**, meta_json MEDIUMTEXT, updated_at не CAS. Нет inventory_version и reservation table. Индексы uid_entity, uid_entity_subtype, uid_slot, uid_slot_subtype, uid_kb не обеспечивают уникальность stack. Новая таблица создаётся InnoDB; код upgrade не доказывает engine уже существующей установленной таблицы.
- E: `advancedinventory.php:89–98`, PK(uid,equip_slot), index uid_item; нет unique(uid,item_id) и FK. S: `:902–915`, PK(uid,slot_code), uid_item и uid_sortorder; item_id BIGINT, хотя I.id INT. Equip/support binding не содержит qty и не резервирует единицу stack.
- `af_inv_add_item:3147–3203`: application matcher uid/entity/subtype/kb_type/kb_key + MD5(meta_json); SELECT LIMIT 1, затем absolute qty update или insert. Нет lock/CAS/unique stack; возможны lost update/duplicate stack. На merge также обновляются title/icon. Для protocol нужны owner guard, exact bytes check после hash prefilter и стабильный выбор legacy duplicate.

## Прямые writers и их callers

**Все строки этой таблицы NOT READY для reservation protocol.** Обычная работоспособность endpoint и наличие POST/CSRF не означают безопасность для ACS. Ни один writer не объявлен safe.

| Путь и функция | Изменение / достижимость | Неподготовленная граница |
| --- | --- | --- |
| advancedinventory/advancedinventory.php:3059 `af_inv_add_item` | I INSERT :3202 или qty/title/icon UPDATE :3169; Shop и migration callers ниже | Нет operation_key, guard, version, receipt; read-modify-write, metadata overwrite |
| там же :3207 `af_inv_remove_item` | I DELETE :3214 либо qty UPDATE :3217 | Обычный SELECT; max(1,qty); over-request удаляет весь stack; bindings не чистятся. Production callers поиском не найдены, но public helper остаётся writer |
| там же :1919 `af_advancedinventory_api_update` | I qty/entity/slot UPDATE :1935; Inventory dispatch api_update | Приведение/max(1), абсолютный set qty без проверки reserve/versions |
| там же :2017 `af_advinv_delete_owner_item_slot` | S DELETE :2041 → E DELETE :2042 → I DELETE :2043; предварительно Appearance cleanup | Нет lock/check/reservation, сам не владеет tx; Appearance callback до удаления |
| там же :1939 `af_advancedinventory_api_delete` | Проверяет owner/item, вызывает delete helper :1950 | Full delete без reserve/version/receipt |
| там же :1954 `af_advancedinventory_api_bulk_delete` | Читает список I, по найденным item вызывает helper :2004; возвращает missing_ids | Нет общей tx, sorted lock set и атомарности batch; возможна частичная запись |
| там же :2213 `af_advancedinventory_api_sell` | I qty UPDATE :2267 или helper :2269, затем af_shop_add_balance | Qty/price/equipped читаются **до START TRANSACTION** :2264; нет current lock/reserve/receipt, порядок item→Balance |
| там же :1694 `af_advancedinventory_api_equip` | E DELETE по item :1707, UPDATE :1710/INSERT :1712 по slot | Owner/item/slot проверяются до отдельных writes; не проверяет qty/reserve/заменяемый item атомарно |
| там же :1717 `af_advancedinventory_api_unequip` | E DELETE по slot :1726 либо item :1728 | Нет guard/version/reserve; slot input и item input — два пути |
| там же :1878 `af_advancedinventory_api_bind_support_slot` | S DELETE :1891, UPDATE :1895/INSERT :1898 | Аналогичный unguarded binding; stack qty не резервируется |
| там же :1903 `af_advancedinventory_api_unbind_support_slot` | S DELETE :1912 либо :1914 | Нет guard/version/reserve |
| charactersheets/modules/ajax.php:30 `af_charactersheets_handle_api_impl` | do=equip_equipment :772: S DELETE/UPDATE/INSERT :811–820 для consumable; E DELETE/UPDATE/INSERT :824–829 для прочего equipment | Второй прямой writer вне Inventory; can_edit_loadout/slot checks не защищают reservation race |
| тот же handler :843–849, do=unequip_equipment | S или E DELETE по slot; последующая запись build_json | Нет общей owner tx/reserve/version; set_active_weapon :758–771 меняет только build_json, не I/E/S |
| advancedinventory/admin.php:316 `AF_Admin_Advancedinventory::reset_test_data_tables` | Variable-table DELETE WHERE 1=1 :337, в списке I/E, legacy/shop/orders/cart | Вызов render_inventory_list :92–105 после post key/confirm=yes. Слово test не ограничивает строки: удаляет реальные данные выбранной БД. S вообще не очищает; нет reservation gate, tx, version или receipts |

HTTP достижимость Inventory: `af_advancedinventory_misc_router:515` → `af_advancedinventory_dispatch:550`; aliases inventory.php/abilities.php и inventories.php → render_* → тот же dispatch :562–571. Это одни writers, а не дополнительные безопасные копии. CS wrapper `af_charactersheets_handle_api:6` вызывает impl; проверка прав редактирования листа не равна разрешению боевого расхода. `api_move` в dispatch :573 возвращает **501 not_implemented**: существующий transfer не обнаружен; переписывание I.uid production code не найдено. Будущий transfer должен быть отдельным owner API, не обходом через grant+remove.

### Lifecycle, normalization и импорт

Эти writers также **NOT READY**, несмотря на отсутствие боевого UI:

| Путь/функция | Фактическая запись и caller | Требование целевой модели |
| --- | --- | --- |
| advancedinventory.php:810 `af_advancedinventory_upgrade_schema` | CREATE/ALTER I :817/:863/:894; raw UPDATE entity :869; CREATE S :903 | Maintenance gate вне tx, actual schema/engine validation, version invalidation для normalization |
| advancedinventory.php:64 `af_advancedinventory_install` | Вызывает storage/upgrade/normalization/import; CREATE E :90 | Не запускать изменение schema/импорт внутри owner mutation |
| advancedinventory.php:107 `af_advancedinventory_activate` | Upgrade, normalization, storage/import | Те же обязательства; activate не служит разрешением боевого расхода |
| advancedinventory.php:1160 `af_advinv_normalize_customization_items_in_db` | I subtype/updated_at UPDATE :1187 | Меняет identity stack без version/locks; gate или owner mutation |
| advancedinventory.php:28 `af_advinv_run_customization_subtype_migration` | Init :19 → cache marker → normalize; также normalizer из install/activate | Запись возможна при bootstrap обычного запроса, не только ACP; marker не per-item receipt |
| advancedinventory.php:4839 `af_advancedinventory_migrate_from_shop` | af_inv_add_item :4889 из af_shop_inventory_legacy; :4914 из paid/completed/issued orders.items_json | Один общий datacache marker пишется после выдач; interruption/reinstall может повторить выдачу. Нужен stable source-row/order-line key, без двойного импорта одного приобретения |
| advancedinventory.php:793 `af_advancedinventory_ensure_inventory_storage` | RENAME af_inventory_items → af_shop_inventory_legacy :806 при inv_id | Не пишет текущие I/E/S, но создаёт источник следующего import; lifecycle maintenance |
| advancedinventory.php:249 `af_advancedinventory_uninstall` | DROP S :260, сохраняет I/E | Удаление bindings при обязательствах должно запрещаться; факт сохранения I не является safe uninstall |

Config writers entities/entity_filters/shop_map в Inventory/admin изменяют классификацию/правила будущего grant, не I.qty/uid напрямую. Они не включены в количество direct writers I/E/S, однако новый intent обязан фиксировать normalized snapshot до retry; изменение mapping не должно менять payload старой key. Существующий `af_advinv_entities_upgrade_schema` не заменяет inventory version.

### Shop, выдача, продажа и сопутствующие эффекты

| Путь/функция | Связь с имуществом | Статус для IC-INV-1 |
| --- | --- | --- |
| advancedshop/advancedshop.php:2152 `af_advancedshop_checkout` | Вызывает checkout_order с checkout_key; возвращает order/items/balance | Checkout receipt не ACS owner receipt |
| advancedshop/appearance_slots.php:150 `af_advancedshop_checkout_order` | owner advisory tx → cart lock :168 → Balance lock :192 → order/charge → grant_inventory_item :201 | Проверяет InnoDB required tables и дедуплицирует paid order; не участвует в общем reserve/version. **NOT READY** до общего guard и joinable Inventory primitive |
| advancedshop/appearance_slots.php:84 `af_advancedshop_order_transaction` | GET_LOCK af_aa_owner_sha1(db:prefix:uid) → START/COMMIT; используется checkout и slot replay/refund | Не общий Inventory lock; самостоятельный transaction owner, несовместим с вложенным owner BEGIN/COMMIT |
| advancedshop/advancedshop.php:2598 `af_advancedshop_grant_inventory_item` | appearance preset → add_item :2648; KB/non-KB → add_item :2844; arpg_talent existence check :2608 | Нет per-line Inventory operation key; повтор прямого вызова может выдать повторно; reread live KB и unique talent check тоже подвержены race |
| там же :2863 `af_advancedshop_ensure_inventory_grant_available` | require Inventory bootstrap, при отсутствии helper exception | Bootstrap может запустить normalization; загрузка helper не доказывает безопасный gateway |
| там же :2884 `af_advancedshop_grant_legacy_inventory_bypass` | Только debug legacy_grant_disabled | **Не writer**: INSERT в legacy отсутствует |
| appearance_slots.php:113 `af_advancedshop_grant_slot_order_line`, :128 `af_advancedshop_replay_slot_order` | Только appearance_slot → paid order → af_aa_slot_grant_order_line | Не создают I/E/S; slot entitlement не боевой item; не обобщать их idempotency на обычный Inventory grant |
| appearance_slots.php:208 `af_advancedshop_refund_slot_order` | Только slot-only order → Balance + af_aa_slot_revoke_order; mixed/legacy item orders отклоняются | Обычный item refund/transfer не реализован; безопасный возврат I не заявляется |
| advancedshop.php:4245 `af_shop_add_balance`, :4261 `af_shop_sub_balance` | Валюта продажи/checkout через Balance owner API | Sale credit не имеет нового Inventory operation key; отдельно доказать совместный receipt/rollback и все side effects |

Inventory `af_advancedinventory_api_appearance_apply:1748` / `...unapply:1808` → `af_aa_upsert_user_assignment` / `af_aa_delete_user_assignment` и `af_advinv_clear_legacy_active_appearance:1734` (DELETE af_aa_active). Delete helper вызывает тот же Appearance delete либо direct fallback :2037. Эти пути не меняют I/E/S, но внешние эффекты удаления требуют отдельной проверки joinability; косметику нельзя превращать в боевое право. Ни один Appearance callback этим аудитом не сертифицирован для atomic owner transaction.

### CharacterSheets и ACS adapters

CS render/calculator/export и `charactersheets/modules/combat_snapshot.php` читают Inventory. AJAX equipment branch — фактический writer, перечисленный выше. Talent/active weapon/sheet build_json сохраняются в CS, не меняют ownership/qty I; не считать их Inventory transfer. `af_advinv_export_charactersheet_equipment_state` — UI reader, не reservation snapshot.

В проверенном `advancedcombatsystem/modules/Infrastructure/Adapters/` InventoryAdapter отсутствует. `CharacterSheetAdapter::createSnapshot` зовёт read-only CS combat snapshot, `CapabilityValidator::character` проверяет equipment source breakdown и `executable=false`; эти проверки не reserve/consume API. KnowledgeBaseAdapter разрешает definitions, остальные adapters обслуживают права/источники/публикацию. В adapters не найдено прямых I/E/S writes или вызова add/remove. Прямое SQL ACS к qty, обход через remove_item и новая изолированная ACS reservation table не добавлены.

## Запланировано и BLOCKED

Целевая модель protocol использует общий owner guard (включая отсутствующий destination stack), operation key, items ASC, reservations ASC, E→S; Shop cart/order/Balance locks должны предшествовать item locks после общего owner guard. Bulk сортирует весь набор заранее. Нынешние checkout cart→Balance→grant и sell item→Balance не совместимы без refactor; advisory lock Shop не даёт защиты от остальных writers. Подробные границы tx и unknown-result lookup — в owner protocol.

Отдельный список **неготовых writers**: add_item; remove_item; api_update; delete helper и оба delete endpoints; sell; equip/unequip; bind/unbind support; CS equip/unequip; ACP reset; upgrade entity normalization; customization normalizer и bootstrap migration; Shop import; install/activate/uninstall; Shop ordinary grant/checkout с его transaction wrapper. Они не мигрированы в этом PR. Нужно также доказать cleanup Appearance и sale Balance effects до объявления composite операции safe.

**BLOCKED:** production migration/owner API implementation, races/atomicity всех writers, consume в боевом commit, equipment/AP, physical transfer, loot grant, custody и coordinator reconciliation. Здесь нет полного боевого расхода, новой экипировки или custody. Обязательства не освобождаются автоматически при timeout/close/deactivate. Для снятия BLOCKED требуются writer coverage, real owner fault/race tests и проверенный ACS commit protocol; один успешный DDL test этого не делает.

## Проверки

Только focused checks, полный suite не запускался. БД форума не использовалась. SQL fixture запускался внутри отдельного Docker MySQL **8.0.46**, network=none, новая случайная БД acs611_fixture_*, после проверки удалена; контейнер теста удалён. PHP runtime проверки — CLI **8.4.27** из существующего af-dev:local, не installed MyBB.

| Проверка | Результат / предел доказательства |
| --- | --- |
| python tests/acs_inventory_owner_contract.py | PASS: шесть payloads, qty bounds/type, запрет лишних полей, hash actor/scope, writer function inventory. JSON Schema не удостоверяет actor и не проверяет связь session/owner |
| python tests/acs_inventory_owner_schema.py acs-611-mysql | PASS: proposal CREATE, InnoDB, PK/unique/index columns, CHECK constraints, сумма двух sessions, duplicate/case-sensitive operation keys, receipts и terminal reservations сохраняются без item, rollback версии |
| Cross-language canonical hash vectors PHP/Python | PASS для шести requests, Unicode/slash и перестановки ключей |
| php -l изменённых PHP | N/A: PHP не изменён; дополнительно lint пяти проверяемых source PHP прошёл |
| git diff --check | PASS |

SQL test проверяет DDL и данные proposal, **не вызывает production reserve/consume** и не доказывает защиту старых writers. Runtime races, unknown COMMIT fault injection, installed schema/engines/сторонние writers, MyBB/production PHP и authorizations остаются NOT_VERIFIED. Работа ограничена ACS-6.1.1.
