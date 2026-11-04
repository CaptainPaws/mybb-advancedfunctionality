# ARPG Origin / Origin Variant JSON contract

Audit baseline: PHP 8.5.0, MyBB 1.8.40, and the current AdvancedFunctionality KB architecture.

## Actual persisted Origin JSON

An Origin is stored in the standard `af_kb.arpg.meta.v1` envelope. With the
current defaults, a newly created entry has this shape (content values are an
example, but the keys and nesting are the runtime contract):

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
    "type_profile": "origin",
    "version": "1.0",
    "size": "medium",
    "creature_type": "humanoid",
    "movement_speed": 100,
    "hp_base": 100,
    "defense_base": 5,
    "attack_power_base": 10,
    "crit_damage_base": 0,
    "elemental_mastery_base": 0,
    "elemental_damage_bonus_base": 0,
    "healing_bonus_base": 0,
    "shield_bonus_base": 0,
    "hp_per_level": 0,
    "defense_per_level": 0,
    "attack_power_per_level": 0,
    "elemental_mastery_per_level": 0,
    "racial_bonuses_text": "",
    "racial_traits_text": "",
    "starting_notes": ""
  }
}
```

## Actual persisted Origin Variant JSON

Origin Variant uses the same envelope and rules schema. A modifier created by
the structured editor is stored only in `rules.modifiers`:

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
    "type_profile": "origin_variant",
    "version": "1.0",
    "inherits_from_origin": true,
    "modifiers": [
      {
        "stat_key": "hp",
        "mode": "flat",
        "value": 150,
        "notes": "Birfolk vitality"
      }
    ],
    "effects": [],
    "grants": [],
    "resources": [],
    "resistances": [],
    "weaknesses": []
  }
}
```

This is also the unified proposed structure: no migration or second modifier
payload is needed. Existing and unknown envelope/rules keys are preserved by
the normal KB edit round trip.

## Parent relation

The actual project contract keeps entity graph edges in `af_kb_relations`.
The Origin Variant parent is the existing `origin_has_variant` relation (Origin
as `from`, Origin Variant as `to`), selected through `origin_parent_key` in the
editor. It is not copied into `rules.modifiers` or a new JSON sidecar. Relation
row `meta_json` remains part of the existing relation contract and is preserved
by the relation editor.

## Mechanical stat mapping

The selector is derived at runtime from numeric fields in the Origin default
schema. Presentation/reference fields such as `size`, `creature_type`,
`racial_bonuses_text`, `racial_traits_text`, and `starting_notes` are excluded.

| Origin schema field | Canonical `rules.modifiers[].stat_key` | Character Sheet VM field | Semantics |
|---|---|---|---|
| `hp_base` | `hp` | `character_hp` | flat final-stat delta |
| `defense_base` | `def` | `character_defense` | flat final-stat delta |
| `attack_power_base` | `atk` | `character_attack_power` | flat final-stat delta |
| `movement_speed` | `speed` | `character_speed` | flat final-stat delta |
| `crit_damage_base` | `crit_dmg` | `character_crit_damage` | flat final-stat delta |
| `elemental_mastery_base` | `mastery` | `character_elemental_mastery` | flat final-stat delta |
| `elemental_damage_bonus_base` | `element_damage_bonus` | `character_element_damage_bonus` | flat final-stat delta |
| `healing_bonus_base` | `healing_bonus` | `character_healing_bonus` | flat final-stat delta |
| `shield_bonus_base` | `shield_strength` | `character_shield_strength` | flat final-stat delta |
| `hp_per_level` | `hp_per_level` | `character_hp` | formula-growth delta, multiplied by `max(0, level - 1)` |
| `defense_per_level` | `defense_per_level` | `character_defense` | formula-growth delta, multiplied by `max(0, level - 1)` |
| `attack_power_per_level` | `attack_power_per_level` | `character_attack_power` | formula-growth delta, multiplied by `max(0, level - 1)` |
| `elemental_mastery_per_level` | `elemental_mastery_per_level` | `character_elemental_mastery` | formula-growth delta, multiplied by `max(0, level - 1)` |

Only `flat` is currently supported. The machine values above are persisted;
the editor displays their human-readable labels and exposes Stat, Operation,
Value, and Notes controls.

## Persistence and compatibility result

- Modifier storage path: canonical ARPG entry JSON → `rules.modifiers[]`.
- Parent storage path: existing `af_kb_relations` → `origin_has_variant`.
- Create/save/reopen/edit/save uses the ordinary `kb_edit` JSON pipeline and
  retains modifier order and values without synthesizing duplicates.
- Existing keys, unknown rules, UI data, blocks, and existing relation rows are
  retained; there is no destructive migration.
- Single JSON/schema contract: **YES**.
- Parallel modifier storage: **NO**.
- Destructive migration: **NONE**.
