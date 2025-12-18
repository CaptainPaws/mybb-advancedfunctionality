<?php
/**
 * AF: AdvancedPostCounter postsactivity page
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'postsactivity.php');
require_once __DIR__ . '/global.php';

$addon = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php';
if (is_file($addon)) {
    require_once $addon;
}

if (function_exists('af_advancedpostcounter_postsactivity_page')) {
    af_advancedpostcounter_postsactivity_page();
    exit;
}

if (function_exists('error_no_permission')) {
    error_no_permission();
}

exit;
