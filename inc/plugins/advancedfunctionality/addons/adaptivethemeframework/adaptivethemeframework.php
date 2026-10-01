<?php
/**
 * AF Addon: Adaptive Theme Framework.
 *
 * The initial scaffold intentionally has no hooks, templates, database state,
 * or runtime assets. Presentation behavior will arrive through component slots.
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_ADAPTIVETHEMEFRAMEWORK_ID', 'adaptivethemeframework');
define('AF_ADAPTIVETHEMEFRAMEWORK_BASE', AF_ADDONS . AF_ADAPTIVETHEMEFRAMEWORK_ID . '/');

function af_adaptivethemeframework_init(): void
{
    // No runtime hooks are required by the scaffold.
}

function af_adaptivethemeframework_install(): bool
{
    return true;
}

function af_adaptivethemeframework_is_installed(): bool
{
    // This version owns no persistent state beyond AF's enabled setting.
    return true;
}

function af_adaptivethemeframework_activate(): bool
{
    return true;
}

function af_adaptivethemeframework_deactivate(): bool
{
    return true;
}

function af_adaptivethemeframework_uninstall(): bool
{
    return true;
}
