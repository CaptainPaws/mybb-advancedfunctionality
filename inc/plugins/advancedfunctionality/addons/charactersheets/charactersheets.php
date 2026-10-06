<?php
/**
 * AF Addon: CharacterSheets
 * MyBB 1.8.x, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

const AF_CS_ID = 'charactersheets';
const AF_CS_TABLE = 'af_charactersheets_accept';
const AF_CS_CONFIG_TABLE = 'af_charactersheets_config';
const AF_CS_SHEETS_TABLE = 'af_cs_sheets';
const AF_CS_EXP_LEDGER_TABLE = 'af_cs_exp_ledger';
const AF_CS_POINTS_LEDGER_TABLE = 'af_cs_points_ledger';
const AF_CS_SKILLS_CATALOG_TABLE = 'af_cs_skills_catalog';
const AF_CS_SKILLS_TABLE = 'af_cs_skills';
const AF_CS_TPL_MARK = '<!--AF_CS_ACCEPT-->';
const AF_CS_ASSET_MARK = '<!--AF_CS_ASSETS-->';
const AF_CS_MODAL_MARK = '<!--AF_CS_MODAL-->';
const AF_CS_ASSET_FALLBACK_VERSION = '1.1.0';
const AF_CS_SETTING_ASSETS_BLACKLIST = 'af_cs_assets_blacklist';
const AF_CS_ALIAS_MARKER = "define('AF_CHARACTERSHEETS_PAGE_ALIAS', 1);";

define('AF_CS_BASE', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/charactersheets/');
define('AF_CS_TPL_DIR', AF_CS_BASE . 'templates/');
define('AF_CS_MODULES', AF_CS_BASE . 'modules/');
define('AF_CS_ASSETS', AF_CS_BASE . 'assets/');

/**
 * Route-aware module loader.
 *
 * The addon bootstrap is loaded on every MyBB request, so showthread must not
 * parse/render the full sheet editor stack. Keep only modules needed to expose
 * postbit metadata and lightweight integration there; heavy modules are pulled
 * when a route/action actually executes them.
 */
function af_charactersheets_require_modules(array $modules): void
{
    static $loaded = [];

    foreach ($modules as $module) {
        $module = trim((string)$module);
        if ($module === '' || isset($loaded[$module])) {
            continue;
        }
        $path = AF_CS_MODULES . $module . '.php';
        if (is_file($path)) {
            require_once $path;
            $loaded[$module] = true;
        }
    }
}

af_charactersheets_require_modules(['permissions', 'experience', 'postbit', 'bootstrap']);

$afCsScript = strtolower(defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '');
if ($afCsScript === 'charactersheets.php') {
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax']);
}
if (defined('IN_ADMINCP')) {
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills']);
}

function af_charactersheets_is_installed(): bool
{
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills']);
    return af_charactersheets_is_installed_impl();
}

function af_charactersheets_install(): void
{
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills']);
    af_charactersheets_install_impl();
}

function af_charactersheets_activate(): bool
{
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills']);
    return af_charactersheets_activate_impl();
}

function af_charactersheets_deactivate(): bool
{
    return af_charactersheets_deactivate_impl();
}

function af_charactersheets_uninstall(): void
{
    af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills']);
    af_charactersheets_uninstall_impl();
}

function af_charactersheets_init(): void
{
    global $plugins;

    $plugins->add_hook('showthread_start', 'af_charactersheets_showthread_start');
    $plugins->add_hook('pre_output_page', 'af_charactersheets_pre_output');
    $plugins->add_hook('misc_start', 'af_charactersheets_misc_start');
    $plugins->add_hook('class_moderation_move_simple', 'af_charactersheets_handle_thread_move_for_acceptance');
    $plugins->add_hook('class_moderation_move_thread_redirect', 'af_charactersheets_handle_thread_move_for_acceptance');
}

function af_charactersheets_showthread_start(): void
{
    global $db, $tid;
    if ((int)$tid > 0) {
        $uids = [];
        $query = $db->simple_select('posts', 'DISTINCT uid', 'tid=' . (int)$tid . ' AND uid>0');
        while ($row = $db->fetch_array($query)) {
            $uids[] = (int)$row['uid'];
        }
        if (function_exists('af_characterworkflow_preload_active_applications')) {
            af_characterworkflow_preload_active_applications($uids);
        }
        af_charactersheets_preload_postbit_metadata($uids);
    }
    af_charactersheets_showthread_start_impl();
}

function af_charactersheets_pre_output(&$page): void
{
    af_charactersheets_pre_output_impl($page);
}

function af_charactersheets_misc_start(): void
{
    global $mybb;
    $action = strtolower((string)$mybb->get_input('action'));
    if (in_array($action, ['af_charactersheet', 'af_charactersheets', 'af_charactersheet_api', 'cs_modal_profile', 'cs_modal_application'], true)) {
        af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax']);
    } elseif (in_array($action, ['af_charactersheets_accept', 'af_charactersheets_transfer', 'af_charactersheets_create_sheet'], true)) {
        af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render']);
    }
    af_charactersheets_misc_start_impl();
}

function af_charactersheets_handle_thread_move_for_acceptance(array $args): void
{
    af_charactersheets_handle_thread_move_for_acceptance_impl($args);
}
