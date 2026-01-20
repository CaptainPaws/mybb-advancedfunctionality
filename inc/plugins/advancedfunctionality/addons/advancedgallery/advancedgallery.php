<?php
/**
 * AF Addon: AdvancedGallery
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AG_ID', 'advancedgallery');
define('AF_AG_VER', '1.0.0');
define('AF_AG_BASE', AF_ADDONS . AF_AG_ID . '/');
define('AF_AG_ASSETS', AF_AG_BASE . 'assets/');
define('AF_AG_TPL_FILE', AF_AG_BASE . 'templates/advancedgallery.html');
define('AF_AG_ALIAS_SIGNATURE', 'AF_PAGE_ALIAS: advancedgallery');
define('AF_AG_MARK_DONE', '<!--af_advancedgallery_done-->');

/* -------------------- INFO -------------------- */

function af_advancedgallery_info(): array
{
    return [
        'name'          => 'AdvancedGallery',
        'description'   => 'Галерея изображений с загрузкой и модерацией.',
        'version'       => AF_AG_VER,
        'author'        => 'AdvancedFunctionality',
        'authorsite'    => '',
    ];
}

/* -------------------- LANG -------------------- */
function af_advancedgallery_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    // ВАЖНО: MyBB сам добавляет ".lang.php", поэтому передаём имя БЕЗ расширения
    $base = 'advancedfunctionality_' . AF_AG_ID;

    // Подстраховка: если язык для аддона ещё не сгенерен AF-ядром — попробуем синкануть
    // и повторить загрузку, но без фатала.
    $langFolder = !empty($lang->language) ? (string)$lang->language : 'russian';
    $expectedFile = MYBB_ROOT . 'inc/languages/' . $langFolder . '/' . $base . '.lang.php';

    if (!is_file($expectedFile) && function_exists('af_sync_addon_languages')) {
        // AF core: подтянет языки аддонов из manifest.php
        // (если он у тебя именно так работает — как мы и договаривались)
        try {
            af_sync_addon_languages();
        } catch (Throwable $e) {
            // молча: не валим фронт из-за языка
        }
    }

    // Если файла всё ещё нет — просто выходим, чтобы не ловить фатал
    if (!is_file($expectedFile)) {
        return;
    }

    if ($admin) {
        $lang->load($base, true, true);
    } else {
        $lang->load($base);
    }
}


/* -------------------- SETTINGS HELPERS -------------------- */

function af_ag_setting_name(string $key): string
{
    return 'af_' . AF_AG_ID . '_' . $key;
}

function af_ag_get_setting(string $key, $default = null)
{
    global $mybb;
    $name = af_ag_setting_name($key);
    return $mybb->settings[$name] ?? $default;
}

function af_ag_ensure_group(string $name, string $title, string $desc): int
{
    global $db;
    $q = $db->simple_select('settinggroups', 'gid', "name='".$db->escape_string($name)."'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) {
        return $gid;
    }

    $max = $db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $db->insert_query('settinggroups', [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder'   => $disp,
        'isdefault'   => 0,
    ]);

    return (int)$db->insert_id();
}

function af_ag_ensure_setting(int $gid, string $name, string $title, string $desc, string $type, string $value, int $order): void
{
    global $db;
    $q = $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]);
    $sid = (int)$db->fetch_field($q, 'sid');

    $row = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => $order,
        'gid'         => $gid,
    ];

    if ($sid) {
        $db->update_query('settings', $row, "sid={$sid}");
    } else {
        $db->insert_query('settings', $row);
    }
}

/* -------------------- INSTALL / UNINSTALL -------------------- */
function af_advancedgallery_install(): bool
{
    global $db, $lang, $mybb;

    af_advancedgallery_load_lang(true);

    if (!$db->table_exists('af_gallery_media')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_gallery_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uid_owner INT NOT NULL,
  type ENUM('local','remote') NOT NULL DEFAULT 'local',
  status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  created_at INT NOT NULL,
  updated_at INT NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  tags VARCHAR(255) NOT NULL DEFAULT '',
  views INT NOT NULL DEFAULT 0,
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  storage_path VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(80) NOT NULL DEFAULT '',
  ext VARCHAR(10) NOT NULL DEFAULT '',
  filesize INT NOT NULL DEFAULT 0,
  width INT NOT NULL DEFAULT 0,
  height INT NOT NULL DEFAULT 0,
  thumb_path VARCHAR(255) NOT NULL DEFAULT '',
  preview_path VARCHAR(255) NOT NULL DEFAULT '',
  remote_url VARCHAR(500) NOT NULL DEFAULT '',
  provider VARCHAR(50) NOT NULL DEFAULT '',
  embed_html MEDIUMTEXT NOT NULL,
  KEY uid_owner (uid_owner),
  KEY status (status),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sql = str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql);
        $db->write_query($sql);
    }

    if (!$db->table_exists('af_gallery_logs')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_gallery_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uid_actor INT NOT NULL,
  uid_owner INT NOT NULL DEFAULT 0,
  media_id INT NOT NULL DEFAULT 0,
  action VARCHAR(50) NOT NULL,
  details TEXT NOT NULL,
  created_at INT NOT NULL,
  KEY uid_actor (uid_actor),
  KEY media_id (media_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sql = str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql);
        $db->write_query($sql);
    }

    if (!$db->table_exists('af_gallery_albums')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_gallery_albums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uid_owner INT NOT NULL,
  visibility ENUM('public','registered','groups','private') NOT NULL DEFAULT 'public',
  allowed_groups VARCHAR(255) NOT NULL DEFAULT '',
  title VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  cover_media_id INT NOT NULL DEFAULT 0,
  sort_mode ENUM('manual','date_desc','date_asc') NOT NULL DEFAULT 'date_desc',
  created_at INT NOT NULL,
  updated_at INT NOT NULL,
  KEY uid_owner (uid_owner),
  KEY visibility (visibility),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sql = str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql);
        $db->write_query($sql);
    }

    if (!$db->table_exists('af_gallery_album_media')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_gallery_album_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  album_id INT NOT NULL,
  media_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at INT NOT NULL,
  UNIQUE KEY album_media (album_id, media_id),
  KEY album_id (album_id),
  KEY media_id (media_id),
  KEY sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sql = str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql);
        $db->write_query($sql);
    }

    $gid = af_ag_ensure_group(
        'af_advancedgallery',
        $lang->af_advancedgallery_group ?? 'AF: Галерея',
        $lang->af_advancedgallery_group_desc ?? 'Настройки аддона AdvancedGallery.'
    );

    af_ag_ensure_setting($gid, 'af_advancedgallery_enabled',
        $lang->af_advancedgallery_enabled ?? 'Включить галерею',
        $lang->af_advancedgallery_enabled_desc ?? 'Да/Нет',
        'yesno', '1', 1
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_items_per_page',
        $lang->af_advancedgallery_items_per_page ?? 'Элементов на страницу',
        $lang->af_advancedgallery_items_per_page_desc ?? 'Сколько карточек выводить на странице.',
        'numeric', '24', 2
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_upload_max_mb',
        $lang->af_advancedgallery_upload_max_mb ?? 'Макс. размер файла (МБ)',
        $lang->af_advancedgallery_upload_max_mb_desc ?? 'Ограничение на размер файла загрузки.',
        'numeric', '10', 3
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_allowed_ext',
        $lang->af_advancedgallery_allowed_ext ?? 'Разрешённые расширения',
        $lang->af_advancedgallery_allowed_ext_desc ?? 'Список через запятую.',
        'text', 'jpg,jpeg,png,gif,webp', 4
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_thumb_w',
        $lang->af_advancedgallery_thumb_w ?? 'Ширина превью',
        $lang->af_advancedgallery_thumb_w_desc ?? 'Ширина превью в пикселях.',
        'numeric', '320', 5
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_thumb_h',
        $lang->af_advancedgallery_thumb_h ?? 'Высота превью',
        $lang->af_advancedgallery_thumb_h_desc ?? 'Высота превью в пикселях.',
        'numeric', '320', 6
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_can_upload_groups',
        $lang->af_advancedgallery_can_upload_groups ?? 'Группы с правом загрузки',
        $lang->af_advancedgallery_can_upload_groups_desc ?? 'ID групп через запятую.',
        'text', '4', 7
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_can_moderate_groups',
        $lang->af_advancedgallery_can_moderate_groups ?? 'Группы модерации',
        $lang->af_advancedgallery_can_moderate_groups_desc ?? 'ID групп через запятую.',
        'text', '4', 8
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_autoapprove_groups',
        $lang->af_advancedgallery_autoapprove_groups ?? 'Группы автопринятия',
        $lang->af_advancedgallery_autoapprove_groups_desc ?? 'ID групп через запятую.',
        'text', '4', 9
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_max_albums',
        $lang->af_advancedgallery_max_albums ?? 'Макс. альбомов на пользователя',
        $lang->af_advancedgallery_max_albums_desc ?? 'Макс. альбомов на пользователя (0 = без лимита).',
        'numeric', '20', 10
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_max_media_per_album',
        $lang->af_advancedgallery_max_media_per_album ?? 'Макс. медиа в одном альбоме',
        $lang->af_advancedgallery_max_media_per_album_desc ?? 'Макс. медиа в одном альбоме (0 = без лимита).',
        'numeric', '200', 11
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_album_visibility_default',
        $lang->af_advancedgallery_album_visibility_default ?? 'Видимость альбома по умолчанию',
        $lang->af_advancedgallery_album_visibility_default_desc ?? 'Видимость альбома по умолчанию.',
        "select\npublic=public\nregistered=registered\nprivate=private", 'public', 12
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_remote_enabled',
        $lang->af_advancedgallery_remote_enabled ?? 'Разрешить remote media (вставку по URL)',
        $lang->af_advancedgallery_remote_enabled_desc ?? 'Да/Нет',
        'yesno', '1', 13
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_remote_whitelist_domains',
        $lang->af_advancedgallery_remote_whitelist_domains ?? 'Разрешённые домены',
        $lang->af_advancedgallery_remote_whitelist_domains_desc ?? 'Разрешённые домены (по одному в строке). Пусто = встроенный whitelist.',
        'textarea', '', 14
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_remote_allow_oembed',
        $lang->af_advancedgallery_remote_allow_oembed ?? 'Разрешить oEmbed (карточки предпросмотра)',
        $lang->af_advancedgallery_remote_allow_oembed_desc ?? 'По умолчанию выключено.',
        'yesno', '0', 15
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_remote_cache_preview',
        $lang->af_advancedgallery_remote_cache_preview ?? 'Кешировать preview/thumbnail для remote',
        $lang->af_advancedgallery_remote_cache_preview_desc ?? 'Кешировать preview/thumbnail для remote (если возможно безопасно).',
        'yesno', '1', 16
    );
    af_ag_ensure_setting($gid, 'af_advancedgallery_remote_max_url_len',
        $lang->af_advancedgallery_remote_max_url_len ?? 'Макс. длина URL',
        $lang->af_advancedgallery_remote_max_url_len_desc ?? 'Максимальная длина URL.',
        'numeric', '500', 17
    );

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    $uploadRoot = af_ag_resolve_upload_path();
    $galleryDir = $uploadRoot . '/af_gallery';
    if (!is_dir($galleryDir)) {
        @mkdir($galleryDir, 0755, true);
    }
    $indexFile = $galleryDir . '/index.html';
    if (!is_file($indexFile)) {
        @file_put_contents($indexFile, '<!doctype html><title>Forbidden</title>');
    }

    af_ag_sync_alias(true);
    af_ag_templates_install_or_update();

    return true;
}

function af_advancedgallery_uninstall(): bool
{
    global $db;

    if ($db->table_exists('af_gallery_media')) {
        $db->drop_table('af_gallery_media');
    }
    if ($db->table_exists('af_gallery_logs')) {
        $db->drop_table('af_gallery_logs');
    }
    if ($db->table_exists('af_gallery_album_media')) {
        $db->drop_table('af_gallery_album_media');
    }
    if ($db->table_exists('af_gallery_albums')) {
        $db->drop_table('af_gallery_albums');
    }

    $db->delete_query('settings', "name IN (
        'af_advancedgallery_enabled',
        'af_advancedgallery_items_per_page',
        'af_advancedgallery_upload_max_mb',
        'af_advancedgallery_allowed_ext',
        'af_advancedgallery_thumb_w',
        'af_advancedgallery_thumb_h',
        'af_advancedgallery_can_upload_groups',
        'af_advancedgallery_can_moderate_groups',
        'af_advancedgallery_autoapprove_groups',
        'af_advancedgallery_max_albums',
        'af_advancedgallery_max_media_per_album',
        'af_advancedgallery_album_visibility_default',
        'af_advancedgallery_remote_enabled',
        'af_advancedgallery_remote_whitelist_domains',
        'af_advancedgallery_remote_allow_oembed',
        'af_advancedgallery_remote_cache_preview',
        'af_advancedgallery_remote_max_url_len'
    )");
    $db->delete_query('settinggroups', "name='af_advancedgallery'");

    $db->delete_query('templates', "title IN (
        'advancedgallery_page',
        'advancedgallery_index',
        'advancedgallery_tile',
        'advancedgallery_view',
        'advancedgallery_upload',
        'advancedgallery_remote_add',
        'advancedgallery_albums',
        'advancedgallery_album_tile',
        'advancedgallery_album_view',
        'advancedgallery_album_form',
        'advancedgallery_picker_modal'
    )");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    af_ag_sync_alias(false);

    return true;
}

function af_advancedgallery_activate(): bool
{
    af_ag_templates_install_or_update();
    return true;
}

function af_advancedgallery_deactivate(): bool
{
    return true;
}

/* -------------------- RUNTIME -------------------- */

function af_advancedgallery_init(): void
{
    global $plugins;

    $plugins->add_hook('pre_output_page', 'af_advancedgallery_pre_output', 10);
}

function af_advancedgallery_pre_output(string &$page = ''): void
{
    global $mybb;

    if (!ag_is_frontend()) {
        return;
    }

    if (empty($mybb->settings['af_advancedgallery_enabled'])) {
        return;
    }

    if (strpos($page, AF_AG_MARK_DONE) !== false) {
        return;
    }

    if (!ag_page_has_editor($page)) {
        return;
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AG_ID . '/assets';

    $cssTag = '<link rel="stylesheet" type="text/css" href="'.$assetsBase.'/advancedgallery.css?ver='.AF_AG_VER.'" />';
    $jsTag  = '<script src="'.$assetsBase.'/advancedgallery.js?ver='.AF_AG_VER.'"></script>';
    $cfg = [
        'pickerUrl' => $bburl . '/gallery.php?action=picker',
        'dataUrl' => $bburl . '/gallery.php?action=picker_data',
    ];
    $cfgTag = '<script>window.AF_GalleryPickerConfig='
        .json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        .';</script>';

    if (stripos($page, '</head>') !== false) {
        if (strpos($page, 'advancedgallery.css') === false) {
            $page = str_ireplace('</head>', $cssTag.$cfgTag.$jsTag.AF_AG_MARK_DONE.'</head>', $page);
        } else {
            $page = str_ireplace('</head>', $cfgTag.$jsTag.AF_AG_MARK_DONE.'</head>', $page);
        }
        return;
    }

    if (stripos($page, '</body>') !== false) {
        $page = str_ireplace('</body>', $cfgTag.$jsTag.AF_AG_MARK_DONE.'</body>', $page);
    } else {
        $page .= $cfgTag.$jsTag.AF_AG_MARK_DONE;
    }
}

/* -------------------- ALIAS -------------------- */

function af_ag_sync_alias(bool $install): void
{
    $src = AF_AG_ASSETS . 'gallery.php';
    $dst = MYBB_ROOT . 'gallery.php';

    if (!is_file($src)) {
        return;
    }

    if ($install) {
        if (is_file($dst)) {
            $existing = @file_get_contents($dst);
            if ($existing === false || strpos($existing, AF_AG_ALIAS_SIGNATURE) === false) {
                return;
            }
        }
        @copy($src, $dst);
        return;
    }

    if (is_file($dst)) {
        $existing = @file_get_contents($dst);
        if ($existing !== false && strpos($existing, AF_AG_ALIAS_SIGNATURE) !== false) {
            @unlink($dst);
        }
    }
}

/* -------------------- TEMPLATE IMPORT -------------------- */

function af_ag_templates_install_or_update(): void
{
    global $db;

    if (!is_file(AF_AG_TPL_FILE)) {
        return;
    }

    $raw = @file_get_contents(AF_AG_TPL_FILE);
    if ($raw === false || $raw === '') {
        return;
    }

    $parts = preg_split('~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->~', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || count($parts) < 3) {
        return;
    }

    for ($i = 1; $i < count($parts); $i += 2) {
        $name = trim((string)$parts[$i]);
        $tpl  = (string)$parts[$i + 1];

        if ($name === '' || $tpl === '') {
            continue;
        }

        $title = $db->escape_string($name);
        $existing = $db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'", ['limit' => 1]);
        $tid = (int)$db->fetch_field($existing, 'tid');

        $row = [
            'title'    => $title,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => '1839',
            'dateline' => TIME_NOW,
        ];

        if ($tid) {
            $db->update_query('templates', $row, "tid='{$tid}'");
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

/* -------------------- RENDER / ROUTES -------------------- */

function af_advancedgallery_render_gallery(): void
{
    global $mybb, $db, $templates, $theme, $header, $headerinclude, $footer, $lang, $page;

    af_advancedgallery_load_lang(false);

    if (empty($mybb->settings['af_advancedgallery_enabled'])) {
        error_no_permission();
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AG_ID . '/assets';

    $headerinclude .= "\n" . '<link rel="stylesheet" type="text/css" href="'.$assetsBase.'/advancedgallery.css?ver='.AF_AG_VER.'" />';
    $headerinclude .= "\n" . '<script src="'.$assetsBase.'/advancedgallery.js?ver='.AF_AG_VER.'"></script>';

    $action = $mybb->get_input('action');
    $ag_page_title = $lang->af_advancedgallery_name ?? 'Галерея';

    switch ($action) {
        case 'albums':
            $ag_content = ag_render_albums();
            break;
        case 'album':
            $ag_content = ag_render_album_view();
            break;
        case 'album_create':
            $ag_content = ag_render_album_form('create', null);
            break;
        case 'album_create_do':
            ag_handle_album_create_do();
            return;
        case 'album_edit':
            $ag_content = ag_render_album_form('edit', null);
            break;
        case 'album_edit_do':
            ag_handle_album_edit_do();
            return;
        case 'album_delete':
            ag_handle_album_delete();
            return;
        case 'album_add_media':
            ag_handle_album_add_media();
            return;
        case 'album_remove_media':
            ag_handle_album_remove_media();
            return;
        case 'album_sort_do':
            ag_handle_album_sort_do();
            return;
        case 'view':
            $ag_content = ag_render_view();
            break;
        case 'upload':
            $ag_content = ag_render_upload();
            break;
        case 'upload_do':
            ag_handle_upload();
            return;
        case 'remote_add':
            $ag_content = ag_render_remote_add();
            break;
        case 'remote_add_do':
            ag_handle_remote_add_do();
            return;
        case 'picker':
            ag_render_picker_modal();
            return;
        case 'picker_data':
            ag_handle_picker_data();
            return;
        case 'delete':
            ag_handle_delete();
            return;
        case 'approve':
            ag_handle_approve();
            return;
        case 'mine':
            $ag_content = ag_render_index(true);
            break;
        default:
            $ag_content = ag_render_index(false);
            break;
    }

    eval('$page = "' . $templates->get('advancedgallery_page') . '";');
    output_page($page);
}

/* -------------------- ROUTE HELPERS -------------------- */

function ag_render_index(bool $mine): string
{
    global $db, $mybb, $templates, $lang;

    $perPage = (int)($mybb->settings['af_advancedgallery_items_per_page'] ?? 24);
    if ($perPage < 1) {
        $perPage = 24;
    }

    $pageNum = max(1, $mybb->get_input('page', MyBB::INPUT_INT));
    $offset = ($pageNum - 1) * $perPage;

    $where = "status='approved'";
    if ($mine) {
        $uid = (int)$mybb->user['uid'];
        $where = "uid_owner='{$uid}'";
    }

    $total = (int)$db->fetch_field($db->simple_select('af_gallery_media', 'COUNT(*) AS cnt', $where), 'cnt');
    $query = $db->simple_select('af_gallery_media', '*', $where, [
        'order_by' => 'created_at',
        'order_dir' => 'DESC',
        'limit' => $perPage,
        'limit_start' => $offset,
    ]);

    $tiles = '';
    while ($media = $db->fetch_array($query)) {
        $tiles .= ag_render_tile($media);
    }

    if ($tiles === '') {
        $tiles = '<div class="ag-empty">'.htmlspecialchars_uni($lang->af_advancedgallery_empty ?? 'Нет изображений.').'</div>';
    }

    $baseUrl = 'gallery.php' . ($mine ? '?action=mine' : '');
    $pagination = multipage($total, $perPage, $pageNum, $baseUrl);

    $ag_upload_url = 'gallery.php?action=upload';
    $ag_mine_url = 'gallery.php?action=mine';
    $ag_albums_url = 'gallery.php?action=albums';
    $ag_albums_label = htmlspecialchars_uni($lang->af_advancedgallery_my_albums ?? 'Мои альбомы');
    $ag_remote_button = '';
    if (ag_remote_enabled() && ag_can_upload()) {
        $ag_remote_button = '<a class="button" href="gallery.php?action=remote_add">'
            .htmlspecialchars_uni($lang->af_advancedgallery_remote_add ?? 'Добавить по ссылке')
            .'</a>';
    }
    $ag_tiles = $tiles;
    $ag_pagination = $pagination;

    eval('$output = "' . $templates->get('advancedgallery_index') . '";');
    return $output;
}

function ag_render_albums(): string
{
    global $db, $mybb, $templates, $lang, $ag_page_title;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    $ag_page_title = $lang->af_advancedgallery_my_albums ?? 'Мои альбомы';

    $uid = (int)$mybb->user['uid'];
    $query = $db->simple_select('af_gallery_albums', '*', "uid_owner='{$uid}'", [
        'order_by' => 'created_at',
        'order_dir' => 'DESC',
    ]);

    $tiles = '';
    while ($album = $db->fetch_array($query)) {
        $tiles .= ag_render_album_tile($album);
    }

    if ($tiles === '') {
        $tiles = '<div class="ag-empty">'.htmlspecialchars_uni($lang->af_advancedgallery_album_empty ?? 'Альбомов пока нет.').'</div>';
    }

    $ag_create_album_url = 'gallery.php?action=album_create';
    $ag_tiles = $tiles;

    eval('$output = "' . $templates->get('advancedgallery_albums') . '";');
    return $output;
}

function ag_render_album_tile(array $album): string
{
    global $templates;

    $coverUrl = ag_album_cover_url($album);
    $title = htmlspecialchars_uni((string)$album['title']);
    $mediaCount = ag_album_media_count((int)$album['id']);

    $ag_album_url = 'gallery.php?action=album&id='.(int)$album['id'];
    $ag_album_cover_url = $coverUrl;
    $ag_album_title = $title;
    $ag_album_count = (int)$mediaCount;

    eval('$output = "' . $templates->get('advancedgallery_album_tile') . '";');
    return $output;
}

function ag_render_album_view(): string
{
    global $db, $mybb, $templates, $lang, $ag_page_title;

    $albumId = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($albumId <= 0) {
        error('Invalid album ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album) {
        error('Album not found.');
    }

    if (!ag_can_view_album($album)) {
        error_no_permission();
    }

    $isOwner = (int)$album['uid_owner'] === (int)$mybb->user['uid'];
    $canManage = ag_can_manage_album($album);

    $ag_page_title = ($lang->af_advancedgallery_albums ?? 'Альбомы') . ' - ' . htmlspecialchars_uni((string)$album['title']);

    $sortInput = $mybb->get_input('sort');
    $validSorts = ['manual', 'date_desc', 'date_asc'];
    $sortMode = in_array($sortInput, $validSorts, true) ? $sortInput : (string)$album['sort_mode'];
    if (!in_array($sortMode, $validSorts, true)) {
        $sortMode = 'date_desc';
    }

    $mediaWhere = "am.album_id='{$albumId}'";
    if (!$isOwner && !ag_can_moderate()) {
        $mediaWhere .= " AND m.status='approved'";
    }

    $orderBy = "m.created_at DESC";
    if ($sortMode === 'date_asc') {
        $orderBy = "m.created_at ASC";
    } elseif ($sortMode === 'manual') {
        $orderBy = "am.sort_order ASC, am.created_at ASC";
    }

    $prefix = TABLE_PREFIX;
    $sql = "SELECT m.* FROM {$prefix}af_gallery_album_media am"
        ." INNER JOIN {$prefix}af_gallery_media m ON m.id=am.media_id"
        ." WHERE {$mediaWhere} ORDER BY {$orderBy}";
    $query = $db->write_query($sql);

    $tiles = '';
    while ($media = $db->fetch_array($query)) {
        $tiles .= ag_render_tile($media);
    }

    if ($tiles === '') {
        $tiles = '<div class="ag-empty">'.htmlspecialchars_uni($lang->af_advancedgallery_album_empty ?? 'Альбом пуст.').'</div>';
    }

    $breadcrumbs = [
        '<a href="gallery.php">'.htmlspecialchars_uni($lang->af_advancedgallery_name ?? 'Галерея').'</a>',
        '<a href="gallery.php?action=albums">'.htmlspecialchars_uni($lang->af_advancedgallery_albums ?? 'Альбомы').'</a>',
        htmlspecialchars_uni((string)$album['title']),
    ];
    $ag_breadcrumbs = implode(' &raquo; ', $breadcrumbs);

    $ag_album_title = htmlspecialchars_uni((string)$album['title']);
    $ag_album_desc = nl2br(htmlspecialchars_uni((string)$album['description']));
    $ag_album_tiles = $tiles;

    $ag_album_actions = '';
    if ($canManage) {
        $ag_album_actions .= '<a class="button" href="gallery.php?action=album_edit&id='.$albumId.'">'
            .htmlspecialchars_uni($lang->af_advancedgallery_edit_album ?? 'Edit album')
            .'</a>';
        $ag_album_actions .= '<form action="gallery.php?action=album_delete&id='.$albumId.'" method="post" class="ag-inline-form">'
            .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
            .'<button type="submit" class="button">'.htmlspecialchars_uni($lang->af_advancedgallery_delete_album ?? 'Delete album').'</button>'
            .'</form>';
    }
    if ($isOwner) {
        $ag_album_actions .= '<a class="button" href="gallery.php?action=mine">'
            .htmlspecialchars_uni($lang->af_advancedgallery_added_to_album ?? 'Add media')
            .'</a>';
    }

    $ag_album_sort_select = ag_render_album_sort_filter($albumId, $sortMode);

    eval('$output = "' . $templates->get('advancedgallery_album_view') . '";');
    return $output;
}

function ag_render_album_sort_select(string $current): string
{
    global $lang;

    $labels = [
        'manual' => $lang->af_advancedgallery_album_sort_manual ?? 'Manual',
        'date_desc' => $lang->af_advancedgallery_album_sort_date_desc ?? 'Newest first',
        'date_asc' => $lang->af_advancedgallery_album_sort_date_asc ?? 'Oldest first',
    ];
    $options = '';
    foreach ($labels as $value => $label) {
        $selected = $current === $value ? ' selected="selected"' : '';
        $options .= '<option value="'.$value.'"'.$selected.'>'.htmlspecialchars_uni($label).'</option>';
    }

    return $options;
}

function ag_render_album_sort_filter(int $albumId, string $current): string
{
    global $lang;

    $options = ag_render_album_sort_select($current);
    $label = htmlspecialchars_uni($lang->af_advancedgallery_album_sort_mode ?? 'Sort mode');

    return '<form action="gallery.php" method="get" class="ag-album-sort-form">'
        .'<input type="hidden" name="action" value="album" />'
        .'<input type="hidden" name="id" value="'.(int)$albumId.'" />'
        .'<label for="ag_album_sort">'.$label.'</label>'
        .'<select name="sort" id="ag_album_sort" onchange="this.form.submit()">'
        .$options
        .'</select>'
        .'</form>';
}

function ag_render_album_form(string $mode, ?array $album): string
{
    global $mybb, $templates, $lang, $ag_page_title;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    if ($mode === 'edit') {
        $albumId = $mybb->get_input('id', MyBB::INPUT_INT);
        if ($albumId <= 0) {
            error('Invalid album ID.');
        }
        $album = ag_get_album($albumId);
        if (!$album) {
            error('Album not found.');
        }
        if (!ag_can_manage_album($album)) {
            error_no_permission();
        }
    }

    $isEdit = $mode === 'edit' && $album;
    $ag_page_title = $isEdit
        ? ($lang->af_advancedgallery_edit_album ?? 'Редактировать альбом')
        : ($lang->af_advancedgallery_create_album ?? 'Создать альбом');

    $visibilityDefault = (string)af_ag_get_setting('album_visibility_default', 'public');
    $visibility = $isEdit ? (string)$album['visibility'] : $visibilityDefault;
    if (!in_array($visibility, ['public', 'registered', 'groups', 'private'], true)) {
        $visibility = 'public';
    }

    $ag_form_action = $isEdit
        ? 'gallery.php?action=album_edit_do&id='.(int)$album['id']
        : 'gallery.php?action=album_create_do';
    $ag_form_title = htmlspecialchars_uni($isEdit ? (string)$album['title'] : '');
    $ag_form_desc = htmlspecialchars_uni($isEdit ? (string)$album['description'] : '');
    $ag_form_visibility = ag_render_album_visibility_select($visibility);
    $ag_form_allowed_groups = htmlspecialchars_uni($isEdit ? (string)$album['allowed_groups'] : '');
    $ag_form_sort_mode = ag_render_album_sort_select($isEdit ? (string)$album['sort_mode'] : 'date_desc');
    $ag_form_cover_media_id = (int)($isEdit ? $album['cover_media_id'] : 0);
    $ag_form_submit_label = $isEdit
        ? ($lang->af_advancedgallery_edit_album ?? 'Редактировать альбом')
        : ($lang->af_advancedgallery_create_album ?? 'Создать альбом');
    $ag_form_my_post_key = $mybb->post_code;

    eval('$output = "' . $templates->get('advancedgallery_album_form') . '";');
    return $output;
}

function ag_render_album_visibility_select(string $current): string
{
    global $lang;

    $labels = [
        'public' => $lang->af_advancedgallery_album_visibility_public ?? 'Public',
        'registered' => $lang->af_advancedgallery_album_visibility_registered ?? 'Registered',
        'groups' => $lang->af_advancedgallery_album_visibility_groups ?? 'Groups',
        'private' => $lang->af_advancedgallery_album_visibility_private ?? 'Private',
    ];
    $options = '';
    foreach ($labels as $value => $label) {
        $selected = $current === $value ? ' selected="selected"' : '';
        $options .= '<option value="'.$value.'"'.$selected.'>'.htmlspecialchars_uni($label).'</option>';
    }
    return $options;
}

function ag_handle_album_create_do(): void
{
    global $db, $mybb, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $uid = (int)$mybb->user['uid'];
    ag_enforce_album_limits($uid);

    $title = trim((string)$mybb->get_input('title'));
    $description = trim((string)$mybb->get_input('description'));
    $visibility = (string)$mybb->get_input('visibility');
    $allowedGroups = (string)$mybb->get_input('allowed_groups');
    $sortMode = (string)$mybb->get_input('sort_mode');
    $coverMediaId = $mybb->get_input('cover_media_id', MyBB::INPUT_INT);

    if (!in_array($visibility, ['public', 'registered', 'groups', 'private'], true)) {
        $visibility = 'public';
    }
    if (!in_array($sortMode, ['manual', 'date_desc', 'date_asc'], true)) {
        $sortMode = 'date_desc';
    }

    $insert = [
        'uid_owner' => $uid,
        'visibility' => $db->escape_string($visibility),
        'allowed_groups' => $db->escape_string($visibility === 'groups' ? $allowedGroups : ''),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($description),
        'cover_media_id' => (int)$coverMediaId,
        'sort_mode' => $db->escape_string($sortMode),
        'created_at' => TIME_NOW,
        'updated_at' => TIME_NOW,
    ];

    $db->insert_query('af_gallery_albums', $insert);
    $albumId = (int)$db->insert_id();

    redirect('gallery.php?action=album&id='.$albumId, $lang->af_advancedgallery_album_saved ?? 'Album saved.');
}

function ag_handle_album_edit_do(): void
{
    global $db, $mybb, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    $albumId = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($albumId <= 0) {
        error('Invalid album ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album) {
        error('Album not found.');
    }
    if (!ag_can_manage_album($album)) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $title = trim((string)$mybb->get_input('title'));
    $description = trim((string)$mybb->get_input('description'));
    $visibility = (string)$mybb->get_input('visibility');
    $allowedGroups = (string)$mybb->get_input('allowed_groups');
    $sortMode = (string)$mybb->get_input('sort_mode');
    $coverMediaId = $mybb->get_input('cover_media_id', MyBB::INPUT_INT);

    if (!in_array($visibility, ['public', 'registered', 'groups', 'private'], true)) {
        $visibility = 'public';
    }
    if (!in_array($sortMode, ['manual', 'date_desc', 'date_asc'], true)) {
        $sortMode = 'date_desc';
    }

    $db->update_query('af_gallery_albums', [
        'visibility' => $db->escape_string($visibility),
        'allowed_groups' => $db->escape_string($visibility === 'groups' ? $allowedGroups : ''),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($description),
        'cover_media_id' => (int)$coverMediaId,
        'sort_mode' => $db->escape_string($sortMode),
        'updated_at' => TIME_NOW,
    ], "id='{$albumId}'");

    redirect('gallery.php?action=album&id='.$albumId, $lang->af_advancedgallery_album_saved ?? 'Album saved.');
}

function ag_handle_album_delete(): void
{
    global $db, $mybb, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    $albumId = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($albumId <= 0) {
        error('Invalid album ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album) {
        error('Album not found.');
    }
    if (!ag_can_manage_album($album)) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $db->delete_query('af_gallery_album_media', "album_id='{$albumId}'");
    $db->delete_query('af_gallery_albums', "id='{$albumId}'");

    redirect('gallery.php?action=albums', $lang->af_advancedgallery_album_deleted ?? 'Album deleted.');
}

function ag_handle_album_add_media(): void
{
    global $db, $mybb, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $albumId = $mybb->get_input('album_id', MyBB::INPUT_INT);
    $mediaId = $mybb->get_input('media_id', MyBB::INPUT_INT);
    if ($albumId <= 0 || $mediaId <= 0) {
        error('Invalid album or media ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album || !ag_can_manage_album($album)) {
        error_no_permission();
    }

    $media = $db->fetch_array($db->simple_select('af_gallery_media', '*', "id='{$mediaId}'", ['limit' => 1]));
    if (!$media) {
        error('Media not found.');
    }
    if (!ag_can_moderate() && (int)$media['uid_owner'] !== (int)$mybb->user['uid']) {
        error_no_permission();
    }

    ag_enforce_media_limits($albumId);

    $exists = $db->simple_select('af_gallery_album_media', 'id', "album_id='{$albumId}' AND media_id='{$mediaId}'", ['limit' => 1]);
    if (!$db->fetch_field($exists, 'id')) {
        $db->insert_query('af_gallery_album_media', [
            'album_id' => $albumId,
            'media_id' => $mediaId,
            'sort_order' => 0,
            'created_at' => TIME_NOW,
        ]);
        $db->update_query('af_gallery_albums', ['updated_at' => TIME_NOW], "id='{$albumId}'");
    }

    redirect('gallery.php?action=view&id='.$mediaId, $lang->af_advancedgallery_added_to_album ?? 'Added to album.');
}

function ag_handle_album_remove_media(): void
{
    global $db, $mybb, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $albumId = $mybb->get_input('album_id', MyBB::INPUT_INT);
    $mediaId = $mybb->get_input('media_id', MyBB::INPUT_INT);
    if ($albumId <= 0 || $mediaId <= 0) {
        error('Invalid album or media ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album || !ag_can_manage_album($album)) {
        error_no_permission();
    }

    $db->delete_query('af_gallery_album_media', "album_id='{$albumId}' AND media_id='{$mediaId}'");
    $db->update_query('af_gallery_albums', ['updated_at' => TIME_NOW], "id='{$albumId}'");

    redirect('gallery.php?action=album&id='.$albumId, $lang->af_advancedgallery_removed_from_album ?? 'Removed from album.');
}

function ag_handle_album_sort_do(): void
{
    global $db, $mybb;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_manage_albums()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $albumId = $mybb->get_input('album_id', MyBB::INPUT_INT);
    if ($albumId <= 0) {
        error('Invalid album ID.');
    }

    $album = ag_get_album($albumId);
    if (!$album || !ag_can_manage_album($album)) {
        error_no_permission();
    }

    $mediaIds = $mybb->get_input('media_id', MyBB::INPUT_ARRAY);
    if (!is_array($mediaIds) || $mediaIds === []) {
        error('No media to sort.');
    }

    $order = 1;
    foreach ($mediaIds as $mediaId) {
        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            continue;
        }
        $db->update_query('af_gallery_album_media', [
            'sort_order' => $order,
        ], "album_id='{$albumId}' AND media_id='{$mediaId}'");
        $order++;
    }

    $db->update_query('af_gallery_albums', ['updated_at' => TIME_NOW], "id='{$albumId}'");
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

function ag_render_tile(array $media): string
{
    global $mybb, $templates;

    $title = htmlspecialchars_uni((string)$media['title']);
    $thumbUrl = ag_media_thumb_url($media);

    $user = get_user((int)$media['uid_owner']);
    $authorName = htmlspecialchars_uni($user['username'] ?? '');
    $ag_author = $authorName !== '' ? build_profile_link($authorName, (int)$media['uid_owner']) : '';

    $ag_view_url = 'gallery.php?action=view&id='.(int)$media['id'];
    $ag_thumb_url = htmlspecialchars_uni($thumbUrl);
    $ag_title = $title;

    $ag_status_badge = '';
    if ($media['status'] === 'pending' && (ag_can_moderate() || (int)$media['uid_owner'] === (int)$mybb->user['uid'])) {
        $ag_status_badge = '<span class="ag-badge ag-badge-pending">Pending</span>';
    }

    eval('$output = "' . $templates->get('advancedgallery_tile') . '";');
    return $output;
}

function ag_render_view(): string
{
    global $db, $mybb, $templates, $lang;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($id <= 0) {
        error('Invalid media ID.');
    }

    $media = $db->fetch_array($db->simple_select('af_gallery_media', '*', "id='{$id}'", ['limit' => 1]));
    if (!$media) {
        error('Media not found.');
    }

    if ($media['status'] !== 'approved') {
        $isOwner = (int)$media['uid_owner'] === (int)$mybb->user['uid'];
        if (!$isOwner && !ag_can_moderate()) {
            error_no_permission();
        }
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $imageUrl = '';
    if ($media['type'] === 'local') {
        $imageRel = $media['preview_path'] ?: $media['storage_path'];
        $imageUrl = $bburl . '/' . ltrim($imageRel, '/');
    } elseif ($media['type'] === 'remote') {
        $imageUrl = (string)$media['remote_url'];
    }

    $user = get_user((int)$media['uid_owner']);
    $authorName = htmlspecialchars_uni($user['username'] ?? '');
    $ag_author = $authorName !== '' ? build_profile_link($authorName, (int)$media['uid_owner']) : '';

    $created = my_date($mybb->settings['dateformat'] ?? 'Y-m-d', (int)$media['created_at']);
    $ag_created = htmlspecialchars_uni($created);

    $descRaw = (string)$media['description'];
    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }
    $parser = new postParser;
    $parser_options = [
        'allow_html'        => 0,
        'allow_mycode'      => 1,
        'allow_smilies'     => 1,
        'allow_imgcode'     => 1,
        'allow_videocode'   => 0,
        'filter_badwords'   => 1,
        'nl2br'             => 1,
    ];
    $ag_description = $parser->parse_message($descRaw, $parser_options);

    $ag_title = htmlspecialchars_uni((string)$media['title']);
    $ag_bbcode = htmlspecialchars_uni(ag_media_bbcode($media, $imageUrl));
    $ag_media_html = ag_render_media_view($media, $imageUrl);

    $ag_approve_form = '';
    if ($media['status'] === 'pending' && ag_can_moderate()) {
        $ag_approve_form = '<form action="gallery.php?action=approve&id='.(int)$media['id'].'" method="post" class="ag-inline-form">'
            .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
            .'<button type="submit" class="button">Approve</button>'
            .'</form>';
    }

    $ag_delete_form = '';
    if (ag_can_delete_media($media)) {
        $ag_delete_form = '<form action="gallery.php?action=delete&id='.(int)$media['id'].'" method="post" class="ag-inline-form">'
            .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
            .'<button type="submit" class="button">Delete</button>'
            .'</form>';
    }

    $ag_add_to_album_form = '';
    if ((int)$media['uid_owner'] === (int)$mybb->user['uid'] && ag_can_manage_albums()) {
        $albumsQuery = $db->simple_select('af_gallery_albums', 'id,title', "uid_owner='".(int)$mybb->user['uid']."'", [
            'order_by' => 'created_at',
            'order_dir' => 'DESC',
        ]);
        $options = '';
        while ($album = $db->fetch_array($albumsQuery)) {
            $options .= '<option value="'.(int)$album['id'].'">'.htmlspecialchars_uni((string)$album['title']).'</option>';
        }

        if ($options === '') {
            $ag_add_to_album_form = '<div class="ag-add-to-album">'
                .'<div class="ag-add-to-album-empty">'.htmlspecialchars_uni($lang->af_advancedgallery_album_empty ?? 'Нет альбомов.').'</div>'
                .'<a class="button" href="gallery.php?action=album_create">'.htmlspecialchars_uni($lang->af_advancedgallery_create_album ?? 'Создать альбом').'</a>'
                .'</div>';
        } else {
            $ag_add_to_album_form = '<form action="gallery.php?action=album_add_media" method="post" class="ag-add-to-album">'
                .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                .'<input type="hidden" name="media_id" value="'.(int)$media['id'].'" />'
                .'<label for="ag_album_select">'.htmlspecialchars_uni($lang->af_advancedgallery_albums ?? 'Альбомы').'</label>'
                .'<select name="album_id" id="ag_album_select">'.$options.'</select>'
                .'<button type="submit" class="button">'.htmlspecialchars_uni($lang->af_advancedgallery_added_to_album ?? 'Добавить в альбом').'</button>'
                .'</form>';
        }
    }

    eval('$output = "' . $templates->get('advancedgallery_view') . '";');
    return $output;
}

function ag_render_upload(): string
{
    global $mybb, $templates;

    if (!ag_can_upload()) {
        error_no_permission();
    }

    $ag_upload_do_url = 'gallery.php?action=upload_do';
    $ag_my_post_key = $mybb->post_code;

    eval('$output = "' . $templates->get('advancedgallery_upload') . '";');
    return $output;
}

function ag_handle_upload(): void
{
    global $db, $mybb;

    if (!ag_can_upload()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    if (empty($_FILES['ag_file'])) {
        error('No file uploaded.');
    }

    $file = $_FILES['ag_file'];
    if (!empty($file['error'])) {
        error('Upload failed.');
    }

    $maxMb = (int)($mybb->settings['af_advancedgallery_upload_max_mb'] ?? 10);
    if ($maxMb < 1) {
        $maxMb = 10;
    }
    $maxBytes = $maxMb * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) {
        error('File is too large.');
    }

    $allowedExts = array_filter(array_map('trim', explode(',', (string)($mybb->settings['af_advancedgallery_allowed_ext'] ?? ''))));
    $allowedExts = array_map('strtolower', $allowedExts);

    $originalName = (string)$file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExts, true)) {
        error('File extension not allowed.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
        error('Invalid file type.');
    }

    $sizeInfo = @getimagesize($file['tmp_name']);
    if (!$sizeInfo) {
        error('Invalid image file.');
    }

    $uploadRoot = af_ag_resolve_upload_path();
    $uid = (int)$mybb->user['uid'];
    $subdir = $uploadRoot . '/af_gallery/' . $uid . '/' . date('Y') . '/' . date('m');
    if (!is_dir($subdir)) {
        @mkdir($subdir, 0755, true);
    }

    $hash = bin2hex(random_bytes(16));
    $filename = $hash . '.' . $ext;
    $destPath = $subdir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        error('Failed to save file.');
    }

    $thumbW = (int)($mybb->settings['af_advancedgallery_thumb_w'] ?? 320);
    $thumbH = (int)($mybb->settings['af_advancedgallery_thumb_h'] ?? 320);
    if ($thumbW < 1) { $thumbW = 320; }
    if ($thumbH < 1) { $thumbH = 320; }

    $thumbName = $hash . '_thumb.' . $ext;
    $thumbPath = $subdir . '/' . $thumbName;
    $thumbPath = ag_make_thumb($destPath, $thumbPath, $thumbW, $thumbH);

    $storageRel = af_ag_relpath($destPath);
    $thumbRel = af_ag_relpath($thumbPath);

    $title = $mybb->get_input('ag_title');
    $description = $mybb->get_input('ag_description');

    $status = ag_is_autoapprove() ? 'approved' : 'pending';

    $insert = [
        'uid_owner'     => $uid,
        'type'          => 'local',
        'status'        => $status,
        'created_at'    => TIME_NOW,
        'updated_at'    => TIME_NOW,
        'title'         => $db->escape_string($title),
        'description'   => $db->escape_string($description),
        'tags'          => '',
        'views'         => 0,
        'original_name' => $db->escape_string($originalName),
        'storage_path'  => $db->escape_string($storageRel),
        'mime'          => $db->escape_string($mime),
        'ext'           => $db->escape_string($ext),
        'filesize'      => (int)$file['size'],
        'width'         => (int)$sizeInfo[0],
        'height'        => (int)$sizeInfo[1],
        'thumb_path'    => $db->escape_string($thumbRel),
        'preview_path'  => $db->escape_string($storageRel),
        'remote_url'    => '',
        'provider'      => '',
        'embed_html'    => '',
    ];

    $db->insert_query('af_gallery_media', $insert);
    $newId = (int)$db->insert_id();

    redirect('gallery.php?action=view&id='.$newId, 'Uploaded successfully.');
}

function ag_render_remote_add(): string
{
    global $db, $mybb, $templates, $lang;

    if (!ag_can_upload() || !ag_remote_enabled()) {
        error_no_permission();
    }

    $ag_remote_action = 'gallery.php?action=remote_add_do';
    $ag_remote_my_post_key = $mybb->post_code;
    $ag_remote_album_field = '';

    if (ag_can_manage_albums()) {
        $options = '<option value="">'.htmlspecialchars_uni($lang->af_advancedgallery_remote_album_none ?? 'Без альбома').'</option>';
        $albumsQuery = $db->simple_select('af_gallery_albums', 'id,title', "uid_owner='".(int)$mybb->user['uid']."'", [
            'order_by' => 'created_at',
            'order_dir' => 'DESC',
        ]);
        while ($album = $db->fetch_array($albumsQuery)) {
            $options .= '<option value="'.(int)$album['id'].'">'.htmlspecialchars_uni((string)$album['title']).'</option>';
        }
        $ag_remote_album_field = '<div class="ag-field">'
            .'<label for="ag_remote_album">'.htmlspecialchars_uni($lang->af_advancedgallery_albums ?? 'Альбомы').'</label>'
            .'<select name="album_id" id="ag_remote_album">'.$options.'</select>'
            .'</div>';
    }

    eval('$output = "' . $templates->get('advancedgallery_remote_add') . '";');
    return $output;
}

function ag_handle_remote_add_do(): void
{
    global $db, $mybb, $lang;

    if (!ag_can_upload() || !ag_remote_enabled()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $urlRaw = (string)$mybb->get_input('ag_remote_url');
    $url = ag_remote_normalize_url($urlRaw);
    if ($url === '') {
        error($lang->af_advancedgallery_remote_invalid_url ?? 'Invalid URL.');
    }

    $parts = @parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || !ag_remote_is_domain_allowed($host)) {
        error($lang->af_advancedgallery_remote_domain_denied ?? 'Domain not allowed.');
    }

    $resolved = ag_remote_resolve_url($url);
    if (!$resolved) {
        error($lang->af_advancedgallery_remote_invalid_url ?? 'Invalid URL.');
    }

    $title = (string)$mybb->get_input('ag_title');
    $description = (string)$mybb->get_input('ag_description');
    $albumId = $mybb->get_input('album_id', MyBB::INPUT_INT);

    $status = ag_is_autoapprove() ? 'approved' : 'pending';

    $thumbPath = '';
    if (!empty($resolved['thumb_url']) && preg_match('~^https?://~i', (string)$resolved['thumb_url'])) {
        $thumbPath = (string)$resolved['thumb_url'];
    }

    $insert = [
        'uid_owner'     => (int)$mybb->user['uid'],
        'type'          => 'remote',
        'status'        => $status,
        'created_at'    => TIME_NOW,
        'updated_at'    => TIME_NOW,
        'title'         => $db->escape_string($title),
        'description'   => $db->escape_string($description),
        'tags'          => '',
        'views'         => 0,
        'original_name' => '',
        'storage_path'  => '',
        'mime'          => '',
        'ext'           => $db->escape_string((string)($resolved['ext'] ?? '')),
        'filesize'      => 0,
        'width'         => 0,
        'height'        => 0,
        'thumb_path'    => $db->escape_string($thumbPath),
        'preview_path'  => '',
        'remote_url'    => $db->escape_string($url),
        'provider'      => $db->escape_string((string)$resolved['provider']),
        'embed_html'    => $db->escape_string((string)$resolved['embed_html']),
    ];

    $db->insert_query('af_gallery_media', $insert);
    $newId = (int)$db->insert_id();

    if ($albumId > 0 && ag_can_manage_albums()) {
        $album = ag_get_album($albumId);
        if ($album && (int)$album['uid_owner'] === (int)$mybb->user['uid']) {
            ag_enforce_media_limits($albumId);
            $exists = $db->simple_select('af_gallery_album_media', 'id', "album_id='{$albumId}' AND media_id='{$newId}'", ['limit' => 1]);
            if (!$db->fetch_field($exists, 'id')) {
                $db->insert_query('af_gallery_album_media', [
                    'album_id' => $albumId,
                    'media_id' => $newId,
                    'sort_order' => 0,
                    'created_at' => TIME_NOW,
                ]);
                $db->update_query('af_gallery_albums', ['updated_at' => TIME_NOW], "id='{$albumId}'");
            }
        }
    }

    redirect('gallery.php?action=view&id='.$newId, $lang->af_advancedgallery_remote_added ?? 'Media added.');
}

function ag_render_picker_modal(): void
{
    global $db, $mybb, $templates, $lang;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_upload()) {
        error_no_permission();
    }

    $albumsHtml = '';
    $albumsQuery = $db->simple_select('af_gallery_albums', 'id,title', "uid_owner='".(int)$mybb->user['uid']."'", [
        'order_by' => 'created_at',
        'order_dir' => 'DESC',
    ]);
    while ($album = $db->fetch_array($albumsQuery)) {
        $albumsHtml .= '<button type="button" class="ag-picker-album" data-album-id="'
            .(int)$album['id'].'">'
            .htmlspecialchars_uni((string)$album['title'])
            .'</button>';
    }
    if ($albumsHtml === '') {
        $albumsHtml = '<div class="ag-empty">'.htmlspecialchars_uni($lang->af_advancedgallery_album_empty ?? 'Нет содержимого.').'</div>';
    }

    $ag_picker_upload_url = 'gallery.php?action=upload';
    $ag_picker_remote_link = '';
    if (ag_remote_enabled()) {
        $ag_picker_remote_link = '<a class="button" href="gallery.php?action=remote_add">'
            .htmlspecialchars_uni($lang->af_advancedgallery_picker_add_remote ?? 'Добавить по ссылке')
            .'</a>';
    }
    $ag_picker_albums = $albumsHtml;

    eval('$output = "' . $templates->get('advancedgallery_picker_modal') . '";');
    header('Content-Type: text/html; charset=UTF-8');
    echo $output;
    exit;
}

function ag_handle_picker_data(): void
{
    global $db, $mybb;

    if ((int)$mybb->user['uid'] <= 0 || !ag_can_upload()) {
        error_no_permission();
    }

    $page = max(1, $mybb->get_input('page', MyBB::INPUT_INT));
    $perPage = (int)($mybb->settings['af_advancedgallery_items_per_page'] ?? 24);
    if ($perPage < 1) {
        $perPage = 24;
    }
    $offset = ($page - 1) * $perPage;

    $uid = (int)$mybb->user['uid'];
    $where = "m.uid_owner='{$uid}'";

    $q = trim((string)$mybb->get_input('q'));
    if ($q !== '') {
        $qEsc = $db->escape_string('%'.$q.'%');
        $where .= " AND m.title LIKE '{$qEsc}'";
    }

    $type = (string)$mybb->get_input('type');
    if (in_array($type, ['local', 'remote'], true)) {
        $where .= " AND m.type='".$db->escape_string($type)."'";
    }

    $albumId = $mybb->get_input('album_id', MyBB::INPUT_INT);
    $join = '';
    if ($albumId > 0) {
        $album = ag_get_album($albumId);
        if ($album && (int)$album['uid_owner'] === $uid) {
            $join = " INNER JOIN ".TABLE_PREFIX."af_gallery_album_media am ON am.media_id=m.id";
            $where .= " AND am.album_id='{$albumId}'";
        }
    }

    $sql = "SELECT m.* FROM ".TABLE_PREFIX."af_gallery_media m{$join} WHERE {$where}"
        ." ORDER BY m.created_at DESC";
    $query = $db->write_query($sql . " LIMIT {$offset}, {$perPage}");

    $items = [];
    while ($row = $db->fetch_array($query)) {
        $thumb = ag_media_thumb_url($row);
        $fullUrl = $row['type'] === 'remote'
            ? (string)$row['remote_url']
            : (rtrim((string)($mybb->settings['bburl'] ?? ''), '/').'/'.ltrim((string)($row['preview_path'] ?: $row['storage_path']), '/'));
        $items[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'thumb' => (string)$thumb,
            'url_full' => (string)$fullUrl,
            'type' => (string)$row['type'],
            'provider' => (string)$row['provider'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ag_handle_delete(): void
{
    global $db, $mybb;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($id <= 0) {
        error('Invalid media ID.');
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $media = $db->fetch_array($db->simple_select('af_gallery_media', '*', "id='{$id}'", ['limit' => 1]));
    if (!$media) {
        error('Media not found.');
    }

    if (!ag_can_delete_media($media)) {
        error_no_permission();
    }

    if ($media['type'] === 'local') {
        $paths = [
            af_ag_abs_path($media['storage_path']),
            af_ag_abs_path($media['thumb_path']),
            af_ag_abs_path($media['preview_path']),
        ];
        foreach ($paths as $path) {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    $db->delete_query('af_gallery_media', "id='{$id}'");

    redirect('gallery.php', 'Deleted.');
}

function ag_handle_approve(): void
{
    global $db, $mybb;

    if (!ag_can_moderate()) {
        error_no_permission();
    }

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if ($id <= 0) {
        error('Invalid media ID.');
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $db->update_query('af_gallery_media', [
        'status' => 'approved',
        'updated_at' => TIME_NOW,
    ], "id='{$id}'");

    redirect('gallery.php?action=view&id='.$id, 'Approved.');
}

/* -------------------- ALBUM HELPERS -------------------- */

function ag_get_album(int $id): ?array
{
    global $db;
    $row = $db->fetch_array($db->simple_select('af_gallery_albums', '*', "id='{$id}'", ['limit' => 1]));
    return $row ?: null;
}

function ag_album_media_count(int $albumId): int
{
    global $db;
    return (int)$db->fetch_field(
        $db->simple_select('af_gallery_album_media', 'COUNT(*) AS cnt', "album_id='{$albumId}'"),
        'cnt'
    );
}

function ag_album_cover_url(array $albumRow): string
{
    global $db, $mybb;
    $coverId = (int)($albumRow['cover_media_id'] ?? 0);
    if ($coverId > 0) {
        $media = $db->fetch_array($db->simple_select('af_gallery_media', '*', "id='{$coverId}'", ['limit' => 1]));
        if ($media) {
            $canView = $media['status'] === 'approved'
                || (int)$media['uid_owner'] === (int)$mybb->user['uid']
                || ag_can_moderate();
            if ($canView) {
                return ag_media_thumb_url($media);
            }
        }
    }

    return ag_placeholder_thumb_url('Album');
}

function ag_can_view_album(array $albumRow): bool
{
    global $mybb;

    $uid = (int)$mybb->user['uid'];
    if ((int)$albumRow['uid_owner'] === $uid) {
        return true;
    }
    if (ag_can_moderate()) {
        return true;
    }

    $visibility = (string)$albumRow['visibility'];
    if ($visibility === 'public') {
        return true;
    }
    if ($visibility === 'registered') {
        return $uid > 0;
    }
    if ($visibility === 'private') {
        return false;
    }
    if ($visibility === 'groups') {
        $allowed = array_filter(array_map('trim', explode(',', (string)$albumRow['allowed_groups'])));
        $allowed = array_map('intval', $allowed);
        return ag_user_in_groups($allowed);
    }

    return false;
}

function ag_can_manage_albums(): bool
{
    global $mybb;
    return (int)$mybb->user['uid'] > 0 && ag_can_upload();
}

function ag_can_manage_album(array $albumRow): bool
{
    global $mybb;
    return ag_can_moderate() || (int)$albumRow['uid_owner'] === (int)$mybb->user['uid'];
}

function ag_enforce_album_limits(int $uid): void
{
    global $db, $lang;

    $limit = (int)af_ag_get_setting('max_albums', 20);
    if ($limit <= 0) {
        return;
    }

    $count = (int)$db->fetch_field(
        $db->simple_select('af_gallery_albums', 'COUNT(*) AS cnt', "uid_owner='{$uid}'"),
        'cnt'
    );
    if ($count >= $limit) {
        error($lang->af_advancedgallery_limit_albums_reached ?? 'Album limit reached.');
    }
}

function ag_enforce_media_limits(int $albumId): void
{
    global $lang;

    $limit = (int)af_ag_get_setting('max_media_per_album', 200);
    if ($limit <= 0) {
        return;
    }

    $count = ag_album_media_count($albumId);
    if ($count >= $limit) {
        error($lang->af_advancedgallery_limit_media_reached ?? 'Media limit reached.');
    }
}

/* -------------------- PERMISSIONS -------------------- */

function ag_get_user_groups(): array
{
    global $mybb;
    $groups = [];
    $groups[] = (int)$mybb->user['usergroup'];
    if (!empty($mybb->user['additionalgroups'])) {
        $extra = array_filter(array_map('trim', explode(',', $mybb->user['additionalgroups'])));
        foreach ($extra as $gid) {
            $groups[] = (int)$gid;
        }
    }
    return array_unique($groups);
}

function ag_groups_from_setting(string $key): array
{
    global $mybb;

    // Поддержка двух вариантов ключей:
    // 1) короткий: can_upload_groups
    // 2) полный:  af_advancedgallery_can_upload_groups  (если вдруг кто-то так передал)
    $raw = '';

    // если передали полный ключ — читаем напрямую
    if ($key !== '' && strpos($key, 'af_'.AF_AG_ID.'_') === 0) {
        $raw = (string)($mybb->settings[$key] ?? '');
    } else {
        // иначе — читаем через наш хелпер (он сам добавит префикс)
        $raw = (string)af_ag_get_setting($key, '');
    }

    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $items = array_filter(array_map('trim', explode(',', $raw)));
    return array_values(array_unique(array_map('intval', $items)));
}

function ag_user_in_groups(array $allowed): bool
{
    if (empty($allowed)) {
        return false;
    }
    $userGroups = ag_get_user_groups();
    foreach ($userGroups as $gid) {
        if (in_array((int)$gid, $allowed, true)) {
            return true;
        }
    }
    return false;
}

function ag_can_view(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedgallery_enabled']);
}

function ag_can_upload(): bool
{
    global $mybb;

    // гости — нет
    if ((int)$mybb->user['uid'] <= 0) {
        return false;
    }

    // если список групп не задан — разрешаем всем зарегистрированным
    $allowed = ag_groups_from_setting('can_upload_groups');
    if (empty($allowed)) {
        return true;
    }

    return ag_user_in_groups($allowed);
}

function ag_can_moderate(): bool
{
    $allowed = ag_groups_from_setting('can_moderate_groups');
    if (empty($allowed)) {
        return false;
    }
    return ag_user_in_groups($allowed);
}

function ag_is_autoapprove(): bool
{
    $allowed = ag_groups_from_setting('autoapprove_groups');
    if (empty($allowed)) {
        return false;
    }
    return ag_user_in_groups($allowed);
}

function ag_remote_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedgallery_remote_enabled']);
}

function ag_can_delete_media(array $media): bool
{
    global $mybb;
    if (ag_can_moderate()) {
        return true;
    }
    return (int)$media['uid_owner'] === (int)$mybb->user['uid'];
}

function ag_is_frontend(): bool
{
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return false;
    }

    if (defined('THIS_SCRIPT')) {
        $s = (string)THIS_SCRIPT;
        if ($s === 'modcp.php') {
            return false;
        }
    }

    return true;
}

function ag_page_has_editor(string $page): bool
{
    if (stripos($page, 'bbcodes_sceditor.js') !== false) {
        return true;
    }
    if (stripos($page, 'sceditor') !== false && stripos($page, 'toolbar') !== false) {
        return true;
    }
    if (stripos($page, '/rin/') !== false && stripos($page, 'editor') !== false) {
        return true;
    }
    return false;
}

function ag_placeholder_thumb_url(string $label = 'Media'): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">'
        .'<rect width="100%" height="100%" fill="#f2f2f2"/>'
        .'<text x="50%" y="50%" font-size="20" text-anchor="middle" fill="#999" dy=".3em">'
        .htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        .'</text>'
        .'</svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function ag_media_thumb_url(array $media): string
{
    global $mybb;

    if (($media['type'] ?? '') === 'remote') {
        $thumb = (string)($media['thumb_path'] ?? '');
        if (preg_match('~^https?://~i', $thumb)) {
            return $thumb;
        }
        if (ag_media_is_direct_image($media)) {
            return (string)($media['remote_url'] ?? '');
        }
        return ag_placeholder_thumb_url('Media');
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $thumbRel = (string)($media['thumb_path'] ?? '');
    if ($thumbRel === '') {
        $thumbRel = (string)($media['storage_path'] ?? '');
    }
    if ($thumbRel === '') {
        return ag_placeholder_thumb_url('Media');
    }
    return $bburl . '/' . ltrim($thumbRel, '/');
}

function ag_media_is_direct_image(array $media): bool
{
    if (($media['type'] ?? '') !== 'remote') {
        return true;
    }
    $provider = (string)($media['provider'] ?? '');
    if (in_array($provider, ['direct', 'imgur', 'giphy', 'discord'], true)) {
        return ag_remote_is_image_url((string)($media['remote_url'] ?? ''));
    }
    return false;
}

function ag_media_bbcode(array $media, string $fallbackUrl): string
{
    $url = $fallbackUrl;
    if (($media['type'] ?? '') === 'remote') {
        $url = (string)($media['remote_url'] ?? $fallbackUrl);
    }
    if (ag_media_is_direct_image($media)) {
        return '[img]'.$url.'[/img]';
    }
    return '[url='.$url.']'.$url.'[/url]';
}

function ag_render_media_view(array $media, string $fallbackUrl): string
{
    $title = htmlspecialchars_uni((string)($media['title'] ?? ''));

    if (($media['type'] ?? '') === 'local') {
        $src = htmlspecialchars_uni($fallbackUrl);
        return '<img src="'.$src.'" alt="'.$title.'" />';
    }

    $provider = (string)($media['provider'] ?? '');
    $embed = (string)($media['embed_html'] ?? '');
    if (in_array($provider, ['youtube', 'vimeo'], true) && $embed !== '') {
        return $embed;
    }

    $remoteUrl = (string)($media['remote_url'] ?? '');
    $remoteEsc = htmlspecialchars_uni($remoteUrl);
    if (ag_media_is_direct_image($media)) {
        return '<img src="'.$remoteEsc.'" alt="'.$title.'" />';
    }

    $thumb = ag_media_thumb_url($media);
    $thumbEsc = htmlspecialchars_uni($thumb);
    $thumbHtml = '';
    if ($thumb !== '' && strpos($thumb, 'data:image/svg+xml') !== 0) {
        $thumbHtml = '<img src="'.$thumbEsc.'" alt="'.$title.'" />';
    }

    return '<a href="'.$remoteEsc.'" target="_blank" rel="noopener">'.$thumbHtml.$remoteEsc.'</a>';
}

function ag_remote_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = preg_replace('~[\s\x00-\x1F\x7F]+~u', '', $url);
    if ($url === '') {
        return '';
    }

    $max = (int)af_ag_get_setting('remote_max_url_len', 500);
    if ($max > 0 && strlen($url) > $max) {
        return '';
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    if (empty($parts['host'])) {
        return '';
    }

    return $url;
}

function ag_remote_is_domain_allowed(string $host): bool
{
    $host = strtolower(trim($host, '.'));
    if ($host === '') {
        return false;
    }

    $raw = trim((string)af_ag_get_setting('remote_whitelist_domains', ''));
    $domains = [];
    if ($raw !== '') {
        $lines = preg_split('~\r\n|\r|\n~', $raw);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = strtolower(trim($line));
                if ($line !== '') {
                    $domains[] = $line;
                }
            }
        }
    } else {
        $domains = [
            'youtube.com',
            'youtu.be',
            'vimeo.com',
            'imgur.com',
            'i.imgur.com',
            'giphy.com',
            'media.giphy.com',
            'cdn.discordapp.com',
            'media.discordapp.net',
        ];
    }

    foreach ($domains as $domain) {
        if ($host === $domain) {
            return true;
        }
        if (substr($host, -strlen('.'.$domain)) === '.'.$domain) {
            return true;
        }
    }

    return false;
}

function ag_remote_is_image_url(string $url): bool
{
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $path = (string)($parts['path'] ?? '');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function ag_remote_extract_youtube_id(array $parts): string
{
    $host = strtolower($parts['host'] ?? '');
    $path = (string)($parts['path'] ?? '');
    $query = (string)($parts['query'] ?? '');

    if ($host === 'youtu.be') {
        $id = trim($path, '/');
        if ($id !== '') {
            return $id;
        }
    }

    if (strpos($host, 'youtube.com') !== false) {
        if ($query !== '') {
            parse_str($query, $vars);
            if (!empty($vars['v'])) {
                return (string)$vars['v'];
            }
        }
        if (preg_match('~^/(embed|shorts)/([^/?]+)~', $path, $m)) {
            return (string)$m[2];
        }
    }

    return '';
}

function ag_remote_extract_vimeo_id(array $parts): string
{
    $path = (string)($parts['path'] ?? '');
    if (preg_match('~/(\\d+)(?:$|/)~', $path, $m)) {
        return (string)$m[1];
    }
    return '';
}

function ag_remote_build_embed(string $provider, string $id): array
{
    if ($provider === 'youtube') {
        $safeId = preg_replace('~[^a-zA-Z0-9_-]~', '', $id);
        if ($safeId === '') {
            return ['embed' => '', 'thumb' => ''];
        }
        $src = 'https://www.youtube.com/embed/'.$safeId;
        $thumb = 'https://i.ytimg.com/vi/'.$safeId.'/hqdefault.jpg';
        $embed = '<iframe class="ag-embed" width="560" height="315" src="'
            .htmlspecialchars_uni($src)
            .'" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
        return ['embed' => $embed, 'thumb' => $thumb];
    }
    if ($provider === 'vimeo') {
        $safeId = preg_replace('~[^0-9]~', '', $id);
        if ($safeId === '') {
            return ['embed' => '', 'thumb' => ''];
        }
        $src = 'https://player.vimeo.com/video/'.$safeId;
        $embed = '<iframe class="ag-embed" width="560" height="315" src="'
            .htmlspecialchars_uni($src)
            .'" title="Vimeo video player" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
        return ['embed' => $embed, 'thumb' => ''];
    }
    return ['embed' => '', 'thumb' => ''];
}

function ag_remote_resolve_url(string $url): ?array
{
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower($parts['host'] ?? '');
    $path = (string)($parts['path'] ?? '');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $isImage = $ext !== '' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    $videoExt = in_array($ext, ['mp4', 'webm'], true) ? $ext : '';

    if (strpos($host, 'youtube.com') !== false || $host === 'youtu.be') {
        $id = ag_remote_extract_youtube_id($parts);
        if ($id === '') {
            return null;
        }
        $embed = ag_remote_build_embed('youtube', $id);
        return [
            'provider' => 'youtube',
            'embed_html' => $embed['embed'],
            'thumb_url' => $embed['thumb'],
            'is_direct_image' => false,
            'ext' => '',
        ];
    }

    if (strpos($host, 'vimeo.com') !== false) {
        $id = ag_remote_extract_vimeo_id($parts);
        if ($id === '') {
            return null;
        }
        $embed = ag_remote_build_embed('vimeo', $id);
        return [
            'provider' => 'vimeo',
            'embed_html' => $embed['embed'],
            'thumb_url' => $embed['thumb'],
            'is_direct_image' => false,
            'ext' => '',
        ];
    }

    if (strpos($host, 'imgur.com') !== false) {
        if ($isImage) {
            return [
                'provider' => 'imgur',
                'embed_html' => '',
                'thumb_url' => '',
                'is_direct_image' => true,
                'ext' => $ext,
            ];
        }
        return null;
    }

    if (strpos($host, 'giphy.com') !== false) {
        return [
            'provider' => 'giphy',
            'embed_html' => '',
            'thumb_url' => '',
            'is_direct_image' => $isImage,
            'ext' => $isImage ? $ext : ($videoExt !== '' ? $videoExt : ''),
        ];
    }

    if (strpos($host, 'discordapp.com') !== false || strpos($host, 'discordapp.net') !== false) {
        return [
            'provider' => 'discord',
            'embed_html' => '',
            'thumb_url' => '',
            'is_direct_image' => $isImage,
            'ext' => $isImage ? $ext : ($videoExt !== '' ? $videoExt : ''),
        ];
    }

    if ($isImage) {
        return [
            'provider' => 'direct',
            'embed_html' => '',
            'thumb_url' => '',
            'is_direct_image' => true,
            'ext' => $ext,
        ];
    }

    return null;
}

/* -------------------- FILE HELPERS -------------------- */

function af_ag_resolve_upload_path(): string
{
    global $mybb;
    $uploadspath = (string)($mybb->settings['uploadspath'] ?? 'uploads');
    if ($uploadspath === '') {
        $uploadspath = 'uploads';
    }

    if ($uploadspath[0] !== '/' && !preg_match('~^[A-Za-z]:\\\\~', $uploadspath)) {
        $uploadspath = rtrim(MYBB_ROOT, '/\\') . '/' . ltrim($uploadspath, '/\\');
    }

    return rtrim($uploadspath, '/\\');
}

function af_ag_relpath(string $absPath): string
{
    $root = rtrim(MYBB_ROOT, '/\\') . '/';
    if (strpos($absPath, $root) === 0) {
        return ltrim(substr($absPath, strlen($root)), '/\\');
    }
    return $absPath;
}

function af_ag_abs_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    if ($path[0] === '/' || preg_match('~^[A-Za-z]:\\\\~', $path)) {
        return $path;
    }
    return rtrim(MYBB_ROOT, '/\\') . '/' . ltrim($path, '/\\');
}

function ag_make_thumb(string $src, string $dst, int $w, int $h): string
{
    $info = @getimagesize($src);
    if (!$info) {
        return $dst;
    }

    $mime = $info['mime'] ?? '';
    $create = null;
    $save = null;
    $dstExt = pathinfo($dst, PATHINFO_EXTENSION);

    switch ($mime) {
        case 'image/jpeg':
            $create = 'imagecreatefromjpeg';
            $save = 'imagejpeg';
            break;
        case 'image/png':
            $create = 'imagecreatefrompng';
            $save = 'imagepng';
            break;
        case 'image/gif':
            $create = 'imagecreatefromgif';
            $save = 'imagegif';
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
                $create = 'imagecreatefromwebp';
                $save = 'imagewebp';
            } else {
                $create = 'imagecreatefromstring';
                $save = 'imagejpeg';
                $dst = preg_replace('~\.webp$~', '.jpg', $dst);
                $dstExt = 'jpg';
            }
            break;
    }

    if (!$create || (!function_exists($create) && $create !== 'imagecreatefromstring')) {
        return $dst;
    }

    if ($create === 'imagecreatefromstring') {
        $srcData = @file_get_contents($src);
        $srcImg = $srcData !== false ? @imagecreatefromstring($srcData) : false;
    } else {
        $srcImg = @$create($src);
    }
    if (!$srcImg) {
        return $dst;
    }

    $srcW = (int)$info[0];
    $srcH = (int)$info[1];

    $ratio = max($w / $srcW, $h / $srcH);
    $newW = (int)ceil($srcW * $ratio);
    $newH = (int)ceil($srcH * $ratio);

    $tmp = imagecreatetruecolor($w, $h);

    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagecolortransparent($tmp, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
    }

    $srcX = (int)(($newW - $w) / 2);
    $srcY = (int)(($newH - $h) / 2);

    $scaled = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    imagecopy($tmp, $scaled, 0, 0, $srcX, $srcY, $w, $h);

    if ($save === 'imagejpeg') {
        imagejpeg($tmp, $dst, 90);
    } elseif ($save === 'imagepng') {
        imagepng($tmp, $dst);
    } elseif ($save === 'imagegif') {
        imagegif($tmp, $dst);
    } elseif ($save === 'imagewebp') {
        imagewebp($tmp, $dst, 90);
    }

    imagedestroy($scaled);
    imagedestroy($tmp);
    imagedestroy($srcImg);

    return $dst;
}
