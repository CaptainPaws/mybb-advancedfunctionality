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
