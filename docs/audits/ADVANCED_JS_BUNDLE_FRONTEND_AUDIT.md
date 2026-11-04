# AdvancedJSBundle frontend permission audit

## Before migration

The addon scanned its own `assets/` directory on every request, discarded maps
and minified variants, sorted filenames naturally, intersected that static list
with a local page classifier, removed every matching tag (query strings included),
and rebuilt one marker block. It did not concatenate or cache file contents; its
only request cache was PHP static directory listings. Consequently there was no
cross-request bundle cache key or invalidation mechanism. CSS delivery already
delegated to `af_theme_stylesheet_delivery_decision()`.

The transform could run before the core collector, however, and the local route
classifier was independent from the canonical frontend permission decision.

## Migrated flow

1. Source owners and the core collector finish first.
2. The canonical `af_frontend_asset_allowed(addon, resource)` resolver evaluates
   each AdvancedJSBundle resource declaration.
3. The ordered, allowed catalog is intersected with files that actually exist.
4. Existing tags for the addon's owned files (including versioned URLs) are
   removed and exactly one marker block is emitted.
5. CSS still passes through theme/file delivery selection; permission and
   delivery remain separate decisions.

The collector directory fallback is disabled for this addon, so it cannot enqueue
the whole directory ahead of the resource decisions. No plugin-specific checks
for Inventory, KB, Wanted, or any other addon were introduced. AdvancedJSBundle
only transforms its own asset namespace, so an absent/forbidden Inventory source
tag cannot be synthesized into this block.

## Order and inline configuration

The catalog is intentionally ordered rather than alphabetically sorted. The
transform does not move, consume, or bundle inline scripts, jQuery, SCEditor, KB
configuration, menu/modal configuration, or other addons' runtimes. Their owner
order therefore remains intact. Query/version suffixes are recognized while
removing duplicates; generated bundle-owned tags retain the existing unversioned
semantics.

## Request matrix

| Page | AdvancedJSBundle resource set |
|---|---|
| index | scroll buttons; post-control tooltips |
| forumdisplay | base set + quick quote, FIMP, threaded-link removal, postbit icons |
| showthread plain | base set + popup detach, quick quote, FIMP, threaded-link removal, postbit icons, quote avatars |
| showthread with Wanted chip | same bundle-owned set as plain showthread; Wanted remains owner-controlled and untouched |
| member | base set |
| usercp | base set |
| newthread | base set |
| editpost | base set |
| shop | base set; Shop runtime untouched |
| inventory | base set; Inventory runtime untouched |
| charactersheets | base set; CharacterSheets runtime untouched |
| kb | base set; KB base/chips/config/SCEditor order untouched |

## Limits and blacklist readiness

This repository has static regression coverage rather than a configured browser,
database, themes, and authenticated warprift.ru fixtures, so console execution
and rendered pre-bundle DOM capture for all matrix rows require staging. The
legacy blacklist was deliberately not removed. The bundle no longer depends on
it for its resource selection and is ready for a separately scoped blacklist
cleanup after staging verification.
