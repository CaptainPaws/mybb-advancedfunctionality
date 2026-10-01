<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$corePath = $root.'/inc/plugins/advancedfunctionality.php';
$routerPath = $root.'/inc/plugins/advancedfunctionality/admin/router.php';
$core = file_get_contents($corePath);
$router = file_get_contents($routerPath);

if (preg_match_all('/^class AF_Admin$/m', $core) !== 1) {
    throw new RuntimeException('Canonical core must contain exactly one AF_Admin definition');
}
if (str_contains($router, 'class AF_Admin') || str_contains($router, 'function dispatch')) {
    throw new RuntimeException('ACP router contains business logic instead of a thin proxy');
}
foreach (["if (!defined('IN_MYBB'))", "require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';", 'AF_Admin::dispatch();'] as $line) {
    if (!str_contains($router, $line)) throw new RuntimeException('ACP router proxy is incomplete: '.$line);
}
if (str_contains($core, 'af_ensure_admin_router') || str_contains($core, 'router.dist.php') || str_contains($core, "file_put_contents(\$router")) {
    throw new RuntimeException('Obsolete generated-router lifecycle remains in canonical core');
}
if (file_exists($root.'/inc/plugins/advancedfunctionality/admin/router.dist.php')) {
    throw new RuntimeException('router.dist.php must not exist');
}

$before = hash_file('sha256', $routerPath);
// Activation/scaffolding source must never carry or overwrite ACP business logic.
$scaffoldStart = strpos($core, 'function af_ensure_scaffold');
$scaffoldEnd = strpos($core, 'function af_write_admin_proxy', $scaffoldStart);
$scaffold = substr($core, $scaffoldStart, $scaffoldEnd - $scaffoldStart);
if (str_contains($scaffold, "AF_ADMIN.'router.php'") || str_contains($scaffold, 'class AF_Admin')) {
    throw new RuntimeException('Reactivate can still rewrite the stable ACP router');
}
if ($before !== hash_file('sha256', $routerPath)) throw new RuntimeException('Router unexpectedly changed');

echo "AF ACP has one canonical implementation and a stable thin router proxy.\n";
