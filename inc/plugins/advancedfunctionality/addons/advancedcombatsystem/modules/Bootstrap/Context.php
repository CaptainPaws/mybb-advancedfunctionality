<?php

if (!defined('IN_MYBB') || !defined('AF_ADDONS')) {
    die('No direct access');
}

final class AF_ACS_Context
{
    public const INACTIVE = 'inactive';
    public const VIEW = 'view';
    // Reserved vocabulary only; no request can enter interactive in stage 1.
    public const INTERACTIVE = 'interactive';

    /**
     * Only the existing AF ACP page is a view in stage 1.
     * Context identifies a surface; it grants no permissions. Authorization is
     * checked separately by ACP/commands without loading it on ordinary pages.
     * Frontend session detection requires the later session/read contracts;
     * a tid, op or context supplied by a client cannot enable combat runtime.
     * Read enabled each time so disable/reactivate cannot leave stale context.
     */
    public static function current(): string
    {
        global $mybb;

        if (!af_is_addon_enabled('advancedcombatsystem')) {
            return self::INACTIVE;
        }

        if (defined('IN_ADMINCP') && IN_ADMINCP
            && $mybb->get_input('af_view') === 'advancedcombatsystem') {
            return self::VIEW;
        }

        return self::INACTIVE;
    }
}
