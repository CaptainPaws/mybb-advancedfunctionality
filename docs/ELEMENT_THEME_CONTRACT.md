# Element theme contract

## Ownership and identity

Knowledge Base `type=arpg_element` is the only identity registry. Active public
entries come from `af_kb_get_public_type_options()`. Titles stay in KB; themes use
string keys, never numeric KB IDs. AdvancedElementTheme owns presentation.
Downstream addons must not maintain element allow-lists or palette copies.

Enable AdvancedElementTheme in AF ACP after deploying. Install/activate/upgrade
create `af_element_theme_styles` and `af_element_theme_surfaces`. These tables
contain only keyed presentation overrides (`palette_json`); no KB identities,
titles or template backups. Activation does not modify other addons' templates.
Existing DB markup receives optional surface attributes at output time; sheet
modal rendering also supports existing templates.

## Integration

Resolve stored values with `af_elementtheme_resolve_key($value)`. Render its
result in `data-element`; unknown or unavailable KB identity resolves to an empty
string and remains neutral. An existing canonical KB key is never converted to
a CSS-only key. Compatibility aliases live only in `af_elementtheme_aliases()`:
`dark` resolves to `shadow` when shadow exists and dark is not itself a KB key.
New data must store the canonical result, not an alias.

Use optional `data-element-surface="application|sheet|postbit|profile"` on the
surface root. Consumers use `--af-element-main`, `--af-element-accent`,
`--af-element-soft`, `--af-element-border` with their neutral fallbacks. The
optional `--af-element-contrast` preserves the existing postbit icon contrast;
it is presentation metadata in the source palette, not another identity list.
Nested roots reset element tokens, so a neutral sheet cannot inherit the
surrounding author's element. Approved-character/application gating remains the
responsibility of existing payload providers; this addon never infers identity
from a user ID, group, appearance or Wanted reservation.

New frontend integrations must follow AF's manifest-based frontend permission
architecture. AdvancedElementTheme's manifest declares a response-aware context
with directory fallback disabled. Its owner delivery checks
`af_frontend_asset_allowed()` with an actual `has_element_surface` fact. Renderers call
`af_elementtheme_mark_surface($surface)` when producing a component; full-page
modal callers mark the surface before emitting the head. Legacy HTML detection
is a compatibility fallback, not the primary integration. Modal
caller pages use CharacterSheets' existing component fact. HTML fragments/JSON
receive no asset tags; the caller already owns the stylesheet. KB element chips
also consume the shared palette.

## Palette and administration

The editable default source is
`inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-theme.css`.
Selectors use `[data-element="KEY"]`; surface layout belongs to consumers.
All existing legacy palettes are retained, and shadow has the former dark colors.

Add an element in KB (`arpg_element`, active public key): it becomes valid on
the next request without PHP edits. Add/edit its palette in AdvancedElementTheme
ACP, or add a default selector in the source CSS. Global overrides apply to all
surfaces. Optional surface overrides replace only supplied tokens. Empty fields
remove overrides and inherit defaults/global values. The editor has separate Global/Application/Sheet/Postbit/Profile tabs. Standard
fields include contrast; arbitrary additional variable names must begin with
`--af-element-`. Text values support rgba/hsl, gradients, color-mix and var()
expressions; hex colors also have native color pickers. Each tab stores its own
optional Custom CSS. The editable source CSS is never rewritten.

The ACP joins live KB options with source/saved styles and displays Bound,
Missing style, or Legacy / unbound. Deleting a KB entry leaves its presentation
intact but runtime resolution becomes neutral. Recreating the same key binds it
again automatically. Unbound styles can be inspected/edited without creating a
second identity registry. Deactivation/uninstallation retain theme metadata.

## Cache and fail-safe behavior

The KB registry and default styles are memoized once per request. Saved global
and surface overrides plus their compiled CSS use MyBB cache `af_elementtheme`,
with request memoization and a versioned compiler format. ACP changes/lifecycle operations invalidate this cache;
KB changes need no presentation-cache rebuild. The cache stores no KB titles.
A small cached inline override stylesheet follows the versioned base stylesheet;
PHP never rewrites the source CSS. Cache misses load each presentation table once,
regardless of post count. Missing tables/API/entries/styles produce neutral
fallbacks. Palette source edits are delivered with a filemtime version.

## ACP binding and metadata

The resolver lazily loads KB's declaration-only bootstrap via
`af_get_addon_bootstrap_path('knowledgebase')`; it never invokes KB init,
frontend hooks or lifecycle operations. `af_kb_public_type_options_available()`
distinguishes missing public storage from a loaded empty registry. Runtime stays
neutral on failure; ACP shows the error and `KB unavailable`, not Legacy, and
blocks saves until identity can be checked. The editor displays current KB title,
type/key/status and a board-relative link through `af_kb_url()`.

Alias rows are marked `Alias → shadow` and link to the canonical editor when the
KB target exists. A real KB key takes precedence over a compatibility alias.
Listing and editing are separate `action=list`/`action=edit` views. Saving returns
to the same canonical element/surface editor with a flash message.

The existing `palette_json` columns now accept
`{"variables":{"--af-element-main":"#ef5b3f"},"custom_css":""}`.
An adapter continues reading old short-token maps. No destructive schema
migration is needed. Encoded metadata is validated against the existing TEXT
column limit before touching a saved row. Public palette getters retain their short-token keys.

Custom CSS compiles inside native CSS `@scope` rooted at `[data-element="KEY"]`
or `[data-element="KEY"][data-element-surface="SURFACE"]`; scoped regions stop
at nested element roots. Use `:scope` for the root itself. Complex selectors
cannot style siblings or another element/surface outside this region. This
requires a browser with CSS @scope support; base palette variables remain ordinary
CSS. ACP token declarations use `!important` only for `--af-element-*`.
Custom CSS declarations are compiled as important in dedicated layers:
`af_elementtheme_surface_custom` before `af_elementtheme_global_custom` (important
layer order is reversed). This gives surface Custom CSS precedence over global
Custom CSS, and both over late/unlayered component CSS, regardless of ATF asset
delivery order or selector specificity. Only authored properties are overridden;
component layout and other forum theme tokens remain owned by their consumers.
Postbit accent consumes the common accent token, while its gradient also uses main.
@media/@supports/@container groups are supported. Global definitions such
as @import/@font-face/@keyframes are rejected, as are unbalanced CSS and HTML
style termination. Escapes outside quoted strings are rejected to avoid ambiguous
scoping validation. Extra variable values cannot contain declaration delimiters.

Regression coverage: `tests/element_theme_delivery_regression.php`,
`tests/element_theme_delivery_browser.cjs` (Chromium; `AF_TEST_BROWSER=firefox` for
an installed Playwright Firefox), `tests/element_theme_editor_regression.php`,
`tests/element_theme_editor_browser.cjs`, `tests/element_theme_regression.php`, the existing ATF
postbit/batch/hot-path, active-application gate, profile composition and
CharacterSheets frontend regressions.


## Animated effects

The Effects tab saves optional `effects` alongside `variables` and `custom_css`
in the existing global `palette_json`; no schema migration or identity mapping
is introduced. Existing metadata without this member means disabled. Palette
saves preserve effects; effects saves preserve global and surface styles.
All presets are opt-in. Presentation suggestions: fire/embers, shadow/stardust,
water/mist; any canonical KB element can use any of the six reusable presets
(stardust, embers, mist, aura, electric, frost).

Settings: enabled, preset, intensity/speed/density/opacity (0–100), color (empty
means current surface accent; optional literal hex/RGB/HSL override), surfaces
(profile, sheet, application, postbit). Opacity is visibility, so 0 is invisible.
Surface palette overrides automatically feed particle colors. The ACP preview
uses the same compiler textures and CSS keyframes and changes before saving.

Surface owners provide a hidden, non-interactive
`[data-af-element-effect]` inside `[data-af-element-effect-host]` on the profile page body (effect-only), the external
`.af-aa-context--sheet.af-apui-surface-body` wrapper, or the application root.
Postbits declare both `postbit-topbar` and `postbit-sidebar` hosts. Surface owners control positioning/stacking. Effects assets are delivered
by ElementTheme after manifest permission checks only when an enabled setting
matches a rendered canonical key/surface, including sheet modal caller facts.
Old installed templates receive only a decorative node through DOM integration;
no templates are restored or replaced. Disabled effects leave existing layout,
palette and custom CSS intact. With JS disabled, decoration remains hidden and
existing content/palette remains usable.

Drawing and motion use original CSS gradients, transforms and opacity, never
external copied assets or canvas. Textures have at most 12 fixed, non-repeating
points. Postbits/mobile use up to three points and static soft glow. There are no
particle DOM nodes, requestAnimationFrame/timer loops or animation KB queries.
Installed hero/header nodes are migrated to the full surface root by the DOM
integration, leaving exactly one layer and preserving nested surface boundaries.
The layer is absolute inside an isolated root, above its own background paint and below content; ATF excludes
it from its topbar content rule, preserving dimensions and desktop sticky position.
One IntersectionObserver pauses off-screen/hidden components; document inactivity
and reduced-motion also pause motion. One MutationObserver handles inserted
sheets/lazy profiles and unobserves detached components. Reduced-motion keeps
static decoration. Compiled effect styles/settings share the versioned MyBB
presentation cache and are invalidated on save.

Targeted coverage: `tests/element_theme_effects_regression.php` and
`tests/element_theme_effects_browser.cjs` (Chromium and installed Playwright Firefox
via `AF_TEST_BROWSER=firefox`), plus existing palette/delivery/editor regressions.

Element source, accent root and effect host are separate component roles. Profile
body uses the same approved owner key as its inner accent root, with
`data-af-element-effect-only` excluding body from authored Custom CSS scopes.
The body layer is fixed to the viewport within the profile page stacking context.
Sheet renderers pass canonical keys into per-instance wrappers (or standalone
body attributes); instances are never matched by UID. Postbit hosts inherit one
author key and one surface configuration. Ancestry lookup bridges installed
templates without restoring them; application integration is unchanged.
