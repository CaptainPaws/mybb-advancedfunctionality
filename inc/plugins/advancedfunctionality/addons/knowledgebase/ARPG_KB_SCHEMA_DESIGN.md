# ARPG_KB_SCHEMA_DESIGN.md
## Canonical contract + UI contract for Knowledge Base (ARPG branch)

# Главный канон новой ARPG-ветки

ARPG KB строится как **двухслойная система**:

## 2.1. Public catalog layer

Публичные типы:

- `arpg_origin`
- `arpg_archetype`
- `arpg_faction`
- `arpg_bestiary`
- `arpg_ability`
- `arpg_talent`
- `arpg_item`
- `arpg_lore`

## 2.2. Service / mechanics layer

Служебные типы:

- `arpg_mechanic_profile`
- `arpg_resource_def`
- `arpg_status_def`
- `arpg_modifier_template`
- `arpg_formula_def`
- `arpg_trigger_template`
- `arpg_condition_template`
- `arpg_scaling_table`
- `arpg_combat_template`
- `arpg_snippet`

## 2.3. Жёсткое правило для service-слоя

Все service entries:

- `category = service.mechanics`
- `visibility.catalog = false`
- `visibility.search = false`
- `visibility.internal = true`

Иными словами:
это скрытый технический слой для калькулятора, нормализации, способностей, статусов, формул и шаблонов.

---

# Главный UI-принцип новой схемы

## 3.1. Не все типы должны иметь mechanic-builder

Есть два класса типов:

### A. Простые редакторы без heavy mechanic buttons
Для них не нужны зелёные кнопки "Добавить эффект/модификатор/ресурс" по боевой механике:

- `arpg_origin`
- `arpg_archetype`
- `arpg_faction`
- `arpg_lore`

### B. Полноценные механические редакторы
Для них нужен typed UI с секциями, строками и add-presets:

- `arpg_ability`
- `arpg_talent`
- `arpg_item`
- `arpg_bestiary`

Это обязательное разделение.

---

## 3.2. DnD UI reuse rule

В ARPG path нельзя снова изобретать велосипед.  
Нужно использовать тот же базовый принцип, который уже работает в DnD editor path:

- секция знает свой массив;
- массив знает тип строки;
- зелёная кнопка создаёт **не пустоту**, а **готовый seed row**;
- у строки есть понятный набор полей;
- пустое состояние не означает "ничего не реализовано", а означает "можно создать первую типизированную запись".

Применение этого правила в ARPG:

- для `ability`, `talent`, `item`, `bestiary` вводится **UI contract**;
- для каждого повторяемого массива определяются:
  - `row_kind`
  - `required fields`
  - `seed row`
  - `allowed presets`

---

# 4. Schema IDs

ARPG-ветка использует уже существующие ids:

- `af_kb.arpg.meta.v1`
- `af_kb.arpg.rules.v1`
- `af_kb.arpg.mechanics.v1`
- `af_kb.arpg.sheet-normalized.v1`

DND path живёт отдельно и не переписывается.

---

# 5. Canonical ARPG envelope

## 5.1. Public entry envelope

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "ability",
  "subtype": "",
  "category": "arpg",
  "tags": [],
  "visibility": {
    "catalog": true,
    "search": true,
    "internal": false
  },
  "meta": {
    "rules": {
      "schema": "af_kb.arpg.rules.v1",
      "version": 1,
      "profile_ref": "",
      "normalization_hints": {},
      "compat_flags": []
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
````

## 5.2. Service entry envelope

```json
{
  "schema": "af_kb.arpg.mechanics.v1",
  "mechanic": "arpg",
  "entity_kind": "mechanic_profile",
  "subtype": "",
  "category": "service.mechanics",
  "tags": [],
  "visibility": {
    "catalog": false,
    "search": false,
    "internal": true
  },
  "meta": {
    "rules": {
      "schema": "af_kb.arpg.rules.v1",
      "version": 1,
      "profile_ref": "",
      "normalization_hints": {},
      "compat_flags": []
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

# 6. Registry / enums

> Здесь фиксируется именно **editor registry**, то есть набор значений, который может использовать UI.
> Это проектный канон, а не попытка “энциклопедически перечислить всё на свете”.

## 6.1. Ability type

* `active`
* `passive`
* `ultimate`

## 6.2. Ability subtype

* `aura`
* `summon`
* `toggle`
* `stance`
* `field`
* `support`
* `counter`
* `movement`
* `custom`

## 6.3. Ability slot

* `basic`
* `skill_1`
* `skill_2`
* `skill_3`
* `support`
* `ultimate`
* `passive`
* `custom`

## 6.4. Damage type registry (merged editor registry)

Базовый проектный список для ARPG editor:

* `physical`
* `fire`
* `ice`
* `water`
* `electric`
* `wind`
* `earth`
* `nature`
* `light`
* `dark`
* `void`
* `quantum`
* `imaginary`
* `aether`
* `anomaly`
* `ether`
* `fusion`
* `glacio`
* `aero`
* `havoc`
* `spectro`
* `dendro`
* `pyro`
* `hydro`
* `electro`
* `cryo`
* `anemo`
* `geo`
* `lightning`
* `slash`
* `pierce`
* `blunt`
* `true`
* `custom`

### Норматив

* движок хранит **project damage type key**;
* при необходимости допускаются aliases на игру/сеттинг;
* UI не должен быть привязан к одной франшизе.

## 6.5. Targeting

* `self`
* `single_enemy`
* `single_ally`
* `line`
* `cone`
* `aoe_ground`
* `aoe_around_self`
* `global`
* `custom`

## 6.6. Resource operations

* `gain`
* `spend`
* `drain`
* `restore`
* `reserve`
* `consume_stack`
* `generate_stack`
* `convert`
* `lock`
* `unlock`

## 6.7. Modifier mode

* `flat`
* `percent`
* `multiplier`
* `override`
* `formula_ref`
* `table_ref`

## 6.8. Talent tree

* `offense`
* `defense`
* `support`
* `utility`
* `custom`

## 6.9. Talent rank

* `common`
* `uncommon`
* `rare`
* `epic`
* `legendary`
* `mythic`

## 6.10. Item kind

* `weapon`
* `armor`
* `accessory`
* `artifact`
* `consumable`
* `material`
* `quest`
* `custom`

## 6.11. Equip slot

* `weapon_one_hand`
* `weapon_two_hand`
* `weapon_catalyst`
* `weapon_ranged`
* `weapon_polearm`
* `head`
* `chest`
* `legs`
* `hands`
* `feet`
* `ring`
* `amulet`
* `trinket`
* `custom`

## 6.12. Item rarity

* `common`
* `uncommon`
* `rare`
* `epic`
* `legendary`
* `mythic`
* `set`
* `unique`
* `custom`

## 6.13. Bestiary rank

* `normal`
* `elite`
* `champion`
* `boss`
* `world_boss`
* `raid_boss`
* `custom`

---

# 7. Public types

---

# 7.1. `arpg_origin`

## Назначение

Лорная и базовая стартовая сущность: происхождение / раса / биотип / народ / lineage.

## Главное правило UI

`arpg_origin` **не получает heavy mechanic builder**.
Никаких зелёных кнопок для эффектов/модификаторов/ресурсов по аналогии с боевыми сущностями.

## Что должно быть в UI

Простая форма с обычными полями:

* `name/title`
* `size`
* `creature_type`
* `base_hp`
* `base_damage`
* `base_defense`
* `movement_speed`
* `racial_bonuses_text`
* `racial_traits_text`
* `starting_notes`
* `tags`

## Canonical `data_json.data`

```json
{
  "origin": {
    "size": "medium",
    "creature_type": "humanoid",
    "base_hp": 100,
    "base_damage": 10,
    "base_defense": 5,
    "movement_speed": 100,
    "racial_bonuses_text": "",
    "racial_traits_text": "",
    "starting_notes": ""
  }
}
```

## Обязательные поля

* `size`
* `creature_type`
* `base_hp`
* `base_damage`
* `base_defense`
* `movement_speed`

## Optional

* `racial_bonuses_text`
* `racial_traits_text`
* `starting_notes`

## Что НЕ делаем

* не вводим `effects[]`, `modifiers[]`, `resources[]` как UI-секции с add-buttons;
* не пытаемся сделать из origin боевую ability.

---

# 7.2. `arpg_archetype`

## Назначение

Класс / роль / архетип билда.

## Главное правило UI

Тоже **без heavy mechanic builder**.

## Что должно быть в UI

Простая форма:

* `role`
* `damage_bias`
* `defense_bias`
* `resource_affinity`
* `base_damage_bonus`
* `base_defense_bonus`
* `slot_rules_text`
* `description_notes`
* `tags`

## Canonical `data_json.data`

```json
{
  "archetype": {
    "role": "striker",
    "damage_bias": "high",
    "defense_bias": "low",
    "resource_affinity": "energy",
    "base_damage_bonus": 0,
    "base_defense_bonus": 0,
    "slot_rules_text": "",
    "description_notes": ""
  }
}
```

## Обязательные поля

* `role`
* `damage_bias`
* `defense_bias`
* `resource_affinity`

## Optional

* `base_damage_bonus`
* `base_defense_bonus`
* `slot_rules_text`
* `description_notes`

## Что НЕ делаем

* не строим отдельные боевые секции `effects[]/modifiers[]/resources[]`;
* не даём зелёные кнопки механик.

---

# 7.3. `arpg_faction`

## Назначение

Фракция, организация, репутационная принадлежность.

## Главное правило UI

Чисто лорный и организационный тип.

## Что должно быть в UI

Простая форма:

* `standing_model`
* `vendor_access_text`
* `story_flags_text`
* `description`
* `tags`

## Canonical `data_json.data`

```json
{
  "faction": {
    "standing_model": "neutral",
    "vendor_access_text": "",
    "story_flags_text": ""
  }
}
```

## Обязательные поля

* `standing_model`

## Optional

* `vendor_access_text`
* `story_flags_text`

## Что НЕ делаем

* не вводим боевой builder;
* не создаём секции эффектов и модификаторов.

---

# 7.4. `arpg_lore`

## Назначение

Чисто энциклопедическая / narrative сущность.

## Главное правило UI

Вообще без механики.

## Что должно быть в UI

* `content_blocks[]`
* `linked_entities_text`
* `timeline_text`
* `source_text`
* `tags`

## Canonical `data_json.data`

```json
{
  "lore": {
    "content_blocks": []
  },
  "linked_entities_text": "",
  "timeline_text": "",
  "source_text": ""
}
```

## Обязательные поля

* `content_blocks[]`

## Optional

* `linked_entities_text`
* `timeline_text`
* `source_text`

## Что НЕ делаем

* не добавляем mechanic sections;
* не валидируем как combat-entity.

---

# 7.5. `arpg_ability`

## Назначение

Активная / пассивная / ультимативная способность.

## Главное правило UI

Это **полноценный mechanic editor**.

## Секции UI

### 1. Ability core

Обычные поля:

* `type`
* `subtype`
* `slot`
* `damage_type`
* `targeting`
* `range`
* `cast_time`
* `cooldown`
* `duration`
* `max_charges`
* `level_cap`

### 2. Costs / resources

Typed repeater `resources[]`

### 3. Effects

Typed repeater `effects[]`

### 4. Modifiers

Typed repeater `modifiers[]`

### 5. Triggers

Typed repeater `triggers[]`

### 6. Conditions

Typed repeater `conditions[]`

### 7. Stacking

Typed repeater `stacking[]`

### 8. Progression / dependencies

Typed repeater `upgrade_requirements[]`

## Canonical `data_json.data`

```json
{
  "ability": {
    "type": "active",
    "subtype": "aura",
    "slot": "skill_1",
    "damage_type": "ice",
    "targeting": "single_enemy",
    "range": 12,
    "cast_time": 0.0,
    "cooldown": 8,
    "duration": 5,
    "max_charges": 1,
    "level_cap": 20
  },
  "resources": [],
  "effects": [],
  "modifiers": [],
  "triggers": [],
  "conditions": [],
  "stacking": [],
  "upgrade_requirements": []
}
```

## Обязательные поля

* `type`
* `subtype`
* `slot`
* `damage_type`
* `targeting`
* `range`
* `cooldown`
* `resources[]`
* `effects[]`
* `modifiers[]`
* `upgrade_requirements[]`
* `level_cap`

## Optional

* `cast_time`
* `duration`
* `max_charges`
* `triggers[]`
* `conditions[]`
* `stacking[]`

---

## 7.5.1. `resources[]` row contract

```json
{
  "op": "spend",
  "resource_key": "",
  "value": 0,
  "per": "cast",
  "duration": 0,
  "notes": ""
}
```

### Поля

* `op`
* `resource_key`
* `value`
* `per`
* `duration`
* `notes`

### Seed presets для зелёной кнопки

* `resource_spend`
* `resource_gain`
* `resource_drain`
* `resource_restore`

---

## 7.5.2. `effects[]` row contract

```json
{
  "kind": "damage",
  "damage_type": "physical",
  "targeting": "single_enemy",
  "value_mode": "flat",
  "value": 0,
  "formula_ref": "",
  "duration": 0,
  "hit_count": 1,
  "status_key": "",
  "notes": ""
}
```

### Поля

* `kind`
* `damage_type`
* `targeting`
* `value_mode`
* `value`
* `formula_ref`
* `duration`
* `hit_count`
* `status_key`
* `notes`

### Seed presets для зелёной кнопки

* `damage`
* `heal`
* `shield`
* `barrier`
* `status`
* `proc`

### Важно

Именно здесь живёт пример вроде Дионы:

* одна строка `damage`
* одна строка `shield`
* одна строка `status`
* при необходимости `targeting=self` и `targeting=single_enemy` могут coexist в одной ability.

---

## 7.5.3. `modifiers[]` row contract

```json
{
  "stat_key": "",
  "mode": "flat",
  "value": 0,
  "duration": 0,
  "condition_text": "",
  "notes": ""
}
```

### Seed presets

* `flat_bonus`
* `percent_bonus`
* `multiplier_bonus`

---

## 7.5.4. `triggers[]` row contract

```json
{
  "event": "on_hit",
  "action_text": "",
  "condition_text": "",
  "notes": ""
}
```

---

## 7.5.5. `conditions[]` row contract

```json
{
  "condition_type": "custom",
  "value": "",
  "notes": ""
}
```

---

## 7.5.6. `stacking[]` row contract

```json
{
  "stack_key": "",
  "max_stacks": 1,
  "policy": "refresh",
  "notes": ""
}
```

---

## 7.5.7. `upgrade_requirements[]` row contract

```json
{
  "level": 1,
  "required_item_key": "",
  "required_qty": 0,
  "required_currency_key": "",
  "required_currency_qty": 0,
  "notes": ""
}
```

### Правило

Одна строка = один requirement для конкретного уровня.
UI должен позволять добавлять несколько строк на один уровень.

---

# 7.6. `arpg_talent`

## Назначение

Талант дерева, пассивная нода, вставляемая усилялка.

## Главное правило UI

Это **полноценный mechanic editor**, но проще, чем ability.

## Секции UI

### 1. Talent core

* `tree`
* `tier`
* `rank`
* `slot_type`
* `node_label`
* `rank_weight`
* `socket_cost`

### 2. Effects / passive effects

* `effects[]`
* `passive_effects[]`

### 3. Modifiers

* `modifiers[]`

### 4. Grants

* `grants[]`

### 5. Requirements / dependencies

* `requirements[]`
* `mutual_exclusives[]`

## Canonical `data_json.data`

```json
{
  "talent": {
    "tree": "offense",
    "tier": 1,
    "rank": "rare",
    "slot_type": "passive",
    "node_label": "cold_focus",
    "rank_weight": 1,
    "socket_cost": 1
  },
  "effects": [],
  "passive_effects": [],
  "modifiers": [],
  "grants": [],
  "requirements": [],
  "mutual_exclusives": []
}
```

## Обязательные поля

* `tree`
* `tier`
* `rank`
* `effects[]`
* `passive_effects[]`
* `modifiers[]`
* `grants[]`
* `requirements[]`

## Optional

* `slot_type`
* `node_label`
* `mutual_exclusives[]`
* `rank_weight`
* `socket_cost`

---

## 7.6.1. `effects[]` / `passive_effects[]` row contract

```json
{
  "kind": "damage_bonus",
  "target": "",
  "damage_type": "",
  "mode": "percent",
  "value": 0,
  "notes": ""
}
```

### Seed presets

* `flat_stat_bonus`
* `percent_stat_bonus`
* `status_damage_bonus`
* `resistance_bonus`
* `passive_proc`

---

## 7.6.2. `grants[]` row contract

```json
{
  "grant_type": "tag",
  "value": "",
  "notes": ""
}
```

### Допустимые `grant_type`

* `tag`
* `ability_unlock`
* `item_unlock`
* `resource_bonus`
* `passive_flag`
* `custom`

---

## 7.6.3. `requirements[]` row contract

```json
{
  "requirement_type": "talent_key",
  "value": "",
  "notes": ""
}
```

### Примеры requirement_type

* `talent_key`
* `tree_tier`
* `level_min`
* `archetype_key`
* `origin_key`
* `custom`

---

# 7.7. `arpg_item`

## Назначение

Оружие, броня, аксессуар, артефакт, расходник, материал, квестовый предмет.

## Главное правило UI

Это **полноценный mechanic editor** с зависимостью от `item_kind`.

## Секции UI

### 1. Item core

* `item_kind`
* `equip_slot`
* `rarity`
* `subtype`
* `level_min`
* `level_max`
* `progression_stage`
* `level_cap`

### 2. Base stats

* `base_stats[]`

### 3. Combat bonuses / modifiers

* `modifiers[]`

### 4. Effects / passive effects

* `effects[]`
* `passive_effects[]`

### 5. Triggers

* `triggers[]`

### 6. Grants

* `grants[]`

### 7. Progression / dependencies

* `upgrade_steps[]`

## Canonical `data_json.data`

```json
{
  "item": {
    "item_kind": "weapon",
    "equip_slot": "weapon_one_hand",
    "rarity": "epic",
    "subtype": "sword",
    "level_min": 1,
    "level_max": 100,
    "progression_stage": "base",
    "level_cap": 100
  },
  "base_stats": [],
  "modifiers": [],
  "effects": [],
  "passive_effects": [],
  "triggers": [],
  "grants": [],
  "upgrade_steps": []
}
```

## Обязательные поля

* `item_kind`
* `equip_slot`
* `rarity`
* `subtype`
* `level_min`
* `level_max`
* `base_stats[]`
* `modifiers[]`
* `effects[]`
* `grants[]`
* `upgrade_steps[]`

## Optional

* `passive_effects[]`
* `triggers[]`
* `progression_stage`
* `level_cap`

---

## 7.7.1. item_kind-dependent UI

### Если `weapon`

Показать:

* `weapon_class`
* `base_damage`
* `damage_type`
* `attack_speed`
* `range`
* `crit_bonus`

### Если `armor`

Показать:

* `armor_class`
* `base_defense`
* `resist_profile_text`

### Если `accessory`

Показать:

* `accessory_role`
* `passive_focus_text`

### Если `artifact`

Показать:

* `artifact_set_text`
* `passive_focus_text`

### Если `consumable`

Показать:

* `use_kind`
* `stack_max`
* `use_cooldown`

### Если `material`

Показать:

* `material_grade`
* `material_usage_text`

### Если `quest`

Показать:

* `quest_usage_text`

---

## 7.7.2. `base_stats[]` row contract

```json
{
  "stat_key": "",
  "mode": "flat",
  "value": 0,
  "notes": ""
}
```

### Seed presets

* `hp`
* `atk`
* `def`
* `crit_rate`
* `crit_dmg`
* `status_hit`
* `status_resist`

---

## 7.7.3. `modifiers[]` row contract

```json
{
  "stat_key": "",
  "mode": "percent",
  "value": 0,
  "condition_text": "",
  "notes": ""
}
```

---

## 7.7.4. `effects[]` / `passive_effects[]` row contract

```json
{
  "kind": "proc",
  "damage_type": "",
  "value_mode": "flat",
  "value": 0,
  "duration": 0,
  "status_key": "",
  "notes": ""
}
```

### Seed presets

* `damage_proc`
* `heal_proc`
* `shield_proc`
* `status_apply`
* `on_hit_bonus`
* `on_equip_bonus`

---

## 7.7.5. `grants[]` row contract

```json
{
  "grant_type": "tag",
  "value": "",
  "notes": ""
}
```

---

## 7.7.6. `upgrade_steps[]` row contract

```json
{
  "from_level": 1,
  "to_level": 10,
  "required_item_key": "",
  "required_qty": 0,
  "required_currency_key": "",
  "required_currency_qty": 0,
  "notes": ""
}
```

### Правило

Шаги улучшения делаются с шагом по 10 уровней.
То есть UI должен ожидать уровни:

* 1→10
* 10→20
* 20→30
* ...
* 90→100

---

# 7.8. `arpg_bestiary`

## Назначение

Бестиарий врагов, монстров, элит, боссов.

## Главное правило UI

Это **полноценный editor**, но по твоему требованию — **без умного ref-picker**, через ручной ввод ключей.

## Секции UI

### 1. Bestiary core

Обычные поля:

* `family`
* `archetype`
* `faction`
* `rank`
* `threat_tier`
* `level`

### 2. Combat stats

Обычные поля:

* `hp`
* `atk`
* `def`
* `armor`
* `crit_rate`
* `crit_dmg`
* `status_hit`
* `status_resist`

### 3. Resists / weaknesses

Два typed repeater:

* `resists[]`
* `weaknesses[]`

### 4. Abilities

Typed repeater `ability_keys[]`

### 5. Loot / rewards

Typed repeater `loot[]`

## Canonical `data_json.data`

```json
{
  "bestiary": {
    "family": "",
    "archetype": "",
    "faction": "",
    "rank": "normal",
    "threat_tier": 1,
    "level": 1
  },
  "combat_stats": {
    "hp": 0,
    "atk": 0,
    "def": 0,
    "armor": 0,
    "crit_rate": 0,
    "crit_dmg": 0,
    "status_hit": 0,
    "status_resist": 0
  },
  "resists": [],
  "weaknesses": [],
  "ability_keys": [],
  "loot": []
}
```

## Обязательные поля

* `family`
* `archetype`
* `faction`
* `rank`
* `threat_tier`
* `level`
* `combat_stats.hp`
* `combat_stats.atk`
* `combat_stats.def`
* `ability_keys[]`
* `loot[]`

## Optional

* `armor`
* `crit_rate`
* `crit_dmg`
* `status_hit`
* `status_resist`
* `resists[]`
* `weaknesses[]`

---

## 7.8.1. `resists[]` / `weaknesses[]` row contract

```json
{
  "damage_type": "",
  "value": 0,
  "notes": ""
}
```

---

## 7.8.2. `ability_keys[]` row contract

```json
{
  "ability_key": "",
  "notes": ""
}
```

### Важно

Это именно **поле ввода ключа**, а не selector по списку.

---

## 7.8.3. `loot[]` row contract

```json
{
  "loot_key": "",
  "kind": "item",
  "qty_min": 1,
  "qty_max": 1,
  "chance": 100,
  "notes": ""
}
```

### Допустимые `kind`

* `item`
* `currency`
* `material`
* `reward`
* `custom`

### Важно

UI обязан позволять:

* добавлять новые строки;
* у каждой строки вводить `loot_key`;
* у каждой строки вводить `qty_min` / `qty_max`.

---

# 8. Service / mechanics types

> Это скрытая техническая часть.
> Она доступна админу/модератору, но не обычному пользователю.

---

# 8.1. `arpg_mechanic_profile`

Назначение:

* проектный реестр статов;
* проектный реестр damage types;
* реестр targeting;
* реестр статусов;
* реестр ресурсов;
* project-wide правила.

```json
{
  "stats_registry": [],
  "damage_type_registry": [],
  "targeting_registry": [],
  "resource_registry": [],
  "status_registry": [],
  "rules": {
    "stacking_policies": [],
    "modifier_modes": [],
    "resource_ops": []
  }
}
```

---

# 8.2. `arpg_resource_def`

```json
{
  "resource": {
    "key": "",
    "label": "",
    "min": 0,
    "max": 100,
    "regen": 0,
    "decay": 0,
    "notes": ""
  }
}
```

---

# 8.3. `arpg_status_def`

```json
{
  "status": {
    "key": "",
    "label": "",
    "kind": "debuff",
    "stacking": "refresh",
    "default_duration": 0,
    "notes": ""
  }
}
```

---

# 8.4. `arpg_modifier_template`

```json
{
  "modifier_template": {
    "key": "",
    "target": "",
    "mode": "flat",
    "default_value": 0,
    "notes": ""
  }
}
```

---

# 8.5. `arpg_formula_def`

```json
{
  "formula": {
    "key": "",
    "expression": "",
    "inputs": [],
    "notes": ""
  }
}
```

---

# 8.6. `arpg_trigger_template`

```json
{
  "trigger_template": {
    "key": "",
    "event": "",
    "params_schema": {},
    "notes": ""
  }
}
```

---

# 8.7. `arpg_condition_template`

```json
{
  "condition_template": {
    "key": "",
    "condition_type": "",
    "params_schema": {},
    "notes": ""
  }
}
```

---

# 8.8. `arpg_scaling_table`

```json
{
  "scaling_table": {
    "key": "",
    "rows": []
  }
}
```

---

# 8.9. `arpg_combat_template`

```json
{
  "combat_template": {
    "key": "",
    "effects": [],
    "modifiers": [],
    "triggers": [],
    "conditions": []
  }
}
```

---

# 8.10. `arpg_snippet`

```json
{
  "snippet": {
    "key": "",
    "payload": {}
  }
}
```

---

# 9. UI contract and empty-state policy

Это критическая часть, которой не хватало раньше.

## 9.1. Простые типы без mechanic-buttons

Для:

* `arpg_origin`
* `arpg_archetype`
* `arpg_faction`
* `arpg_lore`

пустое состояние — это просто форма с обычными полями.
Никаких пустых секций “Нет записей” там быть не должно.

## 9.2. Механические типы с typed adders

Для:

* `arpg_ability`
* `arpg_talent`
* `arpg_item`
* `arpg_bestiary`

каждый repeater обязан иметь:

* `section_key`
* `row_contract`
* `seed_row`
* `seed_presets[]`

### Правило empty state

Если массив пустой, UI обязан показывать:

* понятный заголовок секции;
* пустое описание;
* кнопку "Добавить";
* при нажатии — seed row создаётся не пустым объектом `{}`, а типизированной строкой.

---

# 10. Downstream integration contract

---

## 10.1. CharacterSheets

ARPG KB должен отдавать данные для листа персонажа.

### Origin

* `base_hp`
* `base_damage`
* `base_defense`

### Archetype

* `base_damage_bonus`
* `base_defense_bonus`

### Item

* `base_stats[]`
* `modifiers[]`
* `effects[]`

### Ability

* ability bar
* cooldown/resources/effects

### Talent

* passive modifiers
* grants

---

## 10.2. Advanced Inventory

Inventory должен понимать:

* `arpg_item`
* `arpg_ability`

### Канон

Inventory хранит:

* `kb_type`
* `kb_key`
* `mechanic = arpg`

Для abilities допускается отдельный subtype inventory-элемента.

---

## 10.3. Advanced Shop

Shop должен уметь подтягивать:

* `arpg_item`
* `arpg_talent`

В перспективе — опционально `arpg_ability`, если появятся unlock-покупки.

---

## 10.4. AdvancedThreadFields

ATF работает по DnD-принципу:

* в поле хранится key;
* label берётся из KB;
* поле знает, к какому `kb_type` относится.

### Примеры

* `origin_key -> arpg_origin`
* `archetype_key -> arpg_archetype`
* `faction_key -> arpg_faction`

---

## 10.5. Balance

Balance не меняется.
Начисление валюты и опыта остаётся как в DnD path.

---

# 11. Normalized contract

ARPG downstream envelope:

```json
{
  "schema": "af_kb.arpg.sheet-normalized.v1",
  "entity_ref": "",
  "entity_kind": "",
  "normalized": {
    "base_stats": [],
    "modifiers": [],
    "ability_profile": {},
    "item_profile": {},
    "costs": [],
    "cooldown": {},
    "statuses": [],
    "triggers": [],
    "conditions": [],
    "scaling": []
  }
}
```

---

# 12. Deprecated / что считаем устаревшим

Устаревшее:

1. Почти пустой ARPG seed contract, где есть только:

   * `schema`
   * `mechanic`
   * `entity_kind`
   * `subtype`
   * `category`
   * `tags`

2. Подход, где origin/archetype/faction/lore описаны так же, как боевые сущности.

3. Подход, где bestiary отсутствует как нормальный public type.

4. Подход, где UI не знает, какой row contract создавать по кнопке.

5. Старые alias-типы:

   * `arpg_ability_active`
   * `arpg_ability_passive`
   * `arpg_modifier`
   * `arpg_status`
   * `arpg_resource`
   * `arpg_mechanics`

Они допустимы только как legacy migration aliases, не как канон.

---

# 13. Что должно быть внедрено следующим шагом в коде

1. Переписать `af_kb_default_arpg_type_definitions()`.
2. Дать полноценные `root_defaults` по всем public ARPG types.
3. Для простых типов:

   * отключить heavy mechanic sections.
4. Для `ability`, `talent`, `item`, `bestiary`:

   * описать typed repeater sections;
   * описать row contracts;
   * описать seed presets.
5. Переписать `af_kb_validate_arpg_public_entity()`.
6. Переписать validator для bestiary под:

   * `family`
   * `archetype`
   * `faction`
   * `rank`
   * `threat_tier`
   * `level`
   * `combat_stats`
   * `ability_keys[]`
   * `loot[]`
7. Не трогать DnD path.
8. После PHP — отдельно делать `knowledgebase.js`.

---

# 14. Практический итог

Новый канон ARPG KB держится на пяти опорах:

1. один скрытый `service.mechanics` слой;
2. простые лорные типы без боевых add-buttons;
3. полноценные typed editor contracts для `ability`, `talent`, `item`, `bestiary`;
4. обязательные seed rows для пустых секций;
5. downstream-совместимость с CharacterSheets / Inventory / Shop / ATF / Balance.


