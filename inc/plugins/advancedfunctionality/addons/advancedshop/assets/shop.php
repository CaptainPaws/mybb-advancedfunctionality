<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'shop.php');
define('AF_ADVANCEDSHOP_PAGE_ALIAS', 1);

require_once __DIR__ . '/../../../../../../global.php';

if (!function_exists('af_advancedshop_render_shop_page')) {
    error_no_permission();
}

af_advancedshop_render_shop_page();
exit;
