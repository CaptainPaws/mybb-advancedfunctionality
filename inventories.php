<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'inventories.php');
define('AF_ADVANCEDINVENTORIES_PAGE_ALIAS', 1);

require_once __DIR__ . '/global.php';

if (!function_exists('af_advancedinventory_render_inventories_page')) {
    error_no_permission();
}

af_advancedinventory_render_inventories_page();
exit;
