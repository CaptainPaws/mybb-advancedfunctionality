# Аудит Advanced Inventory / Advanced Shop / CharacterSheets

> Дата аудита: 2026-09-25. Документ описывает фактический код текущей ветки, а не желаемую модель. В рамках аудита код и схемы не изменялись.

## 1. Overview

В системе существуют **три разных уровня предмета**:

1. KB-entry (`af_kb_entries.id`, `type`, `key`, `meta_json`/`data_json`) — определение и механические данные предмета;
2. товарная позиция `af_shop_slots.slot_id` — предложение купить KB-entry либо appearance preset по заданной цене;
3. строка владения `af_advinv_items.id` — принадлежащий пользователю stack/экземпляр-снимок.

Персонаж здесь отождествлён с MyBB-пользователем: inventory и equipment привязаны к `uid`, отдельного `character_item_id` нет. Character Sheet (`af_charactersheets_sheets`) также имеет `uid`, а `sheet.id` не участвует в ключах Inventory.

Главный результат аудита:

- покупка создаёт/увеличивает независимую строку Inventory, а не хранит ссылку на `slot_id` как внешний ключ;
- надетое состояние хранится в `af_advinv_equipped`, расходники быстрых слотов — отдельно в `af_advinv_support_slots`;
- CharacterSheets показывает актуальное состояние этих таблиц напрямую через `af_advinv_export_charactersheet_equipment_state()`;
- механический калькулятор также читает их, но поддерживает лишь узкий legacy-контракт: броня `rules.item.equip.armor.ac_bonus`, у активного оружия `rules.item.weapon.damage_bonus`, а у прочих типов — часть `bonuses`/`on_equip.effects`;
- ARPG-панель totals имеет второй, параллельный pipeline. Она применяет `base_stats` и безусловные flat `modifiers` только из `build_json.equipment.slots`; фактические строки `af_advinv_equipped` туда не синхронизируются. Поэтому отображаемая экипировка и ARPG totals могут расходиться.

### Проанализированные точки кода

- Shop: `advancedshop.php`, `admin.php`, shop templates/JS, regression test;
- Inventory: `advancedinventory.php`, `admin.php`, inventory templates/JS и сохранённый schema snapshot;
- CharacterSheets: `modules/calculator.php`, `render.php`, `ajax.php`, `bootstrap.php`, `permissions.php`, `sheets_crud.php`, шаблоны equipment/weapon/inventory и frontend JS;
- KB использован как источник определения: `af_kb_entries` и вызываемые normalization/extraction helpers.

## 2. Advanced Shop architecture

### 2.1 Таблицы

| Таблица | Назначение и ключевые поля |
|---|---|
| `af_shop_shops` | Магазин (`shop_id`, `code`, локализованные заголовки/описания, оформление, `enabled`, `sortorder`). |
| `af_shop_categories` | Дерево категорий: `cat_id`, `shop_id`, `parent_id`, `title`, `description`, `sortorder`, `enabled`. |
| `af_shop_slots` | Товарная позиция: `slot_id`, `shop_id`, `cat_id`, `source_type`, `source_ref_id`, `kb_type`, `kb_id`, `kb_key`, `price`, `currency`, `stock`, `limit_per_user`, `enabled`, `sortorder`, `meta_json`. Это **shop slot**, не equipment slot. |
| `af_shop_carts` | Корзина пользователя: `cart_id`, `shop_id`, `uid`, `updated_at`. |
| `af_shop_cart_items` | Строка корзины: `id`, `cart_id`, `slot_id`, `qty`. |
| `af_shop_orders` | История покупки: `order_id`, `shop_id`, `uid`, суммарные `total`/`currency`, `status`, `items_json`. |
| balance tables | Не принадлежат Shop; доступны через `af_shop_get_balance()`/`af_shop_sub_balance()`. |

Цена хранится в minor units как целое `price`; валюта — строковый slug, нормализуемый Shop (обычно `credits`, но checkout группирует несколько валют и записывает `mixed` в order). `stock` и `limit_per_user` присутствуют в schema/UI, однако checkout-путь, рассмотренный ниже, не уменьшает stock и не проверяет per-user limit: это gap.

### 2.2 Создание и тип товара

Менеджер создаёт `af_shop_slots`. Источник имеет два фактически поддержанных варианта:

- `source_type=kb`, `source_ref_id`/`kb_id` указывают на KB-entry; дублируются `kb_type` и `kb_key`;
- `source_type=appearance`, `source_ref_id` указывает на preset Advanced Appearance.

Shop не задаёт собственную полную weapon/armor schema. Для KB-товара вид, категория Inventory и механика выводятся из текущего KB `meta_json`: нормализованный `item_kind`, затем `rules.item_kind`, `rules.rules.item_kind`, `rules.item.item_kind` и fallback-теги. Мост `af_advinv_shop_map` может заменить целевой inventory `entity` и заполнить `default_subtype`.

### 2.3 Покупка

`af_advancedshop_checkout()`:

1. проверяет login и включённый Advanced Inventory;
2. `af_advancedshop_checkout_collect_items()` перечитывает активные shop slots и фиксирует `slot_id`, source/KB identity, `slot_meta_json`, количество, цену и валюту;
3. проверяет баланс по каждой валюте;
4. начинает транзакцию, создаёт `af_shop_orders` со snapshot `items_json`, списывает баланс;
5. для каждой позиции вызывает `af_advancedshop_grant_inventory_item()`;
6. очищает cart items и делает commit.

Для KB-товара grant **заново читает KB по `kb_id` на момент checkout**, берёт его `meta_json`, title/icon/type/key, классифицирует destination, добавляет в JSON узел:

```json
{"shop":{"slot_id":123,"kb_id":456,"price_each":1000}}
```

и вызывает `af_inv_add_item()`. `af_inv_add_item()` merge-ит строку только при полном совпадении `uid + entity + subtype + kb_type + kb_key + MD5(meta_json)`; иначе создаёт новый `af_advinv_items.id`. Следовательно, разные snapshots одного KB могут стать разными stacks.

Для appearance сохраняется snapshot `{source_type, appearance:{preset_id,target_key,preview_image,settings_json}}` и synthetic identity `kb_type=appearance`, `kb_key=appearance:<preset_id>`.

### 2.4 Source of truth после покупки

Ответ не бинарный:

- **владение, qty, title/icon snapshot, классификация и купленный JSON**: `af_advinv_items` — source of truth;
- **механика и отображаемое KB-описание при enrichment/CharacterSheets**: KB-entry снова разрешается по `kb_type + kb_key` — живой source of truth;
- `af_shop_slots` после checkout не является runtime source of truth. Его `slot_id`, `kb_id`, цена остаются provenance внутри inventory `meta_json` и order snapshot, без FK.

Редактирование товара (`price`, category, enabled, shop `meta_json`) **не меняет уже купленную строку**. Редактирование связанного KB-entry, напротив, может изменить последующее enrichment, candidate slots, показанные бонусы и расчёт CharacterSheets, хотя сохранённый `af_advinv_items.meta_json` остаётся старым. Это смешанная snapshot/live модель и важный риск воспроизводимости.

## 3. Advanced Inventory architecture

### 3.1 Реальная основная schema

`af_advinv_items`:

| Поле | Тип/смысл |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT`, inventory row/stack ID. |
| `uid` | владелец-пользователь/персонаж. |
| `entity` | верхняя категория, default `equipment`; динамически задаётся `af_advinv_entities`. |
| `slot` | legacy/storage category, default `stash`; при записи обычно совпадает с entity. Это **не надетый slot**. |
| `subtype` | классификация внутри entity (`weapon`, `armor`, `ammo`, `consumable`, `augmentations`, `gear`, `artifact` и настраиваемые значения). |
| `kb_type`, `kb_key` | логическая ссылка на KB-definition; DB FK отсутствует. |
| `title`, `icon` | сохранённые display snapshots/fallback. |
| `qty` | количество в stack, минимум при grant — 1. |
| `meta_json` | snapshot произвольной metadata/KB rules + shop provenance. |
| `created_at`, `updated_at` | timestamps. |

Индексы: `uid_entity`, `uid_entity_subtype`, `uid_slot`, `uid_slot_subtype`, `uid_kb`. Уникальности по KB нет: stacking выполняется прикладным запросом с MD5 JSON.

Сопутствующие таблицы:

- `af_advinv_equipped(uid, equip_slot, item_id, updated_at)`, PK `(uid,equip_slot)`, index `(uid,item_id)`;
- `af_advinv_support_slots(uid,slot_code,item_id,sortorder,created_at,updated_at)`, PK `(uid,slot_code)`;
- `af_advinv_entities(entity,title_ru,title_en,enabled,sortorder,renderer,settings_json,updated_at)`;
- `af_advinv_entity_filters(id,entity,code,title_ru,title_en,sortorder,enabled,match_json,updated_at)` (фильтры/classification);
- `af_advinv_shop_map(id,shop_code,shop_cat_id,inventory_entity,default_subtype,mode,enabled,sortorder,updated_at)`;
- `af_shop_inventory_legacy` — переименованная старая `af_inventory_items`; runtime grant намеренно её не использует;
- старые `af_inventory_items`/`af_inventory_equipped`, если остались в конкретной БД, не являются текущей authority.

### 3.2 Entity и категории

Фактические семантические группы динамичны. В коде есть обработка как минимум `equipment`, `abilities`, `resources`, `customization`, а также entity из `af_advinv_entities`. Equipment subtype detector признаёт:

`weapon`, `armor`, `ammo`, `consumable`, `augmentations` (`augmentation`/`cyberware` нормализуются), `gear`, `artifact`.

Customization имеет фильтры `packs`, `profile`, `postbit`, `thread`, `sheet`, `achievements`, `application`, `inventory`, `other`. Appearance item хранит preset и settings; активное appearance state — отдельная `af_aa_active`, не equipment.

Pets не имеют специальной таблицы/механического класса в исследованном коде: они возможны только как настраиваемый entity/subtype/KB metadata и не подключены к equipment/Sheet calculations.

### 3.3 Metadata и реальные механические ключи

Inventory является generic container и не имеет колонок `damage`, `atk`, `def`, `hp`, `element`, `armor`. Они могут существовать лишь внутри скопированного `meta_json`/живого KB JSON. Фактически читаются следующие формы:

- classification: `item_kind`, `kind`, `type`, `tags`; `rules.item_kind`, `rules.rules.item_kind`, `rules.item.item_kind`, `rules.item.item_type/type/kind/tags`, `rules.item.unique_role/unique_base_kind`;
- slots: `rules.item.equip.slot`, `rules.item.equip.slots`, `rules.item.slot`, `equip.slot(s)`, root `slot`;
- legacy Sheet: `rules.item.bonuses[]`, `rules.item.weapon.damage_bonus`, `rules.item.ammo.damage_bonus`, `rules.item.equip.armor.ac_bonus`, `rules.item.on_equip.effects[]` с `op=add_damage|add_armor|add_ac`;
- ARPG renderer: entry rules `base_stats[]` и `modifiers[]` с `stat_key`, numeric `value`, `mode=flat`, optional `condition_text`; collections `resistances`, `weaknesses`, `resources`, `immunities`, `abilities`, `skills`, `proficiencies`, `grants`;
- weapon display card: `rules.weapon.level`, `base_attack|attack`, `damage_bonus` и `rules.ui.image|icon` (это другой JSON path, не legacy calculator path);
- appearance: `source_type`, `appearance.preset_id/target_key/preview_image/settings_json`;
- purchase provenance: `shop.slot_id/kb_id/price_each`.

Наличие любого иного ключа (`damage`, `crit`, `element`, `resistance`, effects и т. п.) не означает механику: ключ учитывается только если один из перечисленных readers его читает.

## 4. Item storage model

### 4.1 ID map

| Понятие | Реальный ID | Связь |
|---|---|---|
| Definition item | `af_kb_entries.id`; стабильная логическая пара `type+key` | Shop хранит numeric `kb_id` и дублирует type/key; Inventory сохраняет только type/key в колонках, numeric id — только provenance JSON. |
| Shop item | `af_shop_slots.slot_id` | cart item ссылается на него; order snapshot и inventory JSON помнят его значение. |
| Cart row | `af_shop_cart_items.id` | временный ID; к Inventory отношения не имеет. |
| Order | `af_shop_orders.order_id` | не сохраняется в inventory row. |
| Inventory/user/character item | `af_advinv_items.id` | один и тот же ID: отдельного user item/character item нет. |
| Equipment row | составной `(uid,equip_slot)` | отдельного equipment ID нет; `item_id` ссылается логически на inventory id, без FK. |
| Character Sheet | `af_charactersheets_sheets.id` | связь с Inventory только через общий `uid`. |

`qty` означает stack. Один inventory ID может представлять несколько единиц, но equip привязывает весь row и не уменьшает/резервирует одну единицу.

### 4.2 Snapshot против live definition

Inventory сохраняет копию KB JSON на момент покупки, однако `af_advinv_enrich_items_from_kb()`, CharacterSheets bonus display и calculator повторно находят KB по type/key. Поэтому нельзя считать ни snapshot, ни KB единственным глобальным source of truth: possession — Inventory, current definition/mechanics — преимущественно KB.

## 5. Equipment slots

Источник machine keys и русских labels — `af_inv_equipment_slots()`:

| Key | Label | Допустимость/назначение по коду |
|---|---|---|
| `head` | Голова | explicit metadata; armor unique fallback. |
| `body` | Тело | armor default и fallback. |
| `hands` | Руки | explicit/armor unique fallback. |
| `legs` | Ноги | explicit/armor unique fallback. |
| `feet` | Ступни | explicit/armor unique fallback. |
| `back` | Спина | explicit/armor unique fallback. |
| `belt` | Пояс | explicit/armor unique fallback. |
| `weapon_mainhand` | Основная рука | weapon default; aliases `mainhand`, `main_hand`, `weapon`. |
| `weapon_offhand` | Вторая рука | weapon default; aliases `offhand`, `off_hand`. |
| `weapon_twohand` | Двуручное | только explicit metadata. |
| `weapon_melee` | Ближний бой | только explicit metadata. |
| `weapon_ranged` | Дальний бой | только explicit metadata. |
| `ammo` | Боеприпасы | ammo default/alias. |
| `gear` | Снаряжение | gear default. |
| `accessory_1` | Аксессуар 1 | `accessory` разворачивается в `_1` и `_2`; gear fallback. |
| `accessory_2` | Аксессуар 2 | то же. |
| `artifact` | Артефакт | artifact; augmentations fallback также указывает сюда, хотя export equipment исключает subtype `augmentations`. |
| `support_1..4` | Быстрый слот 1..4 | settings/DB-configurable; consumables, отдельная binding table. |

Совместимость проверяется endpoint-ом: requested slot должен входить в `af_inv_candidate_slots_for_item()`. Однако проверка только metadata/subtype-based: нет строгой матрицы slot × subtype после explicit metadata, нет проверки two-hand против main/off hand, ammo/weapon type, ring limits и т. п.

PK `(uid,equip_slot)` гарантирует максимум один item в slot. Перед equip удаляются все строки `(uid,item_id)`, поэтому через штатный endpoint один item максимум в одном обычном slot. DB index `uid_item` не unique, следовательно прямые записи способны нарушить это правило. Перемещение в занятый slot молча заменяет прежний item. Аналогично работают support slots.

## 6. Body parts

Отдельной сущности, таблицы или поля `body_part` **нет**. `head/body/hands/legs/feet/back/belt` — просто значения `equip_slot` и candidate slot metadata. Поэтому:

- `body` — machine key torso, не общий объект «часть тела»;
- у частей тела нет характеристик, количества, иерархии или damage model;
- Human label существует только в PHP map;
- slot участвует в размещении/UI и выборе активного оружия, но сам по себе не добавляет stats.

Также не следует путать три значения с названием slot: `af_shop_slots` (товар), `af_advinv_items.slot` (storage/entity legacy field) и `af_advinv_equipped.equip_slot` (фактическая экипировка).

## 7. Armor

### Фактически используемые параметры

| JSON | Где задаётся/хранится | Где читается | Эффект |
|---|---|---|---|
| `rules.item.item_kind = armor` | KB, snapshot в inventory JSON | classifier/calculator | Определяет subtype/ветку. |
| `rules.item.equip.slot(s)` | KB/snapshot | candidate slot resolver | Только допустимые слоты. |
| `rules.item.equip.armor.ac_bonus` | KB | `af_charactersheets_collect_equipment_meta_bonus_items()` | Добавляется в legacy `bonus_armor`, затем `mechanics.ac_total`; отображается как armor bonus. |
| `rules.item.bonuses[]` | KB | generic extractor, но armor-specific branch в equipment collector возвращает только `ac_bonus` | Для надетого subtype armor в текущем collector фактически обходится, если нет другого legacy build path. |
| `rules.item.on_equip.effects[]` | KB | generic extractor | `add_armor`/`add_ac` поддержаны generic path, но armor-specific branch их не вызывает. |
| entry rules `base_stats[]`, `modifiers[]` | KB `data_json`/`meta_json` | ARPG rule pipeline | Только если item находится в `build_json.equipment.slots`, а не просто в `af_advinv_equipped`; flat unconditional only. |

Нет отдельной inventory-колонки `armor`, `def`, `hp`, resistance. `ac_bonus` изменяет legacy armor/AC, **не** `character_defense`. HP/DEF/ATK от armor могут пройти лишь через ARPG `base_stats/modifiers` при наличии legacy build slot — не через текущий live Inventory equipment flow.

## 8. Weapons

### Фактически используемые параметры

| JSON/state | Reader | Реальное назначение |
|---|---|---|
| `rules.item.item_kind=weapon` | classifier/calculator | Тип предмета. |
| `rules.item.equip.slot(s)` | candidate resolver | Допустимые weapon slots. |
| `rules.item.weapon.damage_bonus` | legacy calculator | Только у active weapon добавляется к `mechanics.damage_bonus`. |
| `rules.item.ammo.damage_bonus` | generic extractor | Возможен для generic/non-weapon branch, но dedicated active-weapon branch его не использует. |
| `rules.item.on_equip.effects[].op=add_damage` | generic extractor | Legacy damage bonus в generic path; dedicated weapon branch его обходит. |
| `rules.weapon.level` | ARPG weapon card | Только display. |
| `rules.weapon.base_attack` или `attack` | ARPG weapon card | Только display (`base_attack`). |
| `rules.weapon.damage_bonus` или `rules.damage_bonus` | ARPG weapon card | Только display percent; это не тот path, который использует calculator (`rules.item.weapon...`). |
| entry `base_stats[]`/`modifiers[]` | ARPG aggregator | Flat unconditional stats, но только из `build.equipment.slots`. |
| `build_json.equipment.active_weapon_slot` | calculator/render/AJAX | Выбирает одно активное оружие для legacy damage bonus и badge/card. |

Специальных работающих readers для weapon `crit`, `speed`, `element`, weapon type или raw `damage` в live equipped path нет. Такие данные могут отображаться через generic KB bonuses HTML или храниться, но не становятся автоматически combat totals. `stat_key=atk|speed|crit_dmg|...` поддержан ARPG flat modifier mapper лишь в параллельном build-slot pipeline.

## 9. Equip/unequip lifecycle

### Inventory endpoints

- router actions: `api_equip`, `api_unequip`;
- `af_advancedinventory_api_equip()` проверяет POST/CSRF, owner permission, принадлежность item, вычисляет candidates, удаляет прежнюю строку этого item, затем insert/update slot;
- `af_advancedinventory_api_unequip()` удаляет по `(uid,equip_slot)` либо `(uid,item_id)`.

### CharacterSheets endpoints

AJAX actions `equip_equipment`, `unequip_equipment`, `set_active_weapon` повторяют логику Inventory. Consumable направляется в `af_advinv_support_slots`, прочее — в `af_advinv_equipped`. Первый weapon slot может быть записан как active в `sheet.build_json`; unequip активного очищает его.

Состояние equip не записывается в `af_advinv_items` и не является boolean. Оно выводится из наличия binding row. Количество не меняется. Referential constraints/cascade отсутствуют; удаление item требует прикладной cleanup.

## 10. Shop → Inventory flow

```text
af_kb_entries / appearance preset
        ↓ manager creates offer
af_shop_slots.slot_id
        ↓ af_shop_cart_items(slot_id, qty)
af_advancedshop_checkout_collect_items()
        ↓ order snapshot + balance transaction
af_advancedshop_grant_inventory_item()
        ↓ live KB read, classification, shop-map, JSON snapshot
af_inv_add_item()
        ↓ merge by identity + exact JSON hash, or insert
af_advinv_items.id
```

Возможные потери/расхождения:

- `order_id` не переносится в Inventory;
- numeric KB id и shop slot существуют только в JSON provenance, не как FK;
- shop slot `meta_json` для KB товара заменяется live KB metadata (кроме добавленного `shop`); для non-KB используется slot JSON;
- title/icon — snapshots, но Sheet может показывать live KB;
- последующее KB edit меняет runtime mechanics, shop edit — нет;
- `stock`/`limit_per_user` не участвуют в показанном checkout flow.

## 11. Inventory → CharacterSheets flow

Есть два независимых пути.

### A. Live equipment/UI и legacy mechanics

```text
af_advinv_equipped + af_advinv_items
  → af_advinv_export_charactersheet_equipment_state(uid)
  → af_charactersheets_build_equipment_html()          [public display]
  → af_charactersheets_collect_equipment_meta_bonus_items()
  → af_charactersheets_compute_sheet_view()
  → sheet_view.mechanics / character_computed_state
```

Export возвращает item identity и slot для UI, но не копирует meta/rules в `build_json`.

### B. ARPG stats/weapon VM

```text
af_charactersheets_sheets.build_json.equipment.slots
  → af_charactersheets_arpg_collect_equipment_rule_sources()
  → af_charactersheets_arpg_build_stats_from_kb()
  → af_charactersheets_arpg_build_view_model()
  → stats_panel / weapon card
```

Текущие equip endpoints меняют DB bindings, но **не заполняют `build_json.equipment.slots`**. Они изменяют в build только `active_weapon_slot`. Это центральный разрыв UI/mechanics.

## 12. Stat aggregation

### 12.1 Legacy sheet calculator

`af_charactersheets_compute_sheet_view()` выполняет:

1. default attributes + allocated stats;
2. KB sources `race`, `race_variant`, `class`, `theme` и их rules;
3. build bonuses (equipped abilities, legacy inventory items, augmentations);
4. live equipment metadata bonuses;
5. additive accumulation по type (`hp_bonus`, `armor_bonus`, `weapon_bonus`, `speed_bonus`, attributes и т. д.);
6. derived totals:
   - `ac_total = floor(DEX) + floor(CON) + armor + rule armor + shield`;
   - `damage_bonus = active weapon bonus + floor(STR)`;
   - `damage_total = 1d4 + damage_bonus`;
   - HP = race/class/theme base + fixed bonuses + item HP + CON;
   - speed = race speed + speed bonus.

Одинаковые поддержанные bonuses складываются. Multiplicative/percentage equipment operation, caps и min/max в этом pipeline отсутствуют; неизвестные bonus types могут остаться в диагностическом списке, но totals не меняют. Приведение части totals к `int` отбрасывает дроби.

### 12.2 ARPG renderer

`af_charactersheets_arpg_build_stats_from_kb()`:

1. суммирует direct base/per-level origin + origin variant + archetype;
2. добавляет `base_damage_bonus` в attack и `base_defense_bonus` в defense;
3. применяет origin-variant modifiers (flat; per-level keys масштабируются);
4. последовательно применяет equipment `base_stats` и `modifiers`, но только `mode=flat`, numeric и без `condition_text`;
5. `af_charactersheets_arpg_merge_runtime_stats()` накладывает результат на canonical character payload **только если canonical key отсутствует, пуст или numeric zero**.

Поддержанные modifier stat keys: `hp`, `def`, `atk`, `speed`, `crit_dmg`, `mastery`, `element_damage_bonus`, `healing_bonus`, `shield_strength` и per-level варианты HP/DEF/ATK/mastery. Нет mapper для `armor` и `crit_rate`. Flat modifiers складываются; percent/multiplicative/conditional игнорируются. Collections resources/resistances/weaknesses складываются; set-like abilities/skills/proficiencies перезаписываются по key; grants append-ятся.

Из-за precedence canonical payload ненулевое значение побеждает пересчитанный KB total целиком, а не складывается с ним.

## 13. Active weapon

`equipped` и `active` различаются:

- equipped: binding в `af_advinv_equipped`;
- active: string `equipment.active_weapon_slot` в `af_charactersheets_sheets.build_json`.

Можно надеть несколько weapons в разные slots. AJAX `set_active_weapon` разрешает только пять weapon slots и требует, чтобы slot был реально занят. Если active пуст/устарел, calculator и renderer выбирают первый занятый slot в порядке mainhand, offhand, twohand, melee, ranged. Inventory API сам active weapon не устанавливает, поэтому fallback особенно важен.

Active влияет только на выбор `rules.item.weapon.damage_bonus` в legacy mechanics и на badge/weapon card. ARPG equipment rule aggregation, если legacy build slots заполнены, применяет **все** slots и active не фильтрует.

## 14. Public/private UI

Фактическая политика в основном соответствует ожидаемой:

- `af_charactersheets_build_equipment_html()` всегда строит публичную сетку **всех slot labels и equipped items**;
- список `state.items`/«Доступные предметы», filters, gear button и equip controls строятся только при `$can_edit`;
- `$can_edit_loadout` предоставляется владельцу sheet либо admin/moderator;
- AJAX повторно проверяет `can_edit_loadout`, то есть скрытие UI не является единственной защитой;
- active weapon badge публичен;
- отдельного privacy-флага item/equipment нет.

Важно: public grid показывает пустые slots тоже. Доступный inventory не отдаётся в разметку посетителю через equipment builder, но отдельная Inventory tab имеет собственную permission policy Advanced Inventory и должна оцениваться отдельно от loadout controls.

### Обязательная матрица: DISPLAY vs MECHANICS

| Механика | Хранится | Отображается | Влияет на Character Sheet | Где реализовано |
|---|---|---|---|---|
| Equipment slot | Да: `(uid,equip_slot)` | Да, public grid | Косвенно: выбирает item; сам бонуса не даёт | `af_advinv_equipped`, `af_inv_equipment_slots()`, equipment renderer |
| Body part | Нет отдельной сущности; только slot key | Да как slot label | Нет | keys `head/body/hands/legs/feet/back/belt` |
| Armor | Да: subtype/KB JSON | Да | **Частично:** `rules.item.equip.armor.ac_bonus` → legacy AC/armor | Inventory export; calculator equipment collector |
| ATK | Возможно в `base_stats/modifiers` | Да в ARPG stats panel | **Частично:** только build-slot flat `stat_key=atk`; live equipped weapon не подмешивается в ARPG ATK | ARPG rule aggregator |
| DEF | Возможно в `base_stats/modifiers` | Да | **Частично:** build-slot flat `stat_key=def`; `ac_bonus` идёт в AC, не DEF | ARPG aggregator + legacy calculator |
| HP | Возможно в `bonuses`/ARPG modifiers | Да | Не из live armor-specific path; generic/build paths могут добавить | bonus normalizer / ARPG aggregator |
| Weapon damage | Да: `rules.item.weapon.damage_bonus` (и display paths) | Да, card/popover | **Частично:** только active weapon `damage_bonus` → legacy damage bonus; не ARPG ATK | calculator + weapon renderer |
| Active weapon | Да: sheet `build_json` slot string | Да, badge/card | Да, выбирает единственный legacy weapon bonus; не ограничивает ARPG rule aggregation | AJAX, calculator, render |
| Element | Может храниться в KB JSON | Может показываться в KB/card context | Нет equipment-specific применения | generic KB display; character element resolver |
| Modifiers | Да: arbitrary JSON | Частично через KB bonuses HTML | **Частично:** unconditional flat known stat keys, но в ARPG только build slots; legacy читает отдельный узкий контракт | `apply_equipment_rules()`, calculator extractors |

## 15. Confirmed working mechanics

- Shop checkout транзакционно пишет order, списывает валюту и выдаёт Inventory row.
- Exact-snapshot stacking и `qty` работают в `af_inv_add_item()`.
- Equipment candidates выводятся из metadata и проверяются сервером.
- Один штатный item перемещается между slots; один обычный slot содержит один item.
- Equip/unequip state сохраняется независимо от UI и публично разрешается для Sheet.
- Multiple equipped weapons и отдельный active weapon поддерживаются.
- Active weapon `rules.item.weapon.damage_bonus` добавляется к legacy `mechanics.damage_bonus`.
- Armor `rules.item.equip.armor.ac_bonus` добавляется к legacy armor/AC.
- В legacy pipeline одинаковые поддержанные bonuses additive.
- Owner/admin/moderator controls защищены и UI, и endpoint permission check.

## 16. Missing/incomplete mechanics

- Нет отдельной body-part model.
- Нет ring/neck/chest keys; torso называется `body`, accessories — два generic slots.
- Нет two-hand/main/off conflict, ammo compatibility, weapon type validation или slot capacity сверх одного binding.
- Нет DB FK и уникальности `(uid,item_id)`; целостность зависит от endpoints.
- Equip stack не резервирует единицу и не учитывает qty.
- Shop stock и per-user limit не применяются в просмотренном checkout.
- Live Inventory equipment не синхронизируется в `build_json.equipment.slots`; ARPG flat modifiers обычно не получают актуальную экипировку.
- Weapon `base_attack`, percent, crit, speed, element, raw damage и effects в основном display/storage only.
- Armor HP/DEF/resistance/modifiers не проходят через armor-specific live collector; реально гарантирован только `ac_bonus`.
- Conditional и percentage modifiers не вычисляются; caps отсутствуют.
- Дублируются JSON paths (`rules.item.weapon.damage_bonus` для mechanics против `rules.weapon.*` для weapon display).
- Inventory snapshot и live KB дают меняющуюся со временем механику купленного item.
- Старые CharacterSheets slots `armor/weapon/shield` сосуществуют с детальными Inventory slots и не являются одной taxonomy.

## 17. Risks

1. **Два источника equipment truth:** DB binding для UI/legacy calculator и `build_json.equipment.slots` для ARPG totals.
2. **Silent semantic drift:** KB edit ретроактивно меняет купленные items, несмотря на сохранённый JSON snapshot.
3. **Path drift:** display и mechanics читают разные weapon nodes.
4. **Integrity:** orphan equipment rows и duplicate item bindings возможны вне штатных endpoints.
5. **Misleading UI:** bonus HTML/card может показывать параметр, который ни один calculator не применяет.
6. **Canonical precedence:** ненулевые character payload stats блокируют computed KB/equipment totals вместо additive merge.
7. **Classification mismatch:** `augmentations` разрешается resolver-ом к artifact fallback, но исключён из equipment export и обслуживается отдельным augmentation subsystem.
8. **Historical ambiguity:** order хранит snapshot, но Inventory не хранит `order_id`; tracing требует сопоставления времени/slot metadata.

## 18. Recommended next steps

Без реализации на этом этапе:

1. утвердить единый equipment source of truth и способ подавать live bindings в ARPG VM;
2. формализовать versioned item rules contract и один canonical path для weapon/armor fields;
3. решить snapshot-vs-live policy для купленного definition;
4. определить slot taxonomy, body-part distinction и conflict rules;
5. составить явную matrix stat key → operation → total → supported modes/caps;
6. определить семантику stack equip и active weapon;
7. добавить будущие contract/integration tests Shop → Inventory → equip → оба Sheet pipelines;
8. отдельно решить stock/limit enforcement и referential cleanup.

## Проверка влияния оружия на характеристики

**Вердикт: работает частично (вариант C).**

Доказанный live path:

```text
af_advinv_equipped
→ af_advinv_export_charactersheet_equipment_state()
→ af_charactersheets_collect_equipment_meta_bonus_items()
→ active_weapon_slot/fallback
→ rules.item.weapon.damage_bonus
→ bonus_weapon
→ damage_bonus_total = bonus_weapon + floor(STR)
→ sheet_view.mechanics.damage_bonus / damage_total
```

То есть активное оружие меняет legacy combat damage bonus. Оно не добавляет автоматически `base_attack`, `attack`, `damage`, `crit`, `speed`, `element` или percent bonus к ARPG `character_attack_power`.

Отдельный ARPG path способен применить `base_stats/modifiers` и изменить HP/DEF/ATK/speed/crit damage/mastery и несколько bonuses, но читает `build_json.equipment.slots`, не live `af_advinv_equipped`. Текущие endpoints заполняют там только `active_weapon_slot`. Поэтому нельзя утверждать формулу «base + KB + equipped weapon = ARPG totals» для обычного equip flow.

Weapon card `af_charactersheets_arpg_collect_weapon_data()` читает `rules.weapon.level/base_attack/attack/damage_bonus`, выбирает active variant и попадает в VM, но эти значения — DISPLAY, не input статистического aggregator-а.

## Проверка влияния брони на характеристики

**Вердикт: работает частично (вариант C).**

Доказанный live path:

```text
af_advinv_equipped
→ af_advinv_export_charactersheet_equipment_state()
→ af_charactersheets_collect_equipment_meta_bonus_items()
→ rules.item.equip.armor.ac_bonus
→ bonus_armor
→ armor_equip_bonus_total
→ mechanics.ac_total / mechanics.armor_bonus
```

Это повышает legacy AC/armor. Оно не повышает автоматически ARPG `character_defense`, HP или resistances. Generic `bonuses`/`on_equip.effects` имеют readers, но dedicated armor branch live collector забирает только `ac_bonus`. ARPG `base_stats/modifiers` брони могут работать только при отдельном присутствии item в legacy `build_json.equipment.slots`, которое штатный live equip flow не обеспечивает.
