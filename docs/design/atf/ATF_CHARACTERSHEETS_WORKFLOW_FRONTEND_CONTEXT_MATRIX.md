# Frontend context matrix: ATF, CharacterSheets, CharacterWorkflow

This matrix was produced from the owner hooks and render paths before the
frontend-permission migration. It is deliberately limited to asset/context
ownership; gameplay, schemas and workflow policy are out of scope.

| Addon | Route context | Form context | Postbit context | Profile context | Modal context | AJAX context | KB dependency | Server-only dependency | Frontend dependency |
|---|---|---|---|---|---|---|---|---|---|
| AdvancedThreadFields | `newthread.php` and first-post `editpost.php` only when fields render; `forumdisplay.php` only when its catalog CTA renders. The current `showthread.php` title block is intentionally empty; ATF values can still be server-rendered in the first post. | `af_atf_prepare_input_block()` establishes the real control fact after forum-field resolution. Preview reuses the same form output. | ATF field/chip markup is server-rendered; no independent postbit runtime fact was found. | None. | KB-backed selectors may open KB UI, but ATF owns only its rendered form runtime and endpoint metadata. | `misc.php?action=af_atf_user_suggest` and ATF early JSON routes return data and must not receive page assets. | Races, classes, themes, origins/variants and mechanics are PHP/source-of-truth inputs for controls. | Validation, persistence, filters, archive lifecycle and KB lookups. | `advancedthreadfields.css/js` plus the endpoint/hide-editor meta config, only with an ATF form or catalog CTA component. Inline `application/json` emitted inside ability controls remains component output. |
| CharacterSheets | `charactersheets.php` is the standalone page/alias. Other routes qualify only through a rendered component fact. | Sheet edit/action controls are part of the rendered sheet. | AdvancedProfileUI asks for a payload; a real sheet/application trigger sets the caller-runtime fact. | AdvancedProfileUI uses the same payload; only a non-empty sheet/application component qualifies. | The caller owns trigger/modal shell runtime. HTML iframe/modal pages render their own sheet document; JSON endpoints do not enqueue caller assets. | `misc.php` API/`ajax_*` actions are server endpoints. They do not independently authorize caller assets. | Character data and derived sheet inputs are read server-side from KB. | ATF data, Inventory/equipment exporters, Balance/stat calculations, acceptance and persistence remain server-side dependencies. | `charactersheets.css/js` on the standalone page or when a sheet/trigger/modal document was actually rendered. Inventory and Balance frontend runtimes are not implied. |
| CharacterWorkflow | No standalone route and no owner-rendered page runtime. Pending/accepted forum IDs constrain server policy, not an asset route. | None owned. | None owned. Workflow decisions are consumed by CharacterSheets' server-rendered moderation controls. | None. | None. | Actions are executed through CharacterSheets/server handlers; no Workflow JSON/page runtime exists. | KB creation/sync policy is server-side. | ATF, KB, CharacterSheets, moderation and group assignment APIs. | None. The addon must deny directory/global frontend discovery; visible buttons remain CharacterSheets-owned component output. |

## Permission contracts selected

* **AdvancedThreadFields:** contextual response fact `has_atf_component`, set by
  real form controls, catalog CTA or first-post display output; no directory
  fallback.
* **CharacterSheets:** contextual route `charactersheets.php`, plus response fact
  `has_charactersheet_component`; no directory fallback.
* **CharacterWorkflow:** contextual with no routes or response rules, because it
  is a server-only orchestrator; no directory fallback.

No Knowledge Base or AdvancedJSBundle file is part of this migration.
