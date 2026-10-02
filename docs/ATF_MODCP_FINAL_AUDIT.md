# ATF ModCP final audit (Task 51)

Target: warprift.ru — MyBB 1.8.40, PHP 8.5.0  
Scope: Tasks 44–50, Adaptive Theme Framework ModCP Phase 1.

## 0. Result

**Phase 1 status: complete after one audit regression fix.**

The audit found one presentation/navigation regression introduced by the Task 44
registry renderer: when an unknown/plugin `modcp.php?action=...` had no active
registry item, the local-navigation renderer treated the empty active parent as
the root parent and rendered the complete Level-1 menu a second time. Task 51
fixes this by returning an empty local navigation when there is no recognized
current item. Unknown actions now keep the safe global ModCP navigation and do not
invent a local active state.

No query, permission, mutation, storage, or MyBB core code is changed by Task 51.

## 1. Full-page roots

All 18 roots identified by the Task 43 audit are present in
`af_adaptivethemeframework_template_seeds()`, use the common
`pun atf-page-shell atf-page atf-modcp` shell, render the ATF global/local
navigation slots, and do not render the legacy `{$modcp_nav}`.

| Root | Phase-1 presentation |
|---|---|
| `modcp` | Overview cards/panels |
| `modcp_reports` | Open-report cards and native inline-report controls |
| `modcp_reports_allreports` | Report-history cards |
| `modcp_modlogs` | Moderator-log cards + native filter contract |
| `modcp_announcements` | Global/forum announcement cards |
| `modcp_announcements_new` | Native create contract in ATF form layout |
| `modcp_announcements_edit` | Native edit contract in ATF form layout |
| `modcp_announcements_delete` | Native POST confirmation in ATF layout |
| `modcp_modqueue_threads` | Thread queue cards |
| `modcp_modqueue_posts` | Post queue cards |
| `modcp_modqueue_attachments` | Attachment queue cards |
| `modcp_modqueue_empty` | ATF empty state |
| `modcp_finduser` | User search/results |
| `modcp_editprofile` | Moderator edit-profile form |
| `modcp_warninglogs` | Warning-log cards/filter |
| `modcp_ipsearch` | IP search/results |
| `modcp_banning` | Ban cards |
| `modcp_banuser` | Add/edit-ban form |

`iplookup` remains a contextual response and is not treated as a nineteenth
full-document root.

## 2. Owned ModCP child templates

The current ownership map contains 68 ModCP templates total: the 18 roots above
plus the following child presentation templates.

### Overview / logs / report history

- `modcp_awaitingmoderation`
- `modcp_awaitingthreads`
- `modcp_awaitingposts`
- `modcp_awaitingattachments`
- `modcp_awaitingmoderation_none`
- `modcp_latestfivemodactions`
- `modcp_modlogs_result`
- `modcp_modlogs_noresults`
- `modcp_modlogs_nologs`
- `modcp_modlogs_multipage`
- `modcp_reports_allreport`
- `modcp_reports_allnoreports`

### Open reports / queue

- `modcp_reports_report`
- `modcp_reports_noreports`
- `modcp_reports_selectall`
- `modcp_modqueue_threads_thread`
- `modcp_modqueue_threads_empty`
- `modcp_modqueue_posts_post`
- `modcp_modqueue_posts_empty`
- `modcp_modqueue_attachments_attachment`
- `modcp_modqueue_attachments_empty`
- `modcp_modqueue_masscontrols`

### IP / warnings / user finder

- `modcp_ipsearch_results`
- `modcp_ipsearch_result`
- `modcp_ipsearch_noresults`
- `modcp_ipsearch_misc_info`
- `modcp_warninglogs_warning`
- `modcp_warninglogs_warning_revoked`
- `modcp_warninglogs_nologs`
- `modcp_finduser_user`
- `modcp_finduser_noresults`

### Edit profile

- `modcp_editprofile_signature`
- `modcp_editprofile_suspensions_info`

Shared `usercp_profile_*` fields remain at their existing UCP /
AdvancedProfileFields boundary. Task 48 did not create a second lease for them.

### Bans

- `modcp_banning_ban`
- `modcp_banning_edit`
- `modcp_banning_remaining`
- `modcp_banning_nobanned`
- `modcp_nobanned`
- `modcp_banuser_addusername`
- `modcp_banuser_editusername`
- `modcp_banuser_bangroups`
- `modcp_banuser_lift`

The shared ban row is intentionally semantic for both the dedicated ban list and
the Task 45 Overview; the Overview no longer expects a table row from that child.

### Announcements

- `modcp_announcements_global`
- `modcp_announcements_forum`
- `modcp_announcements_forum_nomod`
- `modcp_announcements_announcement`
- `modcp_announcements_announcement_global`
- `modcp_no_announcements_global`
- `modcp_no_announcements_forum`
- `modcp_announcements_allowhtml`

Public `announcement_*` templates, preview/postbit/editor templates and generic
date option fragments remain outside this ModCP ownership expansion.

## 3. Navigation audit

### Level 1

The registry exposes, subject to core-derived capability/scope state:

1. Overview
2. Reports
3. Moderation queue
4. Users
5. Warnings
6. Bans
7. IP tools
8. Announcements
9. Moderator log

The ModCP entry gate is the same hard prerequisite as core presentation:
authenticated user + `usergroup.canmodcp == 1`. AdvancedMenu visibility is not
used as authorization.

### Level 2 / contextual items

- Reports: Open / All.
- Queue: Threads / Posts / Attachments, with attachment setting respected.
- Users: Find user; Edit profile only with a target UID.
- Warnings: Log.
- Bans: Ban list / Ban user; Edit ban only with a target UID.
- IP tools: Search; `iplookup` maps back to Search.
- Announcements: List; Add requires typed `fid`; Edit is contextual to `aid`.
- Moderator log: no fabricated destructive child.

The renderer emits `aria-current="page"` only on the deepest resolved item.
Ancestors receive only `is-active`.

### Unknown action regression fixed by Task 51

Before this audit an unknown action had `current === ''`; the local renderer then
matched every root-level item and duplicated Level 1 as a local menu.

Now:

- the global menu remains available;
- local navigation is empty;
- no arbitrary item becomes current;
- plugin output is not blocked.

### No destructive navigation destinations

Built-in registry routes do not point to:

- `do_*`;
- `liftban`;
- delete processing;
- queue/report bulk mutations;
- token-bearing mutation URLs.

Processing actions only influence active-state resolution and titles.

## 4. Permission contract

ATF continues to render core-prepared state; it does not replace backend access
checks.

| Area | Presentation visibility source | Authorization remains in |
|---|---|---|
| Entry | `canmodcp` | `modcp.php` entry gate |
| Reports | `canmanagereportedcontent` + scoped report count for local moderators | core report branch + scoped forum list |
| Queue | `canmanagemodqueue` + available typed scope/count; attachments setting | core queue branch + typed scoped lists |
| Moderator log | `canviewmodlogs` + local scoped count | core log branch |
| Users | `caneditprofiles` | core + `modcp_can_manage_user(uid)` |
| Warnings | `canviewwarnlogs` | core ModCP warning-log branch |
| IP tools | `canuseipsearch` | core result/forum/status filtering |
| Bans | `canbanusers` | core + target/ban-owner checks |
| Announcements | `canmanageannounce` + scoped forum state; global requires supermod | core forum/global scope validation |

A hidden navigation item is not an authorization decision, and a visible item
does not bypass the destination checks.

## 5. Target-user security

Task 48/49 templates do not implement target authorization. Core retains:

- target existence checks;
- `modcp_can_manage_user()`;
- admin/supermod/super-admin protections;
- self-ban rejection;
- banned-group validation;
- duplicate-ban validation;
- existing-ban owner / supermod / ACP override checks.

No ATF template writes directly to user or ban storage.

## 6. CSRF and mutation contracts

### Moderator notes

Preserved:

- POST;
- `my_post_key`;
- `action=do_modnotes`;
- `modnotes`;
- `notessubmit`.

### Open reports

Preserved:

- POST;
- `my_post_key`;
- `action=do_reports`;
- `reports[]`;
- `page`;
- `reportsubmit`;
- `allbox`;
- native inline-report cookies/selection JS.

### Moderation queue

Preserved:

- POST;
- `my_post_key`;
- `action=do_modqueue`;
- typed `threads[tid]`, `posts[pid]`, `attachments[aid]`;
- exact values `ignore|delete|approve`;
- submit `reportsubmit`.

### Edit user

Preserved:

- POST;
- `my_post_key`;
- `action=do_editprofile`;
- `uid`;
- shared `profile_fields[...]`;
- birthday / website / user title / avatar removal / away fields;
- `signature`;
- `usernotes`;
- posting/signature suspension names and periods.

### Bans

Preserved native MyBB names:

- POST-only `action=do_banuser`;
- `my_post_key`;
- `uid`;
- add-mode `username`;
- `banreason`;
- `usergroup`;
- `liftafter`;
- `updateban`.

`liftban` remains the core token-bearing contextual mutation URL and is not a
navigation item. ATF neither removes its token nor invents a tokenless equivalent.

### Announcements

Create preserves:

- `do_new_announcement`;
- `my_post_key`;
- `fid`;
- `title`, `message`;
- all native start/end date/time names;
- `endtime_type`;
- optional `allowhtml`;
- `allowmycode`, `allowsmilies`;
- preview submit.

Edit preserves the same contract plus `aid` and the native hidden `fid`.

Delete remains a confirmation POST with:

- `do_delete_announcement`;
- `my_post_key`;
- `aid`;
- submit `submit`.

No create/edit/delete action was moved to a new ATF API.

## 7. JavaScript / editor contracts

### Reports

Preserved for `inline_reports.js`:

- `.inline_row`;
- `.checkbox`;
- `#reports_RID`;
- `#inline_read`;
- `#selectAllrow`;
- `#allSelectedrow`;
- `allbox`;
- `mark_read_text`;
- `all_text`.

### Queue

Preserved selectors:

- `input.radio_ignore`;
- `input.radio_delete`;
- `input.radio_approve`.

### User finder / bans

Add/search mode retains `#username` Select2 and the existing
`xmlhttp.php?action=get_users` endpoint. Edit-ban mode does not fabricate an
autocomplete input.

### Announcements

Preserved:

- `textarea#message[name=message]`;
- `{$codebuttons}`;
- `{$smilieinserter}`.

ATF does not initialize or replace the editor backend.

### Edit profile

Preserved:

- `#signature[name=signature]`;
- `#usernotes[name=usernotes]`;
- `#modpost` / `#modposts_action`;
- `#suspost` / `#susposts_action`;
- `#suspend_action`;
- native suspension names/period values.

## 8. Pagination and filters

ATF does not implement a second pager. Core `{$multipage}` /
surface-specific native multipage output remains authoritative.

Preserved filter contracts include:

- Moderator log: `uid`, `fid`, `sortby`, `order`, `perpage`;
- Warning log: bracketed `filter[...]` names;
- Find user: `username`, `sortby`, `order`;
- IP search: `ipaddress`, `search_users`, `search_posts`;
- reports/queue/bans: native page/type context generated by core.

Because pagination/filter query construction remains in `modcp.php`, ATF adds no
filter-state query rewriting.

## 9. Legacy-table audit

The migrated root presentation is table-free except for one intentional
compatibility boundary in `modcp_editprofile`:

`atf-modcp-editprofile__provider-table`

This table exists only to host the already-rendered required custom-profile-field
row fragments supplied by the shared MyBB/UCP/AdvancedProfileFields path. ATF
does not duplicate ownership of those shared field templates. Replacing that
transport wrapper would require changing the shared provider contract and is out
of scope for Phase 1.

This is not used for general ModCP page layout.

## 10. Ownership and lifecycle

All ModCP roots and children above use the same generic reversible ownership
ledger as the rest of ATF:

`af_adaptivethemeframework_template_ownership`

The ledger records, per natural key:

- previous content/checksum/timestamp;
- seed content/checksum;
- installed checksum/current content;
- ownership state;
- template set/name identity.

The existing repository regression
`tests/adaptivethemeframework_ownership_upgrade_regression.php` deliberately
derives its fixture from **every current key returned by
`af_adaptivethemeframework_template_seeds()`**. Therefore the ModCP seeds added
in Tasks 44–50 are covered by the same lifecycle invariant rather than a separate
ModCP-only lease mechanism.

That invariant is:

1. OFF/original template state;
2. activation installs ATF seed while preserving one immutable previous value;
3. deactivation restores exact previous bytes or removes an inherited override;
4. reactivation reuses the natural-key lease;
5. no backup chain is created;
6. manual live edits fail closed as `manual_override`;
7. restore mismatches fail closed as `restore_conflict`.

Task 51 adds no new test file. The final audit does not introduce a second
ownership implementation.

## 11. Addon compatibility

Repository-wide architecture remains consistent with Task 43:

- no AF business addon is used as a ModCP authorization provider;
- no new addon-owned `modcp_*` template writer was introduced by Tasks 44–50;
- AdvancedMenu's broader visibility callback remains only a discoverability
  concern; the ATF shell and core route use the strict `canmodcp` entry gate;
- deprecated `advresponsivelayout` is not required by ATF ModCP;
- public announcement templates remain outside ATF ModCP ownership;
- shared profile-field output remains at the existing UCP/APF boundary.

## 12. Query impact

Expected query delta from ATF presentation: **+0 SQL queries on every migrated
ModCP surface.**

Tasks 44–50 consume variables and datasets already built by `modcp.php`.
Navigation uses `$mybb->usergroup`, core-derived scoped counters and
`is_moderator()` where required; the templates themselves perform no lookups.
No per-row avatar/presence/provider query was added to ModCP.

## 13. Security review

### CSRF

Native post keys remain on every migrated mutation form. The native token-bearing
`liftban` URL is retained as-is.

### Permission leakage

ATF does not alter core route checks or scoped SQL. Navigation is presentation,
not authorization.

### Target escalation

Edit-user and ban routes remain guarded by core target checks. ATF accepts no new
target mutation endpoint.

### Destructive routes

No destructive processing route is registered as navigation. Announcement delete
continues through its confirmation POST. Queue/report mutations remain their
native forms.

### Hidden fields

Security/context hidden values (`uid`, `aid`, `fid`, actions, post key) are
preserved where core expects them.

### Route generation

Registry destinations are safe GET surfaces only. Context placeholders are
normalized to typed integer values before URL rendering.

### HTML/template safety

The ModCP seeds do not introduce the MyBB eval-sensitive `{$(` byte sequence.
Core-prepared escaped values remain escaped at the same boundary; ATF does not
decode/reparse report, warning, queue, IP or announcement data.

## 14. Responsive audit

The common ModCP shell uses the site-wide ATF page container. Migrated cards/forms
use `min-width: 0`, wrapping metadata/action rows and mobile single-column grid
rules. Input/select/textarea widths are bounded on the migrated high-risk forms.

The root content region retains a deliberate `overflow-x: auto` safety boundary
for any still-native/shared fragment that cannot shrink. This is preferred over
page-level horizontal overflow.

The edit-profile required-fields transport table is the only deliberately
scrollable legacy-shaped child noted above.

## 15. Intentionally retained native/shared fragments

Phase 1 intentionally does not own:

- `multipage*`;
- `codebuttons` / smilie inserter;
- `preview` / public postbit preview;
- public `announcement_*`;
- generic day/month option fragments where semantic ownership adds no value;
- shared `usercp_profile_*` field implementations;
- core typed information/link fragments that are already rendered into ATF cards
  and do not impose page/table layout.

These remain data/control providers, not competing page shells.

## 16. Remaining Phase 2 visual work

No Phase 2 redesign is part of Task 51. Remaining visual-only work may include:

- final density/spacing pass across ModCP cards;
- consistent iconography for status/actions;
- richer empty-state presentation;
- editor/smilie layout refinement;
- visual hierarchy for warning/report metadata;
- browser/device screenshot pass;
- optional replacement of the edit-profile row-provider transport wrapper only
  after the shared profile-field contract is redesigned.

None of these items blocks ModCP Phase 1 correctness.

## 17. Final acceptance

After the Task 51 unknown-action fix:

- all 18 planned roots use ATF shell/navigation;
- native security and mutation contracts remain owned by MyBB;
- destructive actions are not promoted into navigation;
- ModCP roots/children use the generic reversible ownership mechanism;
- the ownership regression fixture includes every current ModCP seed;
- expected query delta is +0;
- no known AF addon DOM/template writer blocks the migrated surfaces;
- the only retained table is the documented shared profile-field compatibility
  boundary;
- no new file was added under `tests/`.

**ATF ModCP Phase 1 is complete.**
