<?php

$render = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');
$css = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.css');

function arpg_equipment_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

arpg_equipment_assert(is_string($render) && is_string($css), 'Unable to read CharacterSheets sources');
arpg_equipment_assert(strpos($render, 'af_advinv_export_charactersheet_equipment_state($uid)') !== false, 'ARPG equipment does not use the live Inventory export');

$collectorStart = strpos($render, 'function af_charactersheets_arpg_collect_equipment_rule_sources');
$collectorEnd = strpos($render, 'function af_charactersheets_arpg_collect_inventory_items', $collectorStart);
$collector = substr($render, $collectorStart, $collectorEnd - $collectorStart);
arpg_equipment_assert(strpos($collector, "['equipment']['slots']") === false, 'ARPG rule sources still read build_json equipment slots');
arpg_equipment_assert(strpos($collector, 'af_charactersheets_arpg_live_equipment_state($uid)') !== false, 'ARPG rule sources are not resolved from live bindings');
arpg_equipment_assert(strpos($collector, '$weaponResolved') !== false, 'ARPG does not enforce its single-weapon model');

$uiStart = strpos($render, 'function af_charactersheets_build_arpg_equipment_html');
$uiEnd = strpos($render, 'function af_charactersheets_detect_render_profile', $uiStart);
$ui = substr($render, $uiStart, $uiEnd - $uiStart);
foreach (['support_1', 'support_2', 'support_3', 'support_4'] as $slot) {
    arpg_equipment_assert(strpos($ui, "'{$slot}'") !== false, "{$slot} is missing from the always-visible quick slots");
}
arpg_equipment_assert(strpos($ui, '>Инфо</button>') !== false, 'The equipment detail action is not named Инфо');
arpg_equipment_assert(strpos($ui, '>Снять</button>') === false, 'ARPG CharacterSheets still exposes unequip controls');
arpg_equipment_assert(strpos($ui, 'data-afcs-equipment-equip=') === false, 'ARPG CharacterSheets still exposes equip controls');
arpg_equipment_assert(strpos($ui, 'Открыть инвентарь') !== false, 'Owner Inventory navigation is missing');

arpg_equipment_assert(strpos($css, 'max-width:250px; aspect-ratio:1 / 1') !== false, 'Primary cards are not square and capped at 250px');
arpg_equipment_assert(strpos($css, 'width:100px; height:100px; aspect-ratio:1 / 1') !== false, 'Quick-slot cards are not 100px squares');

echo "ARPG live equipment regression checks passed\n";
