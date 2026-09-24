<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'wanted.php');
require_once __DIR__.'/global.php';
if (!function_exists('af_wanted_render_page')) { error('AdvancedWanted is unavailable.'); }
af_wanted_render_page();
