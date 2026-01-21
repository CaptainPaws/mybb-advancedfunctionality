<?php
/**
 * AF Addon: AdvancedFontAwesome
 * MyBB 1.8.39, PHP 8.0+
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AFO_ID', 'advancedfontawesome');
define('AF_AFO_VER', '1.0.0');

define('AF_AFO_MARK_DONE', '<!--af_advancedfontawesome_done-->');

function af_advancedfontawesome_install(): bool
{
    global $db;

    if ($db->table_exists('forums') && !$db->field_exists('af_fa_icon', 'forums')) {
        $db->add_column('forums', 'af_fa_icon', "varchar(255) NOT NULL DEFAULT ''");
    }

    af_afo_ensure_mycode();
    af_afo_install_headerinclude();

    return true;
}

function af_advancedfontawesome_uninstall(): bool
{
    global $db;

    if ($db->table_exists('forums') && $db->field_exists('af_fa_icon', 'forums')) {
        $db->drop_column('forums', 'af_fa_icon');
    }

    $db->delete_query('mycode', "title='AF Font Awesome'");

    return true;
}

function af_advancedfontawesome_deactivate(): void
{
    af_afo_remove_headerinclude();
}

function af_advancedfontawesome_init(): void
{
    global $plugins;

    $plugins->add_hook('pre_output_page', 'af_advancedfontawesome_pre_output');

    if (defined('IN_ADMINCP')) {
        $plugins->add_hook('admin_forum_management_add', 'af_afo_admin_forum_assets');
        $plugins->add_hook('admin_forum_management_edit', 'af_afo_admin_forum_assets');
        $plugins->add_hook('admin_forum_management_add_start', 'af_afo_admin_forum_save_add');
        $plugins->add_hook('admin_forum_management_edit_commit', 'af_afo_admin_forum_save_edit');
    }
}

function af_afo_install_headerinclude(): void
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    $insert = "<!-- af_advancedfontawesome_start -->\n"
        . '<link rel="stylesheet" type="text/css" href="{$mybb->settings[\'bburl\']}/inc/plugins/advancedfunctionality/addons/advancedfontawesome/assets/font-awesome-6/css/all.css" />' . "\n"
        . '<link rel="stylesheet" type="text/css" href="{$mybb->settings[\'bburl\']}/inc/plugins/advancedfunctionality/addons/advancedfontawesome/assets/font-awesome-6/css/all.min.css" />' . "\n"
        . "<!-- af_advancedfontawesome_end -->\n"
        . '{$stylesheets}';

    find_replace_templatesets('headerinclude', '#\s*<!-- af_advancedfontawesome_start -->.*?<!-- af_advancedfontawesome_end -->\s*#is', '');
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

    if (!isset($lang->af_advancedfontawesome_name)) {
        $lang->load('advancedfunctionality_' . AF_AFO_ID);
    }

    $hasAllowHtml     = $db->field_exists('allowhtml', 'mycode');
    $hasAllowMyCode   = $db->field_exists('allowmycode', 'mycode');
    $hasAllowSmilies  = $db->field_exists('allowsmilies', 'mycode');
    $hasAllowImgCode  = $db->field_exists('allowimgcode', 'mycode');
    $hasAllowVideo    = $db->field_exists('allowvideocode', 'mycode');

    $row = [
        'title'       => 'AF Font Awesome',
        'description' => $lang->af_advancedfontawesome_description ?? 'Font Awesome tag.',
        'regex'       => '\\[fa\\]([a-z0-9\- ]+)\\[/fa\\]',
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
        $db->simple_select('mycode', 'cid', "title='{$row['title']}'")
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

    if ($needsEditor || $needsForumIcons) {
        $iconMap = $needsForumIcons ? af_afo_collect_forum_icons() : [];
        $cfg = [
            'cssUrl' => $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets/font-awesome-6/css/all.css',
            'icons'  => $iconMap,
            'defaultStyle' => 'fa-solid',
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

function af_afo_normalize_icon(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $raw);
    $clean = [];

    foreach ($parts as $part) {
        $part = strtolower(trim($part));
        if ($part === '') {
            continue;
        }
        if (preg_match('/^fa[a-z0-9\-]*$/', $part)) {
            $clean[] = $part;
        }
    }

    if (!$clean) {
        $raw = preg_replace('/[^a-z0-9\-]/', '', $raw);
        if ($raw === '') {
            return '';
        }
        return 'fa-solid fa-' . $raw;
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
        return implode(' ', array_unique($clean));
    }

    return implode(' ', array_unique($clean));
}

function af_afo_admin_forum_assets(): void
{
    global $mybb, $page, $lang, $forum_data;

    if (empty($mybb->settings['af_advancedfontawesome_enabled'])) {
        return;
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets';

    $icon = '';
    if (isset($forum_data['af_fa_icon'])) {
        $icon = (string)$forum_data['af_fa_icon'];
    }
    $icon = af_afo_normalize_icon($icon);

    $cfg = [
        'cssUrl' => $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets/font-awesome-6/css/all.css',
        'icon' => $icon,
        'label' => $lang->af_advancedfontawesome_icon_label ?? 'Icon',
        'description' => $lang->af_advancedfontawesome_icon_desc ?? '',
        'searchPlaceholder' => $lang->af_advancedfontawesome_icon_search ?? 'Search icons...',
    ];

    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $page->extra_header .= "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$assetsBase}/advancedfontawesome-admin.css?ver=" . AF_AFO_VER . "\" />";
    $page->extra_header .= "\n<script>window.afAdvancedFontAwesomeAdminConfig={$cfgJson};</script>";
    $page->extra_header .= "\n<script src=\"{$assetsBase}/advancedfontawesome-admin.js?ver=" . AF_AFO_VER . "\"></script>";
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
