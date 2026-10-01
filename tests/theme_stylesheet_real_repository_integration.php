<?php

declare(strict_types=1);

// Load the production implementation. Only the MyBB runtime/DB boundary is
// supplied by this test; discovery, manifests and CSS reads are all real.
define('IN_MYBB', 1);
define('MYBB_ROOT', dirname(__DIR__).'/');
define('TIME_NOW', 1700000000);
define('TABLE_PREFIX', 'mybb_');

final class MyBB { public const INPUT_INT = 1; }
final class RepositoryPlugins { public function add_hook(...$arguments): void {} }
final class RepositoryMyBB {
    public array $settings = [];
    public function get_input(string $name, int $type = 0): string|int { return $type === MyBB::INPUT_INT ? 0 : ''; }
}
final class RepositoryThemeDb {
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): array
    {
        if ($table !== 'themes') throw new RuntimeException("Unexpected table {$table}");
        return ['rows' => [['tid' => 1], ['tid' => 2]], 'position' => 0];
    }
    public function fetch_array(array &$query): array|false { return $query['rows'][$query['position']++] ?? false; }
}

$plugins = new RepositoryPlugins();
$mybb = new RepositoryMyBB();
$db = new RepositoryThemeDb();
require MYBB_ROOT.'inc/plugins/advancedfunctionality.php';

$addons = af_discover_addons();
$entries = af_discover_theme_stylesheets($addons);
foreach ($entries as $entry) {
    $setting = (string)($entry['enabled_setting'] ?? '');
    if ($setting !== '') $mybb->settings[$setting] = '1';
}
$themes = af_get_theme_tids();
$bundle = af_theme_stylesheet_build_bundle();

if (!$addons) throw new RuntimeException('Real addon discovery returned no addons');
if (!$entries) throw new RuntimeException('Real manifest/CSS discovery returned no entries');
if ($themes !== [1, 2]) throw new RuntimeException('Real theme query did not retain Theme #1 and Theme #2');
if (count($bundle['sources']) !== count($entries)) throw new RuntimeException('Enabled real CSS source count differs from discovery count');
if (count($bundle['sections']) !== count($entries)) throw new RuntimeException('Real bundle omitted manifest CSS sections');
foreach ($bundle['source_diagnostics'] as $source) {
    if (empty($source['included'])) throw new RuntimeException('Enabled source was skipped: '.json_encode($source));
}

printf(
    "Real repository discovery: addons=%d; manifest CSS entries=%d; themes=%s; enabled=%d; sections=%d.\n",
    count($addons), count($entries), implode(',', $themes), count($bundle['sources']), count($bundle['sections'])
);
