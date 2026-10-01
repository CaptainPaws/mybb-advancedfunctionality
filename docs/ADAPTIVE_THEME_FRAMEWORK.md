# Adaptive Theme Framework

`adaptivethemeframework` is the AdvancedFunctionality presentation owner for
adaptive layout and theme concerns. Its frontend scope is declared globally by
the addon's manifest-based permission metadata.

## Ownership boundaries

- Adaptive Theme Framework owns layout and theme presentation.
- It does **not** own navigation data. AdvancedMenu remains the navigation owner.
- Addon business logic remains in the addon that owns that domain.
- Standard MyBB template names remain compatibility entrypoints.
- New visual markup will be composed through ATF component slots.

## Component slot contract

Providers call `af_adaptivethemeframework_register_component()` with `owner`,
`key`, `slot`, and either a callable `renderer` or static `html`. The stable
identity is `owner::key`, so registering it twice (including in a different
slot) is rejected. `sortorder` controls deterministic ordering; equal values
are ordered by stable identity. `visibility` may be a boolean or callback, and
`context` may be a callback or a map of exact accepted context values.

Layouts call `af_adaptivethemeframework_render_slot($slot, $context)`. Disabled
addon owners, invisible components, and components whose context does not
match are omitted before their renderer runs. Provider output is trusted HTML;
providers remain responsible for escaping user data.

During migration, `legacy` can contain an existing rendered variable or a
callback. It is emitted together with the new component (`legacy_position` is
`before` or `after`). This dual-render facility is explicit data composition:
it does not inspect or depend on legacy DOM markers.

The catalogue covers the audited profile, post, thread, forum-card, header and
footer integration points. It is returned by
`af_adaptivethemeframework_slots()` and is the authoritative allowlist.

## Design-system contract

ATF visual primitives use the `atf-` prefix. Components use BEM-style
`__element` and `--modifier` suffixes; they do not use MyBB row/table selectors
or `af-apui-*` as their foundation. The initial public set is `atf-page`,
`atf-section`, `atf-card`, `atf-grid`, `atf-stack`, `atf-meta`, `atf-button`,
`atf-tabs`, `atf-chip`, and `atf-form-row`.

Every token and component selector is rooted at `body.atf-active`. The addon
adds that marker at `pre_output_page` only while its bootstrap is active, so a
cached stylesheet has no effect after deactivation. The stylesheet is declared
through `theme_stylesheets` and becomes an `advancedstyles.css` section; the
direct frontend asset list intentionally remains empty.

The responsive contract has desktop as its default and named tablet (`64rem`),
mobile (`48rem`), and narrow-mobile (`30rem`) boundaries. Page and component
gaps are tokenized at the root and changed together at those boundaries.

## Compatibility constraints

The addon does not patch MyBB core, remove or modify templates, replace
`member_profile` or `postbit`, copy Adaptive Responsive Layout, or migrate
another addon. Existing pages are not restyled until they opt into ATF markup.

Adaptive Responsive Layout (`advresponsivelayout`) is deprecated. Adaptive
Theme Framework is its independent replacement, and the two addons should not
be enabled at the same time. The legacy addon is not a dependency of ATF: ATF
does not load it, call it, or rely on its assets or runtime. No slots model the
old `advresponsivelayout.js` DOM contract.

Frontend asset lists remain intentionally empty because CSS delivery is owned
by the AF theme stylesheet pipeline rather than a direct asset URL.
