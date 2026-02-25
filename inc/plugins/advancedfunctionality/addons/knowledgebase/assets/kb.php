<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'kb.php');
define('AF_KB_PAGE_ALIAS', 1);

require_once __DIR__ . '/../../../../../../global.php';

if (!function_exists('af_kb_render_kb_page')) {
    die('KnowledgeBase is unavailable.');
}

af_kb_render_kb_page();
exit;
