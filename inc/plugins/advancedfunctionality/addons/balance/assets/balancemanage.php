<?php
/**
 * AF_BALANCE_PAGE_ALIAS
 */
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'balancemanage.php');
require_once __DIR__ . '/global.php';

if (function_exists('af_balance_render_balancemanage')) {
    af_balance_render_balancemanage();
}

error_no_permission();
