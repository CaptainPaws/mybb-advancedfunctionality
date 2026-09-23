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
   reads the persisted link directly through `af_cwf_has_linked_kb_character()`;
   it then delegates forum/action policy to `af_cwf_can_create_kb()` and
   `af_cwf_can_sync_kb()`. Acceptance metadata is not a prerequisite for
   selecting «Синхронизировать с KB».
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

## Immutable link lifecycle audit (`source_kb_id = 123`)

The first loss was at the prefill-store read on **any POST**. The implementation
deleted the server-side token before MyBB knew whether the request was a preview,
had validation errors, or had successfully inserted a thread. Thus the preview
response still rendered the opaque hidden token, but the next POST could no
longer resolve it. A direct valid submit could retain the in-request global, but
the persistence path also depended on ATF values and on the later page hook.

| Stage | ID 123 after the fix | Evidence / representation |
|---|---|---|
| KB «Подать анкету» | present | GET transport starts as `source_kb_id=123`. |
| Apply request handler | present | The handler loads active Character 123 and checks its canon category. |
| Server prefill payload | present | `entry.id=123`; payload is stored against user, forum, expiry, and random token. |
| Initial ATF values | present server-side | The token resolves the payload; the technical ID is not an editable ATF value. |
| Form metadata | present | Only `af_atf_prefill_token` is a named, enabled hidden input inside the injected ATF form block. |
| Preview | present | The token row is read but no longer deleted by a POST read. |
| Submit POST | present | The hidden opaque token resolves again for the same user and forum. |
| Thread creation | present | `datahandler_post_insert_thread` supplies the real tid. |
| DataHandler hook | present | The server-owned `entry.id=123` is explicitly handed to the thread-link writer. |
| Workflow metadata | present | Canonical persistent key `af_character_workflow.kb_entry_id=123`; acceptance metadata is only a compatibility mirror. |
| Acceptance metadata | present immediately | `af_charactersheets_accept.kb_entry_id=123` is written at creation, not deferred until acceptance. |
| Moderator lookup | present | `af_cwf_has_linked_kb_character()` reads workflow first, validates active Character type, then compatibility/legacy sources. |

**Transport key:** `af_atf_prefill_token` in the browser; `entry.id` (originating
as `source_kb_id`) in its server-side payload. No browser-provided KB ID is
trusted. **Persistent key:** `kb_entry_id`. **Persist hook:**
`af_atf_dh_insert_thread()` after `ThreadDataHandler` assigns the tid. The token
is deleted only after the server-owned ID has been written against that tid.
