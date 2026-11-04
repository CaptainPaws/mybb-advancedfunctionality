# Character Sheet rules pipeline audit

## Runtime pipeline

The persisted source remains one KB entry JSON document. `data_json` is read as
the compatibility projection and `meta_json.rules` as its fallback; the KB
consumer adapter then unwraps the ARPG envelope. Character Sheets resolves ATF
keys, normalizes that rules leaf, dispatches it to the DnD rules state or the
ARPG VM aggregator, and renderers only format VM values.

| Mechanic | Writer / stored key | KB type | Reader and calculation |
|---|---|---|---|
| DnD race | AdvancedThreadFields `character_race` | `race` | `cs_get_sheet_kb_sources` → `af_cs_build_rules_sources` → `af_cs_aggregate_rules` and `apply_kb_rules_to_character_state` |
| DnD variant | AdvancedThreadFields `character_race_variant` (aliases `race_variant`, `racevariant`) | `race_variant` (`racevariant` legacy fallback) | Same DnD aggregate; it is a separate source exactly once |
| ARPG origin | CharacterWorkflow character contract `profile.character_origin`, with ATF `character_origin` / `character_race` fallback | `arpg_origin` | `af_charactersheets_arpg_extract_entry_rules` → `af_charactersheets_arpg_build_stats_from_kb` |
| ARPG variant | CharacterWorkflow `profile.character_origin_variant`, with ATF `character_origin_variant` / `origin_variant` fallback | `arpg_origin_variant` | Parent relation validation → the same extractor → one variant modifier application |
| ARPG equipment | Character Sheet `build_json.equipment.slots.*.{type,key}` | referenced ARPG item type | Same extractor → unconditional `flat` `base_stats` / `modifiers` source |

KnowledgeBase owns the entry JSON and origin/variant relation. AdvancedThreadFields
and CharacterWorkflow store selection keys, not copies of rules. CharacterSheets
is the reader/calculator. The explicit aliases above prevent a writer key such as
`origin_variant` from silently being read as an unrelated `variant_key`.

## Operation semantics

* Origin variants support `flat` only, as declared by the existing operation
  registry and validator. Negative, zero, decimal, and repeated flat rows are
  additive.
* Per-level rows modify the growth term before it is multiplied by
  `max(0, level - 1)`.
* Equipment contributes unconditional `flat` rows. Existing `percent` rows and
  conditional rows remain preserved in JSON and visible to rules/debug data,
  but are not guessed at by a context-free sheet calculation.
* `multiply` and `override` have no supported ARPG Character Sheet execution
  contract. They were not added.

## Collections

ARPG rules collections are merged before being placed in the VM. Numeric
`resistances`, `weaknesses`, and `resources` add by key. `immunities`,
`abilities`, `skills`, and `proficiencies` union by key; `grants` append in
source order. A variant therefore cannot replace its parent's collection.

## Outputs

The full page and iframe modal use the same `af_charactersheets_build_arpg_view_model`
result and inner template. Postbit opens that same modal route. The combat block
also reads HP, defense, attack, and speed from calculated `character_stats`; it
does not repeat modifier arithmetic in a renderer.

## Regression matrix

| Case | Input / stored JSON | Normalized rules | VM / rendered expectation |
|---|---|---|---|
| DnD race | `race` JSON | race source | Existing aggregate unchanged |
| DnD race + variant | `race` + `race_variant` JSON | two ordered sources | Additive fixed values and unioned collections |
| ARPG origin | `hp_base: 1000` | origin rules leaf | HP `1000` |
| ARPG origin + variant | variant `modifiers[{hp,flat,150}]` | modifier retained once | HP `1150` |
| ARPG origin + variant + equipment | equipment `base_stats[{hp,flat,50}]` | three source layers | HP `1200` |
| ARPG origin + resource | `resources: {rage: 5}` | numeric collection | VM resource `rage: 5` |
| ARPG origin + resistance | origin `fire: 2`, variant `fire: 1` | keyed numeric merge | VM resistance `fire: 3` |

The executable regression test covers the six required combat stats, negative
speed, pre-formula per-level modification, multiple flat sources, ignored
unsupported/conditional operations, collection preservation, JSON extraction,
and the calculated-speed combat summary path.
