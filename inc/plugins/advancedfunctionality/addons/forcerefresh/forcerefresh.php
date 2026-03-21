<?php
/**
 * AF Addon: Force Refresh After Quick Reply
 * MyBB 1.8.x / PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* AF core required */ }

define('AF_FORCEREFRESH_ID', 'forcerefresh');
define('AF_FORCEREFRESH_DEFAULT_BLACKLIST', "index.php");

function af_forcerefresh_install(): void
{
    af_forcerefresh_ensure_settings();
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_forcerefresh_uninstall(): void
{
    global $db;

    // не трогаем БД аддона? тут только settings
    $db->delete_query('settings', "name IN ('af_forcerefresh_enabled','af_forcerefresh_delay_ms','af_forcerefresh_debug','af_forcerefresh_assets_blacklist')");
    $db->delete_query('settinggroups', "name='af_forcerefresh'");
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_forcerefresh_init(): void
{
    // no-op
}

function af_forcerefresh_pre_output(string &$page = ''): void
{
    global $mybb;

    if (empty($mybb->settings['af_forcerefresh_enabled'])) {
        return;
    }

    af_forcerefresh_strip_assets($page);

    $script = af_forcerefresh_current_script_name();
    if ($script === '' || af_forcerefresh_assets_disabled_for_current_page()) {
        return;
    }

    if (!in_array($script, ['showthread.php', 'newreply.php', 'newthread.php', 'editpost.php'], true)) {
        return;
    }

    if (!af_forcerefresh_should_load_assets($script, (string)$page)) {
        return;
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $delay = (int)($mybb->settings['af_forcerefresh_delay_ms'] ?? 250);
    if ($delay < 0) $delay = 0;
    if ($delay > 5000) $delay = 5000;

    $src = $bburl . '/inc/plugins/advancedfunctionality/addons/forcerefresh/assets/forcerefresh.js';

    // v=filemtime
    $abs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/forcerefresh/assets/forcerefresh.js';
    $mtime = is_file($abs) ? (int)@filemtime($abs) : 0;
    $src .= '?v=' . $mtime;

    // config
    $debug = !empty($mybb->settings['af_forcerefresh_debug']) ? '1' : '0';

    $cfg = '<script>window.afForceRefreshCfg=' . json_encode([
        'delayMs' => $delay,
        'script'  => $script,
        'debug'   => $debug,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ';</script>';

    // вставим перед </body>
    if (stripos($page, '</body>') !== false) {
        $page = preg_replace(
            '~</body>~i',
            $cfg . "\n" . '<script src="' . htmlspecialchars_uni($src) . '"></script>' . "\n</body>",
            $page,
            1
        );
    }
}

function af_forcerefresh_parse_disable_conditions(string $raw): array
{
    $out = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $out;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = $line;
        $action = null;
        $query = '';
        $qPos = strpos($line, '?');
        if ($qPos !== false) {
            $script = substr($line, 0, $qPos);
            $query = substr($line, $qPos + 1);
        }

        $script = af_forcerefresh_normalize_script_name($script);
        if ($script === '') {
            continue;
        }

        if ($query !== '') {
            parse_str($query, $params);
            if (!empty($params['action'])) {
                $action = strtolower(trim((string)$params['action']));
            }
        }

        $out[] = ['script' => $script, 'action' => $action];
    }

    return $out;
}

function af_forcerefresh_normalize_script_name(string $raw): string
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

function af_forcerefresh_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = af_forcerefresh_current_script_name();
    if ($script === '') {
        return false;
    }

    $action = strtolower(trim((string)($mybb->input['action'] ?? '')));
    $lines = [AF_FORCEREFRESH_DEFAULT_BLACKLIST];
    $custom = trim((string)($mybb->settings['af_forcerefresh_assets_blacklist'] ?? ''));
    if ($custom !== '') {
        $lines[] = $custom;
    }

    foreach (af_forcerefresh_parse_disable_conditions(implode("\n", $lines)) as $cond) {
        if (($cond['script'] ?? '') !== $script) {
            continue;
        }

        $condAction = strtolower(trim((string)($cond['action'] ?? '')));
        if ($condAction === '' || $condAction === $action) {
            return true;
        }
    }

    return false;
}

function af_forcerefresh_strip_assets(string &$page): void
{
    if ($page === '') {
        return;
    }

    $patterns = [
        '~<script>\s*window\.afForceRefreshCfg=.*?</script>\s*~is',
        '~<script\b[^>]*src=["\'][^"\']*forcerefresh\.js(?:\?[^"\']*)?["\'][^>]*>\s*</script>\s*~iu',
    ];

    foreach ($patterns as $pattern) {
        $page = (string)preg_replace($pattern, '', $page);
    }
}

function af_forcerefresh_current_script_name(): string
{
    if (function_exists('af_current_script_name')) {
        $script = (string)af_current_script_name();
        if ($script !== '') {
            return strtolower($script);
        }
    }

    if (defined('THIS_SCRIPT')) {
        return af_forcerefresh_normalize_script_name((string)THIS_SCRIPT);
    }

    foreach (['SCRIPT_NAME', 'PHP_SELF', 'REQUEST_URI'] as $key) {
        $script = af_forcerefresh_normalize_script_name((string)($_SERVER[$key] ?? ''));
        if ($script !== '') {
            return $script;
        }
    }

    return '';
}

function af_forcerefresh_should_load_assets(string $script, string $page): bool
{
    $page = strtolower($page);
    if ($page === '') {
        return false;
    }

    if ($script === 'showthread.php') {
        return strpos($page, 'quickreply') !== false
            || strpos($page, 'do_newreply') !== false
            || strpos($page, 'id="quick_reply_form"') !== false
            || strpos($page, "id='quick_reply_form'") !== false
            || strpos($page, 'name="message"') !== false;
    }

    if (in_array($script, ['newreply.php', 'newthread.php', 'editpost.php'], true)) {
        return strpos($page, 'name="message"') !== false
            || strpos($page, "name='message'") !== false
            || strpos($page, 'id="message"') !== false
            || strpos($page, "id='message'") !== false
            || strpos($page, 'sceditor-container') !== false;
    }

    return false;
}

function af_forcerefresh_try_load_lang(): void
{
    global $lang, $mybb;

    if (!isset($lang) || !is_object($lang) || !method_exists($lang, 'load')) {
        return;
    }

    $addonLang = 'advancedfunctionality_forcerefresh';
    $bblang = (string)($mybb->settings['bblanguage'] ?? 'english');

    $frontFile = MYBB_ROOT . 'inc/languages/' . $bblang . '/' . $addonLang . '.lang.php';
    $adminFile = MYBB_ROOT . 'inc/languages/' . $bblang . '/admin/' . $addonLang . '.lang.php';

    $inAdmin = (defined('IN_ADMINCP') && IN_ADMINCP) || (defined('IN_MODCP') && IN_MODCP);

    // admin-файл — только если существует
    if ($inAdmin && is_file($adminFile)) {
        try { $lang->load($addonLang, true); } catch (Throwable $e) {}
    }

    // фронт — только если существует
    if (is_file($frontFile)) {
        try { $lang->load($addonLang); } catch (Throwable $e) {}
    }
}

function af_forcerefresh_ensure_settings(): void
{
    global $db, $lang;

    // ВАЖНО: не фейлимся, если языковых файлов ещё нет
    af_forcerefresh_try_load_lang();

    $title = 'AF: Force Refresh';
    $desc  = 'Reload settings.';
    $enabledTitle = 'Enable';
    $enabledDesc  = 'If enabled — reload after successful AJAX quick reply.';
    $delayTitle   = 'Reload delay (ms)';
    $delayDesc    = 'Example 200–600 ms. 0 = immediate.';
    $debugTitle   = 'Debug mode';
    $debugDesc    = 'Enable console diagnostics for pending/landed force refresh flow.';
    $blacklistTitle = 'Assets blacklist';
    $blacklistDesc = 'One condition per line: script.php or script.php?action=name. Force Refresh assets are blocked on matching pages.';

    // если язык всё-таки загрузился — используем его
    if (isset($lang) && is_object($lang)) {
        if (!empty($lang->af_forcerefresh_group)) $title = (string)$lang->af_forcerefresh_group;
        if (!empty($lang->af_forcerefresh_group_desc)) $desc = (string)$lang->af_forcerefresh_group_desc;

        if (!empty($lang->af_forcerefresh_enabled)) $enabledTitle = (string)$lang->af_forcerefresh_enabled;
        if (!empty($lang->af_forcerefresh_enabled_desc)) $enabledDesc = (string)$lang->af_forcerefresh_enabled_desc;

        if (!empty($lang->af_forcerefresh_delay_ms)) $delayTitle = (string)$lang->af_forcerefresh_delay_ms;
        if (!empty($lang->af_forcerefresh_delay_ms_desc)) $delayDesc = (string)$lang->af_forcerefresh_delay_ms_desc;

        if (!empty($lang->af_forcerefresh_debug)) $debugTitle = (string)$lang->af_forcerefresh_debug;
        if (!empty($lang->af_forcerefresh_debug_desc)) $debugDesc = (string)$lang->af_forcerefresh_debug_desc;

        if (!empty($lang->af_forcerefresh_assets_blacklist)) $blacklistTitle = (string)$lang->af_forcerefresh_assets_blacklist;
        if (!empty($lang->af_forcerefresh_assets_blacklist_desc)) $blacklistDesc = (string)$lang->af_forcerefresh_assets_blacklist_desc;
    }

    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_forcerefresh'", ['limit'=>1]), 'gid');
    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name' => 'af_forcerefresh',
            'title' => $title,
            'description' => $desc,
            'disporder' => 65,
            'isdefault' => 0,
        ]);
    } else {
        // обновим группу на всякий
        $db->update_query('settinggroups', [
            'title' => $title,
            'description' => $desc,
        ], 'gid=' . $gid);
    }

    $defs = [
        [
            'name' => 'af_forcerefresh_enabled',
            'title' => $enabledTitle,
            'description' => $enabledDesc,
            'optionscode' => 'yesno',
            'value' => '1',
            'disporder' => 1,
        ],
        [
            'name' => 'af_forcerefresh_delay_ms',
            'title' => $delayTitle,
            'description' => $delayDesc,
            'optionscode' => 'text',
            'value' => '250',
            'disporder' => 2,
        ],
        [
            'name' => 'af_forcerefresh_debug',
            'title' => $debugTitle,
            'description' => $debugDesc,
            'optionscode' => 'yesno',
            'value' => '0',
            'disporder' => 3,
        ],
        [
            'name' => 'af_forcerefresh_assets_blacklist',
            'title' => $blacklistTitle,
            'description' => $blacklistDesc,
            'optionscode' => 'textarea',
            'value' => AF_FORCEREFRESH_DEFAULT_BLACKLIST,
            'disporder' => 4,
        ],
    ];

    foreach ($defs as $d) {
        $nameEsc = $db->escape_string($d['name']);
        $sid = (int)$db->fetch_field($db->simple_select('settings', 'sid', "name='{$nameEsc}'", ['limit'=>1]), 'sid');

        $row = [
            'name' => $d['name'],
            'title' => $d['title'],
            'description' => $d['description'],
            'optionscode' => $d['optionscode'],
            'disporder' => (int)$d['disporder'],
            'gid' => $gid,
            'isdefault' => 0,
        ];

        if ($sid > 0) {
            $db->update_query('settings', $row, 'sid=' . $sid);
        } else {
            $row['value'] = $d['value'];
            $db->insert_query('settings', $row);
        }
    }
}
