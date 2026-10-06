# AdvancedEditor feature-lazy toolbar

The response compiler renders the complete configured toolbar before first paint. Showing a button does not load its implementation. Quick Reply and native MyBB Quick Edit start with a source textarea inside the same toolbar shell. Focus and basic BBCode commands never activate SCEditor.

## Delivery and metadata

Initial editor assets are `assets/advancededitor_shell.css`, `assets/advancededitor_shell.js`, icon files, and the existing local-font stylesheet when configured. jQuery remains a MyBB dependency. Published-post assets are separate: the small `charcountandprew_view.js` can run for post counters, and content-only `_view.js`/`_view.css` files follow the declared rendered-content markers. A published table never loads its editor dialog.

`advancededitor/shell.php` compiles button metadata and renders groups, icons, titles, dropdown triggers, configured help placement, and the source wrapper. Pack discovery runs once per PHP request, never on clicks. The compiler is the boundary for a future persistent registry.

A pack manifest retains its existing `assets` and `buttons` and adds declarations such as:

```php
'runtime' => [
    'activation' => 'click',
    'requires' => ['shared-capability'],
    'commands' => ['optional-built-in-alias'],
    'triggers' => ['optional-form-button-name'],
],
'assets' => ['js' => ['relative/runtime.js'], 'css' => ['relative/dialog.css']],
'buttons' => [['cmd' => 'af_example', 'title' => 'Example', 'icon' => 'img/example.svg', 'handler' => 'example']],
'view_assets' => ['js' => ['relative/content_view.js'], 'css' => ['relative/content_view.css']],
'view_markers' => ['data-example-rendered'],
```

Pure insertion declares `opentag`/`closetag`, omits `handler`, and needs no editor JS/CSS. Existing DB custom buttons keep this path. Extensions may supply `handler`, `capability`, and an optional JSON `runtime` in custom definitions. This change does not add DB columns or an ACP runtime authoring interface.

Other addons publish `af_ae_external_buttons` and `af_ae_external_capabilities` under their own frontend permission gate. KB uses this mechanism, supports either addon hook order, and inserts its button into the initial server shell.

## Activation contract

`window.afAdvancedEditorShell.loadCapability(id)` returns a shared Promise while loading or loaded. States are `unloaded`, `loading`, `loaded`, and `failed`. Dependencies load first, stylesheets must finish loading, and JS executes in declared order. Assets are deduplicated by resolved URL and type, including shared helpers. Dependency cycles reject instead of hanging. Network errors/timeouts remove the failed asset and permit retry. A failed command leaves the source textarea and other commands usable and shows a local notice.

The command router preserves the source selection while assets load and invokes the handler on the original click. Pack handlers use `af_ae_<handler>_exec(editor, definition, caller)` or the existing `afAeBuiltinHandlers` registry. The source adapter supplies insertion/value/dropdown methods without impersonating an SCEditor global. A capability declaring a direct or transitive dependency on `wysiwyg` initializes the requested textarea before invoking its handler.

| Capability | First trigger | Editor runtime |
| --- | --- | --- |
| `tables` | Tables / `af_tables` | shared jscolor helper, `tables.js`, `tables.css`; opens the existing builder |
| `jscolorpiker` | Color / `color` | `jscolor.js`, `jscolorpiker.js`, picker CSS; opens immediately |
| `stikers` | Stickers / `af_stikers` | `stikers.js`, `stikers.css`; opens UI, creates its observer only after activation |
| `kb-insert` | KB / `af_kb_insert` | `knowledgebase_insert.js`, `knowledgebase_insert.css`; opens picker using current source/WYS mode |
| `wysiwyg` | WYSIWYG / `af_togglemode`, `source`, or explicit dependency | SCEditor core, BBCode plugin, optional locally available MyBB bridge, native theme/content CSS, `advancededitor_wysiwyg_bbcodes.js`, `advancededitor.js`, full editor CSS |
| `drafts` | Drafts / `af_drafts` | `drafts.js`, `drafts.css`; installs existing per-form recovery/autosave behavior for the selected editor |
| `fontfamily` | Font / `font`, `af_font` | font picker JS/CSS |
| `fontsize` | Size / `size`, `af_fontsize` | size picker JS/CSS |
| `tquote` | `af_tquote` | tquote JS/CSS and shared jscolor helper |
| `resizeimg` | `af_resizeimg` | image size dialog JS/CSS |
| `lockcontent` | `af_lockcontent` | lock dialog JS/CSS |
| `tabs` | `af_tabs` | tabs insertion dialog JS/CSS |
| `embedvideos` | `af_embedvideos` | video insertion dialog JS/CSS |
| `spoiler` | Advanced spoiler / `af_spoiler` | titled spoiler dialog JS/CSS |
| `abbr`, `anchors`, `mark`, `indent`, `floatbb`, `htmlbb`, `lists`, `align` | Corresponding pack command | Each pack's declared assets only |
| `charcountandprew` | Form button `previewpost` | Existing preview JS/CSS; replays first click after binding the requested editor |

Bold, Italic, Underline, Strike, Quote, Code, URL, Image, basic lists/alignment, basic spoiler and custom tag pairs use the initial lightweight insertion runtime. Accordion is pure insertion and makes no editor runtime request. Source insertion uses native UTF-16 selection offsets and restores caret/focus, so surrogate pairs and Cyrillic are preserved.

The source counter is small and initial; it does not activate preview timers. No mandatory Quick Reply autosave requirement was found in the addon. Drafts becomes opt-in on its first command; it retains existing recovery/autosave rather than adding a new drafts UI.

Initial source mode intentionally takes precedence over remembered/default WYSIWYG mode. The configured full/partial WYSIWYG policy still applies when explicitly enabled. Until the visual content is edited, its initial BBCode spelling is retained across Submit and the first source round trip; actual value changes use the native serializer. Loaded pack formats register on `af:capability-ready`, allowing WYSIWYG activation long after a source-mode pack click.

## Quick Edit lifecycle

The shell recognizes only native `showthread.php` numeric `quickedit_PID[name=value]` fields inside their matching post/message/form host. It handles delayed ID assignment. Creation emits `af:editor-ready` with `textarea`, source `instance`, and `quickEdit`. Explicit WYS activation initializes the existing textarea. Destruction closes owned dropdowns, aborts input/form-trigger listeners, destroys the actual SCEditor instance if present, emits `af:editor-destroyed`, clears activated drafts/preview timers, and restores the post counter. The ATF metadata line is outside the edited message host.

## Theme CSS migration

Feature rows remain declared for compatibility but use the generic `exclude_autodiscovery` and `disable_theme_integration` flags. No AF core blacklist or MyBB core change is required. `theme_capabilities.php` detaches only manifest-declared AdvancedEditor feature sections from the existing structured AF bundle. It creates an AF recovery snapshot, uses optimistic DB comparison, updates the theme cache/checksum, and writes a per-theme capability index under `cache/af_advancededitor_capabilities/`.

Unmodified feature sections use upstream static assets. Edited feature bodies are exported to hashed CSS files and loaded with their capabilities. Edited main toolbar/source rules are retained as a small initial shell override. Unrelated sections are preserved. An invalid bundle or failed recovery/write is not destructively rewritten. Keep the normal MyBB cache directory writable and retain the recovery snapshot when reviewing migration on a real theme. Published content now has separate upstream view styles; any site-specific edits to published BBCode styles need visual review against those new view files.

## Verification

New regressions:

- `tests/advancededitor_feature_lazy_regression.php`: real response compiler and pack registry against a mocked MyBB boundary; initial asset exclusions, buttons, tag commands, custom dependencies, declared asset paths, theme exclusions and published content separation.
- `tests/advancededitor_feature_theme_migration_regression.php`: real AF section codec; edited-body preservation, unrelated-section preservation, idempotence and corrupt-bundle refusal.
- `tests/advancededitor_feature_lazy_browser.cjs`: Chromium with actual pack JS/CSS and native SCEditor assets; first-click dialogs, failure/retry, Promise/asset deduplication, cycles, Unicode selection/caret, unchanged toolbar height, source focus, stickers observer, KB insertion, deferred drafts, existing preview, simulated native Quick Edit Save/Cancel/reopen, WYS text preservation and dependent-capability initialization.

Existing lifecycle/postbit/performance tests were updated where responsibility moved from eager counter code to the shell/view runtimes. The browser fixture exercises real addon code but mocks DB and HTTP dialog data. Quick Edit server saving is simulated; this is not an authenticated live MyBB end-to-end test.

Run PHP tests from the repository root with PHP 8.5. The browser test additionally needs Playwright, Chromium, and a MyBB `jscripts/` tree available to both the PHP fixture compiler and its server:

```sh
php tests/fixtures/advancededitor_showthread.php > /tmp/advancededitor-after.html
node tests/advancededitor_feature_lazy_browser.cjs --html /tmp/advancededitor-after.html --mybb-assets /path/to/mybb --report /tmp/advancededitor-report.json
```

`--mybb-assets` is the installation root containing `jscripts/`. Optionally pass `--baseline /path/to/original-repo --baseline-html /tmp/advancededitor-before.html` for identical cold-context before/after measurements. To render the old baseline, copy the same fixture into that checkout and run it from that checkout's root. The baseline uses the original addon and its original eager KB asset delivery.

Full PHP suite: original main had 112 passes / 12 failures across 124 tests. The changed tree has 114 passes / the same 12 failures across 126 tests. Existing failures concern ATF design/postbit/scaffold/reputation, missing `advresponsivelayout`, buddylist contracts, menu section keys, global manifests and the incremental-theme test's missing helper. No new failures were introduced. PHP 8.5 syntax and JavaScript syntax were also checked for changed sources.

## Measurement limits

Network/performance figures in the accompanying report come from the same local PHP/browser fixture, cold Chromium contexts, gzip delivery, jQuery 3.5.1 and SCEditor 3.2.1/native MyBB styles. They include jQuery and published-post counters. One original legacy jscolor URL is unresolved in that baseline; the capability resolver fixes the shared helper path.

These figures demonstrate editor asset delivery changes, not production warprift.ru latency. An authenticated production session and deployment were unavailable, so actual live BEFORE/AFTER timings, complete production theme visual parity, and MyBB server Submit/Save behavior remain to be checked on the deployed forum. Merging main does not deploy the forum.

Measured fixture sample (2026-10-06):

| Метрика | BEFORE | AFTER |
| --- | ---: | ---: |
| Initial JS requests | 31 | 3 |
| JS transferred, bytes (resource transferSize) | 267580 | 40526 |
| Decoded JS, bytes | 948056 | 117090 |
| Scripting, ms | 261.8 | 77.1 |
| DOMContentLoaded, ms | 474.5 | 267.6 |
| Load, ms | 595.5 | 269.6 |

Raw measurement and full suite outputs are included in the task deliverables.
