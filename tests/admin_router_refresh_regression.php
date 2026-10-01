<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$corePath = $root.'/inc/plugins/advancedfunctionality.php';
$routerPath = $root.'/inc/plugins/advancedfunctionality/admin/router.php';
$core = (string)file_get_contents($corePath);
$router = (string)file_get_contents($routerPath);

// There must be exactly one canonical AF_Admin declaration in the repository.
$definitions = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/.git/')) continue;
    $source = (string)file_get_contents($file->getPathname());
    if (preg_match('/^class\s+AF_Admin\s*$/m', $source)) {
        $definitions[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
if ($definitions !== ['inc/plugins/advancedfunctionality.php']) {
    throw new RuntimeException('Expected one canonical AF_Admin definition, found: '.implode(', ', $definitions));
}
if (str_contains($router, 'class AF_Admin') || str_contains($router, 'function dispatch')) {
    throw new RuntimeException('ACP router contains business logic instead of a thin proxy');
}
foreach (["if (!defined('IN_MYBB'))", "require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';", 'AF_Admin::dispatch();'] as $line) {
    if (!str_contains($router, $line)) throw new RuntimeException('ACP router proxy is incomplete: '.$line);
}
if (str_contains($core, 'router.dist.php') || file_exists($root.'/inc/plugins/advancedfunctionality/admin/router.dist.php')) {
    throw new RuntimeException('router.dist.php must not exist');
}

function extractFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    $brace = $start === false ? false : strpos($source, '{', $start);
    if ($start === false || $brace === false) throw new RuntimeException('Unable to find '.$name);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    throw new RuntimeException('Unable to parse '.$name);
}

// A Reactivate-style ensure operation must replace the legacy generated class.
$tmp = sys_get_temp_dir().'/af_admin_router_'.bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
define('AF_ADMIN', $tmp.'/');
eval(extractFunction($core, 'af_admin_router_proxy_contents'));
eval(extractFunction($core, 'af_ensure_admin_router_proxy'));
$legacy = "<?php\n// AF-GENERATED: admin-router v3\nclass AF_Admin { public static function dispatch() {} }\n";
file_put_contents(AF_ADMIN.'router.php', $legacy);
if (!af_ensure_admin_router_proxy()) throw new RuntimeException('Could not replace the legacy router');
$migrated = (string)file_get_contents(AF_ADMIN.'router.php');
if ($migrated !== af_admin_router_proxy_contents() || str_contains($migrated, 'class AF_Admin')) {
    throw new RuntimeException('Legacy router was not replaced with the thin proxy');
}
unlink(AF_ADMIN.'router.php');
rmdir($tmp);

// The exception at the first dispatch dependency proves router + core loaded and
// AF_Admin was callable without PHP encountering a duplicate class declaration.
$probe = <<<'PROBE'
define('IN_MYBB', true);
define('MYBB_ROOT', %s);
class AFRouterProbeLang { public function load($name) { throw new RuntimeException('dispatch reached'); } }
class AFRouterProbePlugins { public function add_hook(...$args) {} }
$lang = new AFRouterProbeLang();
$plugins = new AFRouterProbePlugins();
$mybb = $page = new stdClass();
try {
    require %s;
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'dispatch reached') { echo 'ok'; exit(0); }
    throw $e;
}
exit(2);
PROBE;
$probeRoot = sys_get_temp_dir().'/af_admin_load_'.bin2hex(random_bytes(4));
$probePluginDir = $probeRoot.'/inc/plugins/advancedfunctionality';
mkdir($probePluginDir.'/admin', 0777, true);
copy($corePath, $probeRoot.'/inc/plugins/advancedfunctionality.php');
copy($routerPath, $probePluginDir.'/admin/router.php');
$probeRouter = $probePluginDir.'/admin/router.php';
$probe = sprintf($probe, var_export($probeRoot.'/', true), var_export($probeRouter, true));
$probeFile = tempnam(sys_get_temp_dir(), 'af_router_probe_');
file_put_contents($probeFile, "<?php\n".$probe);
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($probeFile).' 2>&1', $output, $status);
unlink($probeFile);
$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($probeRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanup as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($probeRoot);
if ($status !== 0 || implode("\n", $output) !== 'ok') {
    throw new RuntimeException('Router/core load probe failed: '.implode("\n", $output));
}

echo "AF ACP has one canonical implementation, migrates legacy routers, and loads without duplicate classes.\n";
