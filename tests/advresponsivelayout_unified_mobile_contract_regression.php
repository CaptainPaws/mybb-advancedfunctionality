<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advresponsivelayout';
$php = file_get_contents($root.'/advresponsivelayout.php');
$css = file_get_contents($root.'/assets/advresponsivelayout.css');
$js = file_get_contents($root.'/assets/advresponsivelayout.js');

if (!is_string($php) || !is_string($css) || !is_string($js)) {
    throw new RuntimeException('Responsive layout sources are unreadable');
}

foreach (['--af-mobile-page-gap', '--af-mobile-section-gap', '--af-mobile-card-gap'] as $variable) {
    if (strpos($css, $variable) === false) {
        throw new RuntimeException("Missing shared spacing variable {$variable}");
    }
}

foreach (['af-rwd-index', 'af-rwd-member', 'af-rwd-shop', 'af-rwd-modcp', 'af-rwd-private',
          'af-rwd-misc-docs', 'af-rwd-kb', 'af-rwd-userlist'] as $pageClass) {
    if (strpos($css, $pageClass) === false) {
        throw new RuntimeException("Missing responsive page section {$pageClass}");
    }
}

foreach (['cloneNode(', 'af-rwd-right-trigger', 'af-rwd-main-nav-host', 'af-rwd-extra-overlay'] as $menuRuntime) {
    if (strpos($js, $menuRuntime) !== false) {
        throw new RuntimeException("Adaptive layout still owns mobile navigation: {$menuRuntime}");
    }
}

if (strpos($css, 'overflow-x: hidden') !== false || strpos($css, 'min-width: 320px') !== false) {
    throw new RuntimeException('Responsive layout still masks page overflow');
}

if (strpos($php, "'modcp.php' => 'af-rwd-modcp'") === false) {
    throw new RuntimeException('ModCP page alias is missing');
}

echo "Adaptive responsive unified mobile contract passed.\n";
