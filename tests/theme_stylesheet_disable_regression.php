<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
$router = file_get_contents($root.'/inc/plugins/advancedfunctionality/admin/router.php');

function extractAfFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException('Missing '.$name);
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    throw new RuntimeException('Unterminated '.$name);
}

define('AF_THEME_STYLESHEETS_TABLE', 'af_theme_stylesheets');
define('TIME_NOW', 1700000000);

final class DisableDb
{
    public array $registry = [];
    public function escape_string(string $value): string { return $value; }
    public function update_query(string $table, array $payload, string $where): void
    {
        preg_match("~addon_id='([^']+)'~", $where, $match);
        foreach ($this->registry as &$row) {
            if ((string)$row['addon_id'] === (string)($match[1] ?? '')) $row = array_merge($row, $payload);
        }
    }
}

function af_theme_stylesheets_install_schema(): void {}
function af_theme_stylesheet_deduplicate_registry(): void {}
function af_discover_theme_stylesheets(): array { return $GLOBALS['entries']; }
function af_get_theme_stylesheet_source(array $meta, array $entry): array|false
{
    return empty($entry['css']) ? false : ['source' => $entry['css'], 'checksum' => sha1($entry['css'])];
}
function af_theme_stylesheet_rebase_css_urls(string $css, string $addonId, string $file): string { return $css; }
function af_theme_stylesheet_encode_section(array $meta, string $css): string
{
    return '/* '.$meta['addon_id'].':'.$meta['logical_id'].' */'.$css;
}

eval(extractAfFunction($core, 'af_disable_theme_stylesheet_sources'));
eval(extractAfFunction($core, 'af_theme_stylesheet_build_bundle'));

$db = new DisableDb();
$mybb = (object)['settings' => []];
$entries = [
    ['addon_id' => 'simple', 'logical_id' => 'main', 'file' => '', 'enabled_setting' => 'af_simple_enabled', 'addon_meta' => ['name' => 'Simple'], 'css' => ''],
    ['addon_id' => 'settings', 'logical_id' => 'main', 'file' => 'settings.css', 'enabled_setting' => 'af_settings_enabled', 'addon_meta' => ['name' => 'Settings'], 'css' => '.settings{}'],
    ['addon_id' => 'tables', 'logical_id' => 'main', 'file' => '', 'enabled_setting' => 'af_tables_enabled', 'addon_meta' => ['name' => 'Tables'], 'css' => ''],
    ['addon_id' => 'css_a', 'logical_id' => 'main', 'file' => 'a.css', 'enabled_setting' => 'af_css_a_enabled', 'addon_meta' => ['name' => 'CSS A'], 'css' => '.a{}'],
    ['addon_id' => 'css_b', 'logical_id' => 'main', 'file' => 'b.css', 'enabled_setting' => 'af_css_b_enabled', 'addon_meta' => ['name' => 'CSS B'], 'css' => '.b{}'],
];
$GLOBALS['entries'] =& $entries;
$GLOBALS['db'] =& $db;
$GLOBALS['mybb'] =& $mybb;

foreach ($entries as $index => $entry) {
    $db->registry[] = ['id' => $index + 1, 'theme_tid' => 1, 'stylesheet_sid' => 0,
        'addon_id' => $entry['addon_id'], 'logical_id' => $entry['logical_id'], 'is_integrated' => 0];
    $mybb->settings[$entry['enabled_setting']] = '1';
}

for ($cycle = 1; $cycle <= 3; $cycle++) {
    foreach (['css_a', 'css_b'] as $addonId) {
        $setting = 'af_'.$addonId.'_enabled';
        $mybb->settings[$setting] = '1';
        foreach ($db->registry as &$row) if ($row['addon_id'] === $addonId) $row['is_integrated'] = 1;
        unset($row);
        $before = af_theme_stylesheet_build_bundle();
        if (!str_contains($before['source'], '/* '.$addonId.':main */')) throw new RuntimeException("cycle {$cycle}: enabled section missing");

        $mybb->settings[$setting] = '0';
        af_disable_theme_stylesheet_sources($addonId);
        $after = af_theme_stylesheet_build_bundle();
        if (str_contains($after['source'], '/* '.$addonId.':main */')) throw new RuntimeException("cycle {$cycle}: disabled section retained");
        $state = array_values(array_filter($db->registry, static fn(array $row): bool => $row['addon_id'] === $addonId));
        if (count($state) !== 1 || (int)$state[0]['is_integrated'] !== 0 || (int)$state[0]['stylesheet_sid'] !== 0) {
            throw new RuntimeException("cycle {$cycle}: disabled source registry is inconsistent");
        }
    }
}

$requiredStages = ['ensureEnabledSetting', 'af_rebuild_and_reload_settings', 'disable reconciliation',
    'bundle rebuild', 'require addon bootstrap', "af_'.\$id.'_deactivate()"];
foreach ($requiredStages as $stage) {
    if (!str_contains($router, "af_admin_addon_diagnostic_stage('{$stage}'")) throw new RuntimeException('Missing disable diagnostic stage '.$stage);
}
if (!str_contains($router, "af_admin_addon_diagnostic_stage('enable/disable action'")) throw new RuntimeException('Missing toggle action diagnostic boundary');
foreach (['Addon', 'Stage', 'Exception', 'Message', 'File', 'Line'] as $field) {
    if (!str_contains($core, "'{$field}' =>")) throw new RuntimeException('Missing ACP diagnostic field '.$field);
}
if (!str_contains($core, "'class' => 'MyBB SQL error '.\$dbErrorNumber")) throw new RuntimeException('Missing MyBB SQL error shutdown capture');
if (substr_count($router, "\$fn = 'af_'.\$id.'_deactivate';") !== 1) throw new RuntimeException('Deactivator is not owned exactly once by disableAddon');
if (preg_match("~delete_query\([^;]+af_theme_stylesheets~i", $core)) throw new RuntimeException('Disable introduces destructive registry cleanup');

echo "AF disable cycles passed for simple/settings/table/CSS/no-CSS fixtures; source metadata stayed unique and detached.\n";
