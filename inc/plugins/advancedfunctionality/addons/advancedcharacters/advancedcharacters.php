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
    global $plugins;
    if (isset($plugins) && is_object($plugins)) {
        $plugins->add_hook('pre_output_page', 'af_characters_pre_output_page', 9999);
    }
}

/** Deliver the registered stylesheet only where an AdvancedCharacters view exists. */
function af_characters_pre_output_page(string &$page): void
{
    global $mybb;

    if (empty($mybb->settings['af_advancedcharacters_enabled'])
        || stripos($page, '</head>') === false) {
        return;
    }

    $hasCatalog = strpos($page, 'af-characters-catalog') !== false;
    $hasDetail = strpos($page, 'af-ac-character-detail') !== false;
    if (!$hasCatalog && !$hasDetail) {
        return;
    }

    $assetAllowed = $hasDetail
        ? af_frontend_asset_allowed(AF_CHARACTERS_ID, 'character_detail_stylesheet', null, ['has_character_detail' => true])
        : af_frontend_asset_allowed(AF_CHARACTERS_ID, 'character_catalog_stylesheet', null, ['has_character_catalog' => true]);
    if (!$assetAllowed || strpos($page, 'data-af-advancedcharacters-css') !== false) {
        return;
    }

    $source = 'assets/advancedcharacters.css';
    $delivery = function_exists('af_theme_stylesheet_delivery_decision')
        ? af_theme_stylesheet_delivery_decision(AF_CHARACTERS_ID, $source)
        : ['include_file' => true, 'use_theme_stylesheet' => false, 'theme_href' => ''];

    // On the canonical catalog route MyBB already attaches Theme mode CSS.
    // Other surfaces are loaded here with the same Theme/File delivery choice.
    if (!empty($delivery['use_theme_stylesheet']) && defined('THIS_SCRIPT') && THIS_SCRIPT === 'characters.php') {
        return;
    }

    $href = '';
    if (!empty($delivery['use_theme_stylesheet']) && !empty($delivery['theme_href'])) {
        $href = (string)$delivery['theme_href'];
    } elseif (!empty($delivery['include_file'])) {
        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        if ($bburl !== '') {
            $file = __DIR__ . '/' . $source;
            $version = (string)(@filemtime($file) ?: '1');
            $href = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_CHARACTERS_ID . '/' . $source . '?v=' . rawurlencode($version);
        }
    }

    if ($href === '') {
        return;
    }

    $tag = '<link rel="stylesheet" href="' . htmlspecialchars_uni($href) . '" data-af-advancedcharacters-css>';
    $page = preg_replace('~</head>~i', $tag . "\n</head>", $page, 1) ?? $page;
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
