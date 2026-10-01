# d15989d production runtime audit

## Scope and safety

This is a **read-only** forensic checklist for the production installation. It
does not authorize another source rollback, a lifecycle change, deletion of
settings or stylesheet rows, or a blanket cache purge. Capture every result
before performing recovery.

The repository reference is commit `80e9517bc2f431fd059d85cd0b86a003f266d29a`.
Commit `107248d1fbf46003be446e3edf92dfa4109570ad` has the same tree. The expected
SHA-256 values are:

| Path | SHA-256 |
| --- | --- |
| `inc/plugins/advancedfunctionality.php` | `5c3b3e64777a051f7550f4bbd41aa8d9e9180800142e475ab3ab575b6b5ab310` |
| `inc/plugins/advancedfunctionality/admin/router.php` | `18db13024d97b0dd8354d15393f91e47a8783cf6774222d4f98a3838acc9da4c` |
| `inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php` | `c6dd8ac5cfe44cbef68e2bf7f630a47597ac135df6b2d2928f13e7a0dc1abea9` |

## Evidence capture order

Run commands from the production MyBB root as the deployment user. Store the
output outside the web root.

### 1. Deployment and source

```sh
git rev-parse HEAD
git status --short
sha256sum \
  inc/plugins/advancedfunctionality.php \
  inc/plugins/advancedfunctionality/admin/router.php \
  inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php
git diff --no-index -- /path/to/exported-80e9517/inc/plugins/advancedfunctionality.php inc/plugins/advancedfunctionality.php
git diff --no-index -- /path/to/exported-80e9517/inc/plugins/advancedfunctionality/admin/router.php inc/plugins/advancedfunctionality/admin/router.php
git diff --no-index -- /path/to/exported-80e9517/inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php
```

Do not replace a differing file until all three diffs have been retained. A
matching Git worktree elsewhere on the host is not evidence about the web
server document root; resolve the document root from the active virtual host or
PHP-FPM pool.

### 2. OPcache (same SAPI as production)

CLI settings are not sufficient when HTTP uses PHP-FPM. Capture these values
through an already approved server-side administration mechanism using the FPM
SAPI:

```php
var_export([
    'sapi' => PHP_SAPI,
    'opcache.enable' => ini_get('opcache.enable'),
    'opcache.validate_timestamps' => ini_get('opcache.validate_timestamps'),
    'opcache.revalidate_freq' => ini_get('opcache.revalidate_freq'),
    'status' => function_exists('opcache_get_status')
        ? opcache_get_status(false)
        : null,
]);
```

Also record the resolved FPM configuration (`php-fpm -i`/pool configuration)
and service start time. Do not call `opcache_reset()` during evidence capture.
If source differs, deploy only current `main`, then invalidate the three changed
scripts or perform the normal PHP-FPM reload; do not touch the database.

### 3. Enabled-setting state

Replace `mybb_` only with the installation's configured table prefix. These
queries are read-only:

```sql
SELECT sid, name, value, gid, disporder
FROM mybb_settings
WHERE name LIKE 'af\_%\_enabled'
ORDER BY name, sid;

SELECT name, COUNT(*) AS row_count,
       GROUP_CONCAT(CONCAT(sid, ':', value, ':gid=', gid) ORDER BY sid) AS rows_seen
FROM mybb_settings
WHERE name LIKE 'af\_%\_enabled'
GROUP BY name
HAVING COUNT(*) > 1;
```

Export the resulting rows. Separately capture the corresponding keys loaded in
`$mybb->settings` during an authenticated request and the `$settings` array in
`inc/settings.php`. Compare by key and string value. Do not delete duplicates
manually: the baseline `AF_Admin::ensureEnabledSetting()` path is the supported
normalizer when an addon is deliberately enabled or disabled.

### 4. Stylesheet and generated state

Take read-only exports before using any reconciliation action:

```sql
SELECT * FROM mybb_af_theme_stylesheets ORDER BY tid, addon_id, logical_id, id;
SELECT * FROM mybb_themestylesheets ORDER BY tid, name, sid;
SELECT tid, name, value FROM mybb_themes ORDER BY tid, name;
```

Record hashes, owners, permissions, and mtimes for `cache/themes`, every
`advancedstyles.css`, and AF's `inc/plugins/advancedfunctionality/cache`
artifacts:

```sh
find cache/themes inc/plugins/advancedfunctionality/cache \
  -type f -printf '%p\t%s\t%T@\t%u:%g\n' -exec sha256sum {} \;
find . -type f -name advancedstyles.css \
  -printf '%p\t%s\t%T@\t%u:%g\n' -exec sha256sum {} \;
```

Compare registry source hashes and bundle sections with the current manifest
seeds. If reconciliation is required, use the existing idempotent **Rebuild
missing** or addon synchronization action only after the export; never delete
stylesheet rows directly.

### 5. Fatal and control addon

Use one simple addon that was unchanged by the regression and has no migration
or AdvancedEditor, Buddy alias, or AdvancedMenu dependency. Record the log
cursor/timestamp, activate it once, and then deactivate it once. Preserve the
complete PHP-FPM/web-server error entries for both operations, including error
text, file, line, and stack trace. Do not install a new debug framework.

If a frame names `af_lifecycle_transition()` in either core or generated
router, the executing script is not the baseline tree because that function is
absent from `80e9517`. Correlate the frame's absolute path with the active
document root and OPcache script list.

## Recovery decision

Only act after evidence identifies one branch:

1. **Source mismatch:** deploy current `main`, invalidate only affected opcode
   entries (or use the normal FPM reload), retest, and leave DB state untouched.
2. **Stale opcode:** invalidate affected scripts or use the normal FPM reload,
   then retest without changing source or DB.
3. **Persistent generated state:** retain exports and use only the applicable
   idempotent settings/stylesheet rebuild API.
4. **Different exact fatal:** repair only the demonstrated cause in a separately
   reviewed change.

The audit is not complete until its report includes deployed SHA, all three
production hashes, FPM OPcache values, duplicate-setting results, stylesheet
registry/cache findings, both exact traces, residual state attributed to the
bad deployment, the minimal recovery performed, and explicit confirmation that
no manual DB cleanup occurred.
