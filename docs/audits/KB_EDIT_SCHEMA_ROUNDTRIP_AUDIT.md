# KB schema → `kb_edit` → JSON round-trip audit

Baseline audited: PHP 8.5.0, MyBB 1.8.40, current AdvancedFunctionality tree.

## Current JSON/schema structure

An entry uses `af_kb_entries` for localized entry fields and a canonical JSON payload. The editor reads the rules payload from `meta_json.rules` first, then uses `data_json` and the technical `data` block only as legacy fallbacks. A successful save writes the validated payload to both `meta_json.rules` and the compatibility `data_json`/technical block. These are compatibility projections of the same payload, not independent authoring contracts.

DnD payloads use `af_kb.rules.v1` (or `af_kb.item.v2`) with `type_profile`. ARPG payloads use the envelope `af_kb.arpg.meta.v1`: `schema`, `mechanic`, `tags`, `ui`, `blocks`, and nested `rules` (`af_kb.arpg.rules.v1`). `origin_variant` modifiers remain at `rules.modifiers` inside that envelope.

## Schema → form mappings and ownership

| Editor block | JSON path / storage | Definition and form construction | Load/save owner | Kind | Known consumers |
|---|---|---|---|---|---|
| Display / lore blocks | ARPG envelope `blocks[]`; compatibility rows in `af_kb_blocks`; projection in root `meta_json.blocks[]` | ARPG envelope defaults and the JavaScript block editor; relational row template for compatibility blocks | `af_kb_handle_edit()` and ARPG `bindArpgMode()` | Presentation/lore, except explicitly structured effect data | Entry renderer, catalog/modal UI, legacy block readers |
| UI | ARPG envelope `ui`; root `meta_json.ui`; media columns on the entry | Envelope defaults define `icon_class`, `icon_url`, `background_url`, `background_tab_url`; the existing entry media inputs edit them | `bindArpgMode()` synchronizes media inputs; `af_kb_handle_edit()` saves them | Presentation | KB renderer/catalog/modal; no Character Sheet mechanics consumer was found |
| Rules | DnD payload root; ARPG envelope `rules` | `af_kb_get_type_profile_definition_*()`, persisted type `ui_schema_json`, and mechanic-specific JavaScript editor | mechanic-specific validator plus `af_kb_handle_edit()` | Mechanics | KB structured renderer and downstream rules consumers, including Character Sheet normalization |
| Meta | root `meta_json`, notably `schema`, `tags`, `ui`, `blocks`, `rules`, plus legacy/extension keys | DnD meta UI is hand-mapped; ARPG meta is its envelope | editor raw JSON and `af_kb_handle_edit()` | Mixed envelope metadata | KB loaders, renderer, compatibility/migration readers |
| Formula Profile | ARPG ability rule field `rules.formula_profile` (and service registry entries for available profiles) | ARPG ability editor/schema and mechanics option-set definitions | ARPG editor and validator | Mechanics reference | Formula/rules resolvers and structured KB display |
| Relations | `af_kb_relations` rows with per-row `meta_json`; parent origin/race relations have dedicated selectors | relation row template and fixed relation contracts | `af_kb_handle_edit()` | Entity graph/metadata | KB relationship APIs, variant lookup, entry display |

The persisted `ui_schema_json` describes rules-editor fields and display layout; it is not the value of an entry's `ui` object. Runtime ARPG contract fields are overlaid so stale installed type rows do not hide current contract fields.

## UI block purpose and empty state

The ARPG `UI` section was structurally present but had no child controls. Its keys were not absent from the schema: they are created by ARPG envelope defaults, occur in older canonical/legacy envelopes, are loaded into the ordinary Icon/Background/Banner inputs, and are consumed by KB presentation code. They are not Character Sheet calculation inputs. The empty visual section was therefore a confirmed form-mapping/presentation defect, not a valid type-specific empty state. It now identifies the actual editing controls and explicitly documents preservation of extension keys without creating a second set of inputs.

Types whose display schema intentionally has `sections: []` remain valid: that means the renderer should use the plain body rather than schema cards, and is unrelated to the ARPG editor's `ui` object.

## Confirmed data-loss risks and fixes

1. `af_kb_cleanup_meta_payload()` unconditionally removed legacy root keys `stats`, `bonuses`, and `links` during an ordinary edit. No edit-time migration or proof that all consumers had moved accompanied the deletion. Cleanup is now non-destructive.
2. The ARPG block editor reconstructed every block from its visible fields. Unknown top-level block keys were dropped, and object-valued `data` was replaced by an empty array before the user changed anything. The editor now updates a clone of each original block and accepts both arrays and objects for `data`.
3. The server reconstructed `meta_json.blocks[]` solely from visible compatibility fields, dropping unknown per-block keys. It now merges edited known fields into the matching existing block. Blocks intentionally removed from the submitted list remain removed.

No destructive schema migration was added. Unknown rules/envelope keys already survive the recursive default merge and mechanic validators; scalar JSON normalization retains booleans, integers, zero, negative numbers, and decimals. Array/object editors continue to store structured JSON rather than stringifying scalar numbers or booleans.

## PHP 8.5 audit

The audited edit/save paths guard array traversal with `is_array()`, cast nullable form/database strings before `trim()`/`strlen()`, and validate repeater inputs before `foreach`. No confirmed PHP 8.5 null-offset, `count(null)`, `strlen(null)`, `trim(null)`, invalid `array_key_exists()`, or `foreach`-on-non-array failure was found in the scoped flow. Numeric UI conversion remains explicit; regression coverage includes `0`, negatives, decimals, and booleans.

## Result

- JSON round-trip preserved for untouched legacy/extension keys and nested block data.
- Legacy data preserved; normal edit is not a migration.
- Existing schema identifiers and rules remain unchanged.
- No Character Sheet calculations or DnD mechanics were changed.
- Breaking changes: **NONE**.
