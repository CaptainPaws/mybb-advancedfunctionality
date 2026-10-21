<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) {
    die('No direct access');
}

/** Dispatched only through the existing, permission-protected AF ACP router. */
class AF_Admin_Advancedcombatsystem
{
    public static function dispatch(): void
    {
        global $lang;

        $meta = require __DIR__.'/manifest.php';
        af_sync_addon_languages($meta, false);
        af_load_addon_lang($meta['id'], true);

        $table = new Table;
        $table->construct_header(htmlspecialchars_uni($lang->af_advancedcombatsystem_version));
        $table->construct_header(htmlspecialchars_uni($lang->af_advancedcombatsystem_status));
        $table->construct_cell(htmlspecialchars_uni($meta['version']));
        $table->construct_cell(htmlspecialchars_uni(
            af_is_addon_enabled($meta['id'])
                ? $lang->af_advancedcombatsystem_enabled
                : $lang->af_advancedcombatsystem_disabled
        ));
        $table->construct_row();
        $table->output(htmlspecialchars_uni($lang->af_advancedcombatsystem_name));

        echo '<p>'.htmlspecialchars_uni($lang->af_advancedcombatsystem_notice).'</p>';
    }
}
