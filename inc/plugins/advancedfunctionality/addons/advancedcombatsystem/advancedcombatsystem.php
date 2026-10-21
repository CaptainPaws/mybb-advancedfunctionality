<?php

if (!defined('IN_MYBB') || !defined('AF_ADDONS')) {
    die('No direct access');
}

require_once __DIR__.'/modules/Bootstrap/Context.php';

/**
 * AF invokes this on enabled requests and ignores the return value.
 * Only resolve the lightweight context; ACS-1.1 has no runtime to dispatch.
 * No hooks or AJAX operations are registered.
 */
function af_advancedcombatsystem_init(): string
{
    return AF_ACS_Context::current();
}

/** Administrative paths only: ordinary bootstrap never loads the SQL stack. */
function af_advancedcombatsystem_migrations(): AF_ACS_Migrations
{
    global $db;
    require_once __DIR__.'/modules/Administration/Migrations.php';
    return new AF_ACS_Migrations($db);
}

function af_advancedcombatsystem_is_installed(): bool
{
    try {
        $state = af_advancedcombatsystem_migrations()->status();
        return $state['error'] === null && $state['schema_version'] === AF_ACS_Migrations::VERSION;
    } catch (Throwable $error) { return false; }
}

/** Same AF/MyBB ACP authorization boundary and CSRF token; no public endpoint. */
function af_advancedcombatsystem_lifecycle(string $operation, callable $command): bool
{
    global $mybb;
    if (!defined('IN_ADMINCP') || !IN_ADMINCP || $mybb->request_method !== 'post') {
        throw new RuntimeException('ACS_ACP_POST_REQUIRED');
    }
    verify_post_check($mybb->get_input('my_post_key'));
    try {
        // Turning ACS off must remain possible even with broken dependencies.
        if ($operation !== 'deactivate' && (PHP_VERSION_ID < 80400 || PHP_INT_SIZE !== 8 || !extension_loaded('mysqli') || !extension_loaded('mbstring')
            || (int)$mybb->version_code < 1840 || (int)$mybb->version_code >= 1900)) { throw new RuntimeException('ACS_ENVIRONMENT_INCOMPATIBLE'); }
        $command();
        af_clear_addon_lifecycle_diagnostic('advancedcombatsystem');
        return true;
    } catch (Throwable $error) {
        $safe = preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
        $failure = new RuntimeException($safe);
        af_store_addon_lifecycle_diagnostic('advancedcombatsystem', $operation, 'acs_lifecycle', $failure);
        throw $failure;
    }
}

function af_advancedcombatsystem_install(): bool
{
    return af_advancedcombatsystem_lifecycle('install', static function () {
        af_advancedcombatsystem_migrations()->run();
    });
}

function af_advancedcombatsystem_activate(): bool
{
    return af_advancedcombatsystem_lifecycle('activate', static function () {
        af_advancedcombatsystem_migrations()->assertInstalled();
        unset($GLOBALS['af_acs_deactivated']);
        // No combat hooks/assets exist yet. AF owns the persisted enabled flag.
    });
}

function af_advancedcombatsystem_deactivate(): bool
{
    return af_advancedcombatsystem_lifecycle('deactivate', static function () {
        $GLOBALS['af_acs_deactivated'] = true;
        // Preserve every row, template and setting. AF persists enabled=0.
    });
}

function af_advancedcombatsystem_upgrade(): bool
{
    return af_advancedcombatsystem_lifecycle('upgrade', static function () {
        af_advancedcombatsystem_migrations()->run();
    });
}

function af_advancedcombatsystem_uninstall(string $confirmation = ''): bool
{
    return af_advancedcombatsystem_lifecycle('uninstall', static function () use ($confirmation) {
        af_advancedcombatsystem_migrations()->uninstall($confirmation);
    });
}
