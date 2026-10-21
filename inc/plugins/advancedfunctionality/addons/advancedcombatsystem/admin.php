<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) {
    die('No direct access');
}

/** Dispatched only through the existing, permission-protected AF ACP router. */
class AF_Admin_Advancedcombatsystem
{
    public static function dispatch(): void
    {
        global $lang, $mybb;

        $meta = require __DIR__.'/manifest.php';
        af_sync_addon_languages($meta, false);
        af_load_addon_lang($meta['id'], true);

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $command = $mybb->get_input('acs_command');
            try {
                if ($command === 'install') { af_advancedcombatsystem_install(); }
                elseif ($command === 'upgrade') { af_advancedcombatsystem_upgrade(); }
                elseif ($command === 'uninstall') { af_advancedcombatsystem_uninstall($mybb->get_input('acs_confirmation')); }
                else { throw new RuntimeException('ACS_UNKNOWN_COMMAND'); }
                echo '<p>'.htmlspecialchars_uni($lang->af_advancedcombatsystem_success).'</p>';
            } catch (Throwable $error) {
                $safe = preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
                echo '<div class="error">'.htmlspecialchars_uni($safe).'</div>';
            }
        }

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

        try { $state = af_advancedcombatsystem_migrations()->status(); }
        catch (Throwable $error) { $state = ['schema_version' => null, 'migrations' => [], 'error' => 'ACS_DATABASE_UNAVAILABLE', 'database' => null]; }
        $statusTable = new Table;
        $statusTable->construct_header(htmlspecialchars_uni($lang->af_advancedcombatsystem_schema));
        $statusTable->construct_header(htmlspecialchars_uni($lang->af_advancedcombatsystem_compatibility));
        $statusTable->construct_cell(htmlspecialchars_uni($state['schema_version'] ?? $lang->af_advancedcombatsystem_unavailable));
        $statusTable->construct_cell(htmlspecialchars_uni($state['error'] ?? implode(' / ', $state['database'] ?? [])));
        $statusTable->construct_row();
        $statusTable->output('AdvancedCombatSystem');
        echo '<p>'.htmlspecialchars_uni($lang->af_advancedcombatsystem_migrations).'</p><ul>';
        foreach ($state['migrations'] as $migration) {
            echo '<li>'.htmlspecialchars_uni($migration['version'].' / '.$migration['status'].' / '.$migration['step'].' / '.($migration['error_id'] ?? '—')).'</li>';
        }
        echo '</ul>';

        // Information above is read-only. Commands below require POST + MyBB CSRF.
        $url = 'index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem';
        foreach (['install', 'upgrade', 'uninstall'] as $command) {
            $label = 'af_advancedcombatsystem_'.$command;
            echo '<form method="post" action="'.$url.'">'
                .'<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">'
                .'<input type="hidden" name="acs_command" value="'.$command.'">';
            if ($command === 'uninstall') {
                echo '<p>'.htmlspecialchars_uni($lang->af_advancedcombatsystem_confirm).'</p>'
                    .'<input type="text" name="acs_confirmation" value="" autocomplete="off" aria-label="'.htmlspecialchars_uni($lang->af_advancedcombatsystem_confirm).'">';
            }
            echo '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($lang->$label).'"></form>';
        }
    }
}
