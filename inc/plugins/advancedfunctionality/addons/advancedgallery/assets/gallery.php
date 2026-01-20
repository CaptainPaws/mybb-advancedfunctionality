<?php
// AF_PAGE_ALIAS: advancedgallery

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'gallery.php');

require_once __DIR__ . '/../../../../../../global.php';

if (function_exists('af_advancedgallery_render_gallery')) {
    af_advancedgallery_render_gallery();
    exit;
}

error_no_permission();
exit;
