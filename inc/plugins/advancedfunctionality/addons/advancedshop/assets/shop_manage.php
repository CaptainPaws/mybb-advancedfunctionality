<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'shop_manage.php');
define('AF_ADVANCEDSHOP_MANAGE_PAGE_ALIAS', 1);

require_once __DIR__ . '/global.php';

if (!function_exists('af_advancedshop_render_shop_manage_page')) {
    error_no_permission();
}

af_advancedshop_render_shop_manage_page();
exit;
