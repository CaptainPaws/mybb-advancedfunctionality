<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$profileTemplate = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/member_profile.html');
$profileAddon = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$profileJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/advancedprofileui.js');
$sheetTemplate = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/templates/charactersheet_inner.html');
$inventoryEntry = file_get_contents($root . '/inventory.php');

function member_profile_inventory_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$profileTemplate, $profileAddon, $profileJs, $sheetTemplate, $inventoryEntry] as $source) {
    member_profile_inventory_assert($source !== false, 'A required source file could not be read');
}

member_profile_inventory_assert(strpos($profileTemplate, 'data-tab="inventory"') === false, 'Standalone Inventory profile tab is still registered');
member_profile_inventory_assert(strpos($profileTemplate, 'data-panel="inventory"') === false, 'Orphaned Inventory profile panel remains');
member_profile_inventory_assert(strpos($profileTemplate, 'af_apui_inventory_tab') === false, 'Inventory profile template variable remains');
member_profile_inventory_assert(strpos($profileAddon, 'af_apui_inventory_tab') === false, 'Inventory profile variable is still prepared');
member_profile_inventory_assert(strpos($profileAddon, 'af_apui_build_member_profile_inventory_tab') === false, 'Inventory profile renderer remains');

preg_match_all('/data-tab="([^"]+)"/', $profileTemplate, $tabMatches);
preg_match_all('/data-panel="([^"]+)"/', $profileTemplate, $panelMatches);
member_profile_inventory_assert($tabMatches[1] === $panelMatches[1], 'Profile tab and panel registries are not aligned');
member_profile_inventory_assert(strpos($profileJs, "? fromHash : 'info'") !== false, 'Unknown or removed profile hashes no longer fall back to the info tab');

member_profile_inventory_assert(strpos($sheetTemplate, 'data-afcs-tab="inventory"') !== false, 'Character Sheet Inventory tab was removed');
member_profile_inventory_assert(strpos($sheetTemplate, '{$sheet_inventory_html}') !== false, 'Character Sheet Inventory content was removed');
member_profile_inventory_assert(strpos($inventoryEntry, "af_advancedinventory_render_inventory_page") !== false, 'Standalone Inventory entry point was removed');

fwrite(STDOUT, "Member profile Inventory tab regression checks passed.\n");
