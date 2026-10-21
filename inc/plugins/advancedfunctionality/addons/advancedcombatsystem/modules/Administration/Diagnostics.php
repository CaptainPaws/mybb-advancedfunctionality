<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/AuthorizationService.php';

/** On-demand observations. Never include an external bootstrap or call a reader. */
final class AF_ACS_Diagnostics
{
    private array $sourceCache = [];

    private function declarations(string $file): array
    {
        if (isset($this->sourceCache[$file])) { return $this->sourceCache[$file]; }
        $names = [];
        if (is_file($file) && is_readable($file)) {
            $tokens = token_get_all((string)file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
                for ($j = $i + 1; isset($tokens[$j]); ++$j) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                    if (is_array($next) && $next[0] === T_STRING) { $names[$next[1]] = true; }
                    break;
                }
            }
        }
        return $this->sourceCache[$file] = $names;
    }

    private function interfaces(array $sources): array
    {
        $result = [];
        foreach ($sources as $file => $names) {
            $declared = $this->declarations($file);
            foreach ($names as $name) {
                $parts = explode('::', $name, 2);
                $loaded = count($parts) === 2 ? (class_exists($parts[0], false) && method_exists($parts[0], $parts[1])) : function_exists($name);
                $result[$name] = ['declared' => isset($declared[end($parts)]), 'loaded' => $loaded, 'callable_now' => count($parts) === 2 ? ($loaded && is_callable($parts)) : $loaded];
            }
        }
        return $result;
    }

    public function schema(): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        try { return af_advancedcombatsystem_migrations()->status(); }
        catch (Throwable $error) { return ['schema_version' => null, 'migrations' => [], 'error' => 'ACS_DATABASE_UNAVAILABLE', 'database' => null]; }
    }

    /** Separate evidence (EXISTING/PROPOSED) from safe readiness. */
    public function capabilities(): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        $addons = [];
        if (function_exists('af_discover_addons')) {
            try { foreach (af_discover_addons() as $meta) { $addons[$meta['id']] = $meta; } }
            catch (Throwable $error) { $addons = []; }
        }
        $base = AF_ADDONS;
        $core = dirname(rtrim(AF_ADDONS, '/')).'.php';
        $definitions = [
            'core.facilities' => ['IC-CORE', 'advancedfunctionality', 'EXISTING', '1.3', [$core => ['af_discover_addons', 'af_is_addon_enabled', 'advancedfunctionality_bootstrap_addons', 'af_xmlhttp_call_addon', 'af_xmlhttp_router', 'af_frontend_asset_allowed']], 'CORE_INTERFACE_UNAVAILABLE', 'Restore the AF core and its standard ACP router.'],
            'core.schema' => ['IC-CORE', 'advancedcombatsystem', 'EXISTING', '1.2', [], 'SCHEMA_NOT_READY', 'Use the explicit install/upgrade command after resolving the reported schema error; retain existing data.'],
            'kb.definition_reader' => ['IC-KB', 'knowledgebase', 'EXISTING', '2.1', [$base.'knowledgebase/knowledgebase.php' => ['af_kb_arpg_read_definition', 'af_kb_arpg_normalize_entry', 'af_kb_arpg_character_stat_registry', 'af_kb_arpg_math_contract', 'af_kb_arpg_mechanics_contract']], 'DEFINITION_EXECUTABILITY_UNVERIFIED', 'Validate exact definitions/versions and content prerequisites in stage 2.1; presence of a reader is not execution support.'],
            'kb.combat_snapshot' => ['IC-KB', 'knowledgebase', 'PROPOSED', '2.2', [], 'PINNED_KB_ADAPTER_NOT_IMPLEMENTED', 'Implement IC-KB-1 and validate supported/executable definitions; do not fall back to legacy previews.'],
            'cs.combat_snapshot' => ['IC-CS', 'charactersheets', 'PROPOSED', '2.2', [$base.'charactersheets/charactersheets.php' => ['af_charactersheets_require_modules'], $base.'charactersheets/modules/calculator.php' => ['cs_build_character_state', 'af_charactersheets_compute_sheet_view']], 'IMMUTABLE_CS_ADAPTER_NOT_IMPLEMENTED', 'Implement a permission-checked immutable snapshot; the current view can synchronize fixed skills.'],
            'inv.reserve_consume' => ['IC-INV', 'advancedinventory', 'PROPOSED', '6.1', [$base.'advancedinventory/advancedinventory.php' => ['af_inv_get_items', 'af_inv_get_equipped', 'af_advinv_export_charactersheet_equipment_state', 'af_inv_add_item', 'af_inv_remove_item']], 'IDEMPOTENT_INVENTORY_GATEWAY_NOT_IMPLEMENTED', 'Implement global reserve/consume/release/lookup and audit all writers in IC-INV-1. Never substitute remove_item.'],
            'mybb.atomic_publish' => ['IC-MYBB', 'mybb', 'PROPOSED', '4.1', [MYBB_ROOT.'inc/datahandlers/post.php' => ['PostDataHandler::insert_post']], 'INSTALLED_MYBB_PUBLISH_AUDIT_REQUIRED', 'Audit installed MyBB 1.8.40 PostDataHandler, engines and hooks in stage 4.1, then implement the transaction coordinator.'],
            'theme.components' => ['IC-THEME', 'adaptivethemeframework', 'EXISTING', '4.1', [$base.'adaptivethemeframework/adaptivethemeframework.php' => ['af_adaptivethemeframework_register_component', 'af_adaptivethemeframework_slots']], 'ACS_COMPONENT_PROVIDER_NOT_IMPLEMENTED', 'Use verified Theme slots for later ACS components; do not replace ATF templates or invent a builder slot.'],
            'editor.combat_form' => ['IC-EDITOR', 'advancededitor', 'PROPOSED', '4.1', [$base.'advancededitor/advancededitor.php' => ['af_advancededitor_init', 'af_advancededitor_misc_start']], 'EDITOR_FORM_CONTRACT_NOT_IMPLEMENTED', 'Audit the existing form/preview hooks and add a scoped extension in stage 4.1; never create a second SCEditor.'],
            'aam.delivery' => ['IC-AAM', 'advancedalertsandmentions', 'PROPOSED', '11', [$base.'advancedalertsandmentions/advancedalertsandmentions.php' => ['af_aam_add_alert']], 'IDEMPOTENT_NOTIFICATION_ADAPTER_PENDING', 'Optional: implement outbox delivery acknowledgements in stage 11; absence must not block base ACS.'],
            'stats.consumer' => ['IC-STATS', 'advancedstatistic', 'PROPOSED', '10', [$base.'advancedstatistic/advancedstatistic.php' => ['af_advancedstatistic_get_board_stats']], 'CONFIRMED_EVENT_CONSUMER_PENDING', 'Optional: implement an idempotent confirmed-event consumer in stage 10; do not derive combat state from statistics.'],
        ];
        $state = $this->schema();
        $rows = [];
        foreach ($definitions as $id => [$contract, $owner, $interfaceStatus, $stage, $sources, $reason, $action]) {
            $optional = in_array($id, ['aam.delivery', 'stats.consumer'], true);
            $meta = $addons[$owner] ?? null;
            $detected = $owner === 'advancedfunctionality' ? is_file($core) : ($owner === 'mybb' ? is_file(MYBB_ROOT.'inc/datahandlers/post.php') : $meta !== null);
            $enabled = in_array($owner, ['advancedfunctionality', 'mybb'], true) ? $detected : ($detected && function_exists('af_is_addon_enabled') && af_is_addon_enabled($owner));
            $observed = $this->interfaces($sources);
            $version = $meta['version'] ?? null;
            if ($owner === 'advancedfunctionality' && function_exists('advancedfunctionality_info')) { $version = advancedfunctionality_info()['version'] ?? null; }
            if ($owner === 'mybb') { $version = $GLOBALS['mybb']->version ?? null; }
            $ready = false;
            if ($id === 'core.facilities') {
                $ready = $enabled && $observed !== [] && !array_filter($observed, fn($api) => !$api['declared'] || !$api['callable_now'])
                    && class_exists('AF_Admin', false) && method_exists('AF_Admin', 'findAdminController');
            } elseif ($id === 'core.schema') { $ready = $detected && $state['error'] === null && $state['schema_version'] === '001_foundation'; }
            $rowReason = $ready ? null : (!$detected ? 'OWNER_NOT_DETECTED' : (!$enabled ? 'OWNER_DISABLED' : $reason));
            if ($detected && $enabled && $interfaceStatus === 'EXISTING' && array_filter($observed, fn($api) => !$api['declared'])) { $rowReason = 'INTERFACE_MISSING'; }
            if ($id === 'core.schema' && !$ready) { $rowReason = $state['error'] ?? $reason; }
            $remediation = $ready ? 'No action required for this capability.' : $action;
            if (!$ready && $id !== 'core.schema' && !in_array($owner, ['advancedfunctionality', 'mybb'], true)) {
                if (!$detected) { $remediation = ($optional ? 'Optional: ' : '').'Install/restore owner '.$owner.' through AF. '.$action; }
                elseif (!$enabled) { $remediation = ($optional ? 'Optional: ' : '').'Enable owner '.$owner.' through AF. '.$action; }
            }
            $rows[$id] = [
                'capability' => $id, 'contract_id' => $contract, 'owner' => $owner,
                'detected' => $detected, 'enabled' => $enabled, 'version' => is_string($version) ? $version : null,
                'version_evidence' => $owner === 'mybb' ? 'runtime, not deployment certification' : ($meta ? 'manifest' : 'loaded core metadata'),
                'interface_status' => $interfaceStatus === 'EXISTING' && $observed && array_filter($observed, fn($api) => !$api['declared']) ? 'BLOCKED' : $interfaceStatus,
                'interfaces' => $observed,
                'readiness' => $ready ? 'READY' : ($optional ? 'OPTIONAL_UNAVAILABLE' : 'BLOCKED'),
                'reason' => $rowReason, 'implementation_blocker' => $ready ? null : $reason,
                'roadmap_stage' => $stage, 'remediation' => $remediation,
            ];
        }
        return $rows;
    }

    /** No global readiness flag: only dependencies of the requested operation. */
    public function gate(string $operation): array
    {
        $dependencies = [
            'participant.register' => ['core.facilities', 'core.schema', 'kb.combat_snapshot', 'cs.combat_snapshot'],
            'combat.preview' => ['core.facilities', 'core.schema', 'kb.definition_reader', 'kb.combat_snapshot'],
            'item.consume' => ['core.facilities', 'core.schema', 'inv.reserve_consume'],
            'combat.publish' => ['core.facilities', 'core.schema', 'mybb.atomic_publish', 'theme.components', 'editor.combat_form'],
        ];
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        if (!isset($dependencies[$operation])) { return ['allowed' => false, 'reason' => 'ACS_UNKNOWN_OPERATION', 'blockers' => []]; }
        $rows = $this->capabilities();
        $blockers = [];
        foreach ($dependencies[$operation] as $id) { if ($rows[$id]['readiness'] !== 'READY') { $blockers[] = $rows[$id]; } }
        $enabled = function_exists('af_is_addon_enabled') && af_is_addon_enabled('advancedcombatsystem');
        return ['allowed' => !$blockers && $enabled, 'reason' => $enabled ? ($blockers ? 'ACS_DEPENDENCY_BLOCKED' : null) : 'ACS_DISABLED', 'blockers' => $blockers];
    }

    public function recentErrors(): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        try {
            require_once __DIR__.'/../Infrastructure/Repository.php';
            $rows = (new AF_ACS_Repository($GLOBALS['db']))->recentDiagnostics();
        } catch (Throwable $error) { $rows = []; }
        $last = function_exists('af_read_addon_lifecycle_diagnostic') ? af_read_addon_lifecycle_diagnostic('advancedcombatsystem') : null;
        $code = $last['message'] ?? '';
        // Lifecycle files may contain older messages. Never expose raw text.
        if (is_string($code) && preg_match('/\AACS_[A-Z_]+(?: \[error_id=[a-f0-9]{32}\])?\z/D', $code)) {
            $rows[] = ['error_id' => 'lifecycle', 'category' => 'LIFECYCLE', 'operation' => 'lifecycle', 'phase' => 'acs_lifecycle', 'created_at' => (int)($last['recorded_at'] ?? 0), 'code' => $code];
        }
        return $rows;
    }
}
