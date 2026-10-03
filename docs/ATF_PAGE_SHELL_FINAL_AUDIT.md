# ATF page shell final audit

Task 52 audits the full-page frontend surfaces owned by Adaptive Theme
Framework (ATF). It is a structural audit only: cards, forms, typography and
surface-specific composition remain unchanged.

## Canonical contract

An ATF-owned full document has exactly one primary page container:

```html
<main class="pun atf-page-shell atf-page atf-SURFACE">…</main>
```

`body.atf-active .pun.atf-page-shell` is the only page-width and horizontal
gutter authority. Its width is `100%`, its maximum is
`--atf-page-max-width` (an alias of `--atf-content-max-width`), and its inline
padding is `--atf-page-gap`. It also explicitly neutralizes legacy `.pun`
background, border, radius, shadow, fixed/minimum width and margin. The
surface roots use only block padding, layout and component gaps; none sets a
competing root width or inline gutter.

The responsive-layout addon has intentional `!important` rules for legacy
`#content` and `.pun` containers. When both addons are active, a narrowly
scoped ATF rule reasserts the canonical shell geometry through the tablet and
mobile ranges. The existing ATF breakpoints reduce `--atf-page-gap`; they do
not introduce a second gutter source. ATF-off documents do not match any of
these rules.

## Owned full-page inventory

In the table, **canonical** means the shell above, **page variable** means
`--atf-page-max-width`, and **page gap** means `--atf-page-gap` on the shell.
Grouped rows share byte-equivalent root structure; child templates are not
page containers.

| surface | root/template | shell | width source | horizontal gutter | notes |
|---|---|---|---|---|---|
| Index | `templates/index.html` | canonical + `atf-index` | page variable | page gap | One shell surrounds hero and all forum categories. |
| Forum display | `templates/forumdisplay.html` | canonical + `atf-forumdisplay` | page variable | page gap | One shell surrounds breadcrumb, current-forum category, subforums and topic list; category/list templates are inner components. |
| Thread | `templates/showthread.html` | canonical + `atf-thread` | page variable | page gap | Posts and controls remain inside the one root. |
| Member profile | `templates/member_profile.html` | canonical + `atf-profile` | page variable | page gap | The appearance UID class is an additional context class, not a container. |
| Stock member list | `templates/memberlist.html` | canonical + `atf-userlist` | page variable | page gap | User cards and pagination are inner content. |
| Stock member search | `templates/memberlist_search.html` | canonical + `atf-userlist` | page variable | page gap | Search is a separate full-document root and has one shell. |
| AAS user list | `af_adaptivethemeframework_render_userlist()` | canonical + `atf-userlist` | page variable | page gap | Renderer owns the full surface and creates exactly one shell; it is not inserted into another ATF root. |
| UCP overview | `templates/usercp.html` | canonical + `atf-ucp` | page variable | page gap | Navigation and dashboard widgets are inner participants. |
| UCP profile, avatar, signature, options, password, email, username | `usercp_profile`, `usercp_avatar`, `usercp_editsig` (including suspended), `usercp_options`, `usercp_password`, `usercp_email`, `usercp_changename` | canonical + `atf-ucp atf-ucp-form` | page variable | page gap | Form styling does not change page geometry. |
| UCP buddy/ignore | `templates/usercp_editlists.html` | canonical + `atf-ucp atf-social` | page variable | page gap | Social lists remain inner components. |
| UCP user groups | `usercp_usergroups`, `usercp_usergroups_joingroup` | canonical + `atf-ucp atf-social` | page variable | page gap | Both list and join-request documents comply. |
| UCP thread subscriptions | `usercp_subscriptions`, `usercp_addsubscription_thread`, `usercp_removesubscription_thread` | canonical + `atf-ucp` | page variable | page gap | The additional `atf-subscriptions` class is layout-neutral. |
| UCP forum subscriptions | `usercp_forumsubscriptions`, `usercp_removesubscription_forum` | canonical + `atf-ucp` | page variable | page gap | The additional `atf-subscriptions` class is layout-neutral. |
| UCP drafts and attachments | `usercp_drafts`, `usercp_attachments` | canonical + `atf-ucp atf-content` | page variable | page gap | Collections and statistics cards stay inside the root. |
| PM inbox/list | `templates/private.html` | canonical + `atf-pm` | page variable | page gap | Folder navigation and message collection are inner components. |
| PM read and send | `private_read`, `private_send` | canonical + `atf-pm` | page variable | page gap | Additional `atf-pm-read`/`atf-pm-compose` classes do not set root width. |
| PM search and results | `private_advanced_search`, `private_search_results` | canonical + `atf-pm` | page variable | page gap | Both documents have one root. |
| PM tracking, folders, empty, archive | `private_tracking`, `private_folders`, `private_empty`, `private_archive` | canonical + `atf-pm` | page variable | page gap | All PM-owned full-page variants comply. |
| ModCP overview | `templates/modcp.html` | canonical + `atf-modcp` | page variable | page gap | One workspace root. |
| ModCP reports (open/all) | `modcp_reports`, `modcp_reports_allreports` | canonical + `atf-modcp` | page variable | page gap | Two owned full-document roots. |
| ModCP moderation queue (threads/posts/attachments/empty) | `modcp_modqueue_threads`, `modcp_modqueue_posts`, `modcp_modqueue_attachments`, `modcp_modqueue_empty` | canonical + `atf-modcp` | page variable | page gap | Wide queue content scrolls in `.atf-modcp__content`, not at page level. |
| ModCP users (find/edit) | `modcp_finduser`, `modcp_editprofile` | canonical + `atf-modcp` | page variable | page gap | Shared UCP fragments remain components and add no shell. |
| ModCP warning log and IP search | `modcp_warninglogs`, `modcp_ipsearch` | canonical + `atf-modcp` | page variable | page gap | Results are inner templates. |
| ModCP bans (list/add-or-edit) | `modcp_banning`, `modcp_banuser` | canonical + `atf-modcp` | page variable | page gap | Action variants resolve through the same roots. |
| ModCP announcements (list/new/edit/delete) | `modcp_announcements`, `modcp_announcements_new`, `modcp_announcements_edit`, `modcp_announcements_delete` | canonical + `atf-modcp` | page variable | page gap | Four owned full-document roots. |
| ModCP logs | `templates/modcp_modlogs.html` | canonical + `atf-modcp` | page variable | page gap | Log rows and pagination are inner templates. |
| Alerts/mentions UCP pages | `advancedalertsandmentions/templates/advancedalertsandmentions.html` (`af_aam_list`, `af_aam_prefs`) | canonical + `atf-ucp atf-social` | page variable | page gap | Existing standalone pages already opted into the contract. |
| Account switcher UCP page | `advancedaccountswitcher/templates/advancedaccountswitcher.html` | canonical + `atf-ucp atf-social` | page variable | page gap | Existing standalone page already opted into the contract. |

## Invariant and conflict findings

- Every owned root above contains one `atf-page-shell`, and every occurrence
  also has `pun`, `atf-page`, and a surface class.
- No owned root contains `.pun > .pun`; page shells do not wrap `$header` or
  `$footer`, and child templates contain no page shell.
- No `.atf-index`, `.atf-forumdisplay`, `.atf-thread`, `.atf-profile`,
  `.atf-userlist`, `.atf-ucp`, `.atf-pm` or `.atf-modcp` root rule establishes
  a competing `max-width` or horizontal padding.
- The only discovered competing page geometry was the responsive-layout
  addon's generic `#content`/`.pun` tablet and mobile rule. The ATF-only
  override now keeps the page gap canonical without changing legacy pages.
- The shell is transparent and undecorated. Backgrounds, borders, radii and
  shadows remain on semantic cards, panels and sections.
- Direct shell children are shrinkable. Known wide thread controls and ModCP
  content constrain or scroll locally, so the page itself has no horizontal
  overflow requirement.

## Deliberately outside ATF

Repository standalone full-document templates for Advanced Appearance
(studio/fitting room), Advanced Gallery, Advanced Inventory (abilities and
inventory), Advanced Post Counter, Advanced Shop, Balance, Character Sheets,
and Knowledge Base do not currently use `atf-page-shell`. Advanced Profile UI
also retains its alternate profile/thread documents. They were not migrated
or restyled by this audit. Their existing addon-specific containers and ATF-off
presentation remain unchanged.

## Lifecycle result

The shell and responsive normalization are gated by `body.atf-active`; the
owned templates continue to be installed/released through ATF's existing
template ownership lifecycle. Consequently enabling ATF yields one common
page container, while disabling or releasing ATF restores legacy template and
layout ownership without a global `.pun` change.
