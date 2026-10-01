# Adaptive Theme Framework: template ownership and compatibility contract

**Target:** warprift.ru / MyBB 1.8.40 / PHP 8.5.0
**Status:** normative migration contract; it does not authorize template replacement in
the current implementation.  The evidence and dependency inventory are in
[`ATF_TEMPLATE_COMPATIBILITY_AUDIT.md`](ATF_TEMPLATE_COMPATIBILITY_AUDIT.md).

## Normative rules

In this document **ATF** means **Adaptive Theme Framework**.  AdvancedThreadFields is
written out to avoid the historical `af_atf_*` prefix ambiguity.

1. A stored template has exactly one presentation owner at a time. A provider may own
   data rendered in a slot, but that does not grant it permission to replace the stored
   template.
2. With Adaptive Theme Framework disabled, it neither writes templates nor arbitrates
   other addons. AdvancedProfileUI (APUI) continues its existing standalone behaviour.
3. With Adaptive Theme Framework enabled, it is the sole presentation owner of the
   templates marked **ATF** below. APUI must not apply, refresh, or restore its bundled
   `member_profile`, `postbit_classic`, or `showthread` over an ATF-owned row. APUI remains
   the data provider for its named slots.
4. ATF ownership is a reversible lease, not a transfer or a reset. Deactivation restores
   the exact bytes and metadata captured immediately before the first ATF write. This may
   be a stock MyBB template, an APUI template, or a manually edited theme template; a
   bundled MyBB/APUI seed is never a restoration source.
5. An addon migrated to a slot must stop install/update/uninstall code from patching that
   template while ATF owns it. Hook-based data production remains active.
6. The page shell must render `{$headerinclude}`, `{$header}`, `{$footer}`, and
   `{$stylesheets}` exactly where applicable until all asset integrations have migrated.
   In particular, `$headerinclude` must be emitted once and `{$stylesheets}` must remain
   inside `headerinclude`.

“Owner with ATF” below describes the target migration state. Until the reversible
ownership ledger and activation/deactivation algorithm in this document exist, ATF must
not replace any stored template.

## Ownership and rendering matrix

`Core/theme state` means the template selected by MyBB inheritance plus any user or
third-party edits that exist at activation time. `Existing owner` means ATF does not take
that template over in this migration phase.

| Template | Owner without ATF | Owner with ATF | Required variables | Slots | Temporary shims |
|---|---|---|---|---|---|
| `member_profile` | APUI when enabled; otherwise core/theme state | **ATF**; APUI is data-only | `{$af_apui_forum_info_grid}`, `{$af_apui_character_sheet_tab}`, `{$af_apui_application_tab}`, `{$af_apui_timeline_tab}`, `{$af_apui_activity_tab}`, `{$profilefields}`, `{$contact_details}`, `{$signature}`, `{$modoptions}`, `{$adminoptions}`, `{$buddy_options}`, `{$ignore_options}`, `{$report_options}`, `{$memprofile['uid']}`; also preserve `{$memprofile['advancedpostcounter']}` and the Balance provider output | `profile.forum_info`, `profile.character_sheet`, `profile.application`, `profile.timeline`, `profile.activity`, `profile.balance`, `profile.post_counter`, plus profile composition slots | Keep APUI tab hooks/selectors and uid-scoped Appearance classes until their JS/CSS migrate. Legacy variables may be passed explicitly through a slot, never discovered from DOM. |
| `postbit` | Core/theme state (APUI does not replace it) | **ATF** | `{$post['message']}`, `{$post['signature']}`, attachments and post metadata; all generated author/contact/edit/delete/restore/quote/multiquote/report/warn/moderation/PM controls; mention action/button collection; `{$post['advancedpostcounter']}` and the post id/uid context | `post.author.identity`, `post.author.meta`, `post.author.profile_fields`, `post.author.rail`, `post.author.plaque`, `post.author.character`, `post.post_counter`, `post.before_body`, `post.after_body`, `post.actions` | Preserve MyBB post IDs/button variables while consumers move to slot-owned roots. Do not run the PostCounter template patch. |
| `postbit_classic` | APUI when enabled; otherwise core/theme state | **ATF**; APUI is data-only | Everything required for `postbit`, plus `{$post['af_aa_user_class']}`, `{$post['af_apui_presence_html']}`, `{$post['af_apui_profile_fields_html']}`, `{$post['af_apui_rail_html']}`, `{$post['af_apui_plaque_html']}` | Same `post.*` slots as `postbit`; APUI presence/profile fields/rail/plaque map to the corresponding author slots | Keep `.post.classic` and the APUI author subtree only as a time-bounded RWD/Appearance compatibility shim. ATF and deprecated AdvResponsiveLayout must not be enabled together once that shim is removed. |
| `showthread` | APUI when enabled; otherwise core/theme state | **ATF**; APUI is data-only | `{$posts}` inside both `#posts_container` and `#posts`; `{$quickreply}`; `{$moderationoptions}` and other moderation/thread tools; `{$af_atf_showthread_block}`; pagination/reply/rating/poll/thread-note/browsing/similar-thread variables and MyBB quick-edit/delete/report/thread JS globals | `thread.breadcrumbs`, `thread.meta`, `thread.atf_fields`, `thread.before_posts`, `thread.after_posts` | Temporarily retain `<!--AF_ATF_SHOW-->` and APUI breadcrumb markers/classes only for unmigrated fallback code. The final path renders AdvancedThreadFields through `thread.atf_fields`, not marker injection. |
| `forumdisplay_thread` | Core/theme state plus PosterAvatar/AdvancedThreadFields patches | **ATF** | `{$thread['af_atf_forum_chips']}`, last-poster/date/link/avatar context and normal thread-row values | `thread.meta_chips`, `thread.lastposter_avatar` | Recognise `<!--AF_ATF_FORUM_CHIPS-->` and PosterAvatar marker/cell output during transition; do not reapply either template patch after provider migration. |
| `forumbit_depth2_forum` | Core/theme state | Existing owner (not taken over in this phase) | Normal MyBB forum-row contract; no direct AF variable found | None currently | Manual production review; do not infer safety from the `_lastpost` child. |
| `forumbit_depth2_forum_lastpost` | Core/theme state plus PosterAvatar patch | **ATF** when the forum-card composition migrates | Last-poster identity/link/date and avatar provider context | `forum.lastposter_avatar` | Accept existing `<!-- AVATAR START/END -->` output during transition; migrated PosterAvatar must stop wrapping the template. |
| `index` | Core/theme state | Existing owner | Header-owned `{$af_advancedmenu_top}` / `{$af_advancedmenu_panel}` integrations and standard index values | Header navigation slots, not an index-template slot | No template patch; keep hook behaviour. |
| `header` | Core/theme state | **ATF** when page-shell migration begins | `{$af_advancedmenu_top}`, `{$af_advancedmenu_panel}`, member/user navigation values, and page-shell `{$header}` consumption | `header.primary_navigation`, `header.secondary_navigation`, `header.user_navigation` | Keep AdvancedMenu roots/drawer controls and the AccountSwitcher member-list URL rewrite until their consumers migrate. |
| `headerinclude` | Core/theme state plus FontAwesome, JSBandle and Alerts patches | **ATF** when asset-slot migration begins | `{$stylesheets}`, `{$af_aam_js}`, `{$af_aam_css}` and runtime additions to `$headerinclude` | `header.assets` | Preserve old marked blocks while adopting them once; migrated addons must disable their patchers. Never drop `{$stylesheets}` or emit `$headerinclude` twice. |
| `header_welcomeblock_member` | Core/theme state plus Alerts/AccountSwitcher patches | **ATF** when header migration begins | `{$af_aam_header_icon}`, `{$af_aas_header_button}`, `$modcplink`, `$usercplink` and normal member actions | `header.user_navigation` | Read existing markers during adoption only; prevent both legacy installers from reinserting variables after migration. |
| `footer` | Core/theme state plus Alerts patch | **ATF** when page-shell migration begins | `{$af_aam_modal}` and page-shell `{$footer}` consumption | `footer.components`, `footer.modals` | Keep the existing modal markup/IDs until Alerts registers `footer.modals`; then disable its append/remove patch. |
| `private` | Core/theme state | Existing owner | Standard PM variables | None currently | Preserve `.pm_table` until responsive code is migrated; hooks remain DOM-independent. |
| `memberlist` | Core/theme state | Existing owner | Standard member-list link/row values | None currently | Preserve a real member-list anchor until AccountSwitcher replaces its output-wide URL regex with a navigation contract. |
| `userlist.php` template family | AdvancedAccountSwitcher | AdvancedAccountSwitcher (data and route); ATF may style only through future explicit slots | AAS user rows, images, online-state context and route values | None currently | Preserve `.userlist` and current table/card selectors until AAS JS is migrated. |

The mandatory lists are additive: normal MyBB variables used by a template remain part of
its contract even when the table highlights only compatibility-sensitive values. ATF may
change markup, but must not silently remove permissions, controls, security tokens,
moderation paths, accessibility state, or guest/member variants produced by MyBB.

## APUI arbitration

The activation order is explicit:

* **ATF off:** APUI may apply and restore its three current full-template overrides.
* **ATF activation:** ATF first captures the live rows. Only after that successful capture
  may it install its presentation. If the live bytes happen to be APUI's, those exact
  bytes—not APUI's current bundled file—are the previous state.
* **ATF on:** APUI skips all three override/restore/update writers for ATF-owned `(sid,
  title)` rows, but continues hooks and registers its rendered values as components.
* **ATF deactivation:** ATF applies the conflict-safe restore algorithm below. After the
  ownership lease is released, APUI may resume its normal standalone lifecycle; it must
  not be reinstalled as a means of restoration.

This ordering prevents APUI activation, upgrade, deactivation, or uninstall from writing
over live ATF markup. It also guarantees that disabling ATF returns to an APUI-owned
template when APUI was the owner immediately before ATF activation.

## Reversible ownership ledger

Use a dedicated AF table (proposed name
`TABLE_PREFIX . 'af_adaptivethemeframework_template_ownership'`) rather than APUI's backup
table. APUI's table represents APUI's own lease and is not authoritative for what was live
when ATF activated. The natural identity is `(template_sid, template_name)`; retain
`template_tid` only as a lookup hint because a row can be recreated.

Each ledger record must contain:

| Field | Meaning |
|---|---|
| `template_name`, `template_sid`, `template_tid` | Template name, set/theme context, and current row hint. Master/inherited resolution must be made explicit; never silently write `sid=-2`. |
| `previous_content`, `previous_checksum`, `previous_dateline` | Exact pre-ATF bytes, SHA-256 (or the AF-wide documented checksum algorithm), and original template timestamp. |
| `atf_seed_content`, `atf_seed_checksum` | The old ATF seed required for three-way upgrade comparison. Content is retained so conflicts preserve both versions. |
| `atf_installed_checksum` | Checksum of the bytes actually written after deterministic expansion; this is the ownership proof used for safe update/restore. |
| `atf_version` | ATF version that installed the owned value. |
| `ownership_state` | One of `owned`, `manual_override`, `restore_conflict`, `restored`; unknown values fail closed. |
| `created_at`, `updated_at`, `restored_at` | First acquisition, last transition, and successful release timestamps. |

The table requires a unique key on `(template_sid, template_name)` and indexes for
`ownership_state` and `template_tid`. Content and checksums must be written in the same
database transaction as the template change where the database driver supports it. A
failed ledger write aborts the template write. A new activation after a completed
`restored` lease starts a new acquisition record/history generation; it must not overwrite
the audit record before the new previous state is durable.

### Activation

For every resolved template-set row:

1. lock/read the live row and compute its checksum;
2. if no active `owned`/conflict record exists, store its exact content, checksum,
   dateline and context as `previous_*`;
3. store the ATF seed content/checksum/version, write the deterministic ATF content, then
   record the checksum actually installed and set `ownership_state=owned`;
4. re-read and verify the installed checksum; otherwise roll back and report failure.

If the row is already `owned`, activation is idempotent: it must not create a backup of
ATF output or replace `previous_*`. A conflict state also fails closed and requires an
operator decision.

### Upgrade: three-way decision

Compare **old ATF installed/seed**, **current stored template**, and **new ATF seed** per
template set:

* current checksum equals `atf_installed_checksum` (and normally the old seed checksum):
  safely write the new seed, then update seed/installed checksums and version;
* current checksum differs: classify `manual_override`, retain `previous_content`, old
  ATF seed and the current live bytes, and do not overwrite automatically;
* no trustworthy old installed checksum: ownership cannot be proved, so do not write.

This is a conservative three-way policy: no textual auto-merge is allowed in the first
migration. An operator may export/merge and explicitly adopt a result, which records its
checksum as the new installed value without changing `previous_*`.

### Deactivation and uninstall

Re-read the current row immediately before release:

* if its checksum equals `atf_installed_checksum`, restore `previous_content` and
  `previous_dateline`, verify `previous_checksum`, then mark `restored`;
* if it differs, do **not** write the row. Mark `restore_conflict`, preserve the current
  live version and the previous version in the ledger, and surface an ACP/operator action
  to export, keep-current, or explicitly restore-previous;
* missing/recreated rows, changed `sid`, and checksum/encoding mismatches are conflicts,
  never reasons to install a default seed.

Uninstall follows the same guarded release and must retain unresolved conflict records.
It must never drop the ledger while an `owned`, `manual_override`, or `restore_conflict`
record exists.

## Install-time patch migration register

These are future migrations, not permission to modify templates in this task. “Disable”
means the addon's install/update/uninstall patch routine becomes a no-op for an ATF-owned
row; it must still work normally when ATF is off.

| Legacy patch | Destination | Completion condition |
|---|---|---|
| Alerts welcome block (`{$af_aam_header_icon}`) | `header.user_navigation` | Provider renders the action; installer/uninstaller no longer edits the owned welcome block. |
| Alerts footer (`{$af_aam_modal}`) | `footer.modals` | Provider renders modal once; footer append/remove patch is disabled. |
| Alerts headerinclude (`{$af_aam_js}{$af_aam_css}`) | `header.assets` | Assets register once; headerinclude append/remove patch is disabled. |
| AccountSwitcher welcome block (`{$af_aas_header_button}`) | `header.user_navigation` | Provider renders button; marker/anchor patch is disabled. |
| FontAwesome headerinclude | `header.assets` | Asset provider replaces the marked block; headerinclude patch is disabled. |
| JSBandle headerinclude | `header.assets` | Bundle provider replaces the marked block; headerinclude patch is disabled. |
| PostCounter profile | `profile.post_counter` | `{$memprofile['advancedpostcounter']}` is provider input; profile/custom-field patch is disabled. |
| PostCounter postbit/classic | `post.post_counter` | `{$post['advancedpostcounter']}` is provider input; `$post['user_details']` patch is disabled. |
| PosterAvatar forum cells | `forum.lastposter_avatar` / `thread.lastposter_avatar` | Provider receives explicit last-poster context; cell regex and marker wrapping are disabled. |
| AdvancedThreadFields forum chips | `thread.meta_chips` | `{$thread['af_atf_forum_chips']}` renders through the slot; marker injection/patch is disabled. |
| AdvancedThreadFields showthread marker | `thread.atf_fields` | `{$af_atf_showthread_block}` renders through the slot; `<!--AF_ATF_SHOW-->` fallback is disabled. |

## Release gates and acceptance scenarios

Template ownership may ship only after automated fixtures cover separate template sets
and byte-for-byte assertions for all of the following:

1. **Stock MyBB:** capture → ATF activation → guarded deactivation restores the exact
   original content, checksum, `sid`, and dateline.
2. **APUI-owned:** enable APUI first → capture its live templates → enable ATF → disable
   ATF restores those exact APUI-era bytes; APUI never writes while ATF owns them.
3. **Manual pre-edit:** a user-edited template is captured and restored byte-for-byte,
   not replaced by a bundled seed.
4. **Manual edit while ATF is active:** upgrade and deactivation both refuse to overwrite,
   set the conflict state, and retain/export current plus previous/seed versions.
5. **Repeated activation:** does not turn ATF output into `previous_content`.
6. **Upgrade:** unchanged old ATF output advances automatically; changed output remains
   untouched and becomes `manual_override`.
7. **Multiple template sets/inheritance:** ownership and restoration are isolated by
   `(sid, name)` and never leak into master or another theme.
8. **Compatibility rendering:** member/profile, both post layouts, thread quick reply and
   moderator controls, alerts/account switcher, assets, post counter, avatars, forum
   chips, and the showthread field block each render exactly once.

The user-visible acceptance invariant is: after ATF is deactivated, both appearance and
stored template contents are identical to the state immediately before ATF activation,
unless a post-activation manual edit exists—in which case that edit is left untouched and
the conflict is explicit.
