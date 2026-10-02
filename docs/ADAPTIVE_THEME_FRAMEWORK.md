# Adaptive Theme Framework

`adaptivethemeframework` is the AdvancedFunctionality presentation owner for
adaptive layout and theme concerns. Its frontend scope is declared globally by
the addon's manifest-based permission metadata.

## Ownership boundaries

- Adaptive Theme Framework owns layout and theme presentation.
- It does **not** own navigation data. AdvancedMenu remains the navigation owner.
- Addon business logic remains in the addon that owns that domain.
- Standard MyBB template names remain compatibility entrypoints.
- New visual markup will be composed through ATF component slots.

## Component slot contract

### Postbit provider contract

ATF prepares both `postbit` and `postbit_classic` through the same provider
pipeline; it does not replace either template yet. At the late postbit hook the
composed values are available in `$post['af_atf_slots']`, keyed by slot name.
`$post['af_atf_context']` contains only `pid`, `tid`, and `uid` for diagnostics.

Provider renderers receive `pid`, `tid`, `uid`, and a curated `post` value. The
curated value is not the complete MyBB post row. It contains rendered identity,
author metadata, body-adjacent fragments, APUI composition values,
`af_aa_user_class`, and already permission-checked native action controls. A
provider must not assume any `.af-apui-postbit` element exists. Passing a full
post row requires a new, documented provider-specific justification.

The current mapping is:

| Provider value | ATF slot |
| --- | --- |
| APUI `profilelink` | `post.author.identity` |
| APUI presence | `post.author.meta` |
| APUI profile fields | `post.author.profile_fields` |
| APUI author statistics | `post.author.rail` |
| APUI/AdvancedAppearance plaque | `post.author.plaque` |
| CharacterSheets action composed by APUI | `post.author.character` |
| AdvancedPostCounter marker/output | `post.post_counter` |
| Native MyBB/AAM action buttons | `post.actions` |

`post.before_body` and `post.after_body` are intentionally available extension
points and remain empty until a provider registers content. Native actions are
forwarded as rendered HTML so edit, delete/restore, approve/unapprove, quote,
multiquote/reply, report, mention, warning and PM controls retain MyBB's
permission checks and JavaScript attributes.

With ATF disabled, APUI continues to own its existing `postbit_classic` output
and AdvancedPostCounter continues to populate `$post['advancedpostcounter']`
and its legacy fallback. With ATF enabled, PostCounter uses only
`post.post_counter`; the legacy variable is kept empty to prevent duplication.
APUI still emits its named `$post['af_apui_*']` variables for the unchanged
legacy template during this preparation phase.

The only remaining DOM dependencies are in the legacy APUI template/CSS/JS
presentation path. Provider transport has none. The retired Adaptive Responsive
Layout DOM-moving runtime is deliberately outside this contract and is neither
loaded nor emulated by ATF.

## Member profile provider hand-off

`member_profile` is not an ATF-owned template yet. While ATF is enabled its
late `member_profile_end` hook nevertheless builds a closed profile context and
resolves the future template variables `{$atf_profile_<slot>}`. The context
contains only the profile identity and a small member subset, rendered native
fragments, owner/viewer identity, APUI section output, and AdvancedAppearance
metadata. It does not expose `$GLOBALS` to providers.

| Slot | Owner and actual runtime source |
| --- | --- |
| `profile.hero` | AdvancedProfileUI; MyBB's rendered avatar, formatted name, title, group image/stars, presence, registration/last-visit values, plus uid-scoped AdvancedAppearance metadata. |
| `profile.navigation` | AdvancedProfileUI; links derived from non-empty APUI section outputs, retaining `#af-apui-tab-*` hashes and `data-tab` keys. |
| `profile.forum_info` | Existing `$af_apui_forum_info_grid` builder output. |
| `profile.character_sheet` | Existing `$af_apui_character_sheet_tab`; CharacterSheets remains its renderer/data source when a sheet exists. |
| `profile.application` | Existing `$af_apui_application_tab`; the application integration retains its queries and actions. |
| `profile.timeline`, `profile.activity` | Existing APUI builder outputs (currently APUI empty states). |
| `profile.balance` | Balance's existing `$memprofile['balance']`, after its calculation hook. A disabled/missing addon registers no provider. |
| `profile.post_counter` | AdvancedPostCounter's existing `$memprofile['advancedpostcounter']`; the legacy marker path remains intact. |
| `profile.main` | MyBB-rendered `profilefields`, `contact_details`, and `signature`. |
| `profile.after_content` | MyBB-rendered moderator, administrator, buddy, ignore, and report blocks. |

ATF transports native blocks after MyBB has applied their permissions; it does
not reimplement those checks. `profile.before_content` is intentionally empty.
With ATF off the current APUI template and all legacy variables are unchanged.
Until the future template and JavaScript are migrated, the remaining profile
DOM compatibility surface is APUI's tab/panel selectors and the
`af-apui-member-profile-page` / `af-aa-profile-user-<uid>` styling hierarchy;
providers themselves do not inspect that DOM.

Providers call `af_adaptivethemeframework_register_component()` with `owner`,
`key`, `slot`, and either a callable `renderer` or static `html`. The stable
identity is `owner::key`, so registering it twice (including in a different
slot) is rejected. `sortorder` controls deterministic ordering; equal values
are ordered by stable identity. `visibility` may be a boolean or callback, and
`context` may be a callback or a map of exact accepted context values.

Layouts call `af_adaptivethemeframework_render_slot($slot, $context)`. Disabled
addon owners, invisible components, and components whose context does not
match are omitted before their renderer runs. Provider output is trusted HTML;
providers remain responsible for escaping user data.

During migration, `legacy` can contain an existing rendered variable or a
callback. It is emitted together with the new component (`legacy_position` is
`before` or `after`). This dual-render facility is explicit data composition:
it does not inspect or depend on legacy DOM markers.

The catalogue covers the audited profile, post, thread, forum-card, header and
footer integration points. It is returned by
`af_adaptivethemeframework_slots()` and is the authoritative allowlist.

### `forum.lastposter_avatar` context

Forum-card layouts render this slot with exactly the last-poster identity that
MyBB has already attached to the forum row:

- `fid` (positive integer): the forum whose card is being composed;
- `lastposteruid` (integer, `0` for a guest): MyBB's last-poster user id;
- `lastposter` (string): MyBB's last-poster display name.

Layouts must not query or pass avatar columns and must not pass the complete
forum/global arrays. AdvancedPosterAvatar owns the user lookup, default/letter
avatar fallback and final escaped HTML. Missing contract keys produce no
component. A deleted/unresolved uid follows the same guest fallback as the
legacy renderer. While ATF is active, the provider's slot output is exclusive:
installed legacy template markers are retained for rollback but do not render.

### Thread-card context

Future `forumdisplay_thread` composition must call
`af_adaptivethemeframework_thread_card_context()` and pass that result to its
slots. The closed context contains `tid`, `fid`, `subject`, `lastposteruid` and
`lastposter`; it additionally contains `lastpostpid` only when the query has a
positive value. It never contains the complete thread or global state.

AdvancedThreadFields provides `thread.meta_chips` and requires only `tid` and
`fid`. AdvancedPosterAvatar provides `thread.lastposter_avatar` and uses `tid`,
`fid`, `lastposteruid` and `lastposter`. With ATF active their installed legacy
markers remain available for rollback but produce no duplicate output; with
ATF inactive the existing template variables and marker replacement continue
to operate.

### Showthread context

Showthread composition builds its closed context with
`af_adaptivethemeframework_showthread_context()`. Identity and metadata are
`tid`, `fid`, owner `uid`/`username`, `subject`, `dateline`, and `lastpost`.
`thread.meta` is reserved for additional metadata providers; there is no real
provider today because core owner/forum/date presentation needs no separate
business-data renderer. Thread fields are the real `thread.atf_fields`
provider and use only `tid` and `fid`.

Rendered MyBB contracts are passed explicitly as `posts_html`,
`breadcrumbs_html`, `quickreply_html`, `moderation_html`, `poll_html`,
`thread_tools_html`, `pagination_html`, and `javascript_html`. The layout must
retain both `#posts_container` and `#posts`, and must preserve the generated
post IDs, edit/delete/quick-edit/report controls, moderation permissions and
tokens, poll, tools, pagination, quick reply, and MyBB JavaScript globals.
The `thread.breadcrumbs` core provider returns `breadcrumbs_html`; it never
searches APUI comments or normalizes APUI DOM.

ATF now acquires `showthread` through the same per-template-set ownership ledger
as every other owned seed. The live pre-ATF bytes (including an APUI override)
are restored on release. While ATF is active, APUI skips its `showthread`
writer but continues to supply runtime Appearance CSS. The ATF seed retains
only the APUI body/hero class bridge required by that CSS; `atf-thread*` owns
the layout. It directly renders `thread.breadcrumbs`, `thread.meta`,
`thread.atf_fields`, `thread.before_posts`, and `thread.after_posts`. The
legacy marker paths remain untouched for exact ATF-off restoration.

## Design-system contract

ATF visual primitives use the `atf-` prefix. Components use BEM-style
`__element` and `--modifier` suffixes; they do not use MyBB row/table selectors
or `af-apui-*` as their foundation. The initial public set is `atf-page`,
`atf-section`, `atf-card`, `atf-grid`, `atf-stack`, `atf-meta`, `atf-button`,
`atf-tabs`, `atf-chip`, and `atf-form-row`.

Every token and component selector is rooted at `body.atf-active`. The addon
adds that marker at `pre_output_page` only while its bootstrap is active, so a
cached stylesheet has no effect after deactivation. The stylesheet is declared
through `theme_stylesheets` and becomes an `advancedstyles.css` section; the
direct frontend asset list intentionally remains empty.

The responsive contract has desktop as its default and named tablet (`64rem`),
mobile (`48rem`), and narrow-mobile (`30rem`) boundaries. Page and component
gaps are tokenized at the root and changed together at those boundaries.

## Compatibility constraints

The addon does not patch MyBB core, remove or modify templates, replace
`member_profile` or `postbit`, copy Adaptive Responsive Layout, or migrate
another addon. Existing pages are not restyled until they opt into ATF markup.

Adaptive Responsive Layout (`advresponsivelayout`) is deprecated. Adaptive
Theme Framework is its independent replacement, and the two addons should not
be enabled at the same time. The legacy addon is not a dependency of ATF: ATF
does not load it, call it, or rely on its assets or runtime. No slots model the
old `advresponsivelayout.js` DOM contract.

Frontend asset lists remain intentionally empty because CSS delivery is owned
by the AF theme stylesheet pipeline rather than a direct asset URL.
