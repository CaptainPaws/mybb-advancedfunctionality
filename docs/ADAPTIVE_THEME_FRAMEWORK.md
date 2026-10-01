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

## Compatibility constraints

The addon does not patch MyBB core, remove or modify templates, replace
`member_profile` or `postbit`, copy Adaptive Responsive Layout, introduce visual
design, or migrate another addon. Adaptive Theme Framework and the old
Adaptive Responsive Layout are mutually exclusive: when ATF is enabled, the
legacy addon's asset injection is hard-disabled. No slots model the old
`advresponsivelayout.js` DOM contract.

Frontend asset lists are intentionally empty until the component-slot contract
and presentation implementation are introduced.
