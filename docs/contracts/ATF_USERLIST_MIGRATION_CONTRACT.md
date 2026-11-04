# Adaptive Theme Framework — member list / user list migration contract

## Scope and evidence

This is the preparation contract for the next ATF presentation task. It does
not lease or replace a member-list template, change a query, or change AAS
privacy. The repository audit covered all PHP, template, JavaScript and CSS
sources (including direct template writes and `pre_output_page`). Stock facts
were checked against the MyBB 1.8.40 `memberlist.php` and
`install/resources/mybb_theme.xml` at tag `mybb_1840`.

## 1. The two real surfaces

They are **not aliases for the same renderer**.

| Surface | Entry and gate | Renderer/templates | Current user-facing status |
|---|---|---|---|
| Stock MyBB | `memberlist.php`; `enablememberlist`, then `canviewmemberlist`; hooks `memberlist_start`, `memberlist_search`, and per-row `memberlist_user` | MyBB's `memberlist`, `memberlist_search`, `memberlist_user`, `memberlist_user_avatar`, `memberlist_user_groupimage`, `memberlist_user_userstar`, `memberlist_error`, `memberlist_orderarrow`, `memberlist_referrals`, `memberlist_referrals_bit`, `memberlist_referrals_option`, and dormant `memberlist_search_contact_field`, plus `multipage*` | Still directly reachable, but it is not the normal navigation destination while AAS is enabled. AAS rewrites every rendered anchor whose href contains `memberlist.php` to the absolute `userlist.php` URL. Direct requests are not redirected and still execute stock MyBB. |
| AAS custom list | Root `userlist.php`, installed from `advancedaccountswitcher/assets/userlist.php`; compatibility fallback `misc.php?action=af_aas_account_list`; both call `af_aas_render_account_list_page()` and require AAS enabled plus `canviewmemberlist` | No `memberlist*` or private `userlist*` row template. AAS constructs the complete controls, table rows, and document in PHP and only evaluates shared `headerinclude`, `header`, and `footer`. Root alias installation refuses to overwrite a foreign `userlist.php`. | **This is the route users normally receive today.** The rewritten header links and AAS footer account-list link point to `/userlist.php`. The `misc.php` route remains a real fallback/legacy alias, not the canonical URL. |

There is no AF controller route that makes stock `memberlist.php` and the AAS
list equivalent. Future ownership must therefore name the surface explicitly:
`stock_memberlist` versus `aas_userlist`.

## 2. Repository-wide addon dependency audit

| Addon | Actual interaction | Migration rule |
|---|---|---|
| **AdvancedAccountSwitcher (AAS)** | Owns the custom route, query, privacy/linkage data, markup, assets and output-wide navigation rewrite. Its JS scrapes profile links and avatar-bearing table cells, fetches and parses `online.php`, and creates `.af-ul-*` presence DOM. Its CSS uses `td:has(.af-ul-avatarwrap)`. | Remains route/data/business owner. Replace the rewrite with an explicit navigation URL provider and replace DOM-derived presence with server data. ATF must not reproduce linkage/privacy rules. |
| **AdvancedMenu** | Can remove top-link `<li>` elements by configured substring; its setting documentation names `memberlist.php`. It does not render/filter list rows. | Give it the final registered navigation URL; do not make a card depend on a top-link DOM node. Existing administrator substring rules are legacy compatibility. |
| **Adaptive Responsive Layout** (deprecated) | Detects only `userlist.php`, adds `af-rwd-userlist` / `af-rwd-script-userlist`, globally selects tables, hides heads, turns `tr`/`td` into blocks, makes fields fluid, and JS adds `.af-rwd-mobile-cards`. It has no `memberlist.php` alias in its route map. | Not a compatibility target. Preserve only the UX outcome: real responsive cards, wrapped controls, fluid inputs and no horizontal table scroll. Copy none of its selectors or mutation JS. |
| **AdvancedAppearance** | No member/user-list hook, patch or selector was found. Its uid-scoped and APUI avatar/presence styling belongs to profile/post surfaces. | A future card may expose stable `data-uid`, group data, and the normal formatted username. Do not import APUI markup/classes or infer Appearance from the old DOM. |
| **AdvancedProfileUI** | No list route hook, template patch, JS or CSS selector was found. | No dependency. In particular, cards must not read APUI presence DOM. |
| **Advanced Buddy List** (`advancedbuddylist`) | No member/user-list integration was found. Buddy management remains in UCP. | Do not add buddy controls. |
| **HeaderWelcomeAvatar** | No list integration was found. | Not an avatar provider for this surface. |
| **AdvancedCharacters / AdvancedProfileFields** | No list integration was found. Stock MyBB joins the whole `userfields` row but the stock 1.8.40 row template does not display it; AAS does not join `userfields`. | Do not expose character/profile metadata or custom fields merely because stock SQL fetched them. A provider may later opt into `userlist.card.meta` only with an audited, already-batched source. |
| **FakeOnline** | No member/user-list hook/provider was found. | It is not a current list presence source and must not be guessed into ATF. |
| **Other AF addons** | No list markup dependency was found. Several addons merely include `userlist.php` in an asset blacklist (AdvancedEditor, AdvancedThreadFields, AdvancedShop, AdvancedGallery, Balance, PostCounter and CharacterSheets defaults). | A blacklist is negative asset configuration, not a list provider. Preserve route identity so those assets remain absent. |

No addon in this repository calls `find_replace_templatesets` or directly
updates a `memberlist*` template. The material legacy dependencies are AAS
`pre_output_page`, AAS's hand-built HTML/DOM scraper, and deprecated responsive
table transformation.

## 3. AAS business and privacy contract

AAS is authoritative for all of the following:

1. Installation/removal/self-healing of the marked root alias and the
   `misc.php?action=af_aas_account_list` fallback.
2. The member-list permission check, filtering of groups whose
   `showmemberlist` is false, pagination and query construction.
3. The `af_aas_links` relationship. For an attached account the query selects
   one master with `MIN(master_uid)`; the current page does **not** collapse or
   remove linked accounts from the result set.
4. `users.af_aas_hide_in_list`. Despite the UCP label, current implementation
   uses it only to suppress the master-account cell when either the displayed
   user or master has opted out. It does not hide the user row. That exact
   behavior, including the absence-of-column fallback to zero, must remain.
5. Profile URLs for both displayed and master accounts. The list exposes no
   account-switch button/control per row.

The future ATF adapter must consume an AAS-produced page/row DTO. It must not
query the link table, reinterpret master/linked status, or apply
`af_aas_hide_in_list` itself. AAS should eventually emit its navigation target
through the header/navigation contract, then retire the global anchor regex.

## 4. Card data contract

### AAS user list (the migration target used by visitors)

The no-new-query row contract is:

```text
uid, username_raw, profile_url,
avatar { html or url/dimensions from the shared avatar service },
registered_at, registered_relative,
last_active_at, last_active_relative,
post_count, thread_count,
master { visible, uid, username_raw, profile_url },
presence { state, can_disclose }
```

The existing query already selects `uid`, `username`, `regdate`, `lastactive`,
`postnum`, `threadnum`, `reputation`, `avatar`, `avatardimensions`, `invisible`
and the AAS linkage/privacy columns. The current output actually displays the
avatar, unformatted escaped username/profile link, registration, last activity,
post count, thread count, and optional master link. Although reputation is
selected and is a sort choice, it is **not rendered**; retain it as sort input,
not mandatory card markup.

AAS does not currently select `usertitle`, `usergroup`, `displaygroup`, group
image/stars, `lastvisit`, or custom profile/character fields. These are not
part of the first AAS-card contract. Adding them would change the query and
presentation and is outside this task.

### Stock member list (separate compatibility surface)

MyBB's existing `u.*, f.*` result makes `uid`, formatted username/profile link,
avatar, primary/display group, resolved user title, group image/stars,
registration, last-seen (`max(lastactive,lastvisit)` with invisible permission
handling), post/thread/referral counts and all userfields available in the
render loop. The stock row displays identity/avatar, user title/group image and
stars, registration, posts, threads, last visit, and optional referrals. It
does not display online state, reputation, custom fields, PM, buddy, or
moderator actions. A later stock migration should preserve those displayed
fields and `memberlist_user` hook semantics, but must not treat every selected
`f.*` column as a presentation requirement.

No new PM, buddy, switch-account, linked-account management, or moderator
action is authorized for either card. The only current actions are profile
links and, on AAS, the privacy-approved master profile link.

## 5. Avatar contract

The shared provider is `af_avatar_render()` from AdvancedPosterAvatar. ATF
already uses it for PM cards after one batched user query. User-list migration
must pass the users already present in the list result to that renderer (or a
batch adapter around it), use the normal default/letter fallback policy, and
must not call `get_user()` per card. AAS's present fallback
`af_aas_get_avatar_url($uid)` can perform per-user work and is not the future
contract. Stock `format_avatar()` output remains valid in legacy mode.

## 6. Search, sort, filter and pagination contracts

Presentation must preserve names and values exactly.

| Contract | Stock `memberlist.php` | AAS `userlist.php` |
|---|---|---|
| Search page | `action=search` displays a separate POST form | Inline GET form; no action mode |
| Username | `username`; `username_match=begins|contains|exact` | Same names/values |
| Other search | `website` | None |
| Letter | `letter=A..Z` or `-1` | Same (AAS validates A–Z explicitly) |
| Sort | `sort=username|regdate|lastvisit|postnum|threadnum|reputation|referrals`; referrals is effective only when enabled | Same accepted backend set, including conditional referrals; UI offers username, regdate, lastvisit, postnum, threadnum, reputation |
| Direction | `order=ascending|descending` | Same |
| Page size/page | `perpage` (1–500), `page` | Same |
| User group | No user-selectable group field. Both only exclude every primary/additional group with `showmemberlist=0`. | Same policy, independently implemented by AAS |

Stock's unused `memberlist_search_contact_field` is not evidence of active
custom-profile filters. AAS's filter and pagination URLs are canonical
`userlist.php`; the `misc.php` fallback should redirect or generate canonical
URLs rather than creating a second query contract.

## 7. Presence and appearance

Stock memberlist has no online badge. Its only activity policy is the native
last-seen calculation and `canviewwolinvis` disclosure rule. AAS currently
ignores its selected `invisible` value for rows: browser JS downloads
`online.php`, scrapes visible profile links, and labels every matched uid online
and every other uid offline. It never produces the advertised invisible state.
That is both inaccurate and a DOM/network dependency.

Before ATF renders presence, AAS (or a dedicated server-side presence
provider) must batch-resolve the page's uids against MyBB sessions/online data,
apply native invisible permissions, and return a closed state such as
`online|offline|hidden` plus `can_disclose`. FakeOnline and APUI are not fallback
sources unless they later register an explicit provider. ATF only renders the
provider result.

Cards should expose stable semantic data/classes (`data-uid`, display-group id
when genuinely available, and a provider-supplied presence modifier). Username
styling must come from `format_name()`/formatted username when that surface
provides it; it must not be reconstructed from APUI/Appearance selectors. The
initial AAS contract intentionally retains its current plain username rather
than silently introducing group styling.

## 8. Slots and ownership for the next task

The public slot allowlist now reserves only four generic extension points:

* `userlist.before_list` — provider content before the result collection;
* `userlist.card.meta` — already-authorized extra per-user metadata;
* `userlist.card.actions` — already-authorized permission-sensitive controls;
* `userlist.after_list` — provider content after the collection.

They do nothing by themselves and therefore change neither legacy surface.
Core identity, avatar, dates and counts belong to the future ATF card template,
not to one slot per field.

Recommended next-task boundary:

1. Migrate the **AAS custom surface first**, while AAS keeps route, query,
   filters, privacy and DTO ownership and ATF owns only page/card markup.
2. Add a distinct, reversible lease for stock `memberlist*` only in a separate
   phase. Do not point stock execution at the AAS renderer.
3. Keep `headerinclude`, header/footer and pagination as their existing owners;
   transport their already-rendered values.
4. Make ATF-on selection explicit in AAS and leave its present renderer byte-for-
   byte reachable when ATF is off. No activation/deactivation writer is needed
   for AAS's current PHP-built markup.
5. If stock templates are later acquired, use the existing ownership ledger so
   deactivation restores exact pre-ATF rows and hooks/permissions still execute.

## 9. Legacy dependencies to eliminate

* output-wide `memberlist.php` anchor regex → registered navigation URL;
* pathname sniffing for `userlist.php` → explicit surface/context marker;
* profile-link/row/`td`/`img` scraping → typed row DTO and stable card root;
* browser fetch and parse of `online.php` → batched server-side presence;
* JS-created avatar wrapper/status dot and `td:has(...)` → template-owned
  presence markup and semantic state class;
* AAS hand-concatenated table/inline styles → ATF template and primitives only
  in the ATF-on branch;
* Responsive Layout's global table selectors and mobile mutation → native
  responsive card/grid CSS;
* AAS avatar fallback lookup → shared avatar renderer fed by the list query;
* assumptions that APUI presence/Appearance DOM or userfields are available →
  explicit provider fields only.

**Legacy invariant:** with ATF disabled, none of these migrations is active.
Stock templates, stock hooks, AAS routes/renderer/privacy, addon hooks and
assets continue exactly as they do now.
