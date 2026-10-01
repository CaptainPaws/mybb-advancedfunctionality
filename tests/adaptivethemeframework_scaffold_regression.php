<?php

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
$manifest = require $addon . '/manifest.php';

if (($manifest['id'] ?? '') !== 'adaptivethemeframework'
    || ($manifest['name'] ?? '') !== 'Adaptive Theme Framework'
    || ($manifest['bootstrap'] ?? '') !== 'adaptivethemeframework.php') {
    throw new RuntimeException('Adaptive Theme Framework discovery metadata is invalid.');
}

if (($manifest['frontend'] ?? []) !== [
    'mode' => 'global',
    'directory_fallback' => false,
]) {
    throw new RuntimeException('Adaptive Theme Framework must declare a global manifest frontend context.');
}

if (($manifest['assets']['front'] ?? null) !== ['css' => [], 'js' => []]) {
    throw new RuntimeException('Adaptive Theme Framework must not bypass theme stylesheet delivery.');
}

$stylesheet = $manifest['theme_stylesheets'][0] ?? [];
if (($stylesheet['file'] ?? '') !== 'assets/adaptivethemeframework.css'
    || ($stylesheet['enabled_setting'] ?? '') !== 'af_adaptivethemeframework_enabled') {
    throw new RuntimeException('Adaptive Theme Framework design system must use the AF theme stylesheet pipeline.');
}

if (str_contains(strtolower(serialize($manifest)), 'blacklist')) {
    throw new RuntimeException('Adaptive Theme Framework must not declare blacklist rules.');
}

define('IN_MYBB', true);
define('AF_ADDONS', dirname($addon) . '/');
require $addon . '/adaptivethemeframework.php';

foreach (['install', 'is_installed', 'activate', 'deactivate', 'uninstall'] as $operation) {
    $function = 'af_adaptivethemeframework_' . $operation;
    if (!function_exists($function) || $function() !== true) {
        throw new RuntimeException("Adaptive Theme Framework {$operation} lifecycle failed.");
    }
}

if (!is_dir(dirname($addon) . '/advresponsivelayout')) {
    throw new RuntimeException('The existing advresponsivelayout addon must remain present.');
}

echo "Adaptive Theme Framework scaffold and lifecycle passed.\n";
