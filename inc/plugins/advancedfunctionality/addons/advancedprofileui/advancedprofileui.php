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
define('AF_APUI_BACKUP_TABLE', TABLE_PREFIX . 'af_apui_template_backups');
define('AF_APUI_MARKER_PROFILE_START', '<!-- AF_APUI member_profile START -->');
define('AF_APUI_MARKER_POSTBIT_START', '<!-- AF_APUI postbit_classic START -->');
define('AF_APUI_ASSET_MARK', '<!--af_apui_assets-->');

function af_advancedprofileui_install(): void
{
    global $db;

    af_apui_ensure_schema();
    af_apui_ensure_settings();
    af_apui_apply_overrides();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedprofileui_activate(): void
{
    af_apui_ensure_schema();
    af_apui_apply_overrides();
}

function af_advancedprofileui_deactivate(): void
{
    af_apui_restore_overrides();
}

function af_advancedprofileui_uninstall(): void
{
    global $db;

    af_apui_restore_overrides();

    if ($db->table_exists(AF_APUI_BACKUP_TABLE)) {
        $db->drop_table('af_apui_template_backups');
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
    if (!function_exists('af_ensure_settinggroup') || !function_exists('af_ensure_setting')) {
        return;
    }

    af_ensure_settinggroup(
        'af_' . AF_APUI_ID,
        'AdvancedProfileUI',
        'Каркас кастомного UI профиля и postbit_classic с безопасной подменой шаблонов.'
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_enabled',
        'Включить AdvancedProfileUI',
        'Включает подмену шаблонов member_profile и postbit_classic и подключение ассетов.',
        'yesno',
        '1',
        1
    );
}

function af_apui_ensure_schema(): void
{
    global $db;

    if ($db->table_exists(AF_APUI_BACKUP_TABLE)) {
        return;
    }

    $charset = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';

    $db->write_query(
        "CREATE TABLE " . AF_APUI_BACKUP_TABLE . " (
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
    $plugins->add_hook('pre_output_page', 'af_apui_pre_output_page', 10);
    $plugins->add_hook('global_start', 'af_apui_global_start', 10);
}
af_apui_register_hooks();

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
}

function af_apui_member_profile_init_vars(): void
{
    foreach ([
        'af_apui_character_sheet_tab',
        'af_apui_application_tab',
        'af_apui_inventory_tab',
        'af_apui_timeline_tab',
        'af_apui_activity_tab',
    ] as $varName) {
        if (!isset($GLOBALS[$varName]) || !is_string($GLOBALS[$varName])) {
            $GLOBALS[$varName] = '';
        }
    }
}

function af_apui_pre_output_page(string &$page): void
{
    if (defined('IN_ADMINCP') || !af_apui_is_enabled()) {
        return;
    }

    if ($page === '' || strpos($page, AF_APUI_ASSET_MARK) !== false) {
        return;
    }

    $needAssets = false;
    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $action = strtolower((string)($GLOBALS['mybb']->input['action'] ?? ''));

    if ($script === 'member.php' && $action === 'profile') {
        $needAssets = true;
    }

    if (!$needAssets) {
        if (strpos($page, AF_APUI_MARKER_POSTBIT_START) !== false || strpos($page, AF_APUI_MARKER_PROFILE_START) !== false) {
            $needAssets = true;
        }
    }

    if (!$needAssets) {
        return;
    }

    $bburl = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_APUI_ID . '/assets';

    $cssUrl = af_apui_add_ver($base . '/advancedprofileui.css', AF_APUI_ASSETS_DIR . 'advancedprofileui.css');
    $jsUrl = af_apui_add_ver($base . '/advancedprofileui.js', AF_APUI_ASSETS_DIR . 'advancedprofileui.js');

    $injection = "\n" . AF_APUI_ASSET_MARK . "\n";
    $injection .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
    $injection .= '<script src="' . htmlspecialchars_uni($jsUrl) . '"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $injection . '</head>', $page, 1) ?? $page;
    } else {
        $page .= $injection;
    }
}

function af_apui_add_ver(string $url, string $absFile): string
{
    $ver = is_file($absFile) ? (int)@filemtime($absFile) : 0;
    if ($ver <= 0) {
        return $url;
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $ver;
}

function af_apui_apply_overrides(): void
{
    global $db;

    af_apui_ensure_schema();

    $customTemplates = af_apui_load_custom_templates();
    $targetTitles = ['member_profile', 'postbit_classic'];

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

    if (!$db->table_exists(AF_APUI_BACKUP_TABLE)) {
        return;
    }

    $q = $db->simple_select('af_apui_template_backups', '*');
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

        $db->update_query('af_apui_template_backups', [
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

    $exists = $db->simple_select('af_apui_template_backups', 'id', "template_tid='" . $tid . "'", ['limit' => 1]);
    $backupId = (int)$db->fetch_field($exists, 'id');
    if ($backupId > 0) {
        return;
    }

    $template = (string)($row['template'] ?? '');

    $db->insert_query('af_apui_template_backups', [
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

    $templates = [];
    $templates = array_merge($templates, af_parse_templates_bundle($rawProfile));
    $templates = array_merge($templates, af_parse_templates_bundle($rawPostbit));

    return $templates;
}
