<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ADVRWD_ID', 'advresponsivelayout');
define('AF_ADVRWD_BASE', AF_ADDONS . AF_ADVRWD_ID . '/');
define('AF_ADVRWD_ASSETS', AF_ADVRWD_BASE . 'assets/');
define('AF_ADVRWD_MARK', '<!-- af_advresponsivelayout_assets -->');

af_advresponsivelayout_init();

function af_advresponsivelayout_init(): void
{
    global $plugins;
    $plugins->add_hook('pre_output_page', 'af_advresponsivelayout_pre_output', 12);
}

function af_advresponsivelayout_install(): void
{
    global $lang;
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang(AF_ADVRWD_ID);
    }

    $gid = af_advresponsivelayout_ensure_setting_group(
        $lang->af_advresponsivelayout_group ?? 'AF: Adaptive Responsive Layout',
        $lang->af_advresponsivelayout_group_desc ?? 'Settings for mobile responsive layout system.'
    );

    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enabled', 'Enable mobile responsive system', 'Enable/disable AF mobile responsive layout system.', 'yesno', '1', 1, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_assets_blacklist', 'Assets blacklist', "Disable responsive assets on listed pages (one per line).\nExamples:\nindex.php\nmember.php?action=profile", 'textarea', "modcp.php\nmodcp.php?action=*\nadmin/index.php", 2, $gid);

    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_sticky_main_nav', 'Enable sticky main nav', 'Keep the primary forum menu visible/sticky on mobile screens.', 'yesno', '1', 3, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_right_burger_menu', 'Enable right burger extra menu', 'Show right-side burger for extra/top/user menus while keeping main nav visible.', 'yesno', '1', 4, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_table_wrap', 'Enable table wrapping', 'Auto-wrap wide content tables for horizontal scrolling.', 'yesno', '1', 5, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_media_fixes', 'Enable media fixes', 'Responsive constraints for images/video/iframes and long content.', 'yesno', '1', 6, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_modal_fixes', 'Enable modal fixes', 'Responsive behavior for modal/surface containers.', 'yesno', '1', 7, $gid);

    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_compact_postbit_mobile', 'Enable compact showthread/postbit mobile layout', 'Apply mobile postbit restructuring for showthread and APUI postbits.', 'yesno', '1', 8, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_compact_forumdisplay_mobile', 'Enable compact index/forumdisplay mobile layout', 'Apply stacked mobile layout for forum and thread lists.', 'yesno', '1', 9, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_compact_profile_mobile', 'Enable compact profile/usercp mobile layout', 'Apply mobile layout for profile hero, tabs, side panels and usercp blocks.', 'yesno', '1', 10, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_enable_plugin_patches', 'Enable plugin-aware mobile patches', 'Responsive layout patches for AF plugins (Inventory/Shop/KB/CharacterSheets).', 'yesno', '1', 11, $gid);

    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_mobile_header_breakpoint', 'Mobile header breakpoint (px)', 'Breakpoint where mobile header + right burger behavior becomes active.', 'numeric', '768', 12, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_breakpoint_phone', 'Phone breakpoint (px)', 'Phone breakpoint used by responsive layout system.', 'numeric', '768', 13, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_breakpoint_tablet', 'Tablet breakpoint (px)', 'Tablet breakpoint used by responsive layout system.', 'numeric', '1024', 14, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_breakpoint_desktop', 'Desktop breakpoint (px)', 'Desktop breakpoint used by responsive layout system.', 'numeric', '1200', 15, $gid);

    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_page_pad_mobile', 'Mobile page padding', 'Responsive page padding for narrow screens (CSS value).', 'text', '8px', 16, $gid);
    af_advresponsivelayout_ensure_setting('af_advresponsivelayout_page_pad_desktop', 'Desktop page padding', 'Responsive page padding for desktop screens (CSS value).', 'text', '20px', 17, $gid);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advresponsivelayout_activate(): void
{
    af_advresponsivelayout_install();
}

function af_advresponsivelayout_uninstall(): void
{
    global $db;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advresponsivelayout'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        $db->delete_query('settings', 'gid=' . $gid);
        $db->delete_query('settinggroups', 'gid=' . $gid);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advresponsivelayout_is_installed(): bool
{
    global $db;
    return (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advresponsivelayout'", ['limit' => 1]), 'gid') > 0;
}

function af_advresponsivelayout_ensure_setting_group(string $title, string $description): int
{
    global $db;
    $name = 'af_advresponsivelayout';
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'gid');
    if ($gid > 0) {
        return $gid;
    }

    return (int)$db->insert_query('settinggroups', [
        'name' => $name,
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($description),
        'disporder' => 66,
        'isdefault' => 0,
    ]);
}

function af_advresponsivelayout_ensure_setting(string $name, string $title, string $desc, string $type, string $value, int $order, int $gid): void
{
    global $db;
    $exists = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]), 'sid');
    $row = [
        'name' => $db->escape_string($name),
        'title' => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value' => $db->escape_string($value),
        'disporder' => $order,
        'gid' => $gid,
    ];

    if ($exists > 0) {
        $db->update_query('settings', $row, 'sid=' . $exists);
        return;
    }

    $db->insert_query('settings', $row);
}

function af_advresponsivelayout_normalize_script_name(string $raw): string
{
    $raw = trim(str_replace('\\', '/', $raw));
    if ($raw === '') {
        return '';
    }

    $path = parse_url($raw, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $raw;
    }

    $script = strtolower((string)basename($path));
    if ($script === '' || $script === '.' || $script === '/') {
        return '';
    }

    if (strpos($script, '.php') === false) {
        $script .= '.php';
    }

    return $script;
}

function af_advresponsivelayout_current_script_name(): string
{
    if (defined('THIS_SCRIPT')) {
        $script = af_advresponsivelayout_normalize_script_name((string)THIS_SCRIPT);
        if ($script !== '') {
            return $script;
        }
    }

    foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
        $script = af_advresponsivelayout_normalize_script_name((string)($_SERVER[$key] ?? ''));
        if ($script !== '') {
            return $script;
        }
    }

    return '';
}

function af_advresponsivelayout_normalize_action(string $action): string
{
    return strtolower(trim($action));
}

function af_advresponsivelayout_parse_assets_blacklist(): array
{
    global $mybb;

    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    $raw = str_replace(["\r\n", "\r"], "\n", (string)($mybb->settings['af_advresponsivelayout_assets_blacklist'] ?? ''));

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $scriptPart = $line;
        $actionPart = null;

        $qPos = strpos($line, '?');
        if ($qPos !== false) {
            $scriptPart = trim(substr($line, 0, $qPos));
            $query = trim(substr($line, $qPos + 1));
            foreach (explode('&', $query) as $pair) {
                $parts = explode('=', (string)$pair, 2);
                $key = strtolower(trim(urldecode((string)($parts[0] ?? ''))));
                if ($key !== 'action') {
                    continue;
                }

                $value = trim(urldecode((string)($parts[1] ?? '')));
                $actionPart = $value === '' ? '' : af_advresponsivelayout_normalize_action($value);
                break;
            }
        }

        $script = af_advresponsivelayout_normalize_script_name($scriptPart);
        if ($script === '') {
            continue;
        }

        $cache[] = ['script' => $script, 'action' => $actionPart];
    }

    return $cache;
}

function af_advresponsivelayout_is_blacklisted(?string $script = null, ?string $action = null): bool
{
    global $mybb;

    $script = $script === null ? af_advresponsivelayout_current_script_name() : af_advresponsivelayout_normalize_script_name($script);
    if ($script === '') {
        return false;
    }

    $action = $action === null
        ? af_advresponsivelayout_normalize_action((string)$mybb->get_input('action'))
        : af_advresponsivelayout_normalize_action($action);

    foreach (af_advresponsivelayout_parse_assets_blacklist() as $entry) {
        if (($entry['script'] ?? '') !== $script) {
            continue;
        }

        $entryAction = $entry['action'] ?? null;
        if ($entryAction === null || $entryAction === '' || $entryAction === '*') {
            return true;
        }

        if ($entryAction === $action) {
            return true;
        }
    }

    return false;
}

function af_advresponsivelayout_setting_enabled(string $key, bool $default = true): bool
{
    global $mybb;

    if (!isset($mybb->settings[$key])) {
        return $default;
    }

    return !empty($mybb->settings[$key]) && (string)$mybb->settings[$key] !== '0';
}

function af_advresponsivelayout_should_inject_assets(): bool
{
    if (defined('IN_ADMINCP') || (defined('THIS_SCRIPT') && THIS_SCRIPT === 'xmlhttp.php')) {
        return false;
    }

    if (!af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enabled', true)) {
        return false;
    }

    if (af_advresponsivelayout_is_blacklisted()) {
        return false;
    }

    return true;
}

function af_advresponsivelayout_detect_page_aliases(string $script, string $action): array
{
    $classes = [];
    $map = [
        'index.php' => 'af-rwd-index',
        'forumdisplay.php' => 'af-rwd-forumdisplay',
        'showthread.php' => 'af-rwd-showthread',
        'member.php' => 'af-rwd-member',
        'usercp.php' => 'af-rwd-usercp',
        'private.php' => 'af-rwd-private',
        'postsactivity.php' => 'af-rwd-postsactivity',
        'userlist.php' => 'af-rwd-userlist',
        'search.php' => 'af-rwd-search',
        'gallery.php' => 'af-rwd-gallery',
        'misc.php' => 'af-rwd-misc',
        'shop.php' => 'af-rwd-shop',
        'inventory.php' => 'af-rwd-inventory',
        'inventories.php' => 'af-rwd-inventory',
        'abilities.php' => 'af-rwd-inventory',
        'kb.php' => 'af-rwd-kb',
        'charactersheets.php' => 'af-rwd-charactersheets',
    ];

    if (isset($map[$script])) {
        $classes[] = $map[$script];
    }

    if ($action !== '') {
        if (strpos($action, 'shop') === 0) {
            $classes[] = 'af-rwd-shop';
        }
        if (strpos($action, 'kb') === 0) {
            $classes[] = 'af-rwd-kb';
        }
        if (in_array($action, ['inventory', 'inventories', 'abilities', 'tab', 'entity'], true)) {
            $classes[] = 'af-rwd-inventory';
        }
        if ($action === 'af_charactersheet' || strpos($action, 'charactersheet') !== false) {
            $classes[] = 'af-rwd-charactersheets';
        }
        if (in_array($action, ['help', 'advancedrules'], true)) {
            $classes[] = 'af-rwd-misc-docs';
        }
        if (strpos($action, 'gallery') === 0) {
            $classes[] = 'af-rwd-gallery';
        }
    }

    return array_values(array_unique($classes));
}

function af_advresponsivelayout_is_embedded_context(string $page): bool
{
    if (strpos($page, 'af-apui-surface') !== false) {
        return true;
    }

    return stripos($page, 'modal') !== false
        || stripos($page, 'af-modal') !== false
        || stripos($page, 'af-cs-modal') !== false
        || stripos($page, 'af-inv-iframe') !== false
        || stripos($page, '<iframe') !== false;
}

function af_advresponsivelayout_add_body_classes(string $page): string
{
    global $mybb;

    $script = af_advresponsivelayout_current_script_name();
    $action = af_advresponsivelayout_normalize_action((string)$mybb->get_input('action'));

    $classes = ['af-rwd-enabled', 'af-rwd-right-menu-closed'];
    if ($script !== '') {
        $classes[] = 'af-rwd-script-' . preg_replace('~[^a-z0-9_-]+~', '-', str_replace('.php', '', $script));
    }
    if ($action !== '') {
        $classes[] = 'af-rwd-action-' . preg_replace('~[^a-z0-9_-]+~', '-', $action);
    }

    foreach (af_advresponsivelayout_detect_page_aliases($script, $action) as $cls) {
        $classes[] = $cls;
    }

    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_sticky_main_nav', true)) {
        $classes[] = 'af-rwd-main-nav-sticky';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_right_burger_menu', true)) {
        $classes[] = 'af-rwd-right-burger';
        $classes[] = 'af-rwd-mobile-header';
    }

    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_table_wrap', true)) {
        $classes[] = 'af-rwd-table-wrap-on';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_media_fixes', true)) {
        $classes[] = 'af-rwd-media-fixes-on';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_modal_fixes', true)) {
        $classes[] = 'af-rwd-modal-fixes-on';
    }

    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_compact_postbit_mobile', true)) {
        $classes[] = 'af-rwd-postbit-fixes-on';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_compact_forumdisplay_mobile', true)) {
        $classes[] = 'af-rwd-forumdisplay-fixes-on';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_compact_profile_mobile', true)) {
        $classes[] = 'af-rwd-profile-fixes-on';
    }
    if (af_advresponsivelayout_setting_enabled('af_advresponsivelayout_enable_plugin_patches', true)) {
        $classes[] = 'af-rwd-plugin-patches-on';
    }

    if (af_advresponsivelayout_is_embedded_context($page)) {
        $classes[] = 'af-rwd-modal-surface';
    }

    $classes = array_values(array_unique(array_filter($classes)));

    return (string)preg_replace_callback(
        '~<body\b([^>]*)>~i',
        static function (array $m) use ($classes): string {
            $attrs = (string)($m[1] ?? '');

            if (preg_match('~\bclass\s*=\s*("|\')(.*?)\1~is', $attrs, $classMatch)) {
                $existing = preg_split('~\s+~', trim((string)$classMatch[2])) ?: [];
                $all = array_values(array_unique(array_merge($existing, $classes)));
                $newClass = 'class="' . htmlspecialchars_uni(implode(' ', $all)) . '"';
                $attrs = (string)preg_replace('~\bclass\s*=\s*("|\')(.*?)\1~is', $newClass, $attrs, 1);
                return '<body' . $attrs . '>';
            }

            return '<body class="' . htmlspecialchars_uni(implode(' ', $classes)) . '"' . $attrs . '>';
        },
        $page,
        1
    );
}

function af_advresponsivelayout_runtime_style_tag(): string
{
    global $mybb;

    $phone = max(360, (int)($mybb->settings['af_advresponsivelayout_breakpoint_phone'] ?? 768));
    $tablet = max($phone + 1, (int)($mybb->settings['af_advresponsivelayout_breakpoint_tablet'] ?? 1024));
    $desktop = max($tablet, (int)($mybb->settings['af_advresponsivelayout_breakpoint_desktop'] ?? 1200));
    $headerBp = max(360, (int)($mybb->settings['af_advresponsivelayout_mobile_header_breakpoint'] ?? 768));

    $padMobile = trim((string)($mybb->settings['af_advresponsivelayout_page_pad_mobile'] ?? '8px'));
    $padDesktop = trim((string)($mybb->settings['af_advresponsivelayout_page_pad_desktop'] ?? '20px'));

    if (!preg_match('~^[0-9.]+(?:px|rem|em|vw|%)$~i', $padMobile)) {
        $padMobile = '8px';
    }
    if (!preg_match('~^[0-9.]+(?:px|rem|em|vw|%)$~i', $padDesktop)) {
        $padDesktop = '20px';
    }

    $mobileMax = max(359, $phone - 1);

    return '<style id="af-rwd-runtime-vars">:root{'
        . '--af-rwd-breakpoint-phone:' . (int)$phone . 'px;'
        . '--af-rwd-breakpoint-tablet:' . (int)$tablet . 'px;'
        . '--af-rwd-breakpoint-desktop:' . (int)$desktop . 'px;'
        . '--af-rwd-mobile-max:' . (int)$mobileMax . 'px;'
        . '--af-rwd-mobile-header-breakpoint:' . (int)$headerBp . 'px;'
        . '--af-rwd-page-pad-mobile:' . htmlspecialchars_uni($padMobile) . ';'
        . '--af-rwd-page-pad-desktop:' . htmlspecialchars_uni($padDesktop) . ';'
        . '}</style>';
}

function af_advresponsivelayout_append_runtime_assets(string $page): string
{
    global $mybb;

    if (strpos($page, AF_ADVRWD_MARK) !== false) {
        return $page;
    }

    $base = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/inc/plugins/advancedfunctionality/addons/' . AF_ADVRWD_ID . '/assets/';
    $cssFile = AF_ADVRWD_ASSETS . 'advresponsivelayout.css';
    $jsFile = AF_ADVRWD_ASSETS . 'advresponsivelayout.js';

    $vCss = @is_file($cssFile) ? (string)@filemtime($cssFile) : '1';
    $vJs = @is_file($jsFile) ? (string)@filemtime($jsFile) : '1';

    $viewportMeta = '';
    if (!preg_match('~<meta\b[^>]*\bname\s*=\s*(?:["\']viewport["\']|viewport)\b[^>]*>~i', $page)) {
        $viewportMeta = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    }

    $bits = "\n" . AF_ADVRWD_MARK . "\n"
        . $viewportMeta
        . af_advresponsivelayout_runtime_style_tag() . "\n"
        . '<link rel="stylesheet" href="' . htmlspecialchars_uni($base . 'advresponsivelayout.css?v=' . rawurlencode($vCss)) . '">' . "\n"
        . '<script defer src="' . htmlspecialchars_uni($base . 'advresponsivelayout.js?v=' . rawurlencode($vJs)) . '"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        return (string)preg_replace('~</head>~i', $bits . '</head>', $page, 1);
    }

    return $bits . $page;
}

function af_advresponsivelayout_strip_assets(string $page): string
{
    $basePattern = '[^"\']*?/inc/plugins/advancedfunctionality/addons/' . AF_ADVRWD_ID . '/assets/[^"\']+';

    $page = str_replace(AF_ADVRWD_MARK, '', $page);
    $page = (string)preg_replace('~<style\b[^>]*id=("|\')af-rwd-runtime-vars\1[^>]*>.*?</style>\s*~is', '', $page);
    $page = (string)preg_replace('~<link\b[^>]*\bhref=("|\')' . $basePattern . '\.css(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $page);
    $page = (string)preg_replace('~<script\b[^>]*\bsrc=("|\')' . $basePattern . '\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $page);

    return $page;
}

function af_advresponsivelayout_pre_output(string &$page = ''): void
{
    if (!is_string($page) || $page === '') {
        return;
    }

    $page = af_advresponsivelayout_strip_assets($page);

    if (!af_advresponsivelayout_should_inject_assets()) {
        return;
    }

    $page = af_advresponsivelayout_add_body_classes($page);
    $page = af_advresponsivelayout_append_runtime_assets($page);
}
