# Adaptive Theme Framework — final UCP migration audit

Audit date: 2026-10-02. Target: MyBB 1.8.40 behavior already recorded in the
UCP architecture and private-messaging contracts. This is a Phase 1 correctness
audit; visual polish remains explicitly out of scope.

## Result

The migrated Overview, Profile, Preferences, Security, Social, Subscriptions,
Content, Messages, Advanced Alerts and Mentions (AAM), and Advanced Account
Switcher (AAS) surfaces satisfy the migration contract.

Two concrete regressions were found and corrected during the audit:

1. the Social/AAS rules were missing the `body.atf-active` ownership boundary;
2. PM compose retained core's table wrapper solely to carry `posticons`.
   ATF now removes only the shared template's table container tags after MyBB
   renders it, retaining its permission-filtered inputs, names, values, and
   plugin output. The shared `posticons` template remains non-owned.

No other presentation changes were made.

## Surface findings

| Surface | Presentation owner while ATF is on | Navigation and active-state result |
| --- | --- | --- |
| Overview | ATF `usercp*` seeds | `overview`; `do_notepad` resolves to the GET parent. |
| Profile | ATF profile/avatar/signature seeds | `profile` with registry children; `do_profile`, `do_avatar`, and `do_editsig` resolve to their GET parents. |
| Preferences | ATF options/theme-selector seeds | `preferences.general`; `do_options` resolves to `options`. |
| Security | ATF password/email/username seeds | Registry children preserve MyBB visibility; each `do_*` action resolves to its GET parent. |
| Social | ATF edit-list/group seeds plus provider-owned AAM/AAS ATF templates | Buddy/group permissions stay in MyBB/addon handlers; navigation is registry-only. |
| Subscriptions | ATF thread/forum subscription seeds | Context resolver distinguishes thread and forum POST/confirmation routes and selects the GET parent. |
| Content | ATF draft/attachment seeds | `do_drafts` and `do_attachments` select their GET parents; attachments retain the core setting gate. |
| Messages | ATF interactive `private_*` seeds | One global Messages registry item plus one PM-local folder/management navigation; no duplicated UCP navigation. |
| AAM | AAM-owned `*_atf` page templates | Provider registers list/preferences under Social; POST redirects to the corresponding GET page. |
| AAS | AAS-owned `*_atf` page templates | Provider registers Accounts under Social; all page POST operations retain `action=af_aas` and redirect to that GET parent. |

All migrated page shells consume `atf_ucp_global_navigation` and, where
applicable, `atf_ucp_local_navigation`; none consumes `usercpnav` or evaluates
`usercp_nav`. Legacy AAM/AAS `usercp_menu` hooks remain only for ATF-off fallback
and their ATF render branches never call `usercp_menu()`.

The registry accepts language-key labels only. ATF provides Russian and English
values for its keys; AAM and AAS provide their own Russian/English keys. Core
labels are reused only through MyBB language keys. Visibility callbacks preserve
`canusercp`, `canchangeavatar`, signature thresholds, `enableattachments`, PM
settings/group permissions, and addon-specific enabled/access checks.

## Owned templates

Ownership means a reversible per-template-set lease in
`af_atf_template_ownership`; it does **not** mean the master `sid=-2` row is
modified.

### Site/profile owners

- `index`
- `forumbit_depth2_forum`
- `forumbit_depth2_forum_lastpost`
- `forumdisplay_thread`
- `member_profile`
- `showthread`
- `postbit_classic`

### UCP owners

- Overview: `usercp`, `usercp_currentavatar`, `usercp_notepad`,
  `usercp_latest_threads`, `usercp_latest_threads_threads`,
  `usercp_latest_subscribed`, `usercp_latest_subscribed_threads`,
  `usercp_warnings`, `usercp_warnings_warning`,
  `usercp_warnings_warning_post`.
- Profile: `usercp_profile`, `usercp_profile_away`,
  `usercp_profile_website`, `usercp_profile_customtitle`,
  `usercp_profile_customtitle_currentcustom`,
  `usercp_profile_customtitle_reverttitle`,
  `usercp_profile_profilefields`, `usercp_avatar`,
  `usercp_avatar_current`, `usercp_avatar_upload`,
  `usercp_avatar_remote`, `usercp_avatar_remove`,
  `usercp_avatar_auto_resize_auto`, `usercp_avatar_auto_resize_user`,
  `usercp_editsig`, `usercp_editsig_current`,
  `usercp_editsig_preview`, `usercp_editsig_suspended`.
- Preferences: `usercp_options`, `usercp_options_invisible`,
  `usercp_options_date_format`, `usercp_options_language`,
  `usercp_options_language_option`, `usercp_options_pms`,
  `usercp_options_pms_from_buddys`, `usercp_options_pppselect`,
  `usercp_options_pppselect_option`, `usercp_options_quick_reply`,
  `usercp_options_style`, `usercp_options_time_format`,
  `usercp_options_timezone`, `usercp_options_timezone_option`,
  `usercp_options_tppselect`, `usercp_options_tppselect_option`,
  `usercp_themeselector`, `usercp_themeselector_option`.
- Security: `usercp_password`, `usercp_email`, `usercp_changename`.
- Social: `usercp_editlists`, `usercp_editlists_user`,
  `usercp_editlists_no_buddies`, `usercp_editlists_no_ignored`,
  `usercp_editlists_received_requests`,
  `usercp_editlists_received_request`, `usercp_editlists_sent_requests`,
  `usercp_editlists_sent_request`, `usercp_editlists_no_requests`, and the
  complete `usercp_usergroups*` family present in the seed map.
- Subscriptions: `usercp_subscriptions`, `usercp_subscriptions_thread`,
  `usercp_subscriptions_thread_icon`, `usercp_subscriptions_remove`,
  `usercp_subscriptions_none`, `usercp_forumsubscriptions`,
  `usercp_forumsubscriptions_forum`, `usercp_forumsubscriptions_none`,
  `usercp_addsubscription_thread`, `usercp_removesubscription_thread`,
  `usercp_removesubscription_forum`.
- Content: `usercp_drafts`, `usercp_drafts_draft`,
  `usercp_drafts_draft_forum`, `usercp_drafts_draft_thread`,
  `usercp_drafts_none`, `usercp_attachments`,
  `usercp_attachments_attachment`, `usercp_attachments_none`,
  `delete_attachments_button`.

### Private-message owners

ATF owns the interactive seed map from `private` through
`private_archive_folders_folder`: inbox/message rows, multiple recipients,
folder jump/move/order/quota/action fragments, search/results/read/quick reply,
send/autocomplete/buddy select/tracking, tracking lists, folder management,
empty-folders, and the interactive archive form/folder selectors. The exact
names are the `private*` entries returned by
`af_adaptivethemeframework_template_seeds()`.

## Non-owned templates and preserved integrations

- `usercp_nav*` and `usercp_menu`: never acquired or rendered by an ATF surface.
- `posticons*`, `codebuttons`, `smilieinserter`, `multipage*`, and shared
  autocomplete templates: MyBB/addons retain ownership. Their already-rendered
  controls are transported without duplicating editor/autocomplete setup.
- PM download renderers (`private_archive_html*`, `private_archive_txt*`,
  `private_archive_csv*`) remain MyBB-owned and do not receive the interactive
  shell.
- AdvancedProfileFields templates remain provider-owned. ATF keeps
  `{$customfields}`/profile-field output opaque, so its field permissions,
  required state, value processing, and editor widgets are unchanged.
- AAM's `af_aam_*_atf` templates and AAS's `af_aas_*_atf` templates remain
  addon-owned. ATF supplies navigation/context but does not lease or copy them.
- Header/footer, editor, autocomplete, AAM/AAS modal, and legacy ATF-off addon
  templates retain their existing owners.

No owned seed contains a MyBB layout table or `usercp_nav` reference. PM
posticons are normalized at the boundary because the shared non-owned template
historically returns row/cell wrappers. AAM and AAS select their card/form ATF
variants, so their legacy table templates cannot appear in the ATF branch.

## Addon providers

- **AAM:** `social.alerts` parent with `social.alerts.list` and
  `social.alerts.preferences` children. Visibility requires enabled AAM,
  authenticated user, and `canusercp`; its unread badge remains AAM-owned.
- **AAS:** `social.accounts`. Visibility requires enabled AAS, authenticated
  user, `canusercp`, and `af_aas_user_allowed()`.
- Providers register immediately when the registry is available or enqueue one
  typed definition for ATF to consume after seeding. Duplicate keys fail closed;
  disabled/missing owners are filtered before render.

## Lifecycle and restore

Activation reads the resolved live value for every `(template_sid,
template_name)`, stores one immutable `previous_content` plus checksum, writes
the deterministic seed, and verifies the installed checksum. Repeated ON does
not overwrite the original backup. Deactivation restores the exact stored bytes
and original dateline, or deletes the override if the theme originally inherited
the master row. Restore is checksum-guarded and fails closed on a live edit.

Therefore `OFF → ON → OFF → ON` reuses the same single lease without ownership
conflict or a backup-of-backup chain. A restored row can be reacquired only when
it matches the recorded previous value. The ownership table is the only backup
store; template names are never suffixed or recursively copied.

AAM/AAS provider definitions are request-local. They are rebuilt on enabled
addon initialization, filtered by owner state, and do not persist stale registry
entries across disable/enable cycles. Their legacy hooks/templates remain
available after ATF is turned off.

Adaptive Responsive Layout (`advresponsivelayout`) is deprecated and is neither
a manifest dependency nor a runtime/template dependency. No compatibility
selector, DOM mutator, or template from that addon is used by UCP/PM output.

## Responsive and duplication audit

UCP/PM shells and cards use `min-width: 0`, bounded controls, wrapping action
rows, responsive grids, and horizontal containment only for intrinsically wide
opaque editor/signature content. At the 48rem and 30rem breakpoints, summary,
form, message, subscription, draft, attachment, and tracking layouts collapse
without document-level overflow. Registry navigation is the single intentional
horizontal scroller.

Each surface emits one page shell, one global registry navigation, at most one
local navigation for the active domain, and one native content instance. PM
local navigation contains only compose, permitted folders, and PM management;
it does not repeat global UCP destinations.

## Remaining Phase 2 visual TODO

These are polish items, not correctness blockers:

- refine spacing/typography hierarchy across unusually long translated labels;
- replace semantic icon tokens with the final icon set;
- tune empty-state illustration and card density;
- refine editor toolbar and post-icon appearance without taking ownership of
  shared editor templates;
- perform browser/device visual snapshots for custom themes and very long
  usernames/folder names.
