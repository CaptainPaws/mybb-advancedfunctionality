<?php
declare(strict_types=1);

function talent_e2e_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$kb = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$js = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');
$inventory = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php');
$admin = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedinventory/admin.php');

talent_e2e_assert(strpos($kb, "'luck' => ['label' => 'Удача'") !== false, 'Luck is missing from the shared stat registry');
talent_e2e_assert(strpos($kb, "'armor' => ['label'") === false, 'DnD armor leaked into the ARPG stat registry');
talent_e2e_assert(strpos($kb, "['passive_effect_options'] = af_kb_arpg_passive_effect_options()") !== false, 'Passive registry is not exported through the runtime schema');
talent_e2e_assert(strpos($js, 'function renderPassiveEffectEditor(') !== false && strpos($js, "mechanicsOptions('status_def')") !== false, 'Typed passive editor/status definitions are not wired');
talent_e2e_assert(strpos($inventory, "'code' => 'talent'") !== false && strpos($inventory, '"kb_type":["arpg_talent"]') !== false, 'Built-in Talent inventory classification/filter is missing');
talent_e2e_assert(strpos($inventory, 'if ($exists > 0) continue;') !== false, 'Schema ensure still overwrites existing system filters');
talent_e2e_assert(strpos($admin, "get_input('filter_enabled')") !== false, 'Unchecked enabled POST handling is missing');

echo "ARPG Talent end-to-end contract regression checks passed.\n";
