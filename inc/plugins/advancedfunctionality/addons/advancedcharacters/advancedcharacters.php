<?php
if (!defined('IN_MYBB')) die('No direct access');

define('AF_CHARACTERS_ID', 'advancedcharacters');
define('AF_CHARACTERS_PAGE_ALIAS_SIGNATURE', 'AF_CHARACTERS_PAGE_ALIAS');

function af_advancedcharacters_install(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_activate(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_upgrade(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_deactivate(): void { af_characters_remove_page_alias(); }
function af_advancedcharacters_uninstall(): void { af_characters_remove_page_alias(); }

function af_advancedcharacters_init(): void
{
}

function af_characters_ensure_page_alias(): bool
{
    $source = __DIR__ . '/assets/characters.php';
    $target = MYBB_ROOT . 'characters.php';
    if (!is_file($source)) return false;
    if (is_file($target)) {
        $existing = (string)@file_get_contents($target);
        if (strpos($existing, AF_CHARACTERS_PAGE_ALIAS_SIGNATURE) === false) return false;
        if (hash_file('sha256', $source) === hash_file('sha256', $target)) return true;
    }
    if (!@copy($source, $target)) return false;
    @chmod($target, 0644);
    return true;
}

function af_characters_remove_page_alias(): void
{
    $target = MYBB_ROOT . 'characters.php';
    if (is_file($target) && strpos((string)@file_get_contents($target), AF_CHARACTERS_PAGE_ALIAS_SIGNATURE) !== false) @unlink($target);
}

/** Render the KB Character list directly; this addon owns no character data. */
function af_characters_render_page(): void
{
    global $mybb;
    if (!function_exists('af_kb_render_kb_page')) error('KnowledgeBase is unavailable.');
    $mybb->input['action'] = 'kb';
    $mybb->input['type'] = 'character';
    af_kb_render_kb_page();
}
