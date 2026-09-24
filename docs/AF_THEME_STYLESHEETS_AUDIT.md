# AF theme stylesheets: audit, unified bundle, and migration

## Previous data flow

1. `af_discover_addons()` reads addon manifests in stable, name-sorted order.
2. `af_discover_addon_css_candidates()` combines manifest `assets` and
   `theme_stylesheets` declarations with top-level files discovered in each
   `assets/` directory. `af_discover_theme_stylesheets()` excludes admin-only
   and explicitly excluded candidates.
3. `af_sync_theme_stylesheets()` used to create one MyBB `themestylesheets`
   record per source and per theme through `af_register_theme_stylesheet()`.
   `af_theme_stylesheets` stores its SID, source checksum, last synchronized
   checksum, delivery mode, and manual-edit flag.
4. The old generated name `af_a_914810.css` is not random: it is
   `af_` + an abbreviation of the addon id + the first six characters of
   `sha1(addon id|logical id|source path)`. There were many files because the
   Cartesian product of enabled frontend CSS sources and themes was created.
5. MyBB's `cache_stylesheet()` may write both the editable `.css` cache and an
   optimized `.min.css` variant. AF selected `.min.css` when it existed and
   otherwise `.css`; they represent one layer, not two intended layers. The
   final HTML normalizer also treats those two names as the same layer.
6. At runtime addon asset collection and addon-specific calls pass through
   `af_theme_stylesheet_delivery_decision()`. Theme/auto suppressed the server
   file when a usable attached MyBB stylesheet existed; file mode allowed the
   source URL. A final queue prune and HTML-link normalization handled addons
   that queued the same asset by another supported path.

This infrastructure is part of AF core. It is installed and synchronized by
AF install/activation/runtime signature checks and does **not** depend on the
Adaptive Responsive Layout addon. That addon is merely another CSS producer;
it also has its own direct responsive asset/runtime-style injection.

The selected legacy modes and checksums live in `af_theme_stylesheets`. AF
deactivation does not remove them, activation performs a safe sync, and even
uninstall deliberately keeps the registry and theme stylesheets for recovery.

## Unified design

Every MyBB theme has exactly one new AF-managed stylesheet named
`advancedstyles.css`. The deterministic build order is addon id, then normalized
source path. Every block has the addon display name/id and source path in a
comment. Admin-only CSS is never included.

The bundle has one registry record identified by addon `__af_bundle__` and
logical id `advancedstyles`; existing source registry rows remain as migration
and routing metadata. Existing `af_*.css` records and their CSS bodies are never
deleted or rewritten by migration. After successful bundle caching they are
detached, so they cannot duplicate either the bundle or server files.

### Modes

* **Theme mode** attaches `advancedstyles.css` globally. Every registered AF
  server CSS request resolves to that bundle and the corresponding file URL is
  suppressed. If the bundle cache is unexpectedly unavailable, delivery fails
  open to the source files rather than rendering an unstyled forum.
* **File mode** detaches (but does not delete) `advancedstyles.css`. AF source
  files are queued in the existing addon order. Switching back merely reattaches
  the same database stylesheet, so ACP edits remain intact.

Mode is theme-wide because one bundle cannot safely mix per-source theme and
file delivery. Old `auto` callers map to theme mode.

## Command semantics

* **Integrate into ACP** remains accepted for compatibility for an individual
  legacy row. New normal synchronization does not create further opaque names.
* **Sync all / Sync addon** rebuilds source input and updates the bundle only if
  its database CSS still equals AF's last synchronized checksum. An ACP-edited
  bundle is reported as a manual override and is not overwritten. Since the
  output is one cascade, addon sync validates/rebuilds the complete bundle.
* **Force resync** requires explicit confirmation and intentionally replaces
  the complete bundle with current server sources, clearing manual overrides.
* **Rebuild missing** retains its conservative legacy recovery behavior; normal
  sync also recreates a missing unified bundle without modifying legacy CSS.
* **Restore from seed (safe)** retains the legacy rule: restore only a missing
  or demonstrably unedited stylesheet and skip manual overrides.
* **Show diff/hash status** is read-only.

On the first bundle creation, legacy rows whose CSS differs from their last
synchronized checksum (or is already flagged as edited) are copied to clearly
marked blocks at the end of `advancedstyles.css`. This preserves their cascade
effect while leaving the originals untouched. Later normal sync regards that
bundle as its baseline; force resync is the explicit operation that discards
both those migrated blocks and later ACP edits.

## Migration and rollback

1. Back up the database, especially `themestylesheets`, `themes`, and
   `af_theme_stylesheets`.
2. Activate/update AF or run **Sync all**. Confirm that every theme shows an
   editable `advancedstyles.css` link and inspect the marked migrated overrides.
3. Select **Theme mode** for each theme and refresh the MyBB theme cache if the
   ACP editor reports that it is needed. Verify page source contains one
   `advancedstyles(.min).css` link and no addon source CSS links.
4. Test relevant pages and responsive breakpoints. Only after acceptance should
   **Force resync** be considered; it is destructive to bundle edits by design.

Rollback is non-destructive: select **File mode** to use server CSS immediately.
To restore the exact pre-migration theme-cache arrangement, reattach the retained
legacy stylesheets to the values shown by their AF diagnostics/manifest and
detach `advancedstyles.css`. No source or legacy CSS record has to be recovered
from a deleted file.

