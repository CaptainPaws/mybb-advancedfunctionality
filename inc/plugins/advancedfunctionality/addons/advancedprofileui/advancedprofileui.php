<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_APUI_ID', 'advancedprofileui');
define('AF_APUI_BASE', AF_ADDONS . AF_APUI_ID . '/');
define('AF_APUI_TEMPLATES_DIR', AF_APUI_BASE . 'templates/');
define('AF_APUI_ASSETS_DIR', AF_APUI_BASE . 'assets/');
define('AF_APUI_BACKUP_TABLE_NAME', 'af_apui_template_backups');
define('AF_APUI_BACKUP_TABLE', TABLE_PREFIX . AF_APUI_BACKUP_TABLE_NAME);
define('AF_APUI_MARKER_PROFILE_START', '<!-- AF_APUI member_profile START -->');
define('AF_APUI_MARKER_POSTBIT_START', '<!-- AF_APUI postbit_classic START -->');
define('AF_APUI_MARKER_THREAD_START', '<!-- AF_APUI showthread START -->');
define('AF_APUI_ASSET_MARK', '<!--af_apui_assets-->');
define('AF_APUI_THEME_STYLESHEET_LOGICAL_ID', 'advancedprofileui_main');

function af_advancedprofileui_install(): void
{
    global $db;

    af_apui_ensure_schema();
    af_apui_ensure_settings();
    af_apui_apply_overrides();
    if (function_exists('af_sync_theme_stylesheets')) {
        af_sync_theme_stylesheets(false, AF_APUI_ID);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedprofileui_activate(): void
{
    af_apui_ensure_schema();
    af_apui_ensure_settings();
    af_apui_apply_overrides();
    if (function_exists('af_sync_theme_stylesheets')) {
        af_sync_theme_stylesheets(false, AF_APUI_ID);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedprofileui_deactivate(): void
{
    af_apui_restore_overrides();
}

function af_advancedprofileui_uninstall(): void
{
    global $db;

    af_apui_restore_overrides();

    if ($db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        $db->drop_table(AF_APUI_BACKUP_TABLE_NAME);
    }

    $db->delete_query('settings', "name LIKE 'af_" . AF_APUI_ID . "_%'");
    $db->delete_query('settinggroups', "name='af_" . AF_APUI_ID . "'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_apui_is_enabled(): bool
{
    global $mybb;

    return !empty($mybb->settings['af_' . AF_APUI_ID . '_enabled']);
}

function af_apui_ensure_settings(): void
{
    af_apui_ensure_setting_group();

    $definitions = [
        ['enabled', 'Включить AdvancedProfileUI', 'Включает подмену шаблонов member_profile и postbit_classic и подключение ассетов.', 'yesno', '1', 1],
        ['css_delivery_mode', 'Режим доставки CSS (file/theme/auto)', "file — CSS из файла аддона; theme — CSS из theme stylesheet; auto — theme stylesheet с fallback на file.", "select\nfile=File (legacy)\ntheme=Theme stylesheet\nauto=Auto (theme -> file fallback)", 'auto', 9],
        ['member_profile_body_cover_url', 'member_profile: фон body (большое изображение)', 'URL большого фонового изображения для body на странице профиля.', 'text', '', 2],
        ['member_profile_body_tile_url', 'member_profile: фон body (бесшовная плитка)', 'URL маленького бесшовного изображения для tiled-фона body.', 'text', '', 3],
        ['member_profile_body_bg_mode', 'member_profile: режим фона body', 'cover или tile.', 'text', 'cover', 4],
        ['member_profile_body_overlay', 'member_profile: оверлей фона body', 'CSS background-image слой для оверлея body.', 'text', 'none', 5],
        ['profile_banner_url', 'member_profile: баннер по умолчанию', 'URL картинки баннера для member_profile.', 'text', '', 6],
        ['profile_banner_overlay', 'member_profile: оверлей баннера', 'CSS background-image слой для оверлея баннера.', 'text', 'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%)', 7],
        ['member_profile_css', 'member_profile: пользовательский CSS', 'Дополнительный CSS для member_profile.', 'textarea', '', 8],
        ['postbit_author_bg_url', 'postbit_classic: фон профиля по умолчанию', 'URL фоновой картинки авторского блока postbit.', 'text', '', 20],
        ['postbit_author_overlay', 'postbit_classic: оверлей фона профиля', 'CSS background-image слой для оверлея авторского блока postbit.', 'text', 'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(9, 9, 9, 0.87) 42%, rgb(0, 0, 0) 100%)', 21],
        ['postbit_name_bg_url', 'postbit_classic: фон никнейма по умолчанию', 'URL фоновой картинки для блока никнейма.', 'text', '', 22],
        ['postbit_name_overlay', 'postbit_classic: оверлей никнейма', 'CSS background-image слой для оверлея блока никнейма.', 'text', 'linear-gradient(180deg, rgba(0, 0, 0, 0.24), rgba(69, 69, 69, 0.28))', 23],
        ['postbit_plaque_bg_url', 'postbit_classic: фон нижней плашки', 'URL фоновой картинки для нижней плашки postbit.', 'text', '', 24],
        ['postbit_plaque_overlay', 'postbit_classic: оверлей нижней плашки', 'CSS background-image слой для оверлея нижней плашки postbit.', 'text', 'linear-gradient(180deg, rgba(10, 14, 24, 0.17), rgba(0, 0, 0, 0.85))', 25],
        ['postbit_plaque_media_image_url', 'postbit_classic: URL медиа-картинки нижней плашки', 'URL изображения для левого медиа-блока нижней плашки postbit.', 'text', '', 26],
        ['postbit_plaque_media_icon_class', 'postbit_classic: fallback icon class медиа-блока нижней плашки', 'CSS/font icon class для левого медиа-блока, если картинка не задана.', 'text', '', 27],
        ['postbit_plaque_media_overlay', 'postbit_classic: overlay медиа-блока нижней плашки', 'CSS background-image слой для overlay левого медиа-блока.', 'text', 'none', 28],
        ['postbit_plaque_media_css', 'postbit_classic: дополнительный CSS медиа-блока нижней плашки', 'Дополнительный CSS для контейнера левого медиа-блока нижней плашки.', 'text', '', 29],
        ['postbit_plaque_title_default', 'postbit_classic: заголовок нижней плашки по умолчанию', 'Основной заголовок плашки, если он не задан пресетом/магазином.', 'text', 'Profile plaque', 30],
        ['postbit_plaque_subtitle_default', 'postbit_classic: подзаголовок нижней плашки по умолчанию', 'Вторичная строка плашки, если она не задана пресетом/магазином.', 'text', 'Decorative media slot', 31],
        ['postbit_plaque_icon_url', 'postbit_classic: legacy URL иконки нижней плашки', 'Совместимость со старым URL изображения для иконки внутри нижней плашки postbit.', 'text', '', 32],
        ['postbit_plaque_icon_glyph', 'postbit_classic: legacy fallback-символ иконки нижней плашки', 'Совместимость со старым текстовым fallback-символом для иконки внутри нижней плашки postbit.', 'text', '★', 33],
        ['postbit_plaque_icon_bg', 'postbit_classic: фон контейнера медиа плашки', 'Фон контейнера медиа внутри нижней плашки postbit.', 'text', 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))', 34],
        ['postbit_plaque_icon_overlay', 'postbit_classic: legacy overlay контейнера иконки плашки', 'CSS background-image слой для overlay контейнера иконки внутри нижней плашки postbit.', 'text', 'none', 35],
        ['postbit_plaque_icon_border', 'postbit_classic: border контейнера медиа плашки', 'Цвет рамки контейнера медиа внутри нижней плашки postbit.', 'text', 'rgba(255,255,255,.18)', 36],
        ['postbit_plaque_icon_color', 'postbit_classic: цвет fallback-иконки нижней плашки', 'Цвет fallback-иконки внутри нижней плашки postbit.', 'text', '#f6f1cf', 37],
        ['postbit_plaque_icon_size', 'postbit_classic: размер медиа-блока нижней плашки', 'CSS размер медиа-блока и fallback-иконки внутри нижней плашки postbit.', 'text', '26px', 38],
        ['postbit_css', 'postbit_classic: пользовательский CSS', 'Дополнительный CSS для postbit_classic.', 'textarea', '', 39],
        ['thread_body_cover_url', 'showthread: фон body (большое изображение)', 'URL большого фонового изображения для страницы темы.', 'text', '', 70],
        ['thread_body_tile_url', 'showthread: фон body (бесшовная плитка)', 'URL маленького бесшовного изображения для tiled-фона страницы темы.', 'text', '', 71],
        ['thread_body_bg_mode', 'showthread: режим фона body', 'cover или tile.', 'text', 'cover', 72],
        ['thread_body_overlay', 'showthread: оверлей фона body', 'CSS background-image слой для оверлея body страницы темы.', 'text', 'none', 73],
        ['thread_banner_url', 'showthread: баннер темы по умолчанию', 'URL картинки hero-баннера страницы темы.', 'text', '', 74],
        ['thread_banner_overlay', 'showthread: оверлей баннера темы', 'CSS background-image слой для оверлея баннера showthread.', 'text', 'linear-gradient(180deg, rgba(8, 12, 24, 0.08) 0%, rgba(8, 12, 24, 0.36) 42%, rgba(8, 12, 24, 0.88) 100%)', 75],
        ['thread_css', 'showthread: пользовательский CSS', 'Дополнительный CSS для страницы темы.', 'textarea', '', 76],
        ['sheet_bg_url', 'character sheet: background image url', 'Базовый фон листа персонажа.', 'text', '', 30],
        ['sheet_bg_overlay', 'character sheet: background overlay', 'CSS overlay для листа персонажа.', 'text', 'linear-gradient(180deg, rgba(6, 10, 18, .24) 0%, rgba(6, 10, 18, .78) 100%)', 31],
        ['sheet_panel_bg', 'character sheet: panel/card background', 'Базовый фон панелей листа персонажа.', 'text', 'rgba(0, 0, 0, 0.12)', 32],
        ['sheet_panel_border', 'character sheet: panel/card border', 'Базовая рамка панелей листа персонажа.', 'text', 'rgba(255,255,255,.12)', 33],
        ['sheet_css', 'character sheet: custom css', 'Дополнительный CSS для листа персонажа.', 'textarea', '', 34],
        ['application_bg_url', 'application: background image url', 'Базовый фон анкеты.', 'text', '', 40],
        ['application_bg_overlay', 'application: background overlay', 'CSS overlay для анкеты.', 'text', 'linear-gradient(180deg, rgba(6, 10, 18, .20) 0%, rgba(6, 10, 18, .58) 55%, rgba(6, 10, 18, .88) 100%)', 41],
        ['application_panel_bg', 'application: panel/card background', 'Базовый фон панели анкеты.', 'text', 'rgba(6, 12, 26, .58)', 42],
        ['application_panel_border', 'application: panel/card border', 'Базовая рамка панели анкеты.', 'text', 'rgba(255,255,255,.10)', 43],
        ['application_css', 'application: custom css', 'Дополнительный CSS для анкеты.', 'textarea', '', 44],
        ['inventory_bg_url', 'inventory: background image url', 'Базовый фон инвентаря.', 'text', '', 50],
        ['inventory_bg_overlay', 'inventory: background overlay', 'CSS overlay для инвентаря.', 'text', 'linear-gradient(180deg, rgba(6, 10, 18, .26) 0%, rgba(6, 10, 18, .72) 100%)', 51],
        ['inventory_panel_bg', 'inventory: panel/card background', 'Базовый фон панелей инвентаря.', 'text', 'rgba(21, 25, 34, .92)', 52],
        ['inventory_panel_border', 'inventory: panel/card border', 'Базовая рамка панелей инвентаря.', 'text', 'rgba(255,255,255,.12)', 53],
        ['inventory_css', 'inventory: custom css', 'Дополнительный CSS для инвентаря.', 'textarea', '', 54],
        ['achievements_bg_url', 'achievements: background image url', 'Базовый фон ачивок.', 'text', '', 60],
        ['achievements_bg_overlay', 'achievements: background overlay', 'CSS overlay для ачивок.', 'text', 'linear-gradient(180deg, rgba(6, 10, 18, .24) 0%, rgba(6, 10, 18, .78) 100%)', 61],
        ['achievements_panel_bg', 'achievements: panel/card background', 'Базовый фон панелей ачивок.', 'text', 'rgba(13, 17, 28, .74)', 62],
        ['achievements_panel_border', 'achievements: panel/card border', 'Базовая рамка панелей ачивок.', 'text', 'rgba(255,255,255,.12)', 63],
        ['achievements_css', 'achievements: custom css', 'Дополнительный CSS для ачивок.', 'textarea', '', 64],
    ];

    foreach ($definitions as $definition) {
        af_apui_ensure_setting_preserve_value(
            'af_' . AF_APUI_ID . '_' . $definition[0],
            $definition[1],
            $definition[2],
            $definition[3],
            $definition[4],
            (int)$definition[5]
        );
    }
}

function af_apui_ensure_setting_group(): int
{
    global $db;

    $name = 'af_' . AF_APUI_ID;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        $db->update_query('settinggroups', [
            'title' => $db->escape_string('AdvancedProfileUI'),
            'description' => $db->escape_string('Каркас кастомного UI профиля, postbit и модальных поверхностей с безопасной подменой шаблонов.'),
        ], "gid='" . $gid . "'");
        return $gid;
    }

    $max = (int)$db->fetch_field($db->simple_select('settinggroups', 'MAX(disporder) AS m'), 'm');
    $db->insert_query('settinggroups', [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string('AdvancedProfileUI'),
        'description' => $db->escape_string('Каркас кастомного UI профиля, postbit и модальных поверхностей с безопасной подменой шаблонов.'),
        'disporder' => $max + 1,
        'isdefault' => 0,
    ]);

    return (int)$db->insert_id();
}

function af_apui_ensure_setting_preserve_value(string $name, string $title, string $desc, string $type, string $defaultValue, int $disporder): void
{
    global $db;

    $gid = af_apui_ensure_setting_group();
    $escapedName = $db->escape_string($name);
    $query = $db->simple_select('settings', 'sid', "name='" . $escapedName . "'", ['order_by' => 'sid', 'order_dir' => 'ASC']);
    $sids = [];
    while ($row = $db->fetch_array($query)) {
        $sids[] = (int)$row['sid'];
    }

    if (empty($sids)) {
        $db->insert_query('settings', [
            'name' => $escapedName,
            'title' => $db->escape_string($title),
            'description' => $db->escape_string($desc),
            'optionscode' => $db->escape_string($type),
            'value' => $db->escape_string($defaultValue),
            'disporder' => $disporder,
            'gid' => $gid,
        ]);
        return;
    }

    $keepSid = array_shift($sids);
    if (!empty($sids)) {
        $db->delete_query('settings', 'sid IN (' . implode(',', array_map('intval', $sids)) . ')');
    }

    $db->update_query('settings', [
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'disporder' => $disporder,
        'gid' => $gid,
    ], "sid='" . (int)$keepSid . "'");
}

function af_apui_ensure_schema(): void
{
    global $db;

    if ($db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        return;
    }

    $charset = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_APUI_BACKUP_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_tid INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            sid SMALLINT NOT NULL,
            original_template MEDIUMTEXT NOT NULL,
            original_dateline BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marker VARCHAR(64) NOT NULL DEFAULT 'af_apui',
            checksum CHAR(40) NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            updated_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_template_tid (template_tid),
            KEY idx_title_sid (title, sid)
        ) " . $charset
    );
}

function af_apui_register_hooks(): void
{
    global $plugins;

    if (!af_apui_is_enabled()) {
        return;
    }

    $plugins->add_hook('member_profile_end', 'af_apui_member_profile_end', 5);

    // ВАЖНО: ставим поздний приоритет, чтобы другие плагины успели
    // дописать user_details до того, как APUI начнет его разбирать.
    $plugins->add_hook('postbit', 'af_apui_postbit_compose_userdetails', 100);
    $plugins->add_hook('postbit_prev', 'af_apui_postbit_compose_userdetails', 100);
    $plugins->add_hook('postbit_pm', 'af_apui_postbit_compose_userdetails', 100);

    $plugins->add_hook('pre_output_page', 'af_apui_pre_output_page', 10);
    $plugins->add_hook('global_start', 'af_apui_global_start', 10);
}
af_apui_register_hooks();

function af_apui_get_css_delivery_mode(): string
{
    global $mybb;

    $mode = strtolower(trim((string)($mybb->settings['af_' . AF_APUI_ID . '_css_delivery_mode'] ?? 'auto')));
    if (!in_array($mode, ['file', 'theme', 'auto'], true)) {
        $mode = 'auto';
    }

    return $mode;
}

function af_apui_current_theme_tid(): int
{
    $theme = $GLOBALS['theme'] ?? null;
    if (is_array($theme ?? null)) {
        $tid = (int)($theme['tid'] ?? 0);
        if ($tid > 0) {
            return $tid;
        }
    }

    global $mybb;
    $tid = (int)($mybb->settings['theme'] ?? 0);
    return max(1, $tid);
}

function af_apui_is_stylesheet_attached_to_request(string $attachedTo): bool
{
    global $mybb;

    $attachedTo = trim($attachedTo);
    if ($attachedTo === '') {
        return false;
    }

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $action = strtolower(trim((string)($mybb->input['action'] ?? '')));
    $tokens = preg_split('~\|~', $attachedTo) ?: [];

    foreach ($tokens as $tokenRaw) {
        $token = trim((string)$tokenRaw);
        if ($token === '') {
            continue;
        }
        if (strtolower($token) === 'global') {
            return true;
        }

        $parts = explode('?', $token, 2);
        $file = strtolower(trim((string)$parts[0]));
        $file = basename($file);
        if ($file === '') {
            continue;
        }
        if (strpos($file, '.php') === false) {
            $file .= '.php';
        }

        if ($script !== '' && $file !== $script) {
            continue;
        }

        if (!isset($parts[1]) || trim((string)$parts[1]) === '') {
            return true;
        }

        parse_str((string)$parts[1], $query);
        $neededAction = strtolower(trim((string)($query['action'] ?? '')));
        if ($neededAction === '' || $neededAction === $action) {
            return true;
        }
    }

    return false;
}

function af_apui_theme_stylesheet_is_available(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $db;

    if (!defined('AF_THEME_STYLESHEETS_TABLE') || !$db->table_exists(AF_THEME_STYLESHEETS_TABLE)) {
        $cached = false;
        return false;
    }

    $themeTid = af_apui_current_theme_tid();
    $addonEsc = $db->escape_string(AF_APUI_ID);
    $logicalEsc = $db->escape_string(AF_APUI_THEME_STYLESHEET_LOGICAL_ID);

    $stateQ = $db->simple_select(
        AF_THEME_STYLESHEETS_TABLE,
        'stylesheet_sid',
        "theme_tid='" . (int)$themeTid . "' AND addon_id='" . $addonEsc . "' AND logical_id='" . $logicalEsc . "'",
        ['limit' => 1]
    );
    $sid = (int)$db->fetch_field($stateQ, 'stylesheet_sid');
    if ($sid <= 0) {
        $cached = false;
        return false;
    }

    $rowQ = $db->simple_select(
        'themestylesheets',
        'sid,attachedto',
        "sid='" . (int)$sid . "' AND tid='" . (int)$themeTid . "'",
        ['limit' => 1]
    );
    $row = $db->fetch_array($rowQ);
    if (!is_array($row) || (int)($row['sid'] ?? 0) <= 0) {
        $cached = false;
        return false;
    }

    $cached = af_apui_is_stylesheet_attached_to_request((string)($row['attachedto'] ?? ''));
    return $cached;
}

function af_apui_css_include_tag(string $baseUrl): string
{
    $fileRel = 'assets/advancedprofileui.css';
    $decision = function_exists('af_theme_stylesheet_delivery_decision')
        ? af_theme_stylesheet_delivery_decision(AF_APUI_ID, $fileRel)
        : ['include_file' => true, 'use_theme_stylesheet' => false, 'theme_href' => ''];

    if (!empty($decision['use_theme_stylesheet']) && !empty($decision['theme_href'])) {
        return '<link rel="stylesheet" href="' . htmlspecialchars_uni((string)$decision['theme_href']) . '">' . "\n";
    }

    if (empty($decision['include_file'])) {
        return '';
    }

    $cssUrl = af_apui_add_ver($baseUrl . '/advancedprofileui.css', AF_APUI_ASSETS_DIR . 'advancedprofileui.css');
    return '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
}



function af_apui_postbit_extract_number(string $value): string
{
    $clean = trim(preg_replace('~\s+~u', ' ', strip_tags($value)));
    if ($clean === '') {
        return '0';
    }

    if (preg_match('~[+\-]?\d[\d\s.,]*~u', $clean, $m)) {
        return trim((string)$m[0]);
    }

    return htmlspecialchars_uni($clean);
}

function af_apui_dom_outer_html($node): string
{
    if (!is_object($node)) {
        return '';
    }

    $doc = $node->ownerDocument ?? null;
    if (!is_object($doc) || !method_exists($doc, 'saveHTML')) {
        return '';
    }

    return (string)$doc->saveHTML($node);
}

function af_apui_postbit_extract_profile_fields(string $userDetails): string
{
    $userDetails = trim($userDetails);
    if ($userDetails === '') {
        return '';
    }

    // Сначала нормальная попытка через DOM, чтобы не ломаться на вложенных span/div
    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $prevUseErrors = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = '<?xml encoding="utf-8" ?><div id="af-apui-root">' . $userDetails . '</div>';

        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[@class and contains(concat(" ", normalize-space(@class), " "), " af-apf-postbit-field ")]');

            if ($nodes !== false && $nodes->length > 0) {
                $out = [];
                foreach ($nodes as $node) {
                    $chunk = trim(af_apui_dom_outer_html($node));
                    if ($chunk !== '') {
                        $out[] = $chunk;
                    }
                }

                libxml_clear_errors();
                libxml_use_internal_errors($prevUseErrors);

                return implode("\n", $out);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);
    }

    // Фолбэк: если DOM не сработал, пробуем более широкий regex
    if (preg_match_all('~<([a-z0-9:_-]+)\b[^>]*class=(["\'])[^"\']*\baf-apf-postbit-field\b[^"\']*\2[^>]*>.*?</\1>~is', $userDetails, $m)) {
        return implode("\n", $m[0]);
    }

    return '';
}
function af_apui_postbit_strip_profile_field_names(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Удаляем название поля и двоеточие после него:
    // <span class="af-apf-name">ЛЗ</span>:
    $html = preg_replace(
        '~<span[^>]*class=(["\'])[^"\']*\baf-apf-name\b[^"\']*\1[^>]*>.*?</span>\s*(?:&nbsp;|&#160;|\s)*:\s*~isu',
        '',
        $html
    ) ?? $html;

    // Фолбэк: если двоеточия нет, но span с названием есть — убираем просто его
    $html = preg_replace(
        '~<span[^>]*class=(["\'])[^"\']*\baf-apf-name\b[^"\']*\1[^>]*>.*?</span>\s*~isu',
        '',
        $html
    ) ?? $html;

    return trim($html);
}

function af_apui_format_duration_ru(int $seconds): string
{
    $seconds = max(0, $seconds);

    if ($seconds < 60) {
        return 'только что';
    }

    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' ' . af_apui_ru_plural($minutes, 'минуту', 'минуты', 'минут');
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' ' . af_apui_ru_plural($hours, 'час', 'часа', 'часов');
    }

    $days = (int) floor($hours / 24);
    return $days . ' ' . af_apui_ru_plural($days, 'день', 'дня', 'дней');
}

function af_apui_ru_plural(int $num, string $one, string $few, string $many): string
{
    $n = abs($num) % 100;
    $n1 = $n % 10;

    if ($n > 10 && $n < 20) {
        return $many;
    }

    if ($n1 > 1 && $n1 < 5) {
        return $few;
    }

    if ($n1 === 1) {
        return $one;
    }

    return $many;
}

function af_apui_build_postbit_presence_html(array $post): string
{
    global $db, $mybb;

    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        $title = htmlspecialchars_uni('Оффлайн');
        return '<span class="af-apui-presence-dot af-apui-presence-dot--offline" title="' . $title . '" data-af-title="' . $title . '" aria-label="' . $title . '"></span>';
    }

    static $presenceCache = [];

    if (isset($presenceCache[$uid])) {
        return $presenceCache[$uid];
    }

    $lastActive = 0;

    if ($db->table_exists('sessions')) {
        $query = $db->simple_select(
            'sessions',
            'MAX(time) AS last_time',
            "uid='" . $uid . "'"
        );
        $lastActive = (int)$db->fetch_field($query, 'last_time');
    }

    if ($lastActive <= 0 && !empty($post['lastactive'])) {
        $lastActive = (int)$post['lastactive'];
    }

    $cutoffSeconds = 15 * 60;

    if (!empty($mybb->settings['wolcutoff'])) {
        $tmp = (int)$mybb->settings['wolcutoff'];
        if ($tmp > 0) {
            $cutoffSeconds = $tmp;
        }
    } elseif (!empty($mybb->settings['wolcutoffmins'])) {
        $tmp = (int)$mybb->settings['wolcutoffmins'];
        if ($tmp > 0) {
            $cutoffSeconds = $tmp * 60;
        }
    }

    $isOnline = $lastActive > 0 && $lastActive >= (TIME_NOW - $cutoffSeconds);

    if ($isOnline) {
        $delta = max(0, TIME_NOW - $lastActive);
        $title = 'Онлайн';
        if ($delta < 60) {
            $title .= ' · активен только что';
        } else {
            $title .= ' · активен ' . af_apui_format_duration_ru($delta) . ' назад';
        }

        $title = htmlspecialchars_uni($title);

        $presenceCache[$uid] =
            '<span class="af-apui-presence-dot af-apui-presence-dot--online"'
            . ' title="' . $title . '"'
            . ' data-af-title="' . $title . '"'
            . ' aria-label="' . $title . '"></span>';

        return $presenceCache[$uid];
    }

    $title = 'Оффлайн';
    if ($lastActive > 0) {
        $title .= ' · был активен ' . af_apui_format_duration_ru(max(0, TIME_NOW - $lastActive)) . ' назад';
    }

    $title = htmlspecialchars_uni($title);

    $presenceCache[$uid] =
        '<span class="af-apui-presence-dot af-apui-presence-dot--offline"'
        . ' title="' . $title . '"'
        . ' data-af-title="' . $title . '"'
        . ' aria-label="' . $title . '"></span>';

    return $presenceCache[$uid];
}

function af_apui_decode_action_url(string $url): string
{
    $url = trim(htmlspecialchars_decode($url, ENT_QUOTES));
    return $url;
}

function af_apui_append_query_arg(string $url, string $key, $value): string
{
    $url = af_apui_decode_action_url($url);
    $key = trim($key);

    if ($url === '' || $key === '' || $value === null || $value === '') {
        return $url;
    }

    $fragment = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }

    $base = $url;
    $queryString = '';

    $questionPos = strpos($url, '?');
    if ($questionPos !== false) {
        $base = substr($url, 0, $questionPos);
        $queryString = substr($url, $questionPos + 1);
    }

    $query = [];
    if ($queryString !== '') {
        parse_str($queryString, $query);
    }

    $query[$key] = (string)$value;
    $newQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    return $base . ($newQuery !== '' ? '?' . $newQuery : '') . $fragment;
}

function af_apui_build_surface_url(string $url, int $uid, string $surface): string
{
    $url = af_apui_decode_action_url($url);
    $surface = trim(strtolower($surface));

    if ($url === '' || $uid <= 0 || $surface === '') {
        return $url;
    }

    $url = af_apui_append_query_arg($url, 'uid', $uid);
    $url = af_apui_append_query_arg($url, 'af_apui_owner_uid', $uid);
    $url = af_apui_append_query_arg($url, 'af_apui_surface', $surface);

    return $url;
}

function af_apui_get_charactersheet_postbit_payload(int $uid): array
{
    if ($uid <= 0 || !function_exists('af_cs_get_postbit_sheet_payload')) {
        return [];
    }

    $payload = af_cs_get_postbit_sheet_payload($uid);

    return is_array($payload) ? $payload : [];
}

function af_apui_extract_query_int_from_url(string $url, string $key): int
{
    $url = af_apui_decode_action_url($url);
    if ($url === '') {
        return 0;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['query'])) {
        return 0;
    }

    $query = [];
    parse_str((string)$parts['query'], $query);

    return isset($query[$key]) ? (int)$query[$key] : 0;
}

function af_apui_build_application_thread_url(int $tid, int $pid = 0): string
{
    if ($tid <= 0) {
        return '';
    }

    if ($pid > 0) {
        return 'showthread.php?tid=' . $tid . '&pid=' . $pid . '#pid' . $pid;
    }

    return 'showthread.php?tid=' . $tid;
}

function af_apui_build_postbit_action_button(array $config): string
{
    $labelRaw = trim((string)($config['label'] ?? ''));
    $urlRaw = af_apui_decode_action_url((string)($config['url'] ?? ''));

    if ($labelRaw === '' || $urlRaw === '') {
        return '';
    }

    $titleRaw = trim((string)($config['title'] ?? $labelRaw));
    $icon = (string)($config['icon'] ?? 'fa-solid fa-up-right-from-square');
    $extraAttrs = trim((string)($config['extra_attrs'] ?? ''));
    $useApuiModal = array_key_exists('use_apui_modal', $config) ? (bool)$config['use_apui_modal'] : true;
    $modalKind = trim((string)($config['modal_kind'] ?? 'iframe'));

    $label = htmlspecialchars_uni($labelRaw);
    $title = htmlspecialchars_uni($titleRaw);
    $url = htmlspecialchars_uni($urlRaw);
    $modalUrlRaw = af_apui_decode_action_url((string)($config['modal_url'] ?? $urlRaw));
    $modalUrl = htmlspecialchars_uni($modalUrlRaw);

    $classes = trim(
        'af-apui-postbit-action '
        . (string)($config['modifier'] ?? '')
        . ' '
        . (string)($config['compat_class'] ?? '')
    );

    $attrs = '';

    if ($useApuiModal && $modalUrlRaw !== '') {
        $attrs .= ' data-af-apui-modal-url="' . $modalUrl . '"';
        $attrs .= ' data-af-apui-modal-title="' . $title . '"';

        if ($modalKind !== '') {
            $attrs .= ' data-af-apui-modal-kind="' . htmlspecialchars_uni($modalKind) . '"';
        }
    }

    if (!empty($config['data_attrs']) && is_array($config['data_attrs'])) {
        foreach ($config['data_attrs'] as $attrName => $attrValue) {
            $attrName = trim((string)$attrName);

            if (
                $attrName === ''
                || !preg_match('~^[a-zA-Z_:][a-zA-Z0-9:._-]*$~', $attrName)
                || $attrValue === null
                || $attrValue === false
            ) {
                continue;
            }

            $attrs .= ' ' . $attrName . '="' . htmlspecialchars_uni((string)$attrValue) . '"';
        }
    }

    if ($extraAttrs !== '') {
        $attrs .= ' ' . $extraAttrs;
    }

    return '<a class="' . htmlspecialchars_uni($classes) . '" href="' . $url . '"'
        . $attrs
        . ' aria-label="' . $title . '"'
        . ' title="' . $title . '"'
        . '>'
        . '<span class="af-apui-postbit-action__icon" aria-hidden="true"><i class="' . htmlspecialchars_uni($icon) . '"></i></span>'
        . '<span class="af-apui-postbit-action__label">' . $label . '</span>'
        . '</a>';
}

function af_apui_resolve_application_url(array $post, array $sheetPayload = []): string
{
    $application = (array)($sheetPayload['application'] ?? []);
    $canonicalUrl = af_apui_decode_action_url((string)($application['topic_url'] ?? ($sheetPayload['application_topic_url'] ?? $sheetPayload['application_url'] ?? '')));
    $postUrl = af_apui_decode_action_url((string)($application['post_url'] ?? ($sheetPayload['application_post_url'] ?? '')));
    $tid = (int)($application['tid'] ?? ($sheetPayload['application_tid'] ?? 0));
    $pid = (int)($application['pid'] ?? ($sheetPayload['application_pid'] ?? 0));

    if ($canonicalUrl !== '') {
        return $canonicalUrl;
    }

    if ($tid > 0) {
        return af_apui_build_application_thread_url($tid, $pid);
    }

    if ($postUrl !== '') {
        return $postUrl;
    }

    return '';
}

function af_apui_is_ajax_like_url(string $url): bool
{
    $url = af_apui_decode_action_url($url);
    if ($url === '') {
        return false;
    }

    return (bool)preg_match(
        '~(?:^|[?&])(ajax=1|action=(?:af_charactersheet_api|cs_[^&]*_ajax))(?:[&]|$)~i',
        $url
    );
}

function af_apui_resolve_application_fetch_url(array $sheetPayload): string
{
    $application = (array)($sheetPayload['application'] ?? []);

    $topicUrl = af_apui_decode_action_url((string)($application['topic_url'] ?? ($sheetPayload['application_topic_url'] ?? $sheetPayload['application_url'] ?? '')));
    $postUrl = af_apui_decode_action_url((string)($application['post_url'] ?? ($sheetPayload['application_post_url'] ?? '')));
    $embedUrl = af_apui_decode_action_url((string)($application['embed_url'] ?? ($sheetPayload['application_embed_url'] ?? '')));
    $tid = (int)($application['tid'] ?? ($sheetPayload['application_tid'] ?? 0));
    $pid = (int)($application['pid'] ?? ($sheetPayload['application_pid'] ?? 0));

    // 1) Для fetch нам нужна НОРМАЛЬНАЯ страница темы/поста, а не ajax/api endpoint.
    if ($topicUrl !== '' && !af_apui_is_ajax_like_url($topicUrl)) {
        return $topicUrl;
    }

    if ($tid > 0) {
        $built = af_apui_build_application_thread_url($tid, $pid);
        if ($built !== '' && !af_apui_is_ajax_like_url($built)) {
            return $built;
        }
    }

    if ($postUrl !== '' && !af_apui_is_ajax_like_url($postUrl)) {
        return $postUrl;
    }

    // 2) embed_url используем только как запасной вариант и только если он не ajax-like.
    if ($embedUrl !== '' && !af_apui_is_ajax_like_url($embedUrl)) {
        return $embedUrl;
    }

    // 3) Последний фолбэк — хоть что-то, если больше ничего нет.
    if ($topicUrl !== '') {
        return $topicUrl;
    }

    if ($tid > 0) {
        return af_apui_build_application_thread_url($tid, $pid);
    }

    if ($postUrl !== '') {
        return $postUrl;
    }

    return $embedUrl;
}

function af_apui_resolve_application_pid(array $sheetPayload): int
{
    $application = (array)($sheetPayload['application'] ?? []);

    $pid = (int)($application['pid'] ?? ($sheetPayload['application_pid'] ?? 0));
    if ($pid > 0) {
        return $pid;
    }

    $candidates = [
        (string)($application['topic_url'] ?? ''),
        (string)($sheetPayload['application_topic_url'] ?? ''),
        (string)($application['post_url'] ?? ''),
        (string)($sheetPayload['application_post_url'] ?? ''),
        (string)($application['embed_url'] ?? ''),
        (string)($sheetPayload['application_embed_url'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $candidate = af_apui_decode_action_url($candidate);
        if ($candidate === '') {
            continue;
        }

        $pid = af_apui_extract_query_int_from_url($candidate, 'pid');
        if ($pid > 0) {
            return $pid;
        }

        if (preg_match('~#pid(\d+)~i', $candidate, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
}

function af_apui_build_postbit_actionbar_html(array $post, array $sheetPayload = []): string
{
    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        return '';
    }

    $buttons = [];

    $sheetUrl = '';
    $sheetLabel = 'Лист персонажа';
    $sheetExtraAttrs = '';

    if (!empty($sheetPayload['sheet_url'])) {
        $sheetUrl = af_apui_decode_action_url((string)($sheetPayload['sheet_url'] ?? ''));
    }

    if (!empty($sheetPayload['button_label'])) {
        $sheetLabel = (string)($sheetPayload['button_label'] ?? 'Лист персонажа');
    }

    if ($sheetUrl !== '') {
        $sheetSurfaceUrl = af_apui_build_surface_url($sheetUrl, $uid, 'sheet');

        $sheetExtraAttrs =
            'data-afcs-open="1"'
            . ' data-afcs-sheet="' . htmlspecialchars_uni($sheetSurfaceUrl) . '"'
            . ' data-slug="' . htmlspecialchars_uni((string)($sheetPayload['sheet_slug'] ?? '')) . '"'
            . ' data-af-apui-owner-uid="' . $uid . '"'
            . ' data-af-apui-surface="sheet"';

        $buttons[] = af_apui_build_postbit_action_button([
            'label' => $sheetLabel,
            'title' => $sheetLabel,
            'url' => $sheetSurfaceUrl,
            'icon' => 'fa-solid fa-id-card',
            'modifier' => 'af-apui-postbit-action--sheet',
            'compat_class' => 'af-cs-plaque__btn',
            'extra_attrs' => $sheetExtraAttrs,
            'use_apui_modal' => false,
            'data_attrs' => [
                'data-af-apui-owner-uid' => (string)$uid,
                'data-af-apui-surface' => 'sheet',
            ],
        ]);
    }

    $buttons = array_values(array_filter($buttons));
    if (!$buttons) {
        return '';
    }

    return '<div class="af-apui-postbit-actions" aria-label="Postbit actions">' . implode('', $buttons) . '</div>';
}

function af_apui_get_postbit_plaque_icon_settings(int $uid): array
{
    static $cache = [];

    if (isset($cache[$uid])) {
        return $cache[$uid];
    }

    $settings = [
        'postbit_plaque_media_image_url' => trim((string)af_apui_get_setting_value('postbit_plaque_media_image_url', '')),
        'postbit_plaque_media_icon_class' => trim((string)af_apui_get_setting_value('postbit_plaque_media_icon_class', '')),
        'postbit_plaque_media_overlay' => trim((string)af_apui_get_setting_value('postbit_plaque_media_overlay', 'none')),
        'postbit_plaque_media_css' => trim((string)af_apui_get_setting_value('postbit_plaque_media_css', '')),
        'postbit_plaque_title_default' => trim((string)af_apui_get_setting_value('postbit_plaque_title_default', 'Profile plaque')),
        'postbit_plaque_subtitle_default' => trim((string)af_apui_get_setting_value('postbit_plaque_subtitle_default', 'Decorative media slot')),
        'postbit_plaque_title' => '',
        'postbit_plaque_subtitle' => '',
        'postbit_plaque_icon_url' => trim((string)af_apui_get_setting_value('postbit_plaque_icon_url', '')),
        'postbit_plaque_icon_glyph' => trim((string)af_apui_get_setting_value('postbit_plaque_icon_glyph', '★')),
    ];

    if ($settings['postbit_plaque_icon_glyph'] === '') {
        $settings['postbit_plaque_icon_glyph'] = '★';
    }
    if ($settings['postbit_plaque_title_default'] === '') {
        $settings['postbit_plaque_title_default'] = 'Profile plaque';
    }
    if ($settings['postbit_plaque_subtitle_default'] === '') {
        $settings['postbit_plaque_subtitle_default'] = 'Decorative media slot';
    }

    if (
        $uid > 0
        && function_exists('af_aa_get_apui_defaults')
        && function_exists('af_aa_get_user_preset_settings_for_target')
        && function_exists('af_aa_merge_keys')
        && defined('AF_AA_TARGET_APUI_THEME_PACK')
        && defined('AF_AA_TARGET_APUI_POSTBIT_PACK')
        && defined('AF_AA_TARGET_APUI_FRAGMENT_PACK')
    ) {
        $defaults = af_aa_get_apui_defaults();
        $iconKeys = [
            'postbit_plaque_media_image_url',
            'postbit_plaque_media_icon_class',
            'postbit_plaque_media_overlay',
            'postbit_plaque_media_css',
            'postbit_plaque_title',
            'postbit_plaque_subtitle',
            'postbit_plaque_title_default',
            'postbit_plaque_subtitle_default',
            'postbit_plaque_icon_url',
            'postbit_plaque_icon_glyph'
        ];
        $resolved = af_aa_merge_keys([], $defaults, $iconKeys);

        $themePack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_THEME_PACK, $defaults);
        if (!empty($themePack)) {
            $resolved = af_aa_merge_keys($resolved, (array)$themePack['settings'], $iconKeys);
        }

        $postbitPack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_POSTBIT_PACK, $defaults);
        if (!empty($postbitPack)) {
            $resolved = af_aa_merge_keys($resolved, (array)$postbitPack['settings'], $iconKeys);
        }

        foreach (['postbit_plaque', 'postbit_plaque_icon'] as $fragmentKey) {
            $fragmentPack = af_aa_get_user_preset_settings_for_target($uid, AF_AA_TARGET_APUI_FRAGMENT_PACK . ':' . $fragmentKey, $defaults);
            if (!empty($fragmentPack)) {
                $resolved = af_aa_merge_keys($resolved, (array)$fragmentPack['settings'], $iconKeys);
            }
        }

        foreach ($iconKeys as $iconKey) {
            if (array_key_exists($iconKey, $resolved)) {
                $settings[$iconKey] = trim((string)$resolved[$iconKey]);
            }
        }
        if ($settings['postbit_plaque_icon_glyph'] === '') {
            $settings['postbit_plaque_icon_glyph'] = '★';
        }
        if ($settings['postbit_plaque_title_default'] === '') {
            $settings['postbit_plaque_title_default'] = 'Profile plaque';
        }
        if ($settings['postbit_plaque_subtitle_default'] === '') {
            $settings['postbit_plaque_subtitle_default'] = 'Decorative media slot';
        }
    }

    $cache[$uid] = $settings;

    return $cache[$uid];
}

function af_apui_build_postbit_plaque_html(array $post): string
{
    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        return '';
    }

    $plaqueSettings = af_apui_get_postbit_plaque_icon_settings($uid);
    $mediaImageUrl = trim((string)($plaqueSettings['postbit_plaque_media_image_url'] ?? ''));
    if ($mediaImageUrl === '') {
        $mediaImageUrl = trim((string)($plaqueSettings['postbit_plaque_icon_url'] ?? ''));
    }

    $mediaIconClass = trim((string)($plaqueSettings['postbit_plaque_media_icon_class'] ?? ''));
    $mediaOverlay = trim((string)($plaqueSettings['postbit_plaque_media_overlay'] ?? ''));
    $mediaCss = trim((string)($plaqueSettings['postbit_plaque_media_css'] ?? ''));
    $title = trim((string)($plaqueSettings['postbit_plaque_title'] ?? ($plaqueSettings['postbit_plaque_title_default'] ?? 'Profile plaque')));
    $subtitle = trim((string)($plaqueSettings['postbit_plaque_subtitle'] ?? ($plaqueSettings['postbit_plaque_subtitle_default'] ?? 'Decorative media slot')));
    $legacyGlyph = trim((string)($plaqueSettings['postbit_plaque_icon_glyph'] ?? '★'));
    if ($legacyGlyph === '') {
        $legacyGlyph = '★';
    }
    if ($title === '') {
        $title = 'Profile plaque';
    }
    if ($subtitle === '') {
        $subtitle = 'Decorative media slot';
    }

    $mediaStyle = '';
    if ($mediaOverlay !== '') {
        $mediaStyle .= '--af-apui-postbit-plaque-media-overlay:' . htmlspecialchars_uni($mediaOverlay) . ';';
    }
    if ($mediaCss !== '') {
        $mediaStyle .= htmlspecialchars_uni($mediaCss);
    }
    $mediaAttr = $mediaStyle !== '' ? ' style="' . $mediaStyle . '"' : '';

    if ($mediaImageUrl !== '') {
        $mediaHtml = '<img class="af-apui-postbit-plaque__media-image" src="' . htmlspecialchars_uni($mediaImageUrl) . '" alt="" loading="lazy" decoding="async">';
    } elseif ($mediaIconClass !== '') {
        $mediaHtml = '<i class="af-apui-postbit-plaque__media-icon ' . htmlspecialchars_uni($mediaIconClass) . '" aria-hidden="true"></i>';
    } else {
        $mediaHtml = '<span class="af-apui-postbit-plaque__media-glyph" aria-hidden="true">' . htmlspecialchars_uni($legacyGlyph) . '</span>';
    }

    return '<div class="af-apui-postbit-plaque" data-af-apui-plaque="1">'
        . '<span class="af-apui-postbit-plaque__media" data-af-apui-plaque-media="1"' . $mediaAttr . '>' . $mediaHtml . '</span>'
        . '<span class="af-apui-postbit-plaque__content">'
        . '<span class="af-apui-postbit-plaque__title">' . htmlspecialchars_uni($title) . '</span>'
        . '<span class="af-apui-postbit-plaque__subtitle">' . htmlspecialchars_uni($subtitle) . '</span>'
        . '</span>'
        . '</div>';
}

function af_apui_postbit_compose_userdetails(array &$post): void
{
    $userDetails = (string)($post['user_details'] ?? '');
    $profileFields = af_apui_postbit_extract_profile_fields($userDetails);

    if ($profileFields === '' && strpos($userDetails, 'af-apf-postbit-field') !== false) {
        $profileFields = $userDetails;
    }

    $profileFields = af_apui_postbit_strip_profile_field_names($profileFields);

    $postsValue = af_apui_postbit_extract_number((string)($post['postnum'] ?? '0'));
    $threadsValue = af_apui_postbit_extract_number((string)($post['threadnum'] ?? '0'));
    $reputationValue = af_apui_postbit_extract_number((string)($post['replink'] ?? ($post['reputation'] ?? '0')));

    $uid = (int)($post['uid'] ?? 0);
    $pid = (int)($post['pid'] ?? 0);
    $post['af_aa_user_class'] = $uid > 0 ? 'af-aa-postbit-user-' . $uid : '';

    $levelValue = '1';

    $tooltipMessages = htmlspecialchars_uni('Сообщений');
    $tooltipThreads = htmlspecialchars_uni('Тем');
    $tooltipReputation = htmlspecialchars_uni('Репутация');
    $tooltipPosts = htmlspecialchars_uni('Постов');
    $tooltipLevel = htmlspecialchars_uni('Уровень');

    if ($uid > 0 && function_exists('af_balance_get_postbit_data')) {
        $balanceData = af_balance_get_postbit_data($uid);
        $levelValue = (string)((int)($balanceData['level'] ?? 1));
    }

    $isQuickReplyContext = (defined('THIS_SCRIPT') && strtolower((string)THIS_SCRIPT) === 'newreply.php') || defined('IN_XMLHTTP');
    $afApcPostbitHtml = $isQuickReplyContext ? '' : '<af_apc_uid_' . $uid . '>';

    $sheetPayload = af_apui_get_charactersheet_postbit_payload($uid);

    $post['af_apui_presence_html'] = af_apui_build_postbit_presence_html($post);
    $post['af_apui_profile_fields_html'] = $profileFields;
    $post['af_apui_plaque_html'] = af_apui_build_postbit_plaque_html($post);

    $statsHtml =
        '<div class="author_statistics af-apui-postbit-userdetails">'
        . '<span class="af-apui-stat-item af-apui-stat-item--messages" title="' . $tooltipMessages . '" data-af-title="' . $tooltipMessages . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($postsValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--threads" title="' . $tooltipThreads . '" data-af-title="' . $tooltipThreads . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-copy" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($threadsValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--reputation" title="' . $tooltipReputation . '" data-af-title="' . $tooltipReputation . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-heart" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($reputationValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--posts" title="' . $tooltipPosts . '" data-af-title="' . $tooltipPosts . '" data-af-balance-posts="1" data-pid="' . $pid . '" data-uid="' . $uid . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-pen" aria-hidden="true"></i></span><span class="af-apui-stat-item__value"><span class="af-apc-slot" data-af-apc-slot="1" data-uid="' . $uid . '">' . $afApcPostbitHtml . '</span></span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--level" title="' . $tooltipLevel . '" data-af-title="' . $tooltipLevel . '" data-af-balance-level="1" data-pid="' . $pid . '" data-uid="' . $uid . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-signal" aria-hidden="true"></i></span><span class="af-apui-stat-item__value" data-af-balance-level-value="1">' . htmlspecialchars_uni($levelValue) . '</span></span>'
        . '</div>';

    $actionsHtml = af_apui_build_postbit_actionbar_html($post, $sheetPayload);

    $post['af_apui_author_statistics_html'] = $statsHtml;
    $post['af_apui_actionbar_html'] = $actionsHtml;
    $post['af_apui_rail_html'] = '<div class="af-apui-postbit-rail">' . $statsHtml . $actionsHtml . '</div>';
}

function af_apui_global_start(): void
{
    if (defined('IN_ADMINCP')) {
        return;
    }

    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'member.php') {
        af_apui_member_profile_init_vars();
    }
}

function af_apui_member_profile_end(): void
{
    af_apui_member_profile_init_vars();
    af_apui_member_profile_prepare_layout_vars();
}

function af_apui_member_profile_init_vars(): void
{
    foreach ([
        'af_apui_character_sheet_tab',
        'af_apui_application_tab',
        'af_apui_inventory_tab',
        'af_apui_timeline_tab',
        'af_apui_activity_tab',
        'af_apui_forum_info_grid',
        'af_apui_profilefields_grid',
    ] as $varName) {
        if (!isset($GLOBALS[$varName]) || !is_string($GLOBALS[$varName])) {
            $GLOBALS[$varName] = '';
        }
    }
}

function af_apui_member_profile_prepare_layout_vars(): void
{
    global $lang, $memregdate, $memlastvisitdate, $memprofile, $timeonline;
    global $referrals, $reputation, $warning_level, $profilefields, $contact_details;

    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'member.php') {
        return;
    }

    $forumPairs = [
        [
            'label' => (string)($lang->joined ?? 'Дата регистрации'),
            'value' => (string)$memregdate,
        ],
        [
            'label' => (string)($lang->lastvisit ?? 'Последний визит'),
            'value' => (string)$memlastvisitdate,
        ],
        [
            'label' => (string)($lang->total_posts ?? 'Сообщения'),
            'value' => (string)((int)($memprofile['postnum'] ?? 0))
                . (!empty($lang->ppd_percent_total) ? ' <span class="af-apui-inline-note">(' . $lang->ppd_percent_total . ')</span>' : ''),
        ],
        [
            'label' => (string)($lang->total_threads ?? 'Темы'),
            'value' => (string)((int)($memprofile['threadnum'] ?? 0))
                . (!empty($lang->tpd_percent_total) ? ' <span class="af-apui-inline-note">(' . $lang->tpd_percent_total . ')</span>' : ''),
        ],
        [
            'label' => (string)($lang->timeonline ?? 'Время онлайн'),
            'value' => (string)$timeonline,
        ],
    ];

    $forumPairs = array_merge(
        $forumPairs,
        af_apui_extract_pairs_from_html((string)$referrals),
        af_apui_extract_pairs_from_html((string)$reputation),
        af_apui_extract_pairs_from_html((string)$warning_level)
    );

    $extraPairs = array_merge(
        af_apui_extract_pairs_from_html((string)$profilefields),
        af_apui_extract_pairs_from_html((string)$contact_details)
    );

    $GLOBALS['af_apui_forum_info_grid'] = af_apui_render_info_grid(
        $forumPairs,
        'Основная информация пока недоступна.'
    );

    $GLOBALS['af_apui_profilefields_grid'] = af_apui_render_info_grid(
        $extraPairs,
        'Дополнительная информация не заполнена.'
    );

    $uid = (int)($memprofile['uid'] ?? 0);
    $sheetPayload = af_apui_get_charactersheet_postbit_payload($uid);

    $GLOBALS['af_apui_character_sheet_tab'] = af_apui_build_member_profile_sheet_tab($uid, $sheetPayload);
    $GLOBALS['af_apui_application_tab'] = af_apui_build_member_profile_application_tab($uid, $sheetPayload);
    $GLOBALS['af_apui_inventory_tab'] = af_apui_build_member_profile_inventory_tab($uid);
    $GLOBALS['af_apui_timeline_tab'] = af_apui_build_member_profile_placeholder_tab(
        'Хронология',
        'Здесь появится временная линия персонажа: ключевые эпизоды, квесты и сюжетные вехи.',
        'Слой подготовлен в AdvancedProfileUI и ждёт подключения реального источника данных.'
    );
    $GLOBALS['af_apui_activity_tab'] = af_apui_build_member_profile_placeholder_tab(
        'Активность',
        'Здесь появятся последние действия персонажа на форуме и в игровых модулях.',
        'Можно будет подключить посты, ответы, покупки, изменения листа и другие события без перестройки шаблона.'
    );
}

function af_apui_build_member_profile_placeholder_tab(string $title, string $lead, string $note = ''): string
{
    $html = '<div class="af-apui-tab-stack">';
    $html .= '<section class="af-apui-card af-apui-tab-card">';
    $html .= '<div class="af-apui-tab-card__head">';
    $html .= '<h2>' . htmlspecialchars_uni($title) . '</h2>';
    $html .= '<p>' . htmlspecialchars_uni($lead) . '</p>';
    $html .= '</div>';
    if ($note !== '') {
        $html .= '<div class="af-apui-empty af-apui-empty--soft">' . htmlspecialchars_uni($note) . '</div>';
    }
    $html .= '</section>';
    $html .= '</div>';

    return $html;
}

function af_apui_build_member_profile_surface_actions(array $actions): string
{
    $items = [];

    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }

        $url = af_apui_decode_action_url((string)($action['url'] ?? ''));
        $label = trim((string)($action['label'] ?? ''));
        if ($url === '' || $label === '') {
            continue;
        }

        $items[] = af_apui_build_postbit_action_button([
            'label' => $label,
            'title' => (string)($action['title'] ?? $label),
            'url' => $url,
            'modal_url' => (string)($action['modal_url'] ?? $url),
            'modal_kind' => (string)($action['modal_kind'] ?? 'iframe'),
            'icon' => (string)($action['icon'] ?? 'fa-solid fa-up-right-from-square'),
            'modifier' => 'af-apui-postbit-action--profile-tab ' . (string)($action['modifier'] ?? ''),
            'compat_class' => (string)($action['compat_class'] ?? ''),
            'extra_attrs' => (string)($action['extra_attrs'] ?? ''),
            'use_apui_modal' => array_key_exists('use_apui_modal', $action) ? (bool)$action['use_apui_modal'] : true,
            'data_attrs' => !empty($action['data_attrs']) && is_array($action['data_attrs']) ? $action['data_attrs'] : [],
        ]);
    }

    if (!$items) {
        return '';
    }

    return '<div class="af-apui-tab-actions">' . implode('', $items) . '</div>';
}

function af_apui_build_member_profile_tab_shell(string $title, string $description, string $contentHtml, array $actions = [], string $asideHtml = ''): string
{
    $html = '<div class="af-apui-tab-stack">';
    $html .= '<section class="af-apui-card af-apui-tab-card">';
    $html .= '<div class="af-apui-tab-card__head">';
    $html .= '<h2>' . htmlspecialchars_uni($title) . '</h2>';
    if ($description !== '') {
        $html .= '<p>' . htmlspecialchars_uni($description) . '</p>';
    }
    $html .= '</div>';

    $actionsHtml = af_apui_build_member_profile_surface_actions($actions);
    if ($actionsHtml !== '') {
        $html .= $actionsHtml;
    }

    if ($asideHtml !== '') {
        $html .= '<div class="af-apui-tab-card__aside">' . $asideHtml . '</div>';
    }

    $html .= '<div class="af-apui-tab-card__body">' . $contentHtml . '</div>';
    $html .= '</section>';
    $html .= '</div>';

    return $html;
}

function af_apui_build_member_profile_sheet_tab(int $uid, array $sheetPayload): string
{
    $sheetUrl = af_apui_decode_action_url((string)($sheetPayload['sheet_url'] ?? ''));

    if ($sheetUrl === '') {
        return af_apui_build_member_profile_placeholder_tab(
            'Лист персонажа',
            'Лист персонажа для этого профиля пока не подключён.',
            ''
        );
    }

    $sheetSlug = trim((string)($sheetPayload['sheet_slug'] ?? ''));
    $content = '';
    if ($sheetSlug !== '' && function_exists('af_charactersheets_build_sheet_inner_html')) {
        $content = af_charactersheets_build_sheet_inner_html($sheetSlug);
    }

    if (trim($content) === '') {
        $content = '<div class="af-apui-empty">Лист персонажа пока недоступен для встроенного отображения.</div>';
    } else {
        $content = '<div class="af-apui-sheet-fragment">' . $content . '</div>';
    }

    return af_apui_build_member_profile_tab_shell(
        'Лист персонажа',
        '',
        $content
    );
}

function af_apui_build_member_profile_application_tab(int $uid, array $sheetPayload): string
{
    $application = (array)($sheetPayload['application'] ?? []);
    $applicationUrl = af_apui_resolve_application_url(['uid' => $uid], $sheetPayload);
    $tid = (int)($application['tid'] ?? ($sheetPayload['application_tid'] ?? 0));

    if ($applicationUrl === '' || $tid <= 0) {
        return af_apui_build_member_profile_placeholder_tab(
            'Анкета',
            'Для этого профиля пока не найдена привязанная анкета.',
            ''
        );
    }

    $content = '';
    if (function_exists('af_atf_build_display_block_for_tid_fid') && function_exists('get_thread')) {
        $thread = get_thread($tid);
        $fid = (int)($thread['fid'] ?? 0);
        if ($fid > 0) {
            $content = (string)af_atf_build_display_block_for_tid_fid($tid, $fid);
        }
    }

    if (trim($content) === '') {
        $content = '<div class="af-apui-empty">Содержимое анкеты пока недоступно для встроенного вывода. Используйте кнопку открытия темы.</div>';
    } else {
        $content = '<div class="af-apui-application-embed">' . $content . '</div>';
    }

    return af_apui_build_member_profile_tab_shell(
        'Анкета',
        '',
        $content
    );
}

function af_apui_build_member_profile_inventory_tab(int $uid): string
{
    if ($uid <= 0) {
        return af_apui_build_member_profile_placeholder_tab(
            'Инвентарь',
            'Инвентарь пока недоступен.',
            ''
        );
    }

    $content = function_exists('af_advancedinventory_build_inventory_fragment')
        ? af_advancedinventory_build_inventory_fragment($uid)
        : '';

    if (trim($content) === '') {
        $content = '<div class="af-apui-empty">Инвентарь пока недоступен для встроенного отображения.</div>';
    } else {
        $content = '<div class="af-apui-inventory-fragment">' . $content . '</div>';
    }

    return af_apui_build_member_profile_tab_shell(
        'Инвентарь',
        '',
        $content
    );
}

function af_apui_extract_pairs_from_html(string $html): array
{
    $html = trim($html);
    if ($html === '') {
        return [];
    }

    $pairs = [];

    if (!preg_match_all('~<tr\b[^>]*>(.*?)</tr>~is', $html, $rowMatches) || empty($rowMatches[1])) {
        return [];
    }

    foreach ($rowMatches[1] as $rowHtml) {
        if (!preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~is', $rowHtml, $cellMatches) || count($cellMatches[1]) < 2) {
            continue;
        }

        $label = af_apui_normalize_label_text((string)$cellMatches[1][0]);
        $value = af_apui_normalize_value_html((string)$cellMatches[1][1]);

        if ($label === '') {
            continue;
        }

        $pairs[] = [
            'label' => $label,
            'value' => $value !== '' ? $value : '&mdash;',
        ];
    }

    return $pairs;
}
function af_apui_normalize_label_text(string $html): string
{
    $html = str_replace('&nbsp;', ' ', $html);
    $html = preg_replace('~<br\s*/?>~i', ' ', $html) ?? $html;
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('~\s+~u', ' ', $html) ?? $html;

    return trim($html, " \t\n\r\0\x0B:");
}
function af_apui_normalize_value_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = str_replace('&nbsp;', ' ', $html);
    $html = preg_replace('~^\s*<strong\b[^>]*>(.*?)</strong>\s*$~is', '$1', $html) ?? $html;
    $html = preg_replace('~^\s*<span\b[^>]*>(.*?)</span>\s*$~is', '$1', $html) ?? $html;
    $html = preg_replace('~^\s*<div\b[^>]*>(.*?)</div>\s*$~is', '$1', $html) ?? $html;

    return trim($html);
}
function af_apui_render_info_grid(array $pairs, string $emptyMessage = ''): string
{
    if (empty($pairs)) {
        return $emptyMessage !== ''
            ? '<div class="af-apui-empty">' . htmlspecialchars_uni($emptyMessage) . '</div>'
            : '';
    }

    $out = '';

    foreach ($pairs as $pair) {
        $label = (string)($pair['label'] ?? '');
        $value = (string)($pair['value'] ?? '');

        if ($label === '') {
            continue;
        }

        $out .= '<div class="af-apui-info-item">';
        $out .= '<div class="af-apui-info-label">' . htmlspecialchars_uni($label) . '</div>';
        $out .= '<div class="af-apui-info-value">' . ($value !== '' ? $value : '&mdash;') . '</div>';
        $out .= '</div>';
    }

    return $out;
}

function af_apui_is_application_surface_ajax_request(): bool
{
    global $mybb;

    if (!defined('THIS_SCRIPT') || strtolower((string)THIS_SCRIPT) !== 'showthread.php') {
        return false;
    }

    $surface = strtolower(trim((string)$mybb->get_input('af_apui_surface')));
    if ($surface !== 'application') {
        return false;
    }

    $ajaxRaw = strtolower(trim((string)$mybb->get_input('ajax')));
    return $mybb->get_input('ajax', MyBB::INPUT_INT) === 1
        || $ajaxRaw === '1'
        || $ajaxRaw === 'true';
}

function af_apui_build_application_surface_fragment_html(): string
{
    global $mybb, $thread;

    $tid = (int)($thread['tid'] ?? $mybb->get_input('tid', MyBB::INPUT_INT));
    if ($tid <= 0 && function_exists('get_thread')) {
        $tid = (int)$mybb->get_input('tid', MyBB::INPUT_INT);
        if ($tid > 0) {
            $thread = get_thread($tid);
        }
    }

    $threadRow = is_array($thread ?? null) ? (array)$thread : [];
    $tid = (int)($threadRow['tid'] ?? $tid);
    $fid = (int)($threadRow['fid'] ?? 0);
    $uid = (int)$mybb->get_input('af_apui_owner_uid', MyBB::INPUT_INT);

    if ($uid <= 0) {
        $uid = (int)($threadRow['uid'] ?? 0);
    }

    if ($tid <= 0 || $fid <= 0 || !function_exists('af_atf_build_display_block_for_tid_fid')) {
        return '<div class="af-apui-application-fragment af-aa-context af-aa-context--application"'
            . ' data-af-apui-surface="application"'
            . ($uid > 0 ? ' data-af-apui-owner-uid="' . $uid . '" data-uid="' . $uid . '"' : '')
            . '><div class="af-apui-empty">Содержимое анкеты недоступно.</div></div>';
    }

    $content = (string)af_atf_build_display_block_for_tid_fid($tid, $fid);
    if (trim($content) === '') {
        $content = '<div class="af-apui-empty">Содержимое анкеты недоступно.</div>';
    }

    $classes = 'af-apui-application-fragment af-aa-context af-aa-context--application';
    if ($uid > 0) {
        $classes .= ' af-aa-surface-user-' . $uid;
    }

    return '<div class="' . htmlspecialchars_uni($classes) . '"'
        . ' data-af-apui-surface="application"'
        . ($uid > 0 ? ' data-af-apui-owner-uid="' . $uid . '" data-uid="' . $uid . '"' : '')
        . ' data-af-apui-application-fragment="1"'
        . '>'
        . $content
        . '</div>';
}

function af_apui_pre_output_page(string &$page): void
{
    if (defined('IN_ADMINCP') || !af_apui_is_enabled() || $page === '') {
        return;
    }

    if (af_apui_is_application_surface_ajax_request()) {
        $page = af_apui_build_application_surface_fragment_html();
        return;
    }

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $action = strtolower((string)($GLOBALS['mybb']->input['action'] ?? ''));

    $needAssets = false;

    if ($script === 'member.php' && $action === 'profile') {
        $needAssets = true;
    }

    if (!$needAssets) {
        $needles = [
            AF_APUI_MARKER_POSTBIT_START,
            AF_APUI_MARKER_PROFILE_START,
            AF_APUI_MARKER_THREAD_START,
            'af-apui-profile-page',
            'af-apui-postbit',
            'af-apui-tab',

            'af-apui-surface-body',
            'af-apui-surface-page',
            'data-af-apui-surface="sheet"',
            "data-af-apui-surface='sheet'",
            'data-af-apui-surface="application"',
            "data-af-apui-surface='application'",
            'data-af-apui-surface="inventory"',
            "data-af-apui-surface='inventory'",
            'data-af-apui-surface="achievements"',
            "data-af-apui-surface='achievements'",

            'af-cs-page',
            'af-inv-page',
            'af-apui-application-fragment',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($page, $needle) !== false) {
                $needAssets = true;
                break;
            }
        }
    }

    $page = af_apui_strip_asset_includes($page);

    $page = af_apui_relocate_thread_breadcrumbs($page);

    if (!$needAssets) {
        return;
    }

    $bburl = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_APUI_ID . '/assets';
    $jsUrl = af_apui_add_ver($base . '/advancedprofileui.js', AF_APUI_ASSETS_DIR . 'advancedprofileui.js');

    $cssMode = af_apui_get_css_delivery_mode();

    $injection = "\n" . AF_APUI_ASSET_MARK . "\n";
    if ($cssMode === 'file') {
        $cssUrl = af_apui_add_ver($base . '/advancedprofileui.css', AF_APUI_ASSETS_DIR . 'advancedprofileui.css');
        $injection .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
    } else {
        $injection .= af_apui_css_include_tag($base);
    }
    $injection .= af_apui_build_runtime_style_tag();
    $injection .= '<script src="' . htmlspecialchars_uni($jsUrl) . '" defer></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $injection . '</head>', $page, 1) ?? $page;
    } else {
        $page .= $injection;
    }
}

function af_apui_strip_asset_includes(string $page): string
{
    $patterns = [
        '~<!--\s*af_apui_assets\s*-->\s*~i',
        '~<link\b[^>]*href=(["\'])[^"\']*advancedprofileui\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '~<script\b[^>]*src=(["\'])[^"\']*advancedprofileui\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~is',
        '~<style\b[^>]*id=(["\'])af-apui-runtime-css\1[^>]*>.*?</style>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        $page = preg_replace($pattern, '', $page) ?? $page;
    }

    return $page;
}


function af_apui_is_thread_page_markup(string $page): bool
{
    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';

    if ($script === 'showthread.php') {
        return true;
    }

    return strpos($page, AF_APUI_MARKER_THREAD_START) !== false
        || strpos($page, 'af-apui-thread-page') !== false
        || strpos($page, 'AF_APUI_THREAD_BREADCRUMBS_TOP') !== false
        || strpos($page, 'AF_APUI_THREAD_BREADCRUMBS_BOTTOM') !== false;
}

function af_apui_normalize_thread_breadcrumbs_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Убираем template-комменты MyBB
    $html = preg_replace('~<!--.*?-->~s', '', $html) ?? $html;

    // Убираем \r\n шум
    $html = str_replace(["\r"], '', $html);
    $html = preg_replace("~\n+~", '', $html) ?? $html;

    // Старый активный разделитель вида <br><img ... nav_bit.png>
    $html = preg_replace(
        '~<br\s*/?>\s*<img\b[^>]*>~i',
        '<span class="af-apui-breadcrumb-sep af-apui-breadcrumb-sep--active" aria-hidden="true">›</span>',
        $html
    ) ?? $html;

    // Обычный текстовый разделитель "›" между элементами
    $html = preg_replace(
        '~(?<=>)\s*›\s*(?=<)~u',
        '<span class="af-apui-breadcrumb-sep" aria-hidden="true">›</span>',
        $html
    ) ?? $html;

    // На всякий случай выпиливаем оставшиеся br/img
    $html = preg_replace('~<br\s*/?>~i', '', $html) ?? $html;
    $html = preg_replace('~<img\b[^>]*>~i', '', $html) ?? $html;

    // Чистим лишние пробелы между тегами
    $html = preg_replace('~>\s+<~', '><', $html) ?? $html;

    return trim($html);
}

function af_apui_relocate_thread_breadcrumbs(string $page): string
{
    if ($page === '' || !af_apui_is_thread_page_markup($page)) {
        return $page;
    }

    $hasTopSlot = strpos($page, '<!--AF_APUI_THREAD_BREADCRUMBS_TOP-->') !== false;
    $hasBottomSlot = strpos($page, '<!--AF_APUI_THREAD_BREADCRUMBS_BOTTOM-->') !== false;

    if (!$hasTopSlot && !$hasBottomSlot) {
        return $page;
    }

    $punPos = strpos($page, '<div class="pun af-apui-thread-pun">');
    if ($punPos === false) {
        $punPos = strpos($page, 'af-apui-thread-pun');
    }

    if ($punPos === false) {
        return $page;
    }

    // Ищем хлебные крошки только в верхней части страницы, до APUI-контейнера темы.
    $beforePun = substr($page, 0, (int)$punPos);

    if (
        !preg_match_all(
            '~<div\b[^>]*class=(["\'])[^"\']*\bnavigation\b[^"\']*\1[^>]*>.*?</div>~is',
            $beforePun,
            $matches,
            PREG_OFFSET_CAPTURE
        )
    ) {
        // Если не нашли — просто уберём пустые контейнеры, чтобы не было пустых полос
        $page = str_replace(
            ['<!--AF_APUI_THREAD_BREADCRUMBS_TOP-->', '<!--AF_APUI_THREAD_BREADCRUMBS_BOTTOM-->'],
            '',
            $page
        );

        $page = preg_replace(
            '~<nav\b[^>]*class=(["\'])[^"\']*\baf-apui-thread-breadcrumbs\b[^"\']*\1[^>]*>\s*<div\b[^>]*class=(["\'])[^"\']*\baf-apui-thread-breadcrumbs__inner\b[^"\']*\2[^>]*>\s*</div>\s*</nav>~is',
            '',
            $page
        ) ?? $page;

        return $page;
    }

    // Берём последнюю navigation до .pun — это и есть форумные хлебные крошки.
    $lastMatch = end($matches[0]);
    if (!is_array($lastMatch) || !isset($lastMatch[0], $lastMatch[1])) {
        return $page;
    }

    $rawNavigationHtml = (string)$lastMatch[0];
    $navigationOffset = (int)$lastMatch[1];

    $normalizedNavigationHtml = af_apui_normalize_thread_breadcrumbs_html($rawNavigationHtml);

    // Удаляем верхние штатные хлебные крошки из header-части страницы.
    $page = substr_replace($page, '', $navigationOffset, strlen($rawNavigationHtml));

    // Вставляем одинаковые хлебные крошки и сверху, и снизу.
    $page = str_replace('<!--AF_APUI_THREAD_BREADCRUMBS_TOP-->', $normalizedNavigationHtml, $page);
    $page = str_replace('<!--AF_APUI_THREAD_BREADCRUMBS_BOTTOM-->', $normalizedNavigationHtml, $page);

    // Если вдруг вставить было нечего — выпиливаем пустые контейнеры.
    $page = preg_replace(
        '~<nav\b[^>]*class=(["\'])[^"\']*\baf-apui-thread-breadcrumbs\b[^"\']*\1[^>]*>\s*<div\b[^>]*class=(["\'])[^"\']*\baf-apui-thread-breadcrumbs__inner\b[^"\']*\2[^>]*>\s*</div>\s*</nav>~is',
        '',
        $page
    ) ?? $page;

    return $page;
}

function af_apui_add_ver(string $url, string $absFile): string
{
    $ver = is_file($absFile) ? (int)@filemtime($absFile) : 0;
    if ($ver <= 0) {
        return $url;
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $ver;
}
function af_apui_get_setting_value(string $suffix, string $default = ''): string
{
    global $mybb;

    $key = 'af_' . AF_APUI_ID . '_' . $suffix;
    if (!isset($mybb->settings[$key])) {
        return $default;
    }

    return trim((string)$mybb->settings[$key]);
}

function af_apui_css_url_value(string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return 'none';
    }

    $url = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $url);

    return 'url("' . $url . '")';
}

function af_apui_css_raw_value(string $value, string $default = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(["\r", "\n", ';'], [' ', ' ', ''], $value);
    $value = str_replace(['</style', '<style'], ['<\/style', ''], $value);

    return trim($value);
}

function af_apui_sanitize_custom_css(string $css): string
{
    $css = trim($css);
    if ($css === '') {
        return '';
    }

    $css = str_replace(['</style', '<style'], ['<\/style', ''], $css);

    return $css;
}

function af_apui_build_runtime_style_tag(): string
{
    $profileBanner = af_apui_css_url_value(af_apui_get_setting_value('profile_banner_url', ''));
    $profileBannerOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value(
            'profile_banner_overlay',
            'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%)'
        )
    );

    $bodyMode = strtolower(af_apui_get_setting_value('member_profile_body_bg_mode', 'cover'));
    if ($bodyMode !== 'tile') {
        $bodyMode = 'cover';
    }

    $bodyCoverImage = af_apui_css_url_value(af_apui_get_setting_value('member_profile_body_cover_url', ''));
    $bodyTileImage = af_apui_css_url_value(af_apui_get_setting_value('member_profile_body_tile_url', ''));
    $bodyOverlay = af_apui_css_raw_value(af_apui_get_setting_value('member_profile_body_overlay', 'none'));

    $selectedBodyImage = 'none';
    if ($bodyMode === 'tile') {
        if ($bodyTileImage !== 'none') {
            $selectedBodyImage = $bodyTileImage;
        } elseif ($bodyCoverImage !== 'none') {
            $selectedBodyImage = $bodyCoverImage;
            $bodyMode = 'cover';
        }
    } else {
        if ($bodyCoverImage !== 'none') {
            $selectedBodyImage = $bodyCoverImage;
        } elseif ($bodyTileImage !== 'none') {
            $selectedBodyImage = $bodyTileImage;
            $bodyMode = 'tile';
        }
    }

    $hasMemberProfileBodyImage = ($selectedBodyImage !== 'none');
    $bodyRepeat = $bodyMode === 'tile' ? 'repeat' : 'no-repeat';
    $bodyPosition = $bodyMode === 'tile' ? 'left top' : 'center center';
    $bodyAttachment = $bodyMode === 'tile' ? 'scroll' : 'fixed';
    $bodySize = $bodyMode === 'tile' ? 'auto' : 'cover';

    $postbitAuthorBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_author_bg_url', '')
    );
    $postbitAuthorOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_author_overlay', 'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(24, 24, 24, 0.81) 42%, rgb(0, 0, 0) 100%)')
    );

    $postbitNameBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_name_bg_url', '')
    );
    $postbitNameOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_name_overlay', 'linear-gradient(180deg, rgba(10, 14, 24, .18), rgba(10, 14, 24, .28))')
    );

    $postbitPlaqueBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_plaque_bg_url', '')
    );
    $postbitPlaqueOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_overlay', 'linear-gradient(180deg, rgba(10, 14, 24, .10), rgba(10, 14, 24, .18))')
    );
    $postbitPlaqueMediaOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_media_overlay', 'none')
    );
    $postbitPlaqueIconBg = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_icon_bg', 'linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.08))')
    );
    $postbitPlaqueIconOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_icon_overlay', 'none')
    );
    $postbitPlaqueIconBorder = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_icon_border', 'rgba(255,255,255,.18)')
    );
    $postbitPlaqueIconColor = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_icon_color', '#f6f1cf')
    );
    $postbitPlaqueIconSize = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_icon_size', '26px')
    );

    $sheetBg = af_apui_css_url_value(af_apui_get_setting_value('sheet_bg_url', ''));
    $sheetOverlay = af_apui_css_raw_value(af_apui_get_setting_value('sheet_bg_overlay', 'linear-gradient(180deg, rgba(6, 10, 18, .24) 0%, rgba(6, 10, 18, .78) 100%)'));
    $sheetPanelBg = af_apui_css_raw_value(af_apui_get_setting_value('sheet_panel_bg', 'rgba(0, 0, 0, 0.12)'));
    $sheetPanelBorder = af_apui_css_raw_value(af_apui_get_setting_value('sheet_panel_border', 'rgba(255,255,255,.12)'));

    $applicationBg = af_apui_css_url_value(af_apui_get_setting_value('application_bg_url', ''));
    $applicationOverlay = af_apui_css_raw_value(af_apui_get_setting_value('application_bg_overlay', 'linear-gradient(180deg, rgba(6, 10, 18, .20) 0%, rgba(6, 10, 18, .58) 55%, rgba(6, 10, 18, .88) 100%)'));
    $applicationPanelBg = af_apui_css_raw_value(af_apui_get_setting_value('application_panel_bg', 'rgba(6, 12, 26, .58)'));
    $applicationPanelBorder = af_apui_css_raw_value(af_apui_get_setting_value('application_panel_border', 'rgba(255,255,255,.10)'));

    $inventoryBg = af_apui_css_url_value(af_apui_get_setting_value('inventory_bg_url', ''));
    $inventoryOverlay = af_apui_css_raw_value(af_apui_get_setting_value('inventory_bg_overlay', 'linear-gradient(180deg, rgba(6, 10, 18, .26) 0%, rgba(6, 10, 18, .72) 100%)'));
    $inventoryPanelBg = af_apui_css_raw_value(af_apui_get_setting_value('inventory_panel_bg', 'rgba(21, 25, 34, .92)'));
    $inventoryPanelBorder = af_apui_css_raw_value(af_apui_get_setting_value('inventory_panel_border', 'rgba(255,255,255,.12)'));

    $achievementsBg = af_apui_css_url_value(af_apui_get_setting_value('achievements_bg_url', ''));
    $achievementsOverlay = af_apui_css_raw_value(af_apui_get_setting_value('achievements_bg_overlay', 'linear-gradient(180deg, rgba(6, 10, 18, .24) 0%, rgba(6, 10, 18, .78) 100%)'));
    $achievementsPanelBg = af_apui_css_raw_value(af_apui_get_setting_value('achievements_panel_bg', 'rgba(13, 17, 28, .74)'));
    $achievementsPanelBorder = af_apui_css_raw_value(af_apui_get_setting_value('achievements_panel_border', 'rgba(255,255,255,.12)'));

    $threadBodyMode = strtolower(af_apui_get_setting_value('thread_body_bg_mode', 'cover'));
    if ($threadBodyMode !== 'tile') {
        $threadBodyMode = 'cover';
    }

    $threadBodyCoverImage = af_apui_css_url_value(af_apui_get_setting_value('thread_body_cover_url', ''));
    $threadBodyTileImage = af_apui_css_url_value(af_apui_get_setting_value('thread_body_tile_url', ''));
    $threadBodyOverlay = af_apui_css_raw_value(af_apui_get_setting_value('thread_body_overlay', 'none'));
    $threadBanner = af_apui_css_url_value(af_apui_get_setting_value('thread_banner_url', ''));
    $threadBannerOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value(
            'thread_banner_overlay',
            'linear-gradient(180deg, rgba(8, 12, 24, 0.08) 0%, rgba(8, 12, 24, 0.36) 42%, rgba(8, 12, 24, 0.88) 100%)'
        )
    );

    $selectedThreadBodyImage = 'none';
    if ($threadBodyMode === 'tile') {
        if ($threadBodyTileImage !== 'none') {
            $selectedThreadBodyImage = $threadBodyTileImage;
        } elseif ($threadBodyCoverImage !== 'none') {
            $selectedThreadBodyImage = $threadBodyCoverImage;
            $threadBodyMode = 'cover';
        }
    } else {
        if ($threadBodyCoverImage !== 'none') {
            $selectedThreadBodyImage = $threadBodyCoverImage;
        } elseif ($threadBodyTileImage !== 'none') {
            $selectedThreadBodyImage = $threadBodyTileImage;
            $threadBodyMode = 'tile';
        }
    }

    $hasThreadBodyImage = ($selectedThreadBodyImage !== 'none');
    $threadBodyRepeat = $threadBodyMode === 'tile' ? 'repeat' : 'no-repeat';
    $threadBodyPosition = $threadBodyMode === 'tile' ? 'left top' : 'center center';
    $threadBodyAttachment = $threadBodyMode === 'tile' ? 'scroll' : 'fixed';
    $threadBodySize = $threadBodyMode === 'tile' ? 'auto' : 'cover';

    $memberCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('member_profile_css', ''));
    $postbitCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('postbit_css', ''));
    $sheetCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('sheet_css', ''));
    $applicationCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('application_css', ''));
    $inventoryCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('inventory_css', ''));
    $achievementsCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('achievements_css', ''));
    $threadCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('thread_css', ''));

    $css = ":root{";
    if ($profileBanner !== 'none') {
        $css .= "--af-apui-profile-banner-image:" . $profileBanner . ";";
    }
    $css .= "--af-apui-profile-banner-overlay:" . $profileBannerOverlay . ";";

    if ($postbitAuthorBg !== 'none') {
        $css .= "--af-apui-postbit-author-bg-image:" . $postbitAuthorBg . ";";
    }
    $css .= "--af-apui-postbit-author-overlay:" . $postbitAuthorOverlay . ";";

    if ($postbitNameBg !== 'none') {
        $css .= "--af-apui-postbit-name-bg-image:" . $postbitNameBg . ";";
    }
    $css .= "--af-apui-postbit-name-overlay:" . $postbitNameOverlay . ";";

    if ($postbitPlaqueBg !== 'none') {
        $css .= "--af-apui-postbit-plaque-bg-image:" . $postbitPlaqueBg . ";";
    }
    $css .= "--af-apui-postbit-plaque-overlay:" . $postbitPlaqueOverlay . ";";
    $css .= "--af-apui-postbit-plaque-media-overlay:" . $postbitPlaqueMediaOverlay . ";";
    $css .= "--af-apui-postbit-plaque-icon-bg:" . $postbitPlaqueIconBg . ";";
    $css .= "--af-apui-postbit-plaque-icon-overlay:" . $postbitPlaqueIconOverlay . ";";
    $css .= "--af-apui-postbit-plaque-icon-border:" . $postbitPlaqueIconBorder . ";";
    $css .= "--af-apui-postbit-plaque-icon-color:" . $postbitPlaqueIconColor . ";";
    $css .= "--af-apui-postbit-plaque-icon-size:" . $postbitPlaqueIconSize . ";";

    if ($sheetBg !== 'none') {
        $css .= "--af-apui-modal-sheet-bg-image:" . $sheetBg . ";";
    }
    $css .= "--af-apui-modal-sheet-bg-overlay:" . $sheetOverlay . ";";
    $css .= "--af-apui-modal-sheet-panel-bg:" . $sheetPanelBg . ";";
    $css .= "--af-apui-modal-sheet-panel-border:" . $sheetPanelBorder . ";";

    if ($applicationBg !== 'none') {
        $css .= "--af-apui-modal-application-bg-image:" . $applicationBg . ";";
    }
    $css .= "--af-apui-modal-application-bg-overlay:" . $applicationOverlay . ";";
    $css .= "--af-apui-modal-application-panel-bg:" . $applicationPanelBg . ";";
    $css .= "--af-apui-modal-application-panel-border:" . $applicationPanelBorder . ";";

    if ($inventoryBg !== 'none') {
        $css .= "--af-apui-modal-inventory-bg-image:" . $inventoryBg . ";";
    }
    $css .= "--af-apui-modal-inventory-bg-overlay:" . $inventoryOverlay . ";";
    $css .= "--af-apui-modal-inventory-panel-bg:" . $inventoryPanelBg . ";";
    $css .= "--af-apui-modal-inventory-panel-border:" . $inventoryPanelBorder . ";";

    if ($achievementsBg !== 'none') {
        $css .= "--af-apui-modal-achievements-bg-image:" . $achievementsBg . ";";
    }
    $css .= "--af-apui-modal-achievements-bg-overlay:" . $achievementsOverlay . ";";
    $css .= "--af-apui-modal-achievements-panel-bg:" . $achievementsPanelBg . ";";
    $css .= "--af-apui-modal-achievements-panel-border:" . $achievementsPanelBorder . ";";

    if ($threadBanner !== 'none') {
        $css .= "--af-apui-thread-banner-image:" . $threadBanner . ";";
    }
    $css .= "--af-apui-thread-banner-overlay:" . $threadBannerOverlay . ";";
    $css .= "}\n";

    $memberBodyDeclarations = [];
    if ($bodyOverlay !== 'none') {
        $memberBodyDeclarations[] = "--af-apui-member-profile-body-overlay:" . $bodyOverlay . ";";
    }
    if ($hasMemberProfileBodyImage) {
        $memberBodyDeclarations[] = "background-image:" . $selectedBodyImage . ";";
        $memberBodyDeclarations[] = "background-repeat:" . $bodyRepeat . ";";
        $memberBodyDeclarations[] = "background-position:" . $bodyPosition . ";";
        $memberBodyDeclarations[] = "background-attachment:" . $bodyAttachment . ";";
        $memberBodyDeclarations[] = "background-size:" . $bodySize . ";";
    }
    if (!empty($memberBodyDeclarations)) {
        $css .= "body.af-apui-member-profile-page{" . implode('', $memberBodyDeclarations) . "}\n";
    }

    $threadBodyDeclarations = [];
    if ($threadBodyOverlay !== 'none') {
        $threadBodyDeclarations[] = "--af-apui-thread-body-overlay:" . $threadBodyOverlay . ";";
    }
    if ($hasThreadBodyImage) {
        $threadBodyDeclarations[] = "background-image:" . $selectedThreadBodyImage . ";";
        $threadBodyDeclarations[] = "background-repeat:" . $threadBodyRepeat . ";";
        $threadBodyDeclarations[] = "background-position:" . $threadBodyPosition . ";";
        $threadBodyDeclarations[] = "background-attachment:" . $threadBodyAttachment . ";";
        $threadBodyDeclarations[] = "background-size:" . $threadBodySize . ";";
    }
    if (!empty($threadBodyDeclarations)) {
        $css .= "body.af-apui-thread-page{" . implode('', $threadBodyDeclarations) . "}\n";
    }

    if ($profileBanner !== 'none') {
        $css .= ".af-apui-profile-hero__banner{background-image:var(--af-apui-profile-banner-image);}\n";
    }
    $css .= ".af-apui-profile-hero__banner::after{background:var(--af-apui-profile-banner-overlay, linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%));}\n";

    if ($threadBanner !== 'none') {
        $css .= ".af-apui-thread-hero__banner{background-image:var(--af-apui-thread-banner-image);}\n";
    }
    $css .= ".af-apui-thread-hero__banner::after{background:var(--af-apui-thread-banner-overlay, linear-gradient(180deg, rgba(8, 12, 24, 0.08) 0%, rgba(8, 12, 24, 0.36) 42%, rgba(8, 12, 24, 0.88) 100%));}\n";

    if ($memberCss !== '') {
        $css .= "\n/* member_profile custom css */\n" . $memberCss . "\n";
    }

    if ($postbitCss !== '') {
        $css .= "\n/* postbit_classic custom css */\n" . $postbitCss . "\n";
    }

    if ($sheetCss !== '') {
        $css .= "\n/* character sheet custom css */\n" . $sheetCss . "\n";
    }

    if ($applicationCss !== '') {
        $css .= "\n/* application custom css */\n" . $applicationCss . "\n";
    }

    if ($inventoryCss !== '') {
        $css .= "\n/* inventory custom css */\n" . $inventoryCss . "\n";
    }

    if ($achievementsCss !== '') {
        $css .= "\n/* achievements custom css */\n" . $achievementsCss . "\n";
    }

    if ($threadCss !== '') {
        $css .= "\n/* showthread custom css */\n" . $threadCss . "\n";
    }

    return '<style id="af-apui-runtime-css">' . $css . '</style>' . "\n";
}

function af_apui_apply_overrides(): void
{
    global $db;

    af_apui_ensure_schema();

    $customTemplates = af_apui_load_custom_templates();
    $targetTitles = ['member_profile', 'postbit_classic', 'showthread'];

    foreach ($targetTitles as $title) {
        if (!isset($customTemplates[$title])) {
            continue;
        }

        $titleEsc = $db->escape_string($title);
        $query = $db->simple_select('templates', 'tid,title,sid,template,dateline', "title='" . $titleEsc . "' AND sid != '-2'");

        while ($row = $db->fetch_array($query)) {
            $tid = (int)$row['tid'];
            $currentTemplate = (string)$row['template'];

            af_apui_backup_template_row($row);

            if ($currentTemplate === $customTemplates[$title]) {
                continue;
            }

            $db->update_query('templates', [
                'template' => $db->escape_string($customTemplates[$title]),
                'dateline' => TIME_NOW,
            ], "tid='" . $tid . "'");
        }
    }
}

function af_apui_restore_overrides(): void
{
    global $db;

    if (!$db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        return;
    }

    $q = $db->simple_select(AF_APUI_BACKUP_TABLE_NAME, '*');
    while ($backup = $db->fetch_array($q)) {
        $tid = (int)$backup['template_tid'];
        $title = (string)$backup['title'];
        $sid = (int)$backup['sid'];
        $template = (string)$backup['original_template'];
        $dateline = (int)$backup['original_dateline'];

        $existing = $db->simple_select('templates', 'tid', "tid='" . $tid . "'", ['limit' => 1]);
        $existingTid = (int)$db->fetch_field($existing, 'tid');

        $payload = [
            'title' => $db->escape_string($title),
            'sid' => $sid,
            'template' => $db->escape_string($template),
            'version' => '1800',
            'dateline' => $dateline > 0 ? $dateline : TIME_NOW,
        ];

        if ($existingTid > 0) {
            $db->update_query('templates', $payload, "tid='" . $existingTid . "'");
        } else {
            $db->insert_query('templates', $payload);
        }

        $db->update_query(AF_APUI_BACKUP_TABLE_NAME, [
            'updated_at' => TIME_NOW,
        ], "id='" . (int)$backup['id'] . "'");
    }
}

function af_apui_backup_template_row(array $row): void
{
    global $db;

    $tid = (int)($row['tid'] ?? 0);
    if ($tid <= 0) {
        return;
    }

    $exists = $db->simple_select(AF_APUI_BACKUP_TABLE_NAME, 'id', "template_tid='" . $tid . "'", ['limit' => 1]);
    $backupId = (int)$db->fetch_field($exists, 'id');
    if ($backupId > 0) {
        return;
    }

    $template = (string)($row['template'] ?? '');

    $db->insert_query(AF_APUI_BACKUP_TABLE_NAME, [
        'template_tid' => $tid,
        'title' => $db->escape_string((string)($row['title'] ?? '')),
        'sid' => (int)($row['sid'] ?? -1),
        'original_template' => $db->escape_string($template),
        'original_dateline' => (int)($row['dateline'] ?? 0),
        'marker' => $db->escape_string('af_apui'),
        'checksum' => $db->escape_string(sha1($template)),
        'created_at' => TIME_NOW,
        'updated_at' => TIME_NOW,
    ]);
}

function af_apui_load_custom_templates(): array
{
    $file = AF_APUI_TEMPLATES_DIR . 'member_profile.html';
    $rawProfile = is_file($file) ? (string)file_get_contents($file) : '';

    $filePostbit = AF_APUI_TEMPLATES_DIR . 'postbit_classic.html';
    $rawPostbit = is_file($filePostbit) ? (string)file_get_contents($filePostbit) : '';
    $fileShowthread = AF_APUI_TEMPLATES_DIR . 'showthread.html';
    $rawShowthread = is_file($fileShowthread) ? (string)file_get_contents($fileShowthread) : '';

    $templates = [];
    $templates = array_merge($templates, af_parse_templates_bundle($rawProfile));
    $templates = array_merge($templates, af_parse_templates_bundle($rawPostbit));
    $templates = array_merge($templates, af_parse_templates_bundle($rawShowthread));

    return $templates;
}
