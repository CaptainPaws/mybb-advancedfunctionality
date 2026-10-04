<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$profileTemplate = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/templates/member_profile.html');
$profileAddon = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$profileJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/advancedprofileui.js');
$corePlugin = file_get_contents($root . '/inc/plugins/advancedfunctionality.php');
$atfCss = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css');
$sheetTemplate = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/templates/charactersheet_inner.html');
$inventoryEntry = file_get_contents($root . '/inventory.php');
$inventoryJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedinventory/assets/advancedinventory.js');
$inventoryManifest = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedinventory/manifest.php');
$inventoryAddon = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php');
$sheetRenderer = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');

function member_profile_inventory_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$profileTemplate, $profileAddon, $profileJs, $corePlugin, $atfCss, $sheetTemplate, $inventoryEntry, $inventoryJs, $inventoryManifest, $inventoryAddon, $sheetRenderer] as $source) {
    member_profile_inventory_assert($source !== false, 'A required source file could not be read');
}

member_profile_inventory_assert(strpos($profileTemplate, 'data-tab="inventory"') !== false, 'Inventory profile tab is not registered');
member_profile_inventory_assert(strpos($profileTemplate, 'data-panel="inventory"') !== false, 'Inventory profile panel is missing');
member_profile_inventory_assert(strpos($profileTemplate, 'af_apui_inventory_tab') !== false, 'Inventory profile template variable is missing');
member_profile_inventory_assert(strpos($profileAddon, 'af_inventory_render_profile_inventory($uid)') !== false, 'AdvancedInventory profile provider is not reused');
member_profile_inventory_assert(strpos($inventoryAddon, 'function af_inventory_render_profile_inventory') !== false && strpos($inventoryAddon, 'af_advancedinventory_build_inventory_fragment($ownerUid)') !== false, 'Profile provider must delegate to the canonical inventory fragment');
member_profile_inventory_assert(strpos($inventoryManifest, "['script' => 'member.php', 'action' => 'profile']") !== false, 'Inventory assets are unavailable on the lazy profile surface');
member_profile_inventory_assert(strpos($profileAddon, 'function af_apui_maybe_serve_lazy_profile_tab') !== false, 'Lazy profile API is missing');
member_profile_inventory_assert(strpos($profileJs, "data-lazy-state') === 'loaded'") !== false, 'Client-side lazy cache is missing');
member_profile_inventory_assert(strpos($corePlugin, "add_hook('global_start', 'advancedfunctionality_profile_lazy_endpoint_bridge', 2)") !== false, 'Lazy endpoint is not registered before global_start dispatch');
member_profile_inventory_assert(strpos($profileJs, 'finally {') !== false && strpos($profileJs, "removeAttribute('aria-busy')") !== false, 'Lazy loading state is not cleared in finally');
member_profile_inventory_assert(strpos($profileJs, "panel.setAttribute('data-lazy-state', 'loaded')") !== false, 'Successful tabs are not cached');
member_profile_inventory_assert(strpos($profileJs, "panel.setAttribute('data-lazy-state', 'error')") !== false, 'Failed tabs are not retryable');
member_profile_inventory_assert(strpos($inventoryJs, 'window.AFAdvancedInventoryInit = initAdvancedInventory') !== false, 'Lazy inventory has no idempotent initializer');
member_profile_inventory_assert(strpos($profileAddon, "['sheet', 'inventory', 'timeline', 'activity']") !== false, 'Lazy tab allowlist is missing');
member_profile_inventory_assert(strpos($profileAddon, 'af_charactersheets_build_sheet_inner_html') !== false, 'CharacterSheets renderer is not reused');
member_profile_inventory_assert(strpos($profileAddon, 'Инвентарь пуст.') !== false, 'Inventory empty state is missing');
member_profile_inventory_assert(strpos($profileAddon, 'Инвентарь недоступен.') !== false, 'Inventory unavailable state is missing');
member_profile_inventory_assert(strpos($sheetRenderer, 'af_charactersheets_inventory_action_html($uid)') !== false && strpos($sheetRenderer, 'Открыть инвентарь') !== false, 'Character Sheet equipment does not expose the canonical Inventory action');
member_profile_inventory_assert(strpos($profileAddon, 'Хронология пока пуста.') !== false, 'Timeline empty state is missing');
member_profile_inventory_assert(strpos($profileAddon, 'Активность пока пуста.') !== false, 'Activity empty state is missing');
member_profile_inventory_assert(strpos($atfCss, '.atf-profile-hero__identity { display: flex; flex: 1 1 auto; flex-direction: column;') !== false, 'Hero identity is not a vertical flexible block');

preg_match_all('/data-tab="([^"]+)"/', $profileTemplate, $tabMatches);
preg_match_all('/data-panel="([^"]+)"/', $profileTemplate, $panelMatches);
member_profile_inventory_assert($tabMatches[1] === $panelMatches[1], 'Profile tab and panel registries are not aligned');
member_profile_inventory_assert(strpos($profileJs, "? fromHash : 'info'") !== false, 'Unknown or removed profile hashes no longer fall back to the info tab');

member_profile_inventory_assert(strpos($sheetTemplate, 'data-afcs-tab="inventory"') !== false, 'Character Sheet Inventory tab was removed');
member_profile_inventory_assert(strpos($sheetTemplate, '{$sheet_inventory_html}') !== false, 'Character Sheet Inventory content was removed');
member_profile_inventory_assert(strpos($inventoryEntry, "af_advancedinventory_render_inventory_page") !== false, 'Standalone Inventory entry point was removed');

fwrite(STDOUT, "Member profile Inventory tab regression checks passed.\n");
