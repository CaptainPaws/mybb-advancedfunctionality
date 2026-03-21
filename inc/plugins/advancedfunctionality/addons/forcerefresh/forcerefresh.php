<?php
/**
 * AF Addon: Force Refresh After Quick Reply
 * MyBB 1.8.x / PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* AF core required */ }

define('AF_FORCEREFRESH_ID', 'forcerefresh');

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
    $db->delete_query('settings', "name IN ('af_forcerefresh_enabled','af_forcerefresh_delay_ms','af_forcerefresh_debug')");
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

    $script = af_forcerefresh_current_script_name();
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

function af_forcerefresh_current_script_name(): string
{
    if (function_exists('af_current_script_name')) {
        $script = (string)af_current_script_name();
        if ($script !== '') {
            return strtolower($script);
        }
    }

    if (defined('THIS_SCRIPT')) {
        return strtolower((string)basename(str_replace('\\', '/', (string)THIS_SCRIPT)));
    }

    return strtolower((string)basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''))));
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
