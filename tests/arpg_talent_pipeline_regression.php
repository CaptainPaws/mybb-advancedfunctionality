<?php

$root = dirname(__DIR__);
$render = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');
$ajax = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/ajax.php');
$shop = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php');
$js = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.js');

function talent_pipeline_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

talent_pipeline_assert(strpos($render, "entity='abilities' AND subtype='talent' AND kb_type='arpg_talent'") !== false, 'ownership must come from Advanced Inventory abilities/talent rows');
talent_pipeline_assert(strpos($render, 'af_charactersheets_arpg_talent_catalog') !== false, 'tree must be built from KB talent definitions');
talent_pipeline_assert(strpos($render, 'af_charactersheets_arpg_collect_talent_rule_sources') !== false, 'active talents must provide mechanic rule sources');
talent_pipeline_assert(strpos($render, 'af_charactersheets_arpg_apply_equipment_rules($character_stats, $talentRuleSources)') !== false, 'talent modifiers must enter CharacterSheets totals');
talent_pipeline_assert(strpos($ajax, "'equip_talent'") !== false && strpos($ajax, 'af_charactersheets_arpg_talent_prerequisite_keys') !== false, 'server equip action and prerequisite validation must exist');
talent_pipeline_assert(strpos($ajax, 'verify_post_check') !== false && strpos($ajax, '$can_edit_loadout') !== false, 'talent mutation must retain CSRF and edit permission gates');
talent_pipeline_assert(strpos($shop, "? 'ability_tokens'") !== false, 'talent shop slots must force Ability Tokens');
talent_pipeline_assert(strpos($shop, 'Этот уникальный талант уже принадлежит персонажу') !== false, 'duplicate talent grants must be rejected');
talent_pipeline_assert(strpos($js, "afCsAjax(active ? 'unequip_talent' : 'equip_talent'") !== false, 'tree UI must use the CharacterSheets API rather than local-only state');

echo "ARPG talent pipeline regression checks passed.\n";
