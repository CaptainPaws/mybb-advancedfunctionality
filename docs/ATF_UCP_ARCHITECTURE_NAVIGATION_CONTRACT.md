# ATF User Control Panel architecture and navigation contract

## Scope, evidence, and non-goals

This document is the preparation audit for a future Adaptive Theme Framework
(ATF) User CP shell. It was checked against MyBB 1.8.40 tag `mybb_1840`
(commit `bd2a3447939d3084a5926dd66ece04649e0e0d60`), specifically
`usercp.php`, `private.php`, `inc/functions_user.php`, the English language
files, and `install/resources/mybb_theme.xml`. The audited copies of
`usercp.php` and `mybb_theme.xml` had SHA-256 sums
`6f6b2742c2698fac99fc585a21be6964f92a4dc730800bb3539143b63776c565`
and `376673ae7fdf6792ff6959e759c0d8bc13f6faefb49d76feb2cec2d436ab2f42`.
The complete AF repository was searched for User CP routes, hooks, templates,
and DOM dependencies on 2026-10-02.

The registry phase deliberately took ownership of no `usercp*` template. The
first shell phase now leases only `usercp`, `usercp_currentavatar`, and
`usercp_notepad`; every other User CP surface and all AF addon pages remain
legacy. The existing PM Phase 1 ownership and workspace contract remain as documented in
`ATF_PRIVATE_MESSAGING_MIGRATION_CONTRACT.md`.

The following are explicitly out of scope:

- parsing the legacy `usercp_nav` table or its generated HTML;
- regex insertion into `usercp_nav`;
- browser-side discovery, relocation, or duplication of legacy menu links;
- changing core actions, permissions, forms, tokens, or plugin dispatch;
- claiming that every `usercp.php` handler is a navigable page.

## Route and lifecycle audit

`usercp.php` requires an authenticated user with `canusercp`. It calls
`usercp_menu()` and then `usercp_start` before core action dispatch. Plugins can
therefore terminate a request at `usercp_start`, as both AF UCP addons do. The
core switch near the top only supplies breadcrumbs; the authoritative surface
inventory is the later action branches, including subscription and social
mutation routes omitted from that switch.

### Core interactive surfaces

| Surface | Canonical GET route | Processing/actions that must remain native | Effective visibility |
| --- | --- | --- | --- |
| Home / account summary | `usercp.php` (empty action) | `do_notepad` updates the embedded personal pad | logged in and `canusercp`; widgets are individually conditional |
| Edit profile | `usercp.php?action=profile` | `do_profile` | `canusercp`; individual birthday, website, away, custom-title and custom-field controls remain core/plugin gated |
| Preferences | `usercp.php?action=options` | `do_options` | `canusercp`; PM, invisibility, editor, language and style controls are setting/group gated |
| Change password | `usercp.php?action=password` | `do_password` | `canusercp` |
| Change email | `usercp.php?action=email` | `do_email`, including activation/verification flows | `canusercp`; board email-verification settings alter the result |
| Change username | `usercp.php?action=changename` | `do_changename` | `usergroup.canchangename != 0`; core also enforces cooldown/credits-style limits |
| Avatar | `usercp.php?action=avatar` | `do_avatar` (upload, URL, Gravatar, resize, remove) | core avatar settings and group avatar permissions |
| Signature | `usercp.php?action=editsig` | `do_editsig` (save, preview, update existing posts) | `canusesig`, post threshold, and signature suspension rules |
| Group memberships | `usercp.php?action=usergroups` | same route with `do=joingroup`, `leave`, `display`, and invitation handling | rows/actions derived from membership, leadership, joinability and moderation state |
| Buddy / ignore | `usercp.php?action=editlists` | `do_editlists`, `acceptrequest`, `declinerequest`, `cancelrequest` | social settings and request state; every mutation keeps `my_post_key` |
| Drafts | `usercp.php?action=drafts` | `do_drafts` | draft count/content for current uid |
| Thread subscriptions | `usercp.php?action=subscriptions` | `do_subscriptions`; `addsubscription`, `do_addsubscription`, `removesubscription`, `removesubscriptions` are contextual flows | forum/thread visibility, subscription state and notification capabilities |
| Forum subscriptions | `usercp.php?action=forumsubscriptions` | the same add/remove routes with `type=forum` | forum visibility and subscription state |
| Attachments | `usercp.php?action=attachments` | `do_attachments` | global `enableattachments`; records belong to current uid |
| View public profile | core-generated `member.php?action=profile&uid=<uid>` | none in User CP | current authenticated user |
| Private messages | `private.php` | all `private.php` actions | `enablepms && usergroup.canusepms`; child permissions vary |

The account-summary home also composes current avatar, activation status,
reputation, referrals, latest warnings, latest subscribed threads, latest own
threads and the notepad. These are home widgets, not additional primary routes.
Likewise, `addsubscription`, removal confirmation pages, and the moderated
join-group form are transactional/contextual states reached from content; they
must resolve active navigation to their parent section but must not become
global menu entries.

### Private-message surfaces

The global UCP registry contains one **Messages** destination (`private.php`).
Everything below is local PM navigation owned by the PM workspace contract:

| Local item | Route / source | Visibility or active rule |
| --- | --- | --- |
| Compose | `private.php?action=send` | `cansendpms`; active for send/reply/forward/draft-compose modes |
| Folders | `private.php?fid=<id>` | folder ids and names come from the current user's `pmfolders`; active by exact resolved `fid` |
| Inbox / Sent / Drafts / Trash | the native core folder entries, normally ids `1` / `2` / `3` / `4` | never hard-code display names; resolve the ids from native folder data |
| Tracking | `private.php?action=tracking` | `cantrackpms` |
| Edit folders | `private.php?action=folders` | PM access; mutation remains `do_folders` |
| Advanced search | `private.php?action=advanced_search` | PM access; results are `search` / `results` states |
| Empty folders | `private.php?action=empty` | PM access; destructive submit remains `do_empty` |
| Export messages | `private.php?action=export` | PM access; download processing remains `do_export` |

MyBB 1.8.40 has **no distinct “Unread” PM folder route**. Unread is message
state within folder/search/tracking results. A future local item labelled
“Unread” requires a real provider with a permission-safe query and canonical
route; it must not be fabricated by filtering or scraping inbox markup.
Custom PM folders are provider data and remain local children, never global UCP
registry definitions.

### AF plugin-provided UCP routes

| Provider | Routes and behavior | Templates / dependency | Contract consequence |
| --- | --- | --- | --- |
| Advanced Alerts and Mentions (AAM) | `af_aam_list` (GET list; POST clear-all) and `af_aam_prefs` (GET/POST preferences) are dispatched and exited at `usercp_start`; AAM also observes native `do_editlists` to create buddy-request alerts. | `af_aam_list_page` -> `af_aam_list_row`, `multipage`; `af_aam_ucp_prefs` -> `af_aam_ucp_prefs_row`. The pages currently do not include `{$usercpnav}`. Its `usercp_menu` hook inserts a table row by regex matching stock `usercp_nav_home`, with append fallback. | Register a data provider under **Social / Alerts** with list and preferences as local children. Replace the regex hook only when the registry consumer exists; retain current hook as legacy fallback before then. A shell must offer slots because these plugin pages bypass core's final `usercp` template. |
| Advanced Account Switcher (AAS) | `af_aas` is dispatched and exited at `usercp_start`; POST `do=create`, `link_existing`, `unlink`, and `save_privacy` share that URL. Other AAS routes under `misc.php` are not UCP pages. | `af_aas_usercp` -> `af_aas_ucp_page`, warning, row/empty, create/link/privacy/unlink fragments. It calls `usercp_menu()` again, embeds `{$usercpnav}`, and has a literal legacy-table fallback. Its `usercp_menu` hook appends raw `<tr>` markup. | Register **Social / Accounts** only when addon enabled, user logged in, and `af_aas_user_allowed(uid)`. Forms, master-account gating and tokens remain provider-owned. Remove neither legacy injection nor fallback until that addon adopts the registry/shell contract. |

No other AF addon registers a `usercp_start`, `usercp_menu`, or User CP action.
AdvancedMenu links globally to `usercp.php` and `private.php` but owns neither
UCP hierarchy. AdvancedProfileFields modifies the stock
`usercp_profile_customfield` template and CSS, so profile custom fields are an
opaque plugin-sensitive child. Deprecated Advanced Responsive Layout styles
`#usercp_menu`, `#pm_menu`, `#usercp_content`, and `#pm_content`; those selectors
are legacy behavior, not a compatibility target. FakeOnline only labels
`usercp.php` activity. Several other addons merely blacklist `usercp.php` from
their global assets and add no route.

## Verified template graph

The stock theme ships 119 `usercp*` templates. The following table is the live
graph grouped by root/surface. “Critical” means the values and controls that a
future shell must transport without recomputing. Common page roots also use
`headerinclude`, `header`, `footer`, `usercpnav`, language variables, and theme
table settings; those repeated shell values are not restated in every row.

| Root / action | Direct and transitive children | Critical variables, forms, and plugin boundary |
| --- | --- | --- |
| `usercp` / empty action | `usercp_currentavatar`; `usercp_resendactivation`; `usercp_reputation`; `usercp_referrals`; `usercp_notepad`; `usercp_latest_subscribed` -> `usercp_latest_subscribed_threads`; `usercp_latest_threads` -> `usercp_latest_threads_threads`; `usercp_warnings` -> `usercp_warnings_warning` -> optional `usercp_warnings_warning_post`; shared thread icon/unread/last-post templates | `{$avatar}`, account counts/dates/group, `{$latest_*}`, `{$latest_warnings}`, `{$user_notepad}`. Notepad posts `action=do_notepad` with `my_post_key`; warning, activation, reputation and referral fragments stay permission/setting derived. |
| `usercp_profile` / `profile` | `usercp_profile_day`, `usercp_profile_away`, `usercp_profile_website`, `usercp_profile_customtitle` -> current/revert children; `usercp_profile_profilefields` -> `usercp_profile_customfield` -> text/textarea/select/multiselect/radio/checkbox and select-option children | Post `action=do_profile` + token. Preserve birthday/privacy, website, away state/reason/date, title/revert and exact `profile_fields[$field]` names/cardinality. `usercp_profile_customfield` is modified by AdvancedProfileFields and must be treated as opaque provider output. |
| `usercp_options` / `options` | `usercp_options_invisible`, `_pms`, `_pms_from_buddys`, `_quick_reply`, `_date_format`, `_time_format`, `_timezone` + option, `_tppselect` + option, `_pppselect` + option, `_style`, `_language` + option; theme selector helpers `usercp_themeselector` + option can be called by style building | Post `action=do_options` + token. Preserve every checkbox/select name and generated selected/checked state: privacy, messaging, subscriptions, locale/time, forum/thread display, editor and style/language. Core settings and group permissions decide which children exist. |
| `usercp_password` / `password` | none | `oldpassword`, `password`, `password2`, `action=do_password`, `my_post_key`, `{$errors}`. |
| `usercp_email` / `email` | none | `password`, `email`, `email2`, `action=do_email`, token/errors; verification behavior remains core. |
| `usercp_changename` / `changename` | none | `password`, `username`, max length, `action=do_changename`, token/errors; visible only under core group rule. |
| `usercp_avatar` / `avatar` | `usercp_avatar_current`, `_upload`, `_remote`, `_remove`, one of `_auto_resize_auto` / `_auto_resize_user` | Multipart POST `action=do_avatar`, token, `avatarupload`, `avatarurl`, `auto_resize`, `remove`; retain avatar errors and core-generated dimensions/notes. |
| `usercp_editsig` / `editsig` | dynamic `usercp_editsig_current` or `_preview`; suspended route uses `usercp_editsig_suspended`; shared `smilieinserter`, `codebuttons` | `signature`, `updateposts`, save/preview, `action=do_editsig`, token, parser preview. Keep editor ids and core signature verification; suspension is a separate rendered state. |
| `usercp_editlists` / `editlists` | `usercp_editlists_user`, `_no_buddies`, `_no_ignored`; request wrappers `_received_requests` / `_sent_requests` -> received/sent row or `_no_requests` | Two forms retain `action=do_editlists`, `manage=buddy|ignored`, token, autocomplete ids and JS callbacks. Row remove and request accept/decline/cancel links carry uid/request id and token. AAM depends on the native `do_editlists` lifecycle, not markup. |
| `usercp_drafts` / `drafts` | `usercp_drafts_draft` -> `_draft_thread` or `_draft_forum`; `_none` | POST `action=do_drafts`, token, `deletedraft[id]=type`, edit URL, subject/context/date, select-all and disabled delete state. |
| `usercp_subscriptions` / `subscriptions` | `_thread` -> optional `_thread_icon` and shared unread/last-post templates; `_remove` or `_none`; multipage | POST `action=do_subscriptions`, token, `check[tid]`, bulk `do` notification/delete choices. Preserve visibility-filtered thread links/status and pagination. |
| `usercp_forumsubscriptions` / `forumsubscriptions` | `_forum` -> shared last-post variants; `_none` | Forum identity/counts/last post and token-bearing unsubscribe URL; no bulk form in the root. |
| `usercp_addsubscription_thread` / `addsubscription` | none | POST `action=do_addsubscription`, token, `tid`, notification radio. This is contextual, not menu navigation. |
| `usercp_removesubscription_thread`, `_forum` / `removesubscription` confirmation | none | POST token, tid/fid/type; contextual confirmation. Direct valid token requests may process without displaying this page. |
| `usercp_usergroups` / `usergroups` | `_leader` -> `_leader_usergroup` -> member-list/moderate-request links; `_memberof` -> `_memberof_usergroup` -> description plus display/set-display and four leave-state fragments; `_joinable` -> `_joinable_usergroup` -> description/join; request form `_joingroup` | gid, membership/leader/request counts, permission-derived links. Mutations carry token and `do`; request reason is provider/core data. Do not infer join/leave capability from whether a link rendered. |
| `usercp_attachments` / `attachments` | `_attachment`, `_none`, shared `delete_attachments_button`, multipage | POST `action=do_attachments`, token, `attachments[aid]`, select-all; filename, size/downloads, post/thread visibility-safe links; quota, usage and bandwidth totals. |
| `usercp_nav` / all UCP and PM pages | `usercp_nav_home`; `usercp_nav_messenger` -> compose, dynamic folder, tracking; `usercp_nav_profile` -> conditional change-name and edit-signature; `usercp_nav_misc` -> conditional attachments | This is the legacy renderer only. Its inputs include `{$usercpmenu}`, permission-filtered fragments, PM folders, draft count and collapse state. It is **not** an input to the new registry. AAM regex-mutates and AAS appends raw rows to its rendered accumulator today. |

### Complete stock `usercp*` inventory

For completeness, the 119 names in MyBB 1.8.40 are grouped below. A name in
the XML is not proof of an independent route; the graph above records actual
evaluation/composition.

- **Roots and summary:** `usercp`, `usercp_currentavatar`,
  `usercp_resendactivation`, `usercp_reputation`, `usercp_referrals`,
  `usercp_notepad`, `usercp_latest_subscribed`,
  `usercp_latest_subscribed_threads`, `usercp_latest_threads`,
  `usercp_latest_threads_threads`, `usercp_warnings`,
  `usercp_warnings_warning`, `usercp_warnings_warning_post`.
- **Navigation:** `usercp_nav`, `usercp_nav_home`, `usercp_nav_messenger`,
  `usercp_nav_messenger_compose`, `usercp_nav_messenger_folder`,
  `usercp_nav_messenger_tracking`, `usercp_nav_profile`,
  `usercp_nav_changename`, `usercp_nav_editsignature`, `usercp_nav_misc`,
  `usercp_nav_attachments`.
- **Profile:** `usercp_profile`, `usercp_profile_away`,
  `usercp_profile_website`, `usercp_profile_customfield`,
  `usercp_profile_customtitle`, `usercp_profile_customtitle_currentcustom`,
  `usercp_profile_customtitle_reverttitle`, `usercp_profile_day`,
  `usercp_profile_profilefields`, `usercp_profile_profilefields_checkbox`,
  `usercp_profile_profilefields_multiselect`,
  `usercp_profile_profilefields_radio`,
  `usercp_profile_profilefields_select`,
  `usercp_profile_profilefields_select_option`,
  `usercp_profile_profilefields_text`,
  `usercp_profile_profilefields_textarea`.
- **Options and credentials:** `usercp_options`, `usercp_options_invisible`,
  `usercp_options_date_format`, `usercp_options_language`,
  `usercp_options_language_option`, `usercp_options_pms`,
  `usercp_options_pms_from_buddys`, `usercp_options_pppselect`,
  `usercp_options_pppselect_option`, `usercp_options_quick_reply`,
  `usercp_options_style`, `usercp_options_time_format`,
  `usercp_options_timezone`, `usercp_options_timezone_option`,
  `usercp_options_tppselect`, `usercp_options_tppselect_option`,
  `usercp_themeselector`, `usercp_themeselector_option`, `usercp_password`,
  `usercp_email`, `usercp_changename`.
- **Avatar and signature:** `usercp_avatar`, `usercp_avatar_auto_resize_auto`,
  `usercp_avatar_auto_resize_user`, `usercp_avatar_current`,
  `usercp_avatar_remove`, `usercp_avatar_upload`, `usercp_avatar_remote`,
  `usercp_editsig`, `usercp_editsig_current`, `usercp_editsig_preview`,
  `usercp_editsig_suspended`.
- **Social:** `usercp_editlists`, `usercp_editlists_user`,
  `usercp_editlists_no_buddies`, `usercp_editlists_no_ignored`,
  `usercp_editlists_received_requests`, `usercp_editlists_received_request`,
  `usercp_editlists_sent_requests`, `usercp_editlists_sent_request`,
  `usercp_editlists_no_requests`.
- **Drafts and subscriptions:** `usercp_drafts`, `usercp_drafts_draft`,
  `usercp_drafts_draft_forum`, `usercp_drafts_draft_thread`,
  `usercp_drafts_none`, `usercp_subscriptions`,
  `usercp_subscriptions_thread`, `usercp_subscriptions_thread_icon`,
  `usercp_subscriptions_remove`, `usercp_subscriptions_none`,
  `usercp_forumsubscriptions`, `usercp_forumsubscriptions_forum`,
  `usercp_forumsubscriptions_none`, `usercp_addsubscription_thread`,
  `usercp_removesubscription_forum`, `usercp_removesubscription_thread`.
- **Groups:** `usercp_usergroups`, `usercp_usergroups_joingroup`,
  `usercp_usergroups_leader`, `usercp_usergroups_leader_usergroup`,
  `usercp_usergroups_leader_usergroup_memberlist`,
  `usercp_usergroups_leader_usergroup_moderaterequests`,
  `usercp_usergroups_memberof`, `usercp_usergroups_memberof_usergroup`,
  `usercp_usergroups_memberof_usergroup_description`,
  `usercp_usergroups_memberof_usergroup_display`,
  `usercp_usergroups_memberof_usergroup_leave`,
  `usercp_usergroups_memberof_usergroup_leaveleader`,
  `usercp_usergroups_memberof_usergroup_leaveother`,
  `usercp_usergroups_memberof_usergroup_leaveprimary`,
  `usercp_usergroups_memberof_usergroup_setdisplay`,
  `usercp_usergroups_joinable`, `usercp_usergroups_joinable_usergroup`,
  `usercp_usergroups_joinable_usergroup_description`,
  `usercp_usergroups_joinable_usergroup_join`.
- **Attachments:** `usercp_attachments`, `usercp_attachments_attachment`,
  `usercp_attachments_none`.

The `usercp.php` preload string contains the apparent typo
`usercp_editlists_userusercp_editlists`. It separately preloads/evaluates the
correct templates later, so this is not an extra template and is not a shell
contract.

## Two-level information architecture

### Level 1: global UCP navigation

Level 1 answers “which account domain am I in?” and stays stable on both
`usercp.php` and `private.php`. The proposed order is:

1. **Overview** — account summary (`usercp.php`).
2. **Profile** — personal/public identity.
3. **Preferences** — display, locale, editor and notification defaults.
4. **Security** — password, email, and username when permitted.
5. **Social** — buddies/ignore, group memberships, and plugin accounts/alerts.
6. **Subscriptions** — thread and forum subscriptions.
7. **Content** — drafts and attachments.
8. **Messages** — one entry into the separate PM workspace.

Public “View profile” is a contextual profile action, not a ninth UCP domain.
The personal notepad stays a home widget because core supplies no GET page for
it. This grouping also avoids presenting mutation handlers as destinations.

### Level 2: local section navigation

| Section | Ordered local children |
| --- | --- |
| Overview | no route tabs; summary widgets may use in-page regions |
| Profile | Edit profile; Avatar; Signature (permission gated); View public profile as an external/contextual action |
| Preferences | General preferences; AAM Alert preferences when its provider is enabled (alternatively reachable from Social / Alerts) |
| Security | Password; Email; Username (permission gated) |
| Social | Buddy & ignore; Group memberships; Alerts -> list/preferences (provider); Accounts (provider) |
| Subscriptions | Threads; Forums |
| Content | Drafts; Attachments (setting gated) |
| Messages | Compose; dynamic native folders; Tracking; Advanced search; Edit folders; Empty folders; Export messages |

On a plugin page, the plugin's registered parent determines both active Level
1 and local Level 2 placement. AAM preferences may be shown as a cross-link in
Preferences, but it has one canonical owner (`social.alerts`) so active state
is deterministic and accessibility output does not contain duplicate current
items.

## Server-rendered route registry contract

### Data shape

The registry is ordered data, not HTML. The conceptual PHP shape is:

```php
[
    'profile' => [
        'route' => ['script' => 'usercp.php', 'query' => ['action' => 'profile']],
        'label' => 'ucp_nav_profile',          // language key, not trusted HTML
        'icon' => 'user',                      // optional semantic token
        'visibility' => 'ucp.can_access',      // named resolver/callable
        'active' => ['scripts' => ['usercp.php'], 'actions' => [
            'profile', 'do_profile', 'avatar', 'do_avatar',
            'editsig', 'do_editsig',
        ]],
        'children' => [
            'edit' => [
                'route' => ['script' => 'usercp.php', 'query' => ['action' => 'profile']],
                'label' => 'ucp_nav_edit_profile',
                'visibility' => 'ucp.can_access',
                'active' => ['actions' => ['profile', 'do_profile']],
            ],
            'avatar' => [
                'route' => ['script' => 'usercp.php', 'query' => ['action' => 'avatar']],
                'label' => 'ucp_nav_change_avatar',
                'visibility' => 'core.avatar_allowed',
                'active' => ['actions' => ['avatar', 'do_avatar']],
            ],
            'signature' => [
                'route' => ['script' => 'usercp.php', 'query' => ['action' => 'editsig']],
                'label' => 'ucp_nav_edit_sig',
                'visibility' => 'core.signature_allowed',
                'active' => ['actions' => ['editsig', 'do_editsig']],
            ],
        ],
    ],
]
```

Required entry fields are stable `key`, structured `route`, `label`,
`visibility`, `active`, and ordered `children`. Optional fields are `icon`,
`badge` (data/callable, never HTML), `external`, `provider`, `weight`, and
`meta`. Route building must use MyBB's configured board URL at render time and
HTML-escape once at the output boundary.

### Native registry matrix

| Registry key | Canonical route | Visibility resolver | Active action family |
| --- | --- | --- | --- |
| `overview` | `usercp.php` | `uid && canusercp` | empty action, `do_notepad` |
| `profile.edit` | `usercp.php?action=profile` | UCP access | `profile`, `do_profile` |
| `profile.avatar` | `usercp.php?action=avatar` | core avatar capability/settings | `avatar`, `do_avatar` |
| `profile.signature` | `usercp.php?action=editsig` | the exact `usercp_menu_profile()` signature rule | `editsig`, `do_editsig` |
| `preferences.general` | `usercp.php?action=options` | UCP access | `options`, `do_options` |
| `security.password` | `usercp.php?action=password` | UCP access | `password`, `do_password` |
| `security.email` | `usercp.php?action=email` | UCP access | `email`, `do_email` |
| `security.username` | `usercp.php?action=changename` | `canchangename != 0` | `changename`, `do_changename` |
| `social.lists` | `usercp.php?action=editlists` | UCP access | `editlists`, `do_editlists`, request accept/decline/cancel |
| `social.groups` | `usercp.php?action=usergroups` | UCP access | `usergroups` including its `do` states |
| `subscriptions.threads` | `usercp.php?action=subscriptions` | UCP access | subscriptions plus thread add/remove contextual actions |
| `subscriptions.forums` | `usercp.php?action=forumsubscriptions` | UCP access | forum subscriptions plus forum add/remove contextual actions |
| `content.drafts` | `usercp.php?action=drafts` | UCP access | `drafts`, `do_drafts` |
| `content.attachments` | `usercp.php?action=attachments` | `enableattachments != 0` | `attachments`, `do_attachments` |
| `messages` | `private.php` | `enablepms && canusepms` | every `private.php` surface; local resolver chooses its child |

`removesubscription(s)` must inspect the validated `type`, `tid`, and `fid`
context to choose Threads versus Forums; ambiguous/missing context can activate
the parent Subscriptions section without guessing a child. POST handlers are
listed solely for active-state continuity. Navigation always links to the safe
GET route, never to a mutation.

### Provider API and lifecycle

The future implementation should expose a pre-render registration API such as:

```php
af_atf_ucp_register_navigation_provider([
    'provider' => 'advancedalertsandmentions',
    'key' => 'social.alerts',
    'parent' => 'social',
    'route' => ['script' => 'usercp.php', 'query' => ['action' => 'af_aam_list']],
    'label' => 'af_aam_link_alerts',
    'icon' => 'bell',
    'weight' => 30,
    'visibility' => 'af_aam_ucp_navigation_visible',
    'active' => ['scripts' => ['usercp.php'],
                 'actions' => ['af_aam_list', 'af_aam_prefs']],
    'children' => [/* list, preferences */],
]);
```

Registration must occur before `usercp_menu()`/shell composition (normally
addon bootstrap or `global_start`), while resolution/rendering occurs after
the authenticated user, group, settings and route are known. Recommended
phases are:

1. seed immutable native definitions;
2. let enabled addons register definitions through a dedicated PHP API/hook;
3. validate keys, parents, routes, labels, weights and callables; reject
   duplicate keys unless an explicit, separately authorized filter API exists;
4. resolve visibility using native permission predicates/provider callables;
5. resolve one deepest active entry from normalized `THIS_SCRIPT`, action and
   typed route context;
6. expose data plus separately rendered `ucp.global_navigation` and
   `ucp.local_navigation` slots to the future shell;
7. render semantic links server-side; never parse prior HTML.

Provider failure is fail-closed for that provider: omit its item and log the
error without weakening the destination's own authorization. Registration is
not authorization; every destination continues enforcing permission and CSRF
checks. Labels are language keys/plain text, icons are allowlisted semantic
tokens, and providers cannot submit arbitrary navigation HTML.

### Active-state rules

- Normalize absent `action` to the empty string, never to a label inferred from
  the request URL.
- Match `THIS_SCRIPT` first, then an allowlisted action set, then typed context
  when an action family is shared. Ignore unrelated query parameters.
- Exactly one deepest item is current; its ancestors are active. Render
  `aria-current="page"` only on that deepest link.
- Processing actions map back to their GET parent but are never emitted as
  links. Unknown plugin actions leave the User CP parent active until a
  provider claims them.
- PM folder active state compares the resolved integer folder id. Folder names
  are display data only. Read/send/search/tracking/folder-management actions use
  explicit rules from the PM provider.
- A provider may add a badge/count but may not use rendered visibility of a
  legacy link as its permission test.

## Shell composition boundary

The UCP shell owns only layout and the two navigation slots. Core and plugin
surfaces continue to own their forms/content. The implemented shared context is:

```php
[
    'route' => ['script' => 'usercp.php', 'action' => 'profile'],
    'uid' => 123,
    'global_navigation' => $globalNavigationHtml,
    'local_navigation' => $localNavigationHtml,
    'active_key' => 'overview',
    'title' => $pageTitle,
    'notices' => $noticeSlotHtml,
    'content' => $whitelistedNativeFragments,
    'actions' => $actionSlotHtml,
]
```

The Overview is the sole adopted UCP surface. It consumes
`ucp.global_navigation` and `ucp.local_navigation` and never renders
`{$usercpnav}`. Its account summary, activation state, reputation, referrals,
warnings, subscription/thread lists, avatar, and notepad remain core-produced.
The notepad retains its `do_notepad` POST action, `notepad` field, and
`my_post_key`; it is a widget rather than a navigation route.

The existing `pm.navigation` slot currently transports rendered legacy
`usercpnav`, folder navigation and folder options together. That is acceptable
for PM Phase 1 but is intentionally **not** the final navigation contract. A
future integration must replace only its global-menu portion with
`ucp.global_navigation`, keep PM folder/actions in `ucp.local_navigation` (or a
PM-specific local slot), and avoid double-rendering `{$usercpnav}`. The PM
content/quota/notice/pagination/actions slots and ownership ledger remain
unchanged.

AAM and AAS need explicit adoption because they exit in `usercp_start` and
render full documents themselves. Until they consume the shell, their legacy
pages and menu hooks remain supported; ATF must not capture their output and
rearrange it. AdvancedProfileFields output stays inside the profile content
boundary. No shell code may use `DOMDocument`, selectors, regex, `str_replace`,
or JavaScript to discover navigation from `usercp_nav`.

## Migration order and acceptance criteria

1. Implement/test the typed registry, native permission resolvers and active
   resolver without changing any template or output.
2. Add AAM/AAS data providers while retaining their legacy menu hooks as an
   explicit fallback; prove disabled/unauthorized providers disappear.
3. Split the existing PM navigation provider into global UCP data and local PM
   data. Do not hard-code custom folders or invent Unread.
4. Introduce shell slots/context and migrate one core read-only surface first.
   Template ownership requires a separate task and reversible ledger entry.
5. Migrate form surfaces only after preservation tests cover token, field names,
   errors, editor/autocomplete behavior, and POST-to-GET active continuity.
6. Migrate AAM/AAS full-page dispatch deliberately; only then remove their
   table/regex legacy injection.
7. Retire `usercp_nav` rendering only after all `usercp.php` and `private.php`
   surfaces, addon routes, disabled states, custom PM folders, and rollback have
   been exercised.

Acceptance requires: identical authorization; no mutation URL in navigation;
one deterministic active leaf; server-rendered global and local menus; dynamic
PM folders and addon entries supplied as data; no dependency on legacy table
markup; exact preservation of native form fields/tokens and plugin lifecycle;
and byte-exact template rollback for any later ownership task.
