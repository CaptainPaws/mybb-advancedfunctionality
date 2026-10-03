# ATF Index + Forumdisplay structural audit

## Scope and ownership

This audit covers the server-rendered MyBB 1.8.40 index and forumdisplay composition while AdaptiveThemeFramework (ATF) owns its leased templates. It does not change queries, permissions, visibility, counters, or preferences. When ATF is disabled, the template lease ledger restores the exact pre-activation templates; no JavaScript rearranges either surface.

## Template graph

### Index

`index` renders the page shell and the native `{$forums}` result. MyBB's permission-aware `build_forumbits()` selects `forumbit_depth1_cat` for each visible top-level category. That template emits one `section`, its `header`, and a single forums container containing its native `{$sub_forums}`. Each visible child uses `forumbit_depth2_forum`; its last-post fragment uses `forumbit_depth2_forum_lastpost`.

ATF therefore composes, without re-querying or re-filtering:

```text
section.atf-forum-category
├── header.atf-forum-category__header
└── div.atf-forum-category__forums
    └── article.atf-forum-card × visible child forum
```

The category name, URL, description, collapse image/state and child output are native values. Since the child expression occurs inside the category container, cards cannot precede their heading or become orphans. MyBB remains responsible for omitting inaccessible/empty category results.

### Forumdisplay

`forumdisplay` owns one current-forum section. Its body contains both native `{$subforums}` and `{$threadslist}`. `forumdisplay_subforums` replaces the stock table only because semantic forum-card articles cannot be children of a table. `forumdisplay_threadlist` likewise places `{$threads}` in a non-table section, while retaining small native tables solely around the native row-based announcement/select-all and sort/filter fragments.

```text
section.atf-forumdisplay__category
├── header (current forum name)
└── div.atf-forumdisplay__category-body
    ├── section.atf-forumdisplay__subforums
    │   └── div.atf-forumdisplay__subforum-cards
    │       └── article.atf-forum-card × visible subforum
    └── div.atf-topic-list
        └── section.atf-topic-list__cards
            └── article.atf-topic-card × thread
```

No `article` is emitted directly under `table`, `tbody`, `tr`, or `select`, so browser foster-parenting cannot split cards from their owners.

## Forum-card contract

Each card keeps the native status/unread marker, linked title, viewers, description, moderator/subforum output, thread/post counts (including unapproved indicators), and last-post data. Subforum links remain in the identity region and wrap at arbitrary long names. Last post is an internal compact block with thread link, profile link, date, and a square centered/cropped avatar viewport. If no optional avatar provider supplies markup, ATF uses the configured native default avatar and links it to the last poster when a positive UID exists; this fallback performs no lookup.

## Layout variants

`forum_layout=full` uses a full-width card. Its identity is the only flexible track; counters and last post cannot reduce it below zero or overflow the category.

`forum_layout=grid` uses `repeat(2, minmax(0, 1fr))`. The last odd card spans `1 / -1`. Inside every grid card, status and identity occupy the first row, compact counters follow, and last post is a separate lower block. Thus title and description have the full useful card width rather than sitting between stats and last post. At 48rem and below, the category becomes one column and card metadata stacks.

## Topic-card contract

`forumdisplay_thread` is one article containing status and native thread icon, prefix/title/unread link, multipage links, author and creation time, approved compact metadata, reply/view counters, optional rating, integrated last-post avatar/poster/time, and the native moderator checkbox. Sticky/normal and unread/closed/moved classes remain native inputs. Last post is not an external row.

Rating and moderation child templates remain MyBB-owned table cells. The composer removes only their obsolete outer `td` before inserting their complete controls in the semantic card, preserving IDs, permissions, tokens, and behavior. Pagination, announcements, subscription/password actions, mark-read, sorting/filtering, forum jump, search, thread prefixes and icons stay native.

## Provider boundaries

The showthread-only `thread.atf_fields` slot renders the full AdvancedThreadFields display block and is never consumed by `forumdisplay_thread`.

Forumdisplay consumes only `thread.meta_chips`. AdvancedThreadFields registers its explicit forum metadata provider there and includes only fields whose administrative `show_forum` flag is exactly `1`, with a non-empty value. The topic template renders that result in a dedicated compact metadata element—not in the title or description. The legacy `af_atf_forum_chips` variable is blanked while ATF is active, preventing duplicate output. Consequently arbitrary questionnaire fields cannot leak into a topic description, and the boundary is fixed in composition rather than concealed with CSS.

`thread.lastposter_avatar` and `forum.lastposter_avatar` are optional presentation slots. Their output is accepted as rendered markup; the native, query-free default described above is used only when the relevant slot is empty.
