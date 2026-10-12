# AdvancedGallery 1.1: Appearance albums and Media API

## Scope and migration

Stage 2 extends AdvancedGallery only. It does not implement Appearance presets/masks,
change postbit templates, add a media addon or introduce an Appearance upload path.
The five reserved purposes are `appearance_avatars`, `appearance_postbit_bg`,
`appearance_profile_bg`, `appearance_sheet_bg`, `appearance_theme_bg` (the last key
reserves a destination; it does not enable a new theme surface).

Install, activate or `af_advancedgallery_upgrade()` performs additive migration:

- `af_gallery_albums.system_key VARCHAR(64) NULL DEFAULT NULL`;
- UNIQUE `uid_system_key (uid_owner, system_key)`;
- `af_gallery_media.appearance_retained TINYINT(1) NOT NULL DEFAULT 0`.

Ordinary albums retain NULL keys; old IDs, titles, paths, memberships and media rows
are unchanged. The index permits multiple ordinary albums per owner. System creation
uses MySQL atomic INSERT/ON DUPLICATE KEY, not a read-then-insert race. Providers do
not perform DDL. Activate/upgrade Gallery before using the new context. Upgrade
also installs updated Gallery templates. Theme-specific custom copies of the picker
must retain the new purpose hidden field and root attributes.

No registration hook or constructor GET creates system albums. A valid local image
is saved by the existing Gallery upload handler; only then is its destination album
created/resolved. A failed MIME/permission/size check creates no system album.
`ag_get_or_create_system_album` is an explicit save-boundary provider, not a function
to invoke while displaying a constructor. Existing ordinary default-album behavior
is preserved outside Appearance contexts; system albums never become that default.

System albums start private, are browsable only by their owner or existing Gallery
moderation groups, keep a stable server-owned machine key, and may be renamed.
Normal create/edit forms cannot supply or mutate the machine key; editing a system
album forces private visibility and empty allowed_groups. Normal album deletion is
blocked for system albums. Direct file URLs remain public, without a proxy.

## Provider functions

The Gallery bootstrap loads `media_api.php`; consumers use the enabled addon/provider
boundary, not its HTTP form handlers and not direct Gallery SQL/path assembly.

| Function | Contract |
| --- | --- |
| `ag_get_system_album(int $uid, string $purpose): ?array` | Read only. Existing row or NULL; requires owner/current actor or Gallery moderator. Invalid/missing purpose/schema yields NULL. |
| `ag_get_or_create_system_album(int $uid, string $purpose): int` | Save boundary; requires enabled Gallery, current UID matching owner, and existing upload permission. Throws on invalid purpose/permission/unmigrated schema; repeated/concurrent calls return the same ID. |
| `ag_get_owned_media(int $uid, int $mediaId): ?array` | Exact media owner proof; current actor must be that owner or Gallery moderator. Includes pending/rejected rows for private inspection, not authorization to publish. Passing another UID does not bypass actor access. |
| `ag_resolve_media_url(int $mediaId, string $variant): string` | `full`, `preview`, `thumb`; invalid/missing/non-image returns empty. Approved direct images can resolve for any visitor independently of album privacy. Pending/rejected URLs resolve only for owner/moderator. Not proof of ownership. Does not copy files. |
| `ag_retain_owned_media(int $uid, int $mediaId): bool` | Required before saving a new published Appearance reference. Only the current owner and approved local raster images; permanently pins the ID against Gallery physical deletion. Repeatable, returns false for foreign/unapproved/unsupported images. |

Typical future Appearance save:

```php
$uid = (int)$mybb->user['uid'];
$media = ag_get_owned_media($uid, $mediaId);
if (!$media || !ag_retain_owned_media($uid, $mediaId)) {
    // Reject the reference; do not store a user-provided URL or filesystem path.
}
// Store media_id only. Resolve the display variant through Gallery at render time.
$url = ag_resolve_media_url($mediaId, 'preview');
```

Use the URL lookup only after authorization when attaching a new reference. Do not
use its public approved-image response to adopt a different owner's private media.
The same owned ID may be referenced by multiple presets, PID masks or image roles;
selection does not move/copy it or require a new purpose-specific upload. Local JPEG,
PNG, GIF, WebP reuse keeps Gallery validation. Future animation preferences must
still control display; GIF acceptance here is not permission to bypass those controls.
Remote Gallery behavior remains available in legacy mode; Appearance picker/publication
initially accepts local raster images only, not embeds or remote provider content.

## Picker and uploads

Old `AF_GalleryPicker.open(function (bbcode) { ... })` remains unchanged.
New calls:

```js
AF_GalleryPicker.open(function (item) {
  // Persist item.media_id, not item.url_full or item.thumb.
}, { purpose: 'appearance_avatars' });

// Equivalent:
AF_GalleryPicker.open({ purpose: 'appearance_postbit_bg', onSelect: item => {} });
```

The existing `gallery.php?action=picker` and `picker_data` accept `purpose`.
The first filter shows the existing destination album or an empty result if absent;
“All my images” and other owned albums remain selectable. GET does not create a
system/default album for a new Appearance user. Purpose data includes only approved
local images owned by the current user. `picker_data` keeps its JSON array and legacy
fields; `media_id` is additive. Invalid/foreign album filters are rejected.

New images use the existing `upload_do` multipart form, permissions, CSRF, extensions,
MIME/getimagesize checks, per-file limit and thumbnail generator. The server chooses
the purpose destination regardless of supplied album_id. Responses add `media_id`
and `album_id`. Existing file structure stays `af_gallery/{uid}/{year}/{month}/...`.
The context picker disables remote-add; server remote-add also rejects a nonempty
Appearance purpose. Unknown purposes are rejected, not treated as ordinary uploads.

ID selection posts to `gallery.php?action=picker_select` with `purpose`, `media_id`,
`my_post_key`. It verifies upload access and ownership/approved-local policy, retains
the ID, and returns `{ok, media_id, url_full}` before calling onSelect. Reusing the
picker in another context discards stale responses; repeated opens do not accumulate
modal roots or Escape listeners. Consumers must load Gallery assets through the
existing asset system; this stage does not build an Appearance constructor.

## Deletion and storage policy

Stage 2 chooses conservative preservation rather than introducing a reference ledger
before presets/masks exist. Every Appearance-context upload is retained, including a
pending upload; selecting an existing image retains it before returning its media ID.
Future Appearance server writers must call retention even if they receive an ID
without using this picker. This prevents a client from bypassing the retention boundary.

Single/bulk Gallery delete cannot remove retained rows/files, including for moderators.
A row lock serializes physical deletion against retention UPDATE: a successful selection
cannot subsequently lose its file to a concurrent Gallery delete. Ordinary unretained
media deletion keeps its existing behavior. Moving media or removing album membership
does not clear retention. Rejection remains moderation, does not unlink files, and
public URL resolution can become empty; later renderers must use an inherited fallback.

There is deliberately no unpin/automatic GC endpoint: cancelled selections and unused
Appearance uploads remain retained and count toward storage. A future reference-aware
GC policy can reclaim them only after proving they are unused. This tradeoff prevents
broken published media now and avoids guessing future Appearance schema. Whole-addon
uninstall/database/manual filesystem removal is an administrative destructive operation,
not covered by routine Gallery delete protection.

System albums do not count toward `max_albums`; normal album limits still apply to
ordinary albums. Per-album media limits and upload permissions still apply. New optional
`af_advancedgallery_max_storage_mb` (0 = unlimited) counts SUM(filesize) of all the
owner's media, including system/retained/rejected files, before every local upload.
A MySQL named owner lock serializes quota check through insert and releases at request
shutdown, including errors. Reactivation preserves the configured storage limit.
Remote media retain existing zero-byte accounting because no local payload is stored.

## Upload-root verification and tests

Production warprift.ru configuration/DB was not available. No production upload root
or production writable permissions are claimed verified. Code continues to use only
`af_ag_resolve_upload_path()` for local uploads. Tests use a temporary MyBB root under
`/tmp/ag-media-fixture` with configured relative `uploads`; production configuration
must be checked before deployment/activation. Absolute-root resolution remains in the
same existing resolver; no alternative Appearance filesystem path is introduced.

Targeted tests (PHP 8.5 CLI with mysqli/GD):

- `tests/gallery_media_api_integration.php`: actual legacy-table migration twice,
  preservation, 12 parallel purpose creators, separate UIDs, privacy/permissions,
  defaults/album/storage quotas, owner proof/variants and retention;
- `tests/gallery_http_regression.php`: real multipart upload/MIME/CSRF, thumbnails,
  direct file GET, purpose/all-owned picker, foreign/pending refusal, forged privacy/key,
  retained single/bulk delete, ordinary upload/picker/delete;
- `gallery_media_api_integration.php --after-http`: stored paths/thumbnails/private
  keys and eight parallel retention-versus-deletion races;
- `tests/gallery_picker_browser.cjs`: Chromium ID callback, purpose isolation,
  selecting other owned albums, old BBCode mode, lazy upload/reopen and stale lifecycle.

These fixtures use only a temporary database literally named `gallery_test` with
`test_` tables and must not be connected to a production database. The HTTP fixture
supports test-only actor overrides and is not a production endpoint. Serve it only
in the isolated test environment. No unrelated repository tests/audit are required.
