<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'inventory.php');
define('AF_ADVANCEDINVENTORY_PAGE_ALIAS', 1);

require_once __DIR__ . '/global.php';

if (!function_exists('af_advancedinventory_render_inventory_page')) {
    error_no_permission();
}

af_advancedinventory_render_inventory_page();
exit;
