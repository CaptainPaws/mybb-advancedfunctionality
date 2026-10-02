<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('AF_ADDONS', dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS.'advancedbuddylist/advancedbuddylist.php';

$cases = [
    [['af_advancedbyddylist_enabled'=>'1','af_abdl_enabled'=>'0'], '1'],
    [['af_advancedbyddylist_enabled'=>'0','af_abdl_enabled'=>'1'], '0'],
    [['af_advancedbuddylist_enabled'=>'1','af_advancedbyddylist_enabled'=>'0','af_abdl_enabled'=>'0'], '1'],
    [['af_advancedbuddylist_enabled'=>'0','af_advancedbyddylist_enabled'=>'1','af_abdl_enabled'=>'1'], '0'],
    [['af_abdl_enabled'=>'0'], '0'],
    [[], '1'],
];
foreach ($cases as [$settings,$expected]) {
    if (af_abdl_select_lifecycle_value($settings) !== $expected) {
        throw new RuntimeException('Lifecycle migration priority failed for '.json_encode($settings));
    }
}
$source=file_get_contents(AF_ADDONS.'advancedbuddylist/advancedbuddylist.php');
foreach ([
    "name IN ('af_advancedbyddylist_enabled','af_abdl_enabled')",
    "['af_advancedbyddylist','af_abdl']",
    "'addon_id'=>AF_ABDL_ID",
    "af_theme_stylesheet_encode_section(\$meta,(string)\$section['body'])",
    'User relations are retained deliberately',
] as $needle) {
    if (!str_contains($source,$needle)) throw new RuntimeException('Missing safe migration contract: '.$needle);
}
if (str_contains(substr($source, strpos($source, 'function af_abdl_migrate_addon_identity'), strpos($source, 'function af_abdl_migrate_theme_stylesheet_ownership')-strpos($source, 'function af_abdl_migrate_addon_identity')), "delete_query(AF_ABDL_")) {
    throw new RuntimeException('Identity migration must not delete relation rows.');
}
$manifest=require AF_ADDONS.'advancedbuddylist/manifest.php';
if (($manifest['legacy_ids']??[]) !== ['advancedbyddylist']) throw new RuntimeException('Legacy discovery alias is missing.');
if (($manifest['legacy_lifecycle_settings']??[]) !== ['af_abdl_enabled']) throw new RuntimeException('Secondary legacy switch cannot trigger migration.');
echo "Advanced Buddy List canonical ID migration checks passed.\n";
