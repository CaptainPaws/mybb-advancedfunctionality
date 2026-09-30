# Addon Lifecycle Contract

AdvancedFunctionality has one lifecycle boundary for every addon. The enabled setting and the request-local state published by `af_lifecycle_transition()` are authoritative; a loaded bootstrap, an existing function, or a `manifest.php` file is not runtime permission.

## Contract

1. **Deactivate is not Uninstall.** Deactivation stops runtime execution; destructive schema or data cleanup belongs exclusively to uninstall.
2. An addon is inactive immediately after its enabled state changes. The remainder of the same ACP request must observe that state.
3. A runtime registry built before a lifecycle transition is stale. Callback, frontend-owner, menu-provider, response-fact, asset, and self-heal registries must be invalidated or rebuilt before reuse.
4. Runtime callbacks owned by an inactive addon must not execute, even when PHP has already loaded their functions with `require_once`.
5. Ensure/repair/sync/alias/task/template self-heal is runtime work and is permitted only for an active addon. An explicit lifecycle callback may perform narrowly scoped deactivate reconciliation; it must not reactivate runtime.
6. Persistent tables, records, settings, migration markers, and user configuration survive Deactivate.
7. Manual database cleanup is not a supported recovery process. Existing database state must be handled idempotently by lifecycle code.
8. Activate and Deactivate are idempotent and must support repeated cycles in one unchanged installation.
9. Manifest existence describes addon capabilities, but does not make the addon active. Frontend permission requires both a manifest match and current active state.
10. AF core must use the shared lifecycle state and callback guard. Addon-specific exclusions, blacklists, and lifecycle hacks are forbidden.

The ACP transition order is: persist enabled state, reload MyBB settings, publish/invalidate request-local lifecycle state, perform core reconciliation, invoke the explicit lifecycle callback, and return the normal success redirect. Frontend injection and provider/self-heal dispatch are not part of that redirect path.

## Lifecycle checklist

- [ ] Activate works
- [ ] Deactivate works without HTTP 500
- [ ] repeated Activate works
- [ ] repeated Deactivate works
- [ ] persistent data are preserved
- [ ] inactive provider is not invoked
- [ ] self-heal does not run
- [ ] request caches are invalidated
- [ ] Uninstall is separate from Deactivate
- [ ] no manual database cleanup is required

## Regression history

The disable reconciliation introduced in `e595f03` moved deactivation ownership into the common dispatcher, persisted `enabled=0`, reloaded settings, and detached stylesheet state before the single addon callback. Temporary diagnostics in `7e6ce13` confirmed that state changed before the request failed. Later manifest-based permission and function-discovered menu providers created new runtime paths which treated an on-disk manifest or loaded provider function as permission, bypassing that lifecycle boundary. The shared transition state and callback guard restore the original contract without adding a second addon registry or per-addon exceptions.
