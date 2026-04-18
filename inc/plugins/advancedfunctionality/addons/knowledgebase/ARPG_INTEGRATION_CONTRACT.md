# ARPG Integration Contract (single Meta JSON canon)

Документ фиксирует **архитектурный integration contract** для ARPG-ветки KB и downstream-потребителей (CharacterSheets, Advanced Inventory, Advanced Shop, AdvancedThreadFields) без внедрения полного фронта.

База канона: `ARPG_KB_SCHEMA_DESIGN.md`.

---

## 1) Canonical contract

### 1.1 Knowledge Base resolver lock

Для ARPG downstream-resolver в KB закрепляется schema:

- `af_kb.arpg.sheet-normalized.v1`

Это schema-id единого нормализованного выхода для остальных плагинов.

### 1.2 Single Meta JSON input

ARPG entry нормализуется только из **single Meta JSON envelope**:

- `schema`
- `mechanic`
- `ui`
- `blocks`
- `rules`

Где:

- `blocks` = display/content-only слой;
- `rules` = единственный источник механики/математики.

### 1.3 Normalized output (downstream)

Нормализатор ARPG обязан выдавать единый слой `normalized`:

- `normalized.base_stats`
- `normalized.modifiers`
- `normalized.ability_profile`
- `normalized.item_profile`
- `normalized.costs`
- `normalized.cooldown`
- `normalized.statuses`
- `normalized.triggers`
- `normalized.conditions`
- `normalized.scaling`

`normalized` — это единственный контракт для чтения другими плагинами; прямое чтение raw `rules` допустимо только внутри KB-адаптера/нормализатора.

---

## 2) Type/mechanic model

### 2.1 Public ARPG types

- `arpg_origin`
- `arpg_archetype`
- `arpg_faction`
- `arpg_bestiary`
- `arpg_ability`
- `arpg_talent`
- `arpg_item`
- `arpg_lore`

### 2.2 Hidden service layer

Используется единый hidden mechanics type:

- `arpg_mechanics`

Служебные записи различаются через `rules.service_kind`:

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

## 3) Downstream behavior contract

### 3.1 CharacterSheets

CharacterSheets должен работать только через normalized-слой:

- `origin` + `archetype` собираются в базовую stat-модель (`base_stats`);
- `item` даёт `base_stats/modifiers/effects`;
- `talent` даёт `passive_effects/modifiers/grants`;
- ability bar читает:
  - `rules.type`
  - `rules.slot`
  - `rules.cooldown`
  - `rules.resources`
  - `rules.effects`
- bestiary читается отдельным enemy-payload (не смешивается с player-build);
- `blocks` используются только как display content (не для расчётов).

### 3.2 Advanced Inventory

- поддержка `mechanic=arpg` в KB lookup;
- поддержка `kb_type/kb_key` для:
  - `arpg_item`
  - `arpg_ability`
- отдельный subtype path для ability inventory;
- ARPG abilities не смешиваются с DnD abilities.

### 3.3 Advanced Shop

- чтение/выдача слотов для:
  - `arpg_item`
  - `arpg_talent`
- закладывается расширение под unlock-покупки (контракт поля/флага, без обязательного UI в этой задаче);
- текущий provider path не ломается (backward-compatible адаптер).

### 3.4 AdvancedThreadFields

Используется тот же key/label path, что и в DnD:

- ARPG select fields хранят только `key`;
- `label` резолвится из KB по `kb_type` + `key`;
- required mapping:
  - `origin_key` -> `arpg_origin`
  - `archetype_key` -> `arpg_archetype`
  - `faction_key` -> `arpg_faction`

---

## 4) Required helper/resolver functions

Ниже список функций, которые нужно добавить/переписать (имена как целевые контракты):

1. `af_kb_resolve_entry_by_type_key_mechanic(string $type, string $key, string $mechanic='')`
   - универсальный KB lookup по `type/key/mechanic`.

2. `af_kb_arpg_assemble_meta_json(array $kbRow): array`
   - сборка canonical single Meta JSON из storage (`meta_json/data_json/blocks`) в единый envelope.

3. `af_kb_arpg_normalize_entry(array $entryMeta): array`
   - нормализация ARPG entry в `af_kb.arpg.sheet-normalized.v1`.

4. `af_kb_arpg_compose_character_stats(array $originNorm, array $archetypeNorm, array $talentsNorm, array $itemsNorm): array`
   - композиция базовой стат-модели CharacterSheets.

5. `af_kb_arpg_collect_item_modifiers(array $itemNormList): array`
   - агрегация item modifiers/effects в единый модификаторный слой.

6. `af_kb_arpg_resolve_ability_rows(array $abilityKeys, array $context=[]): array`
   - выборка и нормализация ability-строк для ability bar / inventory ability subtype.

7. `af_kb_arpg_resolve_service_kind_entries(string $serviceKind, array $filters=[]): array`
   - резолв hidden `arpg_mechanics` по `rules.service_kind`.

8. `af_kb_arpg_resolve_bestiary_payload(string $enemyKey, array $opts=[]): array`
   - чтение enemy payload, включая abilities/loot для бестиария.

---

## 5) Existing integration points (files)

### 5.1 Knowledge Base

- `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php`
  - ARPG schema constants/registry уже присутствуют;
  - сюда встраиваются: resolver lock, single-meta assembler, normalized resolver API, service_kind resolver.

- `inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js`
  - ARPG editor payload уже формирует `mechanic=arpg`, `rules.service_kind` и ARPG type map;
  - UI-путь остаётся display/editor-only, без переноса туда механических расчётов.

### 5.2 CharacterSheets

- `inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php`
- `inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php`
- `inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php`
  - точки интеграции: сборка character base model, ability rows, bestiary payload, применение normalized.modifiers.

### 5.3 Advanced Inventory

- `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php`
  - точки интеграции: `kb_type/kb_key` resolver для `arpg_item` и `arpg_ability`, отдельный ability subtype path.

### 5.4 Advanced Shop

- `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php`
  - точки интеграции: KB type normalization и source payload для `arpg_item` / `arpg_talent`, future unlock contract.

### 5.5 AdvancedThreadFields

- `inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php`
  - точки интеграции: key/label resolver pipeline, ARPG mapping для `origin/archetype/faction`.

---

## 6) Explicit non-goals (out of scope)

В рамках этой задачи **не делаем**:

1. Полный фронт-редизайн редакторов/витрин.
2. Новый UI combat simulator.
3. Массовую миграцию всех исторических записей с ручным ремаппингом контента.
4. Баланс-пересчёт формул и live-tuning.
5. Полную реализацию unlock UX (только подготовка contract/hooks).
6. Изменение DnD-канона и DnD downstream-путей, кроме безопасной изоляции ARPG.

---

## 7) Anti-duplication and compatibility rules

1. Никакого дублирования механических данных между плагинами: источник истины = KB + normalized layer.
2. Никаких временных костылей вида «если ARPG — читаем блоки вместо rules».
3. Все downstream-плагины читают через единый normalized contract.
4. Backward compatibility достигается adapter-функциями в KB-resolver слое, а не копированием структуры в каждом плагине.
