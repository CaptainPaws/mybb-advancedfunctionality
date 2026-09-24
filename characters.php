<?php
define('AF_CHARACTERS_PAGE_ALIAS', 1);
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'characters.php');
require_once __DIR__ . '/global.php';
if (!function_exists('af_characters_render_page')) error('AdvancedCharacters is unavailable.');
af_characters_render_page();
exit;
