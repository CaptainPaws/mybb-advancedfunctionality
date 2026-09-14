<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$atfPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.js');
$kbPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$kbJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');
$sheet = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');

function ability_effect_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

foreach (['cooldown_value', 'cooldown_unit', 'cost_value', 'cost_resource', 'effects'] as $key) {
    ability_effect_assert(strpos($atfPhp, "'{$key}'") !== false, "ATF normalizer misses {$key}");
    ability_effect_assert(strpos($kbPhp, "'{$key}'") !== false, "KB contract misses {$key}");
}
foreach (['effect_type', 'value', 'formula_profile', 'coefficient', 'target', 'damage_type', 'element', 'duration_value', 'duration_unit', 'status_key', 'stat_key', 'operation', 'resource_key', 'notes'] as $key) {
    ability_effect_assert(strpos($atfPhp, "'{$key}'") !== false, "ATF effect contract misses {$key}");
    ability_effect_assert(strpos($kbJs, "key: '{$key}'") !== false, "KB effect editor misses {$key}");
}
ability_effect_assert(strpos($atfJs, '>Схема расчёта<') !== false, 'ATF Formula Profile label was not localized');
ability_effect_assert(strpos($kbJs, "label: 'Схема расчёта'") !== false, 'KB Formula Profile label was not localized');
ability_effect_assert(strpos($kbPhp, "'af_kb.character.contract.v1'") !== false, 'Character contract identifier changed');
ability_effect_assert(strpos($sheet, 'af_charactersheets_arpg_build_effect_lines') !== false, 'Character Sheet does not consume effects');
ability_effect_assert(strpos($sheet, "['damage' => 'Урон', 'heal' => 'Лечение', 'shield' => 'Щит']") !== false, 'Character Sheet lost legacy value fallback');

echo "Character ability effects contract regression checks passed\n";
