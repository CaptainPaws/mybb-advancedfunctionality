# ATF private messaging migration contract

## Scope and evidence

This contract began as the preparation audit for PM ownership and now records
the completed Phase 1 migration and final Task 26 audit. It was checked against
the MyBB `mybb_1840` tag (resolved commit
`bd2a3447939d3084a5926dd66ece04649e0e0d60`), specifically `private.php` and
`install/resources/mybb_theme.xml`, and against the complete AF repository.
The inventory below is therefore the MyBB 1.8.40 inventory, rather than a list
inferred from template names. `private_messagebit_sep` is shipped in the theme
XML but is not requested by 1.8.40 `private.php`; it remains deliberately
unowned.

ATF does not change `private.php`, permissions, or either editor. Phase 1 owns
the 44 interactive `private_*` templates listed in the final audit below
through the generic reversible ledger. The eight archive download renderers
and dormant separator remain untouched. ATF exposes a data-only workspace
contract on `private.php`: the generic `pm.navigation`, `pm.quota`,
`pm.notice`, `pm.pagination`, `pm.actions`, `pm.content`,
`pm.before_content`, and `pm.after_content` slots. With ATF off the ledger
restores exact previous bytes; with ATF on the interactive PM surfaces use the
ATF seeds while native MyBB continues to own decisions, validation, actions,
tokens, parsing, and export delivery.

## Verified template graph

### Folder list (`action` absent, or a folder id)

| Template | Live children / composed values | Variables and controls that form the migration boundary |
| --- | --- | --- |
| `private` | `private_messagebit`, `private_nomessages`, `private_jump_folders`, `private_move`, `private_orderarrow`, `private_pmspace`, `private_composelink`, `private_emptyexportlink`, `private_limitwarning`; common `usercp_nav` and `multipage` families | `{$fid}`, `{$foldername}`, `{$sender}`, `{$messagelist}`, `{$multipage}`, `{$folderjump}`, `{$folderoplist}`, `{$orderarrow[...]}`, `{$pmspacebar}`, `{$composelink}`, `{$emptyexportlink}`, `{$limitwarning}`, `{$mybb->post_code}`. Preserve `action=do_stuff`, `check[pmid]`, select-all, move/read/unread/delete actions, sort links and search form. |
| `private_messagebit` | `private_messagebit_icon`, optional `private_messagebit_denyreceipt`, and the multiple-recipient family | `{$message['pmid']}`, `{$message['subject']}`, `{$msgstatus}`, `{$msgalt}`, `{$icon}`, `{$tofromusername}`, `{$senddate}`, `{$denyreceipt}`, `{$bgcolor}`. Preserve the `new_pm`/`old_pm`/replied/forwarded state class and image, read URL and checkbox name/value. |
| `private_multiple_recipients` | `private_multiple_recipients_user`, optional `private_multiple_recipients_bcc` | `{$message['pmid']}`, `{$to_users}`, `{$bcc_users}`; preserve `id="private_message_<pmid>"` because MyBB's popup is bound to it, plus profile links/usernames. |
| `private_jump_folders` / `private_jump_folders_folder` | Folder options are reused by list jump, move, search and archive builders | `{$folder_id}`, `{$folder_name}`, `{$sel}`; preserve folder ids and selected state. Core folders are inbox `1`, sent `2`, drafts `3`, trash `4`, followed by stored custom folders. |
| `private_move` | Uses the folder-option stream | `{$from_fid}`, `{$folderoplist_folder}`; preserve `fromfid` and `fid` fields. |
| `private_orderarrow` | None | `{$fid}`, `{$sortby}`, `{$oppsortnext}`, `{$oppsort}`. |
| `private_pmspace` | None | `{$spaceused}`, `{$spaceused2}`, `{$overhalf}`, `{$belowhalf}`, `{$spaceused_severity}`; this is the quota/storage indicator and severity state. |
| Small conditional children | `private_composelink`, `private_emptyexportlink`, `private_limitwarning`, `private_nomessages` | Keep their permission/quota-gated rendered HTML intact; ATF must not reproduce the underlying permission decisions. |

`private_messagebit_sep` also exists in the stock XML (an empty separator row),
but stock 1.8.40 `private.php` neither preloads nor evaluates it.  It is a
shipped, dormant compatibility template, not a live list dependency.

### Read (`action=read`)

| Template | Live children / composed values | Variables and controls that form the migration boundary |
| --- | --- | --- |
| `private_read` | `private_read_action`, `private_read_to`, optional `private_read_bcc`, the core `postbit`/`postbit_classic` tree returned by `build_postbit($pm, 2)`, and optional `private_quickreply` | `{$pm['subject']}`, `{$action_time}`, `{$message}`, `{$quickreply}`, `{$usercpnav}`. Preserve `#posts_container` and `#posts`: read PMs intentionally share the postbit runtime and addon asset surface. |
| `private_read_action` | None | `{$actioned_on}` for replied/forwarded time. |
| `private_read_to` / `private_read_bcc` | None | `{$to_recipients}`, `{$bcc_recipients}`, `{$bcc}`; BCC visibility remains a PHP permission/participant decision. |
| `private_quickreply` | optional `private_send_tracking`; core editor initialization remains external | `{$pmid}`, `{$to}`, `{$bcc_form_val}`, `{$subject}`, `{$quoted_message}`, `{$private_send_tracking}`, option checkbox states, collapse state, `{$mybb->post_code}`. Preserve form action `private.php`, `action=do_send`, `replyto`, textarea `#message`, send/preview submits, signature/smilies/save-copy/read-receipt options. |

The built postbit supplies the native PM action controls through
`postbit_reply_pm`, `postbit_replyall_pm`, `postbit_forward_pm`, and
`postbit_delete_pm` (plus the ordinary author/date/message postbit children).
Reply, reply-all, forward and delete therefore belong to the read/postbit
contract even though their markup is not in a `private_*` template.

### Compose (`action=send`, including reply/forward/draft modes)

| Template | Live children / composed values | Variables and controls that form the migration boundary |
| --- | --- | --- |
| `private_send` | `private_send_autocomplete`, zero to two `private_send_buddyselect` fragments, optional `private_send_tracking`; core `posticons`, `codebuttons`, `smilieinserter`, `previewpost` | `{$to}`, `{$bcc}`, `{$subject}`, `{$message}`, `{$pmid}`, `{$do}`, `{$max_recipients}`, `{$send_errors}`, `{$preview}`, `{$posticons}`, `{$codebuttons}`, `{$smilieinserter}`, `{$autocompletejs}`, buddy selectors, option states, `{$mybb->post_code}`. Preserve `action=do_send`, recipient inputs `to`/`bcc`, subject, textarea `#message`, draft/send/preview submits, and all option names. |
| `private_send_autocomplete` | Select2 asset/config block | `{$mybb->usergroup['maxpmrecipients']}`, `{$lang->search_user}`, asset URL; preserve `#to` and `#bcc` initialization and the `xmlhttp.php?action=get_users` transport. |
| `private_send_buddyselect` | MyBB `UserCP.openBuddySelect()` bridge | `{$buddy_select}` (`to` or `bcc`) and its JavaScript call. |
| `private_send_tracking` | None | `options[readreceipt]` and `{$optionschecked['readreceipt']}`. |

There is no native PM attachment upload control in these MyBB 1.8.40
templates or in the `private.php` send pipeline.  `usercp_nav_attachments` is a
User CP navigation entry, not a PM attachment feature.  A future plugin-provided
attachment fragment may be transported only after a separate provider and
permission contract is audited; ATF must not invent one.

### Tracking, folders, empty and archive

| Surface | Templates present and actually used | Critical contract |
| --- | --- | --- |
| Tracking | `private_tracking`, `private_tracking_readmessage`, `private_tracking_unreadmessage`, `private_tracking_nomessage`, `private_tracking_readmessage_stop`, `private_tracking_unreadmessage_stop` | Keep separate read/unread pagination (`{$read_multipage}`, `{$unread_multipage}`), pmid checkboxes (`readcheck[]`, `unreadcheck[]`), recipient profile link, subject, read/sent dates, CSRF key, stop selected/all, delete/cancel receipt. |
| Folder management | `private_folders`, `private_folders_folder`, `private_folders_folder_unremovable` | Keep `fid[]` inputs, `newfolder[]`, the immovable core-folder indication, `action=do_folders`, and CSRF key. Never derive removable status in presentation. |
| Empty folders | `private_empty`, `private_empty_folder` | Keep per-folder `empty[<fid>]`, counts/names, `keepunread`, `action=do_empty`, and CSRF key. |
| Archive form | `private_archive`, `private_archive_folders`, `private_archive_folders_folder` | Keep multi-folder `exportfolders[]`, format, date limit/direction, unread and post-export delete settings, `action=do_export`, and CSRF key. |
| Archive downloads | `private_archive_html`, `_html_folderhead`, `_html_message`; `private_archive_txt`, `_txt_folderhead`, `_txt_message`; `private_archive_csv`, `_csv_message` | These are non-shell download renderers selected dynamically as `private_archive_<exporttype>...`. Preserve encoding/escaping, folder boundaries, from/to, subject/message/date and download headers. They should not receive the interactive ATF page shell. |
| Advanced search | `private_advanced_search`, `private_advanced_search_folders`; results use `private_search_results`, `private_search_messagebit`, `private_search_results_nomessages` plus list jump/move and pagination | Keep query fields, folder/status/sort/direction selectors, result snippets and folder column, pmid selection/mass actions, pagination, CSRF key and autocomplete. Search/results are real 1.8.40 PM surfaces even though they were not in the initial eight-name prompt. |

### Complete stock `private_*` inventory

All 53 templates present in the MyBB 1.8.40 theme XML are:

1. `private`
2. `private_advanced_search`
3. `private_advanced_search_folders`
4. `private_archive`
5. `private_archive_csv`
6. `private_archive_csv_message`
7. `private_archive_folders`
8. `private_archive_folders_folder`
9. `private_archive_html`
10. `private_archive_html_folderhead`
11. `private_archive_html_message`
12. `private_archive_txt`
13. `private_archive_txt_folderhead`
14. `private_archive_txt_message`
15. `private_composelink`
16. `private_empty`
17. `private_empty_folder`
18. `private_emptyexportlink`
19. `private_folders`
20. `private_folders_folder`
21. `private_folders_folder_unremovable`
22. `private_jump_folders`
23. `private_jump_folders_folder`
24. `private_limitwarning`
25. `private_messagebit`
26. `private_messagebit_denyreceipt`
27. `private_messagebit_icon`
28. `private_messagebit_sep` (shipped but dormant in stock `private.php`)
29. `private_move`
30. `private_multiple_recipients`
31. `private_multiple_recipients_bcc`
32. `private_multiple_recipients_user`
33. `private_nomessages`
34. `private_orderarrow`
35. `private_pmspace`
36. `private_quickreply`
37. `private_read`
38. `private_read_action`
39. `private_read_bcc`
40. `private_read_to`
41. `private_search_messagebit`
42. `private_search_results`
43. `private_search_results_nomessages`
44. `private_send`
45. `private_send_autocomplete`
46. `private_send_buddyselect`
47. `private_send_tracking`
48. `private_tracking`
49. `private_tracking_nomessage`
50. `private_tracking_readmessage`
51. `private_tracking_readmessage_stop`
52. `private_tracking_unreadmessage`
53. `private_tracking_unreadmessage_stop`

Thus the verified count is 53 (52 evaluated or dynamically addressable by
stock `private.php`, plus the dormant separator), not 43.  The related non-
`private_*` families that a migration must retain are `usercp_nav*`,
`multipage*`, `postbit*`, `posticons*`, `codebuttons`, `smilieinserter` output,
and `previewpost`.

## AF dependency audit

| Addon | Hook/data dependency | DOM/template dependency | Migration consequence |
| --- | --- | --- | --- |
| Advanced Responsive Layout (deprecated) | `pre_output_page` adds `af-rwd-private` and `af-rwd-script-private` and loads its runtime | CSS targets all tables plus `.pm_table`, `#pmdata`, `#usercp_menu`, `#pm_menu`, `#usercp_content`, `#pm_content`, `.pm_search`, `.pm-controls`, `.pm_folders`; JS marks `body.af-rwd-script-private table.pm_table` with `.af-rwd-mobile-cards`. | **Not target compatibility.** Do not copy selectors, classes, JS mutation, or table structure. Its intent was only stacked navigation/content, card-like mobile message rows, compact secondary columns, wrapping search/action/folder controls, and fluid inputs. ATF should recreate those outcomes with primitives. |
| AdvancedJSBundle | Route gate recognizes only `private.php?action=read&pmid>0`; manifest attaches postbit Font Awesome and quote-avatar CSS/JS there. | `postbit-fa-icons.js` expects `.post_date` and permalink links under `.float_right`; `quote-avatars.js` searches quote blockquotes/cites, member profile links and nearest `.post`/postbit/table ancestry. | Migrate `private_read` with the postbit contract, or update these generic scripts before changing that subtree. Keep quote markup and postbit anchors during transition. |
| Advanced Account Switcher | `private_send_end` reads `$pmhandler->pm_insert_data['toid']` and notifies the linked master through AAM. | None. | Preserve hook timing and native send pipeline; no markup shim or template variable required. |
| Advanced Alerts and Mentions | PM alert creation resolves per-recipient `pmid`; formatter links to `private.php?action=read&pmid=...` (or inbox fallback). | No PM template selectors found. Mention JS has generic editor/quick-reply selectors, but its manifest does not attach mention assets to `private.php`. | Preserve canonical list/read URLs and pmid semantics; no PM DOM compatibility shim is justified. |
| Smart URL Titles | `private_send_start`, `private_send_do_send` transform `$message`; `private_end` prepares preview output. | No PM selector and no core PM template patch was found. | Keep hooks and `{$message}`/`{$preview}` flow; do not bypass native compose processing. |
| AdvancedMenu | Registers the PM destination and may remove legacy menu `<li>` entries whose URL contains `private.php`. | Header/user-drawer navigation only; no PM content DOM dependency. | Keep `private.php` canonical. AdvancedMenu remains navigation owner, not PM shell owner. |
| AdvancedBuddyList | Emits `private.php?action=send&uid=<id>` links. | None on the destination. | Preserve compose URL prefill. |
| FakeOnline | Maps `private.php` to the activity label `private`. | None. | Preserve route identity. |
| AdvancedEditor | Global parse hooks affect rendered BBCode, while stock MyBB supplies the compose/quick-reply editor. No private route hook or PM DOM selector exists in this addon. | Its CSS mentions `#quickreply_e` globally, but the addon does not own PM editor creation. | Preserve `#message`, `#quickreply_e`, `{$codebuttons}`, parser output and core initialization. Do not replace/editor-wrap behavior in this migration. |
| AdvancedProfileUI / AdvancedAppearance | Repository-wide audit found no `private.php`, `private_*`, pmid, or PM table dependency. Appearance may still style global/postbit surfaces. | No direct PM dependency. | No PM-specific provider or compatibility shim. Read view still inherits the separately documented postbit/Appearance contract. |

No AF addon currently patches a `private_*` template.  The only PM
`pre_output_page` transformation found is deprecated Responsive Layout's global
class/asset injection.  AdvancedJSBundle also performs global asset
deduplication, but its PM-specific decision is route-based rather than a
template rewrite.

## Native functionality contract

Future ownership is acceptable only if all of the following survive as native,
permission-checked behavior rather than being reimplemented by presentation:

- complete folder navigation (inbox, sent, drafts, trash and custom folders),
  selected folder, folder management and jump controls;
- independent list/search/tracking pagination and sort/order state;
- per-message selection, select-all, mass move, mark read/unread and delete;
- status (new/read/replied/forwarded), icon, subject, sender versus recipient,
  multiple To/BCC display under its existing visibility rules, and date;
- read body/postbit, action time, reply, reply-all, forward and delete;
- compose/reply/forward/draft prefill, validation errors, preview, post icons,
  signature/smilies/save-copy/read-receipt controls and CSRF token;
- MyBB editor, smilie inserter, recipient Select2 autocomplete, maximum-recipient
  behavior and buddy selection;
- sent-message tracking, its read/unread partitions, stop-tracking and receipt
  deletion/cancellation;
- quota bar, warning/limit state and conditional compose/empty/export links;
- empty and archive filters/actions, and byte-correct TXT/CSV/HTML downloads;
- no attachment claim while core 1.8.40 provides none; plugin attachments, if
  later installed, require their own audited slot and permission contract.

## Design-system mapping and shared shells

No PM-specific design system is needed:

| PM need | Existing ATF primitive |
| --- | --- |
| Overall interactive PM page and User CP/content columns | `.atf-page`, `.atf-grid` |
| Page heading, quota/warning, search, pagination/action regions | `.atf-section`, `.atf-stack` |
| Message rows, read body, tracking rows and form groups | `.atf-card` |
| Folder navigation and read/unread tracking switch | `.atf-tabs` (links/forms retain native URLs and fields) |
| Sender/recipient, state, folder and dates | `.atf-meta` |
| Compose, mass action, reply/forward/delete and submit controls | `.atf-button` |
| Recipient/subject/editor/options, folder/archive/search fields | `.atf-form-row` |

One **PM workspace shell** can cover list, advanced-search results, tracking,
folder management, empty and archive-form surfaces: page heading + folder
navigation + quota/meta + a content/action section.  Compose and read can share
the same outer workspace/navigation/quota shell, but need distinct inner
layouts (`form` versus postbit/conversation).  Advanced-search criteria can
share the form inner layout.  Archive download templates must remain standalone
documents, not workspace-shell variants.

## Ownership order

The migration was completed in this order:

1. **Extract and test a data-only PM workspace contract first**: rendered
   `usercpnav`, folder options/navigation, quota/warnings, pagination, canonical
   URLs and native token-bearing action fragments. Do not acquire a template.
2. **Acquired in Task 19: `private` together with `private_messagebit` and its
   directly conditional list children.** They are inseparable: the parent defines mass
   actions/selection while the row carries pmid/status. Include jump/move,
   multiple-recipient, order, empty-state and quota fragments in the same
   ownership release so rollback is exact.
3. **Acquire advanced-search/results** using the same list-row/action shell.
   This validates reuse before migrating unrelated forms.
4. **Acquire `private_read` and its read children**, only after reconciling the
   existing postbit ATF owner and AdvancedJSBundle selectors. Keep postbit as a
   rendered native/owned boundary and migrate `private_quickreply` with it.
5. **Acquire `private_send` and its autocomplete/buddy/tracking children.** Do
   this after read/quick reply establishes the editor boundary; never own or
   recreate editor/Select2 behavior in the shell.
6. **Acquire `private_tracking` and row/footer children** using the workspace
   shell while preserving two independent forms and pagers.
7. **Acquire folder management and empty-folder surfaces**, then the archive
   form. These reuse the form and workspace primitives but have destructive
   token-bearing actions.
8. **Audit archive download templates separately and last.** The audit found no
   ownership need; they remain native format-specific documents outside the
   interactive shell.

Each completed acquisition uses ATF's per-template-set ownership ledger and
restores the exact pre-ATF bytes on release. No migration stage added a
compatibility replacement for Advanced Responsive Layout.

## Task 26 final Phase 1 audit (2026-10-02)

### Final ownership inventory

The repository ownership map contains **44 PM templates**, grouped by the
surface that evaluates them:

- **Workspace/list (16):** `private`, `private_messagebit`,
  `private_messagebit_icon`, `private_messagebit_denyreceipt`,
  `private_multiple_recipients`, `private_multiple_recipients_user`,
  `private_multiple_recipients_bcc`, `private_jump_folders`,
  `private_jump_folders_folder`, `private_move`, `private_orderarrow`,
  `private_pmspace`, `private_composelink`, `private_emptyexportlink`,
  `private_limitwarning`, and `private_nomessages`.
- **Search (5):** `private_advanced_search`,
  `private_advanced_search_folders`, `private_search_results`,
  `private_search_messagebit`, and `private_search_results_nomessages`.
- **Read (5):** `private_read`, `private_read_action`, `private_read_to`,
  `private_read_bcc`, and `private_quickreply`.
- **Send (4):** `private_send`, `private_send_autocomplete`,
  `private_send_buddyselect`, and `private_send_tracking`.
- **Tracking (6):** `private_tracking`, `private_tracking_nomessage`,
  `private_tracking_readmessage`, `private_tracking_unreadmessage`,
  `private_tracking_readmessage_stop`, and
  `private_tracking_unreadmessage_stop`.
- **Folders/empty (5):** `private_folders`, `private_folders_folder`,
  `private_folders_folder_unremovable`, `private_empty`, and
  `private_empty_folder`.
- **Archive form (3):** `private_archive`, `private_archive_folders`, and
  `private_archive_folders_folder`.

Every name has one non-empty seed file and is acquired for every real theme
set (`sid > 0`); the master (`sid = -2`) is evidence/fallback and is never
written. The unique `(template_sid, template_name)` index rules out duplicate
leases. A fresh lease records the exact resolved pre-ATF content, its checksum,
whether it was inherited, the installed seed and checksum, and the resulting
template id. Reacquisition updates seed metadata but never replaces
`previous_content`, `previous_checksum`, `previous_exists`, or
`previous_dateline`, so it cannot form a backup chain.

### Explicit non-ownership and export boundary

The following remain non-owned: `private_archive_html`,
`private_archive_html_folderhead`, `private_archive_html_message`,
`private_archive_txt`, `private_archive_txt_folderhead`,
`private_archive_txt_message`, `private_archive_csv`, and
`private_archive_csv_message`. They are dynamically selected standalone export
renderers. No matching ATF seed exists, so acquisition cannot add the page
shell, header/footer, AdvancedMenu, or an `atf-active` body. MyBB retains the
format-specific download headers, charset/encoding, escaping, and delivery.
The archive **form** is interactive and owned; the generated download is not.

`private_messagebit_sep` also remains non-owned: it is present in the stock
XML but neither preloaded nor evaluated by stock MyBB 1.8.40 `private.php`.
Adding a lease would have no runtime justification.

### Ownership lifecycle result

The full fixture now enumerates the production ownership map rather than a
historical fixed count. Its OFF → ON → OFF → ON run establishes:

1. OFF resolves the exact custom override or inherited master bytes for every
   owned name and records those baseline values.
2. ON creates exactly one lease per `(sid, name)`, installs the exact seed, and
   verifies the live SHA-256 before marking it `owned`.
3. OFF restores an existing override byte-for-byte (including its dateline) or
   deletes the ATF override when the theme previously inherited the master.
4. The second ON reuses the same lease and original backup; it does not create
   duplicate rows or a backup chain.

The conflict paths remain fail-closed: unattributed edits become
`manual_override`, changed owned bytes become `restore_conflict`, and failed
post-write verification becomes `write_failed`. A previous version restored
by another addon is safe to reacquire because its checksum equals the immutable
`previous_checksum`; an arbitrary between-cycle edit is intentionally not
silently normalized. The only compatibility normalizer relevant to the shared
ATF ownership system is AdvancedPosterAvatar's narrowly bounded legacy-marker
cleanup for its historical forum templates. It does not target PM templates.
AdvancedProfileUI checks the ATF seed map before writing shared profile/postbit
surfaces and has no `private_*` writer, so no PM provider or normalizer is
needed.

### Foreign-writer and legacy-layout audit

Repository-wide inspection found no AF install, activate, deactivate,
`find_replace_templatesets`, direct template-table update, backup/restore
routine, or compatibility normalizer that writes a `private_*` template.
PM-related hooks in Advanced Account Switcher and Smart URL Titles operate on
native data at core hook points; AdvancedMenu, AdvancedBuddyList, alerts, and
FakeOnline only retain canonical `private.php` links/route identity.

Advanced Responsive Layout remains deprecated and is not a compatibility
target. None of the 44 ATF PM seeds contains `.pm_table`,
`af-rwd-mobile-cards`, `af-rwd-script-private`, or its table/DOM-moving
contract. Those selectors remain confined to that deprecated addon's own
assets and are not required by the migrated pages.

### Workspace, native behavior, and integration result

PM providers register only when `THIS_SCRIPT === 'private.php'`. Navigation,
quota, warning, pagination, and action providers transport already-rendered
native fragments; empty `pm.content`, `pm.before_content`, or
`pm.after_content` providers render as empty strings without changing the
layout. Parent seeds place each slot once and do not also print the transported
legacy variable. Folder visibility, quota and compose permissions, BCC
visibility, validation, mass actions, tracking decisions, archive options,
and CSRF checks remain in core `private.php`.

The seed audit preserves the native field/action contract for list and search
(`check[pmid]`, `action=do_stuff`, sorting, move/read/unread/delete), tracking
(`readcheck[pmid]`, `unreadcheck[pmid]`, independent pagination and stop/cancel
submits), folders (`fid[]`, `newfolder[]`), emptying (`empty[fid]`,
`keepunread`), archive (`exportfolders[]` and export options), compose/quick
reply (`#to`, `#bcc`, `#message`, `#quickreply_e`, editor fragments and
`my_post_key`), and all hidden mode identifiers. Forms are owned by their
parent templates; row/card children do not introduce nested forms or orphan
submit controls.

`private_read` evaluates the one native `build_postbit($pm, 2)` result. The ATF
postbit provider passes through PM-specific reply, reply-all, forward, and
delete buttons in the same single action slot. The read seed keeps
`#posts_container`, `#posts`, `.post_date`, native permalink content, and the
normal postbit subtree, so AdvancedJSBundle's generic permalink/quote-avatar
runtime does not require a PM shim or showthread-only context. Compose retains
the stock Select2 transport and maximum-recipient calculation, buddy selector,
and core editor initialization.

No PM attachments or other non-core feature was introduced. Static HTML/CSS
inspection found no legacy row fragment in a non-table parent, duplicate parent
form, or PM seed dependency on the old responsive addon. Existing ATF PM rules
provide bounded controls/editors, fluid Select2 containers, wrapping action
rows, stackable cards, and breakable subject/identity text as the Phase 1
responsive baseline.

### Phase 2 boundary

Phase 1 has no known functional PM regression. Visual density, typography,
spacing, and other cosmetic refinements remain Phase 2 work; they must not be
folded into this audit. The next migration block is `memberlist/userlist`.
