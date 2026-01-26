<?php
/**
 * AF Addon: AdvancedFontAwesome
 * MyBB 1.8.39, PHP 8.0+
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AFO_ID', 'advancedfontawesome');
define('AF_AFO_VER', '1.0.0');
define('AF_AFO_BASE', AF_ADDONS . AF_AFO_ID . '/');
define('AF_AFO_TPL_FILE', AF_AFO_BASE . 'templates/advancedfontawesome.html');

define('AF_AFO_MARK_DONE', '<!--af_advancedfontawesome_done-->');

function af_afo_load_templates(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];

    if (!is_file(AF_AFO_TPL_FILE)) {
        return $cache;
    }

    $raw = @file_get_contents(AF_AFO_TPL_FILE);
    if ($raw === false || trim($raw) === '') {
        return $cache;
    }

    $pattern = '~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->\s*(.*?)\s*(?=(<!--\s*TEMPLATE:\s*[a-zA-Z0-9_\-]+\s*-->|$))~si';
    if (!preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER)) {
        return $cache;
    }

    foreach ($matches as $row) {
        $name = trim((string)$row[1]);
        $tpl = trim((string)$row[2]);
        if ($name !== '' && $tpl !== '') {
            $cache[$name] = $tpl;
        }
    }

    return $cache;
}

function af_afo_get_template(string $name): string
{
    $templates = af_afo_load_templates();
    return $templates[$name] ?? '';
}

function af_afo_render_template(string $name, array $vars = []): string
{
    $tpl = af_afo_get_template($name);
    if ($tpl === '') {
        return '';
    }

    foreach ($vars as $key => $value) {
        $tpl = str_replace('{$' . $key . '}', (string)$value, $tpl);
    }

    return $tpl;
}

function af_advancedfontawesome_install(): bool
{
    global $db;

    if ($db->table_exists('forums') && !$db->field_exists('af_fa_icon', 'forums')) {
        $db->add_column('forums', 'af_fa_icon', "varchar(255) NOT NULL DEFAULT ''");
    }

    af_afo_ensure_mycode();
    af_afo_install_headerinclude();
    af_afo_ensure_thread_status_setting();


    // --- ACP Bridge plugin (чтобы поле работало в forum-management) ---
    af_afo_install_acp_bridge_plugin();

    return true;
}

function af_advancedfontawesome_uninstall(): bool
{
    global $db;

    if ($db->table_exists('forums') && $db->field_exists('af_fa_icon', 'forums')) {
        $db->drop_column('forums', 'af_fa_icon');
    }

    $db->delete_query('mycode', "title='AF Font Awesome'");

    // --- убираем ACP bridge plugin (только если это наш файл по сигнатуре) ---
    af_afo_uninstall_acp_bridge_plugin();

    return true;
}

function af_advancedfontawesome_activate(): void
{
    // Языки генерятся ядром AF при enable; тут аддону достаточно безопасного $lang->load().
}

function af_advancedfontawesome_deactivate(): void
{
    af_afo_remove_headerinclude();
}

function af_advancedfontawesome_init(): void
{
    global $plugins;

    // фронт
    $plugins->add_hook('pre_output_page', 'af_advancedfontawesome_pre_output');

    // ACP: гарантированно цепляемся к шапке любой админ-страницы
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        $plugins->add_hook('admin_page_output_header', 'af_afo_admin_page_output_header');
        // Сохранение оставляем — но оно будет работать только если файл реально загружен в ACP (а мы его теперь грузим через header-hook)
        $plugins->add_hook('admin_forum_management_add_start', 'af_afo_admin_forum_save_add');
        $plugins->add_hook('admin_forum_management_edit_commit', 'af_afo_admin_forum_save_edit');
    }
}

/**
 * Безопасная загрузка языкового файла аддона.
 * Важно: не падать, если файл ещё не создан/не синкнут ядром.
 */
function af_afo_lang_load(bool $admin = false): void
{
    global $lang;

    if (!isset($lang) || !is_object($lang)) {
        return;
    }

    // Уже загружено — выходим
    if (isset($lang->af_advancedfontawesome_name) || isset($lang->af_advancedfontawesome_group)) {
        return;
    }

    $file = 'advancedfunctionality_' . AF_AFO_ID;

    $base = rtrim(MYBB_ROOT, '/\\') . '/inc/languages/' . $lang->language . '/';
    $path = $base . ($admin ? 'admin/' : '') . $file . '.lang.php';

    if (@file_exists($path)) {
        $lang->load($file, $admin);
        return;
    }

    $altPath = $base . ($admin ? '' : 'admin/') . $file . '.lang.php';
    if (@file_exists($altPath)) {
        $lang->load($file, !$admin);
    }
}

function af_afo_install_headerinclude(): void
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    $insert = "<!-- af_advancedfontawesome_start -->\n"
        . '<link rel="stylesheet" type="text/css" href="{$mybb->settings[\'bburl\']}/inc/plugins/advancedfunctionality/addons/advancedfontawesome/assets/font-awesome-6/css/all.min.css" />' . "\n"
        . "<!-- af_advancedfontawesome_end -->\n"
        . '{$stylesheets}';

    // убираем старую вставку (если была)
    find_replace_templatesets('headerinclude', '#\s*<!-- af_advancedfontawesome_start -->.*?<!-- af_advancedfontawesome_end -->\s*#is', '');
    // вставляем перед {$stylesheets}
    find_replace_templatesets('headerinclude', '#\{\$stylesheets\}#i', $insert);
}


function af_afo_remove_headerinclude(): void
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
    find_replace_templatesets('headerinclude', '#\s*<!-- af_advancedfontawesome_start -->.*?<!-- af_advancedfontawesome_end -->\s*#is', '');
}

function af_afo_ensure_mycode(): void
{
    global $db, $lang;

    af_afo_lang_load(defined('IN_ADMINCP') && IN_ADMINCP);

    $hasAllowHtml     = $db->field_exists('allowhtml', 'mycode');
    $hasAllowMyCode   = $db->field_exists('allowmycode', 'mycode');
    $hasAllowSmilies  = $db->field_exists('allowsmilies', 'mycode');
    $hasAllowImgCode  = $db->field_exists('allowimgcode', 'mycode');
    $hasAllowVideo    = $db->field_exists('allowvideocode', 'mycode');

    $title = 'AF Font Awesome';

    $row = [
        'title'       => $title,
        'description' => $lang->af_advancedfontawesome_description ?? 'Font Awesome tag.',
        'regex'       => '\\[fa\\]([a-z0-9\\- ]+)\\[/fa\\]',
        'replacement' => '<i class="$1" aria-hidden="true"></i>',
        'active'      => 1,
        'parseorder'  => 52,
    ];

    if ($hasAllowHtml)    { $row['allowhtml'] = 0; }
    if ($hasAllowMyCode)  { $row['allowmycode'] = 1; }
    if ($hasAllowSmilies) { $row['allowsmilies'] = 1; }
    if ($hasAllowImgCode) { $row['allowimgcode'] = 0; }
    if ($hasAllowVideo)   { $row['allowvideocode'] = 0; }

    $existing = $db->fetch_array(
        $db->simple_select('mycode', 'cid', "title='" . $db->escape_string($title) . "'")
    );

    $data = [];
    foreach ($row as $k => $v) {
        $data[$k] = is_int($v) ? $v : $db->escape_string($v);
    }

    if ($existing) {
        $db->update_query('mycode', $data, 'cid=' . (int)$existing['cid']);
    } else {
        $db->insert_query('mycode', $data);
    }
}

function af_afo_ensure_thread_status_setting(): void
{
    global $db;

    // уже есть — ок
    $exists = $db->fetch_array(
        $db->simple_select('settings', 'sid', "name='af_advancedfontawesome_thread_status_map'", ['limit' => 1])
    );
    if ($exists) {
        return;
    }

    // ставим дефолт (пусто) — ты выберешь в ACP
    $insert = [
        'name' => 'af_advancedfontawesome_thread_status_map',
        'title' => 'AF AdvancedFontAwesome: Thread status icons (JSON)',
        'description' => 'JSON map for thread_status icons (keys: newthread,newhotthread,hotthread,folder,dot_folder,lockfolder).',
        'optionscode' => 'textarea',
        'value' => '{}',
        'disporder' => 0,
        'gid' => 1, // можно в "Board Settings" (чтоб не плодить группы). если хочешь отдельную группу — сделаем.
        'isdefault' => 0
    ];

    $db->insert_query('settings', $insert);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedfontawesome_pre_output(string &$page = ''): void
{
    global $mybb;

    if (!af_afo_is_frontend()) {
        return;
    }

    if (empty($mybb->settings['af_advancedfontawesome_enabled'])) {
        return;
    }

    if (strpos($page, AF_AFO_MARK_DONE) !== false) {
        return;
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets';

    $cssTag = '<link rel="stylesheet" type="text/css" href="' . $assetsBase . '/advancedfontawesome.css?ver=' . AF_AFO_VER . '" />';
    if (stripos($page, '</head>') !== false && strpos($page, 'advancedfontawesome.css') === false) {
        $page = str_ireplace('</head>', $cssTag . '</head>', $page);
    }

    $needsEditor = af_afo_page_has_editor($page);
    $needsForumIcons = af_afo_page_has_forum_icons($page);

    // thread_status встречается на forumdisplay в списке тем
    $needsThreadStatusIcons = (stripos($page, 'thread_status') !== false);

    if ($needsEditor || $needsForumIcons || $needsThreadStatusIcons) {
        $iconMap = $needsForumIcons ? af_afo_collect_forum_icons() : [];
        $tsMap   = $needsThreadStatusIcons ? af_afo_collect_thread_status_icons() : [];

        $cfg = [
            'cssUrl' => $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets/font-awesome-6/css/all.min.css',
            'icons'         => $iconMap,
            'threadStatus'  => $tsMap,
            'defaultStyle'  => 'fa-solid',
        ];


        $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cfgTag = '<script>window.afAdvancedFontAwesomeConfig=' . $cfgJson . ';</script>';
        $jsTag  = '<script src="' . $assetsBase . '/advancedfontawesome.js?ver=' . AF_AFO_VER . '"></script>';

        $inserted = false;

        if ($needsEditor && strpos($page, 'advancedfontawesome.js') === false) {
            $pattern = '~(<script[^>]+bbcodes_sceditor\.js[^>]*></script>)~i';
            if (preg_match($pattern, $page)) {
                $page = preg_replace($pattern, '$1' . $cfgTag . $jsTag, $page, 1);
                $inserted = true;
            }
        }

        if (!$inserted && strpos($page, 'advancedfontawesome.js') === false && stripos($page, '</head>') !== false) {
            $page = str_ireplace('</head>', $cfgTag . $jsTag . '</head>', $page);
            $inserted = true;
        }

        if (!$inserted && strpos($page, 'advancedfontawesome.js') === false && stripos($page, '</body>') !== false) {
            $page = str_ireplace('</body>', $cfgTag . $jsTag . '</body>', $page);
        }
    }

    $page .= "\n" . AF_AFO_MARK_DONE;
}

function af_afo_is_frontend(): bool
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

function af_afo_page_has_editor(string $page): bool
{
    if (stripos($page, 'bbcodes_sceditor.js') !== false) {
        return true;
    }
    if (stripos($page, 'sceditor') !== false && stripos($page, 'toolbar') !== false) {
        return true;
    }
    return false;
}

function af_afo_page_has_forum_icons(string $page): bool
{
    if (stripos($page, 'forum_status') !== false) {
        return true;
    }
    if (stripos($page, 'subforumicon') !== false) {
        return true;
    }
    return false;
}

function af_afo_collect_forum_icons(): array
{
    global $db;

    if (!$db->table_exists('forums') || !$db->field_exists('af_fa_icon', 'forums')) {
        return [];
    }

    $map = [];
    $query = $db->simple_select('forums', 'fid,af_fa_icon', "af_fa_icon <> ''");
    while ($row = $db->fetch_array($query)) {
        $fid = (int)($row['fid'] ?? 0);
        $icon = af_afo_normalize_icon((string)($row['af_fa_icon'] ?? ''));
        if ($fid > 0 && $icon !== '') {
            $map[(string)$fid] = $icon;
        }
    }

    return $map;
}

function af_afo_collect_thread_status_icons(): array
{
    global $mybb;

    $raw = (string)($mybb->settings['af_advancedfontawesome_thread_status_map'] ?? '');
    $raw = trim($raw);

    if ($raw === '' || $raw === '{}' || $raw === '[]') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    // whitelist ключей, которые реально используются в MyBB thread_status
    $allowed = [
        'newthread' => 1,
        'newhotthread' => 1,
        'hotthread' => 1,
        'folder' => 1,
        'dot_folder' => 1,
        'lockfolder' => 1,
    ];

    $out = [];
    foreach ($data as $key => $icon) {
        $key = (string)$key;
        if (!isset($allowed[$key])) {
            continue;
        }
        $norm = af_afo_normalize_icon((string)$icon);
        if ($norm !== '') {
            $out[$key] = $norm;
        }
    }

    return $out;
}


function af_afo_normalize_icon(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $raw);
    $clean = [];

    foreach ($parts as $part) {
        $part = strtolower(trim((string)$part));
        if ($part === '') {
            continue;
        }
        if (preg_match('/^fa[a-z0-9\-]*$/', $part)) {
            $clean[] = $part;
        }
    }

    if (!$clean) {
        $raw2 = preg_replace('/[^a-z0-9\-]/', '', strtolower($raw));
        if ($raw2 === '') {
            return '';
        }
        return 'fa-solid fa-' . $raw2;
    }

    $styleClasses = [
        'fa-solid',
        'fa-regular',
        'fa-brands',
        'fa-light',
        'fa-thin',
        'fa-duotone',
    ];

    $hasStyle = false;
    $hasIcon = false;

    foreach ($clean as $part) {
        if (in_array($part, $styleClasses, true)) {
            $hasStyle = true;
            continue;
        }
        if (strpos($part, 'fa-') === 0) {
            $hasIcon = true;
        }
    }

    if (!$hasStyle) {
        $clean[] = 'fa-solid';
    }

    if (!$hasIcon) {
        return implode(' ', array_values(array_unique($clean)));
    }

    return implode(' ', array_values(array_unique($clean)));
}

/**
 * ACP: подключаем ассеты на страницах add/edit форумов.
 * Тут же подключаем Font Awesome CSS, иначе превью/иконки в пикере “невидимы”.
 */
function af_afo_admin_forum_assets(): void
{
    global $mybb, $page, $lang, $forum_data, $db;

    af_afo_lang_load(true);

    if (empty($mybb->settings['af_advancedfontawesome_enabled'])) {
        return;
    }

    // ВАЖНО: ACP живёт в /admin/, поэтому используем относительные пути
    // /admin/ -> ../inc/plugins/...
    $assetsBaseRel = '../inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets';
    $faCssRel      = $assetsBaseRel . '/font-awesome-6/css/all.min.css';

    // --- текущая иконка: forum_data или из БД по fid ---
    $icon = '';
    if (is_array($forum_data) && isset($forum_data['af_fa_icon'])) {
        $icon = (string)$forum_data['af_fa_icon'];
    } else {
        $fid = (int)$mybb->get_input('fid');
        if ($fid > 0 && $db && $db->table_exists('forums') && $db->field_exists('af_fa_icon', 'forums')) {
            $row = $db->fetch_array($db->simple_select('forums', 'af_fa_icon', "fid='{$fid}'", ['limit' => 1]));
            if ($row && isset($row['af_fa_icon'])) {
                $icon = (string)$row['af_fa_icon'];
            }
        }
    }

    $icon = af_afo_normalize_icon($icon);

    $cfg = [
        // fetch() в admin.js будет читать CSS по относительному пути
        'cssUrl'            => $faCssRel,
        'icon'              => $icon,
        'label'             => $lang->af_advancedfontawesome_icon_label ?? 'Иконка',
        'description'       => $lang->af_advancedfontawesome_icon_desc ?? 'Выбери иконку или вставь классы Font Awesome (пример: fa-solid fa-star).',
        'searchPlaceholder' => $lang->af_advancedfontawesome_icon_search ?? 'Поиск иконок...',
    ];

    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // 1) Font Awesome CSS (иначе <i class="fa-..."> пустой)
    $page->extra_header .= "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$faCssRel}?ver=" . AF_AFO_VER . "\" />";

    // 2) Наши админские стили/скрипты
    $page->extra_header .= "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$assetsBaseRel}/advancedfontawesome-admin.css?ver=" . AF_AFO_VER . "\" />";
    $page->extra_header .= "\n<script>window.afAdvancedFontAwesomeAdminConfig={$cfgJson};</script>";
    $page->extra_header .= "\n<script src=\"{$assetsBaseRel}/advancedfontawesome-admin.js?ver=" . AF_AFO_VER . "\"></script>";
}

function af_afo_admin_page_output_header(): void
{
    global $mybb;

    // Мы хотим только forum-management add/edit
    $module = (string)$mybb->get_input('module');
    if ($module !== 'forum-management') {
        return;
    }

    $action = (string)$mybb->get_input('action');
    if ($action !== 'edit' && $action !== 'add') {
        return;
    }

    // Подключаем ассеты (CSS/JS) и конфиг
    af_afo_admin_forum_assets();
}

function af_afo_admin_forum_save_add(): void
{
    global $db, $mybb, $insert_array;

    if (empty($mybb->settings['af_advancedfontawesome_enabled'])) {
        return;
    }

    if (!is_array($insert_array)) {
        return;
    }

    $icon = af_afo_normalize_icon((string)$mybb->get_input('af_fa_icon'));
    $insert_array['af_fa_icon'] = $db->escape_string($icon);
}

function af_afo_admin_forum_save_edit(): void
{
    global $db, $mybb, $fid;

    if (empty($mybb->settings['af_advancedfontawesome_enabled'])) {
        return;
    }

    $fid = (int)$fid;
    if ($fid <= 0) {
        return;
    }

    $icon = af_afo_normalize_icon((string)$mybb->get_input('af_fa_icon'));
    $db->update_query('forums', ['af_fa_icon' => $db->escape_string($icon)], "fid='{$fid}'");
}
function af_afo_install_acp_bridge_plugin(): void
{
    $path = MYBB_ROOT . 'inc/plugins/af_advancedfontawesome_bridge.php';

    // Если файл уже есть — не трогаем (вдруг пользователь правил сам)
    if (@file_exists($path)) {
        return;
    }

    $sig = 'AF_AFO_BRIDGE_PLUGIN_v1';

    $code = <<<PHP
<?php
/**
 * {$sig}
 * ACP bridge for AF Addon: AdvancedFontAwesome
 * Loads addon on ANY ACP pages (forum-management) to inject icon field + save it.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

// если AF_ADDONS не определён (в ACP так бывает), определим мягко — нам нужно лишь пройти guard в аддоне
if (!defined('AF_ADDONS')) { define('AF_ADDONS', 1); }

function af_advancedfontawesome_bridge_info()
{
    return [
        'name' => 'AF AdvancedFontAwesome Bridge',
        'description' => 'Bridge plugin to enable AdvancedFontAwesome on ACP forum-management pages.',
        'website' => '',
        'author' => 'AF',
        'authorsite' => '',
        'version' => '1.0.0',
        'compatibility' => '18*'
    ];
}

function af_advancedfontawesome_bridge_activate() {}
function af_advancedfontawesome_bridge_deactivate() {}

function af_advancedfontawesome_bridge_load_addon(): void
{
    static \$loaded = false;
    if (\$loaded) return;

    \$file = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php';
    if (@file_exists(\$file)) {
        require_once \$file;
        \$loaded = true;
    }
}

function af_advancedfontawesome_bridge_init(): void
{
    global \$plugins;

    // Важно: эти хуки должны быть зарегистрированы именно из обычного MyBB-плагина, который грузится в ACP всегда.
    \$plugins->add_hook('admin_page_output_header', 'af_advancedfontawesome_bridge_admin_header');
    \$plugins->add_hook('admin_forum_management_add_start', 'af_advancedfontawesome_bridge_save_add');
    \$plugins->add_hook('admin_forum_management_edit_commit', 'af_advancedfontawesome_bridge_save_edit');
}

function af_advancedfontawesome_bridge_admin_header(): void
{
    global \$mybb;

    af_advancedfontawesome_bridge_load_addon();

    if (!function_exists('af_afo_admin_page_output_header')) {
        return;
    }

    // фильтруем только forum-management add/edit
    \$module = (string)\$mybb->get_input('module');
    if (\$module !== 'forum-management') return;

    \$action = (string)\$mybb->get_input('action');
    if (\$action !== 'edit' && \$action !== 'add') return;

    af_afo_admin_page_output_header();
}

function af_advancedfontawesome_bridge_save_add(): void
{
    af_advancedfontawesome_bridge_load_addon();
    if (function_exists('af_afo_admin_forum_save_add')) {
        af_afo_admin_forum_save_add();
    }
}

function af_advancedfontawesome_bridge_save_edit(): void
{
    af_advancedfontawesome_bridge_load_addon();
    if (function_exists('af_afo_admin_forum_save_edit')) {
        af_afo_admin_forum_save_edit();
    }
}
PHP;

    @file_put_contents($path, $code);
}

function af_afo_uninstall_acp_bridge_plugin(): void
{
    $path = MYBB_ROOT . 'inc/plugins/af_advancedfontawesome_bridge.php';
    if (!@file_exists($path)) {
        return;
    }

    $txt = @file_get_contents($path);
    if ($txt === false) {
        return;
    }

    // удаляем только если это наш файл
    if (strpos($txt, 'AF_AFO_BRIDGE_PLUGIN_v1') !== false) {
        @unlink($path);
    }
}
