<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) {
    die('No direct access');
}
require_once __DIR__.'/modules/Application/AuthorizationService.php';

/** Standard AF router, Table and Page; no independent ACP authorization. */
class AF_Admin_Advancedcombatsystem
{
    private static function esc(mixed $value): string { return htmlspecialchars_uni((string)$value); }

    public static function dispatch(): void
    {
        global $lang, $mybb, $page;
        $auth = new AF_ACS_AuthorizationService;
        if (!$auth->decision('admin.view')['allowed']) {
            echo '<div class="error">'.self::esc($lang->access_denied ?? 'Access denied').'</div>';
            return;
        }
        $meta = require __DIR__.'/manifest.php';
        af_sync_addon_languages($meta, false);
        af_load_addon_lang($meta['id'], true);
        require_once __DIR__.'/modules/Administration/Diagnostics.php';
        $diagnostics = new AF_ACS_Diagnostics;
        $sections = ['main', 'permissions', 'sessions', 'diagnostics', 'integrations'];
        $section = $mybb->get_input('acs_section');
        if (!in_array($section, $sections, true)) { $section = 'main'; }
        $tabs = [];
        foreach ($sections as $key) {
            $label = 'af_advancedcombatsystem_'.$key;
            $tabs[$key] = ['title' => $lang->$label, 'link' => 'index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section='.$key];
        }
        if (isset($page) && method_exists($page, 'output_nav_tabs')) { $page->output_nav_tabs($tabs, $section); }

        if($section==='sessions') {require_once __DIR__.'/modules/Presentation/Sessions.php';try {AF_ACS_SessionsPage::render(true);}catch(Throwable $error){echo '<p>Сессии ACS недоступны. Проверьте установку в разделе «Основные».</p>';}return;}
        if ($mybb->request_method === 'post') {
            $auth->requirePermission('admin.schema.manage');
            verify_post_check($mybb->get_input('my_post_key'));
            try {
                if ($section !== 'main') { throw new RuntimeException('ACS_UNKNOWN_COMMAND'); }
                $command = $mybb->get_input('acs_command');
                if ($command === 'install') { af_advancedcombatsystem_install(); }
                elseif ($command === 'upgrade') { af_advancedcombatsystem_upgrade(); }
                elseif ($command === 'uninstall') { af_advancedcombatsystem_uninstall($mybb->get_input('acs_confirmation')); }
                else { throw new RuntimeException('ACS_UNKNOWN_COMMAND'); }
                echo '<p>'.self::esc($lang->af_advancedcombatsystem_success).'</p>';
            } catch (Throwable $error) {
                $safe = preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $error->getMessage()) ? $error->getMessage() : 'ACS_DATABASE_ERROR';
                echo '<div class="error">'.self::esc($safe).'</div>';
            }
        }
        if ($section === 'permissions') { self::permissions(); return; }
        if ($section === 'integrations') { self::integrations($diagnostics); return; }
        if ($section === 'diagnostics') {
            $auth->requirePermission('admin.diagnostics.view');
            self::schema($diagnostics->schema());
            self::errors($diagnostics->recentErrors());
            self::integrations($diagnostics);
            return;
        }
        $table = new Table;
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_version));
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_status));
        $table->construct_cell(self::esc($meta['version']));
        $table->construct_cell(self::esc(af_is_addon_enabled($meta['id']) ? $lang->af_advancedcombatsystem_enabled : $lang->af_advancedcombatsystem_disabled));
        $table->construct_row();
        $table->output(self::esc($lang->af_advancedcombatsystem_name));
        require_once __DIR__.'/modules/Domain/Numeric.php';
        echo '<p>Numeric Engine: '.self::esc(AF_ACS_Numeric::POLICY).'</p>';
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_notice).'</p>';
        self::schema($diagnostics->schema());
        self::commands();
    }

    private static function schema(array $state): void
    {
        global $lang;
        $table = new Table;
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_schema));
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_compatibility));
        $table->construct_cell(self::esc($state['schema_version'] ?? $lang->af_advancedcombatsystem_unavailable));
        $table->construct_cell(self::esc($state['error'] ?? implode(' / ', $state['database'] ?? [])));
        $table->construct_row();
        $table->output('AdvancedCombatSystem');
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_migrations).'</p><ul>';
        foreach ($state['migrations'] as $migration) {
            echo '<li>'.self::esc($migration['version'].' / '.$migration['status'].' / '.$migration['step'].' / '.($migration['error_id'] ?? '—')).'</li>';
        }
        echo '</ul>';
    }

    private static function commands(): void
    {
        global $lang, $mybb;
        $url = 'index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=main';
        foreach (['install', 'upgrade', 'uninstall'] as $command) {
            $label = 'af_advancedcombatsystem_'.$command;
            echo '<form method="post" action="'.$url.'">'
                .'<input type="hidden" name="my_post_key" value="'.self::esc($mybb->post_code).'">'
                .'<input type="hidden" name="acs_command" value="'.$command.'">';
            if ($command === 'uninstall') {
                echo '<p>'.self::esc($lang->af_advancedcombatsystem_confirm).'</p>'
                    .'<input type="text" name="acs_confirmation" value="" autocomplete="off" aria-label="'.self::esc($lang->af_advancedcombatsystem_confirm).'">';
            }
            echo '<input type="submit" class="submit_button" value="'.self::esc($lang->$label).'"></form>';
        }
    }

    private static function permissions(): void
    {
        global $lang;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.permissions.view');
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_permissions_note).'</p>';
        $table = new Table;
        foreach (['permission', 'scope', 'policy'] as $key) { $label = 'af_advancedcombatsystem_'.$key; $table->construct_header(self::esc($lang->$label)); }
        foreach (AF_ACS_AuthorizationService::matrix() as $permission => $entry) {
            foreach ([$permission, $entry['scope'], $entry['policy']] as $value) { $table->construct_cell(self::esc($value)); }
            $table->construct_row();
        }
        $table->output(self::esc($lang->af_advancedcombatsystem_permissions));
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_role).': '.self::esc(implode(', ', array_map(fn($role) => $role->value, AF_ACS_Role::cases()))).'</p>';
    }

    private static function errors(array $rows): void
    {
        global $lang;
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_recent_errors).'</p>';
        if (!$rows) { echo '<p>'.self::esc($lang->af_advancedcombatsystem_no_errors).'</p>'; return; }
        $table = new Table;
        foreach (['error_id', 'category', 'operation / phase', 'UTC epoch / code'] as $label) { $table->construct_header(self::esc($label)); }
        foreach ($rows as $row) {
            foreach ([$row['error_id'], $row['category'], $row['operation'].' / '.$row['phase'], $row['created_at'].' / '.($row['code'] ?? '')] as $value) { $table->construct_cell(self::esc($value)); }
            $table->construct_row();
        }
        $table->output(self::esc($lang->af_advancedcombatsystem_diagnostics));
    }

    private static function integrations(AF_ACS_Diagnostics $diagnostics): void
    {
        global $lang, $mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_capability_note).'</p>';
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_probe_note).'</p>';
        $targets = ['sheet_id'=>max(0,(int)$mybb->get_input('acs_probe_sheet_id')),
            'kb_type'=>$mybb->get_input('acs_probe_kb_type'),'kb_key'=>$mybb->get_input('acs_probe_kb_key')];
        echo '<form method="get" action="index.php"><input type="hidden" name="module" value="advancedfunctionality">'
            .'<input type="hidden" name="af_view" value="advancedcombatsystem"><input type="hidden" name="acs_section" value="integrations">'
            .'<label>'.self::esc($lang->af_advancedcombatsystem_probe_sheet).' <input type="number" min="1" name="acs_probe_sheet_id" value="'.($targets['sheet_id'] ?: '').'"></label> '
            .'<label>KB type <select name="acs_probe_kb_type">';
        foreach (['arpg_bestiary','arpg_ability','arpg_element','character'] as $type) {
            echo '<option value="'.$type.'"'.($targets['kb_type'] === $type ? ' selected' : '').'>'.$type.'</option>';
        }
        echo '</select></label> <label>KB key <input type="text" maxlength="64" name="acs_probe_kb_key" value="'.self::esc($targets['kb_key']).'"></label> '
            .'<button type="submit">'.self::esc($lang->af_advancedcombatsystem_probe_run).'</button></form>';
        $table = new Table;
        foreach (['contracts', 'owner', 'interfaces', 'readiness', 'stage_action'] as $key) { $label = 'af_advancedcombatsystem_'.$key; $table->construct_header(self::esc($lang->$label)); }
        foreach ($diagnostics->capabilities(true, $targets) as $row) {
            $apis = [];
            foreach ($row['interfaces'] as $name => $evidence) { $apis[] = $name.': '.($evidence['declared'] ? 'yes' : 'no').' / '.($evidence['loaded'] ? 'yes' : 'no').' / '.($evidence['callable_now'] ? 'yes' : 'no'); }
            $table->construct_cell(self::esc($row['contract_id'].' / '.$row['capability'].' / '.$row['interface_status']));
            $table->construct_cell(self::esc($row['owner'].' / '.($row['detected'] ? 'yes' : 'no').' / '.($row['enabled'] ? 'yes' : 'no').' / '.($row['version'] ?? 'unknown')).'<br>'.self::esc($row['version_evidence']));
            $table->construct_cell(implode('<br>', array_map([self::class, 'esc'], $apis)));
            $details = [];
            foreach (array_slice($row['diagnostics'] ?? [],0,8) as $issue) $details[] = self::esc(substr($issue['code'].' / '.$issue['path'],0,160));
            $table->construct_cell(self::esc($row['readiness'].' / '.($row['reason'] ?? '—')).'<br>'.self::esc($row['scope'] ?? 'interface').'<br>'.implode('<br>',$details));
            $table->construct_cell(self::esc($row['roadmap_stage'].' / '.$row['remediation']));
            $table->construct_row();
        }
        $table->output(self::esc($lang->af_advancedcombatsystem_integrations));
    }
}
