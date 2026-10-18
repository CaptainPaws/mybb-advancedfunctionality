<?php
/** Gallery-owned media providers. No HTTP handlers, uploads or paths in consumers. */
if (!defined('IN_MYBB')) die('No direct access');

function ag_system_album_purposes(): array
{
    return [
        'signature_background' => 'Фоны подписей',
        'signature_images' => 'Изображения подписей',
        'account_avatar' => 'Аватары аккаунта',
        'character_avatar' => 'Аватары персонажа',
        'appearance_avatars' => 'Аватары оформления',
        'appearance_postbit_bg' => 'Фоны постбитов',
        'appearance_profile_bg' => 'Фоны профилей',
        'appearance_sheet_bg' => 'Фоны листов персонажей',
        'appearance_theme_bg' => 'Фоны тем',
    ];
}

/** Additive MySQL migration, only invoked by install/activate/upgrade. */
function ag_ensure_system_album_schema(): void
{
    global $db;
    if (!$db->table_exists('af_gallery_albums') || !$db->table_exists('af_gallery_media')) return;
    if (!ag_album_field_exists('af_gallery_albums', 'system_key')) {
        $db->write_query('ALTER TABLE '.TABLE_PREFIX.'af_gallery_albums ADD system_key VARCHAR(64) NULL DEFAULT NULL');
    }
    $index = $db->write_query("SHOW INDEX FROM ".TABLE_PREFIX."af_gallery_albums WHERE Key_name='uid_system_key'");
    if (!$db->fetch_array($index)) {
        $db->write_query('ALTER TABLE '.TABLE_PREFIX.'af_gallery_albums ADD UNIQUE KEY uid_system_key (uid_owner, system_key)');
    }
    if (!ag_album_field_exists('af_gallery_media', 'appearance_retained')) {
        $db->write_query('ALTER TABLE '.TABLE_PREFIX.'af_gallery_media ADD appearance_retained TINYINT(1) NOT NULL DEFAULT 0');
    }
    unset($GLOBALS['ag_system_album_schema_ready']);
}

function ag_system_album_schema_available(): bool
{
    global $db;
    if (!array_key_exists('ag_system_album_schema_ready', $GLOBALS)) {
        $ready = $db->table_exists('af_gallery_albums')
            && ag_album_field_exists('af_gallery_albums', 'system_key')
            && ag_album_field_exists('af_gallery_media', 'appearance_retained');
        if ($ready) {
            $index = $db->write_query("SHOW INDEX FROM ".TABLE_PREFIX."af_gallery_albums WHERE Key_name='uid_system_key' AND Non_unique=0");
            $ready = (bool)$db->fetch_array($index);
        }
        $GLOBALS['ag_system_album_schema_ready'] = $ready;
    }
    return $GLOBALS['ag_system_album_schema_ready'];
}

function ag_is_system_album(array $album): bool
{
    return (string)($album['system_key'] ?? '') !== '';
}

function ag_media_owner_access(int $uid): bool
{
    global $mybb;
    return ag_can_view() && $uid > 0
        && ((int)($mybb->user['uid'] ?? 0) === $uid || ag_can_moderate());
}

/** Pure lookup: never creates albums or performs DDL. */
function ag_get_system_album(int $uid, string $purpose): ?array
{
    global $db;
    if (!isset(ag_system_album_purposes()[$purpose]) || !ag_media_owner_access($uid)
        || !ag_system_album_schema_available()) return null;
    $row = $db->fetch_array($db->simple_select('af_gallery_albums', '*',
        "uid_owner='{$uid}' AND system_key='".$db->escape_string($purpose)."'", ['limit' => 1]));
    return $row ?: null;
}

/** Call only at the save boundary. UNIQUE + atomic upsert handles concurrent requests. */
function ag_get_or_create_system_album(int $uid, string $purpose): int
{
    global $db, $mybb;
    $purposes = ag_system_album_purposes();
    if (!isset($purposes[$purpose])) throw new InvalidArgumentException('Unknown Gallery purpose.');
    if (!ag_can_view() || !ag_can_upload() || $uid <= 0 || (int)$mybb->user['uid'] !== $uid) {
        throw new RuntimeException('Gallery upload permission required for this owner.');
    }
    if (!ag_system_album_schema_available()) throw new RuntimeException('Activate/upgrade AdvancedGallery first.');
    $key = $db->escape_string($purpose);
    $title = $db->escape_string($purposes[$purpose]);
    $db->write_query("INSERT INTO ".TABLE_PREFIX."af_gallery_albums
        (uid_owner, system_key, visibility, allowed_groups, title, description, cover_media_id, sort_mode, created_at, updated_at, is_default)
        VALUES ({$uid}, '{$key}', 'private', '', '{$title}', '', 0, 'date_desc', ".TIME_NOW.", ".TIME_NOW.", 0)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $album = ag_get_system_album($uid, $purpose);
    if (!$album) throw new RuntimeException('System album could not be resolved.');
    return (int)$album['id'];
}

/** Ownership proof, including pending rows for the owner's private preview. */
function ag_get_owned_media(int $uid, int $mediaId): ?array
{
    global $db;
    if ($mediaId <= 0 || !ag_media_owner_access($uid)) return null;
    $row = $db->fetch_array($db->simple_select('af_gallery_media', '*',
        "id='{$mediaId}' AND uid_owner='{$uid}'", ['limit' => 1]));
    return $row ?: null;
}

/** Request-local batch URL seed. Ownership writers still read/check owned rows directly. */
function ag_preload_media_ids(array $mediaIds): void {
    global $db;
    $cache=&$GLOBALS['ag_media_url_rows'];if (!is_array($cache)) $cache=[];
    $ids=array_values(array_unique(array_filter(array_map('intval',$mediaIds),static fn($id)=>$id>0 && !array_key_exists($id,$cache))));
    if (!$ids || !$db->table_exists('af_gallery_media')) return;
    foreach ($ids as $id) $cache[$id]=null;
    $q=$db->simple_select('af_gallery_media','*','id IN ('.implode(',',$ids).')');
    while ($row=$db->fetch_array($q)) $cache[(int)$row['id']]=$row;
}

/** Public URL lookup is not ownership proof. Published approved images need no album access. */
function ag_resolve_media_url(int $mediaId, string $variant): string
{
    global $db;
    if ($mediaId <= 0 || !in_array($variant, ['full', 'preview', 'thumb'], true)) return '';
    $media = array_key_exists($mediaId,$GLOBALS['ag_media_url_rows']??[]) ? $GLOBALS['ag_media_url_rows'][$mediaId]
        : $db->fetch_array($db->simple_select('af_gallery_media', '*', "id='{$mediaId}'", ['limit' => 1]));
    if (!$media || !ag_media_is_direct_image($media)
        || (($media['status'] ?? '') !== 'approved' && !ag_media_owner_access((int)$media['uid_owner']))) return '';
    if (($media['type'] ?? '') === 'remote') {
        $url = (string)($variant === 'thumb' ? ($media['thumb_path'] ?: $media['remote_url']) : $media['remote_url']);
        return preg_match('~^https?://~i', $url) ? $url : '';
    }
    $path = (string)($media['storage_path'] ?? '');
    if ($variant === 'preview') $path = (string)($media['preview_path'] ?: $path);
    if ($variant === 'thumb') $path = (string)($media['thumb_path'] ?: ($media['preview_path'] ?: $path));
    return ag_local_media_url($path);
}

function ag_local_media_url(string $path): string
{
    global $mybb;
    if ($path === '' || preg_match('~(?:^|/)\.\.(?:/|$)|[\\\\\x00-\x20]|^[a-z]+:~i', $path)) return '';
    return rtrim((string)($mybb->settings['bburl'] ?? ''), '/').'/'.ltrim($path, '/');
}

/** Conservative retention: no unpin until a future reference-aware GC policy exists. */
function ag_retain_owned_media(int $uid, int $mediaId): bool
{
    global $db, $mybb;
    if (!ag_can_view() || !ag_system_album_schema_available() || (int)($mybb->user['uid'] ?? 0) !== $uid) return false;
    $media = ag_get_owned_media($uid, $mediaId);
    if (!$media || $media['status'] !== 'approved' || $media['type'] !== 'local'
        || !in_array($media['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
        || ag_resolve_media_url($mediaId, 'full') === '') return false;
    // Conditional update avoids retaining a row concurrently rejected by moderation.
    $db->update_query('af_gallery_media', ['appearance_retained' => 1],
        "id='{$mediaId}' AND uid_owner='{$uid}' AND status='approved'");
    $current = ag_get_owned_media($uid, $mediaId);
    return $current && $current['status'] === 'approved' && !empty($current['appearance_retained']);
}

function ag_media_is_retained(array $media): bool
{
    return !empty($media['appearance_retained']);
}

function ag_handle_picker_select(): void
{
    global $mybb;
    if ($mybb->request_method !== 'post') error_no_permission();
    verify_post_check($mybb->get_input('my_post_key'));
    $purpose = ag_request_appearance_purpose();
    if ($purpose === '') ag_json_response(['ok' => false, 'error' => 'Appearance purpose required.'], 422);
    $id = $mybb->get_input('media_id', MyBB::INPUT_INT);
    if (in_array($purpose,['account_avatar','character_avatar'],true)) {
        try {$source=ag_avatar_source($id,$purpose);} catch (RuntimeException $e) {ag_json_response(['ok'=>false,'error'=>$e->getMessage()],422);}
        unset($source['path']);
        ag_json_response(['ok'=>true]+$source);
        return;
    }
    if (!ag_can_upload()) error_no_permission();
    if (!ag_retain_owned_media((int)$mybb->user['uid'], $id)) {
        ag_json_response(['ok' => false, 'error' => 'Image is not owned or not approved.'], 403);
    }
    ag_json_response(['ok' => true, 'media_id' => $id, 'url_full' => ag_resolve_media_url($id, 'full')]);
}

function ag_request_appearance_purpose(): string
{
    global $mybb;
    $purpose = trim((string)$mybb->get_input('purpose'));
    if ($purpose !== '' && !isset(ag_system_album_purposes()[$purpose])) error('Unknown Gallery purpose.');
    return $purpose;
}

/** Never create a system/default album when merely opening an Appearance picker. */
function ag_picker_initial_album(int $uid, string $purpose): int
{
    if ($purpose === '' || in_array($purpose,['account_avatar','character_avatar'],true)) return -1;
    $album = ag_get_system_album($uid, $purpose);
    return $album ? (int)$album['id'] : -2; // missing-purpose filter, not all media
}

/** Optional total owner storage quota; ordinary/system/retained files all count. */
function ag_enforce_storage_limit(int $uid, int $additionalBytes): void
{
    global $db;
    $limitMb = (int)af_ag_get_setting('max_storage_mb', 0);
    if ($limitMb <= 0) return;
    ag_lock_owner_upload($uid);
    $used = (int)$db->fetch_field($db->simple_select('af_gallery_media', 'SUM(filesize) AS used', "uid_owner='{$uid}'"), 'used');
    if ($used + max(0, $additionalBytes) > $limitMb * 1024 * 1024) error('Gallery storage limit reached.');
}

/** Preserve private system media browsing, without changing direct file delivery. */
function ag_can_browse_media(array $media): bool
{
    global $db, $mybb;
    if (ag_media_owner_access((int)$media['uid_owner'])) return true;
    if (!ag_system_album_schema_available()) return true;
    $q = $db->write_query('SELECT a.id FROM '.TABLE_PREFIX.'af_gallery_albums a INNER JOIN '
        .TABLE_PREFIX.'af_gallery_album_media am ON am.album_id=a.id WHERE am.media_id='.(int)$media['id'].' AND a.system_key IS NOT NULL LIMIT 1');
    return !$db->fetch_array($q);
}

function ag_ensure_storage_setting(): void
{
    global $db;
    $existing = $db->simple_select('settings', 'sid', "name='af_advancedgallery_max_storage_mb'", ['limit' => 1]);
    if ($db->fetch_field($existing, 'sid')) return;
    $gid = af_ag_ensure_group('af_advancedgallery', 'AF: Галерея', 'Настройки галереи изображений.');
    af_ag_ensure_setting($gid, 'af_advancedgallery_max_storage_mb', 'Общий объём Gallery (МБ)',
        'Все собственные файлы, включая служебные альбомы и сохранённые изображения. 0 = без ограничения.', 'text', '0', 19);
}

/** Validate the destination before saving bytes; create a system album only after success. */
function ag_prepare_upload_album(int $uid, string $purpose): int
{
    global $mybb;
    if ($purpose !== '') {
        $album = ag_get_system_album($uid, $purpose);
        return $album ? (int)$album['id'] : 0;
    }
    $id = $mybb->get_input('album_id', MyBB::INPUT_INT);
    $album = $id > 0 ? ag_get_album($id) : null;
    return $album && (int)$album['uid_owner'] === $uid ? $id : ag_get_default_album_id($uid, true);
}

/** Serialize deletion against retention's UPDATE; no successful selection can lose its file. */
function ag_delete_unretained_media(int $mediaId): bool
{
    global $db;
    $db->write_query('START TRANSACTION');
    try {
        $q = $db->write_query('SELECT * FROM '.TABLE_PREFIX.'af_gallery_media WHERE id='.(int)$mediaId.' FOR UPDATE');
        $media = $db->fetch_array($q);
        if (!$media || !ag_can_delete_media($media) || ag_media_is_retained($media)) {
            $db->write_query('ROLLBACK');
            return false;
        }
        $albums = $db->simple_select('af_gallery_album_media', 'album_id', "media_id='{$mediaId}'");
        $affected = [];
        while ($row = $db->fetch_array($albums)) $affected[] = (int)$row['album_id'];
        $db->delete_query('af_gallery_album_media', "media_id='{$mediaId}'");
        $db->update_query('af_gallery_albums', ['cover_media_id' => 0, 'updated_at' => TIME_NOW], "cover_media_id='{$mediaId}'");
        if ($affected) $db->update_query('af_gallery_albums', ['updated_at' => TIME_NOW], 'id IN ('.implode(',', $affected).')');
        $db->delete_query('af_gallery_media', "id='{$mediaId}'");
        $db->write_query('COMMIT');
    } catch (Throwable $e) {
        $db->write_query('ROLLBACK');
        throw $e;
    }
    if ($media['type'] === 'local') {
        foreach (array_unique([$media['storage_path'], $media['thumb_path'], $media['preview_path']]) as $path) {
            $absolute = af_ag_abs_path((string)$path);
            if ($absolute && is_file($absolute)) @unlink($absolute);
        }
    }
    return true;
}

/** Serialize quota check through the successful upload insert; release even on HTTP errors. */
function ag_lock_owner_upload(int $uid): void
{
    global $db;
    static $locked = [];
    if (isset($locked[$uid])) return;
    $name = 'af_gallery_upload_'.sha1(TABLE_PREFIX.':'.$uid);
    $q = $db->write_query("SELECT GET_LOCK('".$name."', 10) AS acquired");
    if ((int)$db->fetch_field($q, 'acquired') !== 1) error('Upload busy; please retry.');
    $locked[$uid] = true;
    register_shutdown_function(static function () use ($db, $name): void {
        $db->write_query("SELECT RELEASE_LOCK('".$name."')");
    });
}
