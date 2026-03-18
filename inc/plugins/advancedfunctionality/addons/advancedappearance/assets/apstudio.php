<?php

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'apstudio.php');

require_once __DIR__ . '/global.php';

/** AF_AA_APSTUDIO_PAGE_ALIAS */

if (function_exists('af_aa_render_apstudio_page')) {
    af_aa_render_apstudio_page();
    exit;
}

error_no_permission();
exit;
