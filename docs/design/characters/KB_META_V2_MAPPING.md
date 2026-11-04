# KB Meta v2 → CharacterSheets mapping

Источник полей: `af_kb.meta.v2` и вложенные `rules`/`item` структуры из KnowledgeBase.

| KB JSON field | Где используется в CharacterSheets | Статус |
|---|---|---|
| `meta_json.schema` (`af_kb.meta.v1/v2`) | `cs_kb_get_meta`, `af_charactersheets_kb_normalize_entry` | ✅ применяется |
| `meta_json.rules.schema` (`af_kb.rules.v1`) | `cs_kb_get_data_rules`, `af_cs_kb_get_data_rules_result` | ✅ применяется |
| `rules.fixed.stats.*` | `af_cs_aggregate_rules`, `apply_kb_rules_to_character_state`, `compute_sheet_view` | ✅ применяется |
| `rules.fixed_bonuses.stats.*` | `af_cs_aggregate_rules`, `apply_kb_rules_to_character_state`, `compute_sheet_view` | ✅ применяется |
| `rules.fixed.hp/armor/initiative/speed/carry/ep/damage/skill_points/knowledge_slots/language_slots` | aggregate + computed state pools | ✅ применяется |
| `rules.fixed_bonuses.hp/armor/initiative/speed/carry/ep/damage/skill_points/knowledge_slots/language_slots/attribute_points/feat_points/perk_points` | aggregate + computed state pools | ✅ применяется |
| `rules.hp_base` | `af_cs_aggregate_rules`, HP breakdown | ✅ применяется |
| `rules.speed` | aggregate + mechanics speed | ✅ применяется |
| `rules.languages[]` | rules engine (`languages`), computed state/debug | ✅ применяется |
| `rules.grants[]` | `apply_kb_rules_to_character_state`; op: resistance/immunity/weakness/resource/sense/skill/perk/trait/feature/language/knowledge/... | ✅ применяется |
| `rules.choices[]` | `stat_bonus`, `skill_pick_choice`, skill/attr pools в `compute_sheet_view` | ✅ применяется |
| `rules.traits[]` + `traits[].grants[]` | rules engine + passive abilities + trace | ✅ применяется |
| `rules.modifiers[]` | rules engine/modifier trace + computed state/debug | ✅ применяется |
| `rules.effects[]` | rules engine/effect trace + computed state/debug | ✅ применяется |
| `rules.resources{}` | rules engine/resources + computed state/debug | ✅ применяется |
| `rules.resistances/immunities/weaknesses` | rules engine + computed state/debug | ✅ применяется |
| `rules.spell` | сохраняется в normalized rules/computed state/debug (без отдельного combat-engine) | ⚠️ пассивно отображается |
| `item.cyberware.humanity_cost_percent` | `af_charactersheets_extract_humanity_cost_from_data`, humanity recalculation | ✅ применяется |
| `item.cyberware.modifiers/effects/grants` | читается в `af_charactersheets_normalize_bonus_items` + rules trace/debug | ✅ применяется |

## Минимальные реальные профили в KB коде

- `race/class/theme/skill/knowledge/language/spell/item` профили и defaults с rules-схемами определены в `af_kb_get_type_profile_definition()`.
- Для `item` используется `af_kb.item.v2` с `cyberware.humanity_cost_percent/modifiers/effects/grants`.
- Для `spell` используется rules-профиль со `spell` + `effects`.
