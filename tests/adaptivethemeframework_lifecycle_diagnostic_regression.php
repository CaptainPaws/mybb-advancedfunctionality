<?php

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$atf = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');

function atf_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

atf_lifecycle_assert(is_string($core) && is_string($atf), 'Unable to read lifecycle sources');
atf_lifecycle_assert(strpos($atf, 'throw new RuntimeException(\'Adaptive Theme Framework activation failed') === false, 'ATF failure still throws through ACP');
atf_lifecycle_assert(strpos($atf, "af_store_addon_lifecycle_diagnostic(AF_ADAPTIVETHEMEFRAMEWORK_ID, 'enable', \$stage, \$error)") !== false, 'ATF failure is not persisted');
atf_lifecycle_assert(strpos($core, "'template_sid' => preg_match") !== false, 'Structured diagnostic omits template sid');
atf_lifecycle_assert(strpos($core, 'flash_message(htmlspecialchars_uni($message), \'error\')') !== false, 'ACP does not display the lifecycle diagnostic');
atf_lifecycle_assert(strpos($core, "\$GLOBALS['af_adaptivethemeframework_activation_stage'] = 'load_addon'") !== false, 'Lifecycle boundary does not begin before addon load');
atf_lifecycle_assert(strpos($core, "\$GLOBALS['af_adaptivethemeframework_activation_stage'] = 'sync_theme_stylesheets'") !== false, 'Lifecycle boundary does not cover post-activation sync');
atf_lifecycle_assert(strpos($atf, 'function af_adaptivethemeframework_schema_readiness(): bool') !== false, 'Schema-only readiness check is missing');
atf_lifecycle_assert(strpos($atf, 'check_duplicate_leases') < strpos($atf, "'add_index[' . \$name . ']'"), 'Duplicate leases are not checked before index DDL');

// Exercise the real shared formatter without bootstrapping MyBB.
$start = strpos($core, 'function af_format_addon_lifecycle_diagnostic(');
$end = strpos($core, "\nclass AF_Admin", $start);
eval(substr($core, $start, $end - $start));
$base = ['stage' => 'acs_lifecycle', 'exception' => 'RuntimeException', 'message' => 'ACS_DB_VERSION_UNSUPPORTED'];
$acs = af_format_addon_lifecycle_diagnostic($base + ['addon' => 'advancedcombatsystem', 'operation' => 'install']);
atf_lifecycle_assert(str_starts_with($acs, 'AdvancedCombatSystem install failed;'), 'ACS failure is still attributed to ATF');
$atfMessage = af_format_addon_lifecycle_diagnostic($base + ['addon' => 'adaptivethemeframework', 'operation' => 'enable']);
atf_lifecycle_assert(str_starts_with($atfMessage, 'ATF activation failed;'), 'ATF diagnostic naming regressed');

echo "ATF lifecycle diagnostic regression checks passed.\n";
