# ATF card, list and grid structural contract

## Scope and ownership

This audit covers the ATF-rendered index forums, forumdisplay topics, stock
member list, AAS user list, profile composition, UCP collections, private
messages, ModCP collections, subscriptions, drafts and attachments. It is a
presentation contract only: MyBB and each addon continue to own queries,
permissions, tokens, status values and language strings.

The contract is opt-in and scoped by `body.atf-active`. Existing feature BEM
classes remain in the markup so feature-specific layouts and JavaScript do not
lose their hooks.

## Canonical anatomy

Every reusable card starts with `.atf-card` and may expose only the anatomy it
actually has:

```text
.atf-card
├── .atf-card__identity / .atf-card__title
├── .atf-card__body
├── .atf-card__metadata
├── .atf-card__status
└── .atf-card__actions
```

Identity, body and metadata are shrinkable and long values wrap anywhere.
Metadata uses a `dl` for label/value facts where the source provides labels;
short bylines may use another semantic group with `.atf-card__metadata`.
Feature-owned status classes remain present alongside the structural
`.atf-status`; no shared backend status enum is introduced.

Actions use `.atf-card__actions`, wrap instead of overflowing, and disappear
when empty. A destructive subgroup can use `.atf-card__actions--danger` and
destructive controls retain `.atf-button--danger`; this distinguishes intent
without changing branding.

## Avatars

`.atf-avatar` is the common square viewport. Its size is controlled with
`--atf-avatar-size`, and nested provider/core images fill the viewport with
`object-fit: cover` and centered positioning. The existing avatar providers
remain responsible for fallback markup and fallback URLs. The contract is
used only on cards that already render an avatar: forum/thread last-post,
member/AAS user and PM cards. Attachments, drafts and moderation log entries do
not acquire decorative avatars.

## Collections, grids and pagination

`.atf-list__items` is a one-column semantic card collection. `.atf-grid` is the
responsive multi-column variant and uses shrink-safe fractional tracks; it is
forced to one column at the ATF mobile breakpoint. Cards never receive fixed
inline widths.

Pagination belongs in `.atf-list__footer`, as a sibling after the collection,
and never inside an individual card. The AAS list now emits its pager once in
that footer. Core pages may retain a page-level pager above and below a form
when MyBB supplies both, but neither instance is card content.

`.atf-empty-state` is the only shared empty-state structure. It spans a grid,
wraps long language output, and adds no text. Existing MyBB/addon language
variables remain the sole source of messages.

## Surface audit

| Surface | Result | Deliberate exception |
| --- | --- | --- |
| Index forums | Semantic `article` cards; canonical identity, metadata and status hooks | Last-post composition keeps its feature layout |
| Forumdisplay topics | Semantic topic cards; canonical title, metadata, status, avatar and actions | Thread status and rating remain core-owned |
| Member list | User-card anatomy, `dl` metadata and square avatar | Core supplies card actions and fallback image |
| AAS user list | Same user-card anatomy and grid; single list footer pager | AAS retains privacy, filters, query and presence semantics |
| Profile | ATF slot panels compose provider-owned cards/activity | Slot output is not rewritten because providers own its semantics |
| UCP / subscriptions / drafts / attachments | Semantic article collections, wrapped metadata/actions and unified empty state | Selection checkboxes remain native bulk-form controls |
| Private messages | Semantic message cards, common avatar/title/metadata structure | Read/unread and receipt data remain MyBB-owned |
| ModCP | Log/report/queue datasets use semantic cards and unified empty state | Genuinely tabular form/configuration datasets remain tables |

## Long-content and compatibility checks

Canonical anatomy applies `min-width: 0`, bounded maximum widths and
`overflow-wrap: anywhere` to titles, identities, bodies and metadata links.
This covers usernames, thread subjects, URLs, IP addresses and filenames while
allowing feature-specific ellipsis only where it was already intentional.
No query, DTO, permission decision, language text, animation, color token or
branding rule is changed by this migration.
