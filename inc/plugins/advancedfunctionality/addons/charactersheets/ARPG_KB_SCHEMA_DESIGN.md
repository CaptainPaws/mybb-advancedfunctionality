# ARPG KB Schema Design (public + service/mechanics split)

> Цель: добавить ARPG-направление в KB как отдельный контракт, не ломая текущую DnD-механику (`af_kb.meta.v1/v2` + `af_kb.rules.v1`).

## 1) Архитектурный summary

### 1.1 Публичный слой каталога (видимые top-level типы)

Финальный публичный набор (минимальный и стабильный):

- `origin`
- `archetype`
- `faction`
- `ability`
- `talent`
- `item`
- `lore`

Принципы:

1. `ability` — **единый** тип с `subtype`, без обязательного разбиения на top-level `active/passive`.
2. Служебные механические сущности (status/modifier/resource/formula/trigger/condition/stacking/cooldown/cost/charges) не являются публичными плитками по умолчанию.
3. Бестиарий уже включен в подтип на уровне системы ARPG.

### 1.2 Service/mechanics слой (скрытый от каталога по умолчанию)

Механика выносится в отдельные сервисные типы и/или шаблоны:

- `mechanic_profile` (или `combat_profile`) — canonical системный профиль ARPG.
- `resource_def`
- `status_def`
- `modifier_template`
- `formula_def`
- `trigger_template`
- `condition_template`
- `scaling_table`
- `snippet` (переиспользуемые блоки механик)

Эти сущности используются через `ref`/`template_ref` в публичных записях и в Character Sheet normalization layer.

### 1.3 Что хранить как subtype/reference data

- `ability.subtype`: `active|passive|ultimate|support|technique|aura|toggle|custom`
- `item.item_kind`: `weapon|armor|accessory|artifact|implant|consumable|quest|material|custom`
- `item.equip_slot`: проектный enum
- `talent.tree`: `combat|support|survival|specialization|custom`
- `damage_type`, `targeting`, `stacking_policy`, `trigger_event` — как reference enums (в сервисном профиле или registry-справочниках)

### 1.4 Связь с Character Sheet

Character Sheet читает:

1. Публичные сущности (origin/archetype/ability/talent/item) как source content.
2. Service/mechanics шаблоны (через `refs`) как runtime rules.
3. Нормализованный слой (`normalized_*`) как единый интерфейс рендера/калькулятора.

Поток:

`KB entries -> resolver(template refs) -> normalized contracts -> sheet view model`.

---

## 2) Рекомендуемые schema id

Базовый набор:

- `af_kb.arpg.meta.v1` — envelope/мета ARPG-записей.
- `af_kb.arpg.rules.v1` — core rules contract для ARPG сущностей.
- `af_kb.arpg.mechanics.v1` — service/mechanics registry (статусы, ресурсы, формулы, шаблоны).
- `af_kb.arpg.sheet-normalized.v1` — downstream normalized contract для Character Sheet/Inventory/Shop.

Совместимость:

- DnD остаётся на `af_kb.meta.v1/v2` и `af_kb.rules.v1`.
- ARPG не перезаписывает DnD schema ids, а добавляет параллельную ветку.

---

## 3) Финальный список ARPG entity types

### 3.1 Публичные

- `origin`
- `archetype`
- `faction`
- `ability`
- `talent`
- `item`
- `lore`

### 3.2 Сервисные (скрытые)

- `mechanic_profile`
- `resource_def`
- `status_def`
- `modifier_template`
- `formula_def`
- `trigger_template`
- `condition_template`
- `scaling_table`
- `combat_template`
- `snippet`

---

## 4) Публичные типы: назначение, поля, влияние на Character Sheet

## 4.1 `origin`

Назначение:

- происхождение персонажа (био/соц/физиология/стартовые бонусы).

Обязательные поля:

- `schema`, `mechanic`, `entity_kind`, `subtype`, `category`, `tags`
- `origin.starting_modifiers` / `origin.starting_resources`

Optional:

- `requirements`, `grants`, `effects`, `lore_hooks`, `ui`

Влияние на лист:

- стартовые статы/ресурсы, доп. теги, допуски к талантам/экипировке.

## 4.2 `archetype`

Назначение:

- боевая роль и профиль развития.

Обязательные поля:

- базовые stat templates/role tags
- progression hooks (`grants`, `talent_branches`)

Optional:

- `ability_slot_rules`, `resource_affinity`, `equipment_affinity`

Влияние на лист:

- определяет доступные слоты способностей, базовую математику скейлинга.

## 4.3 `faction`

Назначение:

- фракционная принадлежность/репутация/доступ.

Обязательные поля:

- `faction.key`, `faction.standing_rules` или `requirements`

Optional:

- `vendor_access`, `faction_modifiers`, `story_flags`

Влияние на лист:

- открывает/закрывает контент, может давать условные бонусы.

## 4.4 `ability` (единый тип)

Назначение:

- любые боевые/утилитарные способности.

Обязательные поля:

- `subtype` (active/passive/ultimate/support/technique/aura/toggle/custom)
- `ability.slot`
- `effects` (может быть пусто у чисто аурных/триггерных форм)

Optional:

- `requirements`, `costs`, `cooldown`, `charges`, `resources`, `scaling`, `conditions`, `triggers`, `stacking`, `modifiers`, `formula_refs`, `snippet_refs`, `ui`

Влияние на лист:

- формирует action bar/passive grid, resource flow, status interactions.

## 4.5 `talent`

Назначение:

- progression nodes/ranks для билда.

Обязательные поля:

- `talent.tree`, `talent.tier`, `talent.max_rank`
- `rank_effects`

Optional:

- `requires`, `mutual_exclusive_with`, `node_position`, `ui`

Влияние на лист:

- добавляет ранговые модификаторы/триггеры/ресурсы.

## 4.6 `item`

Назначение:

- предметы и экипировка (оружие, броня, аксессуары, артефакты, импланты, расходники).

Обязательные поля:

- `item.item_kind`
- `item.equip_slot` (если экипируемый)
- `item.rarity`

Optional:

- `base_stats`, `substats`, `passive_ability_refs`, `set_tags`, `requirements`, `effects`, `durability`, `upgrade_paths`, `ui`

Влияние на лист:

- вносит базовые и условные бонусы, даёт пассивки/проки, влияет на сборку.

## 4.7 `lore`

Назначение:

- энциклопедический контент без обязательной механики.

Обязательные поля:

- `title`, `category`, `tags`, `content_blocks`

Optional:

- `linked_entities`, `timeline`, `source`, `ui`

Влияние на лист:

- обычно не меняет математику, но может открывать справочные/квестовые связи.

---

## 5) Service/mechanics слой: сущности, поля, способы хранения

## 5.1 Отдельные service entries (рекомендуется)

Использовать как отдельные KB-записи (скрытые из каталога):

1. `mechanic_profile`
   - системные stat/resource/status registries
   - глобальные rules (stacking policies, cooldown policy, charge policy)
2. `resource_def`
   - `key`, `kind`, `min/max`, `regen`, `decay`, `display`
3. `status_def`
   - `key`, `kind`, `duration`, `stacking`, `tick`, `cleanup`, `interactions`
4. `formula_def`
   - `key`, `inputs`, `expression`/`op_tree`, clamps/rounding
5. `modifier_template`
   - унифицированные паттерны flat/percent/override/convert
6. `trigger_template` и `condition_template`
   - события и условия для переиспользования

Плюс: переиспользование и versioning без дублирования в контенте.

## 5.2 Вложенные blocks/rules (внутри публичной записи)

Оставлять вложенно, если логика уникальна для конкретной сущности:

- one-off условия способности
- уникальные эффекты легендарного предмета
- rank-specific talent effects

## 5.3 Reusable templates

Использовать `template_ref` / `snippet_ref`:

- типовые DoT/HoT
- типовые реактивные триггеры (`on_hit`, `on_dodge`, `on_break`)
- стандартные cost/cooldown/charge схемы

## 5.4 Reference objects

Лёгкие справочники (enum-like) можно хранить в `mechanic_profile`:

- damage types
- targeting modes
- stacking policies
- resource operation kinds

---

## 6) Базовый универсальный JSON-контракт ARPG entry

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "ability",
  "subtype": "active",
  "category": "abilities",
  "tags": ["burst", "electric"],
  "visibility": {
    "catalog": true,
    "search": true,
    "internal": false
  },
  "meta": {
    "rules": {
      "schema": "af_kb.arpg.rules.v1",
      "version": 1,
      "profile_ref": "mechanic_profile:arpg_core_v1"
    },
    "source": {
      "canon": false,
      "origin": "oc",
      "license": "project_internal"
    },
    "ui": {
      "title": "",
      "summary": "",
      "icon": "",
      "color": ""
    }
  },
  "data_json": {
    "data": {},
    "blocks": []
  }
}
```

---

## 7) Специализированные JSON-примеры

## 7.1 `origin`

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "origin",
  "subtype": "lineage",
  "category": "origins",
  "tags": ["frontier", "augmented"],
  "meta": {
    "rules": { "schema": "af_kb.arpg.rules.v1", "profile_ref": "mechanic_profile:arpg_core_v1" }
  },
  "data_json": {
    "data": {
      "requirements": [],
      "grants": [{ "kind": "tag", "value": "augmented_body" }],
      "modifiers": [{ "stat": "hp", "mode": "flat", "value": 120 }],
      "resources": [{ "resource": "energy", "max_bonus": 20 }],
      "effects": []
    }
  }
}
```

## 7.2 `archetype`

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "archetype",
  "subtype": "striker",
  "category": "archetypes",
  "tags": ["dps", "mobility"],
  "meta": {
    "rules": { "schema": "af_kb.arpg.rules.v1", "profile_ref": "mechanic_profile:arpg_core_v1" }
  },
  "data_json": {
    "data": {
      "requirements": [],
      "grants": [{ "kind": "ability_slot", "slot": "ultimate", "count": 1 }],
      "modifiers": [{ "stat": "crit_rate", "mode": "percent", "value": 5 }],
      "scaling": [{ "stat": "atk", "ratio": 1.15, "kind": "multiplier" }],
      "triggers": []
    }
  }
}
```

## 7.3 `ability` (единый тип)

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "ability",
  "subtype": "active",
  "category": "abilities",
  "tags": ["shock", "burst"],
  "meta": {
    "rules": {
      "schema": "af_kb.arpg.rules.v1",
      "profile_ref": "mechanic_profile:arpg_core_v1"
    }
  },
  "data_json": {
    "data": {
      "ability": {
        "slot": "skill",
        "damage_type": "electric",
        "targeting": "aoe"
      },
      "requirements": [{ "type": "archetype", "key": "striker" }],
      "costs": [{ "resource": "energy", "amount": 40 }],
      "cooldown": { "seconds": 12 },
      "charges": { "max": 2, "recharge_seconds": 12 },
      "effects": [
        { "kind": "damage", "formula_ref": "formula_def:skill_electric_atk_ratio_v1" },
        { "kind": "status_apply", "status_ref": "status_def:shock_v1", "chance": 0.35, "duration": 8 }
      ],
      "modifiers": [],
      "resources": [],
      "scaling": [{ "table_ref": "scaling_table:ability_level_curve_v1", "stat": "atk" }],
      "triggers": [{ "template_ref": "trigger_template:on_cast_gain_stack_v1", "params": { "stack_key": "overdrive", "amount": 1 } }],
      "conditions": [{ "template_ref": "condition_template:target_has_status", "params": { "status_key": "shock" } }],
      "stacking": { "policy": "refresh", "max_stacks": 1 }
    }
  }
}
```

## 7.4 `talent`

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "talent",
  "subtype": "combat",
  "category": "talents",
  "tags": ["progression"],
  "meta": {
    "rules": { "schema": "af_kb.arpg.rules.v1", "profile_ref": "mechanic_profile:arpg_core_v1" }
  },
  "data_json": {
    "data": {
      "talent": { "tree": "combat", "tier": 2, "max_rank": 3 },
      "requirements": [{ "type": "talent", "key": "combat_focus_1" }],
      "rank_effects": [
        { "rank": 1, "modifiers": [{ "stat": "skill_damage", "mode": "percent", "value": 8 }] },
        { "rank": 2, "modifiers": [{ "stat": "skill_damage", "mode": "percent", "value": 16 }] },
        { "rank": 3, "modifiers": [{ "stat": "skill_damage", "mode": "percent", "value": 24 }] }
      ]
    }
  }
}
```

## 7.5 `item` / equipment

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "item",
  "subtype": "weapon",
  "category": "items",
  "tags": ["equipment", "electric"],
  "meta": {
    "rules": { "schema": "af_kb.arpg.rules.v1", "profile_ref": "mechanic_profile:arpg_core_v1" }
  },
  "data_json": {
    "data": {
      "item": {
        "item_kind": "weapon",
        "equip_slot": "weapon_main",
        "rarity": "epic",
        "level_range": { "min": 1, "max": 90 }
      },
      "requirements": [{ "type": "archetype", "key": "striker" }],
      "modifiers": [{ "stat": "atk", "mode": "flat", "value": 42 }],
      "effects": [{ "kind": "grant_ability", "ability_ref": "ability:weapon_overheat_protocol" }],
      "grants": [{ "kind": "tag", "value": "weapon:blade" }],
      "scaling": [{ "table_ref": "scaling_table:item_weapon_growth_v1" }]
    }
  }
}
```

## 7.6 `mechanic_profile` / `combat_profile`

```json
{
  "schema": "af_kb.arpg.mechanics.v1",
  "mechanic": "arpg",
  "entity_kind": "mechanic_profile",
  "subtype": "combat_core",
  "category": "service.mechanics",
  "tags": ["internal", "core"],
  "visibility": { "catalog": false, "search": false, "internal": true },
  "data_json": {
    "data": {
      "stats": [
        { "key": "hp", "label": "HP", "mode": "flat" },
        { "key": "atk", "label": "ATK", "mode": "flat" },
        { "key": "crit_rate", "label": "Crit Rate", "mode": "percent" }
      ],
      "resources": [
        { "ref": "resource_def:energy_v1" },
        { "ref": "resource_def:stamina_v1" }
      ],
      "statuses": [
        { "ref": "status_def:shock_v1" },
        { "ref": "status_def:burn_v1" }
      ],
      "rules": {
        "stacking_policies": ["refresh", "replace", "stack_additive", "stack_multiplicative"],
        "cooldowns": true,
        "charges": true,
        "costs": true
      },
      "template_registry": {
        "modifier_templates": ["modifier_template:flat_stat_v1", "modifier_template:percent_stat_v1"],
        "trigger_templates": ["trigger_template:on_hit_v1", "trigger_template:on_cast_v1"],
        "condition_templates": ["condition_template:hp_below_percent_v1"]
      }
    }
  }
}
```

---

## 8) Normalized contract для downstream

Рекомендуемый normalized envelope (`af_kb.arpg.sheet-normalized.v1`):

```json
{
  "schema": "af_kb.arpg.sheet-normalized.v1",
  "entity_ref": "ability:arc_shock_burst",
  "entity_kind": "ability",
  "normalized": {
    "modifiers": [],
    "item_profile": {},
    "ability_profile": {},
    "costs": [],
    "cooldown": {},
    "resources": [],
    "statuses": [],
    "triggers": [],
    "conditions": [],
    "formulas": [],
    "scaling": []
  }
}
```

Нормализованные разделы:

1. `normalized.modifiers`
   - `{target, stat, mode(flat|percent|override|convert), value, duration, condition_ref, source_ref}`
2. `normalized.item_profile`
   - `equip_slot`, `rarity`, `base_stats`, `substats`, `set_tags`, `passive_refs`
3. `normalized.ability_profile`
   - `subtype`, `slot`, `targeting`, `damage_type`, `effects`, `rank_scaling`
4. `normalized.costs/cooldown/resources`
   - единые структуры, независимые от origin/archetype/item/talent источника
5. `normalized.statuses/triggers`
   - status applications + trigger graph
6. `normalized.formulas/scaling`
   - все formula/table refs в одном массиве для калькулятора

---

## 9) Раскладка полей по `meta.rules`, `data_json.data`, `blocks/content`

## 9.1 `meta.rules`

Хранить:

- `schema` rules (`af_kb.arpg.rules.v1`)
- `version`
- `profile_ref` (какой mechanic_profile использовать)
- `normalization_hints` (опционально)
- `compat_flags` (например, `sheet_v2`, `inventory_v1`)

Назначение: декларация контракта и роутинг в движке, а не подробная геймплей-логика.

## 9.2 `data_json.data`

Хранить:

- всю исполняемую механику сущности: `requirements`, `grants`, `effects`, `modifiers`, `resources`, `costs`, `cooldown`, `charges`, `scaling`, `triggers`, `conditions`, `stacking`
- refs на service templates (`*_ref`, `template_ref`, `snippet_ref`)

Назначение: canonical machine-readable payload.

## 9.3 `blocks/content`

Хранить:

- нарратив, описания, usage notes, таблицы для человека, примеры синергий
- локализуемые display-блоки

Назначение: UX/контент, не источник расчётной математики.

---

## 10) Практические рекомендации

## 10.1 Что хранить как публичный KB entry

- всё, что игрок/мастер должен видеть как самостоятельную сущность: origin/archetype/faction/ability/talent/item/lore.

## 10.2 Что хранить как service entry

- справочники и шаблоны, переиспользуемые многими сущностями: statuses/resources/formulas/modifier templates/trigger templates/condition templates/mechanic profiles.

## 10.3 Что хранить вложенно в ability/item/talent

- уникальную для записи логику, не имеющую проектной повторяемости.

## 10.4 Что скрывать от каталога, но держать доступным системе

- весь service/mechanics registry и технические snippet-записи.
- управлять через `visibility.catalog=false` + служебные категории (`service.mechanics`).

## 10.5 Как избежать перегрузки каталога

1. Жёстко ограничить публичные top-level типы семью сущностями.
2. Для служебных записей использовать отдельный internal-фильтр и выключенную индексацию витрины.
3. В UI показывать derived-механику через нормализованный профиль, а не через отдельные плитки статусов/модификаторов.

## 10.6 Совместимость с Character Sheet / Shop / Inventory

- Character Sheet:
  - читает публичные сущности + резолвит service refs;
  - работает по `af_kb.arpg.sheet-normalized.v1` для единообразного рендера.
- Shop:
  - работает с `item` + `requirements` + `price/currency` блоками;
  - пассивки/проки item читаются через normalized item profile.
- Inventory:
  - хранит экземпляры item и их instance-state (уровень, роллы, durability) отдельно от KB-канона;
  - KB хранит только canonical item definition.

---

## 11) Future-compatible заметки

- `bestiary` проектировать отдельно, но совместимо:
  - можно добавить `entity_kind: enemy_profile|beast` в будущем;
  - использовать тот же service/mechanics слой и normalized контракт.
- Для AU + canon + OC поддержать `meta.source.origin` (`canon|oc|hybrid`) и `source_ref` на лор/фандом/внутренние документы.

---

## 12) Почему это не ломает DnD

1. DnD schema ids и профили остаются без изменений.
2. ARPG вводится отдельным namespace (`af_kb.arpg.*`).
3. Нет destructive migration: возможна параллельная эксплуатация DnD и ARPG в одном KB.
