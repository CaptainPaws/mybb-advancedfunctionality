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
     * The AF ACP and explicit session page are read views.
     * Context identifies a surface; it grants no permissions. Authorization is
     * checked separately by ACP/commands without loading it on ordinary pages.
     * The showthread link provider resolves only persisted memberships;
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

        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='misc.php' && $mybb->get_input('action')==='acs_sessions')return self::VIEW;
        if(defined('THIS_SCRIPT') && THIS_SCRIPT==='xmlhttp.php' && $mybb->get_input('action')==='af_addon_api' && $mybb->get_input('addon')==='advancedcombatsystem')return self::VIEW;
        return self::INACTIVE;
    }
}
