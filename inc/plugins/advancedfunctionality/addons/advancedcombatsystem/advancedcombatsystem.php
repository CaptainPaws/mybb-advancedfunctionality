<?php

if (!defined('IN_MYBB') || !defined('AF_ADDONS')) {
    die('No direct access');
}

require_once __DIR__.'/modules/Bootstrap/Context.php';

/**
 * AF invokes this on enabled requests and ignores the return value.
 * Only resolve the lightweight context; ACS-1.1 has no runtime to dispatch.
 * No hooks, lifecycle placeholders or AJAX operations are registered.
 */
function af_advancedcombatsystem_init(): string
{
    return AF_ACS_Context::current();
}
