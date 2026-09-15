# Canon character lifecycle audit (KB ↔ ATF ↔ CharacterWorkflow)

This audit was completed before the lifecycle implementation.

1. **Workflow metadata:** application state is stored by `CharacterWorkflow` in
   `af_character_workflow`, keyed by `tid`; its existing `kb_entry_id` is the
   appropriate immutable KB link. `af_charactersheets_accept.kb_entry_id` is a
   compatibility mirror, not a second identity contract.
2. **Apply button:** `af_kb_render_character_profile()` in the Knowledgebase
   addon renders «Подать анкету» for free canon entries.
3. **KB → ATF prefill:** `af_kb_build_character_application_prefill()` creates
   the payload and `af_kb_handle_character_apply()` stores its short-lived token;
   `af_atf_boot_prefill_from_token()` resolves it on `newthread.php`.
4. **Moderation action visibility:** `af_atf_render_character_kb_moderation_button()`
   delegates create/sync policy to `af_cwf_can_create_kb()` and
   `af_cwf_can_sync_kb()`.
5. **Duplicate protection:** the original path uses the deterministic `oc-{tid}`
   key in `af_atf_bridge_sync_character_kb_from_thread()`. Before this change the
   canon branch only linked the entry and did not sync it; server-side identity
   policy was partly based on inferred character category.
6. **KB history/versioning:** there is no entry revision table or version service.
   Entries expose `meta_json`/`data_json`; neither is a revision journal.
7. **Baseline location:** the existing `rules.character_meta` envelope inside KB
   metadata is therefore the least invasive storage. `canon_baseline` is written
   once and remains outside the normal synchronized fields.
8. **Availability:** `rules.character_meta.availability.status` is the existing
   status mechanism (`free`, `held`, `occupied`). The lifecycle adds `pending` to
   that same field and uses `active_application_tid`, rather than creating a
   parallel status.
9. **Archived application:** workflow has no explicit archive column. A linked
   application is active only while its thread exists in a configured pending or
   accepted CharacterWorkflow forum. Moving it elsewhere (or deleting it) makes
   it historical and releases the role; old workflow rows and topics are retained.

