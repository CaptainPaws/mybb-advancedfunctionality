# KB Meta v2 → CharacterSheets mapping

Источник полей: `af_kb.meta.v2` и вложенные `rules`/`item` структуры из KnowledgeBase.

| KB JSON field | Где используется в CharacterSheets | Статус |
|---|---|---|
| `meta_json.schema` (`af_kb.meta.v1/v2`) | `cs_kb_get_meta`, `af_charactersheets_kb_normalize_entry` | ✅ применяется |
| `meta_json.rules.schema` (`af_kb.rules.v1`) | `cs_kb_get_data_rules`, `af_cs_kb_get_data_rules_result` | ✅ применяется |
| `rules.fixed.stats.*` | `af_cs_aggregate_rules`, `af_charactersheets_compute_sheet_view` | ✅ применяется |
| `rules.fixed_bonuses.stats.*` | `af_cs_aggregate_rules`, `af_charactersheets_compute_sheet_view` | ✅ применяется |
| `rules.fixed(_bonuses).hp/speed/armor/skill_points/...` | `af_cs_aggregate_rules`, механики/пулы в `compute_sheet_view` | ✅ применяется |
| `rules.hp_base` | `af_cs_aggregate_rules`, HP breakdown | ✅ применяется |
| `rules.grants[]` | агрегируется и применяется в пулах/skills, + trace в rules engine | ✅ применяется |
| `rules.choices[]` | `stat_bonus`, `skill_pick_choice` в `compute_sheet_view` | ✅ применяется |
| `rules.traits[]` + `traits[].grants[]` | grants/skill grants, список traits в computed state | ✅ применяется |
| `rules.resistances/immunities/weaknesses` | читается в normalized rules и в computed state/debug | ⚠️ отображается в debug/computed state, но не во всех UI-блоках |
| `rules.modifiers/effects/resources` | читается и частично учитывается в `bonus_items`/computed state | ⚠️ частично применяется (зависит от типа modifier) |
| `item.cyberware.humanity_cost_percent` | `af_charactersheets_extract_humanity_cost_from_data`, humanity recalculation | ✅ применяется |
| `item.cyberware.modifiers/effects/grants` | читается в `af_charactersheets_normalize_bonus_items` | ⚠️ частично применяется (механика зависит от типа гранта/модификатора) |
| `spell.*` / spell entry in KB | доступно в normalized rules/computed state | ❌ не подключено к отдельному боевому spell-engine |

## Минимальные реальные профили в KB коде

- `race/class/theme/skill/knowledge/language/spell/item` профили и defaults с rules-схемами определены в `af_kb_get_type_profile_definition()`.
- Для `item` используется `af_kb.item.v2` с `cyberware.humanity_cost_percent/modifiers/effects/grants`.
- Для `spell` используется rules-профиль со `spell` + `effects`.

