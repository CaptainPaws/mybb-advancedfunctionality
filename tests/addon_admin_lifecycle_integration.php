<?php

declare(strict_types=1);

/*
 * Exercise the same public methods used by the ACP router.  This is deliberately
 * not an extracted-function/eval test: the complete AF core and checked-in
 * router are loaded, addon discovery runs against a real manifest/bootstrap,
 * and the lifecycle methods use a small in-memory MyBB DB/settings adapter.
 */

$root = dirname(__DIR__).'/';
$fixtureDir = $root.'inc/plugins/advancedfunctionality/addons/lifecyclecontrol/';
$settingsFile = $root.'inc/settings.php';
$settingsBackup = is_file($settingsFile) ? file_get_contents($settingsFile) : null;

final class LifecycleQuery
{
    public function __construct(public array $rows = [], public int $offset = 0) {}
}

final class LifecycleDb
{
    public array $settings = [];
    private array $settingIds = [];
    private int $nextId = 1;

    public function escape_string(string $value): string { return addslashes($value); }
    public function build_create_table_collation(): string { return '' ; }
    public function table_exists(string $table): bool { return true; }
    public function write_query(string $sql): LifecycleQuery { return new LifecycleQuery(); }
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): LifecycleQuery
    {
        if ($table === 'settinggroups') {
            return new LifecycleQuery([['gid' => 1]]);
        }
        if ($table === 'settings') {
            if (preg_match("~name='([^']+)'~", $where, $match) && array_key_exists(stripslashes($match[1]), $this->settings)) {
                $name = stripslashes($match[1]);
                return new LifecycleQuery([['sid' => $this->settingIds[$name], 'value' => $this->settings[$name]]]);
            }
            return new LifecycleQuery();
        }
        return new LifecycleQuery();
    }
    public function fetch_array(LifecycleQuery $query): array|false { return $query->rows[$query->offset++] ?? false; }
    public function fetch_field(LifecycleQuery $query, string $field): mixed
    {
        $row = $query->rows[0] ?? [];
        return $row[$field] ?? false;
    }
    public function insert_query(string $table, array $data): int
    {
        $id = $this->nextId++;
        if ($table === 'settings' && isset($data['name'])) {
            $name = (string)$data['name'];
            $this->settings[$name] = (string)($data['value'] ?? '');
            $this->settingIds[$name] = $id;
        }
        return $id;
    }
    public function update_query(string $table, array $data, string $where = ''): void
    {
        if ($table === 'settings' && preg_match("~name='([^']+)'~", $where, $match) && isset($data['value'])) {
            $this->settings[stripslashes($match[1])] = (string)$data['value'];
        }
        if ($table === 'settings' && preg_match('~sid=(\\d+)~', $where, $match) && isset($data['value'])) {
            $name = array_search((int)$match[1], $this->settingIds, true);
            if ($name !== false) $this->settings[$name] = (string)$data['value'];
        }
    }
    public function delete_query(string $table, string $where = ''): void {}
}

final class LifecyclePlugins { public function add_hook(...$arguments): void {} }
final class MyBB { public const INPUT_INT = 1; }
final class LifecycleMybb
{
    public array $settings = [];
    public function get_input(string $name, int $type = 0): mixed { return ''; }
}

function rebuild_settings(): void
{
    global $db, $settingsFile;
    $settings = $db->settings;
    file_put_contents($settingsFile, "<?php\n\$settings = ".var_export($settings, true).";\n");
}

function update_theme_stylesheet_list(int $tid = 0, bool $update_disporders = false, bool $update_cache = true): void {}

try {
    mkdir($fixtureDir, 0777, true);
    file_put_contents($fixtureDir.'manifest.php', <<<'PHP'
<?php
if (!defined('IN_MYBB')) die('No direct access');
return ['id'=>'lifecyclecontrol', 'name'=>'Lifecycle Control', 'bootstrap'=>'lifecyclecontrol.php'];
PHP);
    file_put_contents($fixtureDir.'lifecyclecontrol.php', <<<'PHP'
<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_lifecyclecontrol_install(): void { $GLOBALS['lifecycle_install_calls'] = ($GLOBALS['lifecycle_install_calls'] ?? 0) + 1; }
function af_lifecyclecontrol_deactivate(): void { $GLOBALS['lifecycle_deactivate_calls'] = ($GLOBALS['lifecycle_deactivate_calls'] ?? 0) + 1; }
PHP);

    define('IN_MYBB', 1);
    define('IN_ADMINCP', 1);
    define('MYBB_ROOT', $root);
    define('TABLE_PREFIX', 'mybb_');
    define('TIME_NOW', 1700000000);
    define('AF_ADMIN_SKIP_DISPATCH', true);

    $db = new LifecycleDb();
    $plugins = new LifecyclePlugins();
    $mybb = new LifecycleMybb();
    require $root.'inc/plugins/advancedfunctionality.php';
    require $root.'inc/plugins/advancedfunctionality/admin/router.php';

    $sentinel = static fn(): string => 'request-still-valid';
    foreach ([
        'af_advancedmenu_system_registry',
        'af_response_fact_providers',
        'af_frontend_owners',
        'af_self_heal_registry',
    ] as $registry) {
        $GLOBALS[$registry] = ['sentinel' => $sentinel];
    }
    $GLOBALS['af_templates_synced_runtime'] = true;
    $GLOBALS['af_theme_stylesheets_synced_runtime'] = true;

    AF_Admin::enableAddon('lifecyclecontrol');
    if (!AF_Admin::isAddonEnabled('lifecyclecontrol')) throw new RuntimeException('enableAddon did not publish enabled settings');
    AF_Admin::disableAddon('lifecyclecontrol');
    if (AF_Admin::isAddonEnabled('lifecyclecontrol')) throw new RuntimeException('disableAddon left the addon enabled');
    AF_Admin::enableAddon('lifecyclecontrol');
    if (!AF_Admin::isAddonEnabled('lifecyclecontrol')) throw new RuntimeException('second enableAddon did not reactivate the addon');

    if (($GLOBALS['lifecycle_install_calls'] ?? 0) !== 2 || ($GLOBALS['lifecycle_deactivate_calls'] ?? 0) !== 1) {
        throw new RuntimeException('real addon lifecycle callbacks were not executed in the ACP sequence');
    }
    foreach (['af_advancedmenu_system_registry', 'af_response_fact_providers', 'af_frontend_owners', 'af_self_heal_registry'] as $registry) {
        if (($GLOBALS[$registry]['sentinel'] ?? null) !== $sentinel) throw new RuntimeException($registry.' was destroyed during the ACP request');
    }
    if ($GLOBALS['af_templates_synced_runtime'] !== true || $GLOBALS['af_theme_stylesheets_synced_runtime'] !== true) {
        throw new RuntimeException('completed runtime synchronization was incorrectly reopened');
    }

    foreach (['advancededitor', 'advancedmenu', 'advancedbyddylist'] as $id) {
        if (AF_Admin::addonBootstrap($id) === null) throw new RuntimeException('real addon discovery failed for '.$id);
    }

    echo "AF_Admin enable/disable/enable integration passed; callbacks, settings, discovery, and request registries remained coherent.\n";
} finally {
    @unlink($fixtureDir.'manifest.php');
    @unlink($fixtureDir.'lifecyclecontrol.php');
    @rmdir($fixtureDir);
    if ($settingsBackup === null) @unlink($settingsFile); else file_put_contents($settingsFile, $settingsBackup);
}
