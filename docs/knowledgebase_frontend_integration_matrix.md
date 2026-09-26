# Knowledge Base frontend integration matrix

This audit records the boundary used by the frontend permission manifest. It is
not a data-schema or mechanics contract: PHP consumers remain independent of
whether a page receives the KB browser runtime.

| Integration | Existing KB dependency | Frontend KB runtime |
|---|---|---|
| `kb.php` catalog, type, entry and help pages | KB rendering, categories and entries | Base CSS, KB UI CSS, chips/view runtime and endpoint config |
| `kb.php` create/edit and type edit | KB schemas, validators and persistence | Base + UI CSS, base editor runtime, chips, insert runtime, inline language/endpoints/options, then SCEditor init |
| KB category management | KB category tables and forms | KB page/view runtime; no editor insert stack |
| KB chips in parsed posts or sheets | `parse_message_end` renders `.af-kb-chip` | Chip response fact enables CSS, chip/modal runtime and endpoints only when the marker exists |
| KB entry modal endpoint | Reads and renders an entry payload | None: the caller already owns the chip runtime |
| JSON list/get/types/children/variant endpoints | Read-only KB payloads for callers | None |
| AdvancedThreadFields DnD/ARPG forms | Server-side types, races, variants, origins, archetypes, factions, abilities, mechanics and labels; its own AJAX bridge | None merely for reading KB data |
| CharacterSheets DnD/ARPG | Server-side race/variant bonuses, abilities, equipment, normalized rules and modal data | None merely for calculations/rendering; a rendered KB chip is handled by the component fact |
| CharacterWorkflow | Character record creation, acceptance/canonical/original flows, sheet creation and moderation data | None |
| Shop | Item/ability metadata, normalized profiles and rules | None |
| Inventory | Item/ability metadata and normalized profiles | None |
| Editor insert integration on KB edit pages | KB entity search and insertion | Base editor runtime, then chips and insert runtime, then inline config; SCEditor dependencies/init remain page-local |
| ARPG mechanics | `origin`, `origin_variant`, `archetype`, `faction`, `ability`, mechanics option sets and `item` | None outside an actual KB UI/chip |
| DnD mechanics | Race, race variants, classes, themes, abilities and related rule data | None outside an actual KB UI/chip |

## Permission and delivery boundary

The manifest owns only explicit HTML actions on `kb.php` and their legacy
`misc.php` aliases. AJAX, JSON and mutation actions are intentionally absent.
The late response fact `has_kb_chip=true` covers embedded chips without making
`showthread.php`, CharacterSheets, ATF, Shop, Inventory, or editor-bearing pages
KB routes.

Owner injections (`pre_output_page`, header ensure, SCEditor stack, direct KB
scripts and inline configuration) pass through the same permission API while
retaining the legacy asset blacklist as an additional operational control.
Theme stylesheet declarations and automatic/file/theme delivery are unchanged.

The preserved script/config order is: KB base runtime, KB chips runtime, KB
insert runtime, inline language/endpoints/options, runtime mode, and SCEditor
initialization. View/chip contexts omit the base editor and insert runtimes.
