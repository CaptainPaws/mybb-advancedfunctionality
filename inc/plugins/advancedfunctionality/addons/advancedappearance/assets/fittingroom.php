<?php

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'fittingroom.php');

require_once __DIR__ . '/global.php';

/** AF_AA_FITTINGROOM_PAGE_ALIAS */

if (function_exists('af_aa_render_fittingroom_page')) {
    af_aa_render_fittingroom_page();
    exit;
}

error_no_permission();
exit;
