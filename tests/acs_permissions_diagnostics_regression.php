<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir().'/acs13_'.bin2hex(random_bytes(6)).'/';
mkdir($fixture.'inc/plugins', 0777, true);
symlink($root.'/inc/plugins/advancedfunctionality', $fixture.'inc/plugins/advancedfunctionality');
symlink($root.'/inc/plugins/advancedfunctionality.php', $fixture.'inc/plugins/advancedfunctionality.php');
define('IN_MYBB', true);
define('IN_ADMINCP', true);
define('MYBB_ROOT', $fixture);
define('TABLE_PREFIX', 'fixture_');
define('THIS_SCRIPT', 'showthread.php');
$plugins = new class { public function add_hook(...$args): void {} };
$mybb = new class {
    public array $settings = [];
    public array $user = ['uid' => 2];
    public array $usergroup = ['cancp' => 1];
    public array $admin = ['permissions' => ['advancedfunctionality' => ['index' => 1]]];
    public array $input = [];
    public string $request_method = 'post';
    public string $version = '1.8.40';
    public function get_input($key): string { return $this->input[$key] ?? ''; }
};
$db = new class { public function __call($name, $args): never { throw new RuntimeException('Unexpected database call'); } };
$lang = new stdClass;
function is_super_admin($uid): bool { return $uid === 1; }
function check(bool $value, string $label): void { if (!$value) throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label.PHP_EOL; }
function denied(callable $call, string $label): void {
    try { $call(); } catch (RuntimeException $error) { check(str_starts_with($error->getMessage(), 'ACS_'), $label); return; }
    throw new RuntimeException('FAIL allowed '.$label);
}
function htmlspecialchars_uni($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
require $root.'/inc/plugins/advancedfunctionality.php';
require AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';
$mybb->settings['af_advancedcombatsystem_enabled'] = '1';
check(af_advancedcombatsystem_init() === 'inactive' && !class_exists('AF_ACS_Diagnostics', false) && !class_exists('AF_ACS_AuthorizationService', false), 'ordinary bootstrap loads neither diagnostics nor authorization stack');
require AF_ADDONS.'advancedcombatsystem/modules/Application/AuthorizationService.php';
require AF_ADDONS.'advancedcombatsystem/modules/Administration/Diagnostics.php';
$auth = new AF_ACS_AuthorizationService;
check(array_map(fn($r) => $r->value, AF_ACS_Role::cases()) === ['owner', 'gm', 'assistant_gm', 'player', 'observer'], 'five typed roles');
check($auth->decision('admin.view')['allowed'], 'native AF index permission allows ACS administration');
check(!$auth->decision('unknown.permission')['allowed'], 'unknown permission denied');
foreach (AF_ACS_Role::cases() as $role) {
    check(!$auth->decision('session.manage', null, $role)['allowed'], 'missing session context denied for '.$role->value);
    check(!$auth->decision('entity.control', '1', $role)['allowed'], 'role alone never grants entity ownership: '.$role->value);
}
foreach (['confirmed.correct', 'rewards.adjust', 'persistent.modify', 'control.transfer', 'session.reopen', 'admin.recover'] as $critical) {
    check(!$auth->decision($critical, '1', AF_ACS_Role::Owner)['allowed'], 'critical capability reserved: '.$critical);
}
$mybb->user['uid'] = 1;
$mybb->admin['permissions'] = [];
check($auth->decision('admin.view')['allowed'] && !$auth->decision('entity.control', '1', AF_ACS_Role::Gm)['allowed'], 'native super admin ACP access does not grant character control');
$mybb->user['uid'] = 2;
$mybb->admin['permissions'] = [];
check(!$auth->decision('admin.view')['allowed'], 'administrator without AF access denied');
$diagnostics = new AF_ACS_Diagnostics;
denied(fn() => $diagnostics->capabilities(), 'registry denied without administrative permission');
denied(fn() => $diagnostics->recentErrors(), 'error projection denied without permission');
denied(fn() => af_advancedcombatsystem_activate(), 'lifecycle checks authorization before SQL/CSRF dispatch');
require AF_ADDONS.'advancedcombatsystem/admin.php';
ob_start(); AF_Admin_Advancedcombatsystem::dispatch(); $html = ob_get_clean();
check(str_contains($html, 'Access denied') && !str_contains($html, 'schema') && !str_contains($html, 'IC-KB'), 'ACP denial exposes no diagnostic information');
$mybb->user['uid'] = 0;
check(!$auth->decision('admin.view')['allowed'], 'guest denied');
$mybb->user['uid'] = 2;
$mybb->admin['permissions'] = ['advancedfunctionality' => ['index' => 1]];
foreach (['knowledgebase', 'charactersheets', 'advancedinventory', 'adaptivethemeframework', 'advancededitor'] as $id) { $mybb->settings['af_'.$id.'_enabled'] = '1'; }
$before = get_included_files();
$rows = $diagnostics->capabilities();
check($rows['core.facilities']['readiness'] === 'READY', 'actual loaded core interfaces ready');
check($rows['kb.definition_reader']['interfaces']['af_kb_arpg_read_definition']['declared'], 'KB reader declaration detected from actual source');
check(!$rows['kb.definition_reader']['interfaces']['af_kb_arpg_read_definition']['callable_now'], 'source declaration distinguished from callable runtime');
foreach (['kb.combat_snapshot', 'cs.combat_snapshot', 'inv.reserve_consume', 'mybb.atomic_publish', 'editor.combat_form'] as $id) {
    check($rows[$id]['interface_status'] === 'PROPOSED' && $rows[$id]['readiness'] === 'BLOCKED', 'safe contract remains unimplemented: '.$id);
}
check(!function_exists('cs_build_character_state') && !function_exists('af_inv_get_items') && !function_exists('af_kb_arpg_read_definition'), 'diagnostics never include external bootstraps/calculator');
foreach (array_diff(get_included_files(), $before) as $included) {
    check(basename($included) === 'manifest.php' || !preg_match('~/addons/(knowledgebase|charactersheets|advancedinventory|advancededitor|adaptivethemeframework)/~', $included), 'discovery includes metadata only, no external runtime');
}
check(!$diagnostics->gate('participant.register')['allowed'], 'missing mandatory snapshot gates registration');
$mybb->settings['af_knowledgebase_enabled'] = '0';
$disabled = $diagnostics->capabilities();
check($disabled['kb.combat_snapshot']['reason'] === 'OWNER_DISABLED', 'disabled dependency has a concrete reason');
check(str_contains($disabled['kb.combat_snapshot']['remediation'], 'Enable owner knowledgebase'), 'disabled dependency has an actionable remediation');
$gate = $diagnostics->gate('item.consume');
$blockers = array_column($gate['blockers'], 'capability');
check(!$gate['allowed'] && in_array('inv.reserve_consume', $blockers, true) && !in_array('kb.combat_snapshot', $blockers, true), 'item consumption blocked only by its own contracts');
$mybb->settings['af_advancedalertsandmentions_enabled'] = '0';
$mybb->settings['af_advancedstatistic_enabled'] = '0';
$optional = $diagnostics->capabilities();
check($optional['aam.delivery']['readiness'] === 'OPTIONAL_UNAVAILABLE' && $optional['stats.consumer']['readiness'] === 'OPTIONAL_UNAVAILABLE', 'optional missing safe integrations classified separately');
check($optional['core.facilities'] === $rows['core.facilities'] && array_column($diagnostics->gate('item.consume')['blockers'], 'capability') === $blockers, 'optional integrations do not alter core or item gates');
check(!$diagnostics->gate('arbitrary.command')['allowed'], 'unregistered operation denied');
check($rows['mybb.atomic_publish']['readiness'] === 'BLOCKED', 'runtime MyBB version does not certify publish atomicity');
unlink($fixture.'inc/plugins/advancedfunctionality');
unlink($fixture.'inc/plugins/advancedfunctionality.php');
rmdir($fixture.'inc/plugins'); rmdir($fixture.'inc'); rmdir($fixture);
echo "ACS-1.3 focused permissions/capability checks passed.\n";
