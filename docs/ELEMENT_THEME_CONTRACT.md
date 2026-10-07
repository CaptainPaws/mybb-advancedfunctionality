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
`af_frontend_asset_allowed()` with an actual `has_element_surface` fact. Modal
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
CSS. @media/@supports/@container groups are supported. Global definitions such
as @import/@font-face/@keyframes are rejected, as are unbalanced CSS and HTML
style termination. Escapes outside quoted strings are rejected to avoid ambiguous
scoping validation. Extra variable values cannot contain declaration delimiters.

Regression coverage: `tests/element_theme_editor_regression.php`,
`tests/element_theme_editor_browser.cjs`, `tests/element_theme_regression.php`, the existing ATF
postbit/batch/hot-path, active-application gate, profile composition and
CharacterSheets frontend regressions.
