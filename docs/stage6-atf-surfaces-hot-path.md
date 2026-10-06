# Stage 6 — ATF surface delivery and hot path

Baseline: `fe6381fa` (main). ATF version: `0.28.0`. Measurements use PHP **8.5.0 CLI**, built locally from php.net source, without a production MyBB database.

## CSS delivery

| ATF CSS on showthread | Bytes | Change |
|---|---:|---:|
| Before, global monolith | 169,001 | — |
| After, core + navigation + showthread + postbit + compose | 77,840 | −53.9% |
| After, with an actual modal provider/trigger | 84,106 | −50.2% |

Gzip at level 9: 25,198 bytes before; 14,157 bytes after, summed across files. These are source payloads, excluding HTTP headers, unrelated addons and ACP customizations.

The manifest owns core, navigation, index/forumdisplay, showthread, postbit, compose, profile/member, memberlist (including the AAS userlist.php alias), usercp, private, modcp, moderation and conditional modals. Core retains its existing logical/source identity for incremental ACP synchronization. Surface files declare positive `attach` routes and `disable_theme_integration`; the core pipeline excludes them from global advancedstyles.css and queues only attached sources. Sources marked `exclude_autodiscovery` remain provider-owned/lazy. Core has the ordinary theme-delivery fallback for file mode/missing bundle sections.

No UCP, ModCP, private, memberlist, moderation.php or index/forumdisplay layout rules are delivered to showthread. Browser CSSOM validation checks that no identical rule belongs to two bundles. Rules retain their declarations, activation boundary, specificity and ordering within each surface; shared multi-surface selector lists are partitioned rather than copied.

## PHP profiling

CPU timings in milliseconds; the workload is 20 posts by one author, 60 static providers and approximately 208 KB final HTML. The benchmark reports a warmed median of nine samples for composition/slot/page functions; init is one cold call. The after-page workload starts with the real owned-template footer marker, so it includes footer rendering and lazy-modal presence detection. This is a reproducible isolated benchmark, **not live warprift.ru profiling**.

| Measurement | Before, ms | After, ms | Reduction |
|---|---:|---:|---:|
| `init_ms` | 0.357758 | 0.115856 | 67.6% |
| `compose_20_posts_ms` | 2.540614 | 1.237993 | 51.3% |
| `render_slot_with_context_ms` | 0.039516 | 0.022660 | 42.7% |
| `mark_page_ms` | 0.430619 | 0.214582 | 50.2% |

Frontend init preferences-schema checks: **1 → 0**. Layout composition SQL: **1 → 0**. SQL remains in the data preload hook and is reported separately by regression tests; it has not been hidden by discarding providers.

Run: `php tests/atf_hot_path_benchmark.php /path/to/baseline/adaptivethemeframework.php` and `php tests/atf_hot_path_benchmark.php`. The baseline bootstrap needs its matching ownership.php beside it. Timing varies with host load.

## Runtime changes and audit boundaries

- Frontend init no longer calls preferences schema ensure or seeds UCP/ModCP navigation on unrelated routes. Install/activate/explicit upgrade remain the DDL boundaries. Preferences read/save check readiness lazily, return a default or skip persistence when missing, and record a diagnostic without DDL.
- Every owned full-page seed carries body classes before output and one literal footer placement marker. `mark_page` does no full-page body/footer regex transformation on owned showthread. Legacy/custom pages retain opening-body-tag-only activation and literal footer insertion. Route/marker guards bound remaining forum-avatar, topic-avatar and memberlist-avatar compatibility passes. Lazy modal detection is a guarded presence check, without rewriting HTML.
- Provider definitions are partitioned/sorted per slot once and invalidated on registration. Boolean visibility is prepared once; explicitly request-scoped callback visibility is evaluated once. Post/context callbacks retain per-post behavior. Explicit `memo_key` contracts cache eligible provider output; repeated native action controls and reputation output are also memoized. Native URLs, permissions, tokens and handlers remain authoritative.
- The early postbit data hook batches current-page authors, APF secondary values (including absent values), live application elements and current-page reputation. Element loading reads only the canonical element field and avoids the broad profile payload/KB formatter. Layout composition consumes prepared data and runs no SQL. The real-helper test exercises 60 compositions with four initial batches and zero additional SQL.
- Sticky uses one rAF scheduler and one reusable ResizeObserver. All dirty geometry reads precede transform writes. A post resize invalidates following positions; internal sidebar/meta/topbar changes invalidate only that post. Only canonical measured roots and the few upstream layout roots are observed. This catches internal sidebar resizing even when a tall message keeps post height unchanged. Mobile detaches observers/listeners, cancels the frame and clears transforms. Newly appended posts join the controller; the historical postbit.js path delegates to the same runtime.
- Modal runtime/CSS are emitted only for actual footer providers or recognized existing/lazy triggers. Initial canonicalization runs once. Mutation processing scans only added subtrees, ignores its own moves, and deduplicates late roots. Providers keep their own requests, open/close and submission behavior.

## Verification

- PHP 8.5.0: **130/139 tests passed**; **9 existing baseline failures**, **zero new failures**. Baseline: 123/136 passed, 13 failed. All changed PHP files lint clean; `git diff --check` passes.
- Stage 6 browser test passed: 755 elements compared before/after at 1440, 1000, 769, 768, 600 and 375 px; desktop scroll offsets 0, 400 and 1100. Computed visual properties and geometry match. Additional checks cover internal sidebar resize, AJAX post append, breakpoint detach/re-enable, real lazy modal open/Escape close, late modal duplicates and zero document-wide mutation scans.
- Scroll-only frames: zero geometry reads. Mobile scroll: zero geometry reads. Stage 5 browser suite also passed, including actual character/APUI modal loading and responsive form/forum layouts.

Browser reproduction: `PHP_BIN=php BASELINE_CSS=/path/to/old.css BASELINE_STICKY=/path/to/old.postbit-sticky.js node tests/stage6_atf_browser.cjs` with Playwright and Chromium installed. Without baseline variables it runs the runtime/ownership invariants. Also run `PHP_BIN=php node tests/stage5_modal_layout_browser.cjs`.

Existing failures (also reproduced in unmodified main):

- `adaptivethemeframework_scaffold_regression.php`
- `advancedbuddylist_ajax_json_regression.php`
- `advancedbuddylist_friendship_model_regression.php`
- `advancedbuddylist_menu_integration_regression.php`
- `advancedbuddylist_search_request_ux_regression.php`
- `advancededitor_feature_lazy_regression.php`
- `advancedmenu_custom_section_regression.php`
- `advresponsivelayout_unified_mobile_contract_regression.php`
- `global_addon_frontend_manifest_regression.php`

They concern the already absent advresponsivelayout addon, existing BuddyList/Menu/editor source contracts. Stage 6 updates stale ATF layout assertions to the actual baseline values and updates stylesheet tests for explicit file sources/current codec dependencies; no unrelated addon behavior was changed.

## Applying to the forum

After updating files, run ATF activate/upgrade to reconcile owned template seeds and normal AF stylesheet synchronization to replace the unedited old core section. ACP-edited monolith sections remain protected by the existing ownership/three-way merge policy and need a reviewed migration of those customizations; do not force-resync away manual edits. The repository changes do not deploy to warprift.ru, and live production timing/real-data screenshots were unavailable in this environment.
