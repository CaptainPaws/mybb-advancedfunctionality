# AdvancedMessenger

AdvancedMessenger is an optional AdvancedFunctionality addon. Enable it in ACP's
AF addon list after deploying `addons/advancedmessenger/`. It uses `/private.php`;
it installs no public PHP entry point, PM table, derived conversation table or
AdvancedMenu link. Disabling it does not migrate or remove any personal messages.

## Presentation and ownership

| AdvancedMessenger | Adaptive Theme Framework | Result |
| --- | --- | --- |
| On | On | Messenger with ATF shell, variables and compact PM bubbles |
| On | Off | Messenger with its own base CSS and compact PM bubbles |
| Off | On | Existing ATF private messaging templates |
| Off | Off | Existing MyBB/theme private messaging templates |

AM ships its own interactive form templates, initially adapted from the ATF PM
seeds documented in `ATF_PRIVATE_MESSAGING_MIGRATION_CONTRACT.md`. It substitutes
only the request-local MyBB template cache at `private_start`, after core has
checked PM permissions. It does not write, lease, restore or install any
`private_*` database template. ATF's ownership ledger remains exclusively ATF's.
There are no AM activation/deactivation template callbacks to race ATF's restore.
MyBB developer mode can deliberately reload stock XML over cached templates;
production messenger presentation assumes the normal `dev_mode=0` setting.

Full pages have one `.pun.atf-page-shell`; embedded native tools/composer have no
shell. Baseline and panel CSS is scoped to AM components. Resources are declared
in AM's contextual manifest (`private.php`); AF core and ATF resources are not
modified. The runtime template substitution also checks frontend permission.

## Data projection and cost

The only source is MyBB's `privatemessages`. All adapter queries use the current
owner's `uid`. A direct conversation requires a valid single visible recipient,
no BCC, and consistent sender/recipient identifiers. The key uses the peer UID,
so changing a username cannot split history. Unknown/legacy recipient metadata,
system messages, group messages and messages containing BCC are independent
message entries. Drafts are independent editable objects. No recipient array or
BCC list is included in a conversation DTO; the canonical native read view still
owns its permission-filtered participant display for group messages.

Sender copies and recipient copies belonging to other users are never fetched.
A sender without a saved copy has no historical outgoing body in their mailbox;
AM does not recover it from the recipient's copy. Outgoing copies never show an inferred recipient read receipt. Native tracking remains the
source of actual receipt information. Exact self-message copies are paired in
history; ordinary messages are not deduplicated by subject or time.

Dialog pages contain at most 30 entries, sorted by latest owned activity.
Grouping scans only metadata (`pmid/fromid/toid/recipients/folder/dateline/status`)
of the selected owner's mailbox/folder: O(N) classification, O(dialog count)
working memory. The existing `(uid, folder)` index scopes this scan; sorting can
require a database filesort. With no name filter, user data and message previews
are fetched only for the visible page. Name filtering needs participant lookups
across that owner's candidate dialogs. No body/full-history parsing occurs during
the metadata scan. This trades a derived index for a mailbox-local metadata pass;
very large unlimited/imported mailboxes need measured query timings before an
additional index or persistent projection can be justified.

History scans at most 100 metadata candidates per request, filters out unrelated
group messages, and fetches at most 30 accepted bodies. Cursor ordering is the
stable `(dateline, pmid)` tuple. Filtering can yield an empty page with a next
cursor; the previous-messages button continues without losing its position.
Opening an old search/notification `pmid` includes that message and older history;
the latest-messages link explicitly switches to the newest page. Drafts/trash
are excluded from ordinary history; trash has its own history domain.

## Native controller boundary

* `private.php?action=am_history` is the read adapter endpoint. It requires POST,
  a valid native `my_post_key`, a logged-in PM-capable user and an owned anchor.
* Fragment navigation is POST to native `action=read` with `am_fragment=1`.
  Native core validates ownership and applies read/receipt effects before AM
  returns the rendered conversation. Browser history keeps the canonical read
  URL. The first transition from an empty shell uses a full native load so
  parsed-BBCode assets have their normal lifecycle.
* Visible unread history items are submitted sequentially to native `action=read`
  with `am_touch=1` and CSRF. Core alone updates status, receipt and PM counts.
  There is no polling. A quota refresh uses native mailbox rendering, so AM never
  calculates quota independently. Own counters are refreshed and an
  `advancedmessenger:unread` event exposes the native count to interested clients.
* Native `do_send`, PMDataHandler validation/duplicate checks, `do_stuff`, folder
  handlers, search, tracking and exports remain in MyBB. No adapter action writes
  to PM tables. Successful send/draft hooks provide a completion view only after
  native validation and insertion; errors keep the native response.
* Interactive archive forms are adapted. `do_export` and all native archive
  download templates/headers/formats are left untouched.

History renders addon-owned bubbles with MyBB's `postParser::parse_message`,
PM MyCode/smilie/image/video settings and the viewer's media preferences. User-supplied raw HTML remains disabled. Exact, parameter-free buddy notification
texts from the server usercp language file may use native HTML parsing; sender UID
alone never establishes trust, and unknown or changed historical HTML stays escaped. It never embeds `build_postbit` output or runs author/postbit hooks
for each history item. Core's own read controller may still build its stock
postbit; that presentation is discarded. `#posts_container`, `#posts`, quote
markup and canonical message links remain. Context menus use native reply,
quote, reply-all, forward and CSRF-protected move/delete controls. Trash is not
given an invented restore operation. Dates use the viewer's timezone and cursor
pages merge their boundary date separators.

The sidebar is approximately 32% wide on desktop, with compact folders and a
collapsed tools disclosure above the independently scrolling dialog list. The
right pane keeps its peer header and composer outside the history scroll area.
ATF remains the only owner of the exterior `.pun`; accent colors inherit theme
variables. Mobile switches between list and conversation with a back button.

Composer/tool/result documents declare `data-af-layout="content-only"`, using
AdvancedMenu's existing navigation opt-out instead of hiding a second menu with
CSS. They omit header, footer and `.pun`. Normal forum navigation, postbits,
AdvancedProfileUI and ATF templates are not changed. Presence uses MyBB's activity
window and respects invisibility permissions; it does not poll.

## Editor and client state

The inline composer is a same-origin embedded native send page. Each document
contains one `#message` and uses MyBB/AdvancedEditor's normal initialization.
AM never initializes or destroys SCEditor. It subscribes to `af:editor-ready`
and uses an existing instance only to read/restore source. Native recipient
validation, subject, options, preview, draft/save-copy and tracking controls remain.
The compact wrapping toolbar and source/WYSIWYG switch belong to AdvancedEditor. A
ResizeObserver reports inline document height to its parent; the parent validates
both origin and source. Expanded metadata/options can scroll within the frame on
short screens, while the outer conversation pane does not scroll. Tools use the
same native panel mechanism and preserve folder/export/search forms.
Advanced search adds a visible-recipient filter to native result IDs. The peer
header opens that same native search scoped to the direct conversation; filtering
uses the existing conservative conversation identity after core keyword matching. Like native
search it requires keywords or a sender as the primary criterion; the quick
participant search also finds direct dialogs by their recipient. BCC is never
matched by this extra filter. Native flood/permission/search-log handling remains.

Tab-local recovery uses `sessionStorage`, namespaced by account and conversation
or compose mode. It retains text/subject/options and new-message recipients,
offers recovery when a server-provided body differs, saves before navigation and
clears only after a native successful send/save. Existing dialog recipient names
come from current UID resolution, not a stale recovered username. Nothing is
stored in a second server-side PM store. No automatic retry sends a message.

Read navigation and pagination preserve the current view on errors. Non-JSON
native error pages are reported as unexpected page responses, rather than
mislabelled as an expired session. Native forms display their actual server errors
inside the panel. Mobile layout switches between list/conversation, dialog tools
use native focus handling, and controls have keyboard focus styles and labels.

## Verification boundary

Local PHP/JS syntax and template compilation, narrow grouping/access/CSRF code
checks and ownership conflict review only. No schema migration, MyBB core edits,
new automated test suite, live forum deployment or cloud UI testing. Visual and
end-to-end checks of the four addon combinations remain an installation check.

## UI integration corrections (1.2.0)

The technical `.am-layout` has no background/frame/padding. Controls, Select2,
inner panels and action buttons share scoped AM styles built on ATF variables.
Both the panel root and editor containers use border-box sizing; width:100% no
longer adds the root padding to the iframe width. The native submit names/values,
BCC/autocomplete, options and one AdvancedEditor instance remain intact.

All icons use the existing Advanced Font Awesome integration. Each document
retains its canonical font stylesheet URL and the provider's duplicate check.
Manifest stylesheet sources explicitly attach to private.php, preserving their
previous logical IDs and AF's file/theme delivery policy. A late response adapter
restores a missing permitted stylesheet link rather than overriding that policy.

Folders/tools have icons and persist their disclosure state in this tab. Search
has an integrated submit icon. Conversation rows have fixed column geometry and
one-line ellipsis. Bubbles grow to 80% on desktop. Third-level quote chains are
visually foldable; the stored message and original quote nodes are preserved.
Message menus use the popover top layer and are positioned within history bounds,
with a fixed-position fallback. Moving a message expands a small form inside the
menu; deletion still uses MyBB's post key and do_stuff controller.

MyBB get_profile_link() already returns an escaped attribute URL. AM now uses it
as-is for avatar, username and profile control instead of encoding the ampersand
again. The peer header gets its banner from af_aa_build_user_css_payload(peerUid),
the same resolver used by ATF's profile context, with the APUI default fallback.
No profile HTML is fetched and the viewer's UID is not substituted.

History declares `--am-history-background-image: none` in
`body.am-body .am-history`. Replace none with url(...) or override that selector
to add a texture. There is no image asset or global ATF background change.

Embedded mode is retained through native forms, private.php links and native
redirect hooks. Header/footer stay empty. Native error pages receive the same
content-only marker before output providers run. AdvancedAlertsMentions now
honors that marker in its fallback injector, leaving global alerts unchanged.
Profile/forum links from a panel leave the iframe rather than opening a global
page shell inside it. Tool loads refresh the outer sidebar without replacing
native search/export/tracking behavior.

Validation is limited to PHP/JS syntax, template compilation and the critical
integration paths described above. Live visual/function testing and the four
AM/ATF combinations remain deployment checks. Deep quote folding and top-layer
menus enhance modern browsers; without popover support the menu uses fixed
positioning. On very short viewports, expanded address/options/preview content
can still scroll within the capped editor document; the outer pane does not
scroll. Unknown historical HTML is deliberately not decoded or trusted.

## Repeated UI defect fixes (1.3.0)

The required AM layout/form styles now use manifest-declared file delivery, as
AdvancedEditor's structural styles do. They are not replaced by a saved Theme
copy. Theme variables and user overrides still apply; the existing history image
URL is preserved. The late adapter emits only missing AM CSS, never the global
JS queue, so it cannot resurrect sources already consumed by AdvancedJSBundle.
An addon pre_output entry point now covers AF's output-buffer dispatcher as well
as the normal MyBB output_page hooks.

AAM's actual source is its global_start bootstrap, before headerinclude and before
private_start. A narrow request predicate suppresses creation of global UI
fragments for permitted AM panel requests, plus guards the output fallback.
AdvancedMenu also checks the shared request fact before injecting navigation,
including buffer-only output without a body marker. Normal forum alerts and menu
are unchanged. This is composition control, not CSS hiding.

Bubbles use intrinsic max-content width, a desktop minimum of 260px when space
allows, and the existing 80% cap. Mobile reduces the minimum. Sidebar paint is
transparent. Folder/tool and action-menu markup have explicit vertical layout;
normal/destructive actions are separated and move selection stays inside the
menu. Compose button labels are shorter while native submit names/values stay
unchanged. Sending is disabled for an empty source and updates with the existing
editor's valuechanged lifecycle; preview and draft actions remain available.

Advanced search now has a scrolling fields region with criteria, filters and
sorting, checkbox folder choices, and a persistent Cancel/Find footer. The outer
modal owns its compact title/close header. Outside clicks close only a clean
form; Escape and explicit Cancel retain their normal meaning. folder[]=0 means
all folders as in MyBB, and the client keeps it exclusive with explicit folder
choices. Native search action, field names and semantics are unchanged.

Receipt icons are deliberately conservative. A check means a saved sent copy,
not delivery or unread status. A double check requires receipt=2, a nonzero
readtime/status on the actual owned recipient row. MyBB 1.8.40 stores sent copies
with receipt=0 and a different pmid, without a persistent relationship to the
recipient's tracking row. Ordinary outgoing copies therefore cannot truthfully
show double checks, even if native Tracking lists a read receipt elsewhere.
There is no content/time matching, cross-mailbox query, polling or new table.


### 1.3.1 — navigation regression

The shared translation attribute data-am-cancel on every AM root collided with
the composer's delegated cancellation selector. Every click inside that root
therefore navigated the top document to private.php, or posted a close message
from an embedded document. Cancellation now uses an explicit button action
(data-am-action="cancel"), separately from translation attributes. The existing
AJAX/dialog lifecycle, native folder navigation and embedded notification guards
remain in use. Both scripts also guard against repeated initialization. Fragment
responses are validated before replacing a working conversation; failures keep
that view and offer an AJAX retry as well as the native open link.

Folders and Tools start collapsed on each page load. Sidebar refreshes preserve
their live open state; previous-page sessionStorage preferences are no longer
restored. Shared search input/button selectors now outrank the generic AM form
rules, preserving the icon's reserved padding and compact hit area. Decorative
search glyphs do not intercept pointer events. The existing history image is
unchanged. No database, PM operation, editor instance or global menu change.


### 1.3.2 — Stage A: reply-all and PM alerts

Reply-all is offered when the author plus visible recipients yield more than
one other participant. The composer resolves those UIDs against existing users,
excludes the current user and duplicates, and clears BCC on initial reply-all.
POST/preview validation and ordinary replies remain native MyBB operations.
Limits are not bypassed or silently truncated; native send validation applies.

AAM's PM insert-end hook now uses MyBB return_values.pmids from a successful
messagesent result. Each alert points to that recipient's exact inserted copy;
there is no dateline lookup or guess from the mutable saved-copy data. This
covers single, multiple and BCC recipients, omits self-notifications, guards
repeat processing of the same copy and retains existing alert preferences.
Only subject is included in metadata, never recipient/BCC lists. Draft saves
do not emit alerts.

Stage B is deliberately pending confirmation of Stage A on the live forum.
No persistent groups, schema changes or automatic merging of mass mail were
introduced. Manual validation: send to two visible recipients plus BCC, check
individual alerts, reply-all as sender and recipient, preview and submit, and
verify native recipient limits/ignore rules. Functional verification requires
a configured MyBB forum and remains with the site owner.

### 1.4.0 — persistent group conversations

Additive metadata migration (install/upgrade/activate, also ensured once per
active private.php request for an existing deployment):

- af_am_groups: generated gid primary key, title, creator, created, active.
- af_am_members: primary key (gid, uid), joined timestamp, active flag.
- af_am_links: unique pmid, primary key (gid, pmid) for paginated group queries.

No MyBB table is altered. There is no message-body table or historical backfill.
Deactivation and uninstall deliberately retain metadata; no destructive removal
endpoint is provided. Normal AF database credentials need CREATE privileges for
the initial migration. InnoDB/collation are used on MySQL; generated keys use the
native PostgreSQL/SQLite syntax on those drivers.

A new successfully validated AM send to two or more other visible recipients
creates a fresh gid. BCC mail stays ordinary mass mail. Commit hooks collect exact
recipient and saved-copy IDs on the PM handler instance; insert-end creates the
group only after successful delivery. A sender's own selected UID is excluded
for group delivery and savecopy is forced, so the author has one outgoing copy.
There is no subject/time/participant matching between distinct sends.

Group reply forms carry am_gid and am_origin; membership is checked before
native send/preview, recipients are populated on the server, and native limits,
permissions, ignore lists, quotas, CSRF and validation still apply. Deleted
participants or an exceeded limit fail without silent partial delivery. The
validation hook verifies that resolved recipients equal the full membership
minus the sender. Group recipient fields are read-only, and BCC is unavailable
in group replies. To send a separate private message use New message. Draft
copies preserve gid but are excluded from group history. Existing mass-mail
reply-all is retained; old copies are not migrated.

Sidebar and history use group keys from links joined to the current user's
existing PM copies and active membership. History retains the existing cursor
and 30-message batches, with one joined author query rather than per-message
user lookups. Deleted PM copies cannot reappear; trash remains a separate view.
Groups have an icon, title, participant list with profiles, per-message author
names/avatars, and a creator-only CSRF-protected rename form. Renaming changes
only metadata. Reply subjects are capped at MyBB's 85-character limit.

AAM is unchanged. Its exact recipient-copy PM links resolve to a group through
metadata when AM is enabled, and to normal private.php reading when disabled.
Successful group send returns the author's exact saved-copy ID to the existing
iframe completion handler, opening the same conversation after sending.

Deployment verification is intentionally limited to PHP/JS syntax and targeted
integration/security inspection. The site owner should run A -> B,C; B -> A,C;
C -> A,B, then check separate groups with identical members, BCC mass mail,
failed recipients, drafts, rename permissions, deletion/trash, notification links
and disabled-AM native reading. No new automated test suite is included.

### 1.4.1 — navigation, incremental updates and shared UI fixes

Back to dialogs now navigates to private.php (All messages), clearing the active
PM/folder/name filter with a normal browser history entry and draft save.

The existing am_history API accepts after_id for an incremental batch (up to 30
candidate IDs). Ownership, group membership and normal PM identity are retained;
only current-user PM rows are read. Group-link lookup is bounded to the anchor
and newer IDs for this request. No read side effects happen in the endpoint.
A cheap own-mailbox COUNT/MAX(pmid)/SUM(status)/SUM(folder) stamp triggers sidebar refresh
only on changes. Full dialog classification is not performed on every poll.

One timer in the existing runtime runs every 5 seconds (POLL_INTERVAL), pauses
on hidden/pagehide, resumes on visibility/pageshow, ignores old generations and
backs off to 60 seconds on failures. Session/CSRF failures stop it. Bubble append
uses existing parsing/rendering and PM-ID deduplication, preserves the editor,
keeps scroll position unless already at the bottom, and offers New messages.
Native visibility/read handling remains responsible for reading received PMs.

AAM uses a shared confirmed-response badge updater, including AdvancedMenu and
the modal. Mutations are serialized; response epochs/serials reject older read
responses. No optimistic count arithmetic is used. Failed mutations leave row
state/counters unchanged and report an error. Mark-all queries the server count
instead of returning an assumed zero. Existing notification delivery, PM send,
group identity and schema are unchanged.

AAM suppresses only request-local global_pm_alert before header evaluation when
PM alerts are enabled, available for the user, and frontend runtime is allowed.
It does not change native PM counts/preferences/read state or quota warnings.
Quote avatars no longer infer ownership from arbitrary images in a surrounding
table/post. Only author-avatar profile links contribute UID/avatar pairs; name
resolution must be unique in that local index. Each nested quote uses its own
header. Unknown authors receive no guessed avatar.

Validation: PHP/JS syntax, targeted hook/permission/cursor/lifecycle inspection,
and git diff checks. Live UI, delivery/concurrency and scroll testing remain with
the forum owner; no automated suite or cloud browser run was added.
