<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'charactersheets.php');
define('AF_CHARACTERSHEETS_PAGE_ALIAS', 1);

require_once __DIR__ . '/../../../../../../global.php';

if (!function_exists('af_charactersheets_render_page')) {
    error_no_permission();
}

af_charactersheets_render_page();
exit;
