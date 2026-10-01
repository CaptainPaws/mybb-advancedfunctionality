# Addon Lifecycle Contract

AdvancedFunctionality has one lifecycle boundary for every addon. The reloaded MyBB enabled setting is authoritative; a loaded bootstrap, an existing function, or a `manifest.php` file is not runtime permission.

## Contract

1. **Deactivate is not Uninstall.** Deactivation stops runtime execution; destructive schema or data cleanup belongs exclusively to uninstall.
2. An addon is inactive immediately after its enabled setting is persisted and reloaded. The remainder of the same ACP request must observe that setting.
3. ACP enable/disable must not globally clear callback, frontend-owner, menu-provider, response-fact, asset, or self-heal registries. Other code in the request can still own and use those registries. Rebuilding one requires an explicit, registry-specific operation which leaves it usable.
4. Runtime callbacks owned by an inactive addon must not execute, even when PHP has already loaded their functions with `require_once`.
5. Ensure/repair/sync/alias/task/template self-heal is runtime work and is permitted only for an active addon. An explicit lifecycle callback may perform narrowly scoped deactivate reconciliation; it must not reactivate runtime.
6. Persistent tables, records, settings, migration markers, and user configuration survive Deactivate.
7. Manual database cleanup is not a supported recovery process. Existing database state must be handled idempotently by lifecycle code.
8. Activate and Deactivate are idempotent and must support repeated cycles in one unchanged installation.
9. Manifest existence describes addon capabilities, but does not make the addon active. Frontend permission requires both a manifest match and current active state.
10. AF core runtime dispatch must use the shared enabled-state resolver and callback guard. Explicit install/deactivate callbacks are lifecycle operations and are not passed through the inactive runtime-callback guard. Addon-specific exclusions, blacklists, and lifecycle hacks are forbidden.
11. The `af_templates_synced_runtime` and `af_theme_stylesheets_synced_runtime` flags record completed work. An ACP toggle must not reset them and thereby reopen an unrelated synchronization later in the same request.

The enable order is: load the addon bootstrap, run its idempotent install callback, persist `enabled=1`, reload MyBB settings, reconcile the addon's stylesheets, and return the normal success redirect. The disable order is: persist `enabled=0`, reload MyBB settings, detach and reconcile the addon's stylesheet sources, then invoke its explicit deactivate callback before redirecting. Frontend injection and provider/self-heal dispatch are not part of either redirect path.

`af_lifecycle_transition()` remains a low-level helper for callers which own the complete request-local execution context. It is not part of the ACP enable/disable sequence: its broad invalidation cannot guarantee that every registry needed by the remainder of an ACP request is rebuilt.

## Lifecycle checklist

- [ ] Activate works
- [ ] Deactivate works without HTTP 500
- [ ] repeated Activate works
- [ ] repeated Deactivate works
- [ ] persistent data are preserved
- [ ] inactive provider is not invoked
- [ ] self-heal does not run
- [ ] unrelated request registries and completed-sync flags remain usable
- [ ] Uninstall is separate from Deactivate
- [ ] no manual database cleanup is required

## Regression history

The disable reconciliation introduced in `e595f03` moved deactivation ownership into the common dispatcher, persisted `enabled=0`, reloaded settings, and detached stylesheet state before the single addon callback. Commit `d15989d` then inserted the broad `af_lifecycle_transition()` invalidation into both ACP toggle paths. That cleared registries still owned by the active request and reset completed-sync flags after settings had already been reloaded. The ACP paths therefore returned to the last stable boundary from `80e9517`: settings reload is the state publication mechanism and core stylesheet reconciliation remains explicit.

## Lifecycle changes require integration coverage

Changes to `enableAddon()`, `disableAddon()`, the active-state resolver, lifecycle transition helpers, addon bootstrap, or stylesheet reconciliation require an integration regression through the real public `AF_Admin` methods. Static source checks, extracted-function `eval()` tests, and manually changing `$mybb->settings` are useful unit checks but are not lifecycle acceptance coverage. The integration must perform Enable → Disable → Enable with real discovery, bootstrap callbacks, settings reload, reconciliation, and request-local registries.
