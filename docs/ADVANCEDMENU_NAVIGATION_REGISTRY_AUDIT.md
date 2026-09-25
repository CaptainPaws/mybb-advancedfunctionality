# AdvancedMenu stage 1: navigation audit and system registry

This is a source audit of the repository snapshot. Theme HTML stored in a live MyBB database is not in Git, so theme-only labels and the exact `footer` upper-link markup must be verified against the production templates before stage 2.

## Existing menu surfaces

| Surface | Owner / construction | Hook or template | Current injection and behaviour |
|---|---|---|---|
| top links | MyBB theme, subsequently AdvancedMenu | `header` contains `ul.menu.top_links`; AF `pre_output_page` | AdvancedMenu parses the completed page and appends/replaces `<li>` elements. DB configured items use `af_advancedmenu_item`. |
| panel links | MyBB member welcome block plus addons | `header_welcomeblock_member`, AF `pre_output_page` | MyBB supplies Mod CP/Admin CP and member actions. AdvancedMenu mutates `ul.menu.panel_links`; protected substring rules preserve AAS/AAM and CP links in replace mode. |
| user links | MyBB member welcome block | `header_welcomeblock_member` | `$buddylink`, `$searchlink`, `$pmslink` form `ul.menu.user_links`. AdvancedMenu currently only hides/strips existing items. |
| footer upper links | MyBB theme | `footer` (usually `ul.menu.bottom_links`/theme-specific) | No canonical markup exists in this repository. AdvancedMenu can address an explicitly configured extra `<ul>` class, but does not own this block. |
| welcome avatar | HeaderWelcomeAvatar | AF `pre_output_page`; completed welcome `<span>` from guest/member welcome template | Finds the rendered welcome span, wraps it in `.af-hw-wrap`, adds profile/login avatar, moves login/register/logout actions, and loads its own assets. This is layout decoration, not a menu item. |

## Item inventory

| Item / stable key | Owner | Hook/template and current HTML/selector | Action / handler | Visibility and current destination |
|---|---|---|---|---|
| Account Switcher / `advanced_account_switcher` | AdvancedAccountSwitcher | `global_start` prepares `af_aas_header_button`; activation patches `header_welcomeblock_member`; template `af_aas_panel_widget`; trigger `#af_aas_trigger.af-aas-trigger` | Owner JS opens its `#af_aas_panel`; switching remains `misc.php?action=af_aas_switch` with CSRF key | Member only; addon enabled; directly in the welcome/panel area. Registry contains trigger metadata, never modal HTML or switch logic. |
| Alerts / `advanced_alerts` | AdvancedAlertsAndMentions | bootstrap renders `af_aam_header_icon` (`li.alerts`, `#af_aam_header_link`) and `af_aam_modal`; install patches `header_welcomeblock_member`, `headerinclude`, and `footer`; pre-output has safe fallbacks | `onclick="return false"`; owner JS opens `#af_aam_modal`; fallback/list URL `misc.php?action=af_aam_list` | Member only and addon enabled; currently before `$modcplink`. Owner badge callback exposes unread count. |
| Friends / `friends` | MyBB trigger + AdvancedBuddyList modal body | `$buddylink` in `header_welcomeblock_member`; owner replaces `misc_buddypopup`; selector `a[href*="action=buddypopup"]` | `MyBB.popupWindow` requests `misc.php?action=buddypopup&modal=1`; owner hook `misc_start` provides tabs/assets/data | Member only; `user_links`. AdvancedMenu stores only trigger contract; MyBB/owner retains popup logic. |
| Characters / `characters` | AdvancedCharacters | AF `pre_output_page`; injected HTML is `<li class="af-characters-mod-link" data-af-characters-mod-link="1"><a href="characters.php">Персонажи</a></li>` | ordinary `characters.php` link, which delegates page rendering to KnowledgeBase | Current direct injection is staff only and immediately follows Mod CP. Registry describes an ordinary system link and preserves the same staff visibility callback. |
| Mod CP / `modcp` | MyBB | `$modcplink`, `header_welcomeblock_member` | `modcp.php` | `canmodcp`, super moderator, or moderator; panel; AdvancedMenu currently protects it. |
| Admin CP / `admincp` | MyBB | `$admincplink`, `header_welcomeblock_member` | `admin/index.php` | `cancp`; panel; AdvancedMenu currently protects it. |
| New Posts / `new_posts` | MyBB | `$searchlink` or theme link in member welcome/header | `search.php?action=getnew` | member; normally panel/user links (theme dependent). |
| Post activity / `post_activity` | AdvancedPostCounter | no direct navigation injection found; page templates `advancedpostcounter_postsactivity` / `af_apc_postsactivity_page` | `postsactivity.php` alias | public page; registry defaults to top links. |
| Presets / `presets` | AdvancedAppearance | no direct navigation injection found; owner front controller/page | `apstudio.php` | registry limits to `cancp`; defaults to top links. |
| Fitting room / `fitting_room` | AdvancedAppearance | no direct navigation injection found; owner front controller/page | `fittingroom.php` | public; defaults to top links. |

Other AF UI links found are contextual (User CP entries, profile tabs, inventory/shop/KB pages, editor controls and moderation actions), rather than global navigation. They are intentionally not invented as system registry entries in stage 1.

## Registry/provider contract

`af_menu_register_item(array $item)` validates a stable key and one of `link`, `modal`, or `action/system`. Every normalized record contains `source_addon`, `label`, `icon`, `default_container`, `default_sortorder`, `visibility`, `action`, optional `badge_provider`, and optional `renderer`. Owner bootstraps expose convention-based `af_<addon>_menu_provider()` functions. AdvancedMenu discovers those after all enabled AF bootstraps have loaded, and owners call the registration helper themselves.

`action` is runtime metadata: a URL and/or trigger, modal, handler, and owner-template identifiers. Callbacks and modal markup are never persisted in `af_advancedmenu_items`. `af_menu_item_is_visible()` and `af_menu_item_badge()` resolve owner callbacks at request time. In particular, AAM returns its already-calculated unread count; AdvancedMenu neither queries alert tables nor implements alert behaviour.

The registry is deliberately parallel and runtime-only. `af_advancedmenu_build_menu_html()` still consumes only existing DB custom items, and every old template patch/pre-output injection remains active. Thus this stage produces no page markup change.

## Stage 2 removal/migration checklist

Only after registry rendering has parity tests, disable: AAS's `header_welcomeblock_member` placeholder and pre-output fallback; AAM's header-icon patch/fallback (retain footer modal and assets); the MyBB `$buddylink` trigger only if replaced with an equivalent popup trigger (retain `misc_buddypopup`); AdvancedCharacters' regex insertion; duplicate theme links for CP/New Posts; and any production-theme hard-coded links for Post activity, Presets, Fitting room or Characters. HeaderWelcomeAvatar must be adapted to the final welcome markup rather than removed. AdvancedMenu legacy DB injection/hide/protect modes require a separate migration plan.
