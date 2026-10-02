<?php
declare(strict_types=1);
define('IN_MYBB', 1);

$root = dirname(__DIR__);
$source = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php');
$manifest = require $root.'/inc/plugins/advancedfunctionality/addons/advancedbuddylist/manifest.php';

if (!is_string($source)) {
    throw new RuntimeException('Unable to read Advanced Buddy List source.');
}

$schemaCall = strpos($source, "function_exists('af_theme_stylesheets_install_schema')");
$schemaWrite = strpos($source, "['addon_id'=>AF_ABDL_ID,'logical_id'=>\$logical,'updated_at'=>TIME_NOW]");
if ($schemaCall === false || $schemaWrite === false || $schemaCall > $schemaWrite) {
    throw new RuntimeException('Theme stylesheet registry schema is not upgraded before Buddy ownership migration.');
}

$escapedBundleWrite = "['stylesheet'=>\$db->escape_string(\$css),'lastmodified'=>TIME_NOW]";
if (!str_contains($source, $escapedBundleWrite)) {
    throw new RuntimeException('Migrated advancedstyles.css is written without SQL escaping.');
}
if (str_contains($source, "['stylesheet'=>\$css,'lastmodified'=>TIME_NOW]")) {
    throw new RuntimeException('Unsafe raw stylesheet SQL write is still present.');
}

$migrationStart = strpos($source, 'function af_abdl_migrate_addon_identity');
$migrationEnd = strpos($source, 'function af_abdl_select_lifecycle_value');
$migration = substr($source, $migrationStart, $migrationEnd - $migrationStart);
$mark = strrpos($migration, '$migrated = true;');
$themeMigration = strpos($migration, 'af_abdl_migrate_theme_stylesheet_ownership();');
if ($mark === false || $themeMigration === false || $mark < $themeMigration) {
    throw new RuntimeException('Buddy identity migration is marked complete before all migration work succeeds.');
}

foreach ([
    "\$stage = 'migrate_identity'",
    "\$stage = 'ensure_schema'",
    "\$stage = 'migrate_legacy_relations'",
    "\$stage = 'install_page_alias'",
    "af_store_addon_lifecycle_diagnostic(AF_ABDL_ID, 'enable', \$stage, \$error)",
    'throw $error;',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing activation diagnostic contract: '.$needle);
    }
}

if (($manifest['id'] ?? '') !== 'advancedbuddylist' || ($manifest['version'] ?? '') !== '2.1.2') {
    throw new RuntimeException('Advanced Buddy List manifest version/id mismatch.');
}

echo "Advanced Buddy List activation migration regression: OK\n";
