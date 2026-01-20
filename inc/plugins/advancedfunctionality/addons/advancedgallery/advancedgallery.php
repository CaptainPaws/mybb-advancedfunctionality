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

    $db->delete_query('settings', "name IN (
        'af_advancedgallery_enabled',
        'af_advancedgallery_items_per_page',
        'af_advancedgallery_upload_max_mb',
        'af_advancedgallery_allowed_ext',
        'af_advancedgallery_thumb_w',
        'af_advancedgallery_thumb_h',
        'af_advancedgallery_can_upload_groups',
        'af_advancedgallery_can_moderate_groups',
        'af_advancedgallery_autoapprove_groups'
    )");
    $db->delete_query('settinggroups', "name='af_advancedgallery'");

    $db->delete_query('templates', "title IN (
        'advancedgallery_page',
        'advancedgallery_index',
        'advancedgallery_tile',
        'advancedgallery_view',
        'advancedgallery_upload'
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
        case 'view':
            $ag_content = ag_render_view();
            break;
        case 'upload':
            $ag_content = ag_render_upload();
            break;
        case 'upload_do':
            ag_handle_upload();
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
    $ag_tiles = $tiles;
    $ag_pagination = $pagination;

    eval('$output = "' . $templates->get('advancedgallery_index') . '";');
    return $output;
}

function ag_render_tile(array $media): string
{
    global $mybb, $templates;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $title = htmlspecialchars_uni((string)$media['title']);
    $thumbRel = $media['thumb_path'] ?: $media['storage_path'];
    $thumbUrl = $bburl . '/' . ltrim($thumbRel, '/');

    $user = get_user((int)$media['uid_owner']);
    $authorName = htmlspecialchars_uni($user['username'] ?? '');
    $ag_author = $authorName !== '' ? build_profile_link($authorName, (int)$media['uid_owner']) : '';

    $ag_view_url = 'gallery.php?action=view&id='.(int)$media['id'];
    $ag_thumb_url = $thumbUrl;
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
    $imageRel = $media['preview_path'] ?: $media['storage_path'];
    $imageUrl = $bburl . '/' . ltrim($imageRel, '/');

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
    $ag_image_url = $imageUrl;
    $ag_bbcode = '[img]'.$imageUrl.'[/img]';

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
    $raw = (string)af_ag_get_setting($key, '');
    if ($raw === '') {
        return [];
    }
    $items = array_filter(array_map('trim', explode(',', $raw)));
    return array_map('intval', $items);
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
    $allowed = ag_groups_from_setting('af_advancedgallery_can_upload_groups');
    return ag_user_in_groups($allowed);
}

function ag_can_moderate(): bool
{
    $allowed = ag_groups_from_setting('af_advancedgallery_can_moderate_groups');
    return ag_user_in_groups($allowed);
}

function ag_is_autoapprove(): bool
{
    $allowed = ag_groups_from_setting('af_advancedgallery_autoapprove_groups');
    return ag_user_in_groups($allowed);
}

function ag_can_delete_media(array $media): bool
{
    global $mybb;
    if (ag_can_moderate()) {
        return true;
    }
    return (int)$media['uid_owner'] === (int)$mybb->user['uid'];
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
