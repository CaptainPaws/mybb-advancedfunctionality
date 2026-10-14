# ATF page shell contract

Adaptive Theme Framework is the sole owner of the exterior frame, width,
padding and common responsive thresholds of standalone frontend pages.

```html
<div class="pun atf-page-shell">
    <!-- addon content -->
</div>
```

An existing semantic `main` with these classes is equally valid and must be
reused. Exactly one shell belongs to a document. Never insert a shell inside
another shell, or put one on AJAX fragments, iframe/modal content, dropdowns,
tabs, embedded CharacterSheets or postbit. A standalone CharacterSheets view
receives a shell; `embed`, `ajax` and `af_apui_surface=sheet` responses do not.
Appearance previews extracted from full ATF templates remove the shell classes.

The selector `body.atf-active .pun.atf-page-shell` owns geometry. Addons must
not introduce competing outer width/max-width, margin, padding, background,
border, radius or shadow. Semantic wrappers may remain as content. Inner
cards, sections, controls and component-specific responsive layouts retain
their own styles. When ATF is disabled, existing addon fallback styles remain.

The approved decoration is [ATF_ORNATE_FRAME.md](ATF_ORNATE_FRAME.md): a 6px cut,
blue/violet gradient outline, two inner lines, light corners and dark gradient.
All seven `--atf-frame-*` variables are preserved. The upstream 0.29.7 root background tiles paint the cut silhouette; pseudo-layers
paint only the inner lines and corners. The content root has visible overflow
and no clip, mask or transform. The minifier-safe descendant selector and
`#content` specificity fix are preserved. No global `.pun` decoration
and no independent addon frame are permitted.

## ACP configuration

The existing AF settings group is `af_adaptivethemeframework`. A validated editor
is available at Advanced functionality → ATF: внешняя рамка. It rejects invalid
ranges and ordering without updating settings. The normal MyBB settings group
also exposes the six keys. Values saved through that generic editor or directly
in the database are validated again before producing CSS: dimensions are bounded,
non-integer strings use defaults, and invalid breakpoint ordering uses the entire
480/768/1024 default triple.

| Setting | Default | Range / invariant | CSS variable |
| --- | --- | --- | --- |
| `af_atf_page_max_width` | 1200px | 800–1920 | `--atf-page-max-width` |
| `af_atf_page_padding` | 20px | 8–48 | `--atf-page-padding` |
| `af_atf_page_mobile_gutter` | 12px | 4–32 | `--atf-page-mobile-gutter` |
| `af_atf_breakpoint_tablet` | 1024px | 242–2560 | `--atf-breakpoint-tablet` |
| `af_atf_breakpoint_mobile` | 768px | 241–2559 | `--atf-breakpoint-mobile` |
| `af_atf_breakpoint_narrow` | 480px | 240–2558 | `--atf-breakpoint-narrow` |

Server invariant: `narrow < mobile < tablet`. No prior ATF width setting exists
in this checkout; 1200px retains its former 75rem design default at 16px.
Lifecycle registration preserves existing saved values and the enabled switch.
The desktop maximum includes padding (`border-box`): a 1200px setting yields
1200px exterior width when the available parent space permits it. At mobile
widths the shell reserves the configured gutter on both sides of its available
parent. Inner roots use their parent's content width.

A small server-generated style emits validated numeric `@media` conditions.
Tablet/narrow change shared component spacing; mobile switches shared grids and
page gutter; narrow switches shared buttons. Specific addon/component breakpoints
are intentionally retained. `@media (... var(...))` and viewport-detection JS
are not used.

## Delivery and ownership

The approved frame and geometry remain in the existing ATF design-system CSS.
Its manifest still declares global frontend permission, the same frontend asset,
and the same Theme stylesheet identity and attachments. Generated settings CSS
is delivered by ATF's existing page-output hook in both Theme and File modes.
It neither creates a bundle nor writes any Theme stylesheet. Normal AF source
sync preserves custom Theme styles; administrators must merge updated shell rules
into a customized old stylesheet when normal sync reports a manual override.
Update ATF through its normal lifecycle to register the settings and sync sources.
Addon page templates continue to use their existing owners and synchronization.
Rules' renderer promotes the existing semantic root in memory when an installed
`rules_index` still lacks the classes; it does not overwrite customized templates.
MyBB core, AF core and permission decisions are unchanged.

New AF addons must declare frontend permissions in their manifest using
[FRONTEND_ASSET_MANIFEST_CONTRACT.md](FRONTEND_ASSET_MANIFEST_CONTRACT.md).
Do not add plugin-specific frontend rules to AF core. Use the existing template
or page renderer to establish the shell; reuse a shell supplied by the host.

## Page inventory

- Existing MyBB ATF roots: index, forumdisplay, showthread, member/profile and
  supported member forms, usercp, private, memberlist/userlist, newthread,
  newreply, editpost, modcp, moderation, polls, reputation and other already
  owned full-page seeds. Their HTML shells are reused.
- Appearance: apstudio/studio/workshop and fittingroom.
- KB and AdvancedCharacters: the shared KB full-page and edit renderers,
  including `kb.php`, `characters.php` and manifest-declared misc routes.
- Wanted: catalog, dossier and forms through its full-page renderer.
- Shop: hub, shop and management views using its existing full-page template.
- Inventory: inventory, abilities and inventories listing; entity iframes remain
  content-only.
- CharacterSheets: catalog and standalone sheet, excluding embedded responses.
- Gallery and Buddy: existing standalone template/renderer.
- Rules: `misc.php?action=advancedrules`.
- Balance: standalone balance management shell only; mechanics unchanged.
- PostCounter: standalone activity and posts-by-user page templates only.
- Statistic remains an index component. AccountSwitcher and Alerts UCP already
  have canonical shells, which are reused.

Visual and functional acceptance belongs to the forum owner on installed MyBB.
This contract does not claim browser verification.
