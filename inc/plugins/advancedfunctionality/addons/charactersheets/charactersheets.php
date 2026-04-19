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

require_once AF_CS_MODULES . 'permissions.php';
require_once AF_CS_MODULES . 'experience.php';
require_once AF_CS_MODULES . 'sheets_crud.php';
require_once AF_CS_MODULES . 'calculator.php';
require_once AF_CS_MODULES . 'render.php';
require_once AF_CS_MODULES . 'ajax.php';
require_once AF_CS_MODULES . 'postbit.php';
require_once AF_CS_MODULES . 'acp_skills.php';
require_once AF_CS_MODULES . 'bootstrap.php';

function af_charactersheets_is_installed(): bool
{
    return af_charactersheets_is_installed_impl();
}

function af_charactersheets_install(): void
{
    af_charactersheets_install_impl();
}

function af_charactersheets_activate(): bool
{
    return af_charactersheets_activate_impl();
}

function af_charactersheets_deactivate(): bool
{
    return af_charactersheets_deactivate_impl();
}

function af_charactersheets_uninstall(): void
{
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
    af_charactersheets_showthread_start_impl();
}

function af_charactersheets_pre_output(&$page): void
{
    af_charactersheets_pre_output_impl($page);
}

function af_charactersheets_misc_start(): void
{
    af_charactersheets_misc_start_impl();
}

function af_charactersheets_handle_thread_move_for_acceptance(array $args): void
{
    af_charactersheets_handle_thread_move_for_acceptance_impl($args);
}
