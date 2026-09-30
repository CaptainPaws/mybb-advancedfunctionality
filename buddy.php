<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'buddy.php');
require_once __DIR__ . '/global.php';
if (!function_exists('af_abdl_render_page')) { error('Advanced Buddy List is unavailable.'); }
af_abdl_render_page();
exit;
