<?php
/**
 * AF Addon: Knowledge Base
 * MyBB 1.8.x / PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_KB_ID', 'knowledgebase');
define('AF_KB_VER', '1.0.0');
define('AF_KB_BASE', AF_ADDONS . AF_KB_ID . '/');
define('AF_KB_ASSETS', AF_KB_BASE . 'assets/');
define('AF_KB_TPL_DIR', AF_KB_BASE . 'templates/');
define('AF_KB_MARK', '<!--af_kb_assets-->');

define('AF_KB_KEY_PATTERN', '/^[a-z0-9_-]{2,64}$/');
define('AF_KB_PERPAGE', 20);

/* -------------------- LANG -------------------- */

function af_knowledgebase_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    $base = 'advancedfunctionality_' . AF_KB_ID;

    $langFolder = !empty($lang->language) ? (string)$lang->language : 'russian';
    $expectedFile = MYBB_ROOT . 'inc/languages/' . $langFolder . '/' . $base . '.lang.php';

    if (!is_file($expectedFile) && function_exists('af_sync_addon_languages')) {
        try {
            af_sync_addon_languages();
        } catch (Throwable $e) {
            // ignore
        }
    }

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

function af_kb_setting_name(string $key): string
{
    return 'af_' . $key;
}

function af_kb_get_setting(string $key, $default = null)
{
    global $mybb;
    return $mybb->settings[$key] ?? $default;
}

function af_kb_ensure_group(string $name, string $title, string $desc): int
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

function af_kb_ensure_setting(int $gid, string $name, string $title, string $desc, string $type, string $value, int $order): void
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

function af_knowledgebase_install(): bool
{
    global $db, $lang;

    af_knowledgebase_load_lang(true);

    if (!$db->table_exists('af_kb_types')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL UNIQUE,
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  description_ru TEXT NOT NULL,
  description_en TEXT NOT NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_tab_url VARCHAR(255) NOT NULL DEFAULT '',
  sortorder INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_entries')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  `key` VARCHAR(64) NOT NULL,
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  short_ru TEXT NOT NULL,
  short_en TEXT NOT NULL,
  body_ru MEDIUMTEXT NOT NULL,
  body_en MEDIUMTEXT NOT NULL,
  tech_ru TEXT NOT NULL,
  tech_en TEXT NOT NULL,
  meta_json MEDIUMTEXT NOT NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  bg_url VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sortorder INT NOT NULL DEFAULT 0,
  updated_at INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_type_key (type, `key`),
  KEY type_active_sort (type, active, sortorder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_blocks')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  block_key VARCHAR(64) NOT NULL DEFAULT '',
  title_ru VARCHAR(255) NOT NULL DEFAULT '',
  title_en VARCHAR(255) NOT NULL DEFAULT '',
  content_ru MEDIUMTEXT NOT NULL,
  content_en MEDIUMTEXT NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  icon_class VARCHAR(128) NOT NULL DEFAULT '',
  icon_url VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sortorder INT NOT NULL DEFAULT 0,
  KEY entry_sort (entry_id, sortorder),
  KEY entry_block_key (entry_id, block_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_relations')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_relations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_type VARCHAR(64) NOT NULL,
  from_key VARCHAR(64) NOT NULL,
  rel_type VARCHAR(64) NOT NULL,
  to_type VARCHAR(64) NOT NULL,
  to_key VARCHAR(64) NOT NULL,
  meta_json MEDIUMTEXT NOT NULL,
  sortorder INT NOT NULL DEFAULT 0,
  KEY from_idx (from_type, from_key, rel_type),
  KEY to_idx (to_type, to_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    if (!$db->table_exists('af_kb_log')) {
        $sql = <<<SQL
CREATE TABLE {TABLE_PREFIX}af_kb_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uid INT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL,
  type VARCHAR(64) NOT NULL,
  `key` VARCHAR(64) NOT NULL,
  diff_json MEDIUMTEXT NOT NULL,
  dateline INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->write_query(str_replace('{TABLE_PREFIX}', TABLE_PREFIX, $sql));
    }

    $gid = af_kb_ensure_group(
        'af_knowledgebase',
        $lang->af_knowledgebase_group ?? 'AF: Knowledge Base',
        $lang->af_knowledgebase_group_desc ?? 'Settings for Knowledge Base addon.'
    );

    af_kb_ensure_setting(
        $gid,
        'af_knowledgebase_enabled',
        $lang->af_knowledgebase_enabled ?? 'Enable Knowledge Base',
        $lang->af_knowledgebase_enabled_desc ?? 'Yes/No',
        'yesno',
        '1',
        1
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_public_catalog',
        $lang->af_kb_public_catalog ?? 'Public catalog',
        $lang->af_kb_public_catalog_desc ?? 'Show catalog for everyone.',
        'yesno',
        '1',
        2
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_nav_link_enabled',
        $lang->af_kb_nav_link_enabled ?? 'KB nav link',
        $lang->af_kb_nav_link_enabled_desc ?? 'Show Knowledge Base link in the top navigation.',
        'yesno',
        '1',
        3
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_editor_groups',
        $lang->af_kb_editor_groups ?? 'Editor groups',
        $lang->af_kb_editor_groups_desc ?? 'CSV of group IDs that can edit.',
        'text',
        '',
        4
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_types_manage_groups',
        $lang->af_kb_types_manage_groups ?? 'Type management groups',
        $lang->af_kb_types_manage_groups_desc ?? 'CSV of group IDs that can manage types.',
        'text',
        '',
        5
    );
    af_kb_ensure_setting(
        $gid,
        'af_kb_atf_map',
        $lang->af_kb_atf_map ?? 'ATF → KB mapping',
        $lang->af_kb_atf_map_desc ?? 'JSON mapping of ATF field → KB type.',
        'textarea',
        '{}',
        6
    );

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    af_kb_templates_install_or_update();
    af_kb_ensure_schema();

    return true;
}


function af_knowledgebase_uninstall(): bool
{
    global $db;

    $db->drop_table('af_kb_types', true);
    $db->drop_table('af_kb_entries', true);
    $db->drop_table('af_kb_blocks', true);
    $db->drop_table('af_kb_relations', true);
    $db->drop_table('af_kb_log', true);

    $db->delete_query('settings', "name IN ('af_knowledgebase_enabled','af_kb_public_catalog','af_kb_nav_link_enabled','af_kb_editor_groups','af_kb_types_manage_groups','af_kb_atf_map')");
    $db->delete_query('settinggroups', "name='af_knowledgebase'");
    $db->delete_query('templates', "title LIKE 'knowledgebase_%'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
    if (function_exists('af_rebuild_and_reload_settings')) {
        af_rebuild_and_reload_settings();
    }

    return true;
}

function af_knowledgebase_activate(): bool
{
    af_kb_templates_install_or_update();
    af_kb_ensure_schema();
    return true;
}

function af_knowledgebase_deactivate(): bool
{
    return true;
}

/* -------------------- TEMPLATES -------------------- */

function af_kb_templates_install_or_update(): void
{
    global $db;

    if (!is_dir(AF_KB_TPL_DIR)) {
        return;
    }

    $files = glob(AF_KB_TPL_DIR . '*.html');
    if (!$files) {
        return;
    }

    foreach ($files as $file) {
        $name = basename($file, '.html');
        if ($name === '') {
            continue;
        }
        $tpl = @file_get_contents($file);
        if ($tpl === false) {
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

function af_kb_ensure_schema(): void
{
    global $db;

    if ($db->table_exists('af_kb_types')) {
        if (!$db->field_exists('icon_class', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('bg_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'bg_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('bg_tab_url', 'af_kb_types')) {
            $db->add_column('af_kb_types', 'bg_tab_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    if ($db->table_exists('af_kb_entries')) {
        if (!$db->field_exists('icon_class', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('bg_url', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'bg_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('tech_ru', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'tech_ru', "TEXT NOT NULL");
        }
        if (!$db->field_exists('tech_en', 'af_kb_entries')) {
            $db->add_column('af_kb_entries', 'tech_en', "TEXT NOT NULL");
        }
    }

    if ($db->table_exists('af_kb_blocks')) {
        if (!$db->field_exists('icon_class', 'af_kb_blocks')) {
            $db->add_column('af_kb_blocks', 'icon_class', "VARCHAR(128) NOT NULL DEFAULT ''");
        }
        if (!$db->field_exists('icon_url', 'af_kb_blocks')) {
            $db->add_column('af_kb_blocks', 'icon_url', "VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }
}

function af_kb_get_template(string $name): string
{
    global $templates;

    $tpl = '';
    if (is_object($templates)) {
        $tpl = (string)$templates->get($name);
    }

    if ($tpl === '' && is_file(AF_KB_TPL_DIR . $name . '.html')) {
        $tpl = (string)@file_get_contents(AF_KB_TPL_DIR . $name . '.html');
    }

    return $tpl;
}

/* -------------------- ACCESS -------------------- */

function af_kb_is_admin(): bool
{
    global $mybb;
    return !empty($mybb->user['uid']) && $mybb->user['uid'] > 0 && (int)($mybb->usergroup['cancp'] ?? 0) === 1;
}

function af_kb_get_user_groups(): array
{
    global $mybb;

    $groups = [];
    if (!empty($mybb->user['usergroup'])) {
        $groups[] = (int)$mybb->user['usergroup'];
    }
    if (!empty($mybb->user['additionalgroups'])) {
        $extra = explode(',', (string)$mybb->user['additionalgroups']);
        foreach ($extra as $gid) {
            $gid = (int)trim($gid);
            if ($gid > 0) {
                $groups[] = $gid;
            }
        }
    }

    return array_unique($groups);
}

function af_kb_user_in_groups(string $csv): bool
{
    if ($csv === '') {
        return false;
    }

    $allowed = [];
    foreach (explode(',', $csv) as $gid) {
        $gid = (int)trim($gid);
        if ($gid > 0) {
            $allowed[] = $gid;
        }
    }

    if (!$allowed) {
        return false;
    }

    $userGroups = af_kb_get_user_groups();
    foreach ($userGroups as $gid) {
        if (in_array($gid, $allowed, true)) {
            return true;
        }
    }

    return false;
}

function af_kb_can_edit(): bool
{
    if (af_kb_is_admin()) {
        return true;
    }

    $csv = (string)af_kb_get_setting('af_kb_editor_groups', '');
    return af_kb_user_in_groups($csv);
}

function af_kb_can_manage_types(): bool
{
    if (af_kb_is_admin()) {
        return true;
    }

    $csv = (string)af_kb_get_setting('af_kb_types_manage_groups', '');
    return af_kb_user_in_groups($csv);
}

function af_kb_can_view(): bool
{
    if ((int)af_kb_get_setting('af_kb_public_catalog', 1) === 1) {
        return true;
    }

    return af_kb_can_edit() || af_kb_is_admin();
}

/* -------------------- UTILITIES -------------------- */

function af_kb_is_ru(): bool
{
    global $lang;
    return isset($lang->language) && $lang->language === 'russian';
}

function af_kb_pick_text(array $row, string $field): string
{
    $suffix = af_kb_is_ru() ? '_ru' : '_en';
    $key = $field . $suffix;
    $value = (string)($row[$key] ?? '');
    if ($value === '') {
        $fallback = (string)($row[$field . '_ru'] ?? '');
        if ($fallback === '') {
            $fallback = (string)($row[$field . '_en'] ?? '');
        }
        return $fallback;
    }

    return $value;
}

function af_kb_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = str_replace(["\r", "\n", "\t"], '', $url);
    $url = preg_replace('/["\'()\\\\]/', '', $url);
    if ($url === null || $url === '') {
        return '';
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== '') {
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    if (strpos($url, '//') === 0) {
        return '';
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
        return '';
    }

    return $url;
}

function af_kb_sanitize_icon_class(string $class): string
{
    $class = trim($class);
    if ($class === '') {
        return '';
    }

    $class = preg_replace('/[^a-zA-Z0-9 _:-]/', '', $class);
    return $class ?? '';
}

function af_kb_build_icon_html(string $iconUrl, string $iconClass): string
{
    $url = af_kb_sanitize_url($iconUrl);
    if ($url !== '') {
        return '<img class="af-kb-icon-img" src="' . htmlspecialchars_uni($url) . '" alt="" loading="lazy" />';
    }

    $class = af_kb_sanitize_icon_class($iconClass);
    if ($class !== '') {
        return '<i class="' . htmlspecialchars_uni($class) . '"></i>';
    }

    return '';
}

function af_kb_build_bg_style(string $bgUrl): string
{
    $url = af_kb_sanitize_url($bgUrl);
    if ($url === '') {
        return '';
    }

    return "background-image:url('" . htmlspecialchars_uni($url) . "');";
}

function af_kb_build_body_bg_style(string $bgUrl): string
{
    $url = af_kb_sanitize_url($bgUrl);
    if ($url === '') {
        return '';
    }

    $escaped = htmlspecialchars_uni($url);
    return '<style>body{background:url(\'' . $escaped . '\') no-repeat center center fixed;background-size:cover;}</style>';
}

function af_kb_build_tech_hint(string $text): string
{
    $text = preg_replace('/^\s*\[icon=[^\]]+\]\s*/i', '', $text) ?? $text;
    $text = trim(strip_tags($text));
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\r\n?/', "\n", $text);
    $lines = preg_split('/\n+/', $text);
    if (!is_array($lines)) {
        return $text;
    }

    $lines = array_slice($lines, 0, 3);
    $text = implode("\n", $lines);
    return trim($text);
}

function af_kb_build_tech_note_html(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $iconHtml = '';
    if (preg_match('/^\s*\[icon=([^\]]+)\]\s*/i', $text, $matches)) {
        $rawIcon = trim($matches[1]);
        $text = substr($text, strlen($matches[0]));
        $url = af_kb_sanitize_url($rawIcon);
        if ($url !== '' && (preg_match('~^https?://~i', $rawIcon) || strpos($rawIcon, '/') !== false || strpos($rawIcon, '.') !== false)) {
            $iconHtml = '<img class="af-kb-icon-img" src="' . htmlspecialchars_uni($url) . '" alt="" loading="lazy" />';
        } else {
            $class = af_kb_sanitize_icon_class($rawIcon);
            if ($class !== '') {
                $iconHtml = '<i class="' . htmlspecialchars_uni($class) . '"></i>';
            }
        }
    }

    $parsed = af_kb_parse_message(trim($text));
    if ($parsed === '') {
        return '';
    }

    if ($iconHtml !== '') {
        return '<span class="af-kb-tech-icon">' . $iconHtml . '</span><span class="af-kb-tech-text">' . $parsed . '</span>';
    }

    return $parsed;
}

function af_kb_render_tech_note_details(string $label, string $text): string
{
    $html = af_kb_build_tech_note_html($text);
    if ($html === '') {
        return '';
    }

    return '<details class="af-kb-tech"><summary>' . htmlspecialchars_uni($label) . '</summary><div class="af-kb-tech-note">' . $html . '</div></details>';
}

function af_kb_parse_message(string $message): string
{
    if ($message === '') {
        return '';
    }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }

    $parser = new postParser;
    $options = [
        'allow_html'         => 1,
        'allow_mycode'       => 1,
        'allow_basicmycode'  => 1,
        'allow_smilies'      => 1,
        'allow_imgcode'      => 1,
        'allow_videocode'    => 1,
        'allow_list'         => 1,
        'allow_alignmycode'  => 1,
        'allow_font'         => 1,
        'allow_color'        => 1,
        'allow_size'         => 1,
        'filter_badwords'    => 1,
        'nl2br'              => 1,
    ];

    return $parser->parse_message($message, $options);
}

function af_kb_render_json_error(string $message, int $code = 403): void
{
    af_kb_send_json(['success' => false, 'error' => $message], $code);
}

function af_kb_send_json(array $payload, int $code = 200): void
{
    $GLOBALS['af_disable_pre_output'] = true;
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_kb_validate_json(string $raw): bool
{
    if ($raw === '') {
        return true;
    }

    json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE;
}

function af_kb_normalize_json(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '{}';
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $raw;
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function af_kb_decode_json(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function af_kb_is_empty_json(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return true;
    }

    return in_array($raw, ['{}', '[]'], true);
}

function af_kb_render_data_table(string $json): string
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return '';
    }

    $rows = '';
    foreach ($decoded as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $rows .= '<tr><th>' . htmlspecialchars_uni((string)$key) . '</th><td>'
            . htmlspecialchars_uni((string)$value) . '</td></tr>';
    }

    if ($rows === '') {
        return '';
    }

    return '<table class="af-kb-data-table">' . $rows . '</table>';
}

function af_kb_render_tech_details(string $label, string $json, string $copyLabel = ''): string
{
    $json = trim($json);
    if ($json === '' || af_kb_is_empty_json($json)) {
        return '';
    }

    $table = af_kb_render_data_table($json);
    if ($table === '') {
        return '';
    }

    $copyButton = '';
    if ($copyLabel !== '') {
        $copyButton = '<button type="button" class="af-kb-copy-json" data-json="' . htmlspecialchars_uni($json) . '">'
            . htmlspecialchars_uni($copyLabel) . '</button>';
    }

    return '<details class="af-kb-tech"><summary>' . htmlspecialchars_uni($label) . '</summary>' . $copyButton . $table . '</details>';
}

function af_kb_get_entry_ui(array $entry): array
{
    $meta = af_kb_decode_json((string)($entry['meta_json'] ?? ''));
    $ui = [];
    if (!empty($meta['ui']) && is_array($meta['ui'])) {
        $ui = $meta['ui'];
    }

    return [
        'icon_class' => (string)($ui['icon_class'] ?? $entry['icon_class'] ?? ''),
        'icon_url' => (string)($ui['icon_url'] ?? $entry['icon_url'] ?? ''),
        'background_url' => (string)($ui['background_url'] ?? $entry['bg_url'] ?? ''),
        'background_tab_url' => (string)($meta['background_tab_url'] ?? $ui['background_tab_url'] ?? ''),
    ];
}

function af_kb_get_entry_summary(string $type, string $key): array
{
    static $cache = [];
    $cacheKey = $type . ':' . $key;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    global $db;

    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    if (!af_kb_can_edit()) {
        $where .= " AND active=1";
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        $cache[$cacheKey] = [];
        return [];
    }

    $ui = af_kb_get_entry_ui($entry);
    $title = af_kb_pick_text($entry, 'title') ?: $entry['key'];
    $techHint = af_kb_build_tech_hint(af_kb_pick_text($entry, 'tech'));

    $cache[$cacheKey] = [
        'title' => $title,
        'icon_url' => $ui['icon_url'],
        'icon_class' => $ui['icon_class'],
        'tech_hint' => $techHint,
    ];

    return $cache[$cacheKey];
}

/* -------------------- ATF UTILITIES -------------------- */

function af_kb_parse_atf_options(string $rawOptions): array
{
    $result = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawOptions);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strpos($line, '=') === false) {
            $result[$line] = $line;
            continue;
        }
        [$key, $label] = array_map('trim', explode('=', $line, 2));
        if ($key === '') {
            continue;
        }
        $result[$key] = $label === '' ? $key : $label;
    }

    return $result;
}

function af_kb_resolve_atf_select_label(string $fieldOptions, string $rawValue): string
{
    $map = af_kb_parse_atf_options($fieldOptions);
    return $map[$rawValue] ?? $rawValue;
}

/* -------------------- RUNTIME -------------------- */

function af_knowledgebase_init(): void
{
    global $plugins;

    $plugins->add_hook('misc_start', 'af_kb_misc_route', 10);
    $plugins->add_hook('pre_output_page', 'af_knowledgebase_pre_output', 10);
    $plugins->add_hook('parse_message_end', 'af_kb_parse_message_end', 10);
}

function af_knowledgebase_pre_output(string &$page = ''): void
{
    global $mybb, $lang;

    $action = $mybb->get_input('action');
    $is_kb_page = in_array(
        $action,
        ['kb', 'kb_edit', 'kb_get', 'kb_list', 'kb_children', 'kb_type_edit', 'kb_type_delete', 'kb_help', 'kb_types'],
        true
    );
    $enabled = !empty($mybb->settings['af_knowledgebase_enabled']);
    $hasMark = strpos($page, AF_KB_MARK) !== false;

    if ($enabled && !$hasMark) {
        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        if ($bburl !== '') {
            $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_KB_ID . '/assets';
            $cssTag = '';
            $jsTag = '';
            $editorAssets = '';

            if ($is_kb_page) {
                $cssTag .= '<link rel="stylesheet" type="text/css" href="'.$assetsBase.'/knowledgebase.css?ver='.AF_KB_VER.'" />';
                $jsTag .= '<script src="'.$assetsBase.'/knowledgebase.js?ver='.AF_KB_VER.'"></script>';
            }

            if (in_array($action, ['kb_edit', 'kb_type_edit'], true)) {
                $editorAssets = '<link rel="stylesheet" type="text/css" href="'.$bburl.'/jscripts/sceditor/themes/default.min.css" />'
                    . '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.min.js"></script>'
                    . '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.bbcode.min.js"></script>'
                    . '<script src="'.$bburl.'/jscripts/sceditor/jquery.sceditor.mybb.min.js"></script>';
            }

            af_knowledgebase_load_lang(false);
            $langPayload = json_encode([
                'kbInsertLabel' => $lang->af_kb_kb_insert_label ?? 'KB',
                'kbInsertTitle' => $lang->af_kb_kb_insert_title ?? 'Insert KB',
                'kbInsertSearch' => $lang->af_kb_kb_insert_search ?? 'Search...',
                'kbInsertSelect' => $lang->af_kb_kb_insert_select ?? 'Select category',
                'kbInsertEmpty' => $lang->af_kb_kb_insert_empty ?? 'Nothing found',
                'kbInsertHint' => $lang->af_kb_kb_insert_hint ?? 'Select category or continue search',
                'kbInsertButton' => $lang->af_kb_kb_insert_button ?? 'Insert',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $langTag = $langPayload !== false ? '<script>window.afKbLang='.$langPayload.';</script>' : '';

            $kbUiCss = '<link rel="stylesheet" type="text/css" href="'.$assetsBase.'/knowledgebase_kbui.css?ver='.AF_KB_VER.'" />';
            $chipsJs = '<script src="'.$assetsBase.'/knowledgebase_chips.js?ver='.AF_KB_VER.'"></script>';
            $insertJs = '<script src="'.$assetsBase.'/knowledgebase_insert.js?ver='.AF_KB_VER.'"></script>';

            if (stripos($page, '</head>') !== false) {
                $page = str_ireplace(
                    '</head>',
                    $cssTag.$kbUiCss.$editorAssets.$jsTag.$chipsJs.$insertJs.$langTag.AF_KB_MARK.'</head>',
                    $page
                );
            } else {
                $page .= $cssTag.$kbUiCss.$editorAssets.$jsTag.$chipsJs.$insertJs.$langTag.AF_KB_MARK;
            }
        }
    }

    if ($enabled && (int)af_kb_get_setting('af_kb_nav_link_enabled', 1) === 1 && af_kb_can_view()) {
        if (strpos($page, '<!--af_kb_nav-->') === false) {
            af_knowledgebase_load_lang(false);
            $linkText = $lang->af_knowledgebase_name ?? 'KB';
            $li = '<li class="af-kb-link"><a href="misc.php?action=kb">'.htmlspecialchars_uni($linkText).'</a></li><!--af_kb_nav-->';
            $patched = preg_replace(
                '~(<ul[^>]*class="[^"]*\bmenu\b[^"]*\btop_links\b[^"]*"[^>]*>)(.*?)(</ul>)~is',
                '$1$2'.$li.'$3',
                $page,
                1
            );
            if ($patched !== null) {
                $page = $patched;
            }
        }
    }
}

function af_kb_parse_message_end(&$message): void
{
    global $mybb;

    if (empty($mybb->settings['af_knowledgebase_enabled'])) {
        return;
    }

    if (!af_kb_can_view()) {
        return;
    }

    if (stripos($message, '[kb=') === false) {
        return;
    }

    $message = preg_replace_callback(
        '/\\[kb=([a-z0-9_-]{2,64}):([a-z0-9_-]{2,64})\\]/i',
        function (array $matches): string {
            $type = strtolower($matches[1]);
            $key = strtolower($matches[2]);
            $summary = af_kb_get_entry_summary($type, $key);
            $title = $summary['title'] ?? ($type . ':' . $key);
            $iconHtml = af_kb_build_icon_html($summary['icon_url'] ?? '', $summary['icon_class'] ?? '');
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-chip-icon">' . $iconHtml . '</span>' : '';
            $techHint = $summary['tech_hint'] ?? '';

            $attrs = ' data-kb-type="' . htmlspecialchars_uni($type) . '" data-kb-key="' . htmlspecialchars_uni($key) . '"'
                . ' data-kb-title="' . htmlspecialchars_uni($title) . '"';
            if ($techHint !== '') {
                $attrs .= ' data-tech-hint="' . htmlspecialchars_uni($techHint) . '"';
            }

            return '<span class="af-kb-chip"' . $attrs . '>' . $iconWrap
                . '<span class="af-kb-chip-label">' . htmlspecialchars_uni($title) . '</span></span>';
        },
        $message
    );
}

/* -------------------- ROUTER -------------------- */

function af_kb_misc_route(): void
{
    global $mybb;

    $action = $mybb->get_input('action');
    if (!in_array($action, ['kb', 'kb_edit', 'kb_get', 'kb_list', 'kb_children', 'kb_type_edit', 'kb_type_delete', 'kb_help', 'kb_types'], true)) {
        return;
    }

    if (empty($mybb->settings['af_knowledgebase_enabled'])) {
        error_no_permission();
    }

    af_knowledgebase_load_lang(false);

    if ($action === 'kb_get') {
        af_kb_handle_json_get();
    }
    if ($action === 'kb_list') {
        af_kb_handle_json_list();
    }
    if ($action === 'kb_types') {
        af_kb_handle_json_types();
    }
    if ($action === 'kb_children') {
        af_kb_handle_json_children();
    }

    if ($action === 'kb_edit') {
        af_kb_handle_edit();
    }

    if ($action === 'kb_type_edit') {
        af_kb_handle_type_edit();
    }

    if ($action === 'kb_type_delete') {
        af_kb_handle_type_delete();
    }

    if ($action === 'kb_help') {
        af_kb_handle_help();
    }

    af_kb_handle_view();
}

/* -------------------- VIEW HANDLERS -------------------- */

function af_kb_handle_view(): void
{
    global $mybb, $db, $lang, $headerinclude, $header, $footer, $theme, $templates;

    if (!af_kb_can_view()) {
        error($lang->af_kb_no_access ?? 'No access.');
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));
    $query = trim((string)$mybb->get_input('q'));

    if ($type === '') {
        if (function_exists('add_breadcrumb')) {
            add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        }

        $typesWhere = 'active=1';
        if ($query !== '') {
            $safeQuery = $db->escape_string($query);
            $typesWhere .= " AND (title_ru LIKE '%{$safeQuery}%' OR title_en LIKE '%{$safeQuery}%')";
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $perpage = AF_KB_PERPAGE;
        $total = (int)$db->fetch_field(
            $db->simple_select('af_kb_types', 'COUNT(*) AS cnt', $typesWhere),
            'cnt'
        );
        $start = ($page - 1) * $perpage;

        $types = [];
        $q = $db->simple_select(
            'af_kb_types',
            '*',
            $typesWhere,
            [
                'order_by' => 'sortorder, type',
                'order_dir' => 'ASC',
                'limit' => $perpage,
                'limit_start' => $start,
            ]
        );
        while ($row = $db->fetch_array($q)) {
            $types[] = $row;
        }

        $rows = '';
        foreach ($types as $row) {
            $title = af_kb_pick_text($row, 'title');
            if ($title === '') {
                $title = $row['type'];
            }
            $desc = af_kb_pick_text($row, 'description');
            $iconHtml = af_kb_build_icon_html($row['icon_url'] ?? '', $row['icon_class'] ?? '');
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-icon">' . $iconHtml . '</span>' : '';
            $bgStyle = af_kb_build_bg_style($row['bg_tab_url'] ?? '');
            $styleAttr = $bgStyle !== '' ? ' style="' . $bgStyle . '"' : '';
            $bgClass = $bgStyle !== '' ? ' af-kb-tab--with-bg' : '';
            $rows .= '<a class="af-kb-tab'.$bgClass.'"'.$styleAttr.' href="misc.php?action=kb&type='.htmlspecialchars_uni($row['type']).'">'
                . '<span class="af-kb-tab-title">'.$iconWrap.htmlspecialchars_uni($title).'</span>'
                . '<span class="af-kb-tab-desc">'.af_kb_parse_message($desc).'</span>'
                . '</a>';
        }

        $paginationUrl = 'misc.php?action=kb';
        if ($query !== '') {
            $paginationUrl .= '&q=' . urlencode($query);
        }
        $kb_pagination = $total > $perpage && function_exists('multipage')
            ? multipage($total, $perpage, $page, $paginationUrl)
            : '';

        $kb_page_title = $lang->af_kb_catalog_title ?? 'Knowledge Base';
        $kb_types_rows = $rows;
        $kb_query = htmlspecialchars_uni($query);
        $kb_can_edit = af_kb_can_edit() ? '1' : '0';
        $kb_create_link = af_kb_can_manage_types()
            ? '<a class="af-kb-btn af-kb-btn--create af-kb-btn-create" href="misc.php?action=kb_type_edit">'.htmlspecialchars_uni($lang->af_kb_type_create ?? 'Create category').'</a>'
            : '';
        $kb_help_link = af_kb_can_edit()
            ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
            : '';
        $kb_page_bg = '';
        $kb_body_style = '';
        $af_kb_content = '';
        eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_catalog') . "\";");
        eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
        output_page($page);
        exit;
    }

    if ($key === '') {
        $escapedType = $db->escape_string($type);
        $where = "type='{$escapedType}'";
        if (!af_kb_can_edit()) {
            $where .= " AND active=1";
        }
        if ($query !== '') {
            $safeQuery = $db->escape_string($query);
            $where .= " AND (title_ru LIKE '%{$safeQuery}%' OR title_en LIKE '%{$safeQuery}%')";
        }

        $page = max(1, (int)$mybb->get_input('page', MyBB::INPUT_INT));
        $perpage = AF_KB_PERPAGE;
        $total = (int)$db->fetch_field(
            $db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', $where),
            'cnt'
        );
        $start = ($page - 1) * $perpage;

        $entries = [];
        $q = $db->simple_select(
            'af_kb_entries',
            '*',
            $where,
            [
                'order_by' => 'sortorder, id',
                'order_dir' => 'ASC',
                'limit' => $perpage,
                'limit_start' => $start,
            ]
        );
        while ($row = $db->fetch_array($q)) {
            $entries[] = $row;
        }

        $typeRow = $db->fetch_array(
            $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
        );
        $typeTitle = $type;
        $typeDesc = '';
        if ($typeRow) {
            $typeTitle = af_kb_pick_text($typeRow, 'title') ?: $type;
            $typeDesc = af_kb_pick_text($typeRow, 'description');
        }

        $typeIconUrl = $typeRow ? ($typeRow['icon_url'] ?? '') : '';
        $typeIconClass = $typeRow ? ($typeRow['icon_class'] ?? '') : '';
        $rows = '';
        foreach ($entries as $row) {
            $title = af_kb_pick_text($row, 'title');
            if ($title === '') {
                $title = $row['key'];
            }
            $short = af_kb_parse_message(af_kb_pick_text($row, 'short'));
            $entryUi = af_kb_get_entry_ui($row);
            $iconUrl = $entryUi['icon_url'] ?: $typeIconUrl;
            $iconClass = $entryUi['icon_class'] ?: $typeIconClass;
            $iconHtml = af_kb_build_icon_html($iconUrl, $iconClass);
            $iconWrap = $iconHtml !== '' ? '<span class="af-kb-icon">' . $iconHtml . '</span>' : '';
            $entryBgStyle = af_kb_build_bg_style($entryUi['background_tab_url'] ?? '');
            $entryStyle = $entryBgStyle !== '' ? ' style="' . $entryBgStyle . '"' : '';
            $entryClass = $entryBgStyle !== '' ? ' af-kb-entry--with-bg' : '';
            $rows .= '<div class="af-kb-entry'.$entryClass.'"'.$entryStyle.'>
                <h3><a href="misc.php?action=kb&type='.htmlspecialchars_uni($row['type']).'&key='.htmlspecialchars_uni($row['key']).'">'.$iconWrap.htmlspecialchars_uni($title).'</a></h3>
                <div class="af-kb-entry-short">'.$short.'</div>
            </div>';
        }

        if (function_exists('add_breadcrumb')) {
            add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
            add_breadcrumb($typeTitle, 'misc.php?action=kb&type=' . urlencode($type));
        }

        $typeIconHtml = $typeRow ? af_kb_build_icon_html($typeRow['icon_url'] ?? '', $typeRow['icon_class'] ?? '') : '';
        $kb_type_icon = $typeIconHtml !== '' ? '<span class="af-kb-icon">' . $typeIconHtml . '</span>' : '';
        $kb_page_title = htmlspecialchars_uni($typeTitle);
        $kb_type_title = htmlspecialchars_uni($typeTitle);
        $kb_type_description = af_kb_parse_message($typeDesc);
        $kb_type_value = htmlspecialchars_uni($type);
        $kb_query = htmlspecialchars_uni($query);
        $kb_entries_rows = $rows;
        $kb_entries_style = '';
        $kb_entries_class = '';
        $paginationUrl = 'misc.php?action=kb&type=' . urlencode($type);
        if ($query !== '') {
            $paginationUrl .= '&q=' . urlencode($query);
        }
        $kb_pagination = $total > $perpage && function_exists('multipage')
            ? multipage($total, $perpage, $page, $paginationUrl)
            : '';
        $kb_can_edit = af_kb_can_edit() ? '1' : '0';
        $actions = [];
        if (af_kb_can_edit()) {
            $actions[] = '<a class="af-kb-btn af-kb-btn--create af-kb-btn-create" href="misc.php?action=kb_edit&type='.htmlspecialchars_uni($type).'">'.htmlspecialchars_uni($lang->af_kb_create ?? 'Create').'</a>';
        }
        if (af_kb_can_manage_types()) {
            $actions[] = '<a class="af-kb-btn af-kb-btn--edit af-kb-btn-edit" href="misc.php?action=kb_type_edit&type='.htmlspecialchars_uni($type).'">'.htmlspecialchars_uni($lang->af_kb_type_edit ?? 'Edit category').'</a>';
            $confirm = htmlspecialchars_uni($lang->af_kb_type_delete_confirm ?? 'Delete category?');
            $actions[] = '<a class="af-kb-btn af-kb-btn--delete af-kb-btn-delete" href="misc.php?action=kb_type_delete&type='.htmlspecialchars_uni($type).'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'" onclick="return confirm(\''.$confirm.'\');">'.htmlspecialchars_uni($lang->af_kb_type_delete ?? 'Delete category').'</a>';
        }
        $kb_help_link = af_kb_can_edit()
            ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
            : '';
        $kb_type_actions = implode(' ', $actions);
        $kb_page_bg = '';
        $kb_body_style = af_kb_build_body_bg_style($typeRow ? ($typeRow['bg_url'] ?? '') : '');
        $af_kb_content = '';
        eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_list') . "\";");
        eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
        output_page($page);
        exit;
    }

    $escapedType = $db->escape_string($type);
    $escapedKey = $db->escape_string($key);
    $where = "type='{$escapedType}' AND `key`='{$escapedKey}'";
    if (!af_kb_can_edit()) {
        $where .= " AND active=1";
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $typeRow = $db->fetch_array(
        $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
    );
    $typeTitle = $type;
    if ($typeRow) {
        $typeTitle = af_kb_pick_text($typeRow, 'title') ?: $type;
    }

    $title = af_kb_pick_text($entry, 'title');
    if ($title === '') {
        $title = $entry['key'];
    }

    $short = af_kb_parse_message(af_kb_pick_text($entry, 'short'));
    $body = af_kb_parse_message(af_kb_pick_text($entry, 'body'));

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        add_breadcrumb($typeTitle, 'misc.php?action=kb&type=' . urlencode($type));
        add_breadcrumb($title, 'misc.php?action=kb&type=' . urlencode($type) . '&key=' . urlencode($key));
    }

    $blocks = [];
    $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($bq)) {
        if (!$row['active'] && !af_kb_can_edit()) {
            continue;
        }
        $blocks[] = $row;
    }

    $kb_blocks = '';
    foreach ($blocks as $block) {
        $blockIconHtml = af_kb_build_icon_html($block['icon_url'] ?? '', $block['icon_class'] ?? '');
        $block_icon = $blockIconHtml !== '' ? '<span class="af-kb-icon">' . $blockIconHtml . '</span>' : '';
        $block_title = htmlspecialchars_uni(af_kb_pick_text($block, 'title'));
        $block_content = af_kb_parse_message(af_kb_pick_text($block, 'content'));
        $block_data_table = '';
        if (af_kb_can_edit() && !empty($block['data_json'])) {
            $block_data_table = af_kb_render_tech_details(
                $lang->af_kb_technical_data ?? 'Technical data',
                $block['data_json']
            );
        }
        eval("\$kb_blocks .= \"" . af_kb_get_template('knowledgebase_blocks_item') . "\";");
    }

    $relations = [];
    $rq = $db->simple_select('af_kb_relations', '*', "from_type='{$escapedType}' AND from_key='{$escapedKey}'", ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($rq)) {
        $relations[] = $row;
    }

    $grouped = [];
    foreach ($relations as $rel) {
        $grouped[$rel['rel_type']][] = $rel;
    }

    $kb_relations = '';
    foreach ($grouped as $relType => $items) {
        $kb_rel_items = '';
        foreach ($items as $rel) {
            $toTitle = $rel['to_key'];
            $rel_icon = '';
            $target = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($rel['to_type'])."' AND `key`='".$db->escape_string($rel['to_key'])."'",
                    ['limit' => 1]
                )
            );
            if ($target) {
                $toTitle = af_kb_pick_text($target, 'title');
                if ($toTitle === '') {
                    $toTitle = $target['key'];
                }
                $targetUi = af_kb_get_entry_ui($target);
                $relIconHtml = af_kb_build_icon_html($targetUi['icon_url'], $targetUi['icon_class']);
                if ($relIconHtml !== '') {
                    $rel_icon = '<span class="af-kb-icon">' . $relIconHtml . '</span>';
                }
            }
            $rel_to_type = htmlspecialchars_uni($rel['to_type']);
            $rel_to_key = htmlspecialchars_uni($rel['to_key']);
            $rel_title = htmlspecialchars_uni($toTitle);
            $rel_meta_details = '';
            if (af_kb_can_edit() && !empty($rel['meta_json'])) {
                $rel_meta_details = af_kb_render_tech_details(
                    $lang->af_kb_technical_data ?? 'Technical data',
                    $rel['meta_json']
                );
            }
            eval("\$kb_rel_items .= \"" . af_kb_get_template('knowledgebase_rel_item') . "\";");
        }
        $kb_relations .= '<div class="af-kb-rel-group"><h4>'.htmlspecialchars_uni($relType).'</h4><ul>'.$kb_rel_items.'</ul></div>';
    }

    $entryUi = af_kb_get_entry_ui($entry);
    $entryIconHtml = af_kb_build_icon_html($entryUi['icon_url'], $entryUi['icon_class']);
    $kb_entry_icon = $entryIconHtml !== '' ? '<span class="af-kb-icon">' . $entryIconHtml . '</span>' : '';
    $kb_page_title = htmlspecialchars_uni($title);
    $kb_title = htmlspecialchars_uni($title);
    $kb_short = $short;
    $kb_body = $body;
    $kb_can_edit = af_kb_can_edit() ? '1' : '0';
    $kb_edit_link = af_kb_can_edit() ? '<a class="af-kb-btn af-kb-btn--edit af-kb-btn-edit" href="misc.php?action=kb_edit&type='.htmlspecialchars_uni($type).'&key='.htmlspecialchars_uni($key).'">'.htmlspecialchars_uni($lang->af_kb_edit ?? 'Edit').'</a>' : '';
    $kb_delete_form = '';
    if (af_kb_can_edit()) {
        $deleteLabel = $lang->af_kb_delete_entry ?? 'Delete entry';
        $kb_delete_form = '<form class="af-kb-delete-form" method="post" action="misc.php?action=kb_edit&amp;type='
            . htmlspecialchars_uni($type) . '&amp;key=' . htmlspecialchars_uni($key) . '">'
            . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
            . '<button type="submit" name="kb_delete" value="1" class="af-kb-btn af-kb-btn--delete af-kb-btn-delete"'
            . ' onclick="return confirm(\'' . htmlspecialchars_uni($lang->af_kb_delete_confirm ?? 'Delete entry?') . '\');">'
            . htmlspecialchars_uni($deleteLabel) . '</button></form>';
    }
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';
    $kb_meta_details = '';
    if (af_kb_can_edit()) {
        $kb_meta_details = af_kb_render_tech_details(
            $lang->af_kb_technical_data ?? 'Technical data',
            (string)($entry['meta_json'] ?? ''),
            $lang->af_kb_copy_json ?? 'Copy JSON'
        );
    }
    $kb_tech_details = af_kb_render_tech_note_details(
        $lang->af_kb_tech_label ?? 'Technical note',
        af_kb_pick_text($entry, 'tech')
    );
    $kb_page_bg = '';
    $bodyBgUrl = $entryUi['background_url'] ?: ($typeRow ? ($typeRow['bg_url'] ?? '') : '');
    $kb_body_style = af_kb_build_body_bg_style($bodyBgUrl);
    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_view') . "\";");
    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

function af_kb_handle_edit(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_edit()) {
        error_no_permission();
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));

    $entry = null;
    if ($type !== '' && $key !== '') {
        $entry = $db->fetch_array(
            $db->simple_select(
                'af_kb_entries',
                '*',
                "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                ['limit' => 1]
            )
        );
    }

    $errors = [];

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $type = trim((string)$mybb->get_input('type'));
        $key = trim((string)$mybb->get_input('key'));

        if ((int)$mybb->get_input('kb_delete', MyBB::INPUT_INT) === 1) {
            if ($type === '' || $key === '') {
                error($lang->af_kb_not_found ?? 'Not found');
            }
            $existing = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                    ['limit' => 1]
                )
            );
            if (!$existing) {
                error($lang->af_kb_not_found ?? 'Not found');
            }

            $db->delete_query('af_kb_blocks', 'entry_id='.(int)$existing['id']);
            $db->delete_query('af_kb_relations', "from_type='".$db->escape_string($type)."' AND from_key='".$db->escape_string($key)."'");
            $db->delete_query('af_kb_entries', 'id='.(int)$existing['id']);

            $db->insert_query('af_kb_log', [
                'uid'      => (int)$mybb->user['uid'],
                'action'   => $db->escape_string('delete'),
                'type'     => $db->escape_string($type),
                'key'      => $db->escape_string($key),
                'diff_json'=> $db->escape_string('{}'),
                'dateline' => TIME_NOW,
            ]);

            redirect('misc.php?action=kb&type='.urlencode($type), 'Deleted');
        }

        if ($type === '') {
            $errors[] = 'Type is required.';
        }
        if ($key === '') {
            $errors[] = 'Key is required.';
        }
        if ($key !== '' && !preg_match(AF_KB_KEY_PATTERN, $key)) {
            $errors[] = $lang->af_kb_invalid_key ?? 'Invalid key.';
        }

        $metaJson = trim((string)$mybb->get_input('meta_json'));
        $entryIconClass = af_kb_sanitize_icon_class((string)$mybb->get_input('icon_class'));
        $entryIconUrl = af_kb_sanitize_url((string)$mybb->get_input('icon_url'));
        $entryBgUrl = af_kb_sanitize_url((string)$mybb->get_input('background_url'));
        $entryBgTabUrl = af_kb_sanitize_url((string)$mybb->get_input('entry_background_tab_url'));
        if (!af_kb_validate_json($metaJson)) {
            $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
        }

        $blocksInput = $mybb->get_input('blocks', MyBB::INPUT_ARRAY);
        $relationsInput = $mybb->get_input('relations', MyBB::INPUT_ARRAY);

        $parsedBlocks = [];
        if (is_array($blocksInput)) {
            foreach ($blocksInput as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $blockKey = trim((string)($block['block_key'] ?? ''));
                $titleRu = trim((string)($block['title_ru'] ?? ''));
                $titleEn = trim((string)($block['title_en'] ?? ''));
                $contentRu = trim((string)($block['content_ru'] ?? ''));
                $contentEn = trim((string)($block['content_en'] ?? ''));
                $dataJson = trim((string)($block['data_json'] ?? ''));
                $blockIconClass = af_kb_sanitize_icon_class((string)($block['icon_class'] ?? ''));
                $blockIconUrl = af_kb_sanitize_url((string)($block['icon_url'] ?? ''));
                $active = !empty($block['active']) ? 1 : 0;
                $sortorder = (int)($block['sortorder'] ?? 0);

                $dataJsonEmpty = af_kb_is_empty_json($dataJson);
                if ($blockKey === '' && $titleRu === '' && $titleEn === '' && $contentRu === '' && $contentEn === '' && $dataJsonEmpty) {
                    continue;
                }

                if (!$dataJsonEmpty && !af_kb_validate_json($dataJson)) {
                    $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
                    break;
                }

                $parsedBlocks[] = [
                    'block_key'   => $blockKey,
                    'title_ru'    => $titleRu,
                    'title_en'    => $titleEn,
                    'content_ru'  => $contentRu,
                    'content_en'  => $contentEn,
                    'data_json'   => af_kb_normalize_json($dataJson),
                    'icon_class'  => $blockIconClass,
                    'icon_url'    => $blockIconUrl,
                    'active'      => $active,
                    'sortorder'   => $sortorder,
                ];
            }
        }

        $parsedRelations = [];
        if (is_array($relationsInput)) {
            foreach ($relationsInput as $rel) {
                if (!is_array($rel)) {
                    continue;
                }
                $relType = trim((string)($rel['rel_type'] ?? ''));
                $toType = trim((string)($rel['to_type'] ?? ''));
                $toKey = trim((string)($rel['to_key'] ?? ''));
                $meta = trim((string)($rel['meta_json'] ?? ''));
                $sortorder = (int)($rel['sortorder'] ?? 0);

                $metaEmpty = af_kb_is_empty_json($meta);
                if ($relType === '' && $toType === '' && $toKey === '' && $metaEmpty) {
                    continue;
                }

                if ($toKey !== '' && !preg_match(AF_KB_KEY_PATTERN, $toKey)) {
                    $errors[] = $lang->af_kb_invalid_key ?? 'Invalid key.';
                    break;
                }

                if (!$metaEmpty && !af_kb_validate_json($meta)) {
                    $errors[] = $lang->af_kb_invalid_json ?? 'Invalid JSON.';
                    break;
                }

                if ($relType === '' || $toType === '' || $toKey === '') {
                    $errors[] = 'Relation requires rel_type, to_type, to_key.';
                    break;
                }

                $parsedRelations[] = [
                    'rel_type'  => $relType,
                    'to_type'   => $toType,
                    'to_key'    => $toKey,
                    'meta_json' => af_kb_normalize_json($meta),
                    'sortorder' => $sortorder,
                ];
            }
        }

        if (!$errors) {
            $existing = $db->fetch_array(
                $db->simple_select(
                    'af_kb_entries',
                    '*',
                    "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'",
                    ['limit' => 1]
                )
            );

            if ($existing && (!$entry || (int)$existing['id'] !== (int)($entry['id'] ?? 0))) {
                $errors[] = 'Entry with this type/key already exists.';
            }
        }

        if (!$errors) {
            $metaPayload = af_kb_decode_json($metaJson);
            if (!isset($metaPayload['ui']) || !is_array($metaPayload['ui'])) {
                $metaPayload['ui'] = [];
            }
            $metaPayload['ui']['icon_class'] = $entryIconClass;
            $metaPayload['ui']['icon_url'] = $entryIconUrl;
            $metaPayload['ui']['background_url'] = $entryBgUrl;
            $metaPayload['background_tab_url'] = $entryBgTabUrl;
            $metaJsonNormalized = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($metaJsonNormalized === false) {
                $metaJsonNormalized = '{}';
            }

            $data = [
                'type'       => $db->escape_string($type),
                'key'        => $db->escape_string($key),
                'title_ru'   => $db->escape_string((string)$mybb->get_input('title_ru')),
                'title_en'   => $db->escape_string((string)$mybb->get_input('title_en')),
                'short_ru'   => $db->escape_string((string)$mybb->get_input('short_ru')),
                'short_en'   => $db->escape_string((string)$mybb->get_input('short_en')),
                'body_ru'    => $db->escape_string((string)$mybb->get_input('body_ru')),
                'body_en'    => $db->escape_string((string)$mybb->get_input('body_en')),
                'tech_ru'    => $db->escape_string((string)$mybb->get_input('tech_ru')),
                'tech_en'    => $db->escape_string((string)$mybb->get_input('tech_en')),
                'meta_json'  => $db->escape_string(af_kb_normalize_json($metaJsonNormalized)),
                'icon_class' => $db->escape_string($entryIconClass),
                'icon_url'   => $db->escape_string($entryIconUrl),
                'bg_url'     => $db->escape_string($entryBgUrl),
                'active'     => (int)$mybb->get_input('active', MyBB::INPUT_INT) ? 1 : 0,
                'sortorder'  => (int)$mybb->get_input('sortorder', MyBB::INPUT_INT),
                'updated_at' => TIME_NOW,
            ];

            if ($entry) {
                $db->update_query('af_kb_entries', $data, 'id='.(int)$entry['id']);
                $entryId = (int)$entry['id'];
                $action = 'update';
            } else {
                $entryId = (int)$db->insert_query('af_kb_entries', $data);
                $action = 'create';
            }

            $db->delete_query('af_kb_blocks', 'entry_id=' . $entryId);
            foreach ($parsedBlocks as $block) {
                $db->insert_query('af_kb_blocks', [
                    'entry_id'   => $entryId,
                    'block_key'  => $db->escape_string($block['block_key']),
                    'title_ru'   => $db->escape_string($block['title_ru']),
                    'title_en'   => $db->escape_string($block['title_en']),
                    'content_ru' => $db->escape_string($block['content_ru']),
                    'content_en' => $db->escape_string($block['content_en']),
                    'data_json'  => $db->escape_string($block['data_json']),
                    'icon_class' => $db->escape_string($block['icon_class']),
                    'icon_url'   => $db->escape_string($block['icon_url']),
                    'active'     => (int)$block['active'],
                    'sortorder'  => (int)$block['sortorder'],
                ]);
            }

            $db->delete_query('af_kb_relations', "from_type='".$db->escape_string($type)."' AND from_key='".$db->escape_string($key)."'");
            foreach ($parsedRelations as $rel) {
                $db->insert_query('af_kb_relations', [
                    'from_type' => $db->escape_string($type),
                    'from_key'  => $db->escape_string($key),
                    'rel_type'  => $db->escape_string($rel['rel_type']),
                    'to_type'   => $db->escape_string($rel['to_type']),
                    'to_key'    => $db->escape_string($rel['to_key']),
                    'meta_json' => $db->escape_string($rel['meta_json']),
                    'sortorder' => (int)$rel['sortorder'],
                ]);
            }

            $db->insert_query('af_kb_log', [
                'uid'      => (int)$mybb->user['uid'],
                'action'   => $db->escape_string($action),
                'type'     => $db->escape_string($type),
                'key'      => $db->escape_string($key),
                'diff_json'=> $db->escape_string('{}'),
                'dateline' => TIME_NOW,
            ]);

            redirect('misc.php?action=kb&type='.urlencode($type).'&key='.urlencode($key), 'Saved');
        }
    }

    $entry = $entry ?: [
        'type'      => $type,
        'key'       => $key,
        'title_ru'  => '',
        'title_en'  => '',
        'short_ru'  => '',
        'short_en'  => '',
        'body_ru'   => '',
        'body_en'   => '',
        'tech_ru'   => '',
        'tech_en'   => '',
        'meta_json' => '{}',
        'icon_class' => '',
        'icon_url' => '',
        'bg_url' => '',
        'active'    => 1,
        'sortorder' => 0,
    ];

    $blocksRows = '';
    $blocks = [];
    if (!empty($entry['id'])) {
        $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($bq)) {
            $blocks[] = $row;
        }
    }

    if (!$blocks) {
        $blocks[] = [
            'block_key' => '',
            'title_ru' => '',
            'title_en' => '',
            'content_ru' => '',
            'content_en' => '',
        'data_json' => '',
        'active' => 1,
        'sortorder' => 0,
    ];
    }

    $blockIndex = 0;
    foreach ($blocks as $block) {
        $block_index = $blockIndex;
        $block_block_key = htmlspecialchars_uni($block['block_key']);
        $block_title_ru = htmlspecialchars_uni($block['title_ru']);
        $block_title_en = htmlspecialchars_uni($block['title_en']);
        $block_content_ru = htmlspecialchars_uni($block['content_ru']);
        $block_content_en = htmlspecialchars_uni($block['content_en']);
        $block_data_json = htmlspecialchars_uni($block['data_json'] ?? '');
        $block_icon_class = htmlspecialchars_uni($block['icon_class'] ?? '');
        $block_icon_url = htmlspecialchars_uni($block['icon_url'] ?? '');
        $block_active_checked = !empty($block['active']) ? 'checked="checked"' : '';
        $block_sortorder = (int)$block['sortorder'];
        eval("\$blocksRows .= \"" . af_kb_get_template('knowledgebase_blocks_edit_item') . "\";");
        $blockIndex++;
    }

    $relationsRows = '';
    $relations = [];
    if (!empty($entry['type']) && !empty($entry['key'])) {
        $rq = $db->simple_select(
            'af_kb_relations',
            '*',
            "from_type='".$db->escape_string($entry['type'])."' AND from_key='".$db->escape_string($entry['key'])."'",
            ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($rq)) {
            $relations[] = $row;
        }
    }

    if (!$relations) {
        $relations[] = [
            'rel_type' => '',
            'to_type' => '',
            'to_key' => '',
            'meta_json' => '',
            'sortorder' => 0,
        ];
    }

    $relIndex = 0;
    foreach ($relations as $rel) {
        $rel_index = $relIndex;
        $rel_type = htmlspecialchars_uni($rel['rel_type']);
        $rel_to_type = htmlspecialchars_uni($rel['to_type']);
        $rel_to_key = htmlspecialchars_uni($rel['to_key']);
        $rel_meta_json = htmlspecialchars_uni($rel['meta_json'] ?? '');
        $rel_sortorder = (int)$rel['sortorder'];
        eval("\$relationsRows .= \"" . af_kb_get_template('knowledgebase_rel_edit_item') . "\";");
        $relIndex++;
    }

    $kb_errors = '';
    if ($errors) {
        $items = '';
        foreach ($errors as $error) {
            $items .= '<li>'.htmlspecialchars_uni($error).'</li>';
        }
        $kb_errors = '<div class="af-kb-errors"><ul>'.$items.'</ul></div>';
    }

    $kb_page_title = htmlspecialchars_uni($entry['title_ru'] ?: $entry['title_en'] ?: ($entry['key'] ?: 'KB'));
    $kb_type_value = htmlspecialchars_uni($entry['type']);
    $kb_key_value = htmlspecialchars_uni($entry['key']);
    $kb_title_ru = htmlspecialchars_uni($entry['title_ru']);
    $kb_title_en = htmlspecialchars_uni($entry['title_en']);
    $kb_short_ru = htmlspecialchars_uni($entry['short_ru']);
    $kb_short_en = htmlspecialchars_uni($entry['short_en']);
    $kb_body_ru = htmlspecialchars_uni($entry['body_ru']);
    $kb_body_en = htmlspecialchars_uni($entry['body_en']);
    $kb_tech_ru = htmlspecialchars_uni($entry['tech_ru'] ?? '');
    $kb_tech_en = htmlspecialchars_uni($entry['tech_en'] ?? '');
    $kb_meta_json = htmlspecialchars_uni($entry['meta_json'] ?: '{}');
    $entryUi = af_kb_get_entry_ui($entry);
    $kb_icon_class = htmlspecialchars_uni($entryUi['icon_class'] ?? '');
    $kb_icon_url = htmlspecialchars_uni($entryUi['icon_url'] ?? '');
    $kb_background_url = htmlspecialchars_uni($entryUi['background_url'] ?? '');
    $kb_background_tab_url = htmlspecialchars_uni($entryUi['background_tab_url'] ?? '');
    $kb_active_checked = !empty($entry['active']) ? 'checked="checked"' : '';
    $kb_sortorder = (int)$entry['sortorder'];
    $kb_blocks_rows = $blocksRows;
    $kb_relations_rows = $relationsRows;
    $kb_blocks_index = $blockIndex;
    $kb_relations_index = $relIndex;

    $kb_delete_button = !empty($entry['id']) ? '<button type="submit" name="kb_delete" value="1" class="af-kb-btn af-kb-btn--delete af-kb-btn-delete">'.$lang->af_kb_delete.'</button>' : '';
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';
    $kb_tech_template_label = htmlspecialchars_uni($lang->af_kb_insert_template ?? 'Insert template');
    $kb_tech_template = htmlspecialchars_uni($lang->af_kb_tech_template_value ?? '[icon=URL_OR_CLASS] Short technical hint here (1–2 sentences).');
    $kb_page_bg = '';
    $kb_body_style = '';

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        if (!empty($entry['type'])) {
            $typeRow = $db->fetch_array(
                $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($entry['type'])."'", ['limit' => 1])
            );
            $typeTitle = $typeRow ? af_kb_pick_text($typeRow, 'title') : $entry['type'];
            add_breadcrumb($typeTitle ?: $entry['type'], 'misc.php?action=kb&type=' . urlencode($entry['type']));
        }
        if (!empty($entry['key'])) {
            $entryTitle = $entry['title_ru'] ?: $entry['title_en'] ?: $entry['key'];
            add_breadcrumb($entryTitle, 'misc.php?action=kb&type=' . urlencode($entry['type']) . '&key=' . urlencode($entry['key']));
        }
        $editLabel = !empty($entry['id'])
            ? ($lang->af_kb_edit ?? 'Edit')
            : ($lang->af_kb_create ?? 'Create');
        add_breadcrumb($editLabel, 'misc.php?action=kb_edit&type=' . urlencode($entry['type']) . '&key=' . urlencode($entry['key']));
    }

    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_edit') . "\";");
    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

function af_kb_handle_type_edit(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_manage_types()) {
        error_no_permission();
    }

    $type = trim((string)$mybb->get_input('type'));
    $typeRow = null;
    if ($type !== '') {
        $typeRow = $db->fetch_array(
            $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
        );
    }
    $isEditing = (bool)$typeRow;

    $errors = [];

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $type = trim((string)$mybb->get_input('type'));
        if ($type === '') {
            $errors[] = $lang->af_kb_type_required ?? 'Type is required.';
        }

        if ($typeRow && $type !== $typeRow['type']) {
            $errors[] = $lang->af_kb_type_locked ?? 'Type cannot be changed.';
        }

        $titleRu = trim((string)$mybb->get_input('title_ru'));
        $titleEn = trim((string)$mybb->get_input('title_en'));
        $descRu = trim((string)$mybb->get_input('description_ru'));
        $descEn = trim((string)$mybb->get_input('description_en'));
        $iconClass = af_kb_sanitize_icon_class((string)$mybb->get_input('icon_class'));
        $iconUrl = af_kb_sanitize_url((string)$mybb->get_input('icon_url'));
        $bgUrl = af_kb_sanitize_url((string)$mybb->get_input('bg_url'));
        $bgTabUrl = af_kb_sanitize_url((string)$mybb->get_input('bg_tab_url'));
        $sortorder = (int)$mybb->get_input('sortorder', MyBB::INPUT_INT);
        $active = (int)$mybb->get_input('active', MyBB::INPUT_INT) ? 1 : 0;

        if (!$errors) {
            $existingType = $db->fetch_array(
                $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
            );

            if ($existingType && !$typeRow) {
                $errors[] = $lang->af_kb_type_exists ?? 'Type already exists.';
            }
        }

        if (!$errors) {
            $data = [
                'type'           => $db->escape_string($type),
                'title_ru'       => $db->escape_string($titleRu),
                'title_en'       => $db->escape_string($titleEn),
                'description_ru' => $db->escape_string($descRu),
                'description_en' => $db->escape_string($descEn),
                'icon_class'     => $db->escape_string($iconClass),
                'icon_url'       => $db->escape_string($iconUrl),
                'bg_url'         => $db->escape_string($bgUrl),
                'bg_tab_url'     => $db->escape_string($bgTabUrl),
                'sortorder'      => $sortorder,
                'active'         => $active,
            ];

            if ($typeRow) {
                $db->update_query('af_kb_types', $data, 'id='.(int)$typeRow['id']);
            } else {
                $db->insert_query('af_kb_types', $data);
            }

            redirect('misc.php?action=kb&type='.urlencode($type), $lang->af_kb_type_saved ?? 'Category saved.');
        }
    }

    $typeRow = $typeRow ?: [
        'type'           => $type,
        'title_ru'       => '',
        'title_en'       => '',
        'description_ru' => '',
        'description_en' => '',
        'icon_class'     => '',
        'icon_url'       => '',
        'bg_url'         => '',
        'bg_tab_url'     => '',
        'sortorder'      => 0,
        'active'         => 1,
    ];

    $kb_errors = '';
    if ($errors) {
        $items = '';
        foreach ($errors as $error) {
            $items .= '<li>'.htmlspecialchars_uni($error).'</li>';
        }
        $kb_errors = '<div class="af-kb-errors"><ul>'.$items.'</ul></div>';
    }

    $kb_page_title = htmlspecialchars_uni($lang->af_kb_type_edit ?? 'Edit category');
    $kb_type_value = htmlspecialchars_uni($typeRow['type']);
    $kb_type_title_ru = htmlspecialchars_uni($typeRow['title_ru']);
    $kb_type_title_en = htmlspecialchars_uni($typeRow['title_en']);
    $kb_type_description_ru = htmlspecialchars_uni($typeRow['description_ru']);
    $kb_type_description_en = htmlspecialchars_uni($typeRow['description_en']);
    $kb_type_icon_class = htmlspecialchars_uni($typeRow['icon_class'] ?? '');
    $kb_type_icon_url = htmlspecialchars_uni($typeRow['icon_url'] ?? '');
    $kb_type_bg_url = htmlspecialchars_uni($typeRow['bg_url'] ?? '');
    $kb_type_bg_tab_url = htmlspecialchars_uni($typeRow['bg_tab_url'] ?? '');
    $kb_type_sortorder = (int)$typeRow['sortorder'];
    $kb_type_active_checked = !empty($typeRow['active']) ? 'checked="checked"' : '';
    $kb_type_readonly = $isEditing ? 'readonly="readonly"' : '';
    $kb_help_link = af_kb_can_edit()
        ? '<a class="af-kb-help-link" href="misc.php?action=kb_help" title="'.htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help').'"><i class="fa-regular fa-circle-question"></i></a>'
        : '';

    $cancelTarget = $typeRow['type'] !== '' ? 'misc.php?action=kb&type='.urlencode($typeRow['type']) : 'misc.php?action=kb';
    $kb_cancel_link = htmlspecialchars_uni($cancelTarget);

    $kb_type_delete_link = '';
    if (!empty($typeRow['type'])) {
        $confirm = htmlspecialchars_uni($lang->af_kb_type_delete_confirm ?? 'Delete category?');
        $kb_type_delete_link = '<a class="af-kb-btn af-kb-btn--delete af-kb-btn-delete" href="misc.php?action=kb_type_delete&type='.htmlspecialchars_uni($typeRow['type']).'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'" onclick="return confirm(\''.$confirm.'\');">'.htmlspecialchars_uni($lang->af_kb_type_delete ?? 'Delete category').'</a>';
    }
    $kb_page_bg = '';
    $kb_body_style = '';

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        $categoriesLabel = $lang->af_kb_categories_label ?? 'Categories';
        add_breadcrumb($categoriesLabel, 'misc.php?action=kb');
        $editLabel = $isEditing
            ? ($lang->af_kb_type_edit ?? 'Edit category')
            : ($lang->af_kb_type_create ?? 'Create category');
        add_breadcrumb($editLabel, 'misc.php?action=kb_type_edit&type=' . urlencode($typeRow['type']));
    }

    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_type_edit') . "\";");
    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

function af_kb_handle_type_delete(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_manage_types()) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $type = trim((string)$mybb->get_input('type'));
    if ($type === '') {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $typeRow = $db->fetch_array(
        $db->simple_select('af_kb_types', '*', "type='".$db->escape_string($type)."'", ['limit' => 1])
    );
    if (!$typeRow) {
        error($lang->af_kb_not_found ?? 'Not found');
    }

    $entryIds = [];
    $q = $db->simple_select('af_kb_entries', 'id', "type='".$db->escape_string($type)."'");
    while ($row = $db->fetch_array($q)) {
        $entryIds[] = (int)$row['id'];
    }

    if ($entryIds) {
        $db->delete_query('af_kb_blocks', 'entry_id IN ('.implode(',', $entryIds).')');
    }

    $db->delete_query('af_kb_relations', "from_type='".$db->escape_string($type)."' OR to_type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_entries', "type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_log', "type='".$db->escape_string($type)."'");
    $db->delete_query('af_kb_types', 'id='.(int)$typeRow['id']);

    redirect('misc.php?action=kb', $lang->af_kb_type_deleted ?? 'Category deleted.');
}

function af_kb_handle_help(): void
{
    global $lang, $headerinclude, $header, $footer, $templates;

    if (!af_kb_can_edit()) {
        error_no_permission();
    }

    $kb_page_title = htmlspecialchars_uni($lang->af_kb_help_title ?? 'KB help');
    $kb_page_bg = '';
    $kb_body_style = '';

    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', 'misc.php?action=kb');
        add_breadcrumb($lang->af_kb_help_title ?? 'KB help', 'misc.php?action=kb_help');
    }

    $af_kb_content = '';
    eval("\$af_kb_content = \"" . af_kb_get_template('knowledgebase_help') . "\";");
    eval("\$page = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($page);
    exit;
}

/* -------------------- JSON API -------------------- */

function af_kb_handle_json_get(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view()) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $type = trim((string)$mybb->get_input('type'));
    $key = trim((string)$mybb->get_input('key'));
    if ($type === '' || $key === '') {
        af_kb_render_json_error('Missing parameters', 400);
    }

    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    if (!af_kb_can_edit()) {
        $where .= ' AND active=1';
    }

    $entry = $db->fetch_array($db->simple_select('af_kb_entries', '*', $where, ['limit' => 1]));
    if (!$entry) {
        af_kb_render_json_error($lang->af_kb_not_found ?? 'Not found', 404);
    }

    $blocks = [];
    $bq = $db->simple_select('af_kb_blocks', '*', 'entry_id='.(int)$entry['id'], ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($bq)) {
        if (!$row['active'] && !af_kb_can_edit()) {
            continue;
        }
        $blocks[] = [
            'block_key' => $row['block_key'],
            'title'     => af_kb_pick_text($row, 'title'),
            'content'   => af_kb_pick_text($row, 'content'),
            'data_json' => $row['data_json'],
            'sortorder' => (int)$row['sortorder'],
        ];
    }

    $relations = [];
    $rq = $db->simple_select('af_kb_relations', '*', "from_type='".$db->escape_string($type)."' AND from_key='".$db->escape_string($key)."'", ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($rq)) {
        $relations[] = [
            'rel_type'  => $row['rel_type'],
            'to_type'   => $row['to_type'],
            'to_key'    => $row['to_key'],
            'meta_json' => $row['meta_json'],
            'sortorder' => (int)$row['sortorder'],
        ];
    }

    $entryUi = af_kb_get_entry_ui($entry);
    $payload = [
        'entry' => [
            'type'      => $entry['type'],
            'key'       => $entry['key'],
            'title'     => af_kb_pick_text($entry, 'title'),
            'short'     => af_kb_pick_text($entry, 'short'),
            'body'      => af_kb_pick_text($entry, 'body'),
            'meta_json' => $entry['meta_json'],
            'tech_hint' => af_kb_build_tech_hint(af_kb_pick_text($entry, 'tech')),
            'icon_url'  => $entryUi['icon_url'],
            'icon_class'=> $entryUi['icon_class'],
        ],
        'blocks' => $blocks,
        'relations' => $relations,
    ];

    af_kb_send_json($payload);
}

function af_kb_handle_json_list(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view() && (int)($mybb->user['uid'] ?? 0) === 0) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $type = trim((string)$mybb->get_input('type'));
    if ($type === '') {
        af_kb_render_json_error('Missing type', 400);
    }

    $query = trim((string)$mybb->get_input('q'));
    $where = "type='".$db->escape_string($type)."'";
    if (!af_kb_can_edit()) {
        $where .= ' AND active=1';
    }
    if ($query !== '') {
        $safeQuery = $db->escape_string($query);
        $where .= " AND (title_ru LIKE '%{$safeQuery}%' OR title_en LIKE '%{$safeQuery}%'"
            . " OR `key` LIKE '%{$safeQuery}%' OR tech_ru LIKE '%{$safeQuery}%' OR tech_en LIKE '%{$safeQuery}%')";
    }

    $items = [];
    $q = $db->simple_select('af_kb_entries', '*', $where, ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $entryUi = af_kb_get_entry_ui($row);
        $items[] = [
            'type'  => $row['type'],
            'key'   => $row['key'],
            'title' => af_kb_pick_text($row, 'title') ?: $row['key'],
            'tech' => af_kb_build_tech_hint(af_kb_pick_text($row, 'tech')),
            'icon_url' => $entryUi['icon_url'],
            'icon_class' => $entryUi['icon_class'],
        ];
    }

    af_kb_send_json(['success' => true, 'items' => $items]);
}

function af_kb_handle_json_types(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view() && (int)($mybb->user['uid'] ?? 0) === 0) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $where = af_kb_can_edit() ? '1=1' : 'active=1';
    $items = [];
    $q = $db->simple_select('af_kb_types', '*', $where, ['order_by' => 'sortorder, type', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $items[] = [
            'type' => $row['type'],
            'title' => af_kb_pick_text($row, 'title') ?: $row['type'],
            'icon_url' => $row['icon_url'],
            'icon_class' => $row['icon_class'],
            'background_tab_url' => $row['bg_tab_url'] ?? '',
        ];
    }

    af_kb_send_json(['success' => true, 'items' => $items]);
}

function af_kb_handle_json_children(): void
{
    global $mybb, $db, $lang;

    if (!af_kb_can_view()) {
        af_kb_render_json_error($lang->af_kb_no_access ?? 'No access', 403);
    }

    $fromType = trim((string)$mybb->get_input('from_type'));
    $fromKey = trim((string)$mybb->get_input('from_key'));
    $relType = trim((string)$mybb->get_input('rel_type'));

    if ($fromType === '' || $fromKey === '' || $relType === '') {
        af_kb_render_json_error('Missing parameters', 400);
    }

    $items = [];
    $rq = $db->simple_select(
        'af_kb_relations',
        '*',
        "from_type='".$db->escape_string($fromType)."' AND from_key='".$db->escape_string($fromKey)."' AND rel_type='".$db->escape_string($relType)."'",
        ['order_by' => 'sortorder, id', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($rq)) {
        $title = $row['to_key'];
        $target = $db->fetch_array(
            $db->simple_select(
                'af_kb_entries',
                '*',
                "type='".$db->escape_string($row['to_type'])."' AND `key`='".$db->escape_string($row['to_key'])."'",
                ['limit' => 1]
            )
        );
        if ($target) {
            $title = af_kb_pick_text($target, 'title');
            if ($title === '') {
                $title = $target['key'];
            }
        }
        $items[] = [
            'to_type' => $row['to_type'],
            'to_key'  => $row['to_key'],
            'title'   => $title,
        ];
    }

    af_kb_send_json(['items' => $items]);
}
