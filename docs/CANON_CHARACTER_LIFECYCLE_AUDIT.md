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

The earlier token-consumption defect was fixed, but a second, independent break
remained in the successful-submit request. `newthread_start` resolves the token
for the display/preview path, while MyBB invokes `newthread_do_newthread_start`
before inserting a valid submission. The latter prepared posted ATF values but
did not call `af_atf_boot_prefill_from_token()`. PHP request globals do not carry
over from the GET that displayed the form, so the post-insert writer received an
empty `af_atf_prefill_kb_entry` and persisted no link. The fix resolves the
opaque token at the start of the POST path, before validation and insertion.

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

## Runtime architecture map

### Bootstrap and hook registration

The AF core discovers each directory containing a `manifest.php`, sorts the
manifests by addon name, checks `af_{id}_enabled`, includes the manifest's
bootstrap, and calls `af_{id}_init()` from its `global_start` hook. There is one
copy of each audited addon in the repository. Their runtime registration is:

| Addon | Bootstrap | Runtime hooks relevant to this flow |
|---|---|---|
| AdvancedThreadFields | `addons/advancedthreadfields/advancedthreadfields.php` | `newthread_start`, `newthread_do_newthread_start`, `newthread_do_newthread_end`, `datahandler_post_validate`, `datahandler_post_insert_thread`, `showthread_start`, `postbit`, `misc_start`, `pre_output_page` |
| CharacterWorkflow | `addons/characterworkflow/characterworkflow.php` | no page hook of its own; `af_characterworkflow_init()` ensures the schema and exposes the policy/persistence API used by the other addons |
| CharacterSheets | `addons/charactersheets/charactersheets.php`, which includes `modules/bootstrap.php`, `render.php`, and `sheets_crud.php` | `showthread_start`, `pre_output_page`, `misc_start`, and moderation move hooks |

All addon bootstraps are included during the same `global_start`, before the
later new-thread, DataHandler, show-thread, and misc hooks execute. No later hook
overwrites the moderation-button global. `CharacterSheets` assigns it once and
its pre-output hook injects the resulting HTML next to quick-reply controls.

### AdvancedThreadFields: KB to thread pipeline

| Step | Function / hook | Input | Output and KB identity location |
|---|---|---|---|
| Apply CTA | `af_kb_render_character_profile()` | active Character entry | `misc.php?action=kb_character_apply&source_kb_id=X`; shown only when `character_profile.category=canons` and availability permits applying |
| Apply handler | `af_kb_handle_character_apply()` (`Knowledgebase` misc dispatch) | trusted DB lookup by GET `source_kb_id` | calls `af_kb_build_character_application_prefill()`; payload contains `entry.id=X`, `entry.type=character`, `entry.key`, mechanic, mapped profile fields, and `character_abilities` |
| Capability storage | `af_atf_prefill_store_save()` | random token plus payload, uid, fid, expiry | row in `af_atf_prefill_tokens`; MyBB cache is only a fallback |
| Form GET | `af_atf_newthread_start()` → `af_atf_boot_prefill_from_token()` | `fid`, `af_atf_prefill_token` | maps payload values to ATF field IDs and sets request globals; the form carries only the opaque hidden token |
| Preview / failed validation | `af_atf_newthread_do_start()` and the normal form render | posted token and `af_tf` fields | token remains stored and is rendered again; it is not deleted on read |
| Valid submit | `af_atf_newthread_do_start()` | posted token, uid, fid | **now** calls `af_atf_boot_prefill_from_token()` before DataHandler runs, restoring `af_atf_prefill_kb_entry.id=X` in this POST request |
| Validation | `af_atf_dh_validate()` on DataHandler validation hooks | posted `af_tf` values | normalized `ph->data['af_atf_values']`, including the `character_abilities` JSON repeater |
| Thread insert | MyBB `ThreadDataHandler` | validated thread and ATF values | assigns `tid=Y` |
| Post-insert persistence | `af_atf_dh_insert_thread()` on `datahandler_post_insert_thread` | real tid, fid, uid, request-global server payload | calls `af_atf_character_bridge_store_thread_kb_link(Y, ...)`, then saves ATF values |
| Workflow persistence | `af_atf_character_bridge_store_thread_kb_link()` | `entry.id=X` | mirrors `kb_entry_id=X` into `af_charactersheets_accept`, calls `af_cwf_bind_kb_entry()` for canonical workflow persistence, updates canon lifecycle metadata, then deletes the token |

`newthread_do_newthread_end` remains a value-saving fallback. It can call the
same link writer, but the successful DataHandler path already clears the request
global after binding, so it cannot replace the correct relation with a zero.

### Actual moderation button chain

There is exactly one PHP construction of the label «Создать запись в KB»:

1. `CharacterSheets::af_charactersheets_init()` registers
   `showthread_start → af_charactersheets_showthread_start()`.
2. The wrapper calls `af_charactersheets_showthread_start_impl()` in
   `charactersheets/modules/bootstrap.php`.
3. That function builds the full button array and calls
   `af_atf_render_character_kb_moderation_button()`.
4. The ATF renderer calls `af_cwf_has_linked_kb_character()`. A link selects
   `action=af_atf_character_kb_sync` and «Синхронизировать с KB»; no link selects
   `action=af_atf_character_kb_create` and «Создать запись в KB».
5. CharacterSheets assigns the HTML to
   `$GLOBALS['af_charactersheets_accept_button']`; its `pre_output_page` hook
   injects this block. No MyBB template contains a second KB moderation action.

Thus the prior renderer change was executing, but was correctly choosing CREATE
because the workflow writer had never received the token payload on the valid
POST request.

### CharacterWorkflow storage and policy

The canonical table is `af_character_workflow`. Its primary key is `tid`; it has
no separate `uid` or forum columns because those come from the MyBB thread. The
stored columns are `state`, nullable `kb_entry_id`, nullable `sheet_id`, nullable
`sheet_slug`, nullable `greeting_post_id`, `reviewed_by`, `accepted_by_uid`,
`transferred_by_uid`, `accepted_at`, `transferred_at`,
`revision_requested_at`, and `updated_at`. Secondary indexes cover state, KB,
sheet, and greeting IDs.

`af_cwf_upsert_row()` merges an existing row before applying changes, so later
accept/transfer/sheet updates do not reset `kb_entry_id` to null. There is no
workflow cache: reads query the table on each call. The acceptance table is a
compatibility mirror and likewise has no static, AF, or MyBB cache in this path.

`af_cwf_has_linked_kb_character(tid, acceptRow)` reads, in order:

1. `af_character_workflow.kb_entry_id`;
2. `af_charactersheets_accept.kb_entry_id`;
3. legacy exact `character_meta.source_tid`.

It only requires that the linked KB entry is active and has type `character`.
It does **not** require acceptance state, a canon category, mechanic, or a
particular forum. Consequently a newly published pending canon is eligible for
SYNC. Stricter canon validation happens in `af_cwf_validate_source_kb()` when the
sync action executes.

The action/policy surface is:

| Action | Permission / prerequisite | Write target |
|---|---|---|
| `af_charactersheets_accept` | workflow pending forum plus configured moderator group and post key | acceptance mirror, greeting post, workflow state/acceptance metadata |
| `af_charactersheets_transfer` | allowed forum, moderator group, accepted workflow | MyBB thread forum, workflow transfer metadata, configured user groups |
| `af_atf_character_kb_create` | allowed forum, moderator group, no linked Character, post key | creates or updates deterministic `character.key=oc-{tid}`, then binds both metadata stores |
| `af_atf_character_kb_sync` | allowed forum, moderator group, linked active Character, post key | updates that exact KB entry, preserves the immutable canon baseline, binds metadata, refreshes an existing/accepted sheet |
| `af_charactersheets_create_sheet` | allowed forum, moderator group, no existing sheet, post key | acceptance mirror, `af_cs_sheets`, workflow sheet fields |
| needs revision | `af_cwf_mark_needs_revision()` API only; no UI endpoint in these addons | workflow state/reviewer/time |
| release / restore baseline | KB character status-save route, KB edit permission and post key | Character KB lifecycle metadata/content; restore uses the saved canon baseline |

### Key-name contract

| Name | Owner | Lifetime / purpose | Read by |
|---|---|---|---|
| `source_kb_id` | Knowledgebase apply route | untrusted request selector used once for a validated active Character lookup | `af_kb_handle_character_apply()` |
| `entry.id` | server prefill payload | immutable KB identity inside token-protected transport | ATF prefill boot/link writer |
| `af_atf_prefill_token` | KB + ATF | opaque browser transport, persisted through previews/errors | ATF prefill store reader and success-only cleanup |
| `kb_entry_id` | CharacterWorkflow; compatibility mirror in CharacterSheets | canonical persistent relation from thread to KB Character | workflow policy, renderer, sync, sheet resolver |
| `entry_id` | workflow/helper result arrays | transient normalized return name | action handlers and callers |
| `character_meta.source_tid` | KB Character contract | legacy exact reverse link and lifecycle trace | workflow and sheet fallback resolvers |
| `character_kb_id`, `kb_id`, `kb_entry` | none in this lifecycle | no persistent identity contract | none |

### Character contract and CharacterSheets

The actual canon discriminator is
`rules.character_profile.category = canons` (plural). The mechanic is
`rules.character_meta.mechanic`; `af_kb_extract_character_contract()` exposes
these as `profile.category` and `meta.mechanic`. The workflow code compares
against `canons` and reads `meta.mechanic`, so neither a `canon`/`canons`
mismatch nor a top-level mechanic lookup causes this defect.

The sheet source pipeline is:

`tid → af_cwf_has_linked_kb_character() → kb_entry_id → active Character →
af_kb_extract_character_contract() → profile/stats/abilities/meta →
af_charactersheets_build_arpg_view_model()`.

`af_charactersheets_resolve_character_kb_entry()` prioritizes the explicit
workflow/mirror relation, then deterministic `oc-{tid}`, then the legacy exact
`character_meta.source_tid`. Once a KB Character exists, the ARPG view model
uses its profile and embedded abilities and suppresses ATF profile/ability
fallbacks. Runtime `build_json` still owns equipment, inventory, purchased
abilities, and progression; KB sync refreshes stable base selectors without
overwriting those runtime structures. DnD render-path detection remains
separate and unchanged.

The create-sheet action resolves the KB source before creating the sheet. For an
original application with no KB link it can still use the intended ATF-first
fallback. For a linked canon after this fix, it resolves the existing KB entry;
the moderation UI also presents SYNC rather than CREATE. No schema, template,
setting, or destructive migration is required for the repair.

## Proven root cause and minimal repair

**Root cause:** `af_atf_newthread_do_start()` did not resolve the submitted
`af_atf_prefill_token`. On a successful publish request, therefore,
`af_atf_dh_insert_thread()` called the real workflow writer with an empty
`af_atf_prefill_kb_entry`; neither `af_character_workflow.kb_entry_id` nor its
acceptance mirror was written. The actual renderer subsequently found no link
and correctly emitted CREATE.

**Repair:** call `af_atf_boot_prefill_from_token($fidI)` in
`af_atf_newthread_do_start()` before input preparation/DataHandler insertion.
The existing success-only cleanup, immutable-ID validation, writer, renderer,
sync implementation, and CharacterSheets source precedence remain unchanged.
