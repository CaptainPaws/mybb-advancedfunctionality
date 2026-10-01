# Adaptive Theme Framework: template & plugin compatibility audit

**Target:** warprift.ru / MyBB 1.8.40 / PHP 8.5.0  
**Repository snapshot:** this document describes the code in this repository, not every
plugin which may be installed in production. **It is not evidence that any template is
safe to replace.** Before deployment, compare the production template set and installed
plugin list with this matrix.

## Scope and classification

The audit searched all AF add-ons (PHP, template bundles, JavaScript and CSS), root AF
entry points, and tests for template names, MyBB hooks, template writes, output variables,
and selectors. The 14 requested surfaces were checked: `member_profile`, `postbit`,
`postbit_classic`, `showthread`, `forumdisplay_thread`, `forumbit_depth2_forum`, `index`,
`header`, `headerinclude`, `header_welcomeblock_member`, `footer`, `private`,
`memberlist`, and the AF `userlist.php` route/template family. The related
`forumbit_depth2_forum_lastpost` is recorded because that is the template actually
patched by AdvancedPosterAvatar.

Types used below:

* **A — Variable contract:** PHP prepares a value which must remain rendered.
* **B — Template patch:** install/update code edits or replaces a stored template.
* **C — DOM dependency:** JavaScript/CSS/output rewriting expects selectors or structure.
* **D — Hook-only:** the integration executes on a MyBB hook but does not require HTML.

Risk means impact if the named dependency disappears: **Critical** (feature is absent or
template overwrite conflicts), **High** (interactive feature breaks), **Medium**
(presentation/fallback degrades), **Low** (hook or page shell only), and **None found**
(no repository evidence; not a safety assertion).

Migration values are deliberately limited to `KEEP_VARIABLE`, `ATF_SLOT`,
`COMPAT_SHIM`, `NO_CHANGE`, and `MANUAL_REVIEW`.

## Compatibility matrix

| Template | Addon | Dependency type | Exact dependency | Risk | Proposed migration |
|---|---|---|---|---|---|
| `member_profile` | AdvancedProfileUI | B | Replaces the complete non-master template from `templates/member_profile.html`, with backup/restore by `tid`; therefore another rewrite can overwrite, or be overwritten by, APUI. | Critical | MANUAL_REVIEW |
| `member_profile` | AdvancedProfileUI | A | Requires `{$af_apui_forum_info_grid}`, `{$af_apui_character_sheet_tab}`, `{$af_apui_application_tab}`, `{$af_apui_timeline_tab}`, and `{$af_apui_activity_tab}`; also retains MyBB `$profilefields`, `$contact_details`, `$signature`, `$modoptions`, `$adminoptions`, `$buddy_options`, `$ignore_options`, and `$report_options`. | Critical | ATF_SLOT |
| `member_profile` | AdvancedProfileUI | C | Tab runtime owns `[data-af-apui-tabs]`, `.af-apui-tab[data-tab]`, `.af-apui-panel[data-panel]`, matching `aria-controls`/panel IDs, and `hidden`; appearance/RWD styles also use the `af-apui-profile-*` hierarchy. | High | COMPAT_SHIM |
| `member_profile` | CharacterSheets | A | Profile sheet is supplied through APUI's `{$af_apui_character_sheet_tab}` integration; `charactersheet_*` markup is content, not a standard-template patch. | High | ATF_SLOT |
| `member_profile` | Application/Profile integration (AdvancedThreadFields + APUI) | A | Application content is supplied through `{$af_apui_application_tab}`. Preserve the uid context (`$memprofile['uid']`) used to build it. | High | ATF_SLOT |
| `member_profile` | AdvancedInventory | — | No direct profile dependency remains: the regression contract explicitly forbids the removed `af_apui_inventory_tab`; inventory is exposed inside CharacterSheets and on its standalone route instead. | None found | NO_CHANGE |
| `member_profile` | AdvancedPostCounter | A/B | Produces `$memprofile['advancedpostcounter']`; installer historically inserts/removes `{$memprofile['advancedpostcounter']}` in `member_profile` or its custom-field children. | High | ATF_SLOT |
| `member_profile` | Balance | A | `member_profile_end` prepares profile balance output; it must be exposed through the profile composition/provider boundary rather than inferred from DOM. | Medium | ATF_SLOT |
| `member_profile` | AdvancedAppearance | C | Applies uid-scoped classes/styles to `body.af-aa-user-<uid>` and APUI profile hero/background/banner/avatar selectors. | High | COMPAT_SHIM |
| `postbit` | AdvancedProfileUI | B | APUI bundle replaces only `postbit_classic`; repository has no APUI replacement of horizontal `postbit`. | Medium | MANUAL_REVIEW |
| `postbit` | AdvancedPostCounter | A/B | `postbit` hook sets `{$post['advancedpostcounter']}`; installer detects `{$post['user_details']}` and inserts the variable after it, and uninstall removes it. | High | ATF_SLOT |
| `postbit` | AdvancedAlertsAndMentions | A | `postbit`/`postbit_pm` adds the mention action to post button data. A rewritten button region must render the resulting button variable/collection. | High | ATF_SLOT |
| `postbit` | AdvancedThreadFields | D | `postbit` hook participates in thread-field rendering, but no direct dependency on standard `postbit` markup was found. | Low | NO_CHANGE |
| `postbit` | AdvancedAppearance | D | Collects post author uid at `postbit`, `postbit_prev`, and `postbit_pm`; page output styling is separate. | Low | NO_CHANGE |
| `postbit_classic` | AdvancedProfileUI | B | Replaces the entire stored template from `templates/postbit_classic.html`. | Critical | MANUAL_REVIEW |
| `postbit_classic` | AdvancedProfileUI | A | Requires `$post['af_aa_user_class']`, `$post['af_apui_presence_html']`, `$post['af_apui_profile_fields_html']`, `$post['af_apui_rail_html']`, and `$post['af_apui_plaque_html']`, plus standard message/control variables. | Critical | KEEP_VARIABLE |
| `postbit_classic` | AdvancedProfileUI | C | Stable post shell is `.post.classic > .af-apui-postbit`; author subtree includes `.af-apui-postbit-author__inner`, name, avatar, rank, profile-fields, rail and plaque nodes. | Critical | COMPAT_SHIM |
| `postbit_classic` | AdvResponsiveLayout | C | JS moves/restores children inside the APUI author subtree, mounts `.af-rwd-postbit-*` classes, and searches `.post.classic`, rail/stat/action nodes. This is the strongest old-DOM coupling found. | Critical | COMPAT_SHIM |
| `postbit_classic` | AdvancedPostCounter | A/B | Same `$post['advancedpostcounter']` contract and `$post['user_details']` patch anchor as `postbit`. APUI's replacement does not currently render that variable explicitly. | Critical | ATF_SLOT |
| `postbit_classic` | CharacterSheets | A | Plaque content reaches `$post['af_apui_plaque_html']` via APUI provider/composition; preserve plaque slot rather than plaque DOM internals. | High | ATF_SLOT |
| `postbit_classic` | AdvancedAppearance | C | User styling depends on `.af-aa-postbit-user-<uid>`/`$post['af_aa_user_class']` and `.af-apui-postbit*` surfaces. | High | COMPAT_SHIM |
| `showthread` | AdvancedProfileUI | B | Replaces the complete template from `templates/showthread.html`; retains standard quick-edit/delete globals, scripts, `#posts_container`, `#posts`, `$posts`, quick reply and moderation variables. | Critical | MANUAL_REVIEW |
| `showthread` | AdvancedThreadFields | A/C | Produces `$af_atf_showthread_block`; APUI renders it beside `<!--AF_ATF_SHOW-->`. Legacy pre-output fallback searches that marker and injects rendered HTML. | Critical | ATF_SLOT |
| `showthread` | AdvancedProfileUI | C | Breadcrumb relocation uses `<!--AF_APUI_THREAD_BREADCRUMBS_TOP-->` and `...BOTTOM`; CSS/appearance targets `.af-apui-thread-*`. | High | COMPAT_SHIM |
| `showthread` | CharacterSheets | D | `showthread_start` handles workflow/context; no direct standard `showthread` selector or patch was found. | Low | NO_CHANGE |
| `showthread` | AdvancedWanted | D | Wanted/application flows use showthread request/thread context and pre-output chip runtime, not a named standard-template anchor. | Medium | NO_CHANGE |
| `showthread` | AdvancedAppearance | C | Thread owner styling uses `body.af-aa-user-{$thread['uid']}`, `.af-apui-thread-hero` and its background/banner descendants. | High | COMPAT_SHIM |
| `forumdisplay_thread` | AdvancedPosterAvatar | B | Patch removes old markers, anchors start before `{$lastpostdate}` when the same `<td>` contains `{$lastposterlink}` (fallback: before `{$lastposterlink}`), and closes before that cell's `</td>`. | Critical | ATF_SLOT |
| `forumdisplay_thread` | AdvancedThreadFields | A | `forumdisplay_thread` hook sets `$thread['af_atf_forum_chips']`; existing compatibility code can inject at `<!--AF_ATF_FORUM_CHIPS-->`. | High | ATF_SLOT |
| `forumdisplay_thread` | AdvancedThreadFields | C | Fallback insertion is marker/output based; removing the marker and omitting the variable loses chips. Preserve one explicit thread-card metadata slot. | High | COMPAT_SHIM |
| `forumbit_depth2_forum` | Repository result | — | No direct patch, variable, selector, or hook dependency was found for the requested wrapper template. Do not confuse it with its `_lastpost` child below. | None found | MANUAL_REVIEW |
| `forumbit_depth2_forum_lastpost` | AdvancedPosterAvatar | B | Wraps the whole child template with `<!-- AVATAR START -->` / `<!-- AVATAR END -->` markers; rendered output is then transformed to include the last-poster avatar. | High | ATF_SLOT |
| `index` | AdvancedMenu | A | Global menu registry exports `$af_advancedmenu_top` and `$af_advancedmenu_panel`; pages depend on the header-owned menu containers, not on an `index` patch. | Medium | ATF_SLOT |
| `index` | IndexRedirect | D | Index routing is hook/request behavior; no repository evidence of a stored `index` template patch. | Low | NO_CHANGE |
| `index` | FastNews / AdvancedStatistic | D | Index-facing data/hooks exist, but no direct dependency on the requested `index` template/DOM was found. | Low | NO_CHANGE |
| `header` | AdvancedMenu | A/C | Menu content is `$af_advancedmenu_top` / `$af_advancedmenu_panel`; JS expects its own `.af-advancedmenu-*` containers and drawer controls to remain present in the rendered header. | Critical | ATF_SLOT |
| `header` | AdvancedAccountSwitcher | C | Pre-output rewrites every rendered `<a href="...memberlist.php...">` to `userlist.php`; this is output-wide but normally affects header navigation. | High | COMPAT_SHIM |
| `header` | Custom AF pages | A | AAS, Inventory, Shop, KB, CharacterSheets, Appearance, PostCounter and Rules pages evaluate/use the theme `$header`; preserve the conventional page-shell contract (`$headerinclude`, `$header`, `$footer`). | Medium | KEEP_VARIABLE |
| `headerinclude` | AdvancedFontAwesome | B | Removes its marker block, then inserts the marked Font Awesome asset block immediately before `{$stylesheets}`. | Critical | ATF_SLOT |
| `headerinclude` | AdvancedJSBandle | B | Inserts its marked bundle block after `{$stylesheets}` and removes it by marker regex on uninstall. (`advancedjsbandle` is the repository spelling.) | Critical | ATF_SLOT |
| `headerinclude` | AdvancedAlertsAndMentions | A/B | Installer appends `{$af_aam_js}{$af_aam_css}` at end; uninstall removes the exact adjacent pair. | High | ATF_SLOT |
| `headerinclude` | AF asset-producing addons | A | Many add-ons append asset tags to the runtime `$headerinclude` (APUI, Appearance, RWD, Menu, AccountSwitcher, Inventory, Shop, KB, Wanted, CharacterSheets, Editor). Any page shell must emit it exactly once. | Critical | KEEP_VARIABLE |
| `header_welcomeblock_member` | AdvancedAlertsAndMentions | A/B | Inserts `{$af_aam_header_icon}` immediately before `{$modcplink}`; exact anchor and variable are required by install/uninstall. | Critical | ATF_SLOT |
| `header_welcomeblock_member` | AdvancedAccountSwitcher | A/B | Direct DB updater inserts/removes marked `{$af_aas_header_button}` and builds it before header rendering. Anchor priority is before `{$af_aam_header_icon}`, after `{$usercplink}`, before `</ul>`, then template end. | Critical | ATF_SLOT |
| `footer` | AdvancedAlertsAndMentions | A/B | Appends `{$af_aam_modal}` at template end; modal JS expects the add-on's own modal IDs/classes. | High | ATF_SLOT |
| `footer` | Custom AF pages | A | Same conventional page-shell contract: custom routes evaluate/use `$footer`. No other direct footer patch was found. | Medium | KEEP_VARIABLE |
| `private` | AdvancedAccountSwitcher | D | `private_send_end` notification behavior has no dependency on `private` template HTML. | Low | NO_CHANGE |
| `private` | SmartURLTitles | D | PM send/preview hooks alter message processing, not `private` markup. | Low | NO_CHANGE |
| `private` | AdvResponsiveLayout | C | Adds page class `af-rwd-private`; CSS/JS targets `body.af-rwd-script-private table.pm_table` and responsive table structure. | High | COMPAT_SHIM |
| `memberlist` | AdvancedAccountSwitcher | C | Pre-output regex rewrites anchors whose `href` contains `memberlist.php` to the AF `userlist.php` URL. This depends on an actual `<a href>` rather than a variable contract. | High | COMPAT_SHIM |
| `memberlist` | Repository result | — | No AF add-on patches the stored MyBB `memberlist` template itself; several custom pages only reuse its permission/language semantics. | None found | MANUAL_REVIEW |
| `userlist.php` family | AdvancedAccountSwitcher | A/C | Installs a root alias, renders a custom page containing `.userlist` and its own table/card controls; JS detects pathname `userlist.php`, finds user rows/cells/images, and overlays online state. | Critical | COMPAT_SHIM |
| `userlist.php` family | AdvResponsiveLayout | C | Adds `af-rwd-userlist`/`af-rwd-script-userlist` body classes and treats tables/cards as responsive collections. | High | COMPAT_SHIM |

## Required preservation checklist by template

This is the deploy-time reading order; it does not replace the detail in the matrix.

* **`member_profile`:** do not activate APUI's full replacement concurrently with a new
  ATF owner without deciding precedence. Provide named profile slots for forum info,
  character sheet, application, timeline, activity, inventory and post counter. Preserve
  uid context. Keep the APUI tab DOM temporarily or replace its JS in the same release.
* **`postbit` / `postbit_classic`:** provide author metadata, rail/action, plaque and
  post-counter slots. Preserve MyBB post IDs and message/control variables. Classic mode
  needs an RWD compatibility layer until `advresponsivelayout.js` stops moving APUI nodes.
* **`showthread`:** preserve `$af_atf_showthread_block`, `$posts`, `#posts`,
  `#posts_container`, quick-reply/moderation variables and MyBB thread JS globals. Treat
  breadcrumb markers as legacy shims, not future API.
* **`forumdisplay_thread`:** expose explicit `thread_meta` (ATF chips) and
  `lastposter_avatar` slots. Do not retain the fragile same-`td` regex as the final API.
* **`forumbit_depth2_forum`:** no direct evidence was found, but audit its
  `_lastpost` child before changing composition.
* **`index`:** no stored-template patch was found; header menu and index hook behavior
  remain independently required.
* **`header`:** render menu/drawer slots and keep the page-shell variable contract.
* **`headerinclude`:** emit runtime `$headerinclude` once and retain `{$stylesheets}` until
  FontAwesome/JSBandle patches have migrated to an asset slot.
* **`header_welcomeblock_member`:** expose account-switcher and alerts action slots; during
  transition retain `$modcplink` or prevent legacy installers from re-patching.
* **`footer`:** render the alerts modal slot and runtime `$footer` once.
* **`private`:** preserve `.pm_table` through the RWD transition; PHP hooks are DOM-free.
* **`memberlist` / `userlist`:** provide a navigation URL contract instead of regex
  rewriting, but retain the custom user-list selectors until its JS is migrated.

## Findings and migration order

There are **53 integration records across 14 requested surfaces**, plus the necessary
`forumbit_depth2_forum_lastpost` child (15 audited surfaces total), representing **20 named addon/integration
owners or grouped AF page-shell consumers**. Counts describe matrix rows, not installed
production instances.

The highest-risk templates are `postbit_classic`, `member_profile`, `showthread`,
`headerinclude`, and `header_welcomeblock_member`: the first three are wholly replaced by
AdvancedProfileUI; the latter two are installation-time patch targets with fragile
anchors. `forumdisplay_thread` is also high risk because AdvancedPosterAvatar parses a
specific table cell and variable ordering.

The add-ons most coupled to old DOM are, in order:

1. **AdvResponsiveLayout** — moves and restores a detailed APUI classic-postbit tree and
   inspects PM/user-list tables.
2. **AdvancedProfileUI** — owns three complete templates and establishes the DOM consumed
   by Appearance and RWD.
3. **AdvancedPosterAvatar** — patches/recognises last-post table-cell structure.
4. **AdvancedAccountSwitcher** — rewrites `memberlist.php` anchors and inspects custom
   user-list row/image structure.
5. **AdvancedAppearance** — selector-heavy, uid-scoped styling of APUI profile/thread/
   postbit surfaces.

Recommended order: introduce server-rendered ATF slots first; dual-render legacy variables
and marker shims; migrate RWD/menu/user-list JS to slot-owned roots; stop install-time
patching; only then change full templates. Hook-only integrations need no HTML migration,
but must remain registered and receive the same request/data context.

## Repository evidence map

The following locations are the primary evidence behind the matrix (line numbers are a
navigation aid for this snapshot; search the adjacent symbol after later edits):

| Concern | Repository evidence |
|---|---|
| APUI template ownership/backup | `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:2175-2288` |
| APUI profile variable production | `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:1271-1296` |
| APUI postbit variable production | `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:1152-1189` |
| Actual profile contract/DOM | `inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/member_profile.html` |
| Actual classic-post contract/DOM | `inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/postbit_classic.html` |
| Actual thread contract/DOM | `inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/showthread.html` |
| RWD DOM moves/selectors | `inc/plugins/advancedfunctionality/addons/advresponsivelayout/assets/advresponsivelayout.js:41-216`, `:761-805` |
| AAS welcome-block patch | `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:3001-3130` |
| AAS member-list rewrite/user-list DOM | `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:415-444`, `:2946-2990`; `assets/advancedaccountswitcher.js:250-365` |
| Alerts template patches | `inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:907-922` |
| Font Awesome header patch | `inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php:199-216` |
| JS bundle header patch | `inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:175-187`, `:290-305` |
| PostCounter profile/postbit patches | `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1408-1445`, `:1451-1461` |
| PosterAvatar cell/template patches | `inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:462-539` |
| ATF showthread runtime fallback | `inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:590-622` |
| ATF forum chips production/patch | `inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:6124-6180`, `:7088-7130` |
| CharacterSheets thread hooks | `inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:60-75` |
| Inventory profile-tab removal contract | `tests/member_profile_inventory_tab_regression.php:24-37` |
| Appearance profile/post hooks | `inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php:185-214` |
| SmartURLTitles PM hooks | `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:20-40` |

The search also covered addon-owned template bundles. Those templates' use of
`{$headerinclude}`, `{$header}`, and `{$footer}` supports the grouped custom-page shell
record; it does **not** imply that they patch the three standard templates.

## Evidence boundaries / production verification

The repository contains no export of the live MyBB `templates` table and no inventory of
third-party production plugins. Consequently, **“None found” means only that this checkout
contains no matching evidence**. Before any template change:

1. export all live templates named in this document (including inherited theme variants);
2. search them for `af_`, `<!--AF_`, `{$post[...]}`, and non-AF plugin variables;
3. inventory active plugins and repeat the patch/hook/selector search outside this repo;
4. compare APUI's backup table/checksums with current template rows;
5. test both classic and horizontal post layout, guest/member/moderator states, PM pages,
   mobile layout, and custom `userlist.php` routes.
