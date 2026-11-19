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
        $sections = ['main', 'permissions', 'sessions', 'diagnostics', 'integrations', 'publication'];
        $section = $mybb->get_input('acs_section');
        if (!in_array($section, $sections, true)) { $section = 'main'; }
        $tabs = [];
        foreach ($sections as $key) {
            $label = 'af_advancedcombatsystem_'.$key;
            $tabs[$key] = ['title' => $key === 'publication' ? 'Публикация' : $lang->$label, 'link' => 'index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section='.$key];
        }
        if (isset($page) && method_exists($page, 'output_nav_tabs')) { $page->output_nav_tabs($tabs, $section); }

        if ($section === 'publication') { require_once __DIR__.'/modules/Administration/PublicationDiagnostics.php'; AF_ACS_PublicationDiagnostics::render(); return; }

        require_once __DIR__.'/modules/Administration/AcpSections.php';
        if ($section === 'sessions') { AF_ACS_AcpSections::sessions(); return; }
        if ($mybb->request_method === 'post') {
            $auth->requirePermission('admin.schema.manage');
            verify_post_check($mybb->get_input('my_post_key'));
            try {
                if ($section !== 'main') { throw new RuntimeException('ACS_UNKNOWN_COMMAND'); }
                $command = $mybb->get_input('acs_command');
                if ($command === 'install') { $success = af_advancedcombatsystem_install(); }
                elseif ($command === 'upgrade') { $success = af_advancedcombatsystem_upgrade(); }
                elseif ($command === 'uninstall') { $success = af_advancedcombatsystem_uninstall($mybb->get_input('acs_confirmation')); }
                else { throw new RuntimeException('ACS_UNKNOWN_COMMAND'); }
                if (!$success) { throw new RuntimeException('ACS_LIFECYCLE_FAILED'); }
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
            self::readiness($diagnostics);
            self::errors(AF_ACS_AcpSections::errors($diagnostics));
            AF_ACS_AcpSections::inspect();
            if ($mybb->get_input('acs_engine_versions') === '1') { self::engineVersions(); }
            echo '<p><a href="'.self::esc(AF_ACS_AcpSections::url('diagnostics', ['acs_engine_versions'=>'1'])).'">Загрузить версии механических компонентов (без исполнения)</a> · <a href="'.self::esc(AF_ACS_AcpSections::url('integrations')).'">Dependency readiness и blocked capabilities</a></p>';
            return;
        }
        $table = new Table;
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_version));
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_status));
        $table->construct_cell(self::esc($meta['version']));
        $table->construct_cell(self::esc(af_is_addon_enabled($meta['id']) ? $lang->af_advancedcombatsystem_enabled : $lang->af_advancedcombatsystem_disabled));
        $table->construct_row();
        $table->output(self::esc($lang->af_advancedcombatsystem_name));
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_notice).'</p>';
        $state = $diagnostics->schema();
        self::summary($state);
        AF_ACS_AcpSections::settings();
        self::commands();
    }

    /** Compact general view; detailed ledger belongs only to Diagnostics. */
    private static function summary(array $state): void
    {
        $completed=count(array_filter($state['migrations'],static fn($row)=>$row['status']==='completed'));
        $table=new Table;
        $table->construct_header('Проверка');$table->construct_header('Состояние');
        foreach([
            'Схема ACS'=>($state['schema_version']??'Не установлена').' / требуется '.($state['required_version']??'Не зарегистрирована'),
            'Миграции'=>'Завершено: '.$completed.'; записей ledger: '.count($state['migrations']).'.',
            ...self::migrationDetails($state),
            'Боевая публикация'=>'BLOCKED / publication_enabled=false. Installed acceptance: NOT_VERIFIED. IC-MYBB-1 и отдельное решение администратора обязательны.',
        ] as $name=>$value){$table->construct_cell(self::esc($name));$table->construct_cell(self::esc($value));$table->construct_row();}
        $table->output('Состояние ACS');
        echo '<p><a href="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=diagnostics">Диагностика: ledger, ошибки и версии</a> · <a href="index.php?module=advancedfunctionality&amp;af_view=advancedcombatsystem&amp;acs_section=publication">Совместимость публикации</a></p>';
    }

    private static function migrationDetails(array $state): array
    {
        $recommendation = match ($state['error']) {
            null => 'Схема готова к штатной активации.',
            'ACS_NOT_INSTALLED' => 'Выполните штатную установку ACS.',
            'ACS_MIGRATION_PENDING_CHECKSUM_MISMATCH' => 'Проверьте версию пакета, которым была начата незавершённая миграция, перед штатным обновлением.',
            'ACS_MIGRATION_DECLARATION_UNSUPPORTED', 'ACS_MIGRATION_VERSION_MISMATCH', 'ACS_MIGRATION_STEP_CONTRACT_MISMATCH', 'ACS_MIGRATION_DDL_UNSUPPORTED' => 'Проверьте указанное определение миграции в полном выпуске ACS.',
            'ACS_MIGRATION_INCOMPLETE' => 'Проверьте прерванную установку. Для известного журнала и исходных файлов используйте штатное обновление.',
            'ACS_UNKNOWN_MIGRATION' => 'Разверните полный выпуск, которому принадлежит журнал миграций.',
            'ACS_MIGRATION_FILE_UNAVAILABLE', 'ACS_MIGRATION_FILE_INVALID' => 'Повторно загрузите полный проверенный комплект ACS.',
            default => 'Передайте владельцу проекта код ошибки и место несоответствия для сверки схемы, журнала или окружения.',
        };
        $location = implode(' / ', array_filter([$state['version'], $state['object']]));
        return [
            'Состояние миграций' => $state['status'].($state['error'] ? ' / '.$state['error'] : ''),
            'Структура схемы' => $state['structure'].($location ? ' / '.$location : ''),
            'Изменившиеся checksum' => $state['checksum_differences']
                ? implode(', ', array_keys($state['checksum_differences'])).($state['ready'] ? ' (структура совместима)' : '')
                : '—',
            'Рекомендация' => $recommendation,
        ];
    }

    private static function engineVersions(): void
    {
        require_once __DIR__.'/modules/Domain/Numeric.php';
        echo '<p>Numeric Engine: '.self::esc(AF_ACS_Numeric::POLICY).'</p>';
        require_once __DIR__.'/modules/Domain/Formula.php';
        echo '<p>Formula Compiler: '.self::esc(AF_ACS_Formula::VERSION).' ('.self::esc(AF_ACS_Formula::SCHEMA).')</p>';
        require_once __DIR__.'/modules/Domain/Modifiers.php';
        echo '<p>Modifier Pipeline: '.self::esc(AF_ACS_Modifiers::VERSION).'</p>';
        require_once __DIR__.'/modules/Domain/HitCrit.php';
        echo '<p>Hit/Crit: '.self::esc(AF_ACS_HitCrit::VERSION).'</p>';
        require_once __DIR__.'/modules/Domain/Damage.php';
        echo '<p>Damage: '.self::esc(AF_ACS_Damage::VERSION).'</p>';
        require_once __DIR__.'/modules/Domain/Effects.php';
        echo '<p>Effects: '.self::esc(AF_ACS_Effects::VERSION).'</p>';
        require_once __DIR__.'/modules/Domain/Resolver.php';
        echo '<p>Resolver preview/seal/commit: '.self::esc(AF_ACS_Resolver::VERSION).'</p>';
    }

    private static function schema(array $state): void
    {
        global $lang;
        $table = new Table;
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_schema));
        $table->construct_header(self::esc($lang->af_advancedcombatsystem_compatibility));
        $table->construct_cell(self::esc(($state['schema_version'] ?? $lang->af_advancedcombatsystem_unavailable)
            .' / '.($state['required_version'] ?? $lang->af_advancedcombatsystem_unavailable)));
        $table->construct_cell(self::esc($state['error'] ?? implode(' / ', $state['database'] ?? [])));
        $table->construct_row();
        $table->output('AdvancedCombatSystem');
        foreach (self::migrationDetails($state) as $label => $value) {
            echo '<p><strong>'.self::esc($label).'</strong> '.self::esc($value).'</p>';
        }
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
        echo '<p>Матрица только для чтения. Право AF ACP не заменяет native доступ, session GM, согласие владельца и назначение контроллера. Журнал, неизменяемость, server validation и идемпотентность обязательны; RESERVED_DENY не редактируется.</p>';
        $table = new Table;
        foreach (['permission', 'scope', 'policy'] as $key) { $label = 'af_advancedcombatsystem_'.$key; $table->construct_header(self::esc($lang->$label)); }
        $table->construct_header('Условие доступа');
        foreach (AF_ACS_AuthorizationService::matrix() as $permission => $entry) {
            foreach ([$permission, $entry['scope'], $entry['policy']] as $value) { $table->construct_cell(self::esc($value)); }
            $table->construct_cell(self::esc(AF_ACS_AcpSections::policyDescription($entry['policy'])));
            $table->construct_row();
        }
        $table->output(self::esc($lang->af_advancedcombatsystem_permissions));
        echo '<p>Owner — владелец сессии; GM — назначенный ведущий; assistant_gm — помощник без автоматического полного GM-доступа; player — участник; observer — наблюдатель. Роли проверяются вместе с конкретной серверной политикой, сроком назначения и native доступом.</p>';
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

    private static function readiness(AF_ACS_Diagnostics $diagnostics): void
    {
        $table = new Table;
        foreach (['Capability', 'Состояние / уровень', 'Причина / действие'] as $label) { $table->construct_header(self::esc($label)); }
        foreach ($diagnostics->capabilities() as $row) {
            $table->construct_cell(self::esc($row['capability']));
            $table->construct_cell(self::esc($row['readiness'].' / '.$row['verification_level']).'<br>'.self::esc('Installed acceptance: '.$row['installed_acceptance']));
            $table->construct_cell(self::esc(($row['reason'] ?? '—').' / '.$row['remediation']));
            $table->construct_row();
        }
        $table->output('Dependency readiness · без owner-команд');
    }

    private static function integrations(AF_ACS_Diagnostics $diagnostics): void
    {
        global $lang, $mybb;
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_capability_note).'</p>';
        echo '<p>'.self::esc($lang->af_advancedcombatsystem_probe_note).'</p>';
        $targets = ['sheet_id'=>trim($mybb->get_input('acs_probe_sheet_id')),
            'kb_type'=>$mybb->get_input('acs_probe_kb_type'),'kb_key'=>$mybb->get_input('acs_probe_kb_key')];
        echo '<form method="get" action="index.php"><input type="hidden" name="module" value="advancedfunctionality">'
            .'<input type="hidden" name="af_view" value="advancedcombatsystem"><input type="hidden" name="acs_section" value="integrations"><input type="hidden" name="acs_probe" value="1">'
            .'<label>'.self::esc($lang->af_advancedcombatsystem_probe_sheet).' <input type="number" min="1" name="acs_probe_sheet_id" value="'.self::esc($targets['sheet_id']).'"></label> '
            .'<label>KB type <select name="acs_probe_kb_type">';
        foreach (['arpg_bestiary','arpg_ability','arpg_element','character'] as $type) {
            echo '<option value="'.$type.'"'.($targets['kb_type'] === $type ? ' selected' : '').'>'.$type.'</option>';
        }
        echo '</select></label> <label>KB key <input type="text" maxlength="64" name="acs_probe_kb_key" value="'.self::esc($targets['kb_key']).'"></label> '
            .'<button type="submit">'.self::esc($lang->af_advancedcombatsystem_probe_run).'</button></form>';
        $table = new Table;
        foreach (['Контракт / реализация','Owner / установка','Runtime / API','Источник / область проверки','Installed acceptance / блокировка / действие'] as $label)$table->construct_header(self::esc($label));
        foreach ($diagnostics->capabilities($mybb->get_input('acs_probe')==='1', $targets) as $row) {
            $apis=[];
            foreach($row['interfaces'] as $name=>$evidence)$apis[]=self::esc($name.': объявлен '.($evidence['declared']?'да':'нет').', загружен '.($evidence['loaded']?'да':'нет').', callable '.($evidence['callable_now']?'да':'нет'));
            $table->construct_cell(self::esc($row['contract_id'].' / '.$row['capability']).'<br>'.self::esc($row['implementation_status']).'<br>'.self::esc($row['implementation_scope']));
            $table->construct_cell(self::esc($row['owner'].' / файл: '.($row['detected']?'обнаружен':'не обнаружен').' / enabled: '.($row['enabled']?'да':'нет').' / версия: '.($row['version']??'неизвестна')).'<br>'.self::esc('Установка: '.$row['installation_status']).'<br>'.self::esc($row['installation_scope']).'<br>'.self::esc($row['version_evidence']));
            $table->construct_cell(self::esc($row['runtime_status']).'<br>'.implode('<br>',$apis));
            $check=$row['data_check'];$details=[];
            // Bounded explicit metadata, never raw snapshots or exception messages.
            $issues=$check['diagnostics']??[];
            usort($issues,static fn($a,$b)=>(($b['code']??'')==='element_version_invalid')<=>(($a['code']??'')==='element_version_invalid'));
            foreach(array_slice($issues,0,40) as $issue){
                $parts=[];
                foreach(['code','path','canonical_key','entry_type','reference','actual_type','observed_revision','expected','recommendation'] as $key)if(isset($issue[$key])&&is_scalar($issue[$key]))$parts[]=$key.': '.substr((string)$issue[$key],0,1000);
                $details[]=self::esc(implode(' / ',$parts));
            }
            $table->construct_cell(self::esc($check['readiness'].' / '.($check['reason']??'—')).'<br>'.self::esc('Область: '.$check['scope']).'<br>'.implode('<br>',$details));
            $table->construct_cell(self::esc('Installed acceptance: '.$row['installed_acceptance']).'<br>'.self::esc('Уровень: '.$row['verification_level']).'<br>'.self::esc($row['readiness'].' / '.($row['reason']??'—')).'<br>'.self::esc($row['remediation']));
            $table->construct_row();
        }
        $table->output(self::esc($lang->af_advancedcombatsystem_integrations));
    }
}
