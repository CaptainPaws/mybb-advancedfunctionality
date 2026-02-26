<?php
/**
 * AF: AdvancedPostCounter postsbyuser page
 * AF_APC_POSTSBYUSER_ALIAS
 *
 * Этот файл копируется в корень форума как /postsbyuser.php
 * и рендерит страницу ПОСЛЕ подключения global.php.
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'postsbyuser.php');

require_once __DIR__ . '/global.php';

if (function_exists('af_advancedpostcounter_render_postsbyuser_page')) {
    af_advancedpostcounter_render_postsbyuser_page();
    exit;
}

if (function_exists('error_no_permission')) {
    error_no_permission();
}
exit;
