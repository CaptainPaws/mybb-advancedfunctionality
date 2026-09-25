<?php
if (!defined('IN_MYBB')) die('No direct access');

define('AF_CHARACTERS_ID', 'advancedcharacters');
define('AF_CHARACTERS_PAGE_ALIAS_SIGNATURE', 'AF_CHARACTERS_PAGE_ALIAS');

function af_advancedcharacters_menu_provider(): void
{
    af_menu_register_item(['key'=>'characters','source_addon'=>AF_CHARACTERS_ID,'label'=>'Персонажи','icon'=>'fa-solid fa-masks-theater','type'=>'link','default_container'=>'main','default_sortorder'=>70,'visibility'=>true,'action'=>['url'=>'characters.php']]);
}


function af_advancedcharacters_install(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_activate(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_upgrade(): bool { return af_characters_ensure_page_alias(); }
function af_advancedcharacters_deactivate(): void { af_characters_remove_page_alias(); }
function af_advancedcharacters_uninstall(): void { af_characters_remove_page_alias(); }

function af_advancedcharacters_init(): void
{
    global $plugins;
    if (is_object($plugins)) $plugins->add_hook('pre_output_page', 'af_characters_add_moderator_link', 30);
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

function af_characters_add_moderator_link(&$page): void
{
    global $mybb;
    // AdvancedMenu owns this item (including visibility and ordering).  Keep
    // the old injector only as a compatibility fallback when it is disabled.
    if (function_exists('af_menu_configured_registry')
        && !empty($mybb->settings['af_advancedmenu_enabled'])) return;
    if (!is_string($page) || strpos($page, 'data-af-characters-mod-link') !== false) return;
    $isStaff = !empty($mybb->usergroup['cancp']) || !empty($mybb->usergroup['canmodcp'])
        || (!empty($mybb->user['uid']) && function_exists('is_moderator') && is_moderator());
    if (!$isStaff) return;
    $link = '<li class="af-characters-mod-link" data-af-characters-mod-link="1"><a href="characters.php">Персонажи</a></li>';
    $patched = preg_replace('~(<li[^>]*>\s*<a[^>]+href=["\'][^"\']*modcp\.php[^"\']*["\'][^>]*>.*?</a>\s*</li>)~is', '$1' . $link, $page, 1);
    if (is_string($patched)) $page = $patched;
}
