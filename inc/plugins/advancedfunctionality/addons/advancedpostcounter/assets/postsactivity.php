<?php
/**
 * AF: AdvancedPostCounter postsactivity page
 * AF_APC_PAGE_ALIAS
 *
 * Этот файл копируется в корень форума как /postsactivity.php
 * и рендерит страницу ПОСЛЕ подключения global.php.
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'postsactivity.php');

require_once __DIR__ . '/global.php';

if (function_exists('af_apc_render_postsactivity_page')) {
    af_apc_render_postsactivity_page();
    exit;
}

if (function_exists('error_no_permission')) {
    error_no_permission();
}
exit;
