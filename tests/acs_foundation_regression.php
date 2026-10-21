<?php
declare(strict_types=1);

// Explicit LOCAL isolated server only. Creates/drops its own random databases.
// Never pass a production socket. No shared schema or MyBB database is used.
$socket = $argv[1] ?? '';
if (!extension_loaded('mysqli') || !str_starts_with($socket, '/') || !is_file($socket) && !file_exists($socket)) {
    fwrite(STDERR, "Usage: php tests/acs_foundation_regression.php /absolute/isolated-mariadb.sock\n");
    exit(2);
}
mysqli_report(getenv('ACS_TEST_REPORT_OFF') ? MYSQLI_REPORT_OFF : MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
define('IN_MYBB', true);
define('IN_ADMINCP', true);
define('TABLE_PREFIX', 'fixture_');
define('AF_ADDONS', dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
class ACS_TestMybb {
    public array $settings = ['af_advancedcombatsystem_enabled' => '0'];
    public string $request_method = 'post';
    public int $version_code = 1840;
    public string $post_code = 'fixture-token';
    public array $input = ['my_post_key' => 'fixture-token'];
    public function get_input(string $name): string { return $this->input[$name] ?? ''; }
}
$mybb = new ACS_TestMybb;
$lifecycleDiagnostics = [];
function af_is_addon_enabled(string $id): bool { return ($GLOBALS['mybb']->settings['af_'.$id.'_enabled'] ?? '') === '1'; }
function verify_post_check(string $key): void { if ($key !== 'fixture-token') throw new RuntimeException('CSRF'); }
function af_clear_addon_lifecycle_diagnostic(string $id): void { unset($GLOBALS['lifecycleDiagnostics'][$id]); }
function af_store_addon_lifecycle_diagnostic(string $id, string $operation, string $phase, Throwable $error): void { $GLOBALS['lifecycleDiagnostics'][$id] = [$operation, $phase, $error->getMessage()]; }
function af_sync_addon_languages(array $meta, bool $force): void {}
function af_load_addon_lang(string $id, bool $admin): void {
    $meta = require AF_ADDONS.'advancedcombatsystem/manifest.php';
    $GLOBALS['lang'] = (object)$meta['lang']['english']['admin'];
}
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
class Table {
    public array $cells = [];
    public function construct_header($value): void {}
    public function construct_cell($value): void { $this->cells[] = $value; }
    public function construct_row(): void {}
    public function output($title): void { echo $title.' '.implode(' ', $this->cells); }
}
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
function denied(callable $action, string $label, string $expected = ''): void {
    try { $action(); } catch (Throwable $error) { check($expected === '' || str_contains($error->getMessage(), $expected), $label); return; }
    throw new RuntimeException('FAIL allowed '.$label);
}

require AF_ADDONS.'advancedcombatsystem/advancedcombatsystem.php';
check(af_advancedcombatsystem_init() === 'inactive' && !class_exists('AF_ACS_Migrations', false), 'ordinary request does not load migration/storage stack');
$admin = new mysqli('localhost', 'root', '', '', 0, $socket);
$admin->set_charset('utf8mb4');
$databases = [];
$fresh = static function () use ($admin, $socket, &$databases): mysqli {
    $name = 'acs12_fixture_'.bin2hex(random_bytes(6));
    $databases[] = $name;
    $admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $link = new mysqli('localhost', 'root', '', $name, 0, $socket);
    $link->set_charset('utf8mb4');
    if (getenv('ACS_TEST_MYBB_DEFAULTS')) {
        $link->set_charset('utf8');
        $link->query("SET SESSION sql_mode=''");
    }
    return $link;
};
$connectionState = static function (mysqli $link): array {
    $row = $link->query('SELECT @@character_set_client AS client,@@character_set_connection AS connection,@@character_set_results AS results,@@collation_connection AS collation,@@session.sql_mode AS sql_mode')->fetch_assoc();
    $row['mysqlnd_charset'] = $link->character_set_name();
    return $row;
};
try {
    $connection = $fresh();
    // Preserve non-default result/collation settings too, including SQL NULL.
    $connection->query('SET SESSION character_set_results=NULL');
    $sharedState = $connectionState($connection);
    $db = (object)['write_link' => $connection];
    check(!af_advancedcombatsystem_is_installed(), 'empty database is not installed');
    check(af_advancedcombatsystem_install(), 'clean lifecycle install');
    check($sharedState === $connectionState($connection), 'install restores shared MyBB/ATF charset, collation, results and sql_mode');
    $migrations = af_advancedcombatsystem_migrations();
    $state = $migrations->status();
    check($state['schema_version'] === '001_foundation' && $state['migrations'][0]['step'] === 8, 'eight steps read back and version registered');
    $definition = require AF_ADDONS.'advancedcombatsystem/migrations/001_foundation.php';
    $architecture = file_get_contents(dirname(__DIR__).'/docs/acs/ACS_TECHNICAL_ARCHITECTURE.md');
    foreach ($definition['tables'] as $name => $ddl) {
        preg_match('/CREATE TABLE `\{prefix\}af_acs_'.preg_quote($name, '/').'` \(.*?;/s', $architecture, $match);
        check(str_replace(' ROW_FORMAT=DYNAMIC', '', $ddl) === ($match[0] ?? ''), 'DDL matches architecture: '.$name);
    }
    $tables = $connection->query('SHOW TABLES')->fetch_all();
    check(count($tables) === 8, 'exactly eight ACS tables');
    $journal = $connection->query('SELECT * FROM fixture_af_acs_schema_migrations')->fetch_assoc();
    af_advancedcombatsystem_install(); af_advancedcombatsystem_upgrade();
    check($journal === $connection->query('SELECT * FROM fixture_af_acs_schema_migrations')->fetch_assoc(), 'repeated install/upgrade leaves completed journal unchanged');
    $connection->query("UPDATE fixture_af_acs_schema_migrations SET checksum=REPEAT('0',64)");
    denied(fn() => af_advancedcombatsystem_upgrade(), 'changed migration checksum blocks upgrade', 'ACS_MIGRATION_JOURNAL_MISMATCH');
    $connection->query("UPDATE fixture_af_acs_schema_migrations SET checksum='".$journal['checksum']."'");
    check(af_advancedcombatsystem_activate(), 'activate installed foundation');
    check($sharedState === $connectionState($connection), 'reactivation preserves shared MyBB/ATF connection settings');
    $mybb->settings['af_advancedcombatsystem_enabled'] = '1';
    require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/SnapshotStore.php';
    require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/OperationStore.php';
    $store = new AF_ACS_Repository($db);
    $snapshots = new AF_ACS_SnapshotStore($db);
    $operations = new AF_ACS_OperationStore($db);
    // Stage-2 creation services do not exist: seed infrastructure parent fixtures.
    $connection->query("INSERT INTO fixture_af_acs_sessions (tid,owner_uid,status,schema_version,config_json,created_at,updated_at) VALUES (1,1,'active',1,'{\"schema_version\":1}',1,1),(2,2,'active',1,'{\"schema_version\":1}',1,1)");
    $source = ['source_system' => 'fixture', 'source_key' => 'source:1', 'contract_id' => 'fixture.v1', 'contract_version' => '1'];
    $payload = ['schema_version' => 1, 'numeric_policy' => 'binary64-half_up-v1', 'engine_version' => 'fixture-1', 'value' => 0.1, 'text' => 'Текст 🐾'];
    $snapshotId = $snapshots->append('1', $source, $payload);
    $snapshot = $snapshots->get($snapshotId);
    check($snapshot['content_hash'] === hash('sha256', $snapshot['payload_json']), 'immutable snapshot hash and arithmetic identifiers stored');
    check(json_decode($snapshot['payload_json'], true, 512, JSON_THROW_ON_ERROR) === $payload, 'ACS stores four-byte Unicode even with MyBB utf8 connection');
    $request = ['schema_version' => 1, 'name' => "bound ' value"];
    $op = $operations->register('fixture:once', '1', 'audit', $request);
    check($op === $operations->register('fixture:once', '1', 'audit', $request), 'operation registration idempotent');
    denied(fn() => $operations->register('fixture:once', '1', 'audit', ['schema_version' => 1]), 'different payload cannot reuse operation key');
    $event = ['session_id' => '1', 'operation_key' => 'fixture:once', 'event_type' => 'fixture_audit', 'actor_uid' => '0', 'engine_version' => 'fixture-1'];
    $details = $payload + ['snapshot_refs' => [$snapshotId]];
    $eventId = $store->appendEvent($event, $details, ['schema_version' => 1, 'audience' => 'admin']);
    check($operations->get('fixture:once')['status'] === 'committed' && (string)$store->session('1')['session_version'] === '2', 'event/details/operation/session version committed atomically');
    denied(fn() => $store->appendEvent($event, $details, ['schema_version' => 1]), 'confirmed event cannot apply twice');
    denied(fn() => $operations->transition('fixture:once', '2', 'released', ['schema_version' => 1]), 'terminal operation cannot be overwritten');
    $operations->register('fixture:cas', '1', 'audit', ['schema_version' => 1]);
    $operations->transition('fixture:cas', '1', 'recovery_required', ['schema_version' => 1]);
    denied(fn() => $operations->transition('fixture:cas', '1', 'committed', ['schema_version' => 1]), 'stale CAS rejected', 'ACS_VERSION_CONFLICT');
    $operations->transition('fixture:cas', '2', 'rejected', ['schema_version' => 1]);
    $operations->register('fixture:foreign', '2', 'audit', ['schema_version' => 1]);
    denied(fn() => $store->appendEvent(array_replace($event, ['session_id' => '2', 'operation_key' => 'fixture:foreign']), $details, ['schema_version' => 1]), 'cross-session snapshot rejected');
    check($operations->get('fixture:foreign')['status'] === 'prepared', 'failed event rolls operation back');
    denied(fn() => $store->appendEvent($event + ['encounter_id' => '1'], $details, ['schema_version' => 1]), 'future encounter links blocked');
    foreach (['-1', '18446744073709551616', '1e2', '01'] as $bad) denied(fn() => AF_ACS_Repository::integer($bad), 'integer range '.$bad);
    denied(fn() => AF_ACS_Repository::json(['schema_version' => 1, 'number' => INF]), 'nonfinite JSON blocked');
    denied(fn() => AF_ACS_Repository::json(['schema_version' => 1, 'number' => 9007199254740992]), 'unsafe JSON integer requires decimal string');
    check(!method_exists($snapshots, 'update') && !method_exists($store, 'deleteEvent'), 'no public mutable history API');
    $before = [$store->event($eventId), $snapshots->get($snapshotId), $store->session('1')];
    $mybb->settings['af_advancedcombatsystem_enabled'] = '0';
    af_advancedcombatsystem_deactivate();
    denied(fn() => $snapshots->append('1', $source, $payload), 'writes denied while disabled');
    af_advancedcombatsystem_activate();
    $mybb->settings['af_advancedcombatsystem_enabled'] = '1';
    check($before === [$store->event($eventId), $snapshots->get($snapshotId), $store->session('1')], 'deactivate/reactivate preserves history/settings/versions');
    af_advancedcombatsystem_upgrade();
    check($before === [$store->event($eventId), $snapshots->get($snapshotId), $store->session('1')], 'upgrade preserves snapshots/events/versions');
    denied(fn() => af_advancedcombatsystem_uninstall(), 'uninstall requires explicit confirmation');
    $mybb->settings['af_advancedcombatsystem_enabled'] = '0';
    denied(fn() => af_advancedcombatsystem_uninstall('DELETE EMPTY ACS FOUNDATION'), 'uninstall refuses existing data');
    check($before[0] === $store->event($eventId), 'refused uninstall preserves history');
    $connection->query('ALTER TABLE fixture_af_acs_sessions ADD COLUMN unexpected int NULL');
    denied(fn() => af_advancedcombatsystem_upgrade(), 'schema mismatch blocks without hidden repair', 'ACS_SCHEMA_COLUMNS_MISMATCH');
    check($migrations->status()['error'] === 'ACS_SCHEMA_COLUMNS_MISMATCH', 'ACP status exposes safe compatibility code');
    check($sharedState === $connectionState($connection), 'failed migration restores shared connection settings');
    $connection->query('ALTER TABLE fixture_af_acs_sessions DROP COLUMN unexpected');
    $mybb->request_method = 'get';
    denied(fn() => af_advancedcombatsystem_upgrade(), 'GET cannot migrate');
    $mybb->request_method = 'post';
    $mybb->input['my_post_key'] = 'invalid';
    denied(fn() => af_advancedcombatsystem_upgrade(), 'invalid CSRF cannot migrate', 'CSRF');
    $mybb->input['my_post_key'] = 'fixture-token';
    $connection->begin_transaction();
    denied(fn() => af_advancedcombatsystem_upgrade(), 'runner refuses ambient transaction');
    $connection->rollback();

    // Separate database: real DDL commits, then journal write fails at step 4.
    $interrupted = $fresh();
    $db = (object)['write_link' => $interrupted];
    $interrupted->query(str_replace('{prefix}', TABLE_PREFIX, $definition['tables']['schema_migrations']));
    $interrupted->query("CREATE TRIGGER stop_after_ddl BEFORE UPDATE ON fixture_af_acs_schema_migrations FOR EACH ROW BEGIN IF NEW.step=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated injected failure'; END IF; END");
    denied(fn() => af_advancedcombatsystem_install(), 'interrupted migration reports failure');
    $failed = $interrupted->query('SELECT * FROM fixture_af_acs_schema_migrations')->fetch_assoc();
    check($failed['status'] === 'failed' && (string)$failed['step'] === '3' && strlen($failed['error_id']) === 32, 'journal records failed step and error id');
    check($interrupted->query("SHOW TABLES LIKE 'fixture_af_acs_snapshots'")->num_rows === 1, 'DDL table survives failed journal checkpoint');
    $interrupted->query('DROP TRIGGER stop_after_ddl');
    $interrupted->query("INSERT INTO fixture_af_acs_sessions (tid,owner_uid,status,schema_version,config_json,created_at,updated_at) VALUES (1,1,'active',1,'{\"schema_version\":1}',1,1)");
    // A killed process may leave running, not failed; the same readback resumes it.
    $interrupted->query("UPDATE fixture_af_acs_schema_migrations SET status='running'");
    af_advancedcombatsystem_upgrade();
    check(af_advancedcombatsystem_is_installed() && $interrupted->query('SELECT id FROM fixture_af_acs_sessions')->num_rows === 1, 'resume validates existing objects and retains data');
    $other = new mysqli('localhost', 'root', '', '', 0, $socket);
    $other->select_db($databases[1]); $other->set_charset('utf8mb4');
    $lock = 'acs:'.substr(hash('sha256', $databases[1].':'.TABLE_PREFIX), 0, 56);
    $other->query("SELECT GET_LOCK('".$lock."',0)");
    denied(fn() => af_advancedcombatsystem_upgrade(), 'parallel migration is rejected', 'ACS_MAINTENANCE_BUSY');
    $other->query("SELECT RELEASE_LOCK('".$lock."')");
    $other->close();

    $empty = $fresh(); $db = (object)['write_link' => $empty];
    af_advancedcombatsystem_install();
    check(af_advancedcombatsystem_uninstall('DELETE EMPTY ACS FOUNDATION'), 'confirmed disabled empty uninstall');
    check($empty->query('SHOW TABLES')->num_rows === 0, 'empty uninstall readback');
    af_advancedcombatsystem_install();
    check(af_advancedcombatsystem_is_installed(), 'reinstall after empty uninstall');
    require AF_ADDONS.'advancedcombatsystem/admin.php';
    $mybb->request_method = 'get';
    $journalBefore = $empty->query('SELECT * FROM fixture_af_acs_schema_migrations')->fetch_assoc();
    ob_start(); AF_Admin_Advancedcombatsystem::dispatch(); $html = ob_get_clean();
    check(str_contains($html, '001_foundation') && str_contains($html, 'completed'), 'ACP renders schema and migration result');
    check($journalBefore === $empty->query('SELECT * FROM fixture_af_acs_schema_migrations')->fetch_assoc(), 'ACP GET does not mutate migration state');
    echo "All ACS-1.2 focused checks passed.\n";
} finally {
    foreach ($databases as $database) { $admin->query('DROP DATABASE `'.$database.'`'); }
}
