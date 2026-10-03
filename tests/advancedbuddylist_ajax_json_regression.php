<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$buddy = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$js = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.js');
$manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php');

foreach ([$buddy,$js,$manifest] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read Buddy List AJAX sources.');
    }
}

foreach ([
    "!empty(\$_POST['ajax'])",
    "!empty(\$_GET['ajax'])",
    "HTTP_X_REQUESTED_WITH",
    "Content-Type: application/json; charset=utf-8",
    "JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES",
] as $needle) {
    if (!str_contains($buddy, $needle)) {
        throw new RuntimeException('Missing server AJAX/JSON contract: '.$needle);
    }
}

foreach ([
    "ajax=1",
    "'Accept':'application/json'",
    "r.headers.get('content-type')",
    "application/json",
    "Сервер вернул HTML вместо JSON",
    "JSON.parse(text)",
] as $needle) {
    if (!str_contains($js, $needle)) {
        throw new RuntimeException('Missing client AJAX/JSON contract: '.$needle);
    }
}

if (!str_contains($manifest, "'version'     => '2.2.2'")) {
    throw new RuntimeException('Advanced Buddy List version mismatch.');
}

echo "Advanced Buddy List AJAX JSON regression: OK\n";
