<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'abilities.php');
define('AF_ADVANCEDABILITIES_PAGE_ALIAS', 1);

require_once __DIR__ . '/global.php';

if (!function_exists('af_advancedinventory_render_abilities_page')) {
    error_no_permission();
}

af_advancedinventory_render_abilities_page();
exit;
