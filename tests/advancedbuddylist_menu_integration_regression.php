<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

$source = file_get_contents(AF_ADDONS.'advancedbuddylist/advancedbuddylist.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read Advanced Buddy List source.');
}
if (strpos($source, 'af_advancedbuddylist_menu_provider') !== false
    || strpos($source, "af_menu_register_item") !== false
    || strpos($source, "'key' => 'friends'") !== false
) {
    throw new RuntimeException('Advanced Buddy List must not auto-register a Friends menu item.');
}
if (strpos($source, "['url'=>'buddy.php?tab=friends']") === false) {
    throw new RuntimeException('Buddy request notification destination was removed with menu integration.');
}
echo "Advanced Buddy List manual menu ownership regression: OK\n";
