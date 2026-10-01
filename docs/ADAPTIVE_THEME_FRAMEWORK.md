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

## Initial scaffold constraints

The initial addon does not patch MyBB core, remove or modify templates, replace
`member_profile` or `postbit`, copy Adaptive Responsive Layout, introduce visual
design, or migrate another addon. In particular, the existing
`advresponsivelayout` addon remains separate and unchanged.

Frontend asset lists are intentionally empty until the component-slot contract
and presentation implementation are introduced.
