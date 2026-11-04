# Stage 5: CharacterSheets / AdvancedThreadFields

Baseline: `edb9b2a8` (current main fetched during the task). MyBB boundary fixtures use the real PHP modules, templates and browser runtimes. PHP available here: 8.4.24; PHP 8.5 and the live warprift.ru installation were not exercised.

## Runtime and modal ownership

Initial showthread actually includes only CharacterSheets `permissions.php`, `metadata.php`, `postbit.php`, `frontend.php`, confirmed via `get_included_files()` in the runtime fixture. `bootstrap.php`, `experience.php`, `attributes.php`, `sheets_crud.php`, `calculator.php`, `render.php`, `ajax.php`, `acp_skills.php` remain outside its hot path. These exclusions were already present in baseline and are preserved.

Direct sheet/catalog view keeps the full dependency graph, but now excludes `ajax.php`; API/legacy API actions load its render-fragment dependencies plus `ajax.php`. Dependency order and shared declaration ownership from main are preserved. Real direct/embed HTTP responses and all route/lifecycle escalation fixtures pass without fatal errors.

Initial assets, when the response contains a component:

| Route | CharacterSheets | AdvancedThreadFields |
| --- | --- | --- |
| showthread | charactersheets-trigger.css + charactersheets-trigger.js | advancedthreadfields.css; no JS |
| newthread | no CharacterSheets runtime | advancedthreadfields.css + advancedthreadfields-form.js |
| editpost | no CharacterSheets runtime | advancedthreadfields.css + advancedthreadfields-form.js |
| sheet/embedded sheet | charactersheets.css + charactersheets.js | only when the response actually contains ATF content |

KB chip interactions stay with KnowledgeBase; no KB runtime was added for forms. AdvancedEditor `data-af-ae-editor` markers and its lazy initialization remain intact.

The trigger creates an empty iframe only on click, mounts through AFModalHost when present, immediately overlays a spinner/status, sets `aria-busy`, and then assigns src. Load reveals the frame and fades the loader over 180 ms. Error or 30-second timeout offers a link to open the content separately. Closing disposes timers and the iframe, removes the shell/ESC handler and restores focus; each reopen creates fresh loading state. Reduced-motion preferences are respected. The same lightweight `beginLoading` helper serves APUI's application-fragment fetch and universal iframe paths, preserving existing fragment extraction. Application iframe triggers also use `data-afcs-application`.

## Form and forum layout

All ordinary fields have a shared `af-atf-form-field` wrapper, `af-atf-field-label`, `af-atf-field-help`, `af-atf-field-control`. The legacy two-cell label/control row is now a single colspan cell containing one field group. Character top-grid fields use the same contract. Textarea and abilities rows are full-width `af-atf-form-section` sections. Required stars remain within labels.

Controls share 42 px height, 8 px radius, border/background, padding, typography, box sizing and full cell width. Labels precede controls with an 8 px gap. Grids use 20 px row / 24 px column gaps and 4 columns at >=1200 px, 2 at 768–1199 px, 1 below 768 px. Editors/textareas stay within their section and resize vertically. Ability cards have 20 px padding, 24 px inter-card gaps, a wrapping header and separate description area; label help buttons do not move controls. Form CSS cache versions now track CSS changes even when the form JS has not changed.

Forumdisplay does not register the `thread.meta_chips` field provider. Its legacy hook clears the old output variable; the public compatibility renderer returns immediately without field/value SQL or HTML construction. Database values, showthread display and editing are retained. This is a server-side removal, not CSS hiding.

The owned forumdisplay template places the late-bound `<navigation>` token inside `main.atf-forumdisplay`. A forumdisplay_end hook removes its old header occurrence, mirroring existing showthread ownership. Scoped breadcrumb CSS removes duplicated inner padding. No negative margins or absolute positioning; other routes keep their ownership.

Deploy the updated addon templates via the normal activation/template-sync workflow for AdvancedThreadFields and AdaptiveThemeFramework; editing repository seeds does not itself update templates already stored in the live MyBB database.

## SQL and JS before / after

These are fixture/request-contract measurements, not live-site total query counts or timing:

| Workload | Baseline main | Stage 5 |
| --- | --- | --- |
| Page-author / active-application / fallback slug preload | 3 SQL queries | 3 SQL queries |
| Repeated author resolver/payload calls after preload | 0 extra SQL | 0 extra SQL |
| Five distinct KB field type/entry probes, including a miss | 5 SQL queries without preload | 1 batch query, then 0 extra SQL |
| Forum topic field metadata | reads/renders show_forum fields | 0 field SQL / 0 field HTML |
| Repeated display block tid/fid | existing request cache | same shared request cache |
| Initial showthread CharacterSheets JS requests | 1 trigger, 0 full | 1 trigger, 0 full |
| Initial showthread ATF JS requests | 0 | 0 |
| newthread/editpost ATF JS requests | 1 form | 1 form |
| Initial sheet iframe requests | 0 | 0 |

The trigger grows from 3,413 to 6,126 decoded bytes to implement loader/accessibility/error lifecycle. Full CharacterSheets JS remains 90,530 bytes and stays off initial showthread. Form JS remains 49,393 bytes and stays off showthread. Asset splitting is preserved rather than presented as a new reduction from this already optimized baseline.

KB preloading now happens before provider/mechanic resolution, which previously could perform single-entry lookups while merely preparing the later batch. All candidate provider types are batched; hits and misses share the request cache. Dynamic KB lists are request-cached by type, and ability select payloads are assembled once per request. Display HTML remains request-local and keyed by tid/fid (there is one actual render mode); no cross-user cache is introduced.

## Verification

Added:

- `stage5_modal_layout_browser.cjs`: actual lightweight trigger, first/repeated open, sheet/application, iframe src lifecycle, fade, close-before-load, X/overlay/ESC, timeout/error fallback, APUI fetch/iframe reopen, responsive field geometry, no overflow and canonical breadcrumbs at desktop/mobile with pagination.
- `fixtures/stage5_form.php`: actual PHP form renderer and template seed for the browser fixture.
- `stage5_form_cache_regression.php`: field/editor contracts, real counting-DB KB resolution (5 → 1), miss cache, forumdisplay zero-render behavior and shared negative display-block cache.
- `stage5_assets_breadcrumbs_regression.php`: actual showthread/newthread/editpost asset responses and breadcrumb template/header ownership.

Updated the provider regression to require empty forumdisplay output in both framework and legacy modes. Expanded the CharacterSheets load-graph fixture to cover API/application plans and ensure view routes omit API handlers.

Full PHP suite: **136 executed, 123 passed, 13 failed**. All 13 failures reproduce on baseline main (134 tests, 121 passed); **no new failing tests**. Both CharacterSheets browser suites pass. Browser checks cover 1440, 1000, 600 and 375 px, with 4/2/1/1 columns, >=8 px label gaps, 42 px controls and no horizontal overflow. JS syntax, changed PHP syntax and diff whitespace checks pass.

Existing failures, left outside this task's scope:

- adaptivethemeframework_design_system_regression.php
- adaptivethemeframework_postbit_classic_regression.php
- adaptivethemeframework_scaffold_regression.php
- advancedbuddylist_ajax_json_regression.php
- advancedbuddylist_friendship_model_regression.php
- advancedbuddylist_menu_integration_regression.php
- advancedbuddylist_search_request_ux_regression.php
- advancededitor_feature_lazy_regression.php
- advancedmenu_custom_section_regression.php
- advresponsivelayout_unified_mobile_contract_regression.php
- atf_reputation_navigation_regression.php
- global_addon_frontend_manifest_regression.php
- theme_stylesheet_incremental_sync_regression.php

The editor feature/lifecycle browser harnesses require separately supplied MyBB/SCEditor assets and HTML fixtures; they were not run here. Preserved AdvancedEditor integration is covered by the existing PHP contracts and unchanged editor runtime architecture. Form screenshots show the isolated fixture, not the live theme or a live initialized editor toolbar.

Reproduce:

```sh
php tests/charactersheets_runtime_load_graph_regression.php
php tests/stage5_form_cache_regression.php
php tests/stage5_assets_breadcrumbs_regression.php
php tests/fixtures/stage5_form.php > /tmp/stage5-form.html
FORM_HTML=/tmp/stage5-form.html node tests/stage5_modal_layout_browser.cjs
AF_TEST_PHP=php node tests/charactersheets_trigger_browser.cjs
```
