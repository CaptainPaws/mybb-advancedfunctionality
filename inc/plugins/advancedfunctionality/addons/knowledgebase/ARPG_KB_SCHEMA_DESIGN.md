# ARPG KB Schema Design v2
## Canonical contract for Knowledge Base (ARPG branch)

> Цель: зафиксировать новый каноничный ARPG schema contract для KB, не ломая текущую DnD-ветку (`af_kb.meta.v1/v2`, `af_kb.rules.v1`) и подготовить основу для последующего editor UI, validator path и downstream-интеграций.

---

## 1. Архитектурный summary

### 1.1. Общая модель

ARPG-ветка KB строится как **двухслойная система**:

1. **Public catalog layer**  
   Игровые сущности, которые должен видеть мастер/игрок:
   - `arpg_origin`
   - `arpg_archetype`
   - `arpg_faction`
   - `arpg_bestiary`
   - `arpg_ability`
   - `arpg_talent`
   - `arpg_item`
   - `arpg_lore`

2. **Service / mechanics layer**  
   Служебные сущности, скрытые от обычной витрины:
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

Принцип:
- public entries описывают игровые сущности;
- service entries описывают переиспользуемую механику;
- public entries могут ссылаться на service entries через `ref`, `*_ref`, `template_ref`, `snippet_ref`.

---

### 1.2. Что остаётся каноном из текущей реализации

ARPG-ветка обязана использовать уже существующие schema ids:

- `af_kb.arpg.meta.v1`
- `af_kb.arpg.rules.v1`
- `af_kb.arpg.mechanics.v1`
- `af_kb.arpg.sheet-normalized.v1`

Dnd path не переписывается и живёт параллельно.

---

### 1.3. Главный принцип хранения данных

Каждая ARPG-запись должна иметь три уровня:

#### A. Envelope
Технический контейнер записи:
- `schema`
- `mechanic`
- `entity_kind`
- `subtype`
- `category`
- `tags`
- `visibility`
- `meta`
- `data_json`

#### B. `meta.rules`
Контракт и роутинг:
- версия контракта;
- `profile_ref`;
- normalization hints;
- compat flags.

#### C. `data_json.data`
Вся исполняемая механика:
- requirements
- grants
- effects
- modifiers
- resources
- cooldowns
- charges
- scaling
- triggers
- conditions
- stacking
- refs на service-слой

#### D. `data_json.blocks`
Человеко-читаемые display/content blocks:
- описания;
- usage notes;
- тактические заметки;
- примеры синергий;
- лорные вставки;
- таблицы/подсказки для UI.

---

## 2. Canonical ARPG envelope

### 2.1. Public entry envelope

```json
{
  "schema": "af_kb.arpg.meta.v1",
  "mechanic": "arpg",
  "entity_kind": "ability",
  "subtype": "active",
  "category": "abilities",
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
```

### 2.2. Service entry envelope

```json
{
  "schema": "af_kb.arpg.mechanics.v1",
  "mechanic": "arpg",
  "entity_kind": "mechanic_profile",
  "subtype": "combat_core",
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

## 3. Список entity types

### 3.1. Public catalog types

* `arpg_origin`
* `arpg_archetype`
* `arpg_faction`
* `arpg_bestiary`
* `arpg_ability`
* `arpg_talent`
* `arpg_item`
* `arpg_lore`

### 3.2. Service / mechanics types

* `arpg_mechanic_profile`
* `arpg_resource_def`
* `arpg_status_def`
* `arpg_modifier_template`
* `arpg_formula_def`
* `arpg_trigger_template`
* `arpg_condition_template`
* `arpg_scaling_table`
* `arpg_combat_template`
* `arpg_snippet`

---

## 4. Registry / enums / справочники

Это не отдельные публичные плитки каталога. Это каноничные enum/registry, которые должны быть доступны service-механике и editor UI.

---

### 4.1. Damage types

Базовый общий enum `damage_type`:

* `physical`
* `true`
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
* `corruption`
* `sonic`
* `plasma`
* `pierce`
* `slash`
* `blunt`
* `custom`

Примечание:
это intentionally merged enum под несколько ARPG-франшиз. Конкретная игра может ограничивать допустимый subset через `mechanic_profile`.

---

### 4.2. Targeting

* `self`
* `single_enemy`
* `single_ally`
* `multi_enemy`
* `multi_ally`
* `line`
* `cone`
* `aoe_ground`
* `aoe_around_self`
* `aoe_around_target`
* `chain`
* `global`
* `summon_anchor`
* `custom`

---

### 4.3. Resource operation kinds

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

---

### 4.4. Modifier modes

* `flat`
* `percent`
* `multiplier`
* `override`
* `cap`
* `floor`
* `convert`
* `formula_ref`
* `table_ref`

---

### 4.5. Stacking policies

* `none`
* `refresh`
* `replace`
* `stack_additive`
* `stack_multiplicative`
* `independent_instances`
* `highest_only`
* `lowest_only`
* `custom`

---

### 4.6. Trigger events

* `on_spawn`
* `on_enter_combat`
* `on_exit_combat`
* `on_cast`
* `on_hit`
* `on_crit`
* `on_kill`
* `on_damage_taken`
* `on_shield_break`
* `on_dodge`
* `on_parry`
* `on_status_apply`
* `on_status_expire`
* `on_resource_empty`
* `on_resource_full`
* `on_equip`
* `on_unequip`
* `on_tick`
* `on_wave_start`
* `on_wave_end`
* `custom`

---

### 4.7. Rank / rarity enums

#### Talent rank

* `common`
* `uncommon`
* `rare`
* `epic`
* `legendary`
* `mythic`

#### Item rarity

* `common`
* `uncommon`
* `rare`
* `epic`
* `legendary`
* `mythic`
* `set`
* `unique`
* `custom`

#### Bestiary rank

* `trash`
* `normal`
* `elite`
* `champion`
* `boss`
* `world_boss`
* `raid_boss`
* `custom`

---

### 4.8. Item kinds

* `weapon`
* `armor`
* `accessory`
* `artifact`
* `consumable`
* `material`
* `quest`
* `custom`

---

### 4.9. Equip slots

Базовый merged enum:

* `weapon_one_hand`
* `weapon_two_hand`
* `weapon_ranged`
* `weapon_polearm`
* `weapon_catalyst`
* `weapon_sidearm`
* `head`
* `chest`
* `legs`
* `hands`
* `feet`
* `ring`
* `amulet`
* `trinket`
* `artifact_core`
* `artifact_aux`
* `custom`

---

## 5. Public types

---

## 5.1. `arpg_origin`

### Назначение

Происхождение / род / биотип / стартовая платформа персонажа.
Это источник базовой физиологии, стартового HP, стартового урона, стартовой защиты, расовых/видовых особенностей и стартовых выборов.

### Обязательные поля

* `size`
* `creature_type`
* `base_hp`
* `base_damage`
* `base_defense`
* `movement_speed`
* `choices[]`
* `grants[]`
* `traits[]`
* `modifiers[]`
* `effects[]`

### Рекомендуемые optional поля

* `requirements[]`
* `resource_bonuses[]`
* `status_resists[]`
* `allowed_talent_tags[]`
* `allowed_item_tags[]`
* `lore_hooks[]`
* `starting_resources[]`
* `starting_flags[]`

### Canonical `data_json.data`

```json
{
  "origin": {
    "size": "medium",
    "creature_type": "humanoid",
    "base_hp": 100,
    "base_damage": 10,
    "base_defense": 5,
    "movement_speed": 100
  },
  "requirements": [],
  "choices": [],
  "grants": [],
  "traits": [],
  "modifiers": [],
  "effects": [],
  "resource_bonuses": [],
  "status_resists": [],
  "allowed_talent_tags": [],
  "allowed_item_tags": [],
  "starting_resources": [],
  "starting_flags": []
}
```

### Комментарий

`choices`, `grants` и `traits` здесь обязательны не потому, что у каждой origin обязаны быть все три, а потому что editor/validator path должен иметь стабильный контракт и не жить на “пусто/непусто по случаю”.

---

## 5.2. `arpg_archetype`

### Назначение

Боевая роль / классоподобная специализация.
Определяет профиль урона/защиты, привязку к ресурсам, набор слотов и стиль прогрессии.

### Обязательные поля

* `role`
* `damage_bias`
* `defense_bias`
* `resource_affinity`
* `slot_rules[]`
* `grants[]`
* `modifiers[]`
* `effects[]`

### Рекомендуемые optional поля

* `requirements[]`
* `ability_slot_rules[]`
* `resource_rules[]`
* `equipment_affinity[]`
* `progression_hooks[]`
* `talent_branch_access[]`
* `archetype_tags[]`

### Canonical `data_json.data`

```json
{
  "archetype": {
    "role": "striker",
    "damage_bias": "high",
    "defense_bias": "low",
    "resource_affinity": "energy"
  },
  "requirements": [],
  "slot_rules": [],
  "ability_slot_rules": [],
  "resource_rules": [],
  "equipment_affinity": [],
  "progression_hooks": [],
  "talent_branch_access": [],
  "grants": [],
  "modifiers": [],
  "effects": [],
  "archetype_tags": []
}
```

---

## 5.3. `arpg_faction`

### Назначение

Фракция, репутация, отношения, вендоры, доступ к зонам и сюжетным веткам.
Не является heavy combat-сущностью.

### Обязательные поля

* `standing_model`
* `vendor_access[]`
* `story_flags[]`

### Рекомендуемые optional поля

* `requirements[]`
* `faction_modifiers[]`
* `reputation_thresholds[]`
* `restricted_tags[]`
* `allied_factions[]`
* `hostile_factions[]`

### Canonical `data_json.data`

```json
{
  "faction": {
    "standing_model": "neutral"
  },
  "requirements": [],
  "vendor_access": [],
  "story_flags": [],
  "faction_modifiers": [],
  "reputation_thresholds": [],
  "restricted_tags": [],
  "allied_factions": [],
  "hostile_factions": []
}
```

### Комментарий

Faction может содержать условные бонусы, но не должна становиться второй ability/item схемой.

---

## 5.4. `arpg_ability`

### Назначение

Любая активная, пассивная, ультимативная или служебная способность.

### Обязательные поля

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
* `resources[]`
* `effects[]`
* `modifiers[]`
* `triggers[]`
* `conditions[]`
* `stacking[]`
* `scaling[]`
* `upgrade_requirements[]`
* `level_cap`

### Рекомендуемые optional поля

* `requirements[]`
* `status_refs[]`
* `formula_refs[]`
* `snippet_refs[]`
* `animation_refs[]`
* `ui_hints`
* `cancel_rules`
* `combo_rules`
* `summon_rules`
* `field_rules`

### Canonical `data_json.data`

```json
{
  "ability": {
    "type": "active",
    "subtype": "aura",
    "slot": "skill_1",
    "damage_type": "ice",
    "targeting": "single_enemy",
    "range": 12,
    "cast_time": 0.3,
    "cooldown": 8,
    "duration": 5,
    "max_charges": 1,
    "level_cap": 20
  },
  "requirements": [],
  "resources": [],
  "effects": [],
  "modifiers": [],
  "triggers": [],
  "conditions": [],
  "stacking": [],
  "scaling": [],
  "upgrade_requirements": [],
  "status_refs": [],
  "formula_refs": [],
  "snippet_refs": [],
  "animation_refs": [],
  "cancel_rules": [],
  "combo_rules": [],
  "summon_rules": [],
  "field_rules": []
}
```

### Норматив по `effects[]`

Каждый effect обязан иметь как минимум:

* `kind`
* `targeting`
* и один источник численного значения:

  * `value`
  * или `formula_ref`
  * или `table_ref`

Базовые `kind`:

* `damage`
* `heal`
* `shield`
* `barrier`
* `status_apply`
* `status_remove`
* `buff`
* `debuff`
* `summon`
* `aura`
* `field`
* `cleanse`
* `control`
* `displacement`
* `resource_gain`
* `resource_spend`
* `resource_drain`
* `stack_gain`
* `stack_consume`
* `proc`
* `custom`

### Пример ability-эффекта

```json
{
  "kind": "shield",
  "targeting": "self",
  "damage_type": "ice",
  "value_mode": "formula_ref",
  "formula_ref": "arpg_formula_def:diona_shield_hp_ratio_v1",
  "duration": 5,
  "status_ref": "",
  "notes": ""
}
```

---

## 5.5. `arpg_talent`

### Назначение

Талант, нода дерева, билдовая пассивка, сокетируемая или открываемая усилялка.

### Обязательные поля

* `tree`
* `tier`
* `rank`
* `slot_type`
* `node_label`
* `effects[]`
* `passive_effects[]`
* `modifiers[]`
* `grants[]`
* `requirements[]`
* `mutual_exclusives[]`
* `rank_weight`
* `socket_cost`

### Рекомендуемые optional поля

* `unlock_rules[]`
* `ui_position`
* `branch_tags[]`
* `synergy_tags[]`
* `equip_requirements[]`

### Canonical `data_json.data`

```json
{
  "talent": {
    "tree": "offense",
    "tier": 1,
    "rank": "rare",
    "slot_type": "passive",
    "node_label": "cold_focus",
    "rank_weight": 2,
    "socket_cost": 1
  },
  "requirements": [],
  "mutual_exclusives": [],
  "effects": [],
  "passive_effects": [],
  "modifiers": [],
  "grants": [],
  "unlock_rules": [],
  "branch_tags": [],
  "synergy_tags": [],
  "equip_requirements": []
}
```

### Комментарий

В `arpg_talent` нет отдельной long progression как у ability, но есть:

* rarity/rank;
* cost/socket semantics;
* совместимость/исключаемость;
* grants/modifiers/effects.

---

## 5.6. `arpg_item`

### Назначение

Оружие, броня, аксессуар, артефакт, расходник, материал и другие игровые предметы.

### Обязательные поля

* `item_kind`
* `equip_slot`
* `rarity`
* `subtype`
* `level_min`
* `level_max`
* `progression_stage`
* `base_stats[]`
* `substats[]`
* `modifiers[]`
* `effects[]`
* `passive_effects[]`
* `triggers[]`
* `grants[]`
* `upgrade_steps[]`
* `level_cap`

### Рекомендуемые optional поля

* `requirements[]`
* `set_tags[]`
* `durability`
* `refine_rules[]`
* `on_use[]`
* `on_equip[]`
* `passive_refs[]`
* `drop_tags[]`
* `economy_tags[]`

### Canonical `data_json.data`

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
  "requirements": [],
  "base_stats": [],
  "substats": [],
  "modifiers": [],
  "effects": [],
  "passive_effects": [],
  "triggers": [],
  "grants": [],
  "upgrade_steps": [],
  "set_tags": [],
  "durability": {},
  "refine_rules": [],
  "on_use": [],
  "on_equip": [],
  "passive_refs": [],
  "drop_tags": [],
  "economy_tags": []
}
```

### Норматив по `upgrade_steps[]`

Каждый шаг:

* `from_level`
* `to_level`
* `requirements.items[]`
* `requirements.currency[]`
* `effects[]` или `modifiers[]` или `stats_delta[]`

Пример:

```json
{
  "from_level": 10,
  "to_level": 20,
  "requirements": {
    "items": [
      { "key": "ice_crystal_shard", "qty": 3 }
    ],
    "currency": [
      { "key": "credits", "qty": 1500 }
    ]
  },
  "stats_delta": [
    { "stat": "atk", "mode": "flat", "value": 12 }
  ]
}
```

---

## 5.7. `arpg_bestiary`

### Назначение

Сущности бестиария: монстры, элиты, боссы, животные, техногенные враги, иные противники.

### Обязательные поля

* `family`
* `archetype`
* `faction`
* `rank`
* `threat_tier`
* `level`
* `hp`
* `atk`
* `def`
* `armor`
* `crit_rate`
* `crit_dmg`
* `status_hit`
* `status_resist`
* `resists[]`
* `weaknesses[]`
* `ability_refs[]`
* `loot[]`

### Рекомендуемые optional поля

* `passive_refs[]`
* `pattern_refs[]`
* `phases[]`
* `summon_refs[]`
* `arena_rules[]`
* `reward_currency[]`
* `reward_exp`
* `drop_tables[]`
* `ai_tags[]`

### Canonical `data_json.data`

```json
{
  "bestiary": {
    "family": "beast",
    "archetype": "skirmisher",
    "faction": "wild",
    "rank": "elite",
    "threat_tier": 2,
    "level": 14
  },
  "combat_stats": {
    "hp": 1200,
    "atk": 85,
    "def": 45,
    "armor": 15,
    "crit_rate": 0.05,
    "crit_dmg": 0.50,
    "status_hit": 0.10,
    "status_resist": 0.15
  },
  "resists": [],
  "weaknesses": [],
  "ability_refs": [],
  "passive_refs": [],
  "pattern_refs": [],
  "phases": [],
  "summon_refs": [],
  "arena_rules": [],
  "loot": [],
  "reward_currency": [],
  "reward_exp": 0,
  "drop_tables": [],
  "ai_tags": []
}
```

### Норматив по `ability_refs[]`

Список ссылок на `arpg_ability`, а не inline-описания.

### Норматив по `loot[]`

Каждая запись:

* `kind`
* `item_ref` или `table_ref`
* `chance`
* `qty_min`
* `qty_max`

---

## 5.8. `arpg_lore`

### Назначение

Чистый энциклопедический и narrative-контент без обязательной combat-схемы.

### Обязательные поля

* `content_blocks[]`

### Рекомендуемые optional поля

* `linked_entities[]`
* `timeline[]`
* `source`
* `tags[]`
* `index_flags[]`

### Canonical `data_json.data`

```json
{
  "lore": {
    "content_blocks": []
  },
  "linked_entities": [],
  "timeline": [],
  "source": {},
  "index_flags": []
}
```

### Комментарий

Для `arpg_lore` не нужен combat validator.

---

## 6. Service / mechanics types

---

## 6.1. `arpg_mechanic_profile`

### Назначение

Главный системный профиль ARPG-ветки:

* реестр статов;
* реестр ресурсов;
* реестр статусов;
* разрешённые enum;
* правила стэкинга;
* charge/cooldown policies;
* template registries.

### Canonical `data_json.data`

```json
{
  "stats": [],
  "resources": [],
  "statuses": [],
  "rules": {
    "stacking_policies": [],
    "cooldowns": true,
    "charges": true,
    "costs": true
  },
  "registries": {
    "damage_types": [],
    "targeting": [],
    "modifier_modes": [],
    "resource_ops": [],
    "trigger_events": [],
    "talent_ranks": [],
    "item_rarities": [],
    "bestiary_ranks": []
  },
  "template_registry": {
    "modifier_templates": [],
    "trigger_templates": [],
    "condition_templates": [],
    "formula_defs": [],
    "scaling_tables": [],
    "combat_templates": [],
    "snippets": []
  }
}
```

---

## 6.2. `arpg_resource_def`

### Назначение

Описание ресурса: энергия, ярость, мана, выносливость, боезапас, заряд и т.п.

### Canonical `data_json.data`

```json
{
  "resource": {
    "key": "energy",
    "kind": "combat",
    "min": 0,
    "max": 100,
    "regen": 0,
    "decay": 0,
    "display": {
      "label": "Energy",
      "unit": "",
      "color": "#66ccff"
    }
  }
}
```

---

## 6.3. `arpg_status_def`

### Назначение

Каноничное описание статуса.

### Canonical `data_json.data`

```json
{
  "status": {
    "key": "freeze",
    "kind": "debuff",
    "duration": 2.5,
    "stacking": "replace",
    "tick": 0,
    "cleanup": "expire",
    "interactions": []
  }
}
```

---

## 6.4. `arpg_modifier_template`

### Назначение

Переиспользуемый шаблон модификатора.

### Canonical `data_json.data`

```json
{
  "modifier_template": {
    "key": "flat_atk_bonus",
    "target": "atk",
    "mode": "flat",
    "value_source": "inline"
  }
}
```

---

## 6.5. `arpg_formula_def`

### Назначение

Формула, которую могут использовать ability/item/talent/effect/scaling.

### Canonical `data_json.data`

```json
{
  "formula": {
    "key": "shield_from_hp_ratio",
    "inputs": ["hp_max", "talent_level"],
    "expression": "(hp_max * 0.18) + (talent_level * 12)",
    "rounding": "floor",
    "clamps": {
      "min": 0,
      "max": null
    }
  }
}
```

---

## 6.6. `arpg_trigger_template`

### Назначение

Шаблон типового триггера.

### Canonical `data_json.data`

```json
{
  "trigger_template": {
    "key": "on_hit_apply_status",
    "event": "on_hit",
    "params_schema": {
      "status_ref": "string",
      "chance": "number"
    }
  }
}
```

---

## 6.7. `arpg_condition_template`

### Назначение

Шаблон типового условия.

### Canonical `data_json.data`

```json
{
  "condition_template": {
    "key": "hp_below_percent",
    "params_schema": {
      "threshold": "number"
    }
  }
}
```

---

## 6.8. `arpg_scaling_table`

### Назначение

Таблица скейлинга для ability/item/talent.

### Canonical `data_json.data`

```json
{
  "scaling_table": {
    "key": "weapon_growth_v1",
    "rows": []
  }
}
```

---

## 6.9. `arpg_combat_template`

### Назначение

Переиспользуемый комплексный combat-шаблон:

* пак типовых эффектов;
* ruleset атаки/волны/фазы;
* reusable enemy/ability package.

### Canonical `data_json.data`

```json
{
  "combat_template": {
    "key": "boss_phase_two_enrage",
    "effects": [],
    "modifiers": [],
    "triggers": [],
    "conditions": []
  }
}
```

---

## 6.10. `arpg_snippet`

### Назначение

Переиспользуемый кусок механики или display/data payload.

### Canonical `data_json.data`

```json
{
  "snippet": {
    "key": "common_freeze_package",
    "payload": {}
  }
}
```

---

## 7. Normalized downstream contract

### 7.1. Envelope

```json
{
  "schema": "af_kb.arpg.sheet-normalized.v1",
  "entity_ref": "arpg_ability:ice_shield",
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

### 7.2. Разделы normalized payload

#### `normalized.modifiers`

Единый список модификаторов:

```json
{
  "target": "atk",
  "stat": "atk",
  "mode": "flat",
  "value": 12,
  "duration": 0,
  "condition_ref": "",
  "source_ref": "arpg_item:frost_blade"
}
```

#### `normalized.item_profile`

* equip_slot
* rarity
* base_stats
* substats
* passive_refs
* set_tags

#### `normalized.ability_profile`

* type
* subtype
* slot
* targeting
* damage_type
* effects
* rank_scaling

#### `normalized.costs`

Единый контракт стоимости действия.

#### `normalized.cooldown`

Единый контракт cooldown / charges.

#### `normalized.resources`

Все изменения ресурсов в одном формате.

#### `normalized.statuses`

Все status applications / removals / immunity / resist contributions.

#### `normalized.triggers`

Trigger graph для калькулятора и боевой логики.

#### `normalized.conditions`

Все conditions в одном массиве.

#### `normalized.formulas`

Все formula refs, раскладываемые для вычислителя.

#### `normalized.scaling`

Все table refs / rank growth / level growth.

---

## 8. Раскладка по слоям данных

### 8.1. Что хранить в `meta.rules`

Только контракт и роутинг:

* `schema`
* `version`
* `profile_ref`
* `normalization_hints`
* `compat_flags`

**Нельзя** складывать туда полную механику ability/item/talent.

---

### 8.2. Что хранить в `data_json.data`

Всю расчётную механику:

* requirements
* grants
* effects
* modifiers
* resources
* cooldown
* charges
* scaling
* triggers
* conditions
* stacking
* refs на service entries

---

### 8.3. Что хранить в `data_json.blocks`

Display/UX content:

* лор;
* нарратив;
* советы;
* usage notes;
* build tips;
* таблицы и подсказки для человека.

---

## 9. Deprecated / устаревающее

Следующее считается устаревшим и не должно быть каноном новой схемы:

1. Почти пустой ARPG seed-контракт вида:

   * `schema`
   * `mechanic`
   * `entity_kind`
   * `subtype`
   * `category`
   * `tags`
     без полноценного `data_json.data` payload.

2. Полуабстрактные ARPG entries, где есть только envelope, но нет:

   * стабильных обязательных секций;
   * каноничной структуры `data_json.data`.

3. Подход, где bestiary “то есть, то нет”:

   * в новом каноне `arpg_bestiary` — полноценный public type.

4. Неявные payload sections вида:

   * где validator ждёт массивы, но schema их явно не описывает.

5. Непоследовательные старые alias-типы:

   * `arpg_ability_active`
   * `arpg_ability_passive`
   * `arpg_modifier`
   * `arpg_status`
   * `arpg_resource`
   * `arpg_mechanics`
     Они допускаются только как legacy migration aliases, но не как каноничные type keys.

6. Хранение уникальной сложной механики в `meta.rules` вместо `data_json.data`.

7. Раздувание public catalog за счёт status/resource/formula/trigger как публичных плиток.

---

## 10. Что должно быть внедрено во второй задаче правок

Во второй задаче кодовых правок в `knowledgebase.php` нужно:

1. Переписать `af_kb_default_arpg_type_definitions()` под этот новый contract.
2. Дать полноценные `root_defaults` и `fields` для:

   * arpg_origin
   * arpg_archetype
   * arpg_faction
   * arpg_bestiary
   * arpg_ability
   * arpg_talent
   * arpg_item
   * arpg_lore
3. Сохранить service envelope rules:

   * `category=service.mechanics`
   * `visibility.internal=true`
   * `visibility.catalog=false`
   * `visibility.search=false`
4. Переписать `af_kb_validate_arpg_public_entity()`.
5. Переписать `af_kb_validate_arpg_service_entity()`.
6. Обновить `af_kb_validate_arpg_entry_by_type()`.
7. Сохранить DnD path нетронутым.
8. Оставить миграционный слой для legacy ARPG payloads.
9. Не лезть в JS/UI в этой задаче.
10. После PHP-правок уже отдельно делать editor UI path в `knowledgebase.js`.

---

## 11. Практический итог

Новый канон ARPG KB держится на четырёх опорах:

1. **Public + service split**
2. **Жёсткий envelope**
3. **Machine-readable payload в `data_json.data`**
4. **Normalized downstream contract для CharacterSheets / Inventory / Shop**

Это и есть новая source-of-truth схема для ARPG-ветки KB.

```

