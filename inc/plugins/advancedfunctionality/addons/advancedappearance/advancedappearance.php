<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_AA_ID', 'advancedappearance');
define('AF_AA_BASE', AF_ADDONS . AF_AA_ID . '/');
define('AF_AA_ASSETS_DIR', AF_AA_BASE . 'assets/');
define('AF_AA_PRESETS_TABLE_NAME', 'af_aa_presets');
define('AF_AA_ASSIGNMENTS_TABLE_NAME', 'af_aa_assignments');
define('AF_AA_PRESETS_TABLE', TABLE_PREFIX . AF_AA_PRESETS_TABLE_NAME);
define('AF_AA_ASSIGNMENTS_TABLE', TABLE_PREFIX . AF_AA_ASSIGNMENTS_TABLE_NAME);
define('AF_AA_ASSET_MARK', '<!--af_aa_assets-->');
define('AF_AA_TARGET_APUI_THEME_PACK', 'apui_theme_pack');

function af_advancedappearance_install(): void
{
    af_aa_ensure_schema();
    af_aa_ensure_settings();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedappearance_activate(): void
{
    af_aa_ensure_schema();
    af_aa_ensure_settings();
}

function af_advancedappearance_deactivate(): void
{
    // v1: данные не удаляем, только перестаем применять стили.
}

function af_advancedappearance_uninstall(): void
{
    global $db;

    if ($db->table_exists(AF_AA_PRESETS_TABLE_NAME)) {
        $db->drop_table(AF_AA_PRESETS_TABLE_NAME);
    }

    if ($db->table_exists(AF_AA_ASSIGNMENTS_TABLE_NAME)) {
        $db->drop_table(AF_AA_ASSIGNMENTS_TABLE_NAME);
    }

    $db->delete_query('settings', "name LIKE 'af_" . AF_AA_ID . "_%'");
    $db->delete_query('settinggroups', "name='af_" . AF_AA_ID . "'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_aa_is_enabled(): bool
{
    global $mybb;

    return !empty($mybb->settings['af_' . AF_AA_ID . '_enabled']);
}

function af_aa_ensure_settings(): void
{
    if (!function_exists('af_ensure_settinggroup') || !function_exists('af_ensure_setting')) {
        return;
    }

    af_ensure_settinggroup(
        'af_' . AF_AA_ID,
        'AdvancedAppearance',
        'Каталог визуальных пресетов для APUI и их назначения пользователям.'
    );

    af_ensure_setting(
        'af_' . AF_AA_ID,
        'af_' . AF_AA_ID . '_enabled',
        'Включить AdvancedAppearance',
        'Включает применение пресетов к APUI через runtime CSS.',
        'yesno',
        '1',
        1
    );
}

function af_aa_ensure_schema(): void
{
    global $db;

    $charset = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_AA_PRESETS_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            preview_image VARCHAR(512) NOT NULL DEFAULT '',
            target_key VARCHAR(100) NOT NULL,
            settings_json MEDIUMTEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sortorder INT NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug_target (slug, target_key),
            KEY idx_target_enabled_sort (target_key, enabled, sortorder)
        ) " . $charset
    );

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_AA_ASSIGNMENTS_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            target_key VARCHAR(100) NOT NULL,
            preset_id INT UNSIGNED NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_target (entity_type, entity_id, target_key),
            KEY idx_target_entity (target_key, entity_type, entity_id),
            KEY idx_preset (preset_id)
        ) " . $charset
    );
}

function af_aa_register_hooks(): void
{
    global $plugins;

    $plugins->add_hook('postbit', 'af_aa_collect_uid_from_postbit', 20);
    $plugins->add_hook('postbit_prev', 'af_aa_collect_uid_from_postbit', 20);
    $plugins->add_hook('postbit_pm', 'af_aa_collect_uid_from_postbit', 20);
    $plugins->add_hook('member_profile_end', 'af_aa_collect_uid_from_member_profile', 20);
    $plugins->add_hook('pre_output_page', 'af_aa_pre_output_page', 20);
}
af_aa_register_hooks();

function af_aa_collect_uid_from_postbit(array &$post): void
{
    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if (!isset($GLOBALS['af_aa_uids_on_page']) || !is_array($GLOBALS['af_aa_uids_on_page'])) {
        $GLOBALS['af_aa_uids_on_page'] = [];
    }

    $GLOBALS['af_aa_uids_on_page'][$uid] = $uid;
}

function af_aa_collect_uid_from_member_profile(): void
{
    global $memprofile;

    $uid = (int)($memprofile['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if (!isset($GLOBALS['af_aa_uids_on_page']) || !is_array($GLOBALS['af_aa_uids_on_page'])) {
        $GLOBALS['af_aa_uids_on_page'] = [];
    }

    $GLOBALS['af_aa_uids_on_page'][$uid] = $uid;
}

function af_aa_pre_output_page(string &$page): void
{
    if (defined('IN_ADMINCP') || $page === '') {
        return;
    }

    $page = af_aa_strip_asset_includes($page);

    if (!af_aa_is_enabled()) {
        return;
    }

    $uids = [];
    if (isset($GLOBALS['af_aa_uids_on_page']) && is_array($GLOBALS['af_aa_uids_on_page'])) {
        $uids = array_values(array_unique(array_map('intval', $GLOBALS['af_aa_uids_on_page'])));
    }

    if (empty($uids)) {
        return;
    }

    $cssBlock = af_aa_render_page_css($uids);
    if ($cssBlock === '') {
        return;
    }

    $bburl = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AA_ID . '/assets';

    $cssUrl = af_aa_add_ver($base . '/advancedappearance.css', AF_AA_ASSETS_DIR . 'advancedappearance.css');
    $jsUrl = af_aa_add_ver($base . '/advancedappearance.js', AF_AA_ASSETS_DIR . 'advancedappearance.js');

    $injection = "\n" . AF_AA_ASSET_MARK . "\n";
    $injection .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
    $injection .= $cssBlock;
    $injection .= '<script src="' . htmlspecialchars_uni($jsUrl) . '" defer></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $injection . '</head>', $page, 1) ?? $page;
        return;
    }

    $page .= $injection;
}

function af_aa_strip_asset_includes(string $page): string
{
    $patterns = [
        '~<!--\s*af_aa_assets\s*-->\s*~i',
        '~<link\b[^>]*href=(["\'])[^"\']*advancedappearance\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '~<script\b[^>]*src=(["\'])[^"\']*advancedappearance\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~is',
        '~<style\b[^>]*id=(["\'])af-aa-runtime-css\1[^>]*>.*?</style>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        $page = preg_replace($pattern, '', $page) ?? $page;
    }

    return $page;
}

function af_aa_add_ver(string $url, string $absFile): string
{
    $ver = is_file($absFile) ? (int)@filemtime($absFile) : 0;
    if ($ver <= 0) {
        return $url;
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $ver;
}

function af_aa_get_active_assignment(string $entityType, int $entityId, string $targetKey): array
{
    global $db;

    $entityType = trim(strtolower($entityType));
    $targetKey = trim(strtolower($targetKey));
    $entityId = (int)$entityId;

    if ($entityId <= 0 || $entityType === '' || $targetKey === '') {
        return [];
    }

    if (!isset($GLOBALS['af_aa_assignment_cache_runtime']) || !is_array($GLOBALS['af_aa_assignment_cache_runtime'])) {
        $GLOBALS['af_aa_assignment_cache_runtime'] = [];
    }

    $cacheKey = $entityType . ':' . $entityId . ':' . $targetKey;
    if (array_key_exists($cacheKey, $GLOBALS['af_aa_assignment_cache_runtime'])) {
        return $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey];
    }

    $where = "entity_type='" . $db->escape_string($entityType) . "'"
        . " AND entity_id='" . $entityId . "'"
        . " AND target_key='" . $db->escape_string($targetKey) . "'"
        . " AND is_enabled='1'";

    $query = $db->simple_select(AF_AA_ASSIGNMENTS_TABLE_NAME, '*', $where, ['limit' => 1]);
    $row = $db->fetch_array($query);
    if (!is_array($row)) {
        $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = [];
        return [];
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['entity_id'] = (int)($row['entity_id'] ?? 0);
    $row['preset_id'] = (int)($row['preset_id'] ?? 0);
    $row['is_enabled'] = (int)($row['is_enabled'] ?? 0);

    $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = $row;
    return $row;
}

function af_aa_get_preset_by_id(int $presetId): array
{
    global $db;

    $presetId = (int)$presetId;
    if ($presetId <= 0) {
        return [];
    }

    if (!isset($GLOBALS['af_aa_preset_cache_runtime']) || !is_array($GLOBALS['af_aa_preset_cache_runtime'])) {
        $GLOBALS['af_aa_preset_cache_runtime'] = [];
    }

    if (array_key_exists($presetId, $GLOBALS['af_aa_preset_cache_runtime'])) {
        return $GLOBALS['af_aa_preset_cache_runtime'][$presetId];
    }

    $query = $db->simple_select(AF_AA_PRESETS_TABLE_NAME, '*', "id='" . $presetId . "' AND enabled='1'", ['limit' => 1]);
    $row = $db->fetch_array($query);
    if (!is_array($row)) {
        $GLOBALS['af_aa_preset_cache_runtime'][$presetId] = [];
        return [];
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['enabled'] = (int)($row['enabled'] ?? 0);
    $row['sortorder'] = (int)($row['sortorder'] ?? 0);

    $GLOBALS['af_aa_preset_cache_runtime'][$presetId] = $row;
    return $row;
}

function af_aa_get_apui_defaults(): array
{
    $mode = af_aa_sanitize_bg_mode(af_aa_get_apui_setting('member_profile_body_bg_mode', 'cover'), 'cover');

    return [
        'member_profile_body_cover_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('member_profile_body_cover_url', ''), ''),
        'member_profile_body_tile_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('member_profile_body_tile_url', ''), ''),
        'member_profile_body_bg_mode' => $mode,
        'member_profile_body_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('member_profile_body_overlay', 'none'), 'none'),
        'profile_banner_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('profile_banner_url', ''), ''),
        'profile_banner_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('profile_banner_overlay', 'none'), 'none'),
        'postbit_author_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_author_bg_url', ''), ''),
        'postbit_author_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_author_overlay', 'none'), 'none'),
        'postbit_name_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_name_bg_url', ''), ''),
        'postbit_name_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_name_overlay', 'none'), 'none'),
        'postbit_plaque_bg_url' => af_aa_sanitize_image_url(af_aa_get_apui_setting('postbit_plaque_bg_url', ''), ''),
        'postbit_plaque_overlay' => af_aa_sanitize_overlay(af_aa_get_apui_setting('postbit_plaque_overlay', 'none'), 'none'),
    ];
}

function af_aa_build_user_css_payload(int $uid): array
{
    $uid = (int)$uid;
    if ($uid <= 0) {
        return [];
    }

    if (!isset($GLOBALS['af_aa_payload_cache_runtime']) || !is_array($GLOBALS['af_aa_payload_cache_runtime'])) {
        $GLOBALS['af_aa_payload_cache_runtime'] = [];
    }

    if (array_key_exists($uid, $GLOBALS['af_aa_payload_cache_runtime'])) {
        return $GLOBALS['af_aa_payload_cache_runtime'][$uid];
    }

    $defaults = af_aa_get_apui_defaults();

    $assignment = af_aa_get_active_assignment('user', $uid, AF_AA_TARGET_APUI_THEME_PACK);
    if (empty($assignment)) {
        $GLOBALS['af_aa_payload_cache_runtime'][$uid] = [];
        return [];
    }

    $preset = af_aa_get_preset_by_id((int)$assignment['preset_id']);
    if (empty($preset) || ($preset['target_key'] ?? '') !== AF_AA_TARGET_APUI_THEME_PACK) {
        $GLOBALS['af_aa_payload_cache_runtime'][$uid] = [];
        return [];
    }

    $settings = af_aa_decode_and_sanitize_preset_settings((string)($preset['settings_json'] ?? ''), $defaults);

    $mode = $settings['member_profile_body_bg_mode'];
    $selectedBodyImage = $mode === 'tile'
        ? af_aa_css_url_value($settings['member_profile_body_tile_url'])
        : af_aa_css_url_value($settings['member_profile_body_cover_url']);

    if ($selectedBodyImage === 'none') {
        $selectedBodyImage = $mode === 'tile'
            ? af_aa_css_url_value($settings['member_profile_body_cover_url'])
            : af_aa_css_url_value($settings['member_profile_body_tile_url']);

        if ($selectedBodyImage !== 'none') {
            $mode = $mode === 'tile' ? 'cover' : 'tile';
        }
    }

    $payload = [
        'uid' => $uid,
        'selector' => '.af-aa-user-' . $uid,
        'body_selector' => 'body.af-apui-member-profile-page.af-aa-user-' . $uid,
        'vars' => [
            '--af-apui-profile-banner-image' => af_aa_css_url_value($settings['profile_banner_url']),
            '--af-apui-profile-banner-overlay' => af_aa_css_raw_value($settings['profile_banner_overlay'], 'none'),
            '--af-apui-postbit-author-bg-image' => af_aa_css_url_value($settings['postbit_author_bg_url']),
            '--af-apui-postbit-author-overlay' => af_aa_css_raw_value($settings['postbit_author_overlay'], 'none'),
            '--af-apui-postbit-name-bg-image' => af_aa_css_url_value($settings['postbit_name_bg_url']),
            '--af-apui-postbit-name-overlay' => af_aa_css_raw_value($settings['postbit_name_overlay'], 'none'),
            '--af-apui-postbit-plaque-bg-image' => af_aa_css_url_value($settings['postbit_plaque_bg_url']),
            '--af-apui-postbit-plaque-overlay' => af_aa_css_raw_value($settings['postbit_plaque_overlay'], 'none'),
        ],
        'body' => [
            'overlay' => af_aa_css_raw_value($settings['member_profile_body_overlay'], 'none'),
            'image' => $selectedBodyImage,
            'repeat' => $mode === 'tile' ? 'repeat' : 'no-repeat',
            'position' => $mode === 'tile' ? 'left top' : 'center center',
            'attachment' => $mode === 'tile' ? 'scroll' : 'fixed',
            'size' => $mode === 'tile' ? 'auto' : 'cover',
        ],
    ];

    $GLOBALS['af_aa_payload_cache_runtime'][$uid] = $payload;
    return $payload;
}

function af_aa_render_page_css(array $uidsOnPage): string
{
    $uids = [];
    foreach ($uidsOnPage as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) {
            $uids[$uid] = $uid;
        }
    }

    if (empty($uids)) {
        return '';
    }

    af_aa_prime_runtime_cache(array_values($uids));

    $css = '';

    foreach ($uids as $uid) {
        $payload = af_aa_build_user_css_payload($uid);
        if (empty($payload)) {
            continue;
        }

        $css .= $payload['selector'] . '{';
        foreach ($payload['vars'] as $varName => $varValue) {
            $css .= $varName . ':' . $varValue . ';';
        }
        $css .= "}
";

        $css .= $payload['body_selector'] . '{';
        $css .= 'background-image:' . $payload['body']['overlay'] . ',' . $payload['body']['image'] . ';';
        $css .= 'background-repeat:no-repeat,' . $payload['body']['repeat'] . ';';
        $css .= 'background-position:center center,' . $payload['body']['position'] . ';';
        $css .= 'background-attachment:scroll,' . $payload['body']['attachment'] . ';';
        $css .= 'background-size:cover,' . $payload['body']['size'] . ';';
        $css .= "}
";
    }

    if ($css === '') {
        return '';
    }

    return '<style id="af-aa-runtime-css">' . $css . '</style>' . "
";
}

function af_aa_prime_runtime_cache(array $uids): void
{
    global $db;

    $uids = array_values(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    }));

    if (empty($uids)) {
        return;
    }

    if (!isset($GLOBALS['af_aa_assignment_cache_runtime']) || !is_array($GLOBALS['af_aa_assignment_cache_runtime'])) {
        $GLOBALS['af_aa_assignment_cache_runtime'] = [];
    }
    if (!isset($GLOBALS['af_aa_preset_cache_runtime']) || !is_array($GLOBALS['af_aa_preset_cache_runtime'])) {
        $GLOBALS['af_aa_preset_cache_runtime'] = [];
    }

    $in = implode(',', $uids);

    $assignmentsByUid = [];
    $queryAssignments = $db->write_query(
        "SELECT * FROM " . AF_AA_ASSIGNMENTS_TABLE
        . " WHERE entity_type='user'"
        . " AND target_key='" . $db->escape_string(AF_AA_TARGET_APUI_THEME_PACK) . "'"
        . " AND is_enabled='1'"
        . " AND entity_id IN (" . $in . ")"
    );

    while ($row = $db->fetch_array($queryAssignments)) {
        $uid = (int)($row['entity_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }

        $row['id'] = (int)($row['id'] ?? 0);
        $row['entity_id'] = (int)($row['entity_id'] ?? 0);
        $row['preset_id'] = (int)($row['preset_id'] ?? 0);
        $row['is_enabled'] = (int)($row['is_enabled'] ?? 0);

        $assignmentsByUid[$uid] = $row;
        $cacheKey = 'user:' . $uid . ':' . AF_AA_TARGET_APUI_THEME_PACK;
        $GLOBALS['af_aa_assignment_cache_runtime'][$cacheKey] = $row;
    }

    if (empty($assignmentsByUid)) {
        return;
    }

    $presetIds = [];
    foreach ($assignmentsByUid as $row) {
        $pid = (int)($row['preset_id'] ?? 0);
        if ($pid > 0) {
            $presetIds[$pid] = $pid;
        }
    }

    if (empty($presetIds)) {
        return;
    }

    $presetIn = implode(',', array_values($presetIds));
    $queryPresets = $db->write_query(
        "SELECT * FROM " . AF_AA_PRESETS_TABLE
        . " WHERE id IN (" . $presetIn . ")"
        . " AND enabled='1'"
    );

    while ($row = $db->fetch_array($queryPresets)) {
        $pid = (int)($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $row['id'] = $pid;
        $row['enabled'] = (int)($row['enabled'] ?? 0);
        $row['sortorder'] = (int)($row['sortorder'] ?? 0);
        $GLOBALS['af_aa_preset_cache_runtime'][$pid] = $row;
    }
}

function af_aa_decode_and_sanitize_preset_settings(string $json, array $defaults): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $out = $defaults;

    $out['member_profile_body_cover_url'] = af_aa_sanitize_image_url(
        (string)($decoded['member_profile_body_cover_url'] ?? ''),
        $defaults['member_profile_body_cover_url']
    );
    $out['member_profile_body_tile_url'] = af_aa_sanitize_image_url(
        (string)($decoded['member_profile_body_tile_url'] ?? ''),
        $defaults['member_profile_body_tile_url']
    );
    $out['member_profile_body_bg_mode'] = af_aa_sanitize_bg_mode(
        (string)($decoded['member_profile_body_bg_mode'] ?? ''),
        $defaults['member_profile_body_bg_mode']
    );
    $out['member_profile_body_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['member_profile_body_overlay'] ?? ''),
        $defaults['member_profile_body_overlay']
    );

    $out['profile_banner_url'] = af_aa_sanitize_image_url(
        (string)($decoded['profile_banner_url'] ?? ''),
        $defaults['profile_banner_url']
    );
    $out['profile_banner_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['profile_banner_overlay'] ?? ''),
        $defaults['profile_banner_overlay']
    );

    $out['postbit_author_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_author_bg_url'] ?? ''),
        $defaults['postbit_author_bg_url']
    );
    $out['postbit_author_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_author_overlay'] ?? ''),
        $defaults['postbit_author_overlay']
    );

    $out['postbit_name_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_name_bg_url'] ?? ''),
        $defaults['postbit_name_bg_url']
    );
    $out['postbit_name_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_name_overlay'] ?? ''),
        $defaults['postbit_name_overlay']
    );

    $out['postbit_plaque_bg_url'] = af_aa_sanitize_image_url(
        (string)($decoded['postbit_plaque_bg_url'] ?? ''),
        $defaults['postbit_plaque_bg_url']
    );
    $out['postbit_plaque_overlay'] = af_aa_sanitize_overlay(
        (string)($decoded['postbit_plaque_overlay'] ?? ''),
        $defaults['postbit_plaque_overlay']
    );

    return $out;
}

function af_aa_sanitize_image_url(string $url, string $fallback = ''): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }

    if (preg_match('~[\r\n<>]~', $url)) {
        return $fallback;
    }

    if (stripos($url, 'javascript:') !== false) {
        return $fallback;
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return $fallback;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return $fallback;
    }

    return $url;
}

function af_aa_sanitize_bg_mode(string $mode, string $fallback = 'cover'): string
{
    $mode = strtolower(trim($mode));
    if ($mode !== 'cover' && $mode !== 'tile') {
        return $fallback;
    }

    return $mode;
}

function af_aa_sanitize_overlay(string $value, string $fallback = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    if (strpos($value, ';') !== false) {
        return $fallback;
    }

    if (preg_match('~[\r\n]~', $value)) {
        return $fallback;
    }

    if (stripos($value, '<style') !== false || stripos($value, '</style') !== false) {
        return $fallback;
    }

    if (stripos($value, 'javascript:') !== false || strpos($value, '<') !== false || strpos($value, '>') !== false) {
        return $fallback;
    }

    return $value;
}

function af_aa_css_url_value(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return 'none';
    }

    $safe = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $url);
    return 'url("' . $safe . '")';
}

function af_aa_css_raw_value(string $value, string $default = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(["\r", "\n", ';'], [' ', ' ', ''], $value);
    $value = str_replace(['</style', '<style'], ['<\\/style', ''], $value);

    return trim($value);
}

function af_aa_get_apui_setting(string $suffix, string $default = ''): string
{
    global $mybb;

    $key = 'af_advancedprofileui_' . $suffix;
    if (!isset($mybb->settings[$key])) {
        return $default;
    }

    return trim((string)$mybb->settings[$key]);
}
