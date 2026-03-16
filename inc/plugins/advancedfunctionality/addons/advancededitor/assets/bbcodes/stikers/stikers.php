<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

if (!defined('AF_AE_ID')) {
    define('AF_AE_ID', 'advancededitor');
}
if (!defined('AF_AE_STIKERS_CATEGORY_TABLE')) {
    define('AF_AE_STIKERS_CATEGORY_TABLE', 'af_ae_stikers_categories');
}
if (!defined('AF_AE_STIKERS_TABLE')) {
    define('AF_AE_STIKERS_TABLE', 'af_ae_stikers');
}
if (!defined('AF_AE_STIKERS_RECENT_TABLE')) {
    define('AF_AE_STIKERS_RECENT_TABLE', 'af_ae_stikers_recent');
}

function af_advancededitor_stikers_assets_rel_base(): string
{
    return 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/';
}

function af_advancededitor_stikers_rel(): string
{
    return af_advancededitor_stikers_assets_rel_base() . 'stikers/';
}

function af_advancededitor_stikers_abs(): string
{
    return MYBB_ROOT . af_advancededitor_stikers_rel();
}

function af_advancededitor_stikers_url(string $rel = ''): string
{
    global $mybb;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/' . trim(af_advancededitor_stikers_rel(), '/');

    if ($rel === '') {
        return $base . '/';
    }

    return $base . '/' . ltrim($rel, '/');
}

function af_advancededitor_stikers_get_user_folder(int $uid): string
{
    return rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR . 'stikers' . $uid;
}

function af_advancededitor_stikers_is_path_inside(string $path, string $base): bool
{
    $realPath = @realpath($path);
    $realBase = @realpath($base);

    if ($realPath !== false && $realBase !== false) {
        $realBase = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($realPath, $realBase) === 0;
        }

        return strpos($realPath, $realBase) === 0;
    }

    $pathNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $baseNorm = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (DIRECTORY_SEPARATOR === '\\') {
        return stripos($pathNorm, $baseNorm) === 0;
    }

    return strpos($pathNorm, $baseNorm) === 0;
}

function af_advancededitor_stikers_current_action(): string
{
    global $mybb;

    if (isset($mybb) && is_object($mybb) && method_exists($mybb, 'get_input')) {
        $action = (string)$mybb->get_input('action');
        if ($action !== '') {
            return $action;
        }
    }

    if (isset($_REQUEST['action'])) {
        return (string)$_REQUEST['action'];
    }

    return '';
}

function af_advancededitor_stikers_is_ajax_action(?string $action = null): bool
{
    if ($action === null) {
        $action = af_advancededitor_stikers_current_action();
    }

    return in_array($action, [
        'af_ae_stikers_list',
        'af_ae_stikers_upload',
        'af_ae_stikers_delete',
        'af_ae_stikers_recent',
    ], true);
}

function af_advancededitor_stikers_slugify(string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return 'obshee';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if (is_string($converted) && $converted !== '') {
            $title = $converted;
        }
    }

    $title = strtolower($title);
    $title = preg_replace('~[^a-z0-9]+~', '_', $title);
    $title = trim((string)$title, '_');

    return $title !== '' ? $title : 'obshee';
}

function af_advancededitor_stikers_allowed_exts(): array
{
    return ['webp', 'gif', 'png', 'jpg', 'jpeg'];
}

function af_advancededitor_stikers_max_size(): int
{
    return 5 * 1024 * 1024;
}

function af_advancededitor_stikers_max_per_user(): int
{
    return 60;
}
function af_advancededitor_stikers_settings_file(): string
{
    return MYBB_ROOT . 'inc/plugins/advancedfunctionality/cache/advancededitor_stikers.json';
}

function af_advancededitor_stikers_settings_defaults(): array
{
    return [
        'thumb_size' => 50,
        'post_size' => 120,
    ];
}

function af_advancededitor_stikers_normalize_size(int $value, int $default): int
{
    if ($value <= 0) {
        $value = $default;
    }

    if ($value < 24) {
        $value = 24;
    }

    if ($value > 512) {
        $value = 512;
    }

    return $value;
}

function af_advancededitor_stikers_get_settings(): array
{
    $defaults = af_advancededitor_stikers_settings_defaults();
    $file = af_advancededitor_stikers_settings_file();

    if (!is_file($file)) {
        return $defaults;
    }

    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }

    return [
        'thumb_size' => af_advancededitor_stikers_normalize_size((int)($data['thumb_size'] ?? $defaults['thumb_size']), $defaults['thumb_size']),
        'post_size' => af_advancededitor_stikers_normalize_size((int)($data['post_size'] ?? $defaults['post_size']), $defaults['post_size']),
    ];
}

function af_advancededitor_stikers_save_settings(array $input, string &$message = ''): bool
{
    $file = af_advancededitor_stikers_settings_file();
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_dir($dir)) {
        $message = 'Не удалось создать папку для настроек стикеров.';
        return false;
    }

    $defaults = af_advancededitor_stikers_settings_defaults();
    $data = [
        'thumb_size' => af_advancededitor_stikers_normalize_size((int)($input['thumb_size'] ?? $defaults['thumb_size']), $defaults['thumb_size']),
        'post_size' => af_advancededitor_stikers_normalize_size((int)($input['post_size'] ?? $defaults['post_size']), $defaults['post_size']),
    ];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || $json === '') {
        $message = 'Не удалось сериализовать настройки стикеров.';
        return false;
    }

    $ok = @file_put_contents($file, $json, LOCK_EX);
    if ($ok === false) {
        $message = 'Не удалось сохранить настройки стикеров.';
        return false;
    }

    $message = 'Настройки стикеров сохранены.';
    return true;
}
function af_advancededitor_stikers_json(array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_advancededitor_stikers_ensure_base_dir(): bool
{
    $dir = af_advancededitor_stikers_abs();
    if (is_dir($dir)) {
        return true;
    }

    @mkdir($dir, 0775, true);
    return is_dir($dir);
}

function af_advancededitor_stikers_ensure_default_category(): int
{
    global $db;

    af_advancededitor_stikers_ensure_base_dir();

    $slug = 'obshee';
    $q = $db->simple_select(
        AF_AE_STIKERS_CATEGORY_TABLE,
        '*',
        "slug='" . $db->escape_string($slug) . "'",
        ['limit' => 1]
    );
    $row = $db->fetch_array($q);

    if (!empty($row['id'])) {
        return (int)$row['id'];
    }

    $dir = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return (int)$db->insert_query(AF_AE_STIKERS_CATEGORY_TABLE, [
        'title' => $db->escape_string('общее'),
        'slug' => $db->escape_string($slug),
        'path' => $db->escape_string($dir),
        'sortorder' => 0,
        'created_at' => TIME_NOW,
    ]);
}

function af_advancededitor_stikers_ensure_schema(): void
{
    global $db;

    $collation = $db->build_create_table_collation();

    if (!$db->table_exists(AF_AE_STIKERS_CATEGORY_TABLE)) {
        $db->write_query(
            "CREATE TABLE " . TABLE_PREFIX . AF_AE_STIKERS_CATEGORY_TABLE . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                path VARCHAR(255) NOT NULL DEFAULT '',
                sortorder INT UNSIGNED NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=MyISAM {$collation};"
        );
    }

    if (!$db->table_exists(AF_AE_STIKERS_TABLE)) {
        $db->write_query(
            "CREATE TABLE " . TABLE_PREFIX . AF_AE_STIKERS_TABLE . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                path VARCHAR(255) NOT NULL,
                url VARCHAR(255) NOT NULL,
                ext VARCHAR(12) NOT NULL DEFAULT '',
                is_user_sticker TINYINT(1) NOT NULL DEFAULT 0,
                uid INT UNSIGNED NOT NULL DEFAULT 0,
                category_id INT UNSIGNED NOT NULL DEFAULT 0,
                sortorder INT UNSIGNED NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY category_id (category_id),
                KEY uid (uid),
                KEY is_user_sticker (is_user_sticker)
            ) ENGINE=MyISAM {$collation};"
        );
    }

    if (!$db->table_exists(AF_AE_STIKERS_RECENT_TABLE)) {
        $db->write_query(
            "CREATE TABLE " . TABLE_PREFIX . AF_AE_STIKERS_RECENT_TABLE . " (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                uid INT UNSIGNED NOT NULL DEFAULT 0,
                sticker_id INT UNSIGNED NOT NULL DEFAULT 0,
                sticker_url VARCHAR(255) NOT NULL DEFAULT '',
                used_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY uid (uid),
                KEY used_at (used_at)
            ) ENGINE=MyISAM {$collation};"
        );
    }

    af_advancededitor_stikers_ensure_default_category();
}

function af_advancededitor_stikers_is_allowed_upload(array $file, &$error = ''): bool
{
    $error = '';

    if (empty($file['name']) || empty($file['tmp_name'])) {
        $error = 'Файл не выбран.';
        return false;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > af_advancededitor_stikers_max_size()) {
        $error = 'Размер файла превышает лимит.';
        return false;
    }

    $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, af_advancededitor_stikers_allowed_exts(), true)) {
        $error = 'Недопустимый формат файла.';
        return false;
    }

    $mime = function_exists('mime_content_type')
        ? (string)@mime_content_type((string)$file['tmp_name'])
        : '';

    if ($mime !== '' && stripos($mime, 'image/') !== 0) {
        $error = 'Загружаются только изображения.';
        return false;
    }

    return true;
}

function af_advancededitor_stikers_safe_filename(string $name): string
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $base = strtolower((string)pathinfo($name, PATHINFO_FILENAME));
    $base = trim((string)preg_replace('~[^a-z0-9_-]+~', '_', $base), '_-');

    if ($base === '') {
        $base = 'stiker';
    }

    if ($ext === '' || !in_array($ext, af_advancededitor_stikers_allowed_exts(), true)) {
        $ext = 'png';
    }

    return $base . '.' . $ext;
}

function af_advancededitor_stikers_unique_path(string $dir, string $filename): array
{
    $filename = af_advancededitor_stikers_safe_filename($filename);
    $ext = (string)pathinfo($filename, PATHINFO_EXTENSION);
    $base = (string)pathinfo($filename, PATHINFO_FILENAME);

    $final = $filename;
    $i = 1;

    while (is_file(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $final)) {
        $final = $base . '_' . $i . '.' . $ext;
        $i++;
    }

    return [$final, $ext];
}

function af_advancededitor_stikers_sync_filesystem(string &$message = ''): int
{
    global $db;

    $message = '';
    $imported = 0;
    $updated = 0;

    af_advancededitor_stikers_ensure_base_dir();

    $baseDir = rtrim(af_advancededitor_stikers_abs(), '/\\');

    $cq = $db->simple_select(
        AF_AE_STIKERS_CATEGORY_TABLE,
        '*',
        '1=1',
        ['order_by' => 'id', 'order_dir' => 'ASC']
    );

    while ($cat = $db->fetch_array($cq)) {
        $categoryId = (int)($cat['id'] ?? 0);
        $slug = trim((string)($cat['slug'] ?? ''));

        if ($categoryId <= 0 || $slug === '') {
            continue;
        }

        $dir = trim((string)($cat['path'] ?? ''));
        $fallbackDir = $baseDir . DIRECTORY_SEPARATOR . $slug;

        if ($dir === '' || !is_dir($dir)) {
            $dir = $fallbackDir;

            if (is_dir($dir) && (string)($cat['path'] ?? '') !== $dir) {
                $db->update_query(
                    AF_AE_STIKERS_CATEGORY_TABLE,
                    ['path' => $db->escape_string($dir)],
                    'id=' . $categoryId
                );
            }
        }

        if (!is_dir($dir)) {
            continue;
        }

        $files = @scandir($dir);
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $absPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;

            if (!is_file($absPath)) {
                continue;
            }

            if (!af_advancededitor_stikers_is_path_inside($absPath, af_advancededitor_stikers_abs())) {
                continue;
            }

            $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));
            if (!in_array($ext, af_advancededitor_stikers_allowed_exts(), true)) {
                continue;
            }

            $fileName = basename($absPath);
            if ($fileName === '' || $fileName === '.' || $fileName === '..') {
                continue;
            }

            $url = af_advancededitor_stikers_url($slug . '/' . $fileName);
            $title = (string)pathinfo($fileName, PATHINFO_FILENAME);
            $stickerSlug = af_advancededitor_stikers_slugify($title);

            $existing = $db->fetch_array(
                $db->simple_select(
                    AF_AE_STIKERS_TABLE,
                    '*',
                    "is_user_sticker=0 AND (path='" . $db->escape_string($absPath) . "' OR url='" . $db->escape_string($url) . "')",
                    ['limit' => 1]
                )
            );

            if (!empty($existing['id'])) {
                $update = [];

                if ((int)$existing['category_id'] !== $categoryId) {
                    $update['category_id'] = $categoryId;
                }

                if ((string)$existing['path'] !== $absPath) {
                    $update['path'] = $db->escape_string($absPath);
                }

                if ((string)$existing['url'] !== $url) {
                    $update['url'] = $db->escape_string($url);
                }

                if ((string)$existing['ext'] !== $ext) {
                    $update['ext'] = $db->escape_string($ext);
                }

                if (!empty($update)) {
                    $db->update_query(
                        AF_AE_STIKERS_TABLE,
                        $update,
                        'id=' . (int)$existing['id']
                    );
                    $updated++;
                }

                continue;
            }

            $db->insert_query(AF_AE_STIKERS_TABLE, [
                'title' => $db->escape_string($title),
                'slug' => $db->escape_string($stickerSlug),
                'path' => $db->escape_string($absPath),
                'url' => $db->escape_string($url),
                'ext' => $db->escape_string($ext),
                'is_user_sticker' => 0,
                'uid' => 0,
                'category_id' => $categoryId,
                'sortorder' => 0,
                'created_at' => (int)(@filemtime($absPath) ?: TIME_NOW),
            ]);

            $imported++;
        }
    }

    if ($imported > 0 || $updated > 0) {
        $message = 'Синхронизация завершена. Импортировано: ' . $imported . ', обновлено: ' . $updated . '.';
    }

    return $imported;
}

function af_advancededitor_stikers_get_client_config(): array
{
    global $mybb;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $sizes = af_advancededitor_stikers_get_settings();

    return [
        'enabled' => 1,
        'isAuth' => !empty($mybb->user['uid']) ? 1 : 0,
        'listUrl' => $bburl . '/misc.php?action=af_ae_stikers_list',
        'uploadUrl' => $bburl . '/misc.php?action=af_ae_stikers_upload',
        'deleteUrl' => $bburl . '/misc.php?action=af_ae_stikers_delete',
        'recentUrl' => $bburl . '/misc.php?action=af_ae_stikers_recent',
        'limits' => [
            'max_size' => af_advancededitor_stikers_max_size(),
            'max_per_user' => af_advancededitor_stikers_max_per_user(),
            'ext' => af_advancededitor_stikers_allowed_exts(),
        ],
        'sizes' => $sizes,
    ];
}

function af_advancededitor_stikers_get_front_payload(int $uid): array
{
    global $db;

    af_advancededitor_stikers_ensure_schema();

    $cats = [];
    $q = $db->query(
        "SELECT c.id, c.title, c.slug, c.sortorder, COUNT(s.id) AS cnt
         FROM " . TABLE_PREFIX . AF_AE_STIKERS_CATEGORY_TABLE . " c
         LEFT JOIN " . TABLE_PREFIX . AF_AE_STIKERS_TABLE . " s
           ON s.category_id = c.id AND s.is_user_sticker = 0
         GROUP BY c.id, c.title, c.slug, c.sortorder
         HAVING cnt > 0
         ORDER BY c.sortorder ASC, c.id ASC"
    );

    while ($row = $db->fetch_array($q)) {
        $cats[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'slug' => (string)$row['slug'],
            'stickers' => [],
        ];
    }

    if (!empty($cats)) {
        $ids = implode(',', array_map('intval', array_keys($cats)));
        $sq = $db->query(
            "SELECT id, title, url, category_id
             FROM " . TABLE_PREFIX . AF_AE_STIKERS_TABLE . "
             WHERE is_user_sticker = 0
               AND category_id IN ({$ids})
             ORDER BY sortorder ASC, id ASC"
        );

        while ($r = $db->fetch_array($sq)) {
            $cid = (int)$r['category_id'];
            if (!isset($cats[$cid])) {
                continue;
            }

            $cats[$cid]['stickers'][] = [
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'url' => (string)$r['url'],
                'category_id' => $cid,
            ];
        }
    }

    $user = [];
    if ($uid > 0) {
        $uq = $db->simple_select(
            AF_AE_STIKERS_TABLE,
            'id,title,url',
            "is_user_sticker=1 AND uid={$uid}",
            ['order_by' => 'id', 'order_dir' => 'DESC']
        );

        while ($r = $db->fetch_array($uq)) {
            $user[] = [
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'url' => (string)$r['url'],
            ];
        }
    }

    $recent = [];
    if ($uid > 0) {
        $orphanRecentIds = [];

        $rq = $db->query(
            "SELECT
                r.id AS recent_row_id,
                r.sticker_id,
                r.used_at,
                s.id AS sticker_exists,
                s.title,
                s.url
             FROM " . TABLE_PREFIX . AF_AE_STIKERS_RECENT_TABLE . " r
             LEFT JOIN " . TABLE_PREFIX . AF_AE_STIKERS_TABLE . " s
               ON s.id = r.sticker_id
             WHERE r.uid = {$uid}
             ORDER BY r.used_at DESC
             LIMIT 50"
        );

        $seen = [];
        while ($r = $db->fetch_array($rq)) {
            $recentRowId = (int)($r['recent_row_id'] ?? 0);
            $stickerId = (int)($r['sticker_id'] ?? 0);
            $stickerExists = (int)($r['sticker_exists'] ?? 0);
            $url = trim((string)($r['url'] ?? ''));

            if ($stickerId <= 0 || $stickerExists <= 0 || $url === '') {
                if ($recentRowId > 0) {
                    $orphanRecentIds[] = $recentRowId;
                }
                continue;
            }

            $dedupeKey = $stickerId . '|' . $url;
            if (isset($seen[$dedupeKey])) {
                if ($recentRowId > 0) {
                    $orphanRecentIds[] = $recentRowId;
                }
                continue;
            }

            $seen[$dedupeKey] = true;

            $recent[] = [
                'id' => $stickerId,
                'title' => (string)($r['title'] ?: 'Стикер'),
                'url' => $url,
            ];

            if (count($recent) >= 20) {
                break;
            }
        }

        if (!empty($orphanRecentIds)) {
            $orphanRecentIds = array_values(array_unique(array_map('intval', $orphanRecentIds)));
            if (!empty($orphanRecentIds)) {
                $db->delete_query(
                    AF_AE_STIKERS_RECENT_TABLE,
                    'id IN (' . implode(',', $orphanRecentIds) . ')'
                );
            }
        }
    }

    return [
        'categories' => array_values($cats),
        'user_stickers' => $user,
        'recent' => $recent,
        'limits' => [
            'max_size' => af_advancededitor_stikers_max_size(),
            'max_per_user' => af_advancededitor_stikers_max_per_user(),
            'ext' => af_advancededitor_stikers_allowed_exts(),
        ],
        'sizes' => af_advancededitor_stikers_get_settings(),
    ];
}

function af_advancededitor_stikers_handle_ajax(): bool
{
    global $mybb, $db;

    $action = af_advancededitor_stikers_current_action();
    if (!af_advancededitor_stikers_is_ajax_action($action)) {
        return false;
    }

    $uid = (int)($mybb->user['uid'] ?? 0);

    af_advancededitor_stikers_ensure_schema();

    if ($action === 'af_ae_stikers_list') {
        af_advancededitor_stikers_json([
            'success' => true,
            'message' => '',
            'data' => af_advancededitor_stikers_get_front_payload($uid),
        ]);
    }

    if (!function_exists('verify_post_check')) {
        require_once MYBB_ROOT . 'inc/functions.php';
    }

    $postKey = (string)($mybb->input['my_post_key'] ?? $_REQUEST['my_post_key'] ?? '');
    if ($postKey === '' || !verify_post_check($postKey, true)) {
        af_advancededitor_stikers_json([
            'success' => false,
            'message' => 'Неверный my_post_key.',
            'data' => null,
        ]);
    }

    if ($action === 'af_ae_stikers_recent') {
        if ($uid <= 0) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Требуется авторизация.',
                'data' => null,
            ]);
        }

        $sid = (int)($mybb->input['sticker_id'] ?? 0);
        $url = trim((string)($mybb->input['sticker_url'] ?? ''));

        if ($sid <= 0 && $url === '') {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Не передан стикер.',
                'data' => null,
            ]);
        }

        if ($sid > 0) {
            $db->delete_query(AF_AE_STIKERS_RECENT_TABLE, "uid={$uid} AND sticker_id={$sid}");
        } else {
            $db->delete_query(
                AF_AE_STIKERS_RECENT_TABLE,
                "uid={$uid} AND sticker_url='" . $db->escape_string($url) . "'"
            );
        }

        $db->insert_query(AF_AE_STIKERS_RECENT_TABLE, [
            'uid' => $uid,
            'sticker_id' => max(0, $sid),
            'sticker_url' => $db->escape_string($url),
            'used_at' => TIME_NOW,
        ]);

        $db->query(
            "DELETE FROM " . TABLE_PREFIX . AF_AE_STIKERS_RECENT_TABLE . "
             WHERE uid={$uid}
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id
                       FROM " . TABLE_PREFIX . AF_AE_STIKERS_RECENT_TABLE . "
                       WHERE uid={$uid}
                       ORDER BY used_at DESC
                       LIMIT 20
                   ) t
               )"
        );

        af_advancededitor_stikers_json([
            'success' => true,
            'message' => '',
            'data' => null,
        ]);
    }

    if ($action === 'af_ae_stikers_upload') {
        if ($uid <= 0) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Требуется авторизация.',
                'data' => null,
            ]);
        }

        $cnt = (int)$db->fetch_field(
            $db->simple_select(AF_AE_STIKERS_TABLE, 'COUNT(id) AS c', "is_user_sticker=1 AND uid={$uid}"),
            'c'
        );

        if ($cnt >= af_advancededitor_stikers_max_per_user()) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Достигнут лимит пользовательских стикеров.',
                'data' => null,
            ]);
        }

        $file = $_FILES['sticker'] ?? null;
        $error = '';

        if (!is_array($file) || !af_advancededitor_stikers_is_allowed_upload($file, $error)) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => $error ?: 'Файл не получен.',
                'data' => null,
            ]);
        }

        $dir = af_advancededitor_stikers_get_user_folder($uid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir)) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Не удалось создать папку пользователя.',
                'data' => null,
            ]);
        }

        [$finalName, $ext] = af_advancededitor_stikers_unique_path($dir, (string)$file['name']);
        $target = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $finalName;

        if (!@move_uploaded_file((string)$file['tmp_name'], $target)) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Не удалось сохранить файл.',
                'data' => null,
            ]);
        }

        $url = af_advancededitor_stikers_url('stikers' . $uid . '/' . $finalName);

        $id = (int)$db->insert_query(AF_AE_STIKERS_TABLE, [
            'title' => $db->escape_string((string)pathinfo($finalName, PATHINFO_FILENAME)),
            'slug' => $db->escape_string((string)pathinfo($finalName, PATHINFO_FILENAME)),
            'path' => $db->escape_string($target),
            'url' => $db->escape_string($url),
            'ext' => $db->escape_string($ext),
            'is_user_sticker' => 1,
            'uid' => $uid,
            'category_id' => 0,
            'sortorder' => 0,
            'created_at' => TIME_NOW,
        ]);

        af_advancededitor_stikers_json([
            'success' => true,
            'message' => 'Стикер загружен.',
            'data' => [
                'id' => $id,
                'url' => $url,
            ],
        ]);
    }

    if ($action === 'af_ae_stikers_delete') {
        if ($uid <= 0) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Требуется авторизация.',
                'data' => null,
            ]);
        }

        $id = (int)($mybb->input['id'] ?? 0);
        if ($id <= 0) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Некорректный ID.',
                'data' => null,
            ]);
        }

        $row = $db->fetch_array(
            $db->simple_select(
                AF_AE_STIKERS_TABLE,
                '*',
                "id={$id} AND is_user_sticker=1 AND uid={$uid}",
                ['limit' => 1]
            )
        );

        if (empty($row['id'])) {
            af_advancededitor_stikers_json([
                'success' => false,
                'message' => 'Стикер не найден.',
                'data' => null,
            ]);
        }

        $path = (string)$row['path'];
        if ($path !== '' && af_advancededitor_stikers_is_path_inside($path, af_advancededitor_stikers_abs()) && is_file($path)) {
            @unlink($path);
        }

        $db->delete_query(AF_AE_STIKERS_TABLE, "id={$id}");
        $db->delete_query(AF_AE_STIKERS_RECENT_TABLE, "uid={$uid} AND sticker_id={$id}");

        af_advancededitor_stikers_json([
            'success' => true,
            'message' => 'Стикер удалён.',
            'data' => ['id' => $id],
        ]);
    }

    return true;
}

function af_advancededitor_stikers_create_category(string $title, string &$message = ''): bool
{
    global $db;

    $title = trim($title);
    if ($title === '') {
        $message = 'Введите название категории.';
        return false;
    }

    $slug = af_advancededitor_stikers_slugify($title);
    $exists = (int)$db->fetch_field(
        $db->simple_select(
            AF_AE_STIKERS_CATEGORY_TABLE,
            'id',
            "slug='" . $db->escape_string($slug) . "'",
            ['limit' => 1]
        ),
        'id'
    );

    if ($exists > 0) {
        $message = 'Категория с таким slug уже существует.';
        return false;
    }

    af_advancededitor_stikers_ensure_base_dir();

    $dir = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $db->insert_query(AF_AE_STIKERS_CATEGORY_TABLE, [
        'title' => $db->escape_string($title),
        'slug' => $db->escape_string($slug),
        'path' => $db->escape_string($dir),
        'sortorder' => 0,
        'created_at' => TIME_NOW,
    ]);

    $message = 'Категория создана.';
    return true;
}

function af_advancededitor_stikers_update_category_title(int $id, string $title, string &$message = ''): bool
{
    global $db;

    $row = $db->fetch_array(
        $db->simple_select(AF_AE_STIKERS_CATEGORY_TABLE, '*', 'id=' . $id, ['limit' => 1])
    );

    if (empty($row['id'])) {
        $message = 'Категория не найдена.';
        return false;
    }

    $title = trim($title);
    if ($title === '') {
        $message = 'Введите название категории.';
        return false;
    }

    $db->update_query(
        AF_AE_STIKERS_CATEGORY_TABLE,
        ['title' => $db->escape_string($title)],
        'id=' . $id
    );

    $message = 'Категория обновлена.';
    return true;
}

function af_advancededitor_stikers_update_admin_sticker_title(int $id, string $title, string &$message = ''): bool
{
    global $db;

    if ($id <= 0) {
        $message = 'Некорректный ID стикера.';
        return false;
    }

    $row = $db->fetch_array(
        $db->simple_select(
            AF_AE_STIKERS_TABLE,
            '*',
            'id=' . (int)$id . ' AND is_user_sticker=0',
            ['limit' => 1]
        )
    );

    if (empty($row['id'])) {
        $message = 'Стикер не найден.';
        return false;
    }

    $title = trim($title);
    if ($title === '') {
        $message = 'Введите title стикера.';
        return false;
    }

    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 255);
    } else {
        $title = substr($title, 0, 255);
    }

    $db->update_query(
        AF_AE_STIKERS_TABLE,
        [
            'title' => $db->escape_string($title),
        ],
        'id=' . (int)$id
    );

    $message = 'Title стикера обновлён.';
    return true;
}

function af_advancededitor_stikers_upload_admin(int $categoryId, array $file, string &$message = ''): bool
{
    global $db;

    if ($categoryId <= 0) {
        $categoryId = af_advancededitor_stikers_ensure_default_category();
    }

    $cat = $db->fetch_array(
        $db->simple_select(AF_AE_STIKERS_CATEGORY_TABLE, '*', 'id=' . $categoryId, ['limit' => 1])
    );

    if (empty($cat['id'])) {
        $message = 'Категория не найдена.';
        return false;
    }

    $error = '';
    if (!af_advancededitor_stikers_is_allowed_upload($file, $error)) {
        $message = $error;
        return false;
    }

    $dir = (string)$cat['path'];
    if ($dir === '') {
        $dir = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR . (string)$cat['slug'];
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    [$finalName, $ext] = af_advancededitor_stikers_unique_path($dir, (string)$file['name']);
    $target = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $finalName;

    if (!@move_uploaded_file((string)$file['tmp_name'], $target)) {
        $message = 'Не удалось сохранить файл.';
        return false;
    }

    $url = af_advancededitor_stikers_url((string)$cat['slug'] . '/' . $finalName);

    $db->insert_query(AF_AE_STIKERS_TABLE, [
        'title' => $db->escape_string((string)pathinfo($finalName, PATHINFO_FILENAME)),
        'slug' => $db->escape_string((string)pathinfo($finalName, PATHINFO_FILENAME)),
        'path' => $db->escape_string($target),
        'url' => $db->escape_string($url),
        'ext' => $db->escape_string($ext),
        'is_user_sticker' => 0,
        'uid' => 0,
        'category_id' => (int)$cat['id'],
        'sortorder' => 0,
        'created_at' => TIME_NOW,
    ]);

    $message = 'Стикер загружен.';
    return true;
}

function af_advancededitor_stikers_move_admin_sticker(int $id, int $categoryId): bool
{
    global $db;

    if ($id <= 0) {
        return false;
    }

    if ($categoryId <= 0) {
        $categoryId = af_advancededitor_stikers_ensure_default_category();
    }

    $cat = $db->fetch_array(
        $db->simple_select(
            AF_AE_STIKERS_CATEGORY_TABLE,
            '*',
            'id=' . (int)$categoryId,
            ['limit' => 1]
        )
    );

    $st = $db->fetch_array(
        $db->simple_select(
            AF_AE_STIKERS_TABLE,
            '*',
            'id=' . (int)$id . ' AND is_user_sticker=0',
            ['limit' => 1]
        )
    );

    if (empty($cat['id']) || empty($st['id'])) {
        return false;
    }

    $currentCategoryId = (int)($st['category_id'] ?? 0);
    if ($currentCategoryId === (int)$categoryId) {
        return true;
    }

    $targetDir = trim((string)($cat['path'] ?? ''));
    if ($targetDir === '') {
        $targetDir = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR . (string)$cat['slug'];
    }

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    if (!is_dir($targetDir)) {
        return false;
    }

    $oldPath = trim((string)($st['path'] ?? ''));
    $oldUrl  = trim((string)($st['url'] ?? ''));

    if ($oldPath === '' || !is_file($oldPath)) {
        $urlPath = (string)parse_url($oldUrl, PHP_URL_PATH);
        $urlFile = basename($urlPath);

        if ($urlFile !== '' && $urlFile !== '.' && $urlFile !== '..') {
            $fallbackOldPath = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR
                . trim((string)($st['category_id'] ?? 0), '/\\');

            $oldPath = '';
            $oldCategory = $db->fetch_array(
                $db->simple_select(
                    AF_AE_STIKERS_CATEGORY_TABLE,
                    '*',
                    'id=' . (int)$currentCategoryId,
                    ['limit' => 1]
                )
            );

            if (!empty($oldCategory['slug'])) {
                $oldPath = rtrim(af_advancededitor_stikers_abs(), '/\\') . DIRECTORY_SEPARATOR
                    . (string)$oldCategory['slug'] . DIRECTORY_SEPARATOR . $urlFile;
            }
        }
    }

    if ($oldPath === '' || !is_file($oldPath)) {
        return false;
    }

    $oldFilename = basename($oldPath);
    if ($oldFilename === '' || $oldFilename === '.' || $oldFilename === '..') {
        return false;
    }

    [$finalName, $ext] = af_advancededitor_stikers_unique_path($targetDir, $oldFilename);
    $newPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $finalName;

    $moved = @rename($oldPath, $newPath);
    if (!$moved) {
        $moved = @copy($oldPath, $newPath);
        if ($moved) {
            @unlink($oldPath);
        }
    }

    if (!$moved || !is_file($newPath)) {
        return false;
    }

    $newUrl = af_advancededitor_stikers_url((string)$cat['slug'] . '/' . $finalName);
    $newSlug = (string)pathinfo($finalName, PATHINFO_FILENAME);

    $db->update_query(
        AF_AE_STIKERS_TABLE,
        [
            'category_id' => (int)$categoryId,
            'path'        => $db->escape_string($newPath),
            'url'         => $db->escape_string($newUrl),
            'slug'        => $db->escape_string($newSlug),
            'ext'         => $db->escape_string($ext),
        ],
        'id=' . (int)$id
    );

    $db->update_query(
        AF_AE_STIKERS_RECENT_TABLE,
        [
            'sticker_url' => $db->escape_string($newUrl),
        ],
        'sticker_id=' . (int)$id
    );

    return true;
}

function af_advancededitor_stikers_delete_admin_sticker(int $id): bool
{
    global $db;

    if ($id <= 0) {
        return false;
    }

    $row = $db->fetch_array(
        $db->simple_select(AF_AE_STIKERS_TABLE, '*', 'id=' . $id . ' AND is_user_sticker=0', ['limit' => 1])
    );

    if (empty($row['id'])) {
        return false;
    }

    $path = (string)$row['path'];
    if ($path !== '' && af_advancededitor_stikers_is_path_inside($path, af_advancededitor_stikers_abs()) && is_file($path)) {
        @unlink($path);
    }

    $db->delete_query(AF_AE_STIKERS_TABLE, 'id=' . $id);
    $db->delete_query(AF_AE_STIKERS_RECENT_TABLE, 'sticker_id=' . $id);

    return true;
}

function af_advancededitor_stikers_delete_category_with_move(int $id, int $moveTo): bool
{
    global $db;

    if ($id <= 0) {
        return false;
    }

    $cat = $db->fetch_array(
        $db->simple_select(AF_AE_STIKERS_CATEGORY_TABLE, '*', 'id=' . $id, ['limit' => 1])
    );

    if (empty($cat['id']) || (string)$cat['slug'] === 'obshee') {
        return false;
    }

    if ($moveTo <= 0 || $moveTo === $id) {
        $moveTo = af_advancededitor_stikers_ensure_default_category();
    }

    $db->update_query(
        AF_AE_STIKERS_TABLE,
        ['category_id' => $moveTo],
        'category_id=' . $id . ' AND is_user_sticker=0'
    );

    $db->delete_query(AF_AE_STIKERS_CATEGORY_TABLE, 'id=' . $id);
    return true;
}

function af_advancededitor_stikers_pre_output(string &$page): void
{
    if (defined('IN_ADMINCP')) {
        return;
    }

    if (!is_string($page) || $page === '') {
        return;
    }

    if (strpos($page, 'id="af-ae-stikers-front-css"') !== false) {
        return;
    }

    $sizes = af_advancededitor_stikers_get_settings();
    $postSize = (int)$sizes['post_size'];
    $stickerPath = '/' . trim(af_advancededitor_stikers_rel(), '/');

    $selector =
        '.post img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.post_body img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.post_content img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.scaleimages img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.editor_content img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.preview img[src*="' . htmlspecialchars_uni($stickerPath) . '"],'
        . '.af-ae-previewparsed img[src*="' . htmlspecialchars_uni($stickerPath) . '"]';

    $css = '<style id="af-ae-stikers-front-css">'
        . $selector . '{'
        . 'width:' . $postSize . 'px !important;'
        . 'height:' . $postSize . 'px !important;'
        . 'max-width:none !important;'
        . 'max-height:none !important;'
        . 'object-fit:contain;'
        . '}'
        . '</style>';

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $css . '</head>', $page, 1);
        return;
    }

    if (stripos($page, '</body>') !== false) {
        $page = preg_replace('~</body>~i', $css . '</body>', $page, 1);
        return;
    }

    $page .= $css;
}

function af_advancededitor_stikers_boot_autodispatch(): void
{
    if (defined('IN_ADMINCP')) {
        return;
    }

    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'misc.php') {
        return;
    }

    if (!af_advancededitor_stikers_is_ajax_action()) {
        return;
    }

    af_advancededitor_stikers_handle_ajax();
    exit;
}

function af_advancededitor_stikers_boot_register_hooks(): void
{
    global $plugins;

    if (defined('IN_ADMINCP')) {
        return;
    }

    if (isset($plugins) && is_object($plugins) && method_exists($plugins, 'add_hook')) {
        $plugins->add_hook('pre_output_page', 'af_advancededitor_stikers_pre_output', 5);
    }
}

af_advancededitor_stikers_boot_register_hooks();
af_advancededitor_stikers_boot_autodispatch();
