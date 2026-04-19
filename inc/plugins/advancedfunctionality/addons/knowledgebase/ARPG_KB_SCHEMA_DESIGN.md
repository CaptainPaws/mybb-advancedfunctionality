# ARPG_KB_SCHEMA_DESIGN.md
## Canonical single Meta JSON contract for Knowledge Base (ARPG branch)

> Цель: зафиксировать ARPG-ветку KB в таком виде, чтобы:
> - она была одним Meta JSON, как DnD;
> - не плодила кучу отдельных служебных категорий;
> - имела один скрытый механический слой;
> - давала нормальный editor contract для ARPG UI;
> - не ломала DnD path;
> - была пригодна для CharacterSheets / Inventory / Shop / ATF / Balance.

---

# 1. Главный канон ARPG-ветки

## 1.1. Один Meta JSON, а не россыпь отдельных JSON для потребителей

Для связанных плагинов ARPG запись должна существовать как **один собранный Meta JSON**, по смыслу такой же, как у DnD:

- верхний уровень:
  - `schema`
  - `mechanic`
  - `tags`
  - `ui`
  - `blocks`
  - `rules`

Это означает:

- **не** отдельный внешний contract вида `meta + data_json + blocks` для потребителей;
- **а** один единый объект записи, который можно скармливать в:
  - CharacterSheets
  - Advanced Inventory
  - Advanced Shop
  - AdvancedThreadFields
  - другие механические резолверы.

### Важно
Если внутри текущей реализации БД данные физически лежат отдельно:
- `meta_json`
- `data_json`
- `blocks`

это считается **технической внутренней реализацией хранения**, а не каноном внешнего contract.

Канон для ARPG:
**на выходе из KB запись должна собираться в один Meta JSON объект**.

---

## 1.2. Публичные типы

Публичные top-level типы ARPG:

- `arpg_origin`
- `arpg_archetype`
- `arpg_element`
- `arpg_faction`
- `arpg_bestiary`
- `arpg_ability`
- `arpg_talent`
- `arpg_item`
- `arpg_lore`

---

## 1.3. Механическая категория одна

Скрытая техническая категория для механики одна:

- `service.mechanics`

Она:

- доступна для администратора / модератора;
- скрыта от обычной витрины;
- не индексируется как пользовательский каталог;
- используется калькулятором, UI, валидатором и downstream-плагинами.

---

## 1.4. Внутри одной механической категории — разные типы механики

Внутри `service.mechanics` создаются записи **одного скрытого KB-типа**:

- `arpg_mechanics`

А уже **внутри самой записи** выбирается, какой это вид механики:

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

То есть:

- **категория механики одна**
- **KB-type для неё один**
- **внутри rules.service_kind выбирается подтип механики**

Это и есть правильный канон для v1.

---

# 2. Schema IDs

ARPG-ветка использует свои schema ids:

- `af_kb.arpg.meta.v1`
- `af_kb.arpg.rules.v1`
- `af_kb.arpg.sheet-normalized.v1`

DND path живёт отдельно и не переписывается.

---

# 3. Canonical single Meta JSON envelope

## 3.1. Общий envelope для любой ARPG записи

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": [],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "",
    "version": "1.0"
  }
}
````

---

## 3.2. Поля верхнего уровня

### `schema`

Всегда:

* `af_kb.arpg.meta.v1`

### `mechanic`

Всегда:

* `arpg`

### `tags`

Список тегов записи.

### `ui`

Визуальные данные записи:

* `icon_class`
* `icon_url`
* `background_url`
* `background_tab_url`

### `blocks`

Display / lore / explanation / notes blocks.

### `rules`

Вся машинная механика записи.

---

# 4. Blocks contract

`blocks[]` — это display/content слой, как в DnD.

Каждый блок:

```json
{
  "block_key": "bonuses",
  "level": 0,
  "title": {
    "ru": "Бонусы",
    "en": "Bonuses"
  },
  "effects": [],
  "data": []
}
```

## 4.1. Что должно жить в blocks

* лор;
* описание;
* визуально выводимые бонусы;
* текстовые подсказки;
* narrative content;
* usage notes;
* short/summary content.

## 4.2. Что НЕ должно жить в blocks

* основной боевой расчёт;
* прогрессия улучшения;
* машинные ресурсы;
* обязательные validator-секции;
* item/ability/talent numeric contract.

То есть:

* `blocks` — для вывода,
* `rules` — для механики.

---

# 5. Типы записей и их `rules.type_profile`

Новый канон:

## Public

* `origin`
* `archetype`
* `faction`
* `bestiary`
* `ability`
* `talent`
* `item`
* `lore`

## Hidden service

* `service_mechanics`

А внутри `service_mechanics` обязательно есть:

* `service_kind`

---

# 6. Registry / enums

> Это editor registry и validator registry.
> Он может расширяться, но эти значения — канон v1.

---

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

## 6.4. Damage type registry

Проектный merged registry для ARPG:

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

## 6.7. Modifier modes

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

## Смысл

Лорная и стартовая сущность: происхождение / lineage / раса / биотип.

## Главное правило

`arpg_origin` не получает heavy mechanic-builder.
Зелёные кнопки добавления боевых секций тут не нужны.

## UI

Простые поля:

* `size`
* `creature_type`
* `base_hp`
* `base_damage`
* `base_defense`
* `movement_speed`
* `racial_bonuses_text`
* `racial_traits_text`
* `starting_notes`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["humanoid"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "bonuses",
      "level": 0,
      "title": { "ru": "Бонусы", "en": "Bonuses" },
      "effects": [],
      "data": []
    },
    {
      "block_key": "story",
      "level": 0,
      "title": { "ru": "История", "en": "Story" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "origin",
    "version": "1.0",
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

---

# 7.2. `arpg_archetype`

## Смысл

Класс / боевая роль / специализация.

## Главное правило

Тоже без heavy mechanic-builder.

## UI

Поля:

* `role`
* `damage_bias`
* `defense_bias`
* `resource_affinity`
* `base_damage_bonus`
* `base_defense_bonus`
* `slot_rules_text`
* `description_notes`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["striker"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "overview",
      "level": 0,
      "title": { "ru": "Обзор", "en": "Overview" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "archetype",
    "version": "1.0",
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

---

# 7.3. `arpg_faction`

## Смысл

Фракция, организация, репутационная принадлежность.

## Главное правило

Чисто лорная / организационная сущность, без боевого builder.

## UI

Поля:

* `standing_model`
* `vendor_access_text`
* `story_flags_text`
* `description_text`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": [],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "story",
      "level": 0,
      "title": { "ru": "Описание", "en": "Description" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "faction",
    "version": "1.0",
    "standing_model": "neutral",
    "vendor_access_text": "",
    "story_flags_text": "",
    "description_text": ""
  }
}
```

---

# 7.4. `arpg_lore`

## Смысл

Энциклопедическая и narrative сущность.

## Главное правило

Вообще без механики.

## UI

* только blocks
* плюс optional текстовые связи

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": [],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "lore",
      "level": 0,
      "title": { "ru": "Лор", "en": "Lore" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "lore",
    "version": "1.0",
    "linked_entities_text": "",
    "timeline_text": "",
    "source_text": ""
  }
}
```

---

# 7.5. `arpg_ability`

## Смысл

Активная / пассивная / ультимативная способность.

## Главное правило

Это полноценный mechanic editor.

## UI sections

1. `Ability core`
2. `Costs / resources`
3. `Effects`
4. `Modifiers`
5. `Triggers`
6. `Conditions`
7. `Stacking`
8. `Progression / dependencies`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["ice"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "overview",
      "level": 0,
      "title": { "ru": "Описание", "en": "Overview" },
      "effects": [],
      "data": []
    },
    {
      "block_key": "usage",
      "level": 0,
      "title": { "ru": "Применение", "en": "Usage" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "ability",
    "version": "1.0",
    "type": "active",
    "subtype": "aura",
    "slot": "skill_1",
    "damage_type": "ice",
    "targeting": "single_enemy",
    "range": 12,
    "cast_time": 0,
    "cooldown": 8,
    "duration": 5,
    "max_charges": 1,
    "level_cap": 20,
    "resources": [],
    "effects": [],
    "modifiers": [],
    "triggers": [],
    "conditions": [],
    "stacking": [],
    "upgrade_requirements": []
  }
}
```

---

## 7.5.1. `rules.resources[]` row contract

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

### Seed presets

* `resource_spend`
* `resource_gain`
* `resource_drain`
* `resource_restore`

---

## 7.5.2. `rules.effects[]` row contract

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

### Seed presets

* `damage`
* `heal`
* `shield`
* `barrier`
* `status`
* `proc`

### Важно

Пример вроде щита Дионы живёт именно здесь:

* одна строка `damage`
* одна строка `shield`
* одна строка `status`

То есть одна ability может иметь несколько разных effect rows.

---

## 7.5.3. `rules.modifiers[]` row contract

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

## 7.5.4. `rules.triggers[]` row contract

```json
{
  "event": "on_hit",
  "action_text": "",
  "condition_text": "",
  "notes": ""
}
```

---

## 7.5.5. `rules.conditions[]` row contract

```json
{
  "condition_type": "custom",
  "value": "",
  "notes": ""
}
```

---

## 7.5.6. `rules.stacking[]` row contract

```json
{
  "stack_key": "",
  "max_stacks": 1,
  "policy": "refresh",
  "notes": ""
}
```

---

## 7.5.7. `rules.upgrade_requirements[]` row contract

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

У способности до 20 уровней.
Одна строка = один requirement для конкретного уровня.

---

# 7.6. `arpg_talent`

## Смысл

Талант дерева, нода, пассивная усилялка.

## Главное правило

Полноценный mechanic editor, но проще ability.

## UI sections

1. `Talent core`
2. `Effects / passive effects`
3. `Modifiers`
4. `Grants`
5. `Requirements / dependencies`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["offense"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "overview",
      "level": 0,
      "title": { "ru": "Описание", "en": "Overview" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "talent",
    "version": "1.0",
    "tree": "offense",
    "tier": 1,
    "rank": "rare",
    "slot_type": "passive",
    "node_label": "cold_focus",
    "rank_weight": 1,
    "socket_cost": 1,
    "effects": [],
    "passive_effects": [],
    "modifiers": [],
    "grants": [],
    "requirements": [],
    "mutual_exclusives": []
  }
}
```

---

## 7.6.1. `rules.effects[]` / `rules.passive_effects[]`

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

## 7.6.2. `rules.grants[]`

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

## 7.6.3. `rules.requirements[]`

```json
{
  "requirement_type": "talent_key",
  "value": "",
  "notes": ""
}
```

### Допустимые requirement_type

* `talent_key`
* `tree_tier`
* `level_min`
* `archetype_key`
* `origin_key`
* `custom`

---

# 7.7. `arpg_item`

## Смысл

Оружие, броня, аксессуар, артефакт, расходник, материал, квестовый предмет.

## Главное правило

Это полноценный mechanic editor с UI, зависящим от `item_kind`.

## UI sections

1. `Item core`
2. `Base stats`
3. `Combat bonuses / modifiers`
4. `Effects / passive effects`
5. `Triggers`
6. `Grants`
7. `Progression / dependencies`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["weapon"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "overview",
      "level": 0,
      "title": { "ru": "Описание", "en": "Overview" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "item",
    "version": "1.0",
    "item_kind": "weapon",
    "equip_slot": "weapon_one_hand",
    "rarity": "epic",
    "subtype": "sword",
    "level_min": 1,
    "level_max": 100,
    "progression_stage": "base",
    "level_cap": 100,
    "base_stats": [],
    "modifiers": [],
    "effects": [],
    "passive_effects": [],
    "triggers": [],
    "grants": [],
    "upgrade_steps": []
  }
}
```

---

## 7.7.1. item_kind-dependent UI

### Если `weapon`

Дополнительные поля:

* `weapon_class`
* `base_damage`
* `damage_type`
* `attack_speed`
* `range`
* `crit_bonus`

### Если `armor`

* `armor_class`
* `base_defense`
* `resist_profile_text`

### Если `accessory`

* `accessory_role`
* `passive_focus_text`

### Если `artifact`

* `artifact_set_text`
* `passive_focus_text`

### Если `consumable`

* `use_kind`
* `stack_max`
* `use_cooldown`

### Если `material`

* `material_grade`
* `material_usage_text`

### Если `quest`

* `quest_usage_text`

---

## 7.7.2. `rules.base_stats[]`

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

## 7.7.3. `rules.modifiers[]`

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

## 7.7.4. `rules.effects[]` / `rules.passive_effects[]`

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

## 7.7.5. `rules.grants[]`

```json
{
  "grant_type": "tag",
  "value": "",
  "notes": ""
}
```

---

## 7.7.6. `rules.upgrade_steps[]`

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

У оружия и экипировки шаг улучшения — по 10 уровней.

---

# 7.8. `arpg_bestiary`

## Смысл

Бестиарий врагов, монстров, боссов.

## Главное правило

Полноценный editor, но **без умного ref-picker**.
Все ключи вводятся руками.

## UI sections

1. `Bestiary core`
2. `Combat stats`
3. `Resists / weaknesses`
4. `Abilities`
5. `Loot / rewards`

## Canonical Meta JSON example

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["enemy"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [
    {
      "block_key": "overview",
      "level": 0,
      "title": { "ru": "Описание", "en": "Overview" },
      "effects": [],
      "data": []
    }
  ],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "bestiary",
    "version": "1.0",
    "family": "",
    "archetype": "",
    "faction": "",
    "rank": "normal",
    "threat_tier": 1,
    "level": 1,
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
}
```

---

## 7.8.1. `rules.resists[]` / `rules.weaknesses[]`

```json
{
  "damage_type": "",
  "value": 0,
  "notes": ""
}
```

---

## 7.8.2. `rules.ability_keys[]`

```json
{
  "ability_key": "",
  "notes": ""
}
```

### Важно

Это именно поле ручного ввода ключа способности.

---

## 7.8.3. `rules.loot[]`

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

* добавлять строки;
* указывать `loot_key`;
* указывать `qty_min`;
* указывать `qty_max`.

---

# 8. Hidden mechanics type

## 8.1. Общий KB-type

Скрытый тип один:

* `arpg_mechanics`

## 8.2. Категория

Всегда:

* `service.mechanics`

## 8.3. Вид механики выбирается в записи

Через:

* `rules.service_kind`

Допустимые значения:

* `mechanic_profile`
* `resource_def`
* `status_def`
* `modifier_template`
* `formula_def`
* `trigger_template`
* `condition_template`
* `scaling_table`
* `combat_template`
* `snippet`

---

## 8.4. Canonical Meta JSON for service entry

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["internal", "service"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "service_mechanics",
    "version": "1.0",
    "service_kind": "resource_def",
    "category": "service.mechanics",
    "visibility": {
      "catalog": false,
      "search": false,
      "internal": true
    },
    "entries": []
  }
}
```

### Главное правило

У `arpg_mechanics` всегда есть:

* `service_kind`
* `entries[]`

То есть разные виды механики создаются не разными top-level service types, а разными `service_kind` внутри одного скрытого типа.

---

## 8.5. `service_kind = mechanic_profile`

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "tags": ["internal", "service"],
  "ui": {
    "icon_class": "",
    "icon_url": "",
    "background_url": "",
    "background_tab_url": ""
  },
  "blocks": [],
  "rules": {
    "schema": "af_kb.arpg.rules.v1",
    "type_profile": "service_mechanics",
    "version": "1.0",
    "service_kind": "mechanic_profile",
    "category": "service.mechanics",
    "visibility": {
      "catalog": false,
      "search": false,
      "internal": true
    },
    "stats_registry": [],
    "damage_type_registry": [],
    "targeting_registry": [],
    "resource_ops_registry": [],
    "modifier_modes_registry": [],
    "talent_rank_registry": [],
    "item_rarity_registry": [],
    "bestiary_rank_registry": [],
    "entries": []
  }
}
```

### Назначение

Главный реестр проекта:

* статы;
* damage types;
* targeting;
* resource ops;
* modifier modes;
* ranks / rarity;
* project-wide defaults.

---

## 8.6. `service_kind = resource_def`

`entries[]` строки:

```json
{
  "key": "",
  "label": "",
  "min": 0,
  "max": 100,
  "regen": 0,
  "decay": 0,
  "notes": ""
}
```

---

## 8.7. `service_kind = status_def`

```json
{
  "key": "",
  "label": "",
  "kind": "debuff",
  "stacking": "refresh",
  "default_duration": 0,
  "notes": ""
}
```

---

## 8.8. `service_kind = modifier_template`

```json
{
  "key": "",
  "target": "",
  "mode": "flat",
  "default_value": 0,
  "notes": ""
}
```

---

## 8.9. `service_kind = formula_def`

```json
{
  "key": "",
  "expression": "",
  "inputs": [],
  "notes": ""
}
```

---

## 8.10. `service_kind = trigger_template`

```json
{
  "key": "",
  "event": "",
  "params_schema": {},
  "notes": ""
}
```

---

## 8.11. `service_kind = condition_template`

```json
{
  "key": "",
  "condition_type": "",
  "params_schema": {},
  "notes": ""
}
```

---

## 8.12. `service_kind = scaling_table`

```json
{
  "key": "",
  "rows": []
}
```

---

## 8.13. `service_kind = combat_template`

```json
{
  "key": "",
  "effects": [],
  "modifiers": [],
  "triggers": [],
  "conditions": []
}
```

---

## 8.14. `service_kind = snippet`

```json
{
  "key": "",
  "payload": {}
}
```

---

# 9. UI contract and empty-state policy

Это критическая часть.

## 9.1. Простые типы

Для:

* `origin`
* `archetype`
* `faction`
* `lore`

не должно быть пустых механических секций “Нет записей”.
Там обычная форма.

## 9.2. Механические типы

Для:

* `ability`
* `talent`
* `item`
* `bestiary`

каждый repeater обязан иметь:

* `section_key`
* `row_contract`
* `seed_row`
* `seed_presets[]`

### Правило

Если массив пустой:

* UI показывает заголовок секции;
* UI показывает кнопку `Добавить`;
* при клике создаётся **типизированная строка**, а не `{}`.

---

# 10. Downstream integration contract

## 10.1. CharacterSheets

ARPG Meta JSON должен давать:

### Origin

* `rules.base_hp`
* `rules.base_damage`
* `rules.base_defense`

### Archetype

* `rules.base_damage_bonus`
* `rules.base_defense_bonus`

### Item

* `rules.base_stats[]`
* `rules.modifiers[]`
* `rules.effects[]`

### Ability

* `rules.type`
* `rules.slot`
* `rules.cooldown`
* `rules.resources[]`
* `rules.effects[]`

### Talent

* `rules.effects[]`
* `rules.passive_effects[]`
* `rules.modifiers[]`
* `rules.grants[]`

---

## 10.2. Advanced Inventory

Inventory должен работать с:

* `arpg_item`
* `arpg_ability`

и хранить:

* `kb_type`
* `kb_key`
* `mechanic = arpg`

---

## 10.3. Advanced Shop

Shop должен подтягивать:

* `arpg_item`
* `arpg_talent`

---

## 10.4. AdvancedThreadFields

ATF работает как в DnD:

* value = key
* label тянется из KB

Примеры:

* `origin_key -> arpg_origin`
* `archetype_key -> arpg_archetype`
* `element_key -> arpg_element`
* `faction_key -> arpg_faction`

---

## 10.5. Balance

Balance остаётся как в DnD.
Его схема не меняется.

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

1. ARPG contract, где для потребителей есть отдельные разрозненные куски вместо одного Meta JSON.
2. Почти пустая seed-schema, где есть только:

   * `schema`
   * `mechanic`
   * `entity_kind`
   * `subtype`
   * `category`
   * `tags`
3. Подход, где:

   * `origin`
   * `archetype`
   * `faction`
   * `lore`
     пытаются рендериться как тяжёлые боевые сущности.
4. Подход, где service-механика размножена в каталог как куча отдельных top-level service types.
5. Старые alias-типы:

   * `arpg_ability_active`
   * `arpg_ability_passive`
   * `arpg_modifier`
   * `arpg_status`
   * `arpg_resource`
   * `arpg_mechanics`
     допускаются только как legacy migration aliases.

---

# 13. Что должно быть внедрено следующим шагом в коде

1. ARPG должен собираться в один Meta JSON contract по смыслу DnD.
2. Для скрытого механического слоя нужен один KB-type:

   * `arpg_mechanics`
3. Внутри него переключение:

   * `rules.service_kind`
4. Для `origin/archetype/faction/lore`:

   * отключить heavy mechanic builder.
5. Для `ability/talent/item/bestiary`:

   * описать typed repeater sections;
   * описать seed rows;
   * описать seed presets.
6. Переписать validator под новый single-meta contract.
7. Не трогать DnD path.

---

# 14. Практический итог

Новый канон ARPG KB держится на шести опорах:

1. один Meta JSON как в DnD;
2. один скрытый механический слой;
3. одна механическая категория: `service.mechanics`;
4. один скрытый type для неё: `arpg_mechanics`;
5. разные виды механики выбираются через `rules.service_kind`;
6. полноценный typed UI только у `ability`, `talent`, `item`, `bestiary`.
