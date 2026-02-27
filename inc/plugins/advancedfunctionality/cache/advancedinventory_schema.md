# Advanced Inventory / Shop DB schema

- generated_at: 2026-02-27T19:05:35+03:00
- table_prefix: `mybb_`

## Таблица: `mybb_af_advinv_equipped`

| name | type | null | default | key |
|---|---|---|---|---|
| `uid` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `equip_slot` | `varchar(64)` | `NO` | `NULL` | `PRI` |
| `item_id` | `int unsigned` | `NO` | `NULL` | `` |
| `updated_at` | `int unsigned` | `NO` | `NULL` | `` |

## Таблица: `mybb_af_advinv_items`

| name | type | null | default | key |
|---|---|---|---|---|
| `id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `uid` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `slot` | `varchar(32)` | `NO` | `stash` | `` |
| `subtype` | `varchar(32)` | `NO` | `` | `` |
| `kb_type` | `varchar(32)` | `NO` | `` | `` |
| `kb_key` | `varchar(64)` | `NO` | `` | `` |
| `title` | `varchar(255)` | `NO` | `` | `` |
| `icon` | `varchar(255)` | `NO` | `` | `` |
| `qty` | `int` | `NO` | `1` | `` |
| `meta_json` | `mediumtext` | `YES` | `NULL` | `` |
| `created_at` | `int unsigned` | `NO` | `NULL` | `` |
| `updated_at` | `int unsigned` | `NO` | `NULL` | `` |

## Таблица: `mybb_af_inventory_equipped`

| name | type | null | default | key |
|---|---|---|---|---|
| `id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `uid` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `slot_code` | `varchar(32)` | `NO` | `NULL` | `` |
| `inv_id` | `int unsigned` | `NO` | `NULL` | `` |
| `kb_id` | `int unsigned` | `NO` | `NULL` | `` |
| `equipped_at` | `int unsigned` | `NO` | `0` | `` |

## Таблица: `mybb_af_inventory_items`

| name | type | null | default | key |
|---|---|---|---|---|
| `id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `uid` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `slot` | `varchar(32)` | `NO` | `stash` | `` |
| `subtype` | `varchar(32)` | `NO` | `` | `` |
| `kb_type` | `varchar(32)` | `NO` | `` | `` |
| `kb_key` | `varchar(64)` | `NO` | `` | `` |
| `title` | `varchar(255)` | `NO` | `` | `` |
| `icon` | `varchar(255)` | `NO` | `` | `` |
| `qty` | `int` | `NO` | `1` | `` |
| `meta_json` | `mediumtext` | `YES` | `NULL` | `` |
| `created_at` | `int unsigned` | `NO` | `NULL` | `` |
| `updated_at` | `int unsigned` | `NO` | `NULL` | `` |

## Таблица: `mybb_af_kb_entries`

| name | type | null | default | key |
|---|---|---|---|---|
| `id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `type` | `varchar(64)` | `NO` | `NULL` | `MUL` |
| `key` | `varchar(64)` | `NO` | `NULL` | `` |
| `title_ru` | `varchar(255)` | `NO` | `` | `` |
| `title_en` | `varchar(255)` | `NO` | `` | `` |
| `short_ru` | `text` | `NO` | `NULL` | `` |
| `short_en` | `text` | `NO` | `NULL` | `` |
| `body_ru` | `mediumtext` | `NO` | `NULL` | `` |
| `body_en` | `mediumtext` | `NO` | `NULL` | `` |
| `tech_ru` | `text` | `NO` | `NULL` | `` |
| `tech_en` | `text` | `NO` | `NULL` | `` |
| `meta_json` | `mediumtext` | `NO` | `NULL` | `` |
| `data_json` | `mediumtext` | `NO` | `NULL` | `` |
| `item_kind` | `varchar(64)` | `YES` | `NULL` | `MUL` |
| `icon_class` | `varchar(128)` | `NO` | `` | `` |
| `icon_url` | `varchar(255)` | `NO` | `` | `` |
| `banner_url` | `varchar(255)` | `NO` | `` | `` |
| `bg_url` | `varchar(255)` | `NO` | `` | `` |
| `active` | `tinyint(1)` | `NO` | `1` | `MUL` |
| `sortorder` | `int` | `NO` | `0` | `MUL` |
| `updated_at` | `int unsigned` | `NO` | `0` | `` |

## Таблица: `mybb_af_shop_cart_items`

| name | type | null | default | key |
|---|---|---|---|---|
| `id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `cart_id` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `slot_id` | `int unsigned` | `NO` | `NULL` | `` |
| `qty` | `int` | `NO` | `1` | `` |

## Таблица: `mybb_af_shop_carts`

| name | type | null | default | key |
|---|---|---|---|---|
| `cart_id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `shop_id` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `uid` | `int unsigned` | `NO` | `NULL` | `` |
| `updated_at` | `int unsigned` | `NO` | `0` | `` |

## Таблица: `mybb_af_shop_categories`

| name | type | null | default | key |
|---|---|---|---|---|
| `cat_id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `shop_id` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `parent_id` | `int unsigned` | `NO` | `0` | `` |
| `title` | `varchar(255)` | `NO` | `NULL` | `` |
| `description` | `text` | `NO` | `NULL` | `` |
| `sortorder` | `int` | `NO` | `0` | `` |
| `enabled` | `tinyint(1)` | `NO` | `1` | `` |

## Таблица: `mybb_af_shop_inventory_legacy`

| name | type | null | default | key |
|---|---|---|---|---|
| `inv_id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `uid` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `kb_id` | `int unsigned` | `NO` | `NULL` | `` |
| `qty` | `int` | `NO` | `1` | `` |
| `stack_max` | `int` | `NO` | `1` | `` |
| `rarity` | `varchar(32)` | `NO` | `common` | `` |
| `item_kind` | `varchar(32)` | `NO` | `` | `` |
| `slot_code` | `varchar(32)` | `NO` | `` | `` |
| `created_at` | `int unsigned` | `NO` | `0` | `` |
| `updated_at` | `int unsigned` | `NO` | `0` | `` |

## Таблица: `mybb_af_shop_orders`

| name | type | null | default | key |
|---|---|---|---|---|
| `order_id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `shop_id` | `int unsigned` | `NO` | `NULL` | `` |
| `uid` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `total` | `int` | `NO` | `0` | `` |
| `currency` | `varchar(32)` | `NO` | `credits` | `` |
| `created_at` | `int unsigned` | `NO` | `0` | `` |
| `status` | `varchar(32)` | `NO` | `paid` | `` |
| `items_json` | `mediumtext` | `NO` | `NULL` | `` |

## Таблица: `mybb_af_shop_slots`

| name | type | null | default | key |
|---|---|---|---|---|
| `slot_id` | `int unsigned` | `NO` | `NULL` | `PRI` |
| `shop_id` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `cat_id` | `int unsigned` | `NO` | `NULL` | `` |
| `kb_type` | `varchar(32)` | `NO` | `item` | `` |
| `kb_id` | `int unsigned` | `NO` | `NULL` | `MUL` |
| `kb_key` | `varchar(128)` | `NO` | `` | `` |
| `price` | `int` | `NO` | `0` | `` |
| `currency` | `varchar(32)` | `NO` | `credits` | `` |
| `stock` | `int` | `NO` | `-1` | `` |
| `limit_per_user` | `int` | `NO` | `0` | `` |
| `enabled` | `tinyint(1)` | `NO` | `1` | `` |
| `sortorder` | `int` | `NO` | `0` | `` |
| `meta_json` | `mediumtext` | `YES` | `NULL` | `` |

