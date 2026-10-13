<?php

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'apstudio.php');

require_once __DIR__ . '/global.php';

/** AF_AA_STUDIO_PAGE_ALIAS */

/* Public alias for the existing AdvancedAppearance workshop.
 * Keep routing and personal preset API in the canonical apstudio handler.
 */
if (function_exists('af_aa_render_apstudio_page')) {
    af_aa_render_apstudio_page();
    exit;
}

error_no_permission();
exit;
