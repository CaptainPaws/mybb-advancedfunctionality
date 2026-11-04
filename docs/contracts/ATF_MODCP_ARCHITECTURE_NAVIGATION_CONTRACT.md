# ATF ModCP architecture, navigation and permission contract (Task 43)

Status: **audit only**. This document does not grant ATF ownership of any `modcp_*`
template and does not authorize changes to `modcp.php`, permissions, mutations, or
presentation.

## 0. Evidence and interpretation rules

The core evidence is the unmodified MyBB **1.8.40** release: `modcp.php`,
`inc/functions_modcp.php`, and `install/resources/mybb_theme.xml` from tag
`mybb_1840`. The complete file was inspected (not merely its menu), all 4,929
lines/action branches were enumerated, and all 110 XML templates whose names begin
with `modcp` were classified. Repository-wide searches covered PHP, templates in
PHP strings, CSS and JavaScript. The release was used as evidence only and was not
copied into this repository.

Terms used below:

* **GET surface** means a renderable/read-only route. Several core search forms use
  `method="post"` for retrieval; they remain read-only surfaces, but their existing
  method and names must be preserved.
* **Mutation** means a route that changes state. Some mutations are not restricted
  by HTTP method in core; this is a fact to preserve/contain, not an invitation to
  expose them as links.
* Every route first inherits the entry gate: logged-in user and
  `usergroup.canmodcp == 1`. ATF must never replace the core checks described here.
* `issupermod` usually broadens forum scope; it is **not** a substitute for the
  section capability (`canmanageannounce`, `canmanagemodqueue`, and so on).

## A. Route matrix

| Section | Canonical GET surface / exact context | Processing action | Exact permission/scope | Root template | Important children | Dangerous / native result |
|---|---|---|---|---|---|---|
| Overview | `modcp.php` (empty action only) | `action=do_modnotes` | entry gate; widgets additionally use their section capabilities/scopes | `modcp` | awaiting moderation, latest five logs, bans ending, nav | Notes mutation; post key; redirect to home |
| Open reports | `?action=reports&page=N` | `do_reports` | `canmanagereportedcontent`; local moderator additionally needs at least one forum with `canmanagereportedposts`; report updates are constrained by `$flist_reports` | `modcp_reports` | report rows/comments, select-all state, multipage, empty | Marks selected/all open reports read; redirect to same page |
| All reports | `?action=allreports&page=N[&rid=R]` (`page=last` accepted) | none | `canmanagereportedcontent`; query is forum-scoped for local moderators | `modcp_reports_allreports` | all-report row/comment, multipage, empty | Read-only history; `rid` calculates containing page, not detail page |
| Moderator log | `?action=modlogs&perpage=N&page=N&uid=U&fid=F&sortby={dateline,username,forum,thread,subject,action}&order={asc,desc}` | none (core filter form is POST) | `canviewmodlogs`; local moderator needs a forum with `canviewmodlog`; query constrained by scoped forum list | `modcp_modlogs` | results and typed thread/forum/post/announcement information, users, pagination/empty | Read-only |
| Announcement list | `?action=announcements` | none | `canmanageannounce` plus supermod or at least one forum with `canmanageannouncements` | `modcp_announcements` | global/forum groups and rows, active/expired icons, empty rows | Read-only list |
| Add announcement | `?action=new_announcement&fid=F` (`fid=-1` global) | `do_new_announcement` | `canmanageannounce`; global requires `issupermod`; forum requires `is_moderator(fid,'canmanageannouncements')`; forum must be viewable | `modcp_announcements_new` | day/month/options, `allowhtml`, core `preview`, `codebuttons` | Create; preview redisplays same root; success redirects to list |
| Edit announcement | `?action=edit_announcement&aid=A` | `do_edit_announcement` | same capability; existing announcement must exist; its global/forum scope is revalidated | `modcp_announcements_edit` | same editor/date fragments as add | Update; preview redisplays same root; success redirects to list |
| Delete announcement confirmation | `?action=delete_announcement&aid=A` | `do_delete_announcement` | same capability and existing announcement scope validation | `modcp_announcements_delete` | no independent page child | Native confirmation POST; delete and redirect to list |
| Queue—threads | `?action=modqueue&type=threads&page=N` (also default when first available) | `do_modqueue` | `canmanagemodqueue`; local moderator requires forum `canapproveunapprovethreads`; query/mutation constrained by `$flist_queue_threads` | `modcp_modqueue_threads` | thread rows, forum link, cross-type links, mass controls, empty, multipage | Bulk approve/delete/ignore; redirect to queue |
| Queue—posts | `?action=modqueue&type=posts&page=N` | `do_modqueue` | `canmanagemodqueue`; local moderator requires `canapproveunapproveposts`; scoped list | `modcp_modqueue_posts` | post rows, forum/thread links, cross-type links, mass controls, empty, multipage | Bulk approve/delete/ignore; redirect to posts tab |
| Queue—attachments | `?action=modqueue&type=attachments&page=N` | `do_modqueue` | attachments enabled; `canmanagemodqueue`; local moderator requires `canapproveunapproveattachs`; scoped list | `modcp_modqueue_attachments` | attachment rows, cross-type links, mass controls, empty, multipage | Bulk approve/delete/ignore; redirect to attachments tab |
| Queue—no available type | `?action=modqueue` | none | section capability/scope checks above | `modcp_modqueue_empty` | nav only | Read-only empty state |
| Find user | `?action=finduser&username=...&sortby={username,regdate,lastvisit,postnum}&order={asc,desc}&perpage=N&page=N` (core form POST) | none | `caneditprofiles` | `modcp_finduser` | user row/empty and multipage | Read-only lookup; rows link to edit |
| Edit user | `?action=editprofile&uid=U` | `do_editprofile` | `caneditprofiles`, existing target, and `modcp_can_manage_user(uid)` | `modcp_editprofile` | away, profile fields (shared `usercp_profile_*`), signature, suspension selects/info | Target-user mutation; success redirects to find user |
| Warning log | `?action=warninglogs&filter[...]&search[...]&page=N` | none | `canviewwarnlogs` | `modcp_warninglogs` | rows, revoked marker, empty, multipage | Read-only. Details/issue/revoke live in `warnings.php` |
| IP search | `?action=ipsearch` plus search contract below (core form POST) | same render route; no mutation | `canuseipsearch`; post visibility is further constrained for non-supermods by moderator `canviewunapprove`/`canviewdeleted` and forum visibility | `modcp_ipsearch` | results, typed post/reg-IP/last-IP subjects, misc-info link, empty, multipage | Read-only lookup |
| IP lookup | `?action=iplookup&ipaddress=IP` | none | `canuseipsearch` | **none** (AJAX/text response via `output_page`, not a full document) | generated `$iplookup` string; hook `modcp_iplookup_end` | Read-only reverse DNS/GeoIP information; not a shell root |
| Ban list | `?action=banning&page=N` (`page=last` accepted) | none | `canbanusers` | `modcp_banning` | ban row, remaining time, edit/lift controls, empty, multipage | Read-only list; lift control is a tokenized mutation URL |
| Add/edit ban | `?action=banuser` or `?action=banuser&uid=U` | `do_banuser` (**POST-only**) | `canbanusers`; target checks and existing-ban ownership rules below | `modcp_banuser` | add/edit username, banned-group select/hidden group, lift-time options | Creates/updates ban then redirects to list |
| Lift ban | **no GET page**; core currently emits `?action=liftban&uid=U&my_post_key=TOKEN` | `liftban` | `canbanusers`; ban owner or `issupermod` or `cancp` | none | link fragment `modcp_banning_edit` | Destructive tokenized request, redirect to list; never navigation |

There is no standalone ModCP “report detail”, “warning type”, “warning issue”,
“warning revoke”, “change usergroup”, or separate “user notes” route in 1.8.40.
Report details are expanded in rows. Warnings are issued/viewed/revoked in
`warnings.php` (`action=warn`, `view`, `do_warn`, `revoke`, `do_revoke`), while
`modcp.php` only lists them. ModCP profile edit changes profile data, moderator
notes on the user, avatar removal and signature/posting suspensions; it does not
offer a usergroup selector. The home moderator-notes textarea edits the single
global `modmessage` setting, not per-user notes.

### Search/filter/pagination contract

Do not flatten bracketed fields or rename them:

* reports: `page`; all reports also accepts `rid` and `page=last`;
* queue: `type=threads|posts|attachments`, `page`/`page=last`;
* logs: `uid`, `fid`, `sortby`, `order`, `perpage`, `page`/`last`;
* user finder: `username`, `sortby`, `order`, `perpage`, `page`/`last`;
* warnings: `filter[username]`, `filter[uid]`, `filter[mod_username]`,
  `filter[mod_uid]`, `filter[reason]`, `filter[sortby]`, `filter[order]`,
  `filter[per_page]`, optional `search[...]`, and `page`;
* IP: `ipaddress`, presence flags `search_users` and `search_posts`, `perpage`,
  `page`/`last`. Wildcard/CIDR input affects whether lookup information appears;
  `iplookup` uses `ipaddress`.
* bans: `page`/`last`; announcement actions use `fid` or `aid`; profile/ban
  target actions use `uid`.

## B. Template graph

### Full-page roots (the only future shell candidates)

The exact 18 HTML-document roots are:

`modcp`, `modcp_reports`, `modcp_reports_allreports`, `modcp_modlogs`,
`modcp_announcements`, `modcp_announcements_new`, `modcp_announcements_edit`,
`modcp_announcements_delete`, `modcp_modqueue_threads`,
`modcp_modqueue_posts`, `modcp_modqueue_attachments`, `modcp_modqueue_empty`,
`modcp_finduser`, `modcp_editprofile`, `modcp_warninglogs`, `modcp_ipsearch`,
`modcp_banning`, and `modcp_banuser`.

Only these roots may eventually receive `pun atf-page-shell atf-page atf-modcp`.
`iplookup` has no root. Shared `preview`, `codebuttons`, `multipage`,
`forumjump_*`, `usercp_profile_*` and `postbit*` dependencies are not ModCP roots.

### Composition graph (inseparable children)

* `modcp` → `modcp_nav`; `modcp_awaitingmoderation` →
  `modcp_awaiting{threads,posts,attachments}` → `modcp_last{thread,post,attachment}`
  or `modcp_awaitingmoderation_none`; `modcp_latestfivemodactions` → log result
  family; `modcp_banning_ban`/`modcp_nobanned`.
* Every document root → `modcp_nav` → optional
  `modcp_nav_forums_posts` (`nav_announcements`, `nav_modqueue`,
  `nav_reportcenter`, `nav_modlogs`) and optional `modcp_nav_users`
  (`nav_editprofile`, `nav_banning`, `nav_warninglogs`, `nav_ipsearch`). These are
  one permission-built sidebar, not pages.
* `modcp_reports` → `modcp_reports_report` →
  `modcp_reports_report_comment{,_extra}`; plus `selectall`, `multipage`,
  `noreports`. `modcp_reports_allreports` → `allreport` → same comment fragments;
  plus `allnoreports`, `multipage`.
* `modcp_modlogs` → `modcp_modlogs_result` → one of
  `result_thread|result_forum|result_post|result_announcement`; plus `user`,
  `multipage`, `noresults` (filter result) / `nologs` (home widget).
* `modcp_announcements` → `announcements_global`, announcement global/forum rows,
  forum/nomod rows, active/expired icons, and both no-announcement rows.
  Add/edit roots inseparably use `announcements_day`, `month_start`, `month_end`,
  optional `allowhtml`, shared editor/preview. Delete is already the confirmation.
* Each queue root → its typed row and typed empty state, link fragments for other
  types, `link_forum`/`link_thread` as applicable, and `masscontrols`.
* `modcp_finduser` → `finduser_user|finduser_noresults`.
  `modcp_editprofile` → `editprofile_away`, `editprofile_signature`,
  `editprofile_select` → `select_option`, and repeated `suspensions_info`, plus
  shared UCP profile-field fragments.
* `modcp_warninglogs` → `warninglogs_warning` → optional `warning_revoked`, or
  `warninglogs_nologs`.
* `modcp_ipsearch` → `ipsearch_results` → `ipsearch_result` → typed
  `result_regip|result_lastip|result_post`; optional `results_information`; or
  `ipsearch_noresults`. `modcp_ipsearch_misc_info` is the lookup response fragment.
* `modcp_banning` → `banning_ban` → optional `banning_edit` and
  `banning_remaining`; or `banning_nobanned`. `modcp_banuser` →
  `banuser_addusername|banuser_editusername`, `banuser_bangroups` → group rows (or
  `bangroups_hidden`), and `banuser_lift` → `banuser_liftlist`.

Thus rows, controls, links, nav groups, empty states, date options, confirmation
content and pagination wrappers must never receive a page shell independently.

## C. Permission matrix

| Capability | Additional, non-negotiable core conditions |
|---|---|
| Global entry `canmodcp` | user must be logged in. No ATF inference from a visible link is valid. |
| `canmanageannounce` | local forum scope is `canmanageannouncements`; global `fid=-1` is supermod-only; inaccessible/inactive forums are excluded. |
| `canmanagemodqueue` | each queue independently requires local `canapproveunapprovethreads`, `canapproveunapproveposts`, or `canapproveunapproveattachs`; supermods get all-forum scope; attachments setting still applies. |
| `canmanagereportedcontent` | local scope is forums with `canmanagereportedposts`; supermod/admin report queries can include non-post report types globally. |
| `canviewmodlogs` | local scope requires forum `canviewmodlog`; supermods get all-forum scope. |
| `caneditprofiles` | both GET and update call `modcp_can_manage_user(uid)`. A non-supermod cannot manage a target whose effective permissions include `issupermod` or `cancp`; an admin target requires actor `cancp`; a super-admin target requires actor to be super-admin. There is no blanket equal-group comparison and no core prohibition on editing self in this route. |
| `canviewwarnlogs` | only the ModCP log; warning permissions and target validation are owned by `warnings.php`. |
| `canuseipsearch` | post-result visibility remains forum/status scoped, including moderator `canviewunapprove` and `canviewdeleted`; never expose more results merely because a shell link is visible. |
| `canbanusers` | new/update also applies `modcp_can_manage_user`, forbids banning self, validates an actual banned group and duplicate ban; editing/lifting someone else's ban is restricted to the original banning user, `issupermod`, or `cancp`. |

Menu construction combines capability and scope. A section must not be rendered
from capability alone where core also requires a nonzero scoped-forum count.
Conversely, active state is visual only and must not become authorization.

## D. Mutation and CSRF contract

| Action | Native method / token | Exact payload that must survive | Confirmation/result boundary |
|---|---|---|---|
| `do_modnotes` | POST, `my_post_key` | `action`, `modnotes`, submit `notessubmit` | Inline home form; redirect home |
| `do_reports` | POST, `my_post_key` | `reports[]`, `page`, `action`, `reportsubmit`; `allbox`; JS may instead maintain `inlinereports` / `inlinereports_removed` cookies | No confirmation; mark read and redirect |
| `do_modqueue` | POST, `my_post_key` | exactly one typed map: `threads[tid]`, `posts[pid]`, or `attachments[aid]`, values `ignore|delete|approve`; `action`, `reportsubmit` | No confirmation despite possible permanent deletion; retain explicit radio form |
| `do_new_announcement` | POST, `my_post_key` | `fid`, `title`, `message`, `starttime_day/month/year/time`, `endtime_type`, `endtime_day/month/year/time`, `allowhtml` when enabled, `allowmycode`, `allowsmilies`, optional submit `preview` | Preview returns to add form; create redirects |
| `do_edit_announcement` | POST, `my_post_key` | all add fields plus `aid` (and native hidden `fid`); optional `preview` | Preview returns to edit; update redirects |
| `do_delete_announcement` | POST, `my_post_key` | `aid`, `action`, submit `submit` | Preserve native `delete_announcement` confirmation page |
| `do_editprofile` | POST, `my_post_key` | hidden `action`, `uid`; `profile_fields[fid]`, birthday fields, `website`, `usertitle`, `reverttitle`, `remove_avatar`, away fields/reason, `signature`, `usernotes`; suspension controls `suspendsignature` + `action_period/action_time`, `moderateposting` + `modpost_period/modpost_time`, `suspendposting` + `suspost_period/suspost_time` | Validation redisplays form; success redirects |
| `do_banuser` | POST-only, `my_post_key` | `uid` (0/add or target/edit), username fragment supplies `username` for add, `banreason`, `usergroup`, `liftafter`, submit `updateban` | Validation redisplays form; success redirects |
| `liftban` | core tokenized GET-like URL, `my_post_key` | `uid`, token | No confirmation root in core. Keep out of nav; at minimum preserve token and authorization. Do not silently turn it into an unprotected link. |

`do_reports`, `do_modqueue`, announcement `do_*`, `do_editprofile`, and
`do_modnotes` do not all explicitly check `$request_method`; the token remains
mandatory. `do_banuser` additionally requires POST. ATF must not “regularize” these
contracts by changing route, names, or business logic. Queue ignore means no
mutation; delete may be soft or hard according to MyBB settings.

### Required DOM/editor/JavaScript anchors

* Reports require `jscripts/inline_reports.js`, `.inline_row`, `.checkbox`,
  `#reports_RID`, `#inline_read`, `#selectAllrow`, `#allSelectedrow`, `allbox`,
  globals `mark_read_text` and `all_text`, plus the inline-report cookies.
* Queue mass controls target `input.radio_ignore`, `input.radio_delete`, and
  `input.radio_approve`; row names/typed IDs are the mutation contract.
* Announcement editor requires `textarea#message[name=message]`, `$codebuttons`
  and `$smilieinserter`; date/radio names listed above are parsed server-side.
* Warning filter loads core Select2 assets and initializes `#username` against
  `xmlhttp.php?action=get_users` with parameter `query`. The moderator username
  field has no equivalent ID/autocomplete in the stock template.
* Ban-add user autocomplete is rooted in the `modcp_banuser_addusername` fragment
  (`#username`) and the core Select2 user endpoint; edit mode replaces it with
  display text/hidden target data. Preserve the fragment distinction.
* Profile edit depends on shared profile field names, `textarea#signature`,
  `textarea#usernotes`, checkbox IDs/labels and the mutually exclusive
  moderate/suspend-posting controls. Core template JavaScript toggles suspension
  time controls; IDs/names must be retained.
* `modcp_nav` collapse behavior uses `#modcpforums_img`, `#modcpforums_e`,
  `#modcpusers_img`, and `#modcpusers_e`. These may disappear only when Task 44
  deliberately replaces the whole nav behavior, never while mixing old JS/nav.

## E. Addon dependencies and template writers

### Repository findings

No AF addon registers `modcp_start`, `modcp_end`, `modcp_nav`, `modcp_menu`,
`modcp_user`, any `modcp_do_*` hook, or any other core `modcp_*` hook. No addon
calls `find_replace_templatesets` against `modcp_*`; no addon owns or writes a
`modcp_*` template; and no addon JavaScript queries stock ModCP nodes. Therefore
there is **no evidenced third-party full-template owner or compatibility provider**
to normalize in Task 43.

The actual intersections are:

* **AdvancedMenu** registers one global `modcp.php` link. Its visibility callback
  uses `canmodcp || issupermod || is_moderator()`, which is broader than the core
  entry gate. Treat this as a discoverability discrepancy: the destination still
  enforces core permission, and Task 44 should prefer the exact `canmodcp` gate.
* **AdvancedAlertsAndMentions** patches the global member welcome template before
  `$modcplink`; it does not patch ModCP templates. Its pre-output logic opts out
  only when `IN_MODCP` is defined, but stock frontend `modcp.php` defines
  `IN_MYBB`, not `IN_MODCP`; this is a global-header concern, not a ModCP writer.
* **AdvancedGallery** and **AdvancedFontAwesome** explicitly treat
  `THIS_SCRIPT === 'modcp.php'` as non-frontend and do not inject their frontend
  assets. **AdvancedEditor** also opts out on `IN_MODCP`, but still has no ModCP
  hook/template writer; stock announcement `$codebuttons` remains the dependency.
* **FastNews**, **HeaderWelcomeAvatar**, and **AdvancedPosterAvatar** explicitly
  avoid ModCP. **ForceRefresh** and **AdvancedPostCounter** mention `IN_MODCP` in
  admin-context guards but have no ModCP integration.
* Searches found no ModCP route/hook/template integration in the requested
  AdvancedAccountSwitcher, AdvancedAppearance, AdvancedProfileFields,
  AdvancedBuddyList, AdvancedThreadFields, CharacterSheets, Wanted,
  Inventory/Shop, or other moderator/admin adjunct addons. CharacterSheets and
  Knowledgebase only use `canmodcp` in their own authorization rules; that does
  not make them ModCP providers.

### Legacy Advanced Responsive Layout

`advresponsivelayout` maps `modcp.php` to body class `af-rwd-modcp`. Its CSS only
makes guessed menu/content selectors full-width, bounds form controls, and allows
`.af-rwd-table-wrap` horizontal scrolling. No ModCP DOM-moving JavaScript or
template rewrite was found. The UX outcome—single-column controls and scrollable
wide data on small screens—is useful evidence, but its selector guesses and CSS
are not a compatibility target and must not be copied.

## F. Proposed navigation contract

Build one registry, then render two levels from core-provided permission/scope
state. Do not infer access from action names.

**Level 1** (only when core-accessible): Overview; Reports; Queue; Users; Warnings;
Bans; IP tools; Announcements; Moderator log. The log is a genuine core section
and must not be hidden under Overview. “Users” points to `finduser`, not directly
to a target edit page.

**Level 2:**

* Reports: Open, All.
* Queue: Threads, Posts, Attachments—only available types; attachments also
  respects the setting.
* Users: Find user; Edit profile only as contextual crumb/action with a valid
  target, never as a targetless permanent tab.
* Bans: Ban list; Add ban. “Edit ban” is contextual to `uid`; Lift is never nav.
* Announcements: List; Add only with typed `fid`; Edit/Delete are contextual.
* IP tools: Search; lookup is a contextual/AJAX result, not a persistent tab.
* Warnings: Log only. Links to `warnings.php` remain cross-route contextual
  actions, not invented ModCP tabs.
* Moderator log needs filters, not a fabricated destructive/action tab.

## G. Active-state rules

Normalize the basename to `modcp.php`; missing action means `home`. Select the
deepest recognized item, but never let an unknown plugin action clear/crash the
navigation—fall back to no local item (and a safe ModCP/global state), while
leaving plugin output intact.

| Incoming action/context | Level 1 / deepest parent |
|---|---|
| empty, `do_modnotes` | Overview |
| `reports`, `do_reports` | Reports / Open |
| `allreports` | Reports / All |
| `modqueue`, `do_modqueue` | Queue / typed `type`; for processing infer the posted nonempty map (`threads`, `posts`, `attachments`), otherwise Queue |
| `finduser` | Users / Find |
| `editprofile`, `do_editprofile` | Users / Edit (`uid` context) |
| `warninglogs` | Warnings / Log |
| `ipsearch`, `iplookup` | IP tools / Search (lookup is subordinate result) |
| `banning`, `liftban` | Bans / List |
| `banuser`, `do_banuser` | Bans / Add or Edit according to a valid/nonzero `uid` |
| `announcements` | Announcements / List |
| `new_announcement`, `do_new_announcement` | Announcements / Add (`fid` context) |
| `edit_announcement`, `do_edit_announcement` | Announcements / Edit (`aid`) |
| `delete_announcement`, `do_delete_announcement` | Announcements / List, with contextual Delete state—not a nav item |
| `modlogs` | Moderator log |

Processing actions always highlight their safe GET parent. Typed context is only
for highlighting; IDs must still be validated by core.

## H. Ownership boundary and migration order

### Data ownership

MyBB continues to own the entry gate, group/forum permissions, scoped forum lists,
target immunity/ban ownership, visibility, validation, queries, data handlers,
CSRF, moderation classes, logging, cache updates, redirects and all business
rules. ATF may own only the document shell, permission-fed navigation rendering,
layout of existing cards/lists/forms, responsive presentation, and visual active
state. It must submit the native names/actions back to MyBB.

### Reversible ownership recommendation for Task 44

Take no ownership now. In Task 44, first adopt only the 18 proven full-page roots
through the existing generic reversible ownership mechanism; snapshot exact
pre-ATF template text per theme and restore it verbatim. Do not include the 92
`modcp_*` fragments in the initial shell ownership set. Add a fragment later only
when a migrated root demonstrably requires markup changes, with its own exact
snapshot/restore record. Do not add a normalizer or compatibility provider: the
repository has no evidenced `modcp_*` writer. Re-audit immediately before adoption
to catch plugins installed outside this repository.

### Risk-led migration order

1. Registry, permission-fed Level 1/2 navigation, unknown-action fallback, and
   shell application to roots without changing inner forms.
2. Read-only Overview output (leave moderator notes form native), then moderator
   log and all-reports history.
3. Open reports list while freezing inline-report JS/cookie/checkbox contracts.
4. Queue typed lists; migrate one type at a time, retaining radios and bulk POST.
5. IP search/lookup and warning log, including Select2 and cross-route warning
   boundary.
6. Find-user, then profile form with shared UCP fragments and target checks.
7. Ban list, add/edit, then tokenized lift boundary.
8. Announcement list/editor; delete confirmation last.

Each stage must prove exact restore before the next stage. Do not begin Task 44 as
part of this audit.

## I. High-risk surfaces

* **Queue bulk actions:** one submit can approve or soft/hard-delete many scoped
  objects; typed maps, radio defaults and forum filters are security boundaries.
* **Profile edit:** changes another user's public data, user notes, avatar and
  posting/signature state; shared fragments and `modcp_can_manage_user` prevent
  privilege crossover. It is not a usergroup editor.
* **Ban add/edit/lift:** changes effective group membership. Self-ban, target
  immunity, banned-group validation, original-ban-owner restrictions and saved old
  groups must remain core-owned. Lift lacks a confirmation page and is especially
  easy to mis-model as navigation.
* **Announcements:** global actions are supermod-only; forum scope differs per
  moderator. Editor preview shares the processing action; delete requires its
  native confirmation and token.
* **Reports:** local moderators must not see or close out-of-scope reports;
  select-all is cookie-backed and can represent more than the visible page.
* **IP tools:** expose sensitive registration/last/post IP data, DNS/GeoIP, and
  posts whose approval/deletion visibility depends on forum moderator flags.
* **Warnings:** log access (`canviewwarnlogs`) must not be conflated with issuing or
  revoking warnings in `warnings.php`; those cross-route permissions are separate.
* **Supermod/admin boundaries:** `issupermod` expands forum scope, while `cancp`,
  super-admin status, and effective target permissions control target immunity.
  A visual registry must not simplify those axes into a single “staff” boolean.

## J. Recommended Task 44 scope

Task 44 should implement only: a reversible shell for the 18 roots; a registry
whose visibility inputs are supplied by native core state; the two navigation
levels and mapping above; content/notice/action placement without renaming any
field; and regression coverage outside this audit. It should explicitly exclude
business logic, route normalization redirects, new permissions, new confirmation
flows, fragment-wide ownership, warning-system migration, CSS redesign, and any
addon normalizer without a newly demonstrated writer.

Candidate generic slots, created only when the implementation has a consumer, are
`modcp.global_navigation`, `modcp.local_navigation`, `modcp.before_content`,
`modcp.content`, `modcp.after_content`, `modcp.notice`, and `modcp.actions`.
Current repository evidence proves no AF provider requiring an additional
ModCP-specific slot.
